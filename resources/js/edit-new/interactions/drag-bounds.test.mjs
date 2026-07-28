import test from 'node:test';
import assert from 'node:assert/strict';

import {
    correctionToKeepPaintedAxisInside,
    dragAxisOffsetBounds,
} from './drag-bounds.js';

test('rotated shape can move past its layout box until painted edge reaches page edge', () => {
    const bounds = dragAxisOffsetBounds(0, 30, 410, 800);
    assert.deepEqual(bounds, { min: -30, max: 390 });
});

test('existing translation is included in absolute drag offset bounds', () => {
    const bounds = dragAxisOffsetBounds(12, 42, 422, 800);
    assert.deepEqual(bounds, { min: -30, max: 390 });
});

test('oversized painted bounds remain pannable between both page edges', () => {
    const bounds = dragAxisOffsetBounds(0, -40, 940, 800);
    assert.deepEqual(bounds, { min: -140, max: 40 });
});

test('post-drag correction uses painted bounds rather than the invisible layout box', () => {
    assert.equal(correctionToKeepPaintedAxisInside(30, 410, 800), 0);
    assert.equal(correctionToKeepPaintedAxisInside(-12, 368, 800), 12);
    assert.equal(correctionToKeepPaintedAxisInside(432, 812, 800), -12);
});
