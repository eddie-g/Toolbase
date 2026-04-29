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
import { paintSmoothStroke } from '../draw/smooth-stroke.js';
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

/**
 * Repaint the signature composer canvas: clear it, then paint every
 * persisted stroke followed by the in-progress one (if any). Defaults
 * to gray-900 / 3px (the signature tool baseline). Pure DOM-write
 * helper depending on the signature store + the smooth-stroke painter.
 */
export function renderDrawSignaturePreview(signatureCanvas, smoothingInput) {
    if (!signatureCanvas || !signatureCtx) return;
    clearSignatureCanvas(signatureCanvas);
    const smoothing = getSignatureSmoothingAmount(smoothingInput);
    const paint = (stroke) => paintSmoothStroke(signatureCtx, stroke, smoothing, '#111827', 3);
    signatureStrokes.forEach(paint);
    if (signatureActiveStroke) paint(signatureActiveStroke);
}
