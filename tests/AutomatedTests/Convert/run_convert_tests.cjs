/**
 * Convert Tool Automated Tests (Playwright)
 *
 * Automates the "[QA] - Convert tool" Asana task (Netkit -> Claude QA Agent).
 * Each run creates a fresh blank PDF, uploads it as a new document, opens it in
 * the pdf.js editor, and drives the real Convert modal.
 *
 * Scope: the Convert modal and all four tabs -- Images, PDF/A, Word, Excel --
 * including the per-tab Advanced disclosure added by NK_24.
 *
 * WHY WORD AND EXCEL ARE CONTRACT-TESTED RATHER THAN RUN
 * Both are queued jobs behind auth:web,admin, and both CHARGE the account per
 * 50-page transaction. Completing one in a test would need a genuinely
 * authenticated session, a running queue worker, and real credits, and it
 * would bill the account on every run. So those cases intercept the POST,
 * assert the exact form fields the UI sends, and fulfil a canned queued
 * response to drive the rest of the flow. PDF/A is intercepted the same way
 * for its contract, and separately allowed to run for real where the
 * environment can. Image export is exercised end to end for real: it is
 * entirely client-side, costs nothing, and never leaves the browser.
 *
 * Usage:
 *   node run_convert_tests.cjs --list
 *   node run_convert_tests.cjs --run 01-blank-project-loads,09-image-export-download
 *   node run_convert_tests.cjs --run-all
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

/** The four tabs, in the order the modal renders them. */
const TABS = ['images', 'pdfa', 'word', 'excel'];

/** Header text and export-button label per tab, mirrored from setConvertTab(). */
const TAB_META = {
    images: { title: 'Export to Images', button: 'Export' },
    pdfa: { title: 'Convert to PDF/A', button: 'Convert to PDF/A' },
    word: { title: 'Convert to Word', button: 'Convert to Word' },
    excel: { title: 'Convert to Excel', button: 'Convert to Excel' },
};

/** Image size presets, mirrored from the panel markup. */
const IMAGE_DPI_PRESETS = [96, 150, 300];

/** What a fresh Convert modal is created with. */
const CONVERT_DEFAULTS = {
    format: 'jpg',
    dpi: 150,
    quality: '92',
    colorModel: 'rgb',
    smoothing: true,
    pdfaLevel: '2b',
    wordLayout: 'exact',
    excelMode: 'all',
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
    const title = String(label || 'Convert tool automated test').replace(/[()\\]/g, '');
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
    const name = `convert_tool_test_${process.pid}_${uploadCounter}.pdf`;

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
 * Convert is premium — openConvertModal() refuses outright for a guest — so
 * the editor's own authentication flag is rewritten in flight the same way the
 * other suites do it. This shims the CLIENT gate only: the Word and Excel
 * endpoints are still genuinely behind auth:web,admin server-side, which is
 * part of why those cases assert the request rather than its result.
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
// Convert modal
// ---------------------------------------------------------------------------

/** Everything the Convert modal is currently showing. */
function convertModalState(page) {
    return page.evaluate(() => {
        const modal = document.getElementById('enpv-convert-modal');
        const tabs = Array.from(document.querySelectorAll('[data-enpv-convert-tab]')).map((button) => ({
            key: button.dataset.enpvConvertTab,
            active: button.classList.contains('is-active'),
            selected: button.getAttribute('aria-selected'),
            controls: button.getAttribute('aria-controls'),
        }));
        const panels = Array.from(document.querySelectorAll('.enpv-convert-panel')).map((panel) => ({
            id: panel.id,
            hidden: panel.hidden,
        }));
        const progress = document.getElementById('enpv-convert-progress');
        return {
            present: !!modal,
            open: !!modal && modal.hidden === false,
            title: document.getElementById('enpv-convert-title')?.textContent?.trim() || '',
            subtitle: document.getElementById('enpv-convert-subtitle')?.textContent?.trim() || '',
            exportLabel: document.getElementById('enpv-convert-export')?.textContent?.trim() || '',
            exportDisabled: document.getElementById('enpv-convert-export')?.disabled,
            closeDisabled: document.getElementById('enpv-convert-close')?.disabled,
            pageCount: document.getElementById('enpv-convert-page-count')?.textContent?.trim() || '',
            pageCountIsError: !!document.getElementById('enpv-convert-page-count')?.classList?.contains('is-error'),
            sizeEstimate: document.getElementById('enpv-convert-size-estimate')?.textContent?.replace(/\s+/g, ' ').trim() || '',
            progressHidden: progress ? progress.hidden : null,
            progressLabel: document.getElementById('enpv-convert-progress-label')?.textContent?.trim() || '',
            progressPct: document.getElementById('enpv-convert-progress-pct')?.textContent?.trim() || '',
            tabs,
            panels,
            activeTab: (tabs.find((tab) => tab.active) || {}).key || null,
        };
    });
}

async function openConvertModal(page) {
    await page.evaluate(() => document.getElementById('ftb-convert')?.click());
    await page.waitForTimeout(700);
    return convertModalState(page);
}

async function closeConvertModal(page, how = 'close') {
    if (how === 'scrim') {
        // The scrim is the modal element itself; a click on the card must not close it.
        await page.evaluate(() => {
            const modal = document.getElementById('enpv-convert-modal');
            modal?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
    } else {
        const id = how === 'cancel' ? 'enpv-convert-cancel' : 'enpv-convert-close';
        await page.evaluate((elementId) => document.getElementById(elementId)?.click(), id);
    }
    await page.waitForTimeout(500);
    return convertModalState(page);
}

async function setConvertTab(page, tab) {
    await page.evaluate((key) => document.querySelector(`[data-enpv-convert-tab="${key}"]`)?.click(), tab);
    await page.waitForTimeout(450);
    return convertModalState(page);
}

/** The Advanced disclosure for one tab (NK_24). */
function advancedState(page, tab) {
    return page.evaluate((key) => {
        const toggle = document.getElementById(`enpv-convert-advanced-${key}`);
        const panel = document.getElementById(`enpv-convert-advanced-${key}-panel`);
        return {
            togglePresent: !!toggle,
            checked: toggle ? toggle.checked : null,
            disabled: toggle ? toggle.disabled : null,
            expanded: toggle ? toggle.getAttribute('aria-expanded') : null,
            controls: toggle ? toggle.getAttribute('aria-controls') : null,
            panelPresent: !!panel,
            panelHidden: panel ? panel.hidden : null,
            panelVisible: !!panel && panel.getBoundingClientRect().height > 0,
        };
    }, tab);
}

async function setAdvanced(page, tab, open) {
    await page.evaluate(([key, wanted]) => {
        const toggle = document.getElementById(`enpv-convert-advanced-${key}`);
        if (toggle && toggle.checked !== wanted) toggle.click();
    }, [tab, Boolean(open)]);
    await page.waitForTimeout(350);
    return advancedState(page, tab);
}

/** Pick an image format / size / page mode through the real choice buttons. */
async function chooseImageOption(page, attribute, value) {
    await page.evaluate(([attr, val]) => {
        document.querySelector(`[data-enpv-${attr}="${val}"]`)?.click();
    }, [attribute, String(value)]);
    await page.waitForTimeout(400);
}

/** Every image-tab control's current value. */
function imageSettings(page) {
    return page.evaluate(() => ({
        format: document.querySelector('[data-enpv-format].is-active')?.dataset.enpvFormat || null,
        dpi: Number(document.querySelector('[data-enpv-image-dpi].is-active')?.dataset.enpvImageDpi) || null,
        pageMode: document.querySelector('[data-enpv-pages].is-active')?.dataset.enpvPages || null,
        rangeHidden: document.getElementById('enpv-convert-range')?.hidden,
        customHidden: document.getElementById('enpv-convert-custom-wrap')?.hidden,
        colorModel: document.getElementById('enpv-convert-color-model')?.value,
        colorHint: document.getElementById('enpv-convert-color-hint')?.textContent?.trim() || '',
        rgbaDisabled: document.querySelector('#enpv-convert-color-model option[value="rgba"]')?.disabled,
        rgbaLabel: document.querySelector('#enpv-convert-color-model option[value="rgba"]')?.textContent?.trim() || '',
        quality: document.getElementById('enpv-convert-quality')?.value,
        qualityDisabled: document.getElementById('enpv-convert-quality')?.disabled,
        qualityWrapDisabled: !!document.getElementById('enpv-convert-quality-wrap')?.classList?.contains('is-disabled'),
        smoothing: document.getElementById('enpv-convert-smoothing')?.checked,
    }));
}

async function setPageRange(page, from, to) {
    await page.evaluate(([f, t]) => {
        const setValue = (id, value) => {
            const input = document.getElementById(id);
            if (!input) return;
            input.value = String(value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };
        setValue('enpv-convert-page-from', f);
        setValue('enpv-convert-page-to', t);
    }, [from, to]);
    await page.waitForTimeout(450);
}

async function setCustomPages(page, text) {
    await page.evaluate((value) => {
        const input = document.getElementById('enpv-convert-page-custom');
        if (!input) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, text);
    await page.waitForTimeout(600);
}

async function clickExport(page) {
    await page.evaluate(() => document.getElementById('enpv-convert-export')?.click());
}

// ---------------------------------------------------------------------------
// Request capture
// ---------------------------------------------------------------------------

/**
 * Read a multipart body's simple text fields.
 *
 * The conversion endpoints are posted as FormData with the edited PDF
 * attached, so postDataJSON() is not available; the scalar fields are what
 * these cases assert on and they are trivially recoverable from the raw body.
 */
function multipartFields(body) {
    const fields = {};
    const text = String(body || '');
    const re = /name="([^"]+)"(?:; filename="([^"]*)")?\r?\n(?:Content-Type:[^\r\n]*\r?\n)?\r?\n([\s\S]*?)\r?\n--/g;
    let match = re.exec(text);
    while (match) {
        const [, name, filename, value] = match;
        fields[name] = filename == null ? value : `<file:${filename}>`;
        match = re.exec(text);
    }
    return fields;
}

/**
 * Intercept one conversion POST, capture its fields, and answer with a canned
 * response so the UI carries on without a worker, credits, or a real session.
 */
async function interceptConversion(page, urlPattern, respondWith) {
    const captured = { fields: null, url: null, count: 0 };
    await page.route(urlPattern, async (route) => {
        const request = route.request();
        captured.count += 1;
        captured.url = request.url();
        captured.fields = multipartFields(request.postData());
        await route.fulfill({
            status: respondWith.status || 200,
            contentType: 'application/json',
            body: JSON.stringify(respondWith.body || {}),
        });
    });
    return captured;
}

/** Wait until an intercepted request has actually been made. */
async function waitForCapture(page, captured, timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    while (captured.count === 0 && Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(200);
    }
    return captured.count > 0;
}

// ---------------------------------------------------------------------------
// Reading an exported file
// ---------------------------------------------------------------------------

/**
 * Page geometry an export should produce at a given DPI.
 *
 * renderPdfPagesToImages() scales by dpi/72 and ceils, so this is the exact
 * pixel size the encoder is expected to emit rather than an approximation.
 */
function expectedPixelSize(dpi) {
    const scale = dpi / 72;
    return {
        width: Math.ceil(PAGE_WIDTH_PTS * scale),
        height: Math.ceil(PAGE_HEIGHT_PTS * scale),
    };
}

/** Width, height and validity of a JPEG, read from its own markers. */
function jpegInfo(buffer) {
    if (buffer.length < 4 || buffer[0] !== 0xff || buffer[1] !== 0xd8) {
        return { valid: false, reason: 'no JPEG SOI marker' };
    }
    let offset = 2;
    while (offset < buffer.length - 9) {
        if (buffer[offset] !== 0xff) { offset += 1; continue; }
        const marker = buffer[offset + 1];
        // SOF0/1/2/3 and the other non-differential frame headers carry the size.
        if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].includes(marker)) {
            return {
                valid: true,
                height: buffer.readUInt16BE(offset + 5),
                width: buffer.readUInt16BE(offset + 7),
                components: buffer[offset + 9],
            };
        }
        if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
            offset += 2;
            continue;
        }
        offset += 2 + buffer.readUInt16BE(offset + 2);
    }
    return { valid: false, reason: 'no frame header found' };
}

/** Width, height and colour type of a PNG, read from its IHDR. */
function pngInfo(buffer) {
    const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
    if (buffer.length < 33 || !buffer.subarray(0, 8).equals(signature)) {
        return { valid: false, reason: 'no PNG signature' };
    }
    if (buffer.subarray(12, 16).toString('latin1') !== 'IHDR') {
        return { valid: false, reason: 'first chunk is not IHDR' };
    }
    return {
        valid: true,
        width: buffer.readUInt32BE(16),
        height: buffer.readUInt32BE(20),
        bitDepth: buffer[24],
        colourType: buffer[25],
    };
}

/**
 * The file names inside a ZIP, read from its central directory.
 *
 * Parsed here rather than shelling out to unzip so the check works wherever
 * the suite runs, and so a truncated or empty archive is reported as such
 * instead of silently passing a size check.
 */
function zipEntries(buffer) {
    // The end-of-central-directory record is last, after a variable comment.
    let eocd = -1;
    for (let i = buffer.length - 22; i >= 0 && i > buffer.length - 65558; i -= 1) {
        if (buffer.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
    }
    if (eocd < 0) return { valid: false, entries: [], reason: 'no end-of-central-directory record' };

    const count = buffer.readUInt16LE(eocd + 10);
    let offset = buffer.readUInt32LE(eocd + 16);
    const entries = [];
    for (let i = 0; i < count; i += 1) {
        if (offset + 46 > buffer.length || buffer.readUInt32LE(offset) !== 0x02014b50) {
            return { valid: false, entries, reason: `central directory entry ${i} is malformed` };
        }
        const nameLength = buffer.readUInt16LE(offset + 28);
        const extraLength = buffer.readUInt16LE(offset + 30);
        const commentLength = buffer.readUInt16LE(offset + 32);
        entries.push({
            name: buffer.subarray(offset + 46, offset + 46 + nameLength).toString('utf8'),
            compressedSize: buffer.readUInt32LE(offset + 20),
            uncompressedSize: buffer.readUInt32LE(offset + 24),
        });
        offset += 46 + nameLength + extraLength + commentLength;
    }
    return { valid: true, entries, count };
}

/**
 * Decode an exported image in the browser and describe its pixels.
 *
 * The point is to look at what the encoder actually produced rather than at
 * what the UI said it would produce: whether anything was drawn at all, and
 * whether a grayscale export really is grayscale. Decoding is done by the
 * browser's own image pipeline, so it is the same decoder a user's viewer
 * would use rather than a hand-rolled one.
 */
function inspectImagePixels(page, buffer, mimeType) {
    return page.evaluate(async ({ base64, type }) => {
        const dataUrl = `data:${type};base64,${base64}`;
        const image = await new Promise((resolve, reject) => {
            const el = new Image();
            el.onload = () => resolve(el);
            el.onerror = () => reject(new Error('the browser could not decode the exported image'));
            el.src = dataUrl;
        });
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d');
        context.drawImage(image, 0, 0);
        const { data } = context.getImageData(0, 0, canvas.width, canvas.height);

        let dark = 0;
        let coloured = 0;
        let sampled = 0;
        let minLuma = 255;
        // Sample on a stride: a full 1275x1650 scan is millions of pixels and
        // proves nothing a representative sample does not.
        for (let i = 0; i < data.length; i += 4 * 37) {
            const r = data[i];
            const g = data[i + 1];
            const b = data[i + 2];
            sampled += 1;
            if (r !== g || g !== b) coloured += 1;
            const luma = (0.299 * r) + (0.587 * g) + (0.114 * b);
            if (luma < 200) dark += 1;
            if (luma < minLuma) minLuma = luma;
        }
        return {
            width: canvas.width,
            height: canvas.height,
            sampled,
            darkRatio: sampled ? dark / sampled : 0,
            colouredRatio: sampled ? coloured / sampled : 0,
            minLuma: Math.round(minLuma),
        };
    }, { base64: buffer.toString('base64'), type: mimeType });
}

/** Save a Playwright download and hand back its bytes. */
async function saveDownload(download, filename) {
    ensureArtifactDir();
    const target = path.join(ARTIFACT_DIR, filename);
    await download.saveAs(target);
    try { fs.chmodSync(target, 0o666); } catch (_) { /* best effort */ }
    return { path: target, buffer: fs.readFileSync(target) };
}

/**
 * The status line under the toolbar, where conversions report their outcome.
 *
 * setStatus() signals an error with an inline colour rather than a class, so
 * that is what has to be read back: #f88 for a failure, #ddd otherwise.
 */
function statusText(page) {
    return page.evaluate(() => {
        const el = document.getElementById('enpv-status');
        if (!el) return { text: '', isError: false, colour: null };
        const colour = window.getComputedStyle(el).color.replace(/\s/g, '');
        return {
            text: el.textContent?.trim() || '',
            isError: colour === 'rgb(255,136,136)',
            colour,
        };
    });
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: the blank project loads with the Convert tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const canvas = await page.evaluate(() => {
        const element = document.querySelector('#viewer .page canvas');
        return element ? { width: element.width, height: element.height } : null;
    });
    recorder.assert('page-rasterised', !!canvas && canvas.width > 0 && canvas.height > 0,
        'Page 1 rasterises', JSON.stringify(canvas));

    const button = await page.evaluate(() => {
        const el = document.getElementById('ftb-convert');
        if (!el) return null;
        const rect = el.getBoundingClientRect();
        return { visible: rect.width > 0 && rect.height > 0, disabled: !!el.disabled };
    });
    recorder.assert('tool-present', !!button, 'The floating toolbar offers a Convert button');
    recorder.assert('tool-usable', !!button && button.visible && !button.disabled,
        'The Convert button is visible and enabled', JSON.stringify(button));

    const state = await convertModalState(page);
    recorder.assert('modal-present', state.present, 'The Convert modal is in the DOM');
    recorder.assert('modal-closed', !state.open, 'The Convert modal starts closed');
    recorder.equals('four-tabs', state.tabs.length, 4, 'It offers exactly four tabs');
    recorder.equals('tab-keys', state.tabs.map((tab) => tab.key).join(','), TABS.join(','),
        'The tabs are Images, PDF/A, Word and Excel, in that order');

    const fatal = (consoleErrors || []).filter((message) => !/favicon|fonts\.(googleapis|gstatic)/i.test(message));
    recorder.assert('console-clean', fatal.length === 0, 'The editor loads without console errors',
        fatal.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — The modal opens and closes every documented way. */
async function testModalOpenClose(page, { recorder }) {
    const artifacts = [];

    const opened = await openConvertModal(page);
    recorder.assert('opens', opened.open, 'The Convert button opens the modal');
    recorder.equals('opens-on-images', opened.activeTab, 'images', 'It opens on the Images tab');
    recorder.equals('images-panel-shown',
        opened.panels.find((panel) => panel.id === 'enpv-convert-images')?.hidden, false,
        'The Images panel is the one on show');
    recorder.assert('other-panels-hidden',
        opened.panels.filter((panel) => panel.id !== 'enpv-convert-images').every((panel) => panel.hidden === true),
        'Every other panel is hidden');

    artifacts.push(await capture(page, '02-modal-open-close', 'open'));

    for (const how of ['close', 'cancel', 'scrim']) {
        // eslint-disable-next-line no-await-in-loop
        await openConvertModal(page);
        // eslint-disable-next-line no-await-in-loop
        const closed = await closeConvertModal(page, how);
        recorder.assert(`closes-via-${how}`, !closed.open, `The modal closes via ${how}`);
    }

    // A click inside the card must not dismiss it.
    await openConvertModal(page);
    await page.evaluate(() => document.querySelector('.enpv-convert-card')?.dispatchEvent(
        new MouseEvent('click', { bubbles: true }),
    ));
    await page.waitForTimeout(400);
    recorder.assert('card-click-keeps-it-open', (await convertModalState(page)).open,
        'Clicking inside the card does not close the modal');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Switching tabs re-dresses the modal. */
async function testTabSwitching(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    for (const tab of TABS) {
        // eslint-disable-next-line no-await-in-loop
        const state = await setConvertTab(page, tab);
        recorder.equals(`${tab}-active`, state.activeTab, tab, `The ${tab} tab activates`);
        recorder.equals(`${tab}-title`, state.title, TAB_META[tab].title,
            `The header names the ${tab} conversion`);
        recorder.equals(`${tab}-button`, state.exportLabel, TAB_META[tab].button,
            `The action button reads "${TAB_META[tab].button}"`);
        recorder.equals(`${tab}-panel-shown`,
            state.panels.find((panel) => panel.id === `enpv-convert-${tab}`)?.hidden, false,
            `The ${tab} panel is the one on show`);
        recorder.assert(`${tab}-aria-selected`,
            state.tabs.find((entry) => entry.key === tab)?.selected === 'true'
            && state.tabs.filter((entry) => entry.key !== tab).every((entry) => entry.selected === 'false'),
            `aria-selected marks only the ${tab} tab`);
    }

    // Word and Excel are billed per transaction, so the summary must say so.
    const word = await setConvertTab(page, 'word');
    recorder.assert('word-shows-charge', /charge:\s*\$/i.test(word.pageCount),
        'The Word tab states the charge before converting', word.pageCount);
    const excel = await setConvertTab(page, 'excel');
    recorder.assert('excel-shows-charge', /charge:\s*\$/i.test(excel.pageCount),
        'The Excel tab states the charge before converting', excel.pageCount);

    artifacts.push(await capture(page, '03-tab-switching', 'excel'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — NK_24: the Advanced disclosure, on every tab. */
async function testAdvancedToggle(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    for (const tab of TABS) {
        // eslint-disable-next-line no-await-in-loop
        await setConvertTab(page, tab);
        // eslint-disable-next-line no-await-in-loop
        const collapsed = await advancedState(page, tab);
        recorder.assert(`${tab}-toggle-present`, collapsed.togglePresent,
            `The ${tab} tab offers an Advanced toggle`);
        recorder.equals(`${tab}-off-by-default`, collapsed.checked, false,
            `${tab}: Advanced starts off`);
        recorder.equals(`${tab}-hidden-by-default`, collapsed.panelHidden, true,
            `${tab}: the advanced options start hidden`);
        recorder.equals(`${tab}-aria-collapsed`, collapsed.expanded, 'false',
            `${tab}: aria-expanded reports the collapsed state`);
        recorder.equals(`${tab}-aria-controls`, collapsed.controls, `enpv-convert-advanced-${tab}-panel`,
            `${tab}: the toggle names the panel it controls`);

        // eslint-disable-next-line no-await-in-loop
        const expanded = await setAdvanced(page, tab, true);
        recorder.assert(`${tab}-opens`, expanded.panelHidden === false && expanded.panelVisible,
            `${tab}: checking Advanced reveals the options`);
        recorder.equals(`${tab}-aria-expanded`, expanded.expanded, 'true',
            `${tab}: aria-expanded reports the open state`);
    }

    // Per-tab: opening one leaves the others as they were.
    await page.evaluate(() => {
        document.querySelectorAll('[data-enpv-convert-advanced]').forEach((toggle) => {
            if (toggle.checked) {
                toggle.checked = false;
                toggle.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });
    await page.waitForTimeout(300);
    await setConvertTab(page, 'images');
    await setAdvanced(page, 'images', true);
    const others = await Promise.all(TABS.slice(1).map((tab) => advancedState(page, tab)));
    recorder.assert('per-tab-independent', others.every((state) => state.panelHidden === true),
        'Opening one tab\'s Advanced leaves the other three closed',
        TABS.slice(1).map((tab, i) => `${tab}=${others[i].panelHidden}`).join(' '));

    // The choice survives a close and reopen, since the modal is hidden rather
    // than re-rendered.
    await closeConvertModal(page, 'cancel');
    await openConvertModal(page);
    const persisted = await advancedState(page, 'images');
    recorder.equals('persists-across-reopen', persisted.panelHidden, false,
        'An open Advanced panel is still open when the modal is reopened');

    artifacts.push(await capture(page, '04-advanced-toggle', 'images-open'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — Image format, and the alpha-only colour option. */
async function testImageFormats(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);
    await setAdvanced(page, 'images', true);

    let settings = await imageSettings(page);
    recorder.equals('default-format', settings.format, CONVERT_DEFAULTS.format,
        'JPG is the default format');
    recorder.equals('default-color', settings.colorModel, CONVERT_DEFAULTS.colorModel,
        'Colour is the default colour model');
    recorder.equals('jpg-disallows-alpha', settings.rgbaDisabled, true,
        'Transparent background is not offered for JPG');
    recorder.assert('jpg-alpha-explained', /png\/tiff only/i.test(settings.rgbaLabel),
        'The disabled option says why', settings.rgbaLabel);

    for (const format of ['png', 'tiff']) {
        // eslint-disable-next-line no-await-in-loop
        await chooseImageOption(page, 'format', format);
        // eslint-disable-next-line no-await-in-loop
        settings = await imageSettings(page);
        recorder.equals(`${format}-selected`, settings.format, format, `${format.toUpperCase()} can be chosen`);
        recorder.equals(`${format}-allows-alpha`, settings.rgbaDisabled, false,
            `${format.toUpperCase()} offers a transparent background`);
    }

    // Choosing transparency and then dropping back to JPG must not leave an
    // impossible combination selected.
    await page.evaluate(() => {
        const select = document.getElementById('enpv-convert-color-model');
        select.value = 'rgba';
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(300);
    recorder.equals('alpha-chosen', (await imageSettings(page)).colorModel, 'rgba',
        'Transparency can be chosen while PNG/TIFF is active');
    await chooseImageOption(page, 'format', 'jpg');
    recorder.equals('alpha-reverted-for-jpg', (await imageSettings(page)).colorModel, 'rgb',
        'Switching back to JPG drops transparency rather than exporting something impossible');

    artifacts.push(await capture(page, '05-image-formats', 'formats'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — The image size presets. */
async function testImageSizes(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    const offered = await page.evaluate(() => Array.from(document.querySelectorAll('[data-enpv-image-dpi]'))
        .map((button) => Number(button.dataset.enpvImageDpi)));
    recorder.equals('three-presets', offered.join(','), IMAGE_DPI_PRESETS.join(','),
        'The three size presets map to 96, 150 and 300 DPI');

    recorder.equals('default-dpi', (await imageSettings(page)).dpi, CONVERT_DEFAULTS.dpi,
        'Standard (150 DPI) is the default');

    for (const dpi of IMAGE_DPI_PRESETS) {
        // eslint-disable-next-line no-await-in-loop
        await chooseImageOption(page, 'image-dpi', dpi);
        // eslint-disable-next-line no-await-in-loop
        const settings = await imageSettings(page);
        recorder.equals(`dpi-${dpi}-selected`, settings.dpi, dpi, `The ${dpi} DPI preset can be chosen`);
    }

    // Only one preset may be active at a time.
    const activeCount = await page.evaluate(
        () => document.querySelectorAll('[data-enpv-image-dpi].is-active').length,
    );
    recorder.equals('one-active-preset', activeCount, 1, 'Exactly one size preset is active');

    artifacts.push(await capture(page, '06-image-sizes', 'sizes'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Page selection, including a malformed custom range. */
async function testPageSelection(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    let settings = await imageSettings(page);
    recorder.equals('default-mode', settings.pageMode, 'all', 'All Pages is the default');
    recorder.equals('range-hidden', settings.rangeHidden, true, 'The range fields start hidden');
    recorder.equals('custom-hidden', settings.customHidden, true, 'The custom field starts hidden');

    let state = await convertModalState(page);
    recorder.assert('all-pages-summary', /all 5 pages/i.test(state.pageCount),
        'All Pages reports the whole document', state.pageCount);

    await chooseImageOption(page, 'pages', 'range');
    settings = await imageSettings(page);
    recorder.equals('range-revealed', settings.rangeHidden, false, 'Page Range reveals the from/to fields');
    await setPageRange(page, 2, 4);
    state = await convertModalState(page);
    recorder.assert('range-summary', /3 of 5/i.test(state.pageCount),
        'A 2-4 range reports three of five pages', state.pageCount);

    await chooseImageOption(page, 'pages', 'custom');
    settings = await imageSettings(page);
    recorder.equals('custom-revealed', settings.customHidden, false, 'Custom reveals the free-text field');
    await setCustomPages(page, '1, 3-4');
    state = await convertModalState(page);
    recorder.assert('custom-summary', /3 of 5/i.test(state.pageCount),
        '"1, 3-4" reports three of five pages', state.pageCount);
    recorder.assert('custom-not-an-error', !state.pageCountIsError,
        'A valid custom range is not flagged as an error');

    // A range the document cannot satisfy must be refused, and refused visibly.
    await setCustomPages(page, '99-120');
    state = await convertModalState(page);
    recorder.assert('bad-custom-flagged', state.pageCountIsError,
        'An out-of-range custom selection is flagged', state.pageCount);

    await setCustomPages(page, 'not pages at all');
    state = await convertModalState(page);
    recorder.assert('garbage-custom-flagged', state.pageCountIsError,
        'A malformed custom selection is flagged', state.pageCount);

    artifacts.push(await capture(page, '07-page-selection', 'invalid-custom'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — The summary and the size estimate track the settings. */
async function testSummaryAndEstimate(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    const settled = async () => {
        for (let attempt = 0; attempt < 25; attempt++) {
            // eslint-disable-next-line no-await-in-loop
            const state = await convertModalState(page);
            if (!/calculating/i.test(state.sizeEstimate)) return state;
            // eslint-disable-next-line no-await-in-loop
            await page.waitForTimeout(400);
        }
        return convertModalState(page);
    };

    const initial = await settled();
    recorder.assert('estimate-resolves', !/calculating/i.test(initial.sizeEstimate),
        'The size estimate resolves to a figure', initial.sizeEstimate);
    recorder.assert('estimate-has-a-size', /\d/.test(initial.sizeEstimate),
        'The estimate quotes a number', initial.sizeEstimate);

    // A larger DPI must not estimate smaller than a smaller one.
    await chooseImageOption(page, 'image-dpi', 96);
    const small = await settled();
    await chooseImageOption(page, 'image-dpi', 300);
    const large = await settled();
    const bytes = (text) => {
        const match = String(text).match(/([\d.]+)\s*(B|KB|MB|GB)/i);
        if (!match) return null;
        const units = { b: 1, kb: 1024, mb: 1024 ** 2, gb: 1024 ** 3 };
        return Number(match[1]) * units[match[2].toLowerCase()];
    };
    const smallBytes = bytes(small.sizeEstimate);
    const largeBytes = bytes(large.sizeEstimate);
    if (smallBytes == null || largeBytes == null) {
        recorder.skip('estimate-grows-with-dpi', 'The estimate is not quoted in a parseable size',
            `${small.sizeEstimate} / ${large.sizeEstimate}`);
    } else {
        recorder.assert('estimate-grows-with-dpi', largeBytes >= smallBytes,
            'Print DPI does not estimate smaller than Screen DPI',
            `96dpi ${small.sizeEstimate} vs 300dpi ${large.sizeEstimate}`);
    }

    // Fewer pages, smaller job.
    await chooseImageOption(page, 'pages', 'range');
    await setPageRange(page, 1, 1);
    const onePage = await settled();
    recorder.assert('summary-follows-range', /1 of 5/i.test(onePage.pageCount),
        'The summary follows the chosen range', onePage.pageCount);

    artifacts.push(await capture(page, '08-summary-and-estimate', 'estimate'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 09 — A real image export, opened and inspected.
 *
 * A download event and a plausible file size prove only that something
 * arrived. These checks open what arrived: the JPEG's own frame header for
 * its dimensions, the browser's decoder for its pixels, and the ZIP's central
 * directory for its entries. A blank page, a truncated file or an archive
 * with the wrong pages in it would all pass a size check and fail here.
 */
async function testImageExportDownload(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    const expected = expectedPixelSize(CONVERT_DEFAULTS.dpi);

    // One page exports as a bare image.
    await chooseImageOption(page, 'pages', 'range');
    await setPageRange(page, 1, 1);
    const singleDownload = page.waitForEvent('download', { timeout: 90000 });
    await clickExport(page);
    const single = await singleDownload.catch(() => null);
    recorder.assert('single-page-downloads', !!single, 'Exporting one page downloads a file');

    if (single) {
        const name = single.suggestedFilename();
        recorder.assert('single-is-an-image', /\.(jpg|jpeg)$/i.test(name),
            'One page comes down as a JPG rather than an archive', name);

        const saved = await saveDownload(single, '09-single.jpg');
        const info = jpegInfo(saved.buffer);
        recorder.assert('single-is-a-real-jpeg', info.valid,
            'The file really is a JPEG, by its own markers', info.reason || 'SOI + frame header present');
        recorder.equals('single-width', info.width, expected.width,
            `At 150 DPI the page is ${expected.width}px wide`);
        recorder.equals('single-height', info.height, expected.height,
            `At 150 DPI the page is ${expected.height}px tall`);

        const pixels = await inspectImagePixels(page, saved.buffer, 'image/jpeg');
        recorder.equals('single-decodes', pixels.width, expected.width,
            'The browser decodes it back to the same width');
        // The fixture prints a line of text near the top of every page, so a
        // correctly rendered export cannot be uniformly white.
        recorder.assert('single-is-not-blank', pixels.darkRatio > 0,
            'The exported page has the fixture\'s text on it rather than being blank',
            `${(pixels.darkRatio * 100).toFixed(2)}% of sampled pixels are dark, darkest luma ${pixels.minLuma}`);
    }

    await page.waitForTimeout(1500);

    // Several pages are archived instead.
    await openConvertModal(page);
    await chooseImageOption(page, 'pages', 'all');
    const zipDownload = page.waitForEvent('download', { timeout: 120000 });
    await clickExport(page);
    const zipped = await zipDownload.catch(() => null);
    recorder.assert('multi-page-downloads', !!zipped, 'Exporting several pages downloads a file');

    if (zipped) {
        const name = zipped.suggestedFilename();
        recorder.assert('multi-is-a-zip', /\.zip$/i.test(name),
            'Several pages come down as a ZIP archive', name);

        const saved = await saveDownload(zipped, '09-pages.zip');
        const archive = zipEntries(saved.buffer);
        recorder.assert('zip-is-readable', archive.valid,
            'The archive has a readable central directory',
            archive.reason || `${archive.count} entries`);
        // The fixture for this case has three pages.
        recorder.equals('zip-has-one-file-per-page', archive.entries.length, 3,
            'The archive holds one file per page');
        recorder.assert('zip-entries-are-images',
            archive.entries.every((entry) => /\.jpe?g$/i.test(entry.name)),
            'Every entry is a JPG', archive.entries.map((e) => e.name).join(', '));
        recorder.assert('zip-entries-are-named-per-page',
            [1, 2, 3].every((n) => archive.entries.some((entry) => entry.name.includes(`_page-${n}`))),
            'The entries are named for the pages they came from',
            archive.entries.map((e) => e.name).join(', '));
        recorder.assert('zip-entries-have-bytes',
            archive.entries.every((entry) => entry.uncompressedSize > 1000),
            'No entry is empty',
            archive.entries.map((e) => `${e.name}=${e.uncompressedSize}B`).join(' '));
    }

    const status = await statusText(page);
    recorder.assert('reports-success', /exported/i.test(status.text) && !status.isError,
        'The status bar reports the export', status.text);

    artifacts.push(await capture(page, '09-image-export-download', 'exported'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/**
 * 10 — NK_24's promise: a collapsed Advanced panel still exports its settings.
 *
 * Asserted against the telemetry the export posts, which records the settings
 * it actually used rather than the ones the panel happens to be showing.
 */
async function testHiddenAdvancedStillApplies(page, { recorder }) {
    const artifacts = [];
    const logged = [];
    page.on('request', (request) => {
        if (/log-export|activity/i.test(request.url()) && request.method() === 'POST') {
            try { logged.push(request.postDataJSON()); } catch (_) { /* not JSON */ }
        }
    });

    await openConvertModal(page);
    await setAdvanced(page, 'images', true);

    // Move every advanced control off its default, then collapse the panel.
    await page.evaluate(() => {
        const colour = document.getElementById('enpv-convert-color-model');
        colour.value = 'grayscale';
        colour.dispatchEvent(new Event('change', { bubbles: true }));
        const quality = document.getElementById('enpv-convert-quality');
        quality.value = '41';
        quality.dispatchEvent(new Event('input', { bubbles: true }));
        quality.dispatchEvent(new Event('change', { bubbles: true }));
        const smoothing = document.getElementById('enpv-convert-smoothing');
        if (smoothing.checked) smoothing.click();
    });
    await page.waitForTimeout(400);

    const beforeCollapse = await imageSettings(page);
    recorder.equals('colour-set', beforeCollapse.colorModel, 'grayscale', 'Colour was changed to Grayscale');
    recorder.equals('quality-set', beforeCollapse.quality, '41', 'Quality was changed to 41');
    recorder.equals('smoothing-set', beforeCollapse.smoothing, false, 'Anti-aliasing was switched off');

    const collapsed = await setAdvanced(page, 'images', false);
    recorder.equals('panel-collapsed', collapsed.panelHidden, true, 'The Advanced panel is collapsed again');

    const afterCollapse = await imageSettings(page);
    recorder.equals('colour-survives', afterCollapse.colorModel, 'grayscale',
        'Collapsing keeps the chosen colour model');
    recorder.equals('quality-survives', afterCollapse.quality, '41',
        'Collapsing keeps the chosen quality');
    recorder.equals('smoothing-survives', afterCollapse.smoothing, false,
        'Collapsing keeps anti-aliasing switched off');

    // Export with the panel collapsed and read back what was actually used.
    await chooseImageOption(page, 'pages', 'range');
    await setPageRange(page, 1, 1);
    const download = page.waitForEvent('download', { timeout: 90000 });
    await clickExport(page);
    await download.catch(() => null);
    await page.waitForTimeout(1200);

    const record = logged.find((entry) => entry && entry.category === 'image_export');
    if (!record) {
        recorder.skip('telemetry-captured', 'No image_export telemetry was posted to read back');
        recorder.skip('hidden-settings-applied', 'No telemetry to assert against');
    } else {
        recorder.ok('telemetry-captured', 'The export posted a record of the settings it used');
        const details = record.details || record;
        recorder.equals('hidden-colour-applied', String(details.color_model), 'grayscale',
            'The collapsed panel\'s colour model reached the export');
        recorder.equals('hidden-quality-applied', Number(details.quality), 41,
            'The collapsed panel\'s quality reached the export');
        recorder.equals('hidden-antialias-applied', details.anti_aliasing, false,
            'The collapsed panel\'s anti-aliasing setting reached the export');
    }

    artifacts.push(await capture(page, '10-hidden-advanced-applies', 'collapsed'));

    // Telemetry is the client's own account of what it did. The pixels are the
    // evidence. Export the same collapsed grayscale setting as a PNG -- lossless,
    // so a colour channel that survives is a real colour channel rather than a
    // JPEG chroma artefact -- and read the decoded image back.
    await openConvertModal(page);
    await chooseImageOption(page, 'format', 'png');
    await chooseImageOption(page, 'pages', 'range');
    await setPageRange(page, 1, 1);
    const collapsedNow = await advancedState(page, 'images');
    recorder.equals('still-collapsed-for-png', collapsedNow.panelHidden, true,
        'The Advanced panel is still collapsed for the second export');

    const pngDownload = page.waitForEvent('download', { timeout: 90000 });
    await clickExport(page);
    const png = await pngDownload.catch(() => null);
    recorder.assert('png-downloaded', !!png, 'The PNG export downloads');

    if (png) {
        const saved = await saveDownload(png, '10-grayscale.png');
        const info = pngInfo(saved.buffer);
        recorder.assert('png-is-a-real-png', info.valid,
            'The file really is a PNG, by its signature and IHDR', info.reason || 'IHDR present');

        const pixels = await inspectImagePixels(page, saved.buffer, 'image/png');
        recorder.assert('png-is-not-blank', pixels.darkRatio > 0,
            'The exported page has real content on it rather than being blank',
            `${(pixels.darkRatio * 100).toFixed(2)}% of sampled pixels are dark`);
        // The proof: a grayscale export has no pixel where the three channels
        // disagree. This is what telemetry alone cannot establish.
        recorder.equals('png-pixels-are-grayscale', pixels.colouredRatio, 0,
            'Every pixel of the grayscale export really is grey (R=G=B)');
        recorder.ok('grayscale-evidence',
            'The collapsed Advanced setting is proved by the pixels, not just the telemetry',
            `${pixels.sampled} pixels sampled, ${pixels.colouredRatio * 100}% coloured, darkest luma ${pixels.minLuma}`);
    }

    artifacts.push(await capture(page, '10-hidden-advanced-applies', 'grayscale-png'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — JPG Quality only applies to JPG. */
async function testQualityGating(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);
    await setAdvanced(page, 'images', true);

    await chooseImageOption(page, 'format', 'jpg');
    let settings = await imageSettings(page);
    recorder.equals('jpg-quality-enabled', settings.qualityDisabled, false,
        'JPG Quality is available for JPG');
    recorder.equals('jpg-wrap-enabled', settings.qualityWrapDisabled, false,
        'The quality field is not dimmed for JPG');
    recorder.equals('default-quality', settings.quality, CONVERT_DEFAULTS.quality,
        'It defaults to 92');

    for (const format of ['png', 'tiff']) {
        // eslint-disable-next-line no-await-in-loop
        await chooseImageOption(page, 'format', format);
        // eslint-disable-next-line no-await-in-loop
        settings = await imageSettings(page);
        recorder.equals(`${format}-quality-disabled`, settings.qualityDisabled, true,
            `JPG Quality is disabled for ${format.toUpperCase()}`);
        recorder.equals(`${format}-wrap-dimmed`, settings.qualityWrapDisabled, true,
            `The quality field is dimmed for ${format.toUpperCase()}`);
    }

    await chooseImageOption(page, 'format', 'jpg');
    recorder.equals('re-enabled-for-jpg', (await imageSettings(page)).qualityDisabled, false,
        'Returning to JPG re-enables the quality slider');

    artifacts.push(await capture(page, '11-quality-gating', 'png-selected'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — The PDF/A panel and its defaults. */
async function testPdfaPanel(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);
    await setConvertTab(page, 'pdfa');
    await setAdvanced(page, 'pdfa', true);

    const panel = await page.evaluate(() => ({
        level: document.getElementById('enpv-convert-pdfa-level')?.value,
        levels: Array.from(document.querySelectorAll('#enpv-convert-pdfa-level option')).map((o) => o.value),
        embedFonts: document.getElementById('enpv-convert-pdfa-embed-fonts')?.checked,
        srgb: document.getElementById('enpv-convert-pdfa-srgb')?.checked,
        infoBox: document.querySelector('#enpv-convert-pdfa .enpv-info-box')?.textContent?.trim() || '',
    }));

    recorder.equals('default-level', panel.level, CONVERT_DEFAULTS.pdfaLevel,
        'PDF/A-2b is the default conformance level');
    recorder.equals('three-levels', panel.levels.join(','), '1b,2b,3b',
        'All three conformance levels are offered');
    recorder.equals('embed-fonts-on', panel.embedFonts, true, 'Embed all fonts is on by default');
    recorder.equals('srgb-on', panel.srgb, true, 'The sRGB profile is on by default');
    recorder.assert('explains-pdfa', /archival|preservation/i.test(panel.infoBox),
        'The panel explains what PDF/A is for', panel.infoBox.slice(0, 80));

    artifacts.push(await capture(page, '12-pdfa-panel', 'panel'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — What the PDF/A request actually carries. */
async function testPdfaRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptConversion(page, /\/convert-to-pdfa/, {
        status: 200,
        body: {
            success: true,
            label: 'PDF/A-1b',
            download_token: 'qa-suite-token',
            download_name: 'converted.pdf',
            report: { compliant: true, checks: [] },
        },
    });

    await openConvertModal(page);
    await setConvertTab(page, 'pdfa');
    await setAdvanced(page, 'pdfa', true);

    // Move every advanced control off its default so the assertion is real.
    await page.evaluate(() => {
        const level = document.getElementById('enpv-convert-pdfa-level');
        level.value = '1b';
        level.dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('enpv-convert-pdfa-embed-fonts').click();
        document.getElementById('enpv-convert-pdfa-srgb').click();
    });
    await page.waitForTimeout(300);

    await clickExport(page);
    const arrived = await waitForCapture(page, captured, 60000);
    recorder.assert('request-made', arrived, 'Converting posts to the PDF/A endpoint');

    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('level-sent', fields.level, '1b', 'The chosen conformance level is sent');
        recorder.equals('embed-fonts-sent', fields.embed_fonts, '0',
            'Unchecking Embed all fonts is sent as 0');
        recorder.equals('srgb-sent', fields.srgb_profile, '0',
            'Unchecking the sRGB profile is sent as 0');
        recorder.assert('pdf-attached', String(fields.pdf || '').startsWith('<file:'),
            'The edited PDF is attached to the request', fields.pdf);
    }

    artifacts.push(await capture(page, '13-pdfa-request', 'after-request'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — The compliance report a successful PDF/A conversion produces. */
async function testPdfaReport(page, { recorder }) {
    const artifacts = [];

    await interceptConversion(page, /\/convert-to-pdfa/, {
        status: 200,
        body: {
            success: true,
            label: 'PDF/A-2b',
            download_token: 'qa-suite-token',
            download_name: 'archival.pdf',
            report: {
                compliant: true,
                pages: 1,
                checks: [
                    { label: 'Fonts embedded', passed: true },
                    { label: 'Colour profile present', passed: true },
                    { label: 'No external references', passed: true },
                ],
            },
        },
    });

    await openConvertModal(page);
    await setConvertTab(page, 'pdfa');
    await clickExport(page);
    await page.waitForTimeout(4000);

    const report = await page.evaluate(() => {
        const modal = document.getElementById('enpv-pdfa-report-modal');
        return {
            open: !!modal && modal.hidden === false,
            title: document.getElementById('enpv-pdfa-report-title')?.textContent?.trim() || '',
            checks: document.getElementById('enpv-pdfa-report-checks')?.children?.length || 0,
            downloadOffered: !!document.getElementById('enpv-pdfa-report-download'),
        };
    });

    recorder.assert('report-shown', report.open,
        'A successful conversion shows the compliance report', JSON.stringify(report));
    if (report.open) {
        recorder.assert('report-titled', report.title.length > 0,
            'The report is titled', report.title);
        recorder.assert('report-lists-checks', report.checks >= 1,
            'The report lists the compliance checks', `${report.checks} rows`);
        recorder.assert('report-offers-download', report.downloadOffered,
            'The report offers the converted file for download');
        recorder.assert('convert-modal-closed', !(await convertModalState(page)).open,
            'The Convert modal steps aside for the report');
    }

    artifacts.push(await capture(page, '14-pdfa-report', 'report'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — The Word panel, its defaults, and the charge it warns about. */
async function testWordPanel(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);
    const state = await setConvertTab(page, 'word');
    await setAdvanced(page, 'word', true);

    const panel = await page.evaluate(() => ({
        layout: document.getElementById('enpv-convert-word-layout')?.value,
        layouts: Array.from(document.querySelectorAll('#enpv-convert-word-layout option')).map((o) => o.value),
        images: document.getElementById('enpv-convert-word-images')?.checked,
        ocr: document.getElementById('enpv-convert-word-ocr')?.checked,
        infoBox: document.querySelector('#enpv-convert-word .enpv-info-box')?.textContent?.trim() || '',
    }));

    recorder.equals('default-layout', panel.layout, CONVERT_DEFAULTS.wordLayout,
        'Exact Layout is the default');
    recorder.equals('two-layouts', panel.layouts.join(','), 'flow,exact',
        'Both layout modes are offered');
    recorder.equals('images-on', panel.images, true, 'Include images is on by default');
    recorder.equals('ocr-off', panel.ocr, false, 'OCR is off by default');
    recorder.assert('explains-docx', /\.docx|word document/i.test(panel.infoBox),
        'The panel says what it produces', panel.infoBox.slice(0, 80));
    recorder.assert('states-the-charge', /charge:\s*\$\d/i.test(state.pageCount),
        'The charge is stated before the user commits', state.pageCount);

    artifacts.push(await capture(page, '15-word-panel', 'panel'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 16 — What the Word request actually carries. */
async function testWordRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptConversion(page, /\/convert-to-word/, {
        status: 422,
        body: { success: false, message: 'QA suite: conversion intentionally not queued.' },
    });

    await openConvertModal(page);
    await setConvertTab(page, 'word');
    await setAdvanced(page, 'word', true);

    // Move every advanced control off its default.
    await page.evaluate(() => {
        const layout = document.getElementById('enpv-convert-word-layout');
        layout.value = 'flow';
        layout.dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('enpv-convert-word-images').click();
        document.getElementById('enpv-convert-word-ocr').click();
    });
    await page.waitForTimeout(300);

    await clickExport(page);
    const arrived = await waitForCapture(page, captured, 60000);
    recorder.assert('request-made', arrived, 'Converting posts to the Word endpoint');

    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('layout-sent', fields.layout, 'flow', 'The chosen layout mode is sent');
        recorder.equals('images-sent', fields.include_images, '0',
            'Unchecking Include images is sent as 0');
        recorder.equals('ocr-sent', fields.ocr, '1', 'Checking OCR is sent as 1');
        recorder.assert('pdf-attached', String(fields.pdf || '').startsWith('<file:'),
            'The edited PDF is attached to the request', fields.pdf);
        recorder.equals('posted-once', captured.count, 1,
            'One click queues one conversion, so a slip cannot double-charge');
    }

    artifacts.push(await capture(page, '16-word-request', 'after-request'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 17 — The Excel panel, its defaults, and the charge it warns about. */
async function testExcelPanel(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);
    const state = await setConvertTab(page, 'excel');
    await setAdvanced(page, 'excel', true);

    const panel = await page.evaluate(() => ({
        mode: document.getElementById('enpv-convert-excel-mode')?.value,
        modes: Array.from(document.querySelectorAll('#enpv-convert-excel-mode option')).map((o) => o.value),
        mergeCells: document.getElementById('enpv-convert-excel-merge-cells')?.checked,
        sheetPerPage: document.getElementById('enpv-convert-excel-sheet-per-page')?.checked,
        infoBox: document.querySelector('#enpv-convert-excel .enpv-info-box')?.textContent?.trim() || '',
    }));

    recorder.equals('default-mode', panel.mode, CONVERT_DEFAULTS.excelMode,
        'All Content is the default extraction mode');
    recorder.equals('two-modes', panel.modes.join(','), 'all,tables',
        'Both extraction modes are offered');
    recorder.equals('merge-cells-on', panel.mergeCells, true, 'Preserve merged cells is on by default');
    recorder.equals('sheet-per-page-on', panel.sheetPerPage, true,
        'Separate sheet per page is on by default');
    recorder.assert('explains-xlsx', /\.xlsx|spreadsheet/i.test(panel.infoBox),
        'The panel says what it produces', panel.infoBox.slice(0, 80));
    recorder.assert('states-the-charge', /charge:\s*\$\d/i.test(state.pageCount),
        'The charge is stated before the user commits', state.pageCount);

    artifacts.push(await capture(page, '17-excel-panel', 'panel'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 18 — What the Excel request actually carries. */
async function testExcelRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptConversion(page, /\/convert-to-excel/, {
        status: 422,
        body: { success: false, message: 'QA suite: conversion intentionally not queued.' },
    });

    await openConvertModal(page);
    await setConvertTab(page, 'excel');
    await setAdvanced(page, 'excel', true);

    await page.evaluate(() => {
        const mode = document.getElementById('enpv-convert-excel-mode');
        mode.value = 'tables';
        mode.dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('enpv-convert-excel-merge-cells').click();
        document.getElementById('enpv-convert-excel-sheet-per-page').click();
    });
    await page.waitForTimeout(300);

    await clickExport(page);
    const arrived = await waitForCapture(page, captured, 60000);
    recorder.assert('request-made', arrived, 'Converting posts to the Excel endpoint');

    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('mode-sent', fields.mode, 'tables', 'The chosen extraction mode is sent');
        recorder.equals('merge-cells-sent', fields.merge_cells, '0',
            'Unchecking Preserve merged cells is sent as 0');
        recorder.equals('sheet-per-page-sent', fields.sheet_per_page, '0',
            'Unchecking Separate sheet per page is sent as 0');
        recorder.assert('pdf-attached', String(fields.pdf || '').startsWith('<file:'),
            'The edited PDF is attached to the request', fields.pdf);
        recorder.equals('posted-once', captured.count, 1,
            'One click queues one conversion, so a slip cannot double-charge');
    }

    artifacts.push(await capture(page, '18-excel-request', 'after-request'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 19 — The modal locks itself while a conversion is running. */
async function testBusyState(page, { recorder }) {
    const artifacts = [];

    // Hold the request open so the busy state can be observed rather than raced.
    let release = null;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route(/\/convert-to-pdfa/, async (route) => {
        await held;
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, message: 'QA suite: released.' }),
        });
    });

    await openConvertModal(page);
    await setConvertTab(page, 'pdfa');
    await clickExport(page);
    await page.waitForTimeout(2500);

    const busy = await convertModalState(page);
    recorder.equals('progress-shown', busy.progressHidden, false,
        'A running conversion shows the progress bar');
    recorder.assert('progress-labelled', busy.progressLabel.length > 0,
        'The progress bar says what it is doing', busy.progressLabel);
    recorder.equals('export-disabled', busy.exportDisabled, true,
        'The action button is disabled while it runs');
    recorder.equals('close-disabled', busy.closeDisabled, true,
        'The modal cannot be closed mid-conversion');

    const controls = await page.evaluate(() => {
        const inputs = Array.from(document.querySelectorAll('#enpv-convert-modal input, #enpv-convert-modal select'));
        return { total: inputs.length, disabled: inputs.filter((el) => el.disabled).length };
    });
    recorder.equals('all-inputs-disabled', controls.disabled, controls.total,
        'Every field in the modal is disabled while it runs',
        `${controls.disabled}/${controls.total}`);

    const advanced = await advancedState(page, 'pdfa');
    recorder.equals('advanced-toggle-disabled', advanced.disabled, true,
        'The Advanced toggle is disabled with everything else (NK_24)');

    release();
    await page.waitForTimeout(3000);

    const settled = await convertModalState(page);
    recorder.equals('export-re-enabled', settled.exportDisabled, false,
        'The action button comes back once the conversion has finished');
    recorder.equals('close-re-enabled', settled.closeDisabled, false,
        'The modal can be closed again');

    artifacts.push(await capture(page, '19-busy-state', 'settled'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 20 — A failure is explained, and the modal remains usable. */
async function testFailureHandling(page, { recorder }) {
    const artifacts = [];

    await page.route(/\/convert-to-pdfa/, (route) => route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'Conversion service unavailable.' }),
    }));

    await openConvertModal(page);
    await setConvertTab(page, 'pdfa');
    await clickExport(page);
    await page.waitForTimeout(6000);

    const status = await statusText(page);
    recorder.assert('failure-reported', /fail|unavailable|error/i.test(status.text),
        'A failed conversion says so rather than failing silently', status.text);
    recorder.assert('failure-flagged-as-error', status.isError,
        'The failure is styled as an error');

    const state = await convertModalState(page);
    recorder.assert('modal-still-open', state.open,
        'The modal stays open so the user can try again');
    recorder.equals('export-re-enabled', state.exportDisabled, false,
        'The action button is usable again after a failure');
    recorder.equals('close-re-enabled', state.closeDisabled, false,
        'The modal can be closed after a failure');

    const noReport = await page.evaluate(
        () => document.getElementById('enpv-pdfa-report-modal')?.hidden !== false,
    );
    recorder.assert('no-success-report', noReport,
        'A failure shows no compliance report');

    artifacts.push(await capture(page, '20-failure-handling', 'failed'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 21 — Keyboard access and ARIA. */
async function testKeyboardAndAria(page, { recorder }) {
    const artifacts = [];
    await openConvertModal(page);

    const semantics = await page.evaluate(() => {
        const card = document.querySelector('.enpv-convert-card');
        const tablist = document.querySelector('.enpv-convert-tabs');
        const tabs = Array.from(document.querySelectorAll('[data-enpv-convert-tab]'));
        const panels = Array.from(document.querySelectorAll('.enpv-convert-panel'));
        return {
            dialogRole: card?.getAttribute('role'),
            modal: card?.getAttribute('aria-modal'),
            labelledBy: card?.getAttribute('aria-labelledby'),
            tablistRole: tablist?.getAttribute('role'),
            tablistLabel: tablist?.getAttribute('aria-label'),
            allTabs: tabs.every((tab) => tab.getAttribute('role') === 'tab'),
            allControls: tabs.every((tab) => !!tab.getAttribute('aria-controls')),
            controlsResolve: tabs.every((tab) => !!document.getElementById(tab.getAttribute('aria-controls'))),
            allPanels: panels.every((panel) => panel.getAttribute('role') === 'tabpanel'),
            closeLabel: document.getElementById('enpv-convert-close')?.getAttribute('aria-label') || '',
            progressLive: document.getElementById('enpv-convert-progress')?.getAttribute('aria-live'),
            estimateLive: document.getElementById('enpv-convert-size-estimate')?.getAttribute('aria-live'),
        };
    });

    recorder.equals('dialog-role', semantics.dialogRole, 'dialog', 'The card is a dialog');
    recorder.equals('aria-modal', semantics.modal, 'true', 'It is marked as modal');
    recorder.equals('labelled-by-title', semantics.labelledBy, 'enpv-convert-title',
        'The dialog is labelled by its title');
    recorder.equals('tablist-role', semantics.tablistRole, 'tablist', 'The tabs are a tablist');
    recorder.assert('tablist-labelled', String(semantics.tablistLabel || '').length > 0,
        'The tablist has an accessible name', semantics.tablistLabel);
    recorder.assert('tabs-are-tabs', semantics.allTabs, 'Each tab button is a tab');
    recorder.assert('tabs-name-panels', semantics.allControls, 'Each tab names the panel it controls');
    recorder.assert('controls-resolve', semantics.controlsResolve,
        'Every aria-controls target actually exists');
    recorder.assert('panels-are-tabpanels', semantics.allPanels, 'Each panel is a tabpanel');
    recorder.assert('close-is-labelled', semantics.closeLabel.length > 0,
        'The close button has an accessible name', semantics.closeLabel);
    recorder.equals('progress-is-live', semantics.progressLive, 'polite',
        'Progress is announced politely rather than silently');
    recorder.equals('estimate-is-live', semantics.estimateLive, 'polite',
        'The size estimate is announced when it changes');

    // The tabs are real buttons, so they take focus and fire from the keyboard.
    const keyboard = await page.evaluate(() => {
        const tab = document.querySelector('[data-enpv-convert-tab="word"]');
        tab.focus();
        const focused = document.activeElement === tab;
        tab.click();
        return { focused, active: document.querySelector('.enpv-convert-tab.is-active')?.dataset.enpvConvertTab };
    });
    await page.waitForTimeout(400);
    recorder.assert('tab-takes-focus', keyboard.focused, 'A tab can be focused from the keyboard');
    recorder.equals('tab-activates', keyboard.active, 'word', 'Activating a focused tab switches to it');

    artifacts.push(await capture(page, '21-keyboard-and-aria', 'modal'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Convert tool available', run: testBlankProjectLoads },
    { id: '02-modal-open-close', number: '02', title: 'The Convert modal opens with four tabs and closes via X, Cancel and scrim', run: testModalOpenClose, signedIn: true },
    { id: '03-tab-switching', number: '03', title: 'Switching tabs updates the title, subtitle, export button label and summary', run: testTabSwitching, signedIn: true },
    { id: '04-advanced-toggle', number: '04', title: 'Advanced is off by default on every tab, is per-tab, and persists (NK_24)', run: testAdvancedToggle, signedIn: true },
    { id: '05-image-formats', number: '05', title: 'Images: format choice, and transparent background offered for PNG/TIFF only', run: testImageFormats, signedIn: true },
    { id: '06-image-sizes', number: '06', title: 'Images: the size presets map to 96/150/300 DPI', run: testImageSizes, signedIn: true },
    { id: '07-page-selection', number: '07', title: 'Images: All Pages, Page Range and Custom, and a malformed custom range is refused', run: testPageSelection, signedIn: true, pages: 5 },
    { id: '08-summary-and-estimate', number: '08', title: 'Images: the page-count summary and download size estimate track the settings', run: testSummaryAndEstimate, signedIn: true, pages: 5 },
    { id: '09-image-export-download', number: '09', title: 'Images: exporting one page downloads an image, several pages downloads a ZIP', run: testImageExportDownload, signedIn: true, pages: 3 },
    { id: '10-hidden-advanced-applies', number: '10', title: 'Images: hidden Advanced settings still reach the export (NK_24)', run: testHiddenAdvancedStillApplies, signedIn: true },
    { id: '11-quality-gating', number: '11', title: 'Images: JPG Quality is disabled for PNG and TIFF', run: testQualityGating, signedIn: true },
    { id: '12-pdfa-panel', number: '12', title: 'PDF/A: the panel, and PDF/A-2b as the default conformance level', run: testPdfaPanel, signedIn: true },
    { id: '13-pdfa-request', number: '13', title: 'PDF/A: the request carries the chosen level, embed_fonts and srgb_profile', run: testPdfaRequest, signedIn: true },
    { id: '14-pdfa-report', number: '14', title: 'PDF/A: a successful conversion shows the compliance report', run: testPdfaReport, signedIn: true },
    { id: '15-word-panel', number: '15', title: 'Word: the panel, Exact Layout default, and the per-transaction charge', run: testWordPanel, signedIn: true },
    { id: '16-word-request', number: '16', title: 'Word: the request carries layout, include_images and ocr', run: testWordRequest, signedIn: true },
    { id: '17-excel-panel', number: '17', title: 'Excel: the panel, All Content default, and the per-transaction charge', run: testExcelPanel, signedIn: true },
    { id: '18-excel-request', number: '18', title: 'Excel: the request carries mode, merge_cells and sheet_per_page', run: testExcelRequest, signedIn: true },
    { id: '19-busy-state', number: '19', title: 'An export in progress disables the modal controls and shows the progress bar', run: testBusyState, signedIn: true },
    { id: '20-failure-handling', number: '20', title: 'A failed conversion reports the reason and leaves the modal usable', run: testFailureHandling, signedIn: true },
    { id: '21-keyboard-and-aria', number: '21', title: 'Keyboard access and ARIA on the Convert modal and its tablist', run: testKeyboardAndAria, signedIn: true },
];

function summarise(test, checks, artifacts, error, startedAt) {
    const passed = checks.filter((check) => check.result === 'PASS').length;
    const skipped = checks.filter((check) => check.result === 'SKIP').length;
    // Skipped checks are neither failures nor passes: drop them from the
    // denominator so the ratio reads as "everything that could run".
    const total = checks.length - skipped;
    const status = error ? 'error' : (passed === total ? 'passed' : 'failed');

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
                // A fresh context per test so no cookie, tab or tool state leaks
                // from one case into another.
                context = await browser.newContext({
                    viewport: { width: 1500, height: 1000 },
                    ignoreHTTPSErrors: true,
                    acceptDownloads: true,
                });

                // The container has no reliable outbound route; fulfil webfont
                // requests with an empty stylesheet rather than aborting, since
                // an abort surfaces as a console error that case 01 asserts on.
                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => {
                    route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {});
                });

                const page = await context.newPage();
                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(page, csrfToken, `Convert tool test ${test.number}`, test.pages || 1);
                const consoleErrors = test.signedIn
                    ? await openEditorAsSignedIn(page, docId)
                    : await openEditor(page, docId);

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
    TABS,
    TAB_META,
    CONVERT_DEFAULTS,
    IMAGE_DPI_PRESETS,
    buildBlankPdfBuffer,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    openEditorAsSignedIn,
    convertModalState,
    openConvertModal,
    closeConvertModal,
    setConvertTab,
    advancedState,
    setAdvanced,
    chooseImageOption,
    imageSettings,
    setPageRange,
    setCustomPages,
    clickExport,
    expectedPixelSize,
    jpegInfo,
    pngInfo,
    zipEntries,
    inspectImagePixels,
    saveDownload,
    multipartFields,
    interceptConversion,
    waitForCapture,
    statusText,
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
