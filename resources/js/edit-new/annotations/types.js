// Pure type predicates and small label helpers used across the editor to
// classify annotations (shape vs image vs text vs signature) and surface
// human-readable labels. No DOM, no editor state.

export function isShapeAnnotation(ann) {
    return String(ann?.type || '') === 'shape';
}

export function isImageBackedAnnotation(ann) {
    const type = String(ann?.type || '').toLowerCase();
    return type === 'signature' || type === 'image';
}

export function isDirectDrawAnnotation(ann) {
    return isImageBackedAnnotation(ann) && String(ann?.imageToolSource || '') === 'direct-draw';
}

export function canRemoveBackgroundFromImageAnnotation(ann) {
    return String(ann?.type || '').toLowerCase() === 'image' && !isDirectDrawAnnotation(ann);
}

export function normalizeSignatureSourceMode(value) {
    const raw = String(value || '').toLowerCase();
    return ['draw', 'type', 'upload'].includes(raw) ? raw : 'draw';
}

export function getSignatureAnnotationLabel(mode) {
    const normalized = normalizeSignatureSourceMode(mode);
    if (normalized === 'type') return 'Typed signature';
    if (normalized === 'upload') return 'Uploaded signature';
    return 'Drawn signature';
}

export function getAnnotationDisplayLabel(ann) {
    const type = String(ann?.type || '').toLowerCase();
    if (type === 'signature') return getSignatureAnnotationLabel(ann?.signatureSourceMode);
    if (type === 'image') return 'Image';
    return '';
}

export function isBoxAnnotation(ann) {
    return isShapeAnnotation(ann) || isImageBackedAnnotation(ann);
}

export function isTextAnnotation(ann) {
    return !isBoxAnnotation(ann);
}

export function isAnnotationLocked(ann) {
    return Boolean(ann?.locked);
}

export function isLineShape(annOrType) {
    const shapeType = typeof annOrType === 'string' ? annOrType : annOrType?.shapeType;
    return String(shapeType || '') === 'line';
}
