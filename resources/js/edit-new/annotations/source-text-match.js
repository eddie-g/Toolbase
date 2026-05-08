/*
 * Source-text comparison helpers (Phase 7t).
 *
 * Reconstruct the canonical "source text" string for a promoted-from-
 * extraction annotation, normalize text for whitespace-insensitive
 * comparison, and decide whether an annotation's current text still
 * matches its source. Pure: only depends on `renderableSourceLines`.
 */

import { renderableSourceLines } from './source-lines.js';
import { sourceSpanText } from './source-span-text.js';

export function sourceTextFallbackFromAnnotation(ann) {
    const lines = renderableSourceLines(ann);
    if (lines.length) {
        const text = lines
            .map((line) => String(line?.text ?? '').replace(/\s+$/g, ''))
            .filter((lineText) => lineText.length > 0)
            .join('\n')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\s+([,.;!?])/g, '$1')
            .trim();
        if (text) return text;
    }

    const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
    if (!spans.length) return '';
    return spans
        .slice()
        .sort((a, b) => {
            const ay = Number(Array.isArray(a?.bbox) ? a.bbox[1] : 0);
            const by = Number(Array.isArray(b?.bbox) ? b.bbox[1] : 0);
            if (Math.abs(ay - by) > 1.5) return ay - by;
            return Number(Array.isArray(a?.bbox) ? a.bbox[0] : 0)
                - Number(Array.isArray(b?.bbox) ? b.bbox[0] : 0);
        })
        .map((span) => sourceSpanText(span))
        .join('')
        .replace(/[ \t]{2,}/g, ' ')
        .replace(/\s+([,.;!?])/g, '$1')
        .trim();
}

export function normalizeTextForSourceComparison(value) {
    return String(value ?? '')
        .replace(/\r\n?/g, '\n')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/g, ' ')
        // Mirror sourceTextFallbackFromAnnotation, which strips whitespace
        // before punctuation. Without this, leader-dot rows (". . . . . .")
        // normalize differently on the two sides of the comparison —
        // editedText keeps the spaces, source text collapses them — and
        // pristine extracted multi-line annotations are misclassified as
        // "edited", forcing the active editor onto the renderPlainEditorHTML
        // reflow path that visibly collapses leader-dot spacing.
        .replace(/\s+([,.;!?])/g, '$1')
        .trim();
}

export function annotationTextMatchesSource(ann, text = null) {
    const hasSourceContent = (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
        || (Array.isArray(ann?.sourceTextLines) && ann.sourceTextLines.length > 0);
    if (!hasSourceContent) return true;

    const sourceText = sourceTextFallbackFromAnnotation(ann);
    if (!sourceText) return true;

    const currentText = text === null || text === undefined
        ? String(ann?.text ?? '')
        : String(text);
    return normalizeTextForSourceComparison(currentText) === normalizeTextForSourceComparison(sourceText);
}
