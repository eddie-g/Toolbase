/*
 * Pure axis helpers for dragging geometry whose painted bounds may differ
 * from its layout box (most notably rotated shapes).
 */

export function dragAxisOffsetBounds(startOffset, paintedStart, paintedEnd, containerSize) {
    const offset = Number(startOffset);
    const start = Number(paintedStart);
    const end = Number(paintedEnd);
    const size = Number(containerSize);
    if (![offset, start, end, size].every(Number.isFinite) || end <= start || size <= 0) {
        return { min: Number.NEGATIVE_INFINITY, max: Number.POSITIVE_INFINITY };
    }

    const startAligned = offset - start;
    const endAligned = offset + size - end;
    return {
        // When the painted object is larger than the page, keep it pannable
        // between its two edge-aligned positions instead of freezing it.
        min: Math.min(startAligned, endAligned),
        max: Math.max(startAligned, endAligned),
    };
}

export function correctionToKeepPaintedAxisInside(paintedStart, paintedEnd, containerSize) {
    const start = Number(paintedStart);
    const end = Number(paintedEnd);
    const size = Number(containerSize);
    if (![start, end, size].every(Number.isFinite) || end <= start || size <= 0) return 0;

    const paintedSize = end - start;
    if (paintedSize <= size) {
        if (start < 0) return -start;
        if (end > size) return size - end;
        return 0;
    }

    // An oversized painted object cannot fit on both sides. Correct it only
    // when the page is exposed beyond one of its edges; otherwise preserve
    // the user's current pan position.
    if (start > 0) return -start;
    if (end < size) return size - end;
    return 0;
}
