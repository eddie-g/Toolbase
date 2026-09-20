import test from 'node:test';
import assert from 'node:assert/strict';
import { desktopNoticeReason, MIN_EDITOR_WIDTH } from './touch-guard.js';

test('a phone or tablet gets the notice, a touch-screen laptop does not', () => {
    assert.equal(desktopNoticeReason({ coarsePointer: true, canHover: false, viewportWidth: 390 }), 'touch');
    assert.equal(desktopNoticeReason({ coarsePointer: true, canHover: false, viewportWidth: 1180 }), 'touch', 'a tablet in landscape');
    assert.equal(desktopNoticeReason({ coarsePointer: false, canHover: true, viewportWidth: 1440 }), null);
    assert.equal(desktopNoticeReason({ coarsePointer: true, canHover: true, viewportWidth: 1440 }), null, 'a laptop whose screen is also a touch screen');
});

test('a narrow desktop window gets it too, and the limit itself is wide enough', () => {
    assert.equal(desktopNoticeReason({ coarsePointer: false, canHover: true, viewportWidth: MIN_EDITOR_WIDTH - 1 }), 'narrow');
    assert.equal(desktopNoticeReason({ coarsePointer: false, canHover: true, viewportWidth: MIN_EDITOR_WIDTH }), null);
    assert.equal(desktopNoticeReason({ coarsePointer: false, canHover: true, viewportWidth: 0 }), null, 'a window that has not been laid out yet');
});

test('once dismissed it stays away, and the browser suites never see it', () => {
    assert.equal(desktopNoticeReason({ coarsePointer: true, canHover: false, viewportWidth: 390, dismissed: true }), null);
    assert.equal(desktopNoticeReason({ coarsePointer: true, canHover: false, viewportWidth: 390, automated: true }), null);
});
