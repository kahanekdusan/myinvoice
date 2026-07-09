<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use PDO;

/**
 * CRUD pro cenové nabídky (quotes) + položky (quote_items) + listing s filtry/taby.
 *
 * Konvence sdílené s InvoiceRepository:
 *   • sleva na doklad `discount_percent` je zdroj pravdy, materializuje se do záporných
 *     položek item_kind='discount' (replaceItems) — konzistentní přenos na fakturu.
 *   • snapshoty klienta/dodavatele/banky v JSON (SnapshotBuilder), zapsané při create/update.
 *
 * Cenová nabídka NENÍ daňový doklad ? žádná vazba na VatLedger / DPH výkazy, žádné
 * vat_classification_code na položkách.
 */
final class QuoteRepository
{
    private const ALLOWED_PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'other'];
    private const TABS = ['all', 'approved', 'negotiation', 'expired'];

    public function __construct(
        private readonly Connection $db,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    // ?? Read ????????????????????????????????????????????????????????????????

    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT q.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.ic AS client_ic, c.dic AS client_dic,
                    u.name AS created_by_name,
                    p.name AS project_name,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.account_number AS bank_account_number, cur.bank_code AS bank_code,
                    cur.bank_name AS bank_name, cur.iban AS bank_iban, cur.bic AS bank_bic
               FROM quotes q
               JOIN clients c   ON c.id = q.client_id
          LEFT JOIN users u     ON u.id = q.created_by
          LEFT JOIN projects p  ON p.id = q.project_id
               JOIN currencies cur ON cur.id = q.currency_id
              WHERE q.id = ? AND q.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row = $this->castQuote($row);
        $row['items'] = $this->itemsFor($id);
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);

        $discountAmount = 0.0;
        foreach ($row['items'] as $it) {
            if (($it['item_kind'] ?? 'standard') === 'discount') {
                $discountAmount -= (float) $it['total_without_vat'];
            }
        }
        $row['totals'] = [
            'without_vat'      => $row['total_without_vat'],
            'vat'              => $row['total_vat'],
            'with_vat'         => $row['total_with_vat'],
            'rounding'         => $row['rounding'],
            'discount_percent' => $row['discount_percent'],
            'discount_amount'  => round($discountAmount, 2),
        ];
        $row['is_expired'] = self::computeExpired($row['status'], $row['valid_until']);
        $row['related_invoices'] = $this->relatedInvoices($id);

        return $row;
    }

    public function itemsFor(int $quoteId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT qi.id, qi.quote_id, qi.description, qi.quantity, qi.unit,
                    qi.unit_price_without_vat, qi.vat_rate_id, qi.vat_rate_snapshot,
                    qi.total_without_vat, qi.total_vat, qi.total_with_vat,
                    qi.order_index, qi.item_kind,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM quote_items qi
               JOIN vat_rates vr ON vr.id = qi.vat_rate_id
              WHERE qi.quote_id = ?
              ORDER BY qi.order_index, qi.id'
        );
        $stmt->execute([$quoteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /** Faktury/proformy vygenerované z této nabídky (sekce „Související doklady"). */
    public function relatedInvoices(int $quoteId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date,
                    i.total_with_vat, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.source_quote_id = ?
              ORDER BY i.id'
        );
        $stmt->execute([$quoteId]);
        return array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'varsymbol'      => $r['varsymbol'],
                'invoice_type'   => (string) $r['invoice_type'],
                'status'         => (string) $r['status'],
                'issue_date'     => $r['issue_date'],
                'total_with_vat' => (float) $r['total_with_vat'],
                'currency'       => (string) $r['currency'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Multi-tenant guard helper — supplier_id nabídky (i soft-deleted), null když neexistuje. */
    public function supplierIdOf(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    /** Existuje vydaná faktura (ne proforma) z této nabídky? Limit: max 1 vydaná faktura. */
    public function hasFinalInvoice(int $quoteId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM invoices
              WHERE source_quote_id = ? AND invoice_type = 'invoice' AND status <> 'cancelled' LIMIT 1"
        );
        $stmt->execute([$quoteId]);
        return $stmt->fetchColumn() !== false;
    }

    // ?? Listing ?????????????????????????????????????????????????????????????

    /**
     * @return array{data:list<array>, meta:array{total:int,page:int,per_page:int,pages:int}}
     */
    public function list(int $supplierId, array $filters): array
    {
        $pdo = $this->db->pdo();
        [$where, $params] = $this->buildListWhere($supplierId, $filters);

        $sort = match ((string) ($filters['sort'] ?? 'issue_date')) {
            'quote_number'  => 'q.quote_number',
            'total'         => 'q.total_with_vat',
            'valid_until'   => 'q.valid_until',
            'client'        => 'c.company_name',
            default         => 'q.issue_date',
        };
        $dir = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = $perPage > 0 ? min($perPage, 200) : 25;
        $offset  = ($page - 1) * $perPage;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM quotes q JOIN clients c ON c.id = q.client_id WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT q.id, q.quote_number, q.status, q.issue_date, q.valid_until,
                       q.description, q.order_number, q.total_with_vat, q.total_without_vat,
                       q.client_id, c.company_name AS client_company_name,
                       cur.code AS currency
                  FROM quotes q
                  JOIN clients c ON c.id = q.client_id
                  JOIN currencies cur ON cur.id = q.currency_id
                 WHERE {$where}
              ORDER BY {$sort} {$dir}, q.id DESC
                 LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $data = array_map(function (array $r): array {
            return [
                'id'                 => (int) $r['id'],
                'quote_number'       => $r['quote_number'],
                'status'             => (string) $r['status'],
                'issue_date'         => $r['issue_date'],
                'valid_until'        => $r['valid_until'],
                'description'        => $r['description'],
                'order_number'       => $r['order_number'],
                'client_id'          => (int) $r['client_id'],
                'client_company_name'=> $r['client_company_name'],
                'currency'           => (string) $r['currency'],
                'total_with_vat'     => (float) $r['total_with_vat'],
                'total_without_vat'  => (float) $r['total_without_vat'],
                'is_expired'         => self::computeExpired((string) $r['status'], $r['valid_until']),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'data' => $data,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /** @return array{all:int,approved:int,negotiation:int,expired:int} */
    public function tabCounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(1) AS all_count,
                SUM(status IN ('ordered','invoiced')) AS approved,
                SUM(status = 'sent') AS negotiation,
                SUM(status = 'sent' AND valid_until IS NOT NULL AND valid_until < CURDATE()) AS expired
               FROM quotes
              WHERE supplier_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'all'         => (int) ($r['all_count'] ?? 0),
            'approved'    => (int) ($r['approved'] ?? 0),
            'negotiation' => (int) ($r['negotiation'] ?? 0),
            'expired'     => (int) ($r['expired'] ?? 0),
        ];
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function buildListWhere(int $supplierId, array $filters): array
    {
        $where  = ['q.supplier_id = ?', 'q.deleted_at IS NULL'];
        $params = [$supplierId];

        $tab = (string) ($filters['tab'] ?? 'all');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'all';
        }
        if ($tab === 'approved') {
            $where[] = "q.status IN ('ordered','invoiced')";
        } elseif ($tab === 'negotiation') {
            $where[] = "q.status = 'sent'";
        } elseif ($tab === 'expired') {
            $where[] = "q.status = 'sent' AND q.valid_until IS NOT NULL AND q.valid_until < CURDATE()";
        }

        if (!empty($filters['status']) && in_array((string) $filters['status'], ['draft', 'sent', 'ordered', 'invoiced', 'rejected'], true)) {
            $where[] = 'q.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'q.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['issued_from'])) {
            $where[] = 'q.issue_date >= ?';
            $params[] = (string) $filters['issued_from'];
        }
        if (!empty($filters['issued_to'])) {
            $where[] = 'q.issue_date <= ?';
            $params[] = (string) $filters['issued_to'];
        }
        if (!empty($filters['valid_from'])) {
            $where[] = 'q.valid_until >= ?';
            $params[] = (string) $filters['valid_from'];
        }
        if (!empty($filters['valid_to'])) {
            $where[] = 'q.valid_until <= ?';
            $params[] = (string) $filters['valid_to'];
        }
        if (isset($filters['price_min']) && $filters['price_min'] !== '' && is_numeric($filters['price_min'])) {
            $where[] = 'q.total_with_vat >= ?';
            $params[] = (float) $filters['price_min'];
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== '' && is_numeric($filters['price_max'])) {
            $where[] = 'q.total_with_vat <= ?';
            $params[] = (float) $filters['price_max'];
        }
        if (!empty($filters['search'])) {
            $s = '%' . addcslashes(trim((string) $filters['search']), '%_\\') . '%';
            $where[] = '(q.quote_number LIKE ? OR c.company_name LIKE ? OR q.description LIKE ? OR q.order_number LIKE ?)';
            array_push($params, $s, $s, $s, $s);
        }

        return [implode(' AND ', $where), $params];
    }

    // ?? Write ???????????????????????????????????????????????????????????????

    public function createDraft(array $data, int $userId, string $quoteNumber): int
    {
        $pdo = $this->db->pdo();

        $clientId = (int) $data['client_id'];
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $supplierId = (int) $stmt->fetchColumn();
        if ($supplierId === 0) {
            throw new \InvalidArgumentException("Client #{$clientId} nenalezen.");
        }

        $sql = 'INSERT INTO quotes
            (supplier_id, quote_number, client_id, project_id, status,
             issue_date, valid_until, currency_id, exchange_rate, exchange_rate_date,
             reverse_charge, prices_include_vat, language, payment_method,
             order_number, description, note, note_above_items, note_below_items,
             discount_percent, created_by)
            VALUES (?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $pdo->prepare($sql)->execute([
            $supplierId,
            $quoteNumber,
            $clientId,
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            (string) $data['issue_date'],
            empty($data['valid_until']) ? null : (string) $data['valid_until'],
            (int) $data['currency_id'],
            isset($data['exchange_rate']) && $data['exchange_rate'] !== '' ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $this->normalizePaymentMethod($data['payment_method'] ?? null),
            $this->nullableString($data['order_number'] ?? null, 100),
            $this->nullableString($data['description'] ?? null, 255),
            $data['note'] ?? null,
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            InvoiceRepository::clampDiscountPercent($data['discount_percent'] ?? 0),
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateDraft(int $id, array $data): void
    {
        $sql = 'UPDATE quotes SET
                    client_id = ?, project_id = ?, status = ?,
                    issue_date = ?, valid_until = ?, currency_id = ?,
                    exchange_rate = ?, exchange_rate_date = ?,
                    reverse_charge = ?, prices_include_vat = ?, language = ?, payment_method = ?,
                    order_number = ?, description = ?, note = ?, note_above_items = ?, note_below_items = ?,
                    discount_percent = ?
                 WHERE id = ? AND deleted_at IS NULL';

        $this->db->pdo()->prepare($sql)->execute([
            (int) $data['client_id'],
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            $this->normalizeStatus($data['status'] ?? 'draft'),
            (string) $data['issue_date'],
            empty($data['valid_until']) ? null : (string) $data['valid_until'],
            (int) $data['currency_id'],
            isset($data['exchange_rate']) && $data['exchange_rate'] !== '' ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $this->normalizePaymentMethod($data['payment_method'] ?? null),
            $this->nullableString($data['order_number'] ?? null, 100),
            $this->nullableString($data['description'] ?? null, 255),
            $data['note'] ?? null,
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            InvoiceRepository::clampDiscountPercent($data['discount_percent'] ?? 0),
            $id,
        ]);
    }

    /**
     * Přepíše položky nabídky. Sleva (quotes.discount_percent) se materializuje do
     * záporných položek item_kind='discount' na každou sazbu DPH (jako u faktur).
     * Příchozí položky s item_kind='discount' se ignorují (generují se z header pole).
     */
    public function replaceItems(int $quoteId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$quoteId]);

        $stmt = $pdo->prepare(
            'INSERT INTO quote_items
                (quote_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)'
        );

        $vatRates = $this->loadVatRates();

        $metaStmt = $pdo->prepare('SELECT discount_percent, language FROM quotes WHERE id = ?');
        $metaStmt->execute([$quoteId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['discount_percent' => 0, 'language' => 'cs'];
        $discountPercent = InvoiceRepository::clampDiscountPercent($meta['discount_percent'] ?? 0);
        $language = (string) ($meta['language'] ?? 'cs');

        $discountGroups = [];
        $maxOrder = -1;

        foreach (array_values($items) as $i => $item) {
            if (($item['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            $orderIndex = (int) ($item['order_index'] ?? $i);
            $stmt->execute([
                $quoteId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                $orderIndex,
                'standard',
            ]);

            $maxOrder = max($maxOrder, $orderIndex);
            if ($discountPercent > 0) {
                $base = round((float) ($item['quantity'] ?? 1) * (float) ($item['unit_price_without_vat'] ?? 0), 2);
                $key = (string) $vatRateId;
                if (!isset($discountGroups[$key])) {
                    $discountGroups[$key] = ['vat_rate_id' => $vatRateId, 'snapshot' => $rate, 'base' => 0.0];
                }
                $discountGroups[$key]['base'] += $base;
            }
        }

        if ($discountPercent > 0 && $discountGroups !== []) {
            $label = InvoiceRepository::discountLabel($discountPercent, $language);
            $order = $maxOrder + 1;
            foreach ($discountGroups as $g) {
                $disc = round($g['base'] * $discountPercent / 100.0, 2);
                if ($disc == 0.0) {
                    continue;
                }
                $stmt->execute([
                    $quoteId,
                    $label,
                    1.0,
                    '',
                    -$disc,
                    $g['vat_rate_id'],
                    $g['snapshot'],
                    $order++,
                    'discount',
                ]);
            }
        }
    }

    /** Zapíše snapshoty klienta/dodavatele/banky (JSON) — volá se po create/update. */
    public function writeSnapshots(int $id): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT client_id, currency_id, supplier_id FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $snap = $this->snapshots->build((int) $row['client_id'], (int) $row['currency_id'], (int) $row['supplier_id']);
        $enc = static fn ($v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE quotes SET client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ? WHERE id = ?')
            ->execute([$enc($snap['client']), $enc($snap['supplier']), $enc($snap['bank']), $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->pdo()->prepare('UPDATE quotes SET status = ? WHERE id = ?')
            ->execute([$this->normalizeStatus($status), $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE quotes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
            ->execute([$id]);
    }

    // ?? Helpers ?????????????????????????????????????????????????????????????

    private function normalizePaymentMethod(mixed $value): string
    {
        $pm = (string) ($value ?? 'bank_transfer');
        return in_array($pm, self::ALLOWED_PAYMENT_METHODS, true) ? $pm : 'bank_transfer';
    }

    private function normalizeStatus(mixed $value): string
    {
        $s = (string) ($value ?? 'draft');
        return in_array($s, ['draft', 'sent', 'ordered', 'invoiced', 'rejected'], true) ? $s : 'draft';
    }

    private function nullableString(mixed $value, int $maxLen): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $maxLen);
    }

    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (float) $r['rate_percent'];
        }
        return $out;
    }

    private static function computeExpired(string $status, ?string $validUntil): bool
    {
        if ($status !== 'sent' || $validUntil === null || $validUntil === '') {
            return false;
        }
        return $validUntil < date('Y-m-d');
    }

    private function castQuote(array $row): array
    {
        $row['id']                 = (int) $row['id'];
        $row['supplier_id']        = (int) $row['supplier_id'];
        $row['client_id']          = (int) $row['client_id'];
        $row['project_id']         = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['currency_id']        = (int) $row['currency_id'];
        $row['created_by']         = (int) $row['created_by'];
        $row['reverse_charge']     = (bool) $row['reverse_charge'];
        $row['prices_include_vat'] = (bool) $row['prices_include_vat'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding', 'discount_percent'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        $row['exchange_rate'] = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;
        foreach (['client_snapshot', 'supplier_snapshot', 'bank_snapshot'] as $f) {
            if (!empty($row[$f]) && is_string($row[$f])) {
                $row[$f] = json_decode($row[$f], true);
            }
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['quote_id']               = (int) $row['quote_id'];
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        $row['vat_rate_snapshot']      = (float) $row['vat_rate_snapshot'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        $row['item_kind'] = (string) ($row['item_kind'] ?? 'standard');
        return $row;
    }

    private function buildVatBreakdown(array $items): array
    {
        $bd = [];
        foreach ($items as $item) {
            $rate = (float) $item['vat_rate_snapshot'];
            $key = number_format($rate, 2, '.', '');
            if (!isset($bd[$key])) {
                $bd[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $bd[$key]['base'] += (float) $item['total_without_vat'];
            $bd[$key]['vat']  += (float) $item['total_vat'];
        }
        $out = [];
        foreach ($bd as $b) {
            $out[] = ['rate' => $b['rate'], 'base' => round($b['base'], 2), 'vat' => round($b['vat'], 2)];
        }
        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $out;
    }
}
