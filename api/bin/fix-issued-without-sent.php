<?php

declare(strict_types=1);

/**
 * Fix faktur, které jsou vystavené (issued/paid/reminded) ale nemají sent_at —
 * typicky importované faktury z dřívějška, kdy importér sent_at nezapisoval.
 *
 * Změny:
 *   * status='issued' AND sent_at IS NULL  → status='sent', sent_at=issue_date 12:00
 *   * status IN ('paid','reminded') AND sent_at IS NULL → jen sent_at=issue_date 12:00
 *
 * Status 'paid' / 'reminded' nemůžeme dosáhnout bez předchozího odeslání, takže
 * sent_at NULL je tu nekonzistence — opravíme jen sent_at, status zachováme.
 *
 * 'cancellation' interní storno se klientovi neposílá → vynecháno.
 * 'draft' nikdy neměla sent_at → vynecháno.
 *
 * Použití:
 *   php api/bin/fix-issued-without-sent.php           # dry-run
 *   php api/bin/fix-issued-without-sent.php --apply   # zápis do DB
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$apply = in_array('--apply', $argv, true);

$pdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();

$sql = "
    SELECT id, varsymbol, invoice_type, status, issue_date
      FROM invoices
     WHERE invoice_type IN ('invoice','proforma','credit_note')
       AND status IN ('issued','sent','reminded','paid')
       AND sent_at IS NULL
     ORDER BY issue_date, id
";
$rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nic na opravu — všechny vystavené doklady mají sent_at.\n";
    exit(0);
}

$counts = ['issued→sent' => 0, 'paid sent_at' => 0, 'reminded sent_at' => 0, 'sent sent_at' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'issued')        $counts['issued→sent']++;
    elseif ($r['status'] === 'paid')      $counts['paid sent_at']++;
    elseif ($r['status'] === 'reminded')  $counts['reminded sent_at']++;
    elseif ($r['status'] === 'sent')      $counts['sent sent_at']++;
}

echo "Faktur k opravě: " . count($rows) . "\n";
foreach ($counts as $k => $v) if ($v > 0) echo "  $k: $v\n";

if (!$apply) {
    echo "(dry-run — pro zápis spusť s --apply)\n\n";
    foreach (array_slice($rows, 0, 15) as $r) {
        echo sprintf("  #%-6d %-12s %-9s VS=%-15s issue=%s\n",
            $r['id'], $r['invoice_type'], $r['status'],
            $r['varsymbol'] ?? '(none)', $r['issue_date']);
    }
    if (count($rows) > 15) echo "  … a další " . (count($rows) - 15) . "\n";
    exit(0);
}

$updIssued = $pdo->prepare(
    "UPDATE invoices SET status='sent', sent_at=? WHERE id = ? AND status='issued' AND sent_at IS NULL"
);
$updSentAt = $pdo->prepare(
    "UPDATE invoices SET sent_at=? WHERE id = ? AND sent_at IS NULL"
);

$ok = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as $r) {
        $sentAt = ((string) $r['issue_date']) . ' 12:00:00';
        if ($r['status'] === 'issued') {
            $updIssued->execute([$sentAt, (int) $r['id']]);
        } else {
            $updSentAt->execute([$sentAt, (int) $r['id']]);
        }
        $ok++;
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "✗ Chyba, transakce zrušena: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Hotovo: $ok faktur opraveno.\n";
echo "Tip: nyní můžeš spustit `php api/bin/backfill-sent-pdfs.php --apply`\n";
echo "     pro vygenerování archivních PDF kopií těchto dokladů.\n";
