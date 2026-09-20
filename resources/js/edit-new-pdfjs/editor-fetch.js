/*
 * The editor's own fetch. It used to patch window.fetch for every script on
 * the page (analytics, extensions, the Stripe loader) to get two behaviours
 * that only the editor's requests need:
 *
 *  - a request that catches a 419 because its CSRF token was refreshed a
 *    moment too late is replayed once with the new token (NK_22);
 *  - a 503 from a kill switch (code editor_disabled / export_disabled) is
 *    reported to the page once, so it can say so instead of "Save failed".
 *
 * window.fetch is left alone.
 */

export function createEditorFetch({ fetchImpl, getCsrf, refreshCsrf, onUnavailable } = {}) {
    const send = fetchImpl || ((input, init) => globalThis.fetch(input, init));

    async function notifyIfSwitchedOff(response) {
        if (response.status !== 503 || typeof onUnavailable !== 'function') return;
        try {
            const body = await response.clone().json();
            if (body && /_disabled$/.test(String(body.code || ''))) onUnavailable(String(body.code), String(body.message || ''));
        } catch (_) {
            // Not JSON: an ordinary 503 from the platform.
        }
    }

    return async function editorFetch(input, init) {
        const response = await send(input, init);
        if (response.status !== 419 || !init || !init.headers || typeof refreshCsrf !== 'function') {
            await notifyIfSwitchedOff(response);

            return response;
        }
        const headers = new Headers(init.headers);
        if (!headers.has('X-CSRF-TOKEN')) return response;
        try {
            await refreshCsrf();
        } catch (_) {
            return response;
        }
        headers.set('X-CSRF-TOKEN', (typeof getCsrf === 'function' ? getCsrf() : '') || '');
        const replayed = await send(input, { ...init, headers });
        await notifyIfSwitchedOff(replayed);

        return replayed;
    };
}

let configured = null;

/** Called once by the editor's entry point. */
export function configureEditorFetch(options) {
    configured = createEditorFetch(options);

    return configured;
}

/** For the editor's modules: the configured fetch, or the plain one before (or without) the editor. */
export function editorFetch(input, init) {
    return configured ? configured(input, init) : globalThis.fetch(input, init);
}
