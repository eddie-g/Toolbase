import assert from 'node:assert/strict';
import test from 'node:test';

import {
    normalizeSmoothCurvePoints,
    smoothDrawCurvePoints,
    smoothDrawCurveSegments,
    smoothDrawCurveSvgPathD,
} from './smooth-curve.js';
import {
    DIRECT_DRAW_TOOL_SMOOTH_CURVE,
    normalizeDirectDrawTool,
} from './tool-type.js';

test('smooth curve is a dedicated draw tool type', () => {
    assert.equal(normalizeDirectDrawTool('smooth-curve'), DIRECT_DRAW_TOOL_SMOOTH_CURVE);
    assert.equal(normalizeDirectDrawTool('curve'), 'pen');
    assert.equal(normalizeDirectDrawTool('eraser'), 'eraser');
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
