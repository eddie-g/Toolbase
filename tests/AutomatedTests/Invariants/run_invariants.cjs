#!/usr/bin/env node
/**
 * Randomized real-input invariant tester for the pdf.js editor.
 *
 *   node run_invariants.cjs --corpus corpus.json --seeds 1-50 --steps 12 --out results/<name>
 *        [--pdf <path>] [--block <id|page:textPrefix>] [--invariants 1,2,3] [--shrink]
 *        [--quick] [--workers 2] [--minutes 80] [--keep-going] [--shrink-budget 14] [--verbose]
 *
 * Every (pdf, seed) job uploads a fresh copy of the PDF as a guest document,
 * picks a block and an action sequence from the seed, drives the editor with
 * real mouse/keyboard input and checks the invariants after every step (see
 * README.md). Results: <out>/jobs/<pdf>/seed-<n>/result.json (+ screenshots),
 * <out>/summary.json and <out>/summary.md; with --shrink, minimal repro
 * scripts in <out>/repros/.
 */
const fs = require('fs');
const path = require('path');
const { runSequence, TOL } = require('./lib/session.cjs');
const { generate } = require('./lib/generator.cjs');
const { rng } = require('./lib/prng.cjs');
const { shrink, writeRepro } = require('./lib/shrink.cjs');
const report = require('./lib/report.cjs');
const S = require('./lib/signature.cjs');
const H = require('./lib/harness.cjs');

function parseArgs(argv) {
    const a = { corpus: path.join(__dirname, 'corpus.json'), seeds: '1-5', steps: 12, out: null, pdf: null, block: null, invariants: null, shrink: false, quick: false, workers: 2, minutes: null, keepGoing: false, shrinkBudget: 14, verbose: false, maxPdfs: null, script: null };
    for (let i = 0; i < argv.length; i += 1) {
        const k = argv[i]; const v = argv[i + 1];
        switch (k) {
            case '--corpus': a.corpus = v; i += 1; break;
            case '--seeds': a.seeds = v; i += 1; break;
            case '--steps': a.steps = Number(v); i += 1; break;
            case '--out': a.out = v; i += 1; break;
            case '--pdf': a.pdf = v; i += 1; break;
            case '--block': a.block = v; i += 1; break;
            case '--invariants': a.invariants = v.split(',').map((x) => (x.startsWith('I') ? x : `I${x}`)); i += 1; break;
            case '--shrink': a.shrink = true; break;
            case '--quick': a.quick = true; break;
            case '--workers': a.workers = Number(v); i += 1; break;
            case '--minutes': a.minutes = Number(v); i += 1; break;
            case '--keep-going': a.keepGoing = true; break;
            case '--shrink-budget': a.shrinkBudget = Number(v); i += 1; break;
            case '--max-pdfs': a.maxPdfs = Number(v); i += 1; break;
            case '--verbose': a.verbose = true; break;
            case '--script': a.script = v; i += 1; break;
            case '-h': case '--help': console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0]); process.exit(0); break;
            default: throw new Error(`unknown argument ${k}`);
        }
    }
    return a;
}

function seedList(spec) {
    const out = [];
    for (const part of String(spec).split(',')) {
        const [lo, hi] = part.split('-').map(Number);
        for (let s = lo; s <= (hi || lo); s += 1) out.push(s);
    }
    return out;
}

/**
 * --script: a deterministic sequence instead of a random one (regression mode).
 *   type-before:<word>:<text>   open the block (menu), real click just before the first word starting
 *   type-after:<word>:<text>    with <word> (or just after it), type <text>, click outside, Download PDF.
 *   type-at:<text>              same, at a seeded caret point (row/word from the seed): an I7 sweep across a corpus.
 *   <steps>.json                a JSON array of generator steps.
 */
function scriptSteps(spec, seed = 1) {
    if (fs.existsSync(spec)) return JSON.parse(fs.readFileSync(spec, 'utf8'));
    const at = spec.match(/^type-at:(.*)$/s);
    if (at) {
        // seeded caret point (row/word fractions from the seed), then the same edit + download
        const r = rng(seed, 3);
        return [
            { op: 'enter', via: 'menu' },
            { op: 'click', at: { kind: r.pick(['wordStart', 'wordEnd', 'between', 'mid']), row: r.frac(), word: r.frac() } },
            { op: 'type', text: at[1] },
            { op: 'exit', via: 'outside' },
            { op: 'download' },
        ];
    }
    const m = spec.match(/^type-(before|after):([^:]+):(.*)$/s);
    if (!m) throw new Error(`bad --script ${spec}`);
    return [
        { op: 'enter', via: 'menu' },
        { op: 'clickText', needle: m[2], pos: m[1] },
        { op: 'type', text: m[3] },
        { op: 'exit', via: 'outside' },
        { op: 'download' },
    ];
}

/** --block: "promoted_2_14" (page from the id) or "2:Atomic: A test" (page:text prefix). */
function blockRef(spec) {
    if (!spec) return null;
    const m = spec.match(/^promoted_(\d+)_/);
    if (m) return { page: Number(m[1]), index: -1, textPrefix: '', promoted: true, id: spec };
    const [page, ...rest] = spec.split(':');
    return { page: Number(page), index: -1, textPrefix: rest.join(':'), promoted: true };
}

/** --resummarize <dir>: rebuild summary.json/md from every jobs/<pdf>/seed-<n>/result.json (e.g. after a report change). */
function resummarize(dir) {
    const results = [];
    const walk = (d) => { for (const e of fs.readdirSync(d, { withFileTypes: true })) { const f = path.join(d, e.name); if (e.isDirectory()) walk(f); else if (e.name === 'result.json') results.push(JSON.parse(fs.readFileSync(f, 'utf8'))); } };
    walk(path.join(dir, 'jobs'));
    const meta = JSON.parse(fs.readFileSync(path.join(dir, 'meta.json'), 'utf8'));
    let shrinks = {};
    if (fs.existsSync(path.join(dir, 'shrinks.json'))) shrinks = JSON.parse(fs.readFileSync(path.join(dir, 'shrinks.json'), 'utf8'));
    else if (fs.existsSync(path.join(dir, 'summary.json'))) for (const sg of JSON.parse(fs.readFileSync(path.join(dir, 'summary.json'), 'utf8')).signatures || []) if (sg.shrink) shrinks[sg.signature] = sg.shrink;
    const s = report.write(dir, results, meta, shrinks);
    console.error(`resummarized ${results.length} results, ${s.signatures.length} signatures -> ${path.join(dir, 'summary.md')}`);
}

(async () => {
    if (process.argv[2] === '--resummarize') { resummarize(path.resolve(process.argv[3])); return; }
    const args = parseArgs(process.argv.slice(2));
    const run = args.out ? path.basename(args.out) : `run-${new Date().toISOString().replace(/[:.]/g, '-')}`;
    const outDir = path.resolve(args.out || path.join(__dirname, 'results', run));
    fs.mkdirSync(outDir, { recursive: true });
    const corpusDoc = fs.existsSync(args.corpus) ? JSON.parse(fs.readFileSync(args.corpus, 'utf8')) : { pdfs: [] };
    let pdfs = args.pdf ? [{ path: path.resolve(args.pdf), classes: [] }] : corpusDoc.pdfs.filter((p) => fs.existsSync(p.path));
    let seeds = seedList(args.seeds);
    if (args.quick) {
        // 5 PDFs x 5 seeds, class-diverse: the fixed two + three others with the most distinct classes
        const pick = pdfs.filter((p) => p.quick);
        pdfs = pdfs.filter((p) => !(p.classes.length === 1 && p.classes[0] === 'rotated')); // editing is blocked on rotated pages by design
        for (const p of pdfs) {
            if (pick.length >= 5) break;
            const have = new Set(pick.flatMap((q) => q.classes));
            if (pick.includes(p)) continue;
            if (pick.length < 2 || p.classes.some((c) => !have.has(c))) pick.push(p);
        }
        for (const p of pdfs) { if (pick.length >= 5) break; if (!pick.includes(p)) pick.push(p); }
        pdfs = pick.slice(0, 5);
        seeds = seeds.length >= 5 && args.seeds !== '1-5' ? seeds.slice(0, 5) : [1, 2, 3, 4, 5];
        if (!args.minutes) args.minutes = 11;
        if (args.steps === 12) args.steps = 10;
        if (args.workers === 2) args.workers = 3;
    }
    if (args.maxPdfs) pdfs = pdfs.slice(0, args.maxPdfs);
    const fixedSteps = args.script ? scriptSteps(args.script) : null;
    if (fixedSteps && args.seeds === '1-5' && !args.script.startsWith('type-at:')) seeds = [1];
    const block = blockRef(args.block);
    const meta = { run, startedAt: new Date().toISOString(), args: process.argv.slice(2).join(' '), tolerances: TOL, corpus: pdfs };
    fs.writeFileSync(path.join(outDir, 'meta.json'), JSON.stringify(meta, null, 1));
    // job order: seed-major so every PDF gets coverage early under a time budget
    const jobs = [];
    for (const seed of seeds) for (const p of pdfs) jobs.push({ pdf: p.path, seed });
    const deadline = args.minutes ? Date.now() + args.minutes * 60000 : Infinity;
    const results = [];
    const log = (...m) => console.error(`[${new Date().toISOString().slice(11, 19)}]`, ...m);
    log(`${jobs.length} jobs, ${args.workers} workers, ${args.steps} steps, invariants ${args.invariants ? args.invariants.join(',') : 'all'}, out ${outDir}, bundle ${H.bundleName()}`);
    let next = 0;
    const skippedPdfs = new Map();
    const worker = async (w) => {
        while (next < jobs.length && Date.now() < deadline) {
            const job = jobs[next]; next += 1;
            if (skippedPdfs.has(job.pdf)) continue;
            const jobDir = path.join(outDir, 'jobs', path.basename(job.pdf).replace(/\.pdf$/i, '').replace(/[^\w.-]+/g, '_'), `seed-${job.seed}`);
            const steps = fixedSteps ? scriptSteps(args.script, job.seed) : generate(job.seed, args.steps);
            const res = await runSequence({ pdf: job.pdf, seed: job.seed, steps, stepCount: args.steps, block, invariants: args.invariants, outDir: jobDir, baselineDir: path.join(outDir, 'baselines'), keepGoing: args.keepGoing, verbose: args.verbose });
            res.jobDir = jobDir;
            fs.writeFileSync(path.join(jobDir, 'result.json'), JSON.stringify(res, null, 1));
            results.push(res);
            if (res.skipped) skippedPdfs.set(job.pdf, res.skipped);
            const v = res.violations.map((x) => x.signature).join(' ; ');
            log(`w${w} ${path.basename(job.pdf)} seed ${job.seed}: ${res.skipped ? `SKIPPED ${res.skipped} (remaining seeds of this PDF skipped)` : res.error ? `ERROR ${res.error.split('\n')[0].slice(0, 120)}` : v || `ok (${res.executed}/${steps.length} steps)`} [${Math.round((res.timings?.total || 0) / 1000)}s, ${res.bundleEnd}]`);
            report.write(outDir, results, meta, {});
        }
    };
    await Promise.all(Array.from({ length: Math.max(1, args.workers) }, (_, i) => worker(i + 1)));
    if (next < jobs.length) log(`time budget reached: ${jobs.length - next} jobs not started`);
    // shrinking: one minimal repro per distinct signature (first occurrence = lowest seed)
    const shrinks = {};
    if (args.shrink) {
        const reproDir = path.join(outDir, 'repros');
        fs.mkdirSync(reproDir, { recursive: true });
        const firsts = {};
        for (const r of results) {
            const v = r.violations[0];
            if (!v) continue;
            if (!firsts[v.signature] || r.seed < firsts[v.signature].seed) firsts[v.signature] = r;
        }
        const shrinkDeadline = args.minutes ? deadline + 45 * 60000 : Infinity;
        const queue = Object.entries(firsts);
        let qi = 0;
        const sworker = async () => {
            while (qi < queue.length && Date.now() < shrinkDeadline) {
                const [sig, r] = queue[qi]; qi += 1;
                log(`shrinking ${sig} (${r.violations[0].stepIndex + 1} steps)`);
                const slug = S.slug(sig);
                const sh = await shrink(r, { outDir: path.join(outDir, 'shrink', slug), budget: args.shrinkBudget, baselineDir: path.join(outDir, 'baselines'), log });
                const entry = { minimal: sh.minimal, confirmed: sh.confirmed ?? null, trials: sh.trials, flaky: !!sh.flaky, history: sh.history };
                if (sh.minimal) {
                    const v = sh.result.violations.find((x) => x.inv === sh.target.inv);
                    entry.repro = writeRepro(path.join(reproDir, `repro-${slug}.cjs`), { signature: sig, pdf: r.pdf, block: r.block.ref, kind: r.block.kind, seed: r.seed, bundle: v?.bundle, inv: sh.target.inv, steps: sh.minimal, slug });
                    entry.minimalMessage = v?.message; entry.minimalData = v?.data; entry.minimalShots = v?.shots; entry.minimalSignature = v?.signature;
                }
                shrinks[sig] = entry;
                fs.writeFileSync(path.join(outDir, 'shrinks.json'), JSON.stringify(shrinks, null, 1));
                log(`  -> ${sh.minimal ? `${sh.minimal.length} steps (confirmed ${sh.confirmed}) ${entry.repro}` : 'flaky / not reproduced'}`);
                report.write(outDir, results, meta, shrinks);
            }
        };
        await Promise.all(Array.from({ length: Math.max(1, Math.min(args.workers, 2)) }, () => sworker()));
    }
    const summary = report.write(outDir, results, meta, shrinks);
    if (fixedSteps) {
        for (const r of results) {
            const g = r.i7Geometry;
            log(`script result ${path.basename(r.pdf)} block ${r.block?.id}: ${r.error ? `ERROR ${r.error.split('\n')[0]}` : r.stepError ? `ERROR step ${r.stepError.step} ${r.stepError.op}: ${r.stepError.error.split('\n')[0].slice(0, 200)}` : r.violations.length ? `FAIL ${r.violations.map((v) => v.message).join(' | ')}` : 'PASS'}${g ? ` [I7 rows ${g.rows}, edited ${g.edited}, max |dx| ${g.maxAbsDx}pt, |dy| ${g.maxAbsDy}pt, prefix |dx| ${g.maxAbsPrefixDx}pt]` : ''}`);
        }
        process.exitCode = results.some((r) => r.violations.length || r.error || r.stepError) ? 1 : 0;
    }
    log(`done: ${summary.jobs} jobs, ${summary.jobsWithViolations} with violations, ${summary.signatures.length} signatures -> ${path.join(outDir, 'summary.md')}`);
})().catch((e) => { console.error(e); process.exit(1); });
