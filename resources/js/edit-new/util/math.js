// Numeric helpers — pure, no DOM, safe to import from anywhere.

/**
 * Coerce `value` to a finite number and clamp it to the [0, 1] range.
 * Returns `fallback` when the input cannot be parsed as a number.
 */
export const clamp01 = (value, fallback = 0) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return fallback;
    return Math.max(0, Math.min(1, numeric));
};
