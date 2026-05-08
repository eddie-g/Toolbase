/*
 * Canvas-pixel rect helpers for annotation hit-testing & overlays
 * (Phase 7z).
 *
 * `lineSelectionRectPx` widens a line shape's bounding box to give a
 * comfortable hit/selection area. `annRectPx` is the unified
 * "where on the canvas does this annotation live?" query — it picks
 * the line-expansion rect, the source-visual rect, or the stored box
 * rect depending on annotation state.
 */

import { clamp01 } from '../util/math.js';
import { isShapeAnnotation, isLineShape, isHiddenPdfjsAnnotation } from './types.js';
import { resolveAnnBox } from './box.js';
import { sourceVisualRectPx } from './source-visual.js';
import { annotationUsesStoredBoxForDisplay } from './uses-stored-box.js';

// Convert PDF-space box to canvas-pixel rect { left, top, width, height }
export function lineSelectionRectPx(ann, scale, canvasHeight, extraPaddingPx = 14) {
    if (!isShapeAnnotation(ann) || !isLineShape(ann)) return null;
    const box = resolveAnnBox(ann);
    if (!box) return null;
    const left = box.x * scale;
    const top = canvasHeight - (box.y + box.h) * scale;
    const width = Math.max(1, box.w * scale);
    const height = Math.max(1, box.h * scale);
    const lineGeometry = {
        lineStartX: clamp01(ann.lineStartX, 0),
        lineStartY: clamp01(ann.lineStartY, 0),
        lineEndX: clamp01(ann.lineEndX, 1),
        lineEndY: clamp01(ann.lineEndY, 1),
    };
    const x1 = left + (width * lineGeometry.lineStartX);
    const y1 = top + (height * lineGeometry.lineStartY);
    const x2 = left + (width * lineGeometry.lineEndX);
    const y2 = top + (height * lineGeometry.lineEndY);
    const strokePx = Math.max(0, (Number(ann.strokeWidth) || 0) * scale);
    const clearance = (strokePx / 2) + Math.max(0, Number(extraPaddingPx) || 0);
    return {
        left: Math.min(x1, x2) - clearance,
        top: Math.min(y1, y2) - clearance,
        width: Math.abs(x2 - x1) + (clearance * 2),
        height: Math.abs(y2 - y1) + (clearance * 2),
    };
}

export function annRectPx(ann, scale, canvasHeight, opts = {}) {
    if (isHiddenPdfjsAnnotation(ann)) return null;
    if (opts.expandLine !== false) {
        const lineRect = lineSelectionRectPx(ann, scale, canvasHeight);
        if (lineRect) return lineRect;
    }
    if (!annotationUsesStoredBoxForDisplay(ann)) {
        const sourceRect = sourceVisualRectPx(ann, scale);
        if (sourceRect) return sourceRect;
    }
    const box = resolveAnnBox(ann);
    if (!box) return null;
    return {
        left:   box.x * scale,
        top:    canvasHeight - (box.y + box.h) * scale,
        width:  box.w * scale,
        height: box.h * scale,
    };
}
