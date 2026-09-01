/*
 * draw/smooth-curve.
 *
 * Fits a freehand point list to a curve, with an adjustable
 * smoothing amount in [0, 1] (NK_16). The amount drives two things
 * at once:
 *
 *   - passes:  how many neighbour-averaging passes run over the raw
 *              points first, which is what removes hand jitter
 *   - tension: how far the bezier control points reach out from each
 *              segment, which is what rounds the corners
 *
 * At 0 both collapse and the result is a polyline through the exact
 * points the pointer visited. Callers that pass no amount at all keep
 * the pre-NK_16 fixed behaviour (2 passes, full tension) so the
 * pdf.js editor, which shares this module, is unaffected.
 */

export const DEFAULT_DRAW_SMOOTHING = 0.58;

const MAX_SMOOTHING_PASSES = 4;

/** Clamp a smoothing amount into [0, 1], falling back when unusable. */
export function normalizeDrawSmoothing(value, fallback = DEFAULT_DRAW_SMOOTHING) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return fallback;
    return Math.max(0, Math.min(1, amount));
}

/** Averaging passes for a smoothing amount: 0 -> 0, 1 -> MAX_SMOOTHING_PASSES. */
export function drawSmoothingPasses(amount) {
    return Math.round(normalizeDrawSmoothing(amount, 0) * MAX_SMOOTHING_PASSES);
}

function finitePoint(point) {
    const x = Number(point?.x);
    const y = Number(point?.y);
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
}

export function normalizeSmoothCurvePoints(points, minimumDistance = 0.25) {
    const clean = [];
    const threshold = Math.max(0, Number(minimumDistance) || 0);
    for (const candidate of Array.isArray(points) ? points : []) {
        const point = finitePoint(candidate);
        if (!point) continue;
        const previous = clean[clean.length - 1];
        if (previous && Math.hypot(point.x - previous.x, point.y - previous.y) < threshold) continue;
        clean.push(point);
    }
    return clean;
}

export function smoothDrawCurvePoints(points, passes = 2) {
    let result = normalizeSmoothCurvePoints(points);
    const passCount = Math.max(0, Math.min(4, Math.round(Number(passes) || 0)));
    for (let pass = 0; pass < passCount && result.length > 2; pass += 1) {
        const next = [result[0]];
        for (let index = 1; index < result.length - 1; index += 1) {
            const previous = result[index - 1];
            const current = result[index];
            const following = result[index + 1];
            next.push({
                x: (previous.x * 0.25) + (current.x * 0.5) + (following.x * 0.25),
                y: (previous.y * 0.25) + (current.y * 0.5) + (following.y * 0.25),
            });
        }
        next.push(result[result.length - 1]);
        result = next;
    }
    return result;
}

export function smoothDrawCurveSegments(points, tension = 1) {
    const clean = normalizeSmoothCurvePoints(points);
    const reach = normalizeDrawSmoothing(tension, 1) / 6;
    const segments = [];
    for (let index = 0; index < clean.length - 1; index += 1) {
        const p0 = clean[Math.max(0, index - 1)];
        const p1 = clean[index];
        const p2 = clean[index + 1];
        const p3 = clean[Math.min(clean.length - 1, index + 2)];
        segments.push({
            start: p1,
            control1: {
                x: p1.x + ((p2.x - p0.x) * reach),
                y: p1.y + ((p2.y - p0.y) * reach),
            },
            control2: {
                x: p2.x - ((p3.x - p1.x) * reach),
                y: p2.y - ((p3.y - p1.y) * reach),
            },
            end: p2,
        });
    }
    return segments;
}

function svgNumber(value) {
    return Number(value).toFixed(3);
}

export function smoothDrawCurveSvgPathD(points, tension = 1) {
    const clean = normalizeSmoothCurvePoints(points);
    if (!clean.length) return '';
    const first = clean[0];
    if (clean.length === 1) {
        return `M ${svgNumber(first.x)} ${svgNumber(first.y)} L ${svgNumber(first.x + 0.01)} ${svgNumber(first.y)}`;
    }
    const curves = smoothDrawCurveSegments(clean, tension)
        .map((segment) => (
            `C ${svgNumber(segment.control1.x)} ${svgNumber(segment.control1.y)} `
            + `${svgNumber(segment.control2.x)} ${svgNumber(segment.control2.y)} `
            + `${svgNumber(segment.end.x)} ${svgNumber(segment.end.y)}`
        ));
    return `M ${svgNumber(first.x)} ${svgNumber(first.y)} ${curves.join(' ')}`;
}

export function paintSmoothDrawCurve(ctx, stroke, defaultColor = '#111827', defaultWidth = 3, smoothing = null) {
    if (!ctx) return;
    // No amount from either the argument or the stroke -> pre-NK_16 behaviour.
    const requested = smoothing === null || smoothing === undefined ? stroke?.smoothing : smoothing;
    const hasAmount = Number.isFinite(Number(requested));
    const amount = hasAmount ? normalizeDrawSmoothing(requested) : null;
    const points = smoothDrawCurvePoints(stroke?.points, hasAmount ? drawSmoothingPasses(amount) : 2);
    if (!points.length) return;
    const tension = hasAmount ? amount : 1;
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
    smoothDrawCurveSegments(points, tension).forEach((segment) => {
        ctx.bezierCurveTo(
            segment.control1.x,
            segment.control1.y,
            segment.control2.x,
            segment.control2.y,
            segment.end.x,
            segment.end.y,
        );
    });
    ctx.stroke();
    ctx.restore();
}
