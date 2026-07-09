# Cenové nabídky – přehled specifikací (MyInvoice)

> **Kontext:** Tento adresář obsahuje sadu Copilot zadání pro implementaci modulu **Cenové nabídky** v aplikaci MyInvoice (fork/úprava hulana/myinvoice).  
> Inspirační zdroj: iDoklad dokumentace – funkce cenových nabídek.

## Struktura souborů

| Soubor | Oblast |
|--------|--------|
| `01_db_schema.md` | Databázové schéma – tabulky `quotes`, `quote_items` |
| `02_model_and_enums.md` | PHP/Laravel modely, enumy stavů a typy |
| `03_settings.md` | Nastavení nabídek (platnost, číslování, PDF formát) |
| `04_create_edit_quote.md` | Vytvoření a editace nabídky (karta nabídky) |
| `05_quote_items.md` | Položky nabídky – manuální a z ceníku, slevy |
| `06_quote_statuses.md` | Stavový automat (Vytvořena → Vyfakturována) |
| `07_list_and_filters.md` | Seznam nabídek, filtrování, záložky |
| `08_email_templates.md` | E-mailové šablony a odesílání nabídky |
| `09_quote_to_invoice.md` | Konverze nabídky → faktura / zálohová faktura |
| `10_templates.md` | Šablony nabídek (opakující se nabídky) |
| `11_pdf_export.md` | PDF export a tisk nabídky |
| `12_attachments.md` | Přílohy k nabídce |
| `13_api_endpoints.md` | REST API endpointy pro nabídky |
| `14_mobile_considerations.md` | Mobilní UX specifika |

## Priorita implementace

1. DB schéma + modely (`01`, `02`)
2. Nastavení (`03`)
3. CRUD nabídky + položky (`04`, `05`)
4. Stavy (`06`)
5. Seznam + filtry (`07`)
6. Konverze na fakturu (`09`)
7. E-mail + PDF (`08`, `11`)
8. Šablony (`10`)
9. Přílohy (`12`)
10. API (`13`)

## Technologický stack (MyInvoice)

- **Backend:** PHP (Laravel-based nebo custom MVC – dle aktuální architektury)
- **DB:** MySQL / MariaDB
- **Frontend:** Blade/Twig šablony + Alpine.js nebo vanilla JS
- **PDF:** wkhtmltopdf nebo mPDF (dle existujícího řešení pro faktury)
- **Auth:** session / API token (dle existujícího flow)
