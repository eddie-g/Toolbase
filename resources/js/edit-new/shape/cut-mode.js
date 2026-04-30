/*
 * shape/cut-mode (Phase 7ay).
 *
 * Pure predicates + state-only helpers for the polygon-cut mode UX
 * (drag a line through a selected shape to slice it into two
 * polygons). The actual cut math lives in cutShapeAnnotation in
 * main.js because it touches editedTexts / pendingDeleted* /
 * pushUndo / hoverState / selectAnnotation — too many cross-cutting
 * deps to extract in one go.
 */

import {
    isShapeAnnotation,
    isLineShape,
    isAnnotationLocked,
} from '../annotations/types.js';
import { normalizeShapeType } from '../annotations/shape-geometry.js';
import { shapeCutState, setShapeCutState } from '../store/interaction-state.js';

const CUTTABLE_SHAPE_TYPES = new Set(['square', 'circle', 'triangle', 'star', 'polygon']);

export function canCutShapeAnnotation(ann) {
    if (!ann || !isShapeAnnotation(ann)) return false;
    if (isAnnotationLocked(ann) || isLineShape(ann)) return false;
    return CUTTABLE_SHAPE_TYPES.has(normalizeShapeType(ann.shapeType));
}

export function isShapeCutModeArmedFor(ann, pi) {
    return Boolean(
        shapeCutState.armed
        && shapeCutState.pi === pi
        && shapeCutState.uid
        && ann
        && shapeCutState.uid === ann._uid
    );
}

export function resetShapeCutState() {
    setShapeCutState({ armed: false, pi: null, uid: null, pointerId: null, startPt: null, currentPt: null });
}

export function armShapeCutState(ann, pi) {
    if (!canCutShapeAnnotation(ann)) return false;
    setShapeCutState({
        armed: true,
        pi,
        uid: ann._uid,
        pointerId: null,
        startPt: null,
        currentPt: null,
    });
    return true;
}

/**
 * Tear down armed cut mode (Phase 7cl).
 *
 * Resets the cut-mode store, optionally redraws the page that was
 * armed, and runs the cursor / shape-panel sync callbacks so the
 * UI stops advertising the cut affordance.
 */
export function cancelShapeCutMode({ redraw = true } = {}, callbacks = {}) {
    const prevPi = shapeCutState.pi;
    resetShapeCutState();
    if (redraw && prevPi !== null && typeof callbacks.redrawOverlay === 'function') {
        callbacks.redrawOverlay(prevPi);
    }
    if (typeof callbacks.syncCanvasCursors === 'function') callbacks.syncCanvasCursors();
    if (typeof callbacks.syncShapePanelUi === 'function') callbacks.syncShapePanelUi();
}

/**
 * Arm cut mode for a specific annotation on a page (Phase 7cl).
 * No-op when the annotation is not cuttable.
 */
export function armShapeCutMode(ann, pi, callbacks = {}) {
    if (!armShapeCutState(ann, pi)) return;
    if (typeof callbacks.syncCanvasCursors === 'function') callbacks.syncCanvasCursors();
    if (typeof callbacks.syncShapePanelUi === 'function') callbacks.syncShapePanelUi();
    if (typeof callbacks.redrawOverlay === 'function') callbacks.redrawOverlay(pi);
}

/**
 * Toggle armed-state for the supplied annotation (Phase 7cl).
 */
export function toggleShapeCutMode(ann, pi, callbacks = {}) {
    if (isShapeCutModeArmedFor(ann, pi)) {
        cancelShapeCutMode({ redraw: true }, callbacks);
        return;
    }
    armShapeCutMode(ann, pi, callbacks);
}
