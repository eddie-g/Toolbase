import test from 'node:test';
import assert from 'node:assert/strict';
import {
    clampSourceMaskRectToCell,
    dominantSourceRunFontSize,
    isPdfjsPromotedExtractionAnnotation,
    isPdfjsSourceBackedTextAnnotation,
    naturalSourceLineSeparator,
    pdfjsPromotedOverlayShouldRenderAsPersistedOverlay,
    pdfjsSourceOverlayShouldUseSourceBoxInEditMode,
    reconcileRichTextRunWhitespace,
    restoreExplicitSourceWhitespace,
    richTextViewportCssLength,
    sourceVisualLineSlots,
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

test('converts versioned PDF-point rich text to viewport pixels', () => {
    assert.equal(richTextViewportCssLength('21pt', { pointScale: 10 / 3 }), '70px');
    assert.equal(richTextViewportCssLength('25.2pt', { pointScale: 10 / 3 }), '84px');
    assert.equal(richTextViewportCssLength('50px', { pixelRatio: 0.5 }), '25px');
    assert.equal(richTextViewportCssLength('1.2em', { pointScale: 3 }), '1.2em');
});

test('repairs whitespace-only drift without losing mixed run styles', () => {
    const runs = reconcileRichTextRunWhitespace([
        { type: 'text', text: 'Sales', fontWeight: '700', fontSize: 21 },
        { type: 'text', text: 'Invoice', fontWeight: '400', fontSize: 21 },
        { type: 'break' },
        { type: 'text', text: 'Total', fontWeight: '400', fontSize: 12 },
    ], 'Sales Invoice\nTotal');

    assert.deepEqual(runs.map((run) => (
        run.type === 'break'
            ? { type: 'break' }
            : { type: run.type, text: run.text, fontWeight: run.fontWeight, fontSize: run.fontSize }
    )), [
        { type: 'text', text: 'Sales', fontWeight: '700', fontSize: 21 },
        { type: 'text', text: ' Invoice', fontWeight: '400', fontSize: 21 },
        { type: 'break' },
        { type: 'text', text: 'Total', fontWeight: '400', fontSize: 12 },
    ]);
    assert.deepEqual(reconcileRichTextRunWhitespace([
        { type: 'text', text: 'Different', fontWeight: '700' },
    ], 'Content'), []);
});

test('clamps an expanded source mask to midpoint-owned neighbouring rows', () => {
    const clamped = clampSourceMaskRectToCell(
        { left: 1262, top: 548.73, width: 175.91, height: 34.98 },
        { left: 1264.78, top: 555.61, width: 170.38, height: 25.33 },
        [
            { left: 1264.78, top: 535.34, width: 130, height: 25.33 },
            { left: 1264.78, top: 575.88, width: 88.73, height: 25.33 },
        ],
    );

    assert.ok(Math.abs(clamped.top - 558.14) < 0.001);
    assert.ok(Math.abs(clamped.bottom - 578.41) < 0.001);
    assert.equal(clamped.left, 1262);
    assert.ok(Math.abs(clamped.right - 1437.91) < 0.001);
});

test('assigns horizontally overlapping same-row masks to one source column', () => {
    const clamped = clampSourceMaskRectToCell(
        { left: 90, top: 100, width: 125, height: 20 },
        { left: 100, top: 100, width: 100, height: 20 },
        [
            { left: 20, top: 100, width: 100, height: 20 },
            { left: 180, top: 100, width: 80, height: 20 },
            // A distant, differently-sized run on the row does not constrain
            // the mask because the mask never reaches it.
            { left: 500, top: 100, width: 10, height: 20 },
        ],
    );

    assert.equal(clamped.left, 110);
    assert.equal(clamped.right, 185);
    assert.equal(clamped.top, 100);
    assert.equal(clamped.bottom, 120);
});

test('restores an explicit PDF.js whitespace span without inventing word breaks', () => {
    assert.equal(
        restoreExplicitSourceWhitespace(
            'SatelliteTV antennas will be removed',
            'Satellite TV antennas will be removed',
        ),
        'Satellite TV antennas will be removed',
    );
    assert.equal(
        restoreExplicitSourceWhitespace('PAYMENTSARE DUE', 'PAYMENT SHARE DUE'),
        'PAYMENTSARE DUE',
    );
    assert.equal(
        restoreExplicitSourceWhitespace('Label     Value', 'Label Value'),
        'Label     Value',
    );
});

test('preserves exact source line slots and recognizes a double-height break', () => {
    const slots = sourceVisualLineSlots([
        537.266,
        585.453,
        609.531,
        633.625,
    ]);
    assert.equal(slots.length, 4);
    assert.ok(Math.abs(slots[0].slotHeightPx - 48.187) < 0.001);
    assert.equal(slots[0].breakCount, 2);
    assert.ok(Math.abs(slots[1].slotHeightPx - 24.078) < 0.001);
    assert.equal(slots[1].breakCount, 1);
    assert.equal(slots[3].slotHeightPx, 0);
});

test('uses paragraph text rather than a larger bullet glyph as the source font reference', () => {
    assert.equal(dominantSourceRunFontSize([
        { text: 'Household employers.', fontSizePx: 25.3333 },
        { text: '\u2022', fontSizePx: 30.4 },
        { text: 'You will have federal income tax withheld from wages,', fontSizePx: 25.3333 },
        { text: '\u2022', fontSizePx: 30.4 },
        { text: 'You would be required to make estimated tax payments.', fontSizePx: 25.3333 },
    ]), 25.3333);
    assert.equal(dominantSourceRunFontSize([
        { text: '\u2022', fontSizePx: 30.4 },
        { text: '\u2022', fontSizePx: 30.4 },
    ]), 30.4);
});

test('joins extracted visual wraps while preserving semantic paragraph and list breaks', () => {
    assert.equal(naturalSourceLineSeparator('When estimating the tax on your', '2026 tax return, include your household employment'), ' ');
    assert.equal(naturalSourceLineSeparator('taxes if either of the following applies.', '\u2022 You will have tax withheld'), '\n');
    assert.equal(naturalSourceLineSeparator('The Scope of Work includes:', '1. Remove the existing shingles'), '\n');
    assert.equal(naturalSourceLineSeparator('The Scope of Work includes:', 'Remove the existing shingles', 2), '\n\n');
    assert.equal(naturalSourceLineSeparator('hyphen-', 'ated word'), '');
});

test('recognizes PDF.js source-backed text annotations', () => {
    assert.equal(isPdfjsSourceBackedTextAnnotation(baseSourceOverlay), true);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, pdfjsSourceX: null, pdfjsSourceY: null, pdfjsAnchorUid: null, pdfjsSourceText: null }), false);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, type: 'shape' }), false);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ ...baseSourceOverlay, userCreated: true, skipPdfjsSourceMask: true }), true);
    assert.equal(isPdfjsSourceBackedTextAnnotation({ type: 'text', text: 'Standalone', userCreated: true, skipPdfjsSourceMask: true }), false);
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

test('keeps a clean multi-line promoted block paragraph-grouped despite inherent rich editor mode', () => {
    const multiLine = {
        ...basePromotedOverlay,
        id: 'promoted_4_3',
        originalText: 'Box 1.\nBox 2.\nBox 3.\nBox 4.',
        pdfjsSourceText: 'Box 1.\nBox 2.\nBox 3.\nBox 4.',
        text: 'Box 1.\nBox 2.\nBox 3.\nBox 4.',
        sourceLineBBoxes: [{}, {}, {}, {}],
        pdfjsEditorMode: 'rich',
    };
    // Multi-line blocks are inherently forced into rich editor mode by their
    // newlines, so 'rich' alone must not flip them to a single bounding box.
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(multiLine), false);
    // A genuine edit/move/style change still flips it to a persisted overlay.
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({ ...multiLine, movedTextOverlay: true }), true);
});

test('keeps a single-line promoted overlay in rich mode as a persisted overlay', () => {
    assert.equal(pdfjsPromotedOverlayShouldRenderAsPersistedOverlay({
        ...basePromotedOverlay,
        pdfjsEditorMode: 'rich',
    }), true);
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
