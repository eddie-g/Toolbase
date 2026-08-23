import assert from 'node:assert/strict';
import test from 'node:test';

import { canvasBackdropForInk } from '../edit-new/signature/preferences.js';

test('black ink gets a grey canvas', () => {
    const backdrop = canvasBackdropForInk('#000000');

    assert.equal(backdrop.tone, 'grey');
    assert.equal(backdrop.background, '#d8dee9');
});

test('the default near-black ink also gets a grey canvas', () => {
    // #111827 is the composer default, so this is what most users see.
    assert.equal(canvasBackdropForInk('#111827').tone, 'grey');
});

test('other dark inks get a grey canvas too', () => {
    for (const ink of ['#1d4ed8', '#7f1d1d', '#14532d', '#312e81']) {
        assert.equal(canvasBackdropForInk(ink).tone, 'grey', ink);
    }
});

test('light ink gets a dark canvas so it stays visible', () => {
    for (const ink of ['#ffffff', '#fef08a', '#a7f3d0']) {
        assert.equal(canvasBackdropForInk(ink).tone, 'dark', ink);
    }
});

test('brightness is judged perceptually, not by raw channel values', () => {
    // Pure blue is much darker than pure green despite both being "full" on
    // one channel; a naive average would get this wrong.
    assert.equal(canvasBackdropForInk('#0000ff').tone, 'grey');
    assert.equal(canvasBackdropForInk('#00ff00').tone, 'dark');
});

test('shorthand hex is expanded', () => {
    assert.equal(canvasBackdropForInk('#000').tone, 'grey');
    assert.equal(canvasBackdropForInk('#fff').tone, 'dark');
});

test('a missing leading hash is tolerated', () => {
    assert.equal(canvasBackdropForInk('111827').tone, 'grey');
});

test('missing or malformed colours fall back to the grey canvas', () => {
    for (const ink of [undefined, null, '', '   ', 'not-a-colour', '#12345', 'rgb(0,0,0)']) {
        assert.equal(canvasBackdropForInk(ink).tone, 'grey', String(ink));
    }
});

test('the result is a plain CSS colour, never drawn into the bitmap', () => {
    // The signature PNG must stay transparent, so this value is only ever
    // applied as an element background.
    const backdrop = canvasBackdropForInk('#111827');

    assert.match(backdrop.background, /^#[0-9a-f]{6}$/i);
    assert.deepEqual(Object.keys(backdrop).sort(), ['background', 'tone']);
});
