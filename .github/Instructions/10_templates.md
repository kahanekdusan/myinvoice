# 10 – Šablony cenových nabídek

## Cíl

Implementovat šablony pro cenové nabídky – umožnit uložit přednastavená data a opakovaně je použít.

---

## Umístění v menu

**Prodej → Šablony** (sdílená stránka se šablonami faktur)  
Přidej typ `quote` do existujícího dropdown „Typ dokladu".

---

## Životní cyklus šablony

```
1. Vytvoření šablony:
   Prodej → Šablony → Nový → Šablona
   Vyber Typ dokladu: "Cenová nabídka"

2. Použití šablony:
   Prodej → Cenové nabídky → Nová nabídka
   V poli "Šablona" vyber ze seznamu → předvyplní formulář

3. Šablona přepíše pouze vyplněná pole (prázdná pole nechá prázdná)
```

---

## Pole šablony (navíc oproti běžné nabídce)

| Pole | Typ | Popis |
|------|-----|-------|
| `name` | string | Název šablony (zobrazuje se v dropdown) |
| `valid_days` | int\|null | Přepíše globální platnost nabídky (pokud vyplněno) |
| `fixed_variable_symbol` | string\|null | Pevný VS pro všechny nabídky ze šablony |
| `fixed_exchange_rate` | decimal\|null | Pevný kurz měny (zobrazí se jen pro cizí měnu) |

---

## QuoteTemplateController

```php
Route::resource('quote-templates', QuoteTemplateController::class);
```

```php
// NEBO integruj do existujícího TemplateController:
// Přidej větev pro type=quote do store/update/destroy

public function store(Request $request)
{
    $type = $request->input('type'); // 'invoice', 'proforma', 'quote'

    if ($type === 'quote') {
        return $this->storeQuoteTemplate($request);
    }
    // ... existující logika pro faktury
}
```

---

## Aplikace šablony na formulář nabídky (JS)

```javascript
// Při výběru šablony z dropdown:
async function applyTemplate(templateId, formInstance) {
    const response = await fetch(`/quote-templates/${templateId}/data`);
    const tmpl = await response.json();

    // Předvyplnění polí
    if (tmpl.client_id)          formInstance.client_id          = tmpl.client_id;
    if (tmpl.payment_method)     formInstance.payment_method     = tmpl.payment_method;
    if (tmpl.bank_account_id)    formInstance.bank_account_id    = tmpl.bank_account_id;
    if (tmpl.currency_code)      formInstance.currency_code      = tmpl.currency_code;
    if (tmpl.text_before_items)  formInstance.text_before_items  = tmpl.text_before_items;
    if (tmpl.text_after_items)   formInstance.text_after_items   = tmpl.text_after_items;
    if (tmpl.note)               formInstance.note               = tmpl.note;
    if (tmpl.valid_days) {
        formInstance.valid_until = addDays(formInstance.issued_at, tmpl.valid_days);
    }

    // Předvyplnění položek (nahradí stávající)
    if (tmpl.items && tmpl.items.length > 0) {
        formInstance.items = tmpl.items.map(i => ({...i, id: null}));
    }
}
```

---

## API endpoint pro data šablony

```php
Route::get('/quote-templates/{template}/data', function (QuoteTemplate $template) {
    return response()->json(
        $template->load('items')->toArray()
    );
})->name('quote-templates.data');
```

---

## Copilot pokyny

- Šablony jsou per-supplier (vždy filtruj podle `supplier_id`).
- Šablona neobsahuje datum vystavení ani číslo nabídky – ty se generují vždy čerstvě.
- Pokud projekt má `TemplateController` pro faktury, rozšiř ho o typ `quote`; jinak vytvoř `QuoteTemplateController`.
- Dropdown šablon na kartě nabídky filtruj pouze na `type=quote`.
