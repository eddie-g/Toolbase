/*
 * Drag-to-reposition interaction (Phase 6a).
 *
 * Handles `beginDrag` / `endDrag` for box / text / shape annotations.
 * The window-level `mousemove` listener that updates the annotation's
 * position while a drag is in flight still lives in `main.js` for now;
 * it will be extracted in a follow-up commit once `resize` and `rotate`
 * are also factored out.
 *
 * Several main.js helpers (syncActiveEditor,
 * hasActiveBoxSelection) are not yet importable from a module, so they
 * are passed in via `configureDragInteractions(deps)` at boot.
 */

import { activeState } from '../store/active-state.js';
import { dragState, setDragState } from '../store/interaction-state.js';
import { pageData } from '../store/page-data.js';
import { editModeEnabled, addTextMode, shapeMode } from '../store/editor-modes.js';
import { isAnnotationLocked, isBoxAnnotation } from '../annotations/types.js';
import { resolveAnnBox } from '../annotations/box.js';
import { pdfPtFromClient } from '../render/coords.js';
import { pushUndo } from '../history/undo-redo.js';
import { markDirty } from '../store/lifecycle-flags.js';
import { hasActiveBoxSelection } from '../selection/active.js';

let _deps = null;

/**
 * Wire the drag interaction module to its main.js collaborators.
 * Call once during editor bootstrap.
 *
 * @param {Object} deps
 * @param {(restore?: boolean) => void} deps.syncActiveEditor
 */
export function configureDragInteractions(deps) {
    _deps = deps;
}

export function beginDrag(e, pi) {
    if (!_deps) return;
    if (!editModeEnabled && !addTextMode && !shapeMode && !hasActiveBoxSelection()) return;
    const data = pageData[pi];
    const ann  = data?.annotations.find(a => a._uid === activeState.uid);
    const box  = ann ? resolveAnnBox(ann) : null;
    const pt   = ann ? pdfPtFromClient(e.clientX, e.clientY, pi) : null;
    if (!ann || !box || !pt) return;
    if (isAnnotationLocked(ann)) return;
    pushUndo();
    setDragState({
        active: true,
        pi,
        uid: ann._uid,
        offsetXPts: pt.x - box.x,
        offsetYPts: pt.y - box.y,
        startPt: { ...pt },
        startBox: { ...box },
    });
    // Hide the shape action bar / text hover menu while dragging so they don't
    // follow the cursor and re-trigger hover/scale transitions on every mousemove.
    if (isBoxAnnotation(ann)) {
        const sh = document.getElementById('sh-' + (pi + 1));
        if (sh) sh.style.display = 'none';
        document.body.style.cursor = 'grabbing';
    } else {
        const tm = document.getElementById('tm-' + (pi + 1));
        if (tm) tm.style.display = 'none';
        document.body.style.cursor = 'grabbing';
    }
}

export function endDrag() {
    if (!_deps) return;
    if (!dragState.active) return;
    const pi = dragState.pi;
    setDragState({ active: false, pi: null, uid: null, offsetXPts: 0, offsetYPts: 0, startPt: null, startBox: null });
    document.body.style.cursor = '';
    // Restore the shape action bar (hidden during drag).
    if (pi !== null) _deps.syncActiveEditor(true);
    markDirty();
}
