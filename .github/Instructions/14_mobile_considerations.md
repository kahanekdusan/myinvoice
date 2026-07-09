# 14 – Mobilní UX pro cenové nabídky

## Cíl

Zajistit plně funkční správu cenových nabídek na mobilním zařízení (responsivní web + případně PWA).

---

## Požadavky na mobilní zobrazení

### Seznam nabídek (mobile)

- Záložky (Všechny / Schválené / V jednání / Expirované) jako **horizontálně scrollovatelné taby**
- Každá nabídka jako **karta** (card layout) místo tabulky:
  ```
  ┌─────────────────────────────┐
  │ CN2026-0042          [SENT] │
  │ Firma ABC, s.r.o.           │
  │ Vystaveno: 05.07.2026       │
  │ Platnost: 19.07.2026        │
  │ Celkem: 45 000 Kč           │
  │ [Upravit] [PDF] [···]       │
  └─────────────────────────────┘
  ```
- Tlačítko „Nová nabídka" jako plovoucí FAB (Floating Action Button)
- Filtr přes ikonu trychtýře (slide-out panel)

### Karta nabídky (mobile)

- Formulář přizpůsoben pro dotykové ovládání
  - Větší pole pro input (min 44px výška)
  - Datepicker nativní (`<input type="date">`) nebo mobilní picker
  - Autocomplete klienta s debounce (300ms)
- Položky: každá na vlastním řádku; rozbalení pro slevu přes toggle
- Přidání přílohy: přímý upload z fotoaparátu nebo galerie
- Akční tlačítka: „Uložit" + hamburger menu pro Další akce

### Offline podpora (volitelné, phase 2)

- Draft nabídky lze vyplnit offline a odeslat po připojení
- Použij Service Worker pokud projekt PWA podporuje

---

## CSS / Responsive

```css
/* Přidej do existujícího stylesheet: */

/* Karta nabídky v mobilním seznamu */
@media (max-width: 768px) {
    .quotes-table { display: none; }
    .quotes-cards { display: block; }

    .quote-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 8px;
        background: #fff;
    }

    .quote-card .quote-number { font-weight: bold; }
    .quote-card .quote-total  { font-size: 1.1em; }
    .quote-card .quote-actions { display: flex; gap: 8px; margin-top: 8px; }
    .quote-card .quote-actions a { flex: 1; text-align: center; }

    /* FAB tlačítko */
    .fab-new-quote {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--primary-color);
        color: #fff;
        font-size: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        z-index: 1000;
        text-decoration: none;
    }
}

@media (min-width: 769px) {
    .quotes-cards { display: none; }
    .quotes-table { display: table; }
    .fab-new-quote { display: none; }
}
```

---

## Blade view: mobilní karta nabídky

```blade
{{-- resources/views/quotes/_mobile_card.blade.php --}}
<div class="quote-card">
    <div class="d-flex justify-content-between align-items-start">
        <span class="quote-number">{{ $quote->quote_number }}</span>
        <x-quote-status-badge :quote="$quote" />
    </div>
    <div class="quote-client mt-1">{{ $quote->client_name ?? '—' }}</div>
    <div class="text-muted small mt-1">
        Vystaveno: {{ $quote->issued_at?->format('d.m.Y') }}
        &nbsp;·&nbsp;
        Platnost: {{ $quote->valid_until?->format('d.m.Y') ?? '—' }}
    </div>
    <div class="quote-total mt-1">
        {{ number_format($quote->total, 2, ',', ' ') }} {{ $quote->currency_code }}
    </div>
    <div class="quote-actions">
        <a href="{{ route('quotes.edit', $quote) }}" class="btn btn-sm btn-outline-primary">Upravit</a>
        <a href="{{ route('quotes.pdf',  $quote) }}" class="btn btn-sm btn-outline-secondary">PDF</a>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown">···</button>
            <div class="dropdown-menu dropdown-menu-right">
                <a class="dropdown-item" href="{{ route('quotes.send.form', $quote) }}">Odeslat e-mailem</a>
                <a class="dropdown-item" href="{{ route('quotes.copy', $quote) }}" 
                   onclick="event.preventDefault(); document.getElementById('copy-{{ $quote->id }}').submit()">Kopírovat</a>
                <form id="copy-{{ $quote->id }}" method="POST" action="{{ route('quotes.copy', $quote) }}">@csrf</form>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-success" 
                   href="{{ route('quotes.to-invoice', $quote) }}"
                   onclick="event.preventDefault(); document.getElementById('inv-{{ $quote->id }}').submit()">
                   Vygenerovat fakturu
                </a>
                <form id="inv-{{ $quote->id }}" method="POST" action="{{ route('quotes.to-invoice', $quote) }}">@csrf</form>
            </div>
        </div>
    </div>
</div>
```

---

## Copilot pokyny

- Použij existující breakpointy projektu (Bootstrap / Tailwind) – neměň globální CSS.
- Mobilní karty přidej do stejného view jako tabulkový seznam (podmíněně dle šířky obrazovky).
- FAB tlačítko přidej do `quotes/index.blade.php` – ne do global layout.
- Nativní `<input type="date">` funguje lépe na mobilu než JS datepicker – použij ho primárně.
