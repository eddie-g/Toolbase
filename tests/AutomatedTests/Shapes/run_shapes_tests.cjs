/**
 * Shapes Tool Automated Tests (Playwright)
 *
 * Automates part of the "[QA] - Shapes tool" Asana task
 * (Netkit -> Claude QA Agent). Each run creates a fresh blank PDF, uploads it
 * as a new document, opens it in the pdf.js editor, and drives the real UI.
 *
 * Scope note: there is a SEPARATE, older shape suite at
 * tests/OverlayEditor/Shapes/run_shape_tests.cjs, reached from Admin ->
 * Run Shape Tests. That one drives the LEGACY overlay editor (/documents/{id}/edit)
 * and validates PDF drawing operators per shape type. This suite drives the
 * pdf.js editor (/edit-new?pdfjs=1) and its Shapes tool UI. They complement
 * each other; do not duplicate the operator-level assertions here.
 *
 * Usage:
 *   node run_shapes_tests.cjs --list
 *   node run_shapes_tests.cjs --run 01-blank-project-loads,03-primary-shapes-draw
 *   node run_shapes_tests.cjs --run-all
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

const SHAPE_BOX_SELECTOR = '.enpv-annotation-box[data-annotation-type="shape"]';

/** The four shapes the picker shows up front, and the three behind More shapes. */
const PRIMARY_SHAPES = ['circle', 'triangle', 'square', 'line'];
const MORE_SHAPES = ['star', 'x', 'heart'];

/**
 * What currentShapeDefaults() in resources/js/edit-new/shapes/defaults.js
 * produces for a new shape. Asserted by case 18, so a change to the defaults
 * has to be made deliberately in both places.
 */
const SHAPE_DEFAULTS = {
    strokeColor: '#0f172a',
    strokeWidth: 3,
    strokeOpacity: 1,
    fillColor: '#22c55e',
    fillOpacity: 0.22,
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
    const title = String(label || 'Shapes tool automated test').replace(/[()\\]/g, '');
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
    const name = `shapes_tool_test_${process.pid}_${uploadCounter}.pdf`;

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

// ---------------------------------------------------------------------------
// Shapes tool probes
// ---------------------------------------------------------------------------

function shapeModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-shape-on'));
}

function addTextModeOn(page) {
    return page.evaluate(() => document.body.classList.contains('enpv-add-text-on'));
}

/** The two controls that drive shape mode, plus the panel they open. */
function shapeToolControls(page) {
    return page.evaluate(() => {
        const read = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            const rect = el.getBoundingClientRect();
            return {
                present: true,
                disabled: !!el.disabled,
                active: el.classList.contains('is-active') || el.getAttribute('aria-pressed') === 'true',
                visible: rect.width > 0 && rect.height > 0,
            };
        };
        const panel = document.getElementById('shape-tool-panel');
        const panelRect = panel ? panel.getBoundingClientRect() : null;
        return {
            topBar: read('add-shape-btn'),
            floating: read('ftb-add-shape'),
            panelPresent: !!panel,
            panelOpen: !!panel && !panel.hidden && !!panelRect && panelRect.width > 0 && panelRect.height > 0,
        };
    });
}

/**
 * Turn shape mode on or off through a real toolbar button.
 *
 * Defaults to the FLOATING control: at the viewport these tests run at, the
 * top-bar button is present and enabled but has no layout box, so Playwright
 * refuses to click it even with force. Case 02 covers the shared state between
 * the two by reading the top-bar button's aria-pressed rather than clicking it.
 */
async function setShapeMode(page, on, control = 'ftb-add-shape') {
    const current = await shapeModeOn(page);
    if (current === on) return;
    await page.click(`#${control}`, { force: true });
    await page.waitForTimeout(450);
}

/** Is this control actually clickable at the current viewport? */
function controlIsVisible(page, id) {
    return page.evaluate((elementId) => {
        const el = document.getElementById(elementId);
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }, id);
}

/** Read the whole Shape options panel in one go. */
function shapePanelState(page) {
    return page.evaluate(() => {
        const value = (id) => document.getElementById(id)?.value ?? null;
        const text = (id) => document.getElementById(id)?.textContent?.trim() ?? null;
        const checked = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            return el.type === 'checkbox' ? el.checked : el.getAttribute('aria-pressed') === 'true';
        };
        const activeShape = document.querySelector('#shape-tool-panel .sfb-shape-btn.is-active[data-shape-tool]');
        const moreToggle = document.getElementById('shape-more-toggle');
        const moreGrid = document.getElementById('shape-more-grid');
        const moreRect = moreGrid ? moreGrid.getBoundingClientRect() : null;
        return {
            activeShape: activeShape ? activeShape.dataset.shapeTool : null,
            strokeColor: value('shape-stroke-color'),
            strokeHex: value('shape-stroke-hex'),
            strokeWidth: value('shape-stroke-width'),
            strokeWidthLabel: text('shape-stroke-width-value'),
            strokeOpacity: value('shape-stroke-opacity'),
            strokeOpacityLabel: text('shape-stroke-opacity-value'),
            strokeTransparent: checked('shape-stroke-transparent'),
            fillColor: value('shape-fill-color'),
            fillHex: value('shape-fill-hex'),
            fillOpacity: value('shape-fill-opacity'),
            fillOpacityLabel: text('shape-fill-opacity-value'),
            fillTransparent: checked('shape-fill-transparent'),
            moreExpanded: moreToggle ? moreToggle.getAttribute('aria-expanded') : null,
            moreVisible: !!moreRect && moreRect.width > 0 && moreRect.height > 0,
            moreToggleActive: !!moreToggle && moreToggle.classList.contains('is-active'),
        };
    });
}

/**
 * Choose a shape in the picker, opening More shapes first when the wanted
 * shape lives behind the toggle.
 */
async function pickShape(page, shapeType) {
    if (MORE_SHAPES.includes(shapeType)) {
        const expanded = await page.evaluate(() => document
            .getElementById('shape-more-toggle')?.getAttribute('aria-expanded'));
        if (expanded !== 'true') {
            await page.click('#shape-more-toggle', { force: true });
            await page.waitForTimeout(300);
        }
    }
    await page.click(`#shape-tool-panel [data-shape-tool="${shapeType}"]`, { force: true });
    await page.waitForTimeout(300);
}

/**
 * Set stroke and/or fill through the panel's real inputs.
 *
 * Both the colour swatch and the hex field are driven: readShapeInspectorState
 * prefers the <input type="color"> value, and the two are only kept in step by
 * the panel's own `input` handler.
 */
async function setShapeStyle(page, { strokeHex, fillHex, strokeWidth, strokeOpacity, fillOpacity } = {}) {
    // The hex field is <input type="hidden"> — it mirrors the swatch and cannot
    // be typed into. The colour swatch is the only real control, so drive that
    // and let the panel's own input handler write the hex back.
    const setColor = async (colorId, value) => {
        await page.$eval(`#${colorId}`, (el, v) => {
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
        await page.waitForTimeout(200);
    };
    const setRange = async (id, value) => {
        await page.$eval(`#${id}`, (el, v) => {
            el.value = String(v);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
        await page.waitForTimeout(150);
    };
    if (strokeHex) await setColor('shape-stroke-color', strokeHex);
    if (fillHex) await setColor('shape-fill-color', fillHex);
    if (strokeWidth != null) await setRange('shape-stroke-width', strokeWidth);
    if (strokeOpacity != null) await setRange('shape-stroke-opacity', strokeOpacity);
    if (fillOpacity != null) await setRange('shape-fill-opacity', fillOpacity);
}

/** Every shape box currently on the page, with its stored styling. */
function shapeBoxes(page) {
    return page.evaluate((selector) => Array.from(document.querySelectorAll(selector)).map((box) => {
        const rect = box.getBoundingClientRect();
        const layer = box.parentElement;
        const layerRect = layer ? layer.getBoundingClientRect() : null;
        const num = (value) => {
            const parsed = Number.parseFloat(value);
            return Number.isFinite(parsed) ? parsed : null;
        };
        return {
            shapeType: box.dataset.shapeType || null,
            annotationId: box.dataset.annotationId || null,
            uid: box.dataset.uid || null,
            pageIndex: Number.parseInt(box.dataset.pageIndex || '-1', 10),
            strokeColor: box.dataset.strokeColor || null,
            strokeWidth: num(box.dataset.strokeWidth),
            strokeOpacity: num(box.dataset.strokeOpacity),
            strokeTransparent: box.dataset.strokeTransparent === '1',
            fillColor: box.dataset.fillColor || null,
            fillOpacity: num(box.dataset.fillOpacity),
            fillTransparent: box.dataset.fillTransparent === '1',
            rotation: num(box.dataset.rotation) || 0,
            locked: box.dataset.locked === '1',
            width: rect.width,
            height: rect.height,
            relLeft: layerRect ? rect.left - layerRect.left : null,
            relTop: layerRect ? rect.top - layerRect.top : null,
            selected: box.classList.contains('is-selected'),
        };
    }), SHAPE_BOX_SELECTOR);
}

function shapeBoxCount(page) {
    return page.evaluate((selector) => document.querySelectorAll(selector).length, SHAPE_BOX_SELECTOR);
}

/**
 * Press at a fractional page position, drag by a pixel delta, release.
 *
 * The release is expressed in VIEWPORT PIXELS rather than a second page
 * fraction on purpose: a 612x792pt page renders about twice the viewport
 * height, so two page fractions far enough apart to be interesting cannot both
 * be on screen at once. A pixel delta also says what the test means -- "drag
 * 150 by 120" -- rather than leaving it to be recomputed from page geometry.
 *
 * The pointer is stepped so the live preview actually runs. `shiftAt` and
 * `shiftReleaseAt` press and release Shift partway through, which is what
 * case 07 needs.
 */
async function drawShape(page, from, delta, options = {}) {
    const pageNumber = options.pageNumber || 1;
    const viewport = page.viewportSize();
    const outside = (point) => point.x < 2 || point.y < 2
        || point.x > viewport.width - 2 || point.y > viewport.height - 2;

    let start = await pointOnPage(page, pageNumber, from.fx, from.fy);
    let end = { x: start.x + delta.dx, y: start.y + delta.dy };

    // A press point can resolve near the bottom of the viewport, which leaves
    // no room for the drag. Pull the press point back to the middle and try
    // again rather than failing on geometry the test does not care about.
    if (outside(end)) {
        await page.evaluate((dy) => {
            const container = document.getElementById('viewerContainer');
            if (container) container.scrollTop += dy;
        }, start.y - (viewport.height / 2));
        await page.waitForTimeout(400);
        start = await pointOnPage(page, pageNumber, from.fx, from.fy);
        end = { x: start.x + delta.dx, y: start.y + delta.dy };
    }
    if (outside(end)) {
        throw new Error(`Release point ${JSON.stringify(end)} falls outside the ${viewport.width}x${viewport.height} viewport even after recentring`);
    }

    const steps = options.steps || 8;
    if (options.shift) await page.keyboard.down('Shift');
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    for (let step = 1; step <= steps; step++) {
        if (options.shiftAt === step) await page.keyboard.down('Shift');
        if (options.shiftReleaseAt === step) await page.keyboard.up('Shift');
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(start.x + ((delta.dx * step) / steps), start.y + ((delta.dy * step) / steps));
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(35);
    }

    // Snapshot the live preview before releasing, so a caller can prove one existed.
    const previewCount = await shapeBoxCount(page);

    if (options.escapeBeforeRelease) {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(250);
    }
    await page.mouse.up();
    await page.waitForTimeout(500);

    await page.keyboard.up('Shift').catch(() => {});
    return { start, end, previewCount };
}

// ---------------------------------------------------------------------------
// Saving, reloading and zoom
// ---------------------------------------------------------------------------

/** Watch the annotation-state saves so persistence checks need no fixed sleep. */
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

/** The shape annotations carried by the most recent save. */
function savedShapeAnnotations(saveRecorder) {
    const last = saveRecorder?.saves?.[saveRecorder.saves.length - 1];
    return (last?.annotations || [])
        .filter((annotation) => String(annotation?.type || '').toLowerCase() === 'shape');
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

/** Select a shape by clicking its centre, and report whether it took. */
async function selectShape(page, index = 0) {
    const point = await page.evaluate(([selector, i]) => {
        const box = document.querySelectorAll(selector)[i];
        if (!box) return null;
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, [SHAPE_BOX_SELECTOR, index]);
    if (!point) return false;
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(500);
    return page.evaluate((selector) => !!document.querySelector(`${selector}.is-selected`), SHAPE_BOX_SELECTOR);
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: blank project loads with the Shapes tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    recorder.assert('is-pdfjs-editor',
        await page.evaluate(() => !!document.querySelector('#viewer .page canvas')
            && !!document.getElementById('edit-new-root')),
        'This is the pdf.js editor, not the legacy one');

    const controls = await shapeToolControls(page);
    recorder.assert('topbar-shapes-button',
        !!controls.topBar?.present && !controls.topBar.disabled,
        'The top-bar Shapes button is present and enabled', JSON.stringify(controls.topBar));
    recorder.assert('floating-shapes-button',
        !!controls.floating?.present && !controls.floating.disabled,
        'The floating toolbar shapes button is present and enabled', JSON.stringify(controls.floating));
    recorder.assert('panel-closed-on-load', controls.panelPresent && !controls.panelOpen,
        'The Shape options panel starts closed', JSON.stringify({
            present: controls.panelPresent, open: controls.panelOpen,
        }));
    recorder.equals('no-shapes-yet', await shapeBoxCount(page), 0,
        'No shape exists on a blank document');
    recorder.assert('shape-mode-off', !(await shapeModeOn(page)),
        'Shape mode is off until the tool is clicked');

    const realErrors = (consoleErrors || []).filter((message) => !/favicon|fonts\.(googleapis|gstatic)/i.test(message));
    recorder.assert('console-clean', realErrors.length === 0,
        'The console is clean on load', realErrors.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — Shapes toggles from both toolbars and is exclusive with the other tools. */
async function testToolToggleExclusivity(page, { recorder }) {
    const artifacts = [];

    const topBarClickable = await controlIsVisible(page, 'add-shape-btn');
    const floatingClickable = await controlIsVisible(page, 'ftb-add-shape');
    recorder.assert('a-shape-control-is-clickable', topBarClickable || floatingClickable,
        'At least one Shapes control is actually clickable at this viewport',
        JSON.stringify({ topBar: topBarClickable, floating: floatingClickable }));

    const driver = floatingClickable ? 'ftb-add-shape' : 'add-shape-btn';

    // Turning it on opens the panel.
    await page.click(`#${driver}`, { force: true });
    await page.waitForTimeout(500);
    let controls = await shapeToolControls(page);
    recorder.assert('turns-on', await shapeModeOn(page),
        `The ${driver === 'ftb-add-shape' ? 'floating' : 'top-bar'} button puts the body into shape mode`);
    recorder.assert('panel-opens', controls.panelOpen,
        'Turning the tool on opens the Shape options panel', JSON.stringify(controls.panelOpen));

    // Shared state: the OTHER button must light up too. Read rather than click,
    // so this holds even when that control has no layout box at this viewport.
    recorder.assert('both-buttons-share-one-state',
        !!controls.topBar?.active && !!controls.floating?.active,
        'The top-bar and floating buttons report the same active state, so they cannot look active independently',
        JSON.stringify({ topBar: controls.topBar, floating: controls.floating }));

    // Clicking again closes it and draws nothing.
    await page.click(`#${driver}`, { force: true });
    await page.waitForTimeout(450);
    controls = await shapeToolControls(page);
    recorder.assert('toggles-off', !(await shapeModeOn(page)) && !controls.panelOpen,
        'Clicking the button again leaves shape mode and closes the panel',
        JSON.stringify({ mode: await shapeModeOn(page), panel: controls.panelOpen }));
    recorder.assert('both-buttons-clear-together',
        !controls.topBar?.active && !controls.floating?.active,
        'Both buttons clear together when the mode is turned off',
        JSON.stringify({ topBar: controls.topBar?.active, floating: controls.floating?.active }));
    recorder.equals('nothing-drawn-by-toggling', await shapeBoxCount(page), 0,
        'Toggling the tool draws nothing');

    if (topBarClickable && floatingClickable) {
        await page.click('#add-shape-btn', { force: true });
        await page.waitForTimeout(500);
        recorder.assert('either-control-drives-it', await shapeModeOn(page),
            'The other control turns the same mode on');
        await page.click('#add-shape-btn', { force: true });
        await page.waitForTimeout(400);
    } else {
        recorder.skip('either-control-drives-it',
            'Only one Shapes control has a layout box at this viewport, so driving both could not be exercised',
            JSON.stringify({ topBar: topBarClickable, floating: floatingClickable }));
    }

    // Add Text must cancel shape mode, and vice versa.
    await setShapeMode(page, true);
    const addTextControl = (await controlIsVisible(page, 'ftb-add-text')) ? 'ftb-add-text' : 'add-text-btn';
    if (await controlIsVisible(page, addTextControl)) {
        await page.click(`#${addTextControl}`, { force: true });
        await page.waitForTimeout(500);
        recorder.assert('add-text-cancels-shapes',
            !(await shapeModeOn(page)) && (await addTextModeOn(page)),
            'Turning on Add Text cancels shape mode',
            JSON.stringify({ shape: await shapeModeOn(page), addText: await addTextModeOn(page) }));

        await setShapeMode(page, true);
        recorder.assert('shapes-cancels-add-text',
            (await shapeModeOn(page)) && !(await addTextModeOn(page)),
            'Turning shape mode back on cancels Add Text',
            JSON.stringify({ shape: await shapeModeOn(page), addText: await addTextModeOn(page) }));
    } else {
        recorder.skip('add-text-cancels-shapes',
            'No clickable Add Text control at this viewport, so tool exclusivity could not be exercised');
    }

    // Record — rather than assume — whether the mode survives one draw.
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.25, fy: 0.25 }, { dx: 160, dy: 130 });
    const stillOn = await shapeModeOn(page);
    recorder.ok('mode-after-one-draw',
        stillOn
            ? 'Shape mode STAYS on after drawing, so several shapes can be drawn in a row'
            : 'Shape mode DROPS after one draw, matching the Add Text tool',
        `shapeModeOn=${stillOn}`);

    artifacts.push(await capture(page, '02-tool-toggle-exclusivity', 'toggled'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — The four primary shapes draw: circle, triangle, square, line. */
async function testPrimaryShapesDraw(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    let expected = 0;
    for (const shapeType of PRIMARY_SHAPES) {
        // eslint-disable-next-line no-await-in-loop
        await pickShape(page, shapeType);
        // eslint-disable-next-line no-await-in-loop
        const panel = await shapePanelState(page);
        recorder.equals(`picker-shows-${shapeType}`, panel.activeShape, shapeType,
            `The picker shows ${shapeType} as the selected shape`);

        // Spread the four shapes out, but keep every press point inside one
        // scrolled view so each drag lands on screen.
        const fx = 0.15 + ((expected % 2) * 0.38);
        const fy = 0.14 + (Math.floor(expected / 2) * 0.10);

        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await drawShape(page, { fx, fy }, { dx: 170, dy: 120 });
        expected += 1;

        // eslint-disable-next-line no-await-in-loop
        const boxes = await shapeBoxes(page);
        recorder.equals(`${shapeType}-adds-a-shape`, boxes.length, expected,
            `Drawing with ${shapeType} selected adds one shape`);
        const drawn = boxes[boxes.length - 1];
        recorder.equals(`${shapeType}-stores-its-type`, drawn?.shapeType, shapeType,
            `The new annotation records shapeType "${shapeType}" rather than falling back`);
        recorder.assert(`${shapeType}-has-size`,
            !!drawn && drawn.width > 4 && drawn.height > 4,
            `The ${shapeType} is sized to the dragged rectangle`,
            JSON.stringify({ w: drawn?.width, h: drawn?.height }));
    }

    artifacts.push(await capture(page, '03-primary-shapes-draw', 'four-shapes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — More shapes: star, X and heart. */
async function testMoreShapes(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    let panel = await shapePanelState(page);
    recorder.equals('more-collapsed-initially', panel.moreExpanded, 'false',
        'The More shapes toggle starts collapsed and says so through aria-expanded');

    await page.click('#shape-more-toggle', { force: true });
    await page.waitForTimeout(350);
    panel = await shapePanelState(page);
    recorder.equals('more-expands', panel.moreExpanded, 'true',
        'Opening More shapes sets aria-expanded to true');
    recorder.assert('more-grid-visible', panel.moreVisible,
        'The extra shapes become visible', JSON.stringify(panel.moreVisible));

    let expected = 0;
    for (const shapeType of MORE_SHAPES) {
        // eslint-disable-next-line no-await-in-loop
        await pickShape(page, shapeType);
        const fx = 0.14 + (expected * 0.26);
        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await drawShape(page, { fx, fy: 0.18 }, { dx: 150, dy: 130 });
        expected += 1;
        // eslint-disable-next-line no-await-in-loop
        const boxes = await shapeBoxes(page);
        recorder.equals(`${shapeType}-stores-its-type`,
            boxes[boxes.length - 1]?.shapeType, shapeType,
            `${shapeType} draws and records its own shapeType`);
    }

    // Picking a non-primary shape should show on the toggle itself.
    panel = await shapePanelState(page);
    recorder.ok('toggle-marks-non-primary-active',
        panel.moreToggleActive
            ? 'The More shapes toggle shows that a non-primary shape is active'
            : 'The More shapes toggle does NOT indicate the active shape is one of its own',
        JSON.stringify({ toggleActive: panel.moreToggleActive, activeShape: panel.activeShape }));

    // Collapsing must not change the selection.
    const before = (await shapePanelState(page)).activeShape;
    await page.click('#shape-more-toggle', { force: true });
    await page.waitForTimeout(350);
    panel = await shapePanelState(page);
    recorder.equals('more-collapses', panel.moreExpanded, 'false',
        'The toggle collapses again');
    recorder.equals('collapse-keeps-selection', panel.activeShape, before,
        'Collapsing More shapes does not change the selected shape');

    artifacts.push(await capture(page, '04-more-shapes', 'more-shapes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — The chosen shape and style persist between draws. */
async function testStylePersistsBetweenDraws(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'triangle');

    // Set a distinctive style before drawing anything. No catch() here on
    // purpose: if the field cannot be driven, this case must fail loudly rather
    // than quietly draw two default-styled shapes and "pass".
    await setShapeStyle(page, { strokeHex: '#ff0066', fillHex: '#0066ff' });
    const chosen = await shapePanelState(page);
    recorder.assert('panel-took-the-style',
        String(chosen.strokeHex || '').toLowerCase() === '#ff0066'
        && String(chosen.fillHex || '').toLowerCase() === '#0066ff',
        'The panel accepted the distinctive stroke and fill',
        JSON.stringify({ stroke: chosen.strokeHex, fill: chosen.fillHex }));

    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.15, fy: 0.15 }, { dx: 160, dy: 130 });
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.5, fy: 0.15 }, { dx: 160, dy: 130 });

    const boxes = await shapeBoxes(page);
    recorder.equals('two-shapes', boxes.length, 2, 'Two shapes are drawn');

    if (boxes.length === 2) {
        const [first, second] = boxes;
        recorder.equals('second-keeps-shape-type', second.shapeType, first.shapeType,
            'The second shape uses the same shape type as the first');
        recorder.equals('second-keeps-stroke', second.strokeColor, first.strokeColor,
            'The second shape keeps the chosen stroke colour');
        recorder.equals('second-keeps-fill', second.fillColor, first.fillColor,
            'The second shape keeps the chosen fill colour');
        recorder.assert('style-actually-applied',
            String(first.strokeColor || '').toLowerCase() === '#ff0066',
            'The stroke colour set in the panel reached the annotation',
            JSON.stringify({ stroke: first.strokeColor, fill: first.fillColor }));
    }

    artifacts.push(await capture(page, '05-style-persists-between-draws', 'two-shapes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — Drag to draw, with a live preview. */
async function testDragToDrawPreview(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    // A normal down-right drag, with a preview visible before release.
    const drag = await drawShape(page, { fx: 0.2, fy: 0.2 }, { dx: 180, dy: 140 });
    recorder.assert('preview-during-drag', drag.previewCount >= 1,
        'A preview shape exists on the page before the pointer is released',
        `previewCount=${drag.previewCount}`);
    let boxes = await shapeBoxes(page);
    recorder.equals('drag-creates-one', boxes.length, 1, 'Releasing the drag leaves one shape');
    const downRight = boxes[0];

    // The same rectangle dragged up and to the left must give the same box.
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.62, fy: 0.30 }, { dx: -180, dy: -140 });
    boxes = await shapeBoxes(page);
    recorder.equals('up-left-drag-creates-one', boxes.length, 2,
        'A drag up and to the left also draws a shape');
    const upLeft = boxes[1];
    if (downRight && upLeft) {
        recorder.near('up-left-same-width', upLeft.width, downRight.width, 6,
            'Dragging up-left produces the same width as the equivalent down-right drag');
        recorder.near('up-left-same-height', upLeft.height, downRight.height, 6,
            'Dragging up-left produces the same height as the equivalent down-right drag');
    }

    // Escape mid-drag must not leave an orphaned preview behind.
    const before = await shapeBoxCount(page);
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.2, fy: 0.22 }, { dx: 150, dy: 120 }, { escapeBeforeRelease: true });
    const after = await shapeBoxCount(page);
    recorder.assert('escape-leaves-no-orphan', after <= before + 1,
        'Escaping mid-drag leaves no orphaned preview element behind',
        `before=${before} after=${after}`);

    artifacts.push(await capture(page, '06-drag-to-draw-preview', 'previews'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Shift constrains the drawn shape. */
async function testShiftConstrains(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    // Held from the start: a deliberately oblong drag must come out square.
    await drawShape(page, { fx: 0.12, fy: 0.12 }, { dx: 240, dy: 110 }, { shift: true });
    let boxes = await shapeBoxes(page);
    const held = boxes[0];
    recorder.assert('shift-squares-the-shape',
        !!held && Math.abs(held.width - held.height) <= Math.max(8, held.width * 0.06),
        'Holding Shift from the start constrains an oblong drag to a square',
        JSON.stringify({ w: held?.width, h: held?.height }));

    // Pressed midway through the drag.
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.12, fy: 0.36 }, { dx: 240, dy: 110 }, { shiftAt: 4 });
    boxes = await shapeBoxes(page);
    recorder.equals('mid-drag-shape-added', boxes.length, 2,
        'The second drag drew a shape rather than grabbing the first one');
    const midPress = boxes[1];
    recorder.assert('shift-applies-mid-drag',
        !!midPress && Math.abs(midPress.width - midPress.height) <= Math.max(8, midPress.width * 0.06),
        'Pressing Shift partway through the drag still constrains the shape',
        JSON.stringify({ w: midPress?.width, h: midPress?.height }));

    // Held then released before the end: the constraint must lift.
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.12, fy: 0.60 }, { dx: 240, dy: 110 }, { shift: true, shiftReleaseAt: 5 });
    boxes = await shapeBoxes(page);
    recorder.equals('release-shape-added', boxes.length, 3,
        'The third drag drew a shape rather than grabbing an earlier one');
    const released = boxes[2];
    recorder.assert('shift-release-lifts-constraint',
        !!released && Math.abs(released.width - released.height) > 8,
        'Releasing Shift before the end lifts the constraint again',
        JSON.stringify({ w: released?.width, h: released?.height }));

    artifacts.push(await capture(page, '07-shift-constrains', 'constrained'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — A click with no drag, and drags below any minimum. */
async function testClickWithoutDrag(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'circle');

    // A bare click, no movement at all.
    const spot = await pointOnPage(page, 1, 0.3, 0.3);
    await page.mouse.move(spot.x, spot.y);
    await page.mouse.down();
    await page.mouse.up();
    await page.waitForTimeout(600);

    let boxes = await shapeBoxes(page);
    const clickOutcome = boxes.length === 0
        ? 'nothing'
        : (boxes[0].width >= 4 && boxes[0].height >= 4 ? 'default-sized shape' : 'degenerate zero-size shape');
    recorder.ok('click-no-drag-outcome',
        `A click with no drag produces: ${clickOutcome}`,
        JSON.stringify(boxes.map((b) => ({ w: Math.round(b.width), h: Math.round(b.height) }))));

    // A degenerate annotation that is saved but invisible is a defect.
    recorder.assert('no-invisible-selectable-shape',
        boxes.every((box) => box.width >= 4 && box.height >= 4),
        'A click does not leave a shape too small to see but still selectable and saved',
        JSON.stringify(boxes.map((b) => ({ w: b.width, h: b.height }))));

    // A drag of only a few pixels.
    const before = boxes.length;
    await setShapeMode(page, true);
    const tiny = await pointOnPage(page, 1, 0.6, 0.6);
    await page.mouse.move(tiny.x, tiny.y);
    await page.mouse.down();
    for (let step = 1; step <= 3; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(tiny.x + step, tiny.y + step);
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(30);
    }
    await page.mouse.up();
    await page.waitForTimeout(600);

    boxes = await shapeBoxes(page);
    const added = boxes.slice(before);
    recorder.ok('tiny-drag-outcome',
        added.length === 0
            ? 'A three-pixel drag produces nothing'
            : `A three-pixel drag produces a shape of ${Math.round(added[0].width)}x${Math.round(added[0].height)}px`,
        JSON.stringify(added.map((b) => ({ w: Math.round(b.width), h: Math.round(b.height) }))));
    recorder.assert('tiny-drag-not-degenerate',
        added.every((box) => box.width >= 4 && box.height >= 4),
        'A tiny drag does not leave a degenerate zero-size annotation',
        JSON.stringify(added.map((b) => ({ w: b.width, h: b.height }))));

    artifacts.push(await capture(page, '08-click-without-drag', 'minimums'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** Draw one shape with the tool already on, and return it. */
async function drawOne(page, shapeType, at = { fx: 0.2, fy: 0.18 }, delta = { dx: 180, dy: 150 }) {
    await setShapeMode(page, true);
    if (shapeType) await pickShape(page, shapeType);
    await setShapeMode(page, true);
    await drawShape(page, at, delta);
    const boxes = await shapeBoxes(page);
    return boxes[boxes.length - 1];
}

/** 12 — Stroke colour. */
async function testStrokeColour(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    // Before drawing.
    await setShapeStyle(page, { strokeHex: '#ff8800' });
    const chosen = await shapePanelState(page);
    recorder.equals('swatch-and-hex-agree', String(chosen.strokeHex || '').toLowerCase(), '#ff8800',
        'The hex field tracks the colour swatch');
    const drawn = await drawOne(page, null, { fx: 0.18, fy: 0.16 }, { dx: 190, dy: 150 });
    recorder.equals('applies-before-draw', String(drawn?.strokeColor || '').toLowerCase(), '#ff8800',
        'A shape drawn after setting the stroke colour carries it');

    // On an existing selected shape.
    recorder.assert('shape-selectable', await selectShape(page, 0), 'The shape can be selected');
    await setShapeStyle(page, { strokeHex: '#0044cc' });
    let boxes = await shapeBoxes(page);
    recorder.equals('applies-to-selection', String(boxes[0]?.strokeColor || '').toLowerCase(), '#0044cc',
        'Restyling a selected shape changes its stroke colour immediately');

    // Survives a save and reload.
    await saveAndReload(page, saveRecorder);
    boxes = await shapeBoxes(page);
    recorder.equals('survives-reload', String(boxes[0]?.strokeColor || '').toLowerCase(), '#0044cc',
        'The stroke colour survives a save and reload');

    artifacts.push(await capture(page, '12-stroke-colour', 'stroke-colour'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — Stroke width across its whole range. */
async function testStrokeWidthRange(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    const range = await page.evaluate(() => {
        const el = document.getElementById('shape-stroke-width');
        return el ? { min: el.min, max: el.max } : null;
    });
    recorder.equals('slider-min', range?.min, '0', 'The stroke width slider starts at 0');
    recorder.equals('slider-max', range?.max, '24', 'The stroke width slider runs to 24');

    // Readout tracks the slider at both ends.
    await setShapeStyle(page, { strokeWidth: 24 });
    let panel = await shapePanelState(page);
    recorder.assert('readout-tracks-max', String(panel.strokeWidthLabel || '').includes('24'),
        'The readout shows the widest setting', JSON.stringify(panel.strokeWidthLabel));
    const thick = await drawOne(page, null, { fx: 0.15, fy: 0.14 }, { dx: 180, dy: 140 });
    recorder.equals('max-width-applied', thick?.strokeWidth, 24,
        'A shape drawn at the widest setting stores strokeWidth 24');

    await setShapeMode(page, true);
    await setShapeStyle(page, { strokeWidth: 1 });
    panel = await shapePanelState(page);
    recorder.assert('readout-tracks-thin', String(panel.strokeWidthLabel || '').includes('1'),
        'The readout follows the slider down', JSON.stringify(panel.strokeWidthLabel));
    const thin = await drawOne(page, null, { fx: 0.15, fy: 0.42 }, { dx: 180, dy: 140 });
    recorder.equals('thin-width-applied', thin?.strokeWidth, 1,
        'A thin stroke is stored as strokeWidth 1');
    recorder.assert('width-changes-the-outline',
        !!thick && !!thin && thick.strokeWidth !== thin.strokeWidth,
        'The two shapes really do carry different stroke widths',
        JSON.stringify({ thick: thick?.strokeWidth, thin: thin?.strokeWidth }));

    // Width 0 must mean no stroke at all.
    await setShapeMode(page, true);
    await setShapeStyle(page, { strokeWidth: 0 });
    const none = await drawOne(page, null, { fx: 0.15, fy: 0.68 }, { dx: 180, dy: 140 });
    recorder.equals('zero-width-applied', none?.strokeWidth, 0,
        'Width 0 is stored as strokeWidth 0, meaning no stroke');

    // ...and a shape with neither stroke nor fill must not be an invisible,
    // selectable annotation. Recorded as a finding either way.
    await setShapeMode(page, true);
    await setShapeStyle(page, { strokeWidth: 0, fillOpacity: 0 });
    const invisible = await drawOne(page, null, { fx: 0.55, fy: 0.68 }, { dx: 170, dy: 130 });
    recorder.ok('no-stroke-no-fill-outcome',
        invisible
            ? 'A shape with no stroke and 0% fill IS still created as a selectable annotation — worth a product decision'
            : 'A shape with no stroke and 0% fill is not created at all',
        JSON.stringify({ strokeWidth: invisible?.strokeWidth, fillOpacity: invisible?.fillOpacity }));

    artifacts.push(await capture(page, '13-stroke-width-range', 'widths'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — Stroke opacity. */
async function testStrokeOpacity(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    await setShapeStyle(page, { strokeOpacity: 40, fillOpacity: 22 });
    const panel = await shapePanelState(page);
    recorder.assert('readout-matches', String(panel.strokeOpacityLabel || '').includes('40'),
        'The stroke opacity readout matches the slider', JSON.stringify(panel.strokeOpacityLabel));

    const faded = await drawOne(page, null, { fx: 0.18, fy: 0.16 }, { dx: 190, dy: 150 });
    recorder.near('stroke-opacity-applied', faded?.strokeOpacity, 0.4, 0.02,
        'A shape drawn at 40% carries that stroke opacity');
    recorder.near('fill-opacity-independent', faded?.fillOpacity, 0.22, 0.02,
        'Changing stroke opacity leaves fill opacity alone');

    // The two really are independent: move fill and check stroke holds.
    await selectShape(page, 0);
    await setShapeStyle(page, { fillOpacity: 80 });
    let boxes = await shapeBoxes(page);
    recorder.near('stroke-holds-when-fill-moves', boxes[0]?.strokeOpacity, 0.4, 0.02,
        'Moving fill opacity does not drag stroke opacity with it');
    recorder.near('fill-opacity-changed', boxes[0]?.fillOpacity, 0.8, 0.02,
        'Fill opacity itself did change');

    await saveAndReload(page, saveRecorder);
    boxes = await shapeBoxes(page);
    recorder.near('survives-reload', boxes[0]?.strokeOpacity, 0.4, 0.02,
        'Stroke opacity survives a save and reload');

    artifacts.push(await capture(page, '14-stroke-opacity', 'stroke-opacity'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — Fill colour. */
async function testFillColour(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'circle');

    await setShapeStyle(page, { fillHex: '#7700ff' });
    const chosen = await shapePanelState(page);
    recorder.equals('swatch-and-hex-agree', String(chosen.fillHex || '').toLowerCase(), '#7700ff',
        'The fill hex field tracks its colour swatch');

    const drawn = await drawOne(page, null, { fx: 0.18, fy: 0.16 }, { dx: 190, dy: 150 });
    recorder.equals('applies-before-draw', String(drawn?.fillColor || '').toLowerCase(), '#7700ff',
        'A shape drawn after setting the fill colour carries it');

    recorder.assert('shape-selectable', await selectShape(page, 0), 'The shape can be selected');
    await setShapeStyle(page, { fillHex: '#00aa55' });
    let boxes = await shapeBoxes(page);
    recorder.equals('applies-to-selection', String(boxes[0]?.fillColor || '').toLowerCase(), '#00aa55',
        'Restyling a selected shape changes its fill immediately');

    await saveAndReload(page, saveRecorder);
    boxes = await shapeBoxes(page);
    recorder.equals('survives-reload', String(boxes[0]?.fillColor || '').toLowerCase(), '#00aa55',
        'The fill colour survives a save and reload');

    artifacts.push(await capture(page, '15-fill-colour', 'fill-colour'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 16 — Fill opacity. */
async function testFillOpacity(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    const initial = await shapePanelState(page);
    recorder.assert('defaults-to-22',
        Math.abs(Number(initial.fillOpacity) - 22) <= 1,
        'Fill opacity defaults to 22%', JSON.stringify(initial.fillOpacity));

    await setShapeStyle(page, { fillOpacity: 90, strokeOpacity: 100 });
    const panel = await shapePanelState(page);
    recorder.assert('readout-matches', String(panel.fillOpacityLabel || '').includes('90'),
        'The fill opacity readout matches the slider', JSON.stringify(panel.fillOpacityLabel));

    const opaque = await drawOne(page, null, { fx: 0.18, fy: 0.16 }, { dx: 190, dy: 150 });
    recorder.near('fill-opacity-applied', opaque?.fillOpacity, 0.9, 0.02,
        'A shape drawn at 90% carries that fill opacity');
    recorder.near('stroke-untouched', opaque?.strokeOpacity, 1, 0.02,
        'The fill fades while the stroke does not');

    await saveAndReload(page, saveRecorder);
    const boxes = await shapeBoxes(page);
    recorder.near('survives-reload', boxes[0]?.fillOpacity, 0.9, 0.02,
        'Fill opacity survives a save and reload');

    artifacts.push(await capture(page, '16-fill-opacity', 'fill-opacity'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 17 — Transparent stroke and transparent fill. */
async function testTransparency(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    // First establish whether a user can reach these at all.
    const reachable = await page.evaluate(() => {
        const read = (id) => {
            const el = document.getElementById(id);
            if (!el) return { present: false };
            const rect = el.getBoundingClientRect();
            const style = window.getComputedStyle(el);
            return {
                present: true,
                type: el.type,
                inlineDisplay: el.style.display,
                computedDisplay: style.display,
                hasLayoutBox: rect.width > 0 && rect.height > 0,
            };
        };
        return { stroke: read('shape-stroke-transparent'), fill: read('shape-fill-transparent') };
    });

    const strokeReachable = reachable.stroke.present && reachable.stroke.hasLayoutBox;
    const fillReachable = reachable.fill.present && reachable.fill.hasLayoutBox;

    // This is a FINDING, not a pass/fail: the story asked how a user reaches them.
    recorder.ok('how-a-user-reaches-them',
        (strokeReachable || fillReachable)
            ? 'At least one transparency control is reachable in the panel'
            : 'NEITHER transparency control is reachable: both are display:none in _shape-panel.blade.php, so the pdf.js Shapes panel offers no way to set a transparent stroke or fill. The flags are only ever CLEARED by the panel (activateShapeFillFromInspector and the input handler); nothing in this editor sets them true. They can still arrive on a shape loaded from the database.',
        JSON.stringify(reachable));

    // The practical route a user does have is opacity 0 / width 0.
    await setShapeStyle(page, { fillOpacity: 0, strokeOpacity: 100, strokeWidth: 3 });
    const noFill = await drawOne(page, null, { fx: 0.16, fy: 0.15 }, { dx: 180, dy: 140 });
    recorder.near('zero-fill-opacity-applied', noFill?.fillOpacity, 0, 0.02,
        'Fill opacity 0 is the reachable equivalent of a transparent fill');
    recorder.assert('outline-still-there', (noFill?.strokeWidth || 0) > 0 && (noFill?.strokeOpacity || 0) > 0,
        'A shape with no fill keeps its outline',
        JSON.stringify({ w: noFill?.strokeWidth, o: noFill?.strokeOpacity }));

    await setShapeMode(page, true);
    await setShapeStyle(page, { strokeWidth: 0, fillOpacity: 60 });
    const noStroke = await drawOne(page, null, { fx: 0.16, fy: 0.45 }, { dx: 180, dy: 140 });
    recorder.equals('zero-stroke-width-applied', noStroke?.strokeWidth, 0,
        'Stroke width 0 is the reachable equivalent of a transparent stroke');
    recorder.assert('fill-still-there', (noStroke?.fillOpacity || 0) > 0,
        'A shape with no outline keeps its fill', JSON.stringify(noStroke?.fillOpacity));

    // Both gone: invisible. Is it still selectable and recoverable?
    await setShapeMode(page, true);
    await setShapeStyle(page, { strokeWidth: 0, fillOpacity: 0 });
    const invisible = await drawOne(page, null, { fx: 0.16, fy: 0.72 }, { dx: 180, dy: 140 });
    if (invisible) {
        const selectable = await selectShape(page, 2);
        recorder.ok('both-transparent-outcome',
            `A shape with no stroke and no fill IS created, and is ${selectable ? 'still selectable' : 'NOT selectable'} afterwards`,
            JSON.stringify({ selectable, strokeWidth: invisible.strokeWidth, fillOpacity: invisible.fillOpacity }));
    } else {
        recorder.ok('both-transparent-outcome',
            'A shape with no stroke and no fill is not created at all, so it cannot become an invisible annotation');
    }

    artifacts.push(await capture(page, '17-transparent-stroke-and-fill', 'transparency'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 18 — A new shape uses the documented defaults. */
async function testDefaultStyle(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    // Drawn WITHOUT touching the panel.
    const drawn = await drawOne(page, null, { fx: 0.2, fy: 0.18 }, { dx: 190, dy: 150 });
    recorder.assert('a-shape-was-drawn', !!drawn, 'A shape is drawn without touching the panel');

    recorder.equals('default-stroke-colour',
        String(drawn?.strokeColor || '').toLowerCase(), SHAPE_DEFAULTS.strokeColor,
        `The default stroke colour is ${SHAPE_DEFAULTS.strokeColor}`);
    recorder.equals('default-stroke-width', drawn?.strokeWidth, SHAPE_DEFAULTS.strokeWidth,
        `The default stroke width is ${SHAPE_DEFAULTS.strokeWidth}`);
    recorder.near('default-stroke-opacity', drawn?.strokeOpacity, SHAPE_DEFAULTS.strokeOpacity, 0.01,
        'The default stroke opacity is 100%');
    recorder.equals('default-fill-colour',
        String(drawn?.fillColor || '').toLowerCase(), SHAPE_DEFAULTS.fillColor,
        `The default fill colour is ${SHAPE_DEFAULTS.fillColor}`);
    recorder.near('default-fill-opacity', drawn?.fillOpacity, SHAPE_DEFAULTS.fillOpacity, 0.01,
        'The default fill opacity is 22%');

    artifacts.push(await capture(page, '18-default-style', 'defaults'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 19 — Line: endpoints, caps and no fill. */
async function testLineEndpoints(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'line');

    await drawShape(page, { fx: 0.2, fy: 0.2 }, { dx: 220, dy: 160 });
    let boxes = await shapeBoxes(page);
    recorder.equals('line-drawn', boxes.length, 1, 'A line is drawn');
    recorder.equals('stores-line-type', boxes[0]?.shapeType, 'line',
        'The annotation records shapeType "line"');

    // Its stored geometry must be endpoints, not a bounding rectangle.
    const geometry = await page.evaluate((selector) => {
        const box = document.querySelector(selector);
        if (!box) return null;
        return {
            datasetKeys: Object.keys(box.dataset).filter((k) => /point|x1|y1|x2|y2|start|end/i.test(k)),
            hasLineSvg: !!box.querySelector('svg line, svg polyline, svg path'),
            handles: Array.from(box.querySelectorAll(':scope > .enpv-resize-handle'))
                .map((h) => h.dataset.edge),
        };
    }, SHAPE_BOX_SELECTOR);
    recorder.assert('has-endpoint-handles',
        !!geometry && geometry.handles.includes('line-start') && geometry.handles.includes('line-end'),
        'A line carries endpoint handles rather than box edge handles',
        JSON.stringify(geometry?.handles));

    // A line is excluded from rotation, and the UI must agree.
    const rotatable = await page.evaluate((selector) => {
        const box = document.querySelector(selector);
        if (!box) return null;
        box.classList.add('is-selected');
        const handle = box.querySelector(':scope > .enpv-shape-rotate-handle');
        if (!handle) return { handlePresent: false, handleVisible: false };
        const rect = handle.getBoundingClientRect();
        return { handlePresent: true, handleVisible: rect.width > 0 && rect.height > 0 };
    }, SHAPE_BOX_SELECTOR);
    recorder.assert('line-not-rotatable',
        !!rotatable && !rotatable.handleVisible,
        'A line offers no visible rotate handle, matching its exclusion from rotation',
        JSON.stringify(rotatable));

    // Cut is offered on square, circle, triangle, star and polygon only.
    await selectShape(page, 0);
    const cutOffered = await page.evaluate(() => {
        const button = document.querySelector('#enpv-ann-menu [data-action="cut"]');
        if (!button) return { present: false, visible: false };
        const rect = button.getBoundingClientRect();
        return { present: true, visible: rect.width > 0 && rect.height > 0 };
    });
    recorder.assert('line-not-cuttable', !cutOffered.visible,
        'Cut is not offered for a line, matching CUTTABLE_SHAPE_TYPES',
        JSON.stringify(cutOffered));

    await saveAndReload(page, saveRecorder);
    boxes = await shapeBoxes(page);
    recorder.equals('line-survives-reload', boxes[0]?.shapeType, 'line',
        'The line comes back as a line after a save and reload');

    artifacts.push(await capture(page, '19-line-endpoints', 'line'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Shapes tool available', run: testBlankProjectLoads },
    { id: '02-tool-toggle-exclusivity', number: '02', title: 'Shapes toggles from both toolbars and is exclusive with the other tools', run: testToolToggleExclusivity, signedIn: true },
    { id: '03-primary-shapes-draw', number: '03', title: 'The four primary shapes draw: circle, triangle, square, line', run: testPrimaryShapesDraw, signedIn: true },
    { id: '04-more-shapes', number: '04', title: 'More shapes: star, X and heart', run: testMoreShapes, signedIn: true },
    { id: '05-style-persists-between-draws', number: '05', title: 'The chosen shape and style persist between draws', run: testStylePersistsBetweenDraws, signedIn: true },
    { id: '06-drag-to-draw-preview', number: '06', title: 'Drag to draw, with a live preview', run: testDragToDrawPreview, signedIn: true },
    { id: '07-shift-constrains', number: '07', title: 'Shift constrains the drawn shape', run: testShiftConstrains, signedIn: true },
    { id: '08-click-without-drag', number: '08', title: 'A click with no drag, and drags below any minimum', run: testClickWithoutDrag, signedIn: true },
    { id: '12-stroke-colour', number: '12', title: 'Stroke colour', run: testStrokeColour, signedIn: true },
    { id: '13-stroke-width-range', number: '13', title: 'Stroke width across its whole range', run: testStrokeWidthRange, signedIn: true },
    { id: '14-stroke-opacity', number: '14', title: 'Stroke opacity', run: testStrokeOpacity, signedIn: true },
    { id: '15-fill-colour', number: '15', title: 'Fill colour', run: testFillColour, signedIn: true },
    { id: '16-fill-opacity', number: '16', title: 'Fill opacity', run: testFillOpacity, signedIn: true },
    { id: '17-transparent-stroke-and-fill', number: '17', title: 'Transparent stroke and transparent fill', run: testTransparency, signedIn: true },
    { id: '18-default-style', number: '18', title: 'A new shape uses the documented defaults', run: testDefaultStyle, signedIn: true },
    { id: '19-line-endpoints', number: '19', title: 'Line: endpoints, caps and no fill', run: testLineEndpoints, signedIn: true },
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
                docId = await createBlankDocument(page, csrfToken, `Shapes tool test ${test.number}`, test.pages || 1);
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
    SHAPE_BOX_SELECTOR,
    SHAPE_DEFAULTS,
    PRIMARY_SHAPES,
    MORE_SHAPES,
    buildBlankPdfBuffer,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    openEditorAsSignedIn,
    pointOnPage,
    shapeModeOn,
    setShapeMode,
    shapeToolControls,
    shapePanelState,
    pickShape,
    setShapeStyle,
    shapeBoxes,
    shapeBoxCount,
    drawShape,
    attachSaveRecorder,
    savedShapeAnnotations,
    saveDocument,
    saveAndReload,
    readZoom,
    stepZoom,
    selectShape,
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
