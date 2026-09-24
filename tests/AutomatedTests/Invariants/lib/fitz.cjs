/** Node wrapper around fitz_tools.py (PyMuPDF). */
const path = require('path');
const { spawnSync } = require('child_process');
const { PYTHON } = require('./harness.cjs');

const TOOL = path.join(__dirname, 'fitz_tools.py');
const cache = new Map();

function run(args) {
    const res = spawnSync(PYTHON, [TOOL, ...args.map(String)], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
    if (res.status !== 0) throw new Error(`fitz_tools ${args[0]} failed: ${(res.stderr || '').slice(-800)}`);
    return JSON.parse(res.stdout);
}

/** Words of one page (pt, displayed space), cached per file+page. */
function pageWords(pdf, pageNo) {
    const key = `${pdf}#${pageNo}`;
    if (!cache.has(key)) cache.set(key, run(['words', pdf, pageNo]));
    return cache.get(key);
}

function render(pdf, pageNo, rectPt, png, scale) { return run(['render', pdf, pageNo, JSON.stringify(rectPt), png, scale]); }
function bgcheck(pdf, pageNo, oldRect, exclude, shot, scale, redactWords) {
    return run(['bgcheck', pdf, pageNo, JSON.stringify(oldRect), JSON.stringify(exclude), shot, scale, JSON.stringify(redactWords)]);
}
function exportdiff(base, edited, specPath) { return run(['exportdiff', base, edited, specPath]); }
function inkcount(png, threshold = 140) { return run(['inkcount', png, threshold]); }
function stylewords(pdf, pageNo, rectPt) { return run(['stylewords', pdf, pageNo, JSON.stringify(rectPt)]); }

module.exports = { pageWords, render, bgcheck, exportdiff, inkcount, stylewords };
