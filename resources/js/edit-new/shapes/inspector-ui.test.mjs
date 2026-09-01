import assert from 'node:assert/strict';
import test from 'node:test';

import {
    activateShapeFillFromInspector,
    readShapeInspectorState,
    reflectShapeStateToInputs,
} from './inspector-ui.js';

/** Minimal stand-in for a shape-picker button. */
function shapeButton(shapeTool) {
    const classes = new Set();
    const attributes = {};
    return {
        dataset: { shapeTool },
        classList: {
            toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
            contains: (name) => classes.has(name),
        },
        setAttribute: (name, value) => { attributes[name] = value; },
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
    };
}

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


test('the shape buttons report the selected shape through aria-pressed', () => {
    const buttons = ['circle', 'triangle', 'square'].map(shapeButton);

    reflectShapeStateToInputs({ shapeType: 'triangle' }, { shapeTypeButtons: buttons });

    // The CSS class is what the eye follows; aria-pressed is the only thing a
    // screen reader can follow, and it was missing entirely before.
    assert.deepEqual(
        buttons.map((b) => b.getAttribute('aria-pressed')),
        ['false', 'true', 'false'],
    );
    assert.deepEqual(buttons.map((b) => b.classList.contains('is-active')), [false, true, false]);
});

test('changing the selected shape moves aria-pressed with it', () => {
    const buttons = ['circle', 'triangle', 'square'].map(shapeButton);

    reflectShapeStateToInputs({ shapeType: 'triangle' }, { shapeTypeButtons: buttons });
    reflectShapeStateToInputs({ shapeType: 'square' }, { shapeTypeButtons: buttons });

    assert.deepEqual(
        buttons.map((b) => b.getAttribute('aria-pressed')),
        ['false', 'false', 'true'],
        'the previously selected button must be cleared, not left reporting true',
    );
});
