// Pure helpers that compute the absolute polygon vertices for a shape
// annotation in PDF (y-up) and canvas (y-down) coordinate spaces.
// Combines the unit-square vertex layout from ./shape-geometry.js with
// the annotation's resolved bounding box and user rotation.

import { isShapeAnnotation, isLineShape } from './types.js';
import { resolveAnnBox } from './box.js';
import { getShapeUnitVertices } from './shape-geometry.js';

export function getShapePolygonPointsPdf(ann) {
    if (!isShapeAnnotation(ann) || isLineShape(ann)) return [];
    const box = resolveAnnBox(ann);
    if (!box) return [];
    const vertices = getShapeUnitVertices(ann);
    if (!vertices.length) return [];
    const width = Math.max(1, Number(box.w) || 1);
    const height = Math.max(1, Number(box.h) || 1);
    const cx = box.x + (width / 2);
    const cy = box.y + (height / 2);
    const rotation = (Number(ann.rotation) || 0) * (Math.PI / 180);
    const cosRotation = Math.cos(rotation);
    const sinRotation = Math.sin(rotation);
    return vertices.map((point) => {
        const dx = (point.x - 0.5) * width;
        const dyDown = (point.y - 0.5) * height;
        const rotatedX = (dx * cosRotation) - (dyDown * sinRotation);
        const rotatedYDown = (dx * sinRotation) + (dyDown * cosRotation);
        return {
            x: cx + rotatedX,
            y: cy - rotatedYDown,
        };
    });
}

export function getShapePolygonPointsCanvas(ann, scale, canvasHeight) {
    return getShapePolygonPointsPdf(ann).map((point) => ({
        x: point.x * scale,
        y: canvasHeight - (point.y * scale),
    }));
}
