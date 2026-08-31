/**
 * Highlight Tool Automated Tests (Playwright)
 *
 * Automates part of the "[QA] - Highlight tool" story. Each run creates a
 * fresh blank PDF, uploads it as a new document, opens it in the pdf.js
 * editor, and drives the real UI.
 *
 * The harness (document setup, page geometry, save recording, zoom, page
 * rotation, the annotation menu, edit mode, the layers panel and admin
 * sign-in) is deliberately identical to the Shapes and Draw suites. Only the
 * probes below the "Highlight tool probes" banner and the cases themselves are
 * specific to this tool.
 *
 * What makes Highlight different from the other tools:
 *
 *   - A highlight is a SHAPE annotation with shapeType "highlight", not a type
 *     of its own. The suite matches on .enpv-highlight-box and asserts against
 *     shapeType in the save payload.
 *   - It has TWO creation paths, and they disagree on purpose. Selecting text
 *     highlights the text rectangles; dragging over the page snaps to text
 *     rectangles when the area covers any, and only falls back to the dragged
 *     box when it covers none. One drag can therefore commit several
 *     annotations, one per line.
 *   - The fill carries the colour and the stroke is deliberately transparent,
 *     so a highlight reads as a wash over the text rather than a box around it.
 *
 * Usage:
 *   node run_highlight_tests.cjs --list
 *   node run_highlight_tests.cjs --run 01-blank-project-loads,03-area-highlight
 *   node run_highlight_tests.cjs --run-all
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

/** A highlight is a shape annotation; the class is what marks it out. */
const HIGHLIGHT_BOX_SELECTOR = '.enpv-annotation-box.enpv-highlight-box';

/**
 * The six swatches in _highlight-panel.blade.php, in order. Asserted by the
 * colour case, so adding one has to be a deliberate change in both places.
 */
const HIGHLIGHT_SWATCHES = ['#facc15', '#fb923c', '#86efac', '#7dd3fc', '#f9a8d4', '#c4b5fd'];

/** What a fresh highlight is created with, from the module state in main.js. */
const HIGHLIGHT_DEFAULTS = {
    color: '#facc15',
    opacityPercent: 35,
};

/** Slider bounds, mirrored from the panel markup. */
const HIGHLIGHT_RANGES = {
    opacity: { min: 15, max: 80 },
};

/**
 * The blank test PDF prints one line of text near the top of each page. These
 * fractions bracket it, so a case can aim a drag AT the text or deliberately
 * away from it — the two paths behave differently and both need covering.
 */
const TEXT_LINE = { fx: 0.08, fy: 0.052 };
const EMPTY_AREA = { fx: 0.25, fy: 0.45 };

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
    const title = String(label || 'Highlight tool automated test').replace(/[()\\]/g, '');
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
    const name = `highlight_tool_test_${process.pid}_${uploadCounter}.pdf`;

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
    }, HIGHLIGHT_BOX_SELECTOR);
}

/** Drag the rotate handle around the drawing's centre by an angle in degrees. */
async function dragRotateHandle(page, degrees) {
    const handle = await rotateHandleAt(page);
    if (!handle?.visible) return false;
    const centre = await page.evaluate((selector) => {
        const box = document.querySelector(`${selector}.is-selected`);
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, HIGHLIGHT_BOX_SELECTOR);

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
// Shared probes (identical to the Draw suite)
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

/** Every annotation carried by the most recent save, drawing or not. */
function lastSavedAnnotations(saveRecorder) {
    const last = saveRecorder?.saves?.[saveRecorder.saves.length - 1];
    return Array.isArray(last?.annotations) ? last.annotations : [];
}

/**
 * POST an annotation payload straight at the save endpoint.
 *
 * Used to plant state the UI can no longer produce -- a stroke saved before
 * NK_16 added the smoothing field, for instance.
 *
 * The editor scopes saved state by a session id it keeps in localStorage, and
 * the server filters on it. Inventing one here writes to a bucket the reload
 * never reads, so the write looks like it succeeded and silently changes
 * nothing -- the editor's own id is read back and reused instead.
 */
async function saveAnnotationsDirectly(page, docId, annotations) {
    return page.evaluate(async ([id, payload]) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let sessionId = '';
        try { sessionId = window.localStorage.getItem(`edit_new_session_${id}`) || ''; } catch (_) { sessionId = ''; }
        if (!sessionId) return { ok: false, status: 0, reason: 'no editor session id in localStorage' };
        const response = await fetch(`/documents/${id}/save-annotation-state`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                annotations: payload,
                session_annotations: payload,
                session_id: sessionId,
            }),
        });
        return { ok: response.ok, status: response.status, sessionId };
    }, [docId, annotations]);
}

/** Reload the editor without saving first, so planted state is what loads. */
async function reloadEditor(page) {
    await page.reload({ waitUntil: 'load', timeout: 45000 });
    await page.waitForSelector('#viewer .page canvas', { timeout: 30000 });
    await page.waitForTimeout(1400);
}

/**
 * Download the annotated PDF through the real endpoint and write it to the
 * artifact directory, so a case can inspect what a user would actually get.
 *
 * The annotations are passed in rather than collected from the page: the
 * pdf.js editor has no collectSessionAnnotations global (that belongs to the
 * legacy editor), and the state recorded from the last save is exactly what
 * the server is holding anyway.
 */
async function downloadAnnotatedPdf(page, docId, testId, annotations) {
    const encoded = await page.evaluate(async ([id, sessionAnnotations]) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(`/documents/${id}/download-annotated-pdf`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                annotations: sessionAnnotations,
                session_annotations: sessionAnnotations,
                use_exact_download_path: true,
                session_id: `draw-suite-download-${Date.now()}`,
            }),
        });
        if (!response.ok) {
            return { ok: false, status: response.status, body: (await response.text()).slice(0, 300) };
        }
        const bytes = new Uint8Array(await response.arrayBuffer());
        let binary = '';
        for (let index = 0; index < bytes.length; index += 1) binary += String.fromCharCode(bytes[index]);
        return { ok: true, count: sessionAnnotations.length, base64: btoa(binary) };
    }, [docId, annotations]);

    if (!encoded.ok) return { ok: false, status: encoded.status, body: encoded.body };

    ensureArtifactDir();
    const filePath = path.join(ARTIFACT_DIR, `${testId}__download.pdf`);
    const buffer = Buffer.from(encoded.base64, 'base64');
    fs.writeFileSync(filePath, buffer);
    try { fs.chmodSync(filePath, 0o666); } catch (_) { /* best effort */ }
    return { ok: true, filePath, bytes: buffer.length, count: encoded.count };
}

/**
 * Ask PyMuPDF what images a PDF actually contains, and where.
 *
 * The runner has no PDF reader, and asserting on raw bytes would prove almost
 * nothing. The app already resolves a python that has fitz -- the same one it
 * uses to write the file -- so the check reuses it.
 */
function imagesInPdf(filePath) {
    const candidates = [
        path.resolve(__dirname, '..', '..', '..', '.venv', 'bin', 'python'),
        path.resolve(__dirname, '..', '..', '..', 'python', 'venv', 'bin', 'python'),
        'python3',
    ];
    const program = [
        'import json,sys',
        'import fitz',
        'doc = fitz.open(sys.argv[1])',
        'out = []',
        'for number, page in enumerate(doc):',
        '    for info in page.get_image_info():',
        '        bbox = info.get("bbox") or [0, 0, 0, 0]',
        '        out.append({',
        '            "page": number,',
        '            "width": round(bbox[2] - bbox[0], 3),',
        '            "height": round(bbox[3] - bbox[1], 3),',
        '            "x": round(bbox[0], 3),',
        '            "y": round(bbox[1], 3),',
        '        })',
        'print(json.dumps(out))',
    ].join('\n');

    for (const python of candidates) {
        try {
            const stdout = require('child_process').execFileSync(
                python,
                ['-c', program, filePath],
                { encoding: 'utf8', timeout: 60000, stdio: ['ignore', 'pipe', 'pipe'] },
            );
            return { ok: true, python, images: JSON.parse(stdout.trim()) };
        } catch (error) {
            // Try the next candidate; only the last failure is worth reporting.
            if (python === candidates[candidates.length - 1]) {
                return { ok: false, error: String(error.message || error).slice(0, 200) };
            }
        }
    }
    return { ok: false, error: 'no python with fitz' };
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
// Highlight tool probes
// ---------------------------------------------------------------------------

/** Presence and state of the control that toggles the tool. */
function highlightToolControls(page) {
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
        return { floating: read('ftb-highlight'), topBar: read('highlight-btn') };
    });
}

/** Toggle highlight mode through the real control. */
async function setHighlightMode(page, on, control = 'ftb-highlight') {
    const already = await highlightModeOn(page);
    if (already === !!on) return;
    await page.evaluate((id) => document.getElementById(id)?.click(), control);
    await page.waitForTimeout(450);
}

/** Everything the Highlight Options panel is currently showing. */
function highlightPanelState(page) {
    return page.evaluate(() => {
        const panel = document.getElementById('highlight-tool-panel');
        const input = document.getElementById('highlight-tool-opacity');
        const readout = document.getElementById('highlight-tool-opacity-value');
        const swatches = Array.from(document.querySelectorAll('[data-highlight-color]')).map((button) => ({
            color: String(button.dataset.highlightColor || '').toLowerCase(),
            active: button.classList.contains('is-active'),
            label: button.getAttribute('aria-label') || '',
        }));
        return {
            present: !!panel,
            visible: !!panel && panel.classList.contains('is-visible'),
            ariaHidden: panel ? panel.getAttribute('aria-hidden') : null,
            swatches,
            activeSwatch: (swatches.find((button) => button.active) || {}).color || null,
            customColor: String(document.getElementById('highlight-tool-color')?.value || '').toLowerCase(),
            opacity: input ? {
                present: true,
                value: Number(input.value),
                min: Number(input.min),
                max: Number(input.max),
                disabled: !!input.disabled,
                readout: readout ? readout.textContent.trim() : '',
            } : { present: false },
            status: document.getElementById('highlight-tool-status')?.textContent?.trim() || '',
        };
    });
}

/** Set the highlight style through the panel. */
async function setHighlightStyle(page, { opacityPercent, swatch, customHex } = {}) {
    if (opacityPercent != null) await setRange(page, 'highlight-tool-opacity', opacityPercent);
    if (swatch != null) {
        await page.evaluate((hex) => {
            document.querySelector(`[data-highlight-color="${hex}"]`)?.click();
        }, swatch);
        await page.waitForTimeout(300);
    }
    if (customHex != null) {
        await page.evaluate((hex) => {
            const input = document.getElementById('highlight-tool-color');
            if (!input) return;
            input.value = hex;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, customHex);
        await page.waitForTimeout(300);
    }
}

/** Every highlight box on the page. */
function highlightBoxes(page) {
    return page.evaluate((selector) => Array.from(document.querySelectorAll(selector)).map((box) => {
        const rect = box.getBoundingClientRect();
        const svg = box.querySelector('svg');
        const filled = svg?.querySelector('[fill]');
        return {
            annotationId: box.dataset.annotationId || '',
            annotationType: box.dataset.annotationType || '',
            shapeType: box.dataset.shapeType || '',
            locked: box.dataset.locked === '1',
            zIndex: Number(box.dataset.zIndex),
            selected: box.classList.contains('is-selected'),
            width: rect.width,
            height: rect.height,
            left: rect.left,
            top: rect.top,
            fill: String(filled?.getAttribute('fill') || '').toLowerCase(),
            fillOpacity: filled?.getAttribute('fill-opacity'),
        };
    }), HIGHLIGHT_BOX_SELECTOR);
}

function highlightBoxCount(page) {
    return page.evaluate((selector) => document.querySelectorAll(selector).length, HIGHLIGHT_BOX_SELECTOR);
}

/** Whether the drag preview is currently on the page. */
function highlightPreviewState(page) {
    return page.evaluate(() => {
        const previews = Array.from(document.querySelectorAll('.enpv-highlight-drag-selection'));
        return {
            count: previews.length,
            sized: previews.some((el) => Number.parseFloat(el.style.width || '0') > 0),
        };
    });
}

/**
 * Drag a highlight area on the page.
 *
 * Snapshots the preview and the committed count mid-drag, so a caller can
 * prove a preview existed and that nothing landed before the release.
 */
async function dragHighlight(page, from, delta, options = {}) {
    const pageNumber = options.pageNumber || 1;
    const viewport = page.viewportSize();
    const outside = (point) => point.x < 2 || point.y < 2
        || point.x > viewport.width - 2 || point.y > viewport.height - 2;

    let start = await pointOnPage(page, pageNumber, from.fx, from.fy);
    if (outside({ x: start.x + delta.dx, y: start.y + delta.dy })) {
        await page.evaluate((dy) => {
            const container = document.getElementById('viewerContainer');
            if (container) container.scrollTop += dy;
        }, start.y - (viewport.height / 2));
        await page.waitForTimeout(400);
        start = await pointOnPage(page, pageNumber, from.fx, from.fy);
    }

    const steps = options.steps || 10;
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    for (let step = 1; step <= steps; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(start.x + ((delta.dx * step) / steps), start.y + ((delta.dy * step) / steps));
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(25);
    }

    const preview = await highlightPreviewState(page);
    const committedDuringDrag = await highlightBoxCount(page);

    await page.mouse.up();
    await page.waitForTimeout(700);

    return { start, preview, committedDuringDrag };
}

/**
 * Select the text on the page with a real drag across the text layer, which is
 * what a user does before the selection path commits.
 */
async function selectPageText(page, pageNumber = 1) {
    const span = await page.evaluate((number) => {
        const pageDiv = document.querySelector(`#viewer .page[data-page-number="${number}"]`);
        const candidates = Array.from(pageDiv?.querySelectorAll('.textLayer span') || [])
            .filter((el) => (el.textContent || '').trim().length > 2);
        const target = candidates[0];
        if (!target) return null;
        const rect = target.getBoundingClientRect();
        return { left: rect.left, top: rect.top, width: rect.width, height: rect.height, text: target.textContent };
    }, pageNumber);
    if (!span) return null;

    const y = span.top + (span.height / 2);
    await page.mouse.move(span.left + 1, y);
    await page.mouse.down();
    await page.mouse.move(span.left + (span.width * 0.5), y, { steps: 6 });
    await page.mouse.move(span.left + Math.max(6, span.width - 1), y, { steps: 6 });
    await page.mouse.up();
    await page.waitForTimeout(700);

    const selected = await page.evaluate(() => String(window.getSelection?.()?.toString() || ''));
    return { span, selected };
}

/** The highlight annotations carried by the most recent save. */
function savedHighlightAnnotations(saveRecorder) {
    const last = saveRecorder?.saves?.[saveRecorder.saves.length - 1];
    return (last?.annotations || []).filter(
        (annotation) => String(annotation?.shapeType || '').toLowerCase() === 'highlight',
    );
}

/** Click a highlight to select it. */
async function selectHighlight(page, index = 0) {
    const boxes = await highlightBoxes(page);
    const box = boxes[index];
    if (!box) return false;
    await page.mouse.click(box.left + (box.width / 2), box.top + (box.height / 2));
    await page.waitForTimeout(450);
    const after = await highlightBoxes(page);
    return !!after[index]?.selected;
}

/**
 * Select a highlight and wait until its menu is usable.
 *
 * Leaves highlight mode first: while the tool is on, a press on the page
 * starts a new highlight drag rather than selecting what is under the pointer.
 */
async function ensureHighlightSelected(page, index = 0) {
    await setHighlightMode(page, false);
    await setEditMode(page, true);
    for (let attempt = 0; attempt < 5; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        await selectHighlight(page, index);
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

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: blank project loads with the Highlight tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const canvas = await page.evaluate(() => {
        const element = document.querySelector('#viewer .page canvas');
        return element ? { width: element.width, height: element.height } : null;
    });
    recorder.assert('page-rasterised', !!canvas && canvas.width > 0 && canvas.height > 0,
        'Page 1 rasterises', JSON.stringify(canvas));

    const controls = await highlightToolControls(page);
    recorder.assert('control-present', controls.floating.present && controls.floating.visible,
        'The floating toolbar offers the Highlight tool', JSON.stringify(controls.floating));
    recorder.assert('control-enabled', controls.floating.present && !controls.floating.disabled,
        'The Highlight control is enabled on load');
    recorder.assert('control-inactive', !controls.floating.active,
        'The Highlight control starts inactive', `aria-pressed=${controls.floating.pressed}`);

    recorder.assert('mode-off', !(await highlightModeOn(page)),
        'The document does not start in highlight mode');

    const panel = await highlightPanelState(page);
    recorder.assert('panel-present', panel.present, 'The Highlight Options panel is in the DOM');
    recorder.assert('panel-closed', !panel.visible && panel.ariaHidden === 'true',
        'The panel starts closed', `aria-hidden=${panel.ariaHidden}`);

    recorder.equals('no-highlights', await highlightBoxCount(page), 0, 'No highlight exists yet');

    // Both creation paths need a text layer; without one the tool can only ever
    // make area highlights, so its absence would silently halve the tool.
    const textLayer = await page.evaluate(() => {
        const spans = document.querySelectorAll('#viewer .page[data-page-number="1"] .textLayer span');
        return { spans: spans.length, sample: (spans[0]?.textContent || '').trim().slice(0, 40) };
    });
    recorder.assert('text-layer-present', textLayer.spans > 0,
        'The page renders a text layer, which the text-selection path needs',
        JSON.stringify(textLayer));

    const realErrors = consoleErrors.filter((text) => !/favicon|fonts\.(googleapis|gstatic)/i.test(text));
    recorder.assert('console-clean', realErrors.length === 0,
        'Loading the editor logs no console errors', realErrors.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — Highlight is exclusive with every other tool mode. */
async function testToolExclusivity(page, { recorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    recorder.assert('mode-on', await highlightModeOn(page), 'Clicking the control enters highlight mode');

    let panel = await highlightPanelState(page);
    recorder.assert('panel-opens', panel.visible && panel.ariaHidden === 'false',
        'Highlight mode opens the Highlight Options panel');

    let controls = await highlightToolControls(page);
    recorder.assert('control-active', controls.floating.active && controls.floating.pressed === 'true',
        'The control reports itself active', JSON.stringify(controls.floating));

    const rivals = [
        { id: 'ftb-draw-erase', name: 'Draw', probe: drawModeOn },
        { id: 'ftb-add-shape', name: 'Shapes', probe: shapeModeOn },
        { id: 'ftb-add-text', name: 'Add Text', probe: addTextModeOn },
    ];

    for (const rival of rivals) {
        // eslint-disable-next-line no-await-in-loop
        const exists = await page.evaluate((id) => !!document.getElementById(id), rival.id);
        if (!exists) {
            recorder.skip(`rival-${rival.id}`, `${rival.name} is not present in this build`);
            continue;
        }
        // eslint-disable-next-line no-await-in-loop
        await setHighlightMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await page.evaluate((id) => document.getElementById(id)?.click(), rival.id);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(450);
        // eslint-disable-next-line no-await-in-loop
        const stillOn = await highlightModeOn(page);
        // eslint-disable-next-line no-await-in-loop
        const rivalOn = await rival.probe(page);
        recorder.assert(`${rival.id}-cancels-highlight`, rivalOn && !stillOn,
            `${rival.name} cancels highlight mode`, `${rival.name}=${rivalOn} highlight=${stillOn}`);

        // eslint-disable-next-line no-await-in-loop
        await setHighlightMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        recorder.assert(`highlight-cancels-${rival.id}`, !(await rival.probe(page)),
            `Highlight cancels ${rival.name}`);
    }

    await setHighlightMode(page, true);
    await setEditMode(page, true);
    recorder.assert('edit-cancels-highlight', !(await highlightModeOn(page)) && await editModeOn(page),
        'Edit mode cancels highlight mode');
    panel = await highlightPanelState(page);
    recorder.assert('panel-closes-on-edit', !panel.visible, 'The panel closes with the mode');
    await setEditMode(page, false);

    await setHighlightMode(page, true);
    await setHighlightMode(page, false);
    recorder.assert('toggles-off', !(await highlightModeOn(page)),
        'The control toggles highlight mode back off');
    controls = await highlightToolControls(page);
    recorder.assert('control-inactive-again', !controls.floating.active && controls.floating.pressed === 'false',
        'The control reports itself inactive again');

    artifacts.push(await capture(page, '02-tool-exclusivity', 'after-toggles'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Dragging over empty page area commits an area highlight. */
async function testAreaHighlight(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const drag = await dragHighlight(page, EMPTY_AREA, { dx: 200, dy: 60 });

    const boxes = await highlightBoxes(page);
    recorder.equals('one-highlight', boxes.length, 1,
        'A drag over empty page area commits one highlight');

    const box = boxes[0] || {};
    recorder.equals('is-a-shape', box.annotationType, 'shape',
        'A highlight is a shape annotation, not a type of its own');
    recorder.equals('shape-type', box.shapeType, 'highlight',
        'The shape records itself as a highlight');
    recorder.assert('has-area', box.width > 20 && box.height > 10,
        'The highlight has real area', `${Math.round(box.width)}x${Math.round(box.height)}`);

    // It should cover roughly the dragged rectangle, since there is no text
    // here for it to snap to.
    recorder.assert('tracks-the-drag',
        Math.abs(box.width - 200) < 60 && Math.abs(box.height - 60) < 40,
        'The area highlight covers the rectangle that was dragged',
        `dragged 200x60, got ${Math.round(box.width)}x${Math.round(box.height)}`);

    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder);
    recorder.equals('saved-one', saved.length, 1, 'The highlight is included in the save payload');

    const annotation = saved[0] || {};
    recorder.equals('saved-type', String(annotation.type || '').toLowerCase(), 'shape',
        'It saves as a shape');
    recorder.equals('saved-shape-type', String(annotation.shapeType || '').toLowerCase(), 'highlight',
        'It saves with shapeType highlight');
    recorder.equals('fill-is-the-highlight-colour',
        String(annotation.fillColor || '').toLowerCase(), HIGHLIGHT_DEFAULTS.color,
        'The fill carries the highlight colour');
    recorder.assert('stroke-is-transparent',
        Number(annotation.strokeOpacity) === 0 || annotation.strokeTransparent === true,
        'The stroke is transparent, so a highlight is a wash rather than a box',
        `strokeOpacity=${annotation.strokeOpacity} transparent=${annotation.strokeTransparent}`);

    void drag;
    artifacts.push(await capture(page, '03-area-highlight', 'after-drag'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — Dragging across text snaps to the text rather than the dragged box. */
async function testDragOverTextSnaps(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    // A tall drag that starts above the text line and ends below it. If the
    // highlight tracked the drag it would be as tall as the drag; snapping to
    // the text makes it line-height instead.
    await dragHighlight(page, { fx: 0.06, fy: 0.03 }, { dx: 260, dy: 55 });

    const boxes = await highlightBoxes(page);
    recorder.assert('committed', boxes.length >= 1,
        'Dragging across the text line commits at least one highlight', `${boxes.length} boxes`);

    if (boxes.length) {
        const tallest = Math.max(...boxes.map((box) => box.height));
        recorder.assert('snapped-to-text-height', tallest < 45,
            'The highlight takes the height of the text line rather than the height of the drag',
            `drag was 55px tall, tallest highlight is ${Math.round(tallest)}px`);
    }

    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder);
    recorder.assert('saved', saved.length >= 1, 'The snapped highlight saves',
        `${saved.length} saved`);
    recorder.assert('all-are-highlights',
        saved.every((annotation) => String(annotation.shapeType).toLowerCase() === 'highlight'),
        'Everything committed is a highlight');

    artifacts.push(await capture(page, '04-drag-over-text', 'snapped'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — Selecting text commits a highlight over it. */
async function testTextSelectionHighlight(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const selection = await selectPageText(page, 1);
    recorder.assert('text-selectable', !!selection,
        'The page has selectable text to highlight', selection ? selection.span.text : 'no span found');

    if (!selection) {
        artifacts.push(await capture(page, '05-text-selection', 'no-text'));
        return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
    }

    const boxes = await highlightBoxes(page);
    recorder.assert('selection-commits', boxes.length >= 1,
        'Selecting text commits a highlight over it', `${boxes.length} boxes`);

    if (boxes.length) {
        recorder.assert('covers-the-text',
            boxes.some((box) => Math.abs(box.top - selection.span.top) < 30),
            'The highlight lands on the line that was selected',
            `span top ${Math.round(selection.span.top)}, boxes ${boxes.map((b) => Math.round(b.top)).join(',')}`);
        recorder.assert('line-height-not-page-height',
            boxes.every((box) => box.height < 45),
            'Each highlight is one line tall',
            boxes.map((b) => Math.round(b.height)).join(','));
    }

    // The selection is cleared once it has been turned into a highlight, or
    // the next click would re-commit the same text.
    const remaining = await page.evaluate(() => String(window.getSelection?.()?.toString() || ''));
    recorder.equals('selection-cleared', remaining, '',
        'The text selection is released once it has been highlighted');

    await saveDocument(page, saveRecorder);
    recorder.assert('saved', savedHighlightAnnotations(saveRecorder).length >= 1,
        'The highlight from the text selection saves');

    artifacts.push(await capture(page, '05-text-selection', 'highlighted'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — A live preview paints during the drag and commits on release. */
async function testLivePreview(page, { recorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const drag = await dragHighlight(page, EMPTY_AREA, { dx: 220, dy: 70 }, { steps: 14 });

    recorder.equals('nothing-committed-mid-drag', drag.committedDuringDrag, 0,
        'No highlight exists while the pointer is still down');
    recorder.assert('preview-shown', drag.preview.count > 0 && drag.preview.sized,
        'A sized preview element is on the page during the drag', JSON.stringify(drag.preview));

    recorder.equals('committed-on-release', await highlightBoxCount(page), 1,
        'Releasing commits the highlight');

    const after = await highlightPreviewState(page);
    recorder.equals('preview-torn-down', after.count, 0,
        'The preview is removed once the highlight is committed');

    artifacts.push(await capture(page, '06-live-preview', 'after-release'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Drags too small to be meant commit nothing. */
async function testTinyDrags(page, { recorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);

    // finishHighlightDrag requires the pointer to have moved, then width >= 6,
    // and for an area highlight height >= 6 as well.
    await dragHighlight(page, EMPTY_AREA, { dx: 0, dy: 0 }, { steps: 2 });
    recorder.equals('no-move-commits-nothing', await highlightBoxCount(page), 0,
        'A press with no movement commits nothing');

    await dragHighlight(page, { fx: 0.3, fy: 0.5 }, { dx: 3, dy: 3 }, { steps: 2 });
    recorder.equals('sub-threshold-commits-nothing', await highlightBoxCount(page), 0,
        'A drag under the width threshold commits nothing');

    await dragHighlight(page, { fx: 0.3, fy: 0.6 }, { dx: 120, dy: 2 }, { steps: 6 });
    recorder.equals('flat-drag-commits-nothing', await highlightBoxCount(page), 0,
        'A wide but flat drag over empty area commits nothing, since it has no height');

    // And a real one still works, so the guards are not simply swallowing
    // everything.
    await dragHighlight(page, { fx: 0.3, fy: 0.7 }, { dx: 140, dy: 40 });
    recorder.equals('real-drag-still-works', await highlightBoxCount(page), 1,
        'A deliberate drag still commits');

    artifacts.push(await capture(page, '07-tiny-drags', 'after-guards'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — Colour, from both the swatches and the custom picker. */
async function testColour(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const panel = await highlightPanelState(page);
    recorder.equals('swatch-count', panel.swatches.length, HIGHLIGHT_SWATCHES.length,
        'The panel offers the documented swatches');
    recorder.assert('swatch-colours',
        HIGHLIGHT_SWATCHES.every((hex) => panel.swatches.some((swatch) => swatch.color === hex)),
        'The swatches are the documented colours',
        panel.swatches.map((swatch) => swatch.color).join(','));
    recorder.equals('default-swatch-active', panel.activeSwatch, HIGHLIGHT_DEFAULTS.color,
        'Yellow starts as the active colour');

    let drawn = 0;
    for (const hex of ['#7dd3fc', '#f9a8d4']) {
        // eslint-disable-next-line no-await-in-loop
        await setHighlightStyle(page, { swatch: hex });
        // eslint-disable-next-line no-await-in-loop
        const state = await highlightPanelState(page);
        recorder.equals(`swatch-active-${hex}`, state.activeSwatch, hex, `Picking ${hex} marks that swatch active`);
        recorder.equals(`custom-follows-${hex}`, state.customColor, hex,
            `The custom colour input follows the swatch at ${hex}`);

        // eslint-disable-next-line no-await-in-loop
        await dragHighlight(page, { fx: 0.25, fy: 0.35 + (drawn * 0.15) }, { dx: 180, dy: 45 });
        drawn += 1;
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedHighlightAnnotations(saveRecorder);
        const latest = saved[saved.length - 1];
        recorder.equals(`fill-${hex}`, String(latest?.fillColor || '').toLowerCase(), hex,
            `A highlight made on ${hex} stores that fill`);
    }

    await setHighlightStyle(page, { customHex: '#a3e635' });
    const custom = await highlightPanelState(page);
    recorder.equals('custom-colour-applied', custom.customColor, '#a3e635',
        'The custom colour input takes an arbitrary colour');
    recorder.assert('no-swatch-for-custom', custom.activeSwatch === null,
        'No swatch claims to be active for a colour that is not one of them',
        `activeSwatch=${custom.activeSwatch}`);

    await dragHighlight(page, { fx: 0.25, fy: 0.68 }, { dx: 180, dy: 45 });
    await saveDocument(page, saveRecorder);
    const all = savedHighlightAnnotations(saveRecorder);
    recorder.equals('custom-fill', String(all[all.length - 1]?.fillColor || '').toLowerCase(), '#a3e635',
        'A highlight made on the custom colour stores it');

    artifacts.push(await capture(page, '08-colour', 'coloured'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 09 — Opacity applies to the highlight. */
async function testOpacity(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const panel = await highlightPanelState(page);
    recorder.equals('opacity-min', panel.opacity.min, HIGHLIGHT_RANGES.opacity.min,
        'Opacity starts at its documented minimum, so a highlight is never invisible');
    recorder.equals('opacity-max', panel.opacity.max, HIGHLIGHT_RANGES.opacity.max,
        'Opacity stops short of opaque, so a highlight never hides the text under it');

    let drawn = 0;
    for (const percent of [HIGHLIGHT_RANGES.opacity.min, HIGHLIGHT_RANGES.opacity.max]) {
        // eslint-disable-next-line no-await-in-loop
        await setHighlightStyle(page, { opacityPercent: percent });
        // eslint-disable-next-line no-await-in-loop
        const state = await highlightPanelState(page);
        recorder.equals(`readout-${percent}`, state.opacity.readout, `${percent}%`,
            `The readout follows the slider at ${percent}%`);

        // eslint-disable-next-line no-await-in-loop
        await dragHighlight(page, { fx: 0.25, fy: 0.35 + (drawn * 0.2) }, { dx: 180, dy: 45 });
        drawn += 1;
        // eslint-disable-next-line no-await-in-loop
        await saveDocument(page, saveRecorder);
        const saved = savedHighlightAnnotations(saveRecorder);
        const latest = saved[saved.length - 1];
        recorder.near(`fill-opacity-${percent}`, Number(latest?.fillOpacity), percent / 100, 0.02,
            `A highlight made at ${percent}% stores that fill opacity`);
    }

    artifacts.push(await capture(page, '09-opacity', 'two-opacities'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — A fresh highlight uses the documented defaults. */
async function testDefaults(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const panel = await highlightPanelState(page);
    recorder.equals('default-colour', panel.activeSwatch, HIGHLIGHT_DEFAULTS.color, 'Yellow is the default');
    recorder.equals('default-opacity', panel.opacity.value, HIGHLIGHT_DEFAULTS.opacityPercent,
        'The default opacity');
    recorder.assert('status-explains-both-paths', /select text/i.test(panel.status) && /drag/i.test(panel.status),
        'The status line mentions both ways of making a highlight', panel.status);

    await dragHighlight(page, EMPTY_AREA, { dx: 180, dy: 45 });
    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder)[0] || {};
    recorder.equals('annotation-fill', String(saved.fillColor || '').toLowerCase(), HIGHLIGHT_DEFAULTS.color,
        'A default highlight is stored yellow');
    recorder.near('annotation-opacity', Number(saved.fillOpacity), HIGHLIGHT_DEFAULTS.opacityPercent / 100, 0.02,
        'A default highlight is stored at the default opacity');
    recorder.assert('sits-low', Number(saved.zIndex) <= 2,
        'A highlight is given a low z-index, so it sits under other annotations',
        `zIndex=${saved.zIndex}`);

    artifacts.push(await capture(page, '10-defaults', 'default-highlight'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — Style choices persist from one highlight to the next. */
async function testStylePersists(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await setHighlightStyle(page, { swatch: '#86efac', opacityPercent: 60 });

    await dragHighlight(page, { fx: 0.25, fy: 0.35 }, { dx: 180, dy: 45 });
    const afterFirst = await highlightPanelState(page);
    recorder.assert('mode-stays-on', await highlightModeOn(page),
        'Highlight mode survives committing one highlight');
    recorder.equals('colour-persists', afterFirst.activeSwatch, '#86efac', 'The colour survives');
    recorder.equals('opacity-persists', afterFirst.opacity.value, 60, 'The opacity survives');

    await dragHighlight(page, { fx: 0.25, fy: 0.55 }, { dx: 180, dy: 45 });
    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder);
    recorder.equals('two-saved', saved.length, 2, 'Both highlights save');
    recorder.assert('both-same-style',
        saved.every((annotation) => String(annotation.fillColor).toLowerCase() === '#86efac'
            && Math.abs(Number(annotation.fillOpacity) - 0.6) < 0.02),
        'The second highlight is made with the same style as the first',
        JSON.stringify(saved.map((a) => ({ fill: a.fillColor, opacity: a.fillOpacity }))));

    artifacts.push(await capture(page, '11-style-persists', 'two-highlights'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — Selecting a highlight reflects its style, and restyling applies in place. */
async function testRestyleSelected(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await setHighlightStyle(page, { swatch: '#fb923c', opacityPercent: 70 });
    await dragHighlight(page, EMPTY_AREA, { dx: 200, dy: 50 });
    recorder.equals('one-made', await highlightBoxCount(page), 1, 'A highlight exists to re-edit');

    // Edit mode, not highlight mode: while the tool is on, a press on the page
    // starts a new highlight rather than selecting what is under the pointer.
    const selected = await ensureHighlightSelected(page, 0);
    recorder.assert('selected', selected, 'The highlight can be selected');

    const reflected = await highlightPanelState(page);
    recorder.assert('panel-open-for-selection', reflected.visible,
        'Selecting a highlight opens the Highlight Options panel');
    recorder.equals('colour-reflected', reflected.activeSwatch, '#fb923c',
        "The panel shows the selected highlight's own colour");
    recorder.equals('opacity-reflected', reflected.opacity.value, 70,
        "The panel shows the selected highlight's own opacity");

    await setHighlightStyle(page, { swatch: '#c4b5fd', opacityPercent: 25 });
    await page.waitForTimeout(500);

    const after = await highlightBoxes(page);
    recorder.equals('still-one', after.length, 1,
        'Restyling edits the highlight rather than adding another');

    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder);
    recorder.equals('restyled-colour', String(saved[0]?.fillColor || '').toLowerCase(), '#c4b5fd',
        'The new colour is what gets saved');
    recorder.near('restyled-opacity', Number(saved[0]?.fillOpacity), 0.25, 0.02,
        'The new opacity is what gets saved');

    artifacts.push(await capture(page, '12-restyle-selected', 'restyled'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — Select, deselect and the highlight hover menu. */
async function testSelectAndMenu(page, { recorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await dragHighlight(page, EMPTY_AREA, { dx: 200, dy: 50 });
    await setHighlightMode(page, false);

    const usable = await ensureHighlightSelected(page, 0);
    recorder.assert('menu-usable', usable, 'A highlight can be selected and its menu reached');

    const menu = await annMenuState(page);
    recorder.assert('menu-visible', menu.visible, 'The annotation menu comes up for a highlight');
    const actions = (menu.actions || []).map((action) => action.action);
    for (const action of ['delete', 'lock']) {
        recorder.assert(`menu-has-${action}`, actions.includes(action),
            `The menu offers ${action}`, actions.join(','));
    }
    recorder.ok('menu-actions-recorded', 'The action list a highlight offers is recorded', actions.join(','));

    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    recorder.assert('escape-deselects', !(await highlightBoxes(page))[0]?.selected,
        'Escape deselects the highlight');

    artifacts.push(await capture(page, '13-select-and-menu', 'menu-open'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — Move and resize a highlight. */
async function testMoveAndResize(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await dragHighlight(page, EMPTY_AREA, { dx: 200, dy: 50 });
    await setHighlightMode(page, false);
    await ensureHighlightSelected(page, 0);

    const edges = await page.evaluate((selector) => Array.from(
        document.querySelectorAll(`${selector}.is-selected .enpv-resize-handle`),
    ).map((handle) => handle.dataset.edge).sort(), HIGHLIGHT_BOX_SELECTOR);
    recorder.assert('handles-present', edges.length > 0,
        'A selected highlight offers resize handles', edges.join(','));

    const dragHandle = async (edge, dx, dy) => {
        const point = await page.evaluate(([selector, wanted]) => {
            const handle = document.querySelector(`${selector}.is-selected .enpv-resize-handle[data-edge="${wanted}"]`);
            if (!handle) return null;
            const rect = handle.getBoundingClientRect();
            return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
        }, [HIGHLIGHT_BOX_SELECTOR, edge]);
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

    let start = (await highlightBoxes(page))[0];
    if (edges.includes('r')) {
        recorder.assert('right-edge-dragged', await dragHandle('r', 70, 0), 'The right edge handle drags');
        const now = (await highlightBoxes(page))[0];
        recorder.assert('right-edge-widens', now.width > start.width + 20,
            'Dragging the right edge widens the highlight',
            `${Math.round(start.width)} -> ${Math.round(now.width)}`);
        start = now;
    } else {
        recorder.skip('right-edge-dragged', 'No right edge handle on a highlight');
        recorder.skip('right-edge-widens', 'No right edge handle on a highlight');
    }

    // Moving by the body.
    await page.mouse.move(start.left + (start.width / 2), start.top + (start.height / 2));
    await page.mouse.down();
    for (let step = 1; step <= 8; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(
            start.left + (start.width / 2) + ((70 * step) / 8),
            start.top + (start.height / 2) + ((35 * step) / 8),
        );
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(30);
    }
    await page.mouse.up();
    await page.waitForTimeout(600);

    const moved = (await highlightBoxes(page))[0];
    recorder.assert('body-drag-moves', Math.abs(moved.left - start.left) > 20,
        'Dragging the body moves the highlight',
        `left ${Math.round(start.left)} -> ${Math.round(moved.left)}`);
    recorder.near('move-keeps-size', moved.width, start.width, 3, 'Moving does not change the size');

    await saveDocument(page, saveRecorder);
    const saved = savedHighlightAnnotations(saveRecorder);
    recorder.equals('still-one', saved.length, 1, 'No gesture duplicated the highlight');
    recorder.assert('geometry-saved', Number(saved[0]?.pdfWidth) > 0 && Number(saved[0]?.pdfHeight) > 0,
        'The new geometry is saved', `${saved[0]?.pdfWidth} x ${saved[0]?.pdfHeight}`);

    artifacts.push(await capture(page, '14-move-and-resize', 'after-gestures'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — Undo and redo across the highlight operations. */
async function testUndoRedo(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await dragHighlight(page, { fx: 0.25, fy: 0.35 }, { dx: 180, dy: 45 });
    recorder.equals('one-after-first', await highlightBoxCount(page), 1, 'A highlight is committed');

    await clickHistory(page, 'undo');
    recorder.equals('undo-removes', await highlightBoxCount(page), 0, 'Undo removes it');
    await clickHistory(page, 'redo');
    recorder.equals('redo-restores', await highlightBoxCount(page), 1, 'Redo brings it back');

    await setHighlightMode(page, true);
    await dragHighlight(page, { fx: 0.25, fy: 0.55 }, { dx: 180, dy: 45 });
    recorder.equals('two-after-second', await highlightBoxCount(page), 2, 'A second highlight is committed');
    await clickHistory(page, 'undo');
    recorder.equals('undo-is-one-step', await highlightBoxCount(page), 1,
        'Undo unwinds one highlight, not the whole document');
    await clickHistory(page, 'redo');
    recorder.equals('redo-is-one-step', await highlightBoxCount(page), 2, 'Redo replays one step');

    // A restyle is its own history entry. Edit mode, not highlight mode: with
    // the tool on, the click starts a new highlight instead of selecting one,
    // and the style change would silently go to the global default.
    await ensureHighlightSelected(page, 0);
    await saveDocument(page, saveRecorder);
    const before = String(savedHighlightAnnotations(saveRecorder)[0]?.fillColor || '').toLowerCase();

    await setHighlightStyle(page, { swatch: '#f9a8d4' });
    await page.waitForTimeout(500);
    await saveDocument(page, saveRecorder);
    const restyled = String(savedHighlightAnnotations(saveRecorder)[0]?.fillColor || '').toLowerCase();
    recorder.assert('restyle-changed-it', restyled === '#f9a8d4' && restyled !== before,
        'Restyling changes the stored colour', `${before} -> ${restyled}`);

    await clickHistory(page, 'undo');
    recorder.equals('undo-keeps-both', await highlightBoxCount(page), 2,
        'Undoing a restyle does not delete the highlight');
    await saveDocument(page, saveRecorder);
    recorder.equals('undo-reverts-style',
        String(savedHighlightAnnotations(saveRecorder)[0]?.fillColor || '').toLowerCase(), before,
        'Undo puts the previous colour back');

    artifacts.push(await capture(page, '15-undo-redo', 'after-history'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 16 — Autosave and explicit Save. */
async function testAutosaveAndSave(page, { recorder, saveRecorder }) {
    const artifacts = [];

    const before = saveRecorder.saves.length;
    await setHighlightMode(page, true);
    await dragHighlight(page, EMPTY_AREA, { dx: 180, dy: 45 });

    const deadline = Date.now() + 12000;
    while (saveRecorder.saves.length === before && Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(250);
    }
    recorder.assert('autosaves', saveRecorder.saves.length > before,
        'A committed highlight autosaves without the Save control being touched',
        `${saveRecorder.saves.length - before} save(s)`);

    const autosaved = savedHighlightAnnotations(saveRecorder);
    recorder.equals('autosave-carries-it', autosaved.length, 1,
        'The autosave carries the highlight, not an empty payload');

    await saveDocument(page, saveRecorder);
    const explicit = savedHighlightAnnotations(saveRecorder);
    recorder.equals('no-duplicate', explicit.length, 1,
        'An explicit Save writes the same highlight rather than a second copy');
    recorder.equals('same-id', String(explicit[0]?.id || ''), String(autosaved[0]?.id || ''),
        'The highlight keeps its identity between the autosave and the explicit save');

    artifacts.push(await capture(page, '16-autosave-and-save', 'saved'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 17 — Reload fidelity. */
async function testReloadFidelity(page, { recorder, saveRecorder }) {
    const artifacts = [];

    const wanted = [
        { swatch: '#facc15', opacityPercent: 20, fy: 0.3 },
        { swatch: '#7dd3fc', opacityPercent: 55, fy: 0.5 },
        { swatch: '#c4b5fd', opacityPercent: 80, fy: 0.7 },
    ];

    await setHighlightMode(page, true);
    for (const style of wanted) {
        // eslint-disable-next-line no-await-in-loop
        await setHighlightStyle(page, style);
        // eslint-disable-next-line no-await-in-loop
        await dragHighlight(page, { fx: 0.25, fy: style.fy }, { dx: 180, dy: 40 });
    }
    recorder.equals('three-made', await highlightBoxCount(page), 3, 'Three highlights exist');

    await saveDocument(page, saveRecorder);
    const before = savedHighlightAnnotations(saveRecorder);
    recorder.equals('three-saved', before.length, 3, 'All three are saved');

    await saveAndReload(page, saveRecorder);
    recorder.equals('three-after-reload', await highlightBoxCount(page), 3,
        'All three come back after a reload');

    await saveDocument(page, saveRecorder);
    const after = savedHighlightAnnotations(saveRecorder);

    const byId = new Map(before.map((annotation) => [String(annotation.id), annotation]));
    const drift = [];
    let compared = 0;
    for (const annotation of after) {
        const original = byId.get(String(annotation.id));
        if (!original) continue;
        compared += 1;
        if (String(original.fillColor).toLowerCase() !== String(annotation.fillColor).toLowerCase()) {
            drift.push(`fillColor: ${original.fillColor} -> ${annotation.fillColor}`);
        }
        if (Math.abs(Number(original.fillOpacity) - Number(annotation.fillOpacity)) > 0.001) {
            drift.push(`fillOpacity: ${original.fillOpacity} -> ${annotation.fillOpacity}`);
        }
        for (const field of ['pdfX', 'pdfY', 'pdfWidth', 'pdfHeight']) {
            if (Math.abs(Number(original[field]) - Number(annotation[field])) > 0.75) {
                drift.push(`${field}: ${original[field]} -> ${annotation[field]}`);
            }
        }
        if (String(original.shapeType) !== String(annotation.shapeType)) {
            drift.push(`shapeType: ${original.shapeType} -> ${annotation.shapeType}`);
        }
    }

    recorder.equals('all-matched-by-id', compared, 3,
        'Every highlight keeps its identity across the reload');
    recorder.assert('nothing-drifted', drift.length === 0,
        'Colour, opacity, shape type and geometry all survive the round trip',
        drift.slice(0, 6).join(' | '));

    artifacts.push(await capture(page, '17-reload-fidelity', 'after-reload'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * Ask PyMuPDF what a PDF actually contains: the filled rectangles a highlight
 * exports as, and the text that has to survive underneath them.
 *
 * Unlike a drawing, a highlight is not exported as an image -- it is a vector
 * fill -- so the Draw suite's imagesInPdf() would find nothing here.
 */
function highlightsInPdf(filePath) {
    const candidates = [
        path.resolve(__dirname, '..', '..', '..', '.venv', 'bin', 'python'),
        path.resolve(__dirname, '..', '..', '..', 'python', 'venv', 'bin', 'python'),
        'python3',
    ];
    const program = [
        'import json,sys',
        'import fitz',
        'doc = fitz.open(sys.argv[1])',
        'page = doc[0]',
        'fills = []',
        'for drawing in page.get_drawings():',
        '    fill = drawing.get("fill")',
        '    if not fill:',
        '        continue',
        '    rect = drawing.get("rect")',
        '    fills.append({',
        '        "fill": [round(component, 3) for component in fill],',
        '        "opacity": drawing.get("fill_opacity"),',
        '        "width": round(rect.width, 2),',
        '        "height": round(rect.height, 2),',
        '        "x": round(rect.x0, 2),',
        '        "y": round(rect.y0, 2),',
        '    })',
        'print(json.dumps({"fills": fills, "text": page.get_text().strip()}))',
    ].join('\n');

    for (const python of candidates) {
        try {
            const stdout = require('child_process').execFileSync(
                python,
                ['-c', program, filePath],
                { encoding: 'utf8', timeout: 60000, stdio: ['ignore', 'pipe', 'pipe'] },
            );
            return { ok: true, ...JSON.parse(stdout.trim()) };
        } catch (error) {
            if (python === candidates[candidates.length - 1]) {
                return { ok: false, error: String(error.message || error).slice(0, 200) };
            }
        }
    }
    return { ok: false, error: 'no python with fitz' };
}

/**
 * 18 — Downloaded PDF fidelity.
 *
 * A highlight has one job in the exported file: tint the page without
 * destroying what is under it. So the case checks the fill is there, that it is
 * the colour and roughly the opacity that was chosen, and that the text still
 * extracts.
 */
async function testDownloadFidelity(page, { recorder, saveRecorder, docId }) {
    const artifacts = [];

    const textBefore = await page.evaluate(() => {
        const spans = document.querySelectorAll('#viewer .page[data-page-number="1"] .textLayer span');
        return Array.from(spans).map((el) => el.textContent).join(' ').trim();
    });

    await setHighlightMode(page, true);
    await setHighlightStyle(page, { swatch: '#7dd3fc', opacityPercent: 50 });
    await dragHighlight(page, EMPTY_AREA, { dx: 220, dy: 60 });
    recorder.equals('one-made', await highlightBoxCount(page), 1, 'A highlight exists to export');

    await saveDocument(page, saveRecorder);
    const download = await downloadAnnotatedPdf(
        page,
        docId,
        '18-download-fidelity',
        lastSavedAnnotations(saveRecorder),
    );
    recorder.assert('download-succeeds', download.ok, 'The annotated PDF downloads',
        download.ok ? `${download.bytes} bytes` : `status=${download.status} ${download.body}`);

    if (!download.ok) {
        artifacts.push(await capture(page, '18-download-fidelity', 'download-failed'));
        return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
    }
    recorder.assert('download-is-a-pdf', download.bytes > 1000,
        'The download is a real PDF rather than an error page', `${download.bytes} bytes`);

    const inspected = highlightsInPdf(download.filePath);
    if (!inspected.ok) {
        recorder.skip('fill-in-pdf', 'No python with fitz available to inspect the PDF', inspected.error);
        artifacts.push(await capture(page, '18-download-fidelity', 'not-inspected'));
        return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
    }

    const fills = inspected.fills || [];
    recorder.assert('highlight-reaches-the-pdf', fills.length >= 1,
        'The highlight is written into the exported PDF as a filled shape',
        `${fills.length} filled shapes`);

    // #7dd3fc is a light blue: blue well above red, and all three components high.
    const blueish = fills.find((f) => Array.isArray(f.fill) && f.fill.length === 3
        && f.fill[2] > f.fill[0] && f.fill[2] > 0.8);
    recorder.assert('fill-is-the-chosen-colour', !!blueish,
        'The exported fill is the light blue that was chosen, not a default',
        JSON.stringify(fills.slice(0, 3)));

    if (blueish) {
        recorder.assert('fill-has-area', blueish.width > 20 && blueish.height > 10,
            'The exported highlight has real area',
            `${blueish.width}x${blueish.height}`);
        if (blueish.opacity != null) {
            recorder.near('fill-opacity-exported', Number(blueish.opacity), 0.5, 0.12,
                'The exported fill carries roughly the chosen opacity');
        } else {
            recorder.skip('fill-opacity-exported', 'PyMuPDF reported no fill opacity for this shape');
        }
    }

    // The whole point of a highlight is that the text stays readable.
    const words = textBefore.split(/\s+/).filter((word) => word.length > 3).slice(0, 3);
    recorder.assert('text-survives',
        words.length > 0 && words.every((word) => inspected.text.includes(word)),
        'The text under the highlight is still in the exported PDF',
        `looked for ${JSON.stringify(words)} in ${JSON.stringify(inspected.text.slice(0, 80))}`);

    artifacts.push(await capture(page, '18-download-fidelity', 'before-download'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 19 — Highlighting on a rotated page is refused, by design. */
async function testRotatedPages(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await dragHighlight(page, EMPTY_AREA, { dx: 160, dy: 40 });
    await saveDocument(page, saveRecorder);
    recorder.equals('one-before-rotation', await highlightBoxCount(page), 1,
        'A highlight exists before the page is rotated');

    const rotated = await rotatePage(page, 1, 'right');
    recorder.equals('page-rotated', rotated.rotation, 90, 'Page 1 rotates to 90 degrees');
    recorder.assert('rotation-warns', rotated.dialogShown,
        'Rotating warns that editing is unavailable while the page is turned',
        rotated.dialogMessage);
    recorder.equals('highlight-survives-rotation', await highlightBoxCount(page), 1,
        'The highlight made before the rotation is still there');

    await page.evaluate(() => {
        const dialog = document.getElementById('enpv-rotated-edit-dialog');
        if (dialog) dialog.hidden = true;
    });
    await setHighlightMode(page, true);
    await dragHighlight(page, { fx: 0.4, fy: 0.55 }, { dx: 150, dy: 50 });
    recorder.equals('highlight-is-refused', await highlightBoxCount(page), 1,
        'Dragging on a rotated page commits nothing');

    await page.evaluate(() => {
        const dialog = document.getElementById('enpv-rotated-edit-dialog');
        if (dialog) dialog.hidden = true;
    });
    const straightened = await rotatePage(page, 1, 'left');
    recorder.equals('page-straightened', straightened.rotation, 0, 'The page rotates back to 0');

    await setHighlightMode(page, true);
    await dragHighlight(page, { fx: 0.4, fy: 0.55 }, { dx: 150, dy: 50 });
    recorder.equals('works-again', await highlightBoxCount(page), 2,
        'Highlighting works again once the page is straight');

    artifacts.push(await capture(page, '19-rotated-pages', 'after-round-trip'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 20 — Highlighting stays accurate across zoom levels. */
async function testZoomAccuracy(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    await dragHighlight(page, EMPTY_AREA, { dx: 180, dy: 45 });
    await saveDocument(page, saveRecorder);
    const atDefault = savedHighlightAnnotations(saveRecorder)[0];
    recorder.assert('made-at-default-zoom', !!atDefault, 'A highlight exists at the starting zoom');

    const startZoom = await readZoom(page);
    const zoomed = await stepZoom(page, 'in', 2);
    recorder.assert('zoom-changed', zoomed.scale > startZoom.scale,
        'The viewer zooms in', `${startZoom.scale} -> ${zoomed.scale}`);

    await saveDocument(page, saveRecorder);
    const afterZoom = savedHighlightAnnotations(saveRecorder)[0];
    for (const field of ['pdfX', 'pdfY', 'pdfWidth', 'pdfHeight']) {
        recorder.near(`zoom-keeps-${field}`, Number(afterZoom?.[field]), Number(atDefault?.[field]), 0.75,
            `Zooming leaves ${field} alone`);
    }

    const box = (await highlightBoxes(page))[0];
    const layerScale = await page.evaluate((selector) => {
        const target = document.querySelector(selector);
        return Number.parseFloat(target?.parentElement?.dataset?.scale || '') || 0;
    }, HIGHLIGHT_BOX_SELECTOR);
    const expectedWidth = Number(atDefault?.pdfWidth) * layerScale;
    recorder.assert('box-scales-with-zoom',
        layerScale > 0 && Math.abs(box.width - expectedWidth) <= Math.max(6, expectedWidth * 0.08),
        'The rendered highlight scales with the zoom',
        `expected ~${Math.round(expectedWidth)}, got ${Math.round(box.width)}`);

    await setHighlightMode(page, true);
    const drag = await dragHighlight(page, { fx: 0.3, fy: 0.55 }, { dx: 120, dy: 40 });
    const boxes = await highlightBoxes(page);
    recorder.equals('second-committed', boxes.length, 2, 'A highlight made while zoomed commits');
    recorder.assert('lands-under-the-pointer',
        boxes.some((candidate) => Math.abs(candidate.top - drag.start.y) < 90
            && Math.abs(candidate.left - drag.start.x) < 90),
        'It appears where the pointer went',
        `pointer ${Math.round(drag.start.x)},${Math.round(drag.start.y)}`);

    await saveDocument(page, saveRecorder);
    const both = savedHighlightAnnotations(saveRecorder);
    recorder.assert('geometry-is-page-space',
        both.every((a) => Number(a.pdfWidth) > 1 && Number(a.pdfX) >= -1 && Number(a.pdfX) < 612),
        'The highlight made while zoomed stores page geometry, not viewport pixels',
        JSON.stringify(both.map((a) => ({ x: Math.round(a.pdfX), w: Math.round(a.pdfWidth) }))));

    artifacts.push(await capture(page, '20-zoom-accuracy', 'zoomed'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 21 — Multi-select and group restyle. */
async function testMultiSelect(page, { recorder, saveRecorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    for (const fy of [0.3, 0.45, 0.6]) {
        // eslint-disable-next-line no-await-in-loop
        await dragHighlight(page, { fx: 0.25, fy }, { dx: 170, dy: 40 });
    }
    recorder.equals('three-made', await highlightBoxCount(page), 3, 'Three highlights exist');

    await setHighlightMode(page, false);
    await setEditMode(page, true);
    await selectHighlight(page, 0);

    const shiftClick = async (index) => {
        const box = (await highlightBoxes(page))[index];
        if (!box) return false;
        await page.keyboard.down('Shift');
        await page.mouse.click(box.left + (box.width / 2), box.top + (box.height / 2));
        await page.keyboard.up('Shift');
        await page.waitForTimeout(400);
        return true;
    };
    await shiftClick(1);
    await shiftClick(2);

    const multi = await page.evaluate((selector) => ({
        multi: document.querySelectorAll(`${selector}.is-multi-selected`).length,
    }), HIGHLIGHT_BOX_SELECTOR);
    recorder.assert('multi-selected', multi.multi >= 2,
        'Shift-clicking builds a multi-selection across highlights', JSON.stringify(multi));

    if (multi.multi < 2) {
        recorder.skip('group-restyle', 'No multi-selection to operate on');
        recorder.skip('group-delete', 'No multi-selection to operate on');
        artifacts.push(await capture(page, '21-multi-select', 'no-multi'));
        return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
    }

    await setHighlightStyle(page, { swatch: '#86efac' });
    await page.waitForTimeout(500);
    await saveDocument(page, saveRecorder);
    const green = savedHighlightAnnotations(saveRecorder)
        .filter((annotation) => String(annotation.fillColor || '').toLowerCase() === '#86efac').length;
    recorder.assert('group-restyle', green >= 2,
        'A colour change applies to every highlight in the selection',
        `${green} are green`);

    const before = await highlightBoxCount(page);
    await page.keyboard.press('Delete');
    await page.waitForTimeout(700);
    recorder.assert('group-delete', (await highlightBoxCount(page)) < before,
        'Delete removes the whole selection', `${before} -> ${await highlightBoxCount(page)}`);

    artifacts.push(await capture(page, '21-multi-select', 'after-group-ops'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 22 — Keyboard access and ARIA on the Highlight Options panel. */
async function testKeyboardAndAria(page, { recorder }) {
    const artifacts = [];

    await setHighlightMode(page, true);
    const panel = await highlightPanelState(page);
    recorder.equals('aria-hidden-false-when-open', panel.ariaHidden, 'false',
        'The open panel is exposed to assistive technology');

    const controls = await page.evaluate(() => {
        const panelEl = document.getElementById('highlight-tool-panel');
        if (!panelEl) return [];
        return Array.from(panelEl.querySelectorAll('button, input')).map((el) => ({
            id: el.id || '',
            tag: el.tagName.toLowerCase(),
            disabled: !!el.disabled,
            tabIndex: el.tabIndex,
            label: el.getAttribute('aria-label') || el.getAttribute('title') || '',
            hiddenBranch: !!el.closest('[hidden]'),
        }));
    });
    const reachable = controls.filter((control) => !control.hiddenBranch && !control.disabled);
    recorder.assert('controls-found', reachable.length > 0, 'The panel exposes controls',
        `${reachable.length} of ${controls.length}`);
    recorder.assert('in-tab-order', reachable.every((control) => control.tabIndex >= 0),
        'No reachable control is taken out of the tab order',
        reachable.filter((c) => c.tabIndex < 0).map((c) => c.id).join(','));
    recorder.assert('labelled', reachable.every((control) => control.label.length > 0),
        'Every reachable control carries a label',
        reachable.filter((c) => !c.label).map((c) => `${c.tag}#${c.id}`).join(','));

    const stepped = await page.evaluate(() => {
        const input = document.getElementById('highlight-tool-opacity');
        if (!input) return null;
        input.focus();
        return { focused: document.activeElement === input, before: Number(input.value) };
    });
    recorder.assert('slider-focusable', !!stepped?.focused, 'The opacity slider takes keyboard focus');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(250);
    const afterArrow = await highlightPanelState(page);
    recorder.assert('arrow-moves-slider', afterArrow.opacity.value > (stepped?.before ?? 0),
        'An arrow key changes the slider and the readout follows',
        `${stepped?.before} -> ${afterArrow.opacity.value} (${afterArrow.opacity.readout})`);

    // Focus has to be VISIBLE. Checked after real Tab navigation, since
    // :focus-visible does not match a programmatic focus that follows a click.
    const ringFor = async (selector) => {
        await page.evaluate((target) => {
            const el = document.querySelector(target);
            const previous = el?.previousElementSibling;
            if (previous instanceof HTMLElement && previous.tabIndex >= 0) previous.focus();
            else if (el instanceof HTMLElement) el.focus();
        }, selector);
        await page.keyboard.press('Tab');
        await page.waitForTimeout(200);
        return page.evaluate((target) => {
            const el = document.querySelector(target);
            if (!el) return null;
            if (document.activeElement !== el && el instanceof HTMLElement) el.focus();
            const style = window.getComputedStyle(el);
            return {
                matchesFocusVisible: typeof el.matches === 'function' && el.matches(':focus-visible'),
                outlineStyle: style.outlineStyle,
                outlineWidth: style.outlineWidth,
                boxShadow: style.boxShadow,
            };
        }, selector);
    };

    for (const [selector, label] of [
        ['[data-highlight-color="#fb923c"]', 'a colour swatch'],
        ['#highlight-tool-close', 'the close button'],
    ]) {
        // eslint-disable-next-line no-await-in-loop
        const ring = await ringFor(selector);
        const visible = !!ring && ring.matchesFocusVisible && (
            (ring.outlineStyle !== 'none' && Number.parseFloat(ring.outlineWidth) > 0)
            || (ring.boxShadow && ring.boxShadow !== 'none')
        );
        recorder.assert(`focus-visible-${selector.replace(/[^a-z0-9]+/gi, '-')}`, visible,
            `Keyboard focus on ${label} shows a visible ring rather than the hover treatment`,
            JSON.stringify(ring));
    }

    await setHighlightMode(page, false);
    const closed = await highlightPanelState(page);
    recorder.equals('aria-hidden-true-when-closed', closed.ariaHidden, 'true',
        'The closed panel is hidden from assistive technology again');

    artifacts.push(await capture(page, '22-keyboard-and-aria', 'panel-focus'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Highlight tool available', run: testBlankProjectLoads },
    { id: '02-tool-exclusivity', number: '02', title: 'Highlight toggles and is exclusive with the other tools', run: testToolExclusivity, signedIn: true },
    { id: '03-area-highlight', number: '03', title: 'Dragging over empty page area commits an area highlight', run: testAreaHighlight, signedIn: true },
    { id: '04-drag-over-text', number: '04', title: 'Dragging across text snaps to the text rather than the dragged box', run: testDragOverTextSnaps, signedIn: true },
    { id: '05-text-selection', number: '05', title: 'Selecting text commits a highlight over it', run: testTextSelectionHighlight, signedIn: true },
    { id: '06-live-preview', number: '06', title: 'A live preview paints during the drag and commits on release', run: testLivePreview, signedIn: true },
    { id: '07-tiny-drags', number: '07', title: 'Drags too small to be meant commit nothing', run: testTinyDrags, signedIn: true },
    { id: '08-colour', number: '08', title: 'Colour, from both the swatches and the custom picker', run: testColour, signedIn: true },
    { id: '09-opacity', number: '09', title: 'Opacity applies to the highlight', run: testOpacity, signedIn: true },
    { id: '10-defaults', number: '10', title: 'A fresh highlight uses the documented defaults', run: testDefaults, signedIn: true },
    { id: '11-style-persists', number: '11', title: 'Style choices persist from one highlight to the next', run: testStylePersists, signedIn: true },
    { id: '12-restyle-selected', number: '12', title: 'Selecting a highlight reflects its style, and restyling applies in place', run: testRestyleSelected, signedIn: true },
    { id: '13-select-and-menu', number: '13', title: 'Select, deselect and the highlight hover menu', run: testSelectAndMenu, signedIn: true },
    { id: '14-move-and-resize', number: '14', title: 'Move and resize a highlight', run: testMoveAndResize, signedIn: true },
    { id: '15-undo-redo', number: '15', title: 'Undo and redo across the highlight operations', run: testUndoRedo, signedIn: true },
    { id: '16-autosave-and-save', number: '16', title: 'Autosave and explicit Save', run: testAutosaveAndSave, signedIn: true },
    { id: '17-reload-fidelity', number: '17', title: 'Reload fidelity', run: testReloadFidelity, signedIn: true },
    { id: '18-download-fidelity', number: '18', title: 'Downloaded PDF fidelity', run: testDownloadFidelity, signedIn: true },
    { id: '19-rotated-pages', number: '19', title: 'Highlighting on a rotated page is refused, by design', run: testRotatedPages, signedIn: true },
    { id: '20-zoom-accuracy', number: '20', title: 'Highlighting stays accurate across zoom levels', run: testZoomAccuracy, signedIn: true },
    { id: '21-multi-select', number: '21', title: 'Multi-select and group restyle', run: testMultiSelect, signedIn: true },
    { id: '22-keyboard-and-aria', number: '22', title: 'Keyboard access and ARIA on the Highlight Options panel', run: testKeyboardAndAria, signedIn: true },
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
                docId = await createBlankDocument(page, csrfToken, `Highlight tool test ${test.number}`, test.pages || 1);
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
    HIGHLIGHT_BOX_SELECTOR,
    HIGHLIGHT_DEFAULTS,
    HIGHLIGHT_RANGES,
    HIGHLIGHT_SWATCHES,
    TEXT_LINE,
    EMPTY_AREA,
    buildBlankPdfBuffer,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    openEditorAsSignedIn,
    pointOnPage,
    highlightModeOn,
    setHighlightMode,
    highlightToolControls,
    highlightPanelState,
    setHighlightStyle,
    setRange,
    highlightBoxes,
    highlightBoxCount,
    highlightPreviewState,
    dragHighlight,
    selectPageText,
    attachSaveRecorder,
    savedHighlightAnnotations,
    lastSavedAnnotations,
    saveDocument,
    saveAndReload,
    saveAnnotationsDirectly,
    reloadEditor,
    downloadAnnotatedPdf,
    imagesInPdf,
    highlightsInPdf,
    readZoom,
    stepZoom,
    selectHighlight,
    ensureHighlightSelected,
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
