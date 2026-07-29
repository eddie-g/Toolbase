function boolish(value) {
    if (value === true || value === 1) return true;
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

function normalizeComparableText(value) {
    return String(value ?? '').replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').trim();
}

function textWithoutWhitespace(value) {
    return Array.from(String(value ?? ''))
        .filter((character) => !/\s/u.test(character))
        .join('');
}

function normalizedUnderlineRange(value) {
    if (!value) return null;
    const start = Number(value.start ?? value.x0 ?? value.left ?? value[0]);
    const end = Number(value.end ?? value.x1 ?? value.right ?? value[1]);
    if (![start, end].every(Number.isFinite)) return null;
    const left = Math.max(0, Math.min(1, Math.min(start, end)));
    const right = Math.max(0, Math.min(1, Math.max(start, end)));
    return right - left > 0.0001 ? { start: left, end: right } : null;
}

function mergeUnderlineRanges(values) {
    const sorted = Array.from(values || [])
        .map(normalizedUnderlineRange)
        .filter(Boolean)
        .sort((left, right) => left.start - right.start || left.end - right.end);
    const merged = [];
    for (const range of sorted) {
        const previous = merged[merged.length - 1];
        if (previous && range.start <= previous.end + 0.001) {
            previous.end = Math.max(previous.end, range.end);
        } else {
            merged.push({ ...range });
        }
    }
    return merged;
}

function sourceSpanUnderlineMetrics(sourceSpan) {
    const bbox = Array.isArray(sourceSpan?.bbox) ? sourceSpan.bbox : null;
    const bboxTop = Number(bbox?.[1]);
    const bboxBottom = Number(bbox?.[3]);
    const origin = sourceSpan?.origin;
    const baseline = Number(
        Array.isArray(origin)
            ? origin[1]
            : (origin?.y ?? sourceSpan?.baseline ?? sourceSpan?.baseline_y),
    );
    const fontSize = Number(
        sourceSpan?.fontSize
            ?? sourceSpan?.font_size
            ?? sourceSpan?.size,
    );
    return {
        bboxTop,
        bboxBottom,
        baseline,
        fontSize,
    };
}

function sourceSpanOwnsDrawnUnderlineY(sourceSpan, y) {
    const lineY = Number(y);
    if (!Number.isFinite(lineY)) return false;
    const {
        bboxTop,
        bboxBottom,
        baseline,
        fontSize,
    } = sourceSpanUnderlineMetrics(sourceSpan);

    // PyMuPDF coordinates increase down the page. A typographic underline
    // lives in the glyph descender band: just below the text baseline and no
    // lower than the glyph bbox. Form/table rules sit farther below (or above)
    // the baseline and must never become text decoration when a source run is
    // moved. Keep a small tolerance for floating-point/rendering differences.
    if (Number.isFinite(baseline)) {
        const maximumBaselineGap = Math.max(
            1.5,
            Number.isFinite(fontSize) && fontSize > 0 ? fontSize * 0.22 : 0,
        );
        if (lineY < baseline + 0.1 || lineY > baseline + maximumBaselineGap) {
            return false;
        }
    }
    if (Number.isFinite(bboxTop) && lineY < bboxTop - 2) return false;
    if (Number.isFinite(bboxBottom) && lineY > bboxBottom + 0.25) return false;
    return true;
}

// Normalize and validate PDF-drawn underline segments against their owning
// text span. This also protects documents extracted before the stricter
// server-side classifier was introduced.
export function sourceSpanDrawnUnderlineSegments(sourceSpan) {
    const segments = sourceSpan?.drawnUnderlineSegments
        ?? sourceSpan?.drawn_underline_segments;
    if (!Array.isArray(segments)) return [];
    return segments.map((segment) => {
        const x0 = Number(segment?.x0 ?? segment?.left ?? segment?.[0]);
        const x1 = Number(segment?.x1 ?? segment?.right ?? segment?.[1]);
        const y = Number(segment?.y ?? segment?.top ?? segment?.[2]);
        const width = Number(segment?.width ?? segment?.line_width ?? segment?.[3] ?? 0);
        if (![x0, x1, y].every(Number.isFinite)
            || Math.abs(x1 - x0) <= 0.01
            || !sourceSpanOwnsDrawnUnderlineY(sourceSpan, y)) {
            return null;
        }
        return {
            x0: Math.min(x0, x1),
            x1: Math.max(x0, x1),
            y,
            width: Number.isFinite(width) ? Math.max(0, width) : 0,
        };
    }).filter(Boolean);
}

// Extraction records PDF-drawn underlines as absolute page-space line
// segments. Convert them to span-relative horizontal ranges so the browser can
// split a single PDF.js text run around only the underlined words.
export function sourceSpanDrawnUnderlineRanges(sourceSpan) {
    const bbox = Array.isArray(sourceSpan?.bbox) ? sourceSpan.bbox : null;
    const x0 = Number(bbox?.[0]);
    const x1 = Number(bbox?.[2]);
    const width = x1 - x0;
    const directRanges = sourceSpan?.drawnUnderlineRanges
        ?? sourceSpan?.drawn_underline_ranges;
    if (Array.isArray(directRanges) && directRanges.length) {
        return mergeUnderlineRanges(directRanges);
    }

    const rawSegments = sourceSpan?.drawnUnderlineSegments
        ?? sourceSpan?.drawn_underline_segments;
    if (Array.isArray(rawSegments)) {
        const segments = sourceSpanDrawnUnderlineSegments(sourceSpan);
        if (!(Number.isFinite(width) && width > 0)) return [];
        const ranges = segments.map((segment) => {
            const segmentX0 = Number(segment?.x0 ?? segment?.left ?? segment?.[0]);
            const segmentX1 = Number(segment?.x1 ?? segment?.right ?? segment?.[1]);
            if (![segmentX0, segmentX1].every(Number.isFinite)) return null;
            return {
                start: (Math.min(segmentX0, segmentX1) - x0) / width,
                end: (Math.max(segmentX0, segmentX1) - x0) / width,
            };
        });
        const normalized = mergeUnderlineRanges(ranges);
        if (normalized.length) return normalized;
        // Explicit segment metadata is authoritative. In particular, old
        // records can carry a broad boolean plus a form rule that the stricter
        // ownership check above rejects; falling through to the boolean would
        // reintroduce a full-run underline.
        return [];
    }

    return (
        boolish(sourceSpan?.has_drawn_underline)
        || boolish(sourceSpan?.suppress_drawn_underline)
        || boolish(sourceSpan?.underline)
    ) ? [{ start: 0, end: 1 }] : [];
}

function sourceRunBoundaryFractions(text, item, measureTextWidth) {
    const characters = Array.from(String(text || ''));
    if (!characters.length) return [0, 1];
    const measure = typeof measureTextWidth === 'function'
        ? (value) => Number(measureTextWidth(value, item))
        : () => Number.NaN;
    const total = measure(characters.join(''));
    if (!Number.isFinite(total) || total <= 0) {
        return characters.map((_, index) => index / characters.length).concat(1);
    }
    const fractions = [0];
    for (let index = 1; index < characters.length; index += 1) {
        const width = measure(characters.slice(0, index).join(''));
        fractions.push(Number.isFinite(width) ? Math.max(0, Math.min(1, width / total)) : index / characters.length);
    }
    fractions.push(1);
    return fractions;
}

// Split captured source runs at drawn-underline boundaries. Every returned
// fragment explicitly carries underline true/false so a plain fragment does
// not inherit a box-level decoration from a neighboring underlined fragment.
export function splitSourceRunsAtDrawnUnderlineRanges(items, measureTextWidth = null) {
    return Array.from(items || []).flatMap((item) => {
        const text = String(item?.text || '');
        const characters = Array.from(text);
        if (!characters.length) return [];
        const ranges = mergeUnderlineRanges(item?.underlineRanges || []);
        if (!ranges.length) {
            return [{
                ...item,
                underline: item?.underlineRangesPrecise
                    ? false
                    : (boolish(item?.underline) || boolish(item?.hasDrawnUnderline)),
            }];
        }

        const fractions = sourceRunBoundaryFractions(text, item, measureTextWidth);
        const closestBoundary = (target) => {
            let bestIndex = 0;
            let bestDistance = Number.POSITIVE_INFINITY;
            fractions.forEach((fraction, index) => {
                const distance = Math.abs(fraction - target);
                if (distance < bestDistance) {
                    bestIndex = index;
                    bestDistance = distance;
                }
            });
            return bestIndex;
        };
        const boundaries = new Set([0, characters.length]);
        ranges.forEach((range) => {
            let start = closestBoundary(range.start);
            let end = closestBoundary(range.end);
            if (end <= start) {
                end = Math.min(characters.length, start + 1);
                start = Math.max(0, Math.min(start, end - 1));
            }
            boundaries.add(start);
            boundaries.add(end);
        });
        const ordered = Array.from(boundaries).sort((left, right) => left - right);
        const leftPx = Number(item?.leftPx);
        const rightPx = Number(item?.rightPx);
        const widthPx = rightPx - leftPx;

        return ordered.slice(0, -1).map((start, index) => {
            const end = ordered[index + 1];
            if (end <= start) return null;
            const startFraction = fractions[start] ?? (start / characters.length);
            const endFraction = fractions[end] ?? (end / characters.length);
            const midpoint = (startFraction + endFraction) / 2;
            const underline = ranges.some((range) => midpoint >= range.start && midpoint <= range.end);
            return {
                ...item,
                text: characters.slice(start, end).join(''),
                leftPx: Number.isFinite(leftPx) && Number.isFinite(widthPx)
                    ? leftPx + (widthPx * startFraction)
                    : item?.leftPx,
                rightPx: Number.isFinite(leftPx) && Number.isFinite(widthPx)
                    ? leftPx + (widthPx * endFraction)
                    : item?.rightPx,
                underline,
                underlineRanges: undefined,
            };
        }).filter(Boolean);
    });
}

// Lift per-run underline ownership into annotation-level export metadata.
// The browser uses the run flags to draw the moved words; the PDF writer uses
// the absolute segments (or the legacy boolean hint) to erase the old vector
// stroke from the source location.
export function sourceRunDrawnUnderlineMetadata(items) {
    const segments = [];
    const seen = new Set();
    let hasDrawnUnderline = false;
    for (const item of Array.from(items || [])) {
        hasDrawnUnderline = hasDrawnUnderline
            || boolish(item?.hasDrawnUnderline)
            || boolish(item?.underline);
        const rawSegments = item?.sourceUnderlineSegments;
        if (!Array.isArray(rawSegments)) continue;
        for (const value of rawSegments) {
            const x0 = Number(value?.x0 ?? value?.left ?? value?.[0]);
            const x1 = Number(value?.x1 ?? value?.right ?? value?.[1]);
            const y = Number(value?.y ?? value?.top ?? value?.[2]);
            const width = Number(value?.width ?? value?.line_width ?? value?.[3] ?? 0);
            if (![x0, x1, y].every(Number.isFinite) || Math.abs(x1 - x0) <= 0.01) continue;
            const segment = {
                x0: Math.min(x0, x1),
                x1: Math.max(x0, x1),
                y,
                width: Number.isFinite(width) ? Math.max(0, width) : 0,
            };
            const key = `${segment.x0.toFixed(3)}:${segment.x1.toFixed(3)}:${segment.y.toFixed(3)}`;
            if (seen.has(key)) continue;
            seen.add(key);
            segments.push(segment);
        }
    }
    return {
        hasDrawnUnderline: hasDrawnUnderline || segments.length > 0,
        segments,
    };
}

// PDF.js can represent a real word space as its own whitespace-only span.
// Source grouping deliberately ignores that span's empty ink box, so use the
// containing marked-content text as a whitespace authority only when its
// non-whitespace characters are an exact match. Existing visual multi-space
// gaps are retained; this only repairs a boundary whose space disappeared.
export function restoreExplicitSourceWhitespace(constructedText, markedContentText) {
    const constructed = String(constructedText ?? '');
    const authoritative = String(markedContentText ?? '').replace(/\s+/g, ' ').trim();
    if (!constructed.trim() || !authoritative) return constructed;
    if (normalizeComparableText(constructed) === authoritative) return constructed;
    if (textWithoutWhitespace(constructed) !== textWithoutWhitespace(authoritative)) return constructed;
    return authoritative;
}

function medianFinite(values, fallback = 0) {
    const sorted = Array.from(values || [])
        .map(Number)
        .filter(Number.isFinite)
        .sort((left, right) => left - right);
    if (!sorted.length) return fallback;
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2
        ? sorted[middle]
        : (sorted[middle - 1] + sorted[middle]) / 2;
}

function sourceCellRectEdges(value) {
    if (!value) return null;
    const left = Number(value.left);
    const top = Number(value.top);
    const right = Number(value.right ?? (left + Number(value.width)));
    const bottom = Number(value.bottom ?? (top + Number(value.height)));
    if (![left, top, right, bottom].every(Number.isFinite)
        || right <= left
        || bottom <= top) {
        return null;
    }
    return {
        left,
        top,
        right,
        bottom,
        width: right - left,
        height: bottom - top,
        centerX: (left + right) / 2,
        centerY: (top + bottom) / 2,
    };
}

function sourceCellIntervalOverlap(firstStart, firstEnd, secondStart, secondEnd) {
    return Math.max(0, Math.min(firstEnd, secondEnd) - Math.max(firstStart, secondStart));
}

// Return the furthest edge positions a text box may reach without crossing
// another painted text rectangle. Rectangles already intersecting the box are
// ignored: they are normally the PDF.js source glyphs owned by the promoted
// overlay, and a resize must not make a pre-existing overlap worse.
export function textResizeCollisionLimits(startRect, textRects = [], pageRect = null, gap = 2) {
    const start = sourceCellRectEdges(startRect);
    if (!start) return null;
    const page = sourceCellRectEdges(pageRect) || {
        left: Number.NEGATIVE_INFINITY,
        top: Number.NEGATIVE_INFINITY,
        right: Number.POSITIVE_INFINITY,
        bottom: Number.POSITIVE_INFINITY,
    };
    const clearance = Math.max(0, Number(gap) || 0);
    const overlapTolerance = 0.5;
    let left = page.left;
    let top = page.top;
    let right = page.right;
    let bottom = page.bottom;

    for (const value of textRects || []) {
        const candidate = sourceCellRectEdges(value);
        if (!candidate) continue;
        const horizontalOverlap = sourceCellIntervalOverlap(
            start.left,
            start.right,
            candidate.left,
            candidate.right,
        );
        const verticalOverlap = sourceCellIntervalOverlap(
            start.top,
            start.bottom,
            candidate.top,
            candidate.bottom,
        );
        if (horizontalOverlap > overlapTolerance && verticalOverlap > overlapTolerance) continue;

        if (verticalOverlap > overlapTolerance) {
            if (candidate.right <= start.left + overlapTolerance) {
                left = Math.max(left, candidate.right + clearance);
            } else if (candidate.left >= start.right - overlapTolerance) {
                right = Math.min(right, candidate.left - clearance);
            }
        }
        if (horizontalOverlap > overlapTolerance) {
            if (candidate.bottom <= start.top + overlapTolerance) {
                top = Math.max(top, candidate.bottom + clearance);
            } else if (candidate.top >= start.bottom - overlapTolerance) {
                bottom = Math.min(bottom, candidate.top - clearance);
            }
        }
    }

    return {
        left: Math.min(start.left, left),
        top: Math.min(start.top, top),
        right: Math.max(start.right, right),
        bottom: Math.max(start.bottom, bottom),
    };
}

// PDF.js line boxes commonly overlap even when their painted glyph rows do
// not. A source mask based on the full line box can therefore erase the row
// above/below, while subtracting those overlapping neighbour boxes from a
// moved-source mask can uncover the target glyphs. Assign the mask to the
// target group's row/column cell instead: adjacent group centres define the
// halfway boundaries, so every overlapping pixel has exactly one owner.
export function clampSourceMaskRectToCell(maskRect, targetRect, neighbourRects = []) {
    const mask = sourceCellRectEdges(maskRect);
    const target = sourceCellRectEdges(targetRect);
    if (!mask || !target) return maskRect;

    let cellLeft = Number.NEGATIVE_INFINITY;
    let cellTop = Number.NEGATIVE_INFINITY;
    let cellRight = Number.POSITIVE_INFINITY;
    let cellBottom = Number.POSITIVE_INFINITY;

    for (const value of neighbourRects || []) {
        const neighbour = sourceCellRectEdges(value);
        if (!neighbour) continue;
        const sameRect = Math.abs(neighbour.centerX - target.centerX) <= 0.01
            && Math.abs(neighbour.centerY - target.centerY) <= 0.01
            && Math.abs(neighbour.width - target.width) <= 0.01
            && Math.abs(neighbour.height - target.height) <= 0.01;
        if (sameRect) continue;

        const horizontalOverlap = sourceCellIntervalOverlap(
            target.left,
            target.right,
            neighbour.left,
            neighbour.right,
        );
        const minWidth = Math.max(0.01, Math.min(target.width, neighbour.width));
        const sharesColumn = horizontalOverlap > Math.min(4, minWidth * 0.1);
        const minHeight = Math.max(0.01, Math.min(target.height, neighbour.height));
        const sameRow = Math.abs(neighbour.centerY - target.centerY)
            <= Math.max(1, minHeight * 0.35);

        if (sharesColumn && !sameRow) {
            const midpointY = (target.centerY + neighbour.centerY) / 2;
            if (neighbour.centerY < target.centerY && midpointY > mask.top && midpointY < target.centerY) {
                cellTop = Math.max(cellTop, midpointY);
            } else if (neighbour.centerY > target.centerY && midpointY < mask.bottom && midpointY > target.centerY) {
                cellBottom = Math.min(cellBottom, midpointY);
            }
        }

        // Only introduce a column boundary when the mask actually reaches the
        // neighbouring group. This avoids shortening a long source run merely
        // because a much shorter run exists later on the same visual row.
        if (!sameRow) continue;
        const maskTouchesNeighbour = sourceCellIntervalOverlap(
            mask.left,
            mask.right,
            neighbour.left,
            neighbour.right,
        ) > 0.25;
        if (!maskTouchesNeighbour) continue;
        const midpointX = (target.centerX + neighbour.centerX) / 2;
        if (neighbour.centerX < target.centerX && midpointX > mask.left && midpointX < target.centerX) {
            cellLeft = Math.max(cellLeft, midpointX);
        } else if (neighbour.centerX > target.centerX && midpointX < mask.right && midpointX > target.centerX) {
            cellRight = Math.min(cellRight, midpointX);
        }
    }

    const left = Math.max(mask.left, cellLeft);
    const top = Math.max(mask.top, cellTop);
    const right = Math.min(mask.right, cellRight);
    const bottom = Math.min(mask.bottom, cellBottom);
    if (right - left <= 0.25 || bottom - top <= 0.25) return maskRect;
    return {
        left,
        top,
        right,
        bottom,
        width: right - left,
        height: bottom - top,
    };
}

// Convert captured visual line tops into edit-entry slots. The exact slot
// height preserves non-uniform PDF spacing; breakCount identifies
// paragraph-like gaps for diagnostics and future flow policies.
export function sourceVisualLineSlots(lineTops, scaleRatio = 1) {
    const tops = Array.from(lineTops || []).map(Number);
    if (!tops.length || tops.some((top) => !Number.isFinite(top))) return [];
    const scale = Number(scaleRatio);
    const resolvedScale = Number.isFinite(scale) && scale > 0 ? scale : 1;
    const deltas = [];
    for (let index = 0; index < tops.length - 1; index += 1) {
        const delta = tops[index + 1] - tops[index];
        if (delta > 0.5) deltas.push(delta);
    }
    const typicalStep = medianFinite(deltas, 0);
    return tops.map((top, index) => {
        if (index >= tops.length - 1) {
            return { topPx: top, slotHeightPx: 0, breakCount: 0 };
        }
        const delta = Math.max(0, tops[index + 1] - top);
        const ratio = typicalStep > 0 ? delta / typicalStep : 1;
        const breakCount = ratio >= 1.5
            ? Math.max(1, Math.min(12, Math.round(ratio)))
            : 1;
        return {
            topPx: top,
            slotHeightPx: delta * resolvedScale,
            breakCount,
        };
    });
}

// PDF extraction frequently gives a list marker (for example, a bullet) a
// larger glyph box than the paragraph that follows it. The marker must not
// become the paragraph's root font size: doing so turns every ordinary run
// into a fractional `em`, which compounds when the edit DOM is serialized
// and reconstructed. Weight by visible characters so the body typography is
// the stable reference while still handling genuinely uniform symbol runs.
export function dominantSourceRunFontSize(items, fallback = 0) {
    const buckets = new Map();
    Array.from(items || []).forEach((item) => {
        const size = Number(item?.fontSizePx);
        if (!Number.isFinite(size) || size <= 0) return;
        const key = Math.round(size * 100) / 100;
        const visibleCharacters = Array.from(String(item?.text || ''))
            .filter((character) => !/\s/u.test(character)).length;
        const weight = Math.max(1, visibleCharacters);
        const bucket = buckets.get(key) || { size, weight: 0, runs: 0 };
        bucket.weight += weight;
        bucket.runs += 1;
        buckets.set(key, bucket);
    });
    const winner = Array.from(buckets.values()).sort((left, right) => (
        right.weight - left.weight
        || right.runs - left.runs
        || left.size - right.size
    ))[0];
    if (winner) return winner.size;
    const resolvedFallback = Number(fallback);
    return Number.isFinite(resolvedFallback) && resolvedFallback > 0 ? resolvedFallback : 0;
}

const SOURCE_LIST_MARKER_RE = /^\s*(?:[\u2022\u2023\u2043\u204c\u204d\u2219\u25aa\u25ab\u25cf\u25e6]|(?:\d+|[A-Za-z])[.)])(?:\s|$)/u;

// Captured PDF rows are visual wraps, not authored hard returns. When an edit
// switches to natural flow, join ordinary rows with a space but retain real
// paragraph gaps and list-item boundaries.
export function naturalSourceLineSeparator(previousText, nextText, breakCount = 1) {
    const previous = String(previousText || '').trimEnd();
    const next = String(nextText || '').trimStart();
    const count = Number.parseInt(breakCount, 10) || 1;
    if (count >= 2) return '\n\n';
    if (SOURCE_LIST_MARKER_RE.test(next)) return '\n';
    if (!previous || !next) return '\n';
    if (/[-\u00ad]$/u.test(previous) && /^\p{Ll}/u.test(next)) return '';
    if (/^[,.;:!?%)\]}]/u.test(next)) return '';
    return ' ';
}

function normalizeRichPlainText(value) {
    return String(value || '')
        .replace(/\r\n?/g, '\n')
        .replace(/\u00a0/g, ' ')
        .replace(/\u200b/g, '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n[ \t]+/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/\n$/g, '');
}

function richTextRunStyleKey(style) {
    return [
        style?.fontFamily || '',
        style?.fontSourceName || '',
        Number(style?.fontSize || 0).toFixed(3),
        Number(style?.lineHeight || 0).toFixed(3),
        style?.fontWeight || '',
        style?.fontStyle || '',
        style?.color || '',
        style?.underline ? '1' : '0',
    ].join('|');
}

function richTextRunsPlainText(runs) {
    return Array.from(runs || []).map((run) => (
        run?.type === 'break' ? '\n' : (run?.type === 'text' ? String(run.text || '') : '')
    )).join('');
}

// Source-fidelity spans retain the exact PDF face that originally painted
// them. Once the user authors a different family, that immutable identity is
// stale and must not override the computed family serialized for download.
// A live PDF.js face resolved from the computed family is still valid (for
// example when the user explicitly chooses another document font).
export function resolveRichTextRunFontIdentity({
    computedFontFamily = 'Helvetica',
    sourcePdfFontName = '',
    computedDocumentFont = null,
    styleDirty = false,
} = {}) {
    const computedFamily = String(computedFontFamily || '').trim() || 'Helvetica';
    const preservedSourceName = boolish(styleDirty)
        ? ''
        : String(sourcePdfFontName || '').trim();
    const runtimePdfFontName = computedDocumentFont?.source === 'pdfjs-runtime'
        ? String(computedDocumentFont?.pdfFontName || '').trim()
        : '';
    const exactPdfFontName = preservedSourceName || runtimePdfFontName;
    const fontFamily = exactPdfFontName || computedFamily;
    const fontSourceName = exactPdfFontName
        ? (String(computedDocumentFont?.cleanName || '').trim()
            || exactPdfFontName.replace(/^[A-Z]{6}\+/i, ''))
        : (String(computedDocumentFont?.cleanName || '').trim() || fontFamily);
    return { fontFamily, fontSourceName };
}

export function pdfjsFontWeightFromFaceName(faceName, fontObject = null) {
    if (fontObject?.black || fontObject?.bold) return '700';
    const name = String(faceName || '').toLowerCase();
    // A variable-font instance keeps the family's static style name while the
    // real instance weight sits in its `wght` axis token, e.g.
    // `MontserratThin_700wght` is bold, not thin. The axis value wins.
    const axisWeight = /(?:^|[-_ ,+])([1-9]00)wght(?:$|[-_ ,])/.exec(name);
    if (axisWeight) return axisWeight[1];
    if (
        /bold|black|heavy|semibold|demibold|demi|extrabold|ultrabold/.test(name)
        // Commercial PDF faces commonly abbreviate Bold Condensed as `BdCn`
        // (HelveticaNeueLTStd-BdCn). Treat the complete suffix as a bold
        // marker; requiring a separator immediately after `bd` misses it.
        || /(?:^|[-_,])bd(?:cn|cond|it|italic|oblique)?(?:$|[-_,])/.test(name)
    ) return '700';
    if (/medium|(?:^|[-_,])med(?:$|[-_,])/.test(name)) return '500';
    if (/light|thin/.test(name)) return '300';
    return '400';
}

export function richTextViewportCssLength(value, options = {}) {
    const raw = String(value || '').trim();
    const pointScale = Number(options.pointScale) || 0;
    const pixelRatio = Number(options.pixelRatio) || 1;
    const pointMatch = /^(-?\d*\.?\d+)pt$/i.exec(raw);
    if (pointMatch && pointScale > 0) {
        return `${Number.parseFloat(pointMatch[1]) * pointScale}px`;
    }
    const pixelMatch = /^(-?\d*\.?\d+)px$/i.exec(raw);
    if (pixelMatch && pixelRatio > 0 && Math.abs(pixelRatio - 1) > 0.001) {
        return `${Number.parseFloat(pixelMatch[1]) * pixelRatio}px`;
    }
    return raw;
}

export function reconcileRichTextRunWhitespace(runs, expectedText) {
    if (!Array.isArray(runs) || !runs.length) return [];
    const expected = normalizeRichPlainText(expectedText);
    const actual = normalizeRichPlainText(richTextRunsPlainText(runs));
    if (!expected || actual === expected) return runs;
    const withoutWhitespace = (value) => Array.from(String(value || ''))
        .filter((character) => !/\s/u.test(character))
        .join('');
    if (withoutWhitespace(actual) !== withoutWhitespace(expected)) return [];

    const styledCharacters = [];
    runs.forEach((run) => {
        if (run?.type !== 'text') return;
        Array.from(String(run.text || '')).forEach((character) => {
            if (!/\s/u.test(character)) styledCharacters.push({ character, style: run });
        });
    });
    const rebuilt = [];
    let characterIndex = 0;
    let pendingWhitespace = '';
    const appendBreak = () => {
        if (rebuilt.length) rebuilt.push({ type: 'break' });
    };
    const appendText = (text, sourceRun) => {
        if (!text || !sourceRun) return;
        const style = { ...sourceRun };
        delete style.text;
        const key = richTextRunStyleKey(style);
        const previous = rebuilt[rebuilt.length - 1];
        if (previous?.type === 'text' && previous._styleKey === key) {
            previous.text += text;
        } else {
            rebuilt.push({ ...style, type: 'text', text, _styleKey: key });
        }
    };
    for (const character of Array.from(expected)) {
        if (character === '\n') {
            if (pendingWhitespace && rebuilt[rebuilt.length - 1]?.type === 'text') {
                rebuilt[rebuilt.length - 1].text += pendingWhitespace;
            }
            pendingWhitespace = '';
            appendBreak();
            continue;
        }
        if (/\s/u.test(character)) {
            pendingWhitespace += character;
            continue;
        }
        const styledCharacter = styledCharacters[characterIndex++];
        if (!styledCharacter || styledCharacter.character !== character) return [];
        appendText(`${pendingWhitespace}${character}`, styledCharacter.style);
        pendingWhitespace = '';
    }
    if (pendingWhitespace && rebuilt[rebuilt.length - 1]?.type === 'text') {
        rebuilt[rebuilt.length - 1].text += pendingWhitespace;
    }
    while (rebuilt[rebuilt.length - 1]?.type === 'break') rebuilt.pop();
    rebuilt.forEach((run) => delete run._styleKey);
    return normalizeRichPlainText(richTextRunsPlainText(rebuilt)) === expected ? rebuilt : [];
}

export function sourceNaturalizedGapText({
    atLineStart = false,
    currentText = '',
    originalSpaceCount = 0,
    preserveCapturedSpacing = false,
    userMutated = false,
} = {}) {
    if (userMutated) return String(currentText || '');
    if (atLineStart) return '';
    const capturedCount = Math.max(
        1,
        Math.min(120, Number.parseInt(String(originalSpaceCount || 0), 10) || 1),
    );
    return ' '.repeat(preserveCapturedSpacing ? capturedCount : 1);
}

export function sourceRunTextsUseDistributedLeaderSpacing(runTexts, minimumRunCount = 3) {
    const minimum = Math.max(2, Number.parseInt(String(minimumRunCount || 3), 10) || 3);
    let consecutive = 0;
    for (const value of Array.from(runTexts || [])) {
        if (/^[.…·•]+$/u.test(String(value || '').trim())) {
            consecutive += 1;
            if (consecutive >= minimum) return true;
        } else {
            consecutive = 0;
        }
    }
    return false;
}

function hasPdfjsImmutableSourceText(annotation) {
    if (!String(annotation?.pdfjsSourceText || '').trim()) return false;
    return annotation.pdfjsSourceX != null
        || annotation.pdfjsSourceY != null
        || annotation.pdfjsSourceMaskX != null
        || annotation.pdfjsAnchorUid != null;
}

export function isPdfjsSourceBackedTextAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    const hasImmutableSourceText = hasPdfjsImmutableSourceText(annotation);
    if (boolish(annotation.userCreated) && !hasImmutableSourceText) return false;
    if (boolish(annotation.skipPdfjsSourceMask) && !hasImmutableSourceText) return false;
    return annotation.pdfjsSourceX != null
        || annotation.pdfjsSourceY != null
        || annotation.pdfjsAnchorUid != null
        || annotation.pdfjsSourceText != null;
}

export function isPdfjsPromotedExtractionAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    return boolish(annotation.promotedFromExtraction)
        || String(annotation.id || '').startsWith('promoted_');
}

export function promotedTextEditFlags({
    isPromoted = false,
    currentText = '',
    sourceText = '',
    promotedDirty = false,
    preserveSourceTypography = false,
} = {}) {
    const normalizedCurrent = normalizeComparableText(currentText);
    const normalizedSource = normalizeComparableText(sourceText);
    const textChanged = Boolean(
        isPromoted
        && normalizedSource
        && normalizedCurrent !== normalizedSource
    );
    return {
        textChanged,
        promotedDirty: Boolean(
            isPromoted
            && (boolish(promotedDirty) || textChanged)
        ),
        preserveSourceTypography: Boolean(
            isPromoted
            && (boolish(preserveSourceTypography) || textChanged)
        ),
    };
}

function promotedAnnotationIsMultiLine(annotation) {
    if (Array.isArray(annotation.sourceLineBBoxes) && annotation.sourceLineBBoxes.length > 1) return true;
    const lines = String(annotation.pdfjsSourceText || annotation.originalText || annotation.text || '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line.length > 0);
    return lines.length > 1;
}

export function pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation) {
    if (!isPdfjsPromotedExtractionAnnotation(annotation)) return false;
    if (boolish(annotation.pdfjsDeleted)) return false;

    const savedText = normalizeComparableText(annotation.text);
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    const textChanged = Boolean(savedText && sourceText && savedText !== sourceText);
    const hasRichText = String(annotation.richTextHtml || '').trim() !== '';

    const explicitlyDirty = textChanged
        || boolish(annotation.promotedDirty)
        || boolish(annotation.userAuthored)
        || boolish(annotation.styleDirty)
        || boolish(annotation.userForcedRichText)
        || boolish(annotation.movedTextOverlay)
        || boolish(annotation.promotedReflowEnabled)
        || hasRichText;
    if (explicitlyDirty) return true;

    // `pdfjsEditorMode === 'rich'` on its own is NOT a reliable "user edited
    // it" signal for promoted blocks. Multi-line promoted paragraphs are
    // forced into rich editor mode purely because their text contains
    // newlines (see editorModeForBox/boxTextHasNewline in main.js). Treating
    // that inherent rich mode as a dirty signal caused a clean, unchanged
    // multi-line block to revert from its paragraph-grouped per-line source
    // rendering to a single bounding box after merely opening it in edit mode
    // (or after save + reload). Only honor 'rich' as a revert trigger for
    // single-line promoted overlays, where rich mode genuinely implies a
    // user-forced style/structure change rather than an artifact of newlines.
    if (String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich') {
        return !promotedAnnotationIsMultiLine(annotation);
    }

    return false;
}

export function pdfjsSourceOverlayShouldUseSourceBoxInEditMode(annotation, editModeOn = false) {
    if (!editModeOn) return false;
    if (!isPdfjsSourceBackedTextAnnotation(annotation)) return false;
    if (isPdfjsPromotedExtractionAnnotation(annotation)) return false;
    if (!boolish(annotation.savedTextOverlay)) return false;
    if (
        boolish(annotation.pdfjsDeleted)
        || boolish(annotation.movedTextOverlay)
        || boolish(annotation.styleDirty)
        || boolish(annotation.userForcedRichText)
        || String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich'
        || String(annotation.richTextHtml || '').trim() !== ''
    ) {
        return false;
    }

    const sourceText = normalizeComparableText(
        annotation.pdfjsSourceText || annotation.originalText || ''
    );
    const savedText = normalizeComparableText(annotation.text);
    return Boolean(sourceText) && savedText === sourceText;
}
