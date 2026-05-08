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
        if (isBlockTag(tag)) {
            if (out.length === before || !out.endsWith('\n')) out += '\n';
        }
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

/*
 * normalizeRichHtmlForDisplay (Phase 7ar).
 *
 * Strip per-line absolute-positioning artifacts from `_richHtml`
 * blobs before mounting them in the rich-html DOM layer. Galley /
 * source-flow editor builders emit each visual line with its own
 * line-height / transform so it matches the canvas exactly while
 * editing — those rules would force overflow / overlap when the
 * blob is later re-mounted into a wrap-friendly flow container.
 *
 * Walks `[data-line-index]`, `[data-source-span]`,
 * `[data-line-content]` descendants and rewrites the relevant CSS
 * properties to flow-friendly defaults. When `ann` is supplied,
 * `textAlign` and `underline` from the annotation cascade into the
 * per-line elements so the layer matches the ann settings.
 */
export function normalizeRichHtmlForDisplay(html, ann = null) {
    const raw = stripEditorSentinels(html);
    if (!raw.trim()) return '';
    const tmp = document.createElement('div');
    tmp.innerHTML = raw;
    const align = ann ? (ann.textAlign || 'left') : null;
    const decoration = ann ? (ann.underline ? 'underline' : 'none') : null;
    tmp.querySelectorAll('[data-line-index]').forEach((el) => {
        el.style.lineHeight = 'inherit';
        el.style.minHeight = '0';
        el.style.height = 'auto';
        el.style.transform = 'none';
        el.style.removeProperty('transform-origin');
        el.style.display = 'block';
        el.style.width = '100%';
        el.style.boxSizing = 'border-box';
        el.style.whiteSpace = 'pre-wrap';
        el.style.overflowWrap = 'break-word';
        el.style.wordBreak = 'break-word';
        if (align) el.style.textAlign = align;
        if (decoration) el.style.textDecoration = decoration;
    });
    tmp.querySelectorAll('[data-source-span], [data-line-content]').forEach((el) => {
        el.style.whiteSpace = 'normal';
        el.style.overflowWrap = 'break-word';
        el.style.wordBreak = 'break-word';
        el.style.maxWidth = '100%';
    });
    return tmp.innerHTML;
}
