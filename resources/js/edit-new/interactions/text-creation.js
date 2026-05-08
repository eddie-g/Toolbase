/*
 * interactions/text-creation (Phase 7az).
 *
 * cancelTextCreationPreview: tear down the in-flight "drag a box to
 * create a text annotation" preview element + reset its store entry.
 * Called from setAddTextMode and the pointer-up dispatcher when the
 * gesture is abandoned.
 */

import {
    textCreationState,
    setTextCreationState,
} from '../store/interaction-state.js';

export function cancelTextCreationPreview() {
    if (textCreationState.previewEl) {
        textCreationState.previewEl.remove();
    }
    setTextCreationState({ active: false, pi: null, pointerId: null, startX: 0, startY: 0, rect: null, previewEl: null, moved: false });
}
