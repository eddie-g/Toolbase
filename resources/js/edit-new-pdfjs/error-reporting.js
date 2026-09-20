/*
 * What goes wrong in the editor, reported to POST /client-errors
 * (App\Http\Controllers\ClientErrorController): uncaught errors, unhandled
 * promise rejections, and failures the editor catches and carries on from
 * (reportCaughtError). Without this an exception in the browser was invisible.
 *
 * A report is a message, a stack and where it happened. Never document
 * content: no text, no annotation state, no file names.
 */

// Noise no one can act on: extensions, cross-origin scripts, a benign Chrome warning.
const IGNORED = [
    /^Script error\.?$/i,
    /ResizeObserver loop/i,
    /^(chrome|moz|safari(-web)?)-extension:\/\//i,
    /\bextension context invalidated\b/i,
];

export const MAX_REPORTS_PER_PAGE = 10;

export function isReportable(message, source = '') {
    const text = String(message || '').trim();
    if (!text) return false;

    return !IGNORED.some((pattern) => pattern.test(text) || pattern.test(String(source || '')));
}

function describe(reason) {
    if (reason instanceof Error) return { message: `${reason.name}: ${reason.message}`, stack: String(reason.stack || '') };
    if (reason && typeof reason === 'object') {
        const message = typeof reason.message === 'string' ? reason.message : Object.prototype.toString.call(reason);

        return { message, stack: typeof reason.stack === 'string' ? reason.stack : '' };
    }

    return { message: String(reason), stack: '' };
}

/**
 * The reporter, separate from the browser so it can be tested: `send` posts
 * one report, `context` adds what the page knows (document, session, build).
 */
export function createErrorReporter({ send, context = () => ({}), limit = MAX_REPORTS_PER_PAGE } = {}) {
    const seen = new Set();
    let sent = 0;

    function report(kind, reason, extra = {}) {
        // A cancelled request (a newer save replaced it, the user left) is not a failure.
        if (reason && reason.name === 'AbortError') return false;
        const { message, stack } = describe(reason);
        if (!isReportable(message, extra.source)) return false;
        // An error in a render loop fires hundreds of times: once per page load is enough.
        const key = `${kind}|${message}|${stack.split('\n')[1] || ''}`;
        if (seen.has(key) || sent >= limit) return false;
        seen.add(key);
        sent += 1;

        try {
            send({
                kind,
                message: message.slice(0, 500),
                stack: stack.slice(0, 4000),
                source: String(extra.source || '').slice(0, 300),
                line: Number.isFinite(extra.line) ? extra.line : null,
                column: Number.isFinite(extra.column) ? extra.column : null,
                where: String(extra.where || '').slice(0, 120),
                ...context(),
            });
        } catch (_) {
            // Reporting must never be the thing that breaks the editor.
        }

        return true;
    }

    return {
        onError(event) {
            // A failed <img>/<script> load has no error object and a target instead.
            if (event && !event.error && !event.message && event.target && event.target !== globalThis) {
                const target = event.target;

                return report('resource', `Failed to load ${String(target.tagName || 'resource').toLowerCase()}`, {
                    source: target.src || target.href || '',
                });
            }

            return report('error', event?.error || event?.message, {
                source: event?.filename,
                line: event?.lineno,
                column: event?.colno,
            });
        },
        onUnhandledRejection(event) {
            return report('unhandledrejection', event?.reason);
        },
        caught(error, where) {
            return report('caught', error, { where });
        },
        get sent() { return sent; },
    };
}

let installed = null;

/** Called once by the editor. `getCsrf` returns the current token (it is refreshed during long sessions). */
export function installErrorReporting({ url, documentId, editorSession, build, getCsrf } = {}) {
    if (installed || !url || typeof window === 'undefined') return installed;

    const send = (report) => {
        const body = JSON.stringify(report);
        // keepalive: a report made while the page unloads still leaves.
        fetch(url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': (typeof getCsrf === 'function' ? getCsrf() : '') || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        }).catch(() => {});
    };

    installed = createErrorReporter({
        send,
        context: () => ({
            page: window.location.pathname,
            document_id: documentId ? Number(documentId) : null,
            editor_session: typeof editorSession === 'function' ? editorSession() : (editorSession || ''),
            build: build || '',
        }),
    });
    window.addEventListener('error', (event) => installed.onError(event), true);
    window.addEventListener('unhandledrejection', (event) => installed.onUnhandledRejection(event));

    return installed;
}

/** For a catch block that recovers: the user carries on, and the failure is still known. */
export function reportCaughtError(error, where) {
    return installed ? installed.caught(error, where) : false;
}
