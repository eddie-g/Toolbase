/**
 * Draw Tool Automated Tests (Playwright)
 *
 * Automates part of the "[QA] - Draw tool" story. Each run creates a fresh
 * blank PDF, uploads it as a new document, opens it in the pdf.js editor, and
 * drives the real UI.
 *
 * The harness (document setup, page geometry, save recording, zoom, page
 * rotation, the annotation menu, edit mode, the layers panel and admin
 * sign-in) is deliberately identical to the Shapes and Text suites. Only the
 * probes below the "Draw tool probes" banner and the cases themselves are
 * specific to this tool.
 *
 * What makes Draw different from Shapes, and what the cases lean on:
 *
 *   - A drawing is an IMAGE annotation, not its own type. It is marked by
 *     data-direct-draw="1" on the box and directDrawTool on the annotation,
 *     so the suite matches on those rather than on an annotation type.
 *   - pdf.js keeps the stroke as SVG vectors in directDrawVector.strokes,
 *     alongside the rasterised dataUrl. That is what makes colour, size,
 *     opacity and (since NK_16) smoothing re-editable after the fact.
 *   - The eraser is a draw tool, not a separate mode: same session, same
 *     commit path, but the annotation renders above the content it covers.
 *
 * Usage:
 *   node run_draw_tests.cjs --list
 *   node run_draw_tests.cjs --run 01-blank-project-loads,03-pen-draws-a-stroke
 *   node run_draw_tests.cjs --run-all
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

/** A drawing is an image annotation flagged by the direct-draw dataset marker. */
const DRAW_BOX_SELECTOR = '.enpv-annotation-box[data-direct-draw="1"]';

/** The two tools the Draw Options panel offers. */
const DRAW_TOOLS = ['pen', 'eraser'];

/**
 * The six ink swatches in _draw-panel.blade.php, in order. Asserted by the
 * colour case, so adding a swatch has to be a deliberate change in both places.
 */
const INK_SWATCHES = ['#111827', '#dc2626', '#2563eb', '#16a34a', '#ea580c', '#7c3aed'];

/**
 * What the draw-tool store slice starts a fresh session with. Asserted by the
 * defaults case; DEFAULT_DRAW_SMOOTHING lives in draw/smooth-curve.js.
 */
const DRAW_DEFAULTS = {
    tool: 'pen',
    color: '#111827',
    size: 10,
    smoothingPercent: 58,
    opacityPercent: 100,
};

/** Slider bounds, mirrored from the panel markup. */
const DRAW_RANGES = {
    size: { min: 2, max: 36 },
    smoothing: { min: 0, max: 100 },
    opacity: { min: 10, max: 100 },
};

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

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
        /**
         * A check that could not be exercised here. Kept out of the pass
         * denominator so it neither fails the run nor counts as a pass.
         */
        skip: (item, description, detail) => {
            checks.push({
                result: 'SKIP',
                item,
                description,
                detail: detail == null ? '' : String(detail),
            });
            return false;
        },
    };
}

function ensureArtifactDir() {
    if (!fs.existsSync(ARTIFACT_DIR)) {
        fs.mkdirSync(ARTIFACT_DIR, { recursive: true, mode: 0o777 });
    }
}

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
    const title = String(label || 'Draw tool automated test').replace(/[()\\]/g, '');
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
 * Upload a blank PDF and return the new document id. The filename is unique
 * per upload: the app rejects a duplicate original_name with a 409.
 */
async function createBlankDocument(page, csrfToken, label, pageCount = 1) {
    uploadCounter += 1;
    const name = `draw_tool_test_${process.pid}_${uploadCounter}.pdf`;

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
 * Edit mode is premium, and some annotation interactions are only available to
 * a signed-in user. The editor reads its own authentication from one data
 * attribute, so the response is rewritten the same way the Text suite does it.
 * This is a shim for the premium gate only — cases about the guest experience
 * must NOT use it.
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
// Page geometry
// ---------------------------------------------------------------------------

/** Spiral of probe offsets, used to nudge off floating chrome. */
function probeOffsets() {
    const offsets = [];
    for (let radius = 20; radius <= 180; radius += 20) {
        offsets.push([0, -radius], [0, radius], [-radius, 0], [radius, 0],
            [-radius, -radius], [radius, -radius], [-radius, radius], [radius, radius]);
    }
    return offsets;
}

/**
 * Resolve a clickable viewport point at a fractional page position.
 *
 * Pages are routinely larger than the viewport and floating chrome covers
 * parts of the viewer, so the point is scrolled into view then probed outward
 * until it genuinely lands on the target page.
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

        // eslint-disable-next-line no-await-in-loop
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
            // eslint-disable-next-line no-await-in-loop
            const fresh = await locator.boundingBox();
            return {
                x: point.x,
                y: point.y,
                pageBox: fresh || box,
                relX: point.x - (fresh || box).x,
                relY: point.y - (fresh || box).y,
            };
        }

        // Centre the wanted point in the viewer, then probe again. Scrolling the
        // window does nothing here — pdf.js scrolls its own container.
        // eslint-disable-next-line no-await-in-loop
        await page.evaluate(([dx, dy]) => {
            const container = document.getElementById('viewerContainer');
            if (!container) return;
            container.scrollLeft += dx;
            container.scrollTop += dy;
        }, [desiredX - (viewport.width / 2), desiredY - (viewport.height / 2)]);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(400);
    }

    throw new Error(`No clickable point found near (${fx}, ${fy}) on page ${pageNumber}`);
}

function attachSaveRecorder(page) {
    const recorder = { saves: [] };
    page.on('response', async (response) => {
        try {
            if (!response.url().includes('/save-annotation-state')) return;
            if (response.request().method() !== 'POST') return;
            let body = {};
            try { body = response.request().postDataJSON() || {}; } catch (_) { body = {}; }
            recorder.saves.push({
                status: response.status(),
                ok: response.ok(),
                annotations: Array.isArray(body.annotations) ? body.annotations : [],
            });
        } catch (_) {
            // A response that vanishes mid-navigation must not break the run.
        }
    });
    return recorder;
}

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
    await page.waitForTimeout(1400);
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
        // eslint-disable-next-line no-await-in-loop
        const before = (await readZoom(page)).scale;
        // eslint-disable-next-line no-await-in-loop
        await page.click(selector, { force: true });
        // eslint-disable-next-line no-await-in-loop
        await page.waitForFunction(
            (previous) => Math.abs((Number(window.__enpv?.pdfViewer?.currentScale) || 0) - previous) > 0.001,
            before,
            { timeout: 8000 },
        ).catch(() => {});
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(600);
    }
    return readZoom(page);
}

function pageRotation(page, pageNumber = 1) {
    return page.evaluate((number) => {
        const view = window.__enpv?.pdfViewer?.getPageView(number - 1);
        return Number(view?.viewport?.rotation) || 0;
    }, pageNumber);
}

/**
 * Rotate a page through the page manager, the only rotation UI.
 *
 * Rotation round-trips to the server and re-renders, so this waits for the
 * viewport to report the new angle. The "cannot edit" dialog is read BEFORE any
 * cleanup, because closing the manager involves an Escape which dismisses that
 * dialog too — and whether it appears is part of what case 11 asserts.
 */
async function rotatePage(page, pageNumber, direction = 'right') {
    const before = await pageRotation(page, pageNumber);
    await page.click('#enpv-page-manager-open');
    await page.waitForSelector('.enpv-page-manager-item', { timeout: 15000 });
    await page.waitForTimeout(400);
    await page.click(`.enpv-page-manager-item:nth-of-type(${pageNumber}) .enpv-page-manager-rotate-${direction}`);
    await page.waitForFunction(
        ({ number, previous }) => {
            const view = window.__enpv?.pdfViewer?.getPageView(number - 1);
            const rotation = Number(view?.viewport?.rotation);
            return Number.isFinite(rotation) && rotation !== previous;
        },
        { number: pageNumber, previous: before },
        { timeout: 60000 },
    );

    let dialogShown = false;
    let dialogMessage = '';
    for (let attempt = 0; attempt < 10 && !dialogShown; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const state = await page.evaluate(() => {
            const el = document.getElementById('enpv-rotated-edit-dialog');
            return {
                shown: !!el && el.hidden === false,
                message: document.getElementById('enpv-rotated-edit-dialog-description')?.textContent?.trim() || '',
            };
        });
        dialogShown = state.shown;
        dialogMessage = state.message;
        // eslint-disable-next-line no-await-in-loop
        if (!dialogShown) await page.waitForTimeout(200);
    }

    // A modal left open covers the page, and every later click lands on it.
    for (let attempt = 0; attempt < 3; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const open = await page.evaluate(() => document.getElementById('enpv-page-manager-modal')?.hidden === false);
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
    await page.evaluate(() => {
        const el = document.getElementById('enpv-rotated-edit-dialog');
        if (el && el.hidden === false) document.getElementById('enpv-rotated-edit-dialog-close')?.click();
    });
    await page.waitForTimeout(900);
    return { rotation: await pageRotation(page, pageNumber), dialogShown, dialogMessage };
}


function annMenuState(page) {
    return page.evaluate(() => {
        const menu = document.getElementById('enpv-ann-menu');
        if (!menu) return { present: false, visible: false, actions: [] };
        const menuRect = menu.getBoundingClientRect();
        const actions = Array.from(menu.querySelectorAll('[data-action]')).map((button) => {
            const rect = button.getBoundingClientRect();
            return {
                action: button.dataset.action,
                visible: !button.hidden && rect.width > 0 && rect.height > 0,
            };
        });
        return {
            present: true,
            visible: !menu.hidden && menuRect.width > 0 && menuRect.height > 0,
            left: menuRect.left,
            top: menuRect.top,
            actions,
            offered: actions.filter((a) => a.visible).map((a) => a.action),
        };
    });
}

/** Click one action in the annotation menu. Returns false if it is not offered. */
async function annotationMenuAction(page, action) {
    const offered = await page.evaluate((name) => {
        const button = document.querySelector(`#enpv-ann-menu [data-action="${name}"]`);
        if (!button || button.hidden) return false;
        const rect = button.getBoundingClientRect();
        // On-screen as well as sized: the menu can sit at a negative y, where a
        // forced click silently does nothing.
        return rect.width > 0 && rect.height > 0
            && rect.top >= 0 && rect.left >= 0
            && rect.bottom <= window.innerHeight && rect.right <= window.innerWidth;
    }, action);
    if (!offered) return false;
    await page.click(`#enpv-ann-menu [data-action="${action}"]`, { force: true });
    await page.waitForTimeout(600);
    return true;
}

/** The rotate handle for the selected drawing, if one is on show. */
function rotateHandleAt(page) {
    return page.evaluate((selector) => {
        const box = document.querySelector(`${selector}.is-selected`);
        if (!box) return null;
        const handle = box.querySelector(':scope > .enpv-shape-rotate-handle');
        if (!handle) return { present: false, visible: false };
        const rect = handle.getBoundingClientRect();
        return {
            present: true,
            visible: rect.width > 0 && rect.height > 0,
            x: rect.left + (rect.width / 2),
            y: rect.top + (rect.height / 2),
        };
    }, DRAW_BOX_SELECTOR);
}

/** Drag the rotate handle around the drawing's centre by an angle in degrees. */
async function dragRotateHandle(page, degrees) {
    const handle = await rotateHandleAt(page);
    if (!handle?.visible) return false;
    const centre = await page.evaluate((selector) => {
        const box = document.querySelector(`${selector}.is-selected`);
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, DRAW_BOX_SELECTOR);

    const radius = Math.hypot(handle.x - centre.x, handle.y - centre.y) || 60;
    const startAngle = Math.atan2(handle.y - centre.y, handle.x - centre.x);
    const target = startAngle + ((degrees * Math.PI) / 180);

    await page.mouse.move(handle.x, handle.y);
    await page.mouse.down();
    const steps = 10;
    for (let step = 1; step <= steps; step++) {
        const angle = startAngle + (((target - startAngle) * step) / steps);
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(centre.x + (radius * Math.cos(angle)), centre.y + (radius * Math.sin(angle)));
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(35);
    }
    await page.mouse.up();
    await page.waitForTimeout(700);
    return true;
}

function editModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-edit-on'));
}

/** Toggle edit mode through its real control, top bar or floating toolbar. */
async function setEditMode(page, on) {
    if ((await editModeOn(page)) === on) return;
    const selector = await page.evaluate(() => {
        const top = document.getElementById('edit-mode-toggle');
        if (top && top.offsetParent) return '#edit-mode-toggle';
        const floating = document.getElementById('ftb-edit-mode');
        return floating && floating.offsetParent ? '#ftb-edit-mode' : null;
    });
    if (!selector) throw new Error('No visible edit-mode control');
    await page.click(selector, { force: true });
    await page.waitForFunction(
        (wanted) => document.body.classList.contains('enpv-edit-on') === wanted,
        on,
        { timeout: 15000 },
    );
    await page.waitForTimeout(700);
}

async function clickHistory(page, which) {
    await page.evaluate((id) => document.getElementById(id)?.click(), which === 'undo' ? 'undo-btn' : 'redo-btn');
    await page.waitForTimeout(700);
}

// ---------------------------------------------------------------------------
// Layers panel and admin session
// ---------------------------------------------------------------------------

async function layersPanelRows(page) {
    await page.evaluate(() => document.getElementById('enpv-layers-open')?.click());
    await page.waitForTimeout(700);
    return page.evaluate(() => {
        const panel = document.getElementById('enpv-layers-panel');
        return {
            open: !!panel && panel.hidden === false,
            rows: Array.from(panel?.querySelectorAll('[data-annotation-id]') || []).map((row) => ({
                id: row.dataset.annotationId,
                label: (row.textContent || '').replace(/\s+/g, ' ').trim(),
            })),
        };
    });
}

async function closeLayersPanel(page) {
    await page.evaluate(() => document.getElementById('enpv-layers-close')?.click());
    await page.waitForTimeout(400);
}

/**
 * Credentials for the QA admin, or null when none is configured. Forwarded by
 * AutomatedTestController from config/automated_tests.php.
 */
function adminCredentials() {
    const email = String(process.env.AUTOMATED_TESTS_ADMIN_EMAIL || '').trim();
    const password = String(process.env.AUTOMATED_TESTS_ADMIN_PASSWORD || '').trim();
    if (!email || !password) return null;
    return { email, password };
}

/**
 * Sign in through the real Filament admin login.
 *
 * The annotation ID field and debug mask are gated in Blade on
 * auth('admin')->check(), so there is nothing to shim client-side — the markup
 * simply is not sent to a non-admin.
 */
async function signInAsAdmin(page) {
    const credentials = adminCredentials();
    if (!credentials) return false;
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'load', timeout: 45000 });
    const email = await page.$('input[type="email"], #data\\.email');
    const password = await page.$('input[type="password"], #data\\.password');
    if (!email || !password) return false;
    await email.fill(credentials.email);
    await password.fill(credentials.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load', timeout: 45000 }).catch(() => null),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(400);
    return !/\/admin\/login/.test(page.url());
}

async function openEditorAsAdmin(page, docId) {
    if (!(await signInAsAdmin(page))) return null;
    return openEditor(page, docId);
}


// ---------------------------------------------------------------------------
// Draw tool probes
// ---------------------------------------------------------------------------

function drawModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-draw-on'));
}
function shapeModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-shape-on'));
}
function addTextModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-add-text-on'));
}
function highlightModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-highlight-on'));
}

/** Presence and state of the two controls that toggle the tool. */
function drawToolControls(page) {
    return page.evaluate(() => {
        const read = (id) => {
            const el = document.getElementById(id);
            if (!el) return { present: false };
            const rect = el.getBoundingClientRect();
            return {
                present: true,
                visible: rect.width > 0 && rect.height > 0,
                disabled: !!el.disabled,
                active: el.classList.contains('is-active') || el.classList.contains('active'),
                pressed: el.getAttribute('aria-pressed'),
                title: el.getAttribute('title') || '',
            };
        };
        return { floating: read('ftb-draw-erase'), topBar: read('draw-erase-btn') };
    });
}

/**
 * Toggle draw mode through a real control rather than the internal setter, so
 * the binding is part of what is under test.
 */
async function setDrawMode(page, on, control = 'ftb-draw-erase') {
    const already = await drawModeOn(page);
    if (already === !!on) return;
    await page.evaluate((id) => document.getElementById(id)?.click(), control);
    await page.waitForTimeout(450);
}

/** Everything the Draw Options panel is currently showing. */
function drawPanelState(page) {
    return page.evaluate(() => {
        const panel = document.getElementById('draw-tool-panel');
        const readRange = (id, valueId) => {
            const input = document.getElementById(id);
            const readout = document.getElementById(valueId);
            if (!input) return { present: false };
            return {
                present: true,
                value: Number(input.value),
                min: Number(input.min),
                max: Number(input.max),
                step: Number(input.step),
                disabled: !!input.disabled,
                readout: readout ? readout.textContent.trim() : '',
            };
        };
        const toolButtons = Array.from(document.querySelectorAll('[data-draw-direct-tool]')).map((button) => ({
            tool: button.dataset.drawDirectTool,
            active: button.classList.contains('is-active'),
            label: button.getAttribute('aria-label') || '',
        }));
        const swatches = Array.from(document.querySelectorAll('[data-draw-color]')).map((button) => ({
            color: String(button.dataset.drawColor || '').toLowerCase(),
            active: button.classList.contains('is-active'),
            label: button.getAttribute('aria-label') || '',
        }));
        const inkSection = document.getElementById('draw-tool-ink-colors');
        const eraserSection = document.getElementById('draw-tool-eraser-color');
        return {
            present: !!panel,
            visible: !!panel && panel.classList.contains('is-visible'),
            ariaHidden: panel ? panel.getAttribute('aria-hidden') : null,
            toolButtons,
            activeTool: (toolButtons.find((button) => button.active) || {}).tool || null,
            swatches,
            activeSwatch: (swatches.find((button) => button.active) || {}).color || null,
            customColor: String(document.getElementById('draw-tool-color')?.value || '').toLowerCase(),
            size: readRange('draw-tool-size', 'draw-tool-size-value'),
            smoothing: readRange('draw-tool-smoothing', 'draw-tool-smoothing-value'),
            opacity: readRange('draw-tool-opacity', 'draw-tool-opacity-value'),
            inkSectionHidden: inkSection ? inkSection.hidden : null,
            eraserSectionHidden: eraserSection ? eraserSection.hidden : null,
            status: document.getElementById('draw-tool-status')?.textContent?.trim() || '',
        };
    });
}

/** Choose pen or eraser through its panel button. */
async function pickDrawTool(page, tool) {
    await page.evaluate((wanted) => {
        document.querySelector(`[data-draw-direct-tool="${wanted}"]`)?.click();
    }, tool);
    await page.waitForTimeout(350);
}

/**
 * Drive a range input the way a user does.
 *
 * The panel listens for `input`, so the value is set and both events are
 * dispatched rather than the slider being dragged pixel by pixel.
 */
async function setRange(page, id, value) {
    await page.evaluate(([elementId, next]) => {
        const input = document.getElementById(elementId);
        if (!input) return;
        input.value = String(next);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, [id, value]);
    await page.waitForTimeout(300);
}

/** Set any combination of the stroke properties the panel exposes. */
async function setDrawStyle(page, { sizePx, smoothingPercent, opacityPercent, swatch, customHex } = {}) {
    if (sizePx != null) await setRange(page, 'draw-tool-size', sizePx);
    if (smoothingPercent != null) await setRange(page, 'draw-tool-smoothing', smoothingPercent);
    if (opacityPercent != null) await setRange(page, 'draw-tool-opacity', opacityPercent);
    if (swatch != null) {
        await page.evaluate((hex) => {
            document.querySelector(`[data-draw-color="${hex}"]`)?.click();
        }, swatch);
        await page.waitForTimeout(300);
    }
    if (customHex != null) {
        await page.evaluate((hex) => {
            const input = document.getElementById('draw-tool-color');
            if (!input) return;
            input.value = hex;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, customHex);
        await page.waitForTimeout(300);
    }
}

/** Every drawing box on the page, with the state the suite asserts against. */
function drawBoxes(page) {
    return page.evaluate((selector) => Array.from(document.querySelectorAll(selector)).map((box) => {
        const rect = box.getBoundingClientRect();
        const img = box.querySelector('img');
        return {
            annotationId: box.dataset.annotationId || '',
            annotationType: box.dataset.annotationType || '',
            directDraw: box.dataset.directDraw || '',
            directDrawTool: box.dataset.directDrawTool || '',
            locked: box.dataset.locked === '1',
            zIndex: Number(box.dataset.zIndex),
            selected: box.classList.contains('is-selected'),
            opacity: Number(box.style.opacity || '1'),
            width: rect.width,
            height: rect.height,
            left: rect.left,
            top: rect.top,
            hasImage: !!img,
            isSvgDataUrl: String(img?.getAttribute('src') || '').startsWith('data:image/svg+xml'),
            src: String(img?.getAttribute('src') || '').slice(0, 24),
        };
    }), DRAW_BOX_SELECTOR);
}

function drawBoxCount(page) {
    return page.evaluate((selector) => document.querySelectorAll(selector).length, DRAW_BOX_SELECTOR);
}

/** Whether a live preview layer is currently painting. */
function drawPreviewState(page) {
    return page.evaluate(() => {
        const layers = Array.from(document.querySelectorAll('canvas.enpv-draw-preview-layer'));
        return {
            count: layers.length,
            painting: layers.some((layer) => layer.width > 0 && layer.height > 0),
        };
    });
}

/**
 * Drag a freehand stroke on the page.
 *
 * `shape` is a function of t in [0, 1] returning an offset from the press
 * point, so a case can draw a straight line, an arc or a deliberately jittery
 * scribble without repeating the pointer plumbing.
 */
async function drawStroke(page, from, options = {}) {
    const pageNumber = options.pageNumber || 1;
    const steps = options.steps || 24;
    const shape = options.shape || ((t) => ({ dx: 240 * t, dy: 90 * Math.sin(t * Math.PI) }));
    const viewport = page.viewportSize();

    let start = await pointOnPage(page, pageNumber, from.fx, from.fy);
    const outside = (point) => point.x < 2 || point.y < 2
        || point.x > viewport.width - 2 || point.y > viewport.height - 2;

    const furthest = shape(1);
    if (outside({ x: start.x + furthest.dx, y: start.y + furthest.dy })) {
        await page.evaluate((dy) => {
            const container = document.getElementById('viewerContainer');
            if (container) container.scrollTop += dy;
        }, start.y - (viewport.height / 2));
        await page.waitForTimeout(400);
        start = await pointOnPage(page, pageNumber, from.fx, from.fy);
    }

    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    const visited = [{ x: start.x, y: start.y }];
    for (let step = 1; step <= steps; step++) {
        const offset = shape(step / steps);
        const point = { x: start.x + offset.dx, y: start.y + offset.dy };
        visited.push(point);
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(point.x, point.y);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(16);
    }

    // Snapshot mid-stroke state before releasing, so a caller can prove a live
    // preview existed and that nothing was committed yet.
    const preview = await drawPreviewState(page);
    const boxesDuringStroke = await drawBoxCount(page);

    await page.mouse.up();
    await page.waitForTimeout(600);

    return { start, visited, preview, boxesDuringStroke };
}

/** A single click with no movement at all. */
async function tapOnPage(page, from, pageNumber = 1) {
    const point = await pointOnPage(page, pageNumber, from.fx, from.fy);
    await page.mouse.move(point.x, point.y);
    await page.mouse.down();
    await page.waitForTimeout(120);
    await page.mouse.up();
    await page.waitForTimeout(500);
    return point;
}

/** The drawing annotations carried by the most recent save. */
function savedDrawAnnotations(saveRecorder) {
    const last = saveRecorder?.saves?.[saveRecorder.saves.length - 1];
    return (last?.annotations || []).filter((annotation) => !!annotation?.directDrawVector
        || String(annotation?.directDrawTool || '') !== '');
}

/** The first stroke of a drawing annotation, which is where the style rides. */
function primaryStroke(annotation) {
    const strokes = Array.isArray(annotation?.directDrawVector?.strokes)
        ? annotation.directDrawVector.strokes
        : [];
    return strokes[0] || null;
}

/** Click a drawing to select it. */
async function selectDrawing(page, index = 0) {
    const boxes = await drawBoxes(page);
    const box = boxes[index];
    if (!box) return false;
    await page.mouse.click(box.left + (box.width / 2), box.top + (box.height / 2));
    await page.waitForTimeout(450);
    const after = await drawBoxes(page);
    return !!after[index]?.selected;
}

/**
 * Select a drawing and wait until its menu is usable.
 *
 * Leaves draw mode first: while the tool is on, a press on the page starts a
 * new stroke rather than selecting what is under the pointer.
 */
async function ensureDrawingSelected(page, index = 0) {
    await setDrawMode(page, false);
    await setEditMode(page, true);
    for (let attempt = 0; attempt < 5; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        await selectDrawing(page, index);
        // eslint-disable-next-line no-await-in-loop
        const menu = await annMenuState(page);
        if (menu.visible && menu.top < 8) {
            // eslint-disable-next-line no-await-in-loop
            await page.evaluate((wanted) => {
                const container = document.getElementById('viewerContainer');
                if (container) container.scrollTop -= wanted;
            }, 120 - menu.top);
            // eslint-disable-next-line no-await-in-loop
            await page.waitForTimeout(500);
            // eslint-disable-next-line no-await-in-loop
            continue;
        }
        if (menu.visible) return true;
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(350);
    }
    return false;
}

/** The cursor the browser resolves for an element, as CSS actually applies it. */
function resolvedCursor(page, selector) {
    return page.evaluate((target) => {
        const el = document.querySelector(target);
        if (!el) return null;
        return window.getComputedStyle(el).cursor;
    }, selector);
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: blank project loads with the Draw tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const canvas = await page.evaluate(() => {
        const element = document.querySelector('#viewer .page canvas');
        return element ? { width: element.width, height: element.height } : null;
    });
    recorder.assert('page-rasterised', !!canvas && canvas.width > 0 && canvas.height > 0,
        'Page 1 rasterises', JSON.stringify(canvas));

    const controls = await drawToolControls(page);
    recorder.assert('draw-control-present', controls.floating.present && controls.floating.visible,
        'The floating toolbar offers the Draw tool', JSON.stringify(controls.floating));
    recorder.assert('draw-control-enabled', controls.floating.present && !controls.floating.disabled,
        'The Draw control is enabled on load');
    recorder.assert('draw-control-inactive', !controls.floating.active,
        'The Draw control starts inactive', `aria-pressed=${controls.floating.pressed}`);

    // Unlike Shapes, Draw has exactly one toggle. Recorded so that adding a
    // second one later is a deliberate change rather than a silent drift.
    recorder.assert('single-toggle', !controls.topBar.present,
        'Draw is reached from the floating toolbar only, with no top-bar twin');

    recorder.assert('draw-mode-off', !(await drawModeOn(page)),
        'The document does not start in draw mode');

    const panel = await drawPanelState(page);
    recorder.assert('panel-present', panel.present, 'The Draw Options panel is in the DOM');
    recorder.assert('panel-closed', !panel.visible && panel.ariaHidden === 'true',
        'The Draw Options panel starts closed', `aria-hidden=${panel.ariaHidden}`);

    recorder.equals('no-drawings', await drawBoxCount(page), 0, 'No drawing exists yet');

    const realErrors = consoleErrors.filter((text) => !/favicon|fonts\.(googleapis|gstatic)/i.test(text));
    recorder.assert('console-clean', realErrors.length === 0,
        'Loading the editor logs no console errors', realErrors.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — Draw is exclusive with every other tool mode. */
async function testToolExclusivity(page, { recorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    recorder.assert('draw-on', await drawModeOn(page), 'Clicking the control enters draw mode');

    let panel = await drawPanelState(page);
    recorder.assert('panel-opens', panel.visible && panel.ariaHidden === 'false',
        'Draw mode opens the Draw Options panel');

    let controls = await drawToolControls(page);
    recorder.assert('control-active', controls.floating.active && controls.floating.pressed === 'true',
        'The control reports itself active', JSON.stringify(controls.floating));

    // Each of the other tools must take over cleanly.
    const rivals = [
        { id: 'ftb-add-shape', name: 'Shapes', probe: shapeModeOn },
        { id: 'ftb-add-text', name: 'Add Text', probe: addTextModeOn },
        { id: 'ftb-highlight', name: 'Highlight', probe: highlightModeOn },
    ];

    for (const rival of rivals) {
        // eslint-disable-next-line no-await-in-loop
        const exists = await page.evaluate((id) => !!document.getElementById(id), rival.id);
        if (!exists) {
            recorder.skip(`rival-${rival.id}`, `${rival.name} is not present in this build`);
            continue;
        }
        // eslint-disable-next-line no-await-in-loop
        await setDrawMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await page.evaluate((id) => document.getElementById(id)?.click(), rival.id);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(450);
        // eslint-disable-next-line no-await-in-loop
        const drawStillOn = await drawModeOn(page);
        // eslint-disable-next-line no-await-in-loop
        const rivalOn = await rival.probe(page);
        recorder.assert(`${rival.id}-cancels-draw`, rivalOn && !drawStillOn,
            `${rival.name} cancels draw mode`, `${rival.name}=${rivalOn} draw=${drawStillOn}`);

        // eslint-disable-next-line no-await-in-loop
        await setDrawMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        const rivalAfter = await rival.probe(page);
        recorder.assert(`draw-cancels-${rival.id}`, !rivalAfter,
            `Draw cancels ${rival.name}`, `${rival.name}=${rivalAfter}`);
    }

    // Edit mode is the fourth rival and closes the panel outright.
    await setDrawMode(page, true);
    await setEditMode(page, true);
    recorder.assert('edit-cancels-draw', !(await drawModeOn(page)) && await editModeOn(page),
        'Edit mode cancels draw mode');
    panel = await drawPanelState(page);
    recorder.assert('panel-closes-on-edit', !panel.visible,
        'The Draw Options panel closes with the mode');
    await setEditMode(page, false);

    // And the tool turns itself off again.
    await setDrawMode(page, true);
    await setDrawMode(page, false);
    recorder.assert('draw-toggles-off', !(await drawModeOn(page)),
        'The control toggles draw mode back off');
    controls = await drawToolControls(page);
    recorder.assert('control-inactive-again', !controls.floating.active && controls.floating.pressed === 'false',
        'The control reports itself inactive again');

    artifacts.push(await capture(page, '02-tool-exclusivity', 'after-toggles'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — The pen commits a drawing annotation. */
async function testPenDrawsStroke(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const stroke = await drawStroke(page, { fx: 0.2, fy: 0.25 });

    const boxes = await drawBoxes(page);
    recorder.equals('one-drawing', boxes.length, 1, 'One drag commits exactly one drawing');

    const box = boxes[0] || {};
    recorder.equals('marked-direct-draw', box.directDraw, '1',
        'The box carries the direct-draw marker');
    recorder.equals('tool-is-pen', box.directDrawTool, 'pen',
        'The drawing records the pen as its tool');
    recorder.equals('annotation-type', box.annotationType, 'image',
        'A drawing is an image annotation, not a type of its own');
    recorder.assert('has-image', box.hasImage, 'The drawing renders an image element');
    recorder.assert('box-has-area', box.width > 8 && box.height > 8,
        'The committed drawing has real area', `${Math.round(box.width)}x${Math.round(box.height)}`);

    // The box should bound the path that was actually dragged, not the page.
    const dragged = stroke.visited.reduce((acc, point) => ({
        minX: Math.min(acc.minX, point.x),
        maxX: Math.max(acc.maxX, point.x),
        minY: Math.min(acc.minY, point.y),
        maxY: Math.max(acc.maxY, point.y),
    }), { minX: Infinity, maxX: -Infinity, minY: Infinity, maxY: -Infinity });
    const draggedWidth = dragged.maxX - dragged.minX;
    const draggedHeight = dragged.maxY - dragged.minY;
    // Padding is max(brush, 8) each side, doubled again by selection chrome.
    recorder.assert('bounds-track-path',
        box.width >= draggedWidth - 4 && box.width <= draggedWidth + 120
        && box.height >= draggedHeight - 4 && box.height <= draggedHeight + 120,
        'The drawing is cropped to the dragged path',
        `path ${Math.round(draggedWidth)}x${Math.round(draggedHeight)}, box ${Math.round(box.width)}x${Math.round(box.height)}`);

    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    recorder.equals('saved-one', saved.length, 1, 'The drawing is included in the save payload');

    const annotation = saved[0] || {};
    recorder.equals('saved-tool', String(annotation.directDrawTool || ''), 'pen',
        'The saved annotation records the pen');
    const vector = annotation.directDrawVector || null;
    recorder.assert('saved-vector', !!vector && Array.isArray(vector.strokes) && vector.strokes.length > 0,
        'The saved annotation keeps the stroke as vectors, not only a raster',
        `strokes=${vector?.strokes?.length}`);

    const primary = primaryStroke(annotation);
    recorder.assert('vector-has-points', Array.isArray(primary?.points) && primary.points.length > 2,
        'The stored stroke keeps the points the pointer visited', `points=${primary?.points?.length}`);

    artifacts.push(await capture(page, '03-pen-draws-a-stroke', 'after-stroke'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — The eraser is a draw tool, with its own panel state and stacking. */
async function testEraserTool(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await drawStroke(page, { fx: 0.2, fy: 0.3 }, { shape: (t) => ({ dx: 260 * t, dy: 0 }) });

    await pickDrawTool(page, 'eraser');
    const panel = await drawPanelState(page);
    recorder.equals('eraser-active', panel.activeTool, 'eraser', 'The eraser button becomes active');
    recorder.assert('smoothing-disabled', panel.smoothing.disabled,
        'Smoothing greys out for the eraser, which has no stroke to smooth');
    recorder.assert('opacity-disabled', panel.opacity.disabled,
        'Opacity greys out for the eraser');
    recorder.assert('ink-hidden', panel.inkSectionHidden === true,
        'The ink swatches are hidden for the eraser');
    recorder.assert('eraser-colour-shown', panel.eraserSectionHidden === false,
        'The fixed white eraser colour is shown instead');
    recorder.assert('status-eraser', /eraser/i.test(panel.status),
        'The panel status describes the eraser', panel.status);

    await drawStroke(page, { fx: 0.22, fy: 0.32 }, { shape: (t) => ({ dx: 200 * t, dy: 0 }) });

    const boxes = await drawBoxes(page);
    recorder.equals('two-drawings', boxes.length, 2, 'The eraser stroke commits as its own drawing');

    const eraser = boxes.find((box) => box.directDrawTool === 'eraser');
    const pen = boxes.find((box) => box.directDrawTool === 'pen');
    recorder.assert('eraser-recorded', !!eraser, 'One drawing records the eraser as its tool');
    recorder.assert('pen-recorded', !!pen, 'The earlier pen drawing is untouched');
    recorder.assert('eraser-above-pen', !!eraser && !!pen && eraser.zIndex > pen.zIndex,
        'The eraser stacks above the ink it is meant to cover',
        `eraser z=${eraser?.zIndex}, pen z=${pen?.zIndex}`);

    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    const savedEraser = saved.find((annotation) => String(annotation.directDrawTool) === 'eraser');
    recorder.assert('eraser-saved', !!savedEraser, 'The eraser stroke is saved as a drawing');
    recorder.assert('eraser-is-white', !savedEraser
        || String(savedEraser.drawStrokeColor || '').toLowerCase() === '#ffffff',
        'The eraser stroke is stored white', savedEraser?.drawStrokeColor);

    // Switching back must restore the pen's own controls.
    await pickDrawTool(page, 'pen');
    const back = await drawPanelState(page);
    recorder.equals('pen-active-again', back.activeTool, 'pen', 'The pen becomes active again');
    recorder.assert('smoothing-enabled-again', !back.smoothing.disabled,
        'Smoothing is usable again for the pen');
    recorder.assert('ink-shown-again', back.inkSectionHidden === false,
        'The ink swatches come back for the pen');

    artifacts.push(await capture(page, '04-eraser-tool', 'after-eraser'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — A live preview paints during the drag and nothing commits until release. */
async function testLivePreview(page, { recorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const stroke = await drawStroke(page, { fx: 0.2, fy: 0.3 }, { steps: 30 });

    recorder.equals('nothing-committed-mid-stroke', stroke.boxesDuringStroke, 0,
        'No annotation exists while the pointer is still down');
    recorder.assert('preview-painting', stroke.preview.count > 0 && stroke.preview.painting,
        'A preview layer paints during the drag', JSON.stringify(stroke.preview));

    recorder.equals('committed-on-release', await drawBoxCount(page), 1,
        'Releasing commits exactly one drawing');

    const after = await drawPreviewState(page);
    recorder.equals('preview-torn-down', after.count, 0,
        'The preview layer is removed once the stroke is committed');

    artifacts.push(await capture(page, '05-live-preview', 'after-release'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — A press with no travel, and drags below the movement threshold. */
async function testDegenerateStroke(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await tapOnPage(page, { fx: 0.35, fy: 0.35 });
    const afterTap = await drawBoxCount(page);

    // A press with no travel leaves a dot, the way a pen touching paper does.
    // That is a defensible choice rather than a bug, so it is recorded rather
    // than asserted against a number the product has never committed to. What
    // the suite does insist on is that whatever gets committed is WELL FORMED:
    // a zero-area or vectorless annotation would be unselectable and would
    // survive every save, which is the failure mode worth catching.
    recorder.ok('tap-behaviour', 'A press with no travel is recorded',
        afterTap === 0 ? 'no annotation committed' : `${afterTap} annotation committed (a dot)`);

    if (afterTap > 0) {
        const dot = (await drawBoxes(page))[0] || {};
        recorder.assert('dot-has-area', dot.width > 0 && dot.height > 0,
            'A committed dot has real area rather than collapsing to nothing',
            `${Math.round(dot.width)}x${Math.round(dot.height)}`);
        recorder.equals('dot-is-a-drawing', dot.directDraw, '1',
            'A committed dot is a proper drawing annotation');

        await saveDocument(page, saveRecorder);
        const saved = savedDrawAnnotations(saveRecorder);
        const stroke = primaryStroke(saved[saved.length - 1]);
        recorder.assert('dot-has-points', Array.isArray(stroke?.points) && stroke.points.length > 0,
            'A committed dot still stores the point the pointer visited',
            `points=${stroke?.points?.length}`);
    } else {
        recorder.skip('dot-has-area', 'Nothing was committed, so there is no dot to inspect');
        recorder.skip('dot-is-a-drawing', 'Nothing was committed');
        recorder.skip('dot-has-points', 'Nothing was committed');
    }

    // A drag under the 0.5px move threshold adds no further points, so it can
    // only ever produce the same single-point mark a tap does.
    const before = await drawBoxCount(page);
    await drawStroke(page, { fx: 0.5, fy: 0.5 }, { steps: 2, shape: (t) => ({ dx: 0.4 * t, dy: 0.4 * t }) });
    const after = await drawBoxCount(page);
    recorder.assert('sub-pixel-matches-tap', after - before === afterTap,
        'A drag below the movement threshold behaves exactly like a press with no travel',
        `tap committed ${afterTap}, sub-pixel drag committed ${after - before}`);

    const stillUsable = await drawPanelState(page);
    recorder.assert('tool-survives', stillUsable.visible && await drawModeOn(page),
        'The tool is still usable after the degenerate strokes');

    artifacts.push(await capture(page, '06-degenerate-stroke', 'after-taps'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Brush size across its whole range. */
async function testBrushSizeRange(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const panel = await drawPanelState(page);
    recorder.equals('size-min', panel.size.min, DRAW_RANGES.size.min, 'The size slider starts at its documented minimum');
    recorder.equals('size-max', panel.size.max, DRAW_RANGES.size.max, 'The size slider ends at its documented maximum');

    const widths = [];
    for (const size of [DRAW_RANGES.size.min, 18, DRAW_RANGES.size.max]) {
        // eslint-disable-next-line no-await-in-loop
        await setDrawStyle(page, { sizePx: size });
        // eslint-disable-next-line no-await-in-loop
        const state = await drawPanelState(page);
        recorder.equals(`size-readout-${size}`, state.size.readout, `${size}px`,
            `The readout follows the slider at ${size}`);

        // eslint-disable-next-line no-await-in-loop
        await drawStroke(page, { fx: 0.2, fy: 0.2 + (widths.length * 0.18) }, {
            shape: (t) => ({ dx: 200 * t, dy: 0 }),
        });
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedDrawAnnotations(saveRecorder);
        const stroke = primaryStroke(saved[saved.length - 1]);
        widths.push({ size, brushSize: Number(stroke?.brushSize) });
        recorder.near(`brush-size-${size}`, Number(stroke?.brushSize), size, 0.51,
            `A stroke drawn at ${size} stores that brush size`);
    }

    recorder.assert('sizes-increase',
        widths.length === 3 && widths[0].brushSize < widths[1].brushSize && widths[1].brushSize < widths[2].brushSize,
        'Bigger slider values produce bigger stored strokes', JSON.stringify(widths));

    artifacts.push(await capture(page, '07-brush-size-range', 'three-widths'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — Ink colour, from both the swatches and the custom picker. */
async function testInkColour(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const panel = await drawPanelState(page);
    recorder.equals('swatch-count', panel.swatches.length, INK_SWATCHES.length,
        'The panel offers the documented ink swatches');
    recorder.assert('swatch-colours',
        INK_SWATCHES.every((hex) => panel.swatches.some((swatch) => swatch.color === hex)),
        'The swatches are the documented colours',
        panel.swatches.map((swatch) => swatch.color).join(','));
    recorder.equals('default-swatch-active', panel.activeSwatch, DRAW_DEFAULTS.color,
        'Black starts as the active ink');

    let drawn = 0;
    for (const hex of ['#dc2626', '#2563eb']) {
        // eslint-disable-next-line no-await-in-loop
        await setDrawStyle(page, { swatch: hex });
        // eslint-disable-next-line no-await-in-loop
        const state = await drawPanelState(page);
        recorder.equals(`swatch-active-${hex}`, state.activeSwatch, hex, `Picking ${hex} marks that swatch active`);
        recorder.equals(`custom-follows-${hex}`, state.customColor, hex,
            `The custom colour input follows the swatch at ${hex}`);

        // eslint-disable-next-line no-await-in-loop
        await drawStroke(page, { fx: 0.2, fy: 0.2 + (drawn * 0.2) }, { shape: (t) => ({ dx: 200 * t, dy: 0 }) });
        drawn += 1;
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedDrawAnnotations(saveRecorder);
        const stroke = primaryStroke(saved[saved.length - 1]);
        recorder.equals(`stroke-colour-${hex}`, String(stroke?.color || '').toLowerCase(), hex,
            `A stroke drawn on ${hex} stores that colour`);
    }

    // The custom picker must drive the same state as the swatches.
    await setDrawStyle(page, { customHex: '#00b3a4' });
    const custom = await drawPanelState(page);
    recorder.equals('custom-colour-applied', custom.customColor, '#00b3a4',
        'The custom colour input takes an arbitrary colour');
    recorder.assert('no-swatch-for-custom', custom.activeSwatch === null,
        'No swatch claims to be active for a colour that is not one of them',
        `activeSwatch=${custom.activeSwatch}`);

    await drawStroke(page, { fx: 0.2, fy: 0.62 }, { shape: (t) => ({ dx: 200 * t, dy: 0 }) });
    await saveDocument(page, saveRecorder);
    const savedCustom = savedDrawAnnotations(saveRecorder);
    recorder.equals('custom-stroke-colour',
        String(primaryStroke(savedCustom[savedCustom.length - 1])?.color || '').toLowerCase(), '#00b3a4',
        'A stroke drawn on the custom colour stores it');

    artifacts.push(await capture(page, '08-ink-colour', 'coloured-strokes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 09 — Opacity applies to the stroke. */
async function testOpacity(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const panel = await drawPanelState(page);
    recorder.equals('opacity-min', panel.opacity.min, DRAW_RANGES.opacity.min,
        'Opacity cannot be dragged to fully transparent');
    recorder.equals('opacity-max', panel.opacity.max, DRAW_RANGES.opacity.max, 'Opacity reaches 100%');

    for (const percent of [DRAW_RANGES.opacity.min, 45]) {
        // eslint-disable-next-line no-await-in-loop
        await setDrawStyle(page, { opacityPercent: percent });
        // eslint-disable-next-line no-await-in-loop
        const state = await drawPanelState(page);
        recorder.equals(`opacity-readout-${percent}`, state.opacity.readout, `${percent}%`,
            `The readout follows the slider at ${percent}%`);

        // eslint-disable-next-line no-await-in-loop
        await drawStroke(page, { fx: 0.2, fy: percent === 45 ? 0.45 : 0.2 }, {
            shape: (t) => ({ dx: 200 * t, dy: 0 }),
        });
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedDrawAnnotations(saveRecorder);
        const stroke = primaryStroke(saved[saved.length - 1]);
        recorder.near(`stroke-opacity-${percent}`, Number(stroke?.opacity), percent / 100, 0.02,
            `A stroke drawn at ${percent}% stores that opacity`);
    }

    artifacts.push(await capture(page, '09-opacity', 'two-opacities'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — NK_16: smoothing is a stroke property with a real range. */
async function testSmoothingSlider(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const panel = await drawPanelState(page);
    recorder.assert('smoothing-present', panel.smoothing.present,
        'The panel offers a Smoothing slider');
    recorder.equals('smoothing-min', panel.smoothing.min, DRAW_RANGES.smoothing.min, 'Smoothing starts at 0');
    recorder.equals('smoothing-max', panel.smoothing.max, DRAW_RANGES.smoothing.max, 'Smoothing ends at 100');
    recorder.equals('smoothing-default', panel.smoothing.value, DRAW_DEFAULTS.smoothingPercent,
        'Smoothing defaults to the documented amount');

    // NK_16 replaced a tool-mode button. Nothing should offer it as a tool.
    recorder.assert('no-smooth-curve-button',
        panel.toolButtons.every((button) => button.tool !== 'smooth-curve'),
        'Smooth curve is no longer a tool mode beside Pen and Eraser',
        panel.toolButtons.map((button) => button.tool).join(','));
    recorder.equals('two-tools', panel.toolButtons.length, DRAW_TOOLS.length,
        'The panel offers exactly Pen and Eraser');

    const stored = [];
    for (const percent of [0, 58, 100]) {
        // eslint-disable-next-line no-await-in-loop
        await setDrawStyle(page, { smoothingPercent: percent });
        // eslint-disable-next-line no-await-in-loop
        const state = await drawPanelState(page);
        recorder.equals(`smoothing-readout-${percent}`, state.smoothing.readout, `${percent}%`,
            `The readout follows the slider at ${percent}%`);

        // A deliberately jagged path, so the amount has something to act on.
        // eslint-disable-next-line no-await-in-loop
        await drawStroke(page, { fx: 0.2, fy: 0.2 + (stored.length * 0.2) }, {
            steps: 28,
            shape: (t) => ({ dx: 220 * t, dy: (t * 28) % 2 < 1 ? -18 : 18 }),
        });
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedDrawAnnotations(saveRecorder);
        const stroke = primaryStroke(saved[saved.length - 1]);
        stored.push({ percent, smoothing: Number(stroke?.smoothing) });
        recorder.near(`stroke-smoothing-${percent}`, Number(stroke?.smoothing), percent / 100, 0.02,
            `A stroke drawn at ${percent}% stores that smoothing amount`);
    }

    recorder.assert('smoothing-rides-the-stroke',
        stored.every((entry) => Number.isFinite(entry.smoothing)),
        'Every stroke carries its own smoothing amount rather than a global setting',
        JSON.stringify(stored));

    artifacts.push(await capture(page, '10-smoothing-slider', 'three-amounts'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — NK_16: smoothing is re-editable on an existing drawing in pdf.js. */
async function testSmoothingReeditable(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await setDrawStyle(page, { smoothingPercent: 20, sizePx: 12, swatch: '#2563eb' });
    await drawStroke(page, { fx: 0.2, fy: 0.28 }, {
        steps: 26,
        shape: (t) => ({ dx: 240 * t, dy: (t * 26) % 2 < 1 ? -16 : 16 }),
    });
    recorder.equals('drawn', await drawBoxCount(page), 1, 'A drawing exists to re-edit');

    // Selecting it must reflect its own amount back into the panel.
    await setDrawMode(page, false);
    const selected = await selectDrawing(page, 0);
    recorder.assert('selected', selected, 'The drawing can be selected');

    const reflected = await drawPanelState(page);
    recorder.assert('panel-open-for-selection', reflected.visible,
        'Selecting a drawing opens the Draw Options panel');
    recorder.equals('smoothing-reflected', reflected.smoothing.value, 20,
        'The panel shows the selected drawing\'s own smoothing amount');
    recorder.equals('size-reflected', reflected.size.value, 12,
        'The panel shows the selected drawing\'s brush size');
    recorder.equals('colour-reflected', reflected.activeSwatch, '#2563eb',
        'The panel shows the selected drawing\'s colour');

    // Dragging the slider must restyle the selection in place.
    const beforeSrc = (await drawBoxes(page))[0]?.src;
    await setDrawStyle(page, { smoothingPercent: 95 });
    await page.waitForTimeout(500);

    const after = await drawBoxes(page);
    recorder.equals('still-one-drawing', after.length, 1,
        'Restyling edits the drawing rather than adding another');
    recorder.assert('restyled-to-svg', after[0]?.isSvgDataUrl,
        'The restyled drawing is re-emitted as an SVG vector, not a stale raster',
        `${beforeSrc} -> ${after[0]?.src}`);

    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    recorder.near('saved-new-smoothing', Number(primaryStroke(saved[0])?.smoothing), 0.95, 0.02,
        'The new smoothing amount is what gets saved');

    artifacts.push(await capture(page, '11-smoothing-reeditable', 'after-restyle'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — A fresh session starts from the documented defaults. */
async function testDefaults(page, { recorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    const panel = await drawPanelState(page);

    recorder.equals('default-tool', panel.activeTool, DRAW_DEFAULTS.tool, 'The pen is the default tool');
    recorder.equals('default-colour', panel.activeSwatch, DRAW_DEFAULTS.color, 'Black is the default ink');
    recorder.equals('default-size', panel.size.value, DRAW_DEFAULTS.size, 'The default brush size');
    recorder.equals('default-smoothing', panel.smoothing.value, DRAW_DEFAULTS.smoothingPercent,
        'The default smoothing amount');
    recorder.equals('default-opacity', panel.opacity.value, DRAW_DEFAULTS.opacityPercent,
        'The default opacity');
    recorder.assert('status-pen', /pen mode/i.test(panel.status),
        'The panel opens describing pen mode', panel.status);

    artifacts.push(await capture(page, '12-defaults', 'panel-defaults'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — Style choices persist from one stroke to the next. */
async function testStylePersistsBetweenStrokes(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await setDrawStyle(page, { sizePx: 24, smoothingPercent: 12, opacityPercent: 60, swatch: '#16a34a' });

    await drawStroke(page, { fx: 0.2, fy: 0.25 }, { shape: (t) => ({ dx: 200 * t, dy: 0 }) });
    const afterFirst = await drawPanelState(page);
    recorder.equals('tool-stays-on', await drawModeOn(page), true,
        'Draw mode survives committing a stroke, unlike Add Text which drops after one placement');
    recorder.equals('size-persists', afterFirst.size.value, 24, 'The brush size survives the first stroke');
    recorder.equals('smoothing-persists', afterFirst.smoothing.value, 12, 'The smoothing amount survives');
    recorder.equals('opacity-persists', afterFirst.opacity.value, 60, 'The opacity survives');
    recorder.equals('colour-persists', afterFirst.activeSwatch, '#16a34a', 'The ink colour survives');

    await drawStroke(page, { fx: 0.2, fy: 0.5 }, { shape: (t) => ({ dx: 200 * t, dy: 0 }) });
    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    recorder.equals('two-saved', saved.length, 2, 'Both strokes are saved');

    const strokes = saved.map((annotation) => primaryStroke(annotation)).filter(Boolean);
    recorder.assert('both-same-style',
        strokes.length === 2
        && strokes.every((stroke) => String(stroke.color).toLowerCase() === '#16a34a')
        && strokes.every((stroke) => Math.abs(Number(stroke.brushSize) - 24) < 0.51),
        'The second stroke is drawn with the same style as the first',
        JSON.stringify(strokes.map((stroke) => ({ color: stroke.color, brushSize: stroke.brushSize }))));

    artifacts.push(await capture(page, '13-style-persists', 'two-strokes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — Select, deselect and the drawing's hover menu. */
async function testSelectAndMenu(page, { recorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await drawStroke(page, { fx: 0.2, fy: 0.3 }, { shape: (t) => ({ dx: 220 * t, dy: 40 * t }) });
    await setDrawMode(page, false);

    const usable = await ensureDrawingSelected(page, 0);
    recorder.assert('menu-usable', usable, 'A drawing can be selected and its menu reached');

    const menu = await annMenuState(page);
    recorder.assert('menu-visible', menu.visible, 'The annotation menu comes up for a drawing');
    const actions = (menu.actions || []).map((action) => action.action);

    // The menu a drawing gets is the shared image-annotation menu. It offers
    // copy rather than the duplicate entry the Shapes tool has, so the suite
    // asserts what a drawing genuinely provides rather than assuming parity.
    for (const action of ['delete', 'lock', 'copy', 'front', 'back']) {
        recorder.assert(`menu-has-${action}`, actions.includes(action),
            `The menu offers ${action}`, actions.join(','));
    }
    recorder.assert('no-duplicate-entry', !actions.includes('duplicate'),
        'A drawing offers copy instead of the Shapes tool\'s duplicate', actions.join(','));

    // Recorded rather than asserted: cut is offered here even though it was
    // deliberately withheld from lines under NK_14. Whether an area cut is
    // meaningful for a freehand stroke is a product question, not a
    // regression, so the suite documents it for the ticket to decide.
    recorder.ok('menu-actions-recorded',
        'The full action list a drawing offers is recorded, including cut',
        actions.join(','));

    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const afterEscape = await drawBoxes(page);
    recorder.assert('escape-deselects', !afterEscape[0]?.selected,
        'Escape deselects the drawing');

    artifacts.push(await capture(page, '14-select-and-menu', 'menu-open'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — NK_18: a drawing rotates. */
async function testRotateDrawing(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await drawStroke(page, { fx: 0.25, fy: 0.3 }, { shape: (t) => ({ dx: 200 * t, dy: 60 * t }) });
    await setDrawMode(page, false);
    await ensureDrawingSelected(page, 0);

    const boxes = await drawBoxes(page);
    recorder.assert('drawing-selected', !!boxes[0]?.selected, 'The drawing is selected');

    // NK_18 was that annotationBoxCanRotate() rejected any box whose
    // annotationType is set and is not shape or signature. A drawing is
    // "image", so it fell into that branch and never got a handle.
    const handle = await rotateHandleAt(page);
    recorder.assert('rotate-handle-offered', !!handle?.visible,
        'NK_18: a selected drawing offers a rotate handle', JSON.stringify(handle));

    const before = await page.evaluate((selector) => Number(
        document.querySelector(`${selector}.is-selected`)?.dataset?.rotation || '0',
    ), DRAW_BOX_SELECTOR);
    recorder.near('starts-upright', before, 0, 0.51, 'A new drawing starts upright');

    const turned = await dragRotateHandle(page, 35);
    recorder.assert('rotate-drag-works', turned, 'The rotate handle can be dragged');

    const after = await page.evaluate((selector) => {
        const box = document.querySelector(`${selector}.is-selected`) || document.querySelector(selector);
        const content = box?.querySelector('.enpv-image-img, .enpv-page-rotated-content-frame');
        return {
            rotation: Number(box?.dataset?.rotation || '0'),
            isRotated: !!box?.classList?.contains('is-rotated'),
            transform: String(content?.style?.transform || ''),
        };
    }, DRAW_BOX_SELECTOR);

    recorder.assert('rotation-recorded', Math.abs(after.rotation) > 5,
        'Dragging the handle records an angle on the box', `rotation=${after.rotation}`);
    recorder.assert('marked-rotated', after.isRotated,
        'The box is marked rotated so the CSS chrome follows');
    recorder.assert('content-transformed', after.transform.length > 0 && after.transform !== 'none',
        'The drawing content is actually drawn at the angle, not just recorded',
        after.transform.slice(0, 80));

    // The angle has to survive the round trip, or the rotation is cosmetic.
    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    recorder.assert('rotation-saved', Math.abs(Number(saved[0]?.rotation) || 0) > 5,
        'The angle is written to the save payload', `rotation=${saved[0]?.rotation}`);

    await saveAndReload(page, saveRecorder);
    const reloaded = await page.evaluate((selector) => {
        const box = document.querySelector(selector);
        const content = box?.querySelector('.enpv-image-img, .enpv-page-rotated-content-frame');
        return {
            rotation: Number(box?.dataset?.rotation || '0'),
            transform: String(content?.style?.transform || ''),
        };
    }, DRAW_BOX_SELECTOR);
    recorder.assert('rotation-survives-reload', Math.abs(reloaded.rotation) > 5,
        'A reloaded drawing comes back at its angle rather than snapping upright',
        `rotation=${reloaded.rotation}`);
    recorder.assert('reloaded-content-transformed',
        reloaded.transform.length > 0 && reloaded.transform !== 'none',
        'The reloaded drawing is redrawn rotated', reloaded.transform.slice(0, 80));

    artifacts.push(await capture(page, '15-rotate-drawing', 'rotated'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 16 — NK_19: whether draw mode can force a move cursor over the resize handles.
 *
 * The ticket describes a selected drawing whose resize handles show `move`
 * instead of a resize cursor, caused by
 *
 *     body.enpv-draw-on .enpv-direct-draw-box.is-selected * { cursor: move }
 *
 * The wildcard descendant matches the handles as well as the body. This case
 * establishes whether that state is reachable at all: setDrawMode() calls
 * deselectAnnBox() on the way in, so draw-mode-on and a selection may never
 * coexist. If they cannot, the rule is latent rather than live — which is
 * worth knowing before anyone "fixes" a cursor no user can reach.
 */
async function testDrawCursorOverHandles(page, { recorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await drawStroke(page, { fx: 0.2, fy: 0.3 }, { shape: (t) => ({ dx: 220 * t, dy: 60 * t }) });

    // First: a selection with the tool OFF, which is the state a user is
    // actually in when they resize a drawing.
    await setDrawMode(page, false);
    const selected = await selectDrawing(page, 0);
    recorder.assert('selected-with-tool-off', selected,
        'A drawing can be selected once the tool is off');

    const handles = await page.evaluate((selector) => Array.from(
        document.querySelectorAll(`${selector}.is-selected .enpv-resize-handle`),
    ).map((handle) => ({
        classes: handle.className,
        cursor: window.getComputedStyle(handle).cursor,
    })), DRAW_BOX_SELECTOR);

    recorder.assert('handles-present', handles.length > 0,
        'A selected drawing shows resize handles', `${handles.length} handles`);
    recorder.assert('handles-resize-cursor',
        handles.length > 0 && handles.every((handle) => /resize$/.test(handle.cursor)),
        'With the tool off, every handle shows its own resize cursor',
        handles.map((handle) => handle.cursor).join(','));

    // Second: does entering draw mode preserve the selection? If it does, the
    // NK_19 state is live and the handles must be re-checked in it.
    await setDrawMode(page, true);
    await page.waitForTimeout(400);
    const stillSelected = await page.evaluate(
        (selector) => !!document.querySelector(`${selector}.is-selected`),
        DRAW_BOX_SELECTOR,
    );

    recorder.assert('draw-mode-clears-selection', !stillSelected,
        'Entering draw mode deselects, so a selected drawing and draw mode never coexist',
        `still selected = ${stillSelected}`);

    if (stillSelected) {
        const liveHandles = await page.evaluate((selector) => Array.from(
            document.querySelectorAll(`${selector}.is-selected .enpv-resize-handle`),
        ).map((handle) => window.getComputedStyle(handle).cursor), DRAW_BOX_SELECTOR);
        recorder.assert('nk19-live', liveHandles.every((cursor) => /resize$/.test(cursor)),
            'NK_19: in draw mode the handles keep their resize cursors',
            liveHandles.join(','));
    } else {
        recorder.skip('nk19-live',
            'NK_19 is not reachable through the UI: draw mode deselects on entry');
    }

    // The rule itself, asserted directly. It is currently unreachable, but it
    // is one deselect-on-enter change away from becoming the reported bug, so
    // its presence is recorded rather than left implicit.
    const rule = await page.evaluate(() => {
        for (const sheet of Array.from(document.styleSheets)) {
            let rules = [];
            try { rules = Array.from(sheet.cssRules || []); } catch (_) { continue; }
            for (const item of rules) {
                if (item.selectorText && item.selectorText.includes('enpv-direct-draw-box.is-selected')
                    && item.selectorText.includes('*')) {
                    return { selector: item.selectorText, cursor: item.style?.cursor || '' };
                }
            }
        }
        return null;
    });
    recorder.ok('wildcard-rule-recorded',
        'The wildcard cursor rule NK_19 blames is recorded, reachable or not',
        rule ? `${rule.selector} { cursor: ${rule.cursor} }` : 'rule not found in any stylesheet');

    artifacts.push(await capture(page, '16-draw-cursor-over-handles', 'handles'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 17 — Move and resize a drawing, including the edge handles.
 *
 * Drawings used to be built with corner handles only, like a raster image, so
 * a stroke could be scaled proportionally but never stretched along one axis.
 * They are stored as vectors and refit to any box, so they now get the full
 * eight the Shapes tool has.
 */
async function testMoveAndResize(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setDrawMode(page, true);
    await drawStroke(page, { fx: 0.25, fy: 0.3 }, { shape: (t) => ({ dx: 200 * t, dy: 70 * t }) });
    await setDrawMode(page, false);
    await ensureDrawingSelected(page, 0);

    const edges = await page.evaluate((selector) => Array.from(
        document.querySelectorAll(`${selector}.is-selected .enpv-resize-handle`),
    ).map((handle) => handle.dataset.edge).sort(), DRAW_BOX_SELECTOR);

    recorder.assert('all-eight-handles',
        ['b', 'l', 'ne', 'nw', 'r', 'se', 'sw', 't'].every((edge) => edges.includes(edge)),
        'A drawing offers edge handles as well as corners, so it can be stretched on one axis',
        edges.join(','));

    const dragHandle = async (edge, dx, dy) => {
        const point = await page.evaluate(([selector, wanted]) => {
            const handle = document.querySelector(`${selector}.is-selected .enpv-resize-handle[data-edge="${wanted}"]`);
            if (!handle) return null;
            const rect = handle.getBoundingClientRect();
            return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
        }, [DRAW_BOX_SELECTOR, edge]);
        if (!point) return false;
        await page.mouse.move(point.x, point.y);
        await page.mouse.down();
        for (let step = 1; step <= 8; step++) {
            // eslint-disable-next-line no-await-in-loop
            await page.mouse.move(point.x + ((dx * step) / 8), point.y + ((dy * step) / 8));
            // eslint-disable-next-line no-await-in-loop
            await page.waitForTimeout(30);
        }
        await page.mouse.up();
        await page.waitForTimeout(600);
        return true;
    };

    // Right edge: width only.
    let start = (await drawBoxes(page))[0];
    recorder.assert('right-edge-dragged', await dragHandle('r', 70, 0), 'The right edge handle can be dragged');
    let now = (await drawBoxes(page))[0];
    recorder.assert('right-edge-widens', now.width > start.width + 20,
        'Dragging the right edge widens the drawing',
        `${Math.round(start.width)} -> ${Math.round(now.width)}`);
    recorder.near('right-edge-keeps-height', now.height, start.height, 3,
        'Dragging the right edge leaves the height alone');

    // Bottom edge: height only.
    start = now;
    recorder.assert('bottom-edge-dragged', await dragHandle('b', 0, 60), 'The bottom edge handle can be dragged');
    now = (await drawBoxes(page))[0];
    recorder.assert('bottom-edge-heightens', now.height > start.height + 15,
        'Dragging the bottom edge makes the drawing taller',
        `${Math.round(start.height)} -> ${Math.round(now.height)}`);
    recorder.near('bottom-edge-keeps-width', now.width, start.width, 3,
        'Dragging the bottom edge leaves the width alone');

    // A corner still scales proportionally, which is the behaviour a drawing
    // already had and which this change deliberately leaves alone.
    start = now;
    const startRatio = start.width / Math.max(1, start.height);
    recorder.assert('corner-dragged', await dragHandle('se', 60, 20), 'The corner handle can be dragged');
    now = (await drawBoxes(page))[0];
    recorder.near('corner-keeps-aspect', now.width / Math.max(1, now.height), startRatio, 0.08,
        'A corner drag still scales the drawing proportionally',
        `${startRatio.toFixed(3)} -> ${(now.width / Math.max(1, now.height)).toFixed(3)}`);

    // Moving by the body.
    start = now;
    await page.mouse.move(start.left + (start.width / 2), start.top + (start.height / 2));
    await page.mouse.down();
    for (let step = 1; step <= 8; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(
            start.left + (start.width / 2) + ((60 * step) / 8),
            start.top + (start.height / 2) + ((40 * step) / 8),
        );
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(30);
    }
    await page.mouse.up();
    await page.waitForTimeout(600);
    now = (await drawBoxes(page))[0];
    recorder.assert('body-drag-moves', Math.abs(now.left - start.left) > 20,
        'Dragging the body moves the drawing',
        `left ${Math.round(start.left)} -> ${Math.round(now.left)}`);
    recorder.near('move-keeps-size', now.width, start.width, 3,
        'Moving does not change the size');

    recorder.equals('still-a-drawing', now.directDraw, '1',
        'It is still a drawing after every gesture');
    recorder.equals('still-one', (await drawBoxes(page)).length, 1,
        'No gesture duplicated the annotation');

    await saveDocument(page, saveRecorder);
    const saved = savedDrawAnnotations(saveRecorder);
    recorder.equals('saved-one', saved.length, 1, 'The resized drawing saves as one annotation');
    recorder.assert('geometry-saved',
        Number(saved[0]?.pdfWidth) > 0 && Number(saved[0]?.pdfHeight) > 0,
        'The new geometry is written to the save payload',
        `${saved[0]?.pdfWidth} x ${saved[0]?.pdfHeight}`);

    artifacts.push(await capture(page, '17-move-and-resize', 'after-gestures'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Draw tool available', run: testBlankProjectLoads },
    { id: '02-tool-exclusivity', number: '02', title: 'Draw toggles from the floating toolbar and is exclusive with the other tools', run: testToolExclusivity, signedIn: true },
    { id: '03-pen-draws-a-stroke', number: '03', title: 'The pen commits a drawing annotation', run: testPenDrawsStroke, signedIn: true },
    { id: '04-eraser-tool', number: '04', title: 'The eraser is a draw tool, with its own panel state and stacking', run: testEraserTool, signedIn: true },
    { id: '05-live-preview', number: '05', title: 'A live preview paints during the drag and commits on release', run: testLivePreview, signedIn: true },
    { id: '06-degenerate-stroke', number: '06', title: 'A press with no travel, and drags below the movement threshold', run: testDegenerateStroke, signedIn: true },
    { id: '07-brush-size-range', number: '07', title: 'Brush size across its whole range', run: testBrushSizeRange, signedIn: true },
    { id: '08-ink-colour', number: '08', title: 'Ink colour, from both the swatches and the custom picker', run: testInkColour, signedIn: true },
    { id: '09-opacity', number: '09', title: 'Opacity applies to the stroke', run: testOpacity, signedIn: true },
    { id: '10-smoothing-slider', number: '10', title: 'NK_16: smoothing is a stroke property with a real range', run: testSmoothingSlider, signedIn: true },
    { id: '11-smoothing-reeditable', number: '11', title: 'NK_16: smoothing is re-editable on an existing drawing', run: testSmoothingReeditable, signedIn: true },
    { id: '12-defaults', number: '12', title: 'A fresh session starts from the documented defaults', run: testDefaults, signedIn: true },
    { id: '13-style-persists', number: '13', title: 'Style choices persist from one stroke to the next', run: testStylePersistsBetweenStrokes, signedIn: true },
    { id: '14-select-and-menu', number: '14', title: 'Select, deselect and the drawing hover menu', run: testSelectAndMenu, signedIn: true },
    { id: '15-rotate-drawing', number: '15', title: 'NK_18: a drawing rotates', run: testRotateDrawing, signedIn: true },
    { id: '16-draw-cursor-over-handles', number: '16', title: 'NK_19: the draw cursor must not swallow the handle cursors', run: testDrawCursorOverHandles, signedIn: true },
    { id: '17-move-and-resize', number: '17', title: 'Move and resize a drawing, including the edge handles', run: testMoveAndResize, signedIn: true },
];

function summarise(test, checks, artifacts, error, startedAt) {
    const passed = checks.filter((check) => check.result === 'PASS').length;
    const skipped = checks.filter((check) => check.result === 'SKIP').length;
    // Skipped checks are neither failures nor passes: drop them from the
    // denominator so the ratio reads as "everything that could run".
    const total = checks.length - skipped;
    let status = 'passed';
    if (error) status = 'error';
    else if (checks.length === 0) status = 'error';
    else if (passed < total) status = 'failed';

    return {
        id: test.id,
        number: test.number,
        title: test.title,
        status,
        checks,
        checks_passed: passed,
        checks_total: total,
        checks_skipped: skipped,
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
        browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });

        for (const test of selected) {
            const startedAt = Date.now();
            const recorder = createRecorder();
            let context = null;
            docId = null;

            try {
                // A fresh context per test so no cookie, selection or tool
                // state leaks from one case into another.
                context = await browser.newContext({
                    viewport: { width: 1500, height: 1000 },
                    ignoreHTTPSErrors: true,
                });

                // The container has no reliable outbound route; fulfil webfont
                // requests with an empty stylesheet rather than aborting, since
                // an abort surfaces as a console error that case 01 asserts on.
                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => {
                    route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {});
                });

                const page = await context.newPage();
                const saveRecorder = attachSaveRecorder(page);
                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(page, csrfToken, `Draw tool test ${test.number}`, test.pages || 1);
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
        return { success: false, message: String(error.message || error), results };
    } finally {
        if (browser) {
            try { await browser.close(); } catch (_) { /* ignore */ }
        }
    }

    const checksTotal = results.reduce((sum, r) => sum + r.checks_total, 0);
    const checksPassed = results.reduce((sum, r) => sum + r.checks_passed, 0);

    return {
        success: true,
        results,
        summary: {
            tests_total: results.length,
            tests_passed: results.filter((r) => r.status === 'passed').length,
            tests_failed: results.filter((r) => r.status === 'failed').length,
            tests_errored: results.filter((r) => r.status === 'error').length,
            checks_total: checksTotal,
            checks_passed: checksPassed,
            duration_ms: Date.now() - runStartedAt,
        },
    };
}

module.exports = {
    TESTS,
    BASE_URL,
    DRAW_BOX_SELECTOR,
    DRAW_DEFAULTS,
    DRAW_RANGES,
    DRAW_TOOLS,
    INK_SWATCHES,
    buildBlankPdfBuffer,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    openEditorAsSignedIn,
    pointOnPage,
    drawModeOn,
    setDrawMode,
    drawToolControls,
    drawPanelState,
    pickDrawTool,
    setDrawStyle,
    setRange,
    drawBoxes,
    drawBoxCount,
    drawPreviewState,
    drawStroke,
    tapOnPage,
    attachSaveRecorder,
    savedDrawAnnotations,
    primaryStroke,
    saveDocument,
    saveAndReload,
    readZoom,
    stepZoom,
    selectDrawing,
    ensureDrawingSelected,
    setEditMode,
    editModeOn,
    layersPanelRows,
    adminCredentials,
    signInAsAdmin,
    openEditorAsAdmin,
    pageRotation,
    rotatePage,
    annMenuState,
    annotationMenuAction,
    rotateHandleAt,
    dragRotateHandle,
    resolvedCursor,
    clickHistory,
    runTests,
};

// Only auto-run as a CLI; requiring this file (e.g. to reuse helpers) must not
// kick off a browser run.
if (require.main === module) {
    const argv = process.argv.slice(2);

    if (argv.includes('--list')) {
        process.stdout.write(`${JSON.stringify({
            success: true,
            tests: TESTS.map((t) => ({ id: t.id, number: t.number, title: t.title })),
        })}\n`);
    } else {
        const runAll = argv.includes('--run-all');
        const runIndex = argv.indexOf('--run');
        const ids = runAll
            ? TESTS.map((t) => t.id)
            : (runIndex >= 0 ? String(argv[runIndex + 1] || '').split(',').map((s) => s.trim()).filter(Boolean) : []);

        if (ids.length === 0) {
            process.stdout.write(`${JSON.stringify({ success: false, message: 'Nothing to run. Use --list, --run <ids> or --run-all.' })}\n`);
        } else {
            runTests(ids)
                .then((payload) => process.stdout.write(`${JSON.stringify(payload)}\n`))
                .catch((error) => process.stdout.write(`${JSON.stringify({ success: false, message: String(error.message || error), results: [] })}\n`));
        }
    }
}
