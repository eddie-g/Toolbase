/*
 * reflectShapeStateToInputs — push a shape state object into the
 * shape inspector DOM controls and the shape-defaults store slice
 * (Phase 7by). Pure DOM writer otherwise; no closure-local DOM refs.
 *
 * Refs are passed as an object so the caller (main.js) keeps owning
 * the DOM lookups; the helper only mutates store + DOM.
 */

import { clamp01 } from '../util/math.js';
import { normalizeHexColor } from '../util/color.js';
import {
    currentShapeType,
    currentShapeStrokeColor,
    currentShapeStrokeOpacity,
    currentShapeStrokeWidth,
    currentShapeFillColor,
    currentShapeFillOpacity,
    setCurrentShapeType,
    setCurrentShapeStrokeColor,
    setCurrentShapeStrokeOpacity,
    setCurrentShapeStrokeWidth,
    setCurrentShapeStrokeTransparent,
    setCurrentShapeFillColor,
    setCurrentShapeFillOpacity,
    setCurrentShapeFillTransparent,
} from '../store/shape-defaults.js';
import { normalizeShapeType } from '../annotations/shape-geometry.js';
import { currentShapeDefaults } from './defaults.js';

export function reflectShapeStateToInputs(shapeState, refs) {
    if (!shapeState || !refs) return;
    const strokeWidth = Number(shapeState.strokeWidth);
    setCurrentShapeType(normalizeShapeType(shapeState.shapeType));
    setCurrentShapeStrokeColor(normalizeHexColor(shapeState.strokeColor, currentShapeStrokeColor));
    setCurrentShapeStrokeOpacity(clamp01(shapeState.strokeOpacity, currentShapeStrokeOpacity));
    setCurrentShapeStrokeWidth(Math.max(0, Number.isFinite(strokeWidth) ? strokeWidth : currentShapeStrokeWidth));
    setCurrentShapeStrokeTransparent(Boolean(shapeState.strokeTransparent));
    setCurrentShapeFillColor(normalizeHexColor(shapeState.fillColor, currentShapeFillColor));
    setCurrentShapeFillOpacity(clamp01(shapeState.fillOpacity, currentShapeFillOpacity));
    setCurrentShapeFillTransparent(Boolean(shapeState.fillTransparent));

    const {
        shapeStrokeColorInput,
        shapeStrokeHexInput,
        shapeStrokeTransparentInput,
        shapeStrokeWidthInput,
        shapeStrokeWidthValue,
        shapeStrokeOpacityInput,
        shapeStrokeOpacityValue,
        shapeFillColorInput,
        shapeFillHexInput,
        shapeFillTransparentInput,
        shapeFillOpacityInput,
        shapeFillOpacityValue,
        shapeTypeButtons,
    } = refs;

    if (shapeStrokeColorInput) shapeStrokeColorInput.value = currentShapeStrokeColor;
    if (shapeStrokeHexInput) shapeStrokeHexInput.value = currentShapeStrokeColor;
    if (shapeStrokeTransparentInput) shapeStrokeTransparentInput.checked = Boolean(shapeState.strokeTransparent);
    if (shapeStrokeWidthInput) shapeStrokeWidthInput.value = String(currentShapeStrokeWidth);
    if (shapeStrokeWidthValue) shapeStrokeWidthValue.textContent = `${Math.round(currentShapeStrokeWidth)} px`;
    if (shapeStrokeOpacityInput) shapeStrokeOpacityInput.value = String(Math.round(currentShapeStrokeOpacity * 100));
    if (shapeStrokeOpacityValue) shapeStrokeOpacityValue.textContent = `${Math.round(currentShapeStrokeOpacity * 100)}%`;
    if (shapeFillColorInput) shapeFillColorInput.value = currentShapeFillColor;
    if (shapeFillHexInput) shapeFillHexInput.value = currentShapeFillColor;
    if (shapeFillTransparentInput) shapeFillTransparentInput.checked = Boolean(shapeState.fillTransparent);
    if (shapeFillOpacityInput) shapeFillOpacityInput.value = String(Math.round(currentShapeFillOpacity * 100));
    if (shapeFillOpacityValue) shapeFillOpacityValue.textContent = `${Math.round(currentShapeFillOpacity * 100)}%`;
    if (shapeTypeButtons) {
        shapeTypeButtons.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.shapeTool === currentShapeType);
        });
    }
}

export function readShapeInspectorState(refs) {
    if (!refs) return currentShapeDefaults();
    const {
        shapeStrokeColorInput,
        shapeStrokeHexInput,
        shapeStrokeOpacityInput,
        shapeStrokeWidthInput,
        shapeStrokeTransparentInput,
        shapeFillColorInput,
        shapeFillHexInput,
        shapeFillOpacityInput,
        shapeFillTransparentInput,
    } = refs;
    const strokeWidth = Number(shapeStrokeWidthInput?.value);
    const state = {
        shapeType: currentShapeType,
        strokeColor: normalizeHexColor(shapeStrokeColorInput?.value || shapeStrokeHexInput?.value, currentShapeStrokeColor),
        strokeOpacity: clamp01((Number(shapeStrokeOpacityInput?.value) || 0) / 100, currentShapeStrokeOpacity),
        strokeWidth: Math.max(0, Number.isFinite(strokeWidth) ? strokeWidth : currentShapeStrokeWidth),
        strokeTransparent: Boolean(shapeStrokeTransparentInput?.checked),
        fillColor: normalizeHexColor(shapeFillColorInput?.value || shapeFillHexInput?.value, currentShapeFillColor),
        fillOpacity: clamp01((Number(shapeFillOpacityInput?.value) || 0) / 100, currentShapeFillOpacity),
        fillTransparent: Boolean(shapeFillTransparentInput?.checked),
    };
    reflectShapeStateToInputs(state, refs);
    return currentShapeDefaults();
}

/**
 * A shape can retain a transparent-fill flag even while the inspector shows a
 * color and a non-zero opacity. Since the transparency checkbox is no longer a
 * visible control, editing either visible fill control means the user is
 * explicitly turning the fill back on.
 */
export function activateShapeFillFromInspector(refs) {
    const fillOpacity = Number(refs?.shapeFillOpacityInput?.value);
    if (!refs?.shapeFillTransparentInput || !(fillOpacity > 0)) return false;
    refs.shapeFillTransparentInput.checked = false;
    return true;
}
