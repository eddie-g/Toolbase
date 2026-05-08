/*
 * DOM layout helpers for the editor chrome (Phase 7r).
 *
 * Pure functions that read live DOM state for the editor's
 * sticky-toolbar zone and per-page card sizing. No mutable editor
 * state; safe to import from anywhere in the editor module graph.
 */

export function getStickyUiBottomEdge() {
    let safeTop = 0;
    [
        document.querySelector('.top-bar'),
        document.getElementById('floating-tool-bar'),
        document.getElementById('ann-format-bar'),
        document.getElementById('shape-tool-panel'),
    ].forEach((element) => {
        if (!(element instanceof HTMLElement)) return;
        const isHidden = window.getComputedStyle(element).display === 'none';
        if (isHidden) return;
        const rect = element.getBoundingClientRect();
        if (rect.height <= 0 || rect.bottom <= 0) return;
        safeTop = Math.max(safeTop, rect.bottom);
    });
    return Math.max(0, safeTop);
}

export function updatePageCardWidth(pi, canvasWidth) {
    const card = document.getElementById(`card-${pi + 1}`);
    if (!card) return;
    card.style.width = `${Math.ceil(Math.max(0, canvasWidth) + 32)}px`;
}
