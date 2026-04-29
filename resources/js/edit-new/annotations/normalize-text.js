/*
 * Text-annotation normalizer (Phase 7n).
 *
 * Lifted out of main.js. Handles backgroundColor coercion, line-height
 * resolution for reflowed (user-authored / styled / rich-text) text
 * annotations, and the top-level normalizeTextAnnotation entry point.
 *
 * Depends on the renderableSourceLines + sourceLineRotationDegrees
 * modules already extracted in 7k / 7l.
 */

import { renderableSourceLines } from './source-lines.js';
import { sourceLineRotationDegrees } from './source-geometry.js';

export function normalizeTextBackgroundColorValue(value, explicit = false) {
    const raw = String(value ?? '').trim();
    if (!raw) return 'transparent';
    const lower = raw.toLowerCase();
    if (lower === 'transparent') return 'transparent';
    if (!explicit && (lower === '#fff' || lower === '#ffffff' || lower === 'white')) {
        return 'transparent';
    }
    return raw;
}

export function sourceAverageLineHeightPts(ann) {
    const lines = renderableSourceLines(ann);
    if (!Array.isArray(lines) || !lines.length) return 0;
    const heights = lines.map((line, index) => {
        const bbox = Array.isArray(line?.bbox) ? line.bbox : null;
        if (!bbox || bbox.length < 4) return 0;
        const rotation = sourceLineRotationDegrees(ann, index);
        const extent = Math.abs(rotation) === 90
            ? (Number(bbox[2]) - Number(bbox[0]))
            : (Number(bbox[3]) - Number(bbox[1]));
        return Number.isFinite(extent) && extent > 0 ? extent : 0;
    }).filter((height) => Number.isFinite(height) && height > 0);
    if (!heights.length) return 0;
    return heights.reduce((sum, height) => sum + height, 0) / heights.length;
}

export function resolveReflowedTextLineHeightPts(ann) {
    const fontSizePt = Math.max(0, Number(ann?.fontSize) || 0);
    const fontDrivenHeightPts = fontSizePt > 0 ? (fontSizePt * 1.18) : 0;
    const sourceDrivenHeightPts = sourceAverageLineHeightPts(ann);
    const savedLineHeightPts = Math.max(0, Number(ann?.lineHeight) || 0);
    return Math.max(fontDrivenHeightPts, sourceDrivenHeightPts, savedLineHeightPts);
}

export function normalizeTextAnnotation(ann) {
    if (!ann || String(ann.type || '').toLowerCase() !== 'text') return ann;
    ann.backgroundColorExplicit = Boolean(ann.backgroundColorExplicit);
    ann.backgroundColor = normalizeTextBackgroundColorValue(
        ann.backgroundColor,
        ann.backgroundColorExplicit
    );
    const usesReflowedLayout = Boolean(
        ann.userCreated
        || ann.userAuthored
        || ann._userAuthored
        || ann.promotedDirty
        || ann._styleDirty
        || (typeof ann.richTextHtml === 'string' && ann.richTextHtml.trim())
        || (typeof ann._richHtml === 'string' && ann._richHtml.trim())
    );
    if (usesReflowedLayout) {
        const normalizedLineHeight = resolveReflowedTextLineHeightPts(ann);
        if (normalizedLineHeight > 0) {
            ann.lineHeight = normalizedLineHeight;
        }
    }
    return ann;
}
