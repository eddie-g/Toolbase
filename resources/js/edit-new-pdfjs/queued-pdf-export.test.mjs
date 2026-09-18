import test from 'node:test';
import assert from 'node:assert/strict';
import {
    PdfExportError,
    exportErrorFromResponse,
    exportPollDelayMs,
    exportProgressLabel,
    requestQueuedPdfExport,
    waitForQueuedPdfExport,
} from './queued-pdf-export.js';

function jsonResponse(body, status = 200, headers = {}) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'content-type': 'application/json', ...headers },
    });
}

const noDelay = () => Promise.resolve();

test('a queued export is submitted, polled and downloaded through the signed URL', async () => {
    const calls = [];
    const progress = [];
    const replies = [
        jsonResponse({ success: true, queued: true, status: 'queued', progress: 5, status_url: '/documents/7/exports/abc' }, 202),
        jsonResponse({ success: true, status: 'processing', progress: 45, status_url: '/documents/7/exports/abc' }),
        jsonResponse({ success: true, status: 'completed', progress: 100, export_id: 'abc', download_url: '/documents/7/exports/abc/download?signature=s' }),
        new Response(new Blob(['%PDF-1.7']), { status: 200, headers: { 'content-type': 'application/pdf' } }),
    ];
    const fetchImpl = async (url, options) => {
        calls.push({ url, options });
        return replies.shift();
    };

    const result = await requestQueuedPdfExport('/documents/7/download-annotated-pdf', {
        payload: { annotations: [] },
        headers: { 'X-CSRF-TOKEN': 't', 'X-PDF-Unlock-Token': 'u' },
        fetchImpl,
        delay: noDelay,
        onProgress: (data) => progress.push(data.progress),
    });

    assert.equal(result.queued, true);
    assert.equal(result.exportId, 'abc');
    assert.equal(await result.blob.text(), '%PDF-1.7');
    assert.deepEqual(calls.map((call) => call.options.method), ['POST', 'GET', 'GET', 'GET']);
    assert.equal(calls[0].options.headers['X-Export-Mode'], 'queued');
    assert.equal(calls[3].url, '/documents/7/exports/abc/download?signature=s');
    assert.equal(calls[3].options.headers['X-PDF-Unlock-Token'], 'u');
    assert.ok(calls.every((call) => call.options.signal instanceof AbortSignal));
    assert.deepEqual(progress, [5, 5, 45, 100]);
});

test('a server that still streams the PDF back is handled by the same call', async () => {
    const fetchImpl = async () => new Response(new Blob(['%PDF']), { status: 200, headers: { 'content-type': 'application/pdf' } });
    const result = await requestQueuedPdfExport('/x', { payload: {}, fetchImpl });
    assert.equal(result.queued, false);
    assert.equal(await result.blob.text(), '%PDF');
});

test('a failed export reports the server message with a short reference, not process output', async () => {
    const replies = [
        jsonResponse({ success: true, status: 'queued', status_url: '/s' }, 202),
        jsonResponse({ success: false, status: 'failed', message: 'Failed to generate annotated PDF.', reference: '0b5f6c1e-aaaa-bbbb-cccc-000000000000' }),
    ];
    await assert.rejects(
        requestQueuedPdfExport('/x', { payload: {}, fetchImpl: async () => replies.shift(), delay: noDelay }),
        (error) => error instanceof PdfExportError
            && error.code === 'failed'
            && error.message === 'Failed to generate annotated PDF. (ref 0b5f6c1e)',
    );
});

test('error states: session, locked, too large, rate limited, busy', async () => {
    const cases = [
        [419, {}, 'session'],
        [401, {}, 'session'],
        [423, {}, 'locked'],
        [413, {}, 'too_large'],
        [429, { 'Retry-After': '17' }, 'rate_limited'],
        [503, { 'Retry-After': '5' }, 'busy'],
    ];
    for (const [status, headers, code] of cases) {
        const error = await exportErrorFromResponse(jsonResponse({ message: 'server text' }, status, headers));
        assert.equal(error.code, code, `status ${status}`);
        assert.equal(error.status, status);
    }
    const limited = await exportErrorFromResponse(jsonResponse({}, 429, { 'Retry-After': '17' }));
    assert.match(limited.message, /17 seconds/);
    assert.equal(limited.retryAfterSeconds, 17);

    const html = await exportErrorFromResponse(new Response('<html>/var/www/html/storage trace</html>', { status: 500, headers: { 'content-type': 'text/html' } }));
    assert.equal(html.message, 'PDF generation failed (500).');
});

test('the timeout aborts the wait with a timeout error', async () => {
    const fetchImpl = (url, { signal }) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(signal.reason));
    });
    await assert.rejects(
        requestQueuedPdfExport('/x', { payload: {}, fetchImpl, timeoutMs: 20 }),
        (error) => error instanceof PdfExportError && error.code === 'timeout',
    );
});

test('a caller abort cancels polling', async () => {
    const controller = new AbortController();
    const replies = [jsonResponse({ success: true, status: 'queued', status_url: '/s' }, 202)];
    const pending = requestQueuedPdfExport('/x', {
        payload: {},
        signal: controller.signal,
        fetchImpl: async () => replies.shift(),
        delay: async () => { controller.abort(); },
    });
    await assert.rejects(pending, (error) => error.code === 'aborted');
});

test('polling rides out two failed status checks and gives up on the third', async () => {
    const queued = { success: true, status: 'queued', status_url: '/s' };
    let replies = [
        jsonResponse({}, 502),
        Promise.reject(new TypeError('fetch failed')),
        jsonResponse({ success: true, status: 'completed', download_url: '/d' }),
    ];
    const done = await waitForQueuedPdfExport(queued, { fetchImpl: async () => replies.shift(), delay: noDelay });
    assert.equal(done.download_url, '/d');

    replies = [jsonResponse({}, 502), jsonResponse({}, 502), jsonResponse({}, 502)];
    await assert.rejects(
        waitForQueuedPdfExport(queued, { fetchImpl: async () => replies.shift(), delay: noDelay }),
        (error) => error instanceof PdfExportError && error.status === 502,
    );
});

test('an expired or never-finishing export ends the wait', async () => {
    await assert.rejects(
        waitForQueuedPdfExport({ success: false, status: 'expired', message: 'This export has expired. Download the PDF again.' }),
        (error) => error.code === 'expired',
    );
    await assert.rejects(
        waitForQueuedPdfExport({ success: true, status: 'queued', status_url: '/s' }, {
            fetchImpl: async () => jsonResponse({ success: true, status: 'processing', progress: 45 }),
            delay: noDelay,
            maxAttempts: 3,
        }),
        (error) => error.code === 'timeout',
    );
});

test('poll delay backs off to five seconds and labels show determinate progress', () => {
    assert.equal(exportPollDelayMs(0), 1000);
    assert.equal(exportPollDelayMs(1), 1500);
    assert.equal(exportPollDelayMs(10), 5000);
    assert.equal(exportProgressLabel({ status: 'queued', progress: 5 }), 'Preparing PDF… queued');
    assert.equal(exportProgressLabel({ status: 'processing', progress: 45 }), 'Preparing PDF… 45%');
    assert.equal(exportProgressLabel({ status: 'completed', progress: 100 }), 'Preparing PDF… downloading');
});
