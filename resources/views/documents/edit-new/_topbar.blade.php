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
        <div class="enpv-settings-wrap">
            <button id="settings-gear-btn" type="button" class="history-btn enpv-settings-btn" title="Settings" aria-label="Settings" aria-expanded="false" aria-controls="settings-popover">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </button>
            <div id="settings-popover" class="enpv-settings-popover" hidden>
                <div class="enpv-settings-title">Settings</div>
                <div class="enpv-gridlines-row">
                    <div>
                        <div class="enpv-settings-label">Gridlines</div>
                        <div class="enpv-settings-hint">Alignment guides on pages</div>
                    </div>
                    <label class="enpv-grid-toggle" aria-label="Toggle gridlines">
                        <input type="checkbox" id="settings-gridlines-toggle">
                        <span class="grid-toggle-slider"></span>
                    </label>
                </div>
                <div id="settings-gridlines-options" class="enpv-gridlines-options" hidden>
                    <div class="enpv-gridlines-setting">
                        <span>Spacing</span>
                        <span id="settings-gridlines-spacing-label" class="enpv-gridlines-value">50px</span>
                    </div>
                    <input type="range" id="settings-gridlines-spacing" min="10" max="200" value="50" step="5">
                    <div class="enpv-gridlines-two-col">
                        <label>
                            <span>Color</span>
                            <input type="color" id="settings-gridlines-color" value="#3b82f6">
                        </label>
                        <label>
                            <span>Opacity</span>
                            <input type="range" id="settings-gridlines-opacity" min="5" max="50" value="15">
                        </label>
                    </div>
                </div>
            </div>
        </div>
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
