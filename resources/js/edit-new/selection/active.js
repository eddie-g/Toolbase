/*
 * Active-selection predicates (Phase 7h).
 *
 * Tiny derived helpers that read `activeState` + `pageData` and report
 * what kind of annotation (if any) is currently selected. Extracted out
 * of main.js so the interaction modules (drag/resize/rotate) can import
 * them directly instead of receiving them via `configure*Interactions`.
 */

import { activeState } from '../store/active-state.js';
import { pageData } from '../store/page-data.js';
import { isBoxAnnotation, isShapeAnnotation } from '../annotations/types.js';

function activeAnnotation() {
    const data = activeState.pi !== null ? pageData[activeState.pi] : null;
    if (!data) return null;
    return data.annotations.find((item) => item._uid === activeState.uid) || null;
}

export function hasActiveBoxSelection() {
    const ann = activeAnnotation();
    return Boolean(ann && isBoxAnnotation(ann));
}

export function hasActiveShapeSelection() {
    const ann = activeAnnotation();
    return Boolean(ann && isShapeAnnotation(ann));
}
