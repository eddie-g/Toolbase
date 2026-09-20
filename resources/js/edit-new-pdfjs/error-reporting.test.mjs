import test from 'node:test';
import assert from 'node:assert/strict';
import { createErrorReporter, isReportable } from './error-reporting.js';

test('noise nobody can act on is not reported', () => {
    assert.equal(isReportable('Script error.'), false);
    assert.equal(isReportable('ResizeObserver loop completed with undelivered notifications.'), false);
    assert.equal(isReportable('TypeError: x is undefined', 'chrome-extension://abc/content.js'), false);
    assert.equal(isReportable(''), false);
    assert.equal(isReportable("TypeError: Cannot read properties of null (reading 'page')"), true);
});

test('an uncaught error is sent once with where it happened and the page context', () => {
    const sent = [];
    const reporter = createErrorReporter({ send: (report) => sent.push(report), context: () => ({ document_id: 42, build: 'abc123' }) });
    const error = new TypeError('annotation is undefined');
    error.stack = 'TypeError: annotation is undefined\n    at renderOverlay (main.js:120:9)\n    at tick (main.js:80:3)';
    const event = { error, message: error.message, filename: 'https://app.test/build/assets/main.js', lineno: 120, colno: 9 };

    assert.equal(reporter.onError(event), true);
    assert.equal(reporter.onError(event), false, 'a render loop repeats it; one report is enough');

    assert.equal(sent.length, 1);
    assert.deepEqual(
        { kind: sent[0].kind, message: sent[0].message, line: sent[0].line, document_id: sent[0].document_id, build: sent[0].build },
        { kind: 'error', message: 'TypeError: annotation is undefined', line: 120, document_id: 42, build: 'abc123' },
    );
    assert.match(sent[0].stack, /renderOverlay/);
});

test('rejections, caught errors and failed resources are reported by kind', () => {
    const sent = [];
    const reporter = createErrorReporter({ send: (report) => sent.push(report) });

    reporter.onUnhandledRejection({ reason: new Error('save failed') });
    reporter.onUnhandledRejection({ reason: 'a plain string' });
    reporter.onUnhandledRejection({ reason: { message: 'an object with a message' } });
    reporter.caught(new RangeError('bad page index'), 'thumbnail render');
    reporter.onError({ target: { tagName: 'IMG', src: 'https://app.test/fonts/x.woff2' } });

    const aborted = new Error('The operation was aborted.');
    aborted.name = 'AbortError';
    assert.equal(reporter.onUnhandledRejection({ reason: aborted }), false, 'a cancelled request is not a failure');

    assert.deepEqual(sent.map((report) => report.kind), ['unhandledrejection', 'unhandledrejection', 'unhandledrejection', 'caught', 'resource']);
    assert.equal(sent[3].where, 'thumbnail render');
    assert.equal(sent[4].message, 'Failed to load img');
    assert.equal(sent[4].source, 'https://app.test/fonts/x.woff2');
});

test('a page sends a bounded number of reports, and a failing send never throws', () => {
    const sent = [];
    const reporter = createErrorReporter({ send: (report) => sent.push(report), limit: 3 });
    for (let index = 0; index < 10; index += 1) reporter.caught(new Error(`failure ${index}`), 'loop');
    assert.equal(sent.length, 3);

    const broken = createErrorReporter({ send: () => { throw new Error('network down'); } });
    assert.doesNotThrow(() => broken.caught(new Error('anything'), 'x'));
});

test('long messages and stacks are cut before they are sent', () => {
    const sent = [];
    const reporter = createErrorReporter({ send: (report) => sent.push(report) });
    const error = new Error('m'.repeat(2000));
    error.stack = 's'.repeat(10000);
    reporter.caught(error, 'w'.repeat(500));

    assert.equal(sent[0].message.length, 500);
    assert.equal(sent[0].stack.length, 4000);
    assert.equal(sent[0].where.length, 120);
});
