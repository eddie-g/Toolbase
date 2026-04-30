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
import { paintSmoothStroke } from '../draw/smooth-stroke.js';
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

/**
 * Repaint the markup-tool composer canvas: clear it, then paint every
 * persisted stroke followed by the in-progress one (if any). Each stroke
 * carries its own color/width; defaults match the markup-tool tool's
 * baseline (slate-900, 4px). Pure DOM-write helper depending on the
 * markup-tool store + the smooth-stroke painter.
 */
export function renderMarkupToolDrawPreview(markupToolCanvas, smoothingInput) {
    if (!markupToolCanvas || !markupToolCtx) return;
    clearMarkupToolCanvas(markupToolCanvas);
    const smoothing = getMarkupToolSmoothingAmount(smoothingInput);
    const paint = (stroke) => paintSmoothStroke(markupToolCtx, stroke, smoothing, '#0f172a', 4);
    markupToolStrokes.forEach(paint);
    if (markupToolActiveStroke) paint(markupToolActiveStroke);
}

/*
 * updateMarkupToolUi — pure DOM writer for the markup-tool modal
 * (Phase 7cc). Reads markupToolMode from the store and refreshes
 * tabs / panels / hint / clear+apply buttons / canvas cursor;
 * delegates the canvas repaint + status text to the existing
 * renderMarkupToolDrawPreview / setMarkupToolStatus helpers in
 * this module.
 */
export function updateMarkupToolUi(refs) {
    if (!refs) return;
    const {
        markupToolTabs,
        markupToolPanels,
        markupToolHint,
        markupToolClearBtn,
        markupToolApplyBtn,
        markupToolCanvas,
        markupToolSmoothingInput,
        markupToolStatus,
    } = refs;
    if (markupToolTabs) {
        markupToolTabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.markupToolMode === markupToolMode);
        });
    }
    if (markupToolPanels) {
        markupToolPanels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.markupToolPanel === markupToolMode);
        });
    }
    if (markupToolHint) {
        markupToolHint.textContent = markupToolMode === 'draw'
            ? 'Draw mode: click and drag to create a mark.'
            : 'Erase mode: close the modal, then click any annotation to remove it.';
    }
    if (markupToolClearBtn) {
        markupToolClearBtn.disabled = markupToolMode !== 'draw' || !hasMarkupToolDrawContent();
        markupToolClearBtn.hidden = markupToolMode !== 'draw';
    }
    if (markupToolApplyBtn) {
        markupToolApplyBtn.textContent = markupToolMode === 'draw' ? 'Place drawing' : 'Start erasing';
    }
    if (markupToolCanvas) markupToolCanvas.style.cursor = markupToolMode === 'draw' ? 'crosshair' : 'default';

    if (markupToolMode === 'draw') {
        renderMarkupToolDrawPreview(markupToolCanvas, markupToolSmoothingInput);
        const hasContent = hasMarkupToolDrawContent();
        setMarkupToolDirtyState(markupToolApplyBtn, hasContent);
        setMarkupToolStatus(
            markupToolStatus,
            hasContent ? 'Drawing ready to place.' : 'Draw a mark, then place it on the page.',
            hasContent ? 'ready' : 'default',
        );
        return;
    }

    setMarkupToolDirtyState(markupToolApplyBtn, true);
    setMarkupToolStatus(markupToolStatus, 'Erase mode is ready. Close this modal and click annotations on the page to remove them.', 'ready');
}
