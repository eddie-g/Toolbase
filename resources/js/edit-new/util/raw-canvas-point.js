/*
 * util/raw-canvas-point (Phase 7ba).
 *
 * Convert a pointer event to coordinates in a canvas's native
 * (canvas.width / canvas.height) backing-store space, scaling for
 * any CSS resize. Used by the in-modal signature + markup-tool
 * canvases where we draw directly at the canvas's intrinsic
 * resolution. Distinct from canvasPointFromEvent (./canvas-point.js)
 * which returns LOGICAL CSS-pixel coordinates for the HiDPI overlay
 * canvases.
 */

export function rawCanvasPointFromEvent(canvas, event) {
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return null;
    return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height),
    };
}
