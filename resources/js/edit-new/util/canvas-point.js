/*
 * canvasPointFromEvent (Phase 7af).
 *
 * Convert a mouse/pointer event to coordinates in the canvas's
 * LOGICAL (CSS-pixel) space, not its backing-store space. After the
 * HiDPI overlay change, `canvas.width` is `cssW * dpr`, so we must
 * read the logical width via canvasLogicalWidth/Height to keep
 * downstream hit-testing code (pdfPtFromClient, shape hit tests, …)
 * unchanged.
 */

import { canvasLogicalWidth, canvasLogicalHeight } from './dom-metrics.js';

export function canvasPointFromEvent(e, canvas) {
    const r = canvas.getBoundingClientRect();
    if (!r.width || !r.height) return null;
    const logicalW = canvasLogicalWidth(canvas);
    const logicalH = canvasLogicalHeight(canvas);
    return {
        x: (e.clientX - r.left) * (logicalW / r.width),
        y: (e.clientY - r.top)  * (logicalH / r.height),
    };
}
