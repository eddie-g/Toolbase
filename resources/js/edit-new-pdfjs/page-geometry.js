export function normalizePageRotationDegrees(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 0;
    return ((Math.round(numeric) % 360) + 360) % 360;
}

function normalizedRect(left, top, right, bottom) {
    const x0 = Math.min(Number(left), Number(right));
    const y0 = Math.min(Number(top), Number(bottom));
    const x1 = Math.max(Number(left), Number(right));
    const y1 = Math.max(Number(top), Number(bottom));
    if (![x0, y0, x1, y1].every(Number.isFinite)) return null;
    return {
        left: x0,
        top: y0,
        width: Math.max(0, x1 - x0),
        height: Math.max(0, y1 - y0),
    };
}

export function pdfRectToViewportRect(pdfRect, viewport, scale = null) {
    if (!pdfRect || !viewport) return null;
    const x = Number(pdfRect.x);
    const y = Number(pdfRect.y);
    const width = Number(pdfRect.w ?? pdfRect.width);
    const height = Number(pdfRect.h ?? pdfRect.height);
    if (![x, y, width, height].every(Number.isFinite) || width < 0 || height < 0) return null;

    if (typeof viewport.convertToViewportRectangle === 'function') {
        const converted = viewport.convertToViewportRectangle([x, y, x + width, y + height]);
        if (Array.isArray(converted) && converted.length >= 4) {
            return normalizedRect(converted[0], converted[1], converted[2], converted[3]);
        }
    }

    const fallbackScale = Number(scale) || Number(viewport.scale) || 1;
    return {
        left: x * fallbackScale,
        top: Number(viewport.height) - ((y + height) * fallbackScale),
        width: width * fallbackScale,
        height: height * fallbackScale,
    };
}

export function viewportPointToPdfPoint(canvasX, canvasY, viewport, scale = null) {
    const x = Number(canvasX);
    const y = Number(canvasY);
    if (!viewport || !Number.isFinite(x) || !Number.isFinite(y)) return null;
    if (typeof viewport.convertToPdfPoint === 'function') {
        const converted = viewport.convertToPdfPoint(x, y);
        if (Array.isArray(converted) && converted.length >= 2
            && converted.slice(0, 2).every(Number.isFinite)) {
            return { x: converted[0], y: converted[1] };
        }
    }
    const fallbackScale = Number(scale) || Number(viewport.scale) || 1;
    return {
        x: x / fallbackScale,
        y: (Number(viewport.height) - y) / fallbackScale,
    };
}

export function viewportRectToPdfRect(viewportRect, viewport, scale = null) {
    if (!viewportRect || !viewport) return null;
    const left = Number(viewportRect.left ?? viewportRect.x);
    const top = Number(viewportRect.top ?? viewportRect.y);
    const width = Number(viewportRect.width ?? viewportRect.w);
    const height = Number(viewportRect.height ?? viewportRect.h);
    if (![left, top, width, height].every(Number.isFinite) || width < 0 || height < 0) return null;

    const points = [
        viewportPointToPdfPoint(left, top, viewport, scale),
        viewportPointToPdfPoint(left + width, top, viewport, scale),
        viewportPointToPdfPoint(left, top + height, viewport, scale),
        viewportPointToPdfPoint(left + width, top + height, viewport, scale),
    ].filter(Boolean);
    if (points.length !== 4) return null;
    const xs = points.map((point) => point.x);
    const ys = points.map((point) => point.y);
    const x0 = Math.min(...xs);
    const y0 = Math.min(...ys);
    const x1 = Math.max(...xs);
    const y1 = Math.max(...ys);
    return {
        x: x0,
        y: y0,
        w: Math.max(0, x1 - x0),
        h: Math.max(0, y1 - y0),
    };
}

export function viewportPdfDimensions(viewport, scale = null) {
    if (!viewport) return { width: 0, height: 0 };
    const rawWidth = Number(viewport.rawDims?.pageWidth);
    const rawHeight = Number(viewport.rawDims?.pageHeight);
    if (rawWidth > 0 && rawHeight > 0) {
        return { width: rawWidth, height: rawHeight };
    }
    const fallbackScale = Number(scale) || Number(viewport.scale) || 1;
    const rotation = normalizePageRotationDegrees(viewport.rotation);
    const viewportWidth = Number(viewport.width) / fallbackScale;
    const viewportHeight = Number(viewport.height) / fallbackScale;
    return rotation === 90 || rotation === 270
        ? { width: viewportHeight, height: viewportWidth }
        : { width: viewportWidth, height: viewportHeight };
}

export function viewportRotatedContentFrame(viewport, displayWidth, displayHeight) {
    const rotation = normalizePageRotationDegrees(viewport?.rotation);
    const width = Math.max(0, Number(displayWidth) || 0);
    const height = Math.max(0, Number(displayHeight) || 0);
    if (rotation === 90) {
        return {
            rotation,
            width: height,
            height: width,
            transform: `translate(${width}px, 0px) rotate(90deg)`,
        };
    }
    if (rotation === 180) {
        return {
            rotation,
            width,
            height,
            transform: `translate(${width}px, ${height}px) rotate(180deg)`,
        };
    }
    if (rotation === 270) {
        return {
            rotation,
            width: height,
            height: width,
            transform: `translate(0px, ${height}px) rotate(-90deg)`,
        };
    }
    return { rotation: 0, width, height, transform: 'none' };
}

export function rotationSnapshotTransform(rotation, snapshotWidth, snapshotHeight, targetWidth, targetHeight) {
    const normalizedRotation = normalizePageRotationDegrees(rotation);
    const sourceWidth = Math.max(1, Number(snapshotWidth) || 1);
    const sourceHeight = Math.max(1, Number(snapshotHeight) || 1);
    const displayWidth = Math.max(1, Number(targetWidth) || 1);
    const displayHeight = Math.max(1, Number(targetHeight) || 1);
    const widthScale = (normalizedRotation === 90 || normalizedRotation === 270)
        ? displayWidth / sourceHeight
        : displayWidth / sourceWidth;
    const heightScale = (normalizedRotation === 90 || normalizedRotation === 270)
        ? displayHeight / sourceWidth
        : displayHeight / sourceHeight;
    const scale = Math.max(0.0001, Math.min(widthScale, heightScale));

    if (normalizedRotation === 90) {
        return `matrix(0, ${scale}, ${-scale}, 0, ${displayWidth}, 0)`;
    }
    if (normalizedRotation === 180) {
        return `matrix(${-scale}, 0, 0, ${-scale}, ${displayWidth}, ${displayHeight})`;
    }
    if (normalizedRotation === 270) {
        return `matrix(0, ${-scale}, ${scale}, 0, 0, ${displayHeight})`;
    }
    return `matrix(${scale}, 0, 0, ${scale}, 0, 0)`;
}

/**
 * Inline styles for a stamped image (signature / drawing) that must fill its
 * annotation box exactly.
 *
 * On an unrotated page the answer is "no inline sizing at all": the stylesheet
 * already sizes these images at 100% of the box, and any inline pixel value
 * overrides it. That was the NK_DEV_6 bug — the pixels were computed once at
 * render time, so resizing the box left the stamp at its old height and the
 * ink spilled outside the selection.
 *
 * A rotated page genuinely needs explicit dimensions because the axes swap, so
 * those are returned in pixels and must be recomputed whenever the box resizes.
 *
 * `width`/`height` of null mean "clear any inline value and let CSS decide".
 *
 * @param {number|string|null|undefined} rotation page rotation in degrees
 * @param {number} boxWidth  current box width in CSS pixels
 * @param {number} boxHeight current box height in CSS pixels
 * @returns {{width: string|null, height: string|null, transform: string, followsBox: boolean}}
 */
export function stampFrameStyle(rotation, boxWidth, boxHeight) {
    const normalized = normalizePageRotationDegrees(rotation);

    if (normalized === 0) {
        return { width: null, height: null, transform: 'none', followsBox: true };
    }

    const frame = viewportRotatedContentFrame({ rotation: normalized }, boxWidth, boxHeight);
    return {
        width: `${Math.max(1, frame.width)}px`,
        height: `${Math.max(1, frame.height)}px`,
        transform: frame.transform,
        followsBox: false,
    };
}

/** Apply a stampFrameStyle() result to an element. */
export function applyStampFrameStyle(element, style) {
    if (!element || !style) return;
    element.style.width = style.width === null ? '' : style.width;
    element.style.height = style.height === null ? '' : style.height;
    element.style.transform = style.transform;
}
