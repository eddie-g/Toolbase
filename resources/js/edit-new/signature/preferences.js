/*
 * signature/preferences (NK_Dev_5).
 *
 * The composer used to reset every control to its factory default each time
 * the modal opened, so a user who preferred a heavier stroke or a particular
 * script face had to re-pick it on every signature. These preferences remember
 * the last tab and the chosen settings per browser.
 *
 * Only SETTINGS are remembered — never the drawn or typed content, which must
 * still start clean so the previous signature is not silently reused.
 */

import { safeLocalStorageGet, safeLocalStorageSet } from '../util/storage.js';

const PREFS_KEY = 'edit_new_signature_prefs_v1';

export const SIGNATURE_PREF_DEFAULTS = {
    mode: 'draw',
    savedView: false,
    color: '#111827',
    typeColor: '#111827',
    width: '3',
    smoothing: '58',
    typeSize: '136',
    font: 'Great Vibes',
    accountViewMode: 'grid',
};

const MODES = ['draw', 'type', 'upload'];

/** Read stored preferences, falling back to the documented defaults. */
export function readSignaturePreferences() {
    const raw = safeLocalStorageGet(PREFS_KEY);
    if (!raw) return { ...SIGNATURE_PREF_DEFAULTS };

    let parsed = null;
    try {
        parsed = JSON.parse(raw);
    } catch (_) {
        return { ...SIGNATURE_PREF_DEFAULTS };
    }
    if (!parsed || typeof parsed !== 'object') return { ...SIGNATURE_PREF_DEFAULTS };

    const prefs = { ...SIGNATURE_PREF_DEFAULTS };
    if (MODES.includes(String(parsed.mode))) prefs.mode = String(parsed.mode);
    prefs.savedView = parsed.savedView === true;
    if (parsed.accountViewMode === 'row' || parsed.accountViewMode === 'grid') {
        prefs.accountViewMode = parsed.accountViewMode;
    }
    for (const key of ['color', 'typeColor', 'width', 'smoothing', 'typeSize', 'font']) {
        if (typeof parsed[key] === 'string' && parsed[key] !== '') prefs[key] = parsed[key];
    }
    return prefs;
}

/** Merge and persist. Returns the stored object so callers can keep a copy. */
export function writeSignaturePreferences(current, patch) {
    const next = { ...SIGNATURE_PREF_DEFAULTS, ...(current || {}), ...(patch || {}) };
    safeLocalStorageSet(PREFS_KEY, JSON.stringify(next));
    return next;
}

/**
 * Background the composer canvas should use for a given ink colour.
 *
 * A black signature on a white canvas gives no sense of where the mark ends,
 * and a pale signature on white is invisible. Both cases are the same problem —
 * contrast — so the canvas is tinted from the ink's luminance: dark ink gets a
 * grey canvas, light ink gets a dark one.
 *
 * This is a CSS background on the element only. The canvas BITMAP stays
 * transparent, so trimming and the exported PNG are unaffected.
 *
 * @param {string} inkColor hex colour such as "#111827"
 * @returns {{background: string, tone: 'grey'|'dark'}}
 */
export function canvasBackdropForInk(inkColor) {
    const hex = String(inkColor || '').trim().replace('#', '');
    const full = hex.length === 3
        ? hex.split('').map((char) => char + char).join('')
        : hex;

    let luminance = 0;
    if (/^[0-9a-f]{6}$/i.test(full)) {
        const r = parseInt(full.slice(0, 2), 16) / 255;
        const g = parseInt(full.slice(2, 4), 16) / 255;
        const b = parseInt(full.slice(4, 6), 16) / 255;
        // Rec. 709 luma: close enough to perceived brightness for this.
        luminance = (0.2126 * r) + (0.7152 * g) + (0.0722 * b);
    }

    return luminance < 0.5
        ? { background: '#d8dee9', tone: 'grey' }
        : { background: '#3f4756', tone: 'dark' };
}
