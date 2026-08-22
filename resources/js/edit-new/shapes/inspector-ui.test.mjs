import assert from 'node:assert/strict';
import test from 'node:test';

import {
    activateShapeFillFromInspector,
    readShapeInspectorState,
} from './inspector-ui.js';

test('editing a visible fill control re-enables a transparent shape fill', () => {
    const refs = {
        shapeFillColorInput: { value: '#d6c914' },
        shapeFillOpacityInput: { value: '45' },
        shapeFillTransparentInput: { checked: true },
    };

    assert.equal(activateShapeFillFromInspector(refs), true);
    assert.equal(refs.shapeFillTransparentInput.checked, false);

    const state = readShapeInspectorState(refs);
    assert.equal(state.fillColor, '#d6c914');
    assert.equal(state.fillOpacity, 0.45);
    assert.equal(state.fillTransparent, false);
});

test('zero opacity remains an intentionally invisible fill', () => {
    const refs = {
        shapeFillOpacityInput: { value: '0' },
        shapeFillTransparentInput: { checked: true },
    };

    assert.equal(activateShapeFillFromInspector(refs), false);
    assert.equal(refs.shapeFillTransparentInput.checked, true);
});
