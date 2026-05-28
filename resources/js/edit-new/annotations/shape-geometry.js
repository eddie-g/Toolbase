// Pure geometry helpers for shape annotations: shape-type normalization,
// unit-square vertex layouts, polygon point clamping, and basic 2D math
// (point-in-polygon, distance-to-segment). No DOM, no editor state.

import { clamp01 } from '../util/math.js';

export function normalizeShapeType(shapeType) {
    const raw = String(shapeType || '').toLowerCase();
    if (raw === 'rect') return 'square';
    return raw || 'circle';
}

export function defaultPolygonUnitPoints() {
    return [
        { x: 0.50, y: 0.05 },
        { x: 0.90, y: 0.27 },
        { x: 0.90, y: 0.73 },
        { x: 0.50, y: 0.95 },
        { x: 0.10, y: 0.73 },
        { x: 0.10, y: 0.27 },
    ];
}

export function normalizePolygonPointList(points, fallback = null) {
    if (!Array.isArray(points)) return fallback;
    const normalized = points
        .map((point) => ({
            x: clamp01(point?.x, NaN),
            y: clamp01(point?.y, NaN),
        }))
        .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y));
    return normalized.length >= 3 ? normalized : fallback;
}

export function getShapeUnitVertices(ann) {
    const type = normalizeShapeType(ann?.shapeType);
    if (type === 'circle') {
        // High step count so derived polygons (e.g. after a cut) stay smooth.
        const steps = 192;
        const points = [];
        for (let index = 0; index < steps; index += 1) {
            const angle = (Math.PI * 2 * index) / steps;
            points.push({
                x: 0.5 + (Math.cos(angle) * 0.5),
                y: 0.5 + (Math.sin(angle) * 0.5),
            });
        }
        return points;
    }
    if (type === 'triangle') {
        return [
            { x: 0.5, y: 0.0 },
            { x: 1.0, y: 1.0 },
            { x: 0.0, y: 1.0 },
        ];
    }
    if (type === 'star') {
        const outerRadius = 0.5;
        const innerRadius = outerRadius * 0.45;
        const points = [];
        for (let index = 0; index < 10; index += 1) {
            const radius = index % 2 === 0 ? outerRadius : innerRadius;
            const angle = (-Math.PI / 2) + (index * Math.PI / 5);
            points.push({
                x: 0.5 + (Math.cos(angle) * radius),
                y: 0.5 + (Math.sin(angle) * radius),
            });
        }
        return points;
    }
    if (type === 'heart') {
        return [
            { x: 0.50, y: 0.90 },
            { x: 0.18, y: 0.66 },
            { x: 0.06, y: 0.42 },
            { x: 0.10, y: 0.20 },
            { x: 0.31, y: 0.06 },
            { x: 0.50, y: 0.18 },
            { x: 0.69, y: 0.06 },
            { x: 0.90, y: 0.20 },
            { x: 0.94, y: 0.42 },
            { x: 0.82, y: 0.66 },
        ];
    }
    if (type === 'polygon') {
        return normalizePolygonPointList(ann?.polygonPoints, defaultPolygonUnitPoints());
    }
    if (type === 'line') return [];
    return [
        { x: 0.0, y: 0.0 },
        { x: 1.0, y: 0.0 },
        { x: 1.0, y: 1.0 },
        { x: 0.0, y: 1.0 },
    ];
}

export function pointInPolygon(point, polygon) {
    if (!point || !Array.isArray(polygon) || polygon.length < 3) return false;
    let inside = false;
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i, i += 1) {
        const xi = Number(polygon[i]?.x) || 0;
        const yi = Number(polygon[i]?.y) || 0;
        const xj = Number(polygon[j]?.x) || 0;
        const yj = Number(polygon[j]?.y) || 0;
        const intersects = ((yi > point.y) !== (yj > point.y))
            && (point.x < (((xj - xi) * (point.y - yi)) / Math.max(1e-9, (yj - yi))) + xi);
        if (intersects) inside = !inside;
    }
    return inside;
}

export function distanceToSegment(point, start, end) {
    const dx = (Number(end?.x) || 0) - (Number(start?.x) || 0);
    const dy = (Number(end?.y) || 0) - (Number(start?.y) || 0);
    if (Math.abs(dx) < 1e-6 && Math.abs(dy) < 1e-6) {
        return Math.hypot((Number(point?.x) || 0) - (Number(start?.x) || 0), (Number(point?.y) || 0) - (Number(start?.y) || 0));
    }
    const t = Math.max(0, Math.min(1, (
        (((Number(point?.x) || 0) - (Number(start?.x) || 0)) * dx)
        + (((Number(point?.y) || 0) - (Number(start?.y) || 0)) * dy)
    ) / ((dx * dx) + (dy * dy))));
    const projX = (Number(start?.x) || 0) + (t * dx);
    const projY = (Number(start?.y) || 0) + (t * dy);
    return Math.hypot((Number(point?.x) || 0) - projX, (Number(point?.y) || 0) - projY);
}
