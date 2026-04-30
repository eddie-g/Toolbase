/*
 * beginViewportScaleUpdate (Phase 7cg).
 *
 * Suppresses editor-autofit until two animation frames after the
 * viewport has settled. Used by zoom and other scale updates so the
 * active editor doesn't fight the in-progress layout change. Stateless:
 * coordinates entirely through the zoom-state store.
 */

import {
    suppressEditorAutofitResetToken,
    setSuppressEditorAutofit,
    bumpSuppressEditorAutofitResetToken,
} from '../store/zoom-state.js';

export function beginViewportScaleUpdate() {
    setSuppressEditorAutofit(true);
    const token = bumpSuppressEditorAutofitResetToken();
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            if (suppressEditorAutofitResetToken === token) {
                setSuppressEditorAutofit(false);
            }
        });
    });
}
