// ProseMirror spike — minimal alternate code path for editing one text annotation.
//
// Public API:
//   const handle = mountPmEditor(wrapperEl, annotation);
//   handle.getResult(); // → { text, richHtml }
//   handle.destroy();
//
// The spike is intentionally minimal: paragraph + text + hard_break, with
// `bold` and `italic` marks. It exists to validate that editing-via-PM keeps
// the wrapper's absolute position / font metrics rock-steady (no baseline
// jumps), without touching save/load.
//
// Feature-flagged on window.__pmSpike — see eh.click handler in main.js.

import { Schema, DOMParser, DOMSerializer } from 'prosemirror-model';
import { EditorState } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { history, undo, redo } from 'prosemirror-history';
import { keymap } from 'prosemirror-keymap';
import { baseKeymap, toggleMark } from 'prosemirror-commands';

const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: {
            group: 'block',
            content: 'inline*',
            parseDOM: [{ tag: 'p' }, { tag: 'div' }],
            toDOM: () => ['p', { style: 'margin:0;padding:0' }, 0],
        },
        text: { group: 'inline' },
        hard_break: {
            inline: true,
            group: 'inline',
            selectable: false,
            parseDOM: [{ tag: 'br' }],
            toDOM: () => ['br'],
        },
    },
    marks: {
        bold: {
            parseDOM: [
                { tag: 'strong' },
                { tag: 'b', getAttrs: (n) => n.style.fontWeight !== 'normal' && null },
                { style: 'font-weight', getAttrs: (v) => /^(bold(er)?|[5-9]\d{2})$/.test(v) && null },
            ],
            toDOM: () => ['strong', 0],
        },
        italic: {
            parseDOM: [
                { tag: 'em' },
                { tag: 'i' },
                { style: 'font-style=italic' },
            ],
            toDOM: () => ['em', 0],
        },
    },
});

function buildInitialDoc(annotation) {
    // Prefer _richHtml if present; otherwise fall back to plain text with
    // newlines split into paragraphs.
    const html = typeof annotation?._richHtml === 'string' ? annotation._richHtml.trim() : '';
    const parser = DOMParser.fromSchema(schema);
    if (html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        try {
            return parser.parse(tmp);
        } catch (_e) { /* fall through to plain text */ }
    }
    const text = String(annotation?.text ?? '');
    if (!text) return schema.node('doc', null, [schema.node('paragraph')]);
    const tmp = document.createElement('div');
    text.split(/\r?\n/).forEach((line) => {
        const p = document.createElement('p');
        p.textContent = line;
        tmp.appendChild(p);
    });
    return parser.parse(tmp);
}

function serializeToHtml(doc) {
    const serializer = DOMSerializer.fromSchema(schema);
    const fragment = serializer.serializeFragment(doc.content);
    const tmp = document.createElement('div');
    tmp.appendChild(fragment);
    return tmp.innerHTML;
}

function serializeToPlainText(doc) {
    // Use blockSeparator='\n' so paragraph boundaries become newlines.
    return doc.textBetween(0, doc.content.size, '\n', '\n');
}

/**
 * Mount a ProseMirror editor inside `wrapperEl`, seeded from `annotation`.
 * Returns a handle with `destroy()` and `getResult()`.
 *
 * The wrapper element keeps its position / font / leading styles. PM only
 * patches text inside its own contentDOM, so absolute layout is preserved
 * across edits — no baseline jumps.
 */
export function mountPmEditor(wrapperEl, annotation, opts = {}) {
    if (!(wrapperEl instanceof HTMLElement)) {
        throw new Error('mountPmEditor: wrapperEl must be an HTMLElement');
    }
    // Stash original content/contentEditable so we can restore on destroy.
    const prevHtml = wrapperEl.innerHTML;
    const prevContentEditable = wrapperEl.getAttribute('contenteditable');
    // PM owns its own contentEditable surface; the wrapper itself should not be.
    wrapperEl.setAttribute('contenteditable', 'false');
    wrapperEl.innerHTML = '';

    const host = document.createElement('div');
    host.className = 'pm-spike-host';
    // Make the PM surface fill the wrapper. Color is INHERITED so the
    // .active-editor[data-canvas-owns="1"] transparent-glyph rule continues
    // to apply to PM's contentDOM — the canvas paints the visible text and
    // PM only provides the caret/selection model. Anything else would cause
    // PM glyphs to overlap the canvas-painted glyphs.
    host.style.cssText = [
        'all:unset',
        'display:block',
        'width:100%',
        'height:100%',
        'font:inherit',
        'color:inherit',
        'line-height:inherit',
        'text-align:inherit',
        'white-space:pre-wrap',
        'outline:none',
        'caret-color:#2563eb',
        // Opaque background so any underlying canvas/rich-html paint of the
        // original annotation can't bleed through under PM's edited text.
        'background:#ffffff',
    ].join(';');
    wrapperEl.appendChild(host);

    const state = EditorState.create({
        doc: buildInitialDoc(annotation),
        plugins: [
            history(),
            keymap({
                'Mod-z': undo,
                'Mod-y': redo,
                'Mod-Shift-z': redo,
                'Mod-b': toggleMark(schema.marks.bold),
                'Mod-i': toggleMark(schema.marks.italic),
            }),
            keymap(baseKeymap),
        ],
    });

    const view = new EditorView(host, {
        state,
        attributes: {
            // Reset PM's outline/padding so it lays out flush against the
            // wrapper and inherits typography. Color INHERITS so the
            // canvas-owns transparency rule keeps PM's glyphs hidden — the
            // canvas is the visible renderer.
            style: 'outline:none;padding:0;margin:0;min-height:1em;font:inherit;color:inherit;line-height:inherit;text-align:inherit;white-space:pre-wrap;',
            spellcheck: 'false',
        },
        dispatchTransaction(tr) {
            const next = view.state.apply(tr);
            view.updateState(next);
            if (tr.docChanged && typeof opts.onChange === 'function') {
                try { opts.onChange(serializeToPlainText(next.doc), serializeToHtml(next.doc)); } catch (_e) {}
            }
        },
    });

    let destroyed = false;

    return {
        get view() { return view; },
        getResult() {
            const doc = view.state.doc;
            return {
                text: serializeToPlainText(doc),
                richHtml: serializeToHtml(doc),
            };
        },
        focus(opts) {
            try { view.focus(); } catch (_e) {}
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            try { view.destroy(); } catch (_e) {}
            // Restore wrapper to its prior state. Caller is responsible for
            // any re-render via syncActiveEditor / redrawOverlay.
            wrapperEl.innerHTML = prevHtml;
            if (prevContentEditable !== null) {
                wrapperEl.setAttribute('contenteditable', prevContentEditable);
            } else {
                wrapperEl.removeAttribute('contenteditable');
            }
        },
    };
}

export default mountPmEditor;
