// Stage 3: text-stripped page raster.
//
// The PDF page background is rasterised once by PDF.js (and re-rasterised on
// zoom). That bitmap contains every original glyph drawn by the PDF stream.
// When the user commits an edit on an annotation (Stage 2: ann._committed),
// the rich-html-layer paints the new text in the DOM on top of the raster —
// but the raster's original glyphs are still visible underneath, producing
// a "two fonts, slightly mis-aligned" ghosting artifact.
//
// This module takes a per-page pristine snapshot of the freshly-rendered
// raster, then on demand restores that snapshot and paints a fill rectangle
// over each committed text annotation's bbox, erasing the underlying
// glyphs from the raster. Reverting / un-committing is intentionally not
// supported (matches Stage 2 sticky semantics) — the snapshot is kept so
// the patch set can be recomputed cheaply (no PDF re-render) when an
// additional annotation transitions to _committed.
//
// Coordinates: pdfX, pdfY are PDF points in bottom-origin space.
// pageCanvas backing pixels are wPts*renderScale × hPts*renderScale.
// renderScale is stashed on pageCanvas.__renderScale by the caller.

import { isTextAnnotation } from '../annotations/types.js';
import { normalizeHexColor } from '../util/color.js';

const SNAPSHOT_KEY = '__pristineRasterSnapshot';
const PATCH_CACHE_KEY = '__committedPatchSignature';

// Pad the patch by a small margin (in canvas pixels) so antialiased glyph
// edges that sit just outside the recorded bbox are also covered.
const PATCH_PADDING_PX = 1;

function isCanvasUsable(canvas) {
    return canvas instanceof HTMLCanvasElement && canvas.width > 0 && canvas.height > 0;
}

function annotationFillColor(ann) {
    // Use the annotation's explicit background colour when it was set
    // intentionally (and is opaque). Otherwise default to white, which
    // matches the typical PDF page background and works for every tested
    // form (W-9, SS-5, I-9, F1040 family).
    if (ann && ann.backgroundColor && ann.backgroundColorExplicit) {
        const norm = normalizeHexColor(ann.backgroundColor);
        if (norm && norm.toLowerCase() !== 'transparent') return norm;
    }
    return '#ffffff';
}

function patchRectForAnn(ann) {
    // Cover BOTH the current box and the original-extraction box. The
    // original box matters because the underlying PDF text was painted at
    // the original position; if the user shrinks or moves the annotation,
    // the original glyphs would re-emerge around the smaller current box.
    // The current box matters because the new (DOM-rendered) text paints
    // there and we want a clean white backdrop under it.
    const x1 = [Number(ann.pdfX)];
    const y1 = [Number(ann.pdfY)];
    const x2 = [Number(ann.pdfX) + Number(ann.pdfWidth)];
    const y2 = [Number(ann.pdfY) + Number(ann.pdfHeight)];
    const orig = ann._originalPdfBox;
    if (orig && [orig.x, orig.y, orig.w, orig.h].every(Number.isFinite)) {
        x1.push(orig.x);
        y1.push(orig.y);
        x2.push(orig.x + orig.w);
        y2.push(orig.y + orig.h);
    }
    const minX = Math.min(...x1);
    const minY = Math.min(...y1);
    const maxX = Math.max(...x2);
    const maxY = Math.max(...y2);
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
}

function committedTextAnnotations(pageData) {
    if (!pageData || !Array.isArray(pageData.annotations)) return [];
    const out = [];
    for (const ann of pageData.annotations) {
        if (!ann || ann._committed !== true) continue;
        if (!isTextAnnotation(ann)) continue;
        const x = Number(ann.pdfX);
        const y = Number(ann.pdfY);
        const w = Number(ann.pdfWidth);
        const h = Number(ann.pdfHeight);
        if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h)) continue;
        if (w <= 0 || h <= 0) continue;
        out.push(ann);
    }
    return out;
}

function patchSignature(committed, deletedRects) {
    // Cheap fingerprint of the patch set so applyCommittedRasterPatches can
    // short-circuit when nothing changed since the last call. Includes uid
    // + bbox so a moved/resized committed annotation re-patches.
    const parts = [];
    for (let i = 0; i < committed.length; i++) {
        const a = committed[i];
        const r = patchRectForAnn(a);
        parts.push(`c:${a._uid || a.id || i}:${r.x}:${r.y}:${r.w}:${r.h}:${annotationFillColor(a)}`);
    }
    if (Array.isArray(deletedRects)) {
        for (let i = 0; i < deletedRects.length; i++) {
            const r = deletedRects[i];
            parts.push(`d:${r.uid || i}:${r.x}:${r.y}:${r.w}:${r.h}:${r.color || '#ffffff'}`);
        }
    }
    return parts.join('|');
}

/**
 * Snapshot the freshly-rendered page bitmap into an offscreen canvas
 * attached to pageCanvas.__pristineRasterSnapshot. Call immediately after
 * pdfPage.render() resolves (initial setupPage and zoom rerender).
 */
export function capturePristineRasterSnapshot(pageCanvas) {
    if (!isCanvasUsable(pageCanvas)) return;
    const off = document.createElement('canvas');
    off.width = pageCanvas.width;
    off.height = pageCanvas.height;
    const ctx = off.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(pageCanvas, 0, 0);
    pageCanvas[SNAPSHOT_KEY] = off;
    // Reset the patch signature so the next applyCommittedRasterPatches
    // call repaints from the new snapshot.
    pageCanvas[PATCH_CACHE_KEY] = null;
}

/**
 * Restore from snapshot + paint background patches over every committed
 * text annotation's bbox. No-op when the committed set + bboxes haven't
 * changed since the previous call (so it's safe to invoke from
 * redrawOverlay every frame).
 *
 * Returns true when the canvas was repainted, false on no-op.
 */
export function applyCommittedRasterPatches(pageCanvas, pageData) {
    if (!isCanvasUsable(pageCanvas)) return false;
    const snapshot = pageCanvas[SNAPSHOT_KEY];
    if (!snapshot) return false; // snapshot not captured yet

    const committed = committedTextAnnotations(pageData);
    const deletedRects = Array.isArray(pageData?.deletedRectPatches) ? pageData.deletedRectPatches : [];
    const signature = patchSignature(committed, deletedRects);
    if (signature === pageCanvas[PATCH_CACHE_KEY]) return false;

    const ctx = pageCanvas.getContext('2d');
    if (!ctx) return false;

    // Restore pristine state. drawImage with src+dst the same size copies
    // pixels 1:1; reset transform first in case anything else fiddled.
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, pageCanvas.width, pageCanvas.height);
    ctx.drawImage(snapshot, 0, 0);

    if (pageData) {
        const renderScale = Number(pageCanvas.__renderScale) || 1;
        const hPts = Number(pageData.hPts) || (pageCanvas.height / renderScale);
        const fillRectPdf = (rect, fill) => {
            const left = rect.x * renderScale;
            const top = (hPts - (rect.y + rect.h)) * renderScale;
            const width = rect.w * renderScale;
            const height = rect.h * renderScale;
            ctx.fillStyle = fill;
            ctx.fillRect(
                Math.floor(left - PATCH_PADDING_PX),
                Math.floor(top - PATCH_PADDING_PX),
                Math.ceil(width + PATCH_PADDING_PX * 2),
                Math.ceil(height + PATCH_PADDING_PX * 2),
            );
        };
        for (const ann of committed) {
            fillRectPdf(patchRectForAnn(ann), annotationFillColor(ann));
        }
        for (const r of deletedRects) {
            if (![r.x, r.y, r.w, r.h].every(Number.isFinite)) continue;
            if (r.w <= 0 || r.h <= 0) continue;
            fillRectPdf(r, r.color || '#ffffff');
        }
    }
    ctx.restore();
    pageCanvas[PATCH_CACHE_KEY] = signature;
    return true;
}

/**
 * Test/debug helper: returns the count of committed text annotations
 * currently being patched on this page.
 */
export function getCommittedPatchCount(pageData) {
    return committedTextAnnotations(pageData).length;
}
