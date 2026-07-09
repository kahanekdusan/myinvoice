# 11 – PDF export a tisk cenové nabídky

## Cíl

Generovat PDF cenové nabídky a umožnit tisk – analogie k PDF faktur.

---

## QuotePdfService

```php
<?php

namespace App\Services;

use App\Models\Quote;

class QuotePdfService
{
    /**
     * Generuje PDF a vrátí binární obsah.
     */
    public function generate(Quote $quote): string
    {
        $html = view('quotes.pdf', ['quote' => $quote])->render();

        // Použij stejný PDF engine jako pro faktury (mPDF / wkhtmltopdf / DomPDF):
        return app('pdf')->generateFromHtml($html);
    }

    /**
     * Generuje PDF do temp souboru a vrátí cestu (pro e-mailovou přílohu).
     */
    public function generateTemp(Quote $quote): string
    {
        $content  = $this->generate($quote);
        $filename = sys_get_temp_dir() . '/' . uniqid('quote_') . '.pdf';
        file_put_contents($filename, $content);
        return $filename;
    }

    /**
     * Název PDF souboru dle nastavení supplieru.
     */
    public function filename(Quote $quote): string
    {
        $format = $quote->supplier->setting(
            'quote_pdf_filename_format',
            'Cenova-nabidka-{number}'
        );
        return str_replace(
            ['{number}', '{client}', '{date}'],
            [
                $quote->quote_number,
                $quote->client_name,
                $quote->issued_at?->format('Y-m-d'),
            ],
            $format
        ) . '.pdf';
    }
}
```

---

## Routes

```php
Route::get('/{quote}/pdf',   [QuoteController::class, 'pdf'])  ->name('pdf');
Route::get('/{quote}/print', [QuoteController::class, 'print'])->name('print');
```

```php
// V QuoteController:
public function pdf(Quote $quote)
{
    $this->authorize('view', $quote);
    $pdf      = app(QuotePdfService::class)->generate($quote);
    $filename = app(QuotePdfService::class)->filename($quote);

    return response($pdf, 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => "attachment; filename=\"{$filename}\"",
    ]);
}

public function print(Quote $quote)
{
    $this->authorize('view', $quote);
    return view('quotes.print', compact('quote'));
}
```

---

## PDF šablona: `resources/views/quotes/pdf.blade.php`

Použij stejný layout jako faktury (`invoices/pdf.blade.php`), nahraď:
- „Faktura č." → „Cenová nabídka č."
- „Datum splatnosti" → „Platnost do"
- Odstraň: DIČ dodavatele sloupeček splatnosti pokud nabídka nemá (dle nastavení)
- Přidej: datum vystavení + datum platnosti nabídky
- Přidej: zápatí s datem tisku (dle nastavení `print_date_in_footer`)

---

## Copilot pokyny

- Neimplementuj vlastní PDF engine – použij ten, co projekt již používá pro faktury.
- View `quotes/pdf.blade.php` zkopíruj z `invoices/pdf.blade.php` a uprav (viz výše).
- Formát názvu PDF souboru čti z `supplier_settings['quote_pdf_filename_format']`.
- Datum tisku v zápatí: zobraz jen pokud `supplier_settings['print_date_in_footer'] = true`.
