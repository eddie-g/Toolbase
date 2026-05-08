/*
 * Page-bound annotation box constraints (Phase 7m).
 *
 * Pure-ish helpers that clamp a candidate box to the current page's
 * pdf-pt bounds and write it back (via setAnnotationBox) when changed.
 * Used by drag/resize and shape-grow paths.
 */

import { pageData } from '../store/page-data.js';
import { resolveAnnBox } from './box.js';
import { setAnnotationBox } from './geometry-writes.js';

export function boxesRoughlyEqual(a, b, epsilon = 0.05) {
    if (!a || !b) return false;
    return ['x', 'y', 'w', 'h'].every((key) => Math.abs((Number(a[key]) || 0) - (Number(b[key]) || 0)) <= epsilon);
}

export function constrainAnnotationBoxToPage(pi, box) {
    const data = pageData[pi];
    const rawX = Number(box?.x);
    const rawY = Number(box?.y);
    const rawW = Number(box?.w);
    const rawH = Number(box?.h);
    const pageW = Number(data?.wPts);
    const pageH = Number(data?.hPts);
    if (![rawX, rawY, rawW, rawH, pageW, pageH].every(Number.isFinite) || pageW <= 0 || pageH <= 0) {
        return null;
    }

    const w = Math.max(1, Math.min(pageW, rawW));
    const h = Math.max(1, Math.min(pageH, rawH));
    const x = Math.min(Math.max(0, rawX), Math.max(0, pageW - w));
    const y = Math.min(Math.max(0, rawY), Math.max(0, pageH - h));

    return { x, y, w, h };
}

export function setAnnotationBoxWithinPage(ann, pi, box) {
    const constrained = constrainAnnotationBoxToPage(pi, box);
    if (!constrained) return false;

    const wasConstrained = Boolean(ann._pageBoundsConstrained);
    ann._pageBoundsConstrained = !boxesRoughlyEqual(constrained, box);
    const constraintChanged = wasConstrained !== ann._pageBoundsConstrained;

    const current = resolveAnnBox(ann);
    if (current && boxesRoughlyEqual(current, constrained)) {
        return constraintChanged;
    }

    setAnnotationBox(ann, constrained);
    return true;
}
