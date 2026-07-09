# 12 – Přílohy k cenové nabídce

## Cíl

Umožnit připojení až 5 příloh k cenové nabídce (stejné chování jako přílohy u faktur).

---

## Pravidla

| Parametr | Hodnota |
|----------|---------|
| Max počet příloh | 5 |
| Max velikost jedné přílohy | 2 MB |
| Akceptované typy | vše (nebo dle politiky projektu) |
| Zobrazení na PDF | NE – přílohy jsou jen v aplikaci |

---

## QuoteAttachmentController

```php
Route::post('/{quote}/attachments',           [QuoteAttachmentController::class, 'store'])  ->name('attachments.store');
Route::delete('/{quote}/attachments/{attach}', [QuoteAttachmentController::class, 'destroy'])->name('attachments.destroy');
Route::get('/{quote}/attachments/{attach}',    [QuoteAttachmentController::class, 'download'])->name('attachments.download');
```

```php
<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuoteAttachmentController extends Controller
{
    public function store(Request $request, Quote $quote)
    {
        $this->authorize('update', $quote);

        $request->validate([
            'file'   => 'required|file|max:2048', // 2 MB
            'file.*' => 'file|max:2048',
        ]);

        if ($quote->attachments()->count() >= 5) {
            return back()->with('error', 'Lze přiložit maximálně 5 příloh.');
        }

        $file = $request->file('file');
        $path = $file->store("quotes/{$quote->id}/attachments", 'private');

        $quote->attachments()->create([
            'filename'  => $file->getClientOriginalName(),
            'path'      => $path,
            'mime_type' => $file->getMimeType(),
            'size'      => $file->getSize(),
        ]);

        return back()->with('success', 'Příloha přidána.');
    }

    public function destroy(Quote $quote, QuoteAttachment $attach)
    {
        $this->authorize('update', $quote);
        Storage::disk('private')->delete($attach->path);
        $attach->delete();
        return back()->with('success', 'Příloha smazána.');
    }

    public function download(Quote $quote, QuoteAttachment $attach)
    {
        $this->authorize('view', $quote);
        return Storage::disk('private')->download($attach->path, $attach->filename);
    }
}
```

---

## UI – záložka Přílohy na kartě nabídky

```blade
{{-- V quotes/show.blade.php nebo edit.blade.php --}}
<div class="tab-pane" id="attachments">
    <h5>Přílohy ({{ $quote->attachments->count() }}/5)</h5>

    @foreach($quote->attachments as $att)
    <div class="attachment-row">
        <a href="{{ route('quotes.attachments.download', [$quote, $att]) }}">
            📎 {{ $att->filename }}
        </a>
        <small>{{ number_format($att->size / 1024, 1) }} KB</small>
        <form method="POST" action="{{ route('quotes.attachments.destroy', [$quote, $att]) }}" style="display:inline">
            @csrf @method('DELETE')
            <button type="submit" class="btn-link text-danger">✕</button>
        </form>
    </div>
    @endforeach

    @if($quote->attachments->count() < 5)
    <form method="POST" action="{{ route('quotes.attachments.store', $quote) }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="file" accept="*/*">
        <button type="submit" class="btn btn-sm btn-secondary">Přidat přílohu</button>
    </form>
    @endif
</div>
```

---

## Copilot pokyny

- Použij stejný storage disk jako projekt (typicky `private` nebo `local`).
- Přidej záložku „Přílohy" do karet nabídky (vedle záložky „Položky").
- Pokud projekt má drag-and-drop upload pro faktury, adaptuj ho i pro nabídky.
