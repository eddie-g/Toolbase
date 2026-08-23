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

    // A user-created box stays editable with edit mode off.
    const editModeOff = await page.evaluate(() => !document.body.classList.contains('enpv-edit-on'));
    recorder.assert('edit-mode-off', editModeOff,
        'Edit mode is still off, so this box is interactive on its own merits');
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

/** Remove every placed text box so the next scenario starts clean. */
async function deleteAllTextBoxes(page) {
    /* eslint-disable no-await-in-loop */
    for (let guard = 0; guard < 10; guard++) {
        const remaining = await textBoxes(page);
        if (remaining.length === 0) return;
        await page.evaluate((selector) => {
            const box = document.querySelector(selector);
            if (box) box.click();
        }, TEXT_BOX_SELECTOR);
        await page.waitForTimeout(250);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        await page.evaluate((selector) => {
            const box = document.querySelector(selector);
            if (box) box.click();
        }, TEXT_BOX_SELECTOR);
        await page.waitForTimeout(250);
        await page.keyboard.press('Delete');
        await page.waitForTimeout(350);
    }
    /* eslint-enable no-await-in-loop */
    throw new Error('Could not clear placed text boxes');
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
        id: '08-edit-mode-ready',
        number: '08',
        title: 'A newly placed box opens in edit mode with the caret ready',
        run: testEditModeReady,
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
                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(page, csrfToken, `Text tool test ${test.number}`, test.pages || 1);
                const consoleErrors = await openEditor(page, docId);

                const outcome = await test.run(page, { consoleErrors, docId, recorder });
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
