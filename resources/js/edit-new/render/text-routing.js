/*
 * Render-routing predicates: should the canvas paint vs. the DOM
 * rich-html layer paint a given text annotation? (Phase 7aj)
 *
 * `shouldRenderTextInRichHtmlLayer`:
 *   Decide whether a text annotation's glyphs come from the DOM
 *   rich-html layer (CSS measureText spacing, contenteditable-style
 *   reflow) or the canvas drawOriginalSource (per-span exact PDF
 *   positions). Pristine extracted text whose visible content still
 *   matches the PDF source must keep canvas drawing — even if the
 *   user-authored flag was flipped by a non-text edit (geometry grow,
 *   color/bg change, etc.) — because the DOM layer collapses tab
 *   leaders and joins styled runs.
 *
 * `activeEditorCanvasOwnsPaint`:
 *   While a text annotation is in the active editor, decide whether
 *   the canvas still owns painting of the glyphs (so the editor can
 *   hide its own glyphs to avoid double-painting). Only true for
 *   canvas/galley render-modes, or source/source-flow modes where the
 *   canvas would still paint (i.e. shouldRenderTextInRichHtmlLayer is
 *   false).
 */

import { editedTexts } from '../store/edited-texts.js';
import {
    isUserAuthoredAnnotation,
    annTextIsEdited,
    annotationDimensionsChanged,
} from '../annotations/state.js';
import { editorIsEditingAnnotation } from '../editor/is-editing.js';

export function shouldRenderTextInRichHtmlLayer(ann) {
    const hasRichHtml = !!ann?._richHtml;
    const userAuthoredText = (ann?.type || 'text') === 'text'
        && (ann?.userCreated || isUserAuthoredAnnotation(ann));
    if (hasRichHtml) return true;
    if (!userAuthoredText) return false;
    // Pristine extracted text whose visible content still matches the PDF
    // source must keep canvas drawOriginalSource (per-span exact positions),
    // even if the user-authored flag was flipped by a non-text edit
    // (geometry grow, color/bg change, etc.). The DOM layer renders text
    // via CSS measureText spacing, which collapses tab-leader dots and
    // joins styled runs — visibly different from the canvas baseline.
    const hasSourceContent = (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
        || ann?.promotedFromExtraction === true;
    if (!hasSourceContent) return true;
    if (ann?._sourceExactTextEdited || ann?.sourceExactTextEdited || ann?.preserveSourceLayout) return false;
    const editedInSession = typeof editedTexts !== 'undefined'
        && editedTexts[ann?._uid] !== undefined
        && editedTexts[ann?._uid] !== String(ann?.text ?? '');
    if (editedInSession) return true;
    if (annTextIsEdited(ann)) return true;
    if (annotationDimensionsChanged(ann)) return true;
    return false;
}

export function activeEditorCanvasOwnsPaint(ann, aeEl) {
    if (!(aeEl instanceof HTMLElement) || !ann) return false;
    const mode = aeEl.dataset ? aeEl.dataset.renderMode : '';
    if (mode === 'canvas') return true;
    if (mode === 'galley') return true;
    const editingSourceMode = editorIsEditingAnnotation(aeEl, ann)
        && (mode === 'source' || mode === 'source-flow');
    if (!editingSourceMode) return false;
    // Source/source-flow editing is allowed to hide the contenteditable's
    // glyphs only while the canvas still paints the annotation text. Once
    // the annotation is resized, styled, rich-html-backed, or otherwise
    // DOM-owned, hiding the editor would leave no visible text.
    return !shouldRenderTextInRichHtmlLayer(ann);
}
