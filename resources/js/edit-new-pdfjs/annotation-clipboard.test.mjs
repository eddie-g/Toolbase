import test from 'node:test';
import assert from 'node:assert/strict';

import { elementAcceptsNativeClipboard } from './annotation-clipboard.js';

function element({ tagName = 'DIV', type = '', contentEditable = false, closest = null } = {}) {
    return {
        tagName,
        type,
        isContentEditable: contentEditable,
        closest: () => closest,
    };
}

test('shape inspector controls do not block annotation copy and paste shortcuts', () => {
    assert.equal(elementAcceptsNativeClipboard(element({ tagName: 'INPUT', type: 'range' })), false);
    assert.equal(elementAcceptsNativeClipboard(element({ tagName: 'INPUT', type: 'color' })), false);
    assert.equal(elementAcceptsNativeClipboard(element({ tagName: 'BUTTON' })), false);
});

test('text editing controls retain native copy and paste behavior', () => {
    assert.equal(elementAcceptsNativeClipboard(element({ tagName: 'INPUT', type: 'text' })), true);
    assert.equal(elementAcceptsNativeClipboard(element({ tagName: 'TEXTAREA' })), true);
    assert.equal(elementAcceptsNativeClipboard(element({ contentEditable: true })), true);
});
