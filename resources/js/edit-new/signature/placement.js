/*
 * signature/placement (Phase 7co).
 *
 * Cancel the in-flight "drop a signature stamp on the page" mode.
 * Resets the signature-placement store, then runs syncCursors +
 * updateUi callbacks so the canvas cursor + mode bar drop the
 * placement affordance.
 */

import {
    signaturePlacementState,
    setSignaturePlacementState,
} from '../store/signature-state.js';

export function cancelSignaturePlacement({ syncCursors, updateUi } = {}) {
    if (!signaturePlacementState.active) return;
    setSignaturePlacementState({ active: false, asset: null, type: 'signature', toolSource: null });
    if (typeof syncCursors === 'function') syncCursors();
    if (typeof updateUi === 'function') updateUi();
}
