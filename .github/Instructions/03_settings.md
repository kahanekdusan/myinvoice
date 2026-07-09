# 03 – Nastavení cenových nabídek

## Cíl

Rozšířit stávající stránku **Nastavení → Prodej** o sekci pro cenové nabídky.  
Nastavení se ukládají do tabulky `supplier_settings` (nebo ekvivalent v projektu) per supplier.

---

## Nová nastavení (přidat k existujícím)

### A) Výchozí nastavení (záložka „Výchozí nastavení")

| Klíč nastavení | Typ | Výchozí hodnota | Popis |
|---|---|---|---|
| `quote_validity_days` | `int` | `14` | Počet dní platnosti nové nabídky (přičte se k datu vystavení) |
| `quote_default_payment_method` | `string` | (dle globálního nastavení) | Výchozí způsob úhrady na nabídce |
| `quote_default_bank_account_id` | `int\|null` | (hlavní bankovní účet) | Výchozí bankovní účet |

**UI:** Přidej do sekce Výchozí nastavení nový řádek:

```
Platnost cenových nabídek: [  14  ] dní
```

_(Hodnotu lze přepsat přímo na kartě konkrétní nabídky.)_

---

### B) Vzhled a tisk dokladů (záložka „Vzhled a tisk")

| Klíč nastavení | Typ | Výchozí hodnota | Popis |
|---|---|---|---|
| `quote_pdf_filename_format` | `string` | `Cenova-nabidka-{number}` | Formát názvu PDF souboru |

**Dostupné proměnné pro formát PDF názvu:**
- `{number}` – číslo nabídky
- `{client}` – název odběratele
- `{date}` – datum vystavení (YYYY-MM-DD)

---

### C) Číslování (záložka „Číslování")

Přidej do existující záložky Číslování podporu pro číselné řady cenových nabídek.

**Typ dokladu v číselných řadách:** `quote`

Formát čísla používá stejné proměnné jako faktury:
- `{N}` – pořadové číslo (povinné, max 4 znaky)
- `{D}` – den (max 2 znaky)
- `{M}` – měsíc (max 2 znaky)
- `{R}` – rok (max 4 znaky)

**Příklady formátů:**
- `CN{R}{N}` → `CN20260001`
- `{R}-{N}` → `2026-0001`
- `NO{R}{M}{N}` → `NO20260701`

---

## Validační pravidla nastavení

```php
// V SettingsController nebo FormRequest:
'quote_validity_days'            => 'required|integer|min:1|max:365',
'quote_pdf_filename_format'      => 'nullable|string|max:100',
'quote_default_payment_method'   => 'nullable|string|max:50',
'quote_default_bank_account_id'  => 'nullable|integer|exists:bank_accounts,id',
```

---

## Čtení nastavení při vytváření nabídky

```php
// V QuoteController::create() nebo QuoteService::buildDefaults():
$validityDays = $supplier->setting('quote_validity_days', 14);
$quote->valid_until = now()->addDays($validityDays)->toDateString();
$quote->payment_method = $supplier->setting('quote_default_payment_method')
    ?? $supplier->setting('default_payment_method');
$quote->bank_account_id = $supplier->setting('quote_default_bank_account_id')
    ?? $supplier->mainBankAccount()?->id;
```

---

## Copilot pokyny

- Najdi existující metodu/controller pro Nastavení → Prodej a přidej pole tam (netvořit novou stránku).
- Klíče nastavení ulož do stávající tabulky nastavení (`supplier_settings`, `settings`, nebo jiné dle projektu).
- Přidej překlad klíčů do `resources/lang/cs/settings.php` (nebo ekvivalentní i18n soubor).
- Formulář uložení musí respektovat existující CSRF a validaci ostatních polí (nerozebít stávající).
