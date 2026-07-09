# 06 – Stavový automat cenové nabídky

## Přehled stavů

| Stav | Hodnota | Ikona | Barva | Nastavení |
|------|---------|-------|-------|-----------|
| Vytvořena | `draft` | 📝 | šedá | výchozí – auto při vytvoření |
| Odeslána | `sent` | 📤 | modrá | auto po odeslání e-mailem z aplikace; nebo ručně |
| Objednána | `ordered` | ✅ | oranžová | auto při generování zálohové faktury; nebo ručně |
| Vyfakturována | `invoiced` | 🧾 | zelená | auto při generování vydané faktury; nebo ručně |
| Zamítnuta | `rejected` | ❌ | červená | pouze ručně |

---

## Diagram přechodů

```
          ┌────────────────────────────────────────────┐
          │            Manuální změna (vždy možná)      │
          └────────────────────────────────────────────┘
                   ↓        ↓        ↓        ↓
[draft] ──auto──► [sent] ──auto──► [ordered] ──auto──► [invoiced]
   │                │                  │                    │
   └───────────────────────────────────┴────────────────► [rejected]
                                                          (jen ručně)
```

### Automatické přechody

| Trigger | Nový stav |
|---------|-----------|
| Odeslání e-mailem z aplikace (`QuoteMailService::send()`) | `sent` |
| Generování zálohové faktury (`QuoteService::toProformaInvoice()`) | `ordered` |
| Generování vydané faktury (`QuoteService::toInvoice()`) | `invoiced` |

### Manuální přechody

Všechny stavy lze nastavit ručně v editaci nabídky přes pole **Stav nabídky**.

---

## Implementace: QuoteStatusService

```php
<?php

namespace App\Services;

use App\Models\Quote;
use App\Enums\QuoteStatus;
use Illuminate\Support\Facades\Log;

class QuoteStatusService
{
    /**
     * Automatický přechod – volá se z jiných service, ne z controlleru.
     */
    public function transitionTo(Quote $quote, QuoteStatus $newStatus, string $reason = ''): void
    {
        $oldStatus = $quote->status;

        if ($oldStatus === $newStatus) {
            return;
        }

        $quote->status = $newStatus;
        $quote->save();

        Log::info("Quote #{$quote->quote_number} status: {$oldStatus->value} → {$newStatus->value}. {$reason}");
    }

    /**
     * Ručně zadaný stav z formuláře – validuje, že stav existuje.
     */
    public function setManual(Quote $quote, string $statusValue): void
    {
        $status = QuoteStatus::from($statusValue);
        $this->transitionTo($quote, $status, 'Manual update');
    }
}
```

---

## Expirovaná nabídka

Nabídka je považována za **expirovanou** pokud:
- `status = 'sent'` (v jednání) **A**
- `valid_until < TODAY`

Expirovaná nabídka je stále dostupná v seznamu přes filtr „Expirované".  
**Stav se NEMĚNÍ automaticky** – zůstává `sent`, jen se zobrazuje jinak (badge/ikona).

```php
// Helper v modelu Quote:
public function isExpired(): bool
{
    return $this->status === QuoteStatus::Sent
        && $this->valid_until !== null
        && $this->valid_until->isPast();
}
```

---

## Zobrazení stavu v UI

### Badge komponent

```blade
{{-- resources/views/components/quote-status-badge.blade.php --}}
@props(['quote'])

@php
    $colors = [
        'draft'    => 'badge-secondary',
        'sent'     => 'badge-primary',
        'ordered'  => 'badge-warning',
        'invoiced' => 'badge-success',
        'rejected' => 'badge-danger',
    ];
    $label = $quote->status->label();
    $class = $colors[$quote->status->value] ?? 'badge-secondary';
    
    if ($quote->isExpired()) {
        $label .= ' (expirovaná)';
        $class = 'badge-dark';
    }
@endphp

<span class="badge {{ $class }}" title="{{ $label }}">{{ $label }}</span>
```

---

## Akce dostupné dle stavu (toolbar v detailu nabídky)

| Akce | Dostupná pro stavy |
|------|--------------------|
| Upravit | draft, sent, ordered |
| Odeslat e-mailem | draft, sent, ordered |
| Generovat fakturu | draft, sent, ordered |
| Generovat zálohovou fakturu | draft, sent, ordered |
| Kopírovat | všechny |
| Smazat | draft (nebo vždy se soft-delete) |
| Exportovat PDF | všechny |

---

## Copilot pokyny

- Stav `invoiced` a `ordered` se nastavují **automaticky** v service metodách – nepřidávej je do ručního formuláře (nebo je tam nechej ale označ jako „nastavuje se automaticky").
- Při ručním nastavení stavu `invoiced` nebo `ordered` → zobraz informační toast, že stav se normálně nastavuje automaticky.
- Přidej do seznamu nabídek vizuální rozlišení expirovaných (italic nebo jiné podbarvení řádku).
