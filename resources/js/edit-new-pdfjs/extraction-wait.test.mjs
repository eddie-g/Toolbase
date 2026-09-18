import test from 'node:test';
import assert from 'node:assert/strict';
import {
    processingPollDelayMs,
    processingWaitMessage,
    retryDocumentProcessing,
    waitForDocumentProcessing,
} from './extraction-wait.js';

const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const noDelay = () => Promise.resolve();

test('the editor keeps waiting for as long as the server says pending, well past the old 14 seconds', async () => {
    let clock = 0;
    const replies = [
        ...Array.from({ length: 60 }, (_, i) => ({ status: i < 5 ? 'queued' : 'extracting', pending: true, waited_seconds: i * 3 })),
        { status: 'ready', pending: false },
    ];
    const seen = [];
    const result = await waitForDocumentProcessing('/documents/7/processing-status', {
        fetchImpl: async () => json(replies.shift()),
        delay: async (ms) => { clock += ms; },
        now: () => clock,
        onWaiting: (status) => seen.push(status.status),
    });

    assert.equal(result.status, 'ready');
    assert.equal(seen.length, 60);
    assert.ok(clock > 120_000, `waited ${clock} ms of simulated time`);
});

test('a failure ends the wait at once and carries the server message and the retry', async () => {
    const replies = [
        { status: 'extracting', pending: true },
        { status: 'failed', pending: false, can_retry: true, error_code: 'extraction_timeout', message: 'This PDF took too long to process.', retry_url: '/documents/7/processing-retry' },
    ];
    const result = await waitForDocumentProcessing('/s', { fetchImpl: async () => json(replies.shift()), delay: noDelay });
    assert.deepEqual([result.status, result.error_code, result.can_retry, result.retry_url], ['failed', 'extraction_timeout', true, '/documents/7/processing-retry']);
});

test('network trouble is ridden out; six failed checks in a row give up with a retryable failure', async () => {
    let replies = [Promise.reject(new TypeError('fetch failed')), json({}, 502), json({ status: 'extracting', pending: true }), json({ status: 'ready', pending: false })];
    assert.equal((await waitForDocumentProcessing('/s', { fetchImpl: async () => replies.shift(), delay: noDelay })).status, 'ready');

    replies = Array.from({ length: 6 }, () => json({}, 500));
    const gaveUp = await waitForDocumentProcessing('/s', { fetchImpl: async () => replies.shift(), delay: noDelay });
    assert.deepEqual([gaveUp.status, gaveUp.error_code, gaveUp.can_retry], ['failed', 'unreachable', true]);

    const gone = await waitForDocumentProcessing('/s', { fetchImpl: async () => json({}, 404), delay: noDelay });
    assert.equal(gone.error_code, 'not_found');
});

test('a server that never stops saying pending is cut off at the client limit', async () => {
    let clock = 0;
    const result = await waitForDocumentProcessing('/s', {
        fetchImpl: async () => json({ status: 'extracting', pending: true }),
        delay: async (ms) => { clock += ms; },
        now: () => clock,
        maxWaitMs: 60_000,
    });
    assert.deepEqual([result.status, result.error_code], ['failed', 'client_timeout']);
    assert.ok(clock >= 60_000 && clock < 70_000);
});

test('no status url (older pages) means there is nothing to wait for', async () => {
    assert.equal((await waitForDocumentProcessing('', { fetchImpl: async () => assert.fail('no request') })).status, 'ready');
});

test('retry posts with the csrf token and reports throttling and failure plainly', async () => {
    const calls = [];
    const queued = await retryDocumentProcessing('/documents/7/processing-retry', {
        csrf: 'token',
        fetchImpl: async (url, options) => { calls.push({ url, options }); return json({ status: 'queued', pending: true }, 202); },
    });
    assert.equal(queued.status, 'queued');
    assert.deepEqual([calls[0].options.method, calls[0].options.headers['X-CSRF-TOKEN']], ['POST', 'token']);

    assert.equal((await retryDocumentProcessing('/r', { fetchImpl: async () => json({}, 429) })).error_code, 'retry_throttled');
    assert.equal((await retryDocumentProcessing('/r', { fetchImpl: async () => { throw new TypeError('offline'); } })).error_code, 'retry_failed');
});

test('polling eases off and the message follows what the user is waiting for', () => {
    assert.equal(processingPollDelayMs(0), 350);
    assert.equal(processingPollDelayMs(5), 350);
    assert.ok(processingPollDelayMs(8) > 350);
    assert.equal(processingPollDelayMs(40), 3000);
    assert.equal(processingWaitMessage({ status: 'extracting', waited_seconds: 3 }), 'Grouping PDF text into paragraphs...');
    assert.equal(processingWaitMessage({ status: 'queued', waited_seconds: 12 }), 'Waiting for a free worker…');
    assert.match(processingWaitMessage({ status: 'extracting', waited_seconds: 60 }), /few minutes/);
});
