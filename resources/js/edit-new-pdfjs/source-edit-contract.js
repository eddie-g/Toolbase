function boolish(value) {
    if (value === true || value === 1) return true;
    return ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

export function isPdfjsSourceBackedTextAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    return annotation.pdfjsSourceX != null
        || annotation.pdfjsSourceY != null
        || annotation.pdfjsAnchorUid != null
        || annotation.pdfjsSourceText != null;
}

export function pdfjsSourceOverlayShouldUseSourceBoxInEditMode(annotation, editModeOn = false) {
    if (!editModeOn) return false;
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (!boolish(annotation.savedTextOverlay) || boolish(annotation.pdfjsDeleted)) return false;
    if (boolish(annotation.movedTextOverlay)) return false;
    if (boolish(annotation.userForcedRichText)) return false;
    if (String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich') return false;
    return isPdfjsSourceBackedTextAnnotation(annotation);
}
