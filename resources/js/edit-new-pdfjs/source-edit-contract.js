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
    return false;
}
