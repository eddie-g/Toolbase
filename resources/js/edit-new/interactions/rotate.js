/*
 * Rotate-handle interaction (Phase 6c).
 *
 * Owns `beginRotate` / `endRotate` plus the geometry helpers
 * `getRotationCenterClient` and `pointerAngleDeg`. The two pointermove
 * / pointerup window listeners that drive an in-flight rotation still
 * live in main.js and call back into `getRotationCenterClient` /
 * `pointerAngleDeg` (re-exported here).
 *
 * Still-monolithic main.js helpers (pushUndo, hasActiveBoxSelection,
 * markDirty) flow in via `configureRotateInteractions(deps)`.
 */

import { activeState } from '../store/active-state.js';
import { rotateState, setRotateState } from '../store/interaction-state.js';
import { pageData } from '../store/page-data.js';
import { editModeEnabled, addTextMode, shapeMode } from '../store/editor-modes.js';
import { isAnnotationLocked, isShapeAnnotation, isLineShape } from '../annotations/types.js';
import { resolveAnnBox } from '../annotations/box.js';
import { normalizeRotationDegrees } from '../util/geometry.js';
import { markUserAuthored } from '../annotations/state.js';

let _deps = null;

export function configureRotateInteractions(deps) {
    _deps = deps;
}

export function getRotationCenterClient(pi, ann) {
    const data = pageData[pi];
    if (!data) return null;
    const oc = document.getElementById('oc-' + (pi + 1));
    const box = ann ? resolveAnnBox(ann) : null;
    if (!oc || !box) return null;
    const rect = oc.getBoundingClientRect();
    const left = box.x * data.scale;
    const top  = data.canvasHeight - (box.y + box.h) * data.scale;
    const w    = Math.max(1, box.w * data.scale);
    const h    = Math.max(1, box.h * data.scale);
    return {
        cx: rect.left + left + w / 2,
        cy: rect.top + top + h / 2,
    };
}

export function pointerAngleDeg(clientX, clientY, center) {
    return Math.atan2(clientY - center.cy, clientX - center.cx) * (180 / Math.PI);
}

export function beginRotate(e, pi) {
    if (!_deps) return;
    if (!editModeEnabled && !addTextMode && !shapeMode && !_deps.hasActiveBoxSelection()) return;
    const data = pageData[pi];
    const ann = data?.annotations.find(a => a._uid === activeState.uid);
    if (!ann) return;
    if (isAnnotationLocked(ann)) return;
    if (isShapeAnnotation(ann) && isLineShape(ann)) return; // lines rotate via endpoints
    const center = getRotationCenterClient(pi, ann);
    if (!center) return;
    _deps.pushUndo();
    const currentRot = normalizeRotationDegrees(ann.rotation || 0);
    const handleAngle = normalizeRotationDegrees(currentRot + 90); // base = bottom
    const pAngle = pointerAngleDeg(e.clientX, e.clientY, center);
    let offset = pAngle - handleAngle;
    // normalize signed
    offset = ((offset + 540) % 360) - 180;
    setRotateState({
        active: true,
        pi,
        uid: ann._uid,
        pointerId: e.pointerId,
        grabAngleOffset: offset,
    });
    if (e.target && typeof e.target.setPointerCapture === 'function') {
        try { e.target.setPointerCapture(e.pointerId); } catch (_) {}
        e.target.classList.add('is-active');
    }
    if (!ann.rotation) ann.rotation = 0;
    markUserAuthored(ann);
}

export function endRotate() {
    if (!_deps) return;
    if (!rotateState.active) return;
    const rot = document.getElementById('rh-' + (rotateState.pi + 1) + '-rot');
    if (rot) rot.classList.remove('is-active');
    setRotateState({ active: false, pi: null, uid: null, pointerId: null, grabAngleOffset: 0 });
    _deps.markDirty();
}
