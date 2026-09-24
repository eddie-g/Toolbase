/**
 * Delta-debugging shrinker: reduce a failing step list to the shortest one
 * that still violates the SAME invariant on the SAME block (so the same block
 * kind), re-running every candidate on a fresh upload.
 */
const fs = require('fs');
const path = require('path');
const { runSequence } = require('./session.cjs');

/**
 * @param failing  result object from runSequence (with violations[0])
 * @param opts     { outDir, budget (max trials), invariants, log }
 */
async function shrink(failing, opts) {
    const v = failing.violations[0];
    const target = { inv: v.inv, kind: failing.block.kind };
    let steps = failing.steps.slice(0, v.stepIndex + 1);
    const budget = opts.budget ?? 16;
    let trials = 0;
    const history = [];
    const test = async (candidate) => {
        if (trials >= budget) return false;
        trials += 1;
        const dir = path.join(opts.outDir, `trial${trials}`);
        const res = await runSequence({ pdf: failing.pdf, seed: failing.seed, steps: candidate, block: failing.block.ref, invariants: [target.inv], outDir: dir, baselineDir: opts.baselineDir });
        const hit = res.violations.find((x) => x.inv === target.inv);
        const ok = !!hit && res.block.kind === target.kind;
        history.push({ trial: trials, steps: candidate.length, reproduced: ok, signature: hit?.signature || null, error: res.error || null });
        if (opts.log) opts.log(`shrink trial ${trials}: ${candidate.length} steps -> ${ok ? 'still fails' : 'passes'}`);
        if (ok) { failing = res; return true; }
        fs.rmSync(dir, { recursive: true, force: true });
        return false;
    };
    // 0) the prefix up to the failing step must still fail (flakiness gate)
    if (!(await test(steps))) {
        return { minimal: null, flaky: true, trials, history, target };
    }
    steps = failing.steps.slice(0, failing.violations.find((x) => x.inv === target.inv).stepIndex + 1);
    // 1) ddmin: drop chunks, then single steps
    let n = 2;
    while (steps.length >= 2 && trials < budget) {
        const size = Math.ceil(steps.length / n);
        let reduced = false;
        for (let i = 0; i < steps.length && trials < budget; i += size) {
            const candidate = [...steps.slice(0, i), ...steps.slice(i + size)];
            if (!candidate.length) continue;
            if (await test(candidate)) {
                const hitIdx = failing.violations.find((x) => x.inv === target.inv).stepIndex;
                steps = failing.steps.slice(0, hitIdx + 1);
                n = Math.max(n - 1, 2);
                reduced = true;
                break;
            }
        }
        if (!reduced) {
            if (n >= steps.length) break;
            n = Math.min(steps.length, n * 2);
        }
    }
    // 2) confirm once more
    // 2) one confirmation run of the minimal sequence (always allowed, outside the budget)
    const used = trials;
    trials = Math.min(trials, budget - 1);
    const confirmed = await test(steps);
    trials = used + 1;
    return { minimal: steps, result: failing, confirmed, trials, history, target };
}

/** Standalone repro script replaying the minimal sequence with real input. */
function writeRepro(file, info) {
    const lib = path.resolve(__dirname, 'session.cjs');
    const body = `#!/usr/bin/env node
/**
 * Repro for signature: ${info.signature}
 * PDF: ${info.pdf}
 * Block: ${JSON.stringify(info.block)} (kind: ${info.kind})
 * Found by seed ${info.seed} (bundle ${info.bundle}); minimal sequence of ${info.steps.length} steps.
 *
 * Replays the steps with real mouse/keyboard input on a FRESH upload and checks
 * invariant ${info.inv}. Exit code 1 = the invariant is still violated (bug present),
 * 0 = it holds (fixed or flaky). Usage: node ${path.basename(file)} [--verbose]
 */
const path = require('path');
const { runSequence } = require(${JSON.stringify(lib)});
const steps = ${JSON.stringify(info.steps, null, 1)};
(async () => {
    const res = await runSequence({
        pdf: ${JSON.stringify(info.pdf)}, seed: ${info.seed}, steps, block: ${JSON.stringify(info.block)},
        invariants: [${JSON.stringify(info.inv)}], outDir: path.join(__dirname, 'repro-run-${info.slug}'), verbose: process.argv.includes('--verbose'),
    });
    const v = res.violations.find((x) => x.inv === ${JSON.stringify(info.inv)});
    if (res.error) console.log('ERROR', res.error);
    if (v) {
        console.log('FAIL ${info.inv} (' + v.signature + '): ' + v.message);
        console.log(JSON.stringify(v.data, null, 1).slice(0, 4000));
        process.exit(1);
    }
    console.log('PASS: invariant ${info.inv} holds on this sequence (bundle ' + res.bundleEnd + ')');
    process.exit(0);
})().catch((e) => { console.error(e); process.exit(2); });
`;
    fs.writeFileSync(file, body);
    fs.chmodSync(file, 0o755);
    return file;
}

module.exports = { shrink, writeRepro };
