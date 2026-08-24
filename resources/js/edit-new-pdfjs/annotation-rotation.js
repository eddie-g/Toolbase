/*
 * Annotation rotation (NK_9).
 *
 * Text boxes and signatures rotate their CONTENT inside a box that stays
 * axis-aligned, rather than rotating the box itself. That is not an arbitrary
 * choice: the PDF writer does the same — draw_text builds a rotation morph
 * about the centre of an axis-aligned rect — so the editor and the exported
 * page agree about what a rotated annotation is. It also leaves dragging,
 * resizing and the hover menu working on unrotated geometry, and matches how
 * shapes already behave.
 *
 * A page can be rotated too, and that transform is already applied to the same
 * element. These helpers compose the two, in the right order and about the
 * right origin.
 */

/** Degrees, normalised to [0, 360). */
export function normalizeAnnotationRotation(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 0;
    const wrapped = numeric % 360;
    return wrapped < 0 ? wrapped + 360 : wrapped;
}

/** True when the angle is close enough to zero to skip the transform entirely. */
export function isUprightRotation(value) {
    const normalized = normalizeAnnotationRotation(value);
    return normalized < 0.01 || normalized > 359.99;
}

/**
 * CSS transform for an annotation's content.
 *
 * @param {string|null} pageFrameTransform transform already applied for page
 *   rotation, with a `0 0` origin. 'none'/'' means the page is upright.
 * @param {number} frameWidth  content width in CSS px, in the page frame
 * @param {number} frameHeight content height in CSS px, in the page frame
 * @param {number} rotation    the annotation's own angle, in degrees
 * @returns {string} a value for `style.transform`, always with origin `0 0`
 */
export function contentRotationTransform(pageFrameTransform, frameWidth, frameHeight, rotation) {
    const base = String(pageFrameTransform || '').trim();
    const pageTransform = base && base !== 'none' ? base : '';

    if (isUprightRotation(rotation)) {
        return pageTransform || 'none';
    }

    const width = Math.max(0, Number(frameWidth) || 0);
    const height = Math.max(0, Number(frameHeight) || 0);
    const cx = width / 2;
    const cy = height / 2;
    const angle = normalizeAnnotationRotation(rotation);

    // The spin is expressed inside the page frame's own coordinate space, so it
    // follows the page transform rather than fighting it. Origin stays 0 0
    // because the page transform depends on that.
    const spin = `translate(${round(cx)}px, ${round(cy)}px) rotate(${round(angle)}deg) `
        + `translate(${round(-cx)}px, ${round(-cy)}px)`;

    return pageTransform ? `${pageTransform} ${spin}` : spin;
}

/**
 * Axis-aligned size a rotated rectangle needs.
 *
 * Used by the export path, which has to place a rotated signature image inside
 * a rect big enough to hold it.
 */
export function rotatedBoundingSize(width, height, rotation) {
    const w = Math.max(0, Number(width) || 0);
    const h = Math.max(0, Number(height) || 0);
    const radians = (normalizeAnnotationRotation(rotation) * Math.PI) / 180;
    const cos = Math.abs(Math.cos(radians));
    const sin = Math.abs(Math.sin(radians));
    return {
        width: (w * cos) + (h * sin),
        height: (w * sin) + (h * cos),
    };
}

function round(value) {
    return Math.round(value * 1000) / 1000;
}
