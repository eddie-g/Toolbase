import test from 'node:test';
import assert from 'node:assert/strict';
import {
    classifySaveFailure,
    createStateVersionTracker,
    createTabChannel,
    rememberStoredAssets,
    slimAnnotationForSave,
} from './autosave-guard.js';

test('the version moves forward only through this tab\'s own saves', () => {
    const tracker = createStateVersionTracker();
    assert.equal(tracker.baseVersion, null, 'nothing is sent until a load reported a version');
    assert.equal(tracker.noteLoaded(4), false);
    assert.equal(tracker.noteLoaded(4), false, 'a later page of the same load agrees');
    tracker.noteSaved(5);
    assert.equal(tracker.baseVersion, 5);
    tracker.noteSaved(3);
    assert.equal(tracker.baseVersion, 5, 'never backwards');
    assert.equal(tracker.isStale, false);
});

test('a newer version seen anywhere else makes the tab stale', () => {
    const loading = createStateVersionTracker();
    loading.noteLoaded(4);
    assert.equal(loading.noteLoaded(6), true, 'another tab saved while this one was still loading pages');

    const open = createStateVersionTracker();
    open.noteLoaded(4);
    assert.equal(open.noteRemoteSave(4), false, 'an announcement of what this tab already has');
    assert.equal(open.noteRemoteSave(null), false, 'an announcement without a version');
    assert.equal(open.noteRemoteSave(5), true);
    assert.equal(open.baseVersion, 4, 'a stale tab keeps its old version, so the server refuses it too');

    open.reset();
    assert.equal(open.isStale, false);
    assert.equal(open.baseVersion, null);
});

test('an image goes as its stored reference once the server has it, and in full again when replaced', () => {
    const stored = new Map();
    const data = `data:image/png;base64,${'A'.repeat(4000)}`;
    const image = { id: 'img_1', type: 'image', pageIndex: 0, dataUrl: data, width: 120 };
    const text = { id: 'txt_1', type: 'text', pageIndex: 0, text: 'hello' };

    assert.equal(slimAnnotationForSave(image, stored), image, 'unknown to the server: sent in full');
    assert.equal(rememberStoredAssets(stored, [image, text], { img_1: { assetPath: 'annotation-assets/documents/7/img_1.png', mimeType: 'image/png', fileName: 'img_1.png' } }), 1);

    // Exactly the row the server stored: no "src" key it never had, plus the fields the server derived.
    const slim = slimAnnotationForSave(image, stored);
    assert.deepEqual(slim, { id: 'img_1', type: 'image', pageIndex: 0, dataUrl: null, width: 120, assetPath: 'annotation-assets/documents/7/img_1.png', mimeType: 'image/png', fileName: 'img_1.png' });
    assert.equal(image.dataUrl, data, 'the annotation the page draws from is untouched');
    assert.ok(JSON.stringify(slim).length < 200);

    const replaced = { ...image, dataUrl: `data:image/png;base64,${'B'.repeat(4000)}` };
    assert.equal(slimAnnotationForSave(replaced, stored), replaced, 'different data: sent in full');
    assert.equal(slimAnnotationForSave(text, stored), text);

    const signature = { id: 'sig_1', type: 'signature', src: data };
    rememberStoredAssets(stored, [signature], { sig_1: { assetPath: 'annotation-assets/documents/7/sig_1.png' } });
    assert.equal(slimAnnotationForSave(signature, stored).assetPath, 'annotation-assets/documents/7/sig_1.png');
    assert.equal(rememberStoredAssets(stored, [text], { txt_1: { assetPath: 'x' } }), 0, 'nothing inline was sent for it');
});

test('each refused save gets its own meaning and message', () => {
    assert.equal(classifySaveFailure(409, { code: 'stale_state', message: 'Changed in another tab.' }).kind, 'stale');
    assert.equal(classifySaveFailure(409, {}).message.includes('Reload'), true);

    const throttled = classifySaveFailure(429, {}, '7');
    assert.deepEqual([throttled.kind, throttled.retryAfterMs], ['throttled', 7000]);
    assert.equal(classifySaveFailure(429, {}, '').retryAfterMs, 15000);
    assert.equal(classifySaveFailure(429, {}, '9999').retryAfterMs, 120000);

    const tooLarge = classifySaveFailure(413, { message: 'Too much edited content (24.0 MB, the limit is 20 MB).' });
    assert.deepEqual([tooLarge.kind, tooLarge.message], ['too_large', 'Too much edited content (24.0 MB, the limit is 20 MB).']);
    assert.equal(classifySaveFailure(413, {}).kind, 'too_large', 'a proxy that answers 413 with no body');

    const invalid = classifySaveFailure(422, { message: 'The given data was invalid.', errors: { 'annotations.3.text': ['One text box on page 2 is too long to save.'] } });
    assert.deepEqual([invalid.kind, invalid.message], ['invalid', 'One text box on page 2 is too long to save.']);

    assert.equal(classifySaveFailure(419, {}).kind, 'session');
    assert.equal(classifySaveFailure(401, {}).kind, 'session');
    assert.equal(classifySaveFailure(404, {}).kind, 'session', 'after the token refresh an expired session reads as not found');
    assert.equal(classifySaveFailure(403, {}).kind, 'session');
    assert.deepEqual(classifySaveFailure(500, {}), { kind: 'error', message: 'Save failed (500).' });
});

test('tabs of one document hear each other\'s saves and not their own', () => {
    const channels = [];
    class FakeChannel {
        constructor(name) { this.name = name; channels.push(this); }
        postMessage(data) { for (const other of channels) if (other !== this && other.name === this.name) other.onmessage?.({ data }); }
        close() { channels.splice(channels.indexOf(this), 1); }
    }
    const heardByA = [];
    const heardByB = [];
    const a = createTabChannel(7, { onRemoteSave: (v) => heardByA.push(v), ChannelImpl: FakeChannel });
    const b = createTabChannel(7, { onRemoteSave: (v) => heardByB.push(v), ChannelImpl: FakeChannel });
    const other = createTabChannel(8, { onRemoteSave: () => assert.fail('another document'), ChannelImpl: FakeChannel });

    a.announceSave(12);
    assert.deepEqual([heardByA, heardByB], [[], [12]]);
    assert.notEqual(a.tabId, b.tabId);
    b.close();
    a.announceSave(13);
    assert.deepEqual(heardByB, [12]);
    other.close();

    const none = createTabChannel(7, { ChannelImpl: undefined });
    assert.doesNotThrow(() => { none.announceSave(1); none.close(); });
});
