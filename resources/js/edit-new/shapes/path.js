/*
 * drawShapePath — trace a shape annotation's outline into the active
 * Canvas2D path (Phase 7s). Pure: depends only on the shape-geometry
 * helpers and the supplied 2D context.
 */

import {
    normalizeShapeType,
    normalizePolygonPointList,
    defaultPolygonUnitPoints,
} from '../annotations/shape-geometry.js';

export function drawShapePath(ctx, shapeType, rect, lineGeometry = null) {
    const left = Number(rect?.left) || 0;
    const top = Number(rect?.top) || 0;
    const width = Math.max(1, Number(rect?.width) || 1);
    const height = Math.max(1, Number(rect?.height) || 1);
    const right = left + width;
    const bottom = top + height;
    const centerX = left + (width / 2);
    const centerY = top + (height / 2);
    const type = normalizeShapeType(shapeType);

    ctx.beginPath();

    if (type === 'circle') {
        ctx.ellipse(centerX, centerY, width / 2, height / 2, 0, 0, Math.PI * 2);
        return;
    }

    if (type === 'triangle') {
        ctx.moveTo(centerX, top);
        ctx.lineTo(right, bottom);
        ctx.lineTo(left, bottom);
        ctx.closePath();
        return;
    }

    if (type === 'star') {
        const outerRadius = Math.min(width, height) / 2;
        const innerRadius = outerRadius * 0.45;
        for (let i = 0; i < 10; i += 1) {
            const radius = i % 2 === 0 ? outerRadius : innerRadius;
            const angle = (-Math.PI / 2) + (i * Math.PI / 5);
            const x = centerX + (Math.cos(angle) * radius);
            const y = centerY + (Math.sin(angle) * radius);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.closePath();
        return;
    }

    if (type === 'polygon') {
        const points = normalizePolygonPointList(lineGeometry?.polygonPoints, defaultPolygonUnitPoints());
        points.forEach((point, index) => {
            const x = left + (point.x * width);
            const y = top + (point.y * height);
            if (index === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.closePath();
        return;
    }

    if (type === 'line') {
        const geometry = lineGeometry || {
            lineStartX: 0,
            lineStartY: 0,
            lineEndX: 1,
            lineEndY: 1,
        };
        ctx.moveTo(left + (geometry.lineStartX * width), top + (geometry.lineStartY * height));
        ctx.lineTo(left + (geometry.lineEndX * width), top + (geometry.lineEndY * height));
        return;
    }

    ctx.rect(left, top, width, height);
}
