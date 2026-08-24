/**
 * Text Tool Automated Tests (Playwright)
 *
 * Automates part of the "[QA] - Text tool" Asana task
 * (Netkit -> Claude QA Agent). Each run creates a fresh blank PDF, uploads it
 * as a new document, opens it in the pdf.js editor, and drives the real UI.
 *
 * Usage:
 *   node run_text_tests.cjs --list
 *   node run_text_tests.cjs --run 01-blank-project-loads,03-click-to-place-defaults
 *   node run_text_tests.cjs --run-all
 *
 * Output is a single JSON document on stdout so the Laravel controller can
 * parse it. Nothing else may be written to stdout.
 */

const fs = require('fs');
const path = require('path');

process.umask(0o000);

// Playwright must find the browsers bundled in node_modules rather than
// ~/.cache/ms-playwright, which does not exist in the app container.
const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

// Always localhost:80 from inside the container — APP_URL carries the host port.
const BASE_URL = process.env.AUTOMATED_TEST_BASE_URL || 'http://localhost';
const ARTIFACT_DIR = path.resolve(__dirname, 'artifacts');

const PAGE_WIDTH_PTS = 612;
const PAGE_HEIGHT_PTS = 792;

/**
 * What createNewTextAnnotation() in resources/js/edit-new-pdfjs/main.js
 * produces for a click-placed box. Asserted by test 03, so a change to the
 * defaults has to be made deliberately in both places.
 */
const PLACEMENT_DEFAULTS = {
    fontSize: 12,
    fontFamily: 'Helvetica',
    textColor: '#000000',
    widthPts: 36,
    heightPts: Math.ceil(12 * 1.25), // 15
    textAlign: 'left',
    verticalAlign: 'top',
    opacity: 1,
};

/**
 * A drag only sizes the box when it moved and ended larger than this; below
 * the threshold the tool falls back to click-to-place. Dragged boxes are then
 * floored at MIN_DRAG_* canvas px. Mirrors finishAddTextCreation().
 */
const DRAG_THRESHOLD_PX = { width: 20, height: 10 };
const MIN_DRAG_PX = { width: 60, height: 28 };

// A placed box is a user-created annotation box with no other annotation type.
const TEXT_BOX_SELECTOR = '.enpv-annotation-box[data-user-created="1"]:not([data-annotation-type])';

// ---------------------------------------------------------------------------
// Check helpers
// ---------------------------------------------------------------------------

/**
 * Collects PASS/FAIL rows for one test. Same shape as the signature runner so
 * the admin page can render either suite.
 */
function createRecorder() {
    const checks = [];

    const push = (item, passed, description, detail) => {
        checks.push({
            result: passed ? 'PASS' : 'FAIL',
            item,
            description,
            detail: detail == null ? '' : String(detail),
        });
        return passed;
    };

    return {
        checks,
        ok: (item, description, detail) => push(item, true, description, detail),
        fail: (item, description, detail) => push(item, false, description, detail),
        assert: (item, condition, description, detail) => push(item, !!condition, description, detail),
        equals: (item, actual, expected, description) => push(
            item,
            actual === expected,
            description,
            actual === expected
                ? `= ${JSON.stringify(actual)}`
                : `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`,
        ),
        /** Numeric comparison with an explicit tolerance, reported either way. */
        near: (item, actual, expected, tolerance, description) => push(
            item,
            Number.isFinite(actual) && Math.abs(actual - expected) <= tolerance,
            description,
            `expected ${expected} ±${tolerance}, got ${actual}`,
        ),
    };
}

function ensureArtifactDir() {
    if (!fs.existsSync(ARTIFACT_DIR)) {
        fs.mkdirSync(ARTIFACT_DIR, { recursive: true, mode: 0o777 });
    }
}

/** Screenshot for the UI. A screenshot failure never fails the test itself. */
async function capture(page, testId, label) {
    try {
        ensureArtifactDir();
        const filename = `${testId}__${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`;
        const filePath = path.join(ARTIFACT_DIR, filename);
        await page.screenshot({ path: filePath, fullPage: false });
        try { fs.chmodSync(filePath, 0o666); } catch (_) { /* best effort */ }
        return { label, filename };
    } catch (_) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Document setup
// ---------------------------------------------------------------------------

/**
 * Build a minimal valid 612x792 PDF as raw bytes.
 *
 * Written by hand rather than shelling out to PyMuPDF so the runner has no
 * Python dependency — the app container's bare `python3` has no fitz module.
 */
function buildBlankPdfBuffer(label, pageCount = 1) {
    const title = String(label || 'Text tool automated test').replace(/[()\\]/g, '');
    const pages = Math.max(1, Number(pageCount) || 1);

    const pageObjectNumber = (index) => 4 + (index * 2);
    const kids = Array.from({ length: pages }, (_, i) => `${pageObjectNumber(i)} 0 R`).join(' ');

    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        `<< /Type /Pages /Kids [${kids}] /Count ${pages} >>`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    for (let i = 0; i < pages; i++) {
        const contentStream = `BT /F1 12 Tf 24 ${PAGE_HEIGHT_PTS - 36} Td (${title} - page ${i + 1}) Tj ET\n`;
        objects.push(
            `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_WIDTH_PTS} ${PAGE_HEIGHT_PTS}] `
            + `/Resources << /Font << /F1 3 0 R >> >> /Contents ${pageObjectNumber(i) + 1} 0 R >>`,
        );
        objects.push(
            `<< /Length ${Buffer.byteLength(contentStream, 'latin1')} >>\nstream\n${contentStream}endstream`,
        );
    }

    let pdf = '%PDF-1.4\n';
    const offsets = [];
    objects.forEach((body, index) => {
        offsets.push(Buffer.byteLength(pdf, 'latin1'));
        pdf += `${index + 1} 0 obj\n${body}\nendobj\n`;
    });

    const xrefOffset = Buffer.byteLength(pdf, 'latin1');
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    offsets.forEach((offset) => {
        pdf += `${String(offset).padStart(10, '0')} 00000 n \n`;
    });
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF\n`;

    return Buffer.from(pdf, 'latin1');
}

async function fetchCsrfToken(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const token = await page.evaluate(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const input = document.querySelector('input[name="_token"]');
        return input ? input.value : null;
    });
    if (!token) throw new Error('Could not extract CSRF token from /pdf-editor');
    return token;
}

let uploadCounter = 0;

/**
 * Upload a blank PDF and return the new document id.
 *
 * The filename is unique per upload: the app rejects a duplicate original_name
 * with a 409 for signed-in callers, and a collision would otherwise turn into
 * a confusing "no document id" failure on a re-run.
 */
async function createBlankDocument(page, csrfToken, label, pageCount = 1) {
    uploadCounter += 1;
    const name = `text_tool_test_${process.pid}_${uploadCounter}.pdf`;

    const response = await page.request.post(`${BASE_URL}/documents`, {
        multipart: {
            _token: csrfToken,
            document: {
                name,
                mimeType: 'application/pdf',
                buffer: buildBlankPdfBuffer(label, pageCount),
            },
        },
        maxRedirects: 0,
    });

    const location = response.headers()['location'] || '';
    const fromLocation = location.match(/\/documents\/(\d+)/);
    if (fromLocation) return parseInt(fromLocation[1], 10);

    const fromUrl = String(response.url() || '').match(/\/documents\/(\d+)/);
    if (fromUrl) return parseInt(fromUrl[1], 10);

    const body = await response.text().catch(() => '');
    throw new Error(`Failed to create document (HTTP ${response.status()}): ${body.slice(0, 200)}`);
}

/**
 * Open the pdf.js editor and wait until page 1 has actually rasterised.
 * Returns the console errors collected during load.
 */
async function openEditor(page, docId) {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error && error.message ? error.message : error)));

    await page.goto(`${BASE_URL}/documents/${docId}/edit-new?pdfjs=1`, { waitUntil: 'load', timeout: 45000 });
    await page.waitForSelector('#viewer', { timeout: 30000 });
    await page.waitForSelector('#viewer .page canvas', { timeout: 30000 });
    await page.waitForFunction(() => {
        const canvas = document.querySelector('#viewer .page canvas');
        return !!canvas && canvas.width > 0 && canvas.height > 0;
    }, { timeout: 20000 });
    // Let the tool bindings attach before driving the UI.
    await page.waitForTimeout(600);

    return consoleErrors;
}

/**
 * Render the editor as a signed-in user.
 *
 * Edit mode is a premium feature, and a committed user-created text box is only
 * interactive while edit mode or the Add Text tool is on. A guest therefore
 * cannot re-select a box they have just committed, which blocks every case
 * about restyling or reselecting existing text. The editor reads its own
 * authentication from one data attribute, so the response is rewritten the same
 * way the signature suite does it — see renderEditorAsSignedIn there.
 *
 * This is a shim for the premium gate only. Tests that are about the guest
 * experience (01-08) must NOT use it.
 */
async function openEditorAsSignedIn(page, docId) {
    await page.route(/\/documents\/\d+\/edit-new/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(
            'data-editor-authenticated="0"',
            'data-editor-authenticated="1"',
        );
        await route.fulfill({ response, body });
    });

    const consoleErrors = await openEditor(page, docId);

    const flag = await page.evaluate(() => document
        .getElementById('edit-new-root')?.dataset?.editorAuthenticated
        ?? document.querySelector('[data-editor-authenticated]')?.dataset?.editorAuthenticated);
    if (flag !== '1') throw new Error(`Editor did not render as signed in (flag=${flag})`);

    return consoleErrors;
}

// ---------------------------------------------------------------------------
// Editor probes
// ---------------------------------------------------------------------------

/** Current viewer scale, for converting PDF points to CSS pixels. */
function viewerScale(page) {
    return page.evaluate(() => Number(window.__enpv?.pdfViewer?.currentScale) || 0);
}

function addTextModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-add-text-on'));
}

function editModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-edit-on'));
}

/**
 * Toggle edit mode through its real control.
 *
 * A committed user-created text box is only interactive while edit mode or the
 * Add Text tool is on — see the allowInteraction test in renderAnnotationBoxLayer
 * — so anything that re-selects a box has to turn one of them on first.
 */
async function setEditMode(page, on) {
    if ((await editModeOn(page)) === on) return;
    // Same story as the add-text control: the top bar collapses at narrower
    // widths, so fall back to the floating toolbar's toggle.
    const selector = await page.evaluate(() => {
        const top = document.getElementById('edit-mode-toggle');
        if (top && top.offsetParent) return '#edit-mode-toggle';
        const floating = document.getElementById('ftb-edit-mode');
        return floating && floating.offsetParent ? '#ftb-edit-mode' : null;
    });
    if (!selector) throw new Error('No visible edit-mode control');
    await page.click(selector);
    await page.waitForFunction(
        (wanted) => document.body.classList.contains('enpv-edit-on') === wanted,
        on,
        { timeout: 15000 },
    );
    await page.waitForTimeout(700);
}

/**
 * Which add-text control to drive.
 *
 * The top bar collapses at narrower widths, so the floating toolbar button is
 * the fallback. Both drive the same mode.
 */
async function addTextControl(page, prefer = 'auto') {
    return page.evaluate((wanted) => {
        const visible = (el) => !!el && !!el.offsetParent;
        const top = document.getElementById('add-text-btn');
        const floating = document.getElementById('ftb-add-text');
        if (wanted === 'top') return visible(top) ? '#add-text-btn' : null;
        if (wanted === 'floating') return visible(floating) ? '#ftb-add-text' : null;
        if (visible(top)) return '#add-text-btn';
        return visible(floating) ? '#ftb-add-text' : null;
    }, prefer);
}

/** Turn add-text mode on (or off) through the real button. */
async function setAddTextMode(page, on, prefer = 'auto') {
    const selector = await addTextControl(page, prefer);
    if (!selector) throw new Error(`No visible add-text control (prefer=${prefer})`);
    if ((await addTextModeOn(page)) === on) return selector;
    await page.click(selector);
    await page.waitForFunction(
        (wanted) => document.body.classList.contains('enpv-add-text-on') === wanted,
        on,
        { timeout: 8000 },
    );
    return selector;
}

/** Offsets to probe when the desired point is covered, nearest first. */
function probeOffsets() {
    const offsets = [];
    for (let radius = 20; radius <= 320; radius += 20) {
        // Vertical first: the usual obstructions (topbar, zoom bar) are
        // horizontal strips, so moving up/down preserves the intended column.
        offsets.push([0, radius], [0, -radius], [radius, 0], [-radius, 0],
            [radius, radius], [radius, -radius], [-radius, radius], [-radius, -radius]);
    }
    return offsets;
}

/**
 * Resolve a clickable viewport point at a fractional page position.
 *
 * Pages are routinely larger than the viewport (612x792pt renders ~1550x2006
 * CSS px at default zoom) and floating chrome covers parts of the viewer, so
 * the point is scrolled into view and then probed outward until it genuinely
 * lands on the target page.
 *
 * Returns the viewport point and the page box it was resolved against, so the
 * caller can express the result in page-relative pixels.
 */
async function pointOnPage(page, pageNumber, fx, fy) {
    const locator = page.locator(`#viewer .page[data-page-number="${pageNumber}"]`);
    await locator.scrollIntoViewIfNeeded({ timeout: 15000 });
    await page.waitForTimeout(250);

    const viewport = page.viewportSize();
    const offsets = probeOffsets();

    for (let attempt = 0; attempt < 5; attempt++) {
        const box = await locator.boundingBox();
        if (!box) throw new Error(`Page ${pageNumber} has no bounding box`);

        const desiredX = box.x + (box.width * fx);
        const desiredY = box.y + (box.height * fy);

        const point = await page.evaluate(({ x, y, target, probes, vw, vh }) => {
            const hits = (px, py) => {
                if (px < 4 || py < 4 || px > vw - 4 || py > vh - 4) return false;
                const el = document.elementFromPoint(px, py);
                const pageDiv = el && el.closest ? el.closest('.page') : null;
                return !!pageDiv && Number(pageDiv.dataset.pageNumber) === target;
            };
            if (hits(x, y)) return { x, y };
            for (const [dx, dy] of probes) {
                if (hits(x + dx, y + dy)) return { x: x + dx, y: y + dy };
            }
            return null;
        }, { x: desiredX, y: desiredY, target: pageNumber, probes: offsets, vw: viewport.width, vh: viewport.height });

        if (point) {
            const fresh = await locator.boundingBox();
            return {
                x: point.x,
                y: point.y,
                pageBox: fresh || box,
                relX: point.x - (fresh || box).x,
                relY: point.y - (fresh || box).y,
            };
        }

        await page.evaluate(([dx, dy]) => {
            const container = document.getElementById('viewerContainer');
            if (!container) return;
            container.scrollLeft += dx;
            container.scrollTop += dy;
        }, [desiredX - (viewport.width / 2), desiredY - (viewport.height / 2)]);
        await page.waitForTimeout(400);
    }

    throw new Error(`No clickable point found near (${fx}, ${fy}) on page ${pageNumber}`);
}

/**
 * Read every placed text box.
 *
 * Positions are reported relative to their own page as well as the viewport:
 * the viewer scrolls while bringing points into view, so viewport coordinates
 * alone would read a scroll as a move.
 */
function textBoxes(page) {
    return page.evaluate((selector) => Array.from(document.querySelectorAll(selector)).map((box) => {
        const rect = box.getBoundingClientRect();
        const pageDiv = box.closest('.page');
        const pageRect = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0, width: 0, height: 0 };
        const styles = window.getComputedStyle(box);
        const textEl = box.querySelector('.enpv-text-content') || box;
        const textStyles = window.getComputedStyle(textEl);
        const read = (name) => box.style.getPropertyValue(name).trim();
        const num = (value) => {
            const parsed = Number.parseFloat(value);
            return Number.isFinite(parsed) ? parsed : null;
        };
        return {
            id: box.dataset.annotationId || '',
            pageNumber: pageDiv ? Number(pageDiv.dataset.pageNumber) : null,
            selected: box.classList.contains('is-selected'),
            editing: box.classList.contains('is-editing'),
            locked: box.dataset.locked === '1',
            userSized: box.dataset.userSizedTextBox === '1',
            editorMode: box.dataset.editorMode || '',
            text: (textEl.textContent || ''),
            // Scroll-independent — these are what geometry asserts on.
            relLeft: rect.left - pageRect.left,
            relTop: rect.top - pageRect.top,
            width: rect.width,
            height: rect.height,
            pageWidth: pageRect.width,
            pageHeight: pageRect.height,
            // The box carries its own point-to-pixel factor, which is NOT
            // pdfViewer.currentScale — that one omits the 96/72 CSS unit step.
            renderScale: num(box.dataset.renderScale),
            // Creation-time annotation geometry in PDF points, y measured from
            // the page bottom. This is the model the QA case asks about; the
            // rendered box additionally grows to fit its line height.
            annX: num(box.dataset.baseBboxX),
            annY: num(box.dataset.baseBboxY),
            annWidth: num(box.dataset.baseBboxW),
            annHeight: num(box.dataset.baseBboxH),
            annPageHeight: num(box.dataset.basePageHeight),
            fontSizePts: num(box.dataset.fontSizePts),
            fontFamily: box.dataset.fontFamilyValue || read('--enpv-font-family') || textStyles.fontFamily,
            fontSizePx: Number.parseFloat(read('--enpv-font-size')) || Number.parseFloat(textStyles.fontSize),
            textColor: read('--enpv-text-color') || textStyles.color,
            backgroundColor: box.dataset.backgroundColor || read('--enpv-bg-color'),
            opacity: Number.parseFloat(box.dataset.opacity || styles.opacity || '1'),
            textAlign: box.dataset.textAlign || '',
            verticalAlign: box.dataset.verticalAlign || '',
            underline: box.dataset.underline === '1',
            strikeout: box.dataset.strikeout === '1',
            rotation: num(box.dataset.rotation) ?? 0,
            boxTransform: box.style.transform || '',
            isRotated: box.classList.contains('is-rotated'),
            contentTransform: (box.querySelector('.enpv-text-content')?.style?.transform) || '',
            rotateHandleVisible: (() => {
                const handle = box.querySelector(':scope > .enpv-shape-rotate-handle');
                return !!handle && window.getComputedStyle(handle).display !== 'none';
            })(),
        };
    }), TEXT_BOX_SELECTOR);
}

/** The one placed box, or a descriptive throw. */
async function singleTextBox(page) {
    const boxes = await textBoxes(page);
    if (boxes.length !== 1) {
        throw new Error(`Expected exactly one text box, found ${boxes.length}`);
    }
    return boxes[0];
}

/** Click somewhere neutral to commit an edit and drop the selection. */
async function commitAndDeselect(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
    await page.evaluate(() => document.body.click());
    await page.waitForTimeout(250);
}

/**
 * Click an empty spot on the page, away from every placed box.
 *
 * This is what "click elsewhere on the page" means for a commit — clicking the
 * document body outside the viewer is a different gesture.
 */
async function clickEmptyPageSpot(page) {
    const fractions = [[0.85, 0.85], [0.85, 0.15], [0.15, 0.85], [0.5, 0.9]];
    for (const [fx, fy] of fractions) {
        // eslint-disable-next-line no-await-in-loop
        const point = await pointOnPage(page, 1, fx, fy).catch(() => null);
        if (!point) continue;
        // eslint-disable-next-line no-await-in-loop
        const clear = await page.evaluate(({ x, y, selector }) => Array.from(
            document.querySelectorAll(selector),
        ).every((box) => {
            const r = box.getBoundingClientRect();
            return x < r.left - 12 || x > r.right + 12 || y < r.top - 12 || y > r.bottom + 12;
        }), { x: point.x, y: point.y, selector: TEXT_BOX_SELECTOR });
        if (!clear) continue;
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.click(point.x, point.y);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(500);
        return true;
    }
    return false;
}

/**
 * Place a box at the first of several page positions that is actually
 * clickable. Floating chrome moves around, so a single fraction can be covered.
 */
async function clickPlaceSomewhere(page, fractions) {
    let lastError = null;
    for (const [fx, fy] of fractions) {
        try {
            // eslint-disable-next-line no-await-in-loop
            return await clickPlace(page, fx, fy);
        } catch (error) {
            lastError = error;
        }
    }
    throw lastError || new Error('No fraction was clickable');
}

/** Place a box with a single click, returning the point that was clicked. */
async function clickPlace(page, fx, fy, prefer = 'auto') {
    await setAddTextMode(page, true, prefer);
    const point = await pointOnPage(page, 1, fx, fy);
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(600);
    return point;
}

/** Place a box by dragging, returning both endpoints in page-relative px. */
async function dragPlace(page, fx, fy, dx, dy) {
    await setAddTextMode(page, true);
    const start = await pointOnPage(page, 1, fx, fy);
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    // Two moves: the first arms the preview, the second gives it a size.
    await page.mouse.move(start.x + (dx / 2), start.y + (dy / 2), { steps: 4 });
    await page.mouse.move(start.x + dx, start.y + dy, { steps: 4 });
    await page.waitForTimeout(150);
    await page.mouse.up();
    await page.waitForTimeout(600);
    return {
        start,
        end: { relX: start.relX + dx, relY: start.relY + dy },
    };
}

/** Watch the annotation-state saves so persistence tests need no fixed sleep. */
function attachSaveRecorder(page) {
    const recorder = { saves: [] };
    page.on('response', async (response) => {
        try {
            if (!response.url().includes('/save-annotation-state')) return;
            if (response.request().method() !== 'POST') return;
            recorder.saves.push({ status: response.status(), ok: response.ok() });
        } catch (_) {
            // A response that vanishes mid-navigation must not break the run.
        }
    });
    return recorder;
}

/** Save through the real Save control and wait for the request to land. */
async function saveDocument(page, saveRecorder) {
    const before = saveRecorder ? saveRecorder.saves.length : 0;
    // #save-btn is display:none in the pdf.js viewer, so it is dispatched
    // rather than clicked; it is still the same handler the editor uses.
    await page.evaluate(() => document.getElementById('save-btn')?.click());
    if (!saveRecorder) {
        await page.waitForTimeout(4000);
        return true;
    }
    const deadline = Date.now() + 20000;
    while (saveRecorder.saves.length === before && Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(200);
    }
    await page.waitForTimeout(500);
    return saveRecorder.saves.length > before;
}

/** Save, hard-reload, and wait for the editor to come back. */
async function saveAndReload(page, saveRecorder) {
    const saved = await saveDocument(page, saveRecorder);
    await page.reload({ waitUntil: 'load', timeout: 45000 });
    await page.waitForSelector('#viewer .page canvas', { timeout: 30000 });
    await page.waitForTimeout(1200);
    return saved;
}

/** Current zoom, as the viewer reports it. */
function readZoom(page) {
    return page.evaluate(() => ({
        scale: Number(window.__enpv?.pdfViewer?.currentScale) || 0,
        label: document.getElementById('zoom-label')?.textContent?.trim() || '',
    }));
}

/** Step the zoom and wait for the viewer to settle at a new scale. */
async function stepZoom(page, direction, steps = 1) {
    const selector = direction === 'in' ? '#zoom-in' : '#zoom-out';
    for (let i = 0; i < steps; i++) {
        const before = (await readZoom(page)).scale;
        // eslint-disable-next-line no-await-in-loop
        await page.click(selector);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForFunction(
            (previous) => Math.abs((Number(window.__enpv?.pdfViewer?.currentScale) || 0) - previous) > 0.001,
            before,
            { timeout: 8000 },
        ).catch(() => {});
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(500);
    }
    return readZoom(page);
}

/** Rotation pdf.js is currently applying to a page. */
function pageRotation(page, pageNumber = 1) {
    return page.evaluate((number) => {
        const view = window.__enpv?.pdfViewer?.getPageView(number - 1);
        return Number(view?.viewport?.rotation) || 0;
    }, pageNumber);
}

/**
 * Rotate a page through the page manager, which is the only rotation UI.
 * Rotation round-trips to the server and re-renders, so this waits for the
 * viewport to actually report the new angle.
 */
async function rotatePage(page, pageNumber, direction = 'right') {
    const before = await pageRotation(page, pageNumber);
    await page.click('#enpv-page-manager-open');
    await page.waitForSelector('.enpv-page-manager-item', { timeout: 15000 });
    await page.waitForTimeout(400);
    const button = `.enpv-page-manager-item:nth-of-type(${pageNumber}) .enpv-page-manager-rotate-${direction}`;
    await page.click(button);
    await page.waitForFunction(
        ({ number, previous }) => {
            const view = window.__enpv?.pdfViewer?.getPageView(number - 1);
            const rotation = Number(view?.viewport?.rotation);
            return Number.isFinite(rotation) && rotation !== previous;
        },
        { number: pageNumber, previous: before },
        { timeout: 60000 },
    );

    // Read the "cannot edit" dialog before any cleanup: closing the page
    // manager involves an Escape, which dismisses this dialog too.
    let dialogShown = false;
    let dialogMessage = '';
    for (let attempt = 0; attempt < 10 && !dialogShown; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const state = await page.evaluate(() => {
            const el = document.getElementById('enpv-rotated-edit-dialog');
            return {
                shown: !!el && el.hidden === false,
                message: document.getElementById('enpv-rotated-edit-dialog-description')
                    ?.textContent?.trim() || '',
            };
        });
        dialogShown = state.shown;
        dialogMessage = state.message;
        // eslint-disable-next-line no-await-in-loop
        if (!dialogShown) await page.waitForTimeout(200);
    }
    // Close the manager and make sure it really went away — a modal left open
    // covers the page and every later click lands on it instead.
    for (let attempt = 0; attempt < 3; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const open = await page.evaluate(
            () => document.getElementById('enpv-page-manager-modal')?.hidden === false,
        );
        if (!open) break;
        // eslint-disable-next-line no-await-in-loop
        await page.click('#enpv-page-manager-close').catch(() => {});
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(500);
        // eslint-disable-next-line no-await-in-loop
        await page.keyboard.press('Escape').catch(() => {});
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(400);
    }
    // Rotating onto a rotated page raises the "cannot edit" dialog. It is
    // reported back rather than just dismissed, because whether it appears is
    // itself part of the contract test 07 asserts.
    await page.evaluate(() => {
        const el = document.getElementById('enpv-rotated-edit-dialog');
        if (el && el.hidden === false) {
            document.getElementById('enpv-rotated-edit-dialog-close')?.click();
        }
    });
    await page.waitForTimeout(900);
    return { rotation: await pageRotation(page, pageNumber), dialogShown, dialogMessage };
}

/** Read every Text Options control in one round trip. */
function panelState(page) {
    return page.evaluate(() => {
        const value = (id) => document.getElementById(id)?.value ?? null;
        const pressed = (id) => document.getElementById(id)?.getAttribute('aria-pressed') ?? null;
        const disabled = (id) => document.getElementById(id)?.disabled ?? null;
        const bar = document.getElementById('ann-format-bar');
        return {
            visible: !!bar && bar.classList.contains('is-visible'),
            font: value('afb-font'),
            size: value('afb-size'),
            sizeLabel: document.getElementById('afb-size-value')?.textContent?.trim() ?? null,
            textColor: value('afb-text-color'),
            bgColor: value('afb-bg-color'),
            opacity: value('afb-opacity'),
            bold: pressed('afb-bold'),
            italic: pressed('afb-italic'),
            underline: pressed('afb-underline'),
            strikeout: pressed('afb-strikeout'),
            align: value('afb-align'),
            valign: value('afb-valign'),
            disabled: {
                font: disabled('afb-font'),
                size: disabled('afb-size'),
                bold: disabled('afb-bold'),
                align: disabled('afb-align'),
                copy: disabled('afb-copy'),
                delete: disabled('afb-delete'),
            },
        };
    });
}

/**
 * Set a colour input.
 *
 * The native colour picker is an OS dialog no headless browser can drive, so
 * the value is written and the editor's own input/change handlers are fired.
 */
async function setColourInput(page, id, hex) {
    await page.evaluate(({ target, value }) => {
        const input = document.getElementById(target);
        if (!input) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, { target: id, value: hex });
    await page.waitForTimeout(400);
}

/**
 * Click inside a placed text box with a real pointer.
 *
 * Selection is driven by pointer events, so a programmatic element.click() does
 * not select the box or open the Text Options panel. An unselected box is also
 * `pointer-events: none` — the editor hit-tests page clicks against annotation
 * geometry itself — so the point only has to land on the right page, over the
 * box's rectangle.
 */
async function clickInsideBox(page, index = 0) {
    // Nothing can be clicked on a committed text box without one of the two
    // interaction modes being active.
    if (!(await editModeOn(page)) && !(await addTextModeOn(page))) {
        await setEditMode(page, true);
    }
    await page.evaluate(({ selector, at }) => {
        const box = document.querySelectorAll(selector)[at];
        if (box) box.scrollIntoView({ block: 'center', inline: 'center' });
    }, { selector: TEXT_BOX_SELECTOR, at: index });
    await page.waitForTimeout(400);

    const viewport = page.viewportSize();
    const point = await page.evaluate(({ selector, at, vw, vh }) => {
        const box = document.querySelectorAll(selector)[at];
        if (!box) return null;
        const rect = box.getBoundingClientRect();
        const candidates = [
            [0.5, 0.5], [0.25, 0.5], [0.75, 0.5],
            [0.5, 0.25], [0.5, 0.75], [0.1, 0.5], [0.9, 0.5],
        ];
        const pageDiv = box.closest('.page');
        for (const [fx, fy] of candidates) {
            const x = rect.left + (rect.width * fx);
            const y = rect.top + (rect.height * fy);
            if (x < 4 || y < 4 || x > vw - 4 || y > vh - 4) continue;
            const el = document.elementFromPoint(x, y);
            if (!el) continue;
            // Either the box itself (when selected) or its page beneath it.
            if (el === box || box.contains(el)) return { x, y };
            if (pageDiv && el.closest('.page') === pageDiv) return { x, y };
        }
        return null;
    }, { selector: TEXT_BOX_SELECTOR, at: index, vw: viewport.width, vh: viewport.height });

    if (!point) throw new Error(`No clickable point inside text box ${index}`);
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(500);
    return point;
}

/** Select a placed text box. */
async function selectTextBox(page, index = 0) {
    return clickInsideBox(page, index);
}

/**
 * Select the box containing some text, and confirm the right one took focus.
 *
 * Indices shift as boxes are added and removed, and a wrong-box selection
 * shows up as a confusing style assertion rather than a selection failure.
 */
async function selectTextBoxByText(page, needle) {
    for (let attempt = 0; attempt < 2; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const boxes = await textBoxes(page);
        const index = boxes.findIndex((box) => box.text.includes(needle));
        if (index < 0) throw new Error(`No text box contains ${JSON.stringify(needle)}`);
        // eslint-disable-next-line no-await-in-loop
        await clickInsideBox(page, index);
        // eslint-disable-next-line no-await-in-loop
        const after = await textBoxes(page);
        const selected = after.find((box) => box.selected);
        if (selected && selected.text.includes(needle)) return selected;
        // eslint-disable-next-line no-await-in-loop
        await commitAndDeselect(page);
    }
    const boxes = await textBoxes(page);
    throw new Error(
        `Could not select the box containing ${JSON.stringify(needle)}; `
        + `selected=${JSON.stringify(boxes.filter((b) => b.selected).map((b) => b.text))}`,
    );
}

/** Trigger an annotation-menu action with a real pointer. */
async function annotationMenuAction(page, action) {
    const point = await page.evaluate((name) => {
        const menu = document.getElementById('enpv-ann-menu');
        if (!menu || menu.hidden) return null;
        const button = menu.querySelector(`[data-action="${name}"]`);
        if (!button) return null;
        const rect = button.getBoundingClientRect();
        if (!(rect.width > 0) || !(rect.height > 0)) return null;
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, action);
    if (!point) return false;
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(600);
    return true;
}

/** Rendered geometry of the text element inside a box, for wrap assertions. */
function textMetrics(page, index = 0) {
    return page.evaluate(({ selector, at }) => {
        const box = document.querySelectorAll(selector)[at];
        if (!box) return null;
        const el = box.querySelector('.enpv-text-content') || box;
        const rect = el.getBoundingClientRect();
        const lineHeight = Number.parseFloat(box.style.getPropertyValue('--enpv-line-height'))
            || Number.parseFloat(window.getComputedStyle(el).lineHeight)
            || 0;
        // Count laid-out line boxes rather than dividing heights.
        const range = document.createRange();
        range.selectNodeContents(el);
        const rects = Array.from(range.getClientRects())
            .filter((r) => r.width > 0 && r.height > 0);
        const tops = new Set(rects.map((r) => Math.round(r.top)));
        return {
            text: el.textContent || '',
            html: el.innerHTML || '',
            width: rect.width,
            height: rect.height,
            scrollWidth: el.scrollWidth,
            clientWidth: el.clientWidth,
            lineHeight,
            lineCount: tops.size,
            boxWidth: box.getBoundingClientRect().width,
        };
    }, { selector: TEXT_BOX_SELECTOR, at: index });
}

/**
 * Drag a box's rotate handle by `degrees`, clockwise about the box centre.
 * Returns the angle the box ended up at.
 */
async function dragRotateHandle(page, degrees, index = 0) {
    const geometry = await page.evaluate(({ selector, at }) => {
        const box = document.querySelectorAll(selector)[at];
        const handle = box?.querySelector(':scope > .enpv-shape-rotate-handle');
        if (!box || !handle) return null;
        const handleRect = handle.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();
        return {
            hx: handleRect.left + (handleRect.width / 2),
            hy: handleRect.top + (handleRect.height / 2),
            cx: boxRect.left + (boxRect.width / 2),
            cy: boxRect.top + (boxRect.height / 2),
        };
    }, { selector: TEXT_BOX_SELECTOR, at: index });
    if (!geometry) throw new Error('No rotate handle on the selected text box');

    const radius = Math.hypot(geometry.hx - geometry.cx, geometry.hy - geometry.cy);
    const startAngle = Math.atan2(geometry.hy - geometry.cy, geometry.hx - geometry.cx);
    const sweep = (degrees * Math.PI) / 180;

    await page.mouse.move(geometry.hx, geometry.hy);
    await page.mouse.down();
    for (let step = 1; step <= 8; step++) {
        const angle = startAngle + ((sweep * step) / 8);
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(
            geometry.cx + (Math.cos(angle) * radius),
            geometry.cy + (Math.sin(angle) * radius),
        );
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(40);
    }
    await page.mouse.up();
    await page.waitForTimeout(700);
    return (await textBoxes(page))[index]?.rotation ?? null;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/** 01 — Setup: the blank project loads with the Add Text tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const state = await page.evaluate(() => ({
        pages: document.querySelectorAll('#viewer .page').length,
        rendered: Array.from(document.querySelectorAll('#viewer .page canvas'))
            .filter((canvas) => canvas.width > 0 && canvas.height > 0).length,
        topButton: !!document.getElementById('add-text-btn'),
        topDisabled: document.getElementById('add-text-btn')?.disabled === true,
        floatingButton: !!document.getElementById('ftb-add-text'),
        floatingDisabled: document.getElementById('ftb-add-text')?.disabled === true,
        formatBar: !!document.getElementById('ann-format-bar'),
        formatBarVisible: document.getElementById('ann-format-bar')?.classList.contains('is-visible') === true,
        addTextMode: document.body.classList.contains('enpv-add-text-on'),
        // pdf.js-only controls, so their presence proves we are on ?pdfjs=1.
        pdfjsOnly: !!document.getElementById('afb-uppercase') && !!document.getElementById('afb-link-url'),
    }));

    recorder.assert('page-rendered', state.pages === 1 && state.rendered === 1,
        'Exactly one page is present and rasterised', `pages=${state.pages} rendered=${state.rendered}`);
    recorder.assert('add-text-available', state.topButton && !state.topDisabled,
        'The top-bar Add Text button is present and enabled');
    recorder.assert('floating-add-text-available', state.floatingButton && !state.floatingDisabled,
        'The floating toolbar add-text button is present and enabled');
    recorder.assert('pdfjs-editor', state.pdfjsOnly,
        'This is the pdf.js editor, not the legacy one (uppercase + hyperlink controls exist)');
    recorder.assert('format-bar-closed', state.formatBar && !state.formatBarVisible,
        'The Text Options panel exists but is closed with nothing selected');
    recorder.assert('mode-off', !state.addTextMode,
        'Add-text mode starts off');

    const boxes = await textBoxes(page);
    recorder.equals('no-boxes', boxes.length, 0, 'No text box exists on a fresh document');

    recorder.assert('console-clean', consoleErrors.length === 0,
        'No console errors during load', consoleErrors.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — Add Text toggles from both toolbars and is exclusive with other tools. */
async function testAddTextModeToggle(page, { recorder }) {
    const artifacts = [];

    const topSelector = await addTextControl(page, 'top');
    const floatingSelector = await addTextControl(page, 'floating');
    recorder.assert('controls-present', !!topSelector || !!floatingSelector,
        'At least one add-text control is reachable', `top=${topSelector} floating=${floatingSelector}`);

    // One shared mode: whichever control turns it on, both must reflect it.
    const readButtons = () => page.evaluate(() => {
        const flags = (el) => (el ? {
            active: el.classList.contains('active') || el.classList.contains('is-active'),
            pressed: el.getAttribute('aria-pressed'),
        } : null);
        return {
            top: flags(document.getElementById('add-text-btn')),
            floating: flags(document.getElementById('ftb-add-text')),
            mode: document.body.classList.contains('enpv-add-text-on'),
        };
    });

    const primary = topSelector || floatingSelector;
    await page.click(primary);
    await page.waitForTimeout(300);
    const on = await readButtons();
    recorder.assert('mode-on', on.mode, 'Clicking the button enters add-text mode');
    const anyActive = (on.top && on.top.active) || (on.floating && on.floating.active);
    recorder.assert('button-active', anyActive, 'The add-text button shows an active state');

    // Toggling off must not place anything.
    await page.click(primary);
    await page.waitForTimeout(300);
    const off = await readButtons();
    recorder.assert('mode-off-again', !off.mode, 'Clicking again leaves add-text mode');
    recorder.equals('nothing-placed', (await textBoxes(page)).length, 0,
        'Toggling the mode on and off places nothing');

    // The second control drives the same single state.
    if (topSelector && floatingSelector) {
        await page.click(floatingSelector);
        await page.waitForTimeout(300);
        const viaFloating = await readButtons();
        recorder.assert('floating-drives-mode', viaFloating.mode,
            'The floating toolbar button enters the same mode');
        await page.click(topSelector);
        await page.waitForTimeout(300);
        const afterTop = await readButtons();
        recorder.assert('shared-state', !afterTop.mode,
            'The top-bar button turns off a mode the floating button turned on');
    } else {
        recorder.ok('floating-drives-mode',
            'Only one add-text control is visible at this viewport; shared-state check skipped',
            `top=${topSelector} floating=${floatingSelector}`);
    }

    // Exclusivity: another tool must cancel add-text mode.
    await setAddTextMode(page, true);
    const shapeButton = await page.evaluate(() => {
        const candidates = ['#add-shape-btn', '#ftb-shapes', '#ftb-add-shape'];
        return candidates.find((selector) => {
            const el = document.querySelector(selector);
            return !!el && !!el.offsetParent;
        }) || null;
    });
    if (shapeButton) {
        await page.click(shapeButton);
        await page.waitForTimeout(400);
        recorder.assert('shapes-cancel-add-text', !(await addTextModeOn(page)),
            'Turning on the Shapes tool cancels add-text mode');
        // Leave the shape tool again so the context ends clean.
        await page.click(shapeButton).catch(() => {});
        await page.waitForTimeout(300);
    } else {
        recorder.fail('shapes-cancel-add-text', 'No visible Shapes control to test exclusivity against');
    }

    // Placing one box leaves the mode, so the next click cannot place a second.
    await setAddTextMode(page, true);
    const point = await pointOnPage(page, 1, 0.35, 0.3);
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(600);
    recorder.assert('mode-clears-after-place', !(await addTextModeOn(page)),
        'Placing a box leaves add-text mode automatically');

    // Type before committing: a box committed empty is discarded, which would
    // otherwise leave nothing to count.
    await page.keyboard.type('Kept');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);
    recorder.equals('box-survives-commit', (await textBoxes(page)).length, 1,
        'The placed box survives being committed');

    const second = await pointOnPage(page, 1, 0.6, 0.5);
    await page.mouse.click(second.x, second.y);
    await page.waitForTimeout(500);
    recorder.equals('no-second-box', (await textBoxes(page)).length, 1,
        'A further page click does not place a second box');

    artifacts.push(await capture(page, '02-add-text-mode-toggle', 'after-place'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Click-to-place creates a text box with the documented defaults. */
async function testClickToPlaceDefaults(page, { recorder }) {
    const artifacts = [];

    const point = await clickPlace(page, 0.3, 0.28);
    const box = await singleTextBox(page);
    recorder.assert('render-scale-known', box.renderScale > 0,
        'The box exposes its point-to-pixel scale', `renderScale=${box.renderScale}`);

    // Geometry is asserted against the annotation itself, in PDF points, rather
    // than by eye off the rendered box.
    recorder.near('annotation-width', box.annWidth, PLACEMENT_DEFAULTS.widthPts, 0.01,
        `The annotation is ${PLACEMENT_DEFAULTS.widthPts}pt wide`);
    recorder.near('annotation-height', box.annHeight, PLACEMENT_DEFAULTS.heightPts, 0.01,
        `The annotation is ${PLACEMENT_DEFAULTS.heightPts}pt tall`);

    // ...and the rendering has to agree about where it goes.
    recorder.near('left', box.relLeft, point.relX, 2,
        'The box left edge renders at the click point');
    recorder.near('top', box.relTop, point.relY, 2,
        'The box top edge renders at the click point');
    recorder.near('width', box.width / box.renderScale, PLACEMENT_DEFAULTS.widthPts, 1,
        `The rendered box is ${PLACEMENT_DEFAULTS.widthPts}pt wide`);
    // Height only has a floor: the box grows to fit one line of its own font,
    // which is taller than the 15pt the annotation is created with.
    recorder.assert('height-at-least-annotation',
        box.height / box.renderScale >= PLACEMENT_DEFAULTS.heightPts - 1,
        'The rendered box is at least as tall as the annotation',
        `annotation=${PLACEMENT_DEFAULTS.heightPts}pt rendered=${(box.height / box.renderScale).toFixed(2)}pt`);

    // Typography defaults.
    recorder.assert('font-family',
        String(box.fontFamily).toLowerCase().includes(PLACEMENT_DEFAULTS.fontFamily.toLowerCase()),
        `Default font is ${PLACEMENT_DEFAULTS.fontFamily}`, box.fontFamily);
    recorder.near('font-size', box.fontSizePts, PLACEMENT_DEFAULTS.fontSize, 0.01,
        `Default font size is ${PLACEMENT_DEFAULTS.fontSize}pt`);
    recorder.near('font-size-rendered', box.fontSizePx / box.renderScale, PLACEMENT_DEFAULTS.fontSize, 0.3,
        'The rendered glyph size matches that point size');
    recorder.assert('text-color', normaliseColour(box.textColor) === '#000000',
        'Default text colour is black', box.textColor);
    recorder.assert('background', isTransparent(box.backgroundColor),
        'Background starts transparent', box.backgroundColor);
    recorder.equals('opacity', box.opacity, PLACEMENT_DEFAULTS.opacity, 'Opacity starts at 1');
    recorder.equals('text-align', box.textAlign, PLACEMENT_DEFAULTS.textAlign, 'Alignment starts left');
    recorder.equals('vertical-align', box.verticalAlign, PLACEMENT_DEFAULTS.verticalAlign, 'Vertical alignment starts top');
    recorder.assert('no-decorations', !box.underline && !box.strikeout,
        'No underline or strikeout by default');
    recorder.assert('unlocked', !box.locked, 'The new box is unlocked');
    recorder.assert('not-user-sized', !box.userSized,
        'A clicked box is not flagged as user-sized (only a drag sizes it)');

    // Placement selects and opens the box without edit mode having to be on.
    const editModeOff = !(await editModeOn(page));
    recorder.assert('edit-mode-off', editModeOff,
        'The box is placed and opened without edit mode being turned on');
    recorder.assert('selected', box.selected, 'The new box is selected');

    artifacts.push(await capture(page, '03-click-to-place-defaults', 'placed'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — Drag-to-size honours the rectangle, with a click fallback below threshold. */
async function testDragToSize(page, { recorder }) {
    const artifacts = [];

    // A drag comfortably over the threshold takes the dragged geometry.
    const dragWidth = 260;
    const dragHeight = 90;
    const drag = await dragPlace(page, 0.2, 0.25, dragWidth, dragHeight);
    let box = await singleTextBox(page);

    recorder.near('drag-left', box.relLeft, drag.start.relX, 3, 'Box left matches the drag origin');
    recorder.near('drag-top', box.relTop, drag.start.relY, 3, 'Box top matches the drag origin');
    recorder.near('drag-width', box.width, dragWidth, 6, 'Box width matches the dragged rectangle');
    recorder.near('drag-height', box.height, dragHeight, 6, 'Box height matches the dragged rectangle');
    recorder.assert('drag-user-sized', box.userSized, 'A dragged box is flagged as user-sized');
    recorder.assert('no-preview-left', await previewRemoved(page),
        'The drag preview element is removed once the box is created');

    artifacts.push(await capture(page, '04-drag-to-size', 'dragged'));

    // Drag up and to the left: the rectangle is normalised, not inverted.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    const reverse = await dragPlace(page, 0.55, 0.55, -200, -70);
    box = await singleTextBox(page);
    recorder.near('reverse-left', box.relLeft, reverse.end.relX, 4,
        'Dragging up-left puts the box left edge at the release point');
    recorder.near('reverse-top', box.relTop, reverse.end.relY, 4,
        'Dragging up-left puts the box top edge at the release point');
    recorder.near('reverse-width', box.width, 200, 6, 'Reverse drag width is the absolute distance');

    // Below the threshold, the drag is treated as a click.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    const tiny = await dragPlace(page, 0.4, 0.4, DRAG_THRESHOLD_PX.width - 6, DRAG_THRESHOLD_PX.height - 4);
    box = await singleTextBox(page);
    recorder.near('tiny-width', box.annWidth, PLACEMENT_DEFAULTS.widthPts, 0.01,
        'A sub-threshold drag falls back to the default click width');
    recorder.assert('tiny-not-user-sized', !box.userSized,
        'A sub-threshold drag does not flag the box as user-sized');
    recorder.near('tiny-left', box.relLeft, tiny.start.relX, 3,
        'The fallback box is placed at the press point');
    recorder.assert('tiny-no-preview', await previewRemoved(page),
        'No drag preview is left behind by a sub-threshold drag');

    artifacts.push(await capture(page, '04-drag-to-size', 'fallback'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — New boxes are clamped inside the page, on any page of the document. */
async function testPageClamping(page, { recorder }) {
    const artifacts = [];
    // Clamping happens in PDF space, so it is asserted there. A hundredth of a
    // point of slack absorbs float rounding only.
    const slack = 0.01;

    const withinPage = (box) => ({
        left: box.annX >= -slack,
        right: box.annX + box.annWidth <= PAGE_WIDTH_PTS + slack,
        bottom: box.annY >= -slack,
        top: box.annY + box.annHeight <= (box.annPageHeight || PAGE_HEIGHT_PTS) + slack,
        detail: `x=${box.annX?.toFixed(2)} y=${box.annY?.toFixed(2)} `
            + `w=${box.annWidth?.toFixed(2)} h=${box.annHeight?.toFixed(2)} `
            + `page=${PAGE_WIDTH_PTS}x${box.annPageHeight}`,
    });

    // Bottom-right corner: the box must be pulled back inside the page.
    const corner = await clickPlace(page, 0.995, 0.995);
    let box = await singleTextBox(page);
    let bounds = withinPage(box);
    recorder.assert('corner-inside-right', bounds.right,
        'A corner click does not overhang the right edge', bounds.detail);
    recorder.assert('corner-inside-bottom', bounds.bottom,
        'A corner click does not overhang the bottom edge', bounds.detail);
    recorder.assert('corner-clamped', box.relLeft < corner.relX + 2,
        'The box was moved left of the click to stay on the page',
        `boxLeft=${box.relLeft.toFixed(1)} clickX=${corner.relX.toFixed(1)}`);

    // Top-left corner: no negative coordinates.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await clickPlace(page, 0.002, 0.002);
    box = await singleTextBox(page);
    bounds = withinPage(box);
    recorder.assert('origin-non-negative', bounds.left && bounds.top,
        'A top-left click produces no out-of-page coordinates', bounds.detail);

    // A drag that would size past the page edge is clamped too.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await dragPlace(page, 0.8, 0.8, 900, 400);
    box = await singleTextBox(page);
    bounds = withinPage(box);
    recorder.assert('drag-inside-right', bounds.right,
        'An oversized drag is clamped to the page width', bounds.detail);
    recorder.assert('drag-inside-bottom', bounds.bottom,
        'An oversized drag is clamped to the page height', bounds.detail);
    recorder.assert('drag-has-size', box.width > MIN_DRAG_PX.width / 2,
        'Clamping keeps a usable box rather than collapsing it', `width=${box.width.toFixed(1)}`);

    // Regression: the annotation used to be clamped against a nominal one-line
    // height while the box laid out taller, so a box placed hard against the
    // bottom rendered off the page — and a save, which reads geometry back off
    // the DOM, persisted the overhang. Sweep the last few percent of the page,
    // which is where the clamp actually binds.
    for (const fy of [0.999, 0.997, 0.995, 0.985]) {
        // eslint-disable-next-line no-await-in-loop
        await commitAndDeselect(page);
        // eslint-disable-next-line no-await-in-loop
        await deleteAllTextBoxes(page);
        // eslint-disable-next-line no-await-in-loop
        await clickPlace(page, 0.5, fy);
        // eslint-disable-next-line no-await-in-loop
        const placed = await singleTextBox(page);
        const bottom = (placed.relTop + placed.height) / placed.renderScale;
        const pageBottom = placed.annPageHeight || PAGE_HEIGHT_PTS;
        recorder.assert(`rendered-inside-${fy}`, bottom <= pageBottom + 0.05,
            `A box placed at ${(fy * 100).toFixed(1)}% down the page renders inside it`,
            `renderedBottom=${bottom.toFixed(2)}pt page=${pageBottom}pt`);
    }

    artifacts.push(await capture(page, '05-page-clamping', 'clamped'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — A newly placed box opens in edit mode with the caret ready. */
async function testEditModeReady(page, { recorder }) {
    const artifacts = [];

    await clickPlace(page, 0.3, 0.3);
    let box = await singleTextBox(page);

    recorder.assert('selected', box.selected, 'The new box is selected');
    recorder.assert('editing', box.editing, 'The new box is in edit mode');

    const caret = await page.evaluate((selector) => {
        const el = document.querySelector(selector);
        const target = el?.querySelector('.enpv-text-content');
        const active = document.activeElement;
        return {
            hasTextElement: !!target,
            focused: !!target && (active === target || target.contains(active)),
            editable: !!target && target.isContentEditable,
        };
    }, TEXT_BOX_SELECTOR);
    recorder.assert('caret-ready', caret.hasTextElement && caret.editable && caret.focused,
        'The caret is inside an editable text element, so typing needs no extra click',
        JSON.stringify(caret));

    // Typing with no further clicking must land in the box.
    await page.keyboard.type('Ready');
    await page.waitForTimeout(400);
    box = await singleTextBox(page);
    recorder.assert('typing-lands', box.text.includes('Ready'),
        'Typed characters land in the new box without another click', JSON.stringify(box.text));

    // The hover menu is anchored over the box.
    const menu = await page.evaluate(() => {
        const el = document.getElementById('enpv-ann-menu');
        if (!el || el.hidden) return { visible: false };
        const rect = el.getBoundingClientRect();
        return { visible: rect.width > 0 && rect.height > 0, top: rect.top, left: rect.left };
    });
    recorder.assert('menu-anchored', menu.visible, 'The annotation menu is shown for the new box', JSON.stringify(menu));

    artifacts.push(await capture(page, '08-edit-mode-ready', 'first-box'));

    // A second box: placing it must commit the first rather than leave it open.
    await commitAndDeselect(page);
    await clickPlace(page, 0.6, 0.55);
    await page.keyboard.type('Second');
    await page.waitForTimeout(400);

    const boxes = await textBoxes(page);
    recorder.equals('two-boxes', boxes.length, 2, 'Both boxes exist');
    const editingCount = boxes.filter((entry) => entry.editing).length;
    recorder.equals('one-editor', editingCount, 1, 'Only one box is in edit mode at a time');
    const first = boxes.find((entry) => entry.text.includes('Ready'));
    recorder.assert('first-committed', !!first && !first.editing,
        'The first box kept its text and left edit mode', JSON.stringify(first && first.text));

    artifacts.push(await capture(page, '08-edit-mode-ready', 'second-box'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — Placement stays accurate across zoom levels. */
async function testZoomAccuracy(page, { recorder }) {
    const artifacts = [];

    // Where the click landed, expressed in page points, is what the annotation
    // must agree with — so the assertion holds at any zoom by construction.
    const placeAndCompare = async (label) => {
        await deleteAllTextBoxes(page);
        const point = await clickPlace(page, 0.32, 0.3);
        const box = await singleTextBox(page);
        const expectedLeft = point.relX / box.renderScale;
        const expectedTop = point.relY / box.renderScale;
        const actualTop = (box.annPageHeight || PAGE_HEIGHT_PTS) - (box.annY + box.annHeight);
        recorder.near(`${label}-x`, box.annX, expectedLeft, 1,
            `At ${label} the box lands under the pointer horizontally`);
        recorder.near(`${label}-y`, actualTop, expectedTop, 1,
            `At ${label} the box lands under the pointer vertically`);
        await commitAndDeselect(page);
        return { left: box.annX, top: actualTop };
    };

    const initial = await readZoom(page);
    recorder.assert('zoom-readable', initial.scale > 0,
        'The viewer reports a zoom level', `${initial.label} (scale ${initial.scale})`);

    const atStart = await placeAndCompare('default zoom');

    const zoomedIn = await stepZoom(page, 'in', 2);
    recorder.assert('zoom-in-changed', zoomedIn.scale > initial.scale,
        'Zooming in raises the scale', `${initial.label} -> ${zoomedIn.label}`);
    const atZoomIn = await placeAndCompare('zoomed in');

    const zoomedOut = await stepZoom(page, 'out', 4);
    recorder.assert('zoom-out-changed', zoomedOut.scale < zoomedIn.scale,
        'Zooming out lowers the scale', `${zoomedIn.label} -> ${zoomedOut.label}`);
    const atZoomOut = await placeAndCompare('zoomed out');

    // The same page fraction has to produce the same PDF coordinates whatever
    // zoom it was placed at.
    recorder.near('x-consistent-in', atZoomIn.left, atStart.left, 2,
        'The same click point yields the same PDF x when zoomed in');
    recorder.near('x-consistent-out', atZoomOut.left, atStart.left, 2,
        'The same click point yields the same PDF x when zoomed out');
    recorder.near('y-consistent-in', atZoomIn.top, atStart.top, 2,
        'The same click point yields the same PDF y when zoomed in');
    recorder.near('y-consistent-out', atZoomOut.top, atStart.top, 2,
        'The same click point yields the same PDF y when zoomed out');

    artifacts.push(await capture(page, '06-zoom-accuracy', 'zoomed-out'));

    // An existing box must stay anchored to the page when the zoom changes.
    await deleteAllTextBoxes(page);
    await clickPlace(page, 0.4, 0.35);
    await page.keyboard.type('Anchored');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);
    const before = await singleTextBox(page);
    const beforeFraction = {
        x: before.relLeft / before.pageWidth,
        y: before.relTop / before.pageHeight,
    };

    await stepZoom(page, 'in', 2);
    const after = await singleTextBox(page);
    recorder.near('anchor-x', after.relLeft / after.pageWidth, beforeFraction.x, 0.005,
        'A placed box keeps its horizontal position on the page across a zoom change');
    recorder.near('anchor-y', after.relTop / after.pageHeight, beforeFraction.y, 0.005,
        'A placed box keeps its vertical position on the page across a zoom change');
    recorder.near('anchor-annotation-x', after.annX, before.annX, 0.5,
        'Zooming does not move the annotation itself');

    artifacts.push(await capture(page, '06-zoom-accuracy', 'anchored'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 07 — Rotated pages are not editable, and rotating back restores the tool.
 *
 * The QA case originally assumed text could be placed on a rotated page. It
 * cannot: this build renders a rotated page as one flattened image so saved
 * masks and edits stay aligned, tells the user so, and the Add Text tool places
 * nothing there. That contract is what is asserted here, along with the round
 * trip back to 0 degrees.
 */
async function testRotatedPages(page, { recorder }) {
    const artifacts = [];

    recorder.equals('starts-unrotated', await pageRotation(page, 1), 0,
        'The page starts unrotated');

    // Baseline: placement works before any rotation, and the box survives.
    await clickPlace(page, 0.3, 0.3);
    await page.keyboard.type('Before rotation');
    await page.waitForTimeout(350);
    await commitAndDeselect(page);
    const before = await singleTextBox(page);
    recorder.assert('baseline-placed', before.text.includes('Before rotation'),
        'A box can be placed and typed into before the page is rotated');

    // Rotate.
    const rotated = await rotatePage(page, 1, 'right');
    recorder.equals('rotated-90', rotated.rotation, 90, 'The page rotates to 90 degrees');

    recorder.assert('rotation-blocks-editing', await page.evaluate(
        () => document.body.classList.contains('enpv-edit-rotation-blocked'),
    ), 'Rotating the page marks editing as unavailable');
    recorder.assert('rotation-explained',
        rotated.dialogShown && /rotated/i.test(rotated.dialogMessage),
        'The editor explains why the rotated page cannot be edited',
        rotated.dialogMessage.slice(0, 140));
    recorder.assert('dialog-dismissable', await page.evaluate(
        () => document.getElementById('enpv-rotated-edit-dialog')?.hidden === true,
    ), 'The dialog can be dismissed');

    artifacts.push(await capture(page, '07-rotated-pages', 'rotation-blocked'));

    const countBefore = (await textBoxes(page)).length;
    await setAddTextMode(page, true);
    recorder.assert('add-text-still-toggles', await addTextModeOn(page),
        'The Add Text tool can still be switched on');
    const point = await pointOnPage(page, 1, 0.35, 0.3);
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(800);
    recorder.equals('no-placement-while-rotated', (await textBoxes(page)).length, countBefore,
        'Clicking a rotated page places nothing');

    // Rotate back and confirm the tool and the original box both come back.
    await setAddTextMode(page, false).catch(() => {});
    const restored = await rotatePage(page, 1, 'left');
    recorder.equals('rotated-back', restored.rotation, 0, 'The page rotates back to 0 degrees');
    recorder.assert('editing-unblocked', await page.evaluate(
        () => !document.body.classList.contains('enpv-edit-rotation-blocked'),
    ), 'Editing is available again once the page is upright');

    const after = await singleTextBox(page);
    recorder.assert('box-survives-round-trip', after.text.includes('Before rotation'),
        'The existing box survives the rotation round trip', JSON.stringify(after.text));

    // The top-left corner is the invariant. Width follows the content, and the
    // round trip persists the box, at which point its height becomes the
    // rendered one — so y shifts by exactly that difference while the top edge
    // stays put.
    const topEdge = (box) => (box.annPageHeight || PAGE_HEIGHT_PTS) - (box.annY + box.annHeight);
    recorder.near('geometry-x-preserved', after.annX, before.annX, 0.5,
        'The box keeps its left edge across the round trip');
    recorder.near('geometry-top-preserved', topEdge(after), topEdge(before), 0.5,
        'The box keeps its top edge across the round trip');

    // ...and placement works again.
    const placedAgain = await clickPlaceSomewhere(page, [[0.55, 0.5], [0.45, 0.45], [0.35, 0.6], [0.6, 0.35]]);
    recorder.equals('placement-restored', (await textBoxes(page)).length, 2,
        'Text can be placed again once the page is upright',
        `clickedAt=${Math.round(placedAgain.relX)},${Math.round(placedAgain.relY)}`);

    artifacts.push(await capture(page, '07-rotated-pages', 'restored'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 09 — Typing, multi-line entry, word wrap and box reflow. */
async function testTypingAndWrap(page, { saveRecorder, recorder }) {
    const artifacts = [];

    // A user-sized box gives wrapping a width to work against.
    await dragPlace(page, 0.15, 0.2, 240, 120);
    const paragraph = 'The quick brown fox jumps over the lazy dog while the '
        + 'printer warms up and the coffee goes cold again.';
    await page.keyboard.type(paragraph);
    await page.waitForTimeout(600);

    let metrics = await textMetrics(page);
    recorder.assert('text-entered', metrics.text.replace(/\s+/g, ' ').includes('quick brown fox'),
        'The typed paragraph is in the box', metrics.text.slice(0, 60));
    recorder.assert('wraps-to-multiple-lines', metrics.lineCount > 1,
        'A long paragraph wraps onto more than one line', `lines=${metrics.lineCount}`);
    recorder.assert('no-horizontal-overflow', metrics.scrollWidth <= metrics.clientWidth + 2,
        'Wrapped text does not overflow the box horizontally',
        `scrollWidth=${metrics.scrollWidth} clientWidth=${metrics.clientWidth}`);

    artifacts.push(await capture(page, '09-typing-and-wrap', 'paragraph'));

    // A single word longer than the box must break rather than spill out.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await dragPlace(page, 0.15, 0.2, 200, 90);
    await page.keyboard.type('Pneumonoultramicroscopicsilicovolcanoconiosis');
    await page.waitForTimeout(600);
    metrics = await textMetrics(page);
    recorder.assert('long-word-no-overflow', metrics.scrollWidth <= metrics.clientWidth + 2,
        'A word wider than the box is split rather than allowed to overflow',
        `scrollWidth=${metrics.scrollWidth} clientWidth=${metrics.clientWidth}`);
    recorder.assert('long-word-wraps', metrics.lineCount > 1,
        'The over-long word occupies more than one line', `lines=${metrics.lineCount}`);

    // Hard line breaks.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await dragPlace(page, 0.15, 0.2, 240, 140);
    await page.keyboard.type('First line');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Second line');
    await page.waitForTimeout(500);
    metrics = await textMetrics(page);
    recorder.assert('hard-break-renders', metrics.lineCount >= 2,
        'Enter produces a second rendered line', `lines=${metrics.lineCount}`);
    recorder.assert('hard-break-markup', /<br|<div|\n/i.test(metrics.html),
        'The break is represented in the box markup', metrics.html.slice(0, 80));

    await commitAndDeselect(page);
    const committed = await textMetrics(page);
    recorder.assert('hard-break-survives-commit',
        committed.text.includes('First line') && committed.text.includes('Second line'),
        'Both lines survive the commit', JSON.stringify(committed.text));

    await saveAndReload(page, saveRecorder);
    const reloaded = await textMetrics(page);
    recorder.assert('survives-reload',
        !!reloaded && reloaded.text.includes('First line') && reloaded.text.includes('Second line'),
        'Both lines survive a save and reload', JSON.stringify(reloaded && reloaded.text));
    recorder.assert('lines-after-reload', !!reloaded && reloaded.lineCount >= 2,
        'The break is still a break after reload', `lines=${reloaded && reloaded.lineCount}`);

    artifacts.push(await capture(page, '09-typing-and-wrap', 'after-reload'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — Commit and cancel paths, and what happens to an empty box. */
async function testCommitPaths(page, { recorder }) {
    const artifacts = [];

    const placeAndType = async (text, fx, fy) => {
        await clickPlace(page, fx, fy);
        await page.keyboard.type(text);
        await page.waitForTimeout(350);
    };

    // Commit by clicking elsewhere on the page.
    await placeAndType('Alpha', 0.25, 0.2);
    const clickedAway = await clickEmptyPageSpot(page);
    recorder.assert('found-empty-spot', clickedAway,
        'An empty spot on the page was available to click');
    let boxes = await textBoxes(page);
    recorder.assert('click-away-keeps-text', boxes.length === 1 && boxes[0].text.includes('Alpha'),
        'Clicking away keeps the typed text', JSON.stringify(boxes.map((b) => b.text)));
    recorder.assert('click-away-leaves-edit', boxes.length === 1 && !boxes[0].editing,
        'Clicking away leaves edit mode');

    // Commit with Escape — it must not delete the box or clear the text.
    await deleteAllTextBoxes(page);
    await placeAndType('Beta', 0.3, 0.3);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    boxes = await textBoxes(page);
    recorder.assert('escape-keeps-box', boxes.length === 1,
        'Escape does not delete the box', `count=${boxes.length}`);
    recorder.assert('escape-keeps-text', boxes.length === 1 && boxes[0].text.includes('Beta'),
        'Escape does not clear the text', JSON.stringify(boxes.map((b) => b.text)));
    recorder.assert('escape-leaves-edit', boxes.length === 1 && !boxes[0].editing,
        'Escape leaves edit mode');

    // Commit by selecting another annotation.
    await placeAndType('Gamma', 0.6, 0.45);
    await selectTextBox(page, 0);
    boxes = await textBoxes(page);
    const gamma = boxes.find((box) => box.text.includes('Gamma'));
    recorder.assert('select-other-commits', !!gamma && !gamma.editing,
        'Selecting another annotation commits the one being edited',
        JSON.stringify(boxes.map((b) => ({ text: b.text, editing: b.editing }))));
    recorder.equals('both-boxes-kept', boxes.length, 2, 'Both boxes survive');

    // Commit by switching to another tool.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await placeAndType('Delta', 0.3, 0.5);
    const shapeButton = await page.evaluate(() => {
        const candidates = ['#add-shape-btn', '#ftb-shapes', '#ftb-add-shape'];
        return candidates.find((selector) => {
            const el = document.querySelector(selector);
            return !!el && !!el.offsetParent;
        }) || null;
    });
    if (shapeButton) {
        await page.click(shapeButton);
        await page.waitForTimeout(600);
        boxes = await textBoxes(page);
        recorder.assert('tool-switch-keeps-text', boxes.length === 1 && boxes[0].text.includes('Delta'),
            'Switching tools keeps the typed text', JSON.stringify(boxes.map((b) => b.text)));
        recorder.assert('tool-switch-leaves-edit', boxes.length === 1 && !boxes[0].editing,
            'Switching tools leaves edit mode');
        await page.click(shapeButton).catch(() => {});
        await page.waitForTimeout(400);
    } else {
        recorder.fail('tool-switch-keeps-text', 'No visible second tool to switch to');
    }

    artifacts.push(await capture(page, '10-commit-and-empty', 'committed'));

    // An empty box is discarded rather than left behind as an invisible
    // annotation. Confirmed as intended behaviour.
    await commitAndDeselect(page);
    await deleteAllTextBoxes(page);
    await clickPlace(page, 0.4, 0.6);
    recorder.equals('empty-box-exists-while-editing', (await textBoxes(page)).length, 1,
        'The box exists while it is being edited');
    await commitAndDeselect(page);
    recorder.equals('empty-box-discarded', (await textBoxes(page)).length, 0,
        'A box committed with no text is discarded');

    // Whitespace is invisible too, so it should be treated the same way.
    await clickPlace(page, 0.45, 0.65);
    await page.keyboard.type('   ');
    await page.waitForTimeout(350);
    await commitAndDeselect(page);
    recorder.equals('whitespace-box-discarded', (await textBoxes(page)).length, 0,
        'A box committed with only whitespace is discarded too');

    artifacts.push(await capture(page, '10-commit-and-empty', 'empty-discarded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — Text Options panel opens for the selection and mirrors its state. */
async function testPanelMirrorsSelection(page, { recorder }) {
    const artifacts = [];

    // Box A gets a distinctive set of styles, applied through the real panel.
    await clickPlace(page, 0.25, 0.25);
    await page.keyboard.type('Styled');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);
    await selectTextBox(page, 0);

    let panel = await panelState(page);
    recorder.assert('panel-opens', panel.visible,
        'Selecting a box opens the Text Options panel');

    await page.click('#afb-bold');
    await page.waitForTimeout(400);
    await page.click('#afb-italic');
    await page.waitForTimeout(400);
    await page.selectOption('#afb-align', 'center');
    await page.waitForTimeout(400);
    await page.selectOption('#afb-opacity', '0.5');
    await page.waitForTimeout(400);
    await setColourInput(page, 'afb-text-color', '#ff0000');

    panel = await panelState(page);
    recorder.equals('a-bold', panel.bold, 'true', 'Bold reads as pressed after applying it');
    recorder.equals('a-italic', panel.italic, 'true', 'Italic reads as pressed after applying it');
    recorder.equals('a-align', panel.align, 'center', 'Alignment reads back as centre');
    recorder.equals('a-opacity', panel.opacity, '0.5', 'Opacity reads back as 50%');
    recorder.equals('a-colour', String(panel.textColor).toLowerCase(), '#ff0000',
        'Text colour reads back as the chosen red');

    artifacts.push(await capture(page, '11-panel-mirrors-selection', 'styled-box'));

    // Box B is left at defaults; selecting it must re-read every control.
    await commitAndDeselect(page);
    await clickPlace(page, 0.6, 0.55);
    await page.keyboard.type('Plain');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);

    await selectTextBoxByText(page, 'Plain');
    panel = await panelState(page);
    recorder.equals('b-bold', panel.bold, 'false', 'Bold does not carry over to the next selection');
    recorder.equals('b-italic', panel.italic, 'false', 'Italic does not carry over to the next selection');
    recorder.equals('b-align', panel.align, 'left', 'Alignment does not carry over');
    recorder.equals('b-opacity', panel.opacity, '1', 'Opacity does not carry over');
    recorder.equals('b-colour', String(panel.textColor).toLowerCase(), '#000000',
        'Text colour does not carry over');
    recorder.equals('b-valign', panel.valign, 'top', 'Vertical alignment reads its default');

    // ...and switching back must restore the styled box's values.
    await selectTextBoxByText(page, 'Styled');
    panel = await panelState(page);
    // NK_8 regression. Placing the second box re-rendered the annotation layer,
    // which used to overwrite the authored weight and style with the document
    // font's own — so bold and italic reverted on the page. These two checks are
    // the guard; keep them named so a recurrence is attributed to NK_8 rather
    // than read as a panel-state problem.
    recorder.equals('a-bold-again', panel.bold, 'true',
        'Bold survives the layer re-render caused by placing another box (NK_8)');
    recorder.equals('a-italic-again', panel.italic, 'true',
        'Italic survives the layer re-render caused by placing another box (NK_8)');
    recorder.equals('a-align-again', panel.align, 'center', 'Reselecting restores its alignment');

    // Close hides the panel without changing the annotation.
    const findStyled = async () => (await textBoxes(page)).find((box) => box.text.includes('Styled'));
    const beforeClose = await findStyled();
    await page.click('#afb-close');
    await page.waitForTimeout(400);
    panel = await panelState(page);
    recorder.assert('close-hides-panel', !panel.visible, 'The close button hides the panel');
    const afterClose = await findStyled();
    recorder.assert('close-preserves-annotation',
        afterClose.text === beforeClose.text
        && Math.abs(afterClose.annX - beforeClose.annX) < 0.01,
        'Closing the panel does not change the annotation');

    // Deselecting hides it too.
    await selectTextBoxByText(page, 'Styled');
    await commitAndDeselect(page);
    panel = await panelState(page);
    recorder.assert('deselect-hides-panel', !panel.visible, 'Deselecting hides the panel');

    // A locked annotation disables the controls.
    await selectTextBoxByText(page, 'Styled');
    const locked = await annotationMenuAction(page, 'lock');
    if (locked) {
        panel = await panelState(page);
        const boxNow = await findStyled();
        recorder.assert('lock-applied', boxNow.locked, 'The annotation is locked');
        recorder.assert('locked-disables-controls',
            panel.disabled.font === true && panel.disabled.size === true
            && panel.disabled.bold === true && panel.disabled.align === true,
            'Every panel control is disabled on a locked annotation',
            JSON.stringify(panel.disabled));
    } else {
        recorder.fail('locked-disables-controls', 'No lock control in the annotation menu');
    }

    artifacts.push(await capture(page, '11-panel-mirrors-selection', 'locked'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — Font family: standard, Google and document-embedded fonts. */
async function testFontFamily(page, { saveRecorder, recorder }) {
    const artifacts = [];

    await clickPlace(page, 0.25, 0.25);
    await page.keyboard.type('Font check');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);
    await selectTextBox(page, 0);

    const options = await page.evaluate(() => Array.from(
        document.querySelectorAll('#afb-font option'),
    ).map((option) => ({ value: option.value, label: option.textContent.trim(), disabled: option.disabled })));

    const separators = options.filter((option) => option.disabled);
    recorder.assert('separator-disabled', separators.length > 0 && separators.every((s) => s.disabled),
        'The Google Fonts separator is a disabled option that cannot be selected',
        JSON.stringify(separators.map((s) => s.label)));
    const fontBefore = (await singleTextBox(page)).fontFamily;
    const selectedSeparator = await page.selectOption('#afb-font', separators[0].value)
        .then(() => true)
        .catch(() => false);
    await page.waitForTimeout(400);
    const fontAfter = (await singleTextBox(page)).fontFamily;
    recorder.assert('separator-not-selectable', !selectedSeparator && fontAfter === fontBefore,
        'The separator cannot be chosen and does not change the font',
        `selected=${selectedSeparator} font=${fontBefore} -> ${fontAfter}`);

    const standard = ['Georgia', 'Courier', 'TimesRoman'];
    for (const font of standard) {
        // eslint-disable-next-line no-await-in-loop
        await page.selectOption('#afb-font', font);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(500);
        // eslint-disable-next-line no-await-in-loop
        const box = await singleTextBox(page);
        recorder.equals(`applies-${font}`, box.fontFamily, font,
            `Choosing ${font} records it on the annotation`);
        // eslint-disable-next-line no-await-in-loop
        const rendered = await page.evaluate((selector) => {
            const el = document.querySelector(selector)?.querySelector('.enpv-text-content');
            return el ? window.getComputedStyle(el).fontFamily : '';
        }, TEXT_BOX_SELECTOR);
        const family = font === 'TimesRoman' ? 'times' : font.toLowerCase();
        recorder.assert(`renders-${font}`, rendered.toLowerCase().includes(family),
            `The page text renders in ${font}`, rendered);
    }

    // A Google face: the value must be stored even though this container has no
    // outbound route to fetch the webfont.
    await page.selectOption('#afb-font', 'Roboto');
    await page.waitForTimeout(500);
    let box = await singleTextBox(page);
    recorder.equals('applies-google-font', box.fontFamily, 'Roboto',
        'Choosing a Google face records it on the annotation');

    artifacts.push(await capture(page, '12-font-family', 'roboto'));

    // The choice has to survive a save and reload.
    await commitAndDeselect(page);
    await page.selectOption('#afb-font', 'Georgia').catch(() => {});
    await selectTextBox(page, 0);
    await page.selectOption('#afb-font', 'Georgia');
    await page.waitForTimeout(500);
    await commitAndDeselect(page);
    await saveAndReload(page, saveRecorder);

    box = await singleTextBox(page);
    recorder.equals('survives-reload', box.fontFamily, 'Georgia',
        'The chosen font survives a save and reload');
    await selectTextBox(page, 0);
    const panel = await panelState(page);
    recorder.equals('panel-reads-font', panel.font, 'Georgia',
        'The panel reads the saved font back after a reload');

    artifacts.push(await capture(page, '12-font-family', 'after-reload'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 33 — Rotate a text box (NK_9). */
async function testRotateTextBox(page, { saveRecorder, recorder }) {
    const artifacts = [];

    await clickPlace(page, 0.3, 0.3);
    await page.keyboard.type('Rotate me');
    await page.waitForTimeout(350);
    await commitAndDeselect(page);

    let box = await singleTextBox(page);
    recorder.equals('starts-upright', box.rotation, 0, 'A new text box starts unrotated');
    recorder.assert('no-handle-unselected', !box.rotateHandleVisible,
        'The rotate handle is not offered while nothing is selected');

    await selectTextBoxByText(page, 'Rotate me');
    box = await singleTextBox(page);
    recorder.assert('handle-on-selection', box.rotateHandleVisible,
        'Selecting the box offers a rotate handle');

    const rotated = await dragRotateHandle(page, 90);
    box = await singleTextBox(page);
    recorder.near('rotates-to-angle', rotated, 90, 6,
        'Dragging the handle a quarter turn rotates the box by about 90 degrees');
    recorder.assert('marked-rotated', box.isRotated,
        'The box is flagged as rotated');
    recorder.assert('content-transformed', /rotate\(/.test(box.contentTransform),
        'The text content carries a rotation transform', box.contentTransform);
    recorder.assert('box-stays-axis-aligned',
        !/rotate\(/.test(String(box.boxTransform || '')),
        'The box itself is not rotated — only its content is, matching the PDF writer');

    artifacts.push(await capture(page, '33-rotate-text-box', 'rotated'));

    // A re-render of the layer must not straighten it back up.
    const transformAfterRotate = box.contentTransform;
    await commitAndDeselect(page);
    await clickPlace(page, 0.65, 0.6);
    await page.keyboard.type('Second');
    await page.waitForTimeout(300);
    await commitAndDeselect(page);

    let boxes = await textBoxes(page);
    let target = boxes.find((entry) => entry.text.includes('Rotate me'));
    recorder.near('survives-rerender', target?.rotation ?? -1, rotated, 0.5,
        'The angle survives the re-render caused by placing another box');
    recorder.equals('transform-survives-rerender', target?.contentTransform, transformAfterRotate,
        'The rotation transform is unchanged by the re-render');

    // ...nor a save and reload.
    await saveAndReload(page, saveRecorder);
    boxes = await textBoxes(page);
    target = boxes.find((entry) => entry.text.includes('Rotate me'));
    recorder.assert('survives-reload-present', !!target,
        'The rotated box is still there after a reload');
    recorder.near('survives-reload', target?.rotation ?? -1, rotated, 0.5,
        'The angle survives a save and reload');
    recorder.assert('transform-after-reload', /rotate\(/.test(String(target?.contentTransform || '')),
        'The box still renders rotated after a reload', target?.contentTransform);

    artifacts.push(await capture(page, '33-rotate-text-box', 'after-reload'));

    // A locked annotation offers no handle.
    await selectTextBoxByText(page, 'Rotate me');
    const locked = await annotationMenuAction(page, 'lock');
    if (locked) {
        const lockedBox = (await textBoxes(page)).find((entry) => entry.text.includes('Rotate me'));
        recorder.assert('locked-has-no-handle', !lockedBox?.rotateHandleVisible,
            'A locked annotation offers no rotate handle');
    } else {
        recorder.fail('locked-has-no-handle', 'No lock control in the annotation menu');
    }

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Small shared helpers used by the tests above
// ---------------------------------------------------------------------------

function normaliseColour(value) {
    const text = String(value || '').trim().toLowerCase();
    const rgb = text.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (rgb) {
        return `#${[rgb[1], rgb[2], rgb[3]]
            .map((part) => Number(part).toString(16).padStart(2, '0')).join('')}`;
    }
    if (/^#[0-9a-f]{3}$/.test(text)) {
        return `#${text.slice(1).split('').map((c) => c + c).join('')}`;
    }
    return text;
}

function isTransparent(value) {
    const text = String(value || '').trim().toLowerCase();
    return text === '' || text === 'transparent' || text === 'rgba(0, 0, 0, 0)';
}

/** True when no add-text drag preview element is left in the DOM. */
function previewRemoved(page) {
    return page.evaluate(() => document.querySelectorAll('.enpv-text-drag-selection, .text-drag-selection').length === 0);
}

/**
 * Remove every placed text box so the next scenario starts clean.
 *
 * Deletion goes through the annotation menu rather than the Delete key: the
 * caret is often inside the box, where Delete is a text edit rather than an
 * annotation command.
 */
async function deleteAllTextBoxes(page) {
    /* eslint-disable no-await-in-loop */
    for (let guard = 0; guard < 12; guard++) {
        const before = (await textBoxes(page)).length;
        if (before === 0) return;

        await clickInsideBox(page, 0);
        const clicked = await page.evaluate(() => {
            const button = document.querySelector('#enpv-ann-menu [data-action="delete"]');
            if (!button) return false;
            button.click();
            return true;
        });
        if (!clicked) throw new Error('Annotation menu has no delete action');
        await page.waitForTimeout(450);

        if ((await textBoxes(page)).length >= before) {
            // Fall back to the keyboard once, in case the menu click was
            // swallowed by an in-flight re-render.
            await page.keyboard.press('Escape');
            await page.waitForTimeout(200);
            await clickInsideBox(page, 0).catch(() => {});
            await page.keyboard.press('Delete');
            await page.waitForTimeout(400);
        }
    }
    /* eslint-enable no-await-in-loop */
    if ((await textBoxes(page)).length > 0) {
        throw new Error('Could not clear placed text boxes');
    }
}

// ---------------------------------------------------------------------------
// Registry
// ---------------------------------------------------------------------------

const TESTS = [
    {
        id: '01-blank-project-loads',
        number: '01',
        title: 'Setup: blank PDF project loads with the Add Text tool available',
        run: testBlankProjectLoads,
    },
    {
        id: '02-add-text-mode-toggle',
        number: '02',
        title: 'Add Text toggles from both toolbars and is exclusive with other tools',
        run: testAddTextModeToggle,
    },
    {
        id: '03-click-to-place-defaults',
        number: '03',
        title: 'Click-to-place creates a text box with the documented defaults',
        run: testClickToPlaceDefaults,
    },
    {
        id: '04-drag-to-size',
        number: '04',
        title: 'Drag-to-size honours the dragged rectangle, with a click fallback',
        run: testDragToSize,
    },
    {
        id: '05-page-clamping',
        number: '05',
        title: 'New boxes are clamped inside the page',
        run: testPageClamping,
    },
    {
        id: '06-zoom-accuracy',
        number: '06',
        title: 'Placement stays accurate across zoom levels',
        run: testZoomAccuracy,
    },
    {
        id: '07-rotated-pages',
        number: '07',
        title: 'Rotated pages are not editable, and rotating back restores the tool',
        run: testRotatedPages,
    },
    {
        id: '08-edit-mode-ready',
        number: '08',
        title: 'A newly placed box opens in edit mode with the caret ready',
        run: testEditModeReady,
    },
    {
        id: '09-typing-and-wrap',
        // Needs edit mode, which is premium-gated.
        signedIn: true,
        number: '09',
        title: 'Typing, multi-line entry, word wrap and box reflow',
        run: testTypingAndWrap,
    },
    {
        id: '10-commit-and-empty',
        // Needs edit mode, which is premium-gated.
        signedIn: true,
        number: '10',
        title: 'Commit and cancel paths, and what happens to an empty box',
        run: testCommitPaths,
    },
    {
        id: '11-panel-mirrors-selection',
        // Needs edit mode, which is premium-gated.
        signedIn: true,
        number: '11',
        title: 'Text Options panel opens for the selection and mirrors its state',
        run: testPanelMirrorsSelection,
    },
    {
        id: '12-font-family',
        // Needs edit mode, which is premium-gated.
        signedIn: true,
        number: '12',
        title: 'Font family: standard, Google and document-embedded fonts',
        run: testFontFamily,
    },
    {
        id: '33-rotate-text-box',
        number: '33',
        title: 'Rotate a text box',
        // Needs edit mode, which is premium-gated.
        signedIn: true,
        run: testRotateTextBox,
    },
];

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

function summarise(test, checks, artifacts, error, startedAt) {
    const passed = checks.filter((check) => check.result === 'PASS').length;
    const total = checks.length;
    let status = 'passed';
    if (error) status = 'error';
    else if (total === 0) status = 'error';
    else if (passed < total) status = 'failed';

    return {
        id: test.id,
        number: test.number,
        title: test.title,
        status,
        checks,
        checks_passed: passed,
        checks_total: total,
        artifacts: artifacts || [],
        error: error ? String(error.message || error) : null,
        duration_ms: Date.now() - startedAt,
    };
}

async function runTests(ids) {
    const selected = TESTS.filter((test) => ids.includes(test.id));
    if (selected.length === 0) {
        return { success: false, message: 'No known test ids requested', results: [] };
    }

    let browser = null;
    const results = [];
    const runStartedAt = Date.now();
    let docId = null;

    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
        });

        for (const test of selected) {
            const startedAt = Date.now();
            const recorder = createRecorder();
            let context = null;
            try {
                // A fresh context and document per test, so no test can leak
                // state into another.
                context = await browser.newContext({
                    viewport: { width: 1500, height: 1000 },
                    ignoreHTTPSErrors: true,
                });

                // None of these tests are about webfonts, and the container has
                // no reliable outbound route — letting Google Fonts requests hang
                // would add a timeout to every page load. Fulfil with an empty
                // stylesheet rather than aborting: an abort surfaces as a console
                // error, which test 01 legitimately asserts against.
                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => {
                    route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {});
                });

                const page = await context.newPage();
                const saveRecorder = attachSaveRecorder(page);
                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(page, csrfToken, `Text tool test ${test.number}`, test.pages || 1);
                const consoleErrors = test.signedIn
                    ? await openEditorAsSignedIn(page, docId)
                    : await openEditor(page, docId);

                const outcome = await test.run(page, { consoleErrors, docId, recorder, saveRecorder });
                results.push({
                    ...summarise(test, outcome.checks, outcome.artifacts, null, startedAt),
                    document_id: docId,
                });
            } catch (error) {
                // Keep whatever the test proved before it fell over.
                results.push({
                    ...summarise(test, recorder.checks, [], error, startedAt),
                    document_id: docId,
                });
            } finally {
                if (context) {
                    try { await context.close(); } catch (_) { /* ignore */ }
                }
            }
        }
    } catch (error) {
        return {
            success: false,
            message: String(error && error.message ? error.message : error),
            results,
        };
    } finally {
        if (browser) {
            try { await browser.close(); } catch (_) { /* ignore */ }
        }
    }

    const totalChecks = results.reduce((sum, result) => sum + result.checks_total, 0);
    const passedChecks = results.reduce((sum, result) => sum + result.checks_passed, 0);

    return {
        success: true,
        results,
        summary: {
            tests_total: results.length,
            tests_passed: results.filter((result) => result.status === 'passed').length,
            tests_failed: results.filter((result) => result.status === 'failed').length,
            tests_errored: results.filter((result) => result.status === 'error').length,
            checks_total: totalChecks,
            checks_passed: passedChecks,
            duration_ms: Date.now() - runStartedAt,
        },
    };
}

async function main() {
    const args = process.argv.slice(2);

    if (args.includes('--list')) {
        console.log(JSON.stringify({
            total: TESTS.length,
            tests: TESTS.map((test) => ({ id: test.id, number: test.number, title: test.title })),
        }));
        return;
    }

    let ids = TESTS.map((test) => test.id);
    if (args.includes('--run')) {
        const raw = args[args.indexOf('--run') + 1] || '';
        ids = raw.split(',').map((value) => value.trim()).filter(Boolean);
    } else if (!args.includes('--run-all')) {
        console.error('Usage: node run_text_tests.cjs --list | --run <ids> | --run-all');
        process.exit(1);
    }

    const output = await runTests(ids);
    console.log(JSON.stringify(output));
}

// Exported so the harness can be reused (e.g. by an ad-hoc probe) without
// running the suite.
module.exports = {
    TESTS,
    BASE_URL,
    TEXT_BOX_SELECTOR,
    PLACEMENT_DEFAULTS,
    buildBlankPdfBuffer,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    viewerScale,
    setAddTextMode,
    pointOnPage,
    textBoxes,
    clickPlace,
    dragPlace,
    runTests,
};

// Only auto-run as a CLI; requiring this file (e.g. to reuse helpers) must not
// kick off a browser run.
if (require.main === module) {
    main().catch((error) => {
        console.log(JSON.stringify({
            success: false,
            message: String(error && error.message ? error.message : error),
            results: [],
        }));
        process.exit(0);
    });
}
