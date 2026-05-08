/*
 * draw/smooth-stroke (Phase 7aw).
 *
 * Paint a single freehand stroke (point list) onto a 2D canvas with
 * optional quadratic-curve smoothing. Single-point strokes draw a
 * filled dot of radius width/2 (so a tap is visible). Used by both
 * the in-modal signature canvas and the in-modal markup-tool canvas
 * to replace duplicate paintSignatureStroke / paintMarkupToolStroke
 * helpers in main.js.
 *
 * `smooth` is in [0, 1]; values <= 0.01 fall back to straight
 * line-tos. Caller is responsible for already saving / restoring
 * the canvas state if it cares; we save/restore internally.
 */

export function paintSmoothStroke(ctx, stroke, smooth, defaultColor = '#111827', defaultWidth = 3) {
    const points = Array.isArray(stroke?.points) ? stroke.points : [];
    if (!ctx || !points.length) return;
    const color = stroke?.color || defaultColor;
    const width = Math.max(1, Number(stroke?.width) || defaultWidth);

    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = width;

    if (points.length === 1) {
        ctx.beginPath();
        ctx.arc(points[0].x, points[0].y, Math.max(1, width / 2), 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
        return;
    }

    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    if (smooth <= 0.01 || points.length === 2) {
        for (let index = 1; index < points.length; index += 1) {
            ctx.lineTo(points[index].x, points[index].y);
        }
    } else {
        for (let index = 1; index < points.length - 1; index += 1) {
            const current = points[index];
            const next = points[index + 1];
            const midX = (current.x + next.x) / 2;
            const midY = (current.y + next.y) / 2;
            const endX = current.x + ((midX - current.x) * smooth);
            const endY = current.y + ((midY - current.y) * smooth);
            ctx.quadraticCurveTo(current.x, current.y, endX, endY);
        }
        const last = points[points.length - 1];
        ctx.lineTo(last.x, last.y);
    }
    ctx.stroke();
    ctx.restore();
}
