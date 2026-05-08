// CSS color helpers — pure, no DOM.

import { clamp01 } from './math.js';

/**
 * Coerce `value` to a canonical 6-digit lowercase hex (e.g. "#0f172a"),
 * expanding 3-digit shorthand. Returns `fallback` when the input is not a
 * valid hex color string.
 */
export const normalizeHexColor = (value, fallback) => {
    const raw = String(value || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(raw)) return raw.toLowerCase();
    if (/^#[0-9a-f]{3}$/i.test(raw)) {
        const [, r, g, b] = raw;
        return `#${r}${r}${g}${g}${b}${b}`.toLowerCase();
    }
    return fallback;
};

/**
 * Convert a hex color + opacity in [0, 1] to an `rgba(r, g, b, a)` string
 * suitable for direct use as a CSS color value. Bad input falls back to
 * black at the supplied opacity (or fully opaque when opacity is invalid).
 */
export const hexToRgbaString = (hex, opacity) => {
    const normalized = normalizeHexColor(hex, '#000000');
    const safeOpacity = clamp01(opacity, 1);
    const intValue = parseInt(normalized.slice(1), 16);
    const r = (intValue >> 16) & 255;
    const g = (intValue >> 8) & 255;
    const b = intValue & 255;
    return `rgba(${r}, ${g}, ${b}, ${safeOpacity})`;
};
