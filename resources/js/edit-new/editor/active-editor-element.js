/*
 * Active-editor DOM element + editing-state mutators (Phase 7an).
 *
 * Companion to `editor/is-editing.js`. Centralises the small DOM-level
 * operations on the per-page active-editor element (`<div id="ae-N">`)
 * and its `data-editing` / `data-editing-uid` flags.
 *
 *   - activeEditorForPage: lookup helper for the per-page active editor.
 *   - setEditorEditingAnnotation: set the editing flags for a given ann.
 *   - clearEditorEditingState: clear the editing flags.
 */

export function activeEditorForPage(pi) {
    if (pi === null || pi === undefined) return null;
    return document.getElementById('ae-' + (Number(pi) + 1));
}

export function setEditorEditingAnnotation(ae, ann) {
    if (!(ae instanceof HTMLElement) || !ann) return;
    ae.dataset.editing = '1';
    ae.dataset.editingUid = String(ann._uid || '');
}

export function clearEditorEditingState(ae) {
    if (!(ae instanceof HTMLElement)) return;
    delete ae.dataset.editing;
    delete ae.dataset.editingUid;
}
