// Tiny pure helpers that bridge between the page's logical PDF scale and
// the underlying HiDPI canvas / DOM measurements. No editor state.

/**
 * Annotation TEXT scales with the page during zoom (uniform zoom: page +
 * text shrink/grow together). Helper kept as identity so all call sites
 * continue to compile; a previous experiment returned fit-scale here to
 * keep glyphs constant pixel-size, but uniform scaling is the desired UX.
 */
export function fontDisplayScale(scale) {
    return Number(scale) || 0;
}

/**
 * The logical (CSS-pixel) height of an overlay/page canvas. The HiDPI
 * setup stamps `__cssHeight` onto the canvas during sizing; fall back to
 * canvas.height (the backing-store height) when that hint is missing.
 */
export function canvasLogicalHeight(canvas) {
    if (!canvas) return 0;
    return canvas.__cssHeight ?? canvas.height;
}

/**
 * The logical (CSS-pixel) width of an overlay/page canvas. The HiDPI
 * setup stamps `__cssWidth` onto the canvas during sizing; fall back to
 * canvas.width (the backing-store width) when that hint is missing.
 */
export function canvasLogicalWidth(canvas) {
    if (!canvas) return 0;
    return canvas.__cssWidth ?? canvas.width;
}
