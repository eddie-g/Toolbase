/*
 * Word-wrapping helper (Phase 7j).
 *
 * Greedy word-wrap that uses canvas-backed `measureTextWidth` to fit a
 * paragraph into a target pixel width. Falls back to mid-word splits
 * when an individual word can't fit on a line.
 */

import { measureTextWidth } from './measure-width.js';

export function wrapParagraph(text, maxWidthPx, style) {
    const content = String(text || '');
    if (!content) return [''];
    const words = content.split(/(\s+)/).filter((p) => p !== '');
    const lines = [];
    let current = '';
    words.forEach((piece) => {
        const candidate = current + piece;
        if (!current || measureTextWidth(candidate, style) <= maxWidthPx) {
            current = candidate;
            return;
        }
        if (current.trim()) {
            lines.push(current.trimEnd());
            current = piece.trimStart();
            return;
        }
        let remainder = piece;
        while (remainder) {
            let splitIndex = remainder.length;
            while (splitIndex > 1 && measureTextWidth(remainder.slice(0, splitIndex), style) > maxWidthPx) splitIndex--;
            lines.push(remainder.slice(0, splitIndex));
            remainder = remainder.slice(splitIndex);
        }
        current = '';
    });
    if (current || !lines.length) lines.push(current.trimEnd());
    return lines;
}
