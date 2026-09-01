<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\DueDateCalculator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class QuoteToInvoiceConversionTest extends TestCase
{
    private Connection $db;
    private BulkReissueAction $bulk;
    private int $sourceId = 0;
    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje migrovanou testovací DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->bulk = $container->get(BulkReissueAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_reverse($this->createdIds) as $id) {
            $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
    }

    public function testQuoteItemsBecomeOrdinaryInvoiceDraftAndAdvanceConversionStaysProforma(): void
    {
        $pdo = $this->db->pdo();
        $today = date('Y-m-d');
        $fixture = $pdo->query(
            "SELECT c.supplier_id, c.id AS client_id, cur.id AS currency_id,
                    u.id AS user_id, vr.id AS vat_rate_id, vr.rate_percent,
                    COALESCE(c.payment_due_default, s.default_payment_due_days, 7) AS due_value,
                    COALESCE(c.payment_due_unit, s.default_payment_due_unit, 'days') AS due_unit
               FROM clients c
               JOIN supplier s ON s.id = c.supplier_id
         CROSS JOIN currencies cur
         CROSS JOIN users u
         CROSS JOIN vat_rates vr
              WHERE cur.is_active = 1
                AND vr.valid_from <= CURRENT_DATE
                AND (vr.valid_to IS NULL OR vr.valid_to >= CURRENT_DATE)
              ORDER BY ABS(vr.rate_percent - 21), c.id, cur.id, u.id
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($fixture === false) {
            self::markTestSkipped('Chybí základní syntetické FK fixture pro konverzi nabídky.');
        }

        $source = $pdo->prepare(
            "INSERT INTO invoices
                (supplier_id, invoice_type, parent_invoice_id, client_id, project_id,
                 issue_date, tax_date, due_date, currency_id, reverse_charge,
                 prices_include_vat, language, note_above_items, note_below_items,
                 discount_percent, payment_method, numbering_type, status,
                 approval_status, created_by, total_without_vat, total_vat, total_with_vat)
             VALUES (?, 'proforma', NULL, ?, NULL,
                     CURRENT_DATE, NULL, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), ?, 0,
                     1, 'cs', 'Syntetická nabídka', 'Pouze testovací data',
                     0, 'bank_transfer', 'quote', 'issued',
                     'approved', ?, 100, 21, 121)"
        );
        $source->execute([
            (int) $fixture['supplier_id'],
            (int) $fixture['client_id'],
            (int) $fixture['currency_id'],
            (int) $fixture['user_id'],
        ]);
        $this->sourceId = (int) $pdo->lastInsertId();
        $this->createdIds[] = $this->sourceId;

        $item = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat,
                 total_with_vat, order_index, item_kind, vat_classification_code)
             VALUES (?, ?, 1, ?, 121, ?, ?, 100, 21, 121, 0, ?, ?)'
        );
        $item->execute([
            $this->sourceId,
            'Syntetická položka cenové nabídky',
            'ks',
            (int) $fixture['vat_rate_id'],
            (float) $fixture['rate_percent'],
            'standard',
            null,
        ]);

        $invoiceId = $this->bulk->cloneOne(
            $this->sourceId,
            $today,
            false,
            (int) $fixture['user_id'],
            'invoice',
            'default',
            $this->sourceId,
        );
        $this->createdIds[] = $invoiceId;

        $invoiceStmt = $pdo->prepare(
            'SELECT invoice_type, numbering_type, status, parent_invoice_id,
                    issue_date, tax_date, due_date, prices_include_vat,
                    total_without_vat, total_vat, total_with_vat
               FROM invoices WHERE id = ?'
        );
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame('invoice', $invoice['invoice_type'] ?? null);
        self::assertSame('default', $invoice['numbering_type'] ?? null);
        self::assertSame('draft', $invoice['status'] ?? null);
        self::assertSame($this->sourceId, (int) ($invoice['parent_invoice_id'] ?? 0));
        self::assertSame($today, $invoice['issue_date'] ?? null);
        self::assertSame($today, $invoice['tax_date'] ?? null);
        self::assertSame(
            DueDateCalculator::calculate(
                $today,
                (int) $fixture['due_value'],
                (string) $fixture['due_unit'],
            ),
            $invoice['due_date'] ?? null,
        );
        self::assertSame(1, (int) ($invoice['prices_include_vat'] ?? 0));
        self::assertSame(121.0, (float) ($invoice['total_with_vat'] ?? 0));

        $clonedItem = $pdo->query(
            "SELECT description, quantity, unit_price_without_vat
               FROM invoice_items WHERE invoice_id = {$invoiceId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Syntetická položka cenové nabídky', $clonedItem['description'] ?? null);
        self::assertSame(1.0, (float) ($clonedItem['quantity'] ?? 0));
        self::assertSame(121.0, (float) ($clonedItem['unit_price_without_vat'] ?? 0));

        $sourceState = $pdo->query(
            "SELECT invoice_type, numbering_type, status FROM invoices WHERE id = {$this->sourceId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('proforma', $sourceState['invoice_type'] ?? null);
        self::assertSame('quote', $sourceState['numbering_type'] ?? null);
        self::assertSame('issued', $sourceState['status'] ?? null);

        $advanceId = $this->bulk->cloneOne(
            $this->sourceId,
            $today,
            false,
            (int) $fixture['user_id'],
            'proforma',
            'default',
            $this->sourceId,
        );
        $this->createdIds[] = $advanceId;
        $advance = $pdo->query(
            "SELECT invoice_type, numbering_type, tax_date FROM invoices WHERE id = {$advanceId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('proforma', $advance['invoice_type'] ?? null);
        self::assertSame('default', $advance['numbering_type'] ?? null);
        self::assertNull($advance['tax_date'] ?? null);
    }
}
