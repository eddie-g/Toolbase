/*
 * image-import/status (Phase 7ax).
 *
 * Single-line helper that updates the "Image import" modal status
 * banner. Depends on the `imageImportStatus` DOM ref.
 */

export function setImageImportStatus(statusEl, text, isError = false) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.style.color = isError ? '#b91c1c' : '';
}
