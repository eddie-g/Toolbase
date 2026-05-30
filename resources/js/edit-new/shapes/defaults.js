/*
 * currentShapeDefaults — snapshot the live shape-defaults store slice into
 * the plain-object shape used by shape annotations and the format-bar
 * inputs (Phase 7q). Pure: depends only on the store slice plus `clamp01`.
 */

import {
    currentShapeType,
    currentShapeStrokeColor,
    currentShapeStrokeOpacity,
    currentShapeStrokeWidth,
    currentShapeStrokeTransparent,
    currentShapeFillColor,
    currentShapeFillOpacity,
    currentShapeFillTransparent,
} from '../store/shape-defaults.js';
import { clamp01 } from '../util/math.js';

export function currentShapeDefaults() {
    const strokeWidth = Number(currentShapeStrokeWidth);
    return {
        shapeType: currentShapeType,
        strokeColor: currentShapeStrokeColor,
        strokeOpacity: clamp01(currentShapeStrokeOpacity, 1),
        strokeWidth: Math.max(0, Number.isFinite(strokeWidth) ? strokeWidth : 3),
        strokeTransparent: Boolean(currentShapeStrokeTransparent),
        fillColor: currentShapeFillColor,
        fillOpacity: clamp01(currentShapeFillOpacity, 0.22),
        fillTransparent: Boolean(currentShapeFillTransparent),
    };
}
