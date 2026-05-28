/*
 * drawShapeAnnotation — paint a shape annotation onto a 2D canvas
 * context (Phase 7cf). Pure helper: takes the annotation, the
 * destination context, the current display scale, and the canvas
 * height in pixels (needed to flip from PDF-space y-down to
 * canvas-space y-down). All decoration colour / opacity / rotation
 * comes from the annotation itself; nothing reaches into editor
 * state.
 */

import { resolveAnnBox } from '../annotations/box.js';
import { isLineShape } from '../annotations/types.js';
import {
    normalizeShapeType,
    normalizePolygonPointList,
    defaultPolygonUnitPoints,
} from '../annotations/shape-geometry.js';
import { clamp01 } from '../util/math.js';
import { hexToRgbaString } from '../util/color.js';
import { drawShapePath } from './path.js';

export function drawShapeAnnotation(ann, ctx, scale, canvasHeight) {
    const box = resolveAnnBox(ann);
    if (!box) return;

    const rect = {
        left: box.x * scale,
        top: canvasHeight - (box.y + box.h) * scale,
        width: Math.max(1, box.w * scale),
        height: Math.max(1, box.h * scale),
    };
    const strokeWidth = Math.max(1, Number(ann.strokeWidth) || 3);
    const hasStroke = !ann.strokeTransparent && clamp01(ann.strokeOpacity ?? 1, 1) > 0;
    const shapeType = normalizeShapeType(ann.shapeType);
    const openIconShape = shapeType === 'arrow' || shapeType === 'x' || shapeType === 'checkmark';
    const hasFill = !ann.fillTransparent && !isLineShape(ann) && !openIconShape && clamp01(ann.fillOpacity ?? 0.22, 0.22) > 0;
    const lineGeometry = isLineShape(ann)
        ? {
            lineStartX: clamp01(ann.lineStartX, 0),
            lineStartY: clamp01(ann.lineStartY, 0),
            lineEndX: clamp01(ann.lineEndX, 1),
            lineEndY: clamp01(ann.lineEndY, 1),
        }
        : (shapeType === 'polygon'
            ? { polygonPoints: normalizePolygonPointList(ann.polygonPoints, defaultPolygonUnitPoints()) }
            : null);

    ctx.save();
    // strokeWidth is stored in PDF points (the export pipeline writes it as
    // PyMuPDF `width=...`, which is in points). The canvas is sized at
    // `wPts * scale` pixels, so 1 PDF point == `scale` canvas pixels.
    // Multiply here so the editor preview matches the downloaded PDF.
    ctx.lineWidth = Math.max(1, strokeWidth * scale);
    const annLineCap = (ann.lineCap === 'butt' || ann.lineCap === 'square') ? ann.lineCap : 'round';
    ctx.lineCap = annLineCap;
    ctx.lineJoin = 'round';
    const userRot = Number(ann.rotation) || 0;
    if (userRot && !isLineShape(ann)) {
        const rcx = rect.left + rect.width / 2;
        const rcy = rect.top + rect.height / 2;
        ctx.translate(rcx, rcy);
        ctx.rotate(userRot * Math.PI / 180);
        ctx.translate(-rcx, -rcy);
    }
    if (hasFill) {
        drawShapePath(ctx, ann.shapeType, rect, lineGeometry);
        ctx.fillStyle = hexToRgbaString(ann.fillColor, ann.fillOpacity);
        ctx.fill();
    }
    if (hasStroke) {
        // Clear the fill band underneath where the stroke will be painted so the
        // stroke's inner-edge anti-aliasing doesn't blend into the partially
        // transparent fill (which produces a visible "lighter ring" between the
        // fill and the solid stroke).
        if (hasFill) {
            ctx.save();
            ctx.globalCompositeOperation = 'destination-out';
            drawShapePath(ctx, ann.shapeType, rect, lineGeometry);
            ctx.strokeStyle = '#000';
            ctx.stroke();
            ctx.restore();
        }
        drawShapePath(ctx, ann.shapeType, rect, lineGeometry);
        ctx.strokeStyle = hexToRgbaString(ann.strokeColor, ann.strokeOpacity);
        ctx.stroke();
    }
    ctx.restore();
}
