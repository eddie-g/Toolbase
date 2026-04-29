// Persistence layer for the saved-signature library that the signature modal
// renders. Owns the localStorage key, the entry-shape validation, and the
// per-browser entry cap. Pure data — no DOM, no rendering.

import { safeLocalStorageGet, safeLocalStorageSet } from '../util/storage.js';

const SIGNATURE_LIBRARY_KEY = 'edit_new_signature_library_v1';

/** Maximum number of saved signatures retained per browser. */
export const SIGNATURE_LIBRARY_LIMIT = 8;

const isValidEntry = (entry) =>
    !!entry
    && typeof entry === 'object'
    && entry.asset
    && typeof entry.asset === 'object';

/**
 * Read the saved-signature library from localStorage. Returns `[]` for the
 * empty / missing / malformed cases so callers can treat the result as a
 * trustworthy array.
 */
export const readSignatureLibrary = () => {
    const raw = safeLocalStorageGet(SIGNATURE_LIBRARY_KEY);
    if (!raw) return [];
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter(isValidEntry) : [];
    } catch (_error) {
        return [];
    }
};

/**
 * Persist the supplied entries to localStorage, replacing whatever is
 * currently stored. Returns true on success, false if the write failed
 * (e.g. quota exceeded or storage disabled).
 */
export const writeSignatureLibrary = (entries) =>
    safeLocalStorageSet(SIGNATURE_LIBRARY_KEY, JSON.stringify(entries || []));
