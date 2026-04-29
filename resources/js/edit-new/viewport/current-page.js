/*
 * viewport/current-page (Phase 7as).
 *
 * getCurrentVisiblePageIndex(pageJumpInput): best-guess "what page is
 * the user looking at" used by the toolbar / shortcut handlers that
 * insert a new annotation when no annotation is active. Falls back
 * through activeState.pi → page-jump input value → first known page.
 *
 * pageJumpInput is a DOM ref local to main.js — pass it in.
 */

import { activeState } from '../store/active-state.js';
import { pageData } from '../store/page-data.js';

export function getCurrentVisiblePageIndex(pageJumpInput) {
    const fromActive = Number(activeState.pi);
    if (Number.isInteger(fromActive) && fromActive >= 0 && pageData[fromActive]) return fromActive;
    const fromJump = Math.max(0, (Number(pageJumpInput?.value) || 1) - 1);
    if (pageData[fromJump]) return fromJump;
    const firstKey = Object.keys(pageData)[0];
    return Number.isFinite(Number(firstKey)) ? Number(firstKey) : 0;
}
