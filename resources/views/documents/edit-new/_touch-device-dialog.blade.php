{{-- Phones and small touch screens: the editor's drag handles, hover toolbars
     and keyboard shortcuts need a pointer and a wide window. Shown by
     resources/js/edit-new-pdfjs/touch-guard.js; styled like the rotated-page
     notice. --}}
<div
    class="enpv-rotated-edit-dialog enpv-touch-dialog"
    id="enpv-touch-dialog"
    aria-hidden="true"
    hidden
>
    <div class="enpv-rotated-edit-dialog__scrim" aria-hidden="true"></div>
    <section
        class="enpv-rotated-edit-dialog__card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="enpv-touch-dialog-title"
        aria-describedby="enpv-touch-dialog-description"
    >
        <div class="enpv-rotated-edit-dialog__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="13" rx="2"></rect>
                <path d="M8 21h8M12 17v4"></path>
            </svg>
        </div>
        <div>
            <h2 id="enpv-touch-dialog-title">The editor works best on a computer</h2>
            <p id="enpv-touch-dialog-description">
                Editing a PDF needs a mouse or trackpad and a wider screen than this one.
                @if(auth('web')->check() || auth('admin')->check())
                    Your document is saved to your account: open it on a computer to edit it,
                    or download it here.
                @else
                    You can download your document here. To edit it, open NETKIT on a computer.
                @endif
            </p>
        </div>
        <div class="enpv-touch-dialog__actions">
            <a class="enpv-rotated-edit-dialog__close" id="enpv-touch-dialog-download" href="{{ route('documents.download', $document) }}">Download PDF</a>
            <a class="enpv-touch-dialog__secondary" href="{{ route('documents.index') }}">Back to documents</a>
            <button type="button" class="enpv-touch-dialog__secondary" id="enpv-touch-dialog-continue">View it here anyway</button>
        </div>
    </section>
</div>
