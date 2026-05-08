/*
 * Render-routing predicates: should the canvas paint vs. the DOM
 * rich-html layer paint a given text annotation? (Phase 7aj)
 *
 * Stage2 introduced an explicit `ann._committed` flag — the single
 * source of truth for "this annotation is owned by the editor's text
 * engine and must render through the DOM rich-html-layer instead of
 * canvas drawOriginalSource". Semantics:
 *
 *   - Sticky: once true for a session it stays true. Undoing back to
 *     pristine text does not un-commit; with the Stage1 metrics-
 *     compatible font fallbacks the visual delta between DOM and
 *     canvas is small enough that re-routing on revert is unnecessary
 *     churn (and historically a source of routing surprises).
 *   - Transient: not persisted to the DB. On hydrate we re-derive it
 *     from `legacyShouldDomRender` (the original heuristic stack) so
 *     a previously-edited annotation loaded from disk routes the same
 *     way it did when it was saved.
 *   - Memoized: `shouldRenderTextInRichHtmlLayer` writes _committed=true
 *     the first time the legacy heuristics resolve to "yes", so all
 *     subsequent calls short-circuit to the cached flag.
 *   - Explicit entry point: `markCommitted(ann, reason)` lets call
 *     sites (text edits, dimension changes, _richHtml assignment)
 *     transition an annotation directly without waiting for the next
 *     redraw to observe the change.
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

/**
 * The original (pre-Stage2) routing heuristic stack. Computes whether
 * an annotation should render via the DOM rich-html-layer based on
 * observable annotation/edit state, without consulting `_committed`.
 * Used both by `shouldRenderTextInRichHtmlLayer` (to backfill
 * _committed on hydrate) and as an internal invariant check.
 */
function legacyShouldDomRender(ann) {
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

/**
 * Explicitly transition an annotation into "DOM-owned" state. Idempotent.
 * Returns true if the annotation was newly committed (caller can use
 * this to schedule a redraw).
 *
 * `reason` is a short string for debug logging — not persisted.
 */
export function markCommitted(ann, reason = '') {
    if (!ann || ann._committed === true) return false;
    ann._committed = true;
    if (reason && typeof window !== 'undefined' && window.__commitDebug) {
        try {
            window.__commitLog = window.__commitLog || [];
            window.__commitLog.push({ uid: ann._uid, reason, ts: Date.now() });
        } catch (_e) {}
    }
    return true;
}

export function shouldRenderTextInRichHtmlLayer(ann) {
    if (!ann) return false;
    if (ann._committed === true) return true;
    if (legacyShouldDomRender(ann)) {
        // Memoize: the heuristic stack flipped to true; promote to the
        // explicit flag so future calls (and the rest of the code base)
        // see a single source of truth.
        ann._committed = true;
        return true;
    }
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
