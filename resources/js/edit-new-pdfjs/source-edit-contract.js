function boolish(value) {
    if (value === true || value === 1) return true;
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

function normalizeComparableText(value) {
    return String(value ?? '').replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').trim();
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
