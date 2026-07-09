<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\QuoteRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje cenovou nabídku do PDF.
 */
final class QuotePdfRenderer
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly QuoteRepository $repo,
        private readonly Connection $db,
    ) {}

    /** @return string Absolutní cesta k vygenerovanému PDF. */
    public function render(int $quoteId, bool $forceRegenerate = false): string
    {
        $quote = $this->repo->find($quoteId);
        if ($quote === null) {
            throw new \RuntimeException("Nabídka #{$quoteId} nenalezena");
        }
        // Defensive normalizace vstupních stringů z DB/snapshotů na UTF-8.
        $quote = $this->normalizeUtf8Tree($quote);

        $outputPath = $this->cachePath($quote);
        if (!$forceRegenerate && is_file($outputPath)) {
            return $outputPath;
        }

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $rendered = $this->renderHtmlAndCss($quote);

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'tempDir'       => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);

        if (!is_dir(dirname($outputPath))) {
            @mkdir(dirname($outputPath), 0755, true);
        }

        $tmpPath = $outputPath . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);

        if (is_file($outputPath)) {
            @unlink($outputPath);
        }
        if (!@rename($tmpPath, $outputPath)) {
            $outputPath = $tmpPath;
        }

        return $outputPath;
    }

    /**
     * @return array{body:string, css:string}
     */
    private function renderHtmlAndCss(array $quote): array
    {
        $cssPath = Bootstrap::rootDir() . '/styles/quote.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        $body = $this->renderHtml($quote);

        return ['body' => $body, 'css' => $css];
    }

    private function renderHtml(array $quote): string
    {
        $locale = (string) ($quote['language'] ?? 'cs');

        $supplier = $this->normalizeUtf8Tree($this->resolveSupplier($quote));
        $client = $this->normalizeUtf8Tree($this->resolveClient($quote));
        $bank = $this->normalizeUtf8Tree($this->resolveBank($quote));

        return $this->twig()->render('quote.twig', [
            'quote'         => $quote,
            'supplier'      => $supplier,
            'client'        => $client,
            'bank'          => $bank,
            'locale'        => $locale,
            'date_format'   => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'   => $locale === 'en' ? '.' : ',',
            'thousand_sep'  => $locale === 'en' ? ',' : "\u{00A0}",
            'payment_method'=> (string) ($quote['payment_method'] ?? 'bank_transfer'),
        ]);
    }

    private function normalizeUtf8Tree(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeUtf8Tree($v);
            }
            return $value;
        }
        if (is_string($value)) {
            return $this->normalizeUtf8String($value);
        }
        return $value;
    }

    private function normalizeUtf8String(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $oldSubstitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8,Windows-1250,ISO-8859-2,ISO-8859-1');
        } finally {
            mb_substitute_character($oldSubstitute);
        }

        return is_string($converted) ? $converted : '';
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }
        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/quote');
        $this->twig = new Environment($loader, [
            'autoescape'       => 'html',
            'cache'            => false,
            'strict_variables' => false,
        ]);
        return $this->twig;
    }

    private function resolveSupplier(array $quote): array
    {
        $live = [];
        $supplierId = (int) ($quote['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM supplier s
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = ?'
            );
            $stmt->execute([$supplierId]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }

        $snap = $quote['supplier_snapshot'] ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        if (is_array($snap)) {
            return array_merge($live, $snap);
        }

        return $live;
    }

    private function resolveClient(array $quote): array
    {
        $live = [];
        $clientId = (int) ($quote['client_id'] ?? 0);
        if ($clientId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM clients c
              LEFT JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $stmt->execute([$clientId]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }

        $snap = $quote['client_snapshot'] ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        if (is_array($snap)) {
            return array_merge($live, $snap);
        }

        return $live;
    }

    private function resolveBank(array $quote): ?array
    {
        $live = [];
        $currencyId = (int) ($quote['currency_id'] ?? 0);
        if ($currencyId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic
                   FROM currencies
                  WHERE id = ?'
            );
            $stmt->execute([$currencyId]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }

        $snap = $quote['bank_snapshot'] ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        $row = is_array($snap) ? array_merge($live, $snap) : $live;

        if ($row === []) {
            return null;
        }
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        return ($hasCzk || $hasIban) ? $row : null;
    }

    private function cachePath(array $quote): string
    {
        $supplierId = (int) ($quote['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            $supplierId = 1;
        }

        try {
            $issueDate = new \DateTimeImmutable((string) ($quote['issue_date'] ?? 'now'));
        } catch (\Throwable) {
            $issueDate = new \DateTimeImmutable('now');
        }

        $dir = RuntimePaths::storage('quotes') . '/sup-' . $supplierId . '/' . $issueDate->format('Y-m');
        $number = (string) ($quote['quote_number'] ?? ('quote-' . (int) ($quote['id'] ?? 0)));
        $number = preg_replace('/[^A-Za-z0-9_-]/', '_', $number) ?: ('quote-' . (int) ($quote['id'] ?? 0));

        return $dir . '/CenovaNabidka-' . $number . '.pdf';
    }
}
