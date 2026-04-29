/*
 * signature/status (Phase 7au).
 *
 * Three small helpers that toggle the signature modal's status banner,
 * dirty flag, and library save button enabled state. They depend on
 * closure-local DOM refs in main.js (signatureStatus, signatureApplyBtn,
 * signatureSaveBtn) plus the signature store; main.js re-binds each
 * function with the right refs so existing call sites stay
 * argument-free.
 */

import {
    signatureDirty,
    setSignatureDirty,
} from '../store/signature-state.js';

export function setSignatureStatus(statusEl, message, tone = 'default') {
    if (!statusEl) return;
    statusEl.textContent = String(message || '');
    statusEl.classList.toggle('is-error', tone === 'error');
    statusEl.classList.toggle('is-ready', tone === 'ready');
}

export function updateSignatureLibrarySaveUi(saveBtn) {
    if (saveBtn) saveBtn.disabled = !signatureDirty;
}

export function setSignatureDirtyState(applyBtn, saveBtn, dirty) {
    setSignatureDirty(!!dirty);
    if (applyBtn) applyBtn.disabled = !signatureDirty;
    updateSignatureLibrarySaveUi(saveBtn);
}
