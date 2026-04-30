/*
 * syncDrawToolPanelUi — push the draw-tool store slice + edit-mode
 * flag into the draw-tool panel DOM (Phase 7ca). Pure DOM writer;
 * takes the panel + control DOM refs as a single params object so
 * main.js keeps owning the lookups.
 */

import { normalizeHexColor } from '../util/color.js';
import { editModeEnabled } from '../store/editor-modes.js';
import {
    drawModeActive,
    drawToolType,
    drawStrokeColor,
    drawBrushSize,
    drawOpacity,
} from '../store/draw-tool-state.js';
import { setDrawToolStatus } from './session.js';

export function syncDrawToolPanelUi(refs) {
    if (!refs) return;
    const {
        drawToolPanel,
        drawToolButtons,
        drawColorSwatches,
        drawToolColorInput,
        drawToolSizeInput,
        drawToolSizeValue,
        drawToolOpacityInput,
        drawToolOpacityValue,
        drawToolStatus,
    } = refs;
    if (drawToolPanel) {
        drawToolPanel.classList.toggle('is-visible', !editModeEnabled && drawModeActive);
        drawToolPanel.setAttribute('aria-hidden', !editModeEnabled && drawModeActive ? 'false' : 'true');
    }
    if (drawToolButtons) {
        drawToolButtons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.drawDirectTool === drawToolType);
        });
    }
    if (drawColorSwatches) {
        drawColorSwatches.forEach((button) => {
            button.classList.toggle('is-active', normalizeHexColor(button.dataset.drawColor, '') === normalizeHexColor(drawStrokeColor, '#111827'));
        });
    }
    if (drawToolColorInput) drawToolColorInput.value = normalizeHexColor(drawStrokeColor, '#111827');
    if (drawToolSizeInput) drawToolSizeInput.value = String(drawBrushSize);
    if (drawToolSizeValue) drawToolSizeValue.textContent = `${Math.round(drawBrushSize)}px`;
    if (drawToolOpacityInput) drawToolOpacityInput.value = String(Math.round(drawOpacity * 100));
    if (drawToolOpacityValue) drawToolOpacityValue.textContent = `${Math.round(drawOpacity * 100)}%`;
    if (drawToolOpacityInput) drawToolOpacityInput.disabled = drawToolType === 'eraser';
    setDrawToolStatus(
        drawToolStatus,
        drawToolType === 'eraser'
            ? 'Eraser is active. Drag over a drawing to remove parts of it.'
            : 'Pen mode is active. Drag directly on the page to draw.',
    );
}
