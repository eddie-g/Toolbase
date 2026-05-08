// Phase 2 of the PDF.js v4 migration: mount the real AnnotationEditorLayer
// (FREETEXT mode) per page, alongside the existing custom contenteditable
// editor. Opt-in via `?phase2=1` in the URL — when not enabled, this module
// is a no-op and nothing changes for normal users.
//
// Goals:
//   - Prove that AnnotationEditorLayer can be mounted into our page DOM
//     using the same viewport/scale already used by the page canvas + TextLayer.
//   - Give the user a clickable surface to add FREETEXT annotations using
//     PDF.js's built-in editor primitives (caret, selection, IME, undo/redo).
//   - Do NOT yet replace the existing contenteditable. Bridging serialization
//     back into our annotation store / editedTexts is a follow-up step once
//     the editor itself feels right.
//
// Activation:
//   - Append `?phase2=1` to the edit-new URL.
//   - Once loaded, press the "F" key (or call window.__phase2.freeText()) to
//     switch the UIManager into FREETEXT mode. Click anywhere on a page to
//     drop a new text editor. Press Escape or call window.__phase2.none()
//     to leave FREETEXT mode.
//
// Trade-offs / known gaps:
//   - We provide minimal IL10n + EventBus stubs because the full pdf_viewer
//     scaffolding isn't loaded. PDF.js's editor mostly uses these for toolbar
//     wiring and accessibility strings — the core editor works without them.
//   - AnnotationEditorLayer expects a DrawLayer per page. We mount one inside
//     the page-content div. It's required for highlight/ink editors; FREETEXT
//     doesn't strictly need it but the constructor does.
//   - StructTree / accessibility manager are stubbed to null.

const PHASE2_FLAG_KEYS = ['phase2', 'aelayer'];

let _enabled = null;
let _uiManager = null;
let _layers = new Map(); // pageIndex -> { layer, drawLayer, container }
let _mode = 0; // AnnotationEditorType.NONE
let _pdfDocument = null;

/**
 * Returns true when phase 2 should be active for this page load.
 */
export function isPhase2Enabled() {
    if (_enabled !== null) return _enabled;
    try {
        const params = new URLSearchParams(window.location.search);
        _enabled = PHASE2_FLAG_KEYS.some((k) => params.has(k));
    } catch (_e) {
        _enabled = false;
    }
    if (_enabled) {
        document.body?.classList?.add('phase2-enabled');
        // Intentionally noisy — phase 2 is a beta opt-in and the user wants
        // to see at a glance that it's on.
        try { console.info('[phase2] AnnotationEditorLayer scaffolding ENABLED. Press F to enter FREETEXT mode, Escape to leave.'); } catch (_) {}
    }
    return _enabled;
}

function getPdfjsLib() {
    return typeof window !== 'undefined' ? window.pdfjsLib : null;
}

/**
 * Build a minimal IL10n stub. PDF.js's editor uses this for toolbar labels,
 * tooltips, and screen-reader strings. The core editor flow does NOT depend
 * on these returning real translations.
 */
function makeL10nStub() {
    const get = async (_key, _args, fallback) => fallback ?? '';
    return {
        getLanguage: () => 'en-US',
        getDirection: () => 'ltr',
        get,
        translate: async () => {},
        pause: () => {},
        resume: () => {},
    };
}

/**
 * Build a minimal EventBus stub matching the interface used by
 * AnnotationEditorUIManager. This is intentionally a tiny implementation
 * rather than importing the full pdf_viewer.mjs EventBus to keep the
 * scaffolding self-contained.
 */
function makeEventBusStub() {
    const handlers = new Map();
    return {
        on(name, cb) {
            if (!handlers.has(name)) handlers.set(name, new Set());
            handlers.get(name).add(cb);
        },
        off(name, cb) {
            handlers.get(name)?.delete(cb);
        },
        dispatch(name, data) {
            const set = handlers.get(name);
            if (!set) return;
            for (const cb of set) {
                try { cb(data); } catch (e) { console.warn('[phase2] eventBus handler error', name, e); }
            }
        },
        _on(name, cb) { this.on(name, cb); },
        _off(name, cb) { this.off(name, cb); },
    };
}

/**
 * Lazily create the singleton UIManager. PDF.js requires one manager per
 * document; pages register themselves via addLayer().
 */
function ensureUIManager() {
    if (_uiManager) return _uiManager;
    const pdfjsLib = getPdfjsLib();
    if (!pdfjsLib?.AnnotationEditorUIManager) {
        console.warn('[phase2] pdfjsLib.AnnotationEditorUIManager not available — phase 2 disabled.');
        return null;
    }
    const container = document.getElementById('pages-host') || document.body;
    const viewer = container; // PDF.js uses this for focus/scroll math
    const eventBus = makeEventBusStub();
    try {
        _uiManager = new pdfjsLib.AnnotationEditorUIManager(
            container,            // container
            viewer,               // viewer
            null,                 // altTextManager
            eventBus,             // eventBus
            _pdfDocument,         // pdfDocument
            null,                 // pageColors
            null,                 // highlightColors
            false,                // enableHighlightFloatingButton
            false,                // enableUpdatedAddImage
            false,                // enableNewAltTextWhenAddingImage
            null,                 // mlManager
            null,                 // editorUndoBar
            true,                 // supportsPinchToZoom
        );
        // Wire keyboard for the document so undo/redo work.
        try { _uiManager.addEditListeners?.(); } catch (_) {}
        try { window.__phase2 = window.__phase2 || {}; window.__phase2.uiManager = _uiManager; } catch (_) {}
    } catch (e) {
        console.warn('[phase2] Failed to construct AnnotationEditorUIManager — phase 2 disabled.', e);
        return null;
    }
    return _uiManager;
}

/**
 * Mount or refresh the AnnotationEditorLayer for one page. Called from the
 * main render loop right after the TextLayer has been rendered.
 *
 * @param {number} pageIndex 0-based
 * @param {Object} pdfPage   the PDFPageProxy from pdfjsLib.getDocument(...).getPage()
 * @param {Object} viewport  the viewport at the same scale used for the page canvas
 */
export async function setupPhase2Page(pageIndex, pdfPage, viewport) {
    if (!isPhase2Enabled()) return;
    const pdfjsLib = getPdfjsLib();
    if (!pdfjsLib?.AnnotationEditorLayer) {
        console.warn('[phase2] pdfjsLib.AnnotationEditorLayer not available.');
        return;
    }
    if (!_pdfDocument) {
        // Best-effort: pull from the PDFPageProxy parent if available.
        _pdfDocument = pdfPage._transport?._params?.pdfDocument || pdfPage._transport || null;
    }
    const uiManager = ensureUIManager();
    if (!uiManager) return;

    const pg = pageIndex + 1;
    const pageEl = document.getElementById('pc-' + pg);
    if (!pageEl) return;

    let container = document.getElementById('ael-' + pg);
    if (!container) {
        // Page card was created before the phase2 flag was wired in — make
        // the container on demand.
        container = document.createElement('div');
        container.id = 'ael-' + pg;
        container.className = 'annotation-editor-layer';
        // Insert before the active-editor div so our custom contenteditable
        // still wins for clicks on existing annotations.
        const beforeEl = document.getElementById('ae-' + pg);
        if (beforeEl) pageEl.insertBefore(container, beforeEl);
        else pageEl.appendChild(container);
    }

    // Tear down any previous layer for this page (e.g. after a re-render).
    const previous = _layers.get(pageIndex);
    if (previous) {
        try { previous.layer?.destroy?.(); } catch (_) {}
        try { previous.drawLayer?.destroy?.(); } catch (_) {}
        container.replaceChildren();
    }

    // DrawLayer is required by the AnnotationEditorLayer constructor even
    // though FREETEXT doesn't render into it.
    let drawLayer = null;
    try {
        if (pdfjsLib.DrawLayer) {
            drawLayer = new pdfjsLib.DrawLayer({ pageIndex });
            // DrawLayer needs its own DOM root.
            const drawHost = document.createElement('div');
            drawHost.className = 'phase2-draw-layer';
            container.appendChild(drawHost);
            drawLayer.setParent?.(drawHost);
        }
    } catch (e) {
        console.warn('[phase2] DrawLayer construction failed — continuing without it.', e);
    }

    let layer = null;
    try {
        layer = new pdfjsLib.AnnotationEditorLayer({
            uiManager,
            pageIndex,
            div: container,
            structTreeLayer: null,
            accessibilityManager: null,
            annotationLayer: null,
            drawLayer,
            textLayer: document.getElementById('tl-' + pg) || null,
            viewport,
            l10n: makeL10nStub(),
        });
    } catch (e) {
        console.warn('[phase2] AnnotationEditorLayer construction failed for page ' + pg, e);
        return;
    }

    try {
        layer.render({ viewport });
    } catch (e) {
        console.warn('[phase2] AnnotationEditorLayer render failed for page ' + pg, e);
    }

    _layers.set(pageIndex, { layer, drawLayer, container });

    // Re-apply current mode in case pages render after the user already
    // switched modes (e.g. lazy page rendering).
    if (_mode !== 0) {
        try { layer.updateMode(_mode); } catch (_) {}
    }
}

/**
 * Switch the global editor mode. PDF.js exports the type enum as
 * pdfjsLib.AnnotationEditorType: NONE=0, FREETEXT=3, INK=15, STAMP=13,
 * HIGHLIGHT=9 (numeric values may shift across minor versions; use the
 * named enum at runtime).
 */
export async function setPhase2Mode(modeName) {
    if (!isPhase2Enabled()) return;
    const pdfjsLib = getPdfjsLib();
    const uiManager = ensureUIManager();
    if (!uiManager || !pdfjsLib?.AnnotationEditorType) return;
    const enumKey = String(modeName || 'NONE').toUpperCase();
    const mode = pdfjsLib.AnnotationEditorType[enumKey];
    if (typeof mode !== 'number') {
        console.warn('[phase2] Unknown editor mode: ' + modeName);
        return;
    }
    _mode = mode;
    try { await uiManager.updateMode(mode); } catch (e) { console.warn('[phase2] updateMode failed', e); }
    try { document.body?.classList?.toggle('phase2-mode-freetext', enumKey === 'FREETEXT'); } catch (_) {}
}

/**
 * Wire keyboard shortcut and global helpers. Idempotent.
 */
export function installPhase2Hotkeys() {
    if (!isPhase2Enabled()) return;
    if (window.__phase2?._hotkeysInstalled) return;
    window.__phase2 = window.__phase2 || {};
    window.__phase2._hotkeysInstalled = true;
    window.__phase2.freeText = () => setPhase2Mode('FREETEXT');
    window.__phase2.none = () => setPhase2Mode('NONE');
    window.__phase2.layers = _layers;
    document.addEventListener('keydown', (e) => {
        if (!isPhase2Enabled()) return;
        // Ignore when the user is typing into any editable surface.
        const t = e.target;
        if (t && (t.isContentEditable || t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
        if (e.key === 'f' || e.key === 'F') {
            setPhase2Mode('FREETEXT');
            e.preventDefault();
        } else if (e.key === 'Escape') {
            if (_mode !== 0) {
                setPhase2Mode('NONE');
                e.preventDefault();
            }
        }
    });
}

/**
 * Capture the PDFDocumentProxy so the UIManager can be constructed with it.
 * Called from the render loop right after `pdfjsLib.getDocument(url).promise`.
 */
export function setPhase2PdfDocument(pdfDocument) {
    _pdfDocument = pdfDocument || null;
}
