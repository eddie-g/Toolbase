// Fails the build when the editor's first load grows past its budget.
//
// First load = the entry plus everything it imports statically (from Vite's
// manifest). Dynamic imports (html2canvas, anything split off later) and the
// pdf.js worker, which the browser fetches in parallel off the main thread, are
// reported but not counted. Sizes are gzip, which is what travels.
//
// usage: node scripts/check-bundle-budget.mjs [--json]
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const BUILD_DIR = 'public/build';
const BUDGETS = [
    // entry                                   JS gzip     CSS gzip
    { entry: 'resources/js/edit-new-pdfjs/main.js', css: 'resources/css/edit-new-pdfjs/index.css', jsKb: 400, cssKb: 60 },
];

const manifestPath = path.join(BUILD_DIR, 'manifest.json');
if (!fs.existsSync(manifestPath)) {
    console.error(`No ${manifestPath}: run "vite build" first.`);
    process.exit(2);
}
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const gzipKb = (file) => zlib.gzipSync(fs.readFileSync(path.join(BUILD_DIR, file)), { level: 9 }).length / 1024;
const rawKb = (file) => fs.statSync(path.join(BUILD_DIR, file)).size / 1024;

function staticClosure(key, seen = new Set()) {
    if (seen.has(key) || !manifest[key]) return seen;
    seen.add(key);
    for (const imported of manifest[key].imports || []) staticClosure(imported, seen);
    return seen;
}

let failed = false;
const report = [];
for (const budget of BUDGETS) {
    if (!manifest[budget.entry]) {
        console.error(`Entry ${budget.entry} is not in the manifest.`);
        failed = true;
        continue;
    }
    const chunks = [...staticClosure(budget.entry)].map((key) => manifest[key].file).filter((file) => file.endsWith('.js'));
    const lazy = [...new Set([...staticClosure(budget.entry)].flatMap((key) => manifest[key].dynamicImports || []))]
        .map((key) => manifest[key]?.file).filter(Boolean);
    const js = chunks.reduce((sum, file) => sum + gzipKb(file), 0);
    const css = manifest[budget.css] ? gzipKb(manifest[budget.css].file) : 0;
    const line = {
        entry: budget.entry,
        firstLoadJsGzipKb: Math.round(js),
        jsBudgetKb: budget.jsKb,
        cssGzipKb: Math.round(css),
        cssBudgetKb: budget.cssKb,
        chunks: chunks.map((file) => ({ file, rawKb: Math.round(rawKb(file)), gzipKb: Math.round(gzipKb(file)) })),
        lazy: lazy.map((file) => ({ file, gzipKb: Math.round(gzipKb(file)) })),
    };
    report.push(line);
    if (js > budget.jsKb || css > budget.cssKb) failed = true;
}

if (process.argv.includes('--json')) {
    console.log(JSON.stringify(report, null, 2));
} else {
    for (const line of report) {
        const mark = (value, max) => (value > max ? 'OVER' : 'ok');
        console.log(`${line.entry}`);
        console.log(`  first-load JS  ${line.firstLoadJsGzipKb} KB gzip of ${line.jsBudgetKb} KB  [${mark(line.firstLoadJsGzipKb, line.jsBudgetKb)}]`);
        for (const chunk of line.chunks) console.log(`    ${chunk.file}  ${chunk.gzipKb} KB gzip (${chunk.rawKb} KB)`);
        console.log(`  CSS            ${line.cssGzipKb} KB gzip of ${line.cssBudgetKb} KB  [${mark(line.cssGzipKb, line.cssBudgetKb)}]`);
        for (const chunk of line.lazy) console.log(`  loaded on demand: ${chunk.file}  ${chunk.gzipKb} KB gzip`);
    }
}
if (failed) {
    console.error('\nBundle budget exceeded. Split the new code off with a dynamic import(), or raise the budget in scripts/check-bundle-budget.mjs on purpose.');
    process.exit(1);
}
