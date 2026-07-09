# 02 – Modely a enumy: Cenové nabídky

## Cíl

Vytvořit PHP modely a enum třídy pro modul cenových nabídek, konzistentní s existujícími modely v projektu MyInvoice.

---

## QuoteStatus enum

```php
<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft    = 'draft';       // Vytvořena – výchozí stav
    case Sent     = 'sent';        // Odeslána – nastaví se automaticky po odeslání e-mailem
    case Ordered  = 'ordered';     // Objednána – auto při gen. zálohové faktury, nebo ručně
    case Invoiced = 'invoiced';    // Vyfakturována – auto při gen. vydané faktury
    case Rejected = 'rejected';    // Zamítnuta – pouze ručně

    public function label(): string
    {
        return match($this) {
            self::Draft    => 'Vytvořena',
            self::Sent     => 'Odeslána',
            self::Ordered  => 'Objednána',
            self::Invoiced => 'Vyfakturována',
            self::Rejected => 'Zamítnuta',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft    => 'gray',
            self::Sent     => 'blue',
            self::Ordered  => 'orange',
            self::Invoiced => 'green',
            self::Rejected => 'red',
        };
    }

    /** Stavy, které lze nastavit RUČNĚ (nejen automaticky) */
    public static function manuallySettable(): array
    {
        return [self::Draft, self::Sent, self::Ordered, self::Invoiced, self::Rejected];
    }
}
```

---

## Model Quote

```php
<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_id', 'client_id', 'quote_number', 'number_series_id',
        'status', 'template_id',
        // client snapshot
        'client_name', 'client_street', 'client_city', 'client_zip',
        'client_country', 'client_ic', 'client_dic',
        // delivery
        'delivery_name', 'delivery_street', 'delivery_city',
        'delivery_zip', 'delivery_country',
        // content
        'description', 'order_number', 'note',
        'text_before_items', 'text_after_items',
        // payment
        'payment_method', 'bank_account_id', 'currency_code', 'exchange_rate',
        'discount_percent',
        // totals
        'subtotal', 'vat_total', 'total',
        // dates
        'issued_at', 'valid_until',
        'idoklad_id',
    ];

    protected $casts = [
        'status'           => QuoteStatus::class,
        'issued_at'        => 'date',
        'valid_until'      => 'date',
        'discount_percent' => 'float',
        'subtotal'         => 'float',
        'vat_total'        => 'float',
        'total'            => 'float',
        'exchange_rate'    => 'float',
    ];

    // ── Relations ──────────────────────────────────────────────────────

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }

    public function attachments()
    {
        return $this->hasMany(QuoteAttachment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'source_quote_id');
    }

    public function template()
    {
        return $this->belongsTo(QuoteTemplate::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function numberSeries()
    {
        return $this->belongsTo(NumberSeries::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeStatus($query, QuoteStatus $status)
    {
        return $query->where('status', $status);
    }

    /** Schválené = Objednána nebo Vyfakturována */
    public function scopeApproved($query)
    {
        return $query->whereIn('status', [QuoteStatus::Ordered, QuoteStatus::Invoiced]);
    }

    /** V jednání = Odeslána (čeká na reakci) */
    public function scopeInNegotiation($query)
    {
        return $query->where('status', QuoteStatus::Sent);
    }

    /** Expirované = V jednání + platnost vypršela */
    public function scopeExpired($query)
    {
        return $query->where('status', QuoteStatus::Sent)
                     ->where('valid_until', '<', now()->toDateString());
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->status === QuoteStatus::Sent
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }

    public function canBeConverted(): bool
    {
        return in_array($this->status, [
            QuoteStatus::Draft,
            QuoteStatus::Sent,
            QuoteStatus::Ordered,
        ]);
    }

    /** Recalculate totals from items */
    public function recalculateTotals(): void
    {
        $this->subtotal  = $this->items->sum('subtotal');
        $this->vat_total = $this->items->sum('vat_amount');
        $this->total     = $this->items->sum('total');

        // Apply document-level discount
        if ($this->discount_percent > 0) {
            $factor          = 1 - ($this->discount_percent / 100);
            $this->subtotal  = round($this->subtotal  * $factor, 2);
            $this->vat_total = round($this->vat_total * $factor, 2);
            $this->total     = round($this->total     * $factor, 2);
        }
    }
}
```

---

## Model QuoteItem

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quote_id', 'catalog_item_id', 'name', 'unit',
        'quantity', 'unit_price', 'price_type', 'vat_rate',
        'discount_percent', 'discount_note',
        'subtotal', 'vat_amount', 'total', 'sort_order',
    ];

    protected $casts = [
        'quantity'         => 'float',
        'unit_price'       => 'float',
        'vat_rate'         => 'float',
        'discount_percent' => 'float',
        'subtotal'         => 'float',
        'vat_amount'       => 'float',
        'total'            => 'float',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /**
     * Přepočítá subtotal, vat_amount, total z quantity, unit_price, vat_rate, discount_percent.
     * Respektuje price_type (with_vat / without_vat).
     */
    public function recalculate(): void
    {
        $basePrice = $this->quantity * $this->unit_price;

        if ($this->price_type === 'with_vat') {
            $total         = $basePrice;
            $subtotal      = $total / (1 + $this->vat_rate / 100);
            $vatAmount     = $total - $subtotal;
        } else {
            $subtotal      = $basePrice;
            $vatAmount     = $subtotal * ($this->vat_rate / 100);
            $total         = $subtotal + $vatAmount;
        }

        // Apply item-level discount
        if ($this->discount_percent > 0) {
            $factor    = 1 - ($this->discount_percent / 100);
            $subtotal  = round($subtotal  * $factor, 2);
            $vatAmount = round($vatAmount * $factor, 2);
            $total     = round($total     * $factor, 2);
        }

        $this->subtotal   = round($subtotal,  2);
        $this->vat_amount = round($vatAmount, 2);
        $this->total      = round($total,     2);
    }
}
```

---

## Copilot pokyny

- Drž se struktury existujících modelů (Invoice, InvoiceItem) pro konzistenci.
- Enum `QuoteStatus` vlož do `app/Enums/` – stejná složka jako ostatní enumy projektu.
- Přidej `QuoteObserver` pro automatický recalculate při save items (pokud projekt observery používá).
- Pokud projekt nepoužívá Laravel Eloquent, adaptuj modely na aktuální ORM/query builder.
