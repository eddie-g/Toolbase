// Pure annotation normalizers: coerce free-form annotation objects coming
// from extraction or persistence into a canonical shape with sensible
// defaults. No DOM, no editor state. Each function returns the same
// (mutated) ann object for call-site convenience.

import { clamp01 } from '../util/math.js';
import { normalizeHexColor } from '../util/color.js';
import {
    isShapeAnnotation,
    isImageBackedAnnotation,
    isDirectDrawAnnotation,
    isLineShape,
    normalizeSignatureSourceMode,
} from './types.js';
import {
    normalizeShapeType,
    defaultPolygonUnitPoints,
    normalizePolygonPointList,
} from './shape-geometry.js';
import { normalizeDirectDrawTool } from '../draw/tool-type.js';

export function normalizeShapeAnnotation(ann) {
    if (!isShapeAnnotation(ann)) return ann;
    ann.shapeType = normalizeShapeType(ann.shapeType);
    ann.strokeColor = normalizeHexColor(ann.strokeColor, '#0f172a');
    ann.fillColor = normalizeHexColor(ann.fillColor, '#22c55e');
    ann.strokeOpacity = clamp01(ann.strokeOpacity ?? ann.opacity ?? 1, 1);
    ann.fillOpacity = clamp01(ann.fillOpacity ?? (ann.fillTransparent ? 0 : 0.22), ann.fillTransparent ? 0 : 0.22);
    const strokeWidth = Number(ann.strokeWidth);
    ann.strokeWidth = Math.max(0, Number.isFinite(strokeWidth) ? strokeWidth : 3);
    ann.strokeTransparent = Boolean(ann.strokeTransparent);
    ann.fillTransparent = Boolean(ann.fillTransparent);
    ann.rotation = normalizeShapeRotationDegrees(ann.rotation);
    if (ann.shapeType === 'polygon') {
        ann.polygonPoints = normalizePolygonPointList(ann.polygonPoints, defaultPolygonUnitPoints());
    }
    if (isLineShape(ann)) {
        // Line endpoint convention: Y is image-y-down (0 = visual top of
        // bounding box, 1 = visual bottom). This matches /edit and the
        // Python PDF exporter (apply_annotations_direct*.py) which both
        // interpret lineStartY/lineEndY the same way. The default for a
        // freshly-defaulted line is top-left → bottom-right (↘).
        ann.lineStartX = clamp01(ann.lineStartX, 0);
        ann.lineStartY = clamp01(ann.lineStartY, 0);
        ann.lineEndX = clamp01(ann.lineEndX, 1);
        ann.lineEndY = clamp01(ann.lineEndY, 1);
        const cap = String(ann.lineCap || 'round').toLowerCase();
        ann.lineCap = (cap === 'butt' || cap === 'square') ? cap : 'round';
    }
    return ann;
}

/**
 * Apply a partial shape inspector state (stroke/fill colors, opacities,
 * widths, transparent flags) onto a shape annotation, then re-normalize
 * so dependent fields (line endpoints, polygon points) remain coherent.
 * No-op for non-shape annotations.
 */
export function applyShapeStateToAnnotation(ann, shapeState) {
    if (!ann || !isShapeAnnotation(ann)) return;
    const normalized = normalizeShapeAnnotation({ ...ann, ...shapeState });
    ann.shapeType = normalized.shapeType;
    ann.strokeColor = normalized.strokeColor;
    ann.strokeOpacity = normalized.strokeOpacity;
    ann.strokeWidth = normalized.strokeWidth;
    ann.strokeTransparent = normalized.strokeTransparent;
    ann.fillColor = normalized.fillColor;
    ann.fillOpacity = normalized.fillOpacity;
    ann.fillTransparent = normalized.fillTransparent;
    ann.rotation = normalized.rotation;
    if (isLineShape(normalized)) {
        ann.lineStartX = normalized.lineStartX;
        ann.lineStartY = normalized.lineStartY;
        ann.lineEndX = normalized.lineEndX;
        ann.lineEndY = normalized.lineEndY;
    }
}

function normalizeShapeRotationDegrees(value) {
    const angle = Number(value);
    if (!Number.isFinite(angle)) return 0;
    let normalized = angle % 360;
    if (normalized < 0) normalized += 360;
    return Math.abs(normalized) < 1e-6 ? 0 : normalized;
}

export function normalizeImageAnnotation(ann) {
    if (!isImageBackedAnnotation(ann)) return ann;
    if (String(ann.type || '').toLowerCase() === 'signature') {
        ann.signatureSourceMode = normalizeSignatureSourceMode(ann.signatureSourceMode);
    } else {
        delete ann.signatureSourceMode;
    }
    ann.opacity = clamp01(ann.opacity ?? 1, 1);
    ann.rotation = Number(ann.rotation) || 0;
    ann.fileName = String(ann.fileName || (String(ann.type || '').toLowerCase() === 'signature' ? 'signature.png' : 'image.png'));
    ann.mimeType = String(ann.mimeType || 'image/png');
    ann.intrinsicWidth = Math.max(1, Number(ann.intrinsicWidth || ann.width || ann.imageWidth) || 1);
    ann.intrinsicHeight = Math.max(1, Number(ann.intrinsicHeight || ann.height || ann.imageHeight) || 1);
    if (isDirectDrawAnnotation(ann)) {
        ann.drawStrokeColor = normalizeHexColor(ann.drawStrokeColor, '#111827');
        ann.directDrawTool = normalizeDirectDrawTool(ann.directDrawTool);
    } else {
        delete ann.drawStrokeColor;
        delete ann.directDrawTool;
        delete ann.directDrawVector;
    }
    if (isDirectDrawAnnotation(ann) && ann.directDrawVector) {
        ann.directDrawVector = normalizeDirectDrawVector(ann.directDrawVector);
    }
    return ann;
}

function normalizeDirectDrawVector(vector) {
    if (!vector || typeof vector !== 'object') return null;
    const width = Math.max(1, Number(vector.width) || 1);
    const height = Math.max(1, Number(vector.height) || 1);
    const strokes = Array.isArray(vector.strokes) ? vector.strokes : [];
    const normalizedStrokes = strokes.map((stroke) => {
        const points = Array.isArray(stroke?.points) ? stroke.points : [];
        const normalizedPoints = points.map((point) => ({
            x: Math.max(0, Math.min(width, Number(point?.x) || 0)),
            y: Math.max(0, Math.min(height, Number(point?.y) || 0)),
        }));
        if (!normalizedPoints.length) return null;
        return {
            color: normalizeHexColor(stroke?.color, '#111827'),
            opacity: clamp01(stroke?.opacity ?? 1, 1),
            brushSize: Math.max(0.25, Number(stroke?.brushSize) || 1),
            smoothCurve: Boolean(stroke?.smoothCurve),
            points: normalizedPoints,
        };
    }).filter(Boolean);
    if (!normalizedStrokes.length) return null;
    return {
        version: 1,
        width,
        height,
        strokes: normalizedStrokes,
    };
}

/**
 * For a promoted-from-extraction annotation whose visible text still
 * matches the source PDF, return the original source-block placement
 * (top-left + width/height in source PDF y-down coords). Used by the
 * canvas erase rect and the rich-html DOM layer to override a bloated
 * pdfHeight that would otherwise push the rendered text up by one or
 * more line-heights and overlap the annotation above. We never mutate
 * the stored annotation; this is render-only correction.
 */
export function correctedSourceBlockBox(ann, box) {
    if (!ann || !box) return null;
    const isPromoted = ann.promotedFromExtraction === true
        || (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
    if (!isPromoted) return null;
    const sBT = Number(ann.sourceBlockTop);
    const sBL = Number(ann.sourceBlockLeft);
    const sBW = Number(ann.sourceBlockWidth);
    const sBH = Number(ann.sourceBlockHeight);
    if (![sBT, sBL, sBW, sBH].every(Number.isFinite) || sBW <= 0 || sBH <= 0) return null;
    // Only apply when the visible text matches the original extraction.
    const txt = String(ann.text ?? '');
    const orig = String(ann.originalText ?? '');
    if (!orig || txt !== orig) return null;
    // Only apply when the annotation hasn't been moved away from its
    // recorded original position (allow ~5pt slop because some loaders
    // re-snap x to the dominant span x).
    const origBox = ann._originalBox;
    if (origBox) {
        if (Math.abs(box.y - origBox.y) > 0.5) return null;
    }
    // Only kick in when the stored pdfHeight is bloated past the natural
    // source block height (the bug case). A box that exactly matches the
    // source block needs no correction.
    if (!(box.h > sBH + 1)) return null;
    return { left: sBL, top: sBT, width: sBW, height: sBH };
}
