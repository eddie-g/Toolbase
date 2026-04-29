/*
 * HiDPI overlay sizing helpers (Phase 7p).
 *
 * Compute the canvas backing-store scale that combines the device's
 * pixel ratio with the user's CSS zoom level, so vector overlays stay
 * sharp at any zoom. Lifted out of main.js — depends only on the
 * `currentZoomPercent` slice.
 */

import { currentZoomPercent } from '../store/zoom-state.js';

export function overlayDevicePixelRatio() {
    const raw = Number(window.devicePixelRatio) || 1;
    // Clamp: < 1 is meaningless for sharpening; > 3 wastes memory without
    // visible benefit (a 2000×2600-pixel page at dpr=4 is 80 MP).
    if (!Number.isFinite(raw) || raw < 1) return 1;
    return Math.min(3, raw);
}

export function overlayZoomMultiplier() {
    const z = Number(currentZoomPercent);
    if (!Number.isFinite(z) || z <= 0) return 1;
    return z / 100;
}

export function overlayBackingScale() {
    return overlayDevicePixelRatio() * overlayZoomMultiplier();
}

export function sizeOverlayCanvas(canvas, cssW, cssH) {
    if (!canvas) return;
    const backing = overlayBackingScale();
    const w = Math.max(1, Math.round(cssW));
    const h = Math.max(1, Math.round(cssH));
    canvas.width = Math.max(1, Math.round(w * backing));
    canvas.height = Math.max(1, Math.round(h * backing));
    // Stash the FIT-scale logical (CSS) dimensions so drawing/hit-testing
    // code can keep working in fit-scale CSS pixel coords regardless of
    // the backing-store DPR or zoom.
    canvas.__cssWidth = w;
    canvas.__cssHeight = h;
    canvas.__backingScale = backing;
}
