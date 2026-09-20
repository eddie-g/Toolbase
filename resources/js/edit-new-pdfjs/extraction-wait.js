// Waiting for an upload's text extraction (documents.processing_status). The
// editor must not open on a document whose paragraphs are still being built:
// it would show row-grouped text and the next autosave would store that. So it
// polls the light status endpoint for as long as the server says "pending",
// and shows a failure with a retry when the server says "failed".

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

/** Quick at first (most uploads are ready within seconds), then easing off to 3 s. */
import { editorFetch } from './editor-fetch.js';

export function processingPollDelayMs(attempt) {
    if (attempt < 6) return 350;
    return Math.min(3000, Math.round(350 * 1.35 ** (attempt - 5)));
}

export function processingWaitMessage(status = {}) {
    const waited = Number(status.waited_seconds) || 0;
    if (cleanText(status.status) === 'queued' && waited >= 8) return 'Waiting for a free worker…';
    if (waited >= 45) return 'Still working on this PDF. Large files can take a few minutes…';
    return 'Grouping PDF text into paragraphs...';
}

function failure(errorCode, message) {
    return { status: 'failed', pending: false, can_retry: true, error_code: errorCode, message };
}

/**
 * Resolves with the final status payload: status "ready" or "failed". Never
 * resolves while the server reports pending, short of maxWaitMs (the server
 * itself reports a lost job as failed well before that).
 */
export async function waitForDocumentProcessing(statusUrl, {
    fetchImpl = editorFetch,
    delay = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds)),
    now = () => Date.now(),
    onWaiting = null,
    maxWaitMs = 15 * 60 * 1000,
} = {}) {
    if (!cleanText(statusUrl)) return { status: 'ready', pending: false };
    const startedAt = now();
    let consecutiveErrors = 0;

    for (let attempt = 0; now() - startedAt < maxWaitMs; attempt += 1) {
        let data = null;
        try {
            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.status === 404 || response.status === 403) {
                return failure('not_found', 'This document is no longer available.');
            }
            if (response.ok) data = await response.json().catch(() => null);
        } catch (_) {
            data = null;
        }

        if (data && typeof data === 'object' && cleanText(data.status)) {
            consecutiveErrors = 0;
            if (data.pending !== true) return data;
            if (typeof onWaiting === 'function') onWaiting(data);
        } else {
            consecutiveErrors += 1;
            if (consecutiveErrors >= 6) {
                return failure('unreachable', 'Could not reach the server to check on this document.');
            }
        }
        await delay(processingPollDelayMs(attempt));
    }

    return failure('client_timeout', 'This PDF is taking much longer than expected to process.');
}

/** "Try again": asks the server to queue the extraction once more. Resolves with its status payload. */
export async function retryDocumentProcessing(retryUrl, { fetchImpl = editorFetch, csrf = '' } = {}) {
    try {
        const response = await fetchImpl(retryUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        if (response.status === 429) return failure('retry_throttled', 'Too many retries in a short time. Wait a minute, then try again.');
        const data = await response.json().catch(() => null);
        if (response.ok && data && cleanText(data.status)) return data;
    } catch (_) {
        // fall through
    }
    return failure('retry_failed', 'The retry could not be started.');
}
