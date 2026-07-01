@php
    $fallbackBackUrl = route('documents.index');
    $adminBackUrl = route('filament.user.pages.pdf-generator');
    $explicitAdminOrigin = request()->query('from') === 'admin';

    $candidateBackUrl = null;
    $normalizedCandidatePath = '';

    if (!$explicitAdminOrigin) {
        $returnTo = request()->query('return_to');
        $referer = request()->headers->get('referer');
        $candidateBackUrl = is_string($returnTo) && $returnTo !== '' ? $returnTo : ($referer ?: url()->previous());
        $candidatePath = is_string($candidateBackUrl) ? (parse_url($candidateBackUrl, PHP_URL_PATH) ?: '') : '';
        $normalizedCandidatePath = '/' . ltrim($candidatePath, '/');
    }

    $currentUrl = request()->fullUrl();
    $requestHost = request()->getHost();
    $candidateHost = is_string($candidateBackUrl) ? parse_url($candidateBackUrl, PHP_URL_HOST) : null;
    $sameHost = !$candidateHost || $candidateHost === $requestHost;
    $isLivewireUpdate = str_contains($normalizedCandidatePath, '/livewire/update')
        || (is_string($candidateBackUrl) && str_contains($candidateBackUrl, 'livewire/update'));
    $isDocumentEditorCandidate = preg_match('#^/documents/\d+/(?:guided|edit|edit-new|edit-pdfjs|ai)(?:/|$)#', $normalizedCandidatePath) === 1;
    $cameFromAdmin = $explicitAdminOrigin
        || str_starts_with($normalizedCandidatePath, '/admin')
        || str_starts_with($normalizedCandidatePath, '/portal');
    $hasUsableCandidate = !$explicitAdminOrigin
        && is_string($candidateBackUrl)
        && $candidateBackUrl !== ''
        && $candidateBackUrl !== $currentUrl
        && $sameHost
        && !$isLivewireUpdate
        && !$isDocumentEditorCandidate;
    $backUrl = $explicitAdminOrigin
        ? $adminBackUrl
        : ($hasUsableCandidate ? $candidateBackUrl : ($cameFromAdmin ? $adminBackUrl : $fallbackBackUrl));
    $backLabel = $cameFromAdmin ? 'Back to admin' : 'Back to documents';
@endphp

<div class="top-bar">
    <button
        type="button"
        class="doc-top-back-btn"
        title="{{ $backLabel }}"
        aria-label="{{ $backLabel }}"
        onclick="window.location.assign({!! Js::from($backUrl) !!})"
    >
        <span class="doc-top-back-btn__icon" aria-hidden="true">&#8592;</span>
        <span class="doc-top-back-btn__text">{{ $backLabel }}</span>
    </button>
    <div class="doc-name-wrap" id="doc-name-wrap"
         data-rename-url="{{ route('documents.rename', $document) }}"
         data-original-name="{{ $document->original_name }}">
        <span id="doc-name-display"
              class="doc-name-display"
              role="button"
              tabindex="0"
              title="Click to rename document">{{ $document->original_name }}</span>
        <input id="doc-name-input"
               class="doc-name-input"
               type="text"
               maxlength="240"
               style="display:none;"
               aria-label="Document name"
               value="{{ $document->original_name }}" />
        <button id="doc-name-edit-btn" type="button" class="doc-name-edit-btn" title="Rename document" aria-label="Rename document">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
        </button>
    </div>
    @if($guided ?? false)
        <span class="guided-mode-label">Guided mode</span>
    @endif
    <span id="save-status" class="save-status">Saved</span>
    @include('documents.edit-new._floating-toolbar')
    <div class="top-bar-export-group" aria-label="History and export actions">
        <button id="undo-btn" type="button" class="history-btn" title="Undo (Ctrl+Z)" disabled>&#8592;</button>
        <button id="redo-btn" type="button" class="history-btn" title="Redo (Ctrl+Y)" disabled>&#8594;</button>
        <button id="download-pdf-btn" type="button" class="download-btn">Download PDF</button>
    </div>
    @unless($guided ?? false)
        <button id="edit-mode-toggle" type="button" class="edit-mode-toggle" aria-pressed="false" title="Turn edit mode on to show editable text boxes">
            <span class="edit-mode-toggle__icon" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                </svg>
            </span>
            <span id="edit-mode-toggle-label" class="edit-mode-toggle__label">Edit Mode OFF</span>
        </button>
    @endunless
    <button id="add-text-btn" type="button" class="history-btn add-text-btn" title="Add Text — click or drag on the page to place a new text block">Add Text</button>
    <button id="add-shape-btn" type="button" class="history-btn add-shape-btn" title="Shapes — choose a shape and drag on the page to draw it">Shapes</button>
    <button id="save-btn" type="button" class="save-btn">Save</button>
    <a href="{{ route('documents.edit', $document) }}" class="doc-top-edit-link">← Edit</a>
    <a href="{{ route('documents.index') }}" class="doc-top-documents-link">All documents</a>
</div>
