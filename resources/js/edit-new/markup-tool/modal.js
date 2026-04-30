/*
 * markup-tool/modal (Phase 7cm).
 *
 * Show/hide the markup-tool composer modal. Both helpers take the
 * modal element as a ref and an updateUi callback so the editor's
 * mode-bar / cursor / focus rules can run after every visibility
 * change without leaking refs into this module. Tear-down also
 * resets the in-flight stroke state so reopening the modal starts
 * from a clean slate.
 */

import {
    setMarkupToolDrawing,
    setMarkupToolActiveStroke,
    setMarkupToolMode as setMarkupToolModeValue,
} from '../store/markup-tool-state.js';

export function closeMarkupToolModal(markupToolModal, updateUi) {
    if (!markupToolModal) return;
    markupToolModal.classList.remove('is-open');
    markupToolModal.setAttribute('aria-hidden', 'true');
    if (typeof document !== 'undefined' && document.body) {
        document.body.classList.remove('markup-tool-modal-open');
    }
    setMarkupToolDrawing(false);
    setMarkupToolActiveStroke(null);
    if (typeof updateUi === 'function') updateUi();
}

/**
 * Switch the markup-tool composer between the 'draw' and 'erase'
 * sub-modes (Phase 7cr). Coerces any non-string / unknown value to
 * 'draw' so the store always holds one of the two enumerated modes.
 * Runs updateUi after the store mutation so the panel buttons can
 * re-sync.
 */
export function setMarkupToolMode(nextMode, updateUi) {
    setMarkupToolModeValue(String(nextMode || '') === 'erase' ? 'erase' : 'draw');
    if (typeof updateUi === 'function') updateUi();
}
