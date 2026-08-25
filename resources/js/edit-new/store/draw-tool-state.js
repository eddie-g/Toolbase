// Phase 5h store slice: direct-draw tool config + the in-flight stroke
// session. Reads use the bare identifier via ES module live binding;
// writes route through the per-state setter.

import { normalizeDirectDrawTool } from '../draw/tool-type.js';
import { DEFAULT_DRAW_SMOOTHING, normalizeDrawSmoothing } from '../draw/smooth-curve.js';

export let drawModeActive = false;
export let drawToolType = 'pen';
export let drawStrokeColor = '#111827';
export let drawOpacity = 1;
export let drawBrushSize = 10;
export let drawSmoothing = DEFAULT_DRAW_SMOOTHING;
export let activeDrawSession = null;

export function setDrawModeActive(value) { drawModeActive = !!value; }
export function setDrawToolType(value) { drawToolType = normalizeDirectDrawTool(value); }
export function setDrawStrokeColor(value) { drawStrokeColor = value; }
export function setDrawOpacity(value) { drawOpacity = Number.isFinite(Number(value)) ? Number(value) : 1; }
export function setDrawBrushSize(value) { drawBrushSize = Math.max(2, Number(value) || 10); }
export function setDrawSmoothing(value) { drawSmoothing = normalizeDrawSmoothing(value); }
export function setActiveDrawSession(value) { activeDrawSession = value; }
