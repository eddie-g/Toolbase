/*
 * viewport/picker (Phase 7as).
 *
 * pickViewportTargetPage(): which `.page-card` is most visible in the
 * current viewport, plus the PDF-coordinate (bottom-origin) point at
 * the viewport center inside that card. Used by the image / signature
 * import flows to drop newly-added annotations near where the user is
 * looking.
 *
 * Pure DOM read of `.page-card[data-page-number]` rects + window
 * dimensions; consults `pageData` for w/hPts to convert from screen
 * pixels back into PDF points.
 */

import { pageData } from '../store/page-data.js';

export function pickViewportTargetPage() {
    const cards = Array.from(document.querySelectorAll('.page-card[data-page-number]'));
    if (!cards.length) return null;
    const viewportH = window.innerHeight || document.documentElement.clientHeight || 0;
    const viewportW = window.innerWidth || document.documentElement.clientWidth || 0;
    const viewportCenterY = viewportH / 2;
    const viewportCenterX = viewportW / 2;
    let best = null;
    let bestOverlap = -Infinity;
    cards.forEach((card) => {
        const rect = card.getBoundingClientRect();
        const overlap = Math.max(0, Math.min(rect.bottom, viewportH) - Math.max(rect.top, 0));
        if (overlap > bestOverlap) {
            bestOverlap = overlap;
            best = { card, rect };
        }
    });
    if (!best) return null;
    const pageNum = parseInt(best.card.dataset.pageNumber || '1', 10);
    const pi = Number.isFinite(pageNum) ? pageNum - 1 : 0;
    const data = pageData[pi];
    if (!data || !data.wPts || !data.hPts) return { pi, centerPt: null };
    const rect = best.rect;
    const pageW = rect.width || 1;
    const pageH = rect.height || 1;
    // Local coords inside the page card (clamped to its bounds).
    const localX = Math.max(0, Math.min(pageW, viewportCenterX - rect.left));
    const localY = Math.max(0, Math.min(pageH, viewportCenterY - rect.top));
    const pdfXPts = (localX / pageW) * data.wPts;
    // pdfY is bottom-origin (see drawImageBackedAnnotation), so flip Y.
    const pdfYPts = data.hPts - (localY / pageH) * data.hPts;
    return { pi, centerPt: { x: pdfXPts, y: pdfYPts } };
}
