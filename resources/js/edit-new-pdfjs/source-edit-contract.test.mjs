import test from 'node:test';
import assert from 'node:assert/strict';
import {
    isPdfjsPromotedExtractionAnnotation,
    isPdfjsSourceBackedTextAnnotation,
    pdfjsPromotedOverlayShouldRenderAsPersistedOverlay,
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

const basePromotedOverlay = {
    id: 'promoted_1_35',
    type: 'text',
    promotedFromExtraction: true,
    promotedDirty: false,
    userAuthored: false,
    pdfjsDeleted: false,
    originalText: 'Partnership',
    pdfjsSourceText: 'Partnership',
    text: 'Partnership',
};

test('recognizes PDF.js source-backed text annotations', () => {
    assert.equal(isPdfjsSourceBackedTextAnnotation(baseSourceOverlay), true);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, pdfjsSourceX: null, pdfjsSourceY: null, pdfjsAnchorUid: null, pdfjsSourceText: null }), false);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, type: 'shape' }), false);
});

test('uses source box for unmoved saved source overlays in edit mode', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({
        ...baseSourceOverlay,
        text: baseSourceOverlay.pdfjsSourceText,
    }, true), true);
});

test('keeps edited source text as a visible persisted overlay in edit mode', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode(baseSourceOverlay, true), false);
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

test('does not use source box for style-dirty source overlays', () => {
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({ ...baseSourceOverlay, text: baseSourceOverlay.pdfjsSourceText, styleDirty: true }, true), false);
});

test('recognizes promoted extraction annotations', () => {
    assert.equal(isPdfjsPromotedExtractionAnnotation(basePromotedOverlay), true);
    assert.equal(isPdfjsPromotedExtractionAnnotation({ ...basePromotedOverlay, promotedFromExtraction: false }), true);
    assert.equal(isPdfjsPromotedExtractionAnnotation({ ...basePromotedOverlay, id: 'pdfjs_4099_0_35', promotedFromExtraction: false }), false);
});

test('keeps edited promoted source text as a visible persisted overlay', () => {
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({
        ...basePromotedOverlay,
        text: 'Dogership',
        promotedDirty: true,
    }), true);
});

test('does not render clean promoted source text as a persisted overlay', () => {
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(basePromotedOverlay), false);
});

test('keeps style-only promoted edits as visible persisted overlays', () => {
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({
        ...basePromotedOverlay,
        savedTextOverlay: true,
        pdfjsSourceX: 10,
        pdfjsSourceY: 20,
        pdfjsSourceW: 50,
        pdfjsSourceH: 10,
        pdfjsEditorMode: 'source',
        styleDirty: true,
    }), true);
    assert.equal(pdfjsSourceOverlayShouldUseSourceBoxInEditMode({
        ...basePromotedOverlay,
        savedTextOverlay: true,
        pdfjsSourceX: 10,
        pdfjsSourceY: 20,
        pdfjsSourceW: 50,
        pdfjsSourceH: 10,
        pdfjsEditorMode: 'source',
        styleDirty: true,
    }, true), false);
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({
        ...basePromotedOverlay,
        richTextHtml: '<b>Partnership</b>',
    }), true);
});

test('keeps moved promoted source text as a visible persisted overlay', () => {
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({
        ...basePromotedOverlay,
        savedTextOverlay: true,
        movedTextOverlay: true,
        pdfjsSourceX: 73.2,
        pdfjsSourceY: 192.79,
        pdfjsSourceW: 40,
        pdfjsSourceH: 8,
        pdfX: 13.88,
        pdfY: 266.93,
    }), true);
});
