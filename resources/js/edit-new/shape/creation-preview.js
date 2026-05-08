/*
 * shape/creation-preview (Phase 7cq).
 *
 * Tear-down for an in-flight shape-drawing preview (rectangle /
 * ellipse / polygon / line). Resets the shape-creation store, then
 * runs the supplied callbacks so the shift-to-constrain listener
 * detaches, the constrain tooltip hides, the overlay redraws clean,
 * and the shape-panel UI re-syncs.
 */

import { setShapeCreationState } from '../store/interaction-state.js';

export function cancelShapeCreationPreview({
    detachShiftListener,
    hideConstrainTip,
    redrawAllOverlays,
    syncShapePanelUi,
} = {}) {
    setShapeCreationState({
        active: false,
        pi: null,
        pointerId: null,
        startPt: null,
        currentPt: null,
        previewAnn: null,
        constrain: false,
    });
    if (typeof detachShiftListener === 'function') detachShiftListener();
    if (typeof hideConstrainTip === 'function') hideConstrainTip();
    if (typeof redrawAllOverlays === 'function') redrawAllOverlays();
    if (typeof syncShapePanelUi === 'function') syncShapePanelUi();
}
