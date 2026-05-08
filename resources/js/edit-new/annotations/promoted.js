/*
 * annIsPromotedFromExtraction (Phase 7ai).
 *
 * Pure predicate: true when this annotation was promoted from the PDF
 * extraction layer (so its saved text / `_richHtml` encodes one block
 * per source line). Detected via the explicit `promotedFromExtraction`
 * flag or the presence of `sourceSpans` / `sourceLineBBoxes`.
 */

export function annIsPromotedFromExtraction(ann) {
    if (!ann) return false;
    return ann.promotedFromExtraction === true
        || (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
}
