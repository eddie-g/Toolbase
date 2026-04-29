/*
 * Renderable source-line resolver (Phase 7k).
 *
 * Re-derives the per-line text + bbox + spans groupings for a promoted
 * extraction annotation. Two modes:
 *
 *   1. If `ann.sourceLineBBoxes` is present (the normal case), each
 *      bbox is the canonical line and we attach matching spans by
 *      bbox-overlap.
 *   2. If no bboxes are present, or matching spans is incomplete, we
 *      reconstruct lines bottom-up from the spans themselves.
 *
 * The result is cached on `ann._renderableSourceLines` so callers can
 * call this on every render without re-paying the grouping cost.
 *
 * No external deps from main.js — these three functions form a closed
 * pure cluster.
 */

export function spanMatchesLineBBox(span, lineBBox) {
    const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
    if (!bbox || bbox.length < 4 || !Array.isArray(lineBBox) || lineBBox.length < 4) return false;
    const spanLeft = Number(bbox[0]);
    const spanTop = Number(bbox[1]);
    const spanRight = Number(bbox[2]);
    const spanBottom = Number(bbox[3]);
    const lineLeft = Number(lineBBox[0]);
    const lineTop = Number(lineBBox[1]);
    const lineRight = Number(lineBBox[2]);
    const lineBottom = Number(lineBBox[3]);
    if (![spanLeft, spanTop, spanRight, spanBottom, lineLeft, lineTop, lineRight, lineBottom].every(Number.isFinite)) {
        return false;
    }

    const xi = Math.max(spanLeft, lineLeft - 0.5);
    const xa = Math.min(spanRight, lineRight + 0.5);
    if ((xa - xi) <= 0) return false;

    const spanCenterY = (spanTop + spanBottom) / 2;
    if (spanCenterY >= (lineTop - 0.5) && spanCenterY <= (lineBottom + 0.5)) {
        return true;
    }

    const yi = Math.max(spanTop, lineTop - 0.5);
    const ya = Math.min(spanBottom, lineBottom + 0.5);
    const overlapY = ya - yi;
    if (overlapY <= 0) return false;

    const spanHeight = Math.max(0.001, spanBottom - spanTop);
    const lineHeight = Math.max(0.001, lineBottom - lineTop);
    const overlapRatio = overlapY / Math.min(spanHeight, lineHeight);
    return overlapRatio >= 0.5;
}

export function buildRenderableLinesFromSpans(spans) {
    const normalizedSpans = Array.isArray(spans)
        ? spans.map((span, index) => {
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            const left = bbox ? Number(bbox[0]) : Number(origin?.[0]);
            const top = bbox ? Number(bbox[1]) : Number(origin?.[1]);
            const right = bbox ? Number(bbox[2]) : left;
            const bottom = bbox ? Number(bbox[3]) : top;
            const height = Number.isFinite(bottom) && Number.isFinite(top)
                ? Math.max(0.001, Math.abs(bottom - top))
                : 0.001;
            const centerY = Number.isFinite(top) && Number.isFinite(bottom)
                ? ((top + bottom) / 2)
                : Number(origin?.[1]);
            const startX = Number.isFinite(left)
                ? left
                : Number(origin?.[0]);
            return {
                span,
                index,
                bbox,
                origin,
                left,
                top,
                right,
                bottom,
                height,
                centerY,
                startX,
            };
        }).filter((entry) => Number.isFinite(entry.centerY) && Number.isFinite(entry.startX))
        : [];

    if (!normalizedSpans.length) return [];

    normalizedSpans.sort((a, b) => {
        if (Math.abs(a.centerY - b.centerY) > 0.25) return a.centerY - b.centerY;
        if (Math.abs(a.startX - b.startX) > 0.25) return a.startX - b.startX;
        return a.index - b.index;
    });

    const groups = [];
    normalizedSpans.forEach((entry) => {
        const lastGroup = groups[groups.length - 1] || null;
        const tolerance = Math.max(
            1.5,
            Math.min(
                (lastGroup?.avgHeight || entry.height),
                entry.height
            ) * 0.65
        );

        if (!lastGroup || Math.abs(entry.centerY - lastGroup.centerY) > tolerance) {
            groups.push({
                centerY: entry.centerY,
                avgHeight: entry.height,
                entries: [entry],
            });
            return;
        }

        lastGroup.entries.push(entry);
        lastGroup.centerY = (
            (lastGroup.centerY * (lastGroup.entries.length - 1))
            + entry.centerY
        ) / lastGroup.entries.length;
        lastGroup.avgHeight = (
            (lastGroup.avgHeight * (lastGroup.entries.length - 1))
            + entry.height
        ) / lastGroup.entries.length;
    });

    return groups.map((group, rawIndex) => {
        const entries = group.entries.slice().sort((a, b) => {
            if (Math.abs(a.startX - b.startX) > 0.25) return a.startX - b.startX;
            return a.index - b.index;
        });
        const lefts = entries.map((entry) => entry.left).filter(Number.isFinite);
        const tops = entries.map((entry) => entry.top).filter(Number.isFinite);
        const rights = entries.map((entry) => entry.right).filter(Number.isFinite);
        const bottoms = entries.map((entry) => entry.bottom).filter(Number.isFinite);
        const bbox = (lefts.length && tops.length && rights.length && bottoms.length)
            ? [Math.min(...lefts), Math.min(...tops), Math.max(...rights), Math.max(...bottoms)]
            : null;
        return {
            rawIndex,
            bbox,
            spans: entries.map((entry) => entry.span),
            width: bbox ? Math.max(0, Number(bbox[2]) - Number(bbox[0])) : 0,
            height: bbox ? Math.max(0, Number(bbox[3]) - Number(bbox[1])) : 0,
            text: entries.map((entry) => String(entry.span?.render_text ?? entry.span?.text ?? '')).join(''),
        };
    }).filter((line) => Array.isArray(line.bbox) && line.width > 0 && line.height > 0);
}

export function renderableSourceLines(ann) {
    if (Array.isArray(ann?._renderableSourceLines)) return ann._renderableSourceLines;

    const rawBBoxes = Array.isArray(ann?.sourceLineBBoxes) ? ann.sourceLineBBoxes : [];
    const rawTexts = Array.isArray(ann?.sourceTextLines) ? ann.sourceTextLines : [];
    const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];

    let lines = rawBBoxes.map((bbox, rawIndex) => {
        const matchedSpans = spans.filter((span) => spanMatchesLineBBox(span, bbox));
        const width = Math.max(0, Number(bbox?.[2]) - Number(bbox?.[0]));
        const height = Math.max(0, Number(bbox?.[3]) - Number(bbox?.[1]));
        return {
            rawIndex,
            bbox,
            spans: matchedSpans,
            width,
            height,
            text: String(rawTexts[rawIndex] ?? ''),
        };
    });

    const matchedSpanCount = lines.reduce((count, line) => count + (Array.isArray(line.spans) ? line.spans.length : 0), 0);

    if (spans.length && (!lines.length || matchedSpanCount < spans.length)) {
        const spanDerivedLines = buildRenderableLinesFromSpans(spans);
        if (spanDerivedLines.length) {
            lines = spanDerivedLines;
        }
    }

    lines = lines.filter((line) => {
        const meaningfulText = String(line.text || '').trim().length > 0;
        const hasSpans = Array.isArray(line.spans) && line.spans.length > 0;
        const hasRealBox = line.width > 1 && line.height > 1;
        return hasSpans || (hasRealBox && meaningfulText);
    });

    if (rawTexts.length && lines.length === rawTexts.length) {
        lines.forEach((line, index) => {
            line.text = String(rawTexts[index] ?? '');
        });
    } else {
        lines.forEach((line) => {
            if (String(line.text || '').trim()) return;
            if (Array.isArray(line.spans) && line.spans.length) {
                line.text = line.spans.map((span) => String(span?.render_text ?? span?.text ?? '')).join('');
            }
        });
    }

    ann._renderableSourceLines = lines;
    return lines;
}
