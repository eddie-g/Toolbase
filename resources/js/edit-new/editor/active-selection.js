/*
 * editor/active-selection (Phase 7az).
 *
 * Tiny helper that returns { ae, range, selection, active } iff the
 * window has a non-collapsed Selection inside the currently-active
 * annotation's editor element. Used by the format-bar code path to
 * route inline selection-style commands (bold, underline, font, …)
 * to execCommand on the live selection.
 */

import { getActiveAnnAndPage } from '../selection/active.js';

export function getActiveEditorSelection() {
    const active = getActiveAnnAndPage();
    if (!active) return null;
    const ae = document.getElementById('ae-' + (active.pi + 1));
    if (!(ae instanceof HTMLElement) || ae.style.display === 'none') return null;
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return null;
    const range = selection.getRangeAt(0);
    if (!ae.contains(range.startContainer) || !ae.contains(range.endContainer)) return null;
    return { ae, range, selection, active };
}
