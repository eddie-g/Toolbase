/*
 * Page-viewport scale helpers (Phase 7aq).
 *
 * Pure helpers that translate between the document container width
 * and the per-page scale factor used everywhere else for canvas
 * sizing and pt→px conversions.
 *
 *   - getAvailablePageWidth: usable width inside the wrap container
 *     (minus a 32px margin allowance), floored at 200px.
 *   - getFitScaleForWidth: scale factor that fits a page of the
 *     given pdf-pt width into the available container width.
 *   - getAppliedScale: combine a page's stored fitScale with the
 *     user's current zoom percentage.
 *
 * The wrap container is supplied by the caller because it lives in
 * main.js's IIFE-local DOM closure; currentZoomPercent is read live
 * from `store/zoom-state.js`.
 */

import { currentZoomPercent } from '../store/zoom-state.js';

export function getAvailablePageWidth(wrap) {
    return Math.max((wrap?.clientWidth || 0) - 32, 200);
}

export function getFitScaleForWidth(pageWidthPts, wrap) {
    return pageWidthPts > 0 ? (getAvailablePageWidth(wrap) / pageWidthPts) : 1;
}

export function getAppliedScale(data) {
    if (!data) return 1;
    return (data.fitScale || 1) * (currentZoomPercent / 100);
}
