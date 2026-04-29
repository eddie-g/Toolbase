/*
 * Annotation hit-testing (Phase 7am).
 *
 *   - shapeContainsCanvasPoint: true when (x, y) in canvas-px coords
 *     hits the given shape annotation. For line shapes this is a
 *     stroke-distance test; for filled shapes it tests against the
 *     transformed bounding box first, then falls back to an offscreen
 *     2D-context Path2D test for the actual stroke / fill outline,
 *     accounting for user rotation.
 *
 *   - findAnnotationAt: scan an annotation array for the smallest
 *     hit; returns the topmost annotation under the point or null.
 *     Shape annotations defer to shapeContainsCanvasPoint; everything
 *     else uses the bounding-box rect from annRectPx.
 *
 * The offscreen hit-test canvas + 2D context are created once at
 * module load and reused, mirroring the original main.js singletons.
 */

import { resolveAnnBox } from './box.js';
import { isShapeAnnotation, isLineShape } from './types.js';
import { distanceToSegment, normalizeShapeType, normalizePolygonPointList, defaultPolygonUnitPoints } from './shape-geometry.js';
import { drawShapePath } from '../shapes/path.js';
import { clamp01 } from '../util/math.js';
import { annRectPx } from './canvas-rect.js';

const shapeHitTestCanvas = document.createElement('canvas');
const shapeHitTestCtx = shapeHitTestCanvas.getContext('2d');

export function shapeContainsCanvasPoint(ann, x, y, scale, canvasHeight) {
    if (!isShapeAnnotation(ann)) return false;
    if (isLineShape(ann)) {
        const box = resolveAnnBox(ann);
        if (!box) return false;
        const width = Math.max(1, box.w * scale);
        const height = Math.max(1, box.h * scale);
        const left = box.x * scale;
        const top = canvasHeight - (box.y + box.h) * scale;
        const start = {
            x: left + (width * clamp01(ann.lineStartX, 0)),
            y: top + (height * clamp01(ann.lineStartY, 0)),
        };
        const end = {
            x: left + (width * clamp01(ann.lineEndX, 1)),
            y: top + (height * clamp01(ann.lineEndY, 1)),
        };
        const strokeAllowance = Math.max(6, ((Number(ann.strokeWidth) || 3) * scale / 2) + 4);
        return distanceToSegment({ x, y }, start, end) <= strokeAllowance;
    }
    const box = resolveAnnBox(ann);
    if (!box) return false;
    const rect = {
        left: box.x * scale,
        top: canvasHeight - (box.y + box.h) * scale,
        width: Math.max(1, box.w * scale),
        height: Math.max(1, box.h * scale),
    };
    // Allow selecting the shape by clicking anywhere inside its bounding box,
    // not just on the stroke outline. Account for user rotation by transforming
    // the click point into the shape's local (unrotated) space.
    const userRot = Number(ann.rotation) || 0;
    const rcx = rect.left + rect.width / 2;
    const rcy = rect.top + rect.height / 2;
    let lx = x;
    let ly = y;
    if (userRot) {
        const a = -userRot * Math.PI / 180;
        const cos = Math.cos(a);
        const sin = Math.sin(a);
        const dx = x - rcx;
        const dy = y - rcy;
        lx = rcx + dx * cos - dy * sin;
        ly = rcy + dx * sin + dy * cos;
    }
    const bboxSlop = 2;
    if (
        lx >= rect.left - bboxSlop &&
        lx <= rect.left + rect.width + bboxSlop &&
        ly >= rect.top - bboxSlop &&
        ly <= rect.top + rect.height + bboxSlop
    ) {
        return true;
    }
    const hitCtx = shapeHitTestCtx;
    if (!hitCtx) return false;
    const hitPadding = Math.max(rect.width, rect.height) + 8;
    shapeHitTestCanvas.width = Math.max(1, Math.ceil(rect.left + rect.width + hitPadding));
    shapeHitTestCanvas.height = Math.max(1, Math.ceil(rect.top + rect.height + hitPadding));
    hitCtx.lineWidth = Math.max(1, (Number(ann.strokeWidth) || 3) * scale);
    hitCtx.lineJoin = 'round';
    hitCtx.lineCap = (ann.lineCap === 'butt' || ann.lineCap === 'square') ? ann.lineCap : 'round';
    if (userRot) {
        hitCtx.translate(rcx, rcy);
        hitCtx.rotate(userRot * Math.PI / 180);
        hitCtx.translate(-rcx, -rcy);
    }
    drawShapePath(hitCtx, ann.shapeType, rect, normalizeShapeType(ann.shapeType) === 'polygon'
        ? { polygonPoints: normalizePolygonPointList(ann.polygonPoints, defaultPolygonUnitPoints()) }
        : null);
    const fillHit = !ann.fillTransparent && clamp01(ann.fillOpacity ?? 0.22, 0.22) > 0 && hitCtx.isPointInPath(x, y);
    const strokeHit = !ann.strokeTransparent && clamp01(ann.strokeOpacity ?? 1, 1) > 0 && hitCtx.isPointInStroke(x, y);
    return fillHit || strokeHit;
}

export function findAnnotationAt(x, y, annotations, scale, canvasHeight) {
    const hits = annotations
        .map(ann => ({ ann, rect: annRectPx(ann, scale, canvasHeight) }))
        .filter(({ rect }) => rect && rect.width > 0 && rect.height > 0)
        .filter(({ ann, rect }) => {
            const withinBounds = x >= rect.left && x <= rect.left + rect.width && y >= rect.top && y <= rect.top + rect.height;
            if (!withinBounds) return false;
            return isShapeAnnotation(ann)
                ? shapeContainsCanvasPoint(ann, x, y, scale, canvasHeight)
                : true;
        })
        .sort((a, b) => (a.rect.width * a.rect.height) - (b.rect.width * b.rect.height));
    return hits.length ? hits[0].ann : null;
}
