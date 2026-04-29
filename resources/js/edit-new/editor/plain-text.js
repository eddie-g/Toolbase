/*
 * Editor plain-text + sentinel helpers (Phase 7an).
 *
 *   - getEditorPlainText: walk the contenteditable DOM and produce a
 *     canonical plain-text representation. Special-cased so that
 *     `[data-source-gap]` spans (which renderEditorGapHtml emits with
 *     literal multi-space runs sized to pixel width) collapse to a
 *     single space — otherwise a save/edit round-trip inflates the
 *     persisted text by 4-8x per gap each session. <BR> becomes '\n',
 *     block-level elements (DIV/P/LI/TR) get a trailing '\n', NBSP
 *     becomes a regular space, zero-width spaces are stripped, and a
 *     trailing newline is trimmed.
 *
 *   - stripEditorSentinels: remove zero-width-space sentinel
 *     characters used internally as caret anchors.
 *
 *   - richHtmlHasInlineSelectionFormatting: true when the rich HTML
 *     contains any inline-formatting tags (styled spans, font, b,
 *     strong, i, em, u) — used to decide whether saving needs to
 *     preserve per-selection formatting.
 */

export function getEditorPlainText(editor) {
    if (!editor) return '';
    // Walk the DOM rather than using innerText. The source-flow editor
    // emits `[data-source-gap]` spans containing literal multi-space runs
    // sized to pixel width (see renderEditorGapHtml) — innerText would
    // capture those and inflate canonical text by 4-8x per gap on every
    // edit-text round trip, which then gets persisted back into ann.text
    // by saveAllChanges, ballooning whitespace each session.
    // Treat each gap as a single space, mirroring the canonical token
    // separator in extracted text. BR -> '\n', block-level elements get
    // a trailing newline to preserve line structure.
    let out = '';
    const isBlockTag = (tag) => tag === 'DIV' || tag === 'P' || tag === 'LI' || tag === 'TR';
    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) { out += node.nodeValue; return; }
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (node.dataset && node.dataset.sourceGap === '1') { out += ' '; return; }
        const tag = node.nodeName;
        if (tag === 'BR') { out += '\n'; return; }
        const before = out.length;
        for (const child of node.childNodes) walk(child);
        if (isBlockTag(tag) && out.length > before && !out.endsWith('\n')) out += '\n';
    };
    for (const child of editor.childNodes) walk(child);
    return out
        .replace(/\r/g, '')
        .replace(/\u00a0/g, ' ')
        .replace(/\u200b/g, '')
        .replace(/\n$/, '');
}

export function stripEditorSentinels(value) {
    return String(value ?? '').replace(/\u200b/g, '');
}

export function richHtmlHasInlineSelectionFormatting(html) {
    const raw = String(html ?? '');
    if (!raw.trim()) return false;
    const tmp = document.createElement('div');
    tmp.innerHTML = raw;
    return Boolean(tmp.querySelector('span[style], font, b, strong, i, em, u'));
}
