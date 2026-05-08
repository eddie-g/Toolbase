/*
 * Pure AcroForm field/widget normalization helpers (Phase 7v).
 *
 * These four helpers operate on plain entry/annotation objects and
 * have no runtime state deps. The remaining AcroForm pipeline
 * (normalizeAcroWidget, ensureAcroPdfLoaded, ensureAcroWidgetsForPage,
 * updateAcroEntry, drawAcroOverlay) still lives in main.js because it
 * is wired to the live acroFieldLookup / acroFormEntries state.
 */

export function buildAcroFieldLookup(entries) {
    const lookup = {};
    (Array.isArray(entries) ? entries : []).forEach((entry) => {
        const keys = [
            String(entry?.key || '').trim(),
            String(entry?.fieldName || '').trim(),
        ].filter(Boolean);
        keys.forEach((key) => { lookup[key] = entry; });
    });
    return lookup;
}

export function normalizeAcroRect(rectLike) {
    if (!Array.isArray(rectLike) || rectLike.length < 4) return null;
    const rect = rectLike.slice(0, 4).map((value) => Number(value));
    return rect.every((value) => Number.isFinite(value)) ? rect : null;
}

export function normalizeAcroTextColor(colorLike) {
    if (typeof colorLike === 'string') {
        const value = colorLike.trim();
        if (!value) return null;
        if (/^#[0-9a-f]{6}$/i.test(value)) return value.toLowerCase();
        if (/^[0-9a-f]{6}$/i.test(value)) return `#${value.toLowerCase()}`;
        return null;
    }

    if (Array.isArray(colorLike) && colorLike.length > 0) {
        const values = colorLike.slice(0, 3).map((value) => Number(value));
        if (!values.every((value) => Number.isFinite(value))) return null;
        const rgb = values.map((value) => (
            value <= 1
                ? Math.max(0, Math.min(255, Math.round(value * 255)))
                : Math.max(0, Math.min(255, Math.round(value)))
        ));
        while (rgb.length < 3) rgb.push(rgb[0]);
        return `#${rgb.map((value) => value.toString(16).padStart(2, '0')).join('')}`;
    }

    return null;
}

export function acroFieldKey(annotation) {
    return String(annotation?.fieldName || annotation?.id || annotation?.fullName || '').trim();
}
