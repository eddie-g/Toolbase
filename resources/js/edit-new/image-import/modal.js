/*
 * Image-import modal helpers (Phase 7cb): clear / open / close.
 * Pure DOM/store writers. Receive DOM refs as a single params
 * object so main.js keeps owning the lookups; status text is
 * forwarded to the existing setImageImportStatus helper.
 */

import { setImageImportPendingAsset } from '../store/misc-state.js';
import { editModeEnabled } from '../store/editor-modes.js';
import { setImageImportStatus } from './status.js';

export function clearImageImportSelection(refs) {
    if (!refs) return;
    const {
        imageImportFileInput,
        imageImportPreview,
        imageImportPreviewImg,
        imageImportPreviewName,
        imageImportPreviewDims,
        imageImportDropzoneInner,
        imageImportApply,
        imageImportStatus,
    } = refs;
    setImageImportPendingAsset(null);
    if (imageImportFileInput) imageImportFileInput.value = '';
    if (imageImportPreview) imageImportPreview.hidden = true;
    if (imageImportPreviewImg) imageImportPreviewImg.removeAttribute('src');
    if (imageImportPreviewName) imageImportPreviewName.textContent = '';
    if (imageImportPreviewDims) imageImportPreviewDims.textContent = '';
    if (imageImportDropzoneInner) imageImportDropzoneInner.style.display = '';
    if (imageImportApply) imageImportApply.disabled = true;
    setImageImportStatus(imageImportStatus, 'Drop or choose an image to insert.');
}

export function openImageImportModal(refs) {
    if (!refs) return;
    const { imageImportModal } = refs;
    if (!imageImportModal || editModeEnabled) return;
    clearImageImportSelection(refs);
    imageImportModal.classList.add('is-open');
    imageImportModal.setAttribute('aria-hidden', 'false');
}

export function closeImageImportModal(refs) {
    if (!refs) return;
    const { imageImportModal } = refs;
    if (!imageImportModal) return;
    imageImportModal.classList.remove('is-open');
    imageImportModal.setAttribute('aria-hidden', 'true');
    clearImageImportSelection(refs);
}
