/*
 * shape/constrain-listener (Phase 7ck).
 *
 * Attach/detach the global Shift-key listener that drives the
 * "constrain to square / circle / 45°" affordance during shape
 * creation. Idempotent: attach() is a no-op if already attached;
 * detach() is a no-op if already detached. State lives in the
 * misc-state store so the two halves stay paired across hot-reloads
 * and across the editor's various entry points.
 */

import {
    shapeConstrainShiftAttached,
    setShapeConstrainShiftAttached,
} from '../store/misc-state.js';

export function attachShapeConstrainShiftListener(handler) {
    if (shapeConstrainShiftAttached) return;
    setShapeConstrainShiftAttached(true);
    window.addEventListener('keydown', handler, true);
    window.addEventListener('keyup', handler, true);
}

export function detachShapeConstrainShiftListener(handler) {
    if (!shapeConstrainShiftAttached) return;
    setShapeConstrainShiftAttached(false);
    window.removeEventListener('keydown', handler, true);
    window.removeEventListener('keyup', handler, true);
}
