/*
 * 2D polygon math helpers (Phase 7ac).
 *
 * Pure geometry utilities used by the shape-cut feature:
 *   - crossProduct2d: signed z-component of the 2D cross product.
 *   - dedupePolygonVertices: drop sequential near-duplicates (and
 *     close the loop if the last point matches the first).
 *   - computePolygonArea: signed shoelace area.
 *   - clipPolygonAgainstInfiniteLine: Sutherland-Hodgman half-plane
 *     clip using a directed infinite line; keeps the side selected by
 *     `keepPositive`.
 *
 * All functions take/return plain {x, y} point objects and have no
 * editor state dependencies.
 */

export function crossProduct2d(ax, ay, bx, by) {
    return (ax * by) - (ay * bx);
}

export function dedupePolygonVertices(points, epsilon = 0.01) {
    if (!Array.isArray(points)) return [];
    const deduped = [];
    points.forEach((point) => {
        const x = Number(point?.x);
        const y = Number(point?.y);
        if (!Number.isFinite(x) || !Number.isFinite(y)) return;
        const previous = deduped[deduped.length - 1];
        if (previous && Math.hypot(previous.x - x, previous.y - y) <= epsilon) return;
        deduped.push({ x, y });
    });
    if (deduped.length > 1) {
        const first = deduped[0];
        const last = deduped[deduped.length - 1];
        if (Math.hypot(first.x - last.x, first.y - last.y) <= epsilon) {
            deduped.pop();
        }
    }
    return deduped;
}

export function computePolygonArea(points) {
    if (!Array.isArray(points) || points.length < 3) return 0;
    let area = 0;
    for (let index = 0; index < points.length; index += 1) {
        const current = points[index];
        const next = points[(index + 1) % points.length];
        area += ((Number(current?.x) || 0) * (Number(next?.y) || 0)) - ((Number(next?.x) || 0) * (Number(current?.y) || 0));
    }
    return area / 2;
}

export function clipPolygonAgainstInfiniteLine(points, lineStart, lineEnd, keepPositive) {
    const cleaned = dedupePolygonVertices(points);
    if (cleaned.length < 3) return [];
    const dirX = (Number(lineEnd?.x) || 0) - (Number(lineStart?.x) || 0);
    const dirY = (Number(lineEnd?.y) || 0) - (Number(lineStart?.y) || 0);
    const epsilon = 1e-6;
    const isInside = (point) => {
        const side = crossProduct2d(dirX, dirY, (Number(point?.x) || 0) - (Number(lineStart?.x) || 0), (Number(point?.y) || 0) - (Number(lineStart?.y) || 0));
        return keepPositive ? side >= -epsilon : side <= epsilon;
    };
    const intersectionPoint = (from, to) => {
        const edgeX = (Number(to?.x) || 0) - (Number(from?.x) || 0);
        const edgeY = (Number(to?.y) || 0) - (Number(from?.y) || 0);
        const denom = crossProduct2d(dirX, dirY, edgeX, edgeY);
        if (Math.abs(denom) < epsilon) return null;
        const startOffsetX = (Number(lineStart?.x) || 0) - (Number(from?.x) || 0);
        const startOffsetY = (Number(lineStart?.y) || 0) - (Number(from?.y) || 0);
        const t = crossProduct2d(dirX, dirY, startOffsetX, startOffsetY) / denom;
        return {
            x: (Number(from?.x) || 0) + (edgeX * t),
            y: (Number(from?.y) || 0) + (edgeY * t),
        };
    };

    const output = [];
    let previous = cleaned[cleaned.length - 1];
    let previousInside = isInside(previous);
    cleaned.forEach((current) => {
        const currentInside = isInside(current);
        if (currentInside !== previousInside) {
            const intersection = intersectionPoint(previous, current);
            if (intersection) output.push(intersection);
        }
        if (currentInside) output.push({ x: Number(current.x), y: Number(current.y) });
        previous = current;
        previousInside = currentInside;
    });
    return dedupePolygonVertices(output);
}
