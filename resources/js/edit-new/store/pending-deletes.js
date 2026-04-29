/**
 * pending-deletes — sets of identifiers tracking annotations that should be
 * removed when the editor saves.
 *
 *   pendingDeletedAnnotationIds        — server-known annotation ids to delete
 *   pendingDeletedPromotedSourceKeys   — promoted-from-extraction source keys
 *                                         to suppress on the next render
 *
 * Both are mutated in place (`.add` / `.delete` / `.clear`) and the bindings
 * are never reassigned, so consumers can hold the live Set reference.
 */
export const pendingDeletedAnnotationIds = new Set();
export const pendingDeletedPromotedSourceKeys = new Set();
