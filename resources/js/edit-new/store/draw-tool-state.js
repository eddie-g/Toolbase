// Phase 5h store slice: direct-draw tool config + the in-flight stroke
// session. Reads use the bare identifier via ES module live binding;
// writes route through the per-state setter.

export let drawModeActive = false;
export let drawToolType = 'pen';
export let drawStrokeColor = '#111827';
export let drawOpacity = 1;
export let drawBrushSize = 10;
export let activeDrawSession = null;

export function setDrawModeActive(value) { drawModeActive = !!value; }
export function setDrawToolType(value) { drawToolType = String(value || '') === 'eraser' ? 'eraser' : 'pen'; }
export function setDrawStrokeColor(value) { drawStrokeColor = value; }
export function setDrawOpacity(value) { drawOpacity = Number.isFinite(Number(value)) ? Number(value) : 1; }
export function setDrawBrushSize(value) { drawBrushSize = Math.max(2, Number(value) || 10); }
export function setActiveDrawSession(value) { activeDrawSession = value; }
