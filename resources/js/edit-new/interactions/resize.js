/*
 * Resize-from-handle interaction (Phase 6b).
 *
 * Owns `beginResize` / `endResize`. The window-level mousemove handler
 * that mutates the box while a resize is in flight still lives in
 * main.js for now; it will move into a shared dispatcher in 6d.
 *
 * `pushUndo`, `hasActiveBoxSelection`, `markDirty`, and `editableLineStyle`
 * are still defined inside main.js, so the module receives them via
 * `configureResizeInteractions(deps)` once at boot.
 */

import { activeState } from '../store/active-state.js';
import { resizeState, setResizeState } from '../store/interaction-state.js';
import { pageData } from '../store/page-data.js';
import { editModeEnabled, addTextMode, shapeMode } from '../store/editor-modes.js';
import { isAnnotationLocked, isTextAnnotation, isShapeAnnotation, isLineShape } from '../annotations/types.js';
import { resolveAnnBox } from '../annotations/box.js';
import { pdfPtFromClient } from '../render/coords.js';
import { clamp01 } from '../util/math.js';

let _deps = null;

export function configureResizeInteractions(deps) {
    _deps = deps;
}

export function beginResize(e, pi, handle) {
    if (!_deps) return;
    if (!editModeEnabled && !addTextMode && !shapeMode && !_deps.hasActiveBoxSelection()) return;
    const data = pageData[pi];
    const ann = data?.annotations.find(a => a._uid === activeState.uid);
    const box = ann ? resolveAnnBox(ann) : null;
    const pt = ann ? pdfPtFromClient(e.clientX, e.clientY, pi) : null;
    if (!ann || !box || !pt) return;
    if (isAnnotationLocked(ann)) return;
    if (isTextAnnotation(ann)) {
        ann._autoWidth = false;
    }
    _deps.pushUndo();
    const startStyle = _deps.editableLineStyle(ann, 0);
    setResizeState({
        active: true,
        pi,
        uid: ann._uid,
        handle,
        startPt: pt,
        startBox: { ...box },
        startFontSize: startStyle.fontSizePt,
        startLineGeometry: (isShapeAnnotation(ann) && isLineShape(ann))
            ? {
                lineStartX: clamp01(ann.lineStartX, 0),
                lineStartY: clamp01(ann.lineStartY, 0),
                lineEndX: clamp01(ann.lineEndX, 1),
                lineEndY: clamp01(ann.lineEndY, 1),
            }
            : null,
    });
}

export function endResize() {
    if (!_deps) return;
    if (!resizeState.active) return;
    setResizeState({ active: false, pi: null, uid: null, handle: null, startPt: null, startBox: null, startFontSize: null, startLineGeometry: null });
    _deps.markDirty();
}
