import test from 'node:test';
import assert from 'node:assert/strict';

import {
    normalizeAnnotationRotation,
    isUprightRotation,
    contentRotationTransform,
    rotatedBoundingSize,
} from './annotation-rotation.js';

test('normalizeAnnotationRotation wraps into [0, 360)', () => {
    assert.equal(normalizeAnnotationRotation(0), 0);
    assert.equal(normalizeAnnotationRotation(45), 45);
    assert.equal(normalizeAnnotationRotation(360), 0);
    assert.equal(normalizeAnnotationRotation(450), 90);
    assert.equal(normalizeAnnotationRotation(-90), 270);
    assert.equal(normalizeAnnotationRotation(-450), 270);
});

test('normalizeAnnotationRotation treats junk as upright', () => {
    assert.equal(normalizeAnnotationRotation(null), 0);
    assert.equal(normalizeAnnotationRotation(undefined), 0);
    assert.equal(normalizeAnnotationRotation('nonsense'), 0);
    assert.equal(normalizeAnnotationRotation(Number.NaN), 0);
});

test('isUprightRotation only accepts angles that render identically', () => {
    assert.equal(isUprightRotation(0), true);
    assert.equal(isUprightRotation(360), true);
    assert.equal(isUprightRotation(-360), true);
    assert.equal(isUprightRotation(0.005), true);
    assert.equal(isUprightRotation(1), false);
    assert.equal(isUprightRotation(90), false);
});

test('an upright annotation on an upright page needs no transform', () => {
    assert.equal(contentRotationTransform('none', 100, 40, 0), 'none');
    assert.equal(contentRotationTransform('', 100, 40, 0), 'none');
    assert.equal(contentRotationTransform(null, 100, 40, 360), 'none');
});

test('an upright annotation keeps the page transform untouched', () => {
    const pageTransform = 'translate(40px, 0px) rotate(90deg)';
    assert.equal(contentRotationTransform(pageTransform, 100, 40, 0), pageTransform);
});

test('a rotated annotation spins about the content centre', () => {
    assert.equal(
        contentRotationTransform('none', 100, 40, 30),
        'translate(50px, 20px) rotate(30deg) translate(-50px, -20px)',
    );
});

test('a rotated annotation composes after the page transform', () => {
    const pageTransform = 'translate(40px, 0px) rotate(90deg)';
    assert.equal(
        contentRotationTransform(pageTransform, 100, 40, 30),
        `${pageTransform} translate(50px, 20px) rotate(30deg) translate(-50px, -20px)`,
    );
});

test('negative and over-turn angles are normalised into the transform', () => {
    assert.equal(
        contentRotationTransform('none', 20, 20, -90),
        'translate(10px, 10px) rotate(270deg) translate(-10px, -10px)',
    );
    assert.equal(
        contentRotationTransform('none', 20, 20, 450),
        'translate(10px, 10px) rotate(90deg) translate(-10px, -10px)',
    );
});

test('a zero-sized frame still produces a usable transform', () => {
    assert.equal(
        contentRotationTransform('none', 0, 0, 45),
        'translate(0px, 0px) rotate(45deg) translate(0px, 0px)',
    );
});

test('rotatedBoundingSize leaves an upright rect alone', () => {
    assert.deepEqual(rotatedBoundingSize(100, 40, 0), { width: 100, height: 40 });
});

test('rotatedBoundingSize swaps the axes at a quarter turn', () => {
    const { width, height } = rotatedBoundingSize(100, 40, 90);
    assert.ok(Math.abs(width - 40) < 1e-9, `width=${width}`);
    assert.ok(Math.abs(height - 100) < 1e-9, `height=${height}`);
});

test('rotatedBoundingSize grows on the diagonal', () => {
    const { width, height } = rotatedBoundingSize(100, 100, 45);
    const expected = 100 * Math.SQRT2;
    assert.ok(Math.abs(width - expected) < 1e-9, `width=${width}`);
    assert.ok(Math.abs(height - expected) < 1e-9, `height=${height}`);
});

test('rotatedBoundingSize is symmetric about a half turn', () => {
    const a = rotatedBoundingSize(80, 30, 30);
    const b = rotatedBoundingSize(80, 30, 210);
    // Trig either side of a half turn agrees to within float noise, not bit-for-bit.
    assert.ok(Math.abs(a.width - b.width) < 1e-9, `${a.width} vs ${b.width}`);
    assert.ok(Math.abs(a.height - b.height) < 1e-9, `${a.height} vs ${b.height}`);
});
