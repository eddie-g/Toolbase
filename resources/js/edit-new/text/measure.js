/**
 * measureEditedTextHeightPts — return the height in PDF points that an
 * annotation's text needs at the given canvas scale.
 *
 * Mirrors the wrap logic in buildEditedLines / renderPlainEditorHTML so the
 * measured height reflects the actual number of rendered (wrapped) lines,
 * not just the count of `\n`s in the source string. Otherwise a long
 * single-line paragraph collapses the annotation box to one line-height
 * when the user changes font size.
 *
 * Wired via configureMeasure(deps) once at boot. The collaborators
 * (renderableSourceLines, editableLineStyle, blockLineHeightPx)
 * still live in main.js and can be moved later without changing this API.
 */

import { resolveAnnBox } from '../annotations/box.js';
import { fontDisplayScale } from '../util/dom-metrics.js';
import { wrapParagraph } from './wrap.js';

let renderableSourceLines = () => [];
let editableLineStyle = () => ({ fontSizePt: 12 });
let blockLineHeightPx = () => 14;

/**
 * @param {Object} deps
 * @param {(ann: Object) => Array} deps.renderableSourceLines
 * @param {(ann: Object, lineIndex?: number) => Object} deps.editableLineStyle
 * @param {(ann: Object, lineIndex: number, scale: number, style: Object) => number} deps.blockLineHeightPx
 */
export function configureMeasure(deps) {
    if (typeof deps.renderableSourceLines === 'function') renderableSourceLines = deps.renderableSourceLines;
    if (typeof deps.editableLineStyle === 'function') editableLineStyle = deps.editableLineStyle;
    if (typeof deps.blockLineHeightPx === 'function') blockLineHeightPx = deps.blockLineHeightPx;
}

export function measureEditedTextHeightPts(ann, text, scale, overrideWidthPts = null) {
    const maxSourceIndex = Math.max(0, renderableSourceLines(ann).length - 1);
    const box = resolveAnnBox(ann);
    const normalized = String(text ?? '')
        .replace(/\r\n?/g, '\n');
    const paragraphs = normalized.split('\n');
    const widthPts = Number.isFinite(Number(overrideWidthPts)) && Number(overrideWidthPts) > 0
        ? Number(overrideWidthPts)
        : (box ? box.w : 0);
    const maxWidthPx = Math.max(10, widthPts * scale);
    let totalLineCount = 0;
    const perLineHeightPx = [];
    paragraphs.forEach((para, pIdx) => {
        const lineIndex = Math.min(pIdx, maxSourceIndex);
        const style = editableLineStyle(ann, lineIndex);
        const stylePx = { ...style, fontSizePx: style.fontSizePt * fontDisplayScale(scale) };
        const wrapped = box ? wrapParagraph(para, maxWidthPx, stylePx) : [para];
        wrapped.forEach(() => {
            const si = Math.min(totalLineCount, maxSourceIndex);
            perLineHeightPx.push(blockLineHeightPx(ann, si, scale, editableLineStyle(ann, si)));
            totalLineCount++;
        });
    });
    if (!perLineHeightPx.length) {
        perLineHeightPx.push(blockLineHeightPx(ann, 0, scale, editableLineStyle(ann, 0)));
    }
    const totalHeightPx = perLineHeightPx.reduce((a, b) => a + b, 0);
    return Math.max(1, totalHeightPx / Math.max(scale, 0.0001));
}
