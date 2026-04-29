/*
 * Source-visual rect/origin helpers (Phase 7x).
 *
 * Compute the canvas-pixel bounding rect (or top-left origin in PDF
 * points) for a promoted-from-extraction annotation's *original*
 * source layout — the geometry used when the annotation has not been
 * user-modified and should still render in-place over the rasterized
 * source PDF. Pure: depends only on `renderableSourceLines` and
 * `annotationSourceOffset`.
 */

import { renderableSourceLines } from './source-lines.js';
import { annotationSourceOffset } from './box.js';

export function sourceVisualRectPx(ann, scale) {
    const sourceOffset = annotationSourceOffset(ann);
    const lines = renderableSourceLines(ann);
    if (lines.length) {
        const xs = lines.map((line) => Number(line.bbox?.[0]) + sourceOffset.dx).filter(Number.isFinite);
        const ys = lines.map((line) => Number(line.bbox?.[1]) + sourceOffset.dy).filter(Number.isFinite);
        const xe = lines.map((line) => Number(line.bbox?.[2]) + sourceOffset.dx).filter(Number.isFinite);
        const ye = lines.map((line) => Number(line.bbox?.[3]) + sourceOffset.dy).filter(Number.isFinite);
        if (xs.length && ys.length && xe.length && ye.length) {
            const left = Math.min(...xs) * scale;
            const top = Math.min(...ys) * scale;
            const right = Math.max(...xe) * scale;
            const bottom = Math.max(...ye) * scale;
            return {
                left,
                top,
                width: Math.max(2, right - left),
                height: Math.max(2, bottom - top),
            };
        }
    }

    const sL = Number(ann?.sourceBlockLeft);
    const sT = Number(ann?.sourceBlockTop);
    const sW = Number(ann?.sourceBlockWidth);
    const sH = Number(ann?.sourceBlockHeight);
    if ([sL, sT, sW, sH].every(Number.isFinite) && sW > 0 && sH > 0) {
        return {
            left: (sL + sourceOffset.dx) * scale,
            top: (sT + sourceOffset.dy) * scale,
            width: Math.max(2, sW * scale),
            height: Math.max(2, sH * scale),
        };
    }

    return null;
}

export function sourceVisualOriginPts(ann) {
    const sourceOffset = annotationSourceOffset(ann);
    const lines = renderableSourceLines(ann);
    if (lines.length) {
        const xs = lines.map((line) => Number(line.bbox?.[0]) + sourceOffset.dx).filter(Number.isFinite);
        const ys = lines.map((line) => Number(line.bbox?.[1]) + sourceOffset.dy).filter(Number.isFinite);
        if (xs.length && ys.length) {
            return {
                left: Math.min(...xs),
                top: Math.min(...ys),
            };
        }
    }

    const sL = Number(ann?.sourceBlockLeft);
    const sT = Number(ann?.sourceBlockTop);
    if ([sL, sT].every(Number.isFinite)) {
        return {
            left: sL + sourceOffset.dx,
            top: sT + sourceOffset.dy,
        };
    }

    return null;
}
