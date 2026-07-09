<?php

declare(strict_types=1);

namespace MyInvoice\Service\Quote;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Generuje číslo cenové nabídky (quote_number) v SAMOSTATNÉ řadě mimo invoice_counters.
 *
 * Template per supplier: `supplier.quote_number_format` (NULL ? fallback DEFAULT_TEMPLATE).
 * Reset counteru řídí `supplier.quote_number_period` (year/month/none). Counter se
 * atomicky inkrementuje v `quote_counters` per (supplier_id, period).
 *
 * Placeholdery (stejné jako VarsymbolGenerator::render):
 *   {YYYY} = 4-digit rok, {YY} = 2-digit rok, {MM} = 2-digit měsíc,
 *   {C+}   = counter, padding podle počtu C ({CCC} ? 3 znaky 001..999).
 *
 * Příklady:
 *   "CN{YYYY}{CCCC}"  ? "CN20260001"   (period=year, default)
 *   "{YYYY}-{CCCC}"   ? "2026-0042"
 *   "NO{YYYY}{MM}{CCC}" ? "NO2026070001" (period=month)
 */
final class QuoteNumberGenerator
{
    /** Fallback template když supplier.quote_number_format není nastaven. */
    public const DEFAULT_TEMPLATE = 'CN{YYYY}{CCCC}';

    private const VALID_PERIODS  = ['year', 'month', 'none'];
    private const DEFAULT_PERIOD = 'year';

    /** Poslední pojistka proti nekonečné smyčce při přeskakování obsazených čísel. */
    private const MAX_SKIP = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Atomicky vygeneruje další číslo nabídky pro daný supplier a datum.
     * Samoopravné: pokud je counter pozadu (import / ruční zásah), přeskočí na volné číslo.
     */
    public function next(int $supplierId, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }

        [$template, $period] = $this->resolveTemplateAndPeriod($supplierId);
        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $next     = $this->incrementCounter($supplierId, $periodKey);
        $rendered = $this->render($template, $for, $next);

        // Template bez counteru ? fixní číslo, nelze nic přeskakovat.
        if (!$this->hasCounterPlaceholder($template)) {
            return $rendered;
        }

        $attempts = 0;
        while ($this->quoteNumberExists($supplierId, $rendered)) {
            if (++$attempts > self::MAX_SKIP) {
                throw new \RuntimeException(
                    'Nepodařilo se najít volné číslo nabídky ani po ' . self::MAX_SKIP
                    . " pokusech (období {$periodKey}). Zkontroluj číselnou řadu."
                );
            }
            $next     = $this->incrementCounter($supplierId, $periodKey);
            $rendered = $this->render($template, $for, $next);
        }

        return $rendered;
    }

    /**
     * Vrátí, jaké bude další číslo BEZ inkrementu (náhled v UI).
     */
    public function preview(int $supplierId, ?\DateTimeInterface $for = null): string
    {
        if ($supplierId <= 0) {
            return '';
        }
        [$template, $period] = $this->resolveTemplateAndPeriod($supplierId);
        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM quote_counters WHERE supplier_id = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);

        return $this->render($template, $for, $current + 1);
    }

    public function render(string $template, \DateTimeInterface $date, int $counter): string
    {
        $vars = [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ];
        $rendered = strtr($template, $vars);

        $rendered = preg_replace_callback('/\{(C+)\}/', function ($m) use ($counter) {
            $len = strlen($m[1]);
            return str_pad((string) $counter, $len, '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;

        return $rendered;
    }

    /** @return array{0: string, 1: string} [template, period] */
    private function resolveTemplateAndPeriod(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT quote_number_format, quote_number_period FROM supplier WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $template = trim((string) ($row['quote_number_format'] ?? ''));
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $period = (string) ($row['quote_number_period'] ?? self::DEFAULT_PERIOD);
        if (!in_array($period, self::VALID_PERIODS, true)) {
            $period = self::DEFAULT_PERIOD;
        }

        return [$template, $period];
    }

    private function makePeriodKey(string $period, \DateTimeInterface $for): string
    {
        return match ($period) {
            'year'  => $for->format('Y'),
            'none'  => 'ALL',
            default => $for->format('Ym'),
        };
    }

    private function incrementCounter(int $supplierId, string $periodKey): int
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO quote_counters (supplier_id, period, last_number)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute([$supplierId, $periodKey]);

        $stmt = $pdo->prepare(
            'SELECT last_number FROM quote_counters WHERE supplier_id = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $periodKey]);
        return (int) $stmt->fetchColumn();
    }

    private function hasCounterPlaceholder(string $template): bool
    {
        return (bool) preg_match('/\{C+\}/', $template);
    }

    private function quoteNumberExists(int $supplierId, string $quoteNumber): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM quotes WHERE supplier_id = ? AND quote_number = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $quoteNumber]);
        return $stmt->fetchColumn() !== false;
    }
}
