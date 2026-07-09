<?php

declare(strict_types=1);

namespace MyInvoice\Service\Quote;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceMath;
use PDO;

/**
 * Přepočítá sumy cenové nabídky (per-item totals + header totals + vat breakdown).
 *
 * Sdílí pure-function výpočet s fakturami (InvoiceMath) — respektuje reverse_charge
 * i režim cen s DPH (prices_include_vat). Slevové položky (item_kind='discount') jsou
 * v `quote_items` už materializované jako záporné řádky, takže se sčítají automaticky.
 *
 * Cenová nabídka NENÍ daňový doklad ? žádná vazba na VatLedgerService / DPH výkazy.
 */
final class QuoteCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{totals:array{without_vat:float,vat:float,with_vat:float}, vat_breakdown:list<array{rate:float,base:float,vat:float}>}
     */
    public function recompute(int $quoteId): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT reverse_charge, prices_include_vat FROM quotes WHERE id = ?');
        $stmt->execute([$quoteId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new \RuntimeException("Quote {$quoteId} not found");
        }
        $reverseCharge    = (bool) $header['reverse_charge'];
        $pricesIncludeVat = (bool) $header['prices_include_vat'];

        $stmt = $pdo->prepare(
            'SELECT id, quantity, unit_price_without_vat, vat_rate_snapshot
               FROM quote_items WHERE quote_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$quoteId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $computed = InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat);

        $updateItem = $pdo->prepare(
            'UPDATE quote_items SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        $stmt = $pdo->prepare(
            'UPDATE quotes SET total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = 0
             WHERE id = ?'
        );
        $stmt->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $quoteId,
        ]);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }
}
