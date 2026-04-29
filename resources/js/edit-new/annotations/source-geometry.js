/*
 * Source-geometry helpers for promoted extraction annotations (Phase 7l).
 *
 * Pure (or renderableSourceLines-only) helpers that compute per-line
 * orientation, span lookup, and span fill-colour resolution. Lifted out
 * of main.js so style/measurement modules can consume them without a
 * configure(deps) hop.
 */

import { renderableSourceLines } from './source-lines.js';

export function normalizeQuarterTurnDegrees(value) {
    const raw = Number(value);
    if (!Number.isFinite(raw)) return 0;
    const normalized = ((((raw % 360) + 540) % 360) - 180);
    if (Math.abs(normalized - 90) <= 12) return 90;
    if (Math.abs(normalized + 90) <= 12) return -90;
    return 0;
}

export function sourceLineRotationDegrees(ann, lineIndex = 0) {
    const line = renderableSourceLines(ann)[lineIndex];
    const spans = Array.isArray(line?.spans) ? line.spans : [];
    let positiveWeight = 0;
    let negativeWeight = 0;

    spans.forEach((span) => {
        const rotation = normalizeQuarterTurnDegrees(span?.rotation);
        if (!rotation) return;
        const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
        const spanWidth = bbox ? Math.abs(Number(bbox[2]) - Number(bbox[0])) : 0;
        const spanHeight = bbox ? Math.abs(Number(bbox[3]) - Number(bbox[1])) : 0;
        const weight = Math.max(
            1,
            spanWidth,
            spanHeight,
            String(span?.render_text ?? span?.text ?? '').replace(/\s+/g, '').length
        );
        if (rotation > 0) positiveWeight += weight;
        else negativeWeight += weight;
    });

    if (positiveWeight || negativeWeight) {
        return positiveWeight >= negativeWeight ? 90 : -90;
    }

    const bbox = Array.isArray(line?.bbox) ? line.bbox : null;
    if (bbox && bbox.length >= 4) {
        const width = Math.abs(Number(bbox[2]) - Number(bbox[0]));
        const height = Math.abs(Number(bbox[3]) - Number(bbox[1]));
        if (height > width * 1.2) {
            const direction = Array.isArray(spans[0]?.direction) ? spans[0].direction : null;
            const dy = Number(direction?.[1]);
            if (Number.isFinite(dy) && Math.abs(dy) > 0.75) {
                return dy < 0 ? -90 : 90;
            }
        }
    }

    return 0;
}

export function annotationSourceRotationDegrees(ann) {
    const rotations = renderableSourceLines(ann)
        .map((_, index) => sourceLineRotationDegrees(ann, index))
        .filter((value) => value !== 0);

    if (!rotations.length) return 0;

    const positiveCount = rotations.filter((value) => value > 0).length;
    const negativeCount = rotations.length - positiveCount;
    return positiveCount >= negativeCount ? 90 : -90;
}

export function lineSpans(ann, lineIndex) {
    return Array.isArray(renderableSourceLines(ann)[lineIndex]?.spans)
        ? renderableSourceLines(ann)[lineIndex].spans
        : [];
}

// Resolve the fill colour to use when painting one extracted span.
//
// Per-span `hex_color` from the extractor is authoritative for an
// unmodified promoted annotation: the block-level dominant colour
// (persisted into ann.textColor at materialisation) reflects the
// *majority* span colour, so using it for every span overpaints
// minority-colour glyphs (e.g. the white "Part I" badge on the dark
// Schedule 3 header band — block dominant is black, so the span gets
// hidden behind the dark raster background).
//
// ann.textColor only wins once the user has explicitly recoloured the
// annotation via the format bar (_styleDirty), mirroring the same gate
// we apply to font family / weight / style overrides.
export function resolveSpanFillStyle(ann, span) {
    const styleOverride = Boolean(ann?._styleDirty);
    const annTextColor = String(ann?.textColor || '').trim();
    const spanHex = String(span?.hex_color || '').trim();
    if (styleOverride && annTextColor) return annTextColor;
    if (spanHex) return spanHex;
    if (annTextColor) return annTextColor;
    return '#000000';
}
