<?php

declare(strict_types=1);

namespace MyInvoice\Service\Quote;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;

/**
 * Konverze cenové nabídky na vydanou fakturu / zálohovou fakturu (proforma).
 *
 * Přenáší: klient/zakázka, měna+kurz, způsob úhrady, reverse_charge, prices_include_vat
 * (nutné — jinak by se brutto ceny přepočetly jako netto), texty nad/pod položkami,
 * sleva na doklad (discount_percent) a všechny STANDARD položky. Slevové položky se
 * NEkopírují — regeneruje je InvoiceRepository::replaceItems z discount_percent.
 *
 * NEpřenáší: interní poznámku (`note`), platnost (`valid_until`), stav nabídky.
 *
 * Stav nabídky po konverzi: vydaná faktura ? 'invoiced', proforma ? 'ordered'.
 * Z jedné nabídky lze vystavit VÍCE proform, ale jen JEDNU vydanou fakturu.
 *
 * Konverze NEovlivňuje sklad ani DPH evidenci (nabídka není daňový doklad).
 */
final class QuoteToInvoiceConverter
{
    public function __construct(
        private readonly Connection $db,
        private readonly QuoteRepository $quotes,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceDefaults $invoiceDefaults,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
    ) {}

    /**
     * @param 'invoice'|'proforma' $invoiceType
     * @return int ID nové faktury
     */
    public function convert(array $quote, string $invoiceType, int $userId): int
    {
        $data = [
            'invoice_type'       => $invoiceType,
            'client_id'          => (int) $quote['client_id'],
            'project_id'         => $quote['project_id'] ?? null,
            'currency_id'        => (int) $quote['currency_id'],
            'reverse_charge'     => (bool) $quote['reverse_charge'],
            'prices_include_vat' => (bool) $quote['prices_include_vat'],
            'language'           => (string) ($quote['language'] ?? 'cs'),
            'payment_method'     => (string) ($quote['payment_method'] ?? 'bank_transfer'),
            'note_above_items'   => $quote['note_above_items'] ?? null,
            'note_below_items'   => $quote['note_below_items'] ?? null,
            'discount_percent'   => (float) ($quote['discount_percent'] ?? 0),
            'issue_date'         => date('Y-m-d'),
            'items'              => $this->mapItems($quote['items'] ?? []),
        ];

        $data = $this->invoiceDefaults->resolve($data);

        $invoiceId = $this->invoices->createDraft($data, $userId);
        $this->db->pdo()->prepare('UPDATE invoices SET source_quote_id = ? WHERE id = ?')
            ->execute([(int) $quote['id'], $invoiceId]);

        $this->invoices->replaceItems($invoiceId, $data['items']);
        $this->calc->recompute($invoiceId);
        $this->rateApplier->applyToInvoice($invoiceId);

        // Automatický přechod stavu nabídky.
        $this->quotes->setStatus((int) $quote['id'], $invoiceType === 'proforma' ? 'ordered' : 'invoiced');

        return $invoiceId;
    }

    /**
     * Mapuje STANDARD položky nabídky na formát invoice_items. Slevové položky vynechává
     * (regenerují se z header discount_percent v InvoiceRepository::replaceItems).
     *
     * @param list<array> $items
     * @return list<array>
     */
    private function mapItems(array $items): array
    {
        $out = [];
        $order = 0;
        foreach ($items as $it) {
            if (($it['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $out[] = [
                'description'            => (string) ($it['description'] ?? ''),
                'quantity'              => (float) ($it['quantity'] ?? 1),
                'unit'                  => (string) ($it['unit'] ?? 'ks'),
                'unit_price_without_vat'=> (float) ($it['unit_price_without_vat'] ?? 0),
                'vat_rate_id'           => (int) ($it['vat_rate_id'] ?? 0),
                'order_index'           => $order++,
                'item_kind'             => 'standard',
            ];
        }
        return $out;
    }
}
