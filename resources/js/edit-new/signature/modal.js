/*
 * signature/modal (Phase 7cn).
 *
 * Tear down the signature composer modal: hides the dialog (CSS
 * class + aria-hidden + body class), resets in-flight stroke state
 * and edit-target via the signature-state store, then runs the
 * supplied updateModalCopy + updateUi callbacks so the editor's
 * mode-bar / labels can re-sync.
 */

import {
    setSignatureDrawing,
    setSignatureActiveStroke,
    setSignatureEditTarget,
} from '../store/signature-state.js';

export function closeSignatureModal(signatureModal, { updateModalCopy, updateUi } = {}) {
    if (!signatureModal) return;
    signatureModal.classList.remove('is-open');
    signatureModal.setAttribute('aria-hidden', 'true');
    if (typeof document !== 'undefined' && document.body) {
        document.body.classList.remove('signature-modal-open');
    }
    setSignatureDrawing(false);
    setSignatureActiveStroke(null);
    setSignatureEditTarget(null);
    if (typeof updateModalCopy === 'function') updateModalCopy();
    if (typeof updateUi === 'function') updateUi();
}
