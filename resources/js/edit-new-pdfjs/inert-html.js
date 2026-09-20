/*
 * HTML that did not come from the editor (the clipboard) is parsed in an
 * inert document. `div.innerHTML = html` in the live document loads its
 * images at once, and an <img onerror=…> runs even though the div is never
 * attached and only its text is read. A document made by DOMParser loads
 * nothing and runs nothing.
 */

const BLOCKS = new Set(['ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DD', 'DIV', 'DL', 'DT', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'LI', 'MAIN', 'OL', 'P', 'PRE', 'SECTION', 'TABLE', 'TR', 'UL']);
const SKIPPED = new Set(['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'HEAD', 'TITLE', 'META', 'LINK']);

/** The <body> of an inert document holding the markup. */
export function parseInertHtml(html, ParserImpl = globalThis.DOMParser) {
    return new ParserImpl().parseFromString(String(html || ''), 'text/html').body;
}

/** The text of a node tree with a line break per <br> and per block, as a paste should read. */
export function textOfNode(node) {
    let out = '';
    const visit = (current) => {
        if (current.nodeType === 3) {
            out += current.nodeValue || '';

            return;
        }
        if (current.nodeType !== 1) return;
        const tag = String(current.tagName || '').toUpperCase();
        if (SKIPPED.has(tag)) return;
        if (tag === 'BR') {
            out += '\n';

            return;
        }
        const block = BLOCKS.has(tag);
        if (block && out !== '' && !out.endsWith('\n')) out += '\n';
        for (const child of current.childNodes || []) visit(child);
        if (block && !out.endsWith('\n')) out += '\n';
    };
    visit(node);

    return out.replace(/ /g, ' ').replace(/\n+$/, '');
}

export function plainTextFromHtml(html, ParserImpl = globalThis.DOMParser) {
    return textOfNode(parseInertHtml(html, ParserImpl));
}
