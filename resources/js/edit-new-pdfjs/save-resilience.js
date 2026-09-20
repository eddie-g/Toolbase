// What keeps edits from being lost: knowing when there are unsaved changes,
// what to do after a failed save (retry, wait for the network, stop, ask the
// user to sign in), flushing on the way out, and a local draft that survives a
// crash or a closed tab.

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

/**
 * Dirty means a change happened after the state the server last accepted.
 * A save that started before the latest change does not clear it.
 */
export function createDirtyTracker() {
    let changes = 0;
    let saved = 0;

    return {
        markChanged() { changes += 1; },
        /** Call when a save starts; pass the token to completeSave() when it succeeds. */
        beginSave() { return changes; },
        completeSave(token) { if (Number.isInteger(token) && token > saved) saved = token; },
        get isDirty() { return changes !== saved; },
    };
}

/** 2 s, 4 s, 8 s ... capped at a minute. */
export function retryDelayMs(attempt) {
    return Math.min(60000, 2000 * 2 ** Math.max(0, Math.min(10, attempt)));
}

/**
 * What the editor does after a save failed. `kind` comes from
 * classifySaveFailure() (autosave-guard.js), or is "network" when the request
 * itself failed.
 *
 *   retry        try again after delayMs (5xx, network)
 *   wait_online  the browser is offline; try again on the "online" event
 *   halt         retrying cannot help (413, 422); autosave stops until a manual save works
 *   session      signed out; ask the user to sign in, keep the work here
 *   stale        another tab saved a newer state; handled by the autosave guard
 */
export function planAfterSaveFailure(kind, { attempt = 0, online = true, message = '' } = {}) {
    if (kind === 'stale') return { action: 'stale', sticky: true, message };
    if (kind === 'session') {
        return { action: 'session', sticky: true, message: 'You have been signed out. Sign in again in a new tab and your work here will be saved.' };
    }
    if (kind === 'too_large' || kind === 'invalid') {
        return { action: 'halt', sticky: true, message: `${cleanText(message, 'These changes could not be saved.')} Autosave is paused until a save succeeds.` };
    }
    if (kind === 'throttled') return { action: 'retry', sticky: false, delayMs: 15000, message };
    if (!online) {
        return { action: 'wait_online', sticky: true, message: 'You are offline. Your changes are kept here and will be saved when the connection returns.' };
    }
    const delayMs = retryDelayMs(attempt);
    return {
        action: 'retry',
        sticky: true,
        delayMs,
        message: `Not saved. Trying again in ${Math.round(delayMs / 1000)} seconds; your changes are kept here.`,
    };
}

/**
 * fetch(..., { keepalive: true }) and sendBeacon() survive the page closing,
 * but the browser refuses bodies over 64 KiB (shared with other in-flight
 * keepalive requests, hence the margin).
 */
export function fitsKeepalive(body) {
    if (typeof body !== 'string') return false;
    const bytes = typeof TextEncoder === 'function' ? new TextEncoder().encode(body).length : body.length * 3;
    return bytes <= 60000;
}

const DRAFT_MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000;

/**
 * Whether a local draft should be offered on load. It must be this document
 * and editing session, recent, and written against the state the server still
 * has: if the server moved on, restoring would overwrite someone's newer save.
 */
export function draftIsRestorable(draft, { documentId, sessionId, stateVersion, now = Date.now() } = {}) {
    if (!draft || typeof draft !== 'object' || !Array.isArray(draft.annotations)) return false;
    if (String(draft.documentId) !== String(documentId)) return false;
    if (cleanText(draft.sessionId) !== cleanText(sessionId)) return false;
    const savedAt = Number(draft.savedAt);
    if (!Number.isFinite(savedAt) || now - savedAt > DRAFT_MAX_AGE_MS || savedAt > now + 60000) return false;
    const draftVersion = draft.baseVersion ?? null;
    const serverVersion = stateVersion ?? null;
    return draftVersion === serverVersion;
}

/** "14:32" today, otherwise "12 Sep, 14:32". */
export function describeDraftTime(savedAt, now = Date.now(), locale = undefined) {
    const when = new Date(savedAt);
    const time = when.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
    if (new Date(now).toDateString() === when.toDateString()) return time;
    return `${when.toLocaleDateString(locale, { day: 'numeric', month: 'short' })}, ${time}`;
}

/**
 * Drafts in IndexedDB, one per document. Every method resolves (never
 * rejects): private windows and locked-down browsers have no IndexedDB, and
 * the editor must work the same there, just without recovery.
 */
export function createDraftStore({ indexedDB = globalThis.indexedDB, name = 'netkit-editor-drafts' } = {}) {
    const STORE = 'drafts';
    let opening = null;

    function open() {
        if (!indexedDB) return Promise.resolve(null);
        if (!opening) {
            opening = new Promise((resolve) => {
                let request;
                try {
                    request = indexedDB.open(name, 1);
                } catch (_) {
                    resolve(null);
                    return;
                }
                request.onupgradeneeded = () => {
                    if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE);
                };
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => resolve(null);
                request.onblocked = () => resolve(null);
            });
        }
        return opening;
    }

    function run(mode, work) {
        return open().then((db) => new Promise((resolve) => {
            if (!db) {
                resolve(null);
                return;
            }
            try {
                const transaction = db.transaction(STORE, mode);
                const request = work(transaction.objectStore(STORE));
                transaction.oncomplete = () => resolve(request?.result ?? null);
                transaction.onerror = () => resolve(null);
                transaction.onabort = () => resolve(null);
            } catch (_) {
                resolve(null);
            }
        }));
    }

    return {
        put(documentId, draft) { return run('readwrite', (store) => store.put(draft, String(documentId))); },
        get(documentId) { return run('readonly', (store) => store.get(String(documentId))); },
        remove(documentId) { return run('readwrite', (store) => store.delete(String(documentId))); },
    };
}
