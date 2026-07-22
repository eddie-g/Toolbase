@php($editorCanUsePremiumFeatures = auth()->check() || auth('admin')->check())
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit New (PDF.js viewer)</title>

     {{-- Pre-load pdf.js v4 as a global, mirroring edit-new.blade so any of
           the legacy partials' inline scripts that touch window.pdfjsLib stay
           no-op-safe. --}}
     <script type="module">
          import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.min.mjs';
          pdfjsLib.GlobalWorkerOptions.workerSrc =
               'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.worker.min.mjs';
          window.pdfjsLib = pdfjsLib;
          window.dispatchEvent(new Event('pdfjsLibReady'));
     </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,400;1,700&family=Bebas+Neue&family=Cabin:ital,wght@0,400;0,700;1,400;1,700&family=Crimson+Text:ital,wght@0,400;0,700;1,400;1,700&family=DM+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Dosis:wght@400;700&family=Fira+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Heebo:wght@400;700&family=Hind:wght@400;700&family=Inter:ital,wght@0,400;0,700;1,400;1,700&family=Josefin+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Kanit:ital,wght@0,400;0,700;1,400;1,700&family=Karla:ital,wght@0,400;0,700;1,400;1,700&family=Lato:ital,wght@0,400;0,700;1,400;1,700&family=Lora:ital,wght@0,400;0,700;1,400;1,700&family=Manrope:wght@400;700&family=Merriweather:ital,wght@0,400;0,700;1,400;1,700&family=Montserrat:ital,wght@0,400;0,700;1,400;1,700&family=Mukta:wght@400;700&family=Mulish:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Nunito:ital,wght@0,400;0,700;1,400;1,700&family=Open+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Oswald:wght@400;700&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Poppins:ital,wght@0,400;0,700;1,400;1,700&family=Quicksand:wght@400;700&family=Raleway:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Condensed:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Mono:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Slab:wght@400;700&family=Roboto:ital,wght@0,400;0,700;1,400;1,700&family=Rubik:ital,wght@0,400;0,700;1,400;1,700&family=Source+Sans+3:ital,wght@0,400;0,700;1,400;1,700&family=Ubuntu:ital,wght@0,400;0,700;1,400;1,700&family=Work+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap">

    {{-- Same edit-new chrome stylesheet (topbar, toolbar, panels, modals). --}}
    @vite(['resources/css/edit-new/index.css'])

    {{-- New viewer's own stylesheet on top — overrides #pages-wrap so it
         can host the absolutely-positioned PDFViewer container. --}}
    @vite(['resources/css/edit-new-pdfjs/index.css'])
</head>
<body class="enpv-body{{ ($guided ?? false) ? ' enpv-guided' : '' }}">

{{-- Same root + data attrs as edit-new (kept so legacy partials read the
     same dataset shape). The new viewer JS reads the *-url attrs. --}}
<div id="edit-new-root"
     data-doc-id="{{ $document->id }}"
     data-csrf="{{ csrf_token() }}"
     data-info-url="{{ route('pdfTests.documentInfo', $document) }}"
     data-fonts-url="{{ route('documents.getFonts', $document) }}"
     data-save-url="{{ route('documents.saveAnnotationState', $document) }}"
     data-save-acro-form-url="{{ route('documents.saveAcroFormState', $document) }}"
     data-annotation-debug-url="{{ route('documents.annotationDebug.save', $document) }}"
     data-overwrite-url="{{ route('documents.overwriteAnnotationText') }}"
     data-editor-authenticated="{{ $editorCanUsePremiumFeatures ? '1' : '0' }}"
     data-user-logos-url="{{ route('domainSearch.userLogos') }}"
     data-notes-url="{{ $editorCanUsePremiumFeatures ? route('documents.notes.index', $document) : '' }}"
     data-download-url="{{ route('documents.downloadAnnotatedPdf', $document) }}"
     data-convert-to-pdfa-url="{{ route('documents.convertToPdfA', $document) }}"
     data-convert-to-word-url="{{ route('documents.convertToWord', $document) }}"
     data-convert-to-excel-url="{{ route('documents.convertToExcel', $document) }}"
     data-document-conversion-price="{{ config('document-conversion.price_usd_per_transaction', 0.10) }}"
     data-document-conversion-pages-per-transaction="{{ config('document-conversion.pages_per_transaction', 50) }}"
     data-encrypt-pdf-url="{{ route('documents.encryptPdf', $document) }}"
     data-download-pdfa-url="{{ route('documents.downloadPdfA') }}"
     data-download-converted-url="{{ route('documents.downloadConverted') }}"
     data-log-export-url="{{ route('documents.logExport', $document) }}"></div>

{{-- Same UI partials as /edit-new. The legacy main.js is intentionally NOT
     loaded, so all interactive buttons are inert (visual only) for now.
     The new pdf.js viewer takes over the canvas/page area below. --}}
@include('documents.edit-new._topbar')
@include('documents.edit-new._pages')
@include('documents.edit-new._shape-panel')
@include('documents.edit-new._draw-panel')
@include('documents.edit-new._highlight-panel')
@if($editorCanUsePremiumFeatures)
    @include('documents.edit-new._notes-panel')
@endif
@include('documents.edit-new._markup-modal')
@include('documents.edit-new._signature-modal')
@include('documents.edit-new._image-modal')
@include('documents.edit-new._zoom-bar')
@include('documents.edit-new._format-bar')
@include('documents.edit-new._convert-modal-pdfjs')
@include('documents.edit-new._merge-modal-pdfjs')
@include('documents.edit-new._encrypt-modal-pdfjs')

{{-- New PDF.js viewer mount + new viewer's data hooks. The blade's #pages-wrap
     becomes the host for #viewerContainer (CSS overrides #pages-wrap layout
     so the absolute child fills the available space). --}}
<div id="enpv-root"
     data-doc-id="{{ $document->id }}"
     data-guided="{{ ($guided ?? false) ? '1' : '0' }}"
     data-template-type="{{ $document->template_type ?? '' }}"
     data-template-slug="{{ $document->template_slug ?? '' }}"
     data-csrf="{{ csrf_token() }}"
     data-pdf-url="{{ route('documents.file', $document) }}"
     data-current-pdf-url="{{ route('documents.file', $document) }}"
     data-baked-url="{{ route('documents.bakedPdf', $document) }}"
     data-clean-url="{{ route('documents.cleanPdf', $document) }}"
     data-rewrite-url="{{ route('documents.editPdfjsRewriteTj', $document) }}"
     data-redact-url="{{ route('documents.editPdfjsRedactSourceText', $document) }}"
     data-burn-url="{{ route('documents.editPdfjsBurnLayer', $document) }}"
     data-move-url="{{ route('documents.editPdfjsMoveTj', $document) }}"
     data-reflow-url="{{ route('documents.editPdfjsReflowText', $document) }}"
     data-guided-convert-url="{{ route('documents.convertGuidedAcroForm', $document) }}"
     data-regenerate-invoice-url="{{ route('documents.regenerateInvoice', $document) }}"
     data-regenerate-template-url="{{ route('documents.regenerateTemplate', $document) }}"
     data-add-blank-page-url="{{ route('documents.addBlankPage', $document) }}"
     data-reorder-pages-url="{{ route('documents.reorderPages', $document) }}"
     data-merge-pdf-url="{{ route('documents.mergePdfs', $document) }}"
     data-split-pdf-url="{{ route('documents.splitPdf', $document) }}"
     data-document-name="{{ $document->original_name }}"
     data-merge-max-files="{{ config('pdf_editor.merge.max_files', 10) }}"
     data-merge-max-file-bytes="{{ (int) config('pdf_editor.merge.max_file_kb', 20480) * 1024 }}"
     data-merge-max-pages="{{ config('pdf_editor.merge.max_pages', 1000) }}">
    <div id="viewerContainer">
        <div id="viewer" class="pdfViewer"></div>
    </div>
     <div id="enpv-loading-screen" class="enpv-loading-screen" role="status" aria-live="polite">
          <div class="enpv-loading-card">
               <div class="enpv-loading-spinner" aria-hidden="true"></div>
               <div class="enpv-loading-title">Loading editor...</div>
          </div>
     </div>

    {{-- Floating inline editor for click-to-edit on textLayer spans. --}}
    <div id="enpv-edit-bar" class="enpv-edit-bar" hidden>
        <div class="enpv-edit-row">
            <input id="enpv-input" type="text" autocomplete="off" spellcheck="false">
            <button id="enpv-apply" class="enpv-btn enpv-primary">Apply</button>
            <button id="enpv-cancel" class="enpv-btn">Cancel</button>
        </div>
        <div id="enpv-orig" class="enpv-orig"></div>
    </div>

    {{-- Status pill (small, lower-left). The legacy save-status in the topbar
         is left untouched but inert. --}}
    <div id="enpv-status" class="enpv-status-pill">Loading…</div>
    <button id="enpv-download" class="enpv-btn enpv-download-fab" disabled>Download edited PDF</button>

    {{-- Floating hover menu for the currently-selected annotation box.
         Mirrors the legacy /edit-new `tm-${pi+1}` text-menu affordance.
         Hidden until a box is selected; reparented on demand to the page
         div that hosts the box so coordinates stay correct across zoom. --}}
    <div id="enpv-ann-menu" class="enpv-ann-menu" hidden>
        <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-grip" data-action="move" title="Drag to move">⠿</button>
                         <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-cut" data-action="cut" title="Cut shape" aria-label="Cut shape">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M20 4 8.12 15.88"></path><path d="M14.47 14.48 20 20"></path><path d="M8.12 8.12 12 12"></path></svg>
                         </button>
          <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-burn" data-action="burn" title="Burn into PDF" aria-label="Burn into PDF">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c4.4 0 8-3.1 8-7.3 0-3-1.8-5.1-4.2-7.2.1 2.1-.8 3.4-2.1 4.1.2-3.7-1.8-6.4-5.2-9.6.2 3.6-1.1 5.7-2.5 7.6C4.8 11.2 4 12.8 4 14.7 4 18.9 7.6 22 12 22Z"></path></svg>
          </button>
          <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-lock" data-action="lock" title="Lock annotation" aria-label="Lock annotation">
               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 7.88-1"></path></svg>
          </button>
          <button type="button" class="enpv-ann-menu-btn" data-action="front" title="Bring to front" aria-label="Bring to front">⬆</button>
          <button type="button" class="enpv-ann-menu-btn" data-action="back" title="Send to back" aria-label="Send to back">⬇</button>
          <div class="enpv-ann-menu-divider" aria-hidden="true"></div>
          <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-ok" data-action="edit" title="Edit text" aria-label="Edit text">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          </button>
          <div class="enpv-ann-menu-divider" aria-hidden="true"></div>
          <button type="button" class="enpv-ann-menu-btn" data-action="copy" title="Copy text" aria-label="Copy text">
               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          </button>
          <button type="button" class="enpv-ann-menu-btn enpv-ann-menu-delete" data-action="delete" title="Delete" aria-label="Delete">
               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          </button>
          <div class="enpv-ann-menu-divider" aria-hidden="true"></div>
          <button type="button" class="enpv-ann-menu-btn" data-action="deselect" title="Deselect (Esc)" aria-label="Deselect">✕</button>
    </div>
</div>

<div class="enpv-page-manager-rail" id="enpv-page-manager-rail">
     <button type="button" class="enpv-page-manager-open" id="enpv-page-manager-open" title="Manage pages" aria-haspopup="dialog" aria-controls="enpv-page-manager-modal">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
               <rect x="4" y="3" width="12" height="16" rx="2"></rect>
               <path d="M8 7h4"></path>
               <path d="M8 11h4"></path>
               <path d="M18 7h2v14H8v-2"></path>
          </svg>
          <span>Pages</span>
     </button>
</div>

@include('documents.edit-new._guided-helper')

<div class="enpv-page-manager-modal" id="enpv-page-manager-modal" role="dialog" aria-modal="true" aria-labelledby="enpv-page-manager-title" aria-hidden="true" hidden>
     <div class="enpv-page-manager-card">
          <div class="enpv-page-manager-header">
               <div>
                    <h2 id="enpv-page-manager-title">Manage Pages</h2>
                    <p id="enpv-page-manager-count">Loading pages...</p>
               </div>
               <button type="button" class="enpv-page-manager-close" id="enpv-page-manager-close" aria-label="Close page manager">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                         <path d="M18 6 6 18"></path>
                         <path d="m6 6 12 12"></path>
                    </svg>
               </button>
          </div>
          <div class="enpv-page-manager-toolbar">
               <button type="button" class="enpv-page-manager-action" id="enpv-page-manager-add">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                         <path d="M12 5v14"></path>
                         <path d="M5 12h14"></path>
                    </svg>
                    <span>Add Page</span>
               </button>
               <button type="button" class="enpv-page-manager-action is-danger" id="enpv-page-manager-delete">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                         <path d="M3 6h18"></path>
                         <path d="M8 6V4h8v2"></path>
                         <path d="m19 6-.8 14H5.8L5 6"></path>
                         <path d="M10 11v5"></path>
                         <path d="M14 11v5"></path>
                    </svg>
                    <span>Delete Page</span>
               </button>
          </div>
          <div class="enpv-page-manager-status" id="enpv-page-manager-status" role="status" aria-live="polite"></div>
          <div class="enpv-page-manager-grid" id="enpv-page-manager-grid"></div>
     </div>
</div>

<div class="enpv-layers-rail" id="enpv-layers-rail">
     <button type="button" class="enpv-layers-open" id="enpv-layers-open" title="Manage layers" aria-expanded="false" aria-controls="enpv-layers-panel">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
               <path d="m12 3-8 4 8 4 8-4-8-4Z"></path>
               <path d="m4 12 8 4 8-4"></path>
               <path d="m4 17 8 4 8-4"></path>
          </svg>
          <span>Layers</span>
     </button>
</div>

@unless($guided ?? false)
<div class="enpv-notes-rail" id="enpv-notes-rail">
     <button type="button"
             class="enpv-notes-open{{ $editorCanUsePremiumFeatures ? '' : ' is-premium-locked' }}"
             id="ftb-notes"
             title="{{ $editorCanUsePremiumFeatures ? 'Notes — premium note pins for logged-in users' : 'Notes require a free account' }}"
             @unless($editorCanUsePremiumFeatures) data-premium-feature="Notes" @endunless>
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
               <path d="M4 4h16v16H4z"></path>
               <path d="M8 8h8"></path>
               <path d="M8 12h8"></path>
               <path d="M8 16h5"></path>
          </svg>
          <span>Notes</span>
          @unless($editorCanUsePremiumFeatures)
               <small class="ftb-premium-label">Premium</small>
          @endunless
     </button>
</div>
@endunless

<aside class="enpv-layers-panel" id="enpv-layers-panel" aria-hidden="true" hidden>
     <div class="enpv-layers-header">
          <h2>Layers</h2>
          <button type="button" class="enpv-layers-close" id="enpv-layers-close" aria-label="Close layers panel">
               <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"></path>
                    <path d="m6 6 12 12"></path>
               </svg>
          </button>
     </div>
     <div class="enpv-layers-info">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
               <circle cx="12" cy="12" r="9"></circle>
               <path d="M12 11v5"></path>
               <path d="M12 8h.01"></path>
          </svg>
          <span>Reorder items to move them to the back or front.</span>
     </div>
     <div class="enpv-layers-list" id="enpv-layers-list"></div>
</aside>

<aside class="enpv-debug-panel" id="enpv-debug-panel" aria-hidden="true" hidden>
     <div class="enpv-debug-panel__header">
          <div>
               <h2>Annotation Debug</h2>
               <p id="enpv-debug-subtitle" class="enpv-debug-subtitle">
                    <input id="enpv-debug-annotation-id" class="enpv-debug-annotation-id" type="text" value="" readonly aria-label="Annotation id" title="Click to copy annotation id">
                    <span id="enpv-debug-annotation-label">No annotation selected</span>
               </p>
          </div>
          <button type="button" class="enpv-debug-close" id="enpv-debug-close" aria-label="Close debug panel">
               <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"></path>
                    <path d="m6 6 12 12"></path>
               </svg>
          </button>
     </div>
     <div class="enpv-debug-section">
          <div class="enpv-debug-section-title">White Mask</div>
          <div class="enpv-debug-grid">
               <label>X <input id="enpv-debug-mask-x" type="number" step="0.1"></label>
               <label>Y <input id="enpv-debug-mask-y" type="number" step="0.1"></label>
               <label>W <input id="enpv-debug-mask-w" type="number" step="0.1" min="0.1"></label>
               <label>H <input id="enpv-debug-mask-h" type="number" step="0.1" min="0.1"></label>
          </div>
     </div>
     <div class="enpv-debug-section">
          <label class="enpv-debug-section-title" for="enpv-debug-note">Notes</label>
          <textarea id="enpv-debug-note" rows="6" maxlength="50000"></textarea>
     </div>
     <div class="enpv-debug-section">
          <div class="enpv-debug-section-title">Images</div>
          <input id="enpv-debug-images" type="file" accept="image/*" multiple>
          <div class="enpv-debug-images" id="enpv-debug-image-list"></div>
     </div>
     <div class="enpv-debug-status" id="enpv-debug-status" role="status" aria-live="polite"></div>
     <div class="enpv-debug-actions">
          <button type="button" class="enpv-debug-save" id="enpv-debug-save">Save Debug</button>
     </div>
</aside>

@vite(['resources/js/edit-new-pdfjs/main.js'])

</body>
</html>
