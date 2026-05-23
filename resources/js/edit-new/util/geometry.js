// Pure geometry helpers used by the editor: line endpoint constraints,
// line-bounding-box computation, and rotation degree normalization.
// No DOM, no editor state.

import { clamp01 } from './math.js';

/**
 * Compute the bounding box and normalized endpoint coordinates for a line
 * defined by two points in PDF-y-up coordinates. Storage convention is
 * image-y-down (0 = visual top, 1 = visual bottom) to match /edit and the
 * Python PDF exporter, so Y is flipped here.
 */
export function computeLineBoxGeometry(startX, startY, endX, endY) {
    const sx = Number(startX);
    const sy = Number(startY);
    const ex = Number(endX);
    const ey = Number(endY);
    const safeStartX = Number.isFinite(sx) ? sx : 0;
    const safeStartY = Number.isFinite(sy) ? sy : 0;
    const safeEndX = Number.isFinite(ex) ? ex : 0;
    const safeEndY = Number.isFinite(ey) ? ey : 0;
    const left = Math.min(safeStartX, safeEndX);
    const bottom = Math.min(safeStartY, safeEndY);
    const top = Math.max(safeStartY, safeEndY);
    const width = Math.max(1, Math.abs(safeEndX - safeStartX));
    const height = Math.max(1, Math.abs(safeEndY - safeStartY));
    return {
        left,
        bottom,
        width,
        height,
        lineStartX: clamp01((safeStartX - left) / width, 0),
        lineStartY: clamp01((top - safeStartY) / height, 0),
        lineEndX: clamp01((safeEndX - left) / width, 1),
        lineEndY: clamp01((top - safeEndY) / height, 1),
    };
}

export function constrainLineEndpointTo45(startX, startY, endX, endY) {
    const sx = Number(startX) || 0;
    const sy = Number(startY) || 0;
    const ex = Number(endX) || 0;
    const ey = Number(endY) || 0;
    const dx = ex - sx;
    const dy = ey - sy;
    const distance = Math.hypot(dx, dy);
    if (distance < 1) return { x: ex, y: ey };
    const snapStep = Math.PI / 4;
    const angle = Math.round(Math.atan2(dy, dx) / snapStep) * snapStep;
    return {
        x: sx + Math.cos(angle) * distance,
        y: sy + Math.sin(angle) * distance,
    };
}

/**
 * Legacy gentle snap helper: when the drag angle is within ~8° of any
 * 45° step (0°/45°/90°/...), snap the end point onto that ray.
 */
export function snapLineEndpoint(startX, startY, endX, endY) {
    const sx = Number(startX) || 0;
    const sy = Number(startY) || 0;
    const ex = Number(endX) || 0;
    const ey = Number(endY) || 0;
    const dx = ex - sx;
    const dy = ey - sy;
    const distance = Math.hypot(dx, dy);
    if (distance < 1) return { x: ex, y: ey };

    const angle = Math.atan2(dy, dx);
    const snapStep = Math.PI / 4;
    const snapThreshold = (8 * Math.PI) / 180;
    const snappedAngle = Math.round(angle / snapStep) * snapStep;
    let angleDelta = angle - snappedAngle;
    while (angleDelta > Math.PI) angleDelta -= Math.PI * 2;
    while (angleDelta < -Math.PI) angleDelta += Math.PI * 2;
    if (Math.abs(angleDelta) > snapThreshold) return { x: ex, y: ey };

    return {
        x: sx + Math.cos(snappedAngle) * distance,
        y: sy + Math.sin(snappedAngle) * distance,
    };
}

/** Normalize an angle in degrees to the [0, 360) range. */
export function normalizeRotationDegrees(angle) {
    const a = Number(angle);
    if (!Number.isFinite(a)) return 0;
    let n = a % 360;
    if (n < 0) n += 360;
    return n;
}
