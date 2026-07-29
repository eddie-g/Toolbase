import assert from 'node:assert/strict';
import test from 'node:test';

import {
    copyElementRuntimeState,
    deleteElementRuntimeState,
    hasElementRuntimeState,
    readElementRuntimeState,
    writeElementRuntimeState,
} from './runtime-element-state.js';

function elementWithDataset(dataset = {}) {
    return { dataset: { ...dataset } };
}

test('writes large values without serializing them into data attributes', () => {
    const element = elementWithDataset();
    const runs = [{ text: 'A long source run', leftPx: 12.5 }];

    writeElementRuntimeState(element, 'sourceSpanRuns', runs);

    assert.equal(element.dataset.sourceSpanRuns, undefined);
    assert.equal(readElementRuntimeState(element, 'sourceSpanRuns'), runs);
    assert.equal(hasElementRuntimeState(element, 'sourceSpanRuns'), true);
});

test('migrates a legacy dataset value on first read', () => {
    const element = elementWithDataset({ originalText: 'legacy paragraph' });

    assert.equal(readElementRuntimeState(element, 'originalText'), 'legacy paragraph');
    assert.equal(element.dataset.originalText, undefined);
    assert.equal(readElementRuntimeState(element, 'originalText'), 'legacy paragraph');
});

test('copies selected runtime values without adding DOM attributes', () => {
    const source = elementWithDataset();
    const target = elementWithDataset();
    writeElementRuntimeState(source, 'baseText', 'source paragraph');
    writeElementRuntimeState(source, 'sourceSpanRuns', [{ text: 'source' }]);

    copyElementRuntimeState(source, target, ['baseText', 'sourceSpanRuns']);

    assert.equal(readElementRuntimeState(target, 'baseText'), 'source paragraph');
    assert.deepEqual(readElementRuntimeState(target, 'sourceSpanRuns'), [{ text: 'source' }]);
    assert.deepEqual(target.dataset, {});
});

test('deletes runtime and legacy state together', () => {
    const element = elementWithDataset({ preEdit: 'legacy' });
    readElementRuntimeState(element, 'preEdit');

    assert.equal(deleteElementRuntimeState(element, 'preEdit'), true);
    assert.equal(hasElementRuntimeState(element, 'preEdit'), false);
    assert.equal(readElementRuntimeState(element, 'preEdit'), undefined);
});
