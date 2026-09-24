// Editor layout snapshot: what an edited text block shows, word by word,
// so the exporter can draw exactly that instead of re-deriving the layout
// from text + runs + source rows (Asana 1218832511648724).
//
// Each word carries its PDF position (x of its first glyph and its baseline,
// PDF user space like pdfX/pdfY), the width the editor gave it, and its
// drawn style. The guard records the annotation fields the snapshot was
// taken from; the exporter ignores a snapshot whose guard no longer matches.

export const EDITOR_LAYOUT_VERSION = 1;

const INVISIBLE = /[​-‍⁠﻿]/;

function hexColor(value) {
    const match = String(value || '').match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (!match) return String(value || '#000000');
    return `#${match.slice(1, 4).map((part) => Number(part).toString(16).padStart(2, '0')).join('')}`;
}

export function editorLayoutGuardForAnnotation(annotation) {
    const runs = Array.isArray(annotation?.richTextRuns)
        ? annotation.richTextRuns
            .filter((run) => run && run.type !== 'break')
            .map((run) => [
                String(run.text ?? ''),
                String(run.color ?? ''),
                String(run.fontWeight ?? ''),
                String(run.fontStyle ?? ''),
                Number(run.fontSize) || 0,
                String(run.fontFamily ?? ''),
                Boolean(run.underline),
                Boolean(run.strikeout),
            ])
        : [];
    return {
        text: String(annotation?.text ?? ''),
        fontFamily: String(annotation?.fontFamily ?? ''),
        fontSize: Number(annotation?.fontSize) || 0,
        textColor: String(annotation?.textColor ?? ''),
        fontWeight: String(annotation?.fontWeight ?? ''),
        fontStyle: String(annotation?.fontStyle ?? ''),
        runs,
    };
}

/**
 * @param {HTMLElement} box            the committed (not editing) annotation box
 * @param {object} annotation          the annotation just built from the box
 * @param {object} ctx                 { textElement, layerRect, viewport, scale, toPdfPoint }
 *   toPdfPoint(layerX, layerY) -> {x, y} in PDF user space (the editor's own converter)
 */
export function captureEditorLayoutSnapshot(box, annotation, ctx) {
    const tc = ctx?.textElement;
    if (!box || !annotation || !tc || !ctx.layerRect || !(ctx.scale > 0) || typeof ctx.toPdfPoint !== 'function') return null;
    if (box.classList.contains('is-editing')) return null;
    if (Number(ctx.viewport?.rotation || 0) % 360 !== 0) return null;
    if (Number(annotation.rotation || 0) % 360 !== 0) return null;

    const canvas = document.createElement('canvas').getContext('2d');
    const metricsCache = new Map();
    const fontMetrics = (style) => {
        const font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
        if (!metricsCache.has(font)) {
            canvas.font = font;
            const measured = canvas.measureText('Hg');
            metricsCache.set(font, {
                ascent: Number(measured.fontBoundingBoxAscent) || 0,
                descent: Number(measured.fontBoundingBoxDescent) || 0,
            });
        }
        return metricsCache.get(font);
    };
    // A bold/italic PDF face is drawn from its own font file at CSS weight
    // 400; the scaffold run records the source's semantic weight and slant.
    // A weight/slant set inline by the user wins.
    const inlineStyle = (element, prop) => {
        for (let node = element; node && node !== tc.parentElement; node = node.parentElement) {
            if (node.style?.[prop]) return node.style[prop];
            if (node.dataset?.sourceSpanRun === '1') break;
        }
        return '';
    };
    const decorations = (element) => {
        const lines = new Set();
        for (let node = element; node && node !== box; node = node.parentElement) {
            const value = window.getComputedStyle(node).textDecorationLine || '';
            value.split(/\s+/).forEach((part) => part && lines.add(part));
        }
        return lines;
    };
    const styleOf = (element) => {
        const computed = window.getComputedStyle(element);
        const firstFamily = String(computed.fontFamily || '').split(',')[0].trim().replace(/^["']|["']$/g, '');
        const pdfFontName = element.closest?.('[data-source-pdf-font-name]')?.dataset?.sourcePdfFontName || '';
        const semanticWeight = element.closest?.('[data-source-semantic-font-weight]')?.dataset?.sourceSemanticFontWeight || '';
        const semanticStyle = element.closest?.('[data-source-semantic-font-style]')?.dataset?.sourceSemanticFontStyle || '';
        const cssWeight = Number.parseInt(computed.fontWeight, 10) || 400;
        const weight = cssWeight >= 600 || inlineStyle(element, 'fontWeight')
            ? cssWeight
            : Math.max(cssWeight, Number.parseInt(semanticWeight, 10) || 0);
        const italic = /italic|oblique/.test(computed.fontStyle)
            || (!inlineStyle(element, 'fontStyle') && /italic|oblique/.test(semanticStyle));
        const decoration = decorations(element);
        // pdf.js runtime faces (g_d0_f1) are not font names the exporter knows.
        const family = /^g_d\d+_f\d+$/.test(firstFamily) ? '' : firstFamily;
        return {
            computed,
            key: [family, pdfFontName, weight, italic, computed.fontSize, computed.color, decoration.has('underline'), decoration.has('line-through')].join('|'),
            ff: family,
            fs: pdfFontName || family,
            fw: String(weight),
            it: italic,
            c: hexColor(computed.color),
            u: decoration.has('underline'),
            st: decoration.has('line-through'),
        };
    };
    const toPdf = (clientX, clientY) => ctx.toPdfPoint(clientX - ctx.layerRect.left, clientY - ctx.layerRect.top);

    const words = [];
    let current = null;
    let lastRight = null;
    const flush = () => {
        if (current) words.push(current);
        current = null;
    };
    const walker = document.createTreeWalker(tc, NodeFilter.SHOW_TEXT);
    const styleCache = new Map();
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const parent = node.parentElement;
        if (!parent || parent.closest('[data-enpv-caret-marker="1"]')) continue;
        const parentStyle = window.getComputedStyle(parent);
        if (parentStyle.display === 'none' || parentStyle.visibility === 'hidden') continue;
        if (!styleCache.has(parent)) styleCache.set(parent, styleOf(parent));
        const style = styleCache.get(parent);
        const value = node.nodeValue || '';
        for (let index = 0; index < value.length; index += 1) {
            const ch = value[index];
            if (INVISIBLE.test(ch)) continue;
            if (/\s/.test(ch) && ch !== '­') {
                flush();
                continue;
            }
            const range = document.createRange();
            range.setStart(node, index);
            range.setEnd(node, index + 1);
            const rect = Array.from(range.getClientRects()).find((r) => r.width > 0 || r.height > 0);
            if (!rect) continue;
            const metrics = fontMetrics(style.computed);
            const contentHeight = metrics.ascent + metrics.descent;
            // The painted size, whatever transform the display applied.
            const verticalScale = contentHeight > 0 ? rect.height / contentHeight : 1;
            const sizePx = (Number.parseFloat(style.computed.fontSize) || 12) * verticalScale;
            const baselineClientY = rect.top + (metrics.ascent * verticalScale);
            const startsNewPiece = !current
                || current.key !== style.key
                || Math.abs(current.baseClientY - baselineClientY) > sizePx * 0.3
                || (lastRight !== null && rect.left - lastRight > sizePx * 0.25)
                || rect.left < lastRight - sizePx * 0.5;
            if (startsNewPiece) {
                flush();
                const origin = toPdf(rect.left, baselineClientY);
                if (!origin) continue;
                current = {
                    key: style.key,
                    baseClientY: baselineClientY,
                    leftClientX: rect.left,
                    t: '',
                    x: origin.x,
                    b: origin.y,
                    s: sizePx / ctx.scale,
                    ff: style.ff,
                    fs: style.fs,
                    fw: style.fw,
                    it: style.it,
                    c: style.c,
                    u: style.u,
                    st: style.st,
                    rightClientX: rect.right,
                };
            }
            current.t += ch;
            current.rightClientX = Math.max(current.rightClientX, rect.right);
            lastRight = rect.right;
        }
    }
    flush();
    if (!words.length) return null;

    // Rows top to bottom (baseline order), words left to right.
    const rows = [];
    for (const word of words) {
        const right = toPdf(word.rightClientX, word.baseClientY);
        const piece = {
            t: word.t,
            x: Number(word.x.toFixed(3)),
            b: Number(word.b.toFixed(3)),
            w: Number(Math.max(0, (right?.x ?? word.x) - word.x).toFixed(3)),
            s: Number(word.s.toFixed(3)),
            ff: word.ff,
            fs: word.fs,
            fw: word.fw,
            it: word.it,
            c: word.c,
            u: word.u,
            st: word.st,
        };
        let row = rows.find((candidate) => Math.abs(candidate.b - piece.b) <= Math.max(1, piece.s * 0.35));
        if (!row) {
            row = { b: piece.b, words: [] };
            rows.push(row);
        }
        row.words.push(piece);
    }
    // PDF user space: a higher baseline is a larger y.
    rows.sort((a, b) => b.b - a.b);
    rows.forEach((row) => row.words.sort((a, b) => a.x - b.x));

    return {
        v: EDITOR_LAYOUT_VERSION,
        pdfX: Number(annotation.pdfX),
        pdfY: Number(annotation.pdfY),
        guard: editorLayoutGuardForAnnotation(annotation),
        rows,
    };
}
