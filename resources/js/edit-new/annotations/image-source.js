/*
 * getImageAnnotationSource (Phase 7ao).
 *
 * Pure accessor: returns the trimmed source string for an image-
 * backed annotation. Annotations may store their image source under
 * either `dataUrl` (typical for newly-uploaded images and saved
 * signatures) or `src` (legacy / placement-time URL). This unifies
 * the two so callers don't have to re-implement the fallback.
 */

export function getImageAnnotationSource(ann) {
    return String(ann?.dataUrl || ann?.src || '').trim();
}
