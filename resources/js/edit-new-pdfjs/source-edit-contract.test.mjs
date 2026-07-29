import test from 'node:test';
import assert from 'node:assert/strict';
import {
    clampSourceMaskRectToCell,
    dominantSourceRunFontSize,
    isPdfjsPromotedExtractionAnnotation,
    isPdfjsSourceBackedTextAnnotation,
    naturalSourceLineSeparator,
    pdfjsFontWeightFromFaceName,
    pdfjsPromotedOverlayShouldRenderAsPersistedOverlay,
    pdfjsSourceOverlayShouldUseSourceBoxInEditMode,
    promotedTextEditFlags,
    reconcileRichTextRunWhitespace,
    resolveRichTextRunFontIdentity,
    restoreExplicitSourceWhitespace,
    richTextViewportCssLength,
    sourceNaturalizedGapText,
    sourceRunDrawnUnderlineMetadata,
    sourceRunTextsUseDistributedLeaderSpacing,
    sourceSpanDrawnUnderlineSegments,
    sourceSpanDrawnUnderlineRanges,
    sourceVisualLineSlots,
    splitSourceRunsAtDrawnUnderlineRanges,
    textResizeCollisionLimits,
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

test('drops a stale embedded PDF font after changing the authored family to Montserrat', () => {
    const original = resolveRichTextRunFontIdentity({
        computedFontFamily: 'g_d0_f13',
        sourcePdfFontName: 'ETILIDL+HelveticaNeueLTStd-BdCn',
        computedDocumentFont: {
            source: 'pdfjs-runtime',
            pdfFontName: 'ETILIDL+HelveticaNeueLTStd-BdCn',
            cleanName: 'HelveticaNeueLTStd-BdCn',
        },
        styleDirty: false,
    });
    assert.deepEqual(original, {
        fontFamily: 'ETILIDL+HelveticaNeueLTStd-BdCn',
        fontSourceName: 'HelveticaNeueLTStd-BdCn',
    });

    const changed = resolveRichTextRunFontIdentity({
        computedFontFamily: 'Montserrat',
        // Source-fidelity markup still has this attribute after the picker
        // changes the box-level family. It must not leak into the export run.
        sourcePdfFontName: 'ETILIDL+HelveticaNeueLTStd-BdCn',
        computedDocumentFont: null,
        styleDirty: true,
    });
    assert.deepEqual(changed, {
        fontFamily: 'Montserrat',
        fontSourceName: 'Montserrat',
    });
});

test('recognizes abbreviated bold-condensed PDF face names', () => {
    assert.equal(pdfjsFontWeightFromFaceName('ETLIDL+HelveticaNeueLTStd-BdCn'), '700');
    assert.equal(pdfjsFontWeightFromFaceName('HelveticaNeueLTStd-BdIt'), '700');
    assert.equal(pdfjsFontWeightFromFaceName('BBBPSR+HelveticaNeueLTStd-Cn'), '400');
});

test('reads the weight axis of variable-font instances before the style name', () => {
    assert.equal(pdfjsFontWeightFromFaceName('MontserratThin_700wght'), '700');
    assert.equal(pdfjsFontWeightFromFaceName('ABCDEF+MontserratThin_300wght'), '300');
    assert.equal(pdfjsFontWeightFromFaceName('MontserratThin'), '300');
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

test('preserves a spaced suffix and source typography when promoted_1_8 is edited', () => {
    const sourceText = (
        'The provider or facility will not condition treatment on whether I sign the authorization. '
        + 'I may be charged for copies in accordance with state law. '
        + 'This authorization will not expire unless revoked by you or your legal representative '
        + 'or upon notification of death.'
    );
    const editedText = `${sourceText} test`;
    const runs = reconcileRichTextRunWhitespace([
        {
            type: 'text',
            text: 'The provider or facility will not condition treatment on whether I sign the authorization. ',
            fontWeight: '400',
            fontSourceName: 'HelveticaNeueLTStd-Cn',
        },
        {
            type: 'text',
            text: 'I may be charged for copies in accordance with state law.',
            fontWeight: '700',
            fontSourceName: 'HelveticaNeueLTStd-BdCn',
        },
        {
            type: 'text',
            // Reproduce the first-input DOM drift: the leading space was
            // omitted from the final regular run.
            text: 'This authorization will not expire unless revoked by you or your legal representative or upon notification of death.test',
            fontWeight: '400',
            fontSourceName: 'HelveticaNeueLTStd-Cn',
        },
    ], editedText);

    assert.equal(runs.map((run) => run.text || '').join(''), editedText);
    assert.equal(runs.at(-1).text.endsWith('death. test'), true);
    assert.equal(runs.some((run) => (
        run.fontWeight === '700'
        && run.fontSourceName === 'HelveticaNeueLTStd-BdCn'
        && run.text.includes('I may be charged')
    )), true);

    assert.deepEqual(promotedTextEditFlags({
        isPromoted: true,
        currentText: editedText,
        sourceText,
        promotedDirty: false,
        preserveSourceTypography: false,
    }), {
        textChanged: true,
        promotedDirty: true,
        preserveSourceTypography: true,
    });
});

test('keeps the f1040s3 line 13 separator on the regular rich-text run', () => {
    const runs = reconcileRichTextRunWhitespace([
        { type: 'text', text: '13', fontWeight: '700', fontSize: 9.06 },
        {
            type: 'text',
            text: 'Other payments or refundable credits:',
            fontWeight: '400',
            fontSize: 9.06,
        },
    ], '13 Other payments or refundable credits:');

    assert.deepEqual(runs.map((run) => ({
        text: run.text,
        fontWeight: run.fontWeight,
    })), [
        { text: '13', fontWeight: '700' },
        { text: ' Other payments or refundable credits:', fontWeight: '400' },
    ]);
    assert.equal(runs.map((run) => run.text).join(''), '13 Other payments or refundable credits:');
});

test('preserves captured distributed-leader gaps when source markup is naturalized for resize', () => {
    const leaderRuns = ['years', ...Array(24).fill('.')];
    assert.equal(sourceRunTextsUseDistributedLeaderSpacing(leaderRuns), true);
    assert.equal(sourceRunTextsUseDistributedLeaderSpacing(['13', 'Other payments']), false);

    assert.equal(sourceNaturalizedGapText({
        originalSpaceCount: 4,
        preserveCapturedSpacing: true,
    }), '    ');
    assert.equal(sourceNaturalizedGapText({
        originalSpaceCount: 4,
        preserveCapturedSpacing: false,
    }), ' ');
    assert.equal(sourceNaturalizedGapText({
        atLineStart: true,
        originalSpaceCount: 4,
        preserveCapturedSpacing: true,
    }), '');
    assert.equal(sourceNaturalizedGapText({
        currentText: '  x',
        originalSpaceCount: 4,
        preserveCapturedSpacing: true,
        userMutated: true,
    }), '  x');
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

test('limits text-box expansion at neighbouring text on all four edges', () => {
    const start = { left: 100, top: 100, width: 200, height: 120 };
    const limits = textResizeCollisionLimits(start, [
        { left: 20, top: 130, width: 50, height: 20 },
        { left: 330, top: 130, width: 80, height: 20 },
        { left: 150, top: 40, width: 40, height: 30 },
        { left: 150, top: 250, width: 40, height: 30 },
        // This is already inside the starting box and must not freeze resize.
        { left: 120, top: 120, width: 60, height: 20 },
        // Diagonal text does not constrain an edge it cannot overlap.
        { left: 400, top: 300, width: 30, height: 20 },
    ], { left: 0, top: 0, width: 500, height: 500 }, 2);

    assert.deepEqual(limits, {
        left: 72,
        top: 72,
        right: 328,
        bottom: 248,
    });
});

test('uses page edges when no neighbouring text blocks expansion', () => {
    assert.deepEqual(textResizeCollisionLimits(
        { left: 100, top: 100, width: 200, height: 120 },
        [],
        { left: 0, top: 0, width: 500, height: 500 },
    ), {
        left: 0,
        top: 0,
        right: 500,
        bottom: 500,
    });
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

test('moves only the words covered by a drawn PDF underline segment', () => {
    const prefix = 'amended by section 2 of the ';
    const underlined = 'Paperwork Reduction Act of 1995';
    const suffix = '. You do not need to answer these questions unless we';
    const text = `${prefix}${underlined}${suffix}`;
    const glyphWidth = 5;
    const bboxX = 18;
    const sourceSpan = {
        text,
        bbox: [bboxX, 346.84, bboxX + (text.length * glyphWidth), 357.29],
        origin: [bboxX, 354.892],
        font_size: 10.45,
        has_drawn_underline: true,
        drawn_underline_segments: [{
            x0: bboxX + (prefix.length * glyphWidth),
            x1: bboxX + ((prefix.length + underlined.length) * glyphWidth),
            y: 355.905,
            width: 0.37,
        }],
    };
    const ranges = sourceSpanDrawnUnderlineRanges(sourceSpan);
    const runs = splitSourceRunsAtDrawnUnderlineRanges([{
        text,
        leftPx: 60,
        rightPx: 60 + (text.length * glyphWidth),
        topPx: 100,
        bottomPx: 111,
        underlineRanges: ranges,
    }], (value) => String(value).length * glyphWidth);

    assert.deepEqual(runs.map((run) => ({
        text: run.text,
        underline: run.underline,
    })), [
        { text: prefix, underline: false },
        { text: underlined, underline: true },
        { text: suffix, underline: false },
    ]);
    assert.equal(runs[0].rightPx, runs[1].leftPx);
    assert.equal(runs[1].rightPx, runs[2].leftPx);

    const metadata = sourceRunDrawnUnderlineMetadata(runs.map((run) => ({
        ...run,
        hasDrawnUnderline: true,
        sourceUnderlineSegments: sourceSpan.drawn_underline_segments,
    })));
    assert.equal(metadata.hasDrawnUnderline, true);
    assert.deepEqual(metadata.segments, sourceSpan.drawn_underline_segments);
    assert.equal(splitSourceRunsAtDrawnUnderlineRanges([{
        text: prefix,
        underlineRanges: [],
        underlineRangesPrecise: true,
        hasDrawnUnderline: true,
    }])[0].underline, false);
});

test('rejects a nearby f1040 form rule as a drawn text underline', () => {
    const sourceSpan = {
        text: '15 Add lines 9 through 12 and 14. Enter here and on Form 1040, 1040-SR, or 1040-NR, line 31',
        bbox: [64.799995, 601.57782, 434.952057, 610.57782],
        origin: [64.799995, 608.926025],
        font_size: 9,
        has_drawn_underline: true,
        drawn_underline_segments: [
            { x0: 64.8, x1: 302.65, y: 612, width: 1 },
            { x0: 302.15, x1: 417.85, y: 612, width: 1 },
        ],
    };

    assert.deepEqual(sourceSpanDrawnUnderlineSegments(sourceSpan), []);
    // Explicit segments are authoritative: once rejected, the stale boolean
    // must not turn the entire annotation into an underline.
    assert.deepEqual(sourceSpanDrawnUnderlineRanges(sourceSpan), []);
    assert.equal(splitSourceRunsAtDrawnUnderlineRanges([{
        text: sourceSpan.text,
        underlineRanges: sourceSpanDrawnUnderlineRanges(sourceSpan),
        underlineRangesPrecise: true,
        hasDrawnUnderline: false,
    }])[0].underline, false);
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
