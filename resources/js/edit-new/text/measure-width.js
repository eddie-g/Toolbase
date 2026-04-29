/*
 * Canvas-backed text width measurement (Phase 7j).
 *
 * Lifted out of main.js so wrapping, layout, and other text helpers
 * can share the same measurement context without round-tripping
 * through configure(deps).
 */

import { measureCanvas, setMeasureCanvas } from '../store/misc-state.js';

function ensureMeasureCtx() {
    if (!(measureCanvas instanceof HTMLCanvasElement)) {
        setMeasureCanvas(document.createElement('canvas'));
    }
    return measureCanvas.getContext('2d');
}

export function ctxFont(style) {
    return `${style.fontStyle || 'normal'} ${style.fontWeight || '400'} ${Math.max(1, Number(style.fontSizePx) || 1)}px ${style.fontFamily}`;
}

export function measureTextWidth(text, style) {
    const ctx = ensureMeasureCtx();
    if (!ctx) return 0;
    ctx.font = ctxFont(style);
    return ctx.measureText(String(text || '')).width || 0;
}
