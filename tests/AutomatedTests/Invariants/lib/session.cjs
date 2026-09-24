/**
 * One randomized session: fresh upload of one PDF, one block, one action
 * sequence executed with real mouse/keyboard input, invariants checked after
 * every step. Used by the runner, the shrinker and the generated repro scripts.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const H = require('./harness.cjs');
const M = require('./measure.cjs');
const F = require('./fitz.cjs');
const S = require('./signature.cjs');
const { generate, blockChoice } = require('./generator.cjs');

/**
 * Tolerances. *Px100 values are CSS px at 100% zoom and are multiplied by
 * the viewer's currentScale; *Pt values are PDF points.
 */
const TOL = {
    wordPx100: 0.5,            // any word movement (I1 close/caret, I3, I8)
    pixelFloorPx: 1.05,        // never flag less than one CSS px: Chromium snaps text baselines to whole px at deviceScaleFactor 1
    pdfIntraRowDxPx100: 2.0,   // I1 pristine open vs PDF: per-word x drift inside a row relative to the row's first word (browser font advances vs embedded font)
    pdfRowOriginPx100: 0.5,    // I1 pristine open vs PDF: first word of every row, x and y
    boxPt: 0.5,                // box geometry (I1, I3 height, I4, I8)
    fontPx: 0.1,               // I5 font size
    boxHeightPt: 0.5,          // I5 box height
    exportPosPt: 0.5,          // I7 untouched row bbox in the download
    styleColour: 48,           // I7 style: max per-channel colour difference (0-255)
    styleSizePt: 0.6,          // I7 style: font size difference
    styleRowPt: 2.5,           // I7 style: row placement (floor; also 45% of the font size)
    exportRowOriginPt: 0.5,    // I7 every row of the edited block: first-glyph x, baseline, and x of the words before the edit point
    inkPixels: 6,              // I6 leftover pixels at the old place
    renderedDarkPixels: 12,    // I6 the moved text renders (dark pixels at the new place)
    textMatchRatio: 0.8,       // I1 at least this share of reference words must be found again
};

const ALL_INVARIANTS = ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8'];
const PRINTABLE = /^.$/u;
const NAV_KEYS = new Set(['Home', 'End', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown']);

function sha1(file) { return crypto.createHash('sha1').update(fs.readFileSync(file)).digest('hex').slice(0, 12); }
function r2(v) { return Math.round(v * 100) / 100; }

class Session {
    constructor(opts) {
        this.opts = opts;
        this.pdf = path.resolve(opts.pdf);
        this.seed = Number(opts.seed ?? 1);
        this.inv = new Set(opts.invariants && opts.invariants.length ? opts.invariants : ALL_INVARIANTS);
        this.outDir = opts.outDir;
        this.baselineDir = opts.baselineDir || path.join(opts.outDir, '..', 'baselines');
        this.keepGoing = !!opts.keepGoing;
        this.violations = [];
        this.notes = [];
        this.checks = {};
        this.shotBuf = null;
        fs.mkdirSync(this.outDir, { recursive: true });
    }

    log(...a) { if (this.opts.verbose) console.error(`[${path.basename(this.pdf)} s${this.seed}]`, ...a); }
    count(inv) { this.checks[inv] = (this.checks[inv] || 0) + 1; }
    tolPx() { return Math.max(TOL.wordPx100 * this.zoom, TOL.pixelFloorPx); }

    // ------------------------------------------------------------------ setup
    async start() {
        const { browser, context, page } = await H.launch();
        Object.assign(this, { browser, context, page });
        this.bundleStart = H.bundleName();
        const t0 = Date.now();
        this.docId = await H.upload(page, this.pdf, `s${this.seed}`);
        const n = await H.openEditorWithBoxes(page, this.docId, 75000);
        if (!n) throw new Error('SKIP: no editable text boxes after extraction (75 s)');
        this.zoom = await page.evaluate(() => window.__enpv.pdfViewer.currentScale);
        this.timings = { open: Date.now() - t0 };
        await this.chooseBlock();
        this.snapNeighbours = await this.neighbourSnapshot();
    }

    async chooseBlock() {
        const page = this.page;
        const pages = await H.pageCount(page);
        const ref = this.opts.block || null;
        const choice = blockChoice(this.seed);
        let pageNo = ref?.page || null;
        if (!pageNo) {
            const start = Math.floor(choice.pageFrac * pages);
            for (let k = 0; k < pages; k += 1) {
                const p = ((start + k) % pages) + 1;
                if (F.pageWords(this.pdf, p).words.length >= 3) { pageNo = p; break; }
            }
        }
        if (!pageNo) throw new Error('no page with text');
        await H.scrollToPage(page, pageNo);
        let list = [];
        for (let k = 0; k < 10; k += 1) {
            list = (await H.boxes(page)).filter((b) => b.page === pageNo && b.visible && b.text.replace(/\s/g, '').length >= 3 && !/field|shape|image/.test(b.type));
            if (list.length) break;
            await H.sleep(800);
        }
        if (!list.length) throw new Error(`no text boxes on page ${pageNo}`);
        list.sort((a, b) => (a.pt.y - b.pt.y) || (a.pt.x - b.pt.x));
        let box = null;
        if (ref) {
            const norm = (s) => s.replace(/\s+/g, '');
            box = (ref.id && list.find((b) => b.id === ref.id))
                || list.find((b) => b.promoted === ref.promoted && norm(b.text).startsWith(norm(ref.textPrefix)))
                || list.find((b) => norm(b.text).startsWith(norm(ref.textPrefix))) || list[ref.index] || null;
            if (!box) throw new Error(`block ${JSON.stringify(ref)} not found`);
        } else {
            const promoted = list.filter((b) => b.promoted);
            const pool = choice.preferPromoted && promoted.length ? promoted : list;
            box = pool[Math.floor(choice.boxFrac * pool.length)];
        }
        this.pageNo = pageNo;
        this.id = box.id;
        this.scale = box.scale;
        this.origRect = { ...box.pt };
        this.origText = box.text;
        this.blockRef = { page: pageNo, index: list.indexOf(box), textPrefix: box.text.slice(0, 40), promoted: box.promoted, ...(box.promoted ? { id: box.id } : {}) };
        const pw = F.pageWords(this.pdf, pageNo);
        const pad = 1.5;
        this.pdfWords = pw.words.filter((w) => {
            const cx = (w.x + w.r) / 2; const cy = (w.y + w.bot) / 2;
            return cx >= box.pt.x - pad && cx <= box.pt.x + box.pt.w + pad && cy >= box.pt.y - pad && cy <= box.pt.y + box.pt.h + pad;
        });
        const neighbours = list.filter((b) => b.id !== box.id);
        this.kind = S.blockKind(box, this.pdfWords, pw, neighbours);
        this.pristine = true;
        this.moved = false;
        this.log('block', this.id, this.kind, JSON.stringify(box.pt));
    }

    sel() { return H.boxSel(this.id); }

    async words(mode = 'box') {
        const res = await this.page.evaluate(M.pageWords, { sel: this.sel(), mode });
        if (res.error) return { words: [], box: null, scale: this.scale };
        M.assignRows(res.words);
        return res;
    }

    async box() { return H.boxById(this.page, this.id); }
    async text() { return this.page.evaluate(M.textState, { sel: this.sel() }); }

    /** PDF reference words for the pristine block, in page px. */
    pdfRefWords() {
        const s = this.scale;
        const ws = this.pdfWords.map((w) => ({ t: w.t, x: w.x * s, r: w.r * s, y: w.y * s, bot: w.bot * s, base: Number.isFinite(w.base) ? w.base * s : null }));
        M.assignRows(ws);
        return ws;
    }

    async neighbourSnapshot() {
        const all = (await H.boxes(this.page)).filter((b) => b.page === this.pageNo && b.id !== this.id);
        const persisted = await H.persistedMany(this.page, all.map((b) => b.id));
        const map = {};
        for (const b of all) map[b.id] = { pt: b.pt, text: b.text, persisted: persisted[b.id] };
        return map;
    }

    async shot(label) {
        try {
            await H.centerBox(this.page, this.id);
            const bb = await this.page.locator(this.sel()).first().boundingBox();
            if (!bb) return null;
            const m = 30;
            const vp = this.page.viewportSize();
            const clip = { x: Math.max(0, bb.x - m), y: Math.max(0, bb.y - m), width: Math.min(vp.width, bb.width + 2 * m), height: Math.min(vp.height, bb.height + 2 * m) };
            clip.width = Math.min(clip.width, vp.width - clip.x); clip.height = Math.min(clip.height, vp.height - clip.y);
            const buf = await this.page.screenshot({ clip });
            return { label, buf };
        } catch (_) { return null; }
    }

    // ------------------------------------------------------------------ violations
    async violate(inv, step, stepIndex, message, data) {
        if (!this.inv.has(inv)) return;
        if (this.keepGoing && this.violations.some((v) => v.inv === inv)) return;
        if (!step || !step.op) step = this.curStep;
        const v = { inv, stepIndex, step, message, data, kind: this.kind, bundle: H.bundleName() };
        v.signature = S.signatureOf(v, this.kind);
        v.locus = S.locus(v);
        const base = path.join(this.outDir, `v${this.violations.length + 1}_${inv}`);
        v.shots = {};
        try {
            if (this.shotBuf?.buf) { fs.writeFileSync(`${base}_before.png`, this.shotBuf.buf); v.shots.before = `${base}_before.png`; }
            const after = await this.shot('after');
            if (after) { fs.writeFileSync(`${base}_after.png`, after.buf); v.shots.after = `${base}_after.png`; }
            await this.page.screenshot({ path: `${base}_editor.png` }); v.shots.editor = `${base}_editor.png`;
            const r = this.origRect;
            F.render(this.pdf, this.pageNo, [r.x - 10, r.y - 10, r.x + r.w + 10, r.y + r.h + 10], `${base}_pdf.png`, this.scale);
            v.shots.pdf = `${base}_pdf.png`;
        } catch (e) { v.shotError = String(e.message || e).slice(0, 200); }
        this.violations.push(v);
        this.log('VIOLATION', v.signature, message);
    }

    // ------------------------------------------------------------------ targets
    async resolve(at) {
        await H.centerBox(this.page, this.id);
        const tp = await this.page.evaluate(M.targetPoints, { sel: this.sel() });
        if (!tp) throw new Error('target: no box');
        const b = tp.box;
        // keep 5px away from the box edges: the resize handles sit on them (a drag there is a resize, not a selection)
        const clampX = (x) => Math.max(b.x + 5, Math.min(b.x + b.w - 5, x));
        if (!tp.rows.length || !at) return { x: b.x + b.w / 2, y: b.y + b.h / 2, kind: 'center' };
        let ri = Math.min(tp.rows.length - 1, Math.floor(at.row * tp.rows.length));
        if (at.kind === 'hanging' && tp.rows.length > 1) ri = Math.max(1, ri);
        if (at.kind === 'afterMarker') ri = 0;
        const row = tp.rows[ri];
        const wi = Math.min(row.words.length - 1, Math.floor(at.word * row.words.length));
        const w = row.words[wi];
        const next = row.words[wi + 1];
        let x;
        switch (at.kind) {
            case 'wordStart': x = w.l + 0.8; break;
            case 'wordEnd': x = w.r - 0.8; break;
            case 'between': x = next ? (w.r + next.l) / 2 : w.r + 2; break;
            case 'afterMarker': x = row.words[0].r + 0.8; break;
            case 'rowEnd': x = row.words[row.words.length - 1].r + 3; break;
            case 'hanging': x = row.words[0].l - 4; break;
            default: x = (w.l + w.r) / 2;
        }
        x = clampX(x);
        // resize handles (10px dots on the corners and edge midpoints, CSS .enpv-resize-handle):
        // step 8px inward from any handle so a click/drag never becomes a resize
        const hx = [b.x, b.x + b.w / 2, b.x + b.w]; const hy = [b.y, b.y + b.h / 2, b.y + b.h];
        for (const cx of hx) for (const cy of hy) {
            if (Math.abs(x - cx) < 7 && Math.abs(row.cy - cy) < 7) x = cx === b.x + b.w ? cx - 8 : cx + 8;
        }
        return { x, y: row.cy, kind: at.kind, row: ri, word: wi };
    }

    async outsidePoint() {
        return this.page.evaluate((sel) => {
            const box = document.querySelector(sel);
            const pageDiv = box?.closest('.page');
            const pr = pageDiv.getBoundingClientRect();
            const ys = [pr.top + 20, window.innerHeight / 2, window.innerHeight - 30];
            for (const x of [pr.left - 14, pr.right + 14]) {
                for (const y of ys) {
                    if (x < 0 || x > window.innerWidth || y < 0 || y > window.innerHeight) continue;
                    const el = document.elementFromPoint(x, y);
                    if (el && el.closest('#viewerContainer, .pdfViewer') && !el.closest('.page, .enpv-annotation-box, #enpv-ann-menu, .afb, [class*="format-bar"]')) return { x, y };
                }
            }
            return { x: Math.max(2, pr.left - 6), y: window.innerHeight / 2 };
        }, this.sel());
    }

    async exitEdit(via = 'outside') {
        if (via === 'escape') {
            await this.page.keyboard.press('Escape');
            await H.sleep(300);
        }
        const p = await this.outsidePoint();
        await this.page.mouse.click(p.x, p.y);
        await H.sleep(900);
    }

    async ensureEditing() {
        if (!(await H.isEditing(this.page, this.id))) {
            await this.enter({ via: 'menu' }, true);
            return true;
        }
        // After a format-bar control (e.g. the font <select>) the focus is outside the text:
        // a user clicks back into the text before typing, so do the same (real click).
        const focused = await this.page.evaluate((sel) => {
            const root = document.querySelector(sel)?.querySelector('.enpv-text-content');
            return !!root && (root === document.activeElement || root.contains(document.activeElement));
        }, this.sel());
        if (!focused) {
            const pt = await this.resolve({ kind: 'wordEnd', row: 0, word: 0.99 });
            await this.page.mouse.click(pt.x, pt.y);
            await H.sleep(250);
            this.notes.push({ step: this.stepIndex, note: 'clicked back into the text (focus was on a format control)' });
        }
        return false;
    }

    // ------------------------------------------------------------------ I1
    async enter(step, silent = false) {
        const page = this.page;
        const before = await this.words('box');
        // What the user sees before opening: the box's own DOM text only once
        // it paints (a persisted overlay); otherwise the PDF canvas. An idle
        // source handle's DOM text is invisible and sits on its own font's
        // baseline, which produced false "moved on open" reports.
        const paints = await page.evaluate((sel) => document.querySelector(sel)?.classList.contains('is-persisted-overlay') === true, this.sel());
        const usePdfRef = this.pristine || !paints;
        const ref = usePdfRef ? this.pdfRefWords() : before.words;
        this.openRef = { words: before.words, box: await this.box(), persisted: await H.persisted(page, this.id), saves: page.__saves.length, dirty: false, text: (await this.text()).text };
        if (step.via === 'click') {
            const pt = await this.resolve(step.at || { kind: 'mid', row: 0.3, word: 0.4 });
            await H.clickSelect(page, this.id);
            await page.mouse.dblclick(pt.x, pt.y);
            await H.sleep(350);
            if (await H.isEditing(page, this.id)) {
                await page.mouse.click(pt.x, pt.y);
                await H.sleep(250);
            }
            await H.waitEditing(page, this.id, 8000).catch(async () => { await H.enterEditMenu(page, this.id); });
        } else {
            await H.enterEditMenu(page, this.id);
        }
        if (silent) return;
        const edit = await this.words('box');
        this.checkOpen(step, ref, edit.words, usePdfRef ? 'pdf' : 'display');
    }

    checkOpen(step, ref, got, refKind) {
        if (!ref.length || !got.length) {
            // nothing measurable (e.g. the text is painted by another layer): no verdict, not a violation
            this.notes.push({ step: this.stepIndex, note: `I1 open not measurable: ${ref.length} reference words, ${got.length} words in the open box` });
            return null;
        }
        this.count('I1');
        const tolPx = this.tolPx();
        const cmp = M.compare(ref, got, this.scale, tolPx);
        const rows = {};
        for (const d of cmp.detail) { if (!(d.row in rows)) rows[d.row] = d; }
        const origins = Object.values(rows);
        const rowOriginMaxDx = origins.reduce((m, d) => (Math.abs(d.dx) > Math.abs(m) ? d.dx : m), 0);
        const maxDy = cmp.maxDy;
        let intra = 0;
        for (const d of cmp.detail) { const o = rows[d.row]; const v = d.dx - o.dx; if (Math.abs(v) > Math.abs(intra)) intra = v; }
        const intraTol = refKind === 'pdf' ? TOL.pdfIntraRowDxPx100 * this.zoom : tolPx;
        const originTol = refKind === 'pdf' ? Math.max(TOL.pdfRowOriginPx100 * this.zoom, TOL.pixelFloorPx) : tolPx;
        const dys = cmp.detail.map((d) => d.dy);
        const mean = dys.reduce((a, b) => a + b, 0) / Math.max(1, dys.length);
        const sd = Math.sqrt(dys.reduce((a, b) => a + (b - mean) ** 2, 0) / Math.max(1, dys.length));
        const matchedShare = cmp.matched / Math.max(1, Math.min(ref.length, got.length));
        const hangingDx = origins.filter((d) => d.row > 0 && Math.abs(d.dx) > originTol).length > 0 && Math.abs(rows[0]?.dx || 0) <= originTol;
        const data = {
            phase: 'open', ref: refKind, tolPx: r2(originTol), intraTolPx: r2(intraTol), zoom: this.zoom,
            rowOriginMaxDx: r2(rowOriginMaxDx), rowOriginMaxDy: r2(maxDy), maxIntraRowDx: r2(intra), uniformDy: sd < 0.3 && Math.abs(mean) > originTol,
            hangingDx, matched: cmp.matched, refWords: cmp.refWords, gotWords: cmp.gotWords, movedWords: cmp.moved,
            worst: cmp.detail.filter((d) => d.moved).sort((a, b) => Math.hypot(b.dx, b.dy) - Math.hypot(a.dx, a.dy)).slice(0, 8),
        };
        const bad = Math.abs(rowOriginMaxDx) > originTol || Math.abs(maxDy) > originTol || Math.abs(intra) > intraTol || matchedShare < TOL.textMatchRatio;
        if (bad) {
            return this.pendingViolation('I1', step, `opening moved words: row-origin dx ${r2(rowOriginMaxDx)}px, dy ${r2(maxDy)}px, intra-row dx ${r2(intra)}px (tol ${r2(originTol)}/${r2(intraTol)}px, vs ${refKind}); matched ${cmp.matched}/${Math.min(ref.length, got.length)}`, { ...data, cmp: { moved: cmp.moved } });
        }
        return null;
    }

    async checkClose(step) {
        const ref = this.openRef;
        if (!ref || ref.dirty) return;
        this.count('I1');
        const after = await this.words('box');
        const box = await this.box();
        const persisted = await H.persisted(this.page, this.id);
        const cmp = M.compare(ref.words, after.words, this.scale, this.tolPx());
        const boxDelta = ['x', 'y', 'w', 'h'].map((k) => Math.abs((box?.pt?.[k] ?? 0) - (ref.box?.pt?.[k] ?? 0)));
        const boxChanged = Math.max(...boxDelta) > TOL.boxPt;
        const persistedChanged = persisted !== ref.persisted;
        const saves = this.page.__saves.slice(ref.saves);
        const data = { phase: 'close', tolPx: r2(this.tolPx()), maxDx: cmp.maxDx, maxDy: cmp.maxDy, movedWords: cmp.moved, matched: cmp.matched, boxDeltaPt: boxDelta.map(r2), boxChanged, persistedChanged, savesDuring: saves.length, persistedBefore: (ref.persisted || '').slice(0, 300), persistedAfter: (persisted || '').slice(0, 300) };
        if (cmp.moved || boxChanged || persistedChanged) {
            await this.violate('I1', step, this.stepIndex, `open+close without typing changed the block: moved ${cmp.moved} words (max dx ${cmp.maxDx}, dy ${cmp.maxDy}px), box delta ${Math.max(...boxDelta).toFixed(2)}pt, persisted ${persistedChanged ? 'CHANGED' : 'same'}, ${saves.length} saves`, data);
        }
    }

    pendingViolation(inv, step, message, data) { this._pending = { inv, step, message, data }; return this._pending; }
    async flushPending() {
        if (this._pending) { const p = this._pending; this._pending = null; await this.violate(p.inv, p.step, this.stepIndex, p.message, p.data); }
    }

    /** Anything that is not an edit (caret clicks, selection, navigation keys) must move nothing. */
    async checkNoMove(step, before, what) {
        this.count('I1');
        const after = await this.words('box');
        const cmp = M.compare(before.words, after.words, this.scale, this.tolPx());
        const dh = Math.abs((after.box?.h || 0) - (before.box?.h || 0)) / this.scale;
        if (cmp.moved || dh > TOL.boxPt || cmp.matched < before.words.length * TOL.textMatchRatio) {
            await this.violate('I1', step, this.stepIndex, `${what} moved ${cmp.moved} words (max dx ${cmp.maxDx}, dy ${cmp.maxDy}px), box height delta ${r2(dh)}pt`, {
                phase: 'caret', what, tolPx: r2(this.tolPx()), maxDx: cmp.maxDx, maxDy: cmp.maxDy, movedWords: cmp.moved, matched: cmp.matched, boxHeightDeltaPt: r2(dh),
                worst: cmp.detail.filter((d) => d.moved).slice(0, 8),
            });
        }
    }

    // ------------------------------------------------------------------ I2 + I3
    async keystroke(step, key, char) {
        const page = this.page;
        let plan = await page.evaluate(M.keyPlan, { sel: this.sel(), key: char ? 'char' : key });
        if (!plan.ok) {
            // No caret in this block: a key now would go elsewhere (and Backspace/Delete on a
            // selected-but-not-editing box deletes the box). Click back into the text like a user; never press blind.
            if (!(await H.isEditing(page, this.id))) await this.enter({ via: 'menu' }, true);
            const pt = await this.resolve({ kind: 'wordEnd', row: 0, word: 0.99 });
            await page.mouse.click(pt.x, pt.y);
            await H.sleep(250);
            plan = await page.evaluate(M.keyPlan, { sel: this.sel(), key: char ? 'char' : key });
            this.notes.push({ step: this.stepIndex, note: `caret was outside the block before ${char || key}: clicked back into the text${plan.ok ? '' : ' (still no caret: key skipped)'}` });
            if (!plan.ok) return;
        }
        const before = await this.words('box');
        const st0 = await this.text();
        if (char) await page.keyboard.type(char); else await page.keyboard.press(key);
        await H.sleep(140);
        if (NAV_KEYS.has(key)) { await this.checkNoMove(step, before, `navigation key ${key}`); return; }
        if (this.openRef) this.openRef.dirty = true;
        this.pristine = false;
        const st1 = await this.text();
        if (st1.error) {
            const exists = await this.page.evaluate((sel) => !!document.querySelector(sel), this.sel());
            await this.violate('I2', step, this.stepIndex, `after ${char ? `typing "${char}"` : key} the block's editable text is gone (${exists ? 'box without .enpv-text-content' : 'box removed from the page'})`, { kind: 'vanished', key: char || key, boxExists: exists, delta: -(plan.nonws || '').length });
            return;
        }
        // I2
        this.count('I2');
        const ins = char ? char.replace(/\s/g, '') : '';
        const expected = plan.nonws.slice(0, plan.a) + ins + plan.nonws.slice(plan.b);
        if (st1.nonws !== expected) {
            let i = 0; while (i < expected.length && expected[i] === st1.nonws[i]) i += 1;
            await this.violate('I2', step, this.stepIndex, `after ${char ? `typing "${char}"` : key} the text is not original+edit: expected ${expected.length} chars, got ${st1.nonws.length}; first difference at ${i}: expected "${expected.slice(Math.max(0, i - 15), i + 15)}" got "${st1.nonws.slice(Math.max(0, i - 15), i + 15)}"`, {
                kind: 'keystroke', key: char || key, delta: st1.nonws.length - expected.length, at: i, caret: plan.a, removed: plan.removed,
                expectedCtx: expected.slice(Math.max(0, i - 30), i + 30), gotCtx: st1.nonws.slice(Math.max(0, i - 30), i + 30),
            });
            return;
        }
        // I3
        if (key === 'Enter' || !plan.collapsed || !st0.caret?.rect) return;
        if (!char && !plan.removed) return;
        this.count('I3');
        const after = await this.words('box');
        const caretY = st0.caret.rect.y + st0.caret.rect.h / 2;
        const caretX = st0.caret.rect.x;
        const rows = M.assignRows(before.words);
        let caretRow = 0; let bestD = 1e9;
        rows.forEach((r, i) => { const d = Math.abs(r.cy - caretY); if (d < bestD) { bestD = d; caretRow = i; } });
        const tol = this.tolPx();
        const pairs = M.align(before.words, after.words);
        const other = []; const beforeCaret = [];
        for (const [p, q] of pairs) {
            const dx = q.x - p.x; const dy = (q.y + q.bot) / 2 - (p.y + p.bot) / 2;
            if (Math.abs(dx) <= tol && Math.abs(dy) <= tol) continue;
            const e = { t: p.t, row: p.row, dx: r2(dx), dy: r2(dy) };
            if (p.row !== caretRow) other.push(e); else if (p.r <= caretX + 0.5) beforeCaret.push(e);
        }
        const dh = ((after.box?.h || 0) - (before.box?.h || 0)) / this.scale;
        const boxHeightChanged = Math.abs(dh) > TOL.boxPt;
        // Reflow is allowed only when the row genuinely has no room (typing) or the next row's first word now fits (deleting).
        const row = rows[caretRow];
        const rowWords = row ? row.words : [];
        const rowRight = rowWords.length ? Math.max(...rowWords.map((w) => w.r)) : 0;
        const rowChars = rowWords.reduce((s, w) => s + w.t.length, 0) || 1;
        const rowLeft = rowWords.length ? Math.min(...rowWords.map((w) => w.x)) : 0;
        const avgChar = (rowRight - rowLeft) / rowChars;
        const slack = (before.box ? before.box.x + before.box.w : rowRight) - rowRight;
        // Typing into a row that has no room left may wrap (it "has to"); nothing else may re-flow.
        let reflowAllowed = false;
        if (char && slack < avgChar * 2.5) reflowAllowed = true;
        // A delete that pulls the next row's first word(s) up is reported, but labelled separately.
        const pullUp = !char && other.some((e) => e.row === caretRow + 1 && e.dy < -0.5 * (row?.h || 10));
        const data = { pullUp, key: char || key, caretRow, rows: rows.length, tolPx: r2(tol), otherRowsMoved: other.length, beforeCaretMoved: beforeCaret.length, boxHeightDeltaPt: r2(dh), boxHeightChanged, rowSlackPx: r2(slack), avgCharPx: r2(avgChar), reflowAllowed, other: other.slice(0, 8), beforeCaret: beforeCaret.slice(0, 8) };
        if (reflowAllowed && !beforeCaret.length) { if (other.length || boxHeightChanged) this.notes.push({ step: this.stepIndex, note: 'row re-flow allowed (row full / next word fits)', data }); return; }
        if (other.length || beforeCaret.length || boxHeightChanged) {
            await this.violate('I3', step, this.stepIndex, `${char ? `typing "${char}"` : key} in row ${caretRow + 1}/${rows.length}: ${other.length} words on other rows moved, ${beforeCaret.length} words before the caret moved, box height ${r2(dh)}pt (row slack ${r2(slack)}px)`, data);
        }
    }

    // ------------------------------------------------------------------ I5
    async measureState() {
        const w = await this.words('box');
        const info = await this.page.evaluate((sel) => {
            const root = document.querySelector(sel)?.querySelector('.enpv-text-content');
            if (!root) return null;
            const sizes = [];
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            while (walker.nextNode()) { const n = walker.currentNode; if (n.nodeValue.trim() && n.parentElement) sizes.push(parseFloat(getComputedStyle(n.parentElement).fontSize)); }
            sizes.sort((a, b) => a - b);
            return { fontPx: sizes[Math.floor(sizes.length / 2)] || null };
        }, this.sel());
        const box = await this.box();
        return { fontPx: info?.fontPx ?? null, rows: M.assignRows(w.words).length, hPt: box?.pt?.h ?? null, wPt: box?.pt?.w ?? null, text: (await this.text()).text };
    }

    // ------------------------------------------------------------------ steps
    async exec(step) {
        const page = this.page;
        const editing = await H.isEditing(page, this.id);
        switch (step.op) {
            case 'openClose': {
                if (editing) await this.exitEdit('outside');
                await this.enter({ via: step.via, at: { kind: 'mid', row: 0.2, word: 0.5 } });
                await this.flushPending();
                if (this.violations.length && !this.keepGoing) return;
                await this.exitEdit('outside');
                await this.checkClose(step);
                this.openRef = null;
                return;
            }
            case 'enter': {
                if (editing) return;
                await this.enter(step);
                await this.flushPending();
                return;
            }
            case 'exit': {
                if (!editing) return;
                await this.exitEdit(step.via);
                await this.checkClose(step);
                this.openRef = null;
                return;
            }
            case 'click': case 'dblclick': {
                if (!editing) { await this.enter({ via: 'click', at: step.at }); await this.flushPending(); if (this.violations.length && !this.keepGoing) return; }
                const before = await this.words('box');
                const pt = await this.resolve(step.at);
                if (step.op === 'click') await page.mouse.click(pt.x, pt.y); else await page.mouse.dblclick(pt.x, pt.y);
                await H.sleep(250);
                if (!(await H.isEditing(page, this.id))) { this.notes.push({ step: this.stepIndex, note: `${step.op} at ${pt.kind} left edit mode` }); return; }
                await this.checkNoMove(step, before, `${step.op} at ${pt.kind}`);
                return;
            }
            case 'clickText': {
                // deterministic caret placement: real click at the start (or end) of the first word matching step.needle
                if (!editing) { await this.enter({ via: 'menu' }); await this.flushPending(); if (this.violations.length && !this.keepGoing) return; }
                await H.centerBox(page, this.id);
                const tp = await page.evaluate(M.targetPoints, { sel: this.sel() });
                const hit = tp && tp.rows.flatMap((r) => r.words.map((w) => ({ ...w, cy: r.cy }))).find((w) => w.t.startsWith(step.needle));
                if (!hit) throw new Error(`clickText: "${step.needle}" not found`);
                const x = step.pos === 'after' ? hit.r - 0.8 : hit.l + 0.8;
                await page.mouse.click(x, hit.cy);
                await H.sleep(250);
                // verify with the caret itself; nudge with real arrow keys if the click landed one character off
                for (let k = 0; k < 3; k += 1) {
                    const where = await page.evaluate(({ sel, needle, pos }) => {
                        const root = document.querySelector(sel)?.querySelector('.enpv-text-content');
                        const s = getSelection(); if (!root || !s.rangeCount) return 0;
                        const r = s.getRangeAt(0); const pre = document.createRange(); pre.setStart(root, 0); pre.setEnd(r.startContainer, r.startOffset);
                        const strip = (t) => t.replace(/[\s\u00a0\u00ad\u200b-\u200d\u2060\ufeff]+/g, '');
                        const a = strip(pre.toString()).length; const all = strip(root.textContent); const i = all.indexOf(strip(needle));
                        return pos === 'after' ? a - (i + strip(needle).length) : a - i;
                    }, { sel: this.sel(), needle: step.needle, pos: step.pos || 'before' });
                    if (where === 0) break;
                    await page.keyboard.press(where > 0 ? 'ArrowLeft' : 'ArrowRight');
                    this.notes.push({ step: this.stepIndex, note: `clickText nudged caret ${where > 0 ? 'left' : 'right'}` });
                }
                return;
            }
            case 'dragSelect': {
                await this.ensureEditing();
                const before = await this.words('box');
                const a = await this.resolve(step.from); const b = await this.resolve(step.to);
                await page.mouse.move(a.x, a.y); await page.mouse.down();
                await page.mouse.move(b.x, b.y, { steps: 8 }); await page.mouse.up();
                await H.sleep(250);
                if (await H.isEditing(page, this.id)) await this.checkNoMove(step, before, 'drag selection');
                return;
            }
            case 'type': {
                await this.ensureEditing();
                for (const ch of step.text) {
                    await this.keystroke(step, null, ch);
                    if (this.violations.length && !this.keepGoing) return;
                }
                return;
            }
            case 'key': {
                await this.ensureEditing();
                for (let i = 0; i < (step.n || 1); i += 1) {
                    await this.keystroke(step, step.key, null);
                    if (this.violations.length && !this.keepGoing) return;
                }
                return;
            }
            case 'typeDelete': {
                await this.ensureEditing();
                const t0 = await this.text();
                const w0 = await this.words('box');
                for (const ch of step.text) { await this.keystroke(step, null, ch); if (this.violations.length && !this.keepGoing) return; }
                for (let i = 0; i < step.text.length; i += 1) { await this.keystroke(step, 'Backspace', null); if (this.violations.length && !this.keepGoing) return; }
                const t1 = await this.text();
                if (t1.error) return; // reported by keystroke() as I2 'vanished'
                this.count('I2');
                if (t1.nonws !== t0.nonws) {
                    let i = 0; while (i < t0.nonws.length && t0.nonws[i] === t1.nonws[i]) i += 1;
                    await this.violate('I2', step, this.stepIndex, `typing "${step.text}" then Backspace x${step.text.length} did not restore the text (at ${i}: "${t0.nonws.slice(Math.max(0, i - 15), i + 15)}" -> "${t1.nonws.slice(Math.max(0, i - 15), i + 15)}")`, { kind: 'restore', delta: t1.nonws.length - t0.nonws.length, at: i });
                    return;
                }
                const w1 = await this.words('box');
                const cmp = M.compare(w0.words, w1.words, this.scale, this.tolPx());
                if (cmp.moved) this.notes.push({ step: this.stepIndex, note: `type+delete restored the text but ${cmp.moved} words sit elsewhere (max dx ${cmp.maxDx}, dy ${cmp.maxDy}px)` });
                return;
            }
            case 'undo': case 'redo': {
                await this.ensureEditing();
                await page.keyboard.press(step.op === 'undo' ? 'Control+z' : 'Control+y');
                await H.sleep(400);
                if (this.openRef) this.openRef.dirty = true;
                this.pristine = false;
                return;
            }
            case 'format': {
                await this.ensureEditing();
                await this.format(step);
                if (this.openRef) this.openRef.dirty = true;
                this.pristine = false;
                return;
            }
            case 'cycles': {
                if (editing) await this.exitEdit('outside');
                const m0 = await this.measureState();
                const series = [m0];
                this.count('I5');
                for (let k = 0; k < (step.n || 4); k += 1) {
                    await H.enterEditMenu(page, this.id);
                    await this.exitEdit('outside');
                    series.push(await this.measureState());
                }
                const drift = [];
                for (const m of series.slice(1)) {
                    if (m0.fontPx != null && m.fontPx != null && Math.abs(m.fontPx - m0.fontPx) > TOL.fontPx) drift.push('font size');
                    if (m.rows !== m0.rows) drift.push('row count');
                    if (m0.hPt != null && m.hPt != null && Math.abs(m.hPt - m0.hPt) > TOL.boxHeightPt) drift.push('box height');
                    if (m.text.replace(/\s/g, '') !== m0.text.replace(/\s/g, '')) drift.push('text');
                }
                const uniq = [...new Set(drift)];
                if (uniq.length) await this.violate('I5', step, this.stepIndex, `${step.n || 4} open/commit cycles drift: ${uniq.join(', ')} (${series.map((m) => `${m.fontPx}px/${m.rows}r/${m.hPt}pt`).join(' -> ')})`, { drift: uniq, series: series.map(({ text, ...rest }) => rest) });
                return;
            }
            case 'move': return this.move(step);
            case 'reload': return this.reload(step);
            case 'download': return this.download(step);
            default: throw new Error(`unknown op ${step.op}`);
        }
    }

    async format(step) {
        const page = this.page;
        const ctl = { font: '#afb-font', size: '#afb-size', bold: '#afb-bold', italic: '#afb-italic', color: '#afb-text-color' }[step.control];
        const loc = page.locator(ctl).first();
        const visible = await loc.isVisible().catch(() => false);
        if (!visible) { this.notes.push({ step: this.stepIndex, note: `format control ${ctl} not visible` }); return; }
        if (step.control === 'font') {
            await loc.selectOption(step.value).catch((e) => this.notes.push({ step: this.stepIndex, note: `font select failed: ${e.message.slice(0, 80)}` }));
        } else if (step.control === 'size') {
            const bb = await loc.boundingBox();
            if (bb) await page.mouse.click(bb.x + 2 + (bb.width - 4) * step.value, bb.y + bb.height / 2);
        } else if (step.control === 'color') {
            await loc.evaluate((el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, step.value);
        } else {
            const bb = await loc.boundingBox();
            if (bb) await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
        }
        await H.sleep(500);
    }

    // ------------------------------------------------------------------ I6
    async move(step) {
        const page = this.page;
        if (await H.isEditing(page, this.id)) await this.exitEdit('outside');
        const b0 = await this.box();
        await H.clickSelect(page, this.id);
        const grip = page.locator('#enpv-ann-menu [data-action="move"]').first();
        const g = await grip.boundingBox().catch(() => null);
        if (!g) { this.notes.push({ step: this.stepIndex, note: 'no move grip' }); await this.exitEdit('outside'); return; }
        const sx = g.x + g.width / 2; const sy = g.y + g.height / 2;
        await page.mouse.move(sx, sy, { steps: 3 }); await H.sleep(120); await page.mouse.down(); await H.sleep(80);
        await page.mouse.move(sx + step.dx, sy + step.dy, { steps: 16 }); await page.mouse.up(); await H.sleep(700);
        await this.exitEdit('outside');
        const b1 = await this.box();
        const dxPt = b1.pt.x - b0.pt.x; const dyPt = b1.pt.y - b0.pt.y;
        if (Math.abs(dxPt) < 1 && Math.abs(dyPt) < 1) { this.notes.push({ step: this.stepIndex, note: 'move had no effect' }); return; }
        this.moved = true; this.pristine = false;
        this.count('I6');
        // old place: the ORIGINAL rect (where the canvas glyphs are), minus the block's new rect
        const o = this.origRect;
        const oldRect = [o.x, o.y, o.x + o.w, o.y + o.h];
        const clip = await page.evaluate(({ sel, r, s }) => {
            const box = document.querySelector(sel);
            const pageDiv = box.closest('.page');
            const probe = pageDiv.getBoundingClientRect();
            window.scrollBy(0, 0);
            return { px: probe.left + r[0] * s, py: probe.top + r[1] * s };
        }, { sel: this.sel(), r: oldRect, s: this.scale });
        // bring the old place into view
        await page.evaluate(({ y }) => { if (y < 40 || y > window.innerHeight - 200) document.getElementById('viewerContainer')?.scrollBy(0, y - window.innerHeight / 3); }, { y: clip.py });
        await H.sleep(500);
        const pos = await page.evaluate(({ sel, r, s }) => {
            const pageDiv = document.querySelector(sel).closest('.page');
            const pr = pageDiv.getBoundingClientRect();
            return { x: pr.left + r[0] * s, y: pr.top + r[1] * s, w: (r[2] - r[0]) * s, h: (r[3] - r[1]) * s };
        }, { sel: this.sel(), r: oldRect, s: this.scale });
        const vp = page.viewportSize();
        const shotPath = path.join(this.outDir, `move${this.stepIndex}_old.png`);
        const cx = Math.max(0, Math.round(pos.x)); const cy = Math.max(0, Math.round(pos.y));
        const cw = Math.min(vp.width - cx, Math.round(pos.w)); const ch = Math.min(vp.height - cy, Math.round(pos.h));
        if (cw < 4 || ch < 4) { this.notes.push({ step: this.stepIndex, note: 'old place not in view' }); return; }
        // fractional page offset: re-base the rect onto the rounded clip so the render lines up
        const clipRectPt = [(cx - (pos.x - o.x * this.scale)) / this.scale, (cy - (pos.y - o.y * this.scale)) / this.scale];
        const rectPt = [clipRectPt[0], clipRectPt[1], clipRectPt[0] + cw / this.scale, clipRectPt[1] + ch / this.scale];
        const chrome = await this.chromeOff();
        await page.screenshot({ path: shotPath, clip: { x: cx, y: cy, width: cw, height: ch } });
        const nb = b1.pt;
        const exclude = [[nb.x - 1, nb.y - 1, nb.x + nb.w + 1, nb.y + nb.h + 1]];
        const res = F.bgcheck(this.pdf, this.pageNo, rectPt, exclude, shotPath, this.scale, this.pdfWords);
        // new place renders?
        const newShot = path.join(this.outDir, `move${this.stepIndex}_new.png`);
        let rendered = null;
        try {
            await H.centerBox(page, this.id);
            const bb = await page.locator(this.sel()).first().boundingBox();
            await page.screenshot({ path: newShot, clip: { x: bb.x, y: bb.y, width: Math.max(2, Math.min(bb.width, vp.width - bb.x)), height: Math.max(2, Math.min(bb.height, vp.height - bb.y)) } });
            rendered = F.inkcount(newShot);
        } catch (e) { this.notes.push({ step: this.stepIndex, note: `new-place shot failed ${e.message.slice(0, 80)}` }); }
        await this.chromeOn(chrome);
        const data = { movedPt: [r2(dxPt), r2(dyPt)], inkPixels: res.inkPixels, area: res.area, inkBBoxPx: res.inkBBoxPx, tolInk: TOL.inkPixels, newPlaceDarkPixels: rendered?.dark ?? null, shots: { old: shotPath, ink: shotPath.replace('.png', '_ink.png'), expected: shotPath.replace('.png', '_expected.png'), newPlace: newShot } };
        if (res.inkPixels > TOL.inkPixels) {
            await this.violate('I6', step, this.stepIndex, `after moving the block ${r2(dxPt)},${r2(dyPt)}pt its old place keeps ${res.inkPixels} ink pixels (tol ${TOL.inkPixels})`, data);
        } else if (rendered && rendered.dark < TOL.renderedDarkPixels && this.pdfWords.length) {
            await this.violate('I6', step, this.stepIndex, `moved block renders no text at its new place (${rendered.dark} dark pixels)`, { ...data, notRendered: true });
        }
    }

    /** Hide editor chrome (dashed box outlines, handles, menus) for pixel checks. */
    async chromeOff() {
        const h = await this.page.addStyleTag({ content: '.enpv-annotation-box,.enpv-annotation-box *,.textLayer *{outline-color:transparent!important;border-color:transparent!important;box-shadow:none!important}.enpv-resize-handle,#enpv-ann-menu,.afb,[class*="format-bar"]{display:none!important}' });
        await H.sleep(120);
        return h;
    }

    async chromeOn(h) { try { await h.evaluate((el) => el.remove()); } catch (_) { /* ignore */ } }

    // ------------------------------------------------------------------ I8
    async reload(step) {
        const page = this.page;
        if (await H.isEditing(page, this.id)) await this.exitEdit('outside');
        await H.sleep(2500);
        const w0 = await this.words('box');
        const b0 = await this.box();
        const t0 = (await this.text()).text;
        const nb0 = await this.neighbourSnapshot();
        await H.openEditor(page, this.docId);
        await H.scrollToPage(page, this.pageNo);
        await page.waitForSelector(this.sel(), { timeout: 30000 }).catch(() => {});
        await H.sleep(600);
        const z = await page.evaluate(() => window.__enpv.pdfViewer.currentScale);
        if (Math.abs(z - this.zoom) > 0.001) this.notes.push({ step: this.stepIndex, note: `zoom changed on reload ${this.zoom} -> ${z}` });
        this.count('I8');
        const b1 = await this.box();
        if (!b1) { await this.violate('I8', step, this.stepIndex, 'block missing after reload', { textChanged: true }); return; }
        const w1 = await this.words('box');
        const t1 = (await this.text()).text;
        const cmp = M.compare(w0.words, w1.words, this.scale, this.tolPx());
        const boxDelta = ['x', 'y', 'w', 'h'].map((k) => r2(Math.abs(b1.pt[k] - b0.pt[k])));
        const textChanged = t0.replace(/\s/g, '') !== t1.replace(/\s/g, '');
        const nb1 = await this.neighbourSnapshot();
        const nbChanged = Object.keys(nb0).filter((id) => nb1[id] && (nb1[id].text !== nb0[id].text || ['x', 'y', 'w', 'h'].some((k) => Math.abs(nb1[id].pt[k] - nb0[id].pt[k]) > TOL.boxPt)));
        const data = { tolPx: r2(this.tolPx()), maxDx: cmp.maxDx, maxDy: cmp.maxDy, movedWords: cmp.moved, matched: cmp.matched, words: w0.words.length, boxDeltaPt: boxDelta, textChanged, before: t0.slice(0, 200), after: t1.slice(0, 200), neighboursChanged: nbChanged.slice(0, 10), worst: cmp.detail.filter((d) => d.moved).slice(0, 8) };
        if (cmp.moved || Math.max(...boxDelta) > TOL.boxPt || textChanged || nbChanged.length) {
            await this.violate('I8', step, this.stepIndex, `after reload: ${cmp.moved}/${cmp.matched} words moved (max dx ${cmp.maxDx}, dy ${cmp.maxDy}px), box delta ${Math.max(...boxDelta)}pt, text ${textChanged ? 'CHANGED' : 'same'}, ${nbChanged.length} neighbours changed`, data);
        }
        this.snapNeighbours = nb1;
    }

    // ------------------------------------------------------------------ I7
    async baseline() {
        const bundle = H.bundleName();
        fs.mkdirSync(this.baselineDir, { recursive: true });
        const file = path.join(this.baselineDir, `${sha1(this.pdf)}_${bundle.replace(/\W+/g, '_')}.pdf`);
        if (fs.existsSync(file)) return file;
        const page = await this.context.newPage();
        page.__saves = [];
        try {
            const doc = await H.upload(page, this.pdf, 'base');
            await H.openEditorWithBoxes(page, doc);
            await H.downloadPdf(page, `${file}.tmp`);
            fs.renameSync(`${file}.tmp`, file);
        } finally { await page.close().catch(() => {}); }
        return file;
    }

    async download(step) {
        const page = this.page;
        if (await H.isEditing(page, this.id)) await this.exitEdit('outside');
        await H.sleep(1500);
        const base = await this.baseline();
        // Style fidelity: what the edited block shows before the download.
        const paints = await page.evaluate((sel) => document.querySelector(sel)?.classList.contains('is-persisted-overlay') === true, this.sel());
        const editorStyled = paints ? await page.evaluate(M.styledWords, { sel: this.sel() }) : null;
        const edited = path.join(this.outDir, `download${this.stepIndex}.pdf`);
        await H.downloadPdf(page, edited);
        this.count('I7');
        const box = await this.box();
        const o = this.origRect; const n = box.pt;
        const spec = {
            page: this.pageNo,
            touched: [[o.x - 2, o.y - 2, o.x + o.w + 2, o.y + o.h + 2], [n.x - 2, n.y - 2, n.x + n.w + 2, n.y + n.h + 2]],
            block_now: [n.x, n.y, n.x + n.w, n.y + n.h], block_orig: [o.x, o.y, o.x + o.w, o.y + o.h],
            editor_text: (await this.text()).text, pos_tol: TOL.exportPosPt, row_tol: TOL.exportRowOriginPt,
        };
        const specPath = edited.replace(/\.pdf$/, '.spec.json');
        fs.writeFileSync(specPath, JSON.stringify(spec, null, 1));
        const res = F.exportdiff(base, edited, specPath);
        const textBad = res.textMatch === false && !res.textContained;
        const originBad = res.rowOriginBad || [];
        const data = { ...res, baseline: base, edited, untouchedChanged: res.untouchedChanged.slice(0, 12), extraLines: res.extraLines.slice(0, 12), textMatch: textBad ? false : res.textMatch, rowOriginBad: originBad.slice(0, 12), rowGeometry: (res.rowGeometry || []).slice(0, 40) };
        this.geometry = { rows: (res.rowGeometry || []).length, edited: (res.rowGeometry || []).filter((r) => r.edited).length, bad: originBad.length, maxAbsDx: Math.max(0, ...(res.rowGeometry || []).filter((r) => !r.missing).map((r) => Math.abs(r.dx))), maxAbsDy: Math.max(0, ...(res.rowGeometry || []).filter((r) => !r.missing).map((r) => Math.abs(r.dy))), maxAbsPrefixDx: Math.max(0, ...(res.rowGeometry || []).filter((r) => r.edited).map((r) => Math.abs(r.prefixMaxDx || 0))) };
        const style = editorStyled && !editorStyled.error ? this.styleFidelity(editorStyled.words, F.stylewords(edited, this.pageNo, [n.x - 2, n.y - 2, n.x + n.w + 2, n.y + n.h + 2]).words) : null;
        if (style) {
            data.style = style;
            this.styleStats = { matched: style.matched, words: style.editorWords, bad: style.bad.length };
        }
        if (style && style.bad.length) {
            const kinds = [...new Set(style.bad.flatMap((b) => b.what))];
            const w = style.bad[0];
            await this.violate('I7', step, this.stepIndex, `download style: ${style.bad.length}/${style.matched} words differ from the editor (${kinds.join(', ')}); e.g. "${w.t}": ${w.what.map((k) => `${k} ${JSON.stringify(w.editor[k])}→${JSON.stringify(w.pdf[k])}`).join(', ')}`, { ...data, styleKinds: kinds.join('+') });
        }
        if (res.pageCountChanged || res.untouchedChanged.length || res.extraLines.length || textBad || res.colourLost.length || originBad.length) {
            const worst = originBad.filter((r) => !r.missing).sort((a, b) => Math.max(Math.abs(b.dx), Math.abs(b.dy), Math.abs(b.prefixMaxDx || 0)) - Math.max(Math.abs(a.dx), Math.abs(a.dy), Math.abs(a.prefixMaxDx || 0)))[0];
            const originMsg = originBad.length ? `, ${originBad.length} block rows off their origin${worst ? ` (row ${worst.row}${worst.edited ? ' edited' : ''}: dx ${worst.dx}pt, dy ${worst.dy}pt, prefix dx ${worst.prefixMaxDx ?? '-'}pt)` : ' (row missing)'}` : '';
            await this.violate('I7', step, this.stepIndex, `download: ${res.untouchedChanged.length}/${res.untouchedLines} untouched rows changed, ${res.extraLines.length} extra rows, edited text ${textBad ? 'MISMATCH' : 'ok'}, ${res.colourLost.length} colour losses${originMsg}`, data);
        }
    }

    /** I7 style: pair editor words with download words by text; report
     *  colour/bold/italic/size differences and a word whose row placement
     *  (baseline offset from the block's first paired word) moved. */
    styleFidelity(editorWords, pdfWords) {
        const pairs = M.align(editorWords, pdfWords);
        const bad = [];
        const dist = (a, b) => {
            const p = (h) => [1, 3, 5].map((i) => Number.parseInt(String(h || '#000000').slice(i, i + 2), 16) || 0);
            const [x, y] = [p(a), p(b)];
            return Math.max(...x.map((v, i) => Math.abs(v - y[i])));
        };
        const [e0, p0] = pairs[0] || [];
        for (const [e, q] of pairs) {
            const what = [];
            if (dist(e.color, q.color) > TOL.styleColour) what.push('color');
            if (e.bold !== q.bold) what.push('bold');
            if (e.italic !== q.italic) what.push('italic');
            if (Math.abs(e.size - q.size) > TOL.styleSizePt) what.push('size');
            const rowShift = (q.base - p0.base) - (e.base - e0.base);
            if (Math.abs(rowShift) > Math.max(TOL.styleRowPt, e.size * 0.45)) what.push('row');
            if (what.length) {
                bad.push({ t: e.t, what, rowShift: r2(rowShift), editor: { color: e.color, bold: e.bold, italic: e.italic, size: r2(e.size), row: r2(e.base - e0.base) }, pdf: { color: q.color, bold: q.bold, italic: q.italic, size: r2(q.size), row: r2(q.base - p0.base), font: q.font } });
            }
        }
        return { editorWords: editorWords.length, pdfWords: pdfWords.length, matched: pairs.length, bad: bad.slice(0, 20) };
    }

    // ------------------------------------------------------------------ I4
    async checkNeighbours(step) {
        this.count('I4');
        const now = await this.neighbourSnapshot();
        const changed = [];
        for (const [id, a] of Object.entries(this.snapNeighbours)) {
            const b = now[id];
            if (!b) continue; // virtualised / not rendered right now
            const d = Math.max(...['x', 'y', 'w', 'h'].map((k) => Math.abs(b.pt[k] - a.pt[k])));
            const what = [];
            if (b.text !== a.text) what.push('text');
            if (d > TOL.boxPt) what.push('geometry');
            if (b.persisted !== a.persisted) what.push('persisted');
            if (what.length) changed.push({ id, what, deltaPt: r2(d), textBefore: a.text.slice(0, 80), textAfter: b.text.slice(0, 80), persistedBefore: (a.persisted || '').slice(0, 160), persistedAfter: (b.persisted || '').slice(0, 160) });
        }
        if (changed.length) {
            const kinds = [...new Set(changed.flatMap((c) => c.what))];
            await this.violate('I4', step, this.stepIndex, `${changed.length} other blocks changed (${kinds.join(', ')}): ${changed.slice(0, 3).map((c) => `${c.id} ${c.what.join('+')} ${c.deltaPt}pt`).join('; ')}`, { what: kinds.join('+'), changed: changed.slice(0, 10) });
            this.snapNeighbours = now; // report once per change
        }
    }

    // ------------------------------------------------------------------ run
    async run(steps) {
        this.steps = steps;
        this.executed = 0;
        for (let i = 0; i < steps.length; i += 1) {
            this.stepIndex = i;
            const step = steps[i];
            this.curStep = step;
            this.shotBuf = await this.shot('before');
            const t0 = Date.now();
            try {
                await this.exec(step);
            } catch (e) {
                this.notes.push({ step: i, note: `step error: ${String(e.message || e).slice(0, 200)}` });
                this.stepError = { step: i, op: step.op, error: String(e.message || e).slice(0, 300), stack: String(e.stack || '').split('\n').slice(1, 5).join(' | ') };
                break;
            }
            this.executed = i + 1;
            if (!this.violations.length || this.keepGoing) await this.checkNeighbours(step);
            this.log(`step ${i} ${step.op} ${Date.now() - t0}ms`);
            if (this.violations.length && !this.keepGoing) break;
        }
    }

    async close() { try { await this.browser?.close(); } catch (_) { /* ignore */ } }

    result() {
        return {
            pdf: this.pdf, seed: this.seed, docId: this.docId, bundleStart: this.bundleStart, bundleEnd: H.bundleName(),
            block: { id: this.id, ref: this.blockRef, kind: this.kind, page: this.pageNo, rectPt: this.origRect, text: (this.origText || '').slice(0, 160) },
            zoom: this.zoom, steps: this.steps, executed: this.executed, violations: this.violations, notes: this.notes.slice(0, 40),
            checks: this.checks, i7Geometry: this.geometry || null, stepError: this.stepError || null, pageErrors: (this.page?.__errors || []).slice(0, 10), timings: this.timings,
        };
    }
}

/** Run one sequence end to end. opts: {pdf, seed, steps?, stepCount, block?, invariants, outDir, keepGoing, verbose} */
async function runSequence(opts) {
    const s = new Session(opts);
    const t0 = Date.now();
    try {
        await s.start();
        const steps = opts.steps || generate(s.seed, opts.stepCount || 12);
        await s.run(steps);
        const res = s.result();
        res.timings = { ...(res.timings || {}), total: Date.now() - t0 };
        return res;
    } catch (e) {
        const res = s.result();
        if (String(e.message || '').startsWith('SKIP:')) res.skipped = String(e.message).slice(6);
        else res.error = String(e.stack || e.message || e).slice(0, 600);
        res.timings = { total: Date.now() - t0 };
        return res;
    } finally {
        await s.close();
    }
}

module.exports = { Session, runSequence, TOL, ALL_INVARIANTS };
