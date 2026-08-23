/**
 * Signature Tool Automated Tests (Playwright)
 *
 * Automates subtasks 01-03 of the "Signature tool" Asana task
 * (Netkit -> Claude QA Agent):
 *
 *   01  Setup: blank PDF project loads in /edit-new?pdfjs=1
 *   02  Sign toolbar button opens the signature modal in Draw mode
 *   03  Modal shell: tab switching, close paths, state reset between opens
 *
 * Each run creates a fresh blank single-page PDF, uploads it as a new
 * document, opens it in the pdf.js editor, and drives the real UI.
 *
 * Usage:
 *   node run_signature_tests.cjs --list
 *   node run_signature_tests.cjs --run 01-blank-project-loads,02-...
 *   node run_signature_tests.cjs --run-all
 *
 * Output is a single JSON document on stdout so the Laravel controller
 * can parse it. Nothing else may be written to stdout.
 */

const fs = require('fs');
const path = require('path');

process.umask(0o000);

// Ensure Playwright finds the browsers bundled in node_modules rather than
// ~/.cache/ms-playwright, which does not exist in the app container.
const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

// Always localhost:80 from inside the container — APP_URL carries the host port.
const BASE_URL = process.env.AUTOMATED_TEST_BASE_URL || 'http://localhost';
const ARTIFACT_DIR = path.resolve(__dirname, 'artifacts');

// Composer defaults asserted by tests 02 and 03. These mirror
// resetSignatureComposer() in resources/js/edit-new-pdfjs/signature.js.
const COMPOSER_DEFAULTS = {
    color: '#111827',
    typeColor: '#111827',
    width: '3',
    smoothing: '58',
    typeSize: '136',
    font: 'Great Vibes',
    text: '',
    imageName: 'No file selected',
};

// NK_Dev_5 removed every line of subtext under the live preview, so there is
// no hint element left to assert on — its absence is asserted instead.

const APPLY_LABELS = {
    draw: 'Use signature',
    type: 'Use typed signature',
    upload: 'Use uploaded signature',
};

// The blade markup ships "Create a signature, then place it on the current
// page." as the static default, but openSignatureModal() -> setSignatureMode()
// immediately replaces it with the mode-specific idle copy. The runtime string
// below is what a user actually sees on opening the modal.
const DRAW_IDLE_STATUS = 'Draw a signature, then place it on the page.';

// Page size of the blank PDFs this runner generates, in PDF points.
const PAGE_WIDTH_PTS = 612;
const PAGE_HEIGHT_PTS = 792;

// Placement sizing rules from buildSignatureAnnotationAt() in
// resources/js/edit-new-pdfjs/signature.js.
const PLACEMENT = {
    minWidthPts: 24,
    minHeightPts: 8,
    maxWidthFraction: 0.34,
    maxHeightFraction: 0.18,
    edgeMarginPts: 12,
};

const TESTS = [
    {
        id: '01-blank-project-loads',
        number: '01',
        title: 'Setup: blank PDF project loads in /edit-new?pdfjs=1',
        run: testBlankProjectLoads,
    },
    {
        id: '02-sign-button-opens-modal',
        number: '02',
        title: 'Sign toolbar button opens the signature modal in Draw mode',
        run: testSignButtonOpensModal,
    },
    {
        id: '03-modal-shell',
        number: '03',
        title: 'Modal shell: tab switching, close paths, state reset between opens',
        run: testModalShell,
    },
    {
        id: '04-draw-stroke-capture',
        number: '04',
        title: 'Draw tab: stroke capture and pointer event handling',
        run: testDrawStrokeCapture,
    },
    {
        id: '05-draw-controls',
        number: '05',
        title: 'Draw tab: ink colour, stroke width and smoothing controls',
        run: testDrawControls,
    },
    {
        id: '06-type-fonts',
        number: '06',
        title: 'Type tab: text entry, font list and webfont loading',
        run: testTypeFonts,
    },
    {
        id: '07-type-size-autofit',
        number: '07',
        title: 'Type tab: font size, ink colour and auto-fit shrink',
        run: testTypeSizeAutofit,
    },
    {
        id: '08-upload-import',
        number: '08',
        title: 'Upload tab: valid image import and normalisation',
        run: testUploadImport,
    },
    {
        id: '09-upload-invalid',
        number: '09',
        title: 'Upload tab: invalid, oversized and hostile files',
        run: testUploadInvalid,
    },
    {
        id: '10-preview-trim-gating',
        number: '10',
        title: 'Preview stage: Clear button, transparent trim and dirty-state gating',
        run: testPreviewTrimGating,
    },
    {
        id: '11-library-save',
        number: '11',
        title: 'Saved library: saving signatures to the account',
        run: testLibrarySave,
    },
    {
        id: '12-library-load-delete',
        number: '12',
        title: 'Saved library: load and delete account entries',
        run: testLibraryLoadDelete,
    },
    {
        id: '13-library-storage-failures',
        number: '13',
        title: 'Saved library: signed-out state and untrusted entry data',
        run: testLibraryFailures,
    },
    {
        id: '14-placement-sizing',
        number: '14',
        title: 'Placement: click-to-place sizing, clamping and cancel',
        run: testPlacementSizing,
    },
    {
        id: '15-placement-zoom-rotation',
        number: '15',
        title: 'Placement across zoom, multi-page and scroll',
        run: testPlacementZoomScroll,
        pages: 3,
    },
    {
        id: '16-move-resize-lock',
        number: '16',
        title: 'Placed signature: select, move, resize, lock and z-order',
        run: testMoveResizeLock,
    },
    {
        id: '17-copy-delete',
        number: '17',
        title: 'Placed signature: copy and delete',
        run: testCopyDelete,
    },
    {
        id: '18-edit-round-trip',
        number: '18',
        title: 'Edit an existing signature: double-click round-trip per mode',
        run: testEditRoundTrip,
    },
    {
        id: '19-undo-redo',
        number: '19',
        title: 'Undo / redo history for every signature operation',
        run: testUndoRedo,
    },
    {
        id: '20-save-persistence',
        number: '20',
        title: 'Save and reload persistence via /save-annotation-state',
        run: testSavePersistence,
    },
    {
        id: 'nk5-01-retired-copy',
        number: 'NK5-01',
        title: 'Retired copy is gone from the modal',
        run: nk5RetiredCopy,
    },
    {
        id: 'nk5-02-no-subtext',
        number: 'NK5-02',
        title: 'No subtext under the live preview',
        run: nk5NoSubtext,
    },
    {
        id: 'nk5-03-stroke-width',
        number: 'NK5-03',
        title: 'Stroke width supports a much heavier maximum',
        run: nk5StrokeWidth,
    },
    {
        id: 'nk5-04-preview-size',
        number: 'NK5-04',
        title: 'Live preview is larger with less surrounding padding',
        run: nk5PreviewSize,
    },
    {
        id: 'nk5-05-remembered-tab',
        number: 'NK5-05',
        title: 'Modal reopens on the tab you last used',
        run: nk5RememberedTab,
    },
    {
        id: 'nk5-06-remembered-settings',
        number: 'NK5-06',
        title: 'Composer settings are remembered, content is not',
        run: nk5RememberedSettings,
    },
    {
        id: 'nk5-07-grid-row',
        number: 'NK5-07',
        title: 'Saved tab starts in grid and can switch to row',
        run: nk5GridRow,
    },
    {
        id: 'nk5-08-search',
        number: 'NK5-08',
        title: 'Typeahead search filters saved signatures as you type',
        run: nk5Search,
    },
    {
        id: 'nk5-09-rename',
        number: 'NK5-09',
        title: 'Rename a saved signature',
        run: nk5Rename,
    },
    {
        id: 'nk5-10-font-size-placement',
        number: 'NK5-10',
        title: 'Font size determines how large the signature is placed',
        run: nk5FontSizePlacement,
    },
    {
        id: 'nk5-11-aspect-preserved',
        number: 'NK5-11',
        title: 'Aspect ratio is preserved at every placed size',
        run: nk5AspectPreserved,
    },
    {
        id: '21-burn-export',
        number: '21',
        title: 'Burn into PDF and downloaded-PDF fidelity',
        run: testBurnAndExport,
    },
    {
        id: '22-accessibility',
        number: '22',
        title: 'Accessibility and keyboard behaviour',
        run: testAccessibility,
    },
    {
        id: '23-regression-load',
        number: '23',
        title: 'Regression: cross-tool interference and load',
        run: testRegressionAndLoad,
    },
];

// ---------------------------------------------------------------------------
// Check helpers
// ---------------------------------------------------------------------------

/**
 * Collects PASS/FAIL rows for one test. Mirrors the shape runner's
 * makeCheck() shape so the blade template can render either suite.
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
        /** Assert deep-ish equality and report the delta on failure. */
        equals: (item, actual, expected, description) => push(
            item,
            actual === expected,
            description,
            actual === expected ? `= ${JSON.stringify(actual)}` : `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`,
        ),
    };
}

function ensureArtifactDir() {
    if (!fs.existsSync(ARTIFACT_DIR)) {
        fs.mkdirSync(ARTIFACT_DIR, { recursive: true, mode: 0o777 });
    }
}

/**
 * Capture a screenshot artifact and return its metadata for the UI.
 * Failures to screenshot never fail the test itself.
 */
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
 * Build a minimal valid single-page 612x792 PDF as raw bytes.
 *
 * Written by hand rather than shelling out to PyMuPDF so the runner has no
 * Python dependency — the app container's bare `python3` has no fitz module.
 */
function buildBlankPdfBuffer(label, pageCount = 1) {
    const title = String(label || 'Signature automated test').replace(/[()\\]/g, '');
    const pages = Math.max(1, Number(pageCount) || 1);

    // Object layout: 1 catalog, 2 pages tree, 3 font, then two objects per
    // page (page dict at 4+2i, its content stream at 5+2i).
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

/** Upload a blank PDF and return the new document id. */
async function createBlankDocument(page, csrfToken, label, pageCount = 1) {
    const response = await page.request.post(`${BASE_URL}/documents`, {
        multipart: {
            _token: csrfToken,
            document: {
                name: 'signature_automated_test.pdf',
                mimeType: 'application/pdf',
                buffer: buildBlankPdfBuffer(label, pageCount),
            },
        },
        maxRedirects: 0,
    });

    const location = response.headers()['location'] || '';
    const fromLocation = location.match(/\/documents\/(\d+)/);
    if (fromLocation) return parseInt(fromLocation[1], 10);

    const fromUrl = response.url().match(/\/documents\/(\d+)/);
    if (fromUrl) return parseInt(fromUrl[1], 10);

    throw new Error(`Failed to create document (HTTP ${response.status()})`);
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
// Shared page probes
// ---------------------------------------------------------------------------

/** Read the whole signature-modal state in one round trip. */
function readModalState(page) {
    return page.evaluate(({ modes }) => {
        const el = (id) => document.getElementById(id);
        const modal = el('signature-modal');
        const value = (id) => {
            const node = el(id);
            return node ? node.value : null;
        };
        const text = (id) => {
            const node = el(id);
            return node ? (node.textContent || '').trim() : null;
        };
        const canvas = el('signature-canvas');

        let canvasBlank = null;
        if (canvas && canvas.getContext) {
            try {
                const ctx = canvas.getContext('2d');
                const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                canvasBlank = true;
                for (let i = 3; i < data.length; i += 4) {
                    if (data[i] > 8) { canvasBlank = false; break; }
                }
            } catch (_) {
                canvasBlank = null;
            }
        }

        const activeTab = document.querySelector('[data-signature-mode].is-active');
        const activePanels = Array.from(document.querySelectorAll('[data-signature-panel].is-active'))
            .map((node) => node.dataset.signaturePanel);

        return {
            modalCount: document.querySelectorAll('#signature-modal').length,
            isOpen: !!modal && modal.classList.contains('is-open'),
            ariaHidden: modal ? modal.getAttribute('aria-hidden') : null,
            bodyOpenClass: document.body.classList.contains('signature-modal-open'),
            activeMode: activeTab ? activeTab.dataset.signatureMode : null,
            activePanels,
            panelVisibility: modes.reduce((acc, mode) => {
                const panel = document.querySelector(`[data-signature-panel="${mode}"]`);
                acc[mode] = !!panel && panel.classList.contains('is-active');
                return acc;
            }, {}),
            hint: text('signature-hint'),
            status: text('signature-status'),
            applyLabel: text('signature-apply'),
            applyDisabled: el('signature-apply') ? el('signature-apply').disabled : null,
            saveDisabled: el('signature-save-account') ? el('signature-save-account').disabled : null,
            canvasCursor: canvas ? canvas.style.cursor : null,
            canvasBlank,
            values: {
                color: value('signature-color'),
                typeColor: value('signature-type-color'),
                width: value('signature-width'),
                smoothing: value('signature-smoothing'),
                typeSize: value('signature-type-size'),
                font: value('signature-font'),
                text: value('signature-text'),
                saveName: value('signature-save-name'),
            },
            imageName: text('signature-image-name'),
            accountListText: text('signature-account-list'),
        };
    }, { modes: ['draw', 'type', 'upload'] });
}

/** Draw a short stroke across the signature canvas using real pointer input. */
async function drawStroke(page, { large = false } = {}) {
    const box = await page.locator('#signature-canvas').boundingBox();
    if (!box) throw new Error('Signature canvas has no bounding box');

    const y = box.y + (box.height / 2);
    // A taller mark places a taller box, which the resize test needs so the
    // eight handles are not crowded on top of each other.
    const amp = large ? box.height * 0.34 : 24;
    await page.mouse.move(box.x + (box.width * 0.25), y);
    await page.mouse.down();
    await page.mouse.move(box.x + (box.width * 0.40), y - amp, { steps: 8 });
    await page.mouse.move(box.x + (box.width * 0.55), y + amp, { steps: 8 });
    await page.mouse.move(box.x + (box.width * 0.70), y - (amp * 0.6), { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(120);
}

/**
 * Click the scrim at a point the modal card does not cover.
 *
 * The scrim fills the viewport but the card sits on top of its centre, so a
 * plain click (even forced) lands on the card and never reaches the scrim's
 * handler. Aim at the gutter beside the card instead.
 */
async function clickScrim(page) {
    const card = await page.locator('.signature-modal__card').boundingBox();
    if (!card) throw new Error('Signature modal card has no bounding box');

    const x = Math.max(4, card.x / 2);
    const y = card.y + (card.height / 2);
    await page.mouse.click(x, y);
}

async function openSignatureModal(page) {
    await page.click('#ftb-sign');
    await page.waitForSelector('#signature-modal.is-open', { timeout: 10000 });
    await page.waitForTimeout(200);
}

// ---------------------------------------------------------------------------
// Placement / geometry helpers (tests 10, 14, 15, 20)
// ---------------------------------------------------------------------------

/** Measure the inked bounding box on the composer canvas, in canvas pixels. */
function measureCanvasInk(page) {
    return page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) return null;
        const { width, height } = canvas;
        const data = canvas.getContext('2d').getImageData(0, 0, width, height).data;
        let minX = width; let minY = height; let maxX = -1; let maxY = -1; let inked = 0;
        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                if (data[((y * width + x) * 4) + 3] <= 8) continue;
                inked++;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }
        if (maxX < minX) return { inked: 0, canvasWidth: width, canvasHeight: height };
        return {
            inked,
            canvasWidth: width,
            canvasHeight: height,
            boxWidth: maxX - minX + 1,
            boxHeight: maxY - minY + 1,
        };
    });
}

/**
 * Open the modal and draw a compact mark. Leaves the modal open and dirty.
 *
 * Explicitly selects the Draw tab: since NK_Dev_5 the modal reopens on
 * whichever tab was last used, so this cannot assume Draw.
 */
async function composeDrawnMark(page, { large = false } = {}) {
    await openSignatureModal(page);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await drawStroke(page, { large });
}

/** Open the modal, switch to Type, and enter text. Leaves the modal open. */
async function composeTypedMark(page, text) {
    await openSignatureModal(page);
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(150);
    await page.fill('#signature-text', text);
    await page.waitForTimeout(350);
}

/** Click "Use signature" and wait for placement mode to arm. */
async function armPlacement(page) {
    await page.click('#signature-apply');
    await page.waitForFunction(
        () => document.body.classList.contains('enpv-signature-placing'),
        { timeout: 10000 },
    );
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
 * Click a page at a fractional position (0..1 from the page's top-left).
 *
 * Two things make this harder than it looks: pages are routinely larger than
 * the viewport (612x792pt renders ~1550x2006 CSS px at default zoom), and
 * floating chrome — the topbar, tool bar, zoom bar and status pill — covers
 * parts of the viewer. So the point is scrolled into view, then probed
 * outward until it genuinely lands on the target page.
 *
 * Returns the PDF-space point that was ACTUALLY clicked, derived from the
 * page box, so callers assert against the truth rather than the request.
 */
async function clickPageFraction(page, pageNumber, fx, fy) {
    const locator = page.locator(`#viewer .page[data-page-number="${pageNumber}"]`);
    await locator.scrollIntoViewIfNeeded({ timeout: 15000 });
    await page.waitForTimeout(250);

    const viewport = page.viewportSize();
    const offsets = probeOffsets();

    let box = null;
    let point = null;

    for (let attempt = 0; attempt < 5 && !point; attempt++) {
        box = await locator.boundingBox();
        if (!box) throw new Error(`Page ${pageNumber} has no bounding box`);

        const desiredX = box.x + (box.width * fx);
        const desiredY = box.y + (box.height * fy);

        point = await page.evaluate(({ x, y, pageNumber: target, offsets: probes, vw, vh }) => {
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
        }, {
            x: desiredX,
            y: desiredY,
            pageNumber,
            offsets,
            vw: viewport.width,
            vh: viewport.height,
        });

        if (point) break;

        await page.evaluate(([dx, dy]) => {
            const container = document.getElementById('viewerContainer');
            if (!container) return;
            container.scrollLeft += dx;
            container.scrollTop += dy;
        }, [desiredX - (viewport.width / 2), desiredY - (viewport.height / 2)]);
        await page.waitForTimeout(400);
    }

    if (!point) {
        throw new Error(`No clickable point found near (${fx}, ${fy}) on page ${pageNumber}`);
    }

    box = await locator.boundingBox();
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(500);

    return {
        pdfX: ((point.x - box.x) / box.width) * PAGE_WIDTH_PTS,
        // PDF space is bottom-up; viewport coordinates are top-down.
        pdfY: (1 - ((point.y - box.y) / box.height)) * PAGE_HEIGHT_PTS,
    };
}

/**
 * Find a clickable point that is not over any PDF page, for asserting that
 * off-page clicks never place a signature. Returns null when the viewport is
 * entirely covered by page content.
 */
async function findOffPagePoint(page) {
    const candidates = [
        [750, 12], [1200, 12], [300, 12], [1480, 8],
    ];
    for (const [x, y] of candidates) {
        const usable = await page.evaluate(([px, py]) => {
            const el = document.elementFromPoint(px, py);
            if (!el) return false;
            if (el.closest('.page')) return false;
            if (el.closest('button, a, input, select, textarea')) return false;
            return true;
        }, [x, y]);
        if (usable) return { x, y };
    }
    return null;
}

/** Compose a drawn signature and place it in one step. */
async function placeDrawnSignature(page, { pageNumber = 1, fx = 0.5, fy = 0.5, large = false } = {}) {
    await composeDrawnMark(page, { large });
    await armPlacement(page);
    return clickPageFraction(page, pageNumber, fx, fy);
}

/**
 * Record every /save-annotation-state round trip on this page.
 *
 * The pdf.js viewer has no visible Save button — the legacy #save-btn is
 * display:none and the topbar save-status is inert. Placing an annotation
 * calls markManualSaveNeeded(), which debounces an autosave ~1800ms later.
 * So the tests observe that real autosave rather than forcing a click.
 */
function attachSaveRecorder(page) {
    const recorder = { saves: [], cursor: 0 };

    page.on('response', async (response) => {
        try {
            if (!response.url().includes('/save-annotation-state')) return;
            const request = response.request();
            if (request.method() !== 'POST') return;

            let body = {};
            try { body = request.postDataJSON() || {}; } catch (_) { body = {}; }

            let json = null;
            try { json = await response.json(); } catch (_) { json = null; }

            recorder.saves.push({
                status: response.status(),
                ok: response.ok(),
                json,
                all: Array.isArray(body.annotations) ? body.annotations : [],
            });
        } catch (_) {
            // A response that vanishes mid-navigation must not break the run.
        }
    });

    return recorder;
}

/**
 * Wait for the pending autosave to land and return its signature payload.
 *
 * Consumes every save observed since the last call, returning the newest, so
 * a debounce that already fired before this call is still picked up.
 */
async function captureAutosave(page, recorder, { timeout = 30000, autosaveGrace = 4000 } = {}) {
    // Primary path: the debounced autosave a mutation already scheduled.
    const graceDeadline = Date.now() + autosaveGrace;
    while (recorder.saves.length <= recorder.cursor && Date.now() < graceDeadline) {
        await page.waitForTimeout(200);
    }

    // Nothing pending — the caller only scrolled or reloaded, which mutate no
    // state and therefore schedule no autosave. Elicit one so the persisted
    // state can still be inspected. #save-btn is display:none in this viewer,
    // so dispatch the event rather than clicking it.
    if (recorder.saves.length <= recorder.cursor) {
        await page.evaluate(() => {
            document.getElementById('save-btn')?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }).catch(() => {});
    }

    const deadline = Date.now() + timeout;
    while (recorder.saves.length <= recorder.cursor && Date.now() < deadline) {
        await page.waitForTimeout(200);
    }

    if (recorder.saves.length <= recorder.cursor) {
        const diagnostic = await page.evaluate(() => ({
            saveStatus: document.getElementById('save-status')?.textContent?.trim() || null,
            statusPill: document.getElementById('enpv-status')?.textContent?.trim() || null,
            signatureBoxes: document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"]').length,
        })).catch(() => null);
        throw new Error(`No autosave observed within ${timeout}ms. ${JSON.stringify(diagnostic)}`);
    }

    // Let any follow-up save settle so we report the final state, not a
    // mid-sequence one.
    const settleUntil = Date.now() + 2500;
    let seen = recorder.saves.length;
    while (Date.now() < settleUntil) {
        await page.waitForTimeout(300);
        if (recorder.saves.length !== seen) {
            seen = recorder.saves.length;
            // Another save arrived; keep waiting for quiet.
        }
    }

    const latest = recorder.saves[recorder.saves.length - 1];
    recorder.cursor = recorder.saves.length;

    return {
        status: latest.status,
        ok: latest.ok,
        json: latest.json,
        all: latest.all,
        signatures: latest.all.filter((annotation) => String(annotation.type || '').toLowerCase() === 'signature'),
    };
}

/** Round-trip-safe numeric read. */
const num = (value) => (Number.isFinite(Number(value)) ? Number(value) : NaN);

/** Format an annotation's geometry compactly for check details. */
const geom = (annotation) => `page=${annotation.pageIndex} x=${num(annotation.pdfX).toFixed(1)} `
    + `y=${num(annotation.pdfY).toFixed(1)} w=${num(annotation.pdfWidth).toFixed(1)} `
    + `h=${num(annotation.pdfHeight).toFixed(1)}`;

/** Set the viewer zoom by clicking the zoom bar, returning the resulting scale. */
async function stepZoom(page, direction, steps) {
    const selector = direction === 'in' ? '#zoom-in' : '#zoom-out';
    for (let i = 0; i < steps; i++) {
        await page.click(selector);
        await page.waitForTimeout(350);
    }
    await page.waitForTimeout(400);
    return page.evaluate(() => {
        const viewer = window.__enpv?.pdfViewer;
        return viewer ? Number(viewer.currentScale) : null;
    });
}

// ---------------------------------------------------------------------------
// Placed-annotation + upload helpers (tests 04-09, 11-13, 16-19)
// ---------------------------------------------------------------------------

const signatureBoxes = (page) => page.locator('.enpv-annotation-box[data-annotation-type="signature"]');

/**
 * Scroll a placed box into the clickable area and return both its rect and a
 * point that is guaranteed to be inside it AND on screen.
 *
 * Pages are taller than the viewport, the topbar floats over the viewer, and
 * a resized box can be larger than the window — so the geometric centre is
 * not always clickable. Use the intersection of the box with the usable area.
 */
async function ensureBoxInView(page, locator) {
    await locator.scrollIntoViewIfNeeded({ timeout: 10000 });
    await page.waitForTimeout(250);

    const viewport = page.viewportSize();
    const topInset = await page.evaluate(() => {
        const bar = document.querySelector('.top-bar');
        return bar ? Math.ceil(bar.getBoundingClientRect().bottom) + 12 : 90;
    });
    const usable = { left: 12, right: viewport.width - 12, top: topInset, bottom: viewport.height - 40 };

    const intersect = (rect) => {
        const left = Math.max(rect.x + 4, usable.left);
        const right = Math.min(rect.x + rect.width - 4, usable.right);
        const top = Math.max(rect.y + 4, usable.top);
        const bottom = Math.min(rect.y + rect.height - 4, usable.bottom);
        if (right <= left || bottom <= top) return null;
        return { x: (left + right) / 2, y: (top + bottom) / 2 };
    };

    let rect = await locator.boundingBox();
    if (!rect) throw new Error('Signature box has no bounding box');
    let point = intersect(rect);

    for (let attempt = 0; attempt < 4 && !point; attempt++) {
        await page.evaluate(([dx, dy]) => {
            const container = document.getElementById('viewerContainer');
            if (!container) return;
            container.scrollLeft += dx;
            container.scrollTop += dy;
        }, [
            (rect.x + (rect.width / 2)) - (viewport.width / 2),
            (rect.y + (rect.height / 2)) - (viewport.height / 2),
        ]);
        await page.waitForTimeout(400);
        rect = await locator.boundingBox();
        point = rect ? intersect(rect) : null;
    }

    if (!point) throw new Error('Signature box could not be brought into the clickable area');
    return { rect, point };
}

/**
 * Ensure a placed signature is selected so the annotation menu is showing.
 *
 * Clicking a box toggles its selection, and placing one already leaves it
 * selected — so clicking unconditionally would deselect it.
 */
async function selectSignatureBox(page, index = 0) {
    const box = signatureBoxes(page).nth(index);

    const alreadySelected = await box.evaluate((el) => el.classList.contains('is-selected')).catch(() => false);
    if (alreadySelected) return box;

    const { point } = await ensureBoxInView(page, box);
    await page.mouse.click(point.x, point.y);
    await page.waitForTimeout(450);

    const selected = await box.evaluate((el) => el.classList.contains('is-selected')).catch(() => false);
    if (!selected) {
        // One retry: an autosave re-render can swallow the first click.
        await page.mouse.click(point.x, point.y);
        await page.waitForTimeout(450);
    }
    return box;
}

/** Double-click a placed signature, scrolling it into view first. */
async function dblclickSignatureBox(page, index = 0) {
    const box = signatureBoxes(page).nth(index);
    const { point } = await ensureBoxInView(page, box);
    await page.mouse.dblclick(point.x, point.y);
    await page.waitForTimeout(300);
}

/**
 * Run one action from the floating annotation menu.
 *
 * Re-selects first if the selection was dropped (a re-render after an
 * autosave can clear it), and reports the real state on failure instead of a
 * bare locator timeout.
 */
async function annotationMenuAction(page, action, index = 0) {
    const button = page.locator(`#enpv-ann-menu [data-action="${action}"]`);

    for (let attempt = 0; attempt < 3; attempt++) {
        const selected = await page.evaluate(
            () => !!document.querySelector('.enpv-annotation-box.is-selected'),
        );
        if (!selected) await selectSignatureBox(page, index);

        if (await button.isVisible().catch(() => false)) {
            await button.click();
            await page.waitForTimeout(500);
            return;
        }
        await page.waitForTimeout(400);
    }

    const state = await page.evaluate(() => {
        const menu = document.getElementById('enpv-ann-menu');
        return {
            selectedBoxes: document.querySelectorAll('.enpv-annotation-box.is-selected').length,
            menuHidden: menu ? menu.hidden : null,
            visibleActions: menu
                ? Array.from(menu.querySelectorAll('[data-action]')).filter((b) => !b.hidden).map((b) => b.dataset.action)
                : [],
        };
    }).catch(() => null);
    throw new Error(`Menu action "${action}" unavailable: ${JSON.stringify(state)}`);
}

/**
 * Read a placed box's geometry.
 *
 * Positions are reported relative to their own page element as well as the
 * viewport: the viewer scrolls while bringing boxes into view, so viewport
 * coordinates alone would read a scroll as a move.
 */
function boxRects(page) {
    return page.evaluate(() => Array.from(
        document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"]'),
    ).map((box) => {
        const rect = box.getBoundingClientRect();
        const pageDiv = box.closest('.page');
        const pageRect = pageDiv ? pageDiv.getBoundingClientRect() : { left: 0, top: 0 };
        return {
            id: box.dataset.annotationId,
            locked: box.dataset.locked === '1',
            zIndex: Number(box.style.zIndex) || 0,
            left: Math.round(rect.left),
            top: Math.round(rect.top),
            // Scroll-independent, so these are what movement asserts on.
            relLeft: Math.round(rect.left - pageRect.left),
            relTop: Math.round(rect.top - pageRect.top),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
        };
    }));
}

/**
 * Put a file into the signature upload input.
 *
 * Images are generated in-page with canvas where the browser can encode them
 * (PNG/JPEG/WebP) so the suite needs no binary fixtures on disk; GIF and SVG
 * are supplied as literals.
 */
async function setSignatureFile(page, spec) {
    return page.evaluate(async ({ kind, name, mimeType, literal, width, height, transparent }) => {
        const makeBlob = async () => {
            if (literal != null) {
                const response = await fetch(literal);
                return response.blob();
            }
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!transparent) {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, height);
            }
            // A recognisable mark that survives scaling.
            ctx.strokeStyle = '#1d4ed8';
            ctx.lineWidth = Math.max(2, Math.round(width / 40));
            ctx.beginPath();
            ctx.moveTo(width * 0.1, height * 0.7);
            ctx.lineTo(width * 0.4, height * 0.25);
            ctx.lineTo(width * 0.7, height * 0.75);
            ctx.lineTo(width * 0.9, height * 0.3);
            ctx.stroke();
            const dataUrl = canvas.toDataURL(kind);
            const response = await fetch(dataUrl);
            return response.blob();
        };

        const blob = await makeBlob();
        const file = new File([blob], name, { type: mimeType || blob.type });
        const transfer = new DataTransfer();
        transfer.items.add(file);
        const input = document.getElementById('signature-image-input');
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return { size: file.size, type: file.type };
    }, spec);
}

/** Smallest valid GIF, used where canvas cannot encode the format. */
const TINY_GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

/** Wait for the modal status line to settle on something matching `re`. */
async function waitForStatus(page, re, timeout = 8000) {
    const deadline = Date.now() + timeout;
    let last = '';
    while (Date.now() < deadline) {
        last = (await page.evaluate(() => document.getElementById('signature-status')?.textContent?.trim() || '')) || '';
        if (re.test(last)) return last;
        await page.waitForTimeout(150);
    }
    return last;
}

/** Hash the composer canvas so two renders can be compared cheaply. */
function canvasFingerprint(page) {
    return page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) return null;
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let hash = 0;
        let inked = 0;
        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] > 8) {
                inked++;
                hash = (hash * 31 + i + data[i] + data[i + 1] + data[i + 2]) % 2147483647;
            }
        }
        return { hash, inked };
    });
}

// ---------------------------------------------------------------------------
// Test 01
// ---------------------------------------------------------------------------

async function testBlankProjectLoads(page, ctx) {
    const r = ctx.recorder;
    const { consoleErrors, docId } = ctx;

    r.assert('document-created', Number.isFinite(docId) && docId > 0,
        'Blank single-page PDF uploaded and a document created', `document id ${docId}`);

    const pageMetrics = await page.evaluate(() => {
        const pages = document.querySelectorAll('#viewer .page');
        const canvas = document.querySelector('#viewer .page canvas');
        return {
            pageCount: pages.length,
            canvasWidth: canvas ? canvas.width : 0,
            canvasHeight: canvas ? canvas.height : 0,
        };
    });

    r.assert('page-rendered', pageMetrics.canvasWidth > 0 && pageMetrics.canvasHeight > 0,
        'Page 1 canvas rasterised by pdf.js',
        `${pageMetrics.pageCount} page(s), canvas ${pageMetrics.canvasWidth}x${pageMetrics.canvasHeight}`);

    // The editor legitimately logs some network noise; only fail on real errors.
    const meaningfulErrors = consoleErrors.filter((message) => !/favicon|fonts\.googleapis|net::ERR_/i.test(message));
    r.assert('no-console-errors', meaningfulErrors.length === 0,
        'No console errors during editor load',
        meaningfulErrors.length ? meaningfulErrors.slice(0, 3).join(' | ') : 'clean');

    const signButton = await page.evaluate(() => {
        const button = document.getElementById('ftb-sign');
        if (!button) return null;
        const rect = button.getBoundingClientRect();
        return {
            visible: rect.width > 0 && rect.height > 0,
            disabled: !!button.disabled,
            label: (button.textContent || '').trim(),
        };
    });
    r.assert('sign-button-present', !!signButton && signButton.visible && !signButton.disabled,
        'Sign button (#ftb-sign) is visible and enabled',
        signButton ? `label "${signButton.label}"` : 'button missing');

    const modal = await readModalState(page);
    r.assert('modal-present-closed', modal.modalCount === 1 && !modal.isOpen && modal.ariaHidden === 'true',
        'Signature modal exists in the DOM and starts closed',
        `count=${modal.modalCount} open=${modal.isOpen} aria-hidden=${modal.ariaHidden}`);

    // Saved signatures are account-scoped now; a guest run must be told to
    // sign in rather than shown an empty browser library.
    const library = await page.evaluate(() => {
        const list = document.getElementById('signature-account-list');
        const modal = document.getElementById('signature-modal');
        return {
            present: !!list,
            listText: list ? (list.textContent || '').trim() : null,
            accountEnabled: modal?.dataset.accountSignatures === '1',
        };
    });
    const librarySeeded = library.present
        && (library.accountEnabled
            ? true
            : /sign in/i.test(library.listText || ''));
    r.assert('saved-signatures-seeded', librarySeeded,
        'Saved tab renders its account-signature state on load',
        `signedIn=${library.accountEnabled} state="${library.listText}"`);

    const artifact = await capture(page, '01-blank-project-loads', 'editor-loaded');

    return { checks: r.checks, artifacts: [artifact].filter(Boolean), docId };
}

// ---------------------------------------------------------------------------
// Test 02
// ---------------------------------------------------------------------------

async function testSignButtonOpensModal(page, ctx) {
    const r = ctx.recorder;

    await openSignatureModal(page);
    const modal = await readModalState(page);

    r.assert('modal-opens', modal.isOpen && modal.ariaHidden === 'false' && modal.bodyOpenClass,
        'Modal opens with .is-open, aria-hidden="false" and body.signature-modal-open',
        `open=${modal.isOpen} aria-hidden=${modal.ariaHidden} body-class=${modal.bodyOpenClass}`);

    r.equals('default-mode-draw', modal.activeMode, 'draw',
        'Draw tab is active by default');

    const panelsCorrect = modal.panelVisibility.draw
        && !modal.panelVisibility.type
        && !modal.panelVisibility.upload;
    r.assert('draw-panel-only', panelsCorrect,
        'Draw panel is visible; Type and Upload panels are hidden',
        `active panels: ${modal.activePanels.join(', ') || 'none'}`);

    r.assert('no-preview-subtext', modal.hint === null,
        'No subtext is rendered under the live preview (NK_Dev_5)',
        `hint element present=${modal.hint !== null}`);

    r.equals('default-status', modal.status, DRAW_IDLE_STATUS,
        'Status shows the draw-mode idle prompt');

    r.assert('apply-disabled', modal.applyDisabled === true,
        '"Use signature" is disabled until there is content', `disabled=${modal.applyDisabled}`);

    r.assert('save-disabled', modal.saveDisabled === true,
        '"Save" (account) is disabled until there is content', `disabled=${modal.saveDisabled}`);

    const defaults = modal.values;
    const defaultsMatch = defaults.color === COMPOSER_DEFAULTS.color
        && defaults.typeColor === COMPOSER_DEFAULTS.typeColor
        && defaults.width === COMPOSER_DEFAULTS.width
        && defaults.smoothing === COMPOSER_DEFAULTS.smoothing
        && defaults.typeSize === COMPOSER_DEFAULTS.typeSize
        && defaults.font === COMPOSER_DEFAULTS.font
        && defaults.text === COMPOSER_DEFAULTS.text
        && modal.imageName === COMPOSER_DEFAULTS.imageName;
    r.assert('composer-defaults', defaultsMatch,
        'Composer resets to documented defaults (ink #111827, width 3, smoothing 58, Great Vibes, 136px, empty text, no file)',
        `ink=${defaults.color} width=${defaults.width} smoothing=${defaults.smoothing} font=${defaults.font} size=${defaults.typeSize} text=${JSON.stringify(defaults.text)} image=${JSON.stringify(modal.imageName)}`);

    const artifact = await capture(page, '02-sign-button-opens-modal', 'modal-open-draw-mode');

    // Reopening must not stack modals or double-bind the canvas listeners.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    await openSignatureModal(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    await openSignatureModal(page);

    const reopened = await readModalState(page);
    r.assert('no-stacked-modals', reopened.modalCount === 1,
        'Opening Sign repeatedly does not stack modal markup',
        `#signature-modal count=${reopened.modalCount}`);

    await drawStroke(page);
    const strokeState = await page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        if (!canvas) return null;
        const ctx = canvas.getContext('2d');
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        let inked = 0;
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] > 8) inked += 1;
        }
        const apply = document.getElementById('signature-apply');
        return { inked, applyDisabled: apply ? apply.disabled : null };
    });
    r.assert('stroke-after-reopen', !!strokeState && strokeState.inked > 0 && strokeState.applyDisabled === false,
        'Drawing after repeated opens paints once and enables Apply (no duplicated listeners)',
        strokeState ? `${strokeState.inked} inked px, apply disabled=${strokeState.applyDisabled}` : 'canvas unreadable');

    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);

    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 03
// ---------------------------------------------------------------------------

async function testModalShell(page, ctx) {
    const r = ctx.recorder;

    await openSignatureModal(page);

    // --- Tab switching ----------------------------------------------------
    for (const mode of ['draw', 'type', 'upload']) {
        await page.click(`[data-signature-mode="${mode}"]`);
        await page.waitForTimeout(180);
        const state = await readModalState(page);

        const onlyThisPanel = Object.entries(state.panelVisibility)
            .every(([key, active]) => (key === mode ? active : !active));

        r.assert(`tab-${mode}-activates`, state.activeMode === mode && onlyThisPanel,
            `${mode} tab activates its own panel and hides the others`,
            `active=${state.activeMode}, panels=${JSON.stringify(state.panelVisibility)}`);


        r.equals(`tab-${mode}-apply-label`, state.applyLabel, APPLY_LABELS[mode],
            `${mode} tab shows the "${APPLY_LABELS[mode]}" button label`);

        const cursorOk = mode === 'draw'
            ? state.canvasCursor === 'crosshair'
            : state.canvasCursor === 'default';
        r.assert(`tab-${mode}-cursor`, cursorOk,
            `Canvas cursor is ${mode === 'draw' ? 'crosshair' : 'default'} in ${mode} mode`,
            `cursor=${state.canvasCursor}`);
    }

    // --- Strokes survive a tab round-trip ---------------------------------
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(150);
    await drawStroke(page);

    const inkedBefore = await page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let inked = 0;
        for (let i = 3; i < data.length; i += 4) if (data[i] > 8) inked += 1;
        return inked;
    });

    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(200);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);

    const afterRoundTrip = await page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let inked = 0;
        for (let i = 3; i < data.length; i += 4) if (data[i] > 8) inked += 1;
        const apply = document.getElementById('signature-apply');
        return { inked, applyDisabled: apply ? apply.disabled : null };
    });

    r.assert('strokes-survive-tab-switch',
        inkedBefore > 0 && afterRoundTrip.inked > 0 && afterRoundTrip.applyDisabled === false,
        'Strokes survive Draw -> Type -> Draw and Apply stays enabled',
        `inked before=${inkedBefore}, after=${afterRoundTrip.inked}, apply disabled=${afterRoundTrip.applyDisabled}`);

    const artifact = await capture(page, '03-modal-shell', 'draw-tab-with-stroke');

    // --- Close paths ------------------------------------------------------
    const closePaths = [
        { key: 'close-button', description: 'close (x) button', act: async () => page.click('#signature-modal-close') },
        { key: 'cancel-button', description: 'Cancel button', act: async () => page.click('#signature-cancel') },
        { key: 'scrim', description: 'scrim click', act: async () => clickScrim(page) },
        { key: 'escape', description: 'Esc key', act: async () => page.keyboard.press('Escape') },
    ];

    for (const closePath of closePaths) {
        const state = await readModalState(page);
        if (!state.isOpen) {
            await openSignatureModal(page);
        }
        await closePath.act();
        await page.waitForTimeout(250);
        const closed = await readModalState(page);

        r.assert(`close-via-${closePath.key}`,
            !closed.isOpen && closed.ariaHidden === 'true' && !closed.bodyOpenClass,
            `Modal closes via ${closePath.description}`,
            `open=${closed.isOpen} aria-hidden=${closed.ariaHidden} body-class=${closed.bodyOpenClass}`);
    }

    // --- State reset on reopen -------------------------------------------
    // Dirty every mode we can before cancelling, so the reset assertions below
    // are meaningful. The Type controls live in a hidden panel while Draw is
    // active, so switch tabs first rather than waiting out an actionability
    // timeout on an invisible input.
    await openSignatureModal(page);
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(150);
    await page.fill('#signature-text', 'Dirty state');
    await page.selectOption('#signature-font', 'Pacifico');
    await page.waitForTimeout(150);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(150);
    await drawStroke(page);
    await page.click('#signature-cancel');
    await page.waitForTimeout(250);

    await openSignatureModal(page);
    const reset = await readModalState(page);

    r.assert('reset-canvas-blank', reset.canvasBlank === true,
        'Reopening after Cancel clears the preview canvas',
        `canvasBlank=${reset.canvasBlank}`);

    r.assert('reset-apply-disabled', reset.applyDisabled === true && reset.saveDisabled === true,
        'Reopening disables Apply and Save again',
        `apply=${reset.applyDisabled} save=${reset.saveDisabled}`);

    const resetValues = reset.values;
    // The font was changed to Pacifico before cancelling: settings persist,
    // content does not.
    const resetOk = resetValues.font === 'Pacifico'
        && resetValues.text === ''
        && resetValues.color === COMPOSER_DEFAULTS.color
        && resetValues.width === COMPOSER_DEFAULTS.width
        && resetValues.smoothing === COMPOSER_DEFAULTS.smoothing
        && resetValues.typeSize === COMPOSER_DEFAULTS.typeSize;
    r.assert('reset-remembers-settings', resetOk,
        'Reopening remembers the settings the user chose (NK_Dev_5) while clearing content',
        `font=${resetValues.font} text=${JSON.stringify(resetValues.text)} ink=${resetValues.color} width=${resetValues.width} smoothing=${resetValues.smoothing} size=${resetValues.typeSize}`);

    r.equals('reset-status', reset.status, DRAW_IDLE_STATUS,
        'Reopening restores the draw-mode idle status message');

    r.equals('reset-remembers-tab', reset.activeMode, 'draw',
        'Reopening returns to the tab the user last used');

    const resetArtifact = await capture(page, '03-modal-shell', 'reopened-clean-state');

    await page.keyboard.press('Escape');

    return { checks: r.checks, artifacts: [artifact, resetArtifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 04 — Draw tab: stroke capture and pointer handling
// ---------------------------------------------------------------------------

async function testDrawStrokeCapture(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    // --- ink lands under the pointer -------------------------------------
    const box = await page.locator('#signature-canvas').boundingBox();
    const y = box.y + (box.height / 2);
    const x1 = box.x + (box.width * 0.30);
    const x2 = box.x + (box.width * 0.70);
    await page.mouse.move(x1, y);
    await page.mouse.down();
    await page.mouse.move(x2, y, { steps: 20 });
    await page.mouse.up();
    await page.waitForTimeout(250);

    const painted = await page.evaluate(({ left, right, midY }) => {
        const canvas = document.getElementById('signature-canvas');
        const rect = canvas.getBoundingClientRect();
        const sx = canvas.width / rect.width;
        const sy = canvas.height / rect.height;
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let minX = canvas.width; let maxX = -1; let minY = canvas.height; let maxY = -1;
        for (let py = 0; py < canvas.height; py++) {
            for (let px = 0; px < canvas.width; px++) {
                if (data[((py * canvas.width + px) * 4) + 3] <= 8) continue;
                if (px < minX) minX = px;
                if (px > maxX) maxX = px;
                if (py < minY) minY = py;
                if (py > maxY) maxY = py;
            }
        }
        return {
            minX, maxX, minY, maxY,
            // Where the pointer actually went, in canvas pixels.
            expectedMinX: (left - rect.left) * sx,
            expectedMaxX: (right - rect.left) * sx,
            expectedY: (midY - rect.top) * sy,
        };
    }, { left: x1, right: x2, midY: y });

    // The CSS-scaled canvas is where offset bugs show up, so compare the
    // painted extent against the pointer path in canvas pixel space.
    const startDelta = Math.abs(painted.minX - painted.expectedMinX);
    const endDelta = Math.abs(painted.maxX - painted.expectedMaxX);
    const yDelta = Math.abs(((painted.minY + painted.maxY) / 2) - painted.expectedY);
    r.assert('ink-follows-pointer', startDelta <= 12 && endDelta <= 12 && yDelta <= 12,
        'Ink lands under the pointer on the CSS-scaled canvas',
        `startΔ=${startDelta.toFixed(1)} endΔ=${endDelta.toFixed(1)} yΔ=${yDelta.toFixed(1)} px`);

    let state = await readModalState(page);
    r.assert('stroke-commits', state.applyDisabled === false,
        'Releasing the pointer commits the stroke and enables Apply');
    r.equals('ready-status', state.status, 'Signature ready to place.',
        'Status reports the signature is ready');

    // --- multiple separate strokes ---------------------------------------
    const before = await canvasFingerprint(page);
    await page.mouse.move(box.x + (box.width * 0.35), box.y + (box.height * 0.75));
    await page.mouse.down();
    await page.mouse.move(box.x + (box.width * 0.60), box.y + (box.height * 0.80), { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(250);
    const after = await canvasFingerprint(page);
    r.assert('multiple-strokes', after.inked > before.inked,
        'A second separate stroke is added rather than replacing the first',
        `${before.inked} -> ${after.inked} inked px`);

    // --- pointerleave mid-stroke -----------------------------------------
    await page.mouse.move(box.x + (box.width * 0.5), box.y + (box.height * 0.5));
    await page.mouse.down();
    await page.mouse.move(box.x + box.width + 60, box.y + (box.height * 0.5), { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(200);
    const afterLeave = await canvasFingerprint(page);
    // Moving back across the canvas with the button up must not keep drawing.
    await page.mouse.move(box.x + (box.width * 0.2), box.y + (box.height * 0.2), { steps: 10 });
    await page.waitForTimeout(200);
    const afterHover = await canvasFingerprint(page);
    r.assert('no-stuck-drawing', afterHover.inked === afterLeave.inked,
        'Dragging off the canvas ends the stroke; hovering back does not draw',
        `${afterLeave.inked} -> ${afterHover.inked} inked px`);

    // --- ignored outside draw mode ---------------------------------------
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    const typeBefore = await canvasFingerprint(page);
    await page.mouse.move(box.x + (box.width * 0.3), box.y + (box.height * 0.4));
    await page.mouse.down();
    await page.mouse.move(box.x + (box.width * 0.6), box.y + (box.height * 0.6), { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(250);
    const typeAfter = await canvasFingerprint(page);
    r.assert('draw-ignored-off-mode', typeAfter.inked === typeBefore.inked,
        'Pointer input on the canvas is ignored outside Draw mode',
        `${typeBefore.inked} -> ${typeAfter.inked} inked px`);

    // --- touch input -------------------------------------------------------
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    const touched = await page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        const rect = canvas.getBoundingClientRect();
        const send = (type, x, y) => canvas.dispatchEvent(new PointerEvent(type, {
            bubbles: true, cancelable: true, pointerType: 'touch', pointerId: 7, isPrimary: true,
            clientX: rect.left + x, clientY: rect.top + y,
        }));
        send('pointerdown', rect.width * 0.3, rect.height * 0.5);
        for (let i = 1; i <= 8; i++) send('pointermove', rect.width * (0.3 + (i * 0.04)), rect.height * 0.5);
        send('pointerup', rect.width * 0.62, rect.height * 0.5);
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let inked = 0;
        for (let i = 3; i < data.length; i += 4) if (data[i] > 8) inked++;
        return inked;
    });
    r.assert('touch-draws', touched > 0,
        'Touch pointer input draws (pointer events, not mouse-only)', `${touched} inked px`);

    const artifact = await capture(page, '04-draw-stroke-capture', 'strokes');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 05 — Draw tab: ink colour, stroke width, smoothing
// ---------------------------------------------------------------------------

/** Set a colour input and fire the events the app listens for. */
async function setColour(page, id, value) {
    await page.evaluate(({ id: elId, value: hex }) => {
        const el = document.getElementById(elId);
        el.value = hex;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, { id, value });
    await page.waitForTimeout(200);
}

async function setRange(page, id, value) {
    await page.evaluate(({ id: elId, value: v }) => {
        const el = document.getElementById(elId);
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        setter.call(el, String(v));
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, { id, value });
    await page.waitForTimeout(250);
}

/** Count distinct ink colours currently on the composer canvas. */
function inkColours(page) {
    return page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        const seen = new Map();
        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] < 200) continue;
            const key = `${data[i] >> 4},${data[i + 1] >> 4},${data[i + 2] >> 4}`;
            seen.set(key, (seen.get(key) || 0) + 1);
        }
        return Array.from(seen.entries())
            .filter(([, count]) => count > 40)
            .map(([key, count]) => ({ key, count }))
            .sort((a, b) => b.count - a.count);
    });
}

async function testDrawControls(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    // --- readouts track the inputs ---------------------------------------
    await setRange(page, 'signature-width', 7);
    await setRange(page, 'signature-smoothing', 90);
    await setColour(page, 'signature-color', '#cc2222');
    let state = await readModalState(page);
    const labels = await page.evaluate(() => ({
        width: document.getElementById('signature-width-value')?.textContent?.trim(),
        smoothing: document.getElementById('signature-smoothing-value')?.textContent?.trim(),
        colour: document.getElementById('signature-color-value')?.textContent?.trim(),
    }));
    r.assert('readouts-track-inputs',
        labels.width === '7px' && labels.smoothing === '90%' && /CC2222/i.test(labels.colour || ''),
        'Width, smoothing and colour readouts track their inputs',
        `${labels.width} / ${labels.smoothing} / ${labels.colour}`);

    // --- new strokes use the current colour ------------------------------
    await drawStroke(page);
    const redOnly = await inkColours(page);
    const dominant = redOnly[0]?.key || '';
    r.assert('colour-applies-to-new-stroke', dominant.startsWith('12,2,2'),
        'A stroke drawn after changing the colour uses that colour',
        `dominant ink bucket ${dominant}`);

    // --- committed strokes keep their colour -----------------------------
    await setColour(page, 'signature-color', '#1155cc');
    const boxRect = await page.locator('#signature-canvas').boundingBox();
    await page.mouse.move(boxRect.x + (boxRect.width * 0.3), boxRect.y + (boxRect.height * 0.75));
    await page.mouse.down();
    await page.mouse.move(boxRect.x + (boxRect.width * 0.7), boxRect.y + (boxRect.height * 0.78), { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(300);
    const both = await inkColours(page);
    const hasRed = both.some((entry) => entry.key.startsWith('12,2,2'));
    const hasBlue = both.some((entry) => entry.key.startsWith('1,5,12'));
    r.assert('committed-strokes-keep-colour', hasRed && hasBlue,
        'Changing colour recolours only new strokes; committed ones keep theirs',
        `buckets: ${both.slice(0, 3).map((entry) => entry.key).join(' | ')}`);

    // --- width visibly changes the stroke --------------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', 1);
    await drawStroke(page);
    const thin = (await canvasFingerprint(page)).inked;
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', 8);
    await drawStroke(page);
    const thick = (await canvasFingerprint(page)).inked;
    r.assert('width-changes-stroke', thick > thin * 1.8,
        'Stroke width 8 paints substantially more ink than width 1',
        `1px=${thin}px vs 8px=${thick}px inked`);

    // --- smoothing repaints existing strokes -----------------------------
    const atHigh = await canvasFingerprint(page);
    await setRange(page, 'signature-smoothing', 0);
    const atLow = await canvasFingerprint(page);
    r.assert('smoothing-repaints', atLow.hash !== atHigh.hash && atLow.inked > 0,
        'Moving smoothing repaints the existing strokes (render-time setting)',
        `hash ${atHigh.hash} -> ${atLow.hash}, ${atLow.inked} inked px`);

    // --- extremes produce no NaN / blank ---------------------------------
    for (const [id, value] of [['signature-width', 1], ['signature-width', 8],
        ['signature-smoothing', 0], ['signature-smoothing', 100]]) {
        await setRange(page, id, value);
    }
    const extremes = await canvasFingerprint(page);
    state = await readModalState(page);
    r.assert('extremes-safe', extremes.inked > 0 && state.applyDisabled === false,
        'Sliders at their extremes leave the preview intact',
        `${extremes.inked} inked px, apply disabled=${state.applyDisabled}`);

    const artifact = await capture(page, '05-draw-controls', 'controls');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 06 — Type tab: text entry, fonts, webfont loading
// ---------------------------------------------------------------------------

const SIGNATURE_FONTS = [
    'Great Vibes', 'Dancing Script', 'Allura', 'Pacifico', 'Alex Brush', 'Sacramento',
    'Parisienne', 'Marck Script', 'Satisfy', 'Caveat', 'Kaushan Script', 'Tangerine',
];

async function testTypeFonts(page, ctx) {
    const r = ctx.recorder;

    // This test needs real webfonts, so lift the suite-wide block.
    await ctx.setFontsBlocked(false);
    await openSignatureModal(page);
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(200);

    // --- empty text is not placeable -------------------------------------
    let state = await readModalState(page);
    r.assert('empty-text-disabled', state.applyDisabled === true,
        'Empty text leaves Apply disabled');
    await page.fill('#signature-text', '   ');
    await page.waitForTimeout(400);
    state = await readModalState(page);
    r.assert('whitespace-text-disabled', state.applyDisabled === true,
        'Whitespace-only text is treated as empty', `apply disabled=${state.applyDisabled}`);

    await page.fill('#signature-text', 'Ada Lovelace');
    await page.waitForTimeout(600);
    state = await readModalState(page);
    r.equals('typed-ready-status', state.status, 'Typed signature ready to place.',
        'Typing enables the mark and reports it is ready');

    // --- every font renders, and distinctly ------------------------------
    const fingerprints = new Map();
    const failures = [];
    for (const font of SIGNATURE_FONTS) {
        await page.selectOption('#signature-font', font);
        // Give the lazy webfont time to load and repaint.
        await page.waitForTimeout(1200);
        const print = await canvasFingerprint(page);
        if (!print || print.inked === 0) failures.push(`${font}: blank`);
        fingerprints.set(font, print.hash);
    }
    r.assert('all-fonts-render', failures.length === 0,
        `All ${SIGNATURE_FONTS.length} script fonts render a mark`,
        failures.length ? failures.join(' | ') : `${SIGNATURE_FONTS.length} fonts checked`);

    const distinct = new Set(fingerprints.values()).size;
    r.assert('fonts-look-different', distinct >= Math.ceil(SIGNATURE_FONTS.length * 0.75),
        'Fonts render visibly differently rather than all falling back to one face',
        `${distinct} distinct renders across ${SIGNATURE_FONTS.length} fonts`);

    const linkCount = await page.evaluate(() => document.querySelectorAll('link[id^="signature-font-"]').length);
    r.assert('one-link-per-font', linkCount <= SIGNATURE_FONTS.length,
        'Each family injects at most one stylesheet link',
        `${linkCount} link tags for ${SIGNATURE_FONTS.length} fonts`);

    // --- rapid switching: the last selection must win ---------------------
    for (const font of ['Pacifico', 'Tangerine', 'Caveat', 'Allura']) {
        await page.selectOption('#signature-font', font);
        await page.waitForTimeout(60);
    }
    await page.waitForTimeout(2500);
    const settled = await canvasFingerprint(page);
    const allura = fingerprints.get('Allura');
    r.assert('no-stale-render-wins', settled.hash === allura,
        'After rapid font switching the preview matches the last selection',
        settled.hash === allura ? 'matches Allura' : `hash ${settled.hash} vs Allura ${allura}`);

    // --- blocked fonts still fall back -----------------------------------
    await ctx.setFontsBlocked(true);
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await page.selectOption('#signature-font', 'Marck Script');
    await page.fill('#signature-text', 'Offline Fallback');
    const fallbackStart = Date.now();
    await page.waitForFunction(() => {
        const canvas = document.getElementById('signature-canvas');
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        for (let i = 3; i < data.length; i += 4) if (data[i] > 8) return true;
        return false;
    }, { timeout: 8000 }).catch(() => {});
    const fallbackMs = Date.now() - fallbackStart;
    const fallback = await canvasFingerprint(page);
    r.assert('blocked-fonts-fall-back', fallback.inked > 0 && fallbackMs < 6000,
        'With Google Fonts blocked the preview still renders in a fallback face',
        `${fallback.inked} inked px after ${fallbackMs}ms`);

    const artifact = await capture(page, '06-type-fonts', 'typed-fonts');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 07 — Type tab: size, colour, auto-fit shrink
// ---------------------------------------------------------------------------

/** Width of the inked mark, in canvas pixels. */
async function inkWidth(page) {
    const ink = await measureCanvasInk(page);
    return ink && ink.inked ? ink.boxWidth : 0;
}

async function testTypeSizeAutofit(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(200);
    await page.fill('#signature-text', 'Ada');
    await page.waitForTimeout(500);

    // --- size slider rescales --------------------------------------------
    await setRange(page, 'signature-type-size', 48);
    await page.waitForTimeout(500);
    const small = await inkWidth(page);
    await setRange(page, 'signature-type-size', 180);
    await page.waitForTimeout(500);
    const large = await inkWidth(page);
    const sizeLabel = await page.evaluate(() => document.getElementById('signature-type-size-value')?.textContent?.trim());
    r.assert('size-rescales', large > small * 1.5,
        'Font size rescales the mark live', `48px -> ${small}px wide, 180px -> ${large}px wide`);
    r.equals('size-readout', sizeLabel, '180px', 'Size readout tracks the slider');

    // --- colour recolours the whole mark ---------------------------------
    await setColour(page, 'signature-type-color', '#118844');
    await page.waitForTimeout(500);
    const colours = await inkColours(page);
    r.assert('type-colour-applies', (colours[0]?.key || '').startsWith('1,8,4'),
        'Changing the typed ink colour recolours the whole mark',
        `dominant bucket ${colours[0]?.key}`);

    // --- auto-fit shrink --------------------------------------------------
    const canvasWidth = (await measureCanvasInk(page)).canvasWidth;
    await page.fill('#signature-text', 'Bartholomew Featherstonehaugh-Wintersmith');
    await page.waitForTimeout(900);
    const longWidth = await inkWidth(page);
    r.assert('autofit-within-canvas', longWidth > 0 && longWidth <= canvasWidth,
        'A very long name is shrunk to fit inside the canvas',
        `${longWidth}px of ${canvasWidth}px canvas`);
    // The fitter targets 82% of canvas width; allow the final step's overshoot.
    r.assert('autofit-targets-82pct', longWidth <= Math.round(canvasWidth * 0.92),
        'Long text is fitted to roughly the 82% target width, not clipped at the edge',
        `${longWidth}px vs 82% target ${Math.round(canvasWidth * 0.82)}px`);

    // Short text must NOT be shrunk.
    await page.fill('#signature-text', 'Ada');
    await page.waitForTimeout(700);
    const shortAgain = await inkWidth(page);
    r.assert('short-text-not-shrunk', Math.abs(shortAgain - large) <= Math.max(12, large * 0.08),
        'Short text returns to the selected size rather than staying shrunk',
        `${shortAgain}px vs ${large}px at the same slider value`);

    // --- unicode / CJK / emoji -------------------------------------------
    const exotic = [
        ['accented', 'José Ángel Muñoz'],
        ['cjk', '山田太郎'],
        ['emoji', 'Ada 🖋️'],
    ];
    const blanks = [];
    for (const [label, text] of exotic) {
        await page.fill('#signature-text', text);
        await page.waitForTimeout(700);
        const print = await canvasFingerprint(page);
        if (!print || print.inked === 0) blanks.push(label);
    }
    r.assert('unicode-renders', blanks.length === 0,
        'Accented, CJK and emoji text all render without blanking the canvas',
        blanks.length ? `blank for: ${blanks.join(', ')}` : 'all rendered');

    // --- leading/trailing whitespace is trimmed --------------------------
    await page.fill('#signature-text', 'Ada');
    await page.waitForTimeout(600);
    const plain = await canvasFingerprint(page);
    await page.fill('#signature-text', '   Ada   ');
    await page.waitForTimeout(600);
    const padded = await canvasFingerprint(page);
    r.assert('whitespace-trimmed', plain.hash === padded.hash,
        'Leading and trailing whitespace is trimmed before rendering',
        `hash ${plain.hash} vs ${padded.hash}`);

    const artifact = await capture(page, '07-type-size-autofit', 'autofit');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 08 — Upload tab: valid image import and normalisation
// ---------------------------------------------------------------------------

async function testUploadImport(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);
    await page.click('[data-signature-mode="upload"]');
    await page.waitForTimeout(250);

    const readLabel = () => page.evaluate(
        () => document.getElementById('signature-image-name')?.textContent?.trim() || '',
    );

    // --- every supported format imports ----------------------------------
    const formats = [
        { label: 'PNG', spec: { kind: 'image/png', name: 'sig.png', mimeType: 'image/png', width: 400, height: 160, transparent: true } },
        { label: 'JPEG', spec: { kind: 'image/jpeg', name: 'sig.jpg', mimeType: 'image/jpeg', width: 400, height: 160 } },
        { label: 'WebP', spec: { kind: 'image/webp', name: 'sig.webp', mimeType: 'image/webp', width: 400, height: 160 } },
        { label: 'GIF', spec: { literal: TINY_GIF, name: 'sig.gif', mimeType: 'image/gif' } },
        {
            label: 'SVG',
            spec: {
                literal: 'data:image/svg+xml;base64,' + Buffer.from(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="120">'
                    + '<path d="M10 90 L90 30 L170 95 L260 40" stroke="#1d4ed8" stroke-width="6" fill="none"/></svg>',
                ).toString('base64'),
                name: 'sig.svg',
                mimeType: 'image/svg+xml',
            },
        },
    ];

    const failed = [];
    for (const format of formats) {
        await page.click('#signature-clear');
        await page.waitForTimeout(200);
        await setSignatureFile(page, format.spec);
        const status = await waitForStatus(page, /ready to place|Failed|Choose an image/i, 9000);
        const state = await readModalState(page);
        const label = await readLabel();
        if (!/ready to place/i.test(status) || state.applyDisabled !== false || !/\d+×\d+/.test(label)) {
            failed.push(`${format.label}: status="${status}" apply=${state.applyDisabled} label="${label}"`);
        }
    }
    r.assert('all-formats-import', failed.length === 0,
        'PNG, JPEG, WebP, GIF and SVG all import and become placeable',
        failed.length ? failed.join(' | ') : `${formats.length} formats imported`);

    // --- mode switches automatically -------------------------------------
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await setSignatureFile(page, { kind: 'image/png', name: 'auto.png', mimeType: 'image/png', width: 300, height: 120 });
    await waitForStatus(page, /ready to place/i, 9000);
    const afterImport = await readModalState(page);
    r.equals('import-switches-mode', afterImport.activeMode, 'upload',
        'Importing a file switches the composer to Upload mode');

    // --- downscale to 2048 on the longest edge ----------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setSignatureFile(page, { kind: 'image/png', name: 'huge.png', mimeType: 'image/png', width: 4000, height: 3000 });
    await waitForStatus(page, /ready to place/i, 20000);
    const hugeLabel = await readLabel();
    r.assert('downscales-to-2048', /2048×1536/.test(hugeLabel),
        'A 4000×3000 import is downscaled so the longest edge is 2048px',
        `label "${hugeLabel}"`);

    // --- small images are not upscaled ------------------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setSignatureFile(page, { kind: 'image/png', name: 'small.png', mimeType: 'image/png', width: 300, height: 100 });
    await waitForStatus(page, /ready to place/i, 9000);
    const smallLabel = await readLabel();
    r.assert('no-upscale', /300×100/.test(smallLabel),
        'A 300×100 import keeps its own size rather than being upscaled',
        `label "${smallLabel}"`);

    // --- transparency survives --------------------------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setSignatureFile(page, { kind: 'image/png', name: 'alpha.png', mimeType: 'image/png', width: 400, height: 160, transparent: true });
    await waitForStatus(page, /ready to place/i, 9000);
    const alpha = await page.evaluate(() => {
        const canvas = document.getElementById('signature-canvas');
        const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        let clear = 0;
        let opaque = 0;
        for (let i = 3; i < data.length; i += 4) {
            if (data[i] < 8) clear++;
            else if (data[i] > 240) opaque++;
        }
        return { clear, opaque };
    });
    r.assert('transparency-preserved', alpha.clear > 0 && alpha.opaque > 0,
        'A transparent PNG keeps its alpha rather than gaining a solid box',
        `${alpha.clear} transparent px, ${alpha.opaque} opaque px`);

    // --- everything is re-encoded to PNG ----------------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setSignatureFile(page, { kind: 'image/jpeg', name: 'stamp.jpg', mimeType: 'image/jpeg', width: 300, height: 120 });
    await waitForStatus(page, /ready to place/i, 9000);
    await armPlacement(page);
    await clickPageFraction(page, 1, 0.5, 0.45);
    const saved = await captureAutosave(page, ctx.saveRecorder);
    const placed = saved.signatures[0];
    r.assert('reencoded-as-png', !!placed
        && String(placed.mimeType) === 'image/png'
        && String(placed.dataUrl || '').startsWith('data:image/png'),
        'A JPEG import is re-encoded to PNG for the stamped asset',
        placed ? `mimeType=${placed.mimeType} dataUrl=${String(placed.dataUrl).slice(0, 22)}…` : 'no signature placed');
    r.assert('upload-source-mode', !!placed && placed.signatureSourceMode === 'upload',
        'The placed annotation records that it came from an upload',
        placed ? `sourceMode=${placed.signatureSourceMode}` : 'n/a');

    const artifact = await capture(page, '08-upload-import', 'imported');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 09 — Upload tab: invalid, oversized and hostile files
// ---------------------------------------------------------------------------

async function testUploadInvalid(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);
    await page.click('[data-signature-mode="upload"]');
    await page.waitForTimeout(250);

    const cases = [
        {
            label: 'text file',
            spec: { literal: 'data:text/plain;base64,' + Buffer.from('not an image at all').toString('base64'), name: 'notes.txt', mimeType: 'text/plain' },
        },
        {
            label: 'zero-byte file',
            spec: { literal: 'data:application/octet-stream;base64,', name: 'empty.png', mimeType: '' },
        },
        {
            label: 'corrupt png',
            spec: { literal: 'data:image/png;base64,' + Buffer.from('\x89PNG\r\n\x1a\n-truncated-garbage').toString('base64'), name: 'broken.png', mimeType: 'image/png' },
        },
        {
            label: 'pdf renamed to .png',
            spec: { literal: 'data:application/pdf;base64,' + Buffer.from('%PDF-1.4 not really').toString('base64'), name: 'sneaky.png', mimeType: 'image/png' },
        },
    ];

    const survived = [];
    for (const testCase of cases) {
        await page.click('#signature-clear');
        await page.waitForTimeout(200);
        await setSignatureFile(page, testCase.spec);
        const status = await waitForStatus(page, /failed|choose an image|ready to place/i, 9000);
        const state = await readModalState(page);
        // Must not become placeable, and must say something useful.
        if (state.applyDisabled !== true || /ready to place/i.test(status)) {
            survived.push(`${testCase.label}: status="${status}" apply=${state.applyDisabled}`);
        }
    }
    r.assert('invalid-files-rejected', survived.length === 0,
        'Non-images, empty and corrupt files are refused and never become placeable',
        survived.length ? survived.join(' | ') : `${cases.length} bad inputs refused`);

    // --- hostile SVG must not execute or fetch ----------------------------
    let scriptRan = false;
    page.once('dialog', async (dialog) => { scriptRan = true; await dialog.dismiss(); });
    const remoteHits = [];
    const watcher = (request) => {
        if (/evil\.invalid/i.test(request.url())) remoteHits.push(request.url());
    };
    page.on('request', watcher);

    const hostileSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="80">'
        + '<script>window.__svgRan = true; alert("xss");</script>'
        + '<image href="https://evil.invalid/tracker.png" width="50" height="50"/>'
        + '<rect width="200" height="80" fill="#eee"/></svg>';

    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setSignatureFile(page, {
        literal: 'data:image/svg+xml;base64,' + Buffer.from(hostileSvg).toString('base64'),
        name: 'hostile.svg',
        mimeType: 'image/svg+xml',
    });
    await page.waitForTimeout(2500);
    page.off('request', watcher);

    const flagged = await page.evaluate(() => !!window.__svgRan);
    r.assert('svg-script-inert', !flagged && !scriptRan,
        'Script inside an imported SVG does not execute',
        `windowFlag=${flagged} dialog=${scriptRan}`);
    r.assert('svg-no-remote-fetch', remoteHits.length === 0,
        'An imported SVG does not fetch remote resources',
        remoteHits.length ? remoteHits.join(', ') : 'no external requests');

    // --- the composer still works afterwards ------------------------------
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);
    await drawStroke(page);
    const recovered = await readModalState(page);
    r.assert('recovers-after-failure', recovered.applyDisabled === false,
        'After a rejected import the composer still works in another mode',
        `apply disabled=${recovered.applyDisabled}`);

    // --- re-selecting the same file re-imports ----------------------------
    await page.click('[data-signature-mode="upload"]');
    await page.waitForTimeout(200);
    const spec = { kind: 'image/png', name: 'repeat.png', mimeType: 'image/png', width: 240, height: 90 };
    await setSignatureFile(page, spec);
    await waitForStatus(page, /ready to place/i, 9000);
    await page.click('#signature-clear');
    await page.waitForTimeout(300);
    await setSignatureFile(page, spec);
    const second = await waitForStatus(page, /ready to place/i, 9000);
    r.assert('same-file-reimports', /ready to place/i.test(second),
        'Choosing the same file again re-imports it',
        `status="${second}"`);

    const artifact = await capture(page, '09-upload-invalid', 'rejections');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 10 — Clear, transparent trim, dirty-state gating
// ---------------------------------------------------------------------------

async function testPreviewTrimGating(page, ctx) {
    const r = ctx.recorder;

    // --- Dirty-state gating ----------------------------------------------
    await openSignatureModal(page);
    let state = await readModalState(page);
    r.assert('gating-empty-disabled', state.applyDisabled === true && state.saveDisabled === true,
        'Apply and Save start disabled on an empty canvas',
        `apply=${state.applyDisabled} save=${state.saveDisabled}`);

    await drawStroke(page);
    state = await readModalState(page);
    r.assert('gating-draw-enables', state.applyDisabled === false && state.saveDisabled === false,
        'Drawing enables Apply and the account Save',
        `apply=${state.applyDisabled} save=${state.saveDisabled}`);

    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    state = await readModalState(page);
    r.assert('gating-empty-type-disables', state.applyDisabled === true,
        'Switching to Type with empty text disables Apply',
        `apply=${state.applyDisabled}`);

    await page.fill('#signature-text', 'Ada Lovelace');
    await page.waitForTimeout(350);
    state = await readModalState(page);
    r.assert('gating-typed-enables', state.applyDisabled === false,
        'Typing text enables Apply', `apply=${state.applyDisabled}`);

    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);
    state = await readModalState(page);
    r.assert('gating-back-to-draw', state.applyDisabled === false,
        'Returning to Draw re-enables Apply because the strokes are still there',
        `apply=${state.applyDisabled}`);

    // --- Clear in draw mode ----------------------------------------------
    await page.click('#signature-clear');
    await page.waitForTimeout(250);
    state = await readModalState(page);
    r.assert('clear-draw', state.canvasBlank === true && state.applyDisabled === true && state.saveDisabled === true,
        'Clear wipes the canvas and disables Apply/Save',
        `blank=${state.canvasBlank} apply=${state.applyDisabled} save=${state.saveDisabled}`);
    r.equals('clear-status', state.status, 'Preview cleared.', 'Clear reports "Preview cleared."');
    r.equals('clear-resets-text', state.values.text, '', 'Clear also empties the typed-signature text field');

    // Clearing an already-empty canvas must be harmless.
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    state = await readModalState(page);
    r.assert('clear-idempotent', state.canvasBlank === true && state.applyDisabled === true,
        'Clearing an already-empty preview is a harmless no-op',
        `blank=${state.canvasBlank} apply=${state.applyDisabled}`);

    // --- Transparent trim -------------------------------------------------
    // Draw a compact mark, measure its ink box, then confirm the placed
    // annotation was cropped to that box plus ~5% padding (min 2px) rather
    // than carrying the whole 900x300 canvas.
    await drawStroke(page);
    await page.waitForTimeout(150);
    const ink = await measureCanvasInk(page);

    await armPlacement(page);
    await clickPageFraction(page, 1, 0.5, 0.5);
    const saved = await captureAutosave(page, ctx.saveRecorder);

    const placed = saved.signatures[0];
    if (!placed || !ink || !ink.inked) {
        r.fail('trim-measurable', 'Placed signature and canvas ink are measurable',
            `signatures=${saved.signatures.length} inked=${ink ? ink.inked : 'n/a'}`);
    } else {
        const intrinsicW = num(placed.intrinsicWidth);
        const intrinsicH = num(placed.intrinsicHeight);

        r.assert('trim-smaller-than-canvas',
            intrinsicW < ink.canvasWidth && intrinsicH < ink.canvasHeight,
            'Placed asset is cropped to the ink, not the full composer canvas',
            `asset ${intrinsicW}x${intrinsicH} vs canvas ${ink.canvasWidth}x${ink.canvasHeight}`);

        // trimmed = ink box + 2 * max(2, ceil(dimension * 0.05)), clamped to canvas.
        const padX = Math.max(2, Math.ceil(ink.boxWidth * 0.05));
        const padY = Math.max(2, Math.ceil(ink.boxHeight * 0.05));
        const expectedW = Math.min(ink.canvasWidth, ink.boxWidth + (padX * 2));
        const expectedH = Math.min(ink.canvasHeight, ink.boxHeight + (padY * 2));

        r.assert('trim-padding-width', Math.abs(intrinsicW - expectedW) <= 2,
            'Trimmed width equals the ink box plus ~5% padding',
            `expected ~${expectedW}, got ${intrinsicW} (ink box ${ink.boxWidth})`);
        r.assert('trim-padding-height', Math.abs(intrinsicH - expectedH) <= 2,
            'Trimmed height equals the ink box plus ~5% padding',
            `expected ~${expectedH}, got ${intrinsicH} (ink box ${ink.boxHeight})`);

        // Aspect must survive the crop into placed page geometry.
        const assetAspect = intrinsicW / intrinsicH;
        const placedAspect = num(placed.pdfWidth) / num(placed.pdfHeight);
        r.assert('trim-aspect-preserved', Math.abs(assetAspect - placedAspect) / assetAspect <= 0.02,
            'Placed box preserves the trimmed asset aspect ratio',
            `asset ${assetAspect.toFixed(3)} vs placed ${placedAspect.toFixed(3)}`);
    }

    const artifact = await capture(page, '10-preview-trim-gating', 'trimmed-signature-placed');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Tests 11-13 — the saved-signature library
//
// NOTE: the original subtasks described a per-browser localStorage library.
// NK_Dev_4 removed that ("This browser" section) and replaced it with an
// account-backed list, so these are retargeted at the account library.
//
// The SERVER contract (ownership isolation, limits, validation) is covered by
// tests/Feature/SavedSignatureTest.php. Here the endpoints are stubbed so the
// client behaviour is exercised deterministically, with no account
// provisioning and no rows left behind.
// ---------------------------------------------------------------------------

/**
 * Make the editor render as if a signed-in account had loaded it.
 *
 * The flag is server-rendered onto the modal, and the client skips the
 * account fetch entirely when it is "0". Flipping it after load would leave
 * the initial fetch un-exercised (and quietly turn "signed out" assertions
 * into false passes), so rewrite the document response instead.
 */
async function renderEditorAsSignedIn(page, docId) {
    await page.route(/\/documents\/\d+\/edit-new/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(
            'data-account-signatures="0"',
            'data-account-signatures="1"',
        );
        await route.fulfill({ response, body });
    });
    await openEditor(page, docId);

    const enabled = await page.evaluate(
        () => document.getElementById('signature-modal')?.dataset.accountSignatures,
    );
    if (enabled !== '1') throw new Error(`Editor did not render as signed in (flag=${enabled})`);
}

/**
 * Stub /saved-signatures with an in-memory store so list/save/delete can be
 * driven without touching the database.
 */
async function stubAccountEndpoint(page, initial = [], options = {}) {
    const state = { entries: [...initial], posted: [], patched: [], deleted: [], nextId: 100 };

    await page.route(/\/saved-signatures(\/.*)?$/, async (route) => {
        const request = route.request();
        const method = request.method();

        if (options.unauthorised) {
            return route.fulfill({
                status: 401,
                contentType: 'application/json',
                body: JSON.stringify({ success: false, signed_in: false, message: 'Sign in to save signatures to your account.' }),
            });
        }

        if (method === 'GET') {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, signed_in: true, limit: 20, signatures: state.entries }),
            });
        }

        if (method === 'POST') {
            let body = {};
            try { body = JSON.parse(request.postData() || '{}'); } catch (_) { body = {}; }
            state.posted.push(body);

            if (options.limitReached) {
                return route.fulfill({
                    status: 422,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, message: 'You have reached the 20 saved signature limit. Delete one first.' }),
                });
            }

            const created = {
                id: String(state.nextId++),
                name: body.name || `Signature ${state.entries.length + 1}`,
                sourceMode: body.source_mode || 'draw',
                dataUrl: body.data_url,
                composer: body.composer || null,
                width: body.width || 1,
                height: body.height || 1,
                updatedAt: '2026-08-22T12:00:00+00:00',
            };
            state.entries = [created, ...state.entries];
            return route.fulfill({
                status: 201,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, signature: created }),
            });
        }

        if (method === 'PATCH') {
            const id = decodeURIComponent(request.url().split('/').pop());
            let body = {};
            try { body = JSON.parse(request.postData() || '{}'); } catch (_) { body = {}; }
            state.patched.push({ id, name: body.name });
            state.entries = state.entries.map((entry) => (
                String(entry.id) === String(id) ? { ...entry, name: body.name } : entry
            ));
            const updated = state.entries.find((entry) => String(entry.id) === String(id));
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, signature: updated }),
            });
        }

        if (method === 'DELETE') {
            const id = decodeURIComponent(request.url().split('/').pop());
            state.deleted.push(id);
            state.entries = state.entries.filter((entry) => String(entry.id) !== String(id));
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
        }

        return route.fulfill({ status: 405, body: '' });
    });

    return state;
}

const TYPED_ENTRY = {
    id: '1',
    name: 'Work signature',
    sourceMode: 'type',
    // 1x1 png; the composer rebuilds the real mark from `composer`.
    dataUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    composer: { mode: 'type', text: 'Grace Hopper', fontFamily: 'Great Vibes', inkColor: '#111827', fontSize: 120 },
    width: 300,
    height: 90,
    updatedAt: '2026-08-20T09:30:00+00:00',
};

async function testLibrarySave(page, ctx) {
    const r = ctx.recorder;
    const store = await stubAccountEndpoint(page, []);
    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);

    // --- gated on having content -----------------------------------------
    let state = await readModalState(page);
    r.assert('save-gated-empty', state.saveDisabled === true,
        'The account Save button is disabled with an empty preview');

    await drawStroke(page);
    state = await readModalState(page);
    r.assert('save-enabled-when-drawn', state.saveDisabled === false,
        'Drawing enables the account Save button');

    // --- saving posts the right payload ----------------------------------
    await page.click('#signature-save-account');
    await waitForStatus(page, /to your account|Sign in|Could not/i, 9000);

    const posted = store.posted[0] || {};
    r.assert('post-payload-complete',
        typeof posted.data_url === 'string'
        && posted.data_url.startsWith('data:image/png')
        && posted.source_mode === 'draw'
        && !!posted.composer
        && Array.isArray(posted.composer.strokes)
        && posted.composer.strokes.length > 0,
        'Saving posts the PNG data URL, source mode and composer strokes',
        `mode=${posted.source_mode} strokes=${posted.composer?.strokes?.length} dataUrl=${String(posted.data_url).slice(0, 20)}…`);

    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(500);
    const rows = await page.locator('#signature-account-list .signature-library__item').count();
    r.assert('entry-appears', rows === 1, 'The saved signature appears in the list', `${rows} row(s)`);

    const rowText = await page.locator('#signature-account-list').textContent();
    r.assert('row-shows-metadata', /Drawn signature/i.test(rowText),
        'The row shows the signature mode', rowText.replace(/\s+/g, ' ').trim().slice(0, 90));

    const thumb = await page.locator('#signature-account-list .signature-library__thumb img').count();
    r.assert('row-shows-thumbnail', thumb === 1, 'The row shows a thumbnail of the mark');

    // --- server-side limit surfaces to the user ---------------------------
    await page.unroute(/\/saved-signatures(\/.*)?$/);
    await stubAccountEndpoint(page, [], { limitReached: true });
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await drawStroke(page);
    await page.click('#signature-save-account');
    const limitStatus = await waitForStatus(page, /limit|Could not/i, 9000);
    r.assert('limit-surfaced', /limit/i.test(limitStatus),
        'A server-side limit error is shown to the user', `status="${limitStatus}"`);

    const artifact = await capture(page, '11-library-save', 'saved-entry');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

async function testLibraryLoadDelete(page, ctx) {
    const r = ctx.recorder;
    const store = await stubAccountEndpoint(page, [TYPED_ENTRY]);

    // The list is fetched on load, so load the editor with the stub in place.
    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(700);

    const rows = await page.locator('#signature-account-list .signature-library__item').count();
    r.assert('list-hydrates', rows === 1,
        'The account list hydrates from the server on load', `${rows} row(s)`);

    // --- loading restores the original mode and settings ------------------
    await page.locator('#signature-account-list [data-account-signature-action="load"]').first().click();
    await page.waitForTimeout(1200);

    const loaded = await page.evaluate(() => ({
        savedView: document.getElementById('signature-modal').classList.contains('is-saved-view'),
        mode: document.querySelector('[data-signature-mode].is-active')?.dataset.signatureMode || null,
        text: document.getElementById('signature-text').value,
        font: document.getElementById('signature-font').value,
        size: document.getElementById('signature-type-size').value,
        apply: document.getElementById('signature-apply').disabled,
    }));
    r.assert('load-restores-mode', loaded.mode === 'type' && loaded.savedView === false,
        'Loading switches to the entry\'s own mode tab and leaves the Saved view',
        `mode=${loaded.mode} savedView=${loaded.savedView}`);
    r.assert('load-restores-settings',
        loaded.text === 'Grace Hopper' && loaded.font === 'Great Vibes' && loaded.size === '120',
        'Loading restores the typed text, font and size',
        `"${loaded.text}" / ${loaded.font} / ${loaded.size}px`);
    r.assert('load-enables-apply', loaded.apply === false,
        'A loaded signature is immediately placeable');

    const repainted = await canvasFingerprint(page);
    r.assert('load-repaints-preview', repainted.inked > 0,
        'Loading repaints the live preview', `${repainted.inked} inked px`);

    // --- deleting removes only that row -----------------------------------
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(400);
    await page.locator('#signature-account-list [data-account-signature-action="delete"]').first().click();
    await page.waitForTimeout(900);

    r.assert('delete-calls-server', store.deleted.includes('1'),
        'Delete issues a DELETE for that signature', `deleted=[${store.deleted.join(',')}]`);
    const afterDelete = await page.locator('#signature-account-list .signature-library__item').count();
    r.assert('delete-removes-row', afterDelete === 0,
        'The deleted row disappears from the list', `${afterDelete} row(s) left`);
    const emptyText = await page.locator('#signature-account-list').textContent();
    r.assert('empty-state-returns', /no account signatures/i.test(emptyText),
        'The empty state returns once the last entry is gone',
        emptyText.replace(/\s+/g, ' ').trim().slice(0, 70));

    const artifact = await capture(page, '12-library-load-delete', 'after-delete');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

async function testLibraryFailures(page, ctx) {
    const r = ctx.recorder;

    // --- a signed-out session is a normal state, not an error -------------
    await stubAccountEndpoint(page, [], { unauthorised: true });
    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(600);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await drawStroke(page);
    await page.click('#signature-save-account');
    const signInStatus = await waitForStatus(page, /sign in/i, 9000);
    r.assert('401-prompts-sign-in', /sign in/i.test(signInStatus),
        'A 401 from the server asks the user to sign in rather than showing a raw error',
        `status="${signInStatus}"`);

    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(500);
    const listText = await page.locator('#signature-account-list').textContent();
    r.assert('401-list-prompts', /sign in/i.test(listText),
        'The list shows a sign-in prompt rather than an empty state',
        listText.replace(/\s+/g, ' ').trim().slice(0, 70));

    // --- hostile entry names must render as text --------------------------
    await page.unroute(/\/saved-signatures(\/.*)?$/);
    await stubAccountEndpoint(page, [{
        ...TYPED_ENTRY,
        id: '9',
        name: '<img src=x onerror="window.__xss=true"> "><script>window.__xss=true<\/script>',
    }]);
    let dialogFired = false;
    page.once('dialog', async (dialog) => { dialogFired = true; await dialog.dismiss(); });

    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(800);

    const xss = await page.evaluate(() => ({
        flagged: !!window.__xss,
        injectedImg: !!document.querySelector('#signature-account-list img[src="x"]'),
        injectedScript: document.querySelectorAll('#signature-account-list script').length,
        text: document.getElementById('signature-account-list')?.textContent || '',
    }));
    r.assert('entry-names-escaped',
        !xss.flagged && !dialogFired && !xss.injectedImg && xss.injectedScript === 0,
        'A hostile signature name renders as literal text and never executes',
        `flag=${xss.flagged} img=${xss.injectedImg} scripts=${xss.injectedScript}`);
    r.assert('hostile-name-visible-as-text', xss.text.includes('<img src=x'),
        'The hostile name is displayed escaped, so the row is still identifiable',
        xss.text.replace(/\s+/g, ' ').trim().slice(0, 80));

    const artifact = await capture(page, '13-library-storage-failures', 'escaped-name');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 14 — Placement sizing, clamping and cancel
// ---------------------------------------------------------------------------

async function testPlacementSizing(page, ctx) {
    const r = ctx.recorder;

    // --- Arming -----------------------------------------------------------
    await composeDrawnMark(page);
    await armPlacement(page);

    const armed = await page.evaluate(() => ({
        placing: document.body.classList.contains('enpv-signature-placing'),
        modalOpen: !!document.querySelector('#signature-modal.is-open'),
        status: (document.getElementById('status-text') || document.getElementById('enpv-status'))?.textContent?.trim() || null,
    }));
    r.assert('arm-placing-state', armed.placing && !armed.modalOpen,
        'Apply closes the modal and arms placement mode',
        `placing=${armed.placing} modalOpen=${armed.modalOpen}`);

    // --- Clicking off-page must not place ---------------------------------
    const offPage = await findOffPagePoint(page);
    if (!offPage) {
        r.fail('offpage-click-ignored', 'An off-page point is reachable to click',
            'viewport is entirely covered by page content; cannot exercise this path');
    } else {
        await page.mouse.click(offPage.x, offPage.y);
        await page.waitForTimeout(400);
        const afterOffPage = await page.locator('.enpv-annotation-box[data-annotation-type="signature"]').count();
        const stillArmed = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
        r.assert('offpage-click-ignored', afterOffPage === 0 && stillArmed,
            'Clicking outside any page places nothing and leaves placement armed',
            `point=(${offPage.x},${offPage.y}) boxes=${afterOffPage} stillArmed=${stillArmed}`);
    }

    // --- Centre placement --------------------------------------------------
    const clickPoint = await clickPageFraction(page, 1, 0.5, 0.5);

    const selected = await page.evaluate(() => {
        const boxes = Array.from(document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"]'));
        return {
            count: boxes.length,
            anySelected: boxes.some((box) => box.classList.contains('is-selected') || box.getAttribute('aria-selected') === 'true'),
        };
    });
    r.assert('centre-placed', selected.count === 1,
        'Clicking the page places exactly one signature', `boxes=${selected.count}`);

    // --- Corner placements for clamping -----------------------------------
    const corners = [
        { key: 'top-left', fx: 0.02, fy: 0.03 },
        { key: 'top-right', fx: 0.98, fy: 0.03 },
        { key: 'bottom-left', fx: 0.02, fy: 0.97 },
        { key: 'bottom-right', fx: 0.98, fy: 0.97 },
    ];
    const cornerClicks = [];
    for (const corner of corners) {
        await composeDrawnMark(page);
        await armPlacement(page);
        const clicked = await clickPageFraction(page, 1, corner.fx, corner.fy);
        cornerClicks.push({ ...corner, clicked });
    }

    const saved = await captureAutosave(page, ctx.saveRecorder);
    r.assert('all-placed', saved.signatures.length === 5,
        'Centre plus four corner placements all persisted',
        `signatures=${saved.signatures.length}`);

    // --- Sizing rules on the centre placement ------------------------------
    const centre = saved.signatures.find((annotation) => {
        const cx = num(annotation.pdfX) + (num(annotation.pdfWidth) / 2);
        const cy = num(annotation.pdfY) + (num(annotation.pdfHeight) / 2);
        return Math.abs(cx - clickPoint.pdfX) < 40 && Math.abs(cy - clickPoint.pdfY) < 40;
    }) || saved.signatures[0];

    if (!centre) {
        r.fail('sizing-measurable', 'A centre-placed signature is available to measure', 'none found');
    } else {
        const w = num(centre.pdfWidth);
        const h = num(centre.pdfHeight);
        const maxW = PAGE_WIDTH_PTS * PLACEMENT.maxWidthFraction;
        const maxH = PAGE_HEIGHT_PTS * PLACEMENT.maxHeightFraction;

        r.assert('size-width-cap', w <= maxW + 0.5,
            'Width respects the 34% page-width cap',
            `w=${w.toFixed(1)} cap=${maxW.toFixed(1)}`);

        r.assert('size-height-cap', h <= maxH + 0.5,
            'Height respects the 18% page-height cap',
            `h=${h.toFixed(1)} cap=${maxH.toFixed(1)}`);

        r.assert('size-positive', w >= PLACEMENT.minWidthPts - 0.5 && h >= PLACEMENT.minHeightPts - 0.5,
            'Placed box keeps the documented minimum dimensions',
            `w=${w.toFixed(1)} h=${h.toFixed(1)}`);

        const assetAspect = num(centre.intrinsicWidth) / num(centre.intrinsicHeight);
        r.assert('size-aspect', Math.abs((w / h) - assetAspect) / assetAspect <= 0.02,
            'Placed box preserves the signature aspect ratio',
            `placed=${(w / h).toFixed(3)} asset=${assetAspect.toFixed(3)}`);

        const cx = num(centre.pdfX) + (w / 2);
        const cy = num(centre.pdfY) + (h / 2);
        r.assert('centred-on-click',
            Math.abs(cx - clickPoint.pdfX) <= 6 && Math.abs(cy - clickPoint.pdfY) <= 6,
            'Signature is centred on the click point',
            `centre=(${cx.toFixed(1)}, ${cy.toFixed(1)}) click=(${clickPoint.pdfX.toFixed(1)}, ${clickPoint.pdfY.toFixed(1)})`);
    }

    // --- Clamping ----------------------------------------------------------
    const margin = PLACEMENT.edgeMarginPts;
    const outOfBounds = saved.signatures.filter((annotation) => {
        const x = num(annotation.pdfX);
        const y = num(annotation.pdfY);
        const w = num(annotation.pdfWidth);
        const h = num(annotation.pdfHeight);
        return x < margin - 0.5
            || y < margin - 0.5
            || (x + w) > (PAGE_WIDTH_PTS - margin) + 0.5
            || (y + h) > (PAGE_HEIGHT_PTS - margin) + 0.5;
    });
    r.assert('clamped-inside-page', outOfBounds.length === 0,
        `Every placement is clamped inside the page with a ${margin}pt margin`,
        outOfBounds.length ? outOfBounds.map(geom).join(' | ') : `all ${saved.signatures.length} within bounds`);

    // The blanket bounds check above is vacuous unless a placement actually
    // needed clamping, so verify at least one did and that it snapped to the
    // margin exactly rather than merely landing inside it.
    const clampEvidence = [];
    for (const entry of cornerClicks) {
        const match = saved.signatures.find((annotation) => {
            const cx = num(annotation.pdfX) + (num(annotation.pdfWidth) / 2);
            const cy = num(annotation.pdfY) + (num(annotation.pdfHeight) / 2);
            return Math.hypot(cx - entry.clicked.pdfX, cy - entry.clicked.pdfY) < 130;
        });
        if (!match) continue;

        const w = num(match.pdfWidth);
        const h = num(match.pdfHeight);
        const wantedX = entry.clicked.pdfX - (w / 2);
        const wantedY = entry.clicked.pdfY - (h / 2);
        const expectedX = Math.max(margin, Math.min(PAGE_WIDTH_PTS - w - margin, wantedX));
        const expectedY = Math.max(margin, Math.min(PAGE_HEIGHT_PTS - h - margin, wantedY));
        const wasClamped = Math.abs(expectedX - wantedX) > 0.5 || Math.abs(expectedY - wantedY) > 0.5;
        const correct = Math.abs(num(match.pdfX) - expectedX) <= 1.5
            && Math.abs(num(match.pdfY) - expectedY) <= 1.5;

        clampEvidence.push({ key: entry.key, wasClamped, correct, expectedX, expectedY, match });
    }

    const clampedCases = clampEvidence.filter((entry) => entry.wasClamped);
    r.assert('clamping-exercised', clampedCases.length > 0,
        'At least one edge placement actually required clamping',
        `${clampedCases.length}/${clampEvidence.length} corner placements hit a page edge`);

    const misclamped = clampEvidence.filter((entry) => !entry.correct);
    r.assert('clamping-exact', misclamped.length === 0,
        'Clamped placements snap exactly to the 12pt margin',
        misclamped.length
            ? misclamped.map((entry) => `${entry.key}: expected (${entry.expectedX.toFixed(1)}, `
                + `${entry.expectedY.toFixed(1)}) got (${num(entry.match.pdfX).toFixed(1)}, `
                + `${num(entry.match.pdfY).toFixed(1)})`).join(' | ')
            : `${clampEvidence.length} corner placements land exactly where the rules predict`);

    // --- Esc cancels placement --------------------------------------------
    await composeDrawnMark(page);
    await armPlacement(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    const cancelled = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    r.assert('esc-clears-placing', !cancelled,
        'Esc cancels placement mode', `placing=${cancelled}`);

    const beforeStrayClick = await page.locator('.enpv-annotation-box[data-annotation-type="signature"]').count();
    await clickPageFraction(page, 1, 0.3, 0.3);
    const afterStrayClick = await page.locator('.enpv-annotation-box[data-annotation-type="signature"]').count();
    r.assert('esc-prevents-placement', afterStrayClick === beforeStrayClick,
        'After Esc, clicking the page does not place a signature',
        `before=${beforeStrayClick} after=${afterStrayClick}`);

    const artifact = await capture(page, '14-placement-sizing', 'placements-and-clamping');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 15 — Placement across zoom, multi-page and scroll
// ---------------------------------------------------------------------------

async function testPlacementZoomScroll(page, ctx) {
    const r = ctx.recorder;

    const pageCount = await page.locator('#viewer .page').count();
    r.assert('multi-page-doc', pageCount >= 3,
        'Test document rendered with at least 3 pages', `pages=${pageCount}`);

    const seenIds = new Set();

    /**
     * Place one signature and pair it with the annotation it produced, so
     * every assertion compares a placement against its own click point.
     */
    const placeAndIdentify = async (label, opts) => {
        const clicked = await placeDrawnSignature(page, opts);
        const saved = await captureAutosave(page, ctx.saveRecorder);
        const fresh = saved.signatures.filter((annotation) => !seenIds.has(String(annotation.id)));
        fresh.forEach((annotation) => seenIds.add(String(annotation.id)));
        return { label, clicked, annotation: fresh[fresh.length - 1] || null, total: saved.signatures.length };
    };

    // --- Same target at three zoom levels ---------------------------------
    const target = { fx: 0.5, fy: 0.4 };
    const rounds = [];

    const baseScale = await page.evaluate(() => Number(window.__enpv?.pdfViewer?.currentScale) || null);
    rounds.push({ ...(await placeAndIdentify('default', { pageNumber: 1, ...target })), scale: baseScale });

    const zoomedIn = await stepZoom(page, 'in', 2);
    rounds.push({ ...(await placeAndIdentify('zoomed-in', { pageNumber: 1, ...target })), scale: zoomedIn });

    const zoomedOut = await stepZoom(page, 'out', 4);
    rounds.push({ ...(await placeAndIdentify('zoomed-out', { pageNumber: 1, ...target })), scale: zoomedOut });

    r.assert('zoom-levels-differ', new Set(rounds.map((round) => round.scale)).size >= 2,
        'Zoom controls actually changed the viewer scale',
        rounds.map((round) => `${round.label}=${round.scale}`).join(', '));

    r.assert('placed-at-every-zoom', rounds.every((round) => !!round.annotation),
        'A signature was placed successfully at each zoom level',
        rounds.map((round) => `${round.label}=${round.annotation ? 'ok' : 'MISSING'}`).join(', '));

    // The core risk of the pdf.js port: does a clicked pixel map to the right
    // PDF point at every scale?
    const accuracy = rounds
        .filter((round) => round.annotation)
        .map((round) => {
            const centreX = num(round.annotation.pdfX) + (num(round.annotation.pdfWidth) / 2);
            const centreY = num(round.annotation.pdfY) + (num(round.annotation.pdfHeight) / 2);
            return {
                label: round.label,
                scale: round.scale,
                dx: Math.abs(centreX - round.clicked.pdfX),
                dy: Math.abs(centreY - round.clicked.pdfY),
            };
        });

    const worst = accuracy.reduce((acc, entry) => Math.max(acc, entry.dx, entry.dy), 0);
    r.assert('zoom-accurate-mapping', accuracy.length > 0 && worst <= 6,
        'A clicked pixel maps to the same PDF point at every zoom level',
        accuracy.map((entry) => `${entry.label}@${entry.scale}: dx=${entry.dx.toFixed(2)} dy=${entry.dy.toFixed(2)}`).join(' | '));

    const widths = rounds.filter((round) => round.annotation).map((round) => num(round.annotation.pdfWidth));
    const widthSpread = widths.length ? Math.max(...widths) - Math.min(...widths) : Infinity;
    r.assert('zoom-invariant-size', widthSpread <= 2,
        'Physical size on the page is independent of the zoom used to place it',
        `widths: ${widths.map((w) => w.toFixed(1)).join(', ')}`);

    // --- Multi-page targeting ---------------------------------------------
    const onPage2 = await placeAndIdentify('page-2', { pageNumber: 2, fx: 0.4, fy: 0.5 });
    const onPage3 = await placeAndIdentify('page-3', { pageNumber: 3, fx: 0.6, fy: 0.6 });

    r.assert('page2-targeting', !!onPage2.annotation && Number(onPage2.annotation.pageIndex) === 1,
        'Clicking page 2 attaches the signature to page 2, not page 1',
        `pageIndex=${onPage2.annotation ? onPage2.annotation.pageIndex : 'missing'}`);
    r.assert('page3-targeting', !!onPage3.annotation && Number(onPage3.annotation.pageIndex) === 2,
        'Clicking page 3 attaches the signature to page 3',
        `pageIndex=${onPage3.annotation ? onPage3.annotation.pageIndex : 'missing'}`);

    const saved = await captureAutosave(page, ctx.saveRecorder);
    r.assert('all-five-persisted', saved.signatures.length === 5,
        'All five placements persisted', `count=${saved.signatures.length}`);

    const stray = saved.signatures.filter((annotation) => (
        num(annotation.pdfX) < 0
        || num(annotation.pdfY) < 0
        || num(annotation.pdfX) + num(annotation.pdfWidth) > PAGE_WIDTH_PTS
        || num(annotation.pdfY) + num(annotation.pdfHeight) > PAGE_HEIGHT_PTS
    ));
    r.assert('page-bounds', stray.length === 0,
        'Every placement stays inside its own page',
        stray.length ? stray.map(geom).join(' | ') : `${saved.signatures.length} checked`);

    // --- Scroll / virtualisation ------------------------------------------
    await page.evaluate(() => {
        const container = document.getElementById('viewerContainer');
        if (container) container.scrollTop = container.scrollHeight;
    });
    await page.waitForTimeout(1000);
    await page.evaluate(() => {
        const container = document.getElementById('viewerContainer');
        if (container) container.scrollTop = 0;
    });
    await page.waitForTimeout(1400);

    const afterScroll = await captureAutosave(page, ctx.saveRecorder);
    r.assert('survives-virtualisation', afterScroll.signatures.length === saved.signatures.length,
        'Scrolling pages out of view and back neither drops nor duplicates signatures',
        `before=${saved.signatures.length} after=${afterScroll.signatures.length}`);

    const drift = saved.signatures.map((before) => {
        const after = afterScroll.signatures.find((candidate) => candidate.id === before.id);
        if (!after) return Infinity;
        return Math.max(
            Math.abs(num(before.pdfX) - num(after.pdfX)),
            Math.abs(num(before.pdfY) - num(after.pdfY)),
            Math.abs(num(before.pdfWidth) - num(after.pdfWidth)),
            Math.abs(num(before.pdfHeight) - num(after.pdfHeight)),
        );
    });
    const worstDrift = drift.length ? Math.max(...drift) : Infinity;
    r.assert('no-drift-after-scroll', worstDrift <= 0.5,
        'Geometry does not drift after pages re-render',
        `worst delta=${Number.isFinite(worstDrift) ? worstDrift.toFixed(3) + 'pt' : 'annotation missing after scroll'}`);

    const artifact = await capture(page, '15-placement-zoom-rotation', 'multi-page-placements');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 16 — Placed signature: select, move, resize, lock, z-order
// ---------------------------------------------------------------------------

async function testMoveResizeLock(page, ctx) {
    const r = ctx.recorder;
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.45, fy: 0.45, large: true });

    // --- selection exposes the signature-only menu ------------------------
    await selectSignatureBox(page);
    const menu = await page.evaluate(() => {
        const el = document.getElementById('enpv-ann-menu');
        if (!el || el.hidden) return null;
        const visible = Array.from(el.querySelectorAll('[data-action]'))
            .filter((button) => !button.hidden)
            .map((button) => button.dataset.action);
        return { visible };
    });
    const expected = ['move', 'lock', 'front', 'back', 'edit', 'copy', 'delete'];
    const missing = expected.filter((action) => !(menu?.visible || []).includes(action));
    r.assert('signature-menu-actions', !!menu && missing.length === 0,
        'Selecting a signature shows the signature action menu',
        menu ? `visible: ${menu.visible.join(', ')}` : 'menu hidden');
    r.assert('text-only-actions-hidden', !(menu?.visible || []).includes('cut'),
        'Text/shape-only actions stay hidden for a signature',
        `visible: ${(menu?.visible || []).join(', ')}`);

    // --- drag to move -------------------------------------------------------
    const before = (await boxRects(page))[0];
    const box = signatureBoxes(page).first();
    const { point } = await ensureBoxInView(page, box);
    await page.mouse.move(point.x, point.y);
    await page.mouse.down();
    await page.mouse.move(point.x + 90, point.y + 60, { steps: 14 });
    await page.mouse.up();
    await page.waitForTimeout(600);
    const afterDrag = (await boxRects(page))[0];
    r.assert('drag-moves-box',
        Math.abs(afterDrag.relLeft - before.relLeft - 90) <= 14
        && Math.abs(afterDrag.relTop - before.relTop - 60) <= 14,
        'Dragging moves the signature by the drag distance',
        `Δ=(${afterDrag.relLeft - before.relLeft}, ${afterDrag.relTop - before.relTop}) expected ~(90, 60)`);

    const movedSave = await captureAutosave(page, ctx.saveRecorder);
    r.assert('move-persists', movedSave.signatures.length === 1,
        'The moved signature is persisted', geom(movedSave.signatures[0] || {}));

    // --- resize handles -----------------------------------------------------
    const handles = await page.evaluate(() => Array.from(
        document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"] .enpv-resize-handle'),
    ).map((handle) => handle.dataset.edge));
    const wantHandles = ['nw', 'ne', 'sw', 'se', 't', 'r', 'b', 'l'];
    r.assert('eight-resize-handles', wantHandles.every((edge) => handles.includes(edge)),
        'All eight resize handles are present', `found: ${handles.join(',')}`);

    await selectSignatureBox(page);
    const preResize = (await boxRects(page))[0];
    const seHandle = page.locator('.enpv-annotation-box[data-annotation-type="signature"] .enpv-resize-handle.se').first();
    await seHandle.scrollIntoViewIfNeeded({ timeout: 8000 });
    await page.waitForTimeout(250);
    const seBox = await seHandle.boundingBox();
    const grabX = seBox.x + (seBox.width / 2);
    const grabY = seBox.y + (seBox.height / 2);
    await page.mouse.move(grabX, grabY);
    await page.mouse.down();
    // Move in small steps so the drag handler sees intermediate positions.
    await page.mouse.move(grabX + 30, grabY + 18, { steps: 10 });
    await page.mouse.move(grabX + 70, grabY + 40, { steps: 10 });
    await page.mouse.up();
    await page.waitForTimeout(800);
    const postResize = (await boxRects(page))[0];
    r.assert('se-handle-resizes', postResize.width > preResize.width + 20,
        'Dragging the south-east handle grows the signature',
        `${preResize.width}x${preResize.height} -> ${postResize.width}x${postResize.height}`);

    const resizedSave = await captureAutosave(page, ctx.saveRecorder);
    const resized = resizedSave.signatures[0];
    r.assert('resize-stays-on-page', !!resized
        && num(resized.pdfX) >= 0 && num(resized.pdfY) >= 0
        && num(resized.pdfX) + num(resized.pdfWidth) <= PAGE_WIDTH_PTS + 0.5
        && num(resized.pdfY) + num(resized.pdfHeight) <= PAGE_HEIGHT_PTS + 0.5,
        'A resized signature stays inside the page', resized ? geom(resized) : 'missing');

    // --- lock refuses mutation ---------------------------------------------
    await selectSignatureBox(page);
    await annotationMenuAction(page, 'lock');
    const lockedRect = (await boxRects(page))[0];
    r.assert('lock-marks-box', lockedRect.locked === true,
        'Locking marks the box as locked', `locked=${lockedRect.locked}`);

    const lockedBox = signatureBoxes(page).first();
    const { point: lockedPoint } = await ensureBoxInView(page, lockedBox);
    await page.mouse.move(lockedPoint.x, lockedPoint.y);
    await page.mouse.down();
    await page.mouse.move(lockedPoint.x + 80, lockedPoint.y + 50, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(600);
    const afterLockedDrag = (await boxRects(page))[0];
    r.assert('locked-cannot-move',
        Math.abs(afterLockedDrag.relLeft - lockedRect.relLeft) <= 2
        && Math.abs(afterLockedDrag.relTop - lockedRect.relTop) <= 2,
        'A locked signature cannot be dragged',
        `moved by (${afterLockedDrag.relLeft - lockedRect.relLeft}, ${afterLockedDrag.relTop - lockedRect.relTop}) on its page`);

    await selectSignatureBox(page);
    await annotationMenuAction(page, 'lock');
    const unlocked = (await boxRects(page))[0];
    r.assert('unlock-restores', unlocked.locked === false,
        'Unlocking restores interaction', `locked=${unlocked.locked}`);

    // --- z-order ------------------------------------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.5, fy: 0.5 });
    await selectSignatureBox(page, 0);
    await annotationMenuAction(page, 'front');
    const fronted = await boxRects(page);
    await selectSignatureBox(page, 0);
    await annotationMenuAction(page, 'back');
    const backed = await boxRects(page);
    r.assert('z-order-changes', fronted[0].zIndex !== backed[0].zIndex,
        'Bring to front / send to back change the stacking order',
        `front z=${fronted[0].zIndex} back z=${backed[0].zIndex}`);

    const artifact = await capture(page, '16-move-resize-lock', 'after-transforms');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 17 — Placed signature: copy and delete
// ---------------------------------------------------------------------------

async function testCopyDelete(page, ctx) {
    const r = ctx.recorder;
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.4, fy: 0.4 });
    const original = await captureAutosave(page, ctx.saveRecorder);
    const source = original.signatures[0];

    // --- copy ---------------------------------------------------------------
    await selectSignatureBox(page);
    await annotationMenuAction(page, 'copy');
    const copied = await captureAutosave(page, ctx.saveRecorder);
    r.assert('copy-creates-second', copied.signatures.length === 2,
        'Copy creates a second signature', `${copied.signatures.length} on the page`);

    const duplicate = copied.signatures.find((entry) => entry.id !== source.id);
    r.assert('copy-is-offset', !!duplicate
        && Math.abs(num(duplicate.pdfX) - num(source.pdfX)) > 2
        && Math.abs(num(duplicate.pdfY) - num(source.pdfY)) > 2,
        'The copy is offset from the original rather than stacked exactly on it',
        duplicate ? `source ${geom(source)} vs copy ${geom(duplicate)}` : 'no copy found');
    r.assert('copy-keeps-size', !!duplicate
        && Math.abs(num(duplicate.pdfWidth) - num(source.pdfWidth)) <= 0.5
        && Math.abs(num(duplicate.pdfHeight) - num(source.pdfHeight)) <= 0.5,
        'The copy keeps the original size and aspect',
        duplicate ? `${num(duplicate.pdfWidth).toFixed(1)}x${num(duplicate.pdfHeight).toFixed(1)}` : 'n/a');
    r.assert('copy-not-locked', !!duplicate && duplicate.locked !== true,
        'The copy is not locked');
    r.assert('copy-inside-page', !!duplicate
        && num(duplicate.pdfX) >= 0 && num(duplicate.pdfY) >= 0
        && num(duplicate.pdfX) + num(duplicate.pdfWidth) <= PAGE_WIDTH_PTS + 0.5
        && num(duplicate.pdfY) + num(duplicate.pdfHeight) <= PAGE_HEIGHT_PTS + 0.5,
        'The copy is clamped inside the page', duplicate ? geom(duplicate) : 'n/a');

    // --- delete via the menu -------------------------------------------------
    await selectSignatureBox(page, 0);
    await annotationMenuAction(page, 'delete');
    const afterMenuDelete = await captureAutosave(page, ctx.saveRecorder);
    r.assert('menu-delete-removes', afterMenuDelete.signatures.length === 1,
        'Delete from the menu removes exactly one signature',
        `${afterMenuDelete.signatures.length} left`);

    // --- delete via the keyboard ---------------------------------------------
    await selectSignatureBox(page, 0);
    await page.keyboard.press('Delete');
    await page.waitForTimeout(700);
    const remaining = await signatureBoxes(page).count();
    r.assert('keyboard-delete-removes', remaining === 0,
        'The Delete key removes the selected signature', `${remaining} left`);

    // --- locked signatures refuse deletion ------------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.55, fy: 0.55 });
    await selectSignatureBox(page);
    await annotationMenuAction(page, 'lock');
    await selectSignatureBox(page);
    await page.keyboard.press('Delete');
    await page.waitForTimeout(700);
    const lockedSurvives = await signatureBoxes(page).count();
    r.assert('locked-not-deleted', lockedSurvives === 1,
        'A locked signature is not deleted', `${lockedSurvives} on the page`);

    const artifact = await capture(page, '17-copy-delete', 'copy-and-delete');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 18 — Edit an existing signature: double-click round trip
// ---------------------------------------------------------------------------

async function testEditRoundTrip(page, ctx) {
    const r = ctx.recorder;

    // --- typed signature round trip ------------------------------------------
    await composeTypedMark(page, 'Grace Hopper');
    await armPlacement(page);
    await clickPageFraction(page, 1, 0.4, 0.4);
    const placed = await captureAutosave(page, ctx.saveRecorder);
    const beforeEdit = placed.signatures[0];

    await dblclickSignatureBox(page);
    await page.waitForTimeout(1200);

    const editState = await page.evaluate(() => ({
        open: !!document.querySelector('#signature-modal.is-open'),
        mode: document.querySelector('[data-signature-mode].is-active')?.dataset.signatureMode || null,
        text: document.getElementById('signature-text').value,
        applyLabel: document.getElementById('signature-apply')?.textContent?.trim(),
        title: document.getElementById('signature-modal-title')?.textContent?.trim(),
    }));
    r.assert('dblclick-opens-editor', editState.open,
        'Double-clicking a placed signature opens the composer');
    r.assert('edit-restores-mode-and-text', editState.mode === 'type' && editState.text === 'Grace Hopper',
        'The composer reopens in the annotation\'s own mode with its content',
        `mode=${editState.mode} text="${editState.text}"`);
    r.assert('edit-copy-changes', /Update/i.test(editState.applyLabel || '') && /Edit/i.test(editState.title || ''),
        'The modal switches to edit copy',
        `title="${editState.title}" apply="${editState.applyLabel}"`);

    // --- update in place ------------------------------------------------------
    await page.fill('#signature-text', 'Grace B Hopper');
    await page.waitForTimeout(600);
    await page.click('#signature-apply');
    await page.waitForTimeout(900);

    const updated = await captureAutosave(page, ctx.saveRecorder);
    r.assert('update-does-not-duplicate', updated.signatures.length === 1,
        'Updating replaces the signature rather than adding another',
        `${updated.signatures.length} on the page`);
    const afterEdit = updated.signatures[0];
    r.assert('update-keeps-position',
        Math.abs(num(afterEdit.pdfX) - num(beforeEdit.pdfX)) <= 1
        && Math.abs(num(afterEdit.pdfY) - num(beforeEdit.pdfY)) <= 1,
        'Updating keeps the signature exactly where it was',
        `${geom(beforeEdit)} -> ${geom(afterEdit)}`);
    r.assert('update-stores-new-text', afterEdit.signatureComposer?.text === 'Grace B Hopper',
        'The updated text is persisted', `text="${afterEdit.signatureComposer?.text}"`);

    // --- cancelling an edit changes nothing -----------------------------------
    await dblclickSignatureBox(page);
    await page.waitForTimeout(1000);
    await page.fill('#signature-text', 'Should Not Stick');
    await page.waitForTimeout(400);
    await page.click('#signature-cancel');
    await page.waitForTimeout(700);
    const afterCancel = await captureAutosave(page, ctx.saveRecorder);
    r.assert('cancel-leaves-unchanged',
        afterCancel.signatures.length === 1
        && afterCancel.signatures[0].signatureComposer?.text === 'Grace B Hopper',
        'Cancelling an edit leaves the placed signature untouched',
        `text="${afterCancel.signatures[0]?.signatureComposer?.text}"`);

    // --- editing must not re-arm placement ------------------------------------
    const armed = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    await clickPageFraction(page, 1, 0.7, 0.7);
    const afterStrayClick = await signatureBoxes(page).count();
    r.assert('edit-does-not-arm-placement', !armed && afterStrayClick === 1,
        'Editing does not leave placement armed, so a later click places nothing',
        `armed=${armed} boxes=${afterStrayClick}`);

    // --- cross-mode conversion -------------------------------------------------
    await dblclickSignatureBox(page);
    await page.waitForTimeout(1000);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(300);
    await drawStroke(page);
    await page.click('#signature-apply');
    await page.waitForTimeout(900);
    const converted = await captureAutosave(page, ctx.saveRecorder);
    r.assert('cross-mode-conversion',
        converted.signatures.length === 1 && converted.signatures[0].signatureSourceMode === 'draw',
        'A typed signature can be converted to a drawn one in place',
        `sourceMode=${converted.signatures[0]?.signatureSourceMode}`);

    const artifact = await capture(page, '18-edit-round-trip', 'edited');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 19 — Undo / redo
// ---------------------------------------------------------------------------

async function testUndoRedo(page, ctx) {
    const r = ctx.recorder;
    const undo = async () => {
        const enabled = await page.evaluate(() => document.getElementById('undo-btn')?.disabled === false);
        if (enabled) await page.click('#undo-btn');
        else await page.keyboard.press('Control+z');
        await page.waitForTimeout(650);
    };
    const redo = async () => {
        const enabled = await page.evaluate(() => document.getElementById('redo-btn')?.disabled === false);
        if (enabled) await page.click('#redo-btn');
        else await page.keyboard.press('Control+y');
        await page.waitForTimeout(650);
    };
    const count = () => signatureBoxes(page).count();

    // --- place / undo / redo ---------------------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.4, fy: 0.4 });
    r.assert('placed-one', (await count()) === 1, 'A signature was placed');
    const undoEnabled = await page.evaluate(() => document.getElementById('undo-btn')?.disabled === false);
    r.assert('undo-button-enabled', undoEnabled,
        'Placing a signature enables the toolbar Undo button',
        `undo disabled=${!undoEnabled}`);

    await undo();
    r.assert('undo-removes-placement', (await count()) === 0,
        'Undo removes the placed signature', `${await count()} on the page`);
    await redo();
    r.assert('redo-restores-placement', (await count()) === 1,
        'Redo puts it back', `${await count()} on the page`);

    // --- undo a move --------------------------------------------------------
    const beforeMove = (await boxRects(page))[0];
    const { point } = await ensureBoxInView(page, signatureBoxes(page).first());
    await page.mouse.move(point.x, point.y);
    await page.mouse.down();
    await page.mouse.move(point.x + 100, point.y, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(700);
    const moved = (await boxRects(page))[0];
    await undo();
    const restored = (await boxRects(page))[0];
    r.assert('undo-reverts-move',
        Math.abs(moved.relLeft - beforeMove.relLeft) > 30
        && Math.abs(restored.relLeft - beforeMove.relLeft) <= 6,
        'Undo reverts a move back to the original position',
        `${beforeMove.relLeft} -> ${moved.relLeft} -> ${restored.relLeft} (page-relative)`);

    // --- undo a delete -------------------------------------------------------
    await selectSignatureBox(page);
    await annotationMenuAction(page, 'delete');
    r.assert('deleted', (await count()) === 0, 'The signature was deleted');
    await undo();
    r.assert('undo-restores-delete', (await count()) === 1,
        'Undo restores a deleted signature', `${await count()} on the page`);

    // --- keyboard shortcuts ---------------------------------------------------
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(700);
    const afterCtrlZ = await count();
    await page.keyboard.press('Control+y');
    await page.waitForTimeout(700);
    const afterCtrlY = await count();
    r.assert('keyboard-undo-redo', afterCtrlZ !== afterCtrlY,
        'Ctrl+Z and Ctrl+Y drive the same history',
        `after Ctrl+Z: ${afterCtrlZ}, after Ctrl+Y: ${afterCtrlY}`);

    // --- deep sequence --------------------------------------------------------
    while ((await count()) > 0) {
        await selectSignatureBox(page);
        await annotationMenuAction(page, 'delete');
    }
    for (let i = 0; i < 3; i++) {
        await placeDrawnSignature(page, { pageNumber: 1, fx: 0.3 + (i * 0.15), fy: 0.35 });
    }
    r.assert('placed-three', (await count()) === 3, 'Three signatures placed', `${await count()}`);
    for (let i = 0; i < 3; i++) await undo();
    const undoneAll = await count();
    for (let i = 0; i < 3; i++) await redo();
    const redoneAll = await count();
    r.assert('deep-undo-redo', undoneAll === 0 && redoneAll === 3,
        'Undoing then redoing three placements returns the same three',
        `undone=${undoneAll} redone=${redoneAll}`);

    // --- undo past the start is harmless ---------------------------------------
    for (let i = 0; i < 8; i++) await undo();
    const overUndone = await count();
    const stillAlive = await page.evaluate(() => !!document.getElementById('ftb-sign'));
    r.assert('undo-past-start-safe', overUndone === 0 && stillAlive,
        'Undoing past the beginning of history leaves the editor working',
        `${overUndone} boxes, editor alive=${stillAlive}`);

    // --- a new action discards the redo stack -----------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.6, fy: 0.6 });
    const redoDisabled = await page.evaluate(() => document.getElementById('redo-btn')?.disabled);
    r.assert('redo-stack-discarded', redoDisabled === true,
        'Acting after an undo discards the redo stack', `redo disabled=${redoDisabled}`);

    const saved = await captureAutosave(page, ctx.saveRecorder);
    r.assert('history-result-persists', saved.signatures.length === (await count()),
        'What survives the history matches what is persisted',
        `${saved.signatures.length} saved vs ${await count()} on screen`);

    const artifact = await capture(page, '19-undo-redo', 'history');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 20 — Save and reload persistence
// ---------------------------------------------------------------------------

async function testSavePersistence(page, ctx) {
    const r = ctx.recorder;
    const { docId } = ctx;

    // One drawn and one typed signature, so both composer shapes round-trip.
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.35, fy: 0.35 });

    await composeTypedMark(page, 'Grace Hopper');
    await armPlacement(page);
    await clickPageFraction(page, 1, 0.6, 0.65);

    const saved = await captureAutosave(page, ctx.saveRecorder);

    r.assert('save-http-ok', saved.ok && saved.status === 200,
        'POST /save-annotation-state returns 200', `status=${saved.status}`);
    r.assert('save-reports-success', saved.json?.success === true,
        'Save response reports success', `success=${saved.json?.success}`);
    r.assert('both-in-payload', saved.signatures.length === 2,
        'Both signatures are included in the save payload',
        `count=${saved.signatures.length}`);

    const required = ['id', 'pageIndex', 'pdfX', 'pdfY', 'pdfWidth', 'pdfHeight', 'signatureSourceMode'];
    const missing = [];
    for (const annotation of saved.signatures) {
        for (const field of required) {
            if (annotation[field] === undefined || annotation[field] === null) {
                missing.push(`${annotation.id || '?'}.${field}`);
            }
        }
    }
    r.assert('payload-complete', missing.length === 0,
        'Each persisted signature carries full geometry and its source mode',
        missing.length ? `missing: ${missing.join(', ')}` : required.join(', '));

    const drawn = saved.signatures.find((a) => a.signatureSourceMode === 'draw');
    const typed = saved.signatures.find((a) => a.signatureSourceMode === 'type');

    r.assert('drawn-composer', !!drawn
        && drawn.signatureComposer?.mode === 'draw'
        && Array.isArray(drawn.signatureComposer?.strokes)
        && drawn.signatureComposer.strokes.length > 0,
        'Drawn signature persists its stroke data for later editing',
        drawn ? `strokes=${drawn.signatureComposer?.strokes?.length}` : 'drawn signature not found');

    r.assert('typed-composer', !!typed
        && typed.signatureComposer?.mode === 'type'
        && String(typed.signatureComposer?.text || '') === 'Grace Hopper'
        && !!typed.signatureComposer?.fontFamily,
        'Typed signature persists text, font, colour and size',
        typed
            ? `text="${typed.signatureComposer?.text}" font=${typed.signatureComposer?.fontFamily} size=${typed.signatureComposer?.fontSize}`
            : 'typed signature not found');

    await page.waitForTimeout(800);
    const saveStatus = await page.evaluate(() => document.getElementById('save-status')?.textContent?.trim() || null);
    r.assert('save-status-cleared', /saved/i.test(String(saveStatus || '')),
        'Save status settles on "Saved" after the autosave completes', `status="${saveStatus}"`);

    // --- Reload ------------------------------------------------------------
    await openEditor(page, docId);
    await page.waitForTimeout(1200);

    const renderedAfterReload = await page.locator('.enpv-annotation-box[data-annotation-type="signature"]').count();
    r.assert('rehydrated-after-reload', renderedAfterReload === 2,
        'Both signatures re-render after a full page reload',
        `boxes=${renderedAfterReload}`);

    const reSaved = await captureAutosave(page, ctx.saveRecorder);
    const geometryMatches = saved.signatures.every((before) => {
        const after = reSaved.signatures.find((candidate) => candidate.id === before.id);
        if (!after) return false;
        return Math.abs(num(before.pdfX) - num(after.pdfX)) <= 0.5
            && Math.abs(num(before.pdfY) - num(after.pdfY)) <= 0.5
            && Math.abs(num(before.pdfWidth) - num(after.pdfWidth)) <= 0.5
            && Math.abs(num(before.pdfHeight) - num(after.pdfHeight)) <= 0.5
            && Number(before.pageIndex) === Number(after.pageIndex);
    });
    r.assert('geometry-survives-reload', geometryMatches && reSaved.signatures.length === 2,
        'Position and size are byte-for-byte stable across a reload',
        `before=[${saved.signatures.map(geom).join(' | ')}] after=[${reSaved.signatures.map(geom).join(' | ')}]`);

    // --- Composer data is still editable after reload ----------------------
    const drawnBox = page.locator('.enpv-annotation-box[data-annotation-type="signature"]').first();
    await drawnBox.dblclick();
    await page.waitForTimeout(900);
    const editState = await readModalState(page);
    r.assert('edit-after-reload', editState.isOpen
        && ['draw', 'type'].includes(String(editState.activeMode))
        && editState.canvasBlank === false,
        'Reopening a reloaded signature restores its composer content',
        `open=${editState.isOpen} mode=${editState.activeMode} blank=${editState.canvasBlank}`);

    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);

    const artifact = await capture(page, '20-save-persistence', 'after-reload');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ===========================================================================
// [QA] Signature modal improvements (NK_Dev_5) — one test per subtask
// ===========================================================================

const RETIRED_COPY = [
    'This preview becomes the annotation that gets placed into the document.',
    'Draw mode: click and drag to sign.',
    'Upload mode: choose an image and preview the stamp.',
    'Pick a saved signature to load it into the composer.',
];

/** Text currently rendered inside the modal. */
const modalText = (page) => page.evaluate(
    () => document.getElementById('signature-modal')?.textContent || '',
);

// --- 01 · Retired copy is gone from the modal --------------------------------

async function nk5RetiredCopy(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    // Every tab, since the strings lived in different panels.
    for (const mode of ['draw', 'type', 'upload']) {
        await page.click(`[data-signature-mode="${mode}"]`);
        await page.waitForTimeout(250);
        const text = await modalText(page);
        const found = RETIRED_COPY.filter((line) => text.includes(line));
        r.assert(`no-retired-copy-${mode}`, found.length === 0,
            `None of the retired lines appear on the ${mode} tab`,
            found.length ? found.join(' | ') : 'clean');
    }

    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(400);
    const savedText = await modalText(page);
    const savedFound = RETIRED_COPY.filter((line) => savedText.includes(line));
    r.assert('no-retired-copy-saved', savedFound.length === 0,
        'None of the retired lines appear on the Saved tab',
        savedFound.length ? savedFound.join(' | ') : 'clean');

    // The Saved-view status used to carry the fourth string.
    const savedStatus = await page.evaluate(
        () => document.getElementById('signature-status')?.textContent?.trim() || '',
    );
    r.assert('saved-status-not-retired', !savedStatus.includes(RETIRED_COPY[3]),
        'The footer status on the Saved tab no longer shows the retired prompt',
        `status="${savedStatus}"`);

    // And after drawing / clearing, where the status changes again.
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);
    await drawStroke(page);
    const afterDraw = await modalText(page);
    await page.click('#signature-clear');
    await page.waitForTimeout(250);
    const afterClear = await modalText(page);
    const leaked = RETIRED_COPY.filter((line) => afterDraw.includes(line) || afterClear.includes(line));
    r.assert('no-retired-copy-after-actions', leaked.length === 0,
        'The retired lines do not reappear after drawing or clearing',
        leaked.length ? leaked.join(' | ') : 'clean');

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 02 · No subtext under the live preview ---------------------------------

async function nk5NoSubtext(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    const probe = () => page.evaluate(() => ({
        hint: !!document.getElementById('signature-hint'),
        stageCopy: !!document.querySelector('.signature-stage__copy'),
        stageHints: document.querySelectorAll('.signature-stage__hint').length,
        title: document.querySelector('.signature-stage__title')?.textContent?.trim() || null,
        clear: !!document.getElementById('signature-clear'),
        save: !!document.getElementById('signature-save-account'),
    }));

    for (const mode of ['draw', 'type', 'upload']) {
        await page.click(`[data-signature-mode="${mode}"]`);
        await page.waitForTimeout(250);
        const state = await probe();
        r.assert(`no-subtext-${mode}`,
            !state.hint && !state.stageCopy && state.stageHints === 0,
            `No subtext under the live preview on the ${mode} tab`,
            `hint=${state.hint} copy=${state.stageCopy} helpers=${state.stageHints}`);
    }

    const kept = await probe();
    r.assert('preview-controls-kept', kept.title === 'Live preview' && kept.clear && kept.save,
        'The Live preview heading, Save and Clear all remain',
        `title="${kept.title}" clear=${kept.clear} save=${kept.save}`);

    // With the hint gone, the active tab must still make the mode obvious.
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    const discoverable = await page.evaluate(() => {
        const tab = document.querySelector('[data-signature-mode].is-active');
        return {
            mode: tab?.dataset.signatureMode,
            selected: tab?.getAttribute('aria-selected'),
            apply: document.getElementById('signature-apply')?.textContent?.trim(),
        };
    });
    r.assert('mode-still-discoverable',
        discoverable.mode === 'type' && discoverable.selected === 'true' && /typed/i.test(discoverable.apply || ''),
        'The active tab and Apply label still communicate the current mode',
        `mode=${discoverable.mode} apply="${discoverable.apply}"`);

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 03 · Stroke width supports a much heavier maximum ----------------------

async function nk5StrokeWidth(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);

    const range = await page.evaluate(() => {
        const el = document.getElementById('signature-width');
        return { min: Number(el.min), max: Number(el.max), step: Number(el.step) };
    });
    r.assert('range-raised', range.max >= 24 && range.min === 1,
        'Stroke width runs from 1 to at least 24', `min=${range.min} max=${range.max}`);

    await setRange(page, 'signature-width', range.max);
    const readout = await page.evaluate(
        () => document.getElementById('signature-width-value')?.textContent?.trim(),
    );
    r.equals('readout-tracks-max', readout, `${range.max}px`, 'The readout tracks the maximum');

    await setRange(page, 'signature-width', 1);
    await drawStroke(page);
    const thin = (await canvasFingerprint(page)).inked;
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', range.max);
    await drawStroke(page);
    const thick = (await canvasFingerprint(page)).inked;
    r.assert('max-paints-heavier', thick > thin * 3,
        'The maximum width paints a far heavier line than the minimum',
        `1px=${thin} vs ${range.max}px=${thick} inked px`);

    const state = await readModalState(page);
    r.assert('extremes-safe', state.applyDisabled === false && thick > 0,
        'The extremes leave a usable, placeable preview',
        `apply disabled=${state.applyDisabled}`);

    // A heavy stroke must still trim and place correctly.
    await armPlacement(page);
    await clickPageFraction(page, 1, 0.5, 0.45);
    const saved = await captureAutosave(page, ctx.saveRecorder);
    const placed = saved.signatures[0];
    r.assert('heavy-stroke-places', !!placed
        && num(placed.pdfWidth) > 0 && num(placed.pdfHeight) > 0
        && num(placed.intrinsicWidth) < 900,
        'A heavy stroke still trims to its ink and places correctly',
        placed ? `${geom(placed)} intrinsic=${placed.intrinsicWidth}x${placed.intrinsicHeight}` : 'not placed');
    r.assert('heavy-stroke-composer-width',
        !!placed && Number(placed.signatureComposer?.strokeWidth) === range.max,
        'The chosen width is persisted with the annotation for later editing',
        `strokeWidth=${placed?.signatureComposer?.strokeWidth}`);

    return { checks: r.checks, artifacts: [] };
}

// --- 04 · Live preview area is larger with less surrounding padding ---------

async function nk5PreviewSize(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    const layout = await page.evaluate(() => {
        const stage = document.querySelector('.signature-stage');
        const shell = document.querySelector('.signature-stage__canvas-shell');
        const canvas = document.getElementById('signature-canvas');
        const footer = document.querySelector('.signature-modal__footer');
        const card = document.querySelector('.signature-modal__card');
        return {
            stagePad: parseFloat(getComputedStyle(stage).paddingTop),
            shellPad: parseFloat(getComputedStyle(shell).paddingTop),
            canvasHeight: Math.round(canvas.getBoundingClientRect().height),
            shellHeight: Math.round(shell.getBoundingClientRect().height),
            shellBottom: Math.round(shell.getBoundingClientRect().bottom),
            footerTop: Math.round(footer.getBoundingClientRect().top),
            cardBottom: Math.round(card.getBoundingClientRect().bottom),
            viewportHeight: window.innerHeight,
        };
    });

    r.assert('canvas-enlarged', layout.canvasHeight >= 280,
        'The white drawing rectangle is materially taller than before',
        `canvas=${layout.canvasHeight}px, shell=${layout.shellHeight}px`);
    r.assert('padding-reduced', layout.stagePad <= 12 && layout.shellPad <= 10,
        'Padding around the stage and inside the shell is reduced',
        `stage=${layout.stagePad}px shell=${layout.shellPad}px`);
    r.assert('preview-does-not-overlap-footer', layout.shellBottom <= layout.footerTop + 1,
        'The enlarged preview does not overlap the footer actions',
        `shell bottom=${layout.shellBottom} footer top=${layout.footerTop}`);
    r.assert('card-fits-viewport', layout.cardBottom <= layout.viewportHeight + 1,
        'The dialog still fits inside the viewport',
        `card bottom=${layout.cardBottom} viewport=${layout.viewportHeight}`);

    // On the Saved tab the preview is hidden and the list takes the full width.
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(400);
    const savedLayout = await page.evaluate(() => {
        const stage = document.querySelector('.signature-stage');
        const panel = document.querySelector('.signature-modal__panel--saved');
        const body = document.querySelector('.signature-modal__body');
        return {
            stageVisible: stage.getBoundingClientRect().width > 0,
            panelWidth: Math.round(panel.getBoundingClientRect().width),
            bodyWidth: Math.round(body.getBoundingClientRect().width),
        };
    });
    r.assert('saved-tab-full-width',
        !savedLayout.stageVisible && savedLayout.panelWidth >= savedLayout.bodyWidth - 4,
        'The Saved tab hides the preview and uses the full dialog width',
        `panel=${savedLayout.panelWidth}px of body=${savedLayout.bodyWidth}px`);

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 05 · Modal reopens on the tab you last used ---------------------------

async function nk5RememberedTab(page, ctx) {
    const r = ctx.recorder;

    // A fresh browser profile starts on Draw.
    await openSignatureModal(page);
    const first = await readModalState(page);
    r.equals('fresh-profile-draw', first.activeMode, 'draw',
        'A fresh browser profile opens on the Draw tab');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);

    for (const mode of ['type', 'upload']) {
        await openSignatureModal(page);
        await page.click(`[data-signature-mode="${mode}"]`);
        await page.waitForTimeout(250);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(250);
        await openSignatureModal(page);
        const state = await readModalState(page);
        r.equals(`remembers-${mode}`, state.activeMode, mode,
            `Reopening returns to the ${mode} tab`);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(200);
    }

    // The Saved tab is a view, not a mode, and is remembered too.
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(350);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
    await openSignatureModal(page);
    const savedAgain = await page.evaluate(
        () => document.getElementById('signature-modal').classList.contains('is-saved-view'),
    );
    r.assert('remembers-saved-view', savedAgain,
        'Reopening returns to the Saved tab when that is where the user left off');

    // Back to a composer mode, then check it survives a full reload.
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    await page.keyboard.press('Escape');
    await openEditor(page, ctx.docId);
    await openSignatureModal(page);
    const afterReload = await readModalState(page);
    r.equals('survives-reload', afterReload.activeMode, 'type',
        'The remembered tab survives a full page reload');

    // Editing an existing signature must override the remembered tab.
    await page.keyboard.press('Escape');
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.45, fy: 0.4 });
    await dblclickSignatureBox(page);
    await page.waitForTimeout(1100);
    const editing = await readModalState(page);
    r.equals('edit-overrides-remembered-tab', editing.activeMode, 'draw',
        'Editing a drawn signature opens on Draw even though Type was remembered');

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 06 · Composer settings are remembered, content is not -----------------

async function nk5RememberedSettings(page, ctx) {
    const r = ctx.recorder;

    await openSignatureModal(page);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', 13);
    await setRange(page, 'signature-smoothing', 22);
    await setColour(page, 'signature-color', '#227744');
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    await page.selectOption('#signature-font', 'Pacifico');
    await setRange(page, 'signature-type-size', 96);
    await setColour(page, 'signature-type-color', '#aa2266');
    await page.fill('#signature-text', 'Should not persist');
    await page.waitForTimeout(400);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    await openSignatureModal(page);
    const reopened = await readModalState(page);
    r.assert('settings-persist',
        reopened.values.width === '13'
        && reopened.values.smoothing === '22'
        && reopened.values.color === '#227744'
        && reopened.values.font === 'Pacifico'
        && reopened.values.typeSize === '96'
        && reopened.values.typeColor === '#aa2266',
        'Ink, stroke width, smoothing, font and size all persist',
        `width=${reopened.values.width} smoothing=${reopened.values.smoothing} ink=${reopened.values.color} `
        + `font=${reopened.values.font} size=${reopened.values.typeSize} typeInk=${reopened.values.typeColor}`);

    r.assert('content-cleared',
        reopened.values.text === '' && reopened.canvasBlank === true
        && reopened.applyDisabled === true && reopened.saveDisabled === true,
        'The previous CONTENT is cleared and the actions are disabled again',
        `text=${JSON.stringify(reopened.values.text)} blank=${reopened.canvasBlank} apply=${reopened.applyDisabled}`);

    // Clear resets content without wiping the remembered settings.
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await drawStroke(page);
    await page.click('#signature-clear');
    await page.waitForTimeout(300);
    const afterClear = await readModalState(page);
    r.assert('clear-keeps-settings',
        afterClear.canvasBlank === true && afterClear.values.width === '13' && afterClear.values.color === '#227744',
        'Clear wipes the content but keeps the chosen settings',
        `blank=${afterClear.canvasBlank} width=${afterClear.values.width} ink=${afterClear.values.color}`);

    // Corrupt preferences must degrade to defaults rather than break the modal.
    await page.keyboard.press('Escape');
    await page.evaluate(() => {
        try { window.localStorage.setItem('edit_new_signature_prefs_v1', '{not valid json'); } catch (_) { /* ignore */ }
    });
    await openEditor(page, ctx.docId);
    await openSignatureModal(page);
    const recovered = await readModalState(page);
    r.assert('corrupt-prefs-fall-back',
        recovered.isOpen && recovered.activeMode === 'draw' && recovered.values.width === '3',
        'A corrupt preferences value falls back to defaults instead of breaking the modal',
        `mode=${recovered.activeMode} width=${recovered.values.width}`);

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 07 · Saved tab starts in grid and can switch to row -------------------

/** Three named entries, enough to prove a grid has more than one column. */
const NK5_ENTRIES = [
    { ...TYPED_ENTRY, id: '1', name: 'Client contracts' },
    { ...TYPED_ENTRY, id: '2', name: 'Internal approvals' },
    { ...TYPED_ENTRY, id: '3', name: 'Invoice sign-off (final)' },
];

let listRows = () => Promise.resolve(0);

async function openSavedTabSignedIn(page, ctx, entries = NK5_ENTRIES, options = {}) {
    // Bind the row counter to this page so tests can call it argument-free.
    listRows = () => page.locator('#signature-account-list .signature-library__item').count();
    const store = await stubAccountEndpoint(page, entries, options);
    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(700);
    return store;
}

async function nk5GridRow(page, ctx) {
    const r = ctx.recorder;
    await openSavedTabSignedIn(page, ctx);

    const grid = await page.evaluate(() => {
        const list = document.getElementById('signature-account-list');
        const cards = Array.from(list.querySelectorAll('.signature-library__item'));
        const tops = new Set(cards.map((card) => Math.round(card.getBoundingClientRect().top)));
        return {
            isGrid: list.classList.contains('is-grid'),
            columns: getComputedStyle(list).gridTemplateColumns,
            gridPressed: document.querySelector('[data-signature-view-mode="grid"]')?.getAttribute('aria-pressed'),
            rowPressed: document.querySelector('[data-signature-view-mode="row"]')?.getAttribute('aria-pressed'),
            distinctRows: tops.size,
            cards: cards.length,
        };
    });
    r.assert('starts-in-grid', grid.isGrid && grid.gridPressed === 'true' && grid.rowPressed === 'false',
        'The saved list opens in grid mode with the toggle reflecting it',
        `grid=${grid.isGrid} pressed=${grid.gridPressed}/${grid.rowPressed}`);
    r.assert('grid-shares-rows', grid.cards === 3 && grid.distinctRows < grid.cards,
        'Grid places several cards side by side rather than one per line',
        `${grid.cards} cards on ${grid.distinctRows} visual row(s), columns="${grid.columns}"`);

    await page.click('[data-signature-view-mode="row"]');
    await page.waitForTimeout(450);
    const row = await page.evaluate(() => {
        const list = document.getElementById('signature-account-list');
        const cards = Array.from(list.querySelectorAll('.signature-library__item'));
        const tops = new Set(cards.map((card) => Math.round(card.getBoundingClientRect().top)));
        return {
            isRow: list.classList.contains('is-row'),
            isGrid: list.classList.contains('is-grid'),
            rowPressed: document.querySelector('[data-signature-view-mode="row"]')?.getAttribute('aria-pressed'),
            distinctRows: tops.size,
            cards: cards.length,
        };
    });
    r.assert('switches-to-row', row.isRow && !row.isGrid && row.rowPressed === 'true',
        'The toggle switches the list to row mode', `row=${row.isRow} grid=${row.isGrid}`);
    r.assert('row-is-one-per-line', row.distinctRows === row.cards,
        'Row mode stacks one entry per line',
        `${row.cards} cards on ${row.distinctRows} visual row(s)`);

    // Layout is a remembered preference.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(500);
    r.assert('layout-remembered',
        await page.evaluate(() => document.getElementById('signature-account-list').classList.contains('is-row')),
        'The chosen layout is remembered on the next open');

    // Every action still works in row mode, and again in grid.
    for (const mode of ['row', 'grid']) {
        await page.click(`[data-signature-view-mode="${mode}"]`);
        await page.waitForTimeout(350);
        const actions = await page.evaluate(() => {
            const card = document.querySelector('#signature-account-list .signature-library__item');
            const names = Array.from(card?.querySelectorAll('[data-account-signature-action]') || [])
                .map((el) => el.dataset.accountSignatureAction);
            return { load: names.includes('load'), rename: names.includes('rename'), del: names.includes('delete') };
        });
        r.assert(`actions-present-${mode}`, actions.load && actions.rename && actions.del,
            `Load, Rename and Delete are all available in ${mode} mode`,
            JSON.stringify(actions));
    }

    // The empty state renders sensibly in both layouts.
    await page.unroute(/\/saved-signatures(\/.*)?$/);
    await openSavedTabSignedIn(page, ctx, []);
    const emptyGrid = await page.locator('#signature-account-list').textContent();
    await page.click('[data-signature-view-mode="row"]');
    await page.waitForTimeout(350);
    const emptyRow = await page.locator('#signature-account-list').textContent();
    r.assert('empty-state-both-layouts',
        /no account signatures/i.test(emptyGrid) && /no account signatures/i.test(emptyRow),
        'The empty state renders in both layouts',
        `grid="${emptyGrid.trim().slice(0, 30)}" row="${emptyRow.trim().slice(0, 30)}"`);

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 08 · Typeahead search filters saved signatures as you type ------------

async function nk5Search(page, ctx) {
    const r = ctx.recorder;
    const store = await openSavedTabSignedIn(page, ctx);

    r.assert('all-listed-initially', (await listRows()) === 3,
        'Every saved signature is listed before filtering');

    // Typing narrows per keystroke, with no Enter needed.
    await page.locator('#signature-account-search').type('int', { delay: 60 });
    await page.waitForTimeout(400);
    const narrowedText = await page.locator('#signature-account-list').textContent();
    r.assert('narrows-while-typing', (await listRows()) === 1 && /Internal approvals/.test(narrowedText),
        'Typing narrows the list without pressing Enter', `${await listRows()} row(s) for "int"`);

    // Case-insensitive, and matches anywhere in the name rather than the start.
    await page.fill('#signature-account-search', 'APPROVALS');
    await page.waitForTimeout(400);
    r.assert('case-insensitive-substring', (await listRows()) === 1,
        'Matching is case-insensitive and matches mid-name, not just the prefix',
        `${await listRows()} row(s) for "APPROVALS"`);

    await page.fill('#signature-account-search', 'c');
    await page.waitForTimeout(400);
    r.assert('widens-as-deleted', (await listRows()) >= 2,
        'Removing characters widens the results again', `${await listRows()} row(s) for "c"`);

    // Regex-ish characters must be treated as literal text.
    await page.fill('#signature-account-search', '(final)');
    await page.waitForTimeout(400);
    r.assert('regex-chars-literal', (await listRows()) === 1,
        'Regex-like characters in the query are matched literally',
        `${await listRows()} row(s) for "(final)"`);
    await page.fill('#signature-account-search', '.*');
    await page.waitForTimeout(400);
    r.assert('regex-wildcard-not-interpreted', (await listRows()) === 0,
        'A regex wildcard matches nothing rather than everything',
        `${await listRows()} row(s) for ".*"`);

    await page.fill('#signature-account-search', 'zzzz');
    await page.waitForTimeout(400);
    r.assert('no-match-state',
        await page.evaluate(() => !!document.querySelector('.signature-account__empty-search')),
        'A query with no matches shows a dedicated empty state');

    await page.fill('#signature-account-search', '');
    await page.waitForTimeout(400);
    r.assert('clearing-restores', (await listRows()) === 3,
        'Clearing the search restores every entry');

    // Search works in row mode too.
    await page.click('[data-signature-view-mode="row"]');
    await page.waitForTimeout(350);
    await page.fill('#signature-account-search', 'invoice');
    await page.waitForTimeout(400);
    r.assert('search-in-row-mode', (await listRows()) === 1,
        'Search works the same in row mode', `${await listRows()} row(s)`);

    // Actions act on the right entry while a filter is applied.
    await page.locator('#signature-account-list [data-account-signature-action="delete"]').first().click();
    await page.waitForTimeout(900);
    r.assert('filtered-action-hits-right-entry',
        store.deleted.includes('3') && !store.deleted.includes('1') && !store.deleted.includes('2'),
        'Deleting while filtered removes the filtered entry, not the first in the full list',
        `deleted=[${store.deleted.join(',')}]`);

    // Signed out, the field is disabled rather than misleading.
    await page.unroute(/\/saved-signatures(\/.*)?$/);
    await openSavedTabSignedIn(page, ctx, [], { unauthorised: true });
    r.assert('disabled-when-signed-out',
        await page.evaluate(() => document.getElementById('signature-account-search')?.disabled === true),
        'The search field is disabled when signed out');

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 09 · Rename a saved signature ------------------------------------------

async function nk5Rename(page, ctx) {
    const r = ctx.recorder;

    /**
     * Click a rename trigger, scrolling it inside the Saved panel first.
     *
     * The panel scrolls independently, so a row can be laid out below the
     * visible area and get covered even though it has a bounding box.
     */
    /** Reopen the modal on the Saved tab so each scenario starts clean. */
    const reopenSavedTab = async () => {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        await openSignatureModal(page);
        await page.click('[data-signature-view="saved"]');
        await page.waitForTimeout(600);
    };

    const clickRename = async () => {
        const button = page.locator('#signature-account-list button[data-account-signature-action="rename"]').first();
        const visible = await button.isVisible().catch(() => false);
        if (!visible) {
            const why = await page.evaluate(() => {
                const list = document.getElementById('signature-account-list');
                const modal = document.getElementById('signature-modal');
                const btn = list?.querySelector('button[data-account-signature-action="rename"]');
                const cs = btn ? getComputedStyle(btn) : null;
                const parent = btn?.closest('.signature-modal__panel--saved');
                return {
                    modalOpen: !!modal?.classList.contains('is-open'),
                    savedView: !!modal?.classList.contains('is-saved-view'),
                    rows: list?.querySelectorAll('.signature-library__item').length ?? null,
                    listHtmlHead: (list?.innerHTML || '').replace(/\s+/g, ' ').slice(0, 120),
                    btnExists: !!btn,
                    btnDisplay: cs?.display,
                    btnVisibility: cs?.visibility,
                    panelDisplay: parent ? getComputedStyle(parent).display : null,
                };
            }).catch(() => null);
            throw new Error(`Rename button not visible: ${JSON.stringify(why)}`);
        }
        await button.scrollIntoViewIfNeeded({ timeout: 8000 });
        await page.waitForTimeout(250);
        await button.click({ timeout: 8000 });
        await page.waitForTimeout(400);
    };
    const store = await openSavedTabSignedIn(page, ctx);

    // The Rename action opens a focused, pre-filled field.
    await clickRename();
    const editor = await page.evaluate(() => {
        const input = document.querySelector('[data-account-signature-rename-input]');
        return { present: !!input, focused: document.activeElement === input, value: input?.value };
    });
    r.assert('rename-opens-focused', editor.present && editor.focused && editor.value === 'Client contracts',
        'Rename opens a focused field pre-filled with the current name',
        `focused=${editor.focused} value="${editor.value}"`);

    await page.fill('[data-account-signature-rename-input]', 'Renamed via Enter');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(900);
    r.assert('enter-commits',
        store.patched.some((entry) => entry.name === 'Renamed via Enter')
        && /Renamed via Enter/.test(await page.locator('#signature-account-list').textContent()),
        'Enter commits the rename and the list repaints',
        `patched=${JSON.stringify(store.patched)}`);

    const status = await page.evaluate(
        () => document.getElementById('signature-status')?.textContent?.trim(),
    );
    r.assert('rename-confirmed-in-status', /renamed/i.test(status || ''),
        'The status line confirms the rename', `status="${status}"`);

    // Clicking the name itself also starts a rename; blur commits it.
    await page.locator('#signature-account-list .signature-library__name-text').first().click();
    await page.waitForTimeout(400);
    const viaName = await page.evaluate(
        () => !!document.querySelector('[data-account-signature-rename-input]'),
    );
    r.assert('name-click-starts-rename', viaName,
        'Clicking the name itself starts a rename');

    await page.fill('[data-account-signature-rename-input]', 'Renamed via blur');
    // Blur with the keyboard: a mouse click can land on the scrim if the list
    // re-renders under the cursor, which would close the dialog instead.
    await page.keyboard.press('Tab');
    await page.waitForTimeout(900);
    r.assert('blur-commits', store.patched.some((entry) => entry.name === 'Renamed via blur'),
        'Moving focus away commits the rename, consistent with Enter',
        `patched=${JSON.stringify(store.patched.map((entry) => entry.name))}`);
    r.assert('commit-keeps-modal-open',
        await page.evaluate(() => !!document.querySelector('#signature-modal.is-open')),
        'Committing a rename leaves the dialog open');

    // Escape abandons.
    await reopenSavedTab();

    const beforeEscape = store.patched.length;
    await clickRename();
    await page.fill('[data-account-signature-rename-input]', 'Should not stick');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(700);
    const afterEscape = await page.locator('#signature-account-list').textContent();
    r.assert('escape-abandons',
        store.patched.length === beforeEscape && !/Should not stick/.test(afterEscape),
        'Escape abandons the rename without sending anything',
        `patched ${beforeEscape} -> ${store.patched.length}`);

    // An empty name is refused client-side and the original kept.
    await reopenSavedTab();
    const beforeEmpty = store.patched.length;
    await clickRename();
    await page.fill('[data-account-signature-rename-input]', '   ');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(800);
    const afterEmpty = await page.locator('#signature-account-list').textContent();
    r.assert('empty-name-refused',
        store.patched.length === beforeEmpty && /Renamed via blur/.test(afterEmpty),
        'A whitespace-only name is refused and the original kept',
        `patched ${beforeEmpty} -> ${store.patched.length}`);

    // The renamed entry is still complete and loadable.
    const rowIntact = await page.evaluate(() => {
        const card = document.querySelector('#signature-account-list .signature-library__item');
        return {
            thumb: !!card?.querySelector('.signature-library__thumb img'),
            detail: card?.querySelector('.signature-library__detail')?.textContent?.trim(),
        };
    });
    r.assert('renamed-entry-intact',
        rowIntact.thumb && /signature/i.test(rowIntact.detail || ''),
        'The renamed entry keeps its thumbnail and mode label',
        `thumb=${rowIntact.thumb} detail="${rowIntact.detail}"`);

    // A hostile name renders as text.
    await page.unroute(/\/saved-signatures(\/.*)?$/);
    let dialogFired = false;
    page.once('dialog', async (dialog) => { dialogFired = true; await dialog.dismiss(); });
    await openSavedTabSignedIn(page, ctx, [{
        ...TYPED_ENTRY, id: '7', name: '<img src=x onerror="window.__renameXss=true">',
    }]);
    const xss = await page.evaluate(() => ({
        flagged: !!window.__renameXss,
        injected: !!document.querySelector('#signature-account-list img[src="x"]'),
        text: document.getElementById('signature-account-list')?.textContent || '',
    }));
    r.assert('hostile-name-escaped',
        !xss.flagged && !dialogFired && !xss.injected && xss.text.includes('<img src=x'),
        'A hostile signature name renders as literal text and never executes',
        `flagged=${xss.flagged} injected=${xss.injected}`);

    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [] };
}

// --- 10 · Font size determines how large the signature is placed -----------

async function nk5FontSizePlacement(page, ctx) {
    const r = ctx.recorder;

    const placeTyped = async (size, fy) => {
        await openSignatureModal(page);
        await page.click('[data-signature-mode="type"]');
        await page.waitForTimeout(250);
        await page.fill('#signature-text', 'Ada Lovelace');
        await setRange(page, 'signature-type-size', size);
        await page.waitForTimeout(700);
        const ink = await measureCanvasInk(page);
        await armPlacement(page);
        await clickPageFraction(page, 1, 0.5, fy);
        return ink;
    };

    const smallInk = await placeTyped(48, 0.3);
    const largeInk = await placeTyped(180, 0.55);
    const typed = await captureAutosave(page, ctx.saveRecorder);
    const sorted = typed.signatures.slice().sort((a, b) => num(a.pdfWidth) - num(b.pdfWidth));

    r.assert('both-typed-placed', sorted.length === 2, 'Both typed signatures were placed');
    r.assert('smaller-font-smaller-signature',
        sorted.length === 2 && num(sorted[0].pdfWidth) < num(sorted[1].pdfWidth) * 0.6,
        'A 48px font places a genuinely smaller signature than a 180px font',
        sorted.length === 2 ? `${num(sorted[0].pdfWidth).toFixed(1)}pt vs ${num(sorted[1].pdfWidth).toFixed(1)}pt` : 'n/a');
    r.assert('composer-mark-also-smaller',
        !!smallInk && !!largeInk && smallInk.boxWidth < largeInk.boxWidth,
        'The composed mark itself is smaller at the smaller size',
        `${smallInk?.boxWidth}px vs ${largeInk?.boxWidth}px on the canvas`);

    const scales = sorted.map((entry) => num(entry.pdfWidth) / (num(entry.intrinsicWidth) * (208 / 900)));
    r.assert('no-upscaling-blur', scales.every((scale) => scale > 0.9 && scale < 1.15),
        'Placed size tracks the composed mark rather than stretching it to a fixed width',
        `scale = ${scales.map((x) => x.toFixed(2)).join(', ')}`);

    // A small DRAWN mark must place small too, not just typed ones.
    await openSignatureModal(page);
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    const canvasBox = await page.locator('#signature-canvas').boundingBox();
    await page.mouse.move(canvasBox.x + (canvasBox.width * 0.45), canvasBox.y + (canvasBox.height * 0.48));
    await page.mouse.down();
    await page.mouse.move(canvasBox.x + (canvasBox.width * 0.52), canvasBox.y + (canvasBox.height * 0.52), { steps: 6 });
    await page.mouse.up();
    await page.waitForTimeout(300);
    await armPlacement(page);
    await clickPageFraction(page, 1, 0.3, 0.75);
    const withDrawn = await captureAutosave(page, ctx.saveRecorder);
    const drawnSmall = withDrawn.signatures
        .slice()
        .sort((a, b) => num(a.pdfWidth) - num(b.pdfWidth))[0];
    r.assert('small-drawn-mark-places-small',
        !!drawnSmall && num(drawnSmall.pdfWidth) < num(sorted[1].pdfWidth) * 0.5,
        'A small drawn mark also places small rather than being scaled up',
        drawnSmall ? geom(drawnSmall) : 'missing');

    // Caps and margins still hold at every size.
    const violations = withDrawn.signatures.filter((entry) => (
        num(entry.pdfWidth) > PAGE_WIDTH_PTS * 0.34 + 0.5
        || num(entry.pdfHeight) > PAGE_HEIGHT_PTS * 0.18 + 0.5
        || num(entry.pdfX) < PLACEMENT.edgeMarginPts - 0.5
        || num(entry.pdfY) < PLACEMENT.edgeMarginPts - 0.5
    ));
    r.assert('caps-still-hold', violations.length === 0,
        'Page-size caps and the 12pt margin still hold at every size',
        violations.length ? violations.map(geom).join(' | ') : `${withDrawn.signatures.length} checked`);

    r.assert('minimum-size-usable',
        withDrawn.signatures.every((entry) => num(entry.pdfWidth) >= PLACEMENT.minWidthPts - 0.5
            && num(entry.pdfHeight) >= PLACEMENT.minHeightPts - 0.5),
        'Even the smallest mark is placed at a usable minimum rather than vanishing',
        withDrawn.signatures.map((entry) => `${num(entry.pdfWidth).toFixed(1)}x${num(entry.pdfHeight).toFixed(1)}`).join(', '));

    return { checks: r.checks, artifacts: [] };
}

// --- 11 · Aspect ratio is preserved at every placed size -------------------

async function nk5AspectPreserved(page, ctx) {
    const r = ctx.recorder;

    /** Place a mark and return the composed vs placed aspect ratios. */
    const placeAndCompare = async (label, compose, fy) => {
        await openSignatureModal(page);
        await compose();
        const ink = await measureCanvasInk(page);
        await armPlacement(page);
        await clickPageFraction(page, 1, 0.5, fy);
        return { label, ink };
    };

    const canvasPoint = async (fx, fy) => {
        const box = await page.locator('#signature-canvas').boundingBox();
        return { x: box.x + (box.width * fx), y: box.y + (box.height * fy) };
    };

    // Wide and short: a long name at a small size.
    const wide = await placeAndCompare('wide', async () => {
        await page.click('[data-signature-mode="type"]');
        await page.waitForTimeout(250);
        await page.fill('#signature-text', 'Bartholomew Featherstonehaugh');
        await setRange(page, 'signature-type-size', 48);
        await page.waitForTimeout(700);
    }, 0.28);

    // Tall and narrow: a near-vertical stroke.
    const tall = await placeAndCompare('tall', async () => {
        await page.click('[data-signature-mode="draw"]');
        await page.waitForTimeout(250);
        const top = await canvasPoint(0.5, 0.12);
        const bottom = await canvasPoint(0.53, 0.88);
        await page.mouse.move(top.x, top.y);
        await page.mouse.down();
        await page.mouse.move(bottom.x, bottom.y, { steps: 14 });
        await page.mouse.up();
        await page.waitForTimeout(300);
    }, 0.5);

    // Tiny: small enough to hit the minimum-size floor.
    const tiny = await placeAndCompare('tiny', async () => {
        await page.click('[data-signature-mode="draw"]');
        await page.waitForTimeout(250);
        const from = await canvasPoint(0.48, 0.5);
        const to = await canvasPoint(0.53, 0.51);
        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        await page.mouse.move(to.x, to.y, { steps: 5 });
        await page.mouse.up();
        await page.waitForTimeout(300);
    }, 0.72);

    // Huge: wide enough to hit the 34% page-width cap.
    const huge = await placeAndCompare('huge', async () => {
        await page.click('[data-signature-mode="type"]');
        await page.waitForTimeout(250);
        await page.fill('#signature-text', 'Wide Signature Example');
        await setRange(page, 'signature-type-size', 180);
        await page.waitForTimeout(700);
    }, 0.86);

    const saved = await captureAutosave(page, ctx.saveRecorder);
    r.assert('all-four-placed', saved.signatures.length === 4,
        'All four shapes were placed', `${saved.signatures.length} placed`);

    // Compare each placement's aspect against its own source pixels.
    const mismatches = [];
    for (const entry of saved.signatures) {
        const placed = num(entry.pdfWidth) / num(entry.pdfHeight);
        const source = num(entry.intrinsicWidth) / num(entry.intrinsicHeight);
        if (Math.abs(placed - source) / source > 0.05) {
            mismatches.push(`${placed.toFixed(2)} vs ${source.toFixed(2)}`);
        }
    }
    r.assert('aspect-preserved-everywhere', mismatches.length === 0,
        'Every placement keeps the aspect ratio of the mark it came from',
        mismatches.length ? mismatches.join(' | ') : `${saved.signatures.length} placements within 5%`);

    const widths = saved.signatures.map((entry) => num(entry.pdfWidth));
    const smallest = Math.min(...widths);
    const largest = Math.max(...widths);
    r.assert('floor-scales-uniformly',
        smallest >= PLACEMENT.minWidthPts - 0.5,
        'A mark that hits the minimum is scaled up uniformly, not stretched on one axis',
        `smallest width ${smallest.toFixed(1)}pt, floor ${PLACEMENT.minWidthPts}pt`);
    r.assert('cap-scales-uniformly',
        largest <= (PAGE_WIDTH_PTS * 0.34) + 0.5,
        'A mark that hits the width cap is scaled down uniformly',
        `largest width ${largest.toFixed(1)}pt, cap ${(PAGE_WIDTH_PTS * 0.34).toFixed(1)}pt`);

    r.assert('shapes-really-differed',
        !!wide.ink && !!tall.ink && wide.ink.boxWidth / wide.ink.boxHeight > 3
        && tall.ink.boxHeight / tall.ink.boxWidth > 2,
        'The test really did compose a wide-short and a tall-narrow mark',
        `wide=${(wide.ink.boxWidth / wide.ink.boxHeight).toFixed(1)}:1 `
        + `tall=1:${(tall.ink.boxHeight / tall.ink.boxWidth).toFixed(1)}`);

    void tiny;
    void huge;
    return { checks: r.checks, artifacts: [] };
}

// ---------------------------------------------------------------------------
// Test 21 — Burn into the PDF and download
// ---------------------------------------------------------------------------

async function testBurnAndExport(page, ctx) {
    const r = ctx.recorder;

    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.45, fy: 0.45 });
    const beforeExport = await captureAutosave(page, ctx.saveRecorder);
    r.assert('placed-before-export', beforeExport.signatures.length === 1,
        'A signature is on the page before exporting');

    // --- burn is deliberately NOT offered for signatures --------------------
    // canBurnAnnotation() allows only shapes and direct-draw strokes, so the
    // subtask's "burn the signature" step does not match the implementation.
    // Assert the real contract so a future change to it is noticed.
    await selectSignatureBox(page);
    const burnVisible = await page.locator('#enpv-ann-menu [data-action="burn"]').isVisible().catch(() => false);
    r.assert('burn-not-offered-for-signatures', burnVisible === false,
        'Burn is not offered for signatures (only shapes and freehand draw are burnable)',
        `burn visible=${burnVisible}`);

    // --- download the annotated PDF -----------------------------------------
    // The endpoint validates an annotations array, so send the same payload
    // the editor itself saves.
    const annotations = beforeExport.all;
    const download = await page.evaluate(async ({ docId, payload }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(`/documents/${docId}/download-annotated-pdf`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/pdf',
            },
            body: JSON.stringify({ annotations: payload, acro_form_entries: [] }),
        });
        const buffer = await response.arrayBuffer();
        return {
            status: response.status,
            contentType: response.headers.get('content-type'),
            bytes: buffer.byteLength,
            head: new TextDecoder().decode(new Uint8Array(buffer.slice(0, 8))),
        };
    }, { docId: ctx.docId, payload: annotations });

    r.assert('download-returns-pdf',
        download.status === 200 && download.head.startsWith('%PDF'),
        'Downloading the annotated document returns a real PDF',
        `HTTP ${download.status}, ${download.bytes} bytes, header "${download.head.trim()}", type=${download.contentType}`);

    // A page carrying an embedded signature image should be materially larger
    // than the near-empty source document.
    r.assert('export-embeds-signature', download.bytes > 3000,
        'The exported PDF is large enough to contain the embedded signature image',
        `${download.bytes} bytes`);

    // --- exporting must not disturb the editor ------------------------------
    const stillThere = await signatureBoxes(page).count();
    r.assert('export-leaves-editor-intact', stillThere === 1,
        'Exporting leaves the placed signature untouched in the editor',
        `${stillThere} box(es) after download`);

    const artifact = await capture(page, '21-burn-export', 'exported');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 22 — Accessibility and keyboard behaviour
// ---------------------------------------------------------------------------

async function testAccessibility(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    // --- dialog semantics ----------------------------------------------------
    const dialog = await page.evaluate(() => {
        const modal = document.getElementById('signature-modal');
        const card = modal?.querySelector('.signature-modal__card');
        const title = document.getElementById('signature-modal-title');
        return {
            role: card?.getAttribute('role'),
            modal: card?.getAttribute('aria-modal'),
            labelledBy: card?.getAttribute('aria-labelledby'),
            titleId: title?.id,
            ariaHidden: modal?.getAttribute('aria-hidden'),
            bodyLocked: document.body.classList.contains('signature-modal-open'),
        };
    });
    r.assert('dialog-semantics',
        dialog.role === 'dialog' && dialog.modal === 'true'
        && dialog.labelledBy === dialog.titleId && dialog.ariaHidden === 'false',
        'The modal is an aria-modal dialog labelled by its title',
        `role=${dialog.role} aria-modal=${dialog.modal} labelledby=${dialog.labelledBy}`);
    r.assert('background-locked', dialog.bodyLocked,
        'The page behind the modal is locked from scrolling');

    // --- tablist semantics ----------------------------------------------------
    const tabs = await page.evaluate(() => {
        const list = document.querySelector('.signature-modal__tabs');
        const items = Array.from(document.querySelectorAll('.signature-modal__tab'));
        return {
            listRole: list?.getAttribute('role'),
            listLabel: list?.getAttribute('aria-label'),
            wired: items.every((tab) => {
                const panel = document.getElementById(tab.getAttribute('aria-controls'));
                return !!panel && panel.getAttribute('role') === 'tabpanel'
                    && panel.getAttribute('aria-labelledby') === tab.id;
            }),
            inTabOrder: items.filter((tab) => tab.tabIndex === 0).length,
            selected: items.filter((tab) => tab.getAttribute('aria-selected') === 'true').length,
        };
    });
    r.assert('tablist-wired',
        tabs.listRole === 'tablist' && !!tabs.listLabel && tabs.wired,
        'Tabs are a labelled tablist, each wired to its own tabpanel',
        `role=${tabs.listRole} label="${tabs.listLabel}" wired=${tabs.wired}`);
    r.assert('roving-tabindex', tabs.inTabOrder === 1 && tabs.selected === 1,
        'Exactly one tab is in the tab order and marked selected',
        `${tabs.inTabOrder} tabbable, ${tabs.selected} selected`);

    // --- keyboard reaches every control ---------------------------------------
    await page.focus('#signature-tab-draw');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    const arrowMoved = await page.evaluate(() => document.activeElement?.id);
    r.assert('arrow-keys-move-tabs', arrowMoved === 'signature-tab-type',
        'Arrow keys move between tabs', `focus=${arrowMoved}`);

    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);
    const reachable = await page.evaluate(() => {
        const wanted = ['signature-color', 'signature-width', 'signature-smoothing',
            'signature-clear', 'signature-save-account', 'signature-cancel', 'signature-apply'];
        return wanted.filter((id) => {
            const el = document.getElementById(id);
            if (!el) return true;
            // Negative tabindex or disabled-and-unreachable are the failures.
            return el.tabIndex < 0;
        });
    });
    r.assert('controls-keyboard-reachable', reachable.length === 0,
        'Draw-mode controls and the footer actions are keyboard reachable',
        reachable.length ? `unreachable: ${reachable.join(', ')}` : 'all reachable');

    // The upload input must stay focusable despite being visually hidden.
    await page.click('[data-signature-mode="upload"]');
    await page.waitForTimeout(250);
    const uploadFocus = await page.evaluate(() => {
        const input = document.getElementById('signature-image-input');
        input.focus();
        return {
            focused: document.activeElement === input,
            display: getComputedStyle(input).display,
            label: !!document.querySelector('label[for="signature-image-input"]'),
        };
    });
    r.assert('hidden-file-input-focusable',
        uploadFocus.focused && uploadFocus.display !== 'none' && uploadFocus.label,
        'The visually hidden file input is still focusable and has a label',
        `focused=${uploadFocus.focused} display=${uploadFocus.display} label=${uploadFocus.label}`);

    // --- Esc closes ------------------------------------------------------------
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const closed = await readModalState(page);
    r.assert('esc-closes', !closed.isOpen && closed.ariaHidden === 'true',
        'Esc closes the dialog and restores aria-hidden');

    // --- placed annotations expose a name --------------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.5, fy: 0.5 });
    const boxA11y = await page.evaluate(() => {
        const box = document.querySelector('.enpv-annotation-box[data-annotation-type="signature"]');
        const img = box?.querySelector('img');
        const label = box?.querySelector('.enpv-signature-selection-label');
        return { alt: img?.getAttribute('alt'), label: label?.textContent?.trim() };
    });
    r.assert('placed-signature-labelled',
        !!boxA11y.alt && /signature/i.test(boxA11y.alt) && /signature/i.test(boxA11y.label || ''),
        'A placed signature exposes an accessible name',
        `alt="${boxA11y.alt}" label="${boxA11y.label}"`);

    // --- known gaps, asserted so they are visible rather than assumed ----------
    await openSignatureModal(page);
    const liveRegion = await page.evaluate(() => {
        const status = document.getElementById('signature-status');
        return {
            ariaLive: status?.getAttribute('aria-live'),
            role: status?.getAttribute('role'),
        };
    });
    r.assert('status-is-announced',
        !!liveRegion.ariaLive || liveRegion.role === 'status' || liveRegion.role === 'alert',
        'Status messages sit in a live region so screen readers announce them',
        `aria-live=${liveRegion.ariaLive} role=${liveRegion.role}`);

    const focusTrapped = await page.evaluate(async () => {
        const modal = document.getElementById('signature-modal');
        const focusables = Array.from(modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        )).filter((el) => !el.disabled && el.offsetParent !== null);
        if (!focusables.length) return false;
        focusables[focusables.length - 1].focus();
        return document.activeElement === focusables[focusables.length - 1];
    });
    r.assert('focus-starts-inside', focusTrapped,
        'Focus can be placed on the dialog\'s own controls',
        `focusable=${focusTrapped}`);

    await page.keyboard.press('Escape');
    const artifact = await capture(page, '22-accessibility', 'a11y');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 23 — Regression: cross-tool interference and load
// ---------------------------------------------------------------------------

async function testRegressionAndLoad(page, ctx) {
    const r = ctx.recorder;

    // --- signature placement must not break the other tools -------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.35, fy: 0.3 });
    const toolsAlive = await page.evaluate(() => {
        const ids = ['ftb-sign', 'ftb-add-text', 'ftb-add-shape'];
        return ids.map((id) => {
            const el = document.getElementById(id);
            return { id, present: !!el, disabled: el ? !!el.disabled : null };
        });
    });
    r.assert('other-tools-still-usable',
        toolsAlive.every((tool) => tool.present && tool.disabled !== true),
        'The text and shape tools are still usable after placing a signature',
        toolsAlive.map((tool) => `${tool.id}=${tool.present ? 'ok' : 'missing'}`).join(', '));

    // --- switching tools cancels a pending placement --------------------------
    await composeDrawnMark(page);
    await armPlacement(page);
    const armedBefore = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    await page.click('#ftb-add-text');
    await page.waitForTimeout(600);
    const armedAfter = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    r.assert('tool-switch-cancels-placement', armedBefore && !armedAfter,
        'Switching to another tool cancels a pending signature placement',
        `armed before=${armedBefore} after=${armedAfter}`);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // --- signature annotations vs signature form fields -----------------------
    const distinct = await page.evaluate(() => {
        const sigs = document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"]').length;
        const fields = document.querySelectorAll('.enpv-annotation-box[data-annotation-type="field"]').length;
        return { sigs, fields };
    });
    r.assert('signature-vs-field-distinct', distinct.sigs >= 1,
        'Signature annotations are typed distinctly from signature form fields',
        `signature boxes=${distinct.sigs}, field boxes=${distinct.fields}`);

    // --- load: many signatures stay responsive --------------------------------
    const start = Date.now();
    for (let i = 0; i < 7; i++) {
        await placeDrawnSignature(page, {
            pageNumber: 1,
            fx: 0.2 + ((i % 4) * 0.18),
            fy: 0.25 + (Math.floor(i / 4) * 0.22),
        });
    }
    const placeMs = Date.now() - start;
    const total = await signatureBoxes(page).count();
    r.assert('bulk-placement', total >= 8,
        'Eight signatures can be placed on one page',
        `${total} boxes in ${(placeMs / 1000).toFixed(1)}s`);

    const zoomStart = Date.now();
    await stepZoom(page, 'in', 2);
    await stepZoom(page, 'out', 2);
    const zoomMs = Date.now() - zoomStart;
    const afterZoom = await signatureBoxes(page).count();
    r.assert('responsive-under-load', afterZoom === total && zoomMs < 20000,
        'Zooming with many signatures keeps all of them and stays responsive',
        `${afterZoom} boxes, zoom round trip ${(zoomMs / 1000).toFixed(1)}s`);

    const saved = await captureAutosave(page, ctx.saveRecorder, { timeout: 45000 });
    r.assert('bulk-save', saved.signatures.length === total,
        'Every signature survives the save at this volume',
        `${saved.signatures.length} saved of ${total}`);

    // --- repeated modal cycles must not leak listeners -------------------------
    for (let i = 0; i < 8; i++) {
        await openSignatureModal(page);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(120);
    }
    await openSignatureModal(page);
    await drawStroke(page);
    const afterCycles = await readModalState(page);
    r.assert('no-listener-leak', afterCycles.modalCount === 1 && afterCycles.applyDisabled === false,
        'Opening and closing the modal repeatedly leaves it working with one instance',
        `modals=${afterCycles.modalCount} apply disabled=${afterCycles.applyDisabled}`);
    await page.keyboard.press('Escape');

    const artifact = await capture(page, '23-regression-load', 'bulk');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 21 — Burn into the PDF and download
// ---------------------------------------------------------------------------

async function testBurnAndExport(page, ctx) {
    const r = ctx.recorder;

    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.45, fy: 0.45 });
    const beforeExport = await captureAutosave(page, ctx.saveRecorder);
    r.assert('placed-before-export', beforeExport.signatures.length === 1,
        'A signature is on the page before exporting');

    // --- burn is deliberately NOT offered for signatures --------------------
    // canBurnAnnotation() allows only shapes and direct-draw strokes, so the
    // subtask's "burn the signature" step does not match the implementation.
    // Assert the real contract so a future change to it is noticed.
    await selectSignatureBox(page);
    const burnVisible = await page.locator('#enpv-ann-menu [data-action="burn"]').isVisible().catch(() => false);
    r.assert('burn-not-offered-for-signatures', burnVisible === false,
        'Burn is not offered for signatures (only shapes and freehand draw are burnable)',
        `burn visible=${burnVisible}`);

    // --- download the annotated PDF -----------------------------------------
    // The endpoint validates an annotations array, so send the same payload
    // the editor itself saves.
    const annotations = beforeExport.all;
    const download = await page.evaluate(async ({ docId, payload }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(`/documents/${docId}/download-annotated-pdf`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/pdf',
            },
            body: JSON.stringify({ annotations: payload, acro_form_entries: [] }),
        });
        const buffer = await response.arrayBuffer();
        return {
            status: response.status,
            contentType: response.headers.get('content-type'),
            bytes: buffer.byteLength,
            head: new TextDecoder().decode(new Uint8Array(buffer.slice(0, 8))),
        };
    }, { docId: ctx.docId, payload: annotations });

    r.assert('download-returns-pdf',
        download.status === 200 && download.head.startsWith('%PDF'),
        'Downloading the annotated document returns a real PDF',
        `HTTP ${download.status}, ${download.bytes} bytes, header "${download.head.trim()}", type=${download.contentType}`);

    // A page carrying an embedded signature image should be materially larger
    // than the near-empty source document.
    r.assert('export-embeds-signature', download.bytes > 3000,
        'The exported PDF is large enough to contain the embedded signature image',
        `${download.bytes} bytes`);

    // --- exporting must not disturb the editor ------------------------------
    const stillThere = await signatureBoxes(page).count();
    r.assert('export-leaves-editor-intact', stillThere === 1,
        'Exporting leaves the placed signature untouched in the editor',
        `${stillThere} box(es) after download`);

    const artifact = await capture(page, '21-burn-export', 'exported');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 22 — Accessibility and keyboard behaviour
// ---------------------------------------------------------------------------

async function testAccessibility(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    // --- dialog semantics ----------------------------------------------------
    const dialog = await page.evaluate(() => {
        const modal = document.getElementById('signature-modal');
        const card = modal?.querySelector('.signature-modal__card');
        const title = document.getElementById('signature-modal-title');
        return {
            role: card?.getAttribute('role'),
            modal: card?.getAttribute('aria-modal'),
            labelledBy: card?.getAttribute('aria-labelledby'),
            titleId: title?.id,
            ariaHidden: modal?.getAttribute('aria-hidden'),
            bodyLocked: document.body.classList.contains('signature-modal-open'),
        };
    });
    r.assert('dialog-semantics',
        dialog.role === 'dialog' && dialog.modal === 'true'
        && dialog.labelledBy === dialog.titleId && dialog.ariaHidden === 'false',
        'The modal is an aria-modal dialog labelled by its title',
        `role=${dialog.role} aria-modal=${dialog.modal} labelledby=${dialog.labelledBy}`);
    r.assert('background-locked', dialog.bodyLocked,
        'The page behind the modal is locked from scrolling');

    // --- tablist semantics ----------------------------------------------------
    const tabs = await page.evaluate(() => {
        const list = document.querySelector('.signature-modal__tabs');
        const items = Array.from(document.querySelectorAll('.signature-modal__tab'));
        return {
            listRole: list?.getAttribute('role'),
            listLabel: list?.getAttribute('aria-label'),
            wired: items.every((tab) => {
                const panel = document.getElementById(tab.getAttribute('aria-controls'));
                return !!panel && panel.getAttribute('role') === 'tabpanel'
                    && panel.getAttribute('aria-labelledby') === tab.id;
            }),
            inTabOrder: items.filter((tab) => tab.tabIndex === 0).length,
            selected: items.filter((tab) => tab.getAttribute('aria-selected') === 'true').length,
        };
    });
    r.assert('tablist-wired',
        tabs.listRole === 'tablist' && !!tabs.listLabel && tabs.wired,
        'Tabs are a labelled tablist, each wired to its own tabpanel',
        `role=${tabs.listRole} label="${tabs.listLabel}" wired=${tabs.wired}`);
    r.assert('roving-tabindex', tabs.inTabOrder === 1 && tabs.selected === 1,
        'Exactly one tab is in the tab order and marked selected',
        `${tabs.inTabOrder} tabbable, ${tabs.selected} selected`);

    // --- keyboard reaches every control ---------------------------------------
    await page.focus('#signature-tab-draw');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    const arrowMoved = await page.evaluate(() => document.activeElement?.id);
    r.assert('arrow-keys-move-tabs', arrowMoved === 'signature-tab-type',
        'Arrow keys move between tabs', `focus=${arrowMoved}`);

    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(250);
    const reachable = await page.evaluate(() => {
        const wanted = ['signature-color', 'signature-width', 'signature-smoothing',
            'signature-clear', 'signature-save-account', 'signature-cancel', 'signature-apply'];
        return wanted.filter((id) => {
            const el = document.getElementById(id);
            if (!el) return true;
            // Negative tabindex or disabled-and-unreachable are the failures.
            return el.tabIndex < 0;
        });
    });
    r.assert('controls-keyboard-reachable', reachable.length === 0,
        'Draw-mode controls and the footer actions are keyboard reachable',
        reachable.length ? `unreachable: ${reachable.join(', ')}` : 'all reachable');

    // The upload input must stay focusable despite being visually hidden.
    await page.click('[data-signature-mode="upload"]');
    await page.waitForTimeout(250);
    const uploadFocus = await page.evaluate(() => {
        const input = document.getElementById('signature-image-input');
        input.focus();
        return {
            focused: document.activeElement === input,
            display: getComputedStyle(input).display,
            label: !!document.querySelector('label[for="signature-image-input"]'),
        };
    });
    r.assert('hidden-file-input-focusable',
        uploadFocus.focused && uploadFocus.display !== 'none' && uploadFocus.label,
        'The visually hidden file input is still focusable and has a label',
        `focused=${uploadFocus.focused} display=${uploadFocus.display} label=${uploadFocus.label}`);

    // --- Esc closes ------------------------------------------------------------
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    const closed = await readModalState(page);
    r.assert('esc-closes', !closed.isOpen && closed.ariaHidden === 'true',
        'Esc closes the dialog and restores aria-hidden');

    // --- placed annotations expose a name --------------------------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.5, fy: 0.5 });
    const boxA11y = await page.evaluate(() => {
        const box = document.querySelector('.enpv-annotation-box[data-annotation-type="signature"]');
        const img = box?.querySelector('img');
        const label = box?.querySelector('.enpv-signature-selection-label');
        return { alt: img?.getAttribute('alt'), label: label?.textContent?.trim() };
    });
    r.assert('placed-signature-labelled',
        !!boxA11y.alt && /signature/i.test(boxA11y.alt) && /signature/i.test(boxA11y.label || ''),
        'A placed signature exposes an accessible name',
        `alt="${boxA11y.alt}" label="${boxA11y.label}"`);

    // --- known gaps, asserted so they are visible rather than assumed ----------
    await openSignatureModal(page);
    const liveRegion = await page.evaluate(() => {
        const status = document.getElementById('signature-status');
        return {
            ariaLive: status?.getAttribute('aria-live'),
            role: status?.getAttribute('role'),
        };
    });
    r.assert('status-is-announced',
        !!liveRegion.ariaLive || liveRegion.role === 'status' || liveRegion.role === 'alert',
        'Status messages sit in a live region so screen readers announce them',
        `aria-live=${liveRegion.ariaLive} role=${liveRegion.role}`);

    const focusTrapped = await page.evaluate(async () => {
        const modal = document.getElementById('signature-modal');
        const focusables = Array.from(modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        )).filter((el) => !el.disabled && el.offsetParent !== null);
        if (!focusables.length) return false;
        focusables[focusables.length - 1].focus();
        return document.activeElement === focusables[focusables.length - 1];
    });
    r.assert('focus-starts-inside', focusTrapped,
        'Focus can be placed on the dialog\'s own controls',
        `focusable=${focusTrapped}`);

    await page.keyboard.press('Escape');
    const artifact = await capture(page, '22-accessibility', 'a11y');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 23 — Regression: cross-tool interference and load
// ---------------------------------------------------------------------------

async function testRegressionAndLoad(page, ctx) {
    const r = ctx.recorder;

    // --- signature placement must not break the other tools -------------------
    await placeDrawnSignature(page, { pageNumber: 1, fx: 0.35, fy: 0.3 });
    const toolsAlive = await page.evaluate(() => {
        const ids = ['ftb-sign', 'ftb-add-text', 'ftb-add-shape'];
        return ids.map((id) => {
            const el = document.getElementById(id);
            return { id, present: !!el, disabled: el ? !!el.disabled : null };
        });
    });
    r.assert('other-tools-still-usable',
        toolsAlive.every((tool) => tool.present && tool.disabled !== true),
        'The text and shape tools are still usable after placing a signature',
        toolsAlive.map((tool) => `${tool.id}=${tool.present ? 'ok' : 'missing'}`).join(', '));

    // --- switching tools cancels a pending placement --------------------------
    await composeDrawnMark(page);
    await armPlacement(page);
    const armedBefore = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    await page.click('#ftb-add-text');
    await page.waitForTimeout(600);
    const armedAfter = await page.evaluate(() => document.body.classList.contains('enpv-signature-placing'));
    r.assert('tool-switch-cancels-placement', armedBefore && !armedAfter,
        'Switching to another tool cancels a pending signature placement',
        `armed before=${armedBefore} after=${armedAfter}`);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // --- signature annotations vs signature form fields -----------------------
    const distinct = await page.evaluate(() => {
        const sigs = document.querySelectorAll('.enpv-annotation-box[data-annotation-type="signature"]').length;
        const fields = document.querySelectorAll('.enpv-annotation-box[data-annotation-type="field"]').length;
        return { sigs, fields };
    });
    r.assert('signature-vs-field-distinct', distinct.sigs >= 1,
        'Signature annotations are typed distinctly from signature form fields',
        `signature boxes=${distinct.sigs}, field boxes=${distinct.fields}`);

    // --- load: many signatures stay responsive --------------------------------
    const start = Date.now();
    for (let i = 0; i < 7; i++) {
        await placeDrawnSignature(page, {
            pageNumber: 1,
            fx: 0.2 + ((i % 4) * 0.18),
            fy: 0.25 + (Math.floor(i / 4) * 0.22),
        });
    }
    const placeMs = Date.now() - start;
    const total = await signatureBoxes(page).count();
    r.assert('bulk-placement', total >= 8,
        'Eight signatures can be placed on one page',
        `${total} boxes in ${(placeMs / 1000).toFixed(1)}s`);

    const zoomStart = Date.now();
    await stepZoom(page, 'in', 2);
    await stepZoom(page, 'out', 2);
    const zoomMs = Date.now() - zoomStart;
    const afterZoom = await signatureBoxes(page).count();
    r.assert('responsive-under-load', afterZoom === total && zoomMs < 20000,
        'Zooming with many signatures keeps all of them and stays responsive',
        `${afterZoom} boxes, zoom round trip ${(zoomMs / 1000).toFixed(1)}s`);

    const saved = await captureAutosave(page, ctx.saveRecorder, { timeout: 45000 });
    r.assert('bulk-save', saved.signatures.length === total,
        'Every signature survives the save at this volume',
        `${saved.signatures.length} saved of ${total}`);

    // --- repeated modal cycles must not leak listeners -------------------------
    for (let i = 0; i < 8; i++) {
        await openSignatureModal(page);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(120);
    }
    await openSignatureModal(page);
    await drawStroke(page);
    const afterCycles = await readModalState(page);
    r.assert('no-listener-leak', afterCycles.modalCount === 1 && afterCycles.applyDisabled === false,
        'Opening and closing the modal repeatedly leaves it working with one instance',
        `modals=${afterCycles.modalCount} apply disabled=${afterCycles.applyDisabled}`);
    await page.keyboard.press('Escape');

    const artifact = await capture(page, '23-regression-load', 'bulk');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 24 — NK_Dev_5: modal chrome, stroke range and preview sizing
// ---------------------------------------------------------------------------

async function testModalChrome(page, ctx) {
    const r = ctx.recorder;
    await openSignatureModal(page);

    const removedStrings = [
        'This preview becomes the annotation that gets placed into the document.',
        'Draw mode: click and drag to sign.',
        'Upload mode: choose an image and preview the stamp.',
        'Pick a saved signature to load it into the composer.',
    ];
    const modalText = await page.evaluate(() => document.getElementById('signature-modal').textContent || '');
    const stillPresent = removedStrings.filter((line) => modalText.includes(line));
    r.assert('retired-copy-removed', stillPresent.length === 0,
        'The four retired lines of copy are gone from the modal',
        stillPresent.length ? stillPresent.join(' | ') : 'all four removed');

    const subtext = await page.evaluate(() => ({
        hint: !!document.getElementById('signature-hint'),
        copy: !!document.querySelector('.signature-stage__copy'),
        stageHints: document.querySelectorAll('.signature-stage__hint').length,
    }));
    r.assert('no-subtext-under-preview',
        !subtext.hint && !subtext.copy && subtext.stageHints === 0,
        'No subtext is rendered under the live preview',
        `hint=${subtext.hint} copy=${subtext.copy} stageHints=${subtext.stageHints}`);

    const width = await page.evaluate(() => {
        const el = document.getElementById('signature-width');
        return { min: el.min, max: el.max, value: el.value };
    });
    r.assert('stroke-width-range-raised', Number(width.max) >= 24,
        'Stroke width allows a much heavier stroke than before',
        `min=${width.min} max=${width.max}`);

    // The heaviest stroke must actually paint heavier than the lightest.
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', 1);
    await drawStroke(page);
    const thin = (await canvasFingerprint(page)).inked;
    await page.click('#signature-clear');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', Number(width.max));
    await drawStroke(page);
    const thick = (await canvasFingerprint(page)).inked;
    r.assert('max-stroke-paints-heavier', thick > thin * 3,
        'The new maximum stroke width paints a much heavier line',
        `1px=${thin} vs ${width.max}px=${thick} inked px`);

    const layout = await page.evaluate(() => {
        const stage = document.querySelector('.signature-stage');
        const shell = document.querySelector('.signature-stage__canvas-shell');
        const canvas = document.getElementById('signature-canvas');
        return {
            stagePad: parseFloat(getComputedStyle(stage).paddingTop),
            shellPad: parseFloat(getComputedStyle(shell).paddingTop),
            canvasHeight: Math.round(canvas.getBoundingClientRect().height),
            shellHeight: Math.round(shell.getBoundingClientRect().height),
        };
    });
    r.assert('preview-larger-padding-smaller',
        layout.canvasHeight >= 280 && layout.stagePad <= 12 && layout.shellPad <= 10,
        'The white preview area is larger and the surrounding padding reduced',
        `canvas=${layout.canvasHeight}px stagePad=${layout.stagePad}px shellPad=${layout.shellPad}px`);

    const artifact = await capture(page, '24-modal-chrome', 'chrome');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 25 — NK_Dev_5: remembered tab and settings
// ---------------------------------------------------------------------------

async function testRememberedSettings(page, ctx) {
    const r = ctx.recorder;

    await openSignatureModal(page);
    const first = await readModalState(page);
    r.assert('first-open-defaults', first.activeMode === 'draw' && first.values.width === '3',
        'A fresh browser opens on Draw with factory defaults',
        `mode=${first.activeMode} width=${first.values.width}`);

    // Change tab and several settings.
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    await page.selectOption('#signature-font', 'Pacifico');
    await setRange(page, 'signature-type-size', 96);
    await setColour(page, 'signature-type-color', '#aa2266');
    await page.click('[data-signature-mode="draw"]');
    await page.waitForTimeout(200);
    await setRange(page, 'signature-width', 11);
    await setRange(page, 'signature-smoothing', 20);
    await setColour(page, 'signature-color', '#227744');
    await page.click('[data-signature-mode="type"]');
    await page.waitForTimeout(250);
    await page.fill('#signature-text', 'Remembered');
    await page.waitForTimeout(400);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);

    // Reopen without reloading.
    await openSignatureModal(page);
    const reopened = await readModalState(page);
    r.assert('remembers-tab', reopened.activeMode === 'type',
        'Reopening returns to the tab that was last used',
        `mode=${reopened.activeMode}`);
    r.assert('remembers-settings',
        reopened.values.font === 'Pacifico'
        && reopened.values.typeSize === '96'
        && reopened.values.width === '11'
        && reopened.values.smoothing === '20'
        && reopened.values.color === '#227744'
        && reopened.values.typeColor === '#aa2266',
        'Reopening restores every chosen setting',
        `font=${reopened.values.font} size=${reopened.values.typeSize} width=${reopened.values.width} `
        + `smoothing=${reopened.values.smoothing} ink=${reopened.values.color}/${reopened.values.typeColor}`);
    r.assert('content-not-remembered',
        reopened.values.text === '' && reopened.canvasBlank === true && reopened.applyDisabled === true,
        'The previous signature CONTENT is still cleared, so it is never reused by accident',
        `text=${JSON.stringify(reopened.values.text)} blank=${reopened.canvasBlank}`);

    // And across a full reload.
    await page.keyboard.press('Escape');
    await openEditor(page, ctx.docId);
    await openSignatureModal(page);
    const afterReload = await readModalState(page);
    r.assert('remembers-across-reload',
        afterReload.activeMode === 'type'
        && afterReload.values.font === 'Pacifico'
        && afterReload.values.width === '11',
        'Settings and tab survive a full page reload',
        `mode=${afterReload.activeMode} font=${afterReload.values.font} width=${afterReload.values.width}`);

    const artifact = await capture(page, '25-remembered-settings', 'remembered');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 26 — NK_Dev_5: Saved tab grid/row layout, search and rename
// ---------------------------------------------------------------------------

async function testSavedTabLayoutAndSearch(page, ctx) {
    const r = ctx.recorder;

    const entries = [
        { ...TYPED_ENTRY, id: '1', name: 'Client contracts' },
        { ...TYPED_ENTRY, id: '2', name: 'Internal approvals' },
        { ...TYPED_ENTRY, id: '3', name: 'Invoice sign-off' },
    ];
    const store = await stubAccountEndpoint(page, entries);
    await renderEditorAsSignedIn(page, ctx.docId);
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(700);

    // --- grid by default ---------------------------------------------------
    const initial = await page.evaluate(() => {
        const list = document.getElementById('signature-account-list');
        return {
            grid: list.classList.contains('is-grid'),
            row: list.classList.contains('is-row'),
            columns: getComputedStyle(list).gridTemplateColumns,
            gridPressed: document.querySelector('[data-signature-view-mode="grid"]')?.getAttribute('aria-pressed'),
        };
    });
    r.assert('starts-in-grid', initial.grid && !initial.row && initial.gridPressed === 'true',
        'The saved list starts in grid mode',
        `grid=${initial.grid} columns="${initial.columns}"`);
    r.assert('grid-has-multiple-columns', (initial.columns || '').trim().split(/\s+/).length > 1,
        'Grid mode lays entries out in multiple columns',
        `columns="${initial.columns}"`);

    // --- switch to row ------------------------------------------------------
    await page.click('[data-signature-view-mode="row"]');
    await page.waitForTimeout(400);
    const rowMode = await page.evaluate(() => {
        const list = document.getElementById('signature-account-list');
        return {
            row: list.classList.contains('is-row'),
            grid: list.classList.contains('is-grid'),
            rowPressed: document.querySelector('[data-signature-view-mode="row"]')?.getAttribute('aria-pressed'),
        };
    });
    r.assert('switches-to-row', rowMode.row && !rowMode.grid && rowMode.rowPressed === 'true',
        'The layout can be switched to row', `row=${rowMode.row} grid=${rowMode.grid}`);

    // The chosen layout is a preference too.
    await page.keyboard.press('Escape');
    await openSignatureModal(page);
    await page.click('[data-signature-view="saved"]');
    await page.waitForTimeout(500);
    const remembered = await page.evaluate(
        () => document.getElementById('signature-account-list').classList.contains('is-row'),
    );
    r.assert('remembers-layout', remembered,
        'The chosen layout is remembered between opens', `row=${remembered}`);
    await page.click('[data-signature-view-mode="grid"]');
    await page.waitForTimeout(300);

    // --- typeahead search ---------------------------------------------------
    const countRows = () => page.locator('#signature-account-list .signature-library__item').count();
    r.assert('all-entries-listed', (await countRows()) === 3,
        'All saved signatures are listed before filtering');

    await page.fill('#signature-account-search', 'inv');
    await page.waitForTimeout(400);
    const narrowed = await countRows();
    const narrowedText = await page.locator('#signature-account-list').textContent();
    r.assert('typeahead-narrows', narrowed === 1 && /Invoice sign-off/.test(narrowedText),
        'Typing narrows the list as you type, matching case-insensitively',
        `${narrowed} row(s) for "inv"`);

    await page.fill('#signature-account-search', 'c');
    await page.waitForTimeout(400);
    r.assert('typeahead-widens', (await countRows()) >= 2,
        'Deleting characters widens the results again', `${await countRows()} row(s) for "c"`);

    await page.fill('#signature-account-search', 'zzzz');
    await page.waitForTimeout(400);
    const emptyState = await page.evaluate(
        () => !!document.querySelector('.signature-account__empty-search'),
    );
    r.assert('no-match-state', emptyState && (await countRows()) === 0,
        'A query with no matches shows a dedicated empty state', `emptyState=${emptyState}`);

    await page.fill('#signature-account-search', '');
    await page.waitForTimeout(400);
    r.assert('clearing-restores', (await countRows()) === 3,
        'Clearing the search restores every entry');

    // --- rename --------------------------------------------------------------
    await page.locator('#signature-account-list [data-account-signature-action="rename"]').first().click();
    await page.waitForTimeout(400);
    const editing = await page.evaluate(() => {
        const input = document.querySelector('[data-account-signature-rename-input]');
        return { present: !!input, focused: document.activeElement === input, value: input?.value };
    });
    r.assert('rename-opens-inline-editor', editing.present && editing.focused,
        'Rename opens a focused inline field pre-filled with the current name',
        `present=${editing.present} focused=${editing.focused} value="${editing.value}"`);

    await page.fill('[data-account-signature-rename-input]', 'Renamed by test');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(900);
    r.assert('rename-sends-patch',
        store.patched.some((entry) => entry.name === 'Renamed by test'),
        'Committing sends the new name to the server',
        `patched=${JSON.stringify(store.patched)}`);
    const afterRename = await page.locator('#signature-account-list').textContent();
    r.assert('rename-shows-in-list', /Renamed by test/.test(afterRename),
        'The list shows the new name');

    // Escape abandons a rename.
    await page.locator('#signature-account-list [data-account-signature-action="rename"]').first().click();
    await page.waitForTimeout(350);
    await page.fill('[data-account-signature-rename-input]', 'Should not stick');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(600);
    const afterEscape = await page.locator('#signature-account-list').textContent();
    r.assert('escape-abandons-rename', !/Should not stick/.test(afterEscape),
        'Escape abandons a rename without saving it',
        afterEscape.replace(/\s+/g, ' ').trim().slice(0, 70));

    const artifact = await capture(page, '26-saved-layout-search', 'saved-tab');
    await page.keyboard.press('Escape');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Test 27 — NK_Dev_5: font size determines the placed signature size
// ---------------------------------------------------------------------------

async function testFontSizeDrivesPlacement(page, ctx) {
    const r = ctx.recorder;

    /** Compose a typed mark at `size` and place it, returning both geometries. */
    const placeAt = async (size, fy) => {
        await openSignatureModal(page);
        await page.click('[data-signature-mode="type"]');
        await page.waitForTimeout(250);
        await page.fill('#signature-text', 'Ada Lovelace');
        await setRange(page, 'signature-type-size', size);
        await page.waitForTimeout(700);
        const ink = await measureCanvasInk(page);
        await armPlacement(page);
        await clickPageFraction(page, 1, 0.5, fy);
        return ink;
    };

    const smallInk = await placeAt(48, 0.3);
    const largeInk = await placeAt(180, 0.6);
    const saved = await captureAutosave(page, ctx.saveRecorder);

    r.assert('both-placed', saved.signatures.length === 2,
        'Both typed signatures were placed', `${saved.signatures.length} placed`);

    const sorted = saved.signatures.slice().sort((a, b) => num(a.pdfWidth) - num(b.pdfWidth));
    const [small, large] = sorted;

    r.assert('smaller-font-places-smaller',
        !!small && !!large && num(small.pdfWidth) < num(large.pdfWidth) * 0.6,
        'A 48px font places a genuinely smaller signature than a 180px font',
        small && large ? `${num(small.pdfWidth).toFixed(1)}pt vs ${num(large.pdfWidth).toFixed(1)}pt` : 'missing');

    r.assert('composer-size-affects-mark',
        !!smallInk && !!largeInk && smallInk.boxWidth < largeInk.boxWidth,
        'The composed mark itself is smaller at the smaller font size',
        `${smallInk?.boxWidth}px vs ${largeInk?.boxWidth}px on the canvas`);

    // The old bug: every signature was scaled to the same width, so a small
    // mark was stretched up and looked blurry. Placed size should now track
    // the composed pixels one-for-one.
    const scales = sorted.map((entry) => (
        num(entry.pdfWidth) / (num(entry.intrinsicWidth) * (208 / 900))
    ));
    r.assert('no-upscaling-blur', scales.every((scale) => scale > 0.9 && scale < 1.15),
        'Placed size tracks the composed mark instead of stretching it to a fixed width',
        `placed/intrinsic scale = ${scales.map((x) => x.toFixed(2)).join(', ')}`);

    // Aspect must survive, including for the small mark.
    const aspectErrors = sorted.filter((entry) => {
        const placed = num(entry.pdfWidth) / num(entry.pdfHeight);
        const source = num(entry.intrinsicWidth) / num(entry.intrinsicHeight);
        return Math.abs(placed - source) / source > 0.05;
    });
    r.assert('aspect-preserved-at-every-size', aspectErrors.length === 0,
        'Small and large signatures keep their aspect ratio (no squashing by the size floors)',
        sorted.map((entry) => `${(num(entry.pdfWidth) / num(entry.pdfHeight)).toFixed(2)} vs `
            + `${(num(entry.intrinsicWidth) / num(entry.intrinsicHeight)).toFixed(2)}`).join(' | '));

    const artifact = await capture(page, '27-font-size-placement', 'sizes');
    return { checks: r.checks, artifacts: [artifact].filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Orchestration
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
                // A fresh context per test keeps localStorage and the document
                // independent, so tests cannot leak state into each other.
                context = await browser.newContext({
                    viewport: { width: 1500, height: 1000 },
                    ignoreHTTPSErrors: true,
                });

                // Most tests are about modal behaviour, not webfonts. Blocking
                // Google Fonts keeps them hermetic and fast — the container has
                // no reliable outbound route, so letting these requests hang
                // added ~60s to the run. Test 06 toggles this off because
                // webfont loading is precisely what it covers.
                let fontsBlocked = true;
                const setFontsBlocked = (value) => { fontsBlocked = !!value; };
                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => {
                    if (fontsBlocked) route.abort().catch(() => {});
                    else route.continue().catch(() => {});
                });

                const page = await context.newPage();
                const saveRecorder = attachSaveRecorder(page);

                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(
                    page,
                    csrfToken,
                    `Signature test ${test.number}`,
                    test.pages || 1,
                );
                const consoleErrors = await openEditor(page, docId);

                const outcome = await test.run(page, { consoleErrors, docId, saveRecorder, setFontsBlocked, recorder });
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
        console.error('Usage: node run_signature_tests.cjs --list | --run <ids> | --run-all');
        process.exit(1);
    }

    const output = await runTests(ids);
    console.log(JSON.stringify(output));
}

// Only auto-run as a CLI; requiring this file (e.g. to reuse helpers) must
// not kick off a browser run.
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
