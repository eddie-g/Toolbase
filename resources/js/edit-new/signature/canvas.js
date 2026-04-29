/*
 * signature/canvas (Phase 7av).
 *
 * Tiny canvas-state helpers for the in-modal signature draw surface.
 * Mirrors ./markup-tool/state.js. They depend on closure-local DOM
 * refs in main.js (signatureCanvas, signatureSmoothingInput); main.js
 * re-binds them as closure-bound wrappers so existing call sites stay
 * argument-free.
 */

import { clamp01 } from '../util/math.js';
import {
    signatureCtx,
    signatureStrokes,
    signatureActiveStroke,
    setSignatureDrawing,
    setSignatureStrokes,
    setSignatureActiveStroke,
} from '../store/signature-state.js';

export function clearSignatureCanvas(canvas) {
    if (!signatureCtx || !canvas) return;
    signatureCtx.clearRect(0, 0, canvas.width, canvas.height);
    signatureCtx.lineCap = 'round';
    signatureCtx.lineJoin = 'round';
}

export function clearSignatureDrawingState(canvas) {
    setSignatureDrawing(false);
    setSignatureStrokes([]);
    setSignatureActiveStroke(null);
    clearSignatureCanvas(canvas);
}

export function getSignatureSmoothingAmount(smoothingInput) {
    return clamp01((Number(smoothingInput?.value) || 0) / 100, 0.58);
}

export function hasSignatureDrawContent() {
    return signatureStrokes.some((stroke) => Array.isArray(stroke?.points) && stroke.points.length > 0)
        || (Array.isArray(signatureActiveStroke?.points) && signatureActiveStroke.points.length > 0);
}
