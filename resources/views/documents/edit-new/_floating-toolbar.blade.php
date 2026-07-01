<!-- Editor tool buttons -->
@php($editorCanUsePremiumFeatures = auth()->check() || auth('admin')->check())
@php($editorShowPremiumLocks = request()->query('pdfjs') === '1' && ! $editorCanUsePremiumFeatures)
<div class="floating-tool-bar top-bar-toolset" id="floating-tool-bar">
    <!-- Group 1: Selection tools -->
    <div class="ftb-group">
        @unless($guided ?? false)
            <button type="button"
                    class="ftb-btn ftb-edit-mode{{ $editorShowPremiumLocks ? ' is-premium-locked' : '' }}"
                    id="ftb-edit-mode"
                    title="{{ $editorShowPremiumLocks ? 'Edit PDF requires a free account' : 'Edit PDF — click annotations to edit text' }}"
                    @if($editorShowPremiumLocks) data-premium-feature="Edit PDF" @endif>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                </svg>
                <span>Edit PDF</span>
                @if($editorShowPremiumLocks)
                    <small class="ftb-premium-label">Premium</small>
                @endif
            </button>
        @endunless
        @if($guided ?? false)
            <button type="button" class="ftb-btn ftb-guided-convert" id="ftb-guided-convert" title="Convert fields into editable text">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16v16H4z"></path>
                    <path d="M8 8h8"></path>
                    <path d="M8 12h5"></path>
                    <path d="m14 15 2 2 4-4"></path>
                </svg>
                <span>Convert</span>
            </button>
        @endif
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 2: Annotation tools -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn" id="ftb-sign" title="Sign — create a signature by drawing, typing, or uploading an image">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 17.5c2.3-3.6 4.4-5.4 6.2-5.4 1.2 0 1.7.8 1.4 2.3l-.5 2.5c-.3 1.5.1 2.2 1.1 2.2 1.4 0 2.6-1.8 3.8-5.4"></path>
                <path d="M15 15.4c.9 2.1 2.1 3.1 3.7 3.1 1.1 0 2-.4 2.8-1.1"></path>
                <path d="M3 21h18"></path>
            </svg>
            <span>Sign</span>
        </button>
        <button type="button" class="ftb-btn ftb-add-text" id="ftb-add-text" title="Add Text — click on the page to place a new text block">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>
            </svg>
            <span>Text</span>
        </button>
        <button type="button" class="ftb-btn" id="ftb-add-shape" title="Shapes — choose a shape, then drag on the page to draw">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="7" cy="8" r="3.5"></circle>
                <path d="M14 5h6v6"></path>
                <path d="M14 19h6"></path>
                <path d="M4 19l6-8 6 8z"></path>
            </svg>
            <span>Shapes</span>
        </button>
        <button type="button" class="ftb-btn" id="ftb-draw-erase" title="Draw — sketch directly on the page">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.5 3.5 20.5 14.5"></path>
                <path d="M16.5 10.5 13 14l-3-3 3.5-3.5"></path>
                <path d="M7.5 13.5 4 17l3 3 3.5-3.5"></path>
                <path d="M3 21h8"></path>
            </svg>
            <span>Draw</span>
        </button>
        <button type="button" class="ftb-btn" id="ftb-highlight" title="Highlight — select text or drag over the page">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 11-6 6v3h3l6-6"></path>
                <path d="m22 12-4.5 4.5-10-10L12 2l10 10z"></path>
                <path d="M14 19h7"></path>
            </svg>
            <span>Highlight</span>
        </button>
        <button type="button" class="ftb-btn" id="ftb-add-image" title="Image — import an image and place it on the page">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
            <span>Image</span>
        </button>
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 3: Convert -->
    <div class="ftb-group">
        @if(request()->query('pdfjs') === '1')
            <button type="button" class="ftb-btn" id="ftb-convert" title="Convert — export this PDF to another format">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <path d="M14 2v6h6"></path>
                    <path d="M8 13h8"></path>
                    <path d="m13 17 3-3-3-3"></path>
                </svg>
                <span>Convert</span>
            </button>
        @endif
    </div>
</div>
