/*
 * Edit-New PDF editor — server-injected configuration.
 *
 * Reads values from the `#edit-new-root` element's data-* attributes
 * (set by the blade view from Laravel route() / model fields). Every
 * future edit-new module imports its URLs and identifiers from here
 * instead of re-reading the dataset, so server-side coupling lives in
 * exactly one file.
 */

const root = document.getElementById('edit-new-root');
if (!root) {
    throw new Error('edit-new-root element missing');
}
const cfg = root.dataset;

export let CSRF =
    document.querySelector('meta[name="csrf-token"]')?.content || cfg.csrf || '';

// The token above dies with the session (SESSION_LIFETIME minutes of
// inactivity), after which every POST from a still-open tab 419s and only
// a reload — losing unsaved work — recovers (NK_22). `CSRF` is a live
// binding: reassigning it here updates every importer, provided they read
// it at request time rather than baking it into a module-level constant.
// The session is kept warm with a periodic ping plus a refresh on tab
// refocus (timers don't fire while a laptop sleeps); persistence/api.js
// additionally replays a request that still catches a 419.
export const CSRF_URL = cfg.csrfUrl || '/csrf-token';
const CSRF_KEEP_ALIVE_MS = 10 * 60 * 1000;

export async function refreshCsrf() {
    const resp = await fetch(CSRF_URL, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const data = await resp.json().catch(() => ({}));
    if (data && data.token) {
        CSRF = data.token;
        // signature/account-library.js re-reads the meta tag per request.
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = CSRF;
    }
    return CSRF;
}

setInterval(() => { refreshCsrf().catch(() => {}); }, CSRF_KEEP_ALIVE_MS);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshCsrf().catch(() => {});
});

export const INFO_URL = cfg.infoUrl;
export const SAVE_URL = cfg.saveUrl;
export const DOWNLOAD_URL = cfg.downloadUrl;
export const DOC_ID = cfg.docId;
