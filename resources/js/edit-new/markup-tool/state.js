/*
 * markup-tool/state (Phase 7at).
 *
 * Tiny status/canvas helpers for the per-page markup tool modal.
 * They depend on closure-local DOM refs in main.js (status, applyBtn,
 * canvas, smoothingInput) plus the markup-tool store; main.js re-binds
 * each of them with the right DOM refs so existing call sites stay
 * argument-free.
 */

import { clamp01 } from '../util/math.js';
import {
    markupToolCtx,
    markupToolMode,
    markupToolDirty,
    markupToolStrokes,
    markupToolActiveStroke,
    setMarkupToolDirty,
    setMarkupToolDrawing,
    setMarkupToolStrokes,
    setMarkupToolActiveStroke,
} from '../store/markup-tool-state.js';

export function setMarkupToolStatus(statusEl, message, tone = 'default') {
    if (!statusEl) return;
    statusEl.textContent = String(message || '');
    statusEl.classList.toggle('is-error', tone === 'error');
    statusEl.classList.toggle('is-ready', tone === 'ready');
}

export function setMarkupToolDirtyState(applyBtn, dirty) {
    setMarkupToolDirty(!!dirty);
    if (applyBtn) {
        applyBtn.disabled = markupToolMode === 'draw' ? !markupToolDirty : false;
    }
}

export function clearMarkupToolCanvas(canvas) {
    if (!markupToolCtx || !canvas) return;
    markupToolCtx.clearRect(0, 0, canvas.width, canvas.height);
    markupToolCtx.lineCap = 'round';
    markupToolCtx.lineJoin = 'round';
}

export function clearMarkupToolDrawingState(canvas) {
    setMarkupToolDrawing(false);
    setMarkupToolStrokes([]);
    setMarkupToolActiveStroke(null);
    clearMarkupToolCanvas(canvas);
}

export function getMarkupToolSmoothingAmount(smoothingInput) {
    return clamp01((Number(smoothingInput?.value) || 0) / 100, 0.58);
}

export function hasMarkupToolDrawContent() {
    return markupToolStrokes.some((stroke) => Array.isArray(stroke?.points) && stroke.points.length > 0)
        || (Array.isArray(markupToolActiveStroke?.points) && markupToolActiveStroke.points.length > 0);
}

export function syncMarkupToolLabels({
    markupToolColorValue,
    markupToolColorInput,
    markupToolWidthValue,
    markupToolWidthInput,
    markupToolSmoothingValue,
    markupToolSmoothingInput,
}) {
    if (markupToolColorValue && markupToolColorInput) markupToolColorValue.textContent = markupToolColorInput.value.toUpperCase();
    if (markupToolWidthValue && markupToolWidthInput) markupToolWidthValue.textContent = `${markupToolWidthInput.value}px`;
    if (markupToolSmoothingValue && markupToolSmoothingInput) markupToolSmoothingValue.textContent = `${markupToolSmoothingInput.value}%`;
}

export function buildCurrentMarkupAsset(markupToolCanvas) {
    if (!markupToolDirty || !markupToolCanvas) return null;
    return {
        dataUrl: markupToolCanvas.toDataURL('image/png'),
        width: markupToolCanvas.width,
        height: markupToolCanvas.height,
        fileName: 'drawing.png',
        mimeType: 'image/png',
        toolSource: 'draw-erase',
    };
}
