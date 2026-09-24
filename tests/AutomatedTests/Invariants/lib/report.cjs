/** summary.json + summary.md from the per-job results. */
const fs = require('fs');
const path = require('path');

const INV_NAMES = {
    I1: 'Open/close no-op', I2: 'Keystroke integrity', I3: 'Row locality', I4: 'Neighbour isolation',
    I5: 'Session stability', I6: 'Move cleanliness', I7: 'Download fidelity', I8: 'Reload fidelity',
};

function summarize(results, meta, shrinks = {}) {
    const corpus = new Map((meta.corpus || []).map((c) => [path.resolve(c.path), c]));
    const sigs = {};
    const perPdf = {};
    const perInv = {};
    const checks = {};
    const perClass = {};
    const bundles = new Set();
    let errors = 0;
    let skipped = 0;
    const i7 = {};
    for (const r of results) {
        const pdfKey = path.basename(r.pdf);
        const classes = corpus.get(path.resolve(r.pdf))?.classes || [];
        const p = perPdf[pdfKey] || (perPdf[pdfKey] = { pdf: r.pdf, classes, jobs: 0, failedJobs: 0, errors: 0, skipped: null, byInvariant: {}, signatures: {}, errorSamples: [] });
        p.jobs += 1;
        if (r.skipped) { p.skipped = r.skipped; skipped += 1; continue; }
        if (r.bundleStart) bundles.add(r.bundleStart);
        if (r.bundleEnd) bundles.add(r.bundleEnd);
        if (r.error || r.stepError) { p.errors += 1; errors += 1; if (p.errorSamples.length < 3) p.errorSamples.push({ seed: r.seed, error: (r.error || JSON.stringify(r.stepError)).split('\n')[0].slice(0, 200) }); }
        for (const [k, n] of Object.entries(r.checks || {})) checks[k] = (checks[k] || 0) + n;
        if (r.i7Geometry) {
            const g = r.i7Geometry;
            const q = i7[pdfKey] || (i7[pdfKey] = { downloads: 0, rows: 0, editedRows: 0, badRows: 0, maxAbsDx: 0, maxAbsDy: 0, maxAbsPrefixDx: 0, worstSeed: null });
            q.downloads += 1; q.rows += g.rows; q.editedRows += g.edited; q.badRows += g.bad;
            if (Math.max(g.maxAbsDx, g.maxAbsDy, g.maxAbsPrefixDx) > Math.max(q.maxAbsDx, q.maxAbsDy, q.maxAbsPrefixDx)) q.worstSeed = r.seed;
            q.maxAbsDx = Math.max(q.maxAbsDx, g.maxAbsDx); q.maxAbsDy = Math.max(q.maxAbsDy, g.maxAbsDy); q.maxAbsPrefixDx = Math.max(q.maxAbsPrefixDx, g.maxAbsPrefixDx);
        }
        // runs made before the empty-word-list guard: an I1 open verdict on 0 measurable words is no verdict
        r.violations = (r.violations || []).filter((v) => !(v.inv === 'I1' && v.data?.phase === 'open' && (!v.data.refWords || !v.data.gotWords)));
        if (r.violations.length) p.failedJobs += 1;
        for (const v of r.violations || []) {
            perInv[v.inv] = (perInv[v.inv] || 0) + 1;
            p.byInvariant[v.inv] = (p.byInvariant[v.inv] || 0) + 1;
            p.signatures[v.signature] = (p.signatures[v.signature] || 0) + 1;
            for (const c of classes) perClass[c] = (perClass[c] || 0) + 1;
            const s = sigs[v.signature] || (sigs[v.signature] = {
                signature: v.signature, invariant: v.inv, invariantName: INV_NAMES[v.inv], kind: v.kind, locus: v.locus,
                count: 0, pdfs: {}, first: null, bundles: {},
            });
            s.count += 1;
            s.pdfs[pdfKey] = (s.pdfs[pdfKey] || 0) + 1;
            s.bundles[v.bundle] = (s.bundles[v.bundle] || 0) + 1;
            if (!s.first || r.seed < s.first.seed) {
                s.first = { pdf: r.pdf, seed: r.seed, block: r.block, stepIndex: v.stepIndex, step: v.step, message: v.message, data: v.data, shots: v.shots, jobDir: r.jobDir, bundle: v.bundle };
            }
        }
    }
    for (const s of Object.values(sigs)) {
        const sh = shrinks[s.signature];
        if (sh) s.shrink = sh;
    }
    const ordered = Object.values(sigs).sort((a, b) => b.count - a.count);
    // families: invariant + locus (the action and block kind vary for one underlying bug)
    const fam = {};
    for (const sg of ordered) {
        const key = `${sg.invariant}|${sg.locus}`;
        const f = fam[key] || (fam[key] = { family: key, invariant: sg.invariant, locus: sg.locus, count: 0, signatures: 0, kinds: {}, actions: {}, pdfs: {}, example: sg.signature, repro: null });
        f.count += sg.count; f.signatures += 1;
        f.kinds[sg.kind] = (f.kinds[sg.kind] || 0) + sg.count;
        const act = sg.signature.split('|')[2];
        f.actions[act] = (f.actions[act] || 0) + sg.count;
        for (const [k, n] of Object.entries(sg.pdfs)) f.pdfs[k] = (f.pdfs[k] || 0) + n;
        if (!f.repro && sg.shrink?.repro) f.repro = sg.shrink.repro;
    }
    const families = Object.values(fam).sort((a, b) => b.count - a.count);
    return {
        run: meta.run, startedAt: meta.startedAt, finishedAt: new Date().toISOString(), args: meta.args, tolerances: meta.tolerances,
        bundles: [...bundles], jobs: results.length, jobsWithViolations: results.filter((r) => r.violations?.length).length, errors, skipped,
        checksRun: checks, violationsPerInvariant: perInv, violationsPerClass: perClass,
        families, signatures: ordered, perPdf, i7Geometry: i7,
    };
}

function md(summary) {
    const L = [];
    L.push(`# Invariant run ${summary.run}`);
    L.push('');
    L.push(`${summary.jobs} sequences, ${summary.jobsWithViolations} with a violation, ${summary.errors} errored, ${summary.skipped} skipped (editing unavailable by design). Editor bundle(s): ${summary.bundles.join(', ')}.`);
    L.push(`Args: \`${summary.args}\``);
    L.push('');
    L.push('## Per invariant');
    L.push('');
    L.push('| invariant | checks run | violations |');
    L.push('|---|---|---|');
    for (const k of Object.keys(INV_NAMES)) L.push(`| ${k} ${INV_NAMES[k]} | ${summary.checksRun[k] || 0} | ${summary.violationsPerInvariant[k] || 0} |`);
    L.push('');
    L.push(`## Signature families (${summary.families.length}): invariant + locus`);
    L.push('');
    L.push('| family | count | signatures | block kinds | actions | PDFs | a repro |');
    L.push('|---|---|---|---|---|---|---|');
    const top = (o, n = 4) => Object.entries(o).sort((a, b) => b[1] - a[1]).slice(0, n).map(([k, v]) => `${k} (${v})`).join(', ');
    for (const f of summary.families) L.push(`| ${f.family} | ${f.count} | ${f.signatures} | ${top(f.kinds)} | ${top(f.actions)} | ${Object.keys(f.pdfs).length}: ${top(f.pdfs, 3)} | ${f.repro ? path.basename(f.repro) : ''} |`);
    L.push('');
    L.push(`## Distinct signatures (${summary.signatures.length})`);
    L.push('');
    for (const s of summary.signatures) {
        const f = s.first;
        L.push(`### ${s.signature}`);
        L.push('');
        L.push(`- count ${s.count}; PDFs: ${Object.entries(s.pdfs).map(([k, n]) => `${k} (${n})`).join(', ')}; bundles: ${Object.keys(s.bundles).join(', ')}`);
        L.push(`- first: seed ${f.seed} on \`${path.basename(f.pdf)}\`, block \`${f.block?.id}\` p${f.block?.page} (${f.block?.kind}) "${(f.block?.text || '').slice(0, 70)}"`);
        L.push(`- step ${f.stepIndex}: \`${JSON.stringify(f.step)}\``);
        L.push(`- ${f.message}`);
        if (s.shrink) {
            L.push(`- minimal repro: ${s.shrink.minimal ? `${s.shrink.minimal.length} steps, \`${s.shrink.repro}\` (confirmed: ${s.shrink.confirmed})` : `not reproduced on a fresh upload (flaky) after ${s.shrink.trials} trials`}`);
        }
        const shots = Object.entries(f.shots || {}).map(([k, v]) => `[${k}](${v})`).join(' ');
        if (shots) L.push(`- screenshots: ${shots}`);
        L.push('');
    }
    L.push('## Per PDF');
    L.push('');
    L.push('| PDF | classes | jobs | with violation | errors | by invariant | note |');
    L.push('|---|---|---|---|---|---|---|');
    for (const [k, p] of Object.entries(summary.perPdf).sort((a, b) => b[1].failedJobs - a[1].failedJobs)) {
        L.push(`| ${k} | ${p.classes.join(', ')} | ${p.jobs} | ${p.failedJobs} | ${p.errors} | ${Object.entries(p.byInvariant).map(([i, n]) => `${i}:${n}`).join(' ')} | ${p.skipped ? `skipped: ${p.skipped}` : p.errorSamples.map((e) => `s${e.seed}: ${e.error.slice(0, 80)}`).join('; ')} |`);
    }
    L.push('');
    if (Object.keys(summary.i7Geometry || {}).length) {
        L.push('## I7 edited-row geometry (download vs untouched download, pt)');
        L.push('');
        L.push('| PDF | downloads | block rows | edited rows | rows off origin | max abs dx | max abs dy | max abs prefix dx | worst seed |');
        L.push('|---|---|---|---|---|---|---|---|---|');
        for (const [k, q] of Object.entries(summary.i7Geometry)) L.push(`| ${k} | ${q.downloads} | ${q.rows} | ${q.editedRows} | ${q.badRows} | ${q.maxAbsDx} | ${q.maxAbsDy} | ${q.maxAbsPrefixDx} | ${q.worstSeed ?? ''} |`);
        L.push('');
    }
    L.push('## Violations per PDF class');
    L.push('');
    for (const [c, n] of Object.entries(summary.violationsPerClass).sort((a, b) => b[1] - a[1])) L.push(`- ${c}: ${n}`);
    L.push('');
    return L.join('\n');
}

function write(outDir, results, meta, shrinks) {
    const s = summarize(results, meta, shrinks);
    fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify(s, null, 1));
    fs.writeFileSync(path.join(outDir, 'summary.md'), md(s));
    return s;
}

module.exports = { summarize, md, write, INV_NAMES };
