/*
 * Direct-draw canvas primitives (Phase 7ao).
 *
 * Pure helpers used by the direct-pen / eraser code paths to:
 *   - createDrawLayer: append a <canvas> overlay sibling inside the
 *     given page-content container (`#pc-N`). Width/height are the
 *     bitmap backing-store size; cssWidth/cssHeight set the layout
 *     box. Returns the new <canvas> or null if the container is
 *     missing.
 *   - paintDrawDot: stamp a filled circle (used for tap / single-
 *     point strokes).
 *   - paintDrawSegment: stroke a quadratic-bezier segment between
 *     two midpoints (used during pen-down move events for smoothing).
 *   - localPointFromDrawSession: convert a canvas-CSS-pixel point
 *     into the draw layer's bitmap coordinate space, clamping to the
 *     layer bounds.
 */

export function createDrawLayer(pi, className, width, height, left = 0, top = 0, cssWidth = width, cssHeight = height) {
    const pageContent = document.getElementById('pc-' + (pi + 1));
    if (!pageContent) return null;
    const layer = document.createElement('canvas');
    layer.className = className;
    layer.width = Math.max(1, Math.round(width));
    layer.height = Math.max(1, Math.round(height));
    layer.style.left = `${left}px`;
    layer.style.top = `${top}px`;
    layer.style.width = `${Math.max(1, cssWidth)}px`;
    layer.style.height = `${Math.max(1, cssHeight)}px`;
    pageContent.appendChild(layer);
    return layer;
}

export function paintDrawDot(ctx, x, y, width, color, composite = 'source-over') {
    ctx.save();
    ctx.globalCompositeOperation = composite;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(x, y, Math.max(1, width / 2), 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
}

export function paintDrawSegment(ctx, fromMid, controlPoint, toPoint, width, color, composite = 'source-over') {
    ctx.save();
    ctx.globalCompositeOperation = composite;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(fromMid.x, fromMid.y);
    ctx.quadraticCurveTo(controlPoint.x, controlPoint.y, toPoint.x, toPoint.y);
    ctx.stroke();
    ctx.restore();
}

export function localPointFromDrawSession(session, canvasPoint) {
    const relX = (canvasPoint.x - session.left) / Math.max(1, session.displayWidth);
    const relY = (canvasPoint.y - session.top) / Math.max(1, session.displayHeight);
    return {
        x: Math.max(0, Math.min(session.layer.width, relX * session.layer.width)),
        y: Math.max(0, Math.min(session.layer.height, relY * session.layer.height)),
    };
}
