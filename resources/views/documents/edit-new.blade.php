<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit New</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
    <script>
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        }
    </script>
    <!-- Popular Google Fonts available in the /edit-new font picker. Loaded
         once at page load so the canvas + contenteditable can render any of
         them immediately when the user picks one in the format bar. The
         server-side PDF export reads the same TTFs from python/pdf-editor/fonts. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,400;1,700&family=Bebas+Neue&family=Cabin:ital,wght@0,400;0,700;1,400;1,700&family=Crimson+Text:ital,wght@0,400;0,700;1,400;1,700&family=DM+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Dosis:wght@400;700&family=Fira+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Heebo:wght@400;700&family=Hind:wght@400;700&family=Inter:ital,wght@0,400;0,700;1,400;1,700&family=Josefin+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Kanit:ital,wght@0,400;0,700;1,400;1,700&family=Karla:ital,wght@0,400;0,700;1,400;1,700&family=Lato:ital,wght@0,400;0,700;1,400;1,700&family=Lora:ital,wght@0,400;0,700;1,400;1,700&family=Manrope:wght@400;700&family=Merriweather:ital,wght@0,400;0,700;1,400;1,700&family=Montserrat:ital,wght@0,400;0,700;1,400;1,700&family=Mukta:wght@400;700&family=Mulish:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Nunito:ital,wght@0,400;0,700;1,400;1,700&family=Open+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Oswald:wght@400;700&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Poppins:ital,wght@0,400;0,700;1,400;1,700&family=Quicksand:wght@400;700&family=Raleway:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Condensed:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Mono:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Slab:wght@400;700&family=Roboto:ital,wght@0,400;0,700;1,400;1,700&family=Rubik:ital,wght@0,400;0,700;1,400;1,700&family=Source+Sans+3:ital,wght@0,400;0,700;1,400;1,700&family=Ubuntu:ital,wght@0,400;0,700;1,400;1,700&family=Work+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap">
    @vite(['resources/css/edit-new/index.css'])
</head>
<body>

{{-- Server-side config consumed by resources/js/edit-new/main.js. --}}
<div id="edit-new-root"
     data-doc-id="{{ $document->id }}"
     data-csrf="{{ csrf_token() }}"
     data-info-url="{{ route('pdfTests.documentInfo', $document) }}"
     data-save-url="{{ route('documents.saveAnnotationState', $document) }}"
     data-download-url="{{ route('documents.downloadAnnotatedPdf', $document) }}"></div>

<div class="top-bar">
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
    <span id="save-status" class="save-status">Saved</span>
    <button id="undo-btn" type="button" class="history-btn" title="Undo (Ctrl+Z)" disabled>&#8592;</button>
    <button id="redo-btn" type="button" class="history-btn" title="Redo (Ctrl+Y)" disabled>&#8594;</button>
    <button id="edit-mode-toggle" type="button" class="edit-mode-toggle" aria-pressed="false" title="Turn edit mode on to show editable text boxes">
        <span class="edit-mode-toggle__icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
        </span>
            <span id="edit-mode-toggle-label" class="edit-mode-toggle__label">Edit Mode OFF</span>
    </button>
    <button id="add-text-btn" type="button" class="history-btn add-text-btn" title="Add Text — click or drag on the page to place a new text block">Add Text</button>
    <button id="add-shape-btn" type="button" class="history-btn add-shape-btn" title="Shapes — choose a shape and drag on the page to draw it">Shapes</button>
    <button id="save-btn" type="button" class="save-btn">Save</button>
    <button id="download-pdf-btn" type="button" class="download-btn">Download PDF</button>
    <a href="{{ route('documents.edit', $document) }}">← Edit</a>
    <a href="{{ route('documents.index') }}">All documents</a>
</div>

<div id="pages-wrap">
    <div id="error-banner"></div>
</div>
<div id="save-toast">Saved</div>
<div id="shape-constrain-tip">Hold <kbd>Shift</kbd> to constrain shape</div>

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
<div class="shape-tool-panel" id="shape-tool-panel">
    <div class="sfb-shape-grid" id="shape-type-grid">
        <button type="button" class="sfb-shape-btn is-active" data-shape-tool="circle" title="Circle" aria-label="Circle">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="triangle" title="Triangle" aria-label="Triangle">
            <svg viewBox="0 0 24 24"><path d="M12 5 19 18H5z"></path></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="square" title="Square" aria-label="Square">
            <svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12"></rect></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="star" title="Star" aria-label="Star">
            <svg viewBox="0 0 24 24"><path d="m12 4 2.35 4.76 5.25.76-3.8 3.7.9 5.23L12 16l-4.7 2.45.9-5.23-3.8-3.7 5.25-.76z"></path></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="line" title="Line" aria-label="Line">
            <svg viewBox="0 0 24 24"><path d="M5 19 19 5"></path></svg>
        </button>
    </div>
    <div class="afb-divider"></div>
    <span class="sfb-label">Stroke</span>
    <label class="sfb-color" title="Stroke color" aria-label="Stroke color">
        <input type="color" id="shape-stroke-color" value="#0f172a">
    </label>
    <input type="hidden" id="shape-stroke-hex" value="#0f172a">
    <input type="checkbox" id="shape-stroke-transparent" style="display:none;">
    <span class="sfb-mini-label" title="Width">W</span>
    <input type="range" class="sfb-slider" id="shape-stroke-width" min="1" max="24" step="1" value="3" title="Stroke width">
    <span class="sfb-readout" id="shape-stroke-width-value">3px</span>
    <span class="sfb-mini-label" title="Opacity">α</span>
    <input type="range" class="sfb-slider" id="shape-stroke-opacity" min="0" max="100" step="1" value="100" title="Stroke opacity">
    <span class="sfb-readout" id="shape-stroke-opacity-value">100%</span>
    <div class="afb-divider"></div>
    <span class="sfb-label">Fill</span>
    <label class="sfb-color" title="Fill color" aria-label="Fill color">
        <input type="color" id="shape-fill-color" value="#22c55e">
    </label>
    <input type="hidden" id="shape-fill-hex" value="#22c55e">
    <input type="checkbox" id="shape-fill-transparent" style="display:none;">
    <span class="sfb-mini-label" title="Opacity">α</span>
    <input type="range" class="sfb-slider" id="shape-fill-opacity" min="0" max="100" step="1" value="22" title="Fill opacity">
    <span class="sfb-readout" id="shape-fill-opacity-value">22%</span>
</div>
<div class="draw-tool-panel" id="draw-tool-panel" aria-hidden="true">
    <div class="draw-tool-panel__head">
        <p class="draw-tool-panel__title">Draw</p>
        <button type="button" class="draw-tool-panel__close" id="draw-tool-close" aria-label="Close draw tool">×</button>
    </div>
    <div class="draw-tool-panel__stack">
        <div class="draw-tool-panel__tool-group">
            <button type="button" class="draw-tool-btn is-active" id="draw-tool-pen" data-draw-direct-tool="pen" title="Pen" aria-label="Pen">
                <svg viewBox="0 0 24 24"><path d="M4 20h8"></path><path d="M14.5 4.5a2.1 2.1 0 0 1 3 3L8 17l-4 1 1-4 9.5-9.5z"></path><path d="M13 6l5 5"></path></svg>
            </button>
            <button type="button" class="draw-tool-btn" id="draw-tool-eraser" data-draw-direct-tool="eraser" title="Eraser" aria-label="Eraser">
                <svg viewBox="0 0 24 24"><path d="m7 14 7.5-7.5a2.1 2.1 0 0 1 3 0l1 1a2.1 2.1 0 0 1 0 3L11 18H7z"></path><path d="M5 18h10"></path></svg>
            </button>
        </div>
        <div class="draw-tool-panel__slider-wrap">
            <span class="draw-tool-panel__section-label">Size</span>
            <input type="range" id="draw-tool-size" min="2" max="36" step="1" value="10" aria-label="Draw brush size">
            <span class="draw-tool-panel__readout" id="draw-tool-size-value">10px</span>
        </div>
        <div class="draw-tool-panel__slider-wrap">
            <span class="draw-tool-panel__section-label">Alpha</span>
            <input type="range" id="draw-tool-opacity" min="10" max="100" step="1" value="100" aria-label="Draw opacity">
            <span class="draw-tool-panel__readout" id="draw-tool-opacity-value">100%</span>
        </div>
        <div class="draw-tool-panel__stack" style="gap: 8px; width: 100%;">
            <span class="draw-tool-panel__section-label">Color</span>
            <div class="draw-tool-panel__colors">
                <button type="button" class="draw-color-swatch is-active" data-draw-color="#111827" style="--swatch-color:#111827" aria-label="Black ink"></button>
                <button type="button" class="draw-color-swatch" data-draw-color="#dc2626" style="--swatch-color:#dc2626" aria-label="Red ink"></button>
                <button type="button" class="draw-color-swatch" data-draw-color="#2563eb" style="--swatch-color:#2563eb" aria-label="Blue ink"></button>
                <button type="button" class="draw-color-swatch" data-draw-color="#16a34a" style="--swatch-color:#16a34a" aria-label="Green ink"></button>
                <button type="button" class="draw-color-swatch" data-draw-color="#ea580c" style="--swatch-color:#ea580c" aria-label="Orange ink"></button>
                <button type="button" class="draw-color-swatch" data-draw-color="#7c3aed" style="--swatch-color:#7c3aed" aria-label="Violet ink"></button>
            </div>
            <input type="color" id="draw-tool-color" class="draw-tool-panel__custom-color" value="#111827" aria-label="Custom ink color">
        </div>
    </div>
    <div class="draw-tool-panel__status" id="draw-tool-status">Pen mode is active. Drag directly on the page to draw.</div>
</div>
<div class="markup-modal" id="markup-tool-modal" aria-hidden="true">
    <div class="markup-modal__scrim" id="markup-tool-modal-scrim"></div>
    <div class="markup-modal__card" role="dialog" aria-modal="true" aria-labelledby="markup-tool-modal-title">
        <div class="markup-modal__header">
            <div>
                <p class="markup-modal__eyebrow">Markup Tool</p>
                <h2 class="markup-modal__title" id="markup-tool-modal-title">Draw or erase annotations</h2>
                <p class="markup-modal__subtitle">Sketch a freehand mark and place it on the PDF, or switch to erase mode to remove any annotation directly from the page.</p>
            </div>
            <button type="button" class="markup-modal__close" id="markup-tool-modal-close" aria-label="Close draw and erase modal">×</button>
        </div>
        <div class="markup-modal__tabs">
            <button type="button" class="markup-modal__tab is-active" data-markup-tool-mode="draw">Draw</button>
            <button type="button" class="markup-modal__tab" data-markup-tool-mode="erase">Erase</button>
        </div>
        <div class="markup-modal__body">
            <div class="markup-modal__panel is-active" data-markup-tool-panel="draw">
                <div class="markup-modal__controls">
                    <div>
                        <h3 class="markup-modal__section-title">Freehand mark</h3>
                        <p class="markup-modal__section-copy">Use your mouse, trackpad, or stylus to sketch a mark, underline, or handwritten note before placing it on the page.</p>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Ink color</span>
                        <label class="markup-modal__color-chip">
                            <input id="markup-tool-color" type="color" value="#0f172a" aria-label="Drawing ink color">
                            <span class="markup-modal__color-value" id="markup-tool-color-value">#0F172A</span>
                        </label>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Stroke width</span>
                        <div class="markup-modal__field-row">
                            <input id="markup-tool-width" type="range" min="1" max="10" step="1" value="4" aria-label="Drawing stroke width">
                            <span class="markup-modal__slider-value" id="markup-tool-width-value">4px</span>
                        </div>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Stroke smoothing</span>
                        <div class="markup-modal__field-row">
                            <input id="markup-tool-smoothing" type="range" min="0" max="100" step="1" value="58" aria-label="Drawing stroke smoothing">
                            <span class="markup-modal__slider-value" id="markup-tool-smoothing-value">58%</span>
                        </div>
                    </div>
                </div>
                <div class="markup-modal__stage">
                    <div>
                        <h3 class="markup-modal__section-title">Preview</h3>
                        <p class="markup-modal__section-copy">Draw here, then place the finished mark anywhere on the current page.</p>
                    </div>
                    <div class="markup-modal__canvas-shell">
                        <canvas id="markup-tool-canvas" class="markup-modal__canvas" width="880" height="360" aria-label="Freehand drawing canvas"></canvas>
                    </div>
                    <div class="markup-modal__hint" id="markup-tool-hint">Draw mode: click and drag to create a mark.</div>
                </div>
            </div>
            <div class="markup-modal__panel" data-markup-tool-panel="erase">
                <div class="markup-modal__erase">
                    <div class="markup-modal__erase-card">
                        <strong>Erase any annotation</strong>
                        <span>After you start erase mode, click any text box, shape, signature, or image annotation on the page to remove it immediately.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Stay in control</strong>
                        <span>Erase mode stays active until you press Escape or reopen this tool, so you can clean up multiple annotations in one pass.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Visual targeting</strong>
                        <span>Hover over the page to preview which annotation will be deleted before you click.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Undo still works</strong>
                        <span>Every removal uses the normal annotation delete pipeline, so undo and save behavior remain consistent.</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="markup-modal__footer">
            <div class="markup-modal__status" id="markup-tool-status">Draw a mark, then place it on the page.</div>
            <div class="markup-modal__actions">
                <button type="button" class="markup-btn" id="markup-tool-clear">Clear</button>
                <button type="button" class="markup-btn" id="markup-tool-cancel">Cancel</button>
                <button type="button" class="markup-btn markup-btn--primary" id="markup-tool-apply" disabled>Place drawing</button>
            </div>
        </div>
    </div>
</div>
<div class="signature-modal" id="signature-modal" aria-hidden="true">
    <div class="signature-modal__scrim" id="signature-modal-scrim"></div>
    <div class="signature-modal__card" role="dialog" aria-modal="true" aria-labelledby="signature-modal-title">
        <div class="signature-modal__header">
            <div>
                <p class="signature-modal__eyebrow">Sign Document</p>
                <h2 class="signature-modal__title" id="signature-modal-title">Add your signature</h2>
                <p class="signature-modal__subtitle">Create a clean signature mark for this PDF. Draw it freehand, type it in a script style, or upload an existing signature image.</p>
            </div>
            <button type="button" class="signature-modal__close" id="signature-modal-close" aria-label="Close signature modal">×</button>
        </div>
        <div class="signature-modal__tabs">
            <button type="button" class="signature-modal__tab is-active" data-signature-mode="draw">Draw</button>
            <button type="button" class="signature-modal__tab" data-signature-mode="type">Type</button>
            <button type="button" class="signature-modal__tab" data-signature-mode="upload">Upload</button>
        </div>
        <div class="signature-modal__body">
            <div class="signature-modal__sidebar">
                <div class="signature-modal__panel is-active" data-signature-panel="draw">
                    <h3 class="signature-modal__panel-title">Draw naturally</h3>
                    <p class="signature-modal__panel-copy">Use your mouse, trackpad, or stylus to sketch a handwritten signature.</p>
                    <div class="signature-field">
                        <span class="signature-field__label">Ink color</span>
                        <label class="signature-color-chip">
                            <input id="signature-color" type="color" value="#111827" aria-label="Signature ink color">
                            <span class="signature-color-chip__value" id="signature-color-value">#111827</span>
                        </label>
                    </div>
                    <div class="signature-field">
                        <span class="signature-field__label">Stroke width</span>
                        <div class="signature-field__row">
                            <input id="signature-width" type="range" min="1" max="8" step="1" value="3" aria-label="Signature stroke width">
                            <span class="signature-slider-readout" id="signature-width-value">3px</span>
                        </div>
                    </div>
                    <div class="signature-field">
                        <span class="signature-field__label">Stroke smoothing</span>
                        <div class="signature-field__row signature-field__row--stack">
                            <div class="signature-slider-stack">
                                <input id="signature-smoothing" type="range" min="0" max="100" step="1" value="58" aria-label="Signature stroke smoothing">
                                <span class="signature-slider-readout" id="signature-smoothing-value">58%</span>
                            </div>
                            <span class="signature-stage__hint">Lower keeps the raw stroke. Higher softens corners.</span>
                        </div>
                    </div>
                </div>
                <div class="signature-modal__panel" data-signature-panel="type">
                    <h3 class="signature-modal__panel-title">Type a signature</h3>
                    <p class="signature-modal__panel-copy">Enter your name and preview it as a signature mark before placing it on the page.</p>
                    <label class="signature-field">
                        <span class="signature-field__label">Signature text</span>
                        <input id="signature-text" type="text" placeholder="Type your full name">
                    </label>
                    <label class="signature-field">
                        <span class="signature-field__label">Style</span>
                        <select id="signature-font">
                            <option value="Great Vibes" selected>Great Vibes</option>
                            <option value="Dancing Script">Dancing Script</option>
                            <option value="Allura">Allura</option>
                            <option value="Pacifico">Pacifico</option>
                            <option value="Alex Brush">Alex Brush</option>
                            <option value="Sacramento">Sacramento</option>
                            <option value="Parisienne">Parisienne</option>
                            <option value="Marck Script">Marck Script</option>
                            <option value="Satisfy">Satisfy</option>
                            <option value="Caveat">Caveat</option>
                            <option value="Kaushan Script">Kaushan Script</option>
                            <option value="Tangerine">Tangerine</option>
                        </select>
                    </label>
                    <div class="signature-field">
                        <span class="signature-field__label">Ink color</span>
                        <label class="signature-color-chip">
                            <input id="signature-type-color" type="color" value="#111827" aria-label="Typed signature color">
                            <span class="signature-color-chip__value" id="signature-type-color-value">#111827</span>
                        </label>
                    </div>
                </div>
                <div class="signature-modal__panel" data-signature-panel="upload">
                    <h3 class="signature-modal__panel-title">Use an image</h3>
                    <p class="signature-modal__panel-copy">Import a scanned signature, transparent PNG, or any image file and stamp it into the PDF as an annotation.</p>
                    <label class="signature-upload">
                        <input id="signature-image-input" type="file" accept="image/*">
                        <span class="signature-upload__icon" aria-hidden="true">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                        </span>
                        <span class="signature-upload__title">Choose an image</span>
                        <span class="signature-upload__copy">PNG, JPG, WebP, GIF, and SVG are normalized to a stamped PNG asset for export.</span>
                        <span class="signature-upload__meta" id="signature-image-name">No file selected</span>
                    </label>
                </div>
                <div class="signature-library">
                    <div class="signature-library__header">
                        <h3 class="signature-library__title">Saved signatures</h3>
                        <p class="signature-library__copy">Save the current preview and reload it later in this browser.</p>
                    </div>
                    <div class="signature-library__load-row">
                        <select id="signature-library-select" class="signature-library__select" aria-label="Saved signatures">
                            <option value="">Load a saved signature</option>
                        </select>
                        <button type="button" class="signature-library__cta signature-library__load-btn" id="signature-library-load-btn" disabled>Load</button>
                    </div>
                    <div class="signature-library__save-row">
                        <input id="signature-save-name" class="signature-library__name" type="text" placeholder="Signature name">
                        <button type="button" class="signature-library__cta signature-library__save-btn" id="signature-save-btn" disabled>Save current</button>
                    </div>
                    <div class="signature-library__list" id="signature-library-list">
                        <div class="signature-library__empty">No saved signatures yet.</div>
                    </div>
                </div>
            </div>
            <div class="signature-stage">
                <div class="signature-stage__header">
                    <div>
                        <h3 class="signature-stage__title">Live preview</h3>
                        <p class="signature-stage__copy">This preview becomes the annotation that gets placed into the document.</p>
                    </div>
                    <button type="button" class="signature-clear-btn" id="signature-clear">Clear</button>
                </div>
                <div class="signature-stage__canvas-shell">
                    <canvas id="signature-canvas" class="signature-canvas" width="900" height="300"></canvas>
                </div>
                <div class="signature-stage__hint" id="signature-hint">Draw mode: click and drag to sign.</div>
            </div>
        </div>
        <div class="signature-modal__footer">
            <div class="signature-modal__status" id="signature-status">Create a signature, then place it on the current page.</div>
            <div class="signature-modal__actions">
                <button type="button" class="signature-btn" id="signature-cancel">Cancel</button>
                <button type="button" class="signature-btn signature-btn--primary" id="signature-apply" disabled>Use signature</button>
            </div>
        </div>
    </div>
</div>
<!-- Image Import Modal -->
<div class="image-import-modal" id="image-import-modal" aria-hidden="true" role="dialog" aria-labelledby="image-import-title">
    <div class="image-import-modal__scrim" id="image-import-scrim"></div>
    <div class="image-import-modal__card">
        <button type="button" class="image-import-modal__close" id="image-import-close" aria-label="Close image modal">×</button>
        <div class="image-import-modal__header">
            <span class="image-import-modal__eyebrow">Insert</span>
            <h2 class="image-import-modal__title" id="image-import-title">Import an image</h2>
            <p class="image-import-modal__subtitle">Drop an image, paste from clipboard, or pick a file. JPG, PNG, GIF, WebP, or SVG.</p>
        </div>
        <div class="image-import-modal__body">
            <label class="image-import-dropzone" id="image-import-dropzone" tabindex="0">
                <input type="file" id="image-import-file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" hidden>
                <div class="image-import-dropzone__inner" id="image-import-dropzone-inner">
                    <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="3"/>
                        <circle cx="8.5" cy="9" r="1.6"/>
                        <path d="m21 16-5-5-9 9"/>
                    </svg>
                    <div class="image-import-dropzone__title">Drop your image here</div>
                    <div class="image-import-dropzone__subtitle">or <span class="image-import-dropzone__link">browse to upload</span></div>
                    <div class="image-import-dropzone__hint">You can also paste an image (Ctrl/⌘ + V)</div>
                </div>
                <div class="image-import-preview" id="image-import-preview" hidden>
                    <img id="image-import-preview-img" alt="Selected image preview">
                    <div class="image-import-preview__meta">
                        <div class="image-import-preview__name" id="image-import-preview-name"></div>
                        <div class="image-import-preview__dims" id="image-import-preview-dims"></div>
                    </div>
                    <button type="button" class="image-import-preview__remove" id="image-import-clear" aria-label="Remove selected image">×</button>
                </div>
            </label>
        </div>
        <div class="image-import-modal__footer">
            <div class="image-import-modal__status" id="image-import-status">Drop or choose an image to insert.</div>
            <div class="image-import-modal__actions">
                <button type="button" class="signature-btn" id="image-import-cancel">Cancel</button>
                <button type="button" class="signature-btn signature-btn--primary" id="image-import-apply" disabled>Insert image</button>
            </div>
        </div>
    </div>
</div>
<div class="floating-zoom-bar" id="floating-zoom-bar">
    <button type="button" id="page-prev" aria-label="Previous page">‹</button>
    <input id="page-jump" type="number" min="1" value="1">
    <span>/</span>
    <span id="page-total">1</span>
    <span class="divider"></span>
    <button type="button" id="zoom-out" aria-label="Zoom out">−</button>
    <button type="button" id="zoom-in" aria-label="Zoom in">+</button>
    <span class="zoom-label" id="zoom-label">130%</span>
    <span class="divider"></span>
    <button type="button" id="page-next" aria-label="Next page">›</button>
</div>

<!-- Text Format Bar — shown when an annotation is selected -->
<div class="ann-format-bar" id="ann-format-bar">
    <!-- Font Family -->
    <select class="afb-font-select" id="afb-font" title="Font Family">
        <option value="Helvetica">Helvetica</option>
        <option value="Arial">Arial</option>
        <option value="Georgia">Georgia</option>
        <option value="TimesRoman">Times Roman</option>
        <option value="Courier">Courier</option>
        <option value="Verdana">Verdana</option>
        <option value="Palatino">Palatino</option>
        <option value="Garamond">Garamond</option>
        <option value="TrebuchetMS">Trebuchet MS</option>
        <option disabled>───── Google Fonts ─────</option>
        <option value="Roboto" style="font-family: 'Roboto', sans-serif;">Roboto</option>
        <option value="OpenSans" style="font-family: 'Open Sans', sans-serif;">Open Sans</option>
        <option value="Lato" style="font-family: 'Lato', sans-serif;">Lato</option>
        <option value="Montserrat" style="font-family: 'Montserrat', sans-serif;">Montserrat</option>
        <option value="Poppins" style="font-family: 'Poppins', sans-serif;">Poppins</option>
        <option value="SourceSansPro" style="font-family: 'Source Sans 3', sans-serif;">Source Sans 3</option>
        <option value="Inter" style="font-family: 'Inter', sans-serif;">Inter</option>
        <option value="Nunito" style="font-family: 'Nunito', sans-serif;">Nunito</option>
        <option value="Raleway" style="font-family: 'Raleway', sans-serif;">Raleway</option>
        <option value="WorkSans" style="font-family: 'Work Sans', sans-serif;">Work Sans</option>
        <option value="NotoSans" style="font-family: 'Noto Sans', sans-serif;">Noto Sans</option>
        <option value="NotoSerif" style="font-family: 'Noto Serif', serif;">Noto Serif</option>
        <option value="Merriweather" style="font-family: 'Merriweather', serif;">Merriweather</option>
        <option value="PlayfairDisplay" style="font-family: 'Playfair Display', serif;">Playfair Display</option>
        <option value="Oswald" style="font-family: 'Oswald', sans-serif;">Oswald</option>
        <option value="RobotoSlab" style="font-family: 'Roboto Slab', serif;">Roboto Slab</option>
        <option value="RobotoMono" style="font-family: 'Roboto Mono', monospace;">Roboto Mono</option>
        <option value="RobotoCondensed" style="font-family: 'Roboto Condensed', sans-serif;">Roboto Condensed</option>
        <option value="Ubuntu" style="font-family: 'Ubuntu', sans-serif;">Ubuntu</option>
        <option value="Rubik" style="font-family: 'Rubik', sans-serif;">Rubik</option>
        <option value="DMSans" style="font-family: 'DM Sans', sans-serif;">DM Sans</option>
        <option value="Mulish" style="font-family: 'Mulish', sans-serif;">Mulish</option>
        <option value="Quicksand" style="font-family: 'Quicksand', sans-serif;">Quicksand</option>
        <option value="Kanit" style="font-family: 'Kanit', sans-serif;">Kanit</option>
        <option value="FiraSans" style="font-family: 'Fira Sans', sans-serif;">Fira Sans</option>
        <option value="Lora" style="font-family: 'Lora', serif;">Lora</option>
        <option value="Cabin" style="font-family: 'Cabin', sans-serif;">Cabin</option>
        <option value="Heebo" style="font-family: 'Heebo', sans-serif;">Heebo</option>
        <option value="Karla" style="font-family: 'Karla', sans-serif;">Karla</option>
        <option value="Manrope" style="font-family: 'Manrope', sans-serif;">Manrope</option>
        <option value="JosefinSans" style="font-family: 'Josefin Sans', sans-serif;">Josefin Sans</option>
        <option value="Dosis" style="font-family: 'Dosis', sans-serif;">Dosis</option>
        <option value="Barlow" style="font-family: 'Barlow', sans-serif;">Barlow</option>
        <option value="BebasNeue" style="font-family: 'Bebas Neue', sans-serif;">Bebas Neue</option>
        <option value="PTSans" style="font-family: 'PT Sans', sans-serif;">PT Sans</option>
        <option value="CrimsonText" style="font-family: 'Crimson Text', serif;">Crimson Text</option>
        <option value="Hind" style="font-family: 'Hind', sans-serif;">Hind</option>
        <option value="Mukta" style="font-family: 'Mukta', sans-serif;">Mukta</option>
    </select>
    <div class="afb-divider"></div>
    <!-- Font Size -->
    <div class="afb-size-group" title="Font Size">
        <span class="afb-size-label">Size</span>
        <input type="range" class="afb-size-slider" id="afb-size" min="0" max="100" step="1" value="50" />
        <span class="afb-size-value" id="afb-size-value">12pt</span>
    </div>
    <div class="afb-divider"></div>
    <!-- Text Color -->
    <div class="afb-color-wrap" title="Text Color">
        <input type="color" id="afb-text-color" value="#000000" />
    </div>
    <!-- Background Color -->
    <div class="afb-color-wrap" title="Background Color" style="margin-left:2px;">
        <input type="color" id="afb-bg-color" value="#ffffff" />
    </div>
    <div class="afb-divider"></div>
    <!-- Opacity -->
    <div class="afb-opacity-group">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#9ca3af;flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <select id="afb-opacity" title="Opacity" style="width:64px;">
            <option value="1">100%</option>
            <option value="0.9">90%</option>
            <option value="0.8">80%</option>
            <option value="0.7">70%</option>
            <option value="0.6">60%</option>
            <option value="0.5">50%</option>
            <option value="0.4">40%</option>
            <option value="0.3">30%</option>
            <option value="0.2">20%</option>
            <option value="0.1">10%</option>
        </select>
    </div>
    <div class="afb-divider"></div>
    <!-- Bold -->
    <button type="button" class="afb-btn" id="afb-bold" title="Bold" aria-pressed="false"><strong>B</strong></button>
    <!-- Italic -->
    <button type="button" class="afb-btn" id="afb-italic" title="Italic" aria-pressed="false"><em>I</em></button>
    <!-- Underline -->
    <button type="button" class="afb-btn" id="afb-underline" title="Underline" aria-pressed="false" style="text-decoration:underline;">U</button>
    <div class="afb-divider"></div>
    <!-- Text Align -->
    <select id="afb-align" title="Text Alignment" style="width:76px;">
        <option value="left">Left</option>
        <option value="center">Center</option>
        <option value="right">Right</option>
    </select>
    <!-- Vertical Align -->
    <select id="afb-valign" title="Vertical Alignment" style="width:76px;">
        <option value="top">Top</option>
        <option value="middle">Middle</option>
        <option value="bottom">Bottom</option>
    </select>
    <div class="afb-divider"></div>
    <!-- Duplicate -->
    <button type="button" class="afb-btn" id="afb-copy" title="Duplicate annotation">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
    </button>
    <!-- Delete -->
    <button type="button" class="afb-btn is-danger" id="afb-delete" title="Delete annotation">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
    </button>
</div>

@vite(['resources/js/edit-new/main.js'])

</body>
</html>
