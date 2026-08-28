import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DEFAULT_DRAW_SMOOTHING,
    drawSmoothingPasses,
    normalizeDrawSmoothing,
    normalizeSmoothCurvePoints,
    smoothDrawCurvePoints,
    smoothDrawCurveSegments,
    smoothDrawCurveSvgPathD,
} from './smooth-curve.js';

// NK_16 replaced the "smooth curve" tool button with a smoothing slider on
// the pen, so smoothing is no longer a tool type in the edit-new editor. The
// constant itself stays in tool-type.js because the pdf.js editor still
// drives smooth curve as a mode.

test('smoothing amounts clamp into [0, 1] and fall back when unusable', () => {
    assert.equal(normalizeDrawSmoothing(0.25), 0.25);
    assert.equal(normalizeDrawSmoothing(0), 0);
    assert.equal(normalizeDrawSmoothing(1), 1);
    assert.equal(normalizeDrawSmoothing(-3), 0);
    assert.equal(normalizeDrawSmoothing(42), 1);
    assert.equal(normalizeDrawSmoothing(undefined), DEFAULT_DRAW_SMOOTHING);
    assert.equal(normalizeDrawSmoothing('nonsense'), DEFAULT_DRAW_SMOOTHING);
    assert.equal(normalizeDrawSmoothing(null, 0), 0);
});

test('the slider drives averaging passes across its whole range', () => {
    assert.equal(drawSmoothingPasses(0), 0);
    assert.equal(drawSmoothingPasses(DEFAULT_DRAW_SMOOTHING), 2);
    assert.equal(drawSmoothingPasses(1), 4);
    // An unusable amount must not silently inherit the 58% default here.
    assert.equal(drawSmoothingPasses(undefined), 0);
});

test('zero smoothing keeps the exact points the pointer visited', () => {
    const source = [
        { x: 0, y: 0 },
        { x: 10, y: 12 },
        { x: 20, y: -8 },
        { x: 30, y: 10 },
    ];
    assert.deepEqual(smoothDrawCurvePoints(source, drawSmoothingPasses(0)), source);
    // Tension 0 collapses each control point onto its own segment endpoint,
    // which is a straight line — the polyline the user actually drew.
    const [segment] = smoothDrawCurveSegments(source, 0);
    assert.deepEqual(segment.control1, segment.start);
    assert.deepEqual(segment.control2, segment.end);
});

test('tension scales how far control points reach out', () => {
    const points = normalizeSmoothCurvePoints([
        { x: 0, y: 30 },
        { x: 20, y: 5 },
        { x: 50, y: 25 },
        { x: 80, y: 10 },
    ]);
    const reachAt = (tension) => {
        const [segment] = smoothDrawCurveSegments(points, tension);
        return Math.hypot(segment.control1.x - segment.start.x, segment.control1.y - segment.start.y);
    };
    assert.equal(reachAt(0), 0);
    assert.ok(reachAt(0.5) > 0);
    assert.ok(reachAt(1) > reachAt(0.5));
});

test('smoothDrawCurvePoints reduces pointer jitter while preserving endpoints', () => {
    const source = [
        { x: 0, y: 0 },
        { x: 10, y: 12 },
        { x: 20, y: -8 },
        { x: 30, y: 10 },
        { x: 40, y: 0 },
    ];
    const smoothed = smoothDrawCurvePoints(source, 2);
    assert.deepEqual(smoothed[0], source[0]);
    assert.deepEqual(smoothed.at(-1), source.at(-1));
    const sourceVariation = source.slice(1).reduce((sum, point, index) => sum + Math.abs(point.y - source[index].y), 0);
    const smoothVariation = smoothed.slice(1).reduce((sum, point, index) => sum + Math.abs(point.y - smoothed[index].y), 0);
    assert.ok(smoothVariation < sourceVariation);
});

test('smooth curve produces a continuous cubic SVG path', () => {
    const points = normalizeSmoothCurvePoints([
        { x: 0, y: 30 },
        { x: 20, y: 5 },
        { x: 50, y: 25 },
        { x: 80, y: 10 },
    ]);
    assert.equal(smoothDrawCurveSegments(points).length, 3);
    const path = smoothDrawCurveSvgPathD(points);
    assert.match(path, /^M 0\.000 30\.000 C /);
    assert.equal((path.match(/\bC\b/g) || []).length, 3);
    assert.match(path, /80\.000 10\.000$/);
});

test('callers that pass no amount keep the pre-NK_16 fit', () => {
    // The pdf.js editor shares this module and calls smoothDrawCurveSegments /
    // smoothDrawCurveSvgPathD without a tension, so the default must stay 1.
    const points = normalizeSmoothCurvePoints([
        { x: 0, y: 30 },
        { x: 20, y: 5 },
        { x: 50, y: 25 },
        { x: 80, y: 10 },
    ]);
    assert.deepEqual(smoothDrawCurveSegments(points), smoothDrawCurveSegments(points, 1));
    assert.equal(smoothDrawCurveSvgPathD(points), smoothDrawCurveSvgPathD(points, 1));
});
