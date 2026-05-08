/*
 * annotationUsesStoredBoxForDisplay (Phase 7y).
 *
 * A promoted-from-extraction annotation should keep rendering from
 * its original source-block geometry until the user makes a meaningful
 * change. After any of: text edit, dimension change, style change
 * (background, opacity, underline, alignment), user-authored flag, or
 * presence of rich-html content, switch to the stored box for display.
 *
 * Pure: depends only on the annotation, the editedTexts slice, and a
 * handful of already-extracted predicates.
 */

import { editedTexts } from '../store/edited-texts.js';
import { resolveDisplayBgColor } from './display-bg-color.js';
import {
    isUserAuthoredAnnotation,
    annTextIsEdited,
    annotationDimensionsChanged,
} from './state.js';

export function annotationUsesStoredBoxForDisplay(ann) {
    const currentText = editedTexts[ann?._uid];
    const editedInSession = currentText !== undefined && currentText !== String(ann?.text ?? '');
    const annBgColor = resolveDisplayBgColor(ann);
    const annOpacity = Math.min(1, Math.max(0, parseFloat(ann?.opacity ?? 1)));
    const styleChanged = Boolean(
        (annBgColor && annBgColor !== '#ffffff' && annBgColor !== 'transparent')
        || annOpacity < 0.999
        || ann?.underline
        || (String(ann?.verticalAlign || 'top').toLowerCase() !== 'top')
        || (String(ann?.textAlign || 'left').toLowerCase() !== 'left')
    );
    // Keep the DOM/editor geometry aligned with the export/canvas paths.
    // Once a promoted annotation becomes user-authored (style change, text
    // edit, resize, rich formatting), it must render from its stored box
    // instead of the original source block geometry.
    return editedInSession
        || annTextIsEdited(ann)
        || annotationDimensionsChanged(ann)
        || styleChanged
        || isUserAuthoredAnnotation(ann)
        || Boolean(ann?._richHtml);
}
