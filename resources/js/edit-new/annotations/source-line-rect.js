/*
 * sourceLineRectWithinAnnotation (Phase 7ak).
 *
 * Compute the canvas-pixel rectangle of one source line within its
 * annotation. Returns `{ left, top, width, height }` in canvas-pixel
 * coords (with `left`/`top` clamped at 0), or `null` when the line
 * has no bbox or the annotation has no resolvable visual origin.
 */

import { renderableSourceLines } from './source-lines.js';
import { sourceVisualOriginPts } from './source-visual.js';
import { annotationSourceOffset } from './box.js';

export function sourceLineRectWithinAnnotation(ann, lineIndex, scale) {
    const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox)
        ? renderableSourceLines(ann)[lineIndex].bbox
        : null;
    const visualOrigin = sourceVisualOriginPts(ann);
    if (!lineBBox || !visualOrigin) return null;
    const sourceOffset = annotationSourceOffset(ann);
    const lineLeft = Number(lineBBox[0]) + sourceOffset.dx;
    const lineTop = Number(lineBBox[1]) + sourceOffset.dy;
    const lineRight = Number(lineBBox[2]) + sourceOffset.dx;
    const lineBottom = Number(lineBBox[3]) + sourceOffset.dy;
    if (![lineLeft, lineTop, lineRight, lineBottom].every(Number.isFinite)) return null;

    return {
        left: Math.max(0, (lineLeft - visualOrigin.left) * scale),
        top: Math.max(0, (lineTop - visualOrigin.top) * scale),
        width: Math.max(2, (lineRight - lineLeft) * scale),
        height: Math.max(2, (lineBottom - lineTop) * scale),
    };
}
