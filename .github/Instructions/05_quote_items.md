# 05 – Položky cenové nabídky

## Cíl

Implementovat sekci položek na kartě cenové nabídky.  
Chování je stejné jako u položek faktury – adaptuj existující komponentu.

---

## Funkcionality

### Přidání položky

Dvě možnosti (stejně jako u faktur):

1. **Ručně** – vyplnit pole Název, Množství, Jednotka, Cena, DPH, Sleva
2. **Z ceníku** – autocomplete z `catalog_items`; po výběru se předvyplní pole

### Pole jedné položky

| Pole | Typ | Povinné | Poznámka |
|------|-----|---------|----------|
| Název | text | ✅ | Max 255 znaků |
| Množství | decimal | ✅ | Výchozí 1 |
| Jednotka | text | ❌ | ks, hod, kg, m², … |
| Cena/jednotku | decimal | ✅ | Dle globálního nastavení `price_type` |
| Typ ceny | enum | ✅ | `with_vat` / `without_vat` (dle nastavení supplieru) |
| Sazba DPH | select | ✅ | 0 %, 12 %, 21 % (dle nastavení supplieru) |
| Sleva % | decimal | ❌ | **Ikona % na konci řádku** → rozbalí extra řádek se slevou |
| Popis slevy | text | ❌ | Zobrazí se na PDF pod položkou |

### Sleva na položku

```
[Název položky] [Množství] [Jedn.] [Cena] [DPH%] [%]  ← kliknutí na ikonu %
  ↓ rozbalí se:
  [Sleva: ____ %] [Popis slevy: ___________________________]
```

### Automatický přepočet

Při každé změně hodnot v položce JavaScript přepočítá:
- mezisoučet bez DPH
- DPH
- celkem s DPH
- totals celého dokladu (spodní řádek)

Přepočet funguje **bez uložení** (live preview).

---

## JavaScript logika přepočtu (Alpine.js nebo vanilla)

```javascript
// Výpočet pro jednu položku
function calcItem(item) {
    const qty       = parseFloat(item.quantity)  || 0;
    const price     = parseFloat(item.unit_price) || 0;
    const vatRate   = parseFloat(item.vat_rate)   || 0;
    const discPct   = parseFloat(item.discount_percent) || 0;
    const priceType = item.price_type; // 'with_vat' | 'without_vat'

    let subtotal, vatAmount, total;

    if (priceType === 'with_vat') {
        total     = qty * price;
        subtotal  = total / (1 + vatRate / 100);
        vatAmount = total - subtotal;
    } else {
        subtotal  = qty * price;
        vatAmount = subtotal * (vatRate / 100);
        total     = subtotal + vatAmount;
    }

    // Apply item discount
    if (discPct > 0) {
        const factor = 1 - discPct / 100;
        subtotal  = round2(subtotal  * factor);
        vatAmount = round2(vatAmount * factor);
        total     = round2(total     * factor);
    }

    return { subtotal: round2(subtotal), vatAmount: round2(vatAmount), total: round2(total) };
}

function round2(v) { return Math.round(v * 100) / 100; }

// Totals across all items
function calcTotals(items, docDiscountPct) {
    let subtotal = 0, vatTotal = 0, total = 0;
    for (const item of items) {
        const c = calcItem(item);
        subtotal += c.subtotal;
        vatTotal += c.vatAmount;
        total    += c.total;
    }
    if (docDiscountPct > 0) {
        const f = 1 - docDiscountPct / 100;
        subtotal = round2(subtotal * f);
        vatTotal = round2(vatTotal * f);
        total    = round2(total    * f);
    }
    return { subtotal, vatTotal, total };
}
```

---

## Přidání položky z ceníku

```javascript
// Po výběru z autocomplete:
function fillItemFromCatalog(catalogItem, itemRow) {
    itemRow.catalog_item_id = catalogItem.id;
    itemRow.name        = catalogItem.name;
    itemRow.unit        = catalogItem.unit;
    itemRow.unit_price  = catalogItem.price;
    itemRow.vat_rate    = catalogItem.vat_rate;
    itemRow.price_type  = catalogItem.price_type;
    // přepočítej
    Object.assign(itemRow, calcItem(itemRow));
}
```

---

## Řazení položek (drag & drop)

- Položky lze přetahovat pro změnu pořadí (využij existující drag&drop z karet faktur).
- Po přeřazení se aktualizují `sort_order` hodnoty.

---

## Speciální chování vs. faktury

> **Cenová nabídka neovlivňuje skladové zásoby!**  
> Při uložení/konverzi nabídky **nevolej** `InventoryService::decreaseStock()` ani žádnou jinou logiku skladu.

---

## Copilot pokyny

- Adaptuj existující Blade komponentu pro položky faktur (`invoice-items` partial nebo ekvivalent).
- Pokud projekt používá Alpine.js, implementuj logiku jako Alpine component.
- Přidej hidden input `items[{idx}][catalog_item_id]` pro uložení vazby na ceník.
- Validaci duplicitního čísla nabídky proveď AJAX voláním před submit.
