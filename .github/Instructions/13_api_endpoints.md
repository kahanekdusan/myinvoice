# 13 – REST API endpointy pro cenové nabídky

## Cíl

Implementovat REST API endpointy pro modul cenových nabídek, konzistentní s existujícím API projektu.

---

## Autentizace

Použij existující API auth mechanismus projektu (API token / Bearer token / session).

---

## Endpointy

### Quotes (Cenové nabídky)

| Metoda | URL | Popis |
|--------|-----|-------|
| `GET`    | `/api/v1/quotes` | Seznam nabídek (stránkované) |
| `POST`   | `/api/v1/quotes` | Vytvoření nabídky |
| `GET`    | `/api/v1/quotes/{id}` | Detail nabídky |
| `PUT`    | `/api/v1/quotes/{id}` | Aktualizace nabídky |
| `DELETE` | `/api/v1/quotes/{id}` | Smazání nabídky (soft delete) |
| `POST`   | `/api/v1/quotes/{id}/send` | Odeslání e-mailem |
| `POST`   | `/api/v1/quotes/{id}/to-invoice` | Konverze na fakturu |
| `POST`   | `/api/v1/quotes/{id}/to-proforma` | Konverze na zálohu |
| `GET`    | `/api/v1/quotes/{id}/pdf` | Stažení PDF |
| `POST`   | `/api/v1/quotes/{id}/copy` | Zkopírování nabídky |

---

## Query parametry pro GET /api/v1/quotes

```
?page=1
?per_page=25
?tab=all|approved|negotiation|expired
?status=draft|sent|ordered|invoiced|rejected
?issued_from=2026-01-01
?issued_to=2026-12-31
?valid_from=2026-01-01
?valid_to=2026-12-31
?price_min=1000
?price_max=50000
?search=text
?sort=issued_at|total|quote_number
?direction=asc|desc
```

---

## API Resource: QuoteResource

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'quote_number'   => $this->quote_number,
            'status'         => $this->status->value,
            'status_label'   => $this->status->label(),
            'is_expired'     => $this->isExpired(),
            'description'    => $this->description,
            'order_number'   => $this->order_number,
            'client'         => [
                'id'      => $this->client_id,
                'name'    => $this->client_name,
                'ic'      => $this->client_ic,
                'dic'     => $this->client_dic,
                'street'  => $this->client_street,
                'city'    => $this->client_city,
                'zip'     => $this->client_zip,
                'country' => $this->client_country,
            ],
            'payment_method'  => $this->payment_method,
            'bank_account_id' => $this->bank_account_id,
            'currency_code'   => $this->currency_code,
            'exchange_rate'   => $this->exchange_rate,
            'discount_percent'=> $this->discount_percent,
            'subtotal'        => $this->subtotal,
            'vat_total'       => $this->vat_total,
            'total'           => $this->total,
            'issued_at'       => $this->issued_at?->toDateString(),
            'valid_until'     => $this->valid_until?->toDateString(),
            'items'           => QuoteItemResource::collection($this->whenLoaded('items')),
            'invoices_count'  => $this->invoices()->count(),
            'created_at'      => $this->created_at->toIso8601String(),
            'updated_at'      => $this->updated_at->toIso8601String(),
        ];
    }
}
```

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuoteItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'catalog_item_id'  => $this->catalog_item_id,
            'name'             => $this->name,
            'unit'             => $this->unit,
            'quantity'         => $this->quantity,
            'unit_price'       => $this->unit_price,
            'price_type'       => $this->price_type,
            'vat_rate'         => $this->vat_rate,
            'discount_percent' => $this->discount_percent,
            'discount_note'    => $this->discount_note,
            'subtotal'         => $this->subtotal,
            'vat_amount'       => $this->vat_amount,
            'total'            => $this->total,
            'sort_order'       => $this->sort_order,
        ];
    }
}
```

---

## API Controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Quote;
use App\Services\QuoteService;
use App\Http\Resources\QuoteResource;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use Illuminate\Http\Request;

class QuoteApiController extends Controller
{
    public function index(Request $request)
    {
        $quotes = $this->buildQuery($request)
            ->with(['items', 'client'])
            ->paginate($request->input('per_page', 25));

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request)
    {
        $quote = app(QuoteService::class)->create(currentSupplier(), $request->validated());
        return new QuoteResource($quote->load('items'));
    }

    public function show(Quote $quote)
    {
        $this->authorize('view', $quote);
        return new QuoteResource($quote->load(['items', 'attachments', 'invoices']));
    }

    public function update(UpdateQuoteRequest $request, Quote $quote)
    {
        $this->authorize('update', $quote);
        app(QuoteService::class)->update($quote, $request->validated());
        return new QuoteResource($quote->fresh()->load('items'));
    }

    public function destroy(Quote $quote)
    {
        $this->authorize('delete', $quote);
        $quote->delete();
        return response()->noContent();
    }

    public function toInvoice(Quote $quote)
    {
        $this->authorize('update', $quote);
        $invoice = app(QuoteService::class)->toInvoice($quote);
        return response()->json(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]);
    }

    public function toProforma(Quote $quote)
    {
        $this->authorize('update', $quote);
        $invoice = app(QuoteService::class)->toProformaInvoice($quote);
        return response()->json(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number]);
    }
}
```

---

## Chybové odpovědi

Použij stejnou strukturu jako existující API projektu:

```json
{
  "message": "Tuto nabídku nelze vyfakturovat.",
  "errors": {
    "status": ["Nabídka je ve stavu 'invoiced' a nelze ji znovu vyfakturovat."]
  }
}
```

---

## Copilot pokyny

- Registruj API routes ve `routes/api.php` (nebo kde projekt má API routes).
- Přidej `QuotePolicy` (nebo rozšiř existující SupplierPolicy) pro autorizaci.
- Dokumentaci API (Swagger/OpenAPI) přidej pokud projekt ji má.
- Rate limiting: aplikuj stejný limit jako ostatní API endpointy projektu.
