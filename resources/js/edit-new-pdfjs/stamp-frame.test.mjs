import assert from 'node:assert/strict';
import test from 'node:test';

import { applyStampFrameStyle, stampFrameStyle } from './page-geometry.js';

/** Minimal stand-in for an element with a style map. */
function elementWithStyle(initial = {}) {
    return { style: { width: '', height: '', transform: '', ...initial } };
}

test('an unrotated page leaves sizing to CSS so the stamp tracks the box', () => {
    const style = stampFrameStyle(0, 311, 86);

    // NK_DEV_6: inline pixels here go stale the moment the box is resized.
    assert.equal(style.width, null);
    assert.equal(style.height, null);
    assert.equal(style.transform, 'none');
    assert.equal(style.followsBox, true);
});

test('the same holds once the box has been resized down', () => {
    const before = stampFrameStyle(0, 311, 86);
    const after = stampFrameStyle(0, 146, 40);

    assert.deepEqual(after, before);
});

test('missing or odd rotations are treated as unrotated', () => {
    for (const rotation of [undefined, null, NaN, 'nonsense', 360, 720]) {
        const style = stampFrameStyle(rotation, 200, 50);
        assert.equal(style.followsBox, true, `rotation ${String(rotation)}`);
        assert.equal(style.width, null, `rotation ${String(rotation)}`);
    }
});

test('a 90 degree page swaps the axes and offsets the rotation', () => {
    const style = stampFrameStyle(90, 200, 50);

    assert.equal(style.width, '50px');
    assert.equal(style.height, '200px');
    assert.equal(style.transform, 'translate(200px, 0px) rotate(90deg)');
    assert.equal(style.followsBox, false);
});

test('a 180 degree page keeps the axes and flips about the centre', () => {
    const style = stampFrameStyle(180, 200, 50);

    assert.equal(style.width, '200px');
    assert.equal(style.height, '50px');
    assert.equal(style.transform, 'translate(200px, 50px) rotate(180deg)');
});

test('a 270 degree page swaps the axes the other way', () => {
    const style = stampFrameStyle(270, 200, 50);

    assert.equal(style.width, '50px');
    assert.equal(style.height, '200px');
    assert.equal(style.transform, 'translate(0px, 50px) rotate(-90deg)');
});

test('rotated pixel sizes follow the box as it is resized', () => {
    const before = stampFrameStyle(90, 200, 50);
    const after = stampFrameStyle(90, 100, 25);

    assert.equal(before.height, '200px');
    assert.equal(after.height, '100px');
    assert.equal(after.width, '25px');
});

test('a collapsed box never produces a zero or negative size', () => {
    const style = stampFrameStyle(90, 0, 0);

    assert.equal(style.width, '1px');
    assert.equal(style.height, '1px');
});

test('applying an unrotated style clears stale inline pixels', () => {
    // Exactly the state the bug left behind: a stale height on a shrunken box.
    const img = elementWithStyle({ width: '310.891px', height: '86.0658px' });

    applyStampFrameStyle(img, stampFrameStyle(0, 146, 40));

    assert.equal(img.style.width, '');
    assert.equal(img.style.height, '');
    assert.equal(img.style.transform, 'none');
});

test('applying a rotated style writes explicit pixels', () => {
    const img = elementWithStyle();

    applyStampFrameStyle(img, stampFrameStyle(270, 120, 40));

    assert.equal(img.style.width, '40px');
    assert.equal(img.style.height, '120px');
    assert.equal(img.style.transform, 'translate(0px, 40px) rotate(-90deg)');
});

test('applying tolerates a missing element or style', () => {
    assert.doesNotThrow(() => applyStampFrameStyle(null, stampFrameStyle(0, 10, 10)));
    assert.doesNotThrow(() => applyStampFrameStyle(elementWithStyle(), null));
});
