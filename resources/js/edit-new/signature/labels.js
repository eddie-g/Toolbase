/*
 * signature/labels (Phase 7bh).
 *
 * Pure DOM-write helpers that sync the signature modal's color/width
 * value labels and modal title/subtitle copy. Both depend on
 * closure-local DOM refs in main.js; main.js re-binds zero-arg
 * wrappers so existing call sites stay unchanged.
 */

import { signatureEditTarget } from '../store/signature-state.js';

export function syncSignatureColorLabels({
    signatureColorValue,
    signatureColorInput,
    signatureTypeColorValue,
    signatureTypeColorInput,
    signatureWidthValue,
    signatureWidthInput,
    signatureSmoothingValue,
    signatureSmoothingInput,
}) {
    if (signatureColorValue && signatureColorInput) signatureColorValue.textContent = signatureColorInput.value.toUpperCase();
    if (signatureTypeColorValue && signatureTypeColorInput) signatureTypeColorValue.textContent = signatureTypeColorInput.value.toUpperCase();
    if (signatureWidthValue && signatureWidthInput) signatureWidthValue.textContent = `${signatureWidthInput.value}px`;
    if (signatureSmoothingValue && signatureSmoothingInput) signatureSmoothingValue.textContent = `${signatureSmoothingInput.value}%`;
}

export function updateSignatureModalCopy({ signatureModalTitle, signatureModalSubtitle }) {
    const isEditingSignature = Boolean(signatureEditTarget);
    if (signatureModalTitle) {
        signatureModalTitle.textContent = isEditingSignature ? 'Edit your signature' : 'Add your signature';
    }
    if (signatureModalSubtitle) {
        signatureModalSubtitle.textContent = isEditingSignature
            ? 'Adjust the selected signature or replace it with a new one, then save it back into the document.'
            : 'Create a clean signature mark for this PDF. Draw it freehand, type it in a script style, or upload an existing signature image.';
    }
}
