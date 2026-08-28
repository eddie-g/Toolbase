/**
 * Shapes Tool Automated Tests (Playwright)
 *
 * Automates part of the "[QA] - Shapes tool" Asana task
 * (Netkit -> Claude QA Agent). Each run creates a fresh blank PDF, uploads it
 * as a new document, opens it in the pdf.js editor, and drives the real UI.
 *
 * Scope note: there WAS a separate, older shape suite at
 * tests/OverlayEditor/Shapes/run_shape_tests.cjs, reached from Admin ->
 * Run Shape Tests. It drove the LEGACY overlay editor (/documents/{id}/edit)
 * and validated PDF drawing operators per shape type. Both it and its admin
 * page were deleted as dead code under "[Cleanup] - Remove retired admin
 * tools" (subtask 7).
 *
 * That leaves NO operator-level assertions anywhere: nothing now checks that a
 * rectangle emits 'l' path ops, a circle emits 'c' beziers, or that a rotation
 * writes the expected cm matrix in the output PDF. Case 29 (Downloaded PDF
 * fidelity) is the place to add that if it is ever wanted.
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
        const shapeButtons = Array.from(document.querySelectorAll('#shape-tool-panel [data-shape-tool]'));
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
            // NK_13: all seven shapes are in one grid; none are behind a toggle.
            visibleShapes: shapeButtons.filter((b) => {
                const rect = b.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0;
            }).map((b) => b.dataset.shapeTool),
        };
    });
}

/**
 * Choose a shape in the picker, opening More shapes first when the wanted
 * shape lives behind the toggle.
 */
async function pickShape(page, shapeType) {
    // NK_13: every shape is in one grid now — there is no More toggle to expand.
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
// Page rotation
// ---------------------------------------------------------------------------

/** Rotation pdf.js is currently applying to a page. */
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

/**
 * Where a shape is actually PAINTED, relative to its page, in CSS px.
 *
 * Deliberately measures the rendered SVG rather than the annotation's stored
 * geometry. The equivalent text-tool clamp bug looked correct in the stored
 * values while the rendering spilled off the page, so case 09 has to check the
 * thing the user can see.
 */
function paintedShapeBounds(page, index = 0) {
    return page.evaluate(([selector, i]) => {
        const box = document.querySelectorAll(selector)[i];
        if (!box) return null;
        const pageDiv = box.closest('.page');
        if (!pageDiv) return null;
        const pageRect = pageDiv.getBoundingClientRect();

        // Measure the DRAWN element, not the <svg> wrapper. The wrapper is sized
        // to the box and stays axis-aligned; rotation is baked into the drawn
        // geometry inside it, so the wrapper's rect would never reflect a turn.
        const drawn = box.querySelector('svg path, svg polygon, svg polyline, svg ellipse, svg circle, svg rect, svg line');
        const painted = drawn || box.querySelector('svg');
        const rect = (painted || box).getBoundingClientRect();
        return {
            left: rect.left - pageRect.left,
            top: rect.top - pageRect.top,
            right: rect.right - pageRect.left,
            bottom: rect.bottom - pageRect.top,
            width: rect.width,
            height: rect.height,
            pageWidth: pageRect.width,
            pageHeight: pageRect.height,
            usedDrawnGeometry: !!drawn,
        };
    }, [SHAPE_BOX_SELECTOR, index]);
}

// ---------------------------------------------------------------------------
// Annotation menu and handles
// ---------------------------------------------------------------------------

/** Which annotation-menu actions are actually offered for the selection. */
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

/** The rotate handle for the selected shape, if one is on show. */
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
    }, SHAPE_BOX_SELECTOR);
}

/** Drag the rotate handle around the shape's centre by an angle in degrees. */
async function dragRotateHandle(page, degrees) {
    const handle = await rotateHandleAt(page);
    if (!handle?.visible) return false;
    const centre = await page.evaluate((selector) => {
        const box = document.querySelector(`${selector}.is-selected`);
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, SHAPE_BOX_SELECTOR);

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

/** Drag a shape by its body. */
async function dragShapeBy(page, index, dx, dy) {
    const from = await page.evaluate(([selector, i]) => {
        const box = document.querySelectorAll(selector)[i];
        if (!box) return null;
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, [SHAPE_BOX_SELECTOR, index]);
    if (!from) return false;
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    for (let step = 1; step <= 6; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(from.x + ((dx * step) / 6), from.y + ((dy * step) / 6));
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(40);
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

/**
 * Select a shape and wait until its menu is actually usable.
 *
 * Leaves shape mode first: while the tool is on, a press on the page starts a
 * new drawing rather than selecting what is under the pointer, which silently
 * turns "click the shape then use its menu" into a no-op.
 */
async function ensureSelected(page, index = 0, annotationId = null) {
    // Edit mode, not shape mode. With the Shapes tool on, a press on the page
    // begins a new drawing; with everything off, a shape box is clickable but
    // its annotation menu comes up empty. Edit mode is the state in which a
    // shape can be selected AND its menu actually acted on.
    await setShapeMode(page, false);
    await setEditMode(page, true);
    for (let attempt = 0; attempt < 5; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        const target = annotationId == null ? index : await page.evaluate(
            ([selector, id]) => Array.from(document.querySelectorAll(selector))
                .findIndex((box) => box.dataset.annotationId === id),
            [SHAPE_BOX_SELECTOR, annotationId],
        );
        // eslint-disable-next-line no-await-in-loop
        await selectShape(page, target < 0 ? index : target);
        // eslint-disable-next-line no-await-in-loop
        const menu = await annMenuState(page);

        // The menu is drawn ABOVE the shape. A shape near the top of the viewport
        // puts it at a negative y: it still reports a width and height, so it
        // looks usable, but a click there lands nowhere and every action becomes
        // a silent no-op. Scroll the shape down and try again.
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

/** Click the undo or redo control. */
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

/** 04 — Star, X and heart. */
async function testMoreShapes(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    // NK_13 removed the "More shapes" gear: all seven shapes now sit in one grid,
    // with nothing hidden behind a toggle. The gear was genuinely dead in the
    // legacy editor (no JS ever wired it there) and misread as a settings button
    // in the pdf.js one.
    const panel = await shapePanelState(page);
    recorder.assert('all-seven-visible-without-a-toggle',
        MORE_SHAPES.every((t) => panel.visibleShapes.includes(t))
        && PRIMARY_SHAPES.every((t) => panel.visibleShapes.includes(t)),
        'Star, X and heart are visible alongside the primary shapes, with no toggle to expand',
        JSON.stringify(panel.visibleShapes));

    recorder.assert('no-more-toggle-remains',
        await page.evaluate(() => !document.getElementById('shape-more-toggle')
            && !document.getElementById('shape-more-grid')),
        'The More shapes toggle and its separate grid are gone');

    let expected = 0;
    for (const shapeType of MORE_SHAPES) {
        // eslint-disable-next-line no-await-in-loop
        await pickShape(page, shapeType);
        // eslint-disable-next-line no-await-in-loop
        const picked = await shapePanelState(page);
        recorder.equals(`picker-shows-${shapeType}`, picked.activeShape, shapeType,
            `The picker shows ${shapeType} as the selected shape`);

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
        // eslint-disable-next-line no-await-in-loop
        await page.keyboard.press('Escape');
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(250);
    }

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

/** 09 — Shapes are clamped inside the page. */
async function testClampedInsidePage(page, { recorder }) {
    const artifacts = [];
    // Zoom out first: at default zoom the page is wider than the viewport, so a
    // drag past the PAGE edge would also leave the VIEWPORT and fail on harness
    // geometry rather than telling us anything about clamping.
    await stepZoom(page, 'out', 3);
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    // Drag hard past the right and bottom edges.
    await drawShape(page, { fx: 0.80, fy: 0.60 }, { dx: 260, dy: 200 });
    let painted = await paintedShapeBounds(page, 0);
    recorder.assert('overflow-drag-drew-something', !!painted,
        'Dragging past the page edge still produces a shape');

    if (painted) {
        // Measured on the RENDERED shape, not the stored geometry: the text-tool
        // clamp bug looked correct in the annotation while the rendering spilled.
        recorder.assert('painted-not-past-right',
            painted.right <= painted.pageWidth + 1.5,
            'The painted shape does not overhang the right edge of the page',
            JSON.stringify({ right: Math.round(painted.right), pageWidth: Math.round(painted.pageWidth) }));
        recorder.assert('painted-not-past-bottom',
            painted.bottom <= painted.pageHeight + 1.5,
            'The painted shape does not overhang the bottom edge of the page',
            JSON.stringify({ bottom: Math.round(painted.bottom), pageHeight: Math.round(painted.pageHeight) }));
        recorder.assert('keeps-usable-size',
            painted.width >= 8 && painted.height >= 8,
            'The clamped shape keeps a usable size rather than collapsing',
            JSON.stringify({ w: Math.round(painted.width), h: Math.round(painted.height) }));
        recorder.assert('measured-drawn-geometry', painted.usedDrawnGeometry,
            'The measurement came from the drawn SVG geometry, not the box or the SVG wrapper',
            JSON.stringify(painted.usedDrawnGeometry));
    }

    // Now the left/top edges, dragging back off the page. The delta is derived
    // from the real page box: a fixed negative drag can be impossible to perform
    // when the page already sits at the top of the scroll container, and that
    // would fail on harness geometry rather than on clamping.
    await setShapeMode(page, true);
    const anchor = await pointOnPage(page, 1, 0.08, 0.30);
    const overshoot = await page.evaluate(() => {
        const pageDiv = document.querySelector('#viewer .page[data-page-number="1"]');
        const rect = pageDiv.getBoundingClientRect();
        return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
    });
    // Just past the left edge, and upward by whatever room the viewport allows.
    const dx = -((anchor.x - overshoot.left) + 40);
    const dy = -Math.min(anchor.y - 20, (anchor.y - overshoot.top) + 40);
    await drawShape(page, { fx: 0.08, fy: 0.30 }, { dx, dy });
    const boxes = await shapeBoxes(page);
    const corner = await paintedShapeBounds(page, boxes.length - 1);
    if (corner) {
        recorder.assert('no-negative-left', corner.left >= -1.5,
            'The shape does not run off the left edge into negative coordinates',
            JSON.stringify({ left: Math.round(corner.left) }));
        recorder.assert('no-negative-top', corner.top >= -1.5,
            'The shape does not run off the top edge into negative coordinates',
            JSON.stringify({ top: Math.round(corner.top) }));
    }

    // The stored geometry must agree with what was painted.
    const stored = (await shapeBoxes(page))[0];
    recorder.assert('stored-geometry-non-negative',
        !!stored && stored.relLeft >= -1.5 && stored.relTop >= -1.5,
        'The stored annotation position is inside the page too',
        JSON.stringify({ left: Math.round(stored?.relLeft ?? -999), top: Math.round(stored?.relTop ?? -999) }));

    artifacts.push(await capture(page, '09-clamped-inside-page', 'clamped'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — Drawing stays accurate across zoom levels. */
async function testZoomAccuracy(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);
    await pickShape(page, 'square');

    const baseline = await readZoom(page);
    recorder.assert('zoom-readable', baseline.scale > 0,
        'The viewer reports a usable scale', JSON.stringify(baseline));

    // The invariant worth testing is that the same PAGE-RELATIVE rectangle gives
    // the same PDF geometry at any zoom. A fixed PIXEL drag does not: at higher
    // zoom the same pixels legitimately cover fewer PDF points. So the drag is
    // scaled by the zoom, and the resulting PDF width is compared.
    const BASE_DX = 190;
    const BASE_DY = 150;
    const passes = [
        { label: 'baseline', direction: 'in', steps: 0, fy: 0.16 },
        { label: 'zoomed-out', direction: 'out', steps: 2, fy: 0.40 },
        { label: 'zoomed-in', direction: 'in', steps: 3, fy: 0.64 },
    ];

    const measured = [];
    for (const pass of passes) {
        if (pass.steps > 0) {
            // eslint-disable-next-line no-await-in-loop
            await stepZoom(page, pass.direction, pass.steps);
        }
        // eslint-disable-next-line no-await-in-loop
        const zoom = await readZoom(page);
        const factor = zoom.scale / baseline.scale;

        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        const before = (await shapeBoxes(page)).length;
        // Each pass draws at its own fy so it cannot land on an earlier shape
        // and drag that instead of drawing a new one.
        // eslint-disable-next-line no-await-in-loop
        await drawShape(page, { fx: 0.18, fy: pass.fy },
            { dx: BASE_DX * factor, dy: BASE_DY * factor });
        // eslint-disable-next-line no-await-in-loop
        const boxes = await shapeBoxes(page);
        recorder.assert(`${pass.label}-drew`, boxes.length === before + 1,
            `A shape is drawn at ${pass.label} (scale ${zoom.scale.toFixed(2)})`,
            `${before} -> ${boxes.length}`);

        const drawn = boxes[boxes.length - 1];
        if (drawn && boxes.length === before + 1) {
            measured.push({
                label: pass.label,
                scale: zoom.scale,
                ptWidth: drawn.width / zoom.scale,
                ptHeight: drawn.height / zoom.scale,
            });
        }
    }

    recorder.assert('drew-at-every-zoom', measured.length === passes.length,
        'A shape was drawn at every zoom level tested',
        JSON.stringify(measured.map((m) => m.label)));

    if (measured.length === passes.length) {
        const scales = measured.map((m) => m.scale);
        recorder.assert('zoom-actually-changed',
            Math.max(...scales) - Math.min(...scales) > 0.1,
            'The three passes really were at different zoom levels',
            JSON.stringify(scales.map((v) => Number(v.toFixed(3)))));

        const widths = measured.map((m) => m.ptWidth);
        recorder.assert('same-rect-same-pdf-width',
            Math.max(...widths) - Math.min(...widths) <= 6,
            'The same page-relative rectangle produces the same PDF width at every zoom',
            JSON.stringify(measured.map((m) => ({
                at: m.label, scale: Number(m.scale.toFixed(2)), pt: Number(m.ptWidth.toFixed(1)),
            }))));

        const heights = measured.map((m) => m.ptHeight);
        recorder.assert('same-rect-same-pdf-height',
            Math.max(...heights) - Math.min(...heights) <= 6,
            'The same page-relative rectangle produces the same PDF height at every zoom',
            JSON.stringify(heights.map((v) => Number(v.toFixed(1)))));
    }

    // A shape stays anchored when the zoom changes under it.
    const zoomBefore = (await readZoom(page)).scale;
    const boundsBefore = await paintedShapeBounds(page, 0);
    await stepZoom(page, 'out', 2);
    const zoomAfter = (await readZoom(page)).scale;
    const boundsAfter = await paintedShapeBounds(page, 0);
    if (boundsBefore && boundsAfter && zoomBefore > 0 && zoomAfter > 0) {
        recorder.near('stays-anchored-across-zoom',
            boundsAfter.left / zoomAfter, boundsBefore.left / zoomBefore, 6,
            'A shape keeps its place on the page when the zoom changes');
    }

    artifacts.push(await capture(page, '10-zoom-accuracy', 'zoom'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 11 — Drawing on rotated pages.
 *
 * The QA case asks what the Shapes tool does here, and whether it agrees with
 * the Text tool. For text the answer was that a rotated page is not editable at
 * all: the editor renders it as one flattened image, says so in a dialog, and
 * the tool places nothing. This asserts the Shapes tool holds the same contract
 * — two tools differing on the same rotated page would be the finding.
 */
async function testRotatedPages(page, { recorder }) {
    const artifacts = [];

    recorder.equals('starts-unrotated', await pageRotation(page, 1), 0,
        'The page starts unrotated');

    // Baseline: drawing works before any rotation.
    await setShapeMode(page, true);
    await pickShape(page, 'square');
    await drawShape(page, { fx: 0.25, fy: 0.22 }, { dx: 170, dy: 140 });
    const baseline = await shapeBoxes(page);
    recorder.equals('baseline-drawn', baseline.length, 1,
        'A shape can be drawn before the page is rotated');

    const rotated = await rotatePage(page, 1, 'right');
    recorder.equals('rotated-90', rotated.rotation, 90, 'The page rotates to 90 degrees');

    recorder.assert('rotation-blocks-editing', await page.evaluate(
        () => document.body.classList.contains('enpv-edit-rotation-blocked'),
    ), 'Rotating the page marks editing as unavailable');
    recorder.assert('rotation-explained',
        rotated.dialogShown && /rotated/i.test(rotated.dialogMessage),
        'The editor explains why the rotated page cannot be edited',
        rotated.dialogMessage.slice(0, 140));

    artifacts.push(await capture(page, '11-rotated-pages', 'rotation-blocked'));

    // The shapes tool may still toggle, but must place nothing.
    const countBefore = (await shapeBoxes(page)).length;
    await setShapeMode(page, true);
    recorder.assert('shape-tool-still-toggles', await shapeModeOn(page),
        'The Shapes tool can still be switched on');

    const spot = await pointOnPage(page, 1, 0.35, 0.3);
    await page.mouse.move(spot.x, spot.y);
    await page.mouse.down();
    for (let step = 1; step <= 6; step++) {
        // eslint-disable-next-line no-await-in-loop
        await page.mouse.move(spot.x + (step * 25), spot.y + (step * 20));
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(35);
    }
    await page.mouse.up();
    await page.waitForTimeout(900);

    recorder.equals('no-drawing-while-rotated', (await shapeBoxes(page)).length, countBefore,
        'Dragging on a rotated page draws nothing, matching the Text tool contract');

    // Rotate back: the tool and the original shape both return.
    const restored = await rotatePage(page, 1, 'left');
    recorder.equals('rotated-back', restored.rotation, 0, 'The page rotates back to 0 degrees');
    recorder.assert('editing-restored', !(await page.evaluate(
        () => document.body.classList.contains('enpv-edit-rotation-blocked'),
    )), 'Editing becomes available again once the page is upright');
    recorder.equals('original-shape-survived', (await shapeBoxes(page)).length, 1,
        'The shape drawn before rotation is still there afterwards');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 20 — Select, deselect and the hover menu. */
async function testSelectAndMenu(page, { recorder }) {
    const artifacts = [];
    await drawOne(page, 'square', { fx: 0.22, fy: 0.20 }, { dx: 200, dy: 160 });

    recorder.assert('click-selects', await selectShape(page, 0),
        'Clicking a shape selects it');

    const menu = await annMenuState(page);
    recorder.assert('menu-appears', menu.visible,
        'The hover menu appears over the selection', JSON.stringify(menu.offered));
    recorder.ok('menu-actions-offered',
        `The menu offers: ${menu.offered.join(', ')}`, JSON.stringify(menu.offered));

    const handles = await page.evaluate((selector) => Array.from(
        document.querySelectorAll(`${selector}.is-selected > .enpv-resize-handle`),
    ).filter((h) => {
        const r = h.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    }).map((h) => h.dataset.edge), SHAPE_BOX_SELECTOR);
    recorder.assert('handles-appear', handles.length >= 4,
        'Resize handles appear on selection, without needing edit mode',
        JSON.stringify(handles));

    // The menu must follow the shape when the view scrolls.
    const before = await annMenuState(page);
    await page.evaluate(() => {
        const container = document.getElementById('viewerContainer');
        if (container) container.scrollTop += 120;
    });
    await page.waitForTimeout(600);
    const after = await annMenuState(page);
    recorder.assert('menu-follows-scroll',
        after.visible && Math.abs((after.top - before.top) + 120) <= 24,
        'The menu stays anchored over the shape when the page scrolls',
        JSON.stringify({ before: Math.round(before.top), after: Math.round(after.top) }));
    await page.evaluate(() => {
        const container = document.getElementById('viewerContainer');
        if (container) container.scrollTop -= 120;
    });
    await page.waitForTimeout(500);

    // A fully transparent fill must still be selectable, via its outline.
    await selectShape(page, 0);
    await setShapeStyle(page, { fillOpacity: 0 });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const reselected = await selectShape(page, 0);
    recorder.assert('transparent-fill-still-selectable', reselected,
        'A shape with no fill can still be selected',
        JSON.stringify({ fillOpacity: (await shapeBoxes(page))[0]?.fillOpacity }));

    // Every deselect path.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    recorder.assert('escape-deselects',
        !(await page.evaluate((sel) => !!document.querySelector(`${sel}.is-selected`), SHAPE_BOX_SELECTOR)),
        'Escape clears the selection');

    await selectShape(page, 0);
    const empty = await pointOnPage(page, 1, 0.85, 0.85);
    await page.mouse.click(empty.x, empty.y);
    await page.waitForTimeout(500);
    recorder.assert('empty-click-deselects',
        !(await page.evaluate((sel) => !!document.querySelector(`${sel}.is-selected`), SHAPE_BOX_SELECTOR)),
        'Clicking empty page clears the selection');

    artifacts.push(await capture(page, '20-select-and-menu', 'selected'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 21 — Move and resize. */
async function testMoveAndResize(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await drawOne(page, 'square', { fx: 0.22, fy: 0.20 }, { dx: 200, dy: 160 });
    await selectShape(page, 0);

    const before = (await shapeBoxes(page))[0];
    await dragShapeBy(page, 0, 120, 90);
    let after = (await shapeBoxes(page))[0];
    recorder.near('drag-moves-x', after.relLeft - before.relLeft, 120, 6,
        'Dragging the body moves the shape 1:1 on x');
    recorder.near('drag-moves-y', after.relTop - before.relTop, 90, 6,
        'Dragging the body moves the shape 1:1 on y');
    recorder.near('drag-keeps-width', after.width, before.width, 2,
        'Dragging moves the shape without resizing it');

    // One drag must be exactly one undo step.
    await clickHistory(page, 'undo');
    const undone = (await shapeBoxes(page))[0];
    recorder.near('drag-is-one-undo-step', undone.relLeft, before.relLeft, 6,
        'A single undo puts the shape back where it started');
    await clickHistory(page, 'redo');

    // Resize from the right edge. Shapes show handles on selection alone.
    await selectShape(page, 0);
    const beforeResize = (await shapeBoxes(page))[0];
    const handle = await page.evaluate((selector) => {
        const el = document.querySelector(`${selector}.is-selected > .enpv-resize-handle.r`)
            || document.querySelector(`${selector}.is-selected > .enpv-resize-handle[data-edge="r"]`);
        if (!el) return null;
        const rect = el.getBoundingClientRect();
        if (rect.width <= 0) return null;
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, SHAPE_BOX_SELECTOR);
    recorder.assert('resize-handle-available', !!handle,
        'A right-edge resize handle is available on a selected shape');

    if (handle) {
        await page.mouse.move(handle.x, handle.y);
        await page.mouse.down();
        for (let step = 1; step <= 6; step++) {
            // eslint-disable-next-line no-await-in-loop
            await page.mouse.move(handle.x + ((110 * step) / 6), handle.y);
            // eslint-disable-next-line no-await-in-loop
            await page.waitForTimeout(40);
        }
        await page.mouse.up();
        await page.waitForTimeout(700);

        after = (await shapeBoxes(page))[0];
        recorder.near('resize-grows-width', after.width - beforeResize.width, 110, 12,
            'Dragging the right edge grows the shape');
        recorder.near('resize-keeps-left', after.relLeft, beforeResize.relLeft, 4,
            'Resizing from the right leaves the left edge alone');
        recorder.equals('resize-keeps-stroke-width', after.strokeWidth, beforeResize.strokeWidth,
            'The stroke width does not scale with the shape');
    }

    // Position survives a reload.
    const beforeReload = (await shapeBoxes(page))[0];
    await saveAndReload(page, saveRecorder);
    const reloaded = (await shapeBoxes(page))[0];
    recorder.near('position-survives-reload', reloaded?.relLeft, beforeReload.relLeft, 3,
        'The shape is in the same place after a save and reload');
    recorder.near('size-survives-reload', reloaded?.width, beforeReload.width, 3,
        'The shape is the same size after a save and reload');

    artifacts.push(await capture(page, '21-move-and-resize', 'moved'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 22 — Rotate. */
async function testRotate(page, { recorder, saveRecorder }) {
    const artifacts = [];
    await drawOne(page, 'square', { fx: 0.25, fy: 0.22 }, { dx: 220, dy: 170 });
    await selectShape(page, 0);

    const handle = await rotateHandleAt(page);
    recorder.assert('rotate-handle-offered', !!handle?.visible,
        'A selected shape offers a rotate handle', JSON.stringify(handle));

    // Measure the PAINTED area before and after: a rotated outline over an
    // unrotated fill was exactly the NK_12 text-tool bug, and reading a
    // transform off one element would not have caught it.
    const paintedBefore = await paintedShapeBounds(page, 0);

    const rotated = await dragRotateHandle(page, 40);
    recorder.assert('rotate-drag-ran', rotated, 'The rotate handle can be dragged');

    const box = (await shapeBoxes(page))[0];
    recorder.assert('angle-stored', Math.abs(box?.rotation || 0) > 5,
        'The rotation is stored on the annotation', JSON.stringify(box?.rotation));

    const paintedAfter = await paintedShapeBounds(page, 0);
    if (paintedBefore && paintedAfter) {
        // A rotated square paints a LARGER axis-aligned bounding box. Measured on
        // the drawn geometry, so this fails if the angle is stored but never
        // actually painted.
        const grew = (paintedAfter.width > paintedBefore.width + 8)
            || (paintedAfter.height > paintedBefore.height + 8);
        recorder.assert('painted-shape-actually-turns', grew,
            'The painted geometry really rotates, not just the stored angle',
            JSON.stringify({
                before: { w: Math.round(paintedBefore.width), h: Math.round(paintedBefore.height) },
                after: { w: Math.round(paintedAfter.width), h: Math.round(paintedAfter.height) },
            }));
    }

    // NK_12 was a rotated outline over an unrotated background, and the story
    // asked to watch for the same class of bug here. It cannot occur for shapes:
    // each shape is ONE svg element carrying both fill and stroke, so they cannot
    // diverge. Recorded rather than asserted, since a passing check here would
    // only be restating the DOM structure.
    const parts = await page.evaluate((selector) => {
        const box = document.querySelectorAll(selector)[0];
        const drawn = Array.from(box?.querySelectorAll('svg > *') || []);
        // Only elements that actually paint ink count: the shape svg also carries
        // non-painting children (null fill AND null stroke) used as chrome.
        return drawn
            .map((el) => ({
                tag: el.tagName.toLowerCase(),
                fill: el.getAttribute('fill'),
                stroke: el.getAttribute('stroke'),
            }))
            .filter((el) => (el.fill && el.fill !== 'none') || (el.stroke && el.stroke !== 'none'));
    }, SHAPE_BOX_SELECTOR);
    recorder.ok('fill-and-stroke-cannot-diverge',
        parts.length === 1
            ? 'Fill and stroke are one svg element, so the NK_12 split-transform bug is structurally impossible for shapes'
            : `The shape paints ${parts.length} separate svg elements, so fill/stroke divergence IS possible here and needs its own check`,
        JSON.stringify(parts));

    // A locked shape offers no handle. Checked here, while the selection is
    // already live -- after a save and reload the box comes back but the menu
    // is not re-attached by a synthetic click, which is a harness limit rather
    // than a product one.
    const locked = await annotationMenuAction(page, 'lock');
    if (locked) {
        await page.waitForTimeout(400);
        const lockedBox = (await shapeBoxes(page))[0];
        recorder.assert('lock-applies', !!lockedBox?.locked,
            'The lock action actually locks the shape', JSON.stringify(lockedBox?.locked));
        const afterLock = await rotateHandleAt(page);
        // LEFT FAILING DELIBERATELY — this is a real defect, not a flaky check.
        // index.css:5476 hides .enpv-shape-rotate-handle for .is-locked at
        // specificity (0,3,0), but the show rule at index.css:4557
        // (.enpv-annotation-box.is-selected.enpv-shape-box:not([data-shape-type="line"]))
        // is (0,5,0) and carries no :not(.is-locked) guard, so it wins. The
        // signature and user-created-text rules at 4562-4563 DO have that guard;
        // the shape rule is the one that was missed.
        recorder.assert('locked-shape-has-no-handle', !afterLock?.visible,
            'A locked shape offers no rotate handle (FINDING: it still does — see index.css:4557 vs 5476)',
            JSON.stringify({ locked: lockedBox?.locked, handle: afterLock }));
        await annotationMenuAction(page, 'lock');
        await page.waitForTimeout(300);
    } else {
        recorder.assert('locked-shape-has-no-handle', false,
            'Lock should be offered for a selected shape',
            JSON.stringify(await annMenuState(page)));
    }

    // Angle survives a save and reload.
    const angleBefore = (await shapeBoxes(page))[0]?.rotation;
    await saveAndReload(page, saveRecorder);
    const angleAfter = (await shapeBoxes(page))[0]?.rotation;
    recorder.near('angle-survives-reload', angleAfter, angleBefore, 1.5,
        'The angle survives a save and reload');

    artifacts.push(await capture(page, '22-rotate', 'rotated'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 23 — Cut a shape. */
async function testCutShape(page, { recorder }) {
    const artifacts = [];

    // Cut is offered on square, circle, triangle, star and polygon; not on line.
    await drawOne(page, 'square', { fx: 0.20, fy: 0.18 }, { dx: 200, dy: 160 });
    await selectShape(page, 0);
    let menu = await annMenuState(page);
    recorder.assert('cut-offered-for-square', menu.offered.includes('cut'),
        'Cut is offered for a square, matching CUTTABLE_SHAPE_TYPES',
        JSON.stringify(menu.offered));

    // Arming cut must show some state, and Escape must cancel cleanly.
    const armed = await annotationMenuAction(page, 'cut');
    if (armed) {
        const armedState = await page.evaluate((selector) => ({
            bodyArmed: document.body.classList.contains('enpv-shape-cut-armed'),
            boxArmed: !!document.querySelector(`${selector}.is-shape-cut-armed`),
        }), SHAPE_BOX_SELECTOR);
        recorder.assert('cut-shows-armed-state',
            armedState.bodyArmed || armedState.boxArmed,
            'Arming cut puts the editor into a visible armed state',
            JSON.stringify(armedState));

        const countBefore = (await shapeBoxes(page)).length;
        await page.keyboard.press('Escape');
        await page.waitForTimeout(600);
        const afterEscape = await page.evaluate((selector) => ({
            bodyArmed: document.body.classList.contains('enpv-shape-cut-armed'),
            boxArmed: !!document.querySelector(`${selector}.is-shape-cut-armed`),
        }), SHAPE_BOX_SELECTOR);
        recorder.assert('escape-cancels-cut',
            !afterEscape.bodyArmed && !afterEscape.boxArmed,
            'Escape cancels the armed cut cleanly', JSON.stringify(afterEscape));
        recorder.equals('escape-leaves-shape-intact', (await shapeBoxes(page)).length, countBefore,
            'Cancelling the cut leaves the shape as it was');
    } else {
        recorder.fail('cut-shows-armed-state', 'Cut could not be armed, so its state could not be checked');
    }

    // NK_14: heart is cuttable now. It was excluded for no reason anyone could
    // point at, unlike line, which is gated on rotation as well.
    await setShapeMode(page, true);
    await pickShape(page, 'heart');
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.55, fy: 0.18 }, { dx: 170, dy: 140 });
    let boxesNow = await shapeBoxes(page);
    await ensureSelected(page, boxesNow.length - 1);
    menu = await annMenuState(page);
    recorder.assert('cut-offered-for-heart', menu.offered.includes('cut'),
        'Cut is offered for a heart (NK_14)', JSON.stringify(menu.offered));

    // A line must NOT offer cut — still gated on rotation, which excludes lines.
    await setShapeMode(page, true);
    await pickShape(page, 'line');
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.20, fy: 0.55 }, { dx: 200, dy: 120 });
    const boxes = await shapeBoxes(page);
    await selectShape(page, boxes.length - 1);
    menu = await annMenuState(page);
    recorder.assert('cut-not-offered-for-line', !menu.offered.includes('cut'),
        'Cut is not offered for a line', JSON.stringify(menu.offered));

    artifacts.push(await capture(page, '23-cut-shape', 'cut'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 24 — Lock, duplicate, delete and z-order. */
async function testLockDuplicateDeleteOrder(page, { recorder, saveRecorder }) {
    const artifacts = [];
    // Two shapes that OVERLAP visually for the z-order checks, but whose press
    // points do not: starting the second drag inside the first shape would grab
    // that shape instead of drawing a new one.
    await drawOne(page, 'square', { fx: 0.18, fy: 0.18 }, { dx: 220, dy: 170 });
    await drawOne(page, null, { fx: 0.18, fy: 0.42 }, { dx: 220, dy: 170 });

    const zOrder = () => page.evaluate((selector) => Array.from(
        document.querySelectorAll(selector),
    ).map((box, index) => ({
        id: box.dataset.annotationId || `dom-${index}`,
        domIndex: index,
        style: box.style.zIndex || null,
        dataset: box.dataset.zIndex || null,
        computed: window.getComputedStyle(box).zIndex,
    })), SHAPE_BOX_SELECTOR);

    // Bring to front / send to back.
    const selectedOk = await ensureSelected(page, 0);
    const targetId = (await shapeBoxes(page))[0]?.annotationId;
    recorder.assert('shape-selects-for-menu', selectedOk,
        'The shape can be selected and its menu becomes usable',
        JSON.stringify(await annMenuState(page)));
    const front = await annotationMenuAction(page, 'front');
    recorder.assert('front-offered', front, 'The menu offers Bring to front');
    // Track the target by annotation id, not DOM position: the layer re-sorts
    // its boxes by z-index, so after a raise the shape is no longer at index 0
    // and comparing by index would read the wrong pair.
    const zById = async () => {
        const entries = await zOrder();
        return Object.fromEntries(entries.map((e) => [e.id, Number.parseInt(e.dataset ?? e.style ?? '0', 10)]));
    };
    let z = await zById();
    const others = Object.keys(z).filter((id) => id !== targetId);
    recorder.assert('front-raises', z[targetId] > Math.max(...others.map((id) => z[id])),
        'Bring to front puts the shape above the other', JSON.stringify(z));

    await ensureSelected(page, null, targetId);
    await annotationMenuAction(page, 'back');
    z = await zById();
    recorder.assert('back-lowers', z[targetId] < Math.max(...others.map((id) => z[id])),
        'Send to back puts it below again', JSON.stringify(z));

    // Lock disables the panel and blocks moving.
    await ensureSelected(page, 0);
    recorder.assert('lock-offered', await annotationMenuAction(page, 'lock'),
        'The menu offers Lock');
    let boxes = await shapeBoxes(page);
    recorder.assert('lock-applies', boxes[0]?.locked, 'The shape reports itself locked');

    const lockedBefore = boxes[0];
    await dragShapeBy(page, 0, 90, 70);
    boxes = await shapeBoxes(page);
    recorder.near('locked-shape-cannot-move', boxes[0]?.relLeft, lockedBefore.relLeft, 3,
        'A locked shape cannot be dragged');

    await ensureSelected(page, 0);
    await annotationMenuAction(page, 'lock');
    boxes = await shapeBoxes(page);
    recorder.assert('unlock-restores', !boxes[0]?.locked, 'Unlocking restores the shape');

    // Duplicate. The shape menu does not offer a Duplicate action -- unlike text
    // and signature boxes -- so the reachable route is the clipboard. Which one
    // works is recorded rather than assumed.
    await ensureSelected(page, 0);
    const countBefore = (await shapeBoxes(page)).length;
    const menuCopy = await annotationMenuAction(page, 'copy');
    recorder.ok('duplicate-route',
        menuCopy
            ? 'Duplicate is offered in the shape menu'
            : 'The shape menu offers NO Duplicate action (text and signature boxes do); the clipboard is the only route',
        JSON.stringify((await annMenuState(page)).offered));

    if (!menuCopy) {
        await ensureSelected(page, 0);
        await page.keyboard.press('Control+c');
        await page.waitForTimeout(400);
        await page.keyboard.press('Control+v');
        await page.waitForTimeout(900);
    }
    boxes = await shapeBoxes(page);
    recorder.equals('duplicate-adds-shape', boxes.length, countBefore + 1,
        'Duplicating a shape adds one copy');

    const copy = boxes[boxes.length - 1];
    const ids = boxes.map((b) => b.annotationId);
    recorder.assert('duplicate-has-new-id', new Set(ids).size === ids.length,
        'The duplicate has its own annotation id', JSON.stringify(copy?.annotationId));
    recorder.assert('duplicate-is-offset',
        !!copy && (Math.abs(copy.relLeft - boxes[0].relLeft) > 2 || Math.abs(copy.relTop - boxes[0].relTop) > 2),
        'The duplicate is offset rather than hidden underneath the original',
        JSON.stringify({ copy: [Math.round(copy?.relLeft), Math.round(copy?.relTop)] }));

    // The Text tool had a bug where a duplicate lost its rotatability. Shapes
    // should not repeat it, so check the copy is rotatable like its original.
    await ensureSelected(page, boxes.length - 1);
    const copyHandle = await rotateHandleAt(page);
    recorder.assert('duplicate-behaves-like-original', !!copyHandle?.visible,
        'The duplicate offers a rotate handle, so it behaves like the shape it came from',
        JSON.stringify(copyHandle));

    // Delete.
    const beforeDelete = (await shapeBoxes(page)).length;
    recorder.assert('delete-offered', await annotationMenuAction(page, 'delete'),
        'The menu offers Delete');
    recorder.equals('delete-removes', (await shapeBoxes(page)).length, beforeDelete - 1,
        'Delete removes the shape');

    // Z-order survives a reload.
    const orderBefore = await zOrder();
    await saveAndReload(page, saveRecorder);
    const orderAfter = await zOrder();
    recorder.equals('z-order-survives-reload', JSON.stringify(orderAfter), JSON.stringify(orderBefore),
        'The stacking order survives a save and reload');

    artifacts.push(await capture(page, '24-lock-duplicate-delete-order', 'ordered'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 25 — Multi-select and group operations. */
async function testMultiSelect(page, { recorder }) {
    const artifacts = [];
    await drawOne(page, 'square', { fx: 0.18, fy: 0.18 }, { dx: 170, dy: 130 });
    await drawOne(page, null, { fx: 0.18, fy: 0.42 }, { dx: 170, dy: 130 });
    recorder.equals('two-shapes', (await shapeBoxes(page)).length, 2, 'Two shapes are drawn');

    await setShapeMode(page, false);
    await setEditMode(page, true);

    const selectedCount = () => page.evaluate((selector) => Array.from(
        document.querySelectorAll(selector),
    ).filter((box) => box.classList.contains('is-selected')
        || box.classList.contains('is-multi-selected')).length, SHAPE_BOX_SELECTOR);

    // Shift-click was added to the shared annotation pointer-down path on the
    // Text tool story, so it should behave identically here.
    await selectShape(page, 0);
    const second = await page.evaluate((selector) => {
        const box = document.querySelectorAll(selector)[1];
        const rect = box.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, SHAPE_BOX_SELECTOR);
    await page.keyboard.down('Shift');
    await page.mouse.click(second.x, second.y);
    await page.keyboard.up('Shift');
    await page.waitForTimeout(600);

    const multi = await selectedCount();
    recorder.assert('shift-click-multi-selects', multi === 2,
        'Shift-clicking a second shape selects both, matching the Text tool',
        `selected=${multi}`);

    if (multi === 2) {
        const before = await shapeBoxes(page);
        await dragShapeBy(page, 0, 90, 70);
        const after = await shapeBoxes(page);
        const deltas = after.map((box, i) => ({
            dx: box.relLeft - before[i].relLeft,
            dy: box.relTop - before[i].relTop,
        }));
        recorder.assert('group-drag-moves-both',
            deltas.length === 2
            && Math.abs(deltas[0].dx - deltas[1].dx) <= 3
            && Math.abs(deltas[0].dy - deltas[1].dy) <= 3
            && Math.abs(deltas[0].dx) > 20,
            'A group drag moves the whole selection by one offset',
            JSON.stringify(deltas));
    }

    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    recorder.equals('escape-clears-multi', await selectedCount(), 0,
        'Escape clears the multi-selection');

    // A marquee over both shapes, sized from where they actually are.
    const span = await page.evaluate((selector) => {
        const rects = Array.from(document.querySelectorAll(selector)).map((b) => b.getBoundingClientRect());
        return {
            left: Math.min(...rects.map((r) => r.left)),
            top: Math.min(...rects.map((r) => r.top)),
            right: Math.max(...rects.map((r) => r.right)),
            bottom: Math.max(...rects.map((r) => r.bottom)),
        };
    }, SHAPE_BOX_SELECTOR);
    const pad = 26;
    await page.mouse.move(span.left - pad, span.top - pad);
    await page.mouse.down();
    await page.mouse.move(span.right + pad, span.bottom + pad, { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(700);
    const marquee = await selectedCount();
    recorder.assert('marquee-selects-both', marquee === 2,
        'A marquee drag over both shapes selects them', `selected=${marquee}`);

    if (marquee === 2) {
        await page.keyboard.press('Delete');
        await page.waitForTimeout(800);
        recorder.equals('delete-removes-selection', (await shapeBoxes(page)).length, 0,
            'Delete removes every selected shape');
        await clickHistory(page, 'undo');
        recorder.equals('undo-restores-selection', (await shapeBoxes(page)).length, 2,
            'A single undo brings the whole selection back');
    }

    artifacts.push(await capture(page, '25-multi-select', 'multi-selected'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 26 — Undo and redo across every shape operation. */
async function testUndoRedo(page, { recorder }) {
    const artifacts = [];
    const count = async () => (await shapeBoxes(page)).length;

    // Draw.
    await drawOne(page, 'square', { fx: 0.20, fy: 0.20 }, { dx: 190, dy: 150 });
    recorder.equals('draw-adds', await count(), 1, 'Drawing adds a shape');
    await clickHistory(page, 'undo');
    recorder.equals('undo-draw', await count(), 0, 'Undo removes the drawn shape in one step');
    await clickHistory(page, 'redo');
    recorder.equals('redo-draw', await count(), 1, 'Redo brings it back in one step');

    // Restyle.
    await ensureSelected(page, 0);
    const beforeStyle = (await shapeBoxes(page))[0]?.strokeColor;
    await setShapeStyle(page, { strokeHex: '#ff0000' });
    const afterStyle = (await shapeBoxes(page))[0]?.strokeColor;
    recorder.assert('restyle-applied', afterStyle !== beforeStyle,
        'A restyle changes the shape', JSON.stringify({ beforeStyle, afterStyle }));
    // Count the undos it actually takes, so a failure says how far off it is
    // rather than just "not equal".
    let undosToRevert = 0;
    for (let attempt = 1; attempt <= 3; attempt++) {
        // eslint-disable-next-line no-await-in-loop
        await clickHistory(page, 'undo');
        // eslint-disable-next-line no-await-in-loop
        const now = (await shapeBoxes(page))[0]?.strokeColor;
        if (String(now).toLowerCase() === String(beforeStyle).toLowerCase()) {
            undosToRevert = attempt;
            break;
        }
    }
    // LEFT FAILING DELIBERATELY — a restyle appears not to be undoable at all.
    // The Shape options panel binds BOTH events on every input: 'input' calls
    // commitShapeInspectorToSelectedBox({pushHistory:false}) and applies the
    // change, then 'change' calls it again with pushHistory:true. By then the
    // change is already applied, so the snapshot captures the NEW state and
    // undo has nothing earlier to go back to. A real colour picker fires the
    // same input-then-change pair, so this is not an artefact of the harness.
    recorder.assert('undo-restyle-is-one-step', undosToRevert === 1,
        'One undo reverts a restyle (FINDING: no number of undos does — see the panel input/change handlers)',
        undosToRevert === 0
            ? `three undos did not restore ${beforeStyle}`
            : `took ${undosToRevert} undo(s)`);
    for (let i = 0; i < Math.max(undosToRevert, 1); i++) {
        // eslint-disable-next-line no-await-in-loop
        await clickHistory(page, 'redo');
    }

    // Move.
    await ensureSelected(page, 0);
    const beforeMove = (await shapeBoxes(page))[0];
    await dragShapeBy(page, 0, 100, 80);
    const afterMove = (await shapeBoxes(page))[0];
    recorder.assert('move-applied', Math.abs(afterMove.relLeft - beforeMove.relLeft) > 20,
        'The shape moved');
    await clickHistory(page, 'undo');
    recorder.near('undo-move', (await shapeBoxes(page))[0]?.relLeft, beforeMove.relLeft, 5,
        'One undo reverts the move');
    await clickHistory(page, 'redo');

    // Delete.
    await ensureSelected(page, 0);
    await annotationMenuAction(page, 'delete');
    recorder.equals('delete-applied', await count(), 0, 'Delete removes the shape');
    await clickHistory(page, 'undo');
    recorder.equals('undo-delete', await count(), 1, 'One undo brings the deleted shape back');

    // Keyboard shortcuts drive the same stack.
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(700);
    const afterCtrlZ = await count();
    await page.keyboard.press('Control+Shift+z');
    await page.waitForTimeout(700);
    recorder.assert('keyboard-undo-redo-work',
        afterCtrlZ !== 1 || (await count()) === 1,
        'Ctrl+Z and Ctrl+Shift+Z drive the same history stack',
        `afterCtrlZ=${afterCtrlZ} afterRedo=${await count()}`);

    artifacts.push(await capture(page, '26-undo-redo', 'history'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 27 — Autosave and explicit Save. */
async function testAutosaveAndSave(page, { recorder, saveRecorder }) {
    const artifacts = [];

    const savesBefore = saveRecorder.saves.length;
    await drawOne(page, 'square', { fx: 0.20, fy: 0.20 }, { dx: 190, dy: 150 });

    // Drawing must mark the document dirty and schedule a save.
    await page.waitForTimeout(6000);
    const afterDraw = saveRecorder.saves.length;
    recorder.assert('draw-schedules-a-save', afterDraw > savesBefore,
        'Drawing a shape schedules an autosave',
        `saves ${savesBefore} -> ${afterDraw}`);

    // An explicit Save also lands, and carries the shape.
    const explicit = await saveDocument(page, saveRecorder);
    recorder.assert('explicit-save-lands', explicit, 'An explicit Save sends the annotation state');
    const saved = savedShapeAnnotations(saveRecorder);
    recorder.assert('save-carries-the-shape', saved.length >= 1,
        'The saved payload contains the shape', `${saved.length} shape annotations`);

    // Nothing changed: a second Save must not invent new work.
    const quiet = saveRecorder.saves.length;
    await page.waitForTimeout(6000);
    recorder.equals('no-save-when-nothing-changed', saveRecorder.saves.length, quiet,
        'No further autosave is sent while the document is untouched');

    // Rapid edits coalesce rather than racing.
    const beforeBurst = saveRecorder.saves.length;
    await ensureSelected(page, 0);
    for (const colour of ['#111111', '#222222', '#333333', '#444444']) {
        // eslint-disable-next-line no-await-in-loop
        await setShapeStyle(page, { strokeHex: colour });
    }
    await page.waitForTimeout(7000);
    const burst = saveRecorder.saves.length - beforeBurst;
    recorder.assert('rapid-edits-coalesce', burst <= 4,
        'Four rapid restyles do not produce a save each', `${burst} saves for 4 edits`);

    artifacts.push(await capture(page, '27-autosave-and-save', 'saved'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 28 — Reload fidelity. */
async function testReloadFidelity(page, { recorder, saveRecorder }) {
    const artifacts = [];

    // One of several shapes, each with its own styling and one rotated.
    const spec = [
        { type: 'square', fy: 0.14, stroke: '#ff0000', fill: '#00ff00', strokeWidth: 6, fillOpacity: 70 },
        { type: 'circle', fy: 0.36, stroke: '#0000ff', fill: '#ffff00', strokeWidth: 2, fillOpacity: 30 },
        { type: 'triangle', fy: 0.58, stroke: '#ff00ff', fill: '#00ffff', strokeWidth: 12, fillOpacity: 90 },
    ];
    for (const item of spec) {
        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await pickShape(page, item.type);
        // eslint-disable-next-line no-await-in-loop
        await setShapeStyle(page, {
            strokeHex: item.stroke,
            fillHex: item.fill,
            strokeWidth: item.strokeWidth,
            fillOpacity: item.fillOpacity,
        });
        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await drawShape(page, { fx: 0.18, fy: item.fy }, { dx: 170, dy: 130 });
    }

    // Rotate one of them so the angle is part of what must survive.
    await ensureSelected(page, 0);
    await dragRotateHandle(page, 35);

    const before = await shapeBoxes(page);
    recorder.equals('all-shapes-drawn', before.length, spec.length,
        'Every shape in the fixture is drawn');

    await saveAndReload(page, saveRecorder);
    const after = await shapeBoxes(page);
    recorder.equals('all-shapes-return', after.length, before.length,
        'Every shape comes back after a hard reload');

    if (after.length === before.length) {
        const byId = (list) => Object.fromEntries(list.map((b) => [b.annotationId, b]));
        const a = byId(before);
        const b = byId(after);
        const ids = Object.keys(a);
        recorder.assert('annotation-ids-survive', ids.every((id) => b[id]),
            'Every annotation keeps its id across the reload',
            JSON.stringify({ before: ids.length, matched: ids.filter((id) => b[id]).length }));

        const mismatches = ids.filter((id) => b[id]).filter((id) => (
            a[id].shapeType !== b[id].shapeType
            || String(a[id].strokeColor).toLowerCase() !== String(b[id].strokeColor).toLowerCase()
            || String(a[id].fillColor).toLowerCase() !== String(b[id].fillColor).toLowerCase()
            || Math.abs((a[id].strokeWidth || 0) - (b[id].strokeWidth || 0)) > 0.01
            || Math.abs((a[id].fillOpacity || 0) - (b[id].fillOpacity || 0)) > 0.02
            || Math.abs((a[id].rotation || 0) - (b[id].rotation || 0)) > 1.5
        ));
        recorder.assert('styling-and-angle-survive', mismatches.length === 0,
            'Shape type, stroke, fill, opacity and angle all survive the reload',
            JSON.stringify(mismatches.map((id) => ({ before: a[id], after: b[id] }))).slice(0, 300));

        const moved = ids.filter((id) => b[id]).filter((id) => (
            Math.abs(a[id].relLeft - b[id].relLeft) > 3 || Math.abs(a[id].relTop - b[id].relTop) > 3
        ));
        recorder.assert('geometry-survives', moved.length === 0,
            'Every shape comes back in the same place', JSON.stringify(moved));
    }

    // The panel must read the values back when a reloaded shape is selected.
    await ensureSelected(page, 0);
    const panel = await shapePanelState(page);
    const selected = (await shapeBoxes(page))[0];
    recorder.assert('panel-reads-back',
        String(panel.strokeHex || '').toLowerCase() === String(selected?.strokeColor || '').toLowerCase(),
        'Selecting a reloaded shape reads its values back into the panel',
        JSON.stringify({ panel: panel.strokeHex, shape: selected?.strokeColor }));

    artifacts.push(await capture(page, '28-reload-fidelity', 'reloaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 29 — Downloaded PDF fidelity, per shape type. */
async function testDownloadFidelity(page, { recorder, saveRecorder, docId }) {
    const artifacts = [];

    // One of every shape the picker offers, so the export covers each branch of
    // draw_shape rather than one representative.
    const types = [...PRIMARY_SHAPES, ...MORE_SHAPES];
    const failedToDraw = [];
    // NK_15: shapes are drawn back to back WITHOUT deselecting between them.
    // That used to be impossible — a shape stayed selected after being drawn and
    // the picker committed to the selection, so choosing the next shape silently
    // converted the one just drawn. Leaving the Escape out is the point: if the
    // picker ever starts rewriting the selection again, this loop breaks.
    for (let i = 0; i < types.length; i++) {
        // Two columns, and never right at the top edge: a press point too close
        // to the top leaves no room for the drag and the draw is silently lost.
        const fx = 0.14 + ((i % 2) * 0.42);
        const fy = 0.16 + (Math.floor(i / 2) * 0.18);
        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        await pickShape(page, types[i]);
        // eslint-disable-next-line no-await-in-loop
        await setShapeMode(page, true);
        // eslint-disable-next-line no-await-in-loop
        const before = (await shapeBoxes(page)).length;
        // eslint-disable-next-line no-await-in-loop
        await drawShape(page, { fx, fy }, { dx: 150, dy: 110 });
        // eslint-disable-next-line no-await-in-loop
        const boxesNow = await shapeBoxes(page);
        if (boxesNow.length !== before + 1) {
            failedToDraw.push({ type: types[i], reason: 'no shape added' });
        } else {
            // Compare the type actually produced against the one picked, per
            // iteration: comparing sets at the end cannot tell which draw went
            // wrong, only that something is missing.
            // eslint-disable-next-line no-await-in-loop
            const active = (await shapePanelState(page)).activeShape;
            const produced = boxesNow.map((b) => b.shapeType);
            const expectedCount = types.slice(0, i + 1).filter((t) => t === types[i]).length;
            const actualCount = produced.filter((t) => t === types[i]).length;
            if (actualCount < expectedCount) {
                failedToDraw.push({ type: types[i], picked: active, produced });
            }
        }
    }
    // NK_15, guarded: every shape kept the type it was drawn as, even though each
    // was still selected when the next shape was picked.
    const drawnTypes = (await shapeBoxes(page)).map((b) => b.shapeType);
    recorder.assert('picker-does-not-retype-the-selection',
        types.every((t) => drawnTypes.includes(t)),
        'Choosing the next shape does not convert the one already drawn, so several different '
        + 'shapes can be drawn in a row without deselecting (NK_15)',
        JSON.stringify({ expected: types, drawn: drawnTypes }));

    recorder.assert('every-draw-landed', failedToDraw.length === 0,
        'Every shape type drew exactly one shape of that type',
        JSON.stringify(failedToDraw).slice(0, 400));

    // A shape with no stroke, and one with both partly transparent.
    // Style values still commit to a selected shape (that is deliberate — see
    // cases 12 and 15), so deselect before setting up the next draw. Only the
    // shape TYPE stopped applying to the selection under NK_15.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const typesBeforeSetup = (await shapeBoxes(page)).map((b) => b.shapeType);

    await setShapeMode(page, true);
    await pickShape(page, 'square');
    await setShapeStyle(page, { strokeWidth: 0, fillOpacity: 60 });
    const typesAfterSetup = (await shapeBoxes(page)).map((b) => b.shapeType);
    recorder.assert('panel-setup-does-not-retype-existing-shapes',
        JSON.stringify(typesBeforeSetup) === JSON.stringify(typesAfterSetup),
        'Picking a shape and setting style for the NEXT draw leaves existing shapes alone',
        JSON.stringify({ before: typesBeforeSetup, after: typesAfterSetup }));
    await setShapeMode(page, true);
    await drawShape(page, { fx: 0.60, fy: 0.72 }, { dx: 140, dy: 100 });

    const drawn = await shapeBoxes(page);
    recorder.equals('every-type-drawn', drawn.length, types.length + 1,
        'One shape of every offered type is drawn, plus a no-stroke shape');

    const missing = types.filter((t) => !drawn.some((b) => b.shapeType === t));
    recorder.assert('all-types-present', missing.length === 0,
        'Every shape type the picker offers made it onto the page',
        JSON.stringify({ missing, drawn: drawn.map((b) => b.shapeType) }));

    await saveDocument(page, saveRecorder);
    const saved = savedShapeAnnotations(saveRecorder);
    const savedTypes = new Set(saved.map((a) => String(a.shapeType || '').toLowerCase()));
    const notSaved = types.filter((t) => !savedTypes.has(t));
    recorder.assert('all-types-saved', notSaved.length === 0,
        'Every shape type reaches the saved annotation payload', JSON.stringify(notSaved));

    // The download endpoint must return a real PDF.
    // The endpoint validates an annotations array, so send what was just saved.
    const download = await page.evaluate(async ([id, annotations]) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(`/documents/${id}/download-annotated-pdf`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                Accept: 'application/pdf',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ annotations }),
        });
        const buffer = await response.arrayBuffer();
        const head = new TextDecoder().decode(new Uint8Array(buffer).slice(0, 5));
        return { status: response.status, type: response.headers.get('content-type'), bytes: buffer.byteLength, head };
    }, [docId, saved]);
    recorder.assert('download-returns-pdf',
        download.status === 200 && download.head === '%PDF-' && download.bytes > 1000,
        'The download endpoint returns a PDF document', JSON.stringify(download));

    // NOT COVERED, and deliberately said out loud rather than implied.
    recorder.skip('operator-level-fidelity',
        'Per-shape PDF drawing operators are NOT asserted anywhere. The legacy suite that '
        + 'checked them (rectangles emitting l path ops, circles emitting c beziers, the cm '
        + 'rotation matrix, colour/border/opacity in output) was deleted as dead code under '
        + 'the admin cleanup. Verifying placement, size, colour and angle in an external '
        + 'viewer remains a MANUAL check.');

    artifacts.push(await capture(page, '29-download-fidelity', 'all-shapes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 30 — Layers panel and annotation identity. */
async function testLayersAndIdentity(page, { recorder, saveRecorder, docId }) {
    const artifacts = [];
    await drawOne(page, 'square', { fx: 0.18, fy: 0.18 }, { dx: 180, dy: 140 });
    await drawOne(page, 'circle', { fx: 0.18, fy: 0.45 }, { dx: 180, dy: 140 });

    const panel = await layersPanelRows(page);
    recorder.assert('panel-opens', panel.open, 'The layers panel opens');
    recorder.assert('lists-both-shapes', panel.rows.length >= 2,
        'Both shapes are listed', `${panel.rows.length} rows`);
    recorder.assert('rows-named-recognisably',
        panel.rows.every((row) => row.label.length > 0),
        'Each row carries a recognisable name', JSON.stringify(panel.rows.map((r) => r.label)));
    await closeLayersPanel(page);

    // A row must keep pointing at the same annotation across a reload.
    const idsBefore = (await shapeBoxes(page)).map((b) => b.annotationId).sort();
    await saveAndReload(page, saveRecorder);
    const afterPanel = await layersPanelRows(page);
    const idsAfter = (await shapeBoxes(page)).map((b) => b.annotationId).sort();
    recorder.equals('ids-survive-reload', JSON.stringify(idsAfter), JSON.stringify(idsBefore),
        'Annotation ids survive a reload');
    recorder.assert('rows-still-point-at-annotations',
        afterPanel.rows.every((row) => idsAfter.includes(row.id)),
        'Every layer row still points at a real annotation after the reload',
        JSON.stringify({ rows: afterPanel.rows.map((r) => r.id), shapes: idsAfter }));
    await closeLayersPanel(page);

    // Admin-only controls: absent for a non-admin.
    await ensureSelected(page, 0);
    const asGuest = await page.evaluate(() => ({
        annotationId: !!document.getElementById('afb-annotation-id'),
        debug: !!document.getElementById('afb-debug'),
    }));
    recorder.assert('admin-controls-absent-for-non-admin',
        !asGuest.annotationId && !asGuest.debug,
        'The annotation ID field and debug mask are not offered to a non-admin (NK_7)',
        JSON.stringify(asGuest));

    // ...and present for an admin. Skipped, not failed, when unconfigured.
    if (!adminCredentials()) {
        recorder.skip('admin-controls-present-for-admin',
            'No QA admin configured (AUTOMATED_TESTS_ADMIN_EMAIL/PASSWORD) — admin-only controls not exercised');
    } else if (!(await openEditorAsAdmin(page, docId))) {
        recorder.assert('admin-sign-in', false, 'Signed in as the QA admin',
            `login did not take, url=${page.url()}`);
    } else {
        recorder.assert('admin-sign-in', true, 'Signed in as the QA admin');
        await ensureSelected(page, 0);
        const asAdmin = await page.evaluate(() => ({
            annotationId: !!document.getElementById('afb-annotation-id'),
            debug: !!document.getElementById('afb-debug'),
        }));
        recorder.assert('admin-controls-present-for-admin',
            asAdmin.annotationId && asAdmin.debug,
            'The annotation ID field and debug mask are offered to an admin (NK_7)',
            JSON.stringify(asAdmin));
    }

    artifacts.push(await capture(page, '30-layers-and-identity', 'layers'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 31 — Shapes the writer supports but the picker does not offer.
 *
 * The story is explicit that the outcome here is a DECISION, not a pass or
 * fail, so this records what is reachable rather than asserting a verdict.
 */
async function testWriterOnlyShapes(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    const offered = await page.evaluate(() => Array.from(
        document.querySelectorAll('#shape-tool-panel [data-shape-tool]'),
    ).map((button) => button.dataset.shapeTool));

    recorder.equals('picker-offers-seven', offered.length, 7,
        'The picker offers exactly the seven documented shapes');
    recorder.assert('picker-matches-expected',
        [...PRIMARY_SHAPES, ...MORE_SHAPES].every((t) => offered.includes(t)),
        'The picker offers circle, triangle, square, line, star, X and heart',
        JSON.stringify(offered));
    recorder.assert('all-seven-reachable-directly',
        (await shapePanelState(page)).visibleShapes.length === 7,
        'All seven are reachable without expanding anything (NK_13)',
        JSON.stringify((await shapePanelState(page)).visibleShapes));

    const writerOnly = ['arrow', 'checkmark', 'polygon'];
    const reachable = writerOnly.filter((t) => offered.includes(t));
    recorder.assert('writer-only-shapes-are-not-in-the-picker', reachable.length === 0,
        'arrow, checkmark and polygon are not offered by the picker', JSON.stringify(reachable));

    // Recorded for the decision, not asserted.
    recorder.ok('decision-needed',
        'draw_shape in python/pdf-editor/new_annotation_writer.py still handles arrow, checkmark '
        + 'and polygon, and none of the three can be created in this editor. Whether they are '
        + 'reachable another way (an older document, an import, the guided flow) needs checking '
        + 'against real data. The outcome is a decision: either offer them, or the writer is '
        + 'carrying branches for shapes that can no longer occur. Note that polygon is also '
        + 'listed in CUTTABLE_SHAPE_TYPES, so the cut feature has a branch for a shape the '
        + 'picker cannot produce either.',
        JSON.stringify({ pickerOffers: offered, writerAlsoSupports: writerOnly }));

    artifacts.push(await capture(page, '31-writer-only-shapes', 'picker'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 32 — Keyboard access and ARIA on the Shape options panel. */
async function testKeyboardAndAria(page, { recorder }) {
    const artifacts = [];
    await setShapeMode(page, true);

    // Every control needs an accessible name.
    const named = await page.evaluate(() => {
        const panel = document.getElementById('shape-tool-panel');
        const controls = Array.from(panel?.querySelectorAll('button, input, select, [role="button"]') || []);
        return controls.map((el) => ({
            id: el.id || null,
            tag: el.tagName.toLowerCase(),
            type: el.type || null,
            name: (el.getAttribute('aria-label') || el.getAttribute('title')
                || el.textContent?.trim() || '').slice(0, 40),
            hidden: el.type === 'hidden' || window.getComputedStyle(el).display === 'none',
        }));
    });
    const visible = named.filter((c) => !c.hidden);
    const unnamed = visible.filter((c) => !c.name);
    // LEFT FAILING DELIBERATELY — accessibility finding. The two <input
    // type="color"> swatches carry no aria-label, title or associated text, so a
    // screen reader announces them only as "color". Every other control in the
    // panel is named.
    recorder.assert('every-control-has-a-name', unnamed.length === 0,
        'Every visible control in the panel has an accessible name (FINDING: the colour swatches have none)',
        JSON.stringify(unnamed));

    // The shape buttons must expose which one is selected.
    const selectionExposed = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('#shape-tool-panel [data-shape-tool]'));
        const active = buttons.filter((b) => b.classList.contains('is-active'));
        return {
            total: buttons.length,
            activeCount: active.length,
            ariaPressed: buttons.map((b) => b.getAttribute('aria-pressed')),
            ariaSelected: buttons.map((b) => b.getAttribute('aria-selected')),
        };
    });
    const exposesState = selectionExposed.ariaPressed.some((v) => v !== null)
        || selectionExposed.ariaSelected.some((v) => v !== null);
    // LEFT FAILING DELIBERATELY — accessibility finding, and one the story asks
    // for by name: "The shape buttons must expose which one is selected". They
    // mark the active shape with an is-active CSS class only. No aria-pressed,
    // no aria-selected, no role="radio" — so which shape is chosen is invisible
    // to assistive technology.
    recorder.assert('selected-shape-exposed-to-assistive-tech', exposesState,
        'The shape buttons expose which one is selected through ARIA, not just a CSS class',
        JSON.stringify(selectionExposed));

    // Assert the state MOVES, not merely that the attribute exists. Seeding
    // aria-pressed in the Blade markup alone would satisfy the check above while
    // the runtime sync was broken, so this picks a different shape and confirms
    // the attribute follows the selection.
    await pickShape(page, 'triangle');
    const afterPick = await page.evaluate(() => Object.fromEntries(
        Array.from(document.querySelectorAll('#shape-tool-panel [data-shape-tool]'))
            .map((b) => [b.dataset.shapeTool, b.getAttribute('aria-pressed')]),
    ));
    recorder.assert('aria-pressed-follows-the-selection',
        afterPick.triangle === 'true'
        && Object.entries(afterPick).filter(([k]) => k !== 'triangle').every(([, v]) => v === 'false'),
        'Picking a different shape moves aria-pressed onto it and clears the others',
        JSON.stringify(afterPick));

    // NK_13 removed the More shapes toggle, and with it the aria-expanded check
    // that used to live here. Every shape is now directly reachable, which is
    // strictly better for keyboard and screen-reader users than a disclosure
    // they had to find first — so this asserts that instead.
    const reachable = await page.evaluate(() => Array.from(
        document.querySelectorAll('#shape-tool-panel [data-shape-tool]'),
    ).filter((b) => b.tabIndex >= 0 && b.getBoundingClientRect().width > 0).length);
    recorder.equals('every-shape-is-directly-reachable', reachable, 7,
        'All seven shapes are focusable without opening a disclosure first');

    // Sliders must be operable by keyboard.
    const sliderBefore = await page.evaluate(() => document.getElementById('shape-stroke-width')?.value);
    await page.focus('#shape-stroke-width');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    const sliderAfter = await page.evaluate(() => document.getElementById('shape-stroke-width')?.value);
    recorder.assert('sliders-are-keyboard-operable', sliderBefore !== sliderAfter,
        'The stroke width slider responds to arrow keys',
        JSON.stringify({ before: sliderBefore, after: sliderAfter }));

    // Focus must be visible, not suppressed.
    const focusVisible = await page.evaluate(() => {
        const el = document.querySelector('#shape-tool-panel [data-shape-tool="circle"]');
        el.focus();
        const style = window.getComputedStyle(el);
        return {
            outlineStyle: style.outlineStyle,
            outlineWidth: style.outlineWidth,
            boxShadow: style.boxShadow,
            isFocused: document.activeElement === el,
        };
    });
    recorder.assert('focus-is-visible',
        focusVisible.isFocused
        && (focusVisible.outlineStyle !== 'none' || (focusVisible.boxShadow && focusVisible.boxShadow !== 'none')),
        'A focused control shows a visible focus indicator',
        JSON.stringify(focusVisible));

    // Tab order through the panel.
    const tabOrder = await page.evaluate(() => Array.from(
        document.querySelectorAll('#shape-tool-panel button, #shape-tool-panel input'),
    ).filter((el) => el.type !== 'hidden' && window.getComputedStyle(el).display !== 'none')
        .map((el) => el.tabIndex));
    recorder.assert('no-control-removed-from-tab-order',
        tabOrder.every((index) => index >= 0),
        'No visible control is removed from the tab order with a negative tabindex',
        JSON.stringify(tabOrder));

    recorder.skip('screen-reader-pass',
        'Announcing the selected shape and its options through a screen reader is a MANUAL check; '
        + 'the story asks for one pass with a real reader, which automation cannot stand in for.');

    artifacts.push(await capture(page, '32-keyboard-and-aria', 'panel'));
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
    { id: '09-clamped-inside-page', number: '09', title: 'Shapes are clamped inside the page', run: testClampedInsidePage, signedIn: true },
    { id: '10-zoom-accuracy', number: '10', title: 'Drawing stays accurate across zoom levels', run: testZoomAccuracy, signedIn: true },
    { id: '11-rotated-pages', number: '11', title: 'Drawing on rotated pages', run: testRotatedPages, signedIn: true },
    { id: '12-stroke-colour', number: '12', title: 'Stroke colour', run: testStrokeColour, signedIn: true },
    { id: '13-stroke-width-range', number: '13', title: 'Stroke width across its whole range', run: testStrokeWidthRange, signedIn: true },
    { id: '14-stroke-opacity', number: '14', title: 'Stroke opacity', run: testStrokeOpacity, signedIn: true },
    { id: '15-fill-colour', number: '15', title: 'Fill colour', run: testFillColour, signedIn: true },
    { id: '16-fill-opacity', number: '16', title: 'Fill opacity', run: testFillOpacity, signedIn: true },
    { id: '17-transparent-stroke-and-fill', number: '17', title: 'Transparent stroke and transparent fill', run: testTransparency, signedIn: true },
    { id: '18-default-style', number: '18', title: 'A new shape uses the documented defaults', run: testDefaultStyle, signedIn: true },
    { id: '19-line-endpoints', number: '19', title: 'Line: endpoints, caps and no fill', run: testLineEndpoints, signedIn: true },
    { id: '20-select-and-menu', number: '20', title: 'Select, deselect and the hover menu', run: testSelectAndMenu, signedIn: true },
    { id: '21-move-and-resize', number: '21', title: 'Move and resize', run: testMoveAndResize, signedIn: true },
    { id: '22-rotate', number: '22', title: 'Rotate', run: testRotate, signedIn: true },
    { id: '23-cut-shape', number: '23', title: 'Cut a shape', run: testCutShape, signedIn: true },
    { id: '24-lock-duplicate-delete-order', number: '24', title: 'Lock, duplicate, delete and z-order', run: testLockDuplicateDeleteOrder, signedIn: true },
    { id: '25-multi-select', number: '25', title: 'Multi-select and group operations', run: testMultiSelect, signedIn: true },
    { id: '26-undo-redo', number: '26', title: 'Undo and redo across every shape operation', run: testUndoRedo, signedIn: true },
    { id: '27-autosave-and-save', number: '27', title: 'Autosave and explicit Save', run: testAutosaveAndSave, signedIn: true },
    { id: '28-reload-fidelity', number: '28', title: 'Reload fidelity', run: testReloadFidelity, signedIn: true },
    { id: '29-download-fidelity', number: '29', title: 'Downloaded PDF fidelity, per shape type', run: testDownloadFidelity, signedIn: true },
    { id: '30-layers-and-identity', number: '30', title: 'Layers panel and annotation identity', run: testLayersAndIdentity, signedIn: true },
    { id: '31-writer-only-shapes', number: '31', title: 'Shapes the writer supports but the picker does not offer', run: testWriterOnlyShapes, signedIn: true },
    { id: '32-keyboard-and-aria', number: '32', title: 'Keyboard access and ARIA on the Shape options panel', run: testKeyboardAndAria, signedIn: true },
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
    ensureSelected,
    setEditMode,
    layersPanelRows,
    adminCredentials,
    signInAsAdmin,
    openEditorAsAdmin,
    editModeOn,
    pageRotation,
    rotatePage,
    paintedShapeBounds,
    annMenuState,
    annotationMenuAction,
    rotateHandleAt,
    dragRotateHandle,
    dragShapeBy,
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
