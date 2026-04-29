/*
 * resolveDisplayBgColor — pick the background color used for editor
 * rendering of a text annotation (Phase 7u).
 *
 * Substitutes a dark placeholder when an annotation has white/near-
 * white text against a missing/transparent background, so the user
 * can see the text in the editor without mutating the saved
 * `backgroundColor` (which must stay transparent for PDF export).
 *
 * Pure: depends only on the annotation properties.
 */

export const WHITE_TEXT_RE = /^#?f(?:ff|[e-f]{2})(?:f(?:ff|[e-f]{2})){0,2}$/i;

export function resolveDisplayBgColor(ann) {
    const bgRaw = String(ann?.backgroundColor || '').trim();
    const bg = (bgRaw && bgRaw.toLowerCase() !== '#ffffff' && bgRaw.toLowerCase() !== 'transparent')
        ? bgRaw : 'transparent';
    if (bg !== 'transparent') return bg;
    const textColor = String(ann?.textColor || '').trim();
    if (textColor && WHITE_TEXT_RE.test(textColor.replace(/\s/g, ''))) {
        return '#2c3e50'; // dark slate placeholder for white-on-missing-bg text
    }
    return 'transparent';
}
