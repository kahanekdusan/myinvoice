<?php

declare(strict_types=1);

/**
 * Backfill: pro odeslané faktury / proformy / dobropisy, které ještě nemají
 * v `invoice_pdfs` archivovanou verzi `was_sent=1`, vyrenderuje PDF (z aktuálních
 * snapshotů) a uloží do archivu jako `reason='backfill_sent'`, `was_sent=1`.
 *
 * Tím se historie naplní zpětně pro doklady, které byly odeslány ještě před
 * nasazením archivační logiky. Email se NEPOSÍLÁ — jen archivace na disk + DB.
 *
 * Kvalifikační kritéria:
 *   - invoice_type IN ('invoice', 'proforma', 'credit_note')   (cancellation se neposílá)
 *   - sent_at IS NOT NULL                                       (alespoň jednou skutečně odesláno)
 *   - žádný existující invoice_pdfs záznam s was_sent=1
 *
 * Snapshoty se NEpřepisují (renderer::render() bez forceRegenerate). PDF se renderuje
 * z immutable supplier/client/bank snapshotu, takže výsledek odpovídá stavu v okamžiku
 * vystavení — ne dnešním datům dodavatele/klienta.
 *
 * Použití:
 *   php api/bin/backfill-sent-pdfs.php           # dry-run, vypíše seznam
 *   php api/bin/backfill-sent-pdfs.php --apply   # skutečně archivuje
 *   php api/bin/backfill-sent-pdfs.php --apply --limit=50   # batch
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true);
$limit = 0;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int) $m[1];
}

$app       = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();

$pdo      = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$renderer = $container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
$archive  = $container->get(\MyInvoice\Service\Pdf\PdfArchiveService::class);

// Najdi kandidáty: odeslané doklady bez was_sent v archivu
$sql = "
    SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.sent_at,
           i.supplier_id
      FROM invoices i
      LEFT JOIN invoice_pdfs p
             ON p.invoice_id = i.id AND p.was_sent = 1
     WHERE i.invoice_type IN ('invoice','proforma','credit_note')
       AND i.sent_at IS NOT NULL
       AND p.id IS NULL
     GROUP BY i.id
     ORDER BY i.id
";
if ($limit > 0) $sql .= " LIMIT $limit";
$rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Žádné odeslané doklady bez archivovaného PDF — nic na práci.\n";
    exit(0);
}

echo "Kandidátů: " . count($rows) . ($limit > 0 ? " (limit $limit)" : '') . "\n";

if (!$apply) {
    echo "(dry-run — pro skutečnou archivaci spusť s --apply)\n\n";
    foreach (array_slice($rows, 0, 20) as $r) {
        echo sprintf("  #%-6d %-12s %-12s VS=%-15s sent_at=%s\n",
            $r['id'], $r['invoice_type'], $r['status'],
            $r['varsymbol'] ?? '(none)', $r['sent_at']);
    }
    if (count($rows) > 20) echo "  … a další " . (count($rows) - 20) . "\n";
    exit(0);
}

// Najdi sent_to z activity_log (poslední invoice.sent event pro danou fakturu)
$sentToStmt = $pdo->prepare(
    "SELECT payload FROM activity_log
      WHERE action = 'invoice.sent' AND entity_type = 'invoice' AND entity_id = ?
      ORDER BY created_at DESC, id DESC LIMIT 1"
);

$ok = 0;
$skipped = 0;
$err = 0;

foreach ($rows as $r) {
    $id = (int) $r['id'];
    $label = "#$id [" . ($r['varsymbol'] ?? 'no-vs') . " " . $r['invoice_type'] . "]";

    // Resolve sent_to z activity logu (může být null pokud log neexistuje)
    $sentTo = null;
    $sentToStmt->execute([$id]);
    $rawPayload = $sentToStmt->fetchColumn();
    if ($rawPayload) {
        $payload = json_decode((string) $rawPayload, true);
        $candidates = [];
        foreach (['to', 'cc', 'bcc'] as $k) {
            if (!empty($payload[$k]) && is_array($payload[$k])) {
                foreach ($payload[$k] as $em) {
                    $em = trim((string) $em);
                    if ($em !== '' && !in_array($em, $candidates, true)) $candidates[] = $em;
                }
            }
        }
        if (!empty($candidates)) $sentTo = $candidates;
    }

    try {
        // Render PDF (bez forceRegenerate — snapshoty se nepřepisují)
        $pdfPath = $renderer->render($id);
    } catch (\Throwable $e) {
        echo "  ✗ $label render selhal: " . $e->getMessage() . "\n";
        $err++;
        continue;
    }

    try {
        $archiveId = $archive->archiveCopy(
            $id,
            $pdfPath,
            'backfill_sent',
            wasSent: true,
            sentTo: $sentTo,
        );
    } catch (\Throwable $e) {
        echo "  ✗ $label archive selhal: " . $e->getMessage() . "\n";
        $err++;
        continue;
    }

    if ($archiveId === null) {
        echo "  ⚠ $label archive vrátil null (zdroj neexistuje?)\n";
        $skipped++;
        continue;
    }

    $emails = $sentTo ? ' → ' . implode(', ', $sentTo) : '';
    echo "  ✓ $label archive_id=$archiveId$emails\n";
    $ok++;
}

echo "\nHotovo: $ok archivováno";
if ($skipped > 0) echo ", $skipped přeskočeno";
if ($err > 0) echo ", $err chyb";
echo ".\n";
