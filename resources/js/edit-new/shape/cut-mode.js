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
