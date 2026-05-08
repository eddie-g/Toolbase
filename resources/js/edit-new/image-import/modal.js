/*
 * Image-import modal helpers (Phase 7cb): clear / open / close.
 * Pure DOM/store writers. Receive DOM refs as a single params
 * object so main.js keeps owning the lookups; status text is
 * forwarded to the existing setImageImportStatus helper.
 */

import { setImageImportPendingAsset } from '../store/misc-state.js';
import { editModeEnabled } from '../store/editor-modes.js';
import { setImageImportStatus } from './status.js';

export const IMAGE_IMPORT_ACCEPTED_TYPES = new Set([
    'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml',
]);
export const IMAGE_IMPORT_MAX_BYTES = 25 * 1024 * 1024; // 25 MB

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

/*
 * imageImportLoadFile — read an uploaded file, decode it as an image,
 * and stash it as the pending image-import asset (Phase 7ce).
 * Validates mime/size, then drives status text + preview DOM via the
 * supplied refs.
 */
export function imageImportLoadFile(file, refs) {
    if (!file || !refs) return;
    const {
        imageImportPreview,
        imageImportPreviewImg,
        imageImportPreviewName,
        imageImportPreviewDims,
        imageImportDropzoneInner,
        imageImportApply,
        imageImportStatus,
    } = refs;
    const mime = String(file.type || '').toLowerCase();
    if (!IMAGE_IMPORT_ACCEPTED_TYPES.has(mime)) {
        setImageImportStatus(imageImportStatus, 'Unsupported file type. Use PNG, JPG, GIF, WebP, or SVG.', true);
        return;
    }
    if (file.size > IMAGE_IMPORT_MAX_BYTES) {
        setImageImportStatus(imageImportStatus, 'File is too large (max 25 MB).', true);
        return;
    }
    const reader = new FileReader();
    reader.onerror = () => setImageImportStatus(imageImportStatus, 'Could not read that file.', true);
    reader.onload = (ev) => {
        const dataUrl = String(ev.target?.result || '');
        if (!dataUrl) {
            setImageImportStatus(imageImportStatus, 'Could not read that file.', true);
            return;
        }
        const probe = new Image();
        probe.onerror = () => setImageImportStatus(imageImportStatus, 'That image could not be decoded.', true);
        probe.onload = () => {
            const w = probe.naturalWidth || probe.width || 1;
            const h = probe.naturalHeight || probe.height || 1;
            setImageImportPendingAsset({
                dataUrl,
                fileName: file.name || 'image',
                mimeType: mime,
                width: w,
                height: h,
            });
            if (imageImportPreviewImg) imageImportPreviewImg.src = dataUrl;
            if (imageImportPreviewName) imageImportPreviewName.textContent = file.name || 'image';
            if (imageImportPreviewDims) imageImportPreviewDims.textContent = `${w} × ${h} px`;
            if (imageImportPreview) imageImportPreview.hidden = false;
            if (imageImportDropzoneInner) imageImportDropzoneInner.style.display = 'none';
            if (imageImportApply) imageImportApply.disabled = false;
            setImageImportStatus(imageImportStatus, 'Ready to insert on the current page.');
        };
        probe.src = dataUrl;
    };
    reader.readAsDataURL(file);
}
