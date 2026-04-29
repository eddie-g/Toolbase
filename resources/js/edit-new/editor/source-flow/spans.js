/*
 * editor/source-flow/spans (Phase 7bg).
 *
 * Two pure HTML-builder helpers used by buildLineInnerHtml when
 * rendering a "source flow" editor line: emit a literal text span
 * with explicit font props, and emit a fixed-pixel-width gap span
 * that preserves visual inter-span spacing identically to the
 * canvas render.
 *
 * Style object shape: { fontFamily, fontSizePx, fontWeight, fontStyle, color }.
 */

import { escapeHtml } from '../../util/html.js';

export function renderEditorSpanHtml(style, text) {
    return `<span style="font-family:${style.fontFamily};font-size:${style.fontSizePx.toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};color:${style.color};white-space:pre;">${escapeHtml(text)}</span>`;
}

export function renderEditorGapHtml(style, gapPx, spaceCount) {
    const safeGap = Math.max(0, Number(gapPx) || 0);
    const safeCount = Math.max(1, Number(spaceCount) || 1);
    return `<span data-source-gap="1" style="display:inline-block;width:${safeGap.toFixed(2)}px;min-width:${safeGap.toFixed(2)}px;font-family:${style.fontFamily};font-size:${style.fontSizePx.toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};color:${style.color};white-space:pre;overflow:hidden;vertical-align:baseline;">${' '.repeat(safeCount)}</span>`;
}
