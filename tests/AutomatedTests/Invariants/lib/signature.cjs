/**
 * Block-kind classification, locus inference and failure signatures.
 * A signature = invariant id + block kind + triggering action + coarse locus.
 * The locus is inferred from the invariant and its numbers only; it is a
 * hint for triage, not a root cause.
 */

const BULLET = /^([•●▪■◦–—·\-*o]|\(?\d{1,3}[.)]|\(?[a-zA-Z][.)]|\[\d+\])$/;

/** Group PDF words (pt) into rows. */
function rowsOf(words) {
    const rows = [];
    for (const w of [...words].sort((a, b) => (a.y + a.bot) / 2 - (b.y + b.bot) / 2)) {
        const cy = (w.y + w.bot) / 2; const h = Math.max(1, w.bot - w.y);
        let row = rows.find((r) => Math.abs(r.cy - cy) < 0.45 * Math.max(h, r.h));
        if (!row) { row = { cy, h, words: [] }; rows.push(row); }
        row.words.push(w);
    }
    rows.sort((a, b) => a.cy - b.cy);
    rows.forEach((r) => {
        r.words.sort((a, b) => a.x - b.x);
        r.x0 = r.words[0].x; r.x1 = r.words[r.words.length - 1].r;
    });
    return rows;
}

/**
 * kind of a block from its PDF words (pt) and its neighbours on the page.
 * list item | justified paragraph | heading | table cell | single row | paragraph | empty
 */
function blockKind(box, words, pageInfo, neighbours) {
    if (!words.length) return 'empty/graphic';
    const rows = rowsOf(words);
    const first = rows[0].words[0].t;
    const sizes = words.map((w) => w.size).sort((a, b) => a - b);
    const size = sizes[Math.floor(sizes.length / 2)];
    const bold = words.filter((w) => /bold|black|heavy|semibold/i.test(w.font)).length > words.length / 2;
    const bandOverlap = (a, b) => Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
    const sideBySide = neighbours.filter((n) => bandOverlap(n.pt, box.pt) > Math.min(n.pt.h, box.pt.h) * 0.5
        && (n.pt.x >= box.pt.x + box.pt.w - 2 || n.pt.x + n.pt.w <= box.pt.x + 2)).length;
    if (BULLET.test(first) || (rows.length > 1 && rows[1].x0 - rows[0].x0 > 6 && rows.slice(1).every((r) => Math.abs(r.x0 - rows[1].x0) < 1.5) && /^[\W\d]/.test(first))) return 'list item';
    if (rows.length >= 3) {
        const rights = rows.slice(0, -1).map((r) => r.x1);
        if (Math.max(...rights) - Math.min(...rights) < 1.2 && rows[rows.length - 1].x1 < Math.max(...rights) - 4) return 'justified paragraph';
    }
    if (rows.length <= 2 && pageInfo && (size >= pageInfo.median_size * 1.25 || (bold && words.length <= 12))) return 'heading';
    if (sideBySide >= 2 && rows.length <= 3 && box.pt.w < (pageInfo?.w || 600) * 0.3) return 'table cell';
    if (rows.length === 1) return 'single row';
    if (rows.length >= 2 && rows[1].x0 - rows[0].x0 > 6) return 'hanging indent';
    return 'paragraph';
}

function actionLabel(step) {
    if (!step) return '?';
    switch (step.op) {
        case 'enter': case 'openClose': return `${step.op}(${step.via})`;
        case 'click': case 'dblclick': return `${step.op}@${step.at?.kind}`;
        case 'type': return 'type';
        case 'clickText': return `clickText(${step.pos || 'before'})`;
        case 'key': return `key:${step.key}`;
        case 'format': return `format:${step.control}`;
        case 'exit': return `exit(${step.via})`;
        default: return step.op;
    }
}

/** Coarse locus from the violation's own numbers. */
function locus(v) {
    const d = v.data || {};
    switch (v.inv) {
        case 'I1': {
            if (d.persistedChanged || d.savedChange) return 'dirty on open/close (saves without typing)';
            if (d.phase === 'close') return d.boxChanged ? 'close: box geometry changes' : 'close: rows re-laid out';
            if (d.phase === 'caret') return d.boxHeightDeltaPt > 0.5 ? 'caret/selection re-wraps rows (box height)' : 'caret/selection moves words';
            const c = d.cmp || {};
            if (d.rowOriginMaxDy != null && Math.abs(d.rowOriginMaxDy) > d.tolPx && d.uniformDy) return 'open: uniform vertical shift (box origin / row pitch)';
            if (d.rowOriginMaxDy != null && Math.abs(d.rowOriginMaxDy) > d.tolPx) return 'open: row pitch / baselines';
            if (d.hangingDx) return 'open: row x-origin (hanging indent / bullet)';
            if (d.rowOriginMaxDx != null && Math.abs(d.rowOriginMaxDx) > d.tolPx) return 'open: row x-origin';
            if (c.moved) return 'open: intra-row spacing (justification / font advance)';
            return 'open: text mismatch';
        }
        case 'I2': if (d.kind === 'vanished') return 'block text vanishes while typing';
            return d.kind === 'restore' ? 'typed-then-deleted text not restored' : (d.delta > 0 ? 'keystroke duplicates text' : d.delta < 0 ? 'keystroke loses text' : 'keystroke changes other text');
        case 'I3': if (d.boxHeightChanged) return 'row fit: box height changes on keystroke';
            if (d.otherRowsMoved && d.pullUp) return "row fit: delete pulls the next row's word up (re-wrap)";
            if (d.otherRowsMoved) return 'row fit: other rows re-flow on keystroke';
            return 'row fit: words before the caret move';
        case 'I4': return `neighbour ${d.what || 'changed'}`;
        case 'I5': return `session drift: ${(d.drift || []).join('+') || 'state'}`;
        case 'I6': return d.notRendered ? 'moved text not rendered' : 'mask: leftover glyph pixels';
        case 'I7': if (d.styleKinds) {
                const k = d.styleKinds.split('+');
                if (k.includes('row')) return 'exporter style: row placement (empty row / break lost)';
                return `exporter style: ${k.filter((x) => x !== 'row').sort().join('+')} differs from editor`;
            }
            if (d.pageCountChanged) return 'exporter: page count';
            if ((d.rowOriginBad || []).length) return 'exporter edited-row origin';
            if ((d.untouchedChanged || []).length) return 'exporter: untouched row changed';
            if ((d.extraLines || []).length) return 'exporter: extra ink outside block';
            if (d.textMatch === false) return 'exporter: edited text != editor text (row split/merge)';
            if ((d.colourLost || []).length) return 'exporter: colour of unchanged spans lost';
            return 'exporter';
        case 'I8': return d.textChanged ? 'reload: text changes' : 'reload: re-layout after reload';
        default: return v.inv;
    }
}

function signatureOf(v, kind) {
    return `${v.inv}|${kind}|${actionLabel(v.step)}|${locus(v)}`;
}

function slug(sig) { return sig.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase().slice(0, 90); }

module.exports = { rowsOf, blockKind, actionLabel, locus, signatureOf, slug };
