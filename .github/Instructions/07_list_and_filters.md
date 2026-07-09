# 07 – Seznam cenových nabídek a filtrování

## Cíl

Implementovat stránku seznamu cenových nabídek s rychlými záložkami, pokročilými filtry a akcemi nad nabídkami.

---

## Navigace

Umístění v menu: **Prodej → Cenové nabídky**  
URL: `/quotes`

---

## Rychlé záložky (tab filters)

| Záložka | Logika dotazu | Badge s počtem |
|---------|--------------|----------------|
| **Všechny** | bez filtru | celkový počet |
| **Schválené** | `status IN ('ordered', 'invoiced')` | počet |
| **V jednání** | `status = 'sent'` | počet (zvýrazni pokud > 0) |
| **Expirované** | `status = 'sent' AND valid_until < TODAY` | počet (červeně pokud > 0) |

---

## Sloupce tabulky

| Sloupec | Popis | Řaditelný |
|---------|-------|-----------|
| Číslo | `quote_number` | ✅ |
| Odběratel | `client_name` | ✅ |
| Datum vystavení | `issued_at` | ✅ |
| Platnost do | `valid_until` | ✅ |
| Celkem | `total` + `currency_code` | ✅ |
| Stav | badge dle `QuoteStatus` | ✅ |
| Akce | ikony: Upravit / Kopírovat / PDF / Smazat | ❌ |

---

## Pokročilé filtry (tlačítko „Filtr")

```php
// QuoteFilter nebo Scope parametry:

// Vystaveno (od–do)
'issued_from' => 'nullable|date',
'issued_to'   => 'nullable|date',

// Platnost (od–do)
'valid_from'  => 'nullable|date',
'valid_to'    => 'nullable|date',

// Rozsah ceny
'price_min'   => 'nullable|numeric|min:0',
'price_max'   => 'nullable|numeric|min:0',

// Hledání textu
'search'      => 'nullable|string|max:100',
// Prohledává: quote_number, client_name, description, order_number
```

---

## QuoteController: index metoda

```php
public function index(Request $request)
{
    $supplier = currentSupplier();
    $tab      = $request->get('tab', 'all'); // all|approved|negotiation|expired

    $query = Quote::forSupplier($supplier->id)
        ->with(['client'])
        ->orderByDesc('issued_at')
        ->orderByDesc('id');

    // Tab filter
    $query = match($tab) {
        'approved'    => $query->approved(),
        'negotiation' => $query->inNegotiation(),
        'expired'     => $query->expired(),
        default       => $query,
    };

    // Pokročilé filtry
    if ($request->filled('issued_from')) {
        $query->where('issued_at', '>=', $request->issued_from);
    }
    if ($request->filled('issued_to')) {
        $query->where('issued_at', '<=', $request->issued_to);
    }
    if ($request->filled('valid_from')) {
        $query->where('valid_until', '>=', $request->valid_from);
    }
    if ($request->filled('valid_to')) {
        $query->where('valid_until', '<=', $request->valid_to);
    }
    if ($request->filled('price_min')) {
        $query->where('total', '>=', $request->price_min);
    }
    if ($request->filled('price_max')) {
        $query->where('total', '<=', $request->price_max);
    }
    if ($request->filled('search')) {
        $s = $request->search;
        $query->where(function ($q) use ($s) {
            $q->where('quote_number', 'like', "%{$s}%")
              ->orWhere('client_name', 'like', "%{$s}%")
              ->orWhere('description', 'like', "%{$s}%")
              ->orWhere('order_number', 'like', "%{$s}%");
        });
    }

    $quotes = $query->paginate(25)->withQueryString();

    // Badge počty pro záložky
    $counts = [
        'all'         => Quote::forSupplier($supplier->id)->count(),
        'approved'    => Quote::forSupplier($supplier->id)->approved()->count(),
        'negotiation' => Quote::forSupplier($supplier->id)->inNegotiation()->count(),
        'expired'     => Quote::forSupplier($supplier->id)->expired()->count(),
    ];

    return view('quotes.index', compact('quotes', 'tab', 'counts'));
}
```

---

## Akce dostupné z lišty nad seznamem

| Ikona | Akce | Podmínka |
|-------|------|----------|
| ➕ | Vystavit novou nabídku | vždy |
| 📋 | Kopírovat vybranou | 1 vybrána |
| 🧾 | Generovat fakturu vydanou | 1 vybrána, stav ≠ invoiced/rejected |
| 💰 | Generovat zálohovou fakturu | 1 vybrána, stav ≠ invoiced/rejected |
| 📤 | Odeslat e-mailem | 1 vybrána |
| 🗑️ | Smazat | 1+ vybrána |

---

## Hromadné operace

- Checkbox na každém řádku + „Vybrat vše"
- Hromadné smazání (soft delete) – max. nabídky ve stavu `draft`
- **Hromadné odesílání e-mailem není součástí v1** (přidej jako TODO komentář)

---

## Copilot pokyny

- Použij existující layout stránky seznam (faktury) jako základ.
- Záložky implementuj jako URL parametr `?tab=approved` (ne JS tabs).
- Počty v badges načti jedním agregačním dotazem (ne N+1).
- Pagination: použij existující stránkovací komponentu z projektu.
- Expirované nabídky v tabulce zobraz s odlišným stylem řádku (např. třídu `row-expired` s kurzívou nebo podbarvením).
