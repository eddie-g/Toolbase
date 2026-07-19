function boolish(value) {
    if (value === true || value === 1) return true;
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

function normalizeComparableText(value) {
    return String(value ?? '').replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').trim();
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
