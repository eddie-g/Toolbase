export const DIRECT_DRAW_TOOL_PEN = 'pen';
export const DIRECT_DRAW_TOOL_SMOOTH_CURVE = 'smooth-curve';
export const DIRECT_DRAW_TOOL_ERASER = 'eraser';

export function normalizeDirectDrawTool(value) {
    const tool = String(value || '').trim().toLowerCase();
    if (tool === DIRECT_DRAW_TOOL_ERASER) return DIRECT_DRAW_TOOL_ERASER;
    if (tool === DIRECT_DRAW_TOOL_SMOOTH_CURVE) return DIRECT_DRAW_TOOL_SMOOTH_CURVE;
    return DIRECT_DRAW_TOOL_PEN;
}

export function directDrawToolLabel(value) {
    const tool = normalizeDirectDrawTool(value);
    if (tool === DIRECT_DRAW_TOOL_ERASER) return 'Eraser';
    if (tool === DIRECT_DRAW_TOOL_SMOOTH_CURVE) return 'Smooth curve';
    return 'Pen';
}
