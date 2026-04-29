/*
 * Annotation type badge canvas helpers (Phase 7ab).
 *
 * `traceRoundedRectPath` is a pure 2D-context outline tracer.
 * `drawAnnotationTypeBadge` paints the small "TEXT"/"IMAGE"/"SHAPE"
 * label affordance over a selected annotation's bounding rect; it
 * places the badge above the rect when there is room, otherwise tucked
 * into the top of the rect itself.
 */

import { canvasLogicalWidth } from '../util/dom-metrics.js';

export function traceRoundedRectPath(ctx, x, y, width, height, radius) {
    const safeRadius = Math.max(0, Math.min(Number(radius) || 0, width / 2, height / 2));
    ctx.beginPath();
    ctx.moveTo(x + safeRadius, y);
    ctx.lineTo(x + width - safeRadius, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + safeRadius);
    ctx.lineTo(x + width, y + height - safeRadius);
    ctx.quadraticCurveTo(x + width, y + height, x + width - safeRadius, y + height);
    ctx.lineTo(x + safeRadius, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - safeRadius);
    ctx.lineTo(x, y + safeRadius);
    ctx.quadraticCurveTo(x, y, x + safeRadius, y);
    ctx.closePath();
}

export function drawAnnotationTypeBadge(ctx, rect, label) {
    const text = String(label || '').trim();
    if (!text || !rect) return;
    ctx.save();
    ctx.font = '700 11px system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    const paddingX = 8;
    const badgeHeight = 22;
    const badgeWidth = Math.ceil(ctx.measureText(text).width) + (paddingX * 2);
    const badgeX = Math.max(4, Math.min(rect.left + 4, canvasLogicalWidth(ctx.canvas) - badgeWidth - 4));
    const canPlaceAbove = rect.top >= (badgeHeight + 10);
    const badgeY = canPlaceAbove ? (rect.top - badgeHeight - 4) : Math.max(4, rect.top + 4);
    traceRoundedRectPath(ctx, badgeX, badgeY, badgeWidth, badgeHeight, 11);
    ctx.fillStyle = 'rgba(15, 23, 42, 0.92)';
    ctx.fill();
    ctx.strokeStyle = 'rgba(96, 165, 250, 0.5)';
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.fillStyle = '#f8fafc';
    ctx.fillText(text, badgeX + paddingX, badgeY + (badgeHeight / 2));
    ctx.restore();
}
