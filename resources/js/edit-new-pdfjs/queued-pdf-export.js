// The editor's Download: the server queues the export (202 + status_url), the
// client polls with backoff and fetches the finished PDF through the signed,
// expiring download_url. A server that still answers with the PDF itself
// (sync mode) is handled by the same call.

export const QUEUED_EXPORT_HEADER = { 'X-Export-Mode': 'queued' };

export class PdfExportError extends Error {
    constructor(message, { code = 'failed', status = 0, reference = '', retryAfterSeconds = 0 } = {}) {
        super(message);
        this.name = 'PdfExportError';
        this.code = code;
        this.status = status;
        this.reference = reference;
        this.retryAfterSeconds = retryAfterSeconds;
    }
}

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

function withReference(message, reference) {
    const ref = cleanText(reference);
    return ref ? `${message} (ref ${ref.slice(0, 8)})` : message;
}

function retryAfterSeconds(response) {
    const seconds = Number.parseInt(response?.headers?.get?.('Retry-After') ?? '', 10);
    return Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
}

async function readErrorBody(response) {
    const contentType = cleanText(response.headers?.get?.('content-type'));
    if (contentType.includes('application/json')) {
        return response.json().catch(() => ({}));
    }
    return {};
}

/** One message per failure class; never the raw response body. */
export async function exportErrorFromResponse(response, fallback = 'PDF generation failed') {
    const data = await readErrorBody(response);
    const status = Number(response.status) || 0;
    const wait = retryAfterSeconds(response);
    const base = { status, reference: cleanText(data?.reference), retryAfterSeconds: wait };

    if (status === 401 || status === 419) {
        return new PdfExportError('Your session has expired. Reload the page and sign in again; your edits are saved.', { ...base, code: 'session' });
    }
    if (status === 423) {
        return new PdfExportError('This PDF is locked. Enter its password, then download again.', { ...base, code: 'locked' });
    }
    if (status === 413) {
        return new PdfExportError('This document has too much edited content to export at once. Remove very large images and try again.', { ...base, code: 'too_large' });
    }
    if (status === 429) {
        return new PdfExportError(`Too many downloads in a short time. Try again in ${wait || 60} seconds.`, { ...base, code: 'rate_limited' });
    }
    if (status === 503) {
        return new PdfExportError(cleanText(data?.message, 'The PDF service is busy. Try again in a moment.'), { ...base, code: 'busy' });
    }
    return new PdfExportError(
        withReference(cleanText(data?.message, `${fallback} (${status || 'network error'}).`), data?.reference),
        base,
    );
}

/** 1 s, then growing by half each time, capped at 5 s. */
export function exportPollDelayMs(attempt) {
    return Math.min(5000, Math.round(1000 * 1.5 ** Math.max(0, attempt)));
}

function abortError(signal) {
    if (signal?.reason?.name === 'TimeoutError') {
        return new PdfExportError('The PDF is taking longer than expected. Try again in a moment.', { code: 'timeout' });
    }
    return new PdfExportError('The download was cancelled.', { code: 'aborted' });
}

function mapFetchFailure(error, signal) {
    if (error instanceof PdfExportError) return error;
    if (signal?.aborted || error?.name === 'AbortError' || error?.name === 'TimeoutError') return abortError(signal);
    return new PdfExportError('Could not reach the server. Check your connection and try again.', { code: 'network' });
}

/**
 * Polls status_url until the export completes and resolves with the final
 * status payload (download_url, download_name). A failed poll request is
 * retried on the next tick; three in a row give up.
 */
export async function waitForQueuedPdfExport(initial, {
    fetchImpl = globalThis.fetch,
    signal = null,
    onProgress = null,
    delay = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds)),
    maxAttempts = 120,
} = {}) {
    let data = initial || {};
    const statusUrl = cleanText(data.status_url);
    let consecutiveErrors = 0;

    for (let attempt = 0; attempt <= maxAttempts; attempt += 1) {
        const status = cleanText(data.status).toLowerCase();
        if (status === 'completed' && cleanText(data.download_url)) return data;
        if (status === 'failed' || status === 'expired' || data.success === false) {
            throw new PdfExportError(
                withReference(cleanText(data.message, 'PDF generation failed.'), data.reference),
                { code: status === 'expired' ? 'expired' : 'failed', reference: cleanText(data.reference) },
            );
        }
        if (!['queued', 'processing', 'completed'].includes(status) || !statusUrl) {
            throw new PdfExportError('The server returned an invalid export status.');
        }
        if (attempt === maxAttempts) break;

        if (typeof onProgress === 'function') onProgress(data);
        await delay(exportPollDelayMs(attempt));
        if (signal?.aborted) throw abortError(signal);

        try {
            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal,
            });
            if (!response.ok) {
                if (response.status >= 500 || response.status === 429) {
                    consecutiveErrors += 1;
                    if (consecutiveErrors >= 3) throw await exportErrorFromResponse(response, 'PDF status check failed');
                    continue;
                }
                throw await exportErrorFromResponse(response, 'PDF status check failed');
            }
            data = await response.json().catch(() => ({}));
            consecutiveErrors = 0;
        } catch (error) {
            const mapped = mapFetchFailure(error, signal);
            if (mapped.code !== 'network') throw mapped;
            consecutiveErrors += 1;
            if (consecutiveErrors >= 3) throw mapped;
        }
    }

    throw new PdfExportError('The PDF is taking longer than expected. Try again in a moment.', { code: 'timeout' });
}

/**
 * POSTs the export payload and resolves with the PDF blob, whichever way the
 * server answers. onProgress receives { status, progress } (progress 0-100).
 */
export async function requestQueuedPdfExport(url, {
    payload,
    headers = {},
    fetchImpl = globalThis.fetch,
    timeoutMs = 300000,
    signal = null,
    onProgress = null,
    delay,
} = {}) {
    // One signal for the whole export (submit, every poll, the download). Built
    // by hand rather than with AbortSignal.timeout()/any() so it behaves the
    // same in every browser the editor supports.
    const controller = new AbortController();
    const combined = controller.signal;
    const onCallerAbort = () => controller.abort(signal.reason);
    if (signal?.aborted) onCallerAbort();
    else signal?.addEventListener?.('abort', onCallerAbort, { once: true });
    const timer = timeoutMs > 0
        ? globalThis.setTimeout(() => controller.abort(new DOMException('The export timed out.', 'TimeoutError')), timeoutMs)
        : null;

    try {
        const response = await fetchImpl(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                ...QUEUED_EXPORT_HEADER,
                ...headers,
            },
            body: JSON.stringify(payload),
            signal: combined,
        });
        if (!response.ok) throw await exportErrorFromResponse(response);

        const contentType = cleanText(response.headers?.get?.('content-type'));
        if (!contentType.includes('application/json')) {
            return { blob: await response.blob(), queued: false };
        }

        const queued = await response.json().catch(() => ({}));
        if (typeof onProgress === 'function') onProgress(queued);
        const done = await waitForQueuedPdfExport(queued, {
            fetchImpl,
            signal: combined,
            onProgress,
            ...(delay ? { delay } : {}),
        });
        if (typeof onProgress === 'function') onProgress({ ...done, progress: 100 });

        const download = await fetchImpl(cleanText(done.download_url), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/pdf, application/json', ...headers },
            signal: combined,
        });
        if (!download.ok) throw await exportErrorFromResponse(download, 'PDF download failed');
        return { blob: await download.blob(), queued: true, exportId: cleanText(done.export_id) };
    } catch (error) {
        throw mapFetchFailure(error, combined);
    } finally {
        if (timer !== null) globalThis.clearTimeout(timer);
        signal?.removeEventListener?.('abort', onCallerAbort);
    }
}

export function exportProgressLabel(data) {
    const status = cleanText(data?.status).toLowerCase();
    const progress = Math.max(0, Math.min(100, Math.round(Number(data?.progress) || 0)));
    if (status === 'queued') return 'Preparing PDF… queued';
    if (status === 'completed') return 'Preparing PDF… downloading';
    return `Preparing PDF… ${progress}%`;
}
