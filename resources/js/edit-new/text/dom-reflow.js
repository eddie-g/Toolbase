/*
 * DOM-reflow text/HTML normalizers (Phase 7ag).
 *
 * Promoted-extraction annotations carry literal `\n` at every PDF
 * source-line boundary (in `text` / `editedTexts`) and one
 * `<div data-line-index="N">` block per source line (in `_richHtml`).
 * With CSS `white-space: pre-wrap` and `display:block` blocks, those
 * hard breaks freeze text at the original PDF line positions and
 * defeat browser word-wrap when the user resizes the bounding box.
 *
 * Only call these when:
 *   - the annotation was promoted from extraction,
 *   - the text/HTML is unedited (still matches source), AND
 *   - the bounding box dimensions have changed (resize).
 *
 * Behavior:
 *   normalizeTextForDomReflow:
 *     Collapse single `\n` (soft, source-imposed breaks) to a space.
 *     Preserve `\n\n+` (paragraph breaks the user typed via Enter).
 *
 *   normalizeRichHtmlForReflow:
 *     Merge all top-level `<div data-line-index="N">` blocks into the
 *     first block, joining their inner HTML with a literal space.
 *     Inline `<br>` / `<br><br>` runs are preserved verbatim (Enter →
 *     hard break the user typed). Stray `\n` inside text nodes is
 *     also collapsed.
 *
 * Pure: depends only on the DOM document for the rich-html parser.
 *
 * NOTE: main.js previously had two duplicate copies of each helper
 * (lines ~2090 and ~3114). The second declaration shadowed the first
 * via hoisting, so the canonical (second) version's behavior is what
 * was actually wired up — kept here verbatim.
 */

export function normalizeTextForDomReflow(text) {
    return String(text ?? '')
        .replace(/\r\n?/g, '\n')
        // Protect double-newlines (explicit paragraph breaks)
        .replace(/\n{2,}/g, '\x00')
        // Collapse single \n (extraction line-break) → space
        .replace(/\n/g, ' ')
        // Restore double-newlines
        .replace(/\x00/g, '\n\n')
        // Collapse runs of spaces that straddled line-breaks
        .replace(/[ \t]{2,}/g, ' ')
        .trim();
}

export function normalizeRichHtmlForReflow(html) {
    const src = String(html ?? '');
    if (!src) return src;
    const tmp = document.createElement('div');
    tmp.innerHTML = src;

    // Collapse stray \n in every text node (single \n → space, \n\n+ → preserved).
    const collapseNewlines = (root) => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        const textNodes = [];
        let n;
        while ((n = walker.nextNode())) textNodes.push(n);
        textNodes.forEach((tn) => {
            const v = tn.nodeValue;
            if (!v || v.indexOf('\n') === -1) return;
            tn.nodeValue = v
                .replace(/\r\n?/g, '\n')
                .replace(/\n{2,}/g, '\x00')
                .replace(/\n/g, ' ')
                .replace(/\x00/g, '\n\n')
                .replace(/[ \t]{2,}/g, ' ');
        });
    };

    // Find top-level data-line-index blocks (children of tmp).
    const blocks = Array.from(tmp.children).filter(
        (el) => el.tagName === 'DIV' && el.hasAttribute('data-line-index')
    );

    if (blocks.length >= 2) {
        // Merge all into the first block, joining inner HTML with a literal space.
        // Each block's inner HTML is added verbatim (preserving inline <br>s and
        // styled spans like the bold "market") with " " between consecutive blocks.
        const first = blocks[0];
        const mergedParts = blocks.map((b) => b.innerHTML.trim()).filter((s) => s.length > 0);
        const joined = mergedParts.join(' ');
        first.innerHTML = joined;
        // Remove the residual blocks.
        for (let i = 1; i < blocks.length; i++) {
            if (blocks[i].parentNode) blocks[i].parentNode.removeChild(blocks[i]);
        }
        collapseNewlines(first);
    } else if (blocks.length === 1) {
        collapseNewlines(blocks[0]);
    } else {
        collapseNewlines(tmp);
    }

    return tmp.innerHTML;
}
