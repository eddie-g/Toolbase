/*
 * generateAnnotationId (Phase 7ae).
 *
 * Pure id generator for newly-created annotations. Format matches the
 * legacy convention so persisted ids stay stable across sessions.
 */

export function generateAnnotationId() {
    return 'ann_' + Date.now() + '_' + Math.random().toString(36).slice(2, 11);
}
