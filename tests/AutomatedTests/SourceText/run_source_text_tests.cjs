/**
 * Editing Existing PDF Text — Automated Tests (Playwright + PyMuPDF)
 *
 * Automates the "[QA] - Editing existing PDF text (move, resize, edit,
 * delete)" Asana story (Netkit -> Claude QA Agent). Every case uploads one of
 * the real fixtures in tests/OverlayEditor as a disposable document, opens it
 * in the pdf.js editor with Edit PDF on, acts on the document's OWN text --
 * no Add Text, Shapes, Highlight, Draw, Sign or Image -- and then proves two
 * things about it:
 *
 *   A. In the editor: the browser shows the right thing, and the case records
 *      what it saw (geometry in PDF points, text, typography, masks).
 *   B. In the download: the PDF produced by the editor's own Download PDF path
 *      is read back with PyMuPDF (inspect_pdf.py) and compared against what A
 *      recorded -- text where A put it, in the family A used, the old place
 *      scrubbed where A showed a mask, and everything A did not touch equal to
 *      the untouched baseline download of the same document.
 *
 * Checks carry a `component` of "editor" or "download" and an item prefix of
 * "A:" / "B:" so the admin page can tell which half of a case failed.
 *
 * Usage:
 *   node run_source_text_tests.cjs --list
 *   node run_source_text_tests.cjs --run 04-move-single-line,17-delete-scrubbed
 *   node run_source_text_tests.cjs --run-all
 *
 * Output is a single JSON document on stdout so the Laravel controller can
 * parse it. Nothing else may be written to stdout.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

process.umask(0o000);

const ROOT = path.resolve(__dirname, '..', '..', '..');
const localBrowsers = path.join(ROOT, 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.AUTOMATED_TEST_BASE_URL || 'http://localhost';
const ARTIFACT_DIR = path.resolve(__dirname, 'artifacts');
const INSPECTOR = path.resolve(__dirname, 'inspect_pdf.py');
const FIXTURE_DIR = path.resolve(__dirname, '..', '..', 'OverlayEditor');

// The app resolves the same interpreter for its own PDF writes; inside the
// container no venv is active and the system python has no fitz.
const VENV_PYTHON = path.join(ROOT, '.venv', 'bin', 'python');
const PYTHON = (process.env.PYTHON_BIN && process.env.PYTHON_BIN.trim())
    || (fs.existsSync(VENV_PYTHON) ? VENV_PYTHON : 'python3');

const FIXTURES = {
    invoice: 'invoicesample.pdf',
    drylab: 'drylab.pdf',
    f1040s1: 'f1040s1.pdf',
    isartor: 'Isartor-Test-Suite4.pdf',
};

/** Font substitution rules the export applies, so "close" can mean the substitute. */
const FONT_RULES = (() => {
    try {
        const raw = JSON.parse(fs.readFileSync(path.join(ROOT, 'config', 'font_substitutes.json'), 'utf8'));
        return Array.isArray(raw?.rules) ? raw.rules : [];
    } catch (_) {
        return [];
    }
})();

const POINT_TOL = 1.0;      // text position in the download vs the editor, in PDF points
const SIZE_TOL = 0.5;       // font size in the download vs the editor, in points
const MASK_TOL_PX = 0.25;   // moved-source mask vs the immutable source bbox, in CSS px
const BACKGROUND_DARK = 0.02; // a scrubbed region's dark-pixel ratio

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

function createRecorder() {
    const checks = [];
    const push = (component, item, passed, description, detail) => {
        checks.push({
            result: passed ? 'PASS' : 'FAIL',
            component,
            item: `${component === 'editor' ? 'A' : 'B'}:${item}`,
            description,
            detail: detail == null ? '' : String(detail).slice(0, 1500),
        });
        return passed;
    };
    const scoped = (component) => ({
        assert: (item, condition, description, detail) => push(component, item, !!condition, description, detail),
        equals: (item, actual, expected, description) => push(component, item, actual === expected, description,
            actual === expected ? `= ${JSON.stringify(actual)}` : `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`),
        near: (item, actual, expected, tolerance, description) => push(component, item,
            Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance, description,
            `expected ${Number(expected).toFixed(3)} ±${tolerance}, got ${Number.isFinite(actual) ? actual.toFixed(3) : actual}`),
        skip: (item, description, detail) => {
            checks.push({ result: 'SKIP', component, item: `${component === 'editor' ? 'A' : 'B'}:${item}`, description, detail: detail == null ? '' : String(detail) });
            return false;
        },
    });
    return { checks, A: scoped('editor'), B: scoped('download') };
}

function ensureArtifactDir() {
    if (!fs.existsSync(ARTIFACT_DIR)) fs.mkdirSync(ARTIFACT_DIR, { recursive: true, mode: 0o777 });
}

async function capture(page, testId, label) {
    try {
        ensureArtifactDir();
        const filename = `${testId}__${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`;
        await page.screenshot({ path: path.join(ARTIFACT_DIR, filename), fullPage: false });
        try { fs.chmodSync(path.join(ARTIFACT_DIR, filename), 0o666); } catch (_) { /* best effort */ }
        return { label, filename };
    } catch (_) {
        return null;
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const normalize = (text) => String(text || '').replace(/\s+/g, ' ').trim();
const round = (value, places = 3) => Number(Number(value).toFixed(places));

// ---------------------------------------------------------------------------
// PyMuPDF inspection
// ---------------------------------------------------------------------------

function inspect(pdfPath, request) {
    const result = spawnSync(PYTHON, [INSPECTOR], {
        input: JSON.stringify({ pdf: pdfPath, ...request }),
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
    if (result.status !== 0) throw new Error(`inspect_pdf.py failed: ${(result.stderr || '').slice(0, 800)}`);
    return JSON.parse(result.stdout);
}

function summarize(pdfPath, queries = []) {
    return inspect(pdfPath, { summary: true, queries });
}

function query(pdfPath, queries) {
    return inspect(pdfPath, { queries }).queries;
}

const searchCount = (pdfPath, text, page = 0) => query(pdfPath, [{ kind: 'search', page, text }])[0];
const pixelsAt = (pdfPath, rect, page = 0, zoom = 4) => query(pdfPath, [{ kind: 'pixels', page, rect, zoom }])[0];
const charsAt = (pdfPath, rect, page = 0) => query(pdfPath, [{ kind: 'chars', page, rect }])[0];
const rulesAt = (pdfPath, rect, page = 0) => query(pdfPath, [{ kind: 'rules', page, rect }])[0];

/** Drop glyphs far smaller than the size under test: a strapline tucked under a display title. */
function charsFiltered(chars, sizePt) {
    if (!Number.isFinite(sizePt) || !Array.isArray(chars?.chars)) return chars;
    const kept = chars.chars.filter((ch) => ch.size >= sizePt * 0.5);
    if (kept.length === chars.chars.length) return chars;
    return {
        ...chars,
        text: kept.map((ch) => ch.c).join(''),
        count: kept.length,
        notdef: kept.filter((ch) => ch.c === '\uFFFD').length,
        fonts: Array.from(new Set(kept.map((ch) => ch.font))).sort(),
        sizes: Array.from(new Set(kept.map((ch) => ch.size))).sort((a, b) => a - b),
        colors: Array.from(new Set(kept.map((ch) => ch.color))).sort((a, b) => a - b),
        chars: kept,
    };
}

function rectsIntersect(a, b, margin = 0) {
    return a[0] < b[2] + margin && b[0] < a[2] + margin && a[1] < b[3] + margin && b[1] < a[3] + margin;
}

function rectUnion(a, b) {
    return [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.max(a[2], b[2]), Math.max(a[3], b[3])];
}

function hitsInside(search, rect, margin = 1) {
    return (search?.rects || []).filter((r) => rectsIntersect(r, rect, margin));
}

/** Text spans of a page summary that do not touch a rect -- the "background". */
function blocksOutside(pageSummary, rect, margin = 1) {
    return (pageSummary?.blocks || [])
        .flatMap((block) => block.lines.flatMap((line) => line.spans))
        .filter((span) => !rectsIntersect(span.bbox, rect, margin))
        .map((span) => ({
            text: normalize(span.text),
            bbox: span.bbox.map((v) => round(v, 1)),
            font: `${span.font}@${round(span.size, 1)}`,
            color: span.color,
        }))
        .sort((a, b) => a.bbox[1] - b.bbox[1] || a.bbox[0] - b.bbox[0]);
}

/** Every editor line appears, in order, among the download's lines (other text may sit between). */
function linesInOrder(pdfLines, editorLines) {
    let cursor = 0;
    for (const line of editorLines) {
        const index = pdfLines.indexOf(line, cursor);
        if (index < 0) return false;
        cursor = index + 1;
    }
    return editorLines.length > 0;
}

/** Search rect of a line of text nearest to a rect's top-left, for typography sampling. */
function lineHitNear(pdfPath, text, rect, pageIndex = 0) {
    const search = searchCount(pdfPath, text, pageIndex);
    return search.rects.map((r) => ({ r, d: Math.hypot(r[0] - rect[0], r[1] - rect[1]) })).sort((a, b) => a.d - b.d)[0]?.r || null;
}

function sameBlocks(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function blocksTouching(pageSummary, rect, margin = 0) {
    return (pageSummary?.blocks || []).filter((block) => rectsIntersect(block.bbox, rect, margin));
}

/** Lines (in reading order) whose centre lies inside a rect. */
function lineTextsTouching(pageSummary, rect, margin = 0) {
    const inside = (line) => {
        const cx = (line.bbox[0] + line.bbox[2]) / 2;
        const cy = (line.bbox[1] + line.bbox[3]) / 2;
        return line.bbox[0] >= rect[0] - margin - 2 && cx <= rect[2] + margin && cy >= rect[1] - margin && cy <= rect[3] + margin;
    };
    return (pageSummary?.blocks || [])
        .flatMap((block) => block.lines)
        .filter(inside)
        .sort((l, r) => l.bbox[1] - r.bbox[1] || l.bbox[0] - r.bbox[0])
        .map((line) => normalize(line.text));
}

/** The part of the old rect the new rect does not cover: where the scrub must show. */
function uncoveredPart(oldRect, newRect) {
    if (!rectsIntersect(oldRect, newRect)) return oldRect;
    const above = [oldRect[0], oldRect[1], oldRect[2], Math.min(oldRect[3], newRect[1] - 0.5)];
    const below = [oldRect[0], Math.max(oldRect[1], newRect[3] + 0.5), oldRect[2], oldRect[3]];
    const left = [oldRect[0], oldRect[1], Math.min(oldRect[2], newRect[0] - 0.5), oldRect[3]];
    const right = [Math.max(oldRect[0], newRect[2] + 0.5), oldRect[1], oldRect[2], oldRect[3]];
    const area = (r) => Math.max(0, r[2] - r[0]) * Math.max(0, r[3] - r[1]);
    return [above, below, left, right].sort((a, b) => area(b) - area(a))[0];
}

function fontRoot(name) {
    return String(name || '')
        .replace(/^[A-Z]{6}\+/, '')
        .replace(/^[A-Za-z]{6}(?=[A-Z][a-z])/, '')
        .split(',')[0]
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .replace(/(regular|roman|bold|bd|italic|it|thin|light|wght|medium|demi|black|blk|ou|\d+)+$/g, '');
}

/** Whether a font the PDF uses is the expected family or its configured substitute. */
function fontMatches(pdfFont, expectedFamily) {
    const pdfRoot = fontRoot(pdfFont);
    const expectedRoot = fontRoot(expectedFamily);
    if (!pdfRoot || !expectedRoot) return false;
    const head = (value) => value.slice(0, Math.min(5, value.length));
    if (pdfRoot.includes(head(expectedRoot)) || expectedRoot.includes(head(pdfRoot))) return true;
    const rule = FONT_RULES.find((entry) => String(expectedFamily || '').toLowerCase().includes(String(entry.match || '').toLowerCase()));
    if (rule && pdfRoot.includes(head(fontRoot(rule.family)))) return true;
    return false;
}

function isBold(pdfFont) {
    return /bold|bd|700|black|heavy|demi/i.test(String(pdfFont || ''));
}

// ---------------------------------------------------------------------------
// Document setup
// ---------------------------------------------------------------------------

async function fetchCsrfToken(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const token = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="_token"]')?.value || null);
    if (!token) throw new Error('Could not extract CSRF token from /pdf-editor');
    return token;
}

let uploadCounter = 0;

/** Upload a fixture as a disposable document and return its id. */
async function uploadFixture(page, csrfToken, fixture) {
    const file = path.join(FIXTURE_DIR, FIXTURES[fixture]);
    if (!fs.existsSync(file)) throw new Error(`fixture missing: ${file}`);
    uploadCounter += 1;
    const response = await page.request.post(`${BASE_URL}/documents`, {
        multipart: {
            _token: csrfToken,
            document: { name: `source_text_${fixture}_${process.pid}_${uploadCounter}.pdf`, mimeType: 'application/pdf', buffer: fs.readFileSync(file) },
        },
        maxRedirects: 0,
    });
    const location = response.headers()['location'] || response.url() || '';
    const match = location.match(/\/documents\/(\d+)/);
    if (!match) throw new Error(`Failed to create document (HTTP ${response.status()}): ${(await response.text().catch(() => '')).slice(0, 200)}`);
    return Number(match[1]);
}

/**
 * Edit PDF is premium. The editor reads its authentication from one data
 * attribute; the response is rewritten the way the other suites do it.
 */
async function installSignedInShim(page) {
    if (page.__sourceTextShim) return;
    page.__sourceTextShim = true;
    await page.route(/\/documents\/\d+\/edit-new/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace('data-editor-authenticated="0"', 'data-editor-authenticated="1"');
        await route.fulfill({ response, body });
    });
}

async function openEditor(page, docId) {
    await installSignedInShim(page);
    await page.goto(`${BASE_URL}/documents/${docId}/edit-new?pdfjs=1&t=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 120000 });
    await page.waitForFunction(() => !!window.__enpv?.pdfViewer, { timeout: 60000 });
    await page.evaluate(() => {
        const toggle = document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode');
        if (toggle && !document.body.classList.contains('enpv-edit-on')) toggle.click();
    });
    await page.waitForSelector('body.enpv-edit-on', { timeout: 15000 });
    await sleep(1500);
}

/**
 * Open the editor and wait until extraction has promoted the document's text
 * into source boxes. A fresh upload is extracted by a queued job, so this
 * reloads until boxes appear.
 */
async function openEditorWithBoxes(page, docId, minimum = 1, timeoutMs = 180000) {
    const deadline = Date.now() + timeoutMs;
    let found = [];
    while (Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await openEditor(page, docId);
        // eslint-disable-next-line no-await-in-loop
        found = await boxes(page);
        if (found.length >= minimum) return found;
        // eslint-disable-next-line no-await-in-loop
        await sleep(4000);
    }
    throw new Error(`Document ${docId} never promoted its text into boxes (${found.length} found)`);
}

async function setEditMode(page, on) {
    const isOn = await page.evaluate(() => document.body.classList.contains('enpv-edit-on'));
    if (isOn === on) return;
    await page.evaluate(() => (document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode'))?.click());
    await page.waitForFunction((wanted) => document.body.classList.contains('enpv-edit-on') === wanted, on, { timeout: 15000 });
    await sleep(800);
}

// ---------------------------------------------------------------------------
// Editor probes
// ---------------------------------------------------------------------------

/**
 * Every annotation box, with its geometry in CSS px relative to the page and
 * in PDF points (top-left origin, like PyMuPDF), its immutable source bbox
 * (bottom-left origin, as saved), and the typography it carries.
 */
function boxes(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('.enpv-annotation-box')).map((box) => {
        const pageDiv = box.closest('.page');
        const pageRect = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0 };
        const rect = box.getBoundingClientRect();
        const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 1;
        const content = box.querySelector('.enpv-text-content');
        const style = content ? window.getComputedStyle(content) : null;
        const d = box.dataset;
        const num = (value) => (value === undefined || value === '' ? null : Number(value));
        const pageIndex = Number.parseInt(d.pageIndex || (pageDiv?.dataset?.pageNumber ? String(Number(pageDiv.dataset.pageNumber) - 1) : '0'), 10);
        return {
            id: d.annotationId || '',
            uid: d.uid || '',
            pageIndex,
            classes: box.className.replace('enpv-annotation-box', '').trim(),
            selected: box.classList.contains('is-selected'),
            editing: box.classList.contains('is-editing'),
            promotedBlock: box.classList.contains('is-promoted-source-block'),
            userCreated: d.userCreated === '1',
            text: String(d.baseText || d.originalText || content?.textContent || '').replace(/\s+/g, ' ').trim(),
            liveText: String(content?.textContent || '').replace(/\s+/g, ' ').trim(),
            px: { left: rect.left - pageRect.left, top: rect.top - pageRect.top, width: rect.width, height: rect.height },
            scale,
            pt: { x: (rect.left - pageRect.left) / scale, y: (rect.top - pageRect.top) / scale, w: rect.width / scale, h: rect.height / scale },
            src: { x: num(d.sourceBboxX), y: num(d.sourceBboxY), w: num(d.sourceBboxW), h: num(d.sourceBboxH), pageHeight: num(d.basePageHeight) },
            moved: d.movedTextOverlay === '1',
            dxPts: num(d.dxPts), dyPts: num(d.dyPts),
            fontPts: num(d.fontSizePts),
            sourceFont: d.sourceFontFamily || '',
            sourceColor: d.sourceTextColor || '',
            computed: style ? { family: style.fontFamily, sizePx: Number.parseFloat(style.fontSize), weight: style.fontWeight, color: style.color } : null,
            sourceLines: content ? content.querySelectorAll('[data-source-span-line="1"]').length : 0,
        };
    }));
}

async function boxById(page, id) {
    return (await boxes(page)).find((box) => box.id === id) || null;
}

async function findBox(page, predicate, description = 'box') {
    const all = await boxes(page);
    const found = all.find(predicate);
    if (!found) throw new Error(`Could not find ${description} among ${all.length} boxes: ${all.slice(0, 12).map((b) => `${b.id}="${b.text.slice(0, 30)}"`).join(', ')}`);
    return found;
}

const startsWith = (prefix) => (box) => box.text.startsWith(prefix);

/** Every source mask on the page, in CSS px relative to the page. */
function masks(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('.enpv-source-mask-run, .enpv-orig-mask')).map((el) => {
        const pageDiv = el.closest('.page');
        const pageRect = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0 };
        const rect = el.getBoundingClientRect();
        const background = window.getComputedStyle(el).backgroundColor || '';
        const parts = (background.match(/-?\d+(?:\.\d+)?/g) || []).map(Number);
        return {
            cls: el.className,
            px: { left: rect.left - pageRect.left, top: rect.top - pageRect.top, width: rect.width, height: rect.height },
            background,
            rgb: parts.slice(0, 3),
            alpha: parts.length > 3 ? parts[3] : 1,
        };
    }));
}

/** Words of a box's content grouped into visual lines, top to bottom. */
function lineTexts(page, id) {
    return page.evaluate((wanted) => {
        const box = Array.from(document.querySelectorAll('.enpv-annotation-box')).find((el) => el.dataset.annotationId === wanted);
        const root = box?.querySelector('.enpv-text-content');
        if (!root) return [];
        const lines = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node) {
            const value = String(node.nodeValue || '');
            for (const match of value.matchAll(/\S+/g)) {
                const range = document.createRange();
                range.setStart(node, match.index);
                range.setEnd(node, match.index + match[0].length);
                const rect = range.getBoundingClientRect();
                range.detach?.();
                if (!(rect.width > 0)) continue;
                let line = lines.find((entry) => Math.abs(entry.top - rect.top) <= Math.max(1, rect.height * 0.35));
                if (!line) { line = { top: rect.top, words: [] }; lines.push(line); }
                line.words.push(match[0]);
            }
            node = walker.nextNode();
        }
        if (lines.length === 0) {
            // A pristine promoted block keeps its runs in source-line spans that
            // measure as empty until the block is touched; read those instead.
            return Array.from(root.querySelectorAll('.enpv-edit-source-line'))
                .map((line) => String(line.textContent || '').replace(/\s+/g, ' ').trim())
                .filter(Boolean);
        }
        return lines.sort((l, r) => l.top - r.top).map((line) => line.words.join(' '));
    }, id);
}

/**
 * Ink in a region of the rendered editor, from a clipped screenshot: the
 * fraction of dark pixels (luma < 100), like the inspector's `pixels` query.
 */
async function editorInk(page, pageIndex, rectPx) {
    const { PNG } = require('pngjs');
    const origin = await page.evaluate((index) => {
        const pageDiv = document.querySelector(`.pdfViewer .page[data-page-number="${index + 1}"]`);
        const rect = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0 };
        return { left: rect.left, top: rect.top };
    }, pageIndex);
    const clip = { x: origin.left + rectPx.left, y: origin.top + rectPx.top, width: Math.max(1, rectPx.width), height: Math.max(1, rectPx.height) };
    const png = PNG.sync.read(await page.screenshot({ clip }));
    let dark = 0;
    let nonwhite = 0;
    const total = png.width * png.height;
    for (let i = 0; i < png.data.length; i += 4) {
        const luma = (0.299 * png.data[i]) + (0.587 * png.data[i + 1]) + (0.114 * png.data[i + 2]);
        if (luma < 100) dark += 1;
        if (png.data[i] < 250 || png.data[i + 1] < 250 || png.data[i + 2] < 250) nonwhite += 1;
    }
    return { dark_ratio: round(dark / total, 4), nonwhite_ratio: round(nonwhite / total, 4), width: png.width, height: png.height };
}

/** Top-left PDF rect of a box's immutable source bbox. */
function srcRect(box) {
    const pageHeight = box.src.pageHeight || 792;
    return [box.src.x, pageHeight - (box.src.y + box.src.h), box.src.x + box.src.w, pageHeight - box.src.y];
}

/** Top-left PDF rect of a box as rendered. */
function boxRect(box) {
    return [box.pt.x, box.pt.y, box.pt.x + box.pt.w, box.pt.y + box.pt.h];
}

function expandRect(rect, by) {
    return [rect[0] - by, rect[1] - by, rect[2] + by, rect[3] + by];
}

// ---------------------------------------------------------------------------
// Editor drivers
// ---------------------------------------------------------------------------

function locatorFor(page, id) {
    return page.locator(`.enpv-annotation-box[data-annotation-id="${id}"]`).first();
}

async function selectBox(page, id) {
    const locator = locatorFor(page, id);
    await locator.scrollIntoViewIfNeeded();
    const rect = await locator.boundingBox();
    if (!rect) throw new Error(`Cannot select ${id}: no bounding box`);
    const point = { clientX: rect.x + Math.min(8, Math.max(2, rect.width / 2)), clientY: rect.y + Math.min(8, Math.max(2, rect.height / 2)), pointerId: 41, button: 0 };
    await locator.dispatchEvent('pointerdown', point);
    await page.evaluate((init) => window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, ...init })), point);
    await sleep(250);
    return locator;
}

async function deselect(page) {
    await page.keyboard.press('Escape');
    await sleep(200);
    await page.mouse.click(8, 8);
    await sleep(300);
}

/** Drag a box with the real move grip on the annotation menu. */
async function moveBox(page, id, dxPx, dyPx) {
    await selectBox(page, id);
    const grip = page.locator('#enpv-ann-menu [data-action="move"]').first();
    const gripRect = await grip.boundingBox();
    if (!gripRect) throw new Error('No move grip on the annotation menu');
    const startX = gripRect.x + (gripRect.width / 2);
    const startY = gripRect.y + (gripRect.height / 2);
    // Raw mouse moves: hover()'s stability wait can time out while the menu
    // repositions itself, and the grip is a plain button under the pointer.
    await page.mouse.move(startX, startY, { steps: 4 });
    await sleep(150);
    await page.mouse.down();
    await sleep(100);
    await page.mouse.move(startX + dxPx, startY + dyPx, { steps: 20 });
    await page.mouse.up();
    await sleep(900);
    return boxById(page, id);
}

async function enterEdit(page, id) {
    await selectBox(page, id);
    await page.locator('#enpv-ann-menu [data-action="edit"]').first().dispatchEvent('pointerdown');
    await page.waitForFunction((wanted) => {
        const box = Array.from(document.querySelectorAll('.enpv-annotation-box')).find((el) => el.dataset.annotationId === wanted);
        return box?.classList.contains('is-editing') && box.querySelector('.enpv-text-content')?.isContentEditable;
    }, id, { timeout: 20000 });
    await sleep(300);
}

async function commitEdit(page) {
    await page.mouse.click(8, 8);
    await sleep(900);
}

/** Select a run of the box's text by its start and end markers. */
async function selectTextRange(page, id, startNeedle, endNeedle = null) {
    const result = await page.evaluate(({ wanted, start, end }) => {
        const box = Array.from(document.querySelectorAll('.enpv-annotation-box')).find((el) => el.dataset.annotationId === wanted);
        const root = box?.querySelector('.enpv-text-content');
        if (!root) return { ok: false, reason: 'no editable content' };
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let joined = '';
        while (walker.nextNode()) {
            const node = walker.currentNode;
            nodes.push({ node, start: joined.length, end: joined.length + node.nodeValue.length });
            joined += node.nodeValue;
        }
        const squash = (s) => s.replace(/\s+/g, ' ');
        // Find on a whitespace-normalised copy, then map back through a position table.
        const map = [];
        let normal = '';
        for (let i = 0; i < joined.length; i += 1) {
            const ch = joined[i];
            if (/\s/.test(ch)) { if (normal.endsWith(' ')) continue; normal += ' '; map.push(i); } else { normal += ch; map.push(i); }
        }
        const from = normal.indexOf(squash(start));
        if (from < 0) return { ok: false, reason: `start not found: ${start}`, joined: normal.slice(0, 200) };
        const endIndex = end ? normal.indexOf(squash(end), from) : from + squash(start).length - 1;
        if (endIndex < 0) return { ok: false, reason: `end not found: ${end}` };
        const to = end ? endIndex + squash(end).length - 1 : endIndex;
        const rawStart = map[from];
        const rawEnd = map[to] + 1;
        const startNode = nodes.find((entry) => rawStart >= entry.start && rawStart < entry.end) || nodes[nodes.length - 1];
        const endNode = nodes.find((entry) => rawEnd > entry.start && rawEnd <= entry.end) || nodes[nodes.length - 1];
        const range = document.createRange();
        range.setStart(startNode.node, rawStart - startNode.start);
        range.setEnd(endNode.node, rawEnd - endNode.start);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        root.focus();
        return { ok: true, selected: selection.toString() };
    }, { wanted: id, start: startNeedle, end: endNeedle });
    if (!result.ok) throw new Error(`selectTextRange: ${result.reason}`);
    return result.selected;
}

/** Replace one run of a box's text with new text, through the real editor. */
async function replaceText(page, id, needle, replacement) {
    await enterEdit(page, id);
    await selectTextRange(page, id, needle);
    await page.keyboard.type(replacement);
    await sleep(200);
    await commitEdit(page);
    return boxById(page, id);
}

async function deleteTextRange(page, id, startNeedle, endNeedle) {
    await enterEdit(page, id);
    await selectTextRange(page, id, startNeedle, endNeedle);
    await page.keyboard.press('Backspace');
    await sleep(200);
    await commitEdit(page);
    return boxById(page, id);
}

async function deleteBox(page, id) {
    await selectBox(page, id);
    await page.locator('#enpv-ann-menu [data-action="delete"]').first().dispatchEvent('pointerdown');
    await sleep(900);
    return boxById(page, id);
}

/** Drag the right edge while editing; text boxes only expose handles then. */
async function resizeBoxRight(page, id, dxPx) {
    await enterEdit(page, id);
    const handle = page.locator(`.enpv-annotation-box[data-annotation-id="${id}"] > .enpv-resize-handle.r`).first();
    const rect = await handle.boundingBox();
    if (!rect) throw new Error(`No right resize handle on ${id}`);
    const startX = rect.x + (rect.width / 2);
    const startY = rect.y + (rect.height / 2);
    await page.mouse.move(startX, startY);
    await page.mouse.down();
    await page.mouse.move(startX + dxPx, startY, { steps: 16 });
    await page.mouse.up();
    await sleep(500);
    await commitEdit(page);
    return boxById(page, id);
}

/** Click undo (or redo) until its button disables; returns how many steps it took. */
async function unwind(page, buttonId, max = 12) {
    let steps = 0;
    while (steps < max) {
        // eslint-disable-next-line no-await-in-loop
        const disabled = await page.evaluate((id) => { const b = document.getElementById(id); return !b || b.disabled; }, buttonId);
        if (disabled) break;
        // eslint-disable-next-line no-await-in-loop
        await page.evaluate((id) => document.getElementById(id)?.click(), buttonId);
        // eslint-disable-next-line no-await-in-loop
        await sleep(700);
        steps += 1;
    }
    return steps;
}

const undo = (page) => unwind(page, 'undo-btn');
const redo = (page) => unwind(page, 'redo-btn');

function attachSaveRecorder(page) {
    const recorder = { saves: [] };
    page.on('request', (request) => {
        try {
            if (request.method() !== 'POST' || !request.url().includes('/save-annotation-state')) return;
            let body = {};
            try { body = request.postDataJSON() || {}; } catch (_) { body = {}; }
            recorder.saves.push({ annotations: Array.isArray(body.annotations) ? body.annotations : [] });
        } catch (_) { /* ignore */ }
    });
    return recorder;
}

async function saveDocument(page, recorder) {
    const before = recorder ? recorder.saves.length : 0;
    await page.evaluate(() => document.getElementById('save-btn')?.click());
    const deadline = Date.now() + 20000;
    while (recorder && recorder.saves.length === before && Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await sleep(200);
    }
    await sleep(1200);
    return recorder ? recorder.saves[recorder.saves.length - 1] : null;
}

function readZoom(page) {
    return page.evaluate(() => Number.parseInt(String(document.getElementById('zoom-label')?.textContent || '').trim(), 10));
}

/**
 * Zoom to the nearest reachable level: the ladder is not symmetric
 * (190 → 152 → 122 → 98 → 78 → 62 → 50 → 63 → 79 → 99 → 124 → 155 → 194),
 * so the caller gets the level actually reached.
 */
async function setZoom(page, target) {
    for (let attempt = 0; attempt < 20; attempt += 1) {
        // eslint-disable-next-line no-await-in-loop
        const current = await readZoom(page);
        if (Math.abs(current - target) <= 5) return current;
        const out = current > target;
        // eslint-disable-next-line no-await-in-loop
        await page.click(out ? '#zoom-out' : '#zoom-in', { force: true });
        // eslint-disable-next-line no-await-in-loop
        await page.waitForFunction(({ previous, wanted, decreasing }) => {
            const value = Number.parseInt(String(document.getElementById('zoom-label')?.textContent || '').trim(), 10);
            return Math.abs(value - wanted) <= 5 || (decreasing ? value < previous : value > previous);
        }, { previous: current, wanted: target, decreasing: out }, { timeout: 15000 });
        // eslint-disable-next-line no-await-in-loop
        await sleep(900);
    }
    throw new Error(`Could not reach ${target}% zoom`);
}

// ---------------------------------------------------------------------------
// Download
// ---------------------------------------------------------------------------

/**
 * Download through the editor's own Download PDF button and keep the bytes.
 *
 * The button POSTs the editor's payload and opens the result in a popup as a
 * blob: URL, which never surfaces as a download event. The POST is captured
 * and replayed verbatim, so the bytes are exactly what the server built for
 * that payload.
 */
async function downloadPdf(page, testId, label) {
    ensureArtifactDir();
    const requestPromise = page.waitForRequest((request) => request.method() === 'POST' && /\/download-annotated-pdf/.test(request.url()), { timeout: 90000 });
    const popupPromise = page.context().waitForEvent('page', { timeout: 30000 }).catch(() => null);
    await page.locator('#download-pdf-btn').click();
    const request = await requestPromise;
    const body = request.postData() || '';
    const headers = {};
    for (const [name, value] of Object.entries(await request.allHeaders())) {
        const lower = name.toLowerCase();
        if (lower.startsWith(':') || ['content-length', 'host', 'connection'].includes(lower)) continue;
        headers[name] = value;
    }
    const replay = await page.context().request.post(request.url(), { data: body, headers, timeout: 180000 });
    if (!replay.ok()) throw new Error(`Download replay failed (${replay.status()}): ${(await replay.text().catch(() => '')).slice(0, 300)}`);
    const bytes = await replay.body();
    const filename = `${testId}__${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.pdf`;
    const filePath = path.join(ARTIFACT_DIR, filename);
    fs.writeFileSync(filePath, bytes);
    try { fs.chmodSync(filePath, 0o666); } catch (_) { /* best effort */ }
    const popup = await popupPromise;
    if (popup) await popup.close().catch(() => {});
    await sleep(600);
    let payload = {};
    try { payload = JSON.parse(body || '{}'); } catch (_) { payload = {}; }
    return { path: filePath, bytes: bytes.length, payload };
}

/** Untouched download of the document, summarised: what every B compares against. */
async function baseline(page, testId, label = 'baseline') {
    const download = await downloadPdf(page, testId, label);
    return { ...download, report: summarize(download.path) };
}

// ---------------------------------------------------------------------------
// Shared B checks
// ---------------------------------------------------------------------------

function checkBackgroundUntouched(B, base, after, touchedRect, pageIndex = 0, label = 'background') {
    const before = base.report.pages[pageIndex];
    const now = after.pages[pageIndex];
    const outsideBefore = blocksOutside(before, touchedRect, 2);
    const outsideAfter = blocksOutside(now, touchedRect, 2);
    B.assert(`${label}-blocks-identical`, sameBlocks(outsideBefore, outsideAfter),
        'Every text block outside the touched area extracts identically to the baseline',
        sameBlocks(outsideBefore, outsideAfter) ? `${outsideAfter.length} spans` : `only in baseline: ${JSON.stringify(outsideBefore.filter((a) => !outsideAfter.some((b) => JSON.stringify(a) === JSON.stringify(b))).slice(0, 6))}; only after: ${JSON.stringify(outsideAfter.filter((a) => !outsideBefore.some((b) => JSON.stringify(a) === JSON.stringify(b))).slice(0, 6))}`);
    B.equals(`${label}-images`, now.image_count, before.image_count, 'The page keeps its images');
    B.equals(`${label}-widgets`, JSON.stringify(now.widgets.map((w) => [w.name, w.value])), JSON.stringify(before.widgets.map((w) => [w.name, w.value])),
        'The page keeps its widgets and their values');
    B.equals(`${label}-no-notdef`, now.has_notdef, false, 'No missing-glyph character anywhere on the page');
}

function checkOtherPagesIdentical(B, base, after, touchedPage) {
    const untouched = base.report.pages.filter((p) => p.index !== touchedPage);
    const same = untouched.every((p) => after.pages[p.index]?.content_hash === p.content_hash && after.pages[p.index]?.text_hash === p.text_hash);
    B.assert('other-pages-byte-identical', same,
        `Every page other than page ${touchedPage + 1} is byte-identical to the baseline (${untouched.length} pages)`,
        untouched.map((p) => `${p.index + 1}:${after.pages[p.index]?.content_hash === p.content_hash ? 'same' : 'DIFFERENT'}`).join(' '));
}

/** The download shows the string once at the editor's new place, and nowhere near the old one. */
function checkMovedText(B, base, afterPath, text, oldRect, editorDelta, pageIndex = 0, options = {}) {
    const baseHit = hitsInside({ rects: (base.report.pages[pageIndex].blocks.flatMap((b) => b.lines).filter((l) => normalize(l.text).includes(normalize(text))).map((l) => l.bbox)) }, oldRect, 3)[0];
    const search = searchCount(afterPath, text, pageIndex);
    const newRect = search.rects.map((r) => ({ r, d: baseHit && editorDelta ? Math.hypot(r[0] - baseHit[0] - editorDelta.dx, r[1] - baseHit[1] - editorDelta.dy) : 0 })).sort((a, b) => a.d - b.d)[0]?.r || null;
    const scrubRect = uncoveredPart(oldRect, options.newBoxRect || newRect || oldRect);
    const atOld = hitsInside(search, scrubRect, 0);
    B.equals('old-place-not-findable', atOld.length, 0, 'The old bbox returns zero search hits for the string', `${search.count} hits total, scrub rect ${JSON.stringify(scrubRect.map((v) => round(v, 1)))}`);
    const pixels = pixelsAt(afterPath, scrubRect, pageIndex);
    const baselineDark = pixelsAt(base.path, scrubRect, pageIndex).dark_ratio;
    if (options.darkCell) {
        B.assert('old-place-is-cell', pixels.dark_ratio >= baselineDark - 0.02, 'The old place is the cell\'s own dark fill with the light glyphs gone, not a white hole',
            `dark ${pixels.dark_ratio} (baseline ${baselineDark})`);
    } else {
        // Ruling lines that cross the bbox stay (fill-less redaction), so on a
        // ruled form the region is judged against the baseline instead.
        B.assert('old-place-is-background', pixels.dark_ratio <= BACKGROUND_DARK || (pixels.dark_ratio < baselineDark && pixels.dark_ratio <= 0.1),
            'The old place reads as background, not painted-over text', `dark ${pixels.dark_ratio} (baseline ${baselineDark})`);
    }
    B.assert('found-once', search.count >= 1, 'The string is found in the download', `${search.count} hits`);
    if (search.count && baseHit && editorDelta) {
        const expected = [baseHit[0] + editorDelta.dx, baseHit[1] + editorDelta.dy];
        const nearest = search.rects.map((r) => ({ r, d: Math.hypot(r[0] - expected[0], r[1] - expected[1]) })).sort((a, b) => a.d - b.d)[0];
        B.assert('found-at-editor-position', nearest.d <= POINT_TOL, `The string sits where the editor put it (within ${POINT_TOL}pt)`,
            `expected ${expected.map((v) => v.toFixed(2))}, nearest ${nearest.r.slice(0, 2).map((v) => v.toFixed(2))}, off by ${nearest.d.toFixed(2)}pt`);
    }
    return { ...search, newRect };
}

/** Same family (or its substitute), same size, same weight, no boxes. */
function checkReplacementTypography(B, afterPath, rect, expected, pageIndex = 0, label = 'replacement') {
    // Inset vertically so a neighbouring line's glyphs are never sampled.
    const inset = Math.min(1.5, Math.max(0, (rect[3] - rect[1]) * 0.15));
    const chars = charsFiltered(charsAt(afterPath, [rect[0] - 1, rect[1] + inset, rect[2] + 1, rect[3] - inset], pageIndex), expected.sizePt);
    B.assert(`${label}-has-text`, chars.count > 0, 'The replacement region holds text', `${chars.count} chars: ${JSON.stringify(chars.text.slice(0, 60))}`);
    B.equals(`${label}-no-notdef`, chars.notdef, 0, 'No glyph renders as a missing-glyph box');
    const fonts = chars.fonts.filter((f) => !/symbol/i.test(f));
    B.assert(`${label}-font-family`, fonts.length > 0 && fonts.every((f) => fontMatches(f, expected.family)),
        `The replacement uses ${expected.family} or its configured substitute`, `pdf fonts ${JSON.stringify(fonts)} vs editor ${expected.family}`);
    if (Number.isFinite(expected.sizePt)) {
        // A single-span title is fitted back into its source bbox (contract rule
        // 7), so a wider replacement may come out a little smaller, never larger.
        const floor = expected.fitted ? expected.sizePt * 0.9 : expected.sizePt - SIZE_TOL;
        B.assert(`${label}-font-size`, chars.sizes.length > 0 && chars.sizes.every((s) => s >= floor && s <= expected.sizePt + SIZE_TOL),
            expected.fitted ? `The replacement keeps the size or is fitted down by at most 10% (${expected.sizePt}pt)` : `The replacement keeps the size (${expected.sizePt}pt ±${SIZE_TOL})`, `pdf sizes ${JSON.stringify(chars.sizes)}`);
    }
    if (expected.bold !== undefined) {
        B.equals(`${label}-weight`, fonts.some(isBold), expected.bold, expected.bold ? 'Bold stays bold' : 'Regular stays regular', JSON.stringify(fonts));
    }
    if (!expected.light) {
        const pixels = pixelsAt(afterPath, rect, pageIndex);
        B.assert(`${label}-not-a-box`, pixels.nonwhite_ratio >= 0.01 && pixels.dark_ratio <= 0.85,
            'The region carries ink but is not a solid block', `non-white ${pixels.nonwhite_ratio}, dark ${pixels.dark_ratio}`);
    }
    return chars;
}

function editorFamily(box) {
    return box.sourceFont || String(box.computed?.family || '').split(',')[0].trim();
}

function editorBold(box) {
    return isBold(box.sourceFont) || Number(box.computed?.weight) >= 600;
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup, and the baseline download every later case compares against. */
async function testSetup(ctx) {
    const { page, recorder, testId, csrfToken } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    for (const fixture of Object.keys(FIXTURES)) {
        // eslint-disable-next-line no-await-in-loop
        const docId = await uploadFixture(page, csrfToken, fixture);
        const errors = [];
        page.on('pageerror', (error) => errors.push(String(error?.message || error).slice(0, 200)));
        // eslint-disable-next-line no-await-in-loop
        const found = await openEditorWithBoxes(page, docId);
        // eslint-disable-next-line no-await-in-loop
        const pages = await page.evaluate(() => Number(window.__enpv?.pdfViewer?.pagesCount || 0));
        const fixtureReport = summarize(path.join(FIXTURE_DIR, FIXTURES[fixture]));
        A.assert(`${fixture}-opens`, pages === fixtureReport.page_count, `${fixture} opens with all ${fixtureReport.page_count} pages`, `${pages} pages`);
        A.assert(`${fixture}-promoted`, found.length > 0, `${fixture}'s text is promoted into editable boxes`, `${found.length} boxes`);
        A.assert(`${fixture}-edit-mode`, await page.evaluate(() => document.body.classList.contains('enpv-edit-on')), 'Edit PDF is on');
        const target = { invoice: 'Invoice Number', drylab: 'DrylabNews', f1040s1: 'Part I', isartor: 'Isartor Test Suite' }[fixture];
        A.assert(`${fixture}-target-present`, found.some(startsWith(target)), `The case target "${target}" is one of the boxes`);
        A.equals(`${fixture}-console-clean`, errors.length, 0, 'The console is clean', errors.slice(0, 2).join(' | '));
        // eslint-disable-next-line no-await-in-loop
        artifacts.push(await capture(page, testId, `${fixture}-ready`));

        // eslint-disable-next-line no-await-in-loop
        const base = await baseline(page, testId, `${fixture}-baseline`);
        B.equals(`${fixture}-page-count`, base.report.page_count, fixtureReport.page_count, 'The untouched download keeps the page count');
        const textSame = fixtureReport.pages.every((p, i) => base.report.pages[i]?.text_hash === p.text_hash);
        B.assert(`${fixture}-text-identical`, textSame, 'Every page extracts the same text as the fixture');
        B.equals(`${fixture}-images`, base.report.pages.map((p) => p.image_count).join(','), fixtureReport.pages.map((p) => p.image_count).join(','), 'Image counts match the fixture');
        B.equals(`${fixture}-widgets`, base.report.pages.map((p) => p.widgets.length).join(','), fixtureReport.pages.map((p) => p.widgets.length).join(','), 'Widget counts match the fixture');
        B.assert(`${fixture}-fonts`, fixtureReport.pages[0].fonts.every((f) => base.report.pages[0].fonts.some((g) => g.name === f.name)),
            'The fixture\'s embedded fonts are all still listed', JSON.stringify(base.report.pages[0].fonts.map((f) => f.name)));
    }
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — Selecting existing text, and entering Edit without changing anything. */
async function testSelectAndEnterEdit(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    const base = await baseline(page, testId);
    const before = await findBox(page, startsWith('Invoice Number'), 'invoice number row');
    const baseLine = base.report.pages[0].blocks.flatMap((b) => b.lines).find((l) => normalize(l.text).startsWith('Invoice Number'));

    await selectBox(page, before.id);
    const selected = await boxById(page, before.id);
    A.assert('selects-source-box', selected.selected && !selected.userCreated, 'Clicking existing text selects it as a source box, not a user text box', selected.classes);
    A.near('box-left-on-glyphs', selected.pt.x, baseLine.bbox[0], 0.75, 'The box left edge sits on the glyph bounds');
    A.near('box-right-on-glyphs', selected.pt.x + selected.pt.w, baseLine.bbox[2], 3, 'The box right edge sits on the glyph bounds');
    A.equals('typography-family', fontMatches(baseLine.spans[0].font, selected.sourceFont), true, 'The captured font family is the fixture span\'s', `${selected.sourceFont} vs ${baseLine.spans[0].font}`);
    A.near('typography-size', selected.fontPts, baseLine.spans[0].size, SIZE_TOL, 'The captured size is the fixture span\'s');
    const runMasks = async () => (await masks(page)).filter((m) => m.cls.includes('enpv-source-mask-run')).length;
    const masksBefore = await runMasks();

    await enterEdit(page, before.id);
    const editing = await boxById(page, before.id);
    A.assert('enters-edit', editing.editing, 'Edit opens an inline editor on the box');
    A.equals('edit-keeps-size', editing.fontPts, before.fontPts, 'Entering Edit changes no font size');
    A.near('edit-keeps-left', editing.pt.x, before.pt.x, 0.25, 'Entering Edit does not move the box');
    A.near('edit-keeps-width', editing.pt.w, before.pt.w, 0.5, 'Entering Edit does not resize the box');
    A.equals('edit-creates-no-mask', await runMasks(), masksBefore, 'Entering Edit creates no moved-source mask (the inline editor only hides the glyphs it is editing)');
    artifacts.push(await capture(page, testId, 'editing'));
    await page.keyboard.press('Escape');
    await sleep(400);
    await deselect(page);
    const after = await boxById(page, before.id);
    A.equals('escape-keeps-text', after.text, before.text, 'Escape leaves the text unchanged');

    const download = await downloadPdf(page, testId, 'after-select');
    const report = summarize(download.path);
    B.assert('identical-to-baseline', report.pages[0].text_hash === base.report.pages[0].text_hash && sameBlocks(blocksOutside(report.pages[0], [0, 0, 0, 0]), blocksOutside(base.report.pages[0], [0, 0, 0, 0])),
        'Selecting and looking is not an edit: the download is identical to the baseline');
    const line = report.pages[0].blocks.flatMap((b) => b.lines).find((l) => normalize(l.text).startsWith('Invoice Number'));
    B.assert('line-bbox-identical', line && line.bbox.every((v, i) => Math.abs(v - baseLine.bbox[i]) <= 0.25), 'The selected line keeps its glyph bbox', JSON.stringify(line?.bbox));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Untouched text is untouched. */
async function testUntouched(ctx) {
    const { page, recorder, testId, saveRecorder } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    const base = await baseline(page, testId);
    await setEditMode(page, false);
    await setEditMode(page, true);
    const save = await saveDocument(page, saveRecorder);
    const dirty = (save?.annotations || []).filter((a) => a.savedTextOverlay || a.movedTextOverlay || a.pdfjsDeleted || a.promotedDirty || a.userCreated);
    A.assert('save-happened', !!save, 'Save posts the annotation state');
    A.equals('nothing-dirty', dirty.length, 0, 'The save payload carries no changed, moved, deleted or user rows', JSON.stringify(dirty.map((a) => a.id)));
    A.equals('no-overlays', (await boxes(page)).filter((b) => b.moved || b.classes.includes('is-persisted-overlay')).length, 0, 'No box is marked moved or persisted');
    artifacts.push(await capture(page, testId, 'after-save'));
    const download = await downloadPdf(page, testId, 'after-save');
    const report = summarize(download.path);
    const same = base.report.pages.every((p, i) => report.pages[i]?.content_hash === p.content_hash);
    B.assert('byte-identical', same, 'Every page is byte-identical to the baseline: no page was redrawn', report.pages.map((p, i) => p.content_hash === base.report.pages[i].content_hash ? 'same' : 'DIFF').join(','));
    B.assert('text-identical', report.pages.every((p, i) => p.text_hash === base.report.pages[i].text_hash), 'Every page extracts the same text');
    B.equals('no-annotation-exported', (download.payload.annotations || []).filter((a) => a.savedTextOverlay || a.movedTextOverlay || a.pdfjsDeleted).length, 0, 'No promoted row was exported');
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** Shared move: act, record, prove. */
async function runMove(ctx, prefix, dxPx, dyPx, options = {}) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    const base = await baseline(page, testId);
    const before = await findBox(page, startsWith(prefix), `"${prefix}"`);
    const oldRect = srcRect(before);
    const neighbourIds = (options.neighbours || []).map((n) => n);
    const neighboursBefore = (await boxes(page)).filter((b) => neighbourIds.some((n) => b.text.startsWith(n)));

    const after = await moveBox(page, before.id, dxPx, dyPx);
    const delta = { dx: after.pt.x - before.pt.x, dy: after.pt.y - before.pt.y };
    A.assert('moved-overlay', after.moved, 'The box becomes a moved overlay', after.classes);
    A.near('moved-by-dx', delta.dx, dxPx / before.scale, 1.5, 'The box moves horizontally by the pointer distance');
    A.near('moved-by-dy', delta.dy, dyPx / before.scale, 1.5, 'The box moves vertically by the pointer distance');
    A.assert('keeps-family', fontMatches(String(after.computed?.family || '').split(',')[0], before.sourceFont) || String(after.computed?.family || '').toLowerCase().includes(fontRoot(before.sourceFont).slice(0, 5)),
        'The moved text keeps the source font family', `${after.computed?.family} vs ${before.sourceFont}`);
    A.equals('keeps-size', after.fontPts, before.fontPts, 'The moved text keeps the source size');
    A.equals('keeps-text', after.liveText, before.text, 'The moved text keeps its words');
    const allMasks = await masks(page);
    const oldPx = { left: before.src.x * before.scale, width: before.src.w * before.scale, top: (before.src.pageHeight - (before.src.y + before.src.h)) * before.scale, height: before.src.h * before.scale };
    const covering = allMasks.filter((m) => m.px.left <= oldPx.left + 1 && m.px.left + m.px.width >= oldPx.left + oldPx.width - 1 && m.px.top <= oldPx.top + 6 && m.px.top + m.px.height >= oldPx.top + oldPx.height - 6);
    const run = covering.find((m) => m.cls.includes('enpv-source-mask-run')) || covering[0];
    A.assert('mask-present', !!run, 'One source mask covers the original place', JSON.stringify(allMasks.map((m) => ({ cls: m.cls, ...m.px })).slice(0, 4)));
    if (run) {
        A.near('mask-left', run.px.left, oldPx.left, MASK_TOL_PX, `The mask left equals the immutable source bbox at scale (±${MASK_TOL_PX}px)`);
        A.near('mask-right', run.px.left + run.px.width, oldPx.left + oldPx.width, MASK_TOL_PX, `The mask right edge equals the immutable source bbox at scale (±${MASK_TOL_PX}px)`);
        A.assert('mask-covers-height', run.px.top <= oldPx.top + 4 && run.px.top + run.px.height >= oldPx.top + oldPx.height - 4,
            'The mask covers the source line top to bottom', `mask top ${run.px.top.toFixed(2)} h ${run.px.height.toFixed(2)} vs bbox top ${oldPx.top.toFixed(2)} h ${oldPx.height.toFixed(2)}`);
        A.assert('mask-is-one-rectangle', covering.filter((m) => m.cls.includes('enpv-source-mask-run')).length <= 1, 'The erase is one solid rectangle, not segmented by run');
        if (options.darkCell) {
            A.assert('mask-repaints-dark', run.rgb.length === 3 && run.rgb.every((c) => c <= 90) && run.alpha > 0.9, 'The mask repaints the cell in its own dark colour, no white hole', run.background);
            A.assert('moved-text-stays-light', /rgb\((\d+), (\d+), (\d+)/.test(after.computed?.color || '') && after.computed.color.match(/\d+/g).slice(0, 3).every((c) => Number(c) >= 200), 'The moved text keeps its white colour', after.computed?.color);
        } else {
            // The mask paint may be a sampled colour or transparent over a
            // canvas repaint; what matters is that the old glyphs are gone.
            await deselect(page);
            const oldR = [oldPx.left, oldPx.top, oldPx.left + oldPx.width, oldPx.top + oldPx.height];
            const newR = [after.px.left, after.px.top, after.px.left + after.px.width, after.px.top + after.px.height];
            const strip = uncoveredPart(oldR, newR);
            const ink = await editorInk(page, before.pageIndex, { left: strip[0], top: strip[1], width: strip[2] - strip[0], height: strip[3] - strip[1] });
            A.assert('old-place-blank-in-editor', ink.dark_ratio <= 0.03,
                'The old place shows no glyph ink in the editor after the move', `dark ${ink.dark_ratio}, non-white ${ink.nonwhite_ratio} over ${ink.width}x${ink.height}px, mask ${run.background}`);
        }
    }
    for (const n of neighboursBefore) {
        // eslint-disable-next-line no-await-in-loop
        const now = await boxById(page, n.id);
        A.assert(`neighbour-untouched-${n.id.split(':').pop()}`, now && !now.moved && Math.abs(now.pt.y - n.pt.y) < 0.25 && now.text === n.text, `The neighbour "${n.text.slice(0, 24)}" is not moved or restyled`);
        A.assert(`neighbour-unmasked-${n.id.split(':').pop()}`, !allMasks.some((m) => m.px.top < n.px.top + n.px.height - 2 && m.px.top + m.px.height > n.px.top + 2 && m.px.left < n.px.left + n.px.width && m.px.left + m.px.width > n.px.left && !(m.px.top <= oldPx.top + 6 && m.px.top + m.px.height >= oldPx.top + oldPx.height - 6)),
            `No mask covers the neighbour "${n.text.slice(0, 24)}"`);
    }
    artifacts.push(await capture(page, testId, 'moved'));

    const download = await downloadPdf(page, testId, 'moved');
    const report = summarize(download.path);
    const search = checkMovedText(B, base, download.path, options.searchText || before.text, oldRect, delta, 0, { darkCell: !!options.darkCell, newBoxRect: boxRect(after) });
    if (search.count && search.newRect) {
        checkReplacementTypography(B, download.path, search.newRect, { family: before.sourceFont, sizePt: before.fontPts, bold: isBold(before.sourceFont), light: !!options.darkCell }, 0, 'moved-text');
        checkBackgroundUntouched(B, base, report, rectUnion(oldRect, boxRect(after)));
    }
    if (options.darkCell) {
        const cell = pixelsAt(download.path, expandRect(oldRect, 3));
        const cellBefore = pixelsAt(base.path, expandRect(oldRect, 3));
        B.assert('cell-still-dark', cell.dark_ratio >= 0.6 && Math.abs(cell.dark_ratio - cellBefore.dark_ratio) <= 0.2, 'The cell is still dark with no white rectangle', `dark ${cell.dark_ratio} (baseline ${cellBefore.dark_ratio})`);
        if (search.newRect) {
            const chars = charsAt(download.path, expandRect(search.newRect, 0.5));
            B.assert('moved-text-white', chars.colors.length > 0 && chars.colors.every((c) => c >= 0xEEEEEE), 'The moved text is drawn in white', JSON.stringify(chars.colors));
        }
    }
    for (const n of neighboursBefore) {
        const nRect = srcRect(n);
        const hit = searchCount(download.path, n.text.slice(0, 40));
        B.assert(`neighbour-in-place-${n.id.split(':').pop()}`, hitsInside(hit, nRect, 2).length >= 1, `The neighbour "${n.text.slice(0, 24)}" is still at its own bbox`, `${hit.count} hits`);
    }
    if (options.rulesRect) {
        const rulesBefore = rulesAt(base.path, options.rulesRect);
        const rulesAfter = rulesAt(download.path, options.rulesRect);
        B.equals('rules-intact', rulesAfter.count, rulesBefore.count, 'The ruling lines through the band are all still drawn', `${rulesAfter.count} vs ${rulesBefore.count}`);
    }
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean), base, download, before, after, delta };
}

async function testMoveSingleLine(ctx) { return runMove(ctx, 'Invoice Number', 0, 60, { neighbours: ['Organic Items'] }); }
async function testMoveParagraph(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, (b) => b.promotedBlock && b.text.startsWith('Welcome to our first newsletter'), 'drylab welcome paragraph');
    const linesBefore = await lineTexts(page, before.id);
    const title = await findBox(page, startsWith('DrylabNews'));
    const strap = await findBox(page, startsWith('for investors'));
    const after = await moveBox(page, before.id, 0, 120);
    const linesAfter = await lineTexts(page, after.id);
    const delta = { dx: after.pt.x - before.pt.x, dy: after.pt.y - before.pt.y };
    A.assert('moved-overlay', after.moved, 'The paragraph becomes a moved overlay', after.classes);
    A.near('moved-by-dy', delta.dy, 120 / before.scale, 1.5, 'The paragraph moves by the pointer distance');
    A.equals('lines-kept', linesAfter.join('\n'), linesBefore.join('\n'), 'Line breaks and inline runs travel with it', `${linesAfter.length} lines`);
    const allMasks = await masks(page);
    const oldPx = { left: before.px.left, top: before.px.top, width: before.px.width, height: before.px.height };
    const covering = allMasks.filter((m) => m.px.top <= oldPx.top + 4 && m.px.top + m.px.height >= oldPx.top + oldPx.height - 8 && m.px.left <= oldPx.left + 4);
    A.assert('mask-covers-every-line', covering.length >= 1, 'One mask covers every source line of the paragraph', JSON.stringify(allMasks.map((m) => m.px).slice(0, 3)));
    for (const n of [title, strap]) {
        // eslint-disable-next-line no-await-in-loop
        const now = await boxById(page, n.id);
        A.assert(`neighbour-untouched-${n.id.split(':').pop()}`, now && !now.moved && Math.abs(now.pt.y - n.pt.y) < 0.25, `"${n.text.slice(0, 20)}" beside it is untouched`);
    }
    const artifacts = [await capture(page, testId, 'moved')];
    const download = await downloadPdf(page, testId, 'moved');
    const report = summarize(download.path);
    const oldRect = boxRect(before);
    const newRect = boxRect(after);
    const pdfLines = lineTextsTouching(report.pages[0], expandRect(newRect, 2));
    B.assert('lines-in-download', linesInOrder(pdfLines, linesAfter), 'The paragraph\'s lines extract at the new place with the editor\'s line breaks', `editor ${JSON.stringify(linesAfter)} / download ${JSON.stringify(pdfLines)}`);
    const firstLine = searchCount(download.path, linesBefore[0]);
    const scrubRect = uncoveredPart(oldRect, newRect);
    B.equals('old-area-scrubbed', hitsInside(firstLine, scrubRect, 0).length, 0, 'The old paragraph area returns zero hits for its first line', `${firstLine.count} hits, scrub rect ${JSON.stringify(scrubRect.map((v) => round(v, 1)))}`);
    B.assert('old-area-background', pixelsAt(download.path, scrubRect).dark_ratio <= BACKGROUND_DARK, 'The old area reads as background', `dark ${pixelsAt(download.path, scrubRect).dark_ratio}`);
    checkReplacementTypography(B, download.path, lineHitNear(download.path, linesAfter[0], newRect) || newRect, { family: before.sourceFont, sizePt: before.fontPts }, 0, 'paragraph');
    checkBackgroundUntouched(B, base, report, rectUnion(oldRect, newRect));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}
async function testMoveOutOfCell(ctx) { return runMove(ctx, 'Part I', 760, 0, { darkCell: true, searchText: 'Part I', neighbours: ['Additional Income'] }); }
async function testMoveOnRules(ctx) {
    const { page } = ctx;
    const row = await findBox(page, startsWith('Taxable refunds'), 'f1040s1 line 1');
    const band = [row.pt.x - 20, row.pt.y - 14, row.pt.x + row.pt.w + 40, row.pt.y + row.pt.h + 14];
    return runMove(ctx, 'Taxable refunds', 60, 0, { searchText: 'Taxable refunds', neighbours: ['Alimony received', 'Business income'], rulesRect: band });
}
async function testMoveContract(ctx) { return runMove(ctx, 'Subtotal', -170, 0, { neighbours: ['GST (10%)', 'Total'] }); }

/** 09 — Resize a paragraph from its right edge. */
async function testResizeParagraph(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, (b) => b.promotedBlock && b.text.startsWith('Welcome to our first newsletter'), 'drylab welcome paragraph');
    const linesBefore = await lineTexts(page, before.id);
    const narrow = await resizeBoxRight(page, before.id, -120);
    const linesNarrow = await lineTexts(page, narrow.id);
    A.near('width-shrinks', before.pt.w - narrow.pt.w, 120 / before.scale, 4, 'The right edge follows the pointer');
    A.equals('font-size-kept', narrow.fontPts, before.fontPts, 'The font size does not change: the container changes, not the type');
    A.assert('reflows', linesNarrow.length > linesBefore.length, 'The text reflows into more lines', `${linesBefore.length} -> ${linesNarrow.length}`);
    A.equals('words-kept', linesNarrow.join(' '), linesBefore.join(' '), 'Every word is still there');
    const artifacts = [await capture(page, testId, 'narrow')];
    const download = await downloadPdf(page, testId, 'narrow');
    const report = summarize(download.path);
    const rect = boxRect(narrow);
    const pdfLines = lineTextsTouching(report.pages[0], expandRect(rect, 2));
    B.assert('download-reflows-the-same', linesInOrder(pdfLines, linesNarrow), 'The download reflows exactly as the editor did', `editor ${JSON.stringify(linesNarrow)} / download ${JSON.stringify(pdfLines)}`);
    checkReplacementTypography(B, download.path, lineHitNear(download.path, linesNarrow[0], rect) || rect, { family: before.sourceFont, sizePt: before.fontPts }, 0, 'paragraph');
    checkBackgroundUntouched(B, base, report, expandRect(rectUnion(boxRect(before), rect), 1));

    const restored = await resizeBoxRight(page, before.id, 120);
    const linesRestored = await lineTexts(page, restored.id);
    A.equals('resizing-back-restores', linesRestored.join('\n'), linesBefore.join('\n'), 'Resizing back restores the original line breaks');
    const download2 = await downloadPdf(page, testId, 'restored');
    const report2 = summarize(download2.path);
    const pdfLines2 = lineTextsTouching(report2.pages[0], expandRect(boxRect(restored), 2));
    B.assert('restored-download-matches-editor', linesInOrder(pdfLines2, linesRestored), `After resizing back to ${restored.pt.w.toFixed(2)}pt (was ${before.pt.w.toFixed(2)}pt) the download breaks its lines where the editor does`, `editor ${JSON.stringify(linesRestored)} / download ${JSON.stringify(pdfLines2)}`);
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — Resize a single-line title narrower than its text. */
async function testResizeTitle(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, startsWith('Organic Items'), 'invoice header');
    const after = await resizeBoxRight(page, before.id, -100);
    const lines = await lineTexts(page, after.id);
    A.equals('font-size-kept', after.fontPts, before.fontPts, 'The font size is not scaled to fit');
    A.assert('narrower', after.pt.w < before.pt.w - 20, 'The box is narrower than its text was', `${before.pt.w.toFixed(1)} -> ${after.pt.w.toFixed(1)}`);
    A.assert('overflow-rule', lines.length >= 2 || after.pt.h >= before.pt.h, 'The overflow wraps (the box grows) rather than shrinking the type', `${lines.length} lines, h ${after.pt.h.toFixed(1)}`);
    const artifacts = [await capture(page, testId, 'narrow')];
    const download = await downloadPdf(page, testId, 'narrow');
    const report = summarize(download.path);
    const rect = boxRect(after);
    const pdfLines = lineTextsTouching(report.pages[0], expandRect(rect, 2));
    B.equals('layout-matches-editor', pdfLines.join('\n'), lines.join('\n'), 'The download lays the title out exactly as the editor showed');
    checkReplacementTypography(B, download.path, rect, { family: before.sourceFont, sizePt: before.fontPts, bold: isBold(before.sourceFont) }, 0, 'title');
    checkBackgroundUntouched(B, base, report, expandRect(rectUnion(boxRect(before), rect), 1));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** Shared edit: replace a run, record, prove. */
async function runEdit(ctx, prefix, needle, replacement, options = {}) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, options.predicate || startsWith(prefix), `"${prefix}"`);
    const linesBefore = await lineTexts(page, before.id);
    const after = await replaceText(page, before.id, needle, replacement);
    const linesAfter = await lineTexts(page, after.id);
    const expectedText = before.text.replace(needle, replacement);
    A.equals('text-replaced', after.liveText, normalize(expectedText), 'Only the run that was edited changed');
    A.near('box-left-kept', after.pt.x, before.pt.x, 0.5, 'The box does not move');
    A.near('box-top-kept', after.pt.y, before.pt.y, 0.5, 'The box does not move');
    A.equals('font-size-kept', after.fontPts, before.fontPts, 'The size is kept');
    A.assert('family-kept', String(after.computed?.family || '').toLowerCase().includes(fontRoot(before.sourceFont).slice(0, 5)) || fontMatches(String(after.computed?.family || '').split(',')[0], before.sourceFont),
        'The replacement is drawn in the source family', `${after.computed?.family} vs ${before.sourceFont}`);
    if (options.multiline) A.equals('line-count-kept', linesAfter.length, linesBefore.length, 'The line breaks are kept', `${linesAfter.length} lines`);
    if (options.light) A.assert('colour-kept-light', after.computed && after.computed.color.match(/\d+/g).slice(0, 3).every((c) => Number(c) >= 200), 'The text keeps its white colour', after.computed?.color);
    const artifacts = [await capture(page, testId, 'edited')];

    const download = await downloadPdf(page, testId, 'edited');
    const report = summarize(download.path);
    let rect = boxRect(after);
    let typographyRect = null;
    const oldHits = searchCount(download.path, needle);
    B.equals('original-string-gone', hitsInside(oldHits, expandRect(rect, 2), 1).length, 0, `"${needle}" returns zero search hits at the box`, `${oldHits.count} hits on page`);
    const newHits = searchCount(download.path, replacement);
    B.assert('replacement-found', hitsInside(newHits, expandRect(rect, 3), 1).length >= 1, `"${replacement}" is found at the box`, `${newHits.count} hits`);
    if (options.multiline) {
        const pdfLines = lineTextsTouching(report.pages[0], expandRect(rect, 2));
        B.assert('lines-match-editor', linesInOrder(pdfLines, linesAfter), 'The download\'s lines equal the editor\'s lines', `editor ${JSON.stringify(linesAfter)} / download ${JSON.stringify(pdfLines)}`);
        typographyRect = lineHitNear(download.path, linesAfter[0], rect) || rect;
    } else {
        const hit = hitsInside(newHits, expandRect(rect, 3), 1)[0] || null;
        if (hit) rect = expandRect(hit, 0.5);
        const lineChars = charsFiltered(charsAt(download.path, [rect[0] - 1, rect[1] + 1, rect[2] + 1, rect[3] - 1]), before.fontPts);
        B.equals('text-equals-saved', normalize(lineChars.text), normalize(expectedText), 'The extracted text equals what the editor holds, character for character');
        const baseLine = base.report.pages[0].blocks.flatMap((b) => b.lines).find((l) => normalize(l.text).startsWith(prefix));
        if (baseLine && hit) {
            // Search rects follow the face's ascent and descent, which a substitute face shifts a little.
            const tolerance = Math.max(POINT_TOL, (before.fontPts || 12) * 0.06);
            B.assert('in-original-bbox', Math.abs(hit[0] - baseLine.bbox[0]) <= tolerance && Math.abs(hit[1] - baseLine.bbox[1]) <= tolerance, `The replacement sits in the original bbox (±${tolerance.toFixed(2)}pt)`, `${JSON.stringify(hit.map((v) => round(v, 2)))} vs ${JSON.stringify(baseLine.bbox.map((v) => round(v, 2)))}`);
            B.assert('width-within-box', hit[2] <= boxRect(after)[2] + 2, 'The replacement does not overflow the box');
        }
    }
    const chars = checkReplacementTypography(B, download.path, typographyRect || rect, { family: before.sourceFont, sizePt: before.fontPts, bold: isBold(before.sourceFont), light: !!options.light, fitted: !options.multiline }, 0, 'replacement');
    if (options.light) B.assert('white-on-dark', chars.colors.every((c) => c >= 0xEEEEEE) && pixelsAt(download.path, expandRect(rect, 3)).dark_ratio >= 0.5, 'The replacement is white on a still-dark cell', JSON.stringify(chars.colors));
    // The whole box (not just the sampled line) is the touched area.
    checkBackgroundUntouched(B, base, report, expandRect(rectUnion(boxRect(before), boxRect(after)), 1));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean), before, after, download, report, base };
}

async function testEditTitle(ctx) { return runEdit(ctx, 'DrylabNews', 'DrylabNews', 'DrylabNotes'); }
async function testEditWord(ctx) { return runEdit(ctx, 'Welcome to our first newsletter', 'promise', 'pledge', { multiline: true, predicate: (b) => b.promotedBlock && b.text.startsWith('Welcome to our first newsletter') }); }

/** 13 — Replacement text comes only from the saved annotation. */
async function testSavedTextIsTruth(ctx) {
    const { page, recorder, testId, saveRecorder } = ctx;
    const { A, B } = recorder;
    await baseline(page, testId);
    const before = await findBox(page, startsWith('Invoice Number'), 'invoice number row');
    const after = await replaceText(page, before.id, '#20130304', '#77777777');
    const save = await saveDocument(page, saveRecorder);
    const saved = (save?.annotations || []).find((a) => String(a.id) === after.id);
    A.assert('saved-row', !!saved, 'The save payload carries the edited row', saved ? saved.id : JSON.stringify((save?.annotations || []).map((a) => a.id)));
    A.equals('saved-equals-displayed', normalize(saved?.text), after.liveText, 'The saved annotation text equals what the box displays');
    const artifacts = [await capture(page, testId, 'saved')];
    const download = await downloadPdf(page, testId, 'edited');
    const chars = charsAt(download.path, expandRect(boxRect(after), 2));
    B.equals('download-equals-saved', normalize(chars.text), normalize(saved?.text), 'The download extracts the saved text character for character');
    B.equals('original-gone', searchCount(download.path, '#20130304').count, 0, 'The original string returns zero hits anywhere on the page');
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — No boxes, close font (f1040s1 row + drylab title). */
async function testFontCloseNoBoxes(ctx) {
    const { page, recorder, testId, csrfToken } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    const targets = [
        { fixture: 'f1040s1', prefix: 'Taxable refunds', needle: 'Taxable', replacement: 'Taxed' },
        { fixture: 'drylab', prefix: 'DrylabNews', needle: 'News', replacement: 'Notes' },
    ];
    for (const target of targets) {
        // eslint-disable-next-line no-await-in-loop
        const docId = await uploadFixture(page, csrfToken, target.fixture);
        // eslint-disable-next-line no-await-in-loop
        await openEditorWithBoxes(page, docId);
        // eslint-disable-next-line no-await-in-loop
        const before = await findBox(page, startsWith(target.prefix));
        // eslint-disable-next-line no-await-in-loop
        const after = await replaceText(page, before.id, target.needle, target.replacement);
        const family = editorFamily(after);
        A.assert(`${target.fixture}-editor-family`, !!family && !/^(serif|sans-serif|monospace)$/i.test(family), 'The browser draws the replacement in a real face, not a generic fallback', `${after.computed?.family}`);
        A.near(`${target.fixture}-editor-size`, after.computed.sizePx / after.scale, before.fontPts, SIZE_TOL, 'The browser size is the source size');
        // eslint-disable-next-line no-await-in-loop
        const glyphs = await page.evaluate((id) => { const box = Array.from(document.querySelectorAll('.enpv-annotation-box')).find((el) => el.dataset.annotationId === id); const text = box?.querySelector('.enpv-text-content')?.textContent || ''; return { notdef: (text.match(/�/g) || []).length, width: box?.querySelector('.enpv-text-content')?.getBoundingClientRect().width || 0 }; }, after.id);
        A.assert(`${target.fixture}-editor-no-boxes`, glyphs.notdef === 0 && glyphs.width > 0, 'No glyph renders as a box in the browser');
        // eslint-disable-next-line no-await-in-loop
        artifacts.push(await capture(page, testId, `${target.fixture}-edited`));
        // eslint-disable-next-line no-await-in-loop
        const download = await downloadPdf(page, testId, `${target.fixture}-edited`);
        const hit = lineHitNear(download.path, target.replacement, boxRect(after));
        checkReplacementTypography(B, download.path, hit ? expandRect(hit, 0.5) : boxRect(after), { family: before.sourceFont, sizePt: before.fontPts, bold: isBold(before.sourceFont), fitted: true }, 0, target.fixture);
    }
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — Edit on a form. */
async function testEditOnForm(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const heading = await findBox(page, startsWith('Additional Income and Adjustments'), 'form heading');
    const part = await findBox(page, startsWith('Part I'), 'Part I');
    const heading2 = await replaceText(page, heading.id, 'Adjustments', 'Changes');
    A.near('heading-box-kept', heading2.pt.x, heading.pt.x, 0.5, 'The heading box keeps its position');
    A.equals('heading-size-kept', heading2.fontPts, heading.fontPts, 'The heading keeps its size');
    const part2 = await replaceText(page, part.id, 'Part I', 'Part X');
    A.assert('part-stays-white', part2.computed && part2.computed.color.match(/\d+/g).slice(0, 3).every((c) => Number(c) >= 200), 'Part I stays white on its black cell', part2.computed?.color);
    A.near('part-box-kept', part2.pt.y, part.pt.y, 0.5, 'The Part I box keeps its position');
    const widgetsNow = await page.evaluate(() => document.querySelectorAll('.annotationLayer .textWidgetAnnotation, .annotationLayer section').length);
    A.assert('widgets-present', widgetsNow > 0, 'The form widgets are still rendered beside the rows', `${widgetsNow}`);
    const artifacts = [await capture(page, testId, 'edited')];
    const download = await downloadPdf(page, testId, 'edited');
    const report = summarize(download.path);
    const headingHits = searchCount(download.path, 'Additional Income and Changes to Income');
    B.assert('heading-replaced', hitsInside(headingHits, expandRect(boxRect(heading2), 3)).length >= 1, 'The heading replacement is found at its box', `${headingHits.count} hits`);
    checkReplacementTypography(B, download.path, boxRect(heading2), { family: heading.sourceFont, sizePt: heading.fontPts, bold: true }, 0, 'heading');
    const partHits = searchCount(download.path, 'Part X');
    B.assert('part-replaced', hitsInside(partHits, expandRect(boxRect(part2), 3)).length >= 1, 'The Part X replacement is found in the cell', `${partHits.count} hits`);
    const partChars = charsAt(download.path, expandRect(boxRect(part2), 2));
    B.assert('part-white', partChars.colors.every((c) => c >= 0xEEEEEE), 'Part X is drawn in white', JSON.stringify(partChars.colors));
    B.assert('cell-still-dark', pixelsAt(download.path, expandRect(boxRect(part2), 3)).dark_ratio >= 0.5, 'The cell behind it is still black');
    B.equals('widgets-unchanged', JSON.stringify(report.pages[0].widgets.map((w) => [w.name, w.value])), JSON.stringify(base.report.pages[0].widgets.map((w) => [w.name, w.value])), 'All 41 widgets and their values are unchanged', `${report.pages[0].widgets.length}`);
    B.equals('no-notdef', report.pages[0].has_notdef, false, 'No missing-glyph boxes');
    checkBackgroundUntouched(B, base, report, rectUnion(expandRect(boxRect(heading2), 1), expandRect(boxRect(part2), 1)), 0, 'rest');
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 16 — Special glyphs survive (Isartor). */
async function testSpecialGlyphs(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const target = await findBox(page, startsWith('Version 1.0'), 'Isartor version line');
    const neighbours = (await boxes(page)).filter((b) => b.id !== target.id && b.pageIndex === 0);
    const after = await replaceText(page, target.id, '1.0', '1.1');
    A.equals('edited', after.liveText, 'Version 1.1', 'The Verdana line is edited');
    for (const n of neighbours) {
        // eslint-disable-next-line no-await-in-loop
        const now = await boxById(page, n.id);
        A.assert(`neighbour-${n.id.split(':').pop()}`, now && now.text === n.text && Math.abs(now.pt.y - n.pt.y) < 0.25 && now.sourceFont === n.sourceFont, `"${n.text.slice(0, 24)}" keeps its place and face`);
    }
    const artifacts = [await capture(page, testId, 'edited')];
    const download = await downloadPdf(page, testId, 'edited');
    const report = summarize(download.path);
    const hits = searchCount(download.path, 'Version 1.1');
    B.assert('replacement-found', hitsInside(hits, expandRect(boxRect(after), 3)).length >= 1, 'Version 1.1 is found at the box');
    checkReplacementTypography(B, download.path, boxRect(after), { family: target.sourceFont, sizePt: target.fontPts, bold: true }, 0, 'verdana');
    const symbolBefore = base.report.pages[0].fonts.filter((f) => /symbol/i.test(f.name)).map((f) => f.name);
    const symbolAfter = report.pages[0].fonts.filter((f) => /symbol/i.test(f.name)).map((f) => f.name);
    B.equals('symbol-font-kept', symbolAfter.join(','), symbolBefore.join(','), 'The Symbol font is still on the page');
    const edited = expandRect(boxRect(after), 2);
    const symbolSpans = (page0) => page0.blocks.flatMap((b) => b.lines.flatMap((l) => l.spans)).filter((s) => /symbol/i.test(s.font) && !rectsIntersect(s.bbox, edited)).map((s) => `${s.text}@${s.bbox.map((v) => round(v, 1))}`);
    const spansBefore = symbolSpans(base.report.pages[0]);
    const spansAfter = symbolSpans(report.pages[0]);
    B.equals('symbol-spans-identical', spansAfter.join('|'), spansBefore.join('|'), 'Every Symbol-font span outside the edited line keeps its characters and bbox', `${spansAfter.length} spans`);
    checkBackgroundUntouched(B, base, report, expandRect(boxRect(after), 1));
    checkOtherPagesIdentical(B, base, report, 0);
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** Shared delete of a whole row. */
async function runDeleteRow(ctx, prefix, options = {}) {
    const { page, recorder, testId, saveRecorder, docId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, startsWith(prefix), `"${prefix}"`);
    const rect = srcRect(before);
    const neighbourBoxes = (await boxes(page)).filter((b) => (options.neighbours || []).some((n) => b.text.startsWith(n)));
    const masksBefore = (await masks(page)).length;
    const gone = await deleteBox(page, before.id);
    A.assert('row-disappears', !gone, 'The row disappears from the page', gone ? gone.classes : 'gone');
    const whiteMasks = (await masks(page)).filter((m) => m.px.top < before.px.top + before.px.height && m.px.top + m.px.height > before.px.top && m.rgb.every((c) => c >= 250));
    A.equals('no-white-box', whiteMasks.length, 0, 'No white box is painted where the row was', `${(await masks(page)).length - masksBefore} new masks`);
    for (const n of neighbourBoxes) {
        // eslint-disable-next-line no-await-in-loop
        const now = await boxById(page, n.id);
        A.assert(`neighbour-kept-${n.id.split(':').pop()}`, now && now.text === n.text && Math.abs(now.pt.y - n.pt.y) < 0.25, `"${n.text.slice(0, 24)}" keeps its text and place`);
    }
    if (options.reload) {
        await saveDocument(page, saveRecorder);
        await openEditorWithBoxes(page, docId);
        const respawned = (await boxes(page)).find((b) => b.text === before.text);
        A.assert('gone-after-reload', !respawned, 'After save and reload the row is still gone and no handle re-spawns', respawned ? respawned.id : 'none');
    }
    const artifacts = [await capture(page, testId, 'deleted')];
    const download = await downloadPdf(page, testId, 'deleted');
    const report = summarize(download.path);
    const hits = searchCount(download.path, before.text.slice(0, 40));
    B.equals('zero-search-hits', hits.count, 0, 'The deleted text returns zero search hits: removed from the content stream, not hidden');
    B.assert('absent-from-extraction', !report.pages[0].text.includes(before.text.slice(0, 20)), 'The deleted text is absent from get_text()');
    const pixels = pixelsAt(download.path, rect);
    if (options.band) {
        B.assert('band-kept', Math.abs(pixels.nonwhite_ratio - pixelsAt(base.path, rect).nonwhite_ratio) <= 0.35, 'The band behind the row is not replaced by a white box', `nonwhite ${pixels.nonwhite_ratio} (baseline ${pixelsAt(base.path, rect).nonwhite_ratio})`);
    } else {
        B.assert('region-is-background', pixels.dark_ratio <= BACKGROUND_DARK, 'The region reads as background', `dark ${pixels.dark_ratio}`);
    }
    if (options.rulesRect) {
        const rulesBefore = rulesAt(base.path, options.rulesRect);
        const rulesAfter = rulesAt(download.path, options.rulesRect);
        B.equals('rules-intact', rulesAfter.count, rulesBefore.count, 'The ruling lines through and under the row are all still drawn (fill-less redaction)', `${rulesAfter.count} vs ${rulesBefore.count}`);
        const strip = [rect[0], rect[3] - 1, rect[2], rect[3] + 2];
        B.assert('no-white-artifact', Math.abs(pixelsAt(download.path, strip).nonwhite_ratio - pixelsAt(base.path, strip).nonwhite_ratio) <= 0.2, 'No white-box artifact where the row\'s rule runs', `nonwhite ${pixelsAt(download.path, strip).nonwhite_ratio} vs ${pixelsAt(base.path, strip).nonwhite_ratio}`);
    }
    for (const n of neighbourBoxes) {
        const nHits = searchCount(download.path, n.text.slice(0, 40));
        B.assert(`neighbour-in-place-${n.id.split(':').pop()}`, hitsInside(nHits, srcRect(n), 2).length >= 1, `"${n.text.slice(0, 24)}" is still at its bbox`, `${nHits.count} hits`);
    }
    checkBackgroundUntouched(B, base, report, expandRect(rect, 1));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

async function testDeleteScrubbed(ctx) { return runDeleteRow(ctx, 'Watermelon', { reload: true, neighbours: ['Orange', 'Mango'] }); }
async function testDeleteOnForm(ctx) {
    const { page } = ctx;
    const row = await findBox(page, startsWith('Business income'), 'f1040s1 line 3');
    const band = [row.pt.x - 30, row.pt.y - 2, row.pt.x + row.pt.w + 60, row.pt.y + row.pt.h + 4];
    return runDeleteRow(ctx, 'Business income', { band: true, rulesRect: band, neighbours: ['Date of original divorce', 'Other gains'] });
}
async function testDeleteTightRow(ctx) {
    const { page } = ctx;
    const row = await findBox(page, startsWith('2a Alimony'), 'f1040s1 line 2a');
    const band = [row.pt.x - 30, row.pt.y - 2, row.pt.x + row.pt.w + 60, row.pt.y + row.pt.h + 4];
    return runDeleteRow(ctx, '2a Alimony', { band: true, rulesRect: band, neighbours: ['Taxable refunds', 'Date of original divorce'] });
}

/** 20 — Delete a sentence inside a paragraph. */
async function testDeleteSentence(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, (b) => b.promotedBlock && b.text.startsWith('Welcome to our first newsletter'), 'drylab welcome paragraph');
    const after = await deleteTextRange(page, before.id, 'We promise to keep', 'rather long.');
    const lines = await lineTexts(page, after.id);
    A.assert('sentence-gone', !after.liveText.includes('We promise') && !after.liveText.includes('rather long'), 'The sentence is gone from the box');
    A.assert('rest-kept', after.liveText.includes('Welcome to our first newsletter') && after.liveText.includes('The'), 'The rest of the paragraph is kept');
    A.equals('font-size-kept', after.fontPts, before.fontPts, 'The font size is unchanged');
    A.near('box-kept', after.pt.x, before.pt.x, 0.5, 'The box does not move');
    const artifacts = [await capture(page, testId, 'deleted')];
    const download = await downloadPdf(page, testId, 'deleted');
    const report = summarize(download.path);
    B.equals('sentence-zero-hits', searchCount(download.path, 'We promise to keep').count, 0, 'The deleted sentence returns zero hits');
    const pdfLines = lineTextsTouching(report.pages[0], expandRect(boxRect(after), 2));
    B.assert('lines-match-editor', linesInOrder(pdfLines, lines), 'The paragraph extracts with exactly the editor\'s lines', `editor ${JSON.stringify(lines)} / download ${JSON.stringify(pdfLines)}`);
    checkReplacementTypography(B, download.path, lineHitNear(download.path, lines[0], boxRect(after)) || boxRect(after), { family: before.sourceFont, sizePt: before.fontPts }, 0, 'paragraph');
    checkBackgroundUntouched(B, base, report, expandRect(rectUnion(boxRect(before), boxRect(after)), 1));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 21 — The background contract, on a move of the invoice total. */
async function testBackgroundContract(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const before = await findBox(page, startsWith('Total'), 'invoice total');
    const after = await moveBox(page, before.id, -80, 0);
    const touched = expandRect(rectUnion(srcRect(before), boxRect(after)), 2);
    A.assert('touched-recorded', after.moved, 'The acted-on box and its bbox are recorded', JSON.stringify(touched.map((v) => round(v, 1))));
    const artifacts = [await capture(page, testId, 'moved')];
    const download = await downloadPdf(page, testId, 'moved');
    const report = summarize(download.path);
    checkBackgroundUntouched(B, base, report, touched);
    B.equals('fonts-kept', report.pages[0].fonts.map((f) => f.name).filter((n) => base.report.pages[0].fonts.some((f) => f.name === n)).length, base.report.pages[0].fonts.length, 'The original embedded faces are all still listed');
    const pageRect = report.pages[0].rect;
    const strips = [[0, 0, pageRect[2], 100], [0, pageRect[3] - 120, pageRect[2], pageRect[3]], [0, 100, 40, pageRect[3] - 120]].filter((s) => !rectsIntersect(s, touched));
    for (const [i, strip] of strips.entries()) {
        const a = pixelsAt(base.path, strip, 0, 2).dark_ratio;
        const b = pixelsAt(download.path, strip, 0, 2).dark_ratio;
        B.assert(`no-drift-strip-${i}`, Math.abs(a - b) <= 0.01, 'A region away from the touched box has the baseline\'s dark-pixel ratio', `${a} vs ${b}`);
    }
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 22 — Only affected pages are redrawn (drylab and Isartor). */
async function testOnlyAffectedPages(ctx) {
    const { page, recorder, testId, csrfToken } = ctx;
    const { A, B } = recorder;
    const artifacts = [];
    for (const [fixture, prefix, needle, replacement] of [['drylab', 'DrylabNews', 'News', 'Notes'], ['isartor', 'Version 1.0', '1.0', '1.1']]) {
        // eslint-disable-next-line no-await-in-loop
        const docId = await uploadFixture(page, csrfToken, fixture);
        // eslint-disable-next-line no-await-in-loop
        await openEditorWithBoxes(page, docId);
        // eslint-disable-next-line no-await-in-loop
        const base = await baseline(page, testId, `${fixture}-baseline`);
        // eslint-disable-next-line no-await-in-loop
        const before = await findBox(page, startsWith(prefix));
        // eslint-disable-next-line no-await-in-loop
        const after = await replaceText(page, before.id, needle, replacement);
        A.equals(`${fixture}-edited-page-1`, after.pageIndex, 0, 'The edit is on page 1 only');
        // eslint-disable-next-line no-await-in-loop
        artifacts.push(await capture(page, testId, `${fixture}-edited`));
        // eslint-disable-next-line no-await-in-loop
        const download = await downloadPdf(page, testId, `${fixture}-edited`);
        const report = summarize(download.path);
        checkOtherPagesIdentical(B, base, report, 0);
        B.assert(`${fixture}-page-1-changed`, report.pages[0].text_hash !== base.report.pages[0].text_hash, 'Page 1 did change');
        checkBackgroundUntouched(B, base, report, expandRect(boxRect(after), 1), 0, `${fixture}-page1`);
    }
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** Perform one move, one resize, one edit and one delete on the invoice. */
async function fourOperations(page) {
    const moveTarget = await findBox(page, startsWith('Invoice Number'));
    const moved = await moveBox(page, moveTarget.id, 0, 90);
    const resizeTarget = await findBox(page, startsWith('Organic Items'));
    const resized = await resizeBoxRight(page, resizeTarget.id, -100);
    const editTarget = await findBox(page, startsWith('Apple'));
    const edited = await replaceText(page, editTarget.id, 'Apple', 'Apples');
    const deleteTarget = await findBox(page, startsWith('Mango'));
    await deleteBox(page, deleteTarget.id);
    return { moved, resized, edited, deleted: deleteTarget };
}

function stateOf(box) {
    return box ? { text: box.liveText, x: round(box.pt.x, 1), y: round(box.pt.y, 1), w: round(box.pt.w, 1), moved: box.moved } : null;
}

/** 23 — Persistence across save and reload. */
async function testPersistence(ctx) {
    const { page, recorder, testId, saveRecorder, docId } = ctx;
    const { A, B } = recorder;
    await baseline(page, testId);
    const ops = await fourOperations(page);
    await saveDocument(page, saveRecorder);
    const beforeReload = { moved: stateOf(await boxById(page, ops.moved.id)), resized: stateOf(await boxById(page, ops.resized.id)), edited: stateOf(await boxById(page, ops.edited.id)) };
    const masksBefore = (await masks(page)).filter((m) => m.cls.includes('run')).map((m) => m.px);
    const artifacts = [await capture(page, testId, 'before-reload')];
    const download1 = await downloadPdf(page, testId, 'before-reload');
    await openEditorWithBoxes(page, docId);
    const afterReload = { moved: stateOf(await boxById(page, ops.moved.id)), resized: stateOf(await boxById(page, ops.resized.id)), edited: stateOf(await boxById(page, ops.edited.id)) };
    for (const key of ['moved', 'resized', 'edited']) {
        const a = beforeReload[key]; const b = afterReload[key];
        A.assert(`${key}-survives-reload`, a && b && a.text === b.text && Math.abs(a.x - b.x) <= 1 && Math.abs(a.y - b.y) <= 1 && Math.abs(a.w - b.w) <= 1, `The ${key} box comes back with the same geometry and text`, `${JSON.stringify(a)} -> ${JSON.stringify(b)}`);
    }
    A.assert('deleted-stays-gone', !(await boxes(page)).some((b) => b.text === ops.deleted.text), 'The deleted row stays gone');
    const masksAfter = (await masks(page)).filter((m) => m.cls.includes('run')).map((m) => m.px);
    A.assert('mask-still-at-source-bbox', masksAfter.some((m) => masksBefore.some((n) => Math.abs(m.left - n.left) <= MASK_TOL_PX && Math.abs(m.top - n.top) <= 1)), 'The moved row\'s mask is still at the immutable source bbox', JSON.stringify({ before: masksBefore.slice(0, 2), after: masksAfter.slice(0, 2) }));
    artifacts.push(await capture(page, testId, 'after-reload'));
    const download2 = await downloadPdf(page, testId, 'after-reload');
    const r1 = summarize(download1.path); const r2 = summarize(download2.path);
    B.assert('downloads-match', r1.pages[0].text_hash === r2.pages[0].text_hash && sameBlocks(blocksOutside(r1.pages[0], [0, 0, 0, 0]), blocksOutside(r2.pages[0], [0, 0, 0, 0])), 'The download after the reload matches the one before it');
    B.equals('deleted-still-zero-hits', searchCount(download2.path, ops.deleted.text).count, 0, 'The deleted row is still scrubbed after the reload');
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 24 — Undo and redo. */
async function testUndoRedo(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    const base = await baseline(page, testId);
    const original = Object.fromEntries((await boxes(page)).map((b) => [b.id, stateOf(b)]));
    const ops = await fourOperations(page);
    const applied = { moved: stateOf(await boxById(page, ops.moved.id)), edited: stateOf(await boxById(page, ops.edited.id)), resized: stateOf(await boxById(page, ops.resized.id)) };
    const downloadApplied = await downloadPdf(page, testId, 'applied');
    const undoSteps = await undo(page);
    A.assert('undo-stack-covers-operations', undoSteps >= 4, 'Undo walks back through all four operations until the stack is empty', `${undoSteps} undo steps for 4 operations`);
    const undone = Object.fromEntries((await boxes(page)).map((b) => [b.id, stateOf(b)]));
    for (const id of [ops.moved.id, ops.resized.id, ops.edited.id, ops.deleted.id]) {
        const a = original[id]; const b = undone[id];
        A.assert(`undo-restores-${id.split(':').pop()}`, a && b && a.text === b.text && Math.abs(a.x - b.x) <= 0.25 && Math.abs(a.y - b.y) <= 0.25 && Math.abs(a.w - b.w) <= 0.5 && !b.moved, `Undo restores "${a?.text?.slice(0, 20)}"`, `${JSON.stringify(a)} -> ${JSON.stringify(b)}`);
    }
    A.equals('undo-leaves-no-mask', (await masks(page)).filter((m) => m.cls.includes('run')).length, 0, 'No mask is left behind after the undos');
    const artifacts = [await capture(page, testId, 'undone')];
    const downloadUndone = await downloadPdf(page, testId, 'undone');
    const rUndone = summarize(downloadUndone.path);
    B.assert('undo-download-equals-baseline', rUndone.pages[0].text_hash === base.report.pages[0].text_hash && sameBlocks(blocksOutside(rUndone.pages[0], [0, 0, 0, 0]), blocksOutside(base.report.pages[0], [0, 0, 0, 0])), 'After the undos the download is identical to the baseline');
    B.equals('undo-nothing-scrubbed', searchCount(downloadUndone.path, ops.deleted.text).count, searchCount(base.path, ops.deleted.text).count, 'Nothing is scrubbed after the undos');
    const redoSteps = await redo(page);
    A.equals('redo-stack-matches', redoSteps, undoSteps, 'Redo re-applies as many steps as Undo took back');
    const redone = { moved: stateOf(await boxById(page, ops.moved.id)), edited: stateOf(await boxById(page, ops.edited.id)), resized: stateOf(await boxById(page, ops.resized.id)) };
    A.assert('redo-reapplies', ['moved', 'edited', 'resized'].every((k) => JSON.stringify(redone[k]) === JSON.stringify(applied[k])) && !(await boxes(page)).some((b) => b.text === ops.deleted.text), 'Redo re-applies every operation', JSON.stringify({ applied, redone }).slice(0, 400));
    const downloadRedone = await downloadPdf(page, testId, 'redone');
    const rApplied = summarize(downloadApplied.path); const rRedone = summarize(downloadRedone.path);
    B.assert('redo-download-equals-applied', rRedone.pages[0].text_hash === rApplied.pages[0].text_hash, 'After the redos the download equals the one taken before the undos');
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 25 — Zoom is a view, never an input. */
async function testZoom(ctx) {
    const { page, recorder, testId } = ctx;
    const { A, B } = recorder;
    await baseline(page, testId);
    const moveTarget = await findBox(page, startsWith('Invoice Number'));
    const moved = await moveBox(page, moveTarget.id, 0, 90);
    const editTarget = await findBox(page, startsWith('Organic Items'));
    const edited = await replaceText(page, editTarget.id, 'Organic', 'Fresh');
    const reference = { moved: stateOf(moved), edited: stateOf(edited) };
    const artifacts = [];
    const downloads = {};
    for (const zoom of [50, 100, 190]) {
        // eslint-disable-next-line no-await-in-loop
        const reached = await setZoom(page, zoom);
        // The moved mask is held to the contract's ±0.25px at the reference
        // zoom; at other levels a little more than a CSS pixel is tolerated.
        const maskTol = zoom === 190 ? MASK_TOL_PX : 1.5;
        // eslint-disable-next-line no-await-in-loop
        const m = await boxById(page, moved.id); const e = await boxById(page, edited.id);
        A.assert(`geometry-holds-at-${zoom}`, m && e && Math.abs(m.pt.x - reference.moved.x) <= 0.3 && Math.abs(m.pt.y - reference.moved.y) <= 0.3 && Math.abs(e.pt.x - reference.edited.x) <= 0.3, `PDF-point geometry is unchanged at ${zoom}%`, `at ${reached}%: ${JSON.stringify({ moved: stateOf(m), edited: stateOf(e) })}`);
        // eslint-disable-next-line no-await-in-loop
        const run = (await masks(page)).find((x) => x.cls.includes('run'));
        const expectedLeft = moveTarget.src.x * (m?.scale || 1);
        A.assert(`mask-holds-at-${zoom}`, run && Math.abs(run.px.left - expectedLeft) <= maskTol && Math.abs((run.px.left + run.px.width) - (moveTarget.src.x + moveTarget.src.w) * m.scale) <= maskTol, `The moved mask still equals the source bbox at ${zoom}% (±${maskTol}px)`, run ? `at ${reached}%: left ${run.px.left.toFixed(2)} vs ${expectedLeft.toFixed(2)}, right ${(run.px.left + run.px.width).toFixed(2)} vs ${((moveTarget.src.x + moveTarget.src.w) * m.scale).toFixed(2)}` : 'no mask');
        A.assert(`text-inside-box-at-${zoom}`, e && e.liveText.startsWith('Fresh'), 'The replacement stays in its box');
        // eslint-disable-next-line no-await-in-loop
        artifacts.push(await capture(page, testId, `zoom-${reached}`));
        if (zoom === 50 || zoom === 190) {
            // eslint-disable-next-line no-await-in-loop
            downloads[zoom] = summarize((await downloadPdf(page, testId, `zoom-${zoom}`)).path);
        }
    }
    B.assert('downloads-identical', downloads[50].pages[0].text_hash === downloads[190].pages[0].text_hash && sameBlocks(blocksOutside(downloads[50].pages[0], [0, 0, 0, 0]), blocksOutside(downloads[190].pages[0], [0, 0, 0, 0])), 'The downloads at the lowest and highest zoom are identical: zoom is a view, never an input');
    const line = downloads[190].pages[0].blocks.flatMap((b) => b.lines).find((l) => normalize(l.text).startsWith('Invoice Number'));
    B.assert('matches-editor-geometry', line && Math.abs(line.bbox[0] - reference.moved.x) <= 2 && Math.abs(line.bbox[1] - reference.moved.y) <= 4, 'The moved row sits at the editor\'s PDF-point geometry', JSON.stringify(line?.bbox));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-setup-and-baseline', number: '01', title: 'Setup: each fixture uploads, extracts and opens with its text promoted; the untouched download is the baseline', run: testSetup, fixture: null },
    { id: '02-select-and-enter-edit', number: '02', title: 'Selecting existing text makes a source box on the glyph bounds; entering Edit changes nothing', run: testSelectAndEnterEdit, fixture: 'invoice' },
    { id: '03-untouched', number: '03', title: 'Untouched text is untouched: Edit on and off, save, download equals the baseline', run: testUntouched, fixture: 'invoice' },
    { id: '04-move-single-line', number: '04', title: 'Move a single-line row: one solid mask equal to the source bbox, text keeps font, size and colour', run: testMoveSingleLine, fixture: 'invoice' },
    { id: '05-move-paragraph', number: '05', title: 'Move a multi-line paragraph: lines and runs travel with it, neighbours untouched', run: testMoveParagraph, fixture: 'drylab' },
    { id: '06-move-out-of-cell', number: '06', title: 'Move text out of a filled form cell: the mask repaints the cell dark, the text stays white', run: testMoveOutOfCell, fixture: 'f1040s1' },
    { id: '07-move-on-rules', number: '07', title: 'Move text between tight rows: the mask stays inside its bbox, rules and neighbours intact', run: testMoveOnRules, fixture: 'f1040s1' },
    { id: '08-moved-text-contract', number: '08', title: 'Moved text in the download: old place scrubbed, found at the new place, rest unchanged', run: testMoveContract, fixture: 'invoice' },
    { id: '09-resize-paragraph', number: '09', title: 'Resize a paragraph: reflow at the same size, the download reflows the same way', run: testResizeParagraph, fixture: 'drylab' },
    { id: '10-resize-title', number: '10', title: 'Resize a single-line title narrower: the type is not scaled, the export matches', run: testResizeTitle, fixture: 'invoice' },
    { id: '11-edit-title', number: '11', title: 'Edit a single-span title: exact source-line fitting in the original weight', run: testEditTitle, fixture: 'drylab' },
    { id: '12-edit-word', number: '12', title: 'Edit a word inside a paragraph: runs and breaks kept, export matches', run: testEditWord, fixture: 'drylab' },
    { id: '13-saved-text-is-truth', number: '13', title: 'Replacement text comes only from the saved annotation', run: testSavedTextIsTruth, fixture: 'invoice' },
    { id: '14-font-close-no-boxes', number: '14', title: 'No boxes, close font: original family or its substitute at the original size and weight', run: testFontCloseNoBoxes, fixture: null },
    { id: '15-edit-on-form', number: '15', title: 'Edit on a form: substitution keeps size and weight, white-on-black renders, widgets untouched', run: testEditOnForm, fixture: 'f1040s1' },
    { id: '16-special-glyphs', number: '16', title: 'Special glyphs survive an edit of the neighbouring text (Isartor)', run: testSpecialGlyphs, fixture: 'isartor' },
    { id: '17-delete-scrubbed', number: '17', title: 'Delete is scrubbed: zero hits, absent from extraction, gone after reload', run: testDeleteScrubbed, fixture: 'invoice' },
    { id: '18-delete-on-form', number: '18', title: 'Delete on a form leaves the artwork and the widgets', run: testDeleteOnForm, fixture: 'f1040s1' },
    { id: '19-delete-tight-row', number: '19', title: 'Delete one row of a tight stack; the rows above and below stay', run: testDeleteTightRow, fixture: 'f1040s1' },
    { id: '20-delete-sentence', number: '20', title: 'Delete a sentence inside a paragraph; the rest keeps its layout', run: testDeleteSentence, fixture: 'drylab' },
    { id: '21-background-untouched', number: '21', title: 'The background is untouched on a download', run: testBackgroundContract, fixture: 'invoice' },
    { id: '22-only-affected-pages', number: '22', title: 'Only affected pages are redrawn (drylab, Isartor)', run: testOnlyAffectedPages, fixture: null },
    { id: '23-persistence', number: '23', title: 'Move, resize, edit and delete survive save and reload, and download the same', run: testPersistence, fixture: 'invoice' },
    { id: '24-undo-redo', number: '24', title: 'Undo and redo restore each state; the download after undo equals the baseline', run: testUndoRedo, fixture: 'invoice' },
    { id: '25-zoom', number: '25', title: 'Zoom: geometry and masks hold at 50%, 100% and 190%; downloads are identical', run: testZoom, fixture: 'invoice' },
];

function summarise(test, checks, artifacts, error, startedAt) {
    const passed = checks.filter((c) => c.result === 'PASS').length;
    const skipped = checks.filter((c) => c.result === 'SKIP').length;
    const total = checks.length - skipped;
    let status = 'passed';
    if (error) status = 'error';
    else if (checks.length === 0) status = 'error';
    else if (passed < total) status = 'failed';
    const component = (name) => {
        const own = checks.filter((c) => c.component === name && c.result !== 'SKIP');
        return { total: own.length, passed: own.filter((c) => c.result === 'PASS').length };
    };
    return {
        id: test.id, number: test.number, title: test.title, status, checks,
        checks_passed: passed, checks_total: total, checks_skipped: skipped,
        components: { editor: component('editor'), download: component('download') },
        artifacts: artifacts || [], error: error ? String(error.message || error) : null,
        duration_ms: Date.now() - startedAt,
    };
}

async function runTests(ids) {
    const selected = TESTS.filter((test) => ids.includes(test.id));
    if (selected.length === 0) return { success: false, message: 'No known test ids requested', results: [] };
    let browser = null;
    const results = [];
    const runStartedAt = Date.now();
    try {
        browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
        for (const test of selected) {
            const startedAt = Date.now();
            const recorder = createRecorder();
            let context = null;
            let docId = null;
            try {
                context = await browser.newContext({ viewport: { width: 1920, height: 1200 }, ignoreHTTPSErrors: true, acceptDownloads: true });
                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => { route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {}); });
                const page = await context.newPage();
                const saveRecorder = attachSaveRecorder(page);
                const csrfToken = await fetchCsrfToken(page);
                if (test.fixture) {
                    docId = await uploadFixture(page, csrfToken, test.fixture);
                    await openEditorWithBoxes(page, docId);
                }
                const outcome = await test.run({ page, recorder, testId: test.id, docId, csrfToken, saveRecorder });
                results.push({ ...summarise(test, outcome.checks, outcome.artifacts, null, startedAt), document_id: docId });
            } catch (error) {
                results.push({ ...summarise(test, recorder.checks, [], error, startedAt), document_id: docId });
            } finally {
                if (context) { try { await context.close(); } catch (_) { /* ignore */ } }
            }
        }
    } catch (error) {
        return { success: false, message: String(error.message || error), results };
    } finally {
        if (browser) { try { await browser.close(); } catch (_) { /* ignore */ } }
    }
    const checksTotal = results.reduce((sum, r) => sum + r.checks_total, 0);
    const checksPassed = results.reduce((sum, r) => sum + r.checks_passed, 0);
    return {
        success: true, results,
        summary: {
            tests_total: results.length,
            tests_passed: results.filter((r) => r.status === 'passed').length,
            tests_failed: results.filter((r) => r.status === 'failed').length,
            tests_errored: results.filter((r) => r.status === 'error').length,
            checks_total: checksTotal, checks_passed: checksPassed,
            duration_ms: Date.now() - runStartedAt,
        },
    };
}

module.exports = { TESTS, BASE_URL, FIXTURES, PYTHON, inspect, summarize, uploadFixture, openEditor, openEditorWithBoxes, boxes, masks, lineTexts, selectBox, moveBox, replaceText, deleteBox, resizeBoxRight, downloadPdf, runTests };

if (require.main === module) {
    const argv = process.argv.slice(2);
    if (argv.includes('--list')) {
        process.stdout.write(`${JSON.stringify({ success: true, tests: TESTS.map((t) => ({ id: t.id, number: t.number, title: t.title })) })}\n`);
    } else {
        const runAll = argv.includes('--run-all');
        const runIndex = argv.indexOf('--run');
        const ids = runAll ? TESTS.map((t) => t.id) : (runIndex >= 0 ? String(argv[runIndex + 1] || '').split(',').map((s) => s.trim()).filter(Boolean) : []);
        if (ids.length === 0) {
            process.stdout.write(`${JSON.stringify({ success: false, message: 'Nothing to run. Use --list, --run <ids> or --run-all.' })}\n`);
        } else {
            runTests(ids)
                .then((payload) => process.stdout.write(`${JSON.stringify(payload)}\n`))
                .catch((error) => process.stdout.write(`${JSON.stringify({ success: false, message: String(error.message || error), results: [] })}\n`));
        }
    }
}
