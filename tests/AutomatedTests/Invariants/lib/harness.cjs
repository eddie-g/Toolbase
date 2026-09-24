/**
 * Browser + editor harness for the invariant tester.
 *
 * Copied/adapted from tests/AutomatedTests/SourceText/run_source_text_tests.cjs
 * and the nk8131 QA harness (upload, signed-in shim, openEditorWithBoxes,
 * selectBox, enterEdit, commitEdit, downloadPdf). Everything here drives the
 * live app at BASE_URL on FRESH guest uploads only; nothing opens an existing
 * document.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..', '..', '..');
const localBrowsers = path.join(ROOT, 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
const { chromium } = require(path.join(ROOT, 'node_modules', 'playwright'));

const BASE_URL = process.env.INVARIANTS_BASE_URL || 'http://localhost:8081';
const VENV_PYTHON = path.join(ROOT, '.venv', 'bin', 'python');
const PYTHON = fs.existsSync(VENV_PYTHON) ? VENV_PYTHON : 'python3';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** The editor bundle currently served (the main session rebuilds it; never hard-code). */
function bundleName() {
    try {
        const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'public', 'build', 'manifest.json'), 'utf8'));
        const entry = manifest['resources/js/edit-new-pdfjs/main.js'];
        return entry ? path.basename(entry.file) : 'unknown';
    } catch (_) {
        return 'unknown';
    }
}

async function launch({ headless = true } = {}) {
    const browser = await chromium.launch({ headless, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    const context = await browser.newContext({ viewport: { width: 1500, height: 1100 }, deviceScaleFactor: 1, acceptDownloads: true });
    // No network fonts: the tester runs offline-stable (Google Fonts would otherwise race the layout).
    await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {}));
    const page = await context.newPage();
    page.__errors = [];
    page.on('pageerror', (e) => page.__errors.push(String(e?.message || e).slice(0, 300)));
    page.__saves = [];
    page.on('request', (request) => {
        try {
            if (request.method() !== 'POST' || !request.url().includes('/save-annotation-state')) return;
            let body = {};
            try { body = request.postDataJSON() || {}; } catch (_) { body = {}; }
            page.__saves.push({ t: Date.now(), annotations: Array.isArray(body.annotations) ? body.annotations : [] });
        } catch (_) { /* ignore */ }
    });
    return { browser, context, page };
}

async function fetchCsrfToken(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const token = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('input[name="_token"]')?.value || null);
    if (!token) throw new Error('no csrf token on /pdf-editor');
    return token;
}

let uploadCounter = 0;
/** Upload a COPY of filePath as a new guest document; returns the new document id. */
async function upload(page, filePath, tag = 'inv') {
    const csrf = await fetchCsrfToken(page);
    uploadCounter += 1;
    const base = path.basename(filePath).replace(/[^\w.-]+/g, '_').replace(/\.pdf$/i, '').slice(0, 40);
    const response = await page.request.post(`${BASE_URL}/documents`, {
        multipart: { _token: csrf, document: { name: `inv_${tag}_${base}_${process.pid}_${uploadCounter}.pdf`, mimeType: 'application/pdf', buffer: fs.readFileSync(filePath) } },
        maxRedirects: 0,
        timeout: 120000,
    });
    const location = response.headers().location || response.url() || '';
    const m = location.match(/\/documents\/(\d+)/);
    if (!m) throw new Error(`upload failed: HTTP ${response.status()} ${(await response.text().catch(() => '')).slice(0, 200)}`);
    return Number(m[1]);
}

async function installSignedInShim(page) {
    if (page.__shim) return;
    page.__shim = true;
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
    await page.waitForFunction(() => !!window.__enpv?.pdfViewer, null, { timeout: 60000 });
    await page.addStyleTag({ content: '*{caret-color:transparent !important}' });
    await page.evaluate(() => {
        const toggle = document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode');
        if (toggle && !document.body.classList.contains('enpv-edit-on')) toggle.click();
    });
    await page.waitForFunction(() => document.body.classList.contains('enpv-edit-on') || document.body.classList.contains('enpv-edit-rotation-blocked'), null, { timeout: 15000 });
    if (await page.evaluate(() => document.body.classList.contains('enpv-edit-rotation-blocked') && !document.body.classList.contains('enpv-edit-on'))) {
        throw new Error('SKIP: Edit PDF is unavailable (rotated page shown flattened; by design)');
    }
    await sleep(1200);
}

/** Open the editor; reload until extraction has produced boxes (Horizon extracts within seconds). */
async function openEditorWithBoxes(page, docId, timeoutMs = 150000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        await openEditor(page, docId); // throws SKIP: ... when editing is blocked by design
        const n = await page.evaluate(() => document.querySelectorAll('.enpv-annotation-box').length);
        const ready = await page.evaluate(() => document.querySelectorAll('.pdfViewer .page').length);
        if (n > 0 && ready > 0) return n;
        await sleep(3000);
    }
    return 0;
}

async function pageCount(page) {
    return page.evaluate(() => window.__enpv?.pdfViewer?.pagesCount || document.querySelectorAll('.pdfViewer .page').length);
}

async function scrollToPage(page, n) {
    await page.evaluate((num) => document.querySelector(`.pdfViewer .page[data-page-number="${num}"]`)?.scrollIntoView({ block: 'start' }), n);
    await sleep(900);
    await page.waitForFunction((num) => {
        const p = document.querySelector(`.pdfViewer .page[data-page-number="${num}"]`);
        return p && p.querySelector('canvas') && p.dataset.loaded !== undefined ? true : !!p?.querySelector('canvas');
    }, n, { timeout: 30000 }).catch(() => {});
    await sleep(400);
}

function boxSel(id) { return `.enpv-annotation-box[data-annotation-id="${id}"]`; }

/** Inventory of the boxes currently in the DOM (page geometry in PDF points). */
function boxes(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('.enpv-annotation-box')).map((box) => {
        const pageDiv = box.closest('.page');
        const pr = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0 };
        const r = box.getBoundingClientRect();
        const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 1;
        const content = box.querySelector('.enpv-text-content');
        const st = content ? getComputedStyle(content) : null;
        const d = box.dataset;
        return {
            id: d.annotationId || '',
            page: Number(pageDiv?.dataset?.pageNumber || (Number(d.pageIndex || 0) + 1)),
            type: d.annotationType || '',
            promoted: box.classList.contains('is-promoted-source-block'),
            editing: box.classList.contains('is-editing'),
            selected: box.classList.contains('is-selected'),
            text: String(content?.textContent || '').replace(/[\s ]+/g, ' ').trim(),
            baseText: String(d.baseText || d.originalText || '').replace(/\s+/g, ' ').trim(),
            scale,
            pt: { x: +((r.left - pr.left) / scale).toFixed(2), y: +((r.top - pr.top) / scale).toFixed(2), w: +(r.width / scale).toFixed(2), h: +(r.height / scale).toFixed(2) },
            fontPx: st ? Number.parseFloat(st.fontSize) : null,
            family: st ? st.fontFamily.split(',')[0].replace(/["']/g, '') : '',
            visible: r.width > 0 && r.height > 0,
        };
    }));
}

async function boxById(page, id) { return (await boxes(page)).find((b) => b.id === id) || null; }

async function centerBox(page, id) {
    await page.evaluate((sel) => document.querySelector(sel)?.scrollIntoView({ block: 'center', inline: 'center' }), boxSel(id));
    await sleep(250);
}

/** Select with a real mouse click near the box's top-left interior. */
async function clickSelect(page, id) {
    await centerBox(page, id);
    const b = await page.locator(boxSel(id)).first().boundingBox();
    if (!b) throw new Error(`box ${id} not visible`);
    await page.mouse.click(b.x + Math.min(6, b.width / 2), b.y + Math.min(6, b.height / 2));
    await sleep(350);
}

/** Selection through synthetic pointer events (fallback when the click lands on chrome). */
async function selectBox(page, id) {
    await centerBox(page, id);
    const loc = page.locator(boxSel(id)).first();
    const b = await loc.boundingBox();
    if (!b) throw new Error(`cannot select ${id}`);
    const point = { clientX: b.x + Math.min(8, Math.max(2, b.width / 2)), clientY: b.y + Math.min(8, Math.max(2, b.height / 2)), pointerId: 41, button: 0 };
    await loc.dispatchEvent('pointerdown', point);
    await page.evaluate((init) => window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, cancelable: true, ...init })), point);
    await sleep(300);
}

function isEditing(page, id) {
    return page.evaluate((sel) => {
        const box = document.querySelector(sel);
        return !!(box?.classList.contains('is-editing') && box.querySelector('.enpv-text-content')?.isContentEditable);
    }, boxSel(id));
}

async function waitEditing(page, id, timeout = 8000) {
    await page.waitForFunction((sel) => {
        const box = document.querySelector(sel);
        return box?.classList.contains('is-editing') && box.querySelector('.enpv-text-content')?.isContentEditable;
    }, boxSel(id), { timeout });
    await sleep(300);
}

/** Enter edit through the box menu (Edit action). */
async function enterEditMenu(page, id) {
    await selectBox(page, id);
    const item = page.locator('#enpv-ann-menu [data-action="edit"]').first();
    if (await item.count()) {
        const bb = await item.boundingBox().catch(() => null);
        if (bb) await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
        else await item.dispatchEvent('pointerdown');
    }
    if (!(await isEditing(page, id))) await item.dispatchEvent('pointerdown').catch(() => {});
    await waitEditing(page, id);
}

/** Enter edit like a user: click the box, then click into the text (falls back to a double-click). */
async function enterEditClick(page, id, point) {
    await clickSelect(page, id);
    if (point) await page.mouse.click(point.x, point.y);
    await sleep(350);
    if (!(await isEditing(page, id)) && point) {
        await page.mouse.dblclick(point.x, point.y);
        await sleep(400);
    }
    await waitEditing(page, id);
}

/** Leave edit / deselect: click on empty page margin outside every box. */
async function clickOutside(page) {
    await page.mouse.click(4, 400);
    await sleep(900);
}

async function persisted(page, id) {
    return page.evaluate((wanted) => {
        try { return JSON.stringify(window.__enpv.persistedAnnotation(wanted)); } catch (_) { return null; }
    }, id);
}

async function persistedMany(page, ids) {
    return page.evaluate((list) => {
        const out = {};
        for (const id of list) { try { out[id] = JSON.stringify(window.__enpv.persistedAnnotation(id)); } catch (_) { out[id] = null; } }
        return out;
    }, ids);
}

/** Download through the editor's own button; replays the captured POST to get the bytes. */
async function downloadPdf(page, filePath) {
    const requestPromise = page.waitForRequest((r) => r.method() === 'POST' && /\/download-annotated-pdf/.test(r.url()), { timeout: 90000 });
    const popupPromise = page.context().waitForEvent('page', { timeout: 20000 }).catch(() => null);
    await page.locator('#download-pdf-btn').click();
    const request = await requestPromise;
    const body = request.postData() || '';
    const headers = {};
    for (const [name, value] of Object.entries(await request.allHeaders())) {
        const lower = name.toLowerCase();
        if (lower.startsWith(':') || ['content-length', 'host', 'connection', 'x-export-mode'].includes(lower)) continue;
        headers[name] = value;
    }
    const replay = await page.context().request.post(request.url(), { data: body, headers, timeout: 180000 });
    if (!replay.ok()) throw new Error(`download failed ${replay.status()}`);
    fs.writeFileSync(filePath, await replay.body());
    if (/\.pdf$/i.test(filePath)) { try { fs.writeFileSync(filePath.replace(/\.pdf$/i, '.payload.json'), body); } catch (_) { /* evidence only */ } }
    const popup = await popupPromise;
    if (popup) await popup.close().catch(() => {});
    await sleep(400);
    return filePath;
}

module.exports = {
    ROOT, BASE_URL, PYTHON, sleep, bundleName, launch, upload, openEditor, openEditorWithBoxes, pageCount,
    scrollToPage, boxSel, boxes, boxById, centerBox, clickSelect, selectBox, isEditing, waitEditing,
    enterEditMenu, enterEditClick, clickOutside, persisted, persistedMany, downloadPdf,
};
