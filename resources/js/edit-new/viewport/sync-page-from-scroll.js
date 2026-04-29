/*
 * viewport/sync-page-from-scroll (Phase 7bq).
 *
 * Reads visible page-card elements and updates the page-jump input
 * to the page with the largest viewport overlap. Pure DOM helper;
 * takes the input ref as a param so the module stays free of
 * closure-local DOM deps. main.js declares a zero-arg wrapper.
 */

export function syncCurrentPageFromScroll(pageJumpInput) {
    if (!pageJumpInput) return;
    const pages = Array.from(document.querySelectorAll('.page-card[data-page-number]'));
    if (!pages.length) return;
    const viewportBottom = window.innerHeight || document.documentElement.clientHeight || 0;
    let bestPage = null;
    let bestOverlap = -Infinity;
    pages.forEach((page) => {
        const rect = page.getBoundingClientRect();
        const overlapTop = Math.max(rect.top, 0);
        const overlapBottom = Math.min(rect.bottom, viewportBottom);
        const overlap = overlapBottom - overlapTop;
        if (overlap > bestOverlap) {
            bestOverlap = overlap;
            bestPage = page;
        }
    });
    if (!bestPage) return;
    const nextPage = parseInt(bestPage.dataset.pageNumber || '1', 10);
    if (Number.isFinite(nextPage) && String(nextPage) !== pageJumpInput.value) {
        pageJumpInput.value = String(nextPage);
    }
}
