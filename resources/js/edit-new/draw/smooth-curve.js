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

export function smoothDrawCurveSegments(points) {
    const clean = normalizeSmoothCurvePoints(points);
    const segments = [];
    for (let index = 0; index < clean.length - 1; index += 1) {
        const p0 = clean[Math.max(0, index - 1)];
        const p1 = clean[index];
        const p2 = clean[index + 1];
        const p3 = clean[Math.min(clean.length - 1, index + 2)];
        segments.push({
            start: p1,
            control1: {
                x: p1.x + ((p2.x - p0.x) / 6),
                y: p1.y + ((p2.y - p0.y) / 6),
            },
            control2: {
                x: p2.x - ((p3.x - p1.x) / 6),
                y: p2.y - ((p3.y - p1.y) / 6),
            },
            end: p2,
        });
    }
    return segments;
}

function svgNumber(value) {
    return Number(value).toFixed(3);
}

export function smoothDrawCurveSvgPathD(points) {
    const clean = normalizeSmoothCurvePoints(points);
    if (!clean.length) return '';
    const first = clean[0];
    if (clean.length === 1) {
        return `M ${svgNumber(first.x)} ${svgNumber(first.y)} L ${svgNumber(first.x + 0.01)} ${svgNumber(first.y)}`;
    }
    const curves = smoothDrawCurveSegments(clean)
        .map((segment) => (
            `C ${svgNumber(segment.control1.x)} ${svgNumber(segment.control1.y)} `
            + `${svgNumber(segment.control2.x)} ${svgNumber(segment.control2.y)} `
            + `${svgNumber(segment.end.x)} ${svgNumber(segment.end.y)}`
        ));
    return `M ${svgNumber(first.x)} ${svgNumber(first.y)} ${curves.join(' ')}`;
}

export function paintSmoothDrawCurve(ctx, stroke, defaultColor = '#111827', defaultWidth = 3) {
    if (!ctx) return;
    const points = smoothDrawCurvePoints(stroke?.points);
    if (!points.length) return;
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
    smoothDrawCurveSegments(points).forEach((segment) => {
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
