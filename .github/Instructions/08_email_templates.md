# 08 – E-mailové šablony a odesílání cenové nabídky

## Cíl

Implementovat šablonu e-mailu pro cenovou nabídku a odesílací flow – analogie k odesílání faktur.

---

## Šablona e-mailu „Cenová nabídka"

Přidej do existující sekce **Nastavení → E-maily** novou šablonu:

### Klíč šablony: `quote`

```php
// Výchozí obsah šablony (seed nebo migration):
[
    'key'     => 'quote',
    'subject' => '{company_name}: Cenová nabídka č. {quote_number}',
    'body'    => "Dobrý den,\n\nv příloze zasíláme cenovou nabídku č. {quote_number} na částku {total} {currency}.\n\nNabídka je platná do {valid_until}.\n\nV případě dotazů nás neváhejte kontaktovat.\n\nS pozdravem\n{sender_name}\n{company_name}",
]
```

### Dostupné proměnné (zástupné znaky)

| Proměnná | Hodnota |
|----------|---------|
| `{quote_number}` | Číslo cenové nabídky |
| `{description}` | Popis nabídky |
| `{total}` | Celková částka (formátovaná) |
| `{currency}` | Kód měny |
| `{issued_at}` | Datum vystavení |
| `{valid_until}` | Datum platnosti |
| `{client_name}` | Název odběratele |
| `{company_name}` | Název vaší firmy |
| `{sender_name}` | Jméno odesílatele |
| `{bank_account}` | Číslo bankovního účtu |

---

## QuoteMailService

```php
<?php

namespace App\Services;

use App\Models\Quote;
use App\Enums\QuoteStatus;
use App\Mail\QuoteMail;
use Illuminate\Support\Facades\Mail;

class QuoteMailService
{
    public function __construct(
        private QuoteStatusService $statusService,
        private QuotePdfService $pdfService,
    ) {}

    /**
     * Odešle cenovou nabídku zákazníkovi.
     * Po odeslání automaticky přejde stav na 'sent'.
     */
    public function sendToClient(
        Quote $quote,
        string $toEmail,
        string $subject,
        string $body,
        bool $attachPdf = true,
        array $cc = [],
    ): void {
        $mailable = new QuoteMail(
            quote:       $quote,
            subject:     $subject,
            body:        $body,
            attachPdf:   $attachPdf,
        );

        if (!empty($cc)) {
            $mailable->cc($cc);
        }

        Mail::to($toEmail)->send($mailable);

        // Automatický přechod stavu
        if ($quote->status === QuoteStatus::Draft) {
            $this->statusService->transitionTo($quote, QuoteStatus::Sent, 'Sent via email');
        }
    }

    /**
     * Předvyplní data pro odesílací formulář.
     */
    public function buildMailForm(Quote $quote): array
    {
        $template = $quote->supplier->emailTemplate('quote');
        $vars     = $this->buildVars($quote);

        return [
            'to'      => $quote->client?->email ?? '',
            'subject' => $this->interpolate($template['subject'] ?? '', $vars),
            'body'    => $this->interpolate($template['body']    ?? '', $vars),
        ];
    }

    private function buildVars(Quote $quote): array
    {
        return [
            '{quote_number}' => $quote->quote_number,
            '{description}'  => $quote->description ?? '',
            '{total}'        => number_format($quote->total, 2, ',', ' '),
            '{currency}'     => $quote->currency_code,
            '{issued_at}'    => $quote->issued_at?->format('d.m.Y'),
            '{valid_until}'  => $quote->valid_until?->format('d.m.Y'),
            '{client_name}'  => $quote->client_name ?? '',
            '{company_name}' => $quote->supplier->name,
            '{sender_name}'  => auth()->user()?->name ?? $quote->supplier->name,
            '{bank_account}' => $quote->bankAccount?->account_number ?? '',
        ];
    }

    private function interpolate(string $template, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $template);
    }
}
```

---

## Mailable: QuoteMail

```php
<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\QuotePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote  $quote,
        public string $subject,
        public string $body,
        public bool   $attachPdf = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote',
            with: ['body' => $this->body, 'quote' => $this->quote],
        );
    }

    public function attachments(): array
    {
        if (!$this->attachPdf) {
            return [];
        }

        $pdfService = app(QuotePdfService::class);
        $pdfPath    = $pdfService->generateTemp($this->quote);

        return [
            Attachment::fromPath($pdfPath)
                ->as($this->buildPdfFilename())
                ->withMime('application/pdf'),
        ];
    }

    private function buildPdfFilename(): string
    {
        $format = $this->quote->supplier->setting(
            'quote_pdf_filename_format',
            'Cenova-nabidka-{number}'
        );
        return str_replace(
            ['{number}', '{client}', '{date}'],
            [$this->quote->quote_number, $this->quote->client_name, $this->quote->issued_at?->format('Y-m-d')],
            $format
        ) . '.pdf';
    }
}
```

---

## Controller route pro odesílání

```php
// Přidat do routes:
Route::get( '/{quote}/send',      [QuoteController::class, 'sendForm'])->name('send.form');
Route::post('/{quote}/send',      [QuoteController::class, 'send'])    ->name('send');

// V QuoteController:
public function sendForm(Quote $quote)
{
    $this->authorize('view', $quote);
    $mailData = app(QuoteMailService::class)->buildMailForm($quote);
    return view('quotes.send', compact('quote', 'mailData'));
}

public function send(Request $request, Quote $quote)
{
    $this->authorize('view', $quote);
    $request->validate([
        'to'      => 'required|email',
        'subject' => 'required|string|max:200',
        'body'    => 'required|string',
        'cc'      => 'nullable|string', // comma-separated emails
    ]);

    $cc = array_filter(array_map('trim', explode(',', $request->cc ?? '')));

    app(QuoteMailService::class)->sendToClient(
        quote:    $quote,
        toEmail:  $request->to,
        subject:  $request->subject,
        body:     $request->body,
        cc:       $cc,
    );

    return redirect()->route('quotes.show', $quote)
        ->with('success', 'Nabídka byla odeslána na ' . $request->to);
}
```

---

## Copilot pokyny

- E-mailový view `resources/views/emails/quote.blade.php` – použij stejný layout jako `emails/invoice.blade.php`.
- Přidej šablonu `quote` do seederu e-mailových šablon nebo do existující migrace šablon.
- Poštovní server se používá ten nastavený v **Nastavení → E-maily** (globální pro supplieru) – neimplementuj vlastní SMTP logiku.
- V odesílacím formuláři zobraz náhled textu e-mailu (readonly textarea) s možností editace před odesláním.
- Odesilání loguj do `email_logs` tabulky (pokud existuje v projektu).
