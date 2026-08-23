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
