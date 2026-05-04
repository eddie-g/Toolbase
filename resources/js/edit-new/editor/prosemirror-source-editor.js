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
import { baseKeymap, splitBlock } from 'prosemirror-commands';
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
                // Render lines as plain blocks. We deliberately do NOT
                // emit position:absolute / transforms / per-line wrappers
                // here -- the model still carries those attrs for save
                // time, but the editor surface is just text so the user
                // sees and edits actual text, not a stack of overlapping
                // boxes. Inherit font from the host so each line still
                // looks reasonable while editing.
                return [
                    'div',
                    {
                        'data-line-index': String(a.lineIndex || ''),
                        class: 'pm-line',
                        style: 'display:block;margin:0;padding:0;white-space:pre-wrap;',
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
                // Marks carry per-character font/size/weight/style/color
                // for round-trip into applySourceExactTextEdit, but the
                // editor surface itself is plain text -- no per-span
                // styling so the user sees readable, uniform text.
                return ['span', {}, 0];
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
    const lineNodes = lineEls.map((lineEl) => {
        const contentEl = lineEl.querySelector('[data-line-content="1"]') || lineEl;
        const wrapperStyle = lineEl.style;
        const contentStyle = contentEl.style;
        const attrs = {
            lineIndex: lineEl.getAttribute('data-line-index') || '',
            left: wrapperStyle.left || '',
            top: wrapperStyle.top || '',
            width: wrapperStyle.width || '',
            height: wrapperStyle.minHeight || wrapperStyle.height || '',
            lineHeight: wrapperStyle.lineHeight || '',
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
    const doc = buildDocFromSourceHTML(sourceHTML);
    const state = EditorState.create({
        doc,
        plugins: [
            history(),
            keymap({
                'Mod-z': undo,
                'Mod-y': redo,
                'Mod-Shift-z': redo,
                'Enter': splitBlock,
            }),
            keymap(baseKeymap),
        ],
    });
    const view = new EditorView(host, {
        state,
        attributes: {
            class: 'pm-source-exact-editor',
            style: 'font-family:system-ui,Arial,sans-serif;font-size:14px;color:#000;background:#fff;padding:8px;min-height:120px;outline:none;white-space:pre-wrap;line-height:1.4;',
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
    };
}
