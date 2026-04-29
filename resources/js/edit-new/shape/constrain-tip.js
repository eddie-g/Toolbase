/*
 * shape/constrain-tip (Phase 7ax).
 *
 * Tiny helpers that show / hide / position the floating "Hold Shift to
 * constrain" hint that appears next to the pointer while drawing a
 * shape. They depend on a single DOM ref (`tipEl`) and the
 * shapeConstrainUserDismissedTip flag in store/misc-state.js.
 */

import { shapeConstrainUserDismissedTip } from '../store/misc-state.js';

export function positionShapeConstrainTip(tipEl, clientX, clientY) {
    if (!tipEl) return;
    const margin = 14;
    const tipW = tipEl.offsetWidth || 0;
    const tipH = tipEl.offsetHeight || 0;
    const viewportW = window.innerWidth || document.documentElement.clientWidth || 1024;
    const viewportH = window.innerHeight || document.documentElement.clientHeight || 768;
    let left = clientX + margin;
    let top = clientY + margin;
    if (left + tipW + 4 > viewportW) left = Math.max(4, clientX - tipW - margin);
    if (top + tipH + 4 > viewportH) top = Math.max(4, clientY - tipH - margin);
    tipEl.style.left = `${Math.max(4, left)}px`;
    tipEl.style.top = `${Math.max(4, top)}px`;
}

export function showShapeConstrainTip(tipEl, clientX, clientY) {
    if (!tipEl || shapeConstrainUserDismissedTip) return;
    positionShapeConstrainTip(tipEl, clientX, clientY);
    tipEl.classList.add('show');
}

export function hideShapeConstrainTip(tipEl) {
    if (!tipEl) return;
    tipEl.classList.remove('show');
}
