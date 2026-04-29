// Phase 5.5b render leaf: client → PDF-point coordinate conversion.
//
// `pdfPtFromClient(clientX, clientY, pi)` converts an event clientX/Y
// pair into PDF-space points (origin at bottom-left) for the overlay
// canvas of page index `pi`. Returns `null` when the page hasn't been
// rendered yet or the canvas has zero size.

import { pageData } from '../store/page-data.js';
import { canvasLogicalHeight, canvasLogicalWidth } from '../util/dom-metrics.js';

export function pdfPtFromClient(clientX, clientY, pi) {
    const data = pageData[pi];
    if (!data) return null;
    const canvas = document.getElementById('oc-' + (pi + 1));
    if (!canvas) return null;
    const r = canvas.getBoundingClientRect();
    if (!r.width || !r.height) return null;
    // Use the canvas's LOGICAL (CSS-pixel) size — `canvas.width` is the
    // DPR-scaled backing-store size after the HiDPI overlay change.
    const logicalW = canvasLogicalWidth(canvas);
    const logicalH = canvasLogicalHeight(canvas);
    const cx = (clientX - r.left) * (logicalW / r.width);
    const cy = (clientY - r.top)  * (logicalH / r.height);
    return { x: cx / data.scale, y: (data.canvasHeight - cy) / data.scale };
}
