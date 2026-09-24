/**
 * Geometry + text measurement (runs in the page through page.evaluate) and
 * word alignment. Adapted from nk8131 agent-3 measure.cjs: per-word client
 * rects come from a Range over every character of the box's contenteditable
 * (mode 'box') or of the pdf.js text layer inside the box's area (mode 'pdf').
 * All coordinates returned are CSS px relative to the page div.
 */

/** In-page: per-word rects. arg = { sel, mode: 'box'|'pdf', rect?: {x,y,w,h} page-relative px for mode 'pdf' }. */
function pageWords({ sel, mode, rect }) {
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
                chars.push({ ch, node: nodeIdx, l: rc.left, t: rc.top, r: rc.right, b: rc.bottom });
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
        if (!cur) cur = { t: '', l: c.l, top: c.t, r: c.r, bot: c.b, lastR: c.r, lastT: c.t, lastB: c.b };
        cur.t += c.ch; cur.l = Math.min(cur.l, c.l); cur.r = Math.max(cur.r, c.r); cur.top = Math.min(cur.top, c.t); cur.bot = Math.max(cur.bot, c.b);
        cur.lastR = c.r; cur.lastT = c.t; cur.lastB = c.b;
    }
    flush();
    const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 1;
    return {
        scale,
        box: { x: +(br.left - pr.left).toFixed(2), y: +(br.top - pr.top).toFixed(2), w: +br.width.toFixed(2), h: +br.height.toFixed(2) },
        page: { x: pr.left, y: pr.top, w: pr.width, h: pr.height },
        words: words.map((w) => ({ t: w.t, x: +(w.l - pr.left).toFixed(2), r: +(w.r - pr.left).toFixed(2), y: +(w.top - pr.top).toFixed(2), bot: +(w.bot - pr.top).toFixed(2) })),
    };
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

const norm = (s) => s.normalize('NFKC').replace(/[­-]+$/g, '-').replace(/[­]/g, '').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').toLowerCase();

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
        const dy = (q.y + q.bot) / 2 - (p.y + p.bot) / 2;
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

module.exports = { pageWords, textState, keyPlan, targetPoints, align, assignRows, compare, norm };
