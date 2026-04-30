/*
 * erase-mode/cancel (Phase 7cp).
 *
 * Tear down the direct-erase mode (the "drag to wipe pen strokes"
 * affordance). Idempotent: returns early when erase mode is not
 * armed. Resets the eraseMode flag + hover state through their
 * respective stores, then runs syncCursors + updateUi +
 * redrawAllOverlays callbacks so the canvas cursor / mode bar /
 * page repaint drop the eraser visuals.
 */

import { eraseMode, setEraseModeFlag } from '../store/editor-modes.js';
import { clearHoverState } from '../store/hover-state.js';

export function cancelEraseMode({ syncCursors, updateUi, redrawAllOverlays } = {}) {
    if (!eraseMode) return;
    setEraseModeFlag(false);
    clearHoverState();
    if (typeof syncCursors === 'function') syncCursors();
    if (typeof updateUi === 'function') updateUi();
    if (typeof redrawAllOverlays === 'function') redrawAllOverlays();
}
