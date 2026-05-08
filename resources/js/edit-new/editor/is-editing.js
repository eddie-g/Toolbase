/*
 * editorIsEditingAnnotation (Phase 7ah).
 *
 * Pure DOM-state predicate: true when the given active-editor element
 * is currently in editing mode for the given annotation. The editor's
 * `data-editing` flag and `data-editing-uid` attribute are written by
 * the editor lifecycle in main.js and consulted from many call sites
 * to decide whether to apply caret/selection routing.
 */

export function editorIsEditingAnnotation(ae, ann) {
    return ae instanceof HTMLElement
        && ann
        && ae.dataset.editing === '1'
        && ae.dataset.editingUid === String(ann._uid || '');
}
