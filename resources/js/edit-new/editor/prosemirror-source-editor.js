/**
 * ProseMirror-backed editor for the debug modal's "live render" pane in
 * source-exact mode. The DOM produced by buildEditorHTML(ann, scale) is
 * parsed into a structured PM document where each top-level
 * [data-line-index] block becomes a `line` node carrying its absolute
 * position attrs, and each inline span becomes text marked with a
 * `pdfRun` mark capturing fontFamily/fontSize/fontWeight/fontStyle/color.
 *
 * Editing benefits over raw contenteditable:
 *  - Enter splits a line block cleanly (PM handles caret + DOM update).
 *  - Backspace/Delete across span boundaries does not shred mark spans.
 *  - Per-character font/size/color marks survive insert/delete instead
 *    of disappearing into the surrounding plain text.
 *  - Undo/redo (Mod-z / Mod-y) work natively.
 *
 * On save, getLineTexts() walks the doc and emits one string per `line`
 * node, splitting on hard breaks within a line, suitable for
 * applySourceExactTextEdit(ann, lineTexts).
 */
import { EditorState } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { Schema } from 'prosemirror-model';
import { keymap } from 'prosemirror-keymap';
import { baseKeymap } from 'prosemirror-commands';
import { history, undo, redo } from 'prosemirror-history';

const schema = new Schema({
    nodes: {
        doc: { content: 'line+' },
        line: {
            content: '(text | hard_break)*',
            attrs: {
                lineIndex: { default: '' },
                left: { default: '' },
                top: { default: '' },
                width: { default: '' },
                height: { default: '' },
                lineHeight: { default: '' },
                marginTop: { default: '' },
                fontFamily: { default: '' },
                fontSize: { default: '' },
                fontWeight: { default: '400' },
                fontStyle: { default: 'normal' },
                color: { default: '#000' },
                textAlign: { default: 'left' },
                transform: { default: '' },
                contentTransform: { default: '' },
            },
            toDOM(node) {
                const a = node.attrs;
                // Render lines as plain block divs (NO position:absolute
                // and NO per-line transforms) so the user sees stacked,
                // editable text -- but keep the original line's font
                // family/size/weight/style/color and text-align so the
                // editor visually matches the source.
                //
                // Preserve the source line's horizontal `left` offset as
                // padding-left so centered/indented lines keep their
                // visual alignment when we drop absolute positioning.
                const parts = ['display:block', 'margin:0', 'padding:0', 'white-space:pre-wrap', 'overflow-wrap:break-word', 'word-break:break-word'];
                if (a.marginTop) parts.push('margin-top:' + a.marginTop);
                if (a.left) parts.push('padding-left:' + a.left);
                if (a.fontFamily) parts.push('font-family:' + a.fontFamily);
                if (a.fontSize) parts.push('font-size:' + a.fontSize);
                parts.push('font-weight:' + (a.fontWeight || '400'));
                parts.push('font-style:' + (a.fontStyle || 'normal'));
                parts.push('color:' + (a.color || '#000'));
                parts.push('text-align:' + (a.textAlign || 'left'));
                if (a.lineHeight) parts.push('line-height:' + a.lineHeight);
                return [
                    'div',
                    {
                        'data-line-index': String(a.lineIndex || ''),
                        class: 'pm-line',
                        style: parts.join(';'),
                    },
                    0,
                ];
            },
        },
        text: {},
        hard_break: {
            inline: true,
            group: 'inline',
            selectable: false,
            toDOM() { return ['br']; },
            parseDOM: [{ tag: 'br' }],
        },
    },
    marks: {
        pdfRun: {
            attrs: {
                fontFamily: { default: '' },
                fontSize: { default: '' },
                fontWeight: { default: '400' },
                fontStyle: { default: 'normal' },
                color: { default: '#000' },
            },
            toDOM(mark) {
                const a = mark.attrs;
                const parts = [];
                if (a.fontFamily) parts.push('font-family:' + a.fontFamily);
                if (a.fontSize) parts.push('font-size:' + a.fontSize);
                if (a.fontWeight) parts.push('font-weight:' + a.fontWeight);
                if (a.fontStyle) parts.push('font-style:' + a.fontStyle);
                if (a.color) parts.push('color:' + a.color);
                return ['span', { style: parts.join(';') }, 0];
            },
        },
    },
});

function readStyle(el, prop) {
    if (!(el instanceof HTMLElement)) return '';
    const inline = el.style.getPropertyValue(prop);
    if (inline) return inline.trim();
    const computed = el.ownerDocument && el.ownerDocument.defaultView
        ? el.ownerDocument.defaultView.getComputedStyle(el).getPropertyValue(prop)
        : '';
    return (computed || '').trim();
}

function inheritedStyle(el, prop) {
    let cur = el;
    while (cur && cur.nodeType === Node.ELEMENT_NODE) {
        const v = cur.style && cur.style.getPropertyValue(prop);
        if (v) return v.trim();
        cur = cur.parentElement;
    }
    return '';
}

function buildDocFromSourceHTML(sourceHTML) {
    const tmp = document.createElement('div');
    tmp.innerHTML = sourceHTML || '';
    const lineEls = Array.from(tmp.querySelectorAll(':scope > [data-line-index]'));
    if (!lineEls.length) {
        return schema.nodes.doc.create(null, [schema.nodes.line.create()]);
    }
    const lineNodes = lineEls.map((lineEl, idx) => {
        const contentEl = lineEl.querySelector('[data-line-content="1"]') || lineEl;
        const wrapperStyle = lineEl.style;
        const contentStyle = contentEl.style;
        // Compute inter-line vertical gap from absolute `top` values so
        // we can preserve paragraph spacing after stripping absolute
        // positioning. The first line keeps no top margin; subsequent
        // lines get the delta between this line's top and the previous
        // line's top minus its line-height.
        let marginTop = '';
        if (idx > 0) {
            const prev = lineEls[idx - 1];
            const prevTop = parseFloat(prev.style.top || '0') || 0;
            const prevLh = parseFloat(prev.style.lineHeight || prev.style.minHeight || prev.style.height || '0') || 0;
            const curTop = parseFloat(lineEl.style.top || '0') || 0;
            const gap = curTop - (prevTop + prevLh);
            if (gap > 0.5) marginTop = gap + 'px';
        }
        const attrs = {
            lineIndex: lineEl.getAttribute('data-line-index') || '',
            left: wrapperStyle.left || '',
            top: wrapperStyle.top || '',
            width: wrapperStyle.width || '',
            height: wrapperStyle.minHeight || wrapperStyle.height || '',
            lineHeight: wrapperStyle.lineHeight || '',
            marginTop,
            fontFamily: wrapperStyle.fontFamily || '',
            fontSize: wrapperStyle.fontSize || '',
            fontWeight: wrapperStyle.fontWeight || '400',
            fontStyle: wrapperStyle.fontStyle || 'normal',
            color: wrapperStyle.color || '#000',
            textAlign: wrapperStyle.textAlign || 'left',
            transform: wrapperStyle.transform || '',
            contentTransform: contentStyle.transform || '',
        };
        const inlineNodes = [];
        contentEl.childNodes.forEach((child) => {
            collectInlineNodes(child, inlineNodes, attrs);
        });
        return schema.nodes.line.create(attrs, inlineNodes);
    });
    return schema.nodes.doc.create(null, lineNodes);
}

function collectInlineNodes(node, out, lineAttrs) {
    if (node.nodeType === Node.TEXT_NODE) {
        const text = (node.nodeValue || '').replace(/\u200b/g, '');
        if (!text) return;
        const parent = node.parentElement;
        const mark = schema.marks.pdfRun.create({
            fontFamily: inheritedStyle(parent, 'font-family') || lineAttrs.fontFamily,
            fontSize: inheritedStyle(parent, 'font-size') || lineAttrs.fontSize,
            fontWeight: inheritedStyle(parent, 'font-weight') || lineAttrs.fontWeight,
            fontStyle: inheritedStyle(parent, 'font-style') || lineAttrs.fontStyle,
            color: inheritedStyle(parent, 'color') || lineAttrs.color,
        });
        out.push(schema.text(text, [mark]));
        return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    const el = node;
    const tag = el.tagName.toLowerCase();
    if (tag === 'br') {
        out.push(schema.nodes.hard_break.create());
        return;
    }
    if (el.hasAttribute('data-source-gap')) {
        // Render gaps as plain spaces in the editable representation.
        const w = parseFloat(el.style.width || '0');
        if (w > 0) {
            const fontPx = parseFloat(inheritedStyle(el, 'font-size') || lineAttrs.fontSize || '12') || 12;
            const approxSpaces = Math.max(1, Math.round(w / (fontPx * 0.3)));
            const mark = schema.marks.pdfRun.create({
                fontFamily: inheritedStyle(el, 'font-family') || lineAttrs.fontFamily,
                fontSize: inheritedStyle(el, 'font-size') || lineAttrs.fontSize,
                fontWeight: inheritedStyle(el, 'font-weight') || lineAttrs.fontWeight,
                fontStyle: inheritedStyle(el, 'font-style') || lineAttrs.fontStyle,
                color: inheritedStyle(el, 'color') || lineAttrs.color,
            });
            out.push(schema.text(' '.repeat(approxSpaces), [mark]));
        }
        return;
    }
    el.childNodes.forEach((child) => collectInlineNodes(child, out, lineAttrs));
}

export function mountProseMirrorSourceEditor(host, sourceHTML, opts = {}) {
    if (!(host instanceof HTMLElement)) throw new Error('mountProseMirrorSourceEditor: host must be an HTMLElement');
    host.innerHTML = '';
    host.style.position = 'relative';
    // The debug-modal host element ships with contenteditable="true" so
    // the raw fallback works. With PM mounted, only PM's inner div must
    // be editable -- otherwise clicks register against the outer
    // contenteditable layer, PM never sees the selection update, and
    // Enter splits at doc start instead of the caret.
    host.removeAttribute('contenteditable');
    host.setAttribute('contenteditable', 'false');
    const doc = buildDocFromSourceHTML(sourceHTML);
    const state = EditorState.create({
        doc,
        plugins: [
            history(),
            keymap({
                'Mod-z': undo,
                'Mod-y': redo,
                'Mod-Shift-z': redo,
            }),
            // baseKeymap's Enter is a chain that handles caret/selection
            // correctly: newlineInCode -> createParagraphNear ->
            // liftEmptyBlock -> splitBlock. Using just splitBlock leaves
            // the caret in the wrong place when splitting at the very
            // start/end of a block.
            keymap(baseKeymap),
        ],
    });
    const view = new EditorView(host, {
        state,
        attributes: {
            class: 'pm-source-exact-editor',
            // Neutral host -- per-line and per-mark spans below provide
            // the actual font/size/weight/color from the source.
            style: 'background:#fff;padding:0;min-height:120px;outline:none;color:#000;',
        },
    });
    return {
        view,
        focus() { view.focus(); },
        destroy() { view.destroy(); },
        getLineTexts() {
            const out = [];
            view.state.doc.forEach((lineNode) => {
                if (lineNode.type !== schema.nodes.line) return;
                let buf = '';
                const lineLines = [];
                lineNode.content.forEach((child) => {
                    if (child.type === schema.nodes.hard_break) {
                        lineLines.push(buf);
                        buf = '';
                    } else if (child.isText) {
                        buf += child.text;
                    }
                });
                lineLines.push(buf);
                lineLines.forEach((s) => out.push(String(s).replace(/\u00a0/g, ' ').trim()));
            });
            return out;
        },
        // Per-line list of segments, each carrying the text plus the
        // pdfRun mark attrs (fontFamily/fontSize/fontWeight/fontStyle/
        // color). Save path uses this to update the matching original
        // sourceSpan in place when the segment shape is unchanged,
        // preserving per-span formatting (bold, link colors, etc.).
        // Splits emit a new line entry on hard_break, mirroring
        // getLineTexts.
        getLineSegments() {
            const out = [];
            const flush = (segs) => {
                out.push(segs.map((s) => ({
                    text: String(s.text).replace(/\u00a0/g, ' '),
                    fontFamily: s.attrs.fontFamily || '',
                    fontSize: s.attrs.fontSize || '',
                    fontWeight: s.attrs.fontWeight || '400',
                    fontStyle: s.attrs.fontStyle || 'normal',
                    color: s.attrs.color || '#000',
                })));
            };
            view.state.doc.forEach((lineNode) => {
                if (lineNode.type !== schema.nodes.line) return;
                let segs = [];
                lineNode.content.forEach((child) => {
                    if (child.type === schema.nodes.hard_break) {
                        flush(segs);
                        segs = [];
                    } else if (child.isText) {
                        const mark = (child.marks || []).find((m) => m.type === schema.marks.pdfRun);
                        const attrs = mark ? mark.attrs : {};
                        const last = segs[segs.length - 1];
                        if (last
                            && last.attrs.fontFamily === (attrs.fontFamily || '')
                            && last.attrs.fontSize === (attrs.fontSize || '')
                            && last.attrs.fontWeight === (attrs.fontWeight || '400')
                            && last.attrs.fontStyle === (attrs.fontStyle || 'normal')
                            && last.attrs.color === (attrs.color || '#000')) {
                            last.text += child.text;
                        } else {
                            segs.push({
                                text: child.text,
                                attrs: {
                                    fontFamily: attrs.fontFamily || '',
                                    fontSize: attrs.fontSize || '',
                                    fontWeight: attrs.fontWeight || '400',
                                    fontStyle: attrs.fontStyle || 'normal',
                                    color: attrs.color || '#000',
                                },
                            });
                        }
                    }
                });
                flush(segs);
            });
            return out;
        },
    };
}
