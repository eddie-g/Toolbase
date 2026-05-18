function boolish(value) {
    if (value === true || value === 1) return true;
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

function normalizeComparableText(value) {
    return String(value ?? '').replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').trim();
}

export function isPdfjsSourceBackedTextAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
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

export function pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation) {
    if (!isPdfjsPromotedExtractionAnnotation(annotation)) return false;
    if (boolish(annotation.pdfjsDeleted)) return false;

    const savedText = normalizeComparableText(annotation.text);
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    const textChanged = Boolean(savedText && sourceText && savedText !== sourceText);
    const hasRichText = String(annotation.richTextHtml || '').trim() !== '';

    return textChanged
        || boolish(annotation.promotedDirty)
        || boolish(annotation.userAuthored)
        || boolish(annotation.styleDirty)
        || boolish(annotation.userForcedRichText)
        || boolish(annotation.movedTextOverlay)
        || boolish(annotation.promotedReflowEnabled)
        || hasRichText
        || String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich';
}

export function pdfjsSourceOverlayShouldUseSourceBoxInEditMode(annotation, editModeOn = false) {
    if (!editModeOn) return false;
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation)) return false;
    if (!boolish(annotation.savedTextOverlay) || boolish(annotation.pdfjsDeleted)) return false;
    if (boolish(annotation.movedTextOverlay)) return false;
    if (boolish(annotation.styleDirty)) return false;
    if (boolish(annotation.userForcedRichText)) return false;
    if (String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich') return false;
    if (!isPdfjsSourceBackedTextAnnotation(annotation)) return false;

    const savedText = normalizeComparableText(annotation.text);
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    return Boolean(sourceText && savedText === sourceText);
}
