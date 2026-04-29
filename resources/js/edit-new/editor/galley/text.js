/*
 * editor/galley/text (Phase 7bc).
 *
 * Pure helpers for the galley-rendered editor (the "source flow"
 * mode where each text line is a div with .ae-galley-source-span
 * + .ae-galley-tail children). They translate the live DOM into
 * the canonical newline-joined string we want to persist into
 * editedTexts, and diff a prev/next pair to detect typed input.
 *
 * No store, no events, no side effects — just DOM reads and string
 * arithmetic.
 */

export function getGalleyEditorText(ae) {
    if (!(ae instanceof HTMLElement)) return '';
    const lines = ae.querySelectorAll('.ae-galley-line');
    if (!lines.length) return '';
    const lineTexts = [];
    lines.forEach((line) => {
        let txt = '';
        line.querySelectorAll('.ae-galley-source-span, .ae-galley-tail').forEach((node) => {
            txt += node.textContent || '';
        });
        lineTexts.push(txt);
    });
    return lineTexts.join('\n');
}

export function expectedGalleyText(ann, ae) {
    if (!(ae instanceof HTMLElement)) return '';
    const lines = ae.querySelectorAll('.ae-galley-line');
    if (!lines.length) return '';
    const appends = (ann && ann._galleyAppends && typeof ann._galleyAppends === 'object') ? ann._galleyAppends : {};
    const lineTexts = [];
    lines.forEach((line) => {
        const lineIdx = Number(line.dataset.lineIndex || 0);
        let txt = '';
        line.querySelectorAll('.ae-galley-source-span').forEach((node) => {
            txt += node.dataset.originalText || '';
        });
        txt += String(appends[lineIdx] || '');
        lineTexts.push(txt);
    });
    return lineTexts.join('\n');
}

export function diffGalleyAdded(prev, next) {
    const p = String(prev || '');
    const n = String(next || '');
    if (n.length <= p.length) return '';
    let i = 0;
    while (i < p.length && p.charCodeAt(i) === n.charCodeAt(i)) i++;
    let j = 0;
    const maxJ = p.length - i;
    while (j < maxJ && p.charCodeAt(p.length - 1 - j) === n.charCodeAt(n.length - 1 - j)) j++;
    return n.slice(i, n.length - j);
}
