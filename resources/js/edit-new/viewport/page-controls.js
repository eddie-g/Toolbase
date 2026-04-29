/*
 * viewport/page-controls (Phase 7bo).
 *
 * Pure DOM-write helper that syncs the page-total label and clamps
 * the page-jump input value/max against the document's total pages.
 * Takes the two DOM refs as a single object so the module stays
 * free of closure-local DOM deps; main.js declares a single-arg
 * wrapper preserving the existing call site.
 */

export function updatePageControls({ pageTotalLabel, pageJumpInput }, totalPages = 1) {
    if (pageTotalLabel) pageTotalLabel.textContent = String(totalPages || 1);
    if (pageJumpInput) {
        pageJumpInput.max = String(totalPages || 1);
        const current = parseInt(pageJumpInput.value || '1', 10);
        if (!Number.isFinite(current) || current < 1 || current > (totalPages || 1)) {
            pageJumpInput.value = '1';
        }
    }
}
