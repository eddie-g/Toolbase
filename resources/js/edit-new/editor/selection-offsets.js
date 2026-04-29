/*
 * Editor selection-offset helpers (Phase 7al).
 *
 * Pure DOM utilities for translating between contenteditable DOM
 * selection ranges and canonical text offsets. Used to preserve the
 * caret/selection across re-renders of the active editor.
 *
 *   - canonicalTextLength             — visible-character length
 *     (skips \r and zero-width space)
 *   - domTextOffsetForCanonicalOffset — map canonical offset back to
 *     a DOM string offset within a single text node
 *   - childIndex                      — index of `node` in its parent
 *   - editorDomPositionToTextOffset   — DOM (node, offset) → flat
 *     editor text offset, treating <br> and block-tag boundaries as
 *     newlines and collapsing zero-width / NBSP characters to their
 *     visible canonical form
 *   - getEditorSelectionOffsets       — current window selection
 *     mapped to {start, end} editor offsets, or null when there is no
 *     selection inside `editor`
 *   - findEditorTextPosition          — flat editor text offset →
 *     DOM (node, offset) suitable for re-creating a Range
 *   - setEditorSelectionOffsets       — restore a (start, end) pair
 *     into the live window selection and re-focus the editor
 */

export function canonicalTextLength(value) {
    return String(value ?? '')
        .replace(/\r/g, '')
        .replace(/\u200b/g, '')
        .length;
}

export function domTextOffsetForCanonicalOffset(value, canonicalOffset) {
    const text = String(value ?? '');
    let seen = 0;
    const target = Math.max(0, Number(canonicalOffset) || 0);
    for (let i = 0; i < text.length; i++) {
        if (text[i] === '\r' || text[i] === '\u200b') continue;
        if (seen >= target) return i;
        seen++;
        if (seen >= target) return i + 1;
    }
    return text.length;
}

export function childIndex(node) {
    if (!node || !node.parentNode) return 0;
    return Array.prototype.indexOf.call(node.parentNode.childNodes, node);
}

export function editorDomPositionToTextOffset(editor, targetNode, targetOffset) {
    if (!editor || !targetNode) return null;
    const isBlockTag = (tag) => tag === 'DIV' || tag === 'P' || tag === 'LI' || tag === 'TR';
    let offset = 0;
    let lastChar = '';
    let found = false;

    const appendText = (value) => {
        const text = String(value ?? '').replace(/\r/g, '').replace(/\u00a0/g, ' ');
        for (const ch of text) {
            if (ch === '\u200b') continue;
            offset += 1;
            lastChar = ch;
        }
    };
    const appendNewline = () => {
        offset += 1;
        lastChar = '\n';
    };

    const walk = (node) => {
        if (found || !node) return;

        if (node === targetNode) {
            if (node.nodeType === Node.TEXT_NODE) {
                appendText(String(node.nodeValue ?? '').slice(0, Math.max(0, targetOffset)));
            } else if (node.nodeType === Node.ELEMENT_NODE || node === editor) {
                const children = Array.from(node.childNodes || []);
                const limit = Math.max(0, Math.min(Number(targetOffset) || 0, children.length));
                for (let i = 0; i < limit; i++) walk(children[i]);
            }
            found = true;
            return;
        }

        if (node.nodeType === Node.TEXT_NODE) {
            appendText(node.nodeValue);
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (node.dataset && node.dataset.sourceGap === '1' && !node.contains(targetNode)) {
            appendText(' ');
            return;
        }
        if (node.nodeName === 'BR') {
            appendNewline();
            return;
        }

        const before = offset;
        for (const child of Array.from(node.childNodes)) {
            walk(child);
            if (found) return;
        }
        if (isBlockTag(node.nodeName) && offset > before && lastChar !== '\n') appendNewline();
    };

    if (targetNode === editor) {
        walk(editor);
    } else {
        for (const child of Array.from(editor.childNodes)) {
            walk(child);
            if (found) break;
        }
    }

    return found ? offset : null;
}

export function getEditorSelectionOffsets(editor) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !editor) return null;
    const range = selection.getRangeAt(0);
    if (!editor.contains(range.startContainer) || !editor.contains(range.endContainer)) return null;

    const start = editorDomPositionToTextOffset(editor, range.startContainer, range.startOffset);
    const end = editorDomPositionToTextOffset(editor, range.endContainer, range.endOffset);
    if (start === null || end === null) return null;

    return { start, end };
}

export function findEditorTextPosition(editor, targetOffset) {
    const isBlockTag = (tag) => tag === 'DIV' || tag === 'P' || tag === 'LI' || tag === 'TR';
    let remaining = Math.max(0, Number(targetOffset) || 0);
    let lastPos = { node: editor, offset: 0 };
    let lastChar = '';

    const walk = (node) => {
        if (!node) return null;

        if (node.nodeType === Node.TEXT_NODE) {
            const len = canonicalTextLength(node.nodeValue);
            if (remaining <= len) {
                return {
                    node,
                    offset: domTextOffsetForCanonicalOffset(node.nodeValue, remaining),
                };
            }
            remaining -= len;
            lastPos = { node, offset: String(node.nodeValue ?? '').length };
            const visible = String(node.nodeValue ?? '').replace(/\r/g, '').replace(/\u200b/g, '');
            if (visible) lastChar = visible[visible.length - 1];
            return null;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) return null;
        const tag = node.nodeName;

        if (node.dataset && node.dataset.sourceGap === '1') {
            const idx = childIndex(node);
            if (remaining <= 0) return { node: node.parentNode, offset: idx };
            if (remaining <= 1) return { node: node.parentNode, offset: idx + 1 };
            remaining -= 1;
            lastPos = { node: node.parentNode, offset: idx + 1 };
            lastChar = ' ';
            return null;
        }

        if (tag === 'BR') {
            const idx = childIndex(node);
            if (remaining <= 0) return { node: node.parentNode, offset: idx };
            if (remaining <= 1) return { node: node.parentNode, offset: idx + 1 };
            remaining -= 1;
            lastPos = { node: node.parentNode, offset: idx + 1 };
            lastChar = '\n';
            return null;
        }

        const before = remaining;
        const children = Array.from(node.childNodes);
        for (const child of children) {
            const found = walk(child);
            if (found) return found;
        }

        if (isBlockTag(tag) && remaining < before && lastChar !== '\n') {
            const idx = childIndex(node);
            if (remaining <= 0) return { node, offset: node.childNodes.length };
            if (remaining <= 1 && node.parentNode) return { node: node.parentNode, offset: idx + 1 };
            remaining -= 1;
            lastPos = node.parentNode
                ? { node: node.parentNode, offset: idx + 1 }
                : { node, offset: node.childNodes.length };
            lastChar = '\n';
        }

        return null;
    };

    for (const child of Array.from(editor.childNodes)) {
        const found = walk(child);
        if (found) return found;
    }

    return lastPos;
}

export function setEditorSelectionOffsets(editor, start, end = start) {
    if (!editor) return;

    const startPos = findEditorTextPosition(editor, start);
    const endPos = findEditorTextPosition(editor, end);
    const selection = window.getSelection();
    if (!selection || !startPos || !endPos) return;

    const range = document.createRange();
    range.setStart(startPos.node, startPos.offset);
    range.setEnd(endPos.node, endPos.offset);
    selection.removeAllRanges();
    selection.addRange(range);
    editor.focus({ preventScroll: true });
}
