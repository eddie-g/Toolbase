/**
 * Geometry + text measurement (runs in the page through page.evaluate) and
 * word alignment. Adapted from nk8131 agent-3 measure.cjs: per-word client
 * rects come from a Range over every character of the box's contenteditable
 * (mode 'box') or of the pdf.js text layer inside the box's area (mode 'pdf').
 * All coordinates returned are CSS px relative to the page div.
 */

/** In-page: per-word rects. arg = { sel, mode: 'box'|'pdf', rect?: {x,y,w,h} page-relative px for mode 'pdf' }. */
function pageWords({ sel, mode, rect }) {
    // Chrome places an inline text box's top at baseline - font ascent; the
    // canvas reports that ascent for the resolved font (cached per font).
    const ascentCache = new Map();
    const ctx2d = document.createElement('canvas').getContext('2d');
    const fontAscentPx = (st) => {
        if (!st || !ctx2d) return NaN;
        const font = `${st.fontStyle} ${st.fontWeight} ${st.fontSize} ${st.fontFamily}`;
        if (!ascentCache.has(font)) {
            ctx2d.font = font;
            const m = ctx2d.measureText('Hg');
            ascentCache.set(font, Number.isFinite(m.fontBoundingBoxAscent) ? m.fontBoundingBoxAscent : NaN);
        }
        return ascentCache.get(font);
    };
    const box = document.querySelector(sel);
    if (!box) return { error: 'no box' };
    const pageDiv = box.closest('.page');
    const pr = pageDiv.getBoundingClientRect();
    const br = box.getBoundingClientRect();
    const area = rect ? { left: pr.left + rect.x, top: pr.top + rect.y, right: pr.left + rect.x + rect.w, bottom: pr.top + rect.y + rect.h }
        : { left: br.left, top: br.top, right: br.right, bottom: br.bottom };
    let roots;
    if (mode === 'pdf') {
        const tl = pageDiv.querySelector(':scope > .textLayer') || pageDiv.querySelector('.textLayer');
        roots = tl ? [tl] : [];
    } else {
        const c = box.querySelector('.enpv-text-content');
        roots = c ? [c] : [];
    }
    const chars = [];
    for (const root of roots) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let nodeIdx = 0;
        while (walker.nextNode()) {
            const n = walker.currentNode; const v = n.nodeValue || '';
            nodeIdx += 1;
            if (!v) continue;
            const parent = n.parentElement;
            const st = parent ? getComputedStyle(parent) : null;
            if (st && (st.display === 'none' || st.visibility === 'hidden')) continue;
            for (let i = 0; i < v.length; i += 1) {
                const ch = v[i];
                if (/[​-‍⁠﻿­]/.test(ch)) continue;
                if (/[\s ]/.test(ch)) { chars.push({ ch: ' ', node: nodeIdx }); continue; }
                const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + 1);
                const rc = Array.from(r.getClientRects()).find((x) => x.width > 0 || x.height > 0);
                if (!rc) continue;
                if (mode === 'pdf') {
                    const cx = (rc.left + rc.right) / 2; const cy = (rc.top + rc.bottom) / 2;
                    if (cx < area.left - 3 || cx > area.right + 3 || cy < area.top - 3 || cy > area.bottom + 3) continue;
                }
                // Baseline = content-area top + the font's ascent (what Chrome
                // uses for an inline text box), so it compares with the PDF's
                // glyph origin whatever font the editor resolved.
                chars.push({ ch, node: nodeIdx, l: rc.left, t: rc.top, r: rc.right, b: rc.bottom, base: rc.top + fontAscentPx(st) });
            }
            chars.push({ ch: ' ', node: nodeIdx, soft: true });
        }
    }
    const words = []; let cur = null;
    const flush = () => { if (cur) { words.push(cur); cur = null; } };
    let softBreak = false;
    for (const c of chars) {
        if (c.ch === ' ') { if (c.soft) softBreak = true; else flush(); continue; }
        if (cur) {
            const h = Math.max(1, c.b - c.t, cur.lastB - cur.lastT);
            const cy = (c.t + c.b) / 2; const py = (cur.lastT + cur.lastB) / 2;
            const lineChange = Math.abs(cy - py) > 0.6 * Math.min(c.b - c.t, cur.lastB - cur.lastT) || c.l < cur.lastR - h * 0.6;
            const gap = c.l - cur.lastR;
            if (lineChange || (softBreak && gap > 0.18 * h)) flush();
        }
        softBreak = false;
        if (!cur) cur = { t: '', l: c.l, top: c.t, r: c.r, bot: c.b, base: c.base, lastR: c.r, lastT: c.t, lastB: c.b };
        cur.t += c.ch; cur.l = Math.min(cur.l, c.l); cur.r = Math.max(cur.r, c.r); cur.top = Math.min(cur.top, c.t); cur.bot = Math.max(cur.bot, c.b);
        cur.lastR = c.r; cur.lastT = c.t; cur.lastB = c.b;
    }
    flush();
    const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 1;
    return {
        scale,
        box: { x: +(br.left - pr.left).toFixed(2), y: +(br.top - pr.top).toFixed(2), w: +br.width.toFixed(2), h: +br.height.toFixed(2) },
        page: { x: pr.left, y: pr.top, w: pr.width, h: pr.height },
        words: words.map((w) => ({ t: w.t, x: +(w.l - pr.left).toFixed(2), r: +(w.r - pr.left).toFixed(2), y: +(w.top - pr.top).toFixed(2), bot: +(w.bot - pr.top).toFixed(2), base: Number.isFinite(w.base) ? +(w.base - pr.top).toFixed(2) : null })),
    };
}

/** In-page: the box's visible words with their computed style, in PDF pt
 *  (page-relative): colour, bold, italic, size and baseline. */
function styledWords({ sel }) {
    const box = document.querySelector(sel);
    if (!box) return { error: 'no box' };
    const pageDiv = box.closest('.page');
    const pr = pageDiv.getBoundingClientRect();
    const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 1;
    const tc = box.querySelector('.enpv-text-content') || box;
    const ctx2d = document.createElement('canvas').getContext('2d');
    const ascentCache = new Map();
    const ascent = (st) => {
        const font = `${st.fontStyle} ${st.fontWeight} ${st.fontSize} ${st.fontFamily}`;
        if (!ascentCache.has(font)) { ctx2d.font = font; ascentCache.set(font, ctx2d.measureText('Hg').fontBoundingBoxAscent); }
        return ascentCache.get(font);
    };
    const hex = (c) => {
        const m = String(c || '').match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
        return m ? '#' + m.slice(1, 4).map((v) => Number(v).toString(16).padStart(2, '0')).join('') : String(c || '');
    };
    // A bold/italic PDF face is often drawn from its own font file at CSS
    // weight 400: the scaffold run records the source's semantic weight and
    // slant. An inline weight/style the user set wins over it.
    const explicit = (el, prop) => {
        for (let n = el; n && n !== tc.parentElement; n = n.parentElement) {
            if (n.style && n.style[prop]) return n.style[prop];
            if (n.dataset && (n.dataset.sourceSpanRun === '1' || n.dataset.sourceSemanticFontWeight)) break;
        }
        return '';
    };
    const semantic = (el, key) => el.closest?.(`[data-source-semantic-font-${key}]`)?.dataset?.[key === 'weight' ? 'sourceSemanticFontWeight' : 'sourceSemanticFontStyle'] || '';
    // A bold/italic face (Helvetica-Bold) draws bold/italic whatever the CSS says.
    const visualBold = (el, st) => {
        if (Number.parseInt(st.fontWeight, 10) >= 600) return true;
        if (/bold|black|heavy|semibold|demi/i.test(st.fontFamily.split(',')[0])) return true;
        if (explicit(el, 'fontWeight')) return false;
        return Number.parseInt(semantic(el, 'weight'), 10) >= 600;
    };
    const visualItalic = (el, st) => {
        if (/italic|oblique/.test(st.fontStyle)) return true;
        if (/italic|oblique/i.test(st.fontFamily.split(',')[0])) return true;
        if (explicit(el, 'fontStyle')) return false;
        return /italic|oblique/.test(semantic(el, 'style'));
    };
    const words = []; let cur = null;
    const flush = () => { if (cur) { words.push(cur); cur = null; } };
    const walker = document.createTreeWalker(tc, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const n = walker.currentNode; const v = n.nodeValue || '';
        const parent = n.parentElement;
        const st = parent ? getComputedStyle(parent) : null;
        if (!st || st.display === 'none' || st.visibility === 'hidden') continue;
        for (let i = 0; i < v.length; i += 1) {
            const ch = v[i];
            if (/[\u00ad\u200b-\u200d\u2060\ufeff]/.test(ch)) continue;
            if (/\s/.test(ch)) { flush(); continue; }
            const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + 1);
            const rc = Array.from(r.getClientRects()).find((x) => x.width > 0 || x.height > 0);
            if (!rc) continue;
            // A word the editor wraps (at a hyphen, no space) continues on
            // the next row: that is a new word, as it is in the PDF.
            const charBase = (rc.top + ascent(st) - pr.top) / scale;
            if (cur && Math.abs(charBase - cur.base) > (Number.parseFloat(st.fontSize) / scale) * 0.5) flush();
            if (!cur) {
                cur = {
                    t: '', x: (rc.left - pr.left) / scale, base: charBase,
                    size: Number.parseFloat(st.fontSize) / scale, color: hex(st.color),
                    bold: visualBold(parent, st), italic: visualItalic(parent, st),
                };
            }
            cur.t += ch;
        }
    }
    flush();
    return { scale, words };
}

/** In-page: text + caret state of the box's contenteditable. */
function textState({ sel }) {
    const strip = (s) => String(s || '').replace(/[\s ­​-‍⁠﻿]+/g, '');
    const box = document.querySelector(sel);
    const root = box?.querySelector('.enpv-text-content');
    if (!root) return { error: 'no content' };
    const raw = root.textContent || '';
    const out = { raw, nonws: strip(raw), text: raw.replace(/[­​-‍⁠﻿]/g, '').replace(/[\s ]+/g, ' ').trim(), editing: box.classList.contains('is-editing') };
    const s = getSelection();
    if (s.rangeCount && root.contains(s.getRangeAt(0).startContainer)) {
        const r = s.getRangeAt(0);
        const pre = document.createRange(); pre.setStart(root, 0); pre.setEnd(r.startContainer, r.startOffset);
        out.caret = { a: strip(pre.toString()).length, sel: strip(r.toString()), collapsed: r.collapsed };
        const rects = Array.from(r.getClientRects());
        let cr = rects[0];
        if (!cr) {
            const probe = r.cloneRange(); probe.collapse(true);
            cr = probe.getBoundingClientRect();
        }
        const pr = box.closest('.page').getBoundingClientRect();
        if (cr) out.caret.rect = { x: +(cr.left - pr.left).toFixed(2), y: +(cr.top - pr.top).toFixed(2), h: +cr.height.toFixed(2) };
    }
    return out;
}

/**
 * In-page: what a key is expected to do to the non-whitespace text.
 * Uses Selection.modify to learn which character Backspace/Delete removes,
 * then restores the selection exactly.
 */
function keyPlan({ sel, key }) {
    const strip = (s) => String(s || '').replace(/[\s ­​-‍⁠﻿]+/g, '');
    const box = document.querySelector(sel);
    const root = box?.querySelector('.enpv-text-content');
    const s = getSelection();
    if (!root || !s.rangeCount || !root.contains(s.getRangeAt(0).startContainer)) return { ok: false, reason: 'no caret in box' };
    const saved = s.getRangeAt(0).cloneRange();
    const nonws = strip(root.textContent);
    const pre = document.createRange(); pre.setStart(root, 0); pre.setEnd(saved.startContainer, saved.startOffset);
    let a = strip(pre.toString()).length;
    let removed = strip(saved.toString());
    let b = a + removed.length;
    if (saved.collapsed && (key === 'Backspace' || key === 'Delete')) {
        try {
            s.modify('extend', key === 'Backspace' ? 'backward' : 'forward', 'character');
            const ext = s.getRangeAt(0);
            removed = strip(ext.toString());
        } catch (_) { removed = ''; }
        s.removeAllRanges(); s.addRange(saved);
        if (key === 'Backspace') { b = a; a = Math.max(0, a - removed.length); } else { b = a + removed.length; }
    }
    return { ok: true, nonws, a, b, removed, collapsed: saved.collapsed };
}

/** In-page: point targets inside the box, computed from the live text (for real mouse input). */
function targetPoints({ sel }) {
    const box = document.querySelector(sel);
    const root = box?.querySelector('.enpv-text-content');
    if (!root) return null;
    const br = box.getBoundingClientRect();
    const words = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const n = walker.currentNode; const v = n.nodeValue || '';
        const re = /[^\s ​­]+/g; let m;
        while ((m = re.exec(v))) {
            const r = document.createRange(); r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length);
            const rc = Array.from(r.getClientRects()).find((x) => x.width > 0);
            if (!rc) continue;
            words.push({ t: m[0], l: rc.left, r: rc.right, t0: rc.top, b: rc.bottom });
        }
    }
    words.sort((p, q) => (Math.abs(p.t0 - q.t0) > 2 ? p.t0 - q.t0 : p.l - q.l));
    const rows = [];
    for (const w of words) {
        const row = rows.find((rr) => Math.abs((rr.top + rr.bot) / 2 - (w.t0 + w.b) / 2) < 0.5 * (w.b - w.t0));
        if (row) { row.words.push(w); row.top = Math.min(row.top, w.t0); row.bot = Math.max(row.bot, w.b); } else rows.push({ top: w.t0, bot: w.b, words: [w] });
    }
    rows.forEach((rr) => rr.words.sort((p, q) => p.l - q.l));
    return { box: { x: br.left, y: br.top, w: br.width, h: br.height }, rows: rows.map((rr) => ({ cy: (rr.top + rr.bot) / 2, words: rr.words.map((w) => ({ t: w.t, l: w.l, r: w.r, cy: (w.t0 + w.b) / 2 })) })) };
}

// Hyphen variants (U+00AD soft hyphen read back from a substitute font's
// hyphen glyph, U+2010/2011) and plain '-' pair as the same word.
const norm = (s) => s.normalize('NFKC').replace(/[\u00ad\u2010\u2011-]/g, '').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').toLowerCase();

/** LCS-align two word lists by text. */
function align(a, b) {
    const n = a.length; const m = b.length;
    if (n * m > 4e6) return [];
    const A = a.map((w) => norm(w.t)); const B = b.map((w) => norm(w.t));
    const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
    for (let i = n - 1; i >= 0; i -= 1) for (let j = m - 1; j >= 0; j -= 1) dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    const pairs = []; let i = 0; let j = 0;
    while (i < n && j < m) {
        if (A[i] === B[j]) { pairs.push([a[i], b[j]]); i += 1; j += 1; } else if (dp[i + 1][j] >= dp[i][j + 1]) i += 1; else j += 1;
    }
    return pairs;
}

/** Cluster words into rows by vertical centre; returns row index per word (mutates w.row). */
function assignRows(words) {
    const sorted = [...words].sort((p, q) => (p.y + p.bot) / 2 - (q.y + q.bot) / 2);
    const rows = [];
    for (const w of sorted) {
        const cy = (w.y + w.bot) / 2; const h = Math.max(1, w.bot - w.y);
        let row = rows.find((r) => Math.abs(r.cy - cy) < 0.45 * Math.max(h, r.h));
        if (!row) { row = { cy, h, words: [] }; rows.push(row); }
        row.words.push(w);
    }
    rows.sort((p, q) => p.cy - q.cy);
    rows.forEach((r, i) => r.words.forEach((w) => { w.row = i; }));
    return rows;
}

/** Compare two word sets; returns stats + per-word deltas (px and pt). */
function compare(ref, got, scale, thresholdPx) {
    const pairs = align(ref, got);
    let maxDx = 0; let maxDy = 0; let maxDw = 0; let moved = 0;
    const detail = [];
    for (const [p, q] of pairs) {
        const dx = q.x - p.x;
        const dy = Number.isFinite(p.base) && Number.isFinite(q.base)
            ? q.base - p.base
            : (q.y + q.bot) / 2 - (p.y + p.bot) / 2;
        const dw = (q.r - q.x) - (p.r - p.x);
        if (Math.abs(dx) > Math.abs(maxDx)) maxDx = dx;
        if (Math.abs(dy) > Math.abs(maxDy)) maxDy = dy;
        if (Math.abs(dw) > Math.abs(maxDw)) maxDw = dw;
        const mv = Math.abs(dx) > thresholdPx || Math.abs(dy) > thresholdPx;
        if (mv) moved += 1;
        detail.push({ t: p.t, row: p.row, x: p.x, y: +((p.y + p.bot) / 2).toFixed(2), dx: +dx.toFixed(2), dy: +dy.toFixed(2), dw: +dw.toFixed(2), moved: mv });
    }
    return {
        refWords: ref.length, gotWords: got.length, matched: pairs.length, moved,
        maxDx: +maxDx.toFixed(2), maxDy: +maxDy.toFixed(2), maxDw: +maxDw.toFixed(2),
        maxDxPt: +(maxDx / scale).toFixed(2), maxDyPt: +(maxDy / scale).toFixed(2),
        detail,
    };
}

module.exports = { styledWords, pageWords, textState, keyPlan, targetPoints, align, assignRows, compare, norm };
