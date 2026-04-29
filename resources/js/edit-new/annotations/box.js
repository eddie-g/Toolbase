// Pure annotation bounding-box resolution. Reads PDF-space coordinates off an
// annotation, falling back to the source-block extraction box, and computes
// offsets relative to the annotation's original box. No DOM, no editor state.

export function resolveAnnBox(ann) {
    const px = Number(ann.pdfX), py = Number(ann.pdfY);
    const pw = Number(ann.pdfWidth), ph = Number(ann.pdfHeight);
    if ([px, py, pw, ph].every(Number.isFinite) && pw > 0 && ph > 0) return { x: px, y: py, w: pw, h: ph };
    return resolveSourceAnnBox(ann);
}

export function resolveSourceAnnBox(ann) {
    const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
    const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
    const pageH = Number(ann.sourcePageHeight);
    if ([sL, sT, sW, sH, pageH].every(Number.isFinite) && sW > 0 && sH > 0)
        return { x: sL, y: pageH - (sT + sH), w: sW, h: sH };
    return null;
}

export function resolveOriginalAnnBox(ann) {
    const box = ann._originalBox;
    if (box && [box.x, box.y, box.w, box.h].every(Number.isFinite)) return box;
    return resolveAnnBox(ann);
}

export function annotationOffset(ann) {
    const cur = resolveAnnBox(ann), orig = resolveOriginalAnnBox(ann);
    if (!cur || !orig) return { dx: 0, dy: 0 };
    return { dx: cur.x - orig.x, dy: cur.y - orig.y };
}

export function annotationSourceOffset(ann) {
    const cur = resolveAnnBox(ann), orig = resolveOriginalAnnBox(ann);
    if (!cur || !orig) return { dx: 0, dy: 0 };
    // dx: horizontal shift in CSS/source space (same in both coordinate systems)
    // dy: change in visual TOP in CSS top-down space.
    //     The visual top = pageH - (pdfY + pdfH) in PDF pts.
    //     When height changes (resize + move) the old formula -(cur.y - orig.y)
    //     diverges because it measures BOTTOM corner movement, not TOP.
    return { dx: cur.x - orig.x, dy: (orig.y + orig.h) - (cur.y + cur.h) };
}
