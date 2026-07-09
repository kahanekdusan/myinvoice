# 04 – Karta cenové nabídky (vytvoření a editace)

## Cíl

Implementovat formulář pro vytvoření a editaci cenové nabídky.  
Formulář je analogií ke kartě faktury, ale s rozšířeními specifickými pro nabídky.

---

## Routes

```php
// routes/web.php (přidej do skupiny s auth middleware)
Route::prefix('quotes')->name('quotes.')->group(function () {
    Route::get('/',              [QuoteController::class, 'index'])   ->name('index');
    Route::get('/create',        [QuoteController::class, 'create'])  ->name('create');
    Route::post('/',             [QuoteController::class, 'store'])   ->name('store');
    Route::get('/{quote}',       [QuoteController::class, 'show'])    ->name('show');
    Route::get('/{quote}/edit',  [QuoteController::class, 'edit'])    ->name('edit');
    Route::put('/{quote}',       [QuoteController::class, 'update'])  ->name('update');
    Route::delete('/{quote}',    [QuoteController::class, 'destroy']) ->name('destroy');
    Route::post('/{quote}/copy', [QuoteController::class, 'copy'])    ->name('copy');
});
```

---

## Pole na kartě nabídky

### Hlavička (vždy viditelná)

| Pole | Povinné | Poznámka |
|------|---------|----------|
| Číslo nabídky | auto | Generuje se z číselné řady; editovatelné přes ikonu tužky (validace na duplicitu) |
| Šablona | ne | Dropdown ze `quote_templates` – po výběru předvyplní pole |
| Štítky (Labels) | ne | Multi-select, sdílené s fakturami (tabulka `labels`) |
| Odběratel | doporučené | Autocomplete z `clients`; tlačítko „+" pro rychlé založení |
| Dodací adresa je jiná | ne | Checkbox, zobrazí se až po výběru odběratele; rozbalí extra pole |
| Stav nabídky | ano | Select dle `QuoteStatus` enum (výchozí: `draft`) |
| Číslo objednávky/smlouvy | ne | Volný text |
| Popis | ne | Krátký název (auto-fill = první položka nabídky, pokud prázdné) |
| Datum vystavení | ano | Default = dnes |
| Platnost do | ano | Default = vystavení + `quote_validity_days` dní |
| Způsob úhrady | doporučené | Select dle číselníku způsobů úhrady |
| Bankovní účet | doporučené | Select z bankovních účtů supplieru |

### Rozšířené podrobnosti (po kliknutí na „Podrobnosti" / expandable)

| Pole | Poznámka |
|------|----------|
| Měna | Select; cizí měna → zobrazí pole Kurz (auto-načte z ČNB/ECB, editovatelné) |
| Interní poznámka | Textarea; **nezobrazuje se na PDF** |
| Text před položkami | Textarea; přenáší se na vygenerovanou fakturu |
| Text za položkami | Textarea; přenáší se na vygenerovanou fakturu |

---

## Controller: QuoteController

```php
<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteTemplate;
use App\Services\QuoteService;
use App\Services\NumberSeriesService;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function __construct(
        private QuoteService $quoteService,
        private NumberSeriesService $numberSeriesService,
    ) {}

    public function index()
    {
        // → viz 07_list_and_filters.md
    }

    public function create()
    {
        $defaults = $this->quoteService->buildDefaults(currentSupplier());
        $templates = QuoteTemplate::forSupplier(currentSupplier()->id)->get();

        return view('quotes.create', compact('defaults', 'templates'));
    }

    public function store(StoreQuoteRequest $request)
    {
        $quote = $this->quoteService->create(
            currentSupplier(),
            $request->validated()
        );

        // Handle "Další akce" button
        return match ($request->input('action')) {
            'send'  => redirect()->route('quotes.send', $quote)->with('success', 'Nabídka uložena a odeslána.'),
            'pdf'   => redirect()->route('quotes.pdf',  $quote),
            'print' => redirect()->route('quotes.print', $quote),
            default => redirect()->route('quotes.show', $quote)->with('success', 'Nabídka uložena.'),
        };
    }

    public function edit(Quote $quote)
    {
        $this->authorize('update', $quote);
        $templates = QuoteTemplate::forSupplier(currentSupplier()->id)->get();
        return view('quotes.edit', compact('quote', 'templates'));
    }

    public function update(UpdateQuoteRequest $request, Quote $quote)
    {
        $this->authorize('update', $quote);
        $this->quoteService->update($quote, $request->validated());
        return redirect()->route('quotes.show', $quote)->with('success', 'Nabídka aktualizována.');
    }

    public function destroy(Quote $quote)
    {
        $this->authorize('delete', $quote);
        $quote->delete(); // soft delete
        return redirect()->route('quotes.index')->with('success', 'Nabídka smazána.');
    }

    public function copy(Quote $quote)
    {
        $this->authorize('view', $quote);
        $newQuote = $this->quoteService->copy($quote);
        return redirect()->route('quotes.edit', $newQuote)->with('success', 'Nabídka zkopírována.');
    }
}
```

---

## FormRequest: StoreQuoteRequest

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id'           => 'nullable|integer|exists:clients,id',
            'status'              => 'required|string|in:draft,sent,ordered,invoiced,rejected',
            'template_id'         => 'nullable|integer|exists:quote_templates,id',
            'description'         => 'nullable|string|max:255',
            'order_number'        => 'nullable|string|max:100',
            'note'                => 'nullable|string',
            'text_before_items'   => 'nullable|string',
            'text_after_items'    => 'nullable|string',
            'payment_method'      => 'nullable|string|max:50',
            'bank_account_id'     => 'nullable|integer|exists:bank_accounts,id',
            'currency_code'       => 'required|string|size:3',
            'exchange_rate'       => 'required|numeric|min:0.0001',
            'discount_percent'    => 'nullable|numeric|min:0|max:100',
            'issued_at'           => 'required|date',
            'valid_until'         => 'nullable|date|after_or_equal:issued_at',
            // Dodací adresa
            'delivery_name'       => 'nullable|string|max:200',
            'delivery_street'     => 'nullable|string|max:200',
            'delivery_city'       => 'nullable|string|max:100',
            'delivery_zip'        => 'nullable|string|max:20',
            'delivery_country'    => 'nullable|string|max:100',
            // Položky
            'items'               => 'required|array|min:1',
            'items.*.name'        => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.0001',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.vat_rate'    => 'required|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
```

---

## QuoteService: základní metody

```php
<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Supplier;
use App\Enums\QuoteStatus;

class QuoteService
{
    public function buildDefaults(Supplier $supplier): array
    {
        return [
            'issued_at'       => now()->toDateString(),
            'valid_until'     => now()->addDays(
                $supplier->setting('quote_validity_days', 14)
            )->toDateString(),
            'payment_method'  => $supplier->setting('quote_default_payment_method'),
            'bank_account_id' => $supplier->setting('quote_default_bank_account_id')
                                 ?? $supplier->mainBankAccount()?->id,
            'currency_code'   => 'CZK',
            'exchange_rate'   => 1.0,
        ];
    }

    public function create(Supplier $supplier, array $data): Quote
    {
        $quote = new Quote($data);
        $quote->supplier_id  = $supplier->id;
        $quote->status       = QuoteStatus::Draft;
        $quote->quote_number = app(NumberSeriesService::class)
            ->nextNumber($supplier, 'quote');

        // Snapshot klienta
        if ($quote->client_id) {
            $this->snapshotClient($quote);
        }

        $quote->save();

        // Uložení položek
        foreach ($data['items'] ?? [] as $idx => $itemData) {
            $item = new QuoteItem($itemData);
            $item->sort_order = $idx;
            $item->recalculate();
            $quote->items()->save($item);
        }

        $quote->recalculateTotals();
        $quote->save();

        return $quote;
    }

    public function update(Quote $quote, array $data): Quote
    {
        $quote->fill($data);

        if (isset($data['client_id']) && $data['client_id'] !== $quote->getOriginal('client_id')) {
            $this->snapshotClient($quote);
        }

        // Sync items
        $quote->items()->delete();
        foreach ($data['items'] ?? [] as $idx => $itemData) {
            $item = new QuoteItem($itemData);
            $item->sort_order = $idx;
            $item->recalculate();
            $quote->items()->save($item);
        }

        $quote->recalculateTotals();
        $quote->save();

        return $quote;
    }

    public function copy(Quote $quote): Quote
    {
        $newQuote = $quote->replicate(['quote_number', 'status', 'idoklad_id']);
        $newQuote->status       = QuoteStatus::Draft;
        $newQuote->quote_number = app(NumberSeriesService::class)
            ->nextNumber($quote->supplier, 'quote');
        $newQuote->issued_at    = now()->toDateString();
        $newQuote->valid_until  = now()->addDays(
            $quote->supplier->setting('quote_validity_days', 14)
        )->toDateString();
        $newQuote->save();

        foreach ($quote->items as $item) {
            $newItem = $item->replicate(['quote_id']);
            $newQuote->items()->save($newItem);
        }

        return $newQuote;
    }

    private function snapshotClient(Quote $quote): void
    {
        $client = $quote->client;
        if (!$client) return;

        $quote->client_name    = $client->name;
        $quote->client_street  = $client->street;
        $quote->client_city    = $client->city;
        $quote->client_zip     = $client->zip;
        $quote->client_country = $client->country;
        $quote->client_ic      = $client->ic;
        $quote->client_dic     = $client->dic;
    }
}
```

---

## Ovládací tlačítka na formuláři

```html
<!-- Spodní lišta formuláře -->
<div class="form-actions">
    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Zrušit</button>

    <div class="btn-group">
        <button type="submit" name="action" value="save" class="btn btn-primary">Uložit</button>
        <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">
            Další akce ▾
        </button>
        <ul class="dropdown-menu">
            <li><button type="submit" name="action" value="send">Odeslat a uložit</button></li>
            <li><button type="submit" name="action" value="print">Vytisknout a uložit</button></li>
            <li><button type="submit" name="action" value="pdf">Exportovat do PDF a uložit</button></li>
        </ul>
    </div>
</div>
```

---

## Copilot pokyny

- View soubory umísti do `resources/views/quotes/` (create.blade.php, edit.blade.php, show.blade.php).
- Použij existující layout z projektu (ten samý co faktury).
- Pole „Dodací adresa" zobraz/skryj JavaScriptem po zaškrtnutí checkboxu.
- Autocomplete odběratele adaptuj na existující komponentu z karet faktur.
- Snapshot klienta: při změně odběratele auto-vyplň client_* pole z dat klienta přes AJAX.
- Měna: při změně měny na cizí → zobraz pole „Kurz" a načti aktuální kurz z ČNB API.
