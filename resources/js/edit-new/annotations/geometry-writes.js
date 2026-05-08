/*
 * Annotation geometry write helpers (Phase 7a).
 *
 * Three closely-related pure functions extracted from main.js:
 *
 *   - shouldClampToSourceHeight(ann, requestedH)
 *       Defense for promoted-from-extraction annotations: if the visible
 *       text still equals the original and the requested height exceeds
 *       the source-block height, return the source height; else null.
 *
 *   - normalizePromotedAnnotationGeometry(ann)
 *       One-shot heal at hydration time: rewrites pdfHeight + lineHeight
 *       on a promoted annotation that was persisted with bloated geometry.
 *
 *   - setAnnotationBox(ann, b)
 *       Live write helper used by every interaction module. Clamps to
 *       source height when applicable (and bumps pdfY to keep the visual
 *       top stable), mirrors the four geometry fields into
 *       ann.annotation_data, and converts the annotation to user-authored
 *       on a width/height change.
 *
 * No module state. No external collaborator deps beyond markUserAuthored
 * (which is itself already a pure helper in annotations/state.js).
 */

import { markUserAuthored } from './state.js';

export function shouldClampToSourceHeight(ann, requestedH) {
    if (!ann || !Number.isFinite(requestedH)) return null;
    const isPromoted = ann.promotedFromExtraction === true
        || (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
    if (!isPromoted) return null;
    const sH = Number(ann.sourceBlockHeight);
    if (!Number.isFinite(sH) || sH <= 0) return null;
    const txt = String(ann.text ?? '');
    const orig = String(ann.originalText ?? '');
    if (!orig || txt !== orig) return null;
    if (requestedH <= sH + 1) return null;
    return sH;
}

export function normalizePromotedAnnotationGeometry(ann) {
    if (!ann) return;
    const clamped = shouldClampToSourceHeight(ann, Number(ann.pdfHeight));
    if (clamped == null) return;
    // Heal-only: do NOT bump pdfY here. The original bloat path inflated
    // h while leaving pdfY anchored at the source-block bottom, so just
    // resetting h restores the source-block top. (The setAnnotationBox
    // y-bump only applies to live writes where the caller computed pdfY
    // assuming the requested h.)
    ann.pdfHeight = clamped;
    if (ann.annotation_data && typeof ann.annotation_data === 'object') {
        ann.annotation_data.pdfHeight = clamped;
    }
    // Also reset lineHeight back to the source per-line height so the
    // DOM rich-html layer doesn't paint taller than the corrected box.
    const sH = Number(ann.sourceBlockHeight);
    const lineCount = Math.max(1, Array.isArray(ann.sourceTextLines) ? ann.sourceTextLines.length : 1);
    if (Number.isFinite(sH) && sH > 0) {
        const naturalLineHeight = sH / lineCount;
        if (Number.isFinite(naturalLineHeight) && naturalLineHeight > 0) {
            ann.lineHeight = naturalLineHeight;
            if (ann.annotation_data && typeof ann.annotation_data === 'object') {
                ann.annotation_data.lineHeight = naturalLineHeight;
            }
        }
    }
}

export function setAnnotationBox(ann, b) {
    const oldW = Number(ann.pdfWidth);
    const oldH = Number(ann.pdfHeight);
    // Refuse to bloat pdfHeight past sourceBlockHeight when the visible text
    // still matches the source — see shouldClampToSourceHeight above.
    // The caller computed `b.y` assuming the requested `b.h`. Rendering
    // anchors at the visual TOP = `pdfY + pdfHeight` (PDF y-up). If we
    // silently clamp h smaller without adjusting y, the visible top drops
    // by (b.h - clampedH) pts — text "slides down". Bump y up by the same
    // delta so the visual top stays where the caller intended.
    const clampedH = shouldClampToSourceHeight(ann, Number(b.h));
    const finalH = clampedH != null ? clampedH : b.h;
    const finalY = clampedH != null ? (Number(b.y) + (Number(b.h) - clampedH)) : b.y;
    ann.pdfX = b.x; ann.pdfY = finalY; ann.pdfWidth = b.w; ann.pdfHeight = finalH;
    if (ann.annotation_data && typeof ann.annotation_data === 'object') {
        ann.annotation_data.pdfX = b.x; ann.annotation_data.pdfY = finalY;
        ann.annotation_data.pdfWidth = b.w; ann.annotation_data.pdfHeight = finalH;
    }
    // One-way conversion to user-authored on resize (dimension change).
    // Pure moves (same w/h) don't trigger conversion — only an actual resize does.
    if (Number.isFinite(oldW) && Number.isFinite(oldH)
        && (Math.abs(oldW - b.w) > 0.25 || Math.abs(oldH - finalH) > 0.25)) {
        markUserAuthored(ann);
    }
}
