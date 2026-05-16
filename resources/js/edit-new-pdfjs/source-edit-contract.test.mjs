import test from 'node:test';
import assert from 'node:assert/strict';
import {
    isPdfjsSourceBackedTextAnnotation,
    pdfjsSourceOverlayShouldUseSourceBoxInEditMode,
} from './source-edit-contract.js';

const baseSourceOverlay = {
    id: 'pdfjs_4037_0_source:0:53',
    type: 'text',
    savedTextOverlay: true,
    pdfjsEditorMode: 'source',
    movedTextOverlay: false,
    userForcedRichText: false,
    pdfjsSourceX: 47.953125,
    pdfjsSourceY: 261.928125,
    pdfjsSourceW: 538.6199707031251,
    pdfjsSourceH: 6.6,
    pdfjsAnchorUid: 'source:0:53',
    pdfjsSourceText: 'or a maximum rental charge of $1,242.20 plus applicable taxes',
    text: 'or a maximum rental charge of $1,942.20 plus applicable taxes',
};

test('recognizes PDF.js source-backed text annotations', () => {
    assert.equal(isPdfjsSourceBackedTextAnnotation(baseSourceOverlay), true);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, pdfjsSourceX: null, pdfjsSourceY: null, pdfjsAnchorUid: null, pdfjsSourceText: null }), false);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, type: 'shape' }), false);
});

test('uses source box for unmoved saved source overlays in edit mode', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode(baseSourceOverlay, true), true);
});

test('does not use source box outside edit mode', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode(baseSourceOverlay, false), false);
});

test('does not use source box for moved overlays', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({ ...baseSourceOverlay, movedTextOverlay: true }, true), false);
});

test('does not use source box for deleted overlays', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({ ...baseSourceOverlay, pdfjsDeleted: true }, true), false);
});

test('does not use source box for rich/manual source overlays', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({ ...baseSourceOverlay, pdfjsEditorMode: 'rich' }, true), false);
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({ ...baseSourceOverlay, userForcedRichText: true }, true), false);
});
