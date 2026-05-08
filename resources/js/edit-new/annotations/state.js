// Pure helpers that classify an annotation's edit state: whether it's been
// authored or modified by the user (vs preserved from extraction), whether
// its visible text has been edited, and whether its bounding box has been
// resized away from the originally captured PDF box. No DOM, no editor state.

import { resolveAnnBox, resolveOriginalAnnBox, resolveSourceAnnBox } from './box.js';
import { markDirty } from '../store/lifecycle-flags.js';

/**
 * True if the annotation should be treated as user-authored: explicitly
 * flagged via _userAuthored / userAuthored / promotedDirty, or simply not
 * promoted from extraction in the first place.
 *
 * Persistence: flagged onto `userAuthored` in the saved payload; the load
 * hydrator restores `_userAuthored` from `userAuthored` OR `promotedDirty`
 * (legacy flag already persisted).
 */
export function isUserAuthoredAnnotation(ann) {
    if (!ann) return false;
    if (ann._userAuthored) return true;
    if (ann.userAuthored) return true;
    if (ann.promotedDirty) return true;
    if (!ann.promotedFromExtraction) return true;
    return false;
}

/** Mark an annotation as user-authored, mirroring the flag onto annotation_data. */
export function markUserAuthored(ann) {
    if (!ann || ann._userAuthored) return;
    ann._userAuthored = true;
    ann.promotedDirty = true;
    if (ann.annotation_data && typeof ann.annotation_data === 'object') {
        ann.annotation_data.userAuthored = true;
        ann.annotation_data.promotedDirty = true;
    }
}

/** True if ann.text has been edited away from its originalText (the value at extraction). */
export function annTextIsEdited(ann) {
    const saved    = String(ann?.text ?? '');
    const original = String(ann?.originalText ?? '');
    if (!original) return false; // no baseline to compare against
    const norm = (s) => s.replace(/[\s\n]+/g, ' ').trim();
    return norm(saved) !== norm(original);
}

/**
 * True if the annotation's current box has been resized away from its
 * recorded original PDF box. Prefers _originalPdfBox (captured from the
 * loaded pdfWidth/pdfHeight); falls back to resolveOriginalAnnBox so
 * legacy annotations still work.
 */
export function annotationDimensionsChanged(ann) {
    const cur = resolveAnnBox(ann);
    if (!cur) return false;
    const origPdf = ann._originalPdfBox;
    const orig = (origPdf && [origPdf.x, origPdf.y, origPdf.w, origPdf.h].every(Number.isFinite))
        ? origPdf
        : resolveOriginalAnnBox(ann);
    if (!orig) return false;
    return Math.abs(cur.w - orig.w) > 0.25 || Math.abs(cur.h - orig.h) > 0.25;
}

/**
 * True if the annotation's current box has moved away from its original
 * extraction-time position by more than 0.25pt on either axis. Companion
 * to annotationDimensionsChanged.
 */
export function annotationPositionChanged(ann) {
    const cur = resolveAnnBox(ann);
    const orig = resolveOriginalAnnBox(ann);
    if (!cur || !orig) return false;
    return Math.abs(cur.x - orig.x) > 0.25 || Math.abs(cur.y - orig.y) > 0.25;
}

/**
 * Normalize a string so that text-equality comparisons used to decide
 * whether to persist a promoted-from-extraction annotation as "dirty"
 * ignore line-ending style differences (CRLF / CR vs LF).
 */
export function normalizeAnnotationTextForDirtyComparison(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

/**
 * Stamp the latest contenteditable HTML onto the annotation as the
 * canonical rich-text source, mark it user-authored + style-dirty,
 * and flag the global save state dirty. Called after any rich-text
 * format command applied via the format bar.
 */
export function persistRichEditorHtml(active, ae) {
    active.ann._richHtml = ae.innerHTML;
    active.ann._styleDirty = true;
    markUserAuthored(active.ann);
    markDirty();
}

/**
 * True if a promoted-from-extraction annotation has diverged from its
 * captured baseline (text edited, moved, resized, or had a style change),
 * so the saved payload should carry promotedDirty=true.
 */
export function shouldPersistPromotedDirty(ann, currentText) {
    if (!ann?.promotedFromExtraction) return false;
    const originalText = normalizeAnnotationTextForDirtyComparison(ann.originalText ?? ann.text ?? '');
    const nextText = normalizeAnnotationTextForDirtyComparison(currentText);
    if (nextText !== originalText) return true;
    if (annotationPositionChanged(ann)) return true;
    if (annotationDimensionsChanged(ann)) return true;
    if (ann._styleDirty) return true;
    return false;
}

/**
 * True if a promoted text annotation with a non-transparent background
 * has been horizontally trimmed below its source extraction box and
 * still matches the original text/top-left/height — in that case the
 * saved payload should preserve the original source box so background
 * rendering covers the full extracted region.
 */
export function shouldPersistPromotedSourceBoxForBackground(ann, currentText) {
    if (String(ann?.type || '').toLowerCase() !== 'text') return false;
    if (!ann?.promotedFromExtraction) return false;

    const background = String(ann?.backgroundColor || '').trim().toLowerCase();
    if (!background || background === 'transparent') return false;
    if (typeof ann?._richHtml === 'string' && ann._richHtml.trim()) return false;

    const currentBox = resolveAnnBox(ann);
    const sourceBox = resolveSourceAnnBox(ann);
    if (!currentBox || !sourceBox) return false;

    const currentNormalized = normalizeAnnotationTextForDirtyComparison(currentText);
    const originalNormalized = normalizeAnnotationTextForDirtyComparison(ann.originalText ?? ann.text ?? '');
    if (currentNormalized !== originalNormalized) return false;

    const geometryTolerance = 0.75;
    const topLeftMatchesSource = Math.abs(currentBox.x - sourceBox.x) <= geometryTolerance
        && Math.abs(currentBox.y - sourceBox.y) <= geometryTolerance;
    if (!topLeftMatchesSource) return false;

    if (Math.abs(currentBox.h - sourceBox.h) > 2.0) return false;

    return (sourceBox.w - currentBox.w) > 2.0;
}
