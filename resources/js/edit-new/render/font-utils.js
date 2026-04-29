// Pure helpers for inspecting / normalizing font metadata coming from the
// extraction pipeline. No DOM, no module-private state — safe to import
// from anywhere.

const PDF_SUBSET_FONT_RE = /^[A-Z]{6}\+/;

/**
 * Strip the `PDF_` prefix, the trailing `PSMT` / `PS-…MT` artefacts that
 * pdf.js leaves on PostScript names, and any leading 6-letter subset tag
 * (e.g. "AAAAAA+Arial" → "Arial").
 */
export const normalizeFontName = (name) => {
    let s = String(name || '').replace(/^PDF_/i, '').trim();
    if (!s) return '';
    s = s.replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').trim();
    if (s.includes('+')) {
        const p = s.split('+', 2);
        if (p[0].length === 6) s = p[1];
    }
    return s;
};

/** Map a font-file extension to a CSS @font-face `format()` keyword. */
export const fontFileFormat = (fileExt) => {
    const ext = String(fileExt || 'ttf').toLowerCase();
    if (ext === 'woff2') return 'woff2';
    if (ext === 'woff') return 'woff';
    return (ext === 'otf' || ext === 'cff') ? 'opentype' : 'truetype';
};

/** True when the font payload describes a PDF subset (e.g. "AAAAAA+Arial"). */
export const isSubsetEmbeddedFontData = (fontData) => {
    const pdfName = String(fontData?.pdf_font_name || '').trim();
    return PDF_SUBSET_FONT_RE.test(pdfName);
};

/**
 * PDF subset fonts frequently have PDF-private glyph encodings. They render
 * correctly inside the original PDF because the content stream uses the
 * subset's character codes, but rendering Unicode text through the same font
 * in browser canvas/DOM can map letters to the wrong glyphs. Skip those (and
 * the GNU FreeFont family, which has the same problem) so the overlay falls
 * back to a system family.
 */
export const shouldBypassEmbeddedFont = (name, family = '', fontData = null) => {
    const rawName = normalizeFontName(name);
    const rawFamily = normalizeFontName(family);
    return isSubsetEmbeddedFontData(fontData)
        || /^Free(?:Sans|Serif|Mono)/i.test(rawName)
        || /^Free(?:Sans|Serif|Mono)/i.test(rawFamily)
        || /^ArialMT$/i.test(rawName);
};
