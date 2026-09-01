import test from 'node:test';
import assert from 'node:assert/strict';

import {
    composeTextDecorationLine,
    decorationTokensFromValue,
    annotationTextDecorationLine,
} from './decoration.js';

test('composeTextDecorationLine returns none when neither flag is set', () => {
    assert.equal(composeTextDecorationLine(false, false), 'none');
});

test('composeTextDecorationLine emits a single token for a single flag', () => {
    assert.equal(composeTextDecorationLine(true, false), 'underline');
    assert.equal(composeTextDecorationLine(false, true), 'line-through');
});

test('composeTextDecorationLine keeps both tokens when both are set', () => {
    assert.equal(composeTextDecorationLine(true, true), 'underline line-through');
});

test('composeTextDecorationLine coerces loosely-typed flags', () => {
    assert.equal(composeTextDecorationLine(1, 0), 'underline');
    assert.equal(composeTextDecorationLine(undefined, 'yes'), 'line-through');
    assert.equal(composeTextDecorationLine(null, null), 'none');
});

test('decorationTokensFromValue reads each token out of a CSS value', () => {
    assert.deepEqual(decorationTokensFromValue('underline'), { underline: true, strikeout: false });
    assert.deepEqual(decorationTokensFromValue('line-through'), { underline: false, strikeout: true });
    assert.deepEqual(
        decorationTokensFromValue('underline line-through'),
        { underline: true, strikeout: true },
    );
});

test('decorationTokensFromValue tolerates shorthand, casing and empties', () => {
    assert.deepEqual(
        decorationTokensFromValue('UNDERLINE SOLID RED'),
        { underline: true, strikeout: false },
    );
    assert.deepEqual(decorationTokensFromValue('none'), { underline: false, strikeout: false });
    assert.deepEqual(decorationTokensFromValue(''), { underline: false, strikeout: false });
    assert.deepEqual(decorationTokensFromValue(null), { underline: false, strikeout: false });
});

test('annotationTextDecorationLine reads the annotation flags', () => {
    assert.equal(annotationTextDecorationLine({}), 'none');
    assert.equal(annotationTextDecorationLine({ underline: true }), 'underline');
    assert.equal(annotationTextDecorationLine({ strikeout: true }), 'line-through');
    assert.equal(
        annotationTextDecorationLine({ underline: true, strikeout: true }),
        'underline line-through',
    );
});

test('annotationTextDecorationLine is null-safe', () => {
    assert.equal(annotationTextDecorationLine(null), 'none');
    assert.equal(annotationTextDecorationLine(undefined), 'none');
});

// The reason this module exists (NK_7): both decorations live in one CSS
// property, so a naive "write the whole property" toggle drops the other one.
test('toggling one decoration token leaves the other in place', () => {
    const existing = decorationTokensFromValue('underline');

    const withStrikeout = composeTextDecorationLine(existing.underline, true);
    assert.equal(withStrikeout, 'underline line-through');

    const backToUnderlineOnly = (() => {
        const tokens = decorationTokensFromValue(withStrikeout);
        tokens.strikeout = false;
        return composeTextDecorationLine(tokens.underline, tokens.strikeout);
    })();
    assert.equal(backToUnderlineOnly, 'underline');
});

test('clearing the last token falls back to none, not an empty string', () => {
    const tokens = decorationTokensFromValue('line-through');
    tokens.strikeout = false;
    assert.equal(composeTextDecorationLine(tokens.underline, tokens.strikeout), 'none');
});
