import test from 'node:test';
import assert from 'node:assert/strict';
import { canonicalJson, createDeltaTracker, hashAnnotation, imagesToUpload } from './delta-save.js';

const text = (id, value, extra = {}) => ({ id, type: 'text', pageIndex: 0, x: 10, y: 20, text: value, style: { align: 'left', lineHeight: 1.2 }, ...extra });

test('the hash ignores key order and undefined, and notices any real change', () => {
    const a = text('a', 'Hello');
    const reordered = { style: { lineHeight: 1.2, align: 'left' }, text: 'Hello', y: 20, x: 10, pageIndex: 0, type: 'text', id: 'a', transient: undefined };
    assert.equal(canonicalJson(a), canonicalJson(reordered));
    assert.equal(hashAnnotation(a), hashAnnotation(reordered));
    assert.notEqual(hashAnnotation(a), hashAnnotation(text('a', 'Hello!')));
    assert.notEqual(hashAnnotation(a), hashAnnotation(text('a', 'Hello', { style: { align: 'right', lineHeight: 1.2 } })));
    assert.notEqual(hashAnnotation(text('a', 'x', { dataUrl: null })), hashAnnotation(text('a', 'x')), 'null is a value the server stores');
});

test('the first save is full, the next ones carry what changed, what is gone and the count', () => {
    const tracker = createDeltaTracker();
    const state = Array.from({ length: 500 }, (_, index) => text(`ann_${index}`, `Annotation number ${index}`));

    const first = tracker.plan(state);
    assert.deepEqual([first.delta, first.annotations.length, first.expectedCount], [false, 500, 500]);
    tracker.acknowledge(first);

    const next = state.map((annotation) => (annotation.id === 'ann_7' ? text('ann_7', 'Typed into one box') : annotation));
    next.push(text('ann_new', 'Added'));
    next.splice(9, 1);   // ann_9 deleted: neither sent nor listed
    const delta = tracker.plan(next);

    assert.equal(delta.delta, true);
    assert.deepEqual(delta.annotations.map((annotation) => annotation.id), ['ann_7', 'ann_new']);
    assert.deepEqual(delta.removedIds, ['ann_9']);
    assert.equal(delta.expectedCount, 500);   // 500, one deleted, one added

    const fullBytes = JSON.stringify({ annotations: next }).length;
    const deltaBytes = JSON.stringify({ delta: true, annotations: delta.annotations, removed_ids: delta.removedIds, expected_count: delta.expectedCount }).length;
    assert.ok(deltaBytes < 1024 && deltaBytes < fullBytes / 50, `${deltaBytes} bytes against ${fullBytes}`);

    tracker.acknowledge(delta);
    const idle = tracker.plan(next);
    assert.deepEqual([idle.delta, idle.annotations.length, idle.removedIds.length, idle.expectedCount], [true, 0, 0, 500]);
});

test('whenever the two sides might disagree, everything is sent', () => {
    const tracker = createDeltaTracker();
    const state = [text('a', 'one'), text('b', 'two')];
    tracker.acknowledge(tracker.plan(state));
    assert.equal(tracker.plan(state).delta, true);

    tracker.reset();   // a failed save, a reload, delta_base_missing
    assert.equal(tracker.plan(state).delta, false);
    assert.equal(tracker.hasBaseline, false);

    tracker.acknowledge(tracker.plan(state));
    assert.equal(tracker.plan([text('a', 'one'), { type: 'text', text: 'no id' }]).delta, false, 'an annotation without an id');
    assert.equal(tracker.plan([text('a', 'one'), text('a', 'again')]).delta, false, 'the same id twice');
    assert.equal(tracker.plan([text('a', 'changed'), text('b', 'changed too')]).delta, false, 'everything changed');

    tracker.acknowledge(tracker.plan([text('a', 'one'), text('a', 'dup')]));
    assert.equal(tracker.hasBaseline, false, 'a state that cannot be keyed is no baseline');

    // What is acknowledged is what travelled, not what the editor holds by the time the answer arrives.
    const live = [text('a', 'one'), text('b', 'two')];
    const sent = tracker.plan(live);
    live[1].text = 'typed while the save was in flight';
    tracker.acknowledge(sent);
    assert.deepEqual(tracker.plan(live).annotations.map((annotation) => annotation.id), ['b']);
});

test('only image data the server does not have yet is uploaded', () => {
    const png = 'data:image/png;base64,AAAA';
    const stored = new Map([['sig', { assetPath: 'annotation-assets/documents/1/sig_x.png', data: png }]]);
    const annotations = [
        { id: 'sig', type: 'signature', dataUrl: png },
        { id: 'new', type: 'image', src: 'data:image/jpeg;base64,BBBB', fileName: 'photo.jpg' },
        { id: 'replaced', type: 'image', dataUrl: 'data:image/png;base64,CCCC' },
        { id: 'loaded', type: 'image', src: '/documents/1/annotation-assets/loaded_y.png', assetPath: 'annotation-assets/documents/1/loaded_y.png' },
        text('t', 'not an image'),
    ];
    stored.set('replaced', { assetPath: 'p', data: 'data:image/png;base64,OLD' });

    assert.deepEqual(imagesToUpload(annotations, stored), [
        { id: 'new', dataUrl: 'data:image/jpeg;base64,BBBB', fileName: 'photo.jpg', mimeType: null },
        { id: 'replaced', dataUrl: 'data:image/png;base64,CCCC', fileName: null, mimeType: null },
    ]);
});
