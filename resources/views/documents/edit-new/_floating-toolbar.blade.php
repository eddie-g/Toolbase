<!-- Floating tool bar -->
<div class="floating-tool-bar" id="floating-tool-bar">
    <!-- Group 1: Selection tools -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn ftb-edit-mode" id="ftb-edit-mode" title="Edit PDF — click annotations to edit text">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
            <span>Edit PDF</span>
        </button>
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 2: Annotation tools -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn" id="ftb-sign" title="Sign — create a signature by drawing, typing, or uploading an image">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="m3 21 3.75-.75L18.5 8.5a2.12 2.12 0 1 0-3-3L3.75 17.25z"></path>
                <path d="m14.5 6.5 3 3"></path>
                <path d="M2.5 21.5h6"></path>
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
        <button type="button" class="ftb-btn" id="ftb-draw-erase" title="Draw &amp; Erase — sketch a freehand mark or remove annotations">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 20h8"></path>
                <path d="M14.5 4.5a2.1 2.1 0 0 1 3 3L8 17l-4 1 1-4 9.5-9.5z"></path>
                <path d="M13 6l5 5"></path>
            </svg>
            <span>Draw / Erase</span>
        </button>
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 3: Insert -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn" id="ftb-add-image" title="Image — import an image and place it on the page">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
            <span>Image</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
            <span>Arrow</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span>Check</span>
        </button>
    </div>
</div>
