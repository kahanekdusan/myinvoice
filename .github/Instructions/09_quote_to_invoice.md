# 09 – Konverze cenové nabídky na fakturu

## Cíl

Implementovat funkci generování vydané faktury a zálohové faktury z cenové nabídky.  
Data se přenesou z nabídky do faktury – uživatel nemusí nic přepisovat ručně.

---

## Co se přenáší z nabídky do faktury

| Pole nabídky | Cíl na faktuře | Poznámka |
|---|---|---|
| `client_id` | `client_id` | |
| `client_*` (snapshot) | `client_*` snapshot | |
| `delivery_*` | `delivery_*` | |
| `payment_method` | `payment_method` | |
| `bank_account_id` | `bank_account_id` | |
| `currency_code` | `currency_code` | |
| `exchange_rate` | `exchange_rate` | |
| `order_number` | `order_number` | |
| `text_before_items` | `text_before_items` | ✅ přenáší se |
| `text_after_items` | `text_after_items` | ✅ přenáší se |
| `items` | `items` | všechny položky včetně slev |
| `discount_percent` | `discount_percent` | sleva na doklad |
| `source_quote_id` | `source_quote_id` | vazba zpět na nabídku |

**NEPŘENÁŠÍ se:**
- `note` (interní poznámka) – není na faktuře
- `valid_until` – není relevantní pro fakturu
- `status` nabídky – faktura má vlastní stav

---

## Nová data generovaná pro fakturu

| Pole | Logika |
|------|--------|
| `invoice_number` | Generuje `NumberSeriesService` (nová číselná řada faktur) |
| `issued_at` | dnes |
| `due_date` | dnes + `invoice_due_days` (z nastavení supplieru) |
| `status` | `issued` (vystavená) |
| `invoice_type` | `regular` nebo `proforma` |

---

## QuoteService: konverzní metody

```php
<?php
// V App\Services\QuoteService

/**
 * Vytvoří vydanou fakturu z cenové nabídky.
 * Stav nabídky → 'invoiced'.
 */
public function toInvoice(Quote $quote): Invoice
{
    $invoice = $this->buildInvoiceFromQuote($quote, 'regular');
    $invoice->save();

    $this->copyItemsToInvoice($quote, $invoice);

    // Automatický přechod stavu nabídky
    app(QuoteStatusService::class)->transitionTo(
        $quote, QuoteStatus::Invoiced, "Invoice #{$invoice->invoice_number} created"
    );

    return $invoice;
}

/**
 * Vytvoří zálohovou fakturu z cenové nabídky.
 * Stav nabídky → 'ordered'.
 * Z jedné nabídky lze vystavit VÍCE zálohových faktur.
 */
public function toProformaInvoice(Quote $quote): Invoice
{
    $invoice = $this->buildInvoiceFromQuote($quote, 'proforma');
    $invoice->save();

    $this->copyItemsToInvoice($quote, $invoice);

    // Automatický přechod stavu nabídky
    app(QuoteStatusService::class)->transitionTo(
        $quote, QuoteStatus::Ordered, "Proforma #{$invoice->invoice_number} created"
    );

    return $invoice;
}

private function buildInvoiceFromQuote(Quote $quote, string $type): Invoice
{
    $supplier = $quote->supplier;

    return new Invoice([
        'supplier_id'       => $quote->supplier_id,
        'client_id'         => $quote->client_id,
        'invoice_type'      => $type,
        'source_quote_id'   => $quote->id,
        // Client snapshot
        'client_name'       => $quote->client_name,
        'client_street'     => $quote->client_street,
        'client_city'       => $quote->client_city,
        'client_zip'        => $quote->client_zip,
        'client_country'    => $quote->client_country,
        'client_ic'         => $quote->client_ic,
        'client_dic'        => $quote->client_dic,
        // Delivery
        'delivery_name'     => $quote->delivery_name,
        'delivery_street'   => $quote->delivery_street,
        'delivery_city'     => $quote->delivery_city,
        'delivery_zip'      => $quote->delivery_zip,
        'delivery_country'  => $quote->delivery_country,
        // Payment
        'payment_method'    => $quote->payment_method,
        'bank_account_id'   => $quote->bank_account_id,
        'currency_code'     => $quote->currency_code,
        'exchange_rate'     => $quote->exchange_rate,
        // Content
        'order_number'      => $quote->order_number,
        'text_before_items' => $quote->text_before_items,
        'text_after_items'  => $quote->text_after_items,
        'discount_percent'  => $quote->discount_percent,
        // Dates
        'issued_at'         => now()->toDateString(),
        'due_date'          => now()->addDays(
            $supplier->setting('invoice_due_days', 14)
        )->toDateString(),
        // Totals (budou přepočítány po zkopírování items)
        'subtotal'          => $quote->subtotal,
        'vat_total'         => $quote->vat_total,
        'total'             => $quote->total,
        // Number
        'invoice_number'    => app(NumberSeriesService::class)
                                    ->nextNumber($supplier, $type === 'proforma' ? 'proforma_invoice' : 'invoice'),
    ]);
}

private function copyItemsToInvoice(Quote $quote, Invoice $invoice): void
{
    foreach ($quote->items as $quoteItem) {
        $invoiceItem = new InvoiceItem([
            'catalog_item_id'  => $quoteItem->catalog_item_id,
            'name'             => $quoteItem->name,
            'unit'             => $quoteItem->unit,
            'quantity'         => $quoteItem->quantity,
            'unit_price'       => $quoteItem->unit_price,
            'price_type'       => $quoteItem->price_type,
            'vat_rate'         => $quoteItem->vat_rate,
            'discount_percent' => $quoteItem->discount_percent,
            'discount_note'    => $quoteItem->discount_note,
            'subtotal'         => $quoteItem->subtotal,
            'vat_amount'       => $quoteItem->vat_amount,
            'total'            => $quoteItem->total,
            'sort_order'       => $quoteItem->sort_order,
        ]);
        $invoice->items()->save($invoiceItem);
    }
}
```

---

## Routes pro konverzi

```php
Route::post('/{quote}/to-invoice',         [QuoteController::class, 'toInvoice'])        ->name('to-invoice');
Route::post('/{quote}/to-proforma-invoice', [QuoteController::class, 'toProformaInvoice'])->name('to-proforma-invoice');
```

```php
// V QuoteController:
public function toInvoice(Quote $quote)
{
    $this->authorize('update', $quote);

    if (!$quote->canBeConverted()) {
        return back()->with('error', 'Tuto nabídku nelze vyfakturovat.');
    }

    $invoice = app(QuoteService::class)->toInvoice($quote);

    return redirect()
        ->route('invoices.show', $invoice)
        ->with('success', "Faktura č. {$invoice->invoice_number} byla vytvořena z nabídky.");
}

public function toProformaInvoice(Quote $quote)
{
    $this->authorize('update', $quote);

    if (!$quote->canBeConverted()) {
        return back()->with('error', 'Tuto nabídku nelze vyfakturovat.');
    }

    $invoice = app(QuoteService::class)->toProformaInvoice($quote);

    return redirect()
        ->route('invoices.show', $invoice)
        ->with('success', "Zálohová faktura č. {$invoice->invoice_number} byla vytvořena.");
}
```

---

## Zobrazení souvisejících dokladů

Na kartě nabídky přidej záložku / sekci **„Související doklady"**:

```blade
@if($quote->invoices->isNotEmpty())
<div class="related-documents">
    <h4>Související doklady</h4>
    <ul>
        @foreach($quote->invoices as $invoice)
        <li>
            <a href="{{ route('invoices.show', $invoice) }}">
                {{ $invoice->invoice_type === 'proforma' ? 'Zálohová faktura' : 'Faktura vydaná' }}
                č. {{ $invoice->invoice_number }}
            </a>
            — {{ number_format($invoice->total, 2, ',', ' ') }} {{ $invoice->currency_code }}
        </li>
        @endforeach
    </ul>
</div>
@endif
```

---

## Copilot pokyny

- Konverze nesmí ovlivnit sklad (`InventoryService` nevolej z QuoteService).
- Z jedné nabídky lze vystavit více zálohových faktur (n:1 vazba), ale jen jednu vydanou fakturu – přidej validaci.
- Po vytvoření faktury přesměruj uživatele na kartu nové faktury (ne zpět na nabídku).
- Pokud projekt má `InvoiceObserver` pro automatické akce po vytvoření faktury, ujisti se, že ho konverze spustí správně.
