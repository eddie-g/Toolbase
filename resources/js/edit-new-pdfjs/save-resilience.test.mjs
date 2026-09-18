import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createDirtyTracker,
    createDraftStore,
    describeDraftTime,
    draftIsRestorable,
    fitsKeepalive,
    planAfterSaveFailure,
    retryDelayMs,
} from './save-resilience.js';

test('a change made while a save is in flight keeps the document dirty', () => {
    const dirty = createDirtyTracker();
    assert.equal(dirty.isDirty, false);
    dirty.markChanged();
    const first = dirty.beginSave();
    dirty.markChanged();                 // typed again before the response came back
    dirty.completeSave(first);
    assert.equal(dirty.isDirty, true, 'the save covered the first change only');
    dirty.completeSave(dirty.beginSave());
    assert.equal(dirty.isDirty, false);
    dirty.completeSave(first);
    assert.equal(dirty.isDirty, false, 'a late, older completion changes nothing');
});

test('each failure has its own path', () => {
    const server = planAfterSaveFailure('error', { attempt: 0, online: true });
    assert.deepEqual([server.action, server.delayMs, server.sticky], ['retry', 2000, true]);
    assert.match(server.message, /Trying again in 2 seconds/);
    assert.equal(planAfterSaveFailure('network', { attempt: 3, online: true }).delayMs, 16000);

    const offline = planAfterSaveFailure('network', { attempt: 0, online: false });
    assert.equal(offline.action, 'wait_online');
    assert.match(offline.message, /offline/i);

    const tooLarge = planAfterSaveFailure('too_large', { message: 'Too much edited content (24 MB, the limit is 20 MB).' });
    assert.equal(tooLarge.action, 'halt');
    assert.match(tooLarge.message, /24 MB.*Autosave is paused/);
    assert.equal(planAfterSaveFailure('invalid', { message: 'One text box is too long.' }).action, 'halt');

    const session = planAfterSaveFailure('session');
    assert.equal(session.action, 'session');
    assert.match(session.message, /Sign in again in a new tab/);

    assert.equal(planAfterSaveFailure('stale', { message: 'x' }).action, 'stale');
    assert.deepEqual([planAfterSaveFailure('throttled').action, planAfterSaveFailure('throttled').sticky], ['retry', false]);
});

test('retries back off to a minute and stay there', () => {
    assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 30].map(retryDelayMs), [2000, 4000, 8000, 16000, 32000, 60000, 60000, 60000]);
});

test('only small bodies can outlive the page', () => {
    assert.equal(fitsKeepalive('x'.repeat(59000)), true);
    assert.equal(fitsKeepalive('x'.repeat(61000)), false);
    assert.equal(fitsKeepalive('é'.repeat(31000)), false, 'bytes, not characters');
    assert.equal(fitsKeepalive(null), false);
});

test('a draft is offered only for the same document, session and server state, and not forever', () => {
    const now = Date.UTC(2026, 8, 18, 15, 0, 0);
    const draft = { documentId: 7, sessionId: 's1', baseVersion: 4, savedAt: now - 60000, annotations: [{ id: 'a' }] };
    const same = { documentId: '7', sessionId: 's1', stateVersion: 4, now };

    assert.equal(draftIsRestorable(draft, same), true);
    assert.equal(draftIsRestorable(draft, { ...same, stateVersion: 5 }), false, 'the server has a newer state: restoring would overwrite it');
    assert.equal(draftIsRestorable(draft, { ...same, documentId: 8 }), false);
    assert.equal(draftIsRestorable(draft, { ...same, sessionId: 's2' }), false);
    assert.equal(draftIsRestorable({ ...draft, savedAt: now - 15 * 24 * 3600 * 1000 }, same), false, 'older than two weeks');
    assert.equal(draftIsRestorable({ ...draft, annotations: null }, same), false);
    assert.equal(draftIsRestorable(null, same), false);
    assert.equal(draftIsRestorable({ ...draft, baseVersion: null }, { ...same, stateVersion: null }), true, 'a server that reports no version');
});

test('the draft time reads naturally', () => {
    const now = new Date(2026, 8, 18, 15, 0, 0).getTime();
    assert.equal(describeDraftTime(new Date(2026, 8, 18, 14, 32).getTime(), now, 'en-GB'), '14:32');
    // The month abbreviation is the runtime's ("Sep" or "Sept").
    assert.match(describeDraftTime(new Date(2026, 8, 12, 9, 5).getTime(), now, 'en-GB'), /^12 Sept?, 09:05$/);
});

test('without IndexedDB the draft store quietly does nothing', async () => {
    const store = createDraftStore({ indexedDB: undefined });
    assert.equal(await store.put(7, { a: 1 }), null);
    assert.equal(await store.get(7), null);
    assert.equal(await store.remove(7), null);

    const throwing = createDraftStore({ indexedDB: { open() { throw new Error('SecurityError'); } } });
    assert.equal(await throwing.get(7), null);
});
