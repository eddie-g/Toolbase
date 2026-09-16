import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('./main.js', import.meta.url), 'utf8');
const functionSource = (name, nextName) => source.slice(
    source.indexOf(`function ${name}(`),
    source.indexOf(`function ${nextName}(`),
);
const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
const sourceLayoutCompatible = (current, original) => current.length === original.length;
const remapRuns = new Function('normalizeSourceRunText', 'sourceRunTextWithSyntheticGaps', `
    ${functionSource('remapSourceRunItemsForCurrentText', 'remapSourceRunItemsForCurrentTextLines')}
    return remapSourceRunItemsForCurrentText;
`)(normalize, (items, start = 0, end = items.length) => normalize(items.slice(start, end).map((item) => item.text).join(' ')));
const detectRules = new Function(`
    ${functionSource('detectHorizontalCanvasRuleGaps', 'applyDeletedEraseMaskSegments')}
    return detectHorizontalCanvasRuleGaps;
`)();
const maskRuleOptions = [];
const refreshMask = new Function('window', 'applyDeletedEraseMaskSegments', `
    ${functionSource('scheduleRuleAwareMaskRefresh', 'createDeletedEraseElement')}
    return scheduleRuleAwareMaskRefresh;
`)({ requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() },
    (mask, rect, page, options) => maskRuleOptions.push(options));
refreshMask({}, {}, {}, { minRectWidth: 18 });

test('shorter and longer replacements in a middle styled run retain source geometry and font', () => {
    const items = [
        { text: '11', leftPx: 0, rightPx: 12, fontWeight: '700' },
        { text: 'Combine lines. This is your', leftPx: 40, rightPx: 200, fontWeight: '400' },
        { text: 'additional income', leftPx: 212, rightPx: 300, fontWeight: '700' },
        { text: '. Enter here', leftPx: 300, rightPx: 390, fontWeight: '400' },
    ];
    for (const replacement of ['taxes', 'deductions', 'taxable income']) {
        const remapped = remapRuns(items, `11 Combine lines. This is your additional ${replacement} . Enter here`);
        assert.ok(remapped);
        assert.equal(remapped[2].text, `additional ${replacement}`);
        assert.equal(remapped[2].sourceText, 'additional income');
        assert.equal(remapped[2].textChangedFromSource, true);
        items.forEach((item, index) => {
            assert.equal(remapped[index].leftPx, item.leftPx);
            assert.equal(remapped[index].rightPx, item.rightPx);
            assert.equal(remapped[index].fontWeight, item.fontWeight);
        });
        assert.equal(items[2].text, 'additional income');
    }
});

test('single-run replacements retain their source identity and do not resurrect the old text', () => {
    const remapped = remapRuns([{ text: '2a', fontWeight: '700', leftPx: 10, rightPx: 24 }], '44');
    assert.equal(remapped[0].text, '44');
    assert.equal(remapped[0].sourceText, '2a');
});

test('edits that cannot be assigned to a single captured run still use the reflow path', () => {
    const items = [{ text: 'Start' }, { text: 'middle' }, { text: 'End' }];
    assert.equal(remapRuns(items, 'Different entirely unrelated'), null);
});

test('captured paragraph rows survive arbitrary text lengths but release for structural and style changes', () => {
    const baseline = '7. Original heading\nIndented body\nLast row';
    const check = (text, overrides = {}, nestedBreak = false) => {
        const content = { querySelectorAll: () => text.split('\n').map((row) => ({
            textContent: row, querySelector: () => nestedBreak,
        })) };
        const box = { dataset: { sourceSpanEditActive: '1', ...overrides }, classList: { contains: () => true } };
        return new Function('persistedAnnotationsById', 'flattenedTextFromSourceSpanMarkup',
            'selectedBoxTextElement', 'flattenedEditBaselineForTextElement', 'promotedSourceLayoutCompatibleTextEdit', `
            ${functionSource('promotedSourceBlockEditKeepsExactLayout', 'clearSourceFidelitySpanEditMarkup')}
            return promotedSourceBlockEditKeepsExactLayout;
        `)({ get: () => ({ pdfjsSourceText: baseline }) }, () => text, () => content,
            () => baseline, sourceLayoutCompatible)(box);
    };
    for (const replacement of ['x', 'different', 'a substantially longer replacement']) {
        assert.equal(check(baseline.replace('Indented body', replacement)), true);
    }
    const changed = baseline.replace('Indented body', 'x');
    assert.equal(check(`${changed}\nNew row`), false);
    assert.equal(check(changed.replace('\n', ' ')), false);
    assert.equal(check(changed, {}, true), false);
    for (const flag of ['styleDirty', 'userForcedRichText', 'userSizedTextBox']) {
        assert.equal(check(changed, { [flag]: '1' }), false);
    }
});

test('saved captured-row intent survives unequal-length reconstruction but not reflow, resize, or styling', () => {
    const keepsGeometry = new Function('isPromotedExtractionAnnotation', 'boolish', 'normalizeComparableText',
        'promotedSourceLayoutCompatibleTextEdit', `
        ${functionSource('promotedOverlayKeepsSourceBlockGeometry', 'isUserCreatedTextAnnotation')}
        return promotedOverlayKeepsSourceBlockGeometry;
    `)(() => true, (value) => value === true || value === '1', normalize, sourceLayoutCompatible);
    const annotation = {
        text: '7. Heading\nNew body text', pdfjsSourceText: '7. Heading\nBody', pdfjsPreserveSourceRows: true,
    };
    assert.equal(keepsGeometry(annotation), true);
    assert.equal(keepsGeometry({ ...annotation, pdfjsPreserveSourceRows: false }), false);
    assert.equal(keepsGeometry({ ...annotation, text: `${annotation.text}\nExtra row` }), false);
    for (const flag of ['styleDirty', 'userForcedRichText', 'userSizedTextBox', 'promotedReflowEnabled']) {
        assert.equal(keepsGeometry({ ...annotation, [flag]: true }), false);
    }
});

test('commit retains naturalized paragraph insets while untouched source cleanup still removes temporary layout', () => {
    for (const naturalized of [false, true]) {
        const original = { paddingLeft: '80px', paddingTop: '5px', textIndent: '-24px', width: '100%', height: '100%', lineHeight: '22px' };
        const content = { style: { ...original } };
        const box = { dataset: { promotedSourceBlockExactEditLayout: '1', sourceSpanNaturalized: naturalized ? '1' : '0' } };
        let horizontalFitCleared = false;
        const clearLayout = new Function('selectedBoxTextElement', 'clearPromotedSourceBlockEditHorizontalFit', `
            ${functionSource('clearPromotedSourceBlockEditLayout', 'alignPromotedSourceEditGlyphsToCapturedRanges')}
            return clearPromotedSourceBlockEditLayout;
        `)(() => content, () => { horizontalFitCleared = true; });
        clearLayout(box);
        assert.deepEqual(content.style, naturalized ? original : Object.fromEntries(Object.keys(original).map((key) => [key, ''])));
        assert.equal(box.dataset.promotedSourceBlockExactEditLayout, undefined);
        assert.equal(horizontalFitCleared, true);
    }
});

function canvasPage({ ruleLeft, ruleRight, pixelRatio = 1, renderScale = 2.533 }) {
    const width = 120;
    const height = 50;
    const canvas = {
        width: width * pixelRatio,
        height: height * pixelRatio,
        getBoundingClientRect: () => ({ left: 0, top: 0, width, height }),
        getContext: () => ({
            getImageData: (left, top, sampleWidth, sampleHeight) => {
                const data = new Uint8ClampedArray(sampleWidth * sampleHeight * 4).fill(255);
                for (let row = 0; row < sampleHeight; row += 1) {
                    for (let column = 0; column < sampleWidth; column += 1) {
                        const canvasX = (left + column) / pixelRatio;
                        const canvasY = (top + row) / pixelRatio;
                        if (canvasX < ruleLeft || canvasX >= ruleRight || canvasY < 25 || canvasY >= 27) continue;
                        const offset = (row * sampleWidth + column) * 4;
                        data[offset] = 0;
                        data[offset + 1] = 0;
                        data[offset + 2] = 0;
                    }
                }
                return { data };
            },
        }),
    };
    return {
        getBoundingClientRect: () => ({ left: 0, top: 0, width, height }),
        querySelector: (selector) => selector === ':scope canvas' ? canvas : { dataset: { scale: String(renderScale) } },
    };
}

test('all delayed stationary-mask refreshes reject digit strokes confined to the text box', () => {
    assert.equal(maskRuleOptions.length, 4);
    const rect = { left: 40, top: 10, width: 28, height: 30 };
    for (const pixelRatio of [1, 2, 3]) {
        const page = canvasPage({ ruleLeft: 43, ruleRight: 65, pixelRatio });
        assert.ok(detectRules(page, rect, { minRectWidth: 18 }).length, 'Old detector must reproduce the false rule');
        for (const options of maskRuleOptions) assert.deepEqual(detectRules(page, rect, options), []);
    }
});

test('stationary masks preserve real rules extending beyond narrow text boxes at multiple scales', () => {
    for (const pixelRatio of [1, 2, 3]) {
        for (const renderScale of [1, 2.533, 4]) {
            const page = canvasPage({ ruleLeft: 0, ruleRight: 120, pixelRatio, renderScale });
            for (const width of [8, 28, 70]) {
                const gaps = detectRules(page, { left: 30, top: 10, width, height: 30 }, maskRuleOptions[0]);
                assert.equal(gaps.length, 1);
                assert.ok(gaps[0].top <= 15 && gaps[0].top + gaps[0].height >= 17);
            }
        }
    }
});

test('a long rule ending at the source rectangle still survives masking', () => {
    const page = canvasPage({ ruleLeft: 40, ruleRight: 110 });
    assert.equal(detectRules(page, { left: 40, top: 10, width: 70, height: 30 }, maskRuleOptions[0]).length, 1);
});

test('moved-source masks retain light and dark cell fills without adopting dark glyph ink', () => {
    const chooseColor = (local, surrounding) => new Function(
        'samplePageBackgroundColor', 'samplePageSurroundingBackgroundColor', 'parseCssRgb', `
        ${functionSource('samplePageMovedSourceMaskColor', 'samplePageForegroundColor')}
        return samplePageMovedSourceMaskColor({}, {});
    `)(() => local, () => surrounding, (value) => {
        const channels = value.match(/\d+/g)?.map(Number);
        return channels ? { r: channels[0], g: channels[1], b: channels[2] } : null;
    });
    assert.equal(chooseColor('rgb(224, 224, 224)', 'rgb(255, 255, 255)'), 'rgb(224, 224, 224)');
    assert.equal(chooseColor('rgb(192, 224, 232)', 'rgb(255, 255, 255)'), 'rgb(192, 224, 232)');
    assert.equal(chooseColor('rgb(32, 32, 32)', 'rgb(255, 255, 255)'), 'rgb(32, 32, 32)');
    assert.equal(chooseColor('rgb(128, 128, 128)', 'rgb(255, 255, 255)'), 'rgb(255, 255, 255)');
    assert.equal(chooseColor('rgb(255, 255, 255)', 'rgb(255, 255, 255)'), 'rgb(255, 255, 255)');
});

test('preformatted moved masks cover inter-run glyph strokes while protecting neighboring content', () => {
    const applyMask = new Function(
        'document', 'applyMovedSourceVisibilityForBox', 'movedOverlayRunItemsForBox',
        'movedOverlayProtectedRectsForBox', 'movedOverlayOwnedUnderlineRectsForBox',
        'movedOverlayRuleGapRectsForMask', 'samplePageMovedSourceMaskColor', `
        ${functionSource('rectToEdges', 'shouldSkipTallNeighborProtectionForMask')}
        ${functionSource('applyMovedOverlayRunMaskSegments', 'scheduleMovedOverlayRunMaskRefresh')}
        return applyMovedOverlayRunMaskSegments;
    `)(
        { createElement: () => ({ style: {} }) },
        () => {},
        () => [{ leftPx: 0, rightPx: 100, topPx: 5, bottomPx: 15 }],
        () => [{ left: 80, top: 30, right: 100, bottom: 40 }],
        () => [],
        () => assert.fail('Preformatted content must not be treated as page rules'),
        () => 'white',
    );
    const mask = { style: {}, replaceChildren(...children) { this.children = children; } };
    assert.equal(applyMask(mask, { dataset: { movedTextOverlay: '1', promotedPreformattedBlock: '1' } },
        { left: 0, top: 0, width: 100, height: 60 }), true);
    const covers = (left, top) => mask.children.some(({ style }) => {
        const segmentLeft = Number.parseFloat(style.left);
        const segmentTop = Number.parseFloat(style.top);
        return left >= segmentLeft && left < segmentLeft + Number.parseFloat(style.width)
            && top >= segmentTop && top < segmentTop + Number.parseFloat(style.height);
    });
    assert.equal(covers(50, 20), true, 'Ink outside tight run bounds must be erased');
    assert.equal(covers(50, 59), true, 'The full source block must be covered');
    assert.equal(covers(90, 35), false, 'Protected neighboring content must remain visible');
});