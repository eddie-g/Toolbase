/**
 * Merge / Split Tool Automated Tests (Playwright)
 *
 * Automates the "[QA] - Merge / Split tool" Asana task (Netkit -> Claude QA
 * Agent). Each run creates a fresh blank PDF, uploads it as a new document,
 * opens it in the pdf.js editor, and drives the real Merge / Split modal.
 *
 * Scope: the floating toolbar's Merge / Split button, the shared modal, the
 * Merge tab (add whole PDFs, order them, merge them into the current working
 * document) and the Split tab (tick pages, name the result, download it or
 * open it as a new editor document).
 *
 * WHY MOST OF THIS RUNS FOR REAL
 * Unlike the Convert tool's Word and Excel paths, neither endpoint here bills
 * the account or needs a queue worker: a merge runs merge_pdf_documents.py and
 * a split runs split_pdf.py synchronously in the app container, and the tool
 * is not premium-gated, so every case runs as a guest. Cases 11, 20 and 21
 * therefore exercise the real thing end to end and read the result back
 * through the app's own pdf.js. Only the contract, busy and failure cases
 * intercept the POST.
 *
 * Usage:
 *   node run_merge_split_tests.cjs --list
 *   node run_merge_split_tests.cjs --run 01-blank-project-loads,11-real-merge
 *   node run_merge_split_tests.cjs --run-all
 *
 * Output is a single JSON document on stdout so the Laravel controller can
 * parse it. Nothing else may be written to stdout.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const zlib = require('zlib');

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

/** Limits injected on #enpv-root from config/pdf_editor.php, mirrored here. */
const MERGE_LIMITS = {
    maxFiles: 10,
    maxFileBytes: 20 * 1024 * 1024,
    maxPages: 1000,
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
// PDF fixtures
// ---------------------------------------------------------------------------

/**
 * Build a minimal valid 612x792 PDF as raw bytes, one line of text per page
 * reading "<label> - page N". Those strings are what the merge and split
 * cases read back to prove page order, so they are kept in an uncompressed
 * content stream where both PyMuPDF and this file's own parser can find them.
 */
function buildBlankPdfBuffer(label, pageCount = 1) {
    const title = String(label || 'Merge Split tool automated test').replace(/[()\\]/g, '');
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

/** RC4, written out because OpenSSL 3 no longer ships it. */
function rc4(key, data) {
    const s = Array.from({ length: 256 }, (_, i) => i);
    let j = 0;
    for (let i = 0; i < 256; i += 1) {
        j = (j + s[i] + key[i % key.length]) & 0xff;
        [s[i], s[j]] = [s[j], s[i]];
    }
    const out = Buffer.alloc(data.length);
    let i = 0;
    j = 0;
    for (let k = 0; k < data.length; k += 1) {
        i = (i + 1) & 0xff;
        j = (j + s[i]) & 0xff;
        [s[i], s[j]] = [s[j], s[i]];
        out[k] = data[k] ^ s[(s[i] + s[j]) & 0xff];
    }
    return out;
}

/**
 * A one-page PDF locked with the standard security handler (R2, 40-bit RC4)
 * and a non-empty user password, which is what makes pdf.js refuse it
 * without a password. The page content itself is never decrypted by the
 * client -- it throws on the /Encrypt dictionary first -- so only the
 * handler's /O and /U values need to be genuine.
 */
function buildEncryptedPdfBuffer(userPassword = 'secret', ownerPassword = 'owner') {
    const PAD = Buffer.from([
        0x28, 0xbf, 0x4e, 0x5e, 0x4e, 0x75, 0x8a, 0x41, 0x64, 0x00, 0x4e, 0x56, 0xff, 0xfa, 0x01, 0x08,
        0x2e, 0x2e, 0x00, 0xb6, 0xd0, 0x68, 0x3e, 0x80, 0x2f, 0x0c, 0xa9, 0xfe, 0x64, 0x53, 0x69, 0x7a,
    ]);
    const pad = (password) => Buffer.concat([Buffer.from(password, 'latin1'), PAD]).subarray(0, 32);
    const md5 = (...parts) => crypto.createHash('md5').update(Buffer.concat(parts)).digest();
    const permissions = -1;
    const permissionBytes = Buffer.alloc(4);
    permissionBytes.writeInt32LE(permissions);
    const fileId = crypto.createHash('md5').update('merge-split-encrypted-fixture').digest();

    const ownerKey = md5(pad(ownerPassword)).subarray(0, 5);
    const oValue = rc4(ownerKey, pad(userPassword));
    const fileKey = md5(pad(userPassword), oValue, permissionBytes, fileId).subarray(0, 5);
    const uValue = rc4(fileKey, PAD);
    const hex = (buffer) => `<${buffer.toString('hex')}>`;

    const content = 'BT /F1 12 Tf 24 756 Td (locked) Tj ET\n';
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_WIDTH_PTS} ${PAGE_HEIGHT_PTS}] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>`,
        `<< /Length ${content.length} >>\nstream\n${content}endstream`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        `<< /Filter /Standard /V 1 /R 2 /Length 40 /P ${permissions} /O ${hex(oValue)} /U ${hex(uValue)} >>`,
    ];

    let pdf = '%PDF-1.4\n';
    const offsets = [];
    objects.forEach((body, index) => {
        offsets.push(Buffer.byteLength(pdf, 'latin1'));
        pdf += `${index + 1} 0 obj\n${body}\nendobj\n`;
    });
    const xrefOffset = Buffer.byteLength(pdf, 'latin1');
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    offsets.forEach((offset) => { pdf += `${String(offset).padStart(10, '0')} 00000 n \n`; });
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R /Encrypt 6 0 R /ID [${hex(fileId)} ${hex(fileId)}] >>\nstartxref\n${xrefOffset}\n%%EOF\n`;
    return Buffer.from(pdf, 'latin1');
}

/** A file spec for page.setInputFiles(). */
function pdfFile(name, label, pageCount = 1) {
    return { name, mimeType: 'application/pdf', buffer: buildBlankPdfBuffer(label, pageCount) };
}

/**
 * The page count of a PDF, from the largest /Count in its page tree -- the
 * root Pages node carries the total, and any intermediate node is smaller.
 */
function pdfPageCount(buffer) {
    const text = buffer.toString('latin1');
    let best = 0;
    const re = /\/Count\s+(\d+)/g;
    let match = re.exec(text);
    while (match) {
        best = Math.max(best, Number(match[1]));
        match = re.exec(text);
    }
    return best;
}

/**
 * Every "(...) Tj" string in a PDF's content streams, in file order,
 * inflating FlateDecode streams so a writer that compresses on save is read
 * the same as one that does not.
 */
function pdfTextStrings(buffer) {
    const strings = [];
    const text = buffer.toString('latin1');
    const re = /stream\r?\n([\s\S]*?)\r?\nendstream/g;
    let match = re.exec(text);
    while (match) {
        const raw = Buffer.from(match[1], 'latin1');
        let body = raw.toString('latin1');
        try {
            body = zlib.inflateSync(raw).toString('latin1');
        } catch (_) {
            // Not compressed, or not a content stream at all.
        }
        const tj = /\(((?:\\.|[^\\)])*)\)\s*Tj/g;
        let inner = tj.exec(body);
        while (inner) {
            strings.push(inner[1]);
            inner = tj.exec(body);
        }
        match = re.exec(text);
    }
    return strings;
}

// ---------------------------------------------------------------------------
// Document setup
// ---------------------------------------------------------------------------

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
    const name = `merge_split_tool_test_${process.pid}_${uploadCounter}.pdf`;

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
 * Open the pdf.js editor and wait until page 1 has rasterised and the editor
 * has published its viewer on window.__enpv. Returns the console errors
 * collected during load.
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
        return !!canvas && canvas.width > 0 && canvas.height > 0 && !!window.__enpv?.pdfViewer;
    }, { timeout: 20000 });
    // Let the tool bindings attach before driving the UI.
    await page.waitForTimeout(600);

    return consoleErrors;
}

/** Console noise that says nothing about the tool under test. */
function meaningfulConsoleErrors(consoleErrors) {
    return (consoleErrors || []).filter((text) => !/fonts\.(googleapis|gstatic)|favicon|ERR_INTERNET_DISCONNECTED|ERR_NAME_NOT_RESOLVED/i.test(text));
}

// ---------------------------------------------------------------------------
// Editor probes
// ---------------------------------------------------------------------------

function editorPageCount(page) {
    return page.evaluate(() => Number(window.__enpv?.pdfViewer?.pagesCount || 0));
}

/** Every page's text, read through the app's own pdf.js. */
function editorPageTexts(page) {
    return page.evaluate(async () => {
        const doc = window.__enpv?.pdfViewer?.pdfDocument;
        if (!doc) return [];
        const texts = [];
        for (let number = 1; number <= doc.numPages; number += 1) {
            // eslint-disable-next-line no-await-in-loop
            const content = await (await doc.getPage(number)).getTextContent();
            texts.push(content.items.map((item) => item.str).join(' ').replace(/\s+/g, ' ').trim());
        }
        return texts;
    });
}

async function waitForPageCount(page, expected, timeoutMs = 90000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        if ((await editorPageCount(page)) === expected) return true;
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(300);
    }
    return false;
}

function documentName(page) {
    return page.evaluate(() => document.getElementById('doc-name-display')?.textContent?.trim() || '');
}

/**
 * The status line under the toolbar. setStatus() signals an error with an
 * inline colour rather than a class: #f88 for a failure, #ddd otherwise.
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
// Modal probes and drivers
// ---------------------------------------------------------------------------

function modalState(page) {
    return page.evaluate(() => {
        const modal = document.getElementById('enpv-merge-modal');
        const byId = (id) => document.getElementById(id);
        const status = (id) => {
            const el = byId(id);
            return { text: el?.textContent?.trim() || '', isError: !!el?.classList.contains('is-error') };
        };
        const tab = (id) => {
            const el = byId(id);
            return {
                selected: el?.getAttribute('aria-selected'),
                active: !!el?.classList.contains('is-active'),
                tabIndex: el ? el.tabIndex : null,
                disabled: !!el?.disabled,
            };
        };
        const nameDialog = byId('enpv-split-name-dialog');
        return {
            present: !!modal,
            open: !!modal && modal.hidden === false,
            busy: !!modal?.classList.contains('is-busy'),
            title: byId('enpv-merge-title')?.textContent?.trim() || '',
            subtitle: byId('enpv-merge-subtitle')?.textContent?.trim() || '',
            mergeTab: tab('enpv-merge-tab'),
            splitTab: tab('enpv-split-tab'),
            activeTab: byId('enpv-split-tab')?.getAttribute('aria-selected') === 'true' ? 'split' : 'merge',
            mergePanelHidden: byId('enpv-merge-panel')?.hidden,
            splitPanelHidden: byId('enpv-split-panel')?.hidden,
            closeDisabled: !!byId('enpv-merge-close')?.disabled,
            cancelDisabled: !!byId('enpv-merge-cancel')?.disabled,
            dropzoneDisabled: !!byId('enpv-merge-dropzone')?.disabled,
            submitDisabled: !!byId('enpv-merge-submit')?.disabled,
            mergeSummary: byId('enpv-merge-summary')?.textContent?.trim() || '',
            mergeStatus: status('enpv-merge-status'),
            fileInputValue: byId('enpv-merge-file-input')?.value || '',
            splitSummary: byId('enpv-split-summary')?.textContent?.trim() || '',
            splitStatus: status('enpv-split-status'),
            splitSubmitDisabled: !!byId('enpv-split-submit')?.disabled,
            splitCancelDisabled: !!byId('enpv-split-cancel')?.disabled,
            selectAllDisabled: !!byId('enpv-split-select-all')?.disabled,
            clearDisabled: !!byId('enpv-split-clear')?.disabled,
            nameDialogOpen: !!nameDialog && nameDialog.hidden === false,
            nameValue: byId('enpv-split-name-input')?.value || '',
            nameDisabled: !!byId('enpv-split-name-input')?.disabled,
            nameStatus: status('enpv-split-name-status'),
            nameCancelDisabled: !!byId('enpv-split-name-cancel')?.disabled,
            nameDownloadDisabled: !!byId('enpv-split-name-download')?.disabled,
            nameOpenDisabled: !!byId('enpv-split-name-open-editor')?.disabled,
            focusedId: document.activeElement?.id || '',
            focusedIsSplitCard: !!document.activeElement?.classList?.contains('enpv-split-page'),
        };
    });
}

async function openModal(page) {
    await page.evaluate(() => document.getElementById('ftb-merge-pdf')?.click());
    await page.waitForTimeout(700);
    return modalState(page);
}

async function closeModal(page, how = 'close') {
    if (how === 'close') {
        await page.evaluate(() => document.getElementById('enpv-merge-close')?.click());
    } else if (how === 'cancel') {
        await page.evaluate(() => document.getElementById('enpv-merge-cancel')?.click());
    } else if (how === 'split-cancel') {
        await page.evaluate(() => document.getElementById('enpv-split-cancel')?.click());
    } else if (how === 'escape') {
        await page.keyboard.press('Escape');
    } else {
        // The scrim: a click on the modal element itself, outside the card.
        await page.evaluate(() => document.getElementById('enpv-merge-modal')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        ));
    }
    await page.waitForTimeout(400);
    return modalState(page);
}

async function setTab(page, tab) {
    await page.evaluate((id) => document.getElementById(id)?.click(), tab === 'split' ? 'enpv-split-tab' : 'enpv-merge-tab');
    await page.waitForTimeout(600);
    return modalState(page);
}

/** The document cards on the Merge tab, top to bottom. */
function mergeCards(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('#enpv-merge-list .enpv-merge-item')).map((row) => {
        const button = (suffix) => row.querySelector(`.enpv-merge-actions button[aria-label$="${suffix}"]`);
        const up = row.querySelector('.enpv-merge-actions button[aria-label^="Move "][aria-label$=" up"]');
        const down = row.querySelector('.enpv-merge-actions button[aria-label^="Move "][aria-label$=" down"]');
        const remove = row.querySelector('.enpv-merge-actions button[aria-label^="Remove "]');
        const image = row.querySelector('.enpv-merge-thumb img');
        void button;
        return {
            id: row.dataset.mergeId || '',
            name: row.querySelector('.enpv-merge-name')?.textContent?.trim() || '',
            meta: Array.from(row.querySelectorAll('.enpv-merge-meta > span')).map((span) => span.textContent.trim()).join(' | '),
            isCurrent: !!row.querySelector('.enpv-merge-current-badge'),
            ariaLabel: row.getAttribute('aria-label') || '',
            draggable: row.draggable,
            hasThumbnail: !!image && String(image.getAttribute('src') || '').startsWith('data:image/png'),
            upDisabled: up ? up.disabled : null,
            downDisabled: down ? down.disabled : null,
            hasRemove: !!remove,
            removeDisabled: remove ? remove.disabled : null,
            gripLabel: row.querySelector('.enpv-merge-grip')?.getAttribute('aria-label') || '',
        };
    }));
}

/** Add files through the (hidden) file input, like the drop zone's picker does. */
async function addMergeFiles(page, files) {
    await page.setInputFiles('#enpv-merge-file-input', files);
    await page.waitForFunction(() => {
        const text = document.getElementById('enpv-merge-status')?.textContent || '';
        return text !== 'Checking PDF files...';
    }, { timeout: 60000 }).catch(() => null);
    await page.waitForTimeout(500);
    return mergeCards(page);
}

async function moveCard(page, id, direction) {
    await page.evaluate(({ cardId, suffix }) => {
        const row = document.querySelector(`#enpv-merge-list .enpv-merge-item[data-merge-id="${CSS.escape(cardId)}"]`);
        row?.querySelector(`.enpv-merge-actions button[aria-label^="Move "][aria-label$=" ${suffix}"]`)?.click();
    }, { cardId: id, suffix: direction });
    await page.waitForTimeout(300);
    return mergeCards(page);
}

async function removeCard(page, id) {
    await page.evaluate((cardId) => {
        const row = document.querySelector(`#enpv-merge-list .enpv-merge-item[data-merge-id="${CSS.escape(cardId)}"]`);
        row?.querySelector('.enpv-merge-actions button[aria-label^="Remove "]')?.click();
    }, id);
    await page.waitForTimeout(300);
    return mergeCards(page);
}

async function clickMergeSubmit(page) {
    await page.evaluate(() => document.getElementById('enpv-merge-submit')?.click());
}

/** The page cards on the Split tab. */
function splitCards(page) {
    return page.evaluate(() => Array.from(document.querySelectorAll('#enpv-split-grid .enpv-split-page')).map((card) => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        const canvas = card.querySelector('canvas');
        return {
            index: Number(card.dataset.splitPageIndex),
            label: card.querySelector('.enpv-split-page-label')?.textContent?.trim() || '',
            ariaLabel: card.getAttribute('aria-label') || '',
            role: card.getAttribute('role') || '',
            pressed: card.getAttribute('aria-pressed'),
            selected: card.classList.contains('is-selected'),
            tabIndex: card.tabIndex,
            checked: !!checkbox?.checked,
            checkboxDisabled: !!checkbox?.disabled,
            checkboxLabel: checkbox?.getAttribute('aria-label') || '',
            hasCanvas: !!canvas,
            canvasDrawn: !!canvas && canvas.width > 1 && canvas.height > 1,
        };
    }));
}

async function clickSplitCard(page, index) {
    await page.evaluate((at) => {
        const card = document.querySelector(`#enpv-split-grid .enpv-split-page[data-split-page-index="${at}"]`);
        card?.querySelector('.enpv-split-page-label')?.click();
    }, index);
    await page.waitForTimeout(250);
    return splitCards(page);
}

async function clickSplitCheckbox(page, index) {
    await page.evaluate((at) => {
        const card = document.querySelector(`#enpv-split-grid .enpv-split-page[data-split-page-index="${at}"]`);
        card?.querySelector('input[type="checkbox"]')?.click();
    }, index);
    await page.waitForTimeout(250);
    return splitCards(page);
}

async function keyToggleSplitCard(page, index, key) {
    await page.evaluate((at) => {
        document.querySelector(`#enpv-split-grid .enpv-split-page[data-split-page-index="${at}"]`)?.focus();
    }, index);
    await page.keyboard.press(key);
    await page.waitForTimeout(250);
    return splitCards(page);
}

async function openNameDialog(page) {
    await page.evaluate(() => document.getElementById('enpv-split-submit')?.click());
    await page.waitForTimeout(500);
    return modalState(page);
}

async function setSplitName(page, value) {
    await page.evaluate((next) => {
        const input = document.getElementById('enpv-split-name-input');
        if (!input) return;
        input.value = next;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }, value);
}

async function clickSplitDestination(page, which) {
    await page.evaluate((id) => document.getElementById(id)?.click(), which === 'editor' ? 'enpv-split-name-open-editor' : 'enpv-split-name-download');
}

// ---------------------------------------------------------------------------
// Request capture
// ---------------------------------------------------------------------------

/**
 * Read a multipart body's fields. Both endpoints are posted as FormData with
 * files attached, so postDataJSON() is not available; the scalar fields and
 * the filenames are what these cases assert on. A field name that repeats
 * (page_indices[]) collects into an array in the order it was sent.
 */
function multipartFields(body) {
    const fields = {};
    const text = String(body || '');
    const re = /name="([^"]+)"(?:; filename="([^"]*)")?\r?\n(?:Content-Type:[^\r\n]*\r?\n)?\r?\n([\s\S]*?)\r?\n--/g;
    let match = re.exec(text);
    while (match) {
        const [, name, filename, value] = match;
        const entry = filename == null ? value : `<file:${filename}>`;
        if (name in fields) {
            fields[name] = Array.isArray(fields[name]) ? [...fields[name], entry] : [fields[name], entry];
        } else {
            fields[name] = entry;
        }
        match = re.exec(text);
    }
    return fields;
}

/**
 * Intercept one POST, capture its fields, and answer with a canned response
 * so the UI carries on without touching the real document.
 */
async function interceptPost(page, urlPattern, respondWith) {
    const captured = { fields: null, url: null, count: 0 };
    await page.route(urlPattern, async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        captured.count += 1;
        captured.url = route.request().url();
        captured.fields = multipartFields(route.request().postData());
        const body = typeof respondWith.body === 'function' ? respondWith.body(captured) : respondWith.body;
        await route.fulfill({
            status: respondWith.status || 200,
            contentType: 'application/json',
            body: JSON.stringify(body || {}),
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

/** Accept the next window.confirm(); Playwright dismisses dialogs by default. */
function acceptNextConfirm(page) {
    const seen = { message: '', count: 0 };
    page.once('dialog', async (dialog) => {
        seen.message = dialog.message();
        seen.count += 1;
        await dialog.accept();
    });
    return seen;
}

/** Save a Playwright download and hand back its bytes. */
async function saveDownload(download, filename) {
    ensureArtifactDir();
    const target = path.join(ARTIFACT_DIR, filename);
    await download.saveAs(target);
    try { fs.chmodSync(target, 0o666); } catch (_) { /* best effort */ }
    return { path: target, buffer: fs.readFileSync(target) };
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: the blank project loads with the Merge / Split tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const setup = await page.evaluate(() => {
        const button = document.getElementById('ftb-merge-pdf');
        const modal = document.getElementById('enpv-merge-modal');
        return {
            buttonPresent: !!button,
            buttonEnabled: !!button && !button.disabled,
            premiumLocked: !!button?.classList.contains('is-premium-locked'),
            buttonLabel: button?.getAttribute('aria-label') || '',
            buttonText: button?.textContent?.replace(/\s+/g, ' ').trim() || '',
            modalPresent: !!modal,
            modalHidden: modal ? modal.hidden : null,
            tabCount: document.querySelectorAll('.enpv-merge-split-tabs [role="tab"]').length,
            viewerExposed: !!window.__enpv?.pdfViewer,
            pageCount: Number(window.__enpv?.pdfViewer?.pagesCount || 0),
            canvasPainted: (() => {
                const canvas = document.querySelector('#viewer .page canvas');
                return !!canvas && canvas.width > 0;
            })(),
            mergeUrl: document.getElementById('enpv-root')?.dataset?.mergePdfUrl || '',
            splitUrl: document.getElementById('enpv-root')?.dataset?.splitPdfUrl || '',
        };
    });

    recorder.assert('page-rasterised', setup.canvasPainted, 'Page 1 rasterises');
    recorder.assert('viewer-exposed', setup.viewerExposed, 'The editor publishes its viewer for the suite to read');
    recorder.equals('one-page', setup.pageCount, 1, 'The blank project is one page');
    recorder.assert('button-present', setup.buttonPresent, 'The floating toolbar has the Merge / Split button');
    recorder.assert('button-enabled', setup.buttonEnabled, 'The button is enabled');
    recorder.equals('not-premium-locked', setup.premiumLocked, false, 'The tool is not premium-locked');
    recorder.assert('button-labelled', /merge or split/i.test(setup.buttonLabel), 'The button has an accessible name', setup.buttonLabel);
    recorder.assert('button-text', /Merge \/ Split/.test(setup.buttonText), 'The button reads Merge / Split', setup.buttonText);
    recorder.assert('modal-present', setup.modalPresent, 'The shared modal is in the DOM');
    recorder.equals('modal-closed', setup.modalHidden, true, 'It starts closed');
    recorder.equals('two-tabs', setup.tabCount, 2, 'It offers exactly two tabs');
    recorder.assert('endpoints-wired', /merge-pdfs$/.test(setup.mergeUrl) && /split-pdf$/.test(setup.splitUrl),
        'Both endpoints are wired on the root', `${setup.mergeUrl} ${setup.splitUrl}`);
    const noise = meaningfulConsoleErrors(consoleErrors);
    recorder.equals('console-clean', noise.length, 0, 'The console is clean on load', noise.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — The modal opens on Merge and closes via X, Cancel, scrim and Escape. */
async function testModalOpenClose(page, { recorder }) {
    const artifacts = [];

    const opened = await openModal(page);
    recorder.assert('opens', opened.open, 'The toolbar button opens the modal');
    recorder.equals('title', opened.title, 'Merge / Split PDFs', 'The modal is titled Merge / Split PDFs');
    recorder.equals('opens-on-merge', opened.activeTab, 'merge', 'A fresh session opens on the Merge tab');
    recorder.equals('merge-panel-shown', opened.mergePanelHidden, false, 'The Merge panel is on show');
    recorder.equals('split-panel-hidden', opened.splitPanelHidden, true, 'The Split panel is hidden');

    artifacts.push(await capture(page, '02-modal-open-close', 'open'));

    for (const how of ['close', 'cancel', 'scrim', 'escape']) {
        // eslint-disable-next-line no-await-in-loop
        await openModal(page);
        // eslint-disable-next-line no-await-in-loop
        const closed = await closeModal(page, how);
        recorder.assert(`closes-via-${how}`, !closed.open, `The modal closes via ${how}`);
    }

    await openModal(page);
    await page.evaluate(() => document.querySelector('.enpv-merge-card')?.dispatchEvent(
        new MouseEvent('click', { bubbles: true }),
    ));
    await page.waitForTimeout(300);
    recorder.assert('card-click-keeps-it-open', (await modalState(page)).open,
        'Clicking inside the card does not close the modal');

    const focus = await closeModal(page, 'close');
    recorder.equals('focus-returns-to-button', focus.focusedId, 'ftb-merge-pdf',
        'Closing returns focus to the Merge / Split button');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Tabs show one panel at a time; the last tab is remembered for the session. */
async function testTabSwitching(page, { recorder, docId }) {
    const artifacts = [];

    await openModal(page);
    const split = await setTab(page, 'split');
    recorder.equals('split-active', split.activeTab, 'split', 'Split becomes the active tab');
    recorder.equals('split-panel-shown', split.splitPanelHidden, false, 'The Split panel is on show');
    recorder.equals('merge-panel-hidden', split.mergePanelHidden, true, 'The Merge panel is hidden');
    recorder.equals('split-selected', split.splitTab.selected, 'true', 'aria-selected marks Split');
    recorder.equals('merge-unselected', split.mergeTab.selected, 'false', 'aria-selected clears Merge');
    recorder.equals('roving-tabindex', `${split.splitTab.tabIndex}/${split.mergeTab.tabIndex}`, '0/-1',
        'Only the active tab is in the tab order');
    recorder.assert('split-renders-cards', (await splitCards(page)).length >= 1, 'The Split tab renders page cards');
    recorder.assert('split-focuses-card', split.focusedIsSplitCard, 'Switching to Split focuses the first page card');

    artifacts.push(await capture(page, '03-tab-switching', 'split'));

    const merge = await setTab(page, 'merge');
    recorder.equals('back-to-merge', merge.activeTab, 'merge', 'Merge becomes active again');
    recorder.equals('merge-panel-back', merge.mergePanelHidden, false, 'The Merge panel returns');
    recorder.equals('split-panel-gone', merge.splitPanelHidden, true, 'The Split panel hides');
    recorder.equals('merge-focuses-dropzone', merge.focusedId, 'enpv-merge-dropzone',
        'Switching to Merge focuses the drop zone');

    // Session memory: leave on Split, close, reopen.
    await setTab(page, 'split');
    const stored = await page.evaluate(() => window.sessionStorage.getItem('pdf-editor-merge-split-tab'));
    recorder.equals('tab-stored', stored, 'split', 'The chosen tab is written to sessionStorage');
    await closeModal(page, 'close');
    const reopened = await openModal(page);
    recorder.equals('reopens-on-split', reopened.activeTab, 'split', 'Reopening comes back to the last tab used');
    await closeModal(page, 'close');

    // ...and survives a reload of the same tab.
    await openEditor(page, docId);
    const afterReload = await openModal(page);
    recorder.equals('survives-reload', afterReload.activeTab, 'split', 'The remembered tab survives a page reload');
    await closeModal(page, 'close');

    // A fresh browser session starts on Merge.
    const fresh = await page.context().browser().newContext({ viewport: { width: 1500, height: 1000 } });
    try {
        const freshPage = await fresh.newPage();
        await openEditor(freshPage, docId);
        const freshState = await openModal(freshPage);
        recorder.equals('fresh-session-on-merge', freshState.activeTab, 'merge', 'A fresh session opens on Merge');
    } finally {
        await fresh.close().catch(() => {});
    }

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — The current document card. */
async function testCurrentDocumentCard(page, { recorder }) {
    const artifacts = [];

    const opened = await openModal(page);
    const cards = await mergeCards(page);
    const name = await documentName(page);

    recorder.equals('one-card', cards.length, 1, 'A fresh Merge tab lists one document');
    recorder.equals('card-id-current', cards[0]?.id, 'current', 'It is the current document');
    recorder.equals('card-named-as-editor', cards[0]?.name, name, 'The card carries the document name the editor shows');
    recorder.assert('current-badge', cards[0]?.isCurrent, 'It is marked Current document');
    recorder.equals('no-remove', cards[0]?.hasRemove, false, 'The current document has no remove control');
    recorder.equals('single-card-cannot-move', `${cards[0]?.upDisabled}/${cards[0]?.downDisabled}`, 'true/true',
        'A lone card cannot move up or down');
    recorder.assert('page-count-shown', /\b1 page\b/.test(cards[0]?.meta || ''), 'The card states its page count', cards[0]?.meta);
    recorder.equals('summary', opened.mergeSummary, '1 PDF · 1 page', 'The summary counts one PDF and one page');
    recorder.equals('merge-disabled', opened.submitDisabled, true, 'Merge PDFs is disabled with only the current document');
    recorder.equals('status-asks-for-pdf', opened.mergeStatus.text, 'Add at least one PDF to merge.',
        'The status asks for at least one PDF');
    recorder.equals('status-not-error', opened.mergeStatus.isError, false, 'That is guidance, not an error');
    recorder.equals('aria-position', cards[0]?.ariaLabel, `${name}, position 1 of 1`, 'The card names its file and position');

    // Its thumbnail is prepared asynchronously after the modal opens.
    await page.waitForTimeout(1500);
    recorder.assert('current-thumbnail', (await mergeCards(page))[0]?.hasThumbnail, 'The current document gets a first-page thumbnail');

    artifacts.push(await capture(page, '04-current-document-card', 'merge-tab'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — Adding PDFs. */
async function testAddPdfs(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    const cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const state = await modalState(page);

    recorder.equals('three-cards', cards.length, 3, 'Two added PDFs make three cards');
    recorder.equals('current-stays-first', cards[0]?.id, 'current', 'The current document stays first');
    recorder.equals('names', cards.slice(1).map((card) => card.name).join(','), 'alpha.pdf,beta.pdf',
        'Added cards carry their filenames in selection order');
    recorder.assert('page-counts', /\b2 pages\b/.test(cards[1]?.meta || '') && /\b3 pages\b/.test(cards[2]?.meta || ''),
        'Each added card states its page count', `${cards[1]?.meta} | ${cards[2]?.meta}`);
    recorder.assert('sizes', /\d+(\.\d+)? (B|KB|MB)/.test(cards[1]?.meta || ''), 'Each added card states its file size', cards[1]?.meta);
    recorder.assert('thumbnails', cards.slice(1).every((card) => card.hasThumbnail), 'Each added card has a rendered thumbnail');
    recorder.assert('added-are-uploads', cards.slice(1).every((card) => card.id.startsWith('upload-')),
        'Added cards get opaque upload ids', cards.slice(1).map((card) => card.id).join(','));
    recorder.equals('summary', state.mergeSummary, '3 PDFs · 6 pages', 'The summary counts every document and page');
    recorder.equals('merge-enabled', state.submitDisabled, false, 'Merge PDFs enables once a PDF is added');
    recorder.equals('status', state.mergeStatus.text, '2 PDFs added.', 'The status confirms how many were added');
    recorder.equals('input-reset', state.fileInputValue, '', 'The file input is cleared so the same file can be chosen again');

    artifacts.push(await capture(page, '05-add-pdfs', 'added'));

    const more = await addMergeFiles(page, [pdfFile('gamma.pdf', 'gamma', 1)]);
    recorder.equals('appends', more.map((card) => card.name).join(','), `${cards[0].name},alpha.pdf,beta.pdf,gamma.pdf`,
        'A second selection appends without disturbing the order');
    recorder.equals('summary-after-append', (await modalState(page)).mergeSummary, '4 PDFs · 7 pages', 'The summary follows');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — Reordering whole documents. */
async function testReorder(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    let cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const order = () => cards.map((card) => card.name.replace(/\.pdf$/, '')).map((name) => (name === cards[0].name ? name : name));
    const ids = Object.fromEntries(cards.map((card) => [card.name, card.id]));
    const currentName = cards[0].name;

    recorder.equals('top-cannot-move-up', cards[0].upDisabled, true, 'The top card cannot move up');
    recorder.equals('top-can-move-down', cards[0].downDisabled, false, 'The top card can move down');
    recorder.equals('bottom-cannot-move-down', cards[2].downDisabled, true, 'The bottom card cannot move down');
    recorder.equals('middle-moves-both', `${cards[1].upDisabled}/${cards[1].downDisabled}`, 'false/false',
        'A middle card can move either way');
    recorder.assert('controls-named-for-file', await page.evaluate(() => !!document.querySelector(
        '#enpv-merge-list button[aria-label="Move beta.pdf up"]',
    )), 'Move controls are named for their file');

    cards = await moveCard(page, ids['beta.pdf'], 'up');
    recorder.equals('beta-moves-up', cards.map((card) => card.name).join(','), `${currentName},beta.pdf,alpha.pdf`,
        'Move up swaps the card with the one above');

    cards = await moveCard(page, 'current', 'down');
    recorder.equals('current-moves-down', cards.map((card) => card.name).join(','), `beta.pdf,${currentName},alpha.pdf`,
        'The current document moves like any other');
    recorder.assert('current-keeps-badge', cards[1].isCurrent, 'The current document keeps its badge when moved');
    recorder.equals('positions-announced', cards.map((card) => card.ariaLabel).join('|'),
        `beta.pdf, position 1 of 3|${currentName}, position 2 of 3|alpha.pdf, position 3 of 3`,
        'Every card announces its new position');

    cards = await moveCard(page, 'current', 'down');
    recorder.equals('current-to-bottom', cards[2].id, 'current', 'The current document can go last');
    recorder.equals('bottom-locks', cards[2].downDisabled, true, 'Its Move down disables at the bottom');

    // An end-stop press is a no-op rather than a wrap.
    cards = await moveCard(page, 'current', 'down');
    recorder.equals('no-wrap', cards[2].id, 'current', 'Move down at the bottom does nothing');
    void order;

    artifacts.push(await capture(page, '06-reorder', 'reordered'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Removing added PDFs. */
async function testRemove(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    let cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const alphaId = cards.find((card) => card.name === 'alpha.pdf')?.id;
    const betaId = cards.find((card) => card.name === 'beta.pdf')?.id;
    recorder.equals('remove-only-on-added', cards.filter((card) => card.hasRemove).length, 2,
        'Only the added cards carry a remove control');

    cards = await removeCard(page, alphaId);
    let state = await modalState(page);
    recorder.equals('alpha-removed', cards.map((card) => card.name).join(','), `${cards[0].name},beta.pdf`, 'Removing drops the card');
    recorder.equals('summary-follows', state.mergeSummary, '2 PDFs · 4 pages', 'The summary follows the removal');
    recorder.equals('still-mergeable', state.submitDisabled, false, 'Merge stays enabled with one PDF left');

    artifacts.push(await capture(page, '07-remove', 'one-removed'));

    cards = await removeCard(page, betaId);
    state = await modalState(page);
    recorder.equals('only-current-left', `${cards.length}:${cards[0]?.id}`, '1:current', 'Removing the last added PDF leaves the current document');
    recorder.equals('merge-disabled-again', state.submitDisabled, true, 'Merge PDFs disables again');
    recorder.equals('summary-back', state.mergeSummary, '1 PDF · 1 page', 'The summary is back to one PDF');

    cards = await removeCard(page, 'current');
    recorder.equals('current-cannot-be-removed', cards.length, 1, 'The current document cannot be removed');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — Invalid files are refused client-side. */
async function testInvalidFilesRefused(page, { recorder }) {
    const artifacts = [];

    await openModal(page);

    let cards = await addMergeFiles(page, [{ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') }]);
    let state = await modalState(page);
    recorder.equals('text-file-refused', state.mergeStatus.text, 'notes.txt is not a PDF.', 'A text file is refused by name');
    recorder.assert('text-file-is-error', state.mergeStatus.isError, 'The refusal is an error status');
    recorder.equals('text-file-no-card', cards.length, 1, 'No card is added for it');

    const oversize = Buffer.alloc(MERGE_LIMITS.maxFileBytes + 1, 0x20);
    Buffer.from('%PDF-1.4\n').copy(oversize);
    cards = await addMergeFiles(page, [{ name: 'big.pdf', mimeType: 'application/pdf', buffer: oversize }]);
    state = await modalState(page);
    recorder.equals('oversize-refused', state.mergeStatus.text, 'big.pdf exceeds the 20.0 MB file limit.',
        'A file over the limit is refused before it is parsed');
    recorder.equals('oversize-no-card', cards.length, 1, 'No card is added for it');

    cards = await addMergeFiles(page, [{ name: 'broken.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nthis is not a pdf') }]);
    state = await modalState(page);
    recorder.assert('corrupt-refused', state.mergeStatus.isError && /broken\.pdf|Invalid PDF|could not be opened/i.test(state.mergeStatus.text),
        'A file pdf.js cannot open is refused with a reason', state.mergeStatus.text);
    recorder.equals('corrupt-no-card', cards.length, 1, 'No card is added for it');

    cards = await addMergeFiles(page, [{ name: 'locked.pdf', mimeType: 'application/pdf', buffer: buildEncryptedPdfBuffer() }]);
    state = await modalState(page);
    recorder.equals('encrypted-refused', state.mergeStatus.text, 'locked.pdf is password protected.',
        'An encrypted PDF is refused as password protected');
    recorder.equals('encrypted-no-card', cards.length, 1, 'No card is added for it');
    recorder.equals('merge-still-disabled', state.submitDisabled, true, 'Nothing refused enables Merge');

    artifacts.push(await capture(page, '08-invalid-files-refused', 'refused'));

    // A good file still goes in afterwards.
    cards = await addMergeFiles(page, [pdfFile('fine.pdf', 'fine', 1)]);
    recorder.equals('good-file-still-accepted', cards.length, 2, 'A valid PDF is still accepted after refusals');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 09 — File-count and page limits. */
async function testLimits(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    const eleven = Array.from({ length: 11 }, (_, i) => pdfFile(`doc${i + 1}.pdf`, `doc${i + 1}`, 1));
    let cards = await addMergeFiles(page, eleven);
    let state = await modalState(page);
    recorder.equals('first-ten-added', cards.length, 1 + MERGE_LIMITS.maxFiles, 'Only the first ten of eleven files are added');
    recorder.equals('overflow-explained', state.mergeStatus.text, 'Only the first 10 files were added. The limit is 10.',
        'The overflow is explained');
    recorder.assert('overflow-is-error', state.mergeStatus.isError, 'It is an error status');

    cards = await addMergeFiles(page, [pdfFile('extra.pdf', 'extra', 1)]);
    state = await modalState(page);
    recorder.equals('full-refuses-more', cards.length, 1 + MERGE_LIMITS.maxFiles, 'A full list accepts nothing more');
    recorder.equals('full-explained', state.mergeStatus.text, 'You can add up to 10 PDFs.', 'The limit is stated');

    artifacts.push(await capture(page, '09-limits', 'file-limit'));

    await closeModal(page, 'close');
    await openModal(page);
    cards = await addMergeFiles(page, [pdfFile('huge.pdf', 'huge', MERGE_LIMITS.maxPages)]);
    state = await modalState(page);
    recorder.equals('huge-added', cards.length, 2, 'A 1000-page PDF itself is accepted');
    recorder.equals('page-limit-disables-merge', state.submitDisabled, true,
        'Merge disables when the total would pass 1000 pages');
    // Left as an assertion on purpose: today addMergeFiles() overwrites the
    // limit message that renderMergeItems() just set with "1 PDF added.", so
    // the user is left with a disabled Merge button and no reason (NK_26).
    // It turns green by itself once the status is not clobbered.
    recorder.equals('page-limit-explained', state.mergeStatus.text, 'The merged PDF would exceed the 1000-page limit.',
        'The page limit is explained rather than leaving a dead button');
    recorder.equals('summary-counts-it', state.mergeSummary, '2 PDFs · 1001 pages', 'The summary still counts the pages');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — The merge request contract, and the confirm. */
async function testMergeRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptPost(page, /\/merge-pdfs/, {
        status: 422,
        body: { success: false, message: 'QA suite: merge intentionally refused.' },
    });

    await openModal(page);
    let cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const betaId = cards.find((card) => card.name === 'beta.pdf').id;
    const alphaId = cards.find((card) => card.name === 'alpha.pdf').id;
    cards = await moveCard(page, betaId, 'up');
    cards = await moveCard(page, betaId, 'up');
    recorder.equals('order-set', cards.map((card) => card.id).join(','), `${betaId},current,${alphaId}`,
        'The setup puts an upload above the current document');

    const confirm = acceptNextConfirm(page);
    await clickMergeSubmit(page);
    const arrived = await waitForCapture(page, captured, 60000);
    recorder.equals('confirm-asked', confirm.count, 1, 'Merge asks for confirmation first');
    recorder.assert('confirm-wording', /merge these pdfs/i.test(confirm.message), 'The confirm says what it is about to do', confirm.message);
    recorder.assert('request-made', arrived, 'Accepting posts to the merge endpoint');

    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('alpha-attached', fields[`pdfs[${alphaId}]`], '<file:alpha.pdf>', 'alpha.pdf is attached under its upload id');
        recorder.equals('beta-attached', fields[`pdfs[${betaId}]`], '<file:beta.pdf>', 'beta.pdf is attached under its upload id');
        let order = null;
        try { order = JSON.parse(fields.order); } catch (_) { order = null; }
        recorder.equals('order-sent', JSON.stringify(order), JSON.stringify([betaId, 'current', alphaId]),
            'The order is posted exactly as the cards were arranged');
        recorder.assert('session-sent', String(fields.session_id || '').length > 0, 'The editor session id is sent');
        recorder.equals('posted-once', captured.count, 1, 'One click posts one merge');
    }

    artifacts.push(await capture(page, '10-merge-request', 'after-request'));

    // Declining the confirm sends nothing.
    await page.waitForTimeout(800);
    const before = captured.count;
    await clickMergeSubmit(page); // Playwright dismisses the dialog by default.
    await page.waitForTimeout(1500);
    recorder.equals('decline-sends-nothing', captured.count, before, 'Dismissing the confirm posts nothing');
    recorder.equals('decline-keeps-modal', (await modalState(page)).open, true, 'The modal stays open');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — A real merge, end to end. */
async function testRealMerge(page, { recorder, docId }) {
    const artifacts = [];
    const nameBefore = await documentName(page);

    await openModal(page);
    let cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const betaId = cards.find((card) => card.name === 'beta.pdf').id;
    cards = await moveCard(page, betaId, 'up');
    cards = await moveCard(page, betaId, 'up');
    recorder.equals('order-set', cards.map((card) => card.name).join(','), `beta.pdf,${nameBefore},alpha.pdf`,
        'The setup order is beta, current, alpha');

    acceptNextConfirm(page);
    await clickMergeSubmit(page);
    const merged = await waitForPageCount(page, 6, 120000);
    await page.waitForTimeout(1200);
    recorder.assert('page-count-is-six', merged, 'The editor reloads with all six pages', `${await editorPageCount(page)} pages`);
    recorder.equals('modal-closed', (await modalState(page)).open, false, 'The modal closes on success');
    // setStatus('PDFs merged.') fires before pdf.js finishes loading the new
    // document, whose own "Loaded N pages." then lands on the same line.
    recorder.assert('status', /^(PDFs merged\.|Loaded 6 pages\.)$/.test((await statusText(page)).text),
        'The status reports the merge, or the reload it triggered', (await statusText(page)).text);
    recorder.equals('same-document', page.url().includes(`/documents/${docId}/`), true, 'The document keeps its id');
    recorder.equals('same-name', await documentName(page), nameBefore, 'The document keeps its name');

    const texts = await editorPageTexts(page);
    const expectations = [
        [0, 'beta - page 1'], [1, 'beta - page 2'], [2, 'beta - page 3'],
        [3, 'page 1'], [4, 'alpha - page 1'], [5, 'alpha - page 2'],
    ];
    recorder.assert('pages-in-chosen-order', expectations.every(([index, needle]) => (texts[index] || '').includes(needle)),
        'Every page carries the text of the document it came from, in the chosen order', JSON.stringify(texts));
    recorder.assert('current-in-the-middle', /test 11 - page 1/.test(texts[3] || ''),
        'The current document sits where it was placed', texts[3]);

    artifacts.push(await capture(page, '11-real-merge', 'merged'));

    const reopened = await openModal(page);
    const after = await mergeCards(page);
    recorder.equals('merge-tab-counts-merged-pages', reopened.mergeSummary, '1 PDF · 6 pages', 'The Merge tab now counts the merged pages');
    recorder.assert('current-card-six-pages', /\b6 pages\b/.test(after[0]?.meta || ''), 'The current card states six pages', after[0]?.meta);

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — A failed merge. */
async function testMergeFailure(page, { recorder }) {
    const artifacts = [];

    let status = 422;
    let message = 'QA suite: merge intentionally refused.';
    await page.route(/\/merge-pdfs/, (route) => route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message }),
    }));

    await openModal(page);
    let cards = await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2), pdfFile('beta.pdf', 'beta', 3)]);
    const betaId = cards.find((card) => card.name === 'beta.pdf').id;
    cards = await moveCard(page, betaId, 'up');
    const orderBefore = cards.map((card) => card.id).join(',');

    acceptNextConfirm(page);
    await clickMergeSubmit(page);
    await page.waitForTimeout(3000);

    let state = await modalState(page);
    recorder.equals('modal-stays-open', state.open, true, 'The modal stays open after a refusal');
    recorder.equals('order-intact', (await mergeCards(page)).map((card) => card.id).join(','), orderBefore, 'The chosen order is intact');
    recorder.equals('message-shown', state.mergeStatus.text, message, 'The server message is shown');
    recorder.assert('message-is-error', state.mergeStatus.isError, 'It is styled as an error');
    recorder.equals('submit-usable', state.submitDisabled, false, 'Merge can be tried again');
    recorder.equals('close-usable', state.closeDisabled, false, 'The modal can be closed');
    recorder.equals('not-busy', state.busy, false, 'The busy state is cleared');
    const editorStatus = await statusText(page);
    recorder.equals('editor-status', editorStatus.text, 'PDF merge failed.', 'The editor status line reports the failure');
    recorder.assert('editor-status-error', editorStatus.isError, 'It is flagged as an error');

    artifacts.push(await capture(page, '12-merge-failure', 'refused'));

    status = 409;
    message = 'Another page operation is already running. Please try again.';
    acceptNextConfirm(page);
    await clickMergeSubmit(page);
    await page.waitForTimeout(3000);
    state = await modalState(page);
    recorder.equals('conflict-shown', state.mergeStatus.text, message, 'A 409 conflict is shown the same way');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — Busy state during a merge. */
async function testMergeBusy(page, { recorder }) {
    const artifacts = [];

    let release = null;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route(/\/merge-pdfs/, async (route) => {
        await held;
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, message: 'QA suite: released.' }),
        });
    });

    await openModal(page);
    await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 2)]);
    acceptNextConfirm(page);
    await clickMergeSubmit(page);
    await page.waitForTimeout(2500);

    const busy = await modalState(page);
    const cards = await mergeCards(page);
    recorder.equals('busy-class', busy.busy, true, 'The modal is marked busy');
    recorder.equals('status-merging', busy.mergeStatus.text, 'Merging PDFs...', 'The status says it is merging');
    recorder.equals('close-disabled', busy.closeDisabled, true, 'Close is disabled');
    recorder.equals('cancel-disabled', busy.cancelDisabled, true, 'Cancel is disabled');
    recorder.equals('dropzone-disabled', busy.dropzoneDisabled, true, 'The drop zone is disabled');
    recorder.equals('tabs-disabled', `${busy.mergeTab.disabled}/${busy.splitTab.disabled}`, 'true/true', 'Both tabs are disabled');
    recorder.equals('submit-disabled', busy.submitDisabled, true, 'Merge PDFs is disabled');
    recorder.assert('card-controls-disabled', cards.every((card) => card.upDisabled && card.downDisabled && (card.removeDisabled ?? true)),
        'Every card control is disabled');
    recorder.assert('cards-not-draggable', cards.every((card) => card.draggable === false), 'Cards are not draggable');

    artifacts.push(await capture(page, '13-merge-busy', 'busy'));

    release();
    await page.waitForTimeout(2500);
    const settled = await modalState(page);
    recorder.equals('settles', settled.busy, false, 'The busy state clears when the request settles');
    recorder.equals('close-re-enabled', settled.closeDisabled, false, 'Close comes back');
    recorder.equals('submit-re-enabled', settled.submitDisabled, false, 'Merge PDFs comes back');
    recorder.assert('card-controls-re-enabled', (await mergeCards(page)).some((card) => card.removeDisabled === false),
        'Card controls come back');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — The Split tab's page grid. */
async function testSplitPageGrid(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    const state = await setTab(page, 'split');
    await page.waitForTimeout(1500);
    const cards = await splitCards(page);

    recorder.equals('one-card-per-page', cards.length, 4, 'There is one card per page of the current PDF');
    recorder.equals('labels', cards.map((card) => card.label).join(','), 'Page 1,Page 2,Page 3,Page 4', 'Cards are labelled Page N');
    recorder.equals('checkbox-names', cards.map((card) => card.checkboxLabel).join(','),
        'Include page 1,Include page 2,Include page 3,Include page 4', 'Each checkbox is named Include page N');
    recorder.assert('cards-are-buttons', cards.every((card) => card.role === 'button' && card.tabIndex === 0), 'Each card is a focusable button');
    recorder.assert('nothing-selected', cards.every((card) => !card.selected && !card.checked && card.pressed === 'false'),
        'Nothing starts selected');
    recorder.assert('thumbnails', cards.every((card) => card.hasCanvas) && cards.some((card) => card.canvasDrawn),
        'Each card has a thumbnail canvas and the visible ones are drawn');
    recorder.equals('summary-zero', state.splitSummary, '0 pages selected', 'The summary starts at zero');
    recorder.equals('submit-disabled', state.splitSubmitDisabled, true, 'Split selected pages is disabled at zero');
    recorder.equals('clear-disabled', state.clearDisabled, true, 'Clear selection is disabled at zero');
    recorder.equals('select-all-enabled', state.selectAllDisabled, false, 'Select all is available');

    artifacts.push(await capture(page, '14-split-page-grid', 'grid'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 15 — Toggling pages. */
async function testSplitToggles(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    await setTab(page, 'split');

    let cards = await clickSplitCard(page, 0);
    let state = await modalState(page);
    recorder.assert('click-selects', cards[0].selected && cards[0].checked && cards[0].pressed === 'true',
        'Clicking a card selects it, ticks its checkbox and sets aria-pressed');
    recorder.equals('summary-singular', state.splitSummary, '1 page selected', 'The summary is singular for one page');
    recorder.equals('submit-enabled', state.splitSubmitDisabled, false, 'The submit enables with the first selection');
    recorder.equals('clear-enabled', state.clearDisabled, false, 'Clear enables with the first selection');

    cards = await clickSplitCheckbox(page, 1);
    state = await modalState(page);
    recorder.assert('checkbox-toggles-once', cards[1].selected && cards[1].checked,
        'Clicking the checkbox itself toggles the page exactly once');
    recorder.equals('summary-plural', state.splitSummary, '2 pages selected', 'The summary is plural for two');

    cards = await keyToggleSplitCard(page, 2, 'Space');
    recorder.assert('space-toggles', cards[2].selected, 'Space toggles a focused card');
    cards = await keyToggleSplitCard(page, 2, 'Enter');
    recorder.assert('enter-toggles', !cards[2].selected, 'Enter toggles it back');
    recorder.equals('summary-after-keys', (await modalState(page)).splitSummary, '2 pages selected', 'The summary tracks keyboard toggles');

    artifacts.push(await capture(page, '15-split-toggles', 'two-selected'));

    cards = await clickSplitCard(page, 0);
    cards = await clickSplitCheckbox(page, 1);
    state = await modalState(page);
    recorder.assert('deselects', cards.every((card) => !card.selected), 'Clicking again deselects');
    recorder.equals('summary-back-to-zero', state.splitSummary, '0 pages selected', 'The summary returns to zero');
    recorder.equals('submit-disabled-again', state.splitSubmitDisabled, true, 'The submit disables again');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 16 — Select all and Clear selection. */
async function testSelectAllClear(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    await setTab(page, 'split');
    await page.evaluate(() => document.getElementById('enpv-split-select-all')?.click());
    await page.waitForTimeout(300);
    let cards = await splitCards(page);
    let state = await modalState(page);
    recorder.assert('all-selected', cards.length === 4 && cards.every((card) => card.selected && card.checked), 'Select all ticks every page');
    recorder.equals('summary-all', state.splitSummary, '4 pages selected', 'The summary counts them all');
    recorder.equals('select-all-disables-itself', state.selectAllDisabled, true, 'Select all disables once everything is selected');
    recorder.equals('clear-enabled', state.clearDisabled, false, 'Clear is available');
    recorder.equals('submit-enabled', state.splitSubmitDisabled, false, 'Every page is a valid split');

    const dialog = await openNameDialog(page);
    recorder.equals('all-pages-can-split', dialog.nameDialogOpen, true, 'Selecting every page still opens the filename dialog');
    await page.evaluate(() => document.getElementById('enpv-split-name-cancel')?.click());
    await page.waitForTimeout(300);

    artifacts.push(await capture(page, '16-select-all-clear', 'all-selected'));

    await page.evaluate(() => document.getElementById('enpv-split-clear')?.click());
    await page.waitForTimeout(300);
    cards = await splitCards(page);
    state = await modalState(page);
    recorder.assert('cleared', cards.every((card) => !card.selected && !card.checked), 'Clear empties the selection');
    recorder.equals('summary-zero', state.splitSummary, '0 pages selected', 'The summary returns to zero');
    recorder.equals('clear-disables-itself', state.clearDisabled, true, 'Clear disables once empty');
    recorder.equals('select-all-back', state.selectAllDisabled, false, 'Select all comes back');
    recorder.equals('submit-disabled', state.splitSubmitDisabled, true, 'The submit disables');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 17 — Selection lifecycle across tabs and reopening. */
async function testSelectionLifecycle(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    await setTab(page, 'split');
    await clickSplitCard(page, 0);
    await clickSplitCard(page, 2);
    recorder.equals('two-selected', (await modalState(page)).splitSummary, '2 pages selected', 'The setup selects two pages');

    await setTab(page, 'merge');
    await setTab(page, 'split');
    const back = await splitCards(page);
    recorder.equals('survives-tab-switch', back.filter((card) => card.selected).map((card) => card.index).join(','), '0,2',
        'The selection survives a trip to the Merge tab and back');
    recorder.equals('summary-survives', (await modalState(page)).splitSummary, '2 pages selected', 'So does the summary');

    artifacts.push(await capture(page, '17-selection-lifecycle', 'after-tab-switch'));

    await closeModal(page, 'split-cancel');
    const reopened = await openModal(page);
    recorder.equals('reopens-on-split', reopened.activeTab, 'split', 'Reopening comes back to the Split tab');
    recorder.equals('selection-cleared', reopened.splitSummary, '0 pages selected', 'Closing and reopening starts a new selection');
    recorder.assert('cards-cleared', (await splitCards(page)).every((card) => !card.selected), 'No card is selected');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 18 — The filename dialog. */
async function testFilenameDialog(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptPost(page, /\/split-pdf/, {
        status: 422,
        body: { success: false, message: 'QA suite: split intentionally refused.' },
    });

    await openModal(page);
    await setTab(page, 'split');
    let state = await openNameDialog(page);
    recorder.equals('needs-a-selection', state.nameDialogOpen, false, 'The dialog does not open without a selection');

    await clickSplitCard(page, 0);
    state = await openNameDialog(page);
    const docBase = (await documentName(page)).replace(/\.pdf$/i, '');
    recorder.equals('opens', state.nameDialogOpen, true, 'The dialog opens with a selection');
    recorder.equals('default-name', state.nameValue, `${docBase}_split.pdf`, 'It defaults to <document>_split.pdf');
    recorder.equals('name-focused', state.focusedId, 'enpv-split-name-input', 'The filename field takes focus');
    recorder.assert('name-preselected', await page.evaluate(() => {
        const input = document.getElementById('enpv-split-name-input');
        return !!input && input.selectionStart === 0 && input.selectionEnd === input.value.length;
    }), 'The default name is selected for overtyping');

    artifacts.push(await capture(page, '18-filename-dialog', 'open'));

    await setSplitName(page, '   ');
    await clickSplitDestination(page, 'download');
    await page.waitForTimeout(600);
    state = await modalState(page);
    recorder.equals('empty-refused', state.nameStatus.text, 'Enter a valid PDF filename.', 'An empty name is refused');
    recorder.assert('empty-is-error', state.nameStatus.isError, 'It is an error status');
    recorder.equals('empty-posts-nothing', captured.count, 0, 'Nothing is posted for an empty name');
    recorder.equals('dialog-stays', state.nameDialogOpen, true, 'The dialog stays open');

    await setSplitName(page, 'my:report?.txt');
    await clickSplitDestination(page, 'download');
    await waitForCapture(page, captured, 60000);
    await page.waitForTimeout(800);
    recorder.equals('normalised-name-sent', captured.fields?.output_name, 'my_report_.txt.pdf',
        'Illegal characters become underscores and .pdf is appended before sending');
    recorder.equals('normalised-name-shown', (await modalState(page)).nameValue, 'my_report_.txt.pdf', 'The field shows the normalised name');

    await page.evaluate(() => document.getElementById('enpv-split-name-cancel')?.click());
    await page.waitForTimeout(300);
    state = await modalState(page);
    recorder.equals('cancel-closes-dialog', state.nameDialogOpen, false, 'Cancel closes the dialog');
    recorder.equals('cancel-returns-focus', state.focusedId, 'enpv-split-submit', 'Focus returns to the Split button');
    recorder.equals('modal-still-open', state.open, true, 'The modal itself stays open');

    await openNameDialog(page);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    state = await modalState(page);
    recorder.equals('escape-closes-dialog-first', `${state.nameDialogOpen}/${state.open}`, 'false/true',
        'Escape closes the dialog before the modal');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    recorder.equals('second-escape-closes-modal', (await modalState(page)).open, false, 'A second Escape closes the modal');

    await openModal(page);
    await clickSplitCard(page, 0);
    await openNameDialog(page);
    await page.evaluate(() => document.getElementById('enpv-split-name-dialog')?.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    await page.waitForTimeout(300);
    state = await modalState(page);
    recorder.equals('dialog-scrim-closes-dialog-only', `${state.nameDialogOpen}/${state.open}`, 'false/true',
        "Clicking the dialog's own scrim closes only the dialog");

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 19 — The split request contract, for both destinations. */
async function testSplitRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptPost(page, /\/split-pdf/, {
        status: 200,
        body: (seen) => (String(seen.fields?.output_action) === 'editor'
            ? { success: true, output_action: 'editor', edit_url: `${BASE_URL}/pdf-editor` }
            : { success: true, output_action: 'download', download_token: 'qa-token' }),
    });
    // The popup is sent to the download URL; answer it so nothing hangs.
    await page.context().route(/\/documents\/download-converted/, (route) => route.fulfill({
        status: 200, contentType: 'text/plain', body: 'QA suite: download stub.',
    }));

    await openModal(page);
    await setTab(page, 'split');
    await clickSplitCard(page, 3);
    await clickSplitCard(page, 1);
    await clickSplitCard(page, 4);
    await openNameDialog(page);
    await setSplitName(page, 'contract.pdf');
    await clickSplitDestination(page, 'download');
    const arrived = await waitForCapture(page, captured, 90000);
    recorder.assert('request-made', arrived, 'Split & download posts to the split endpoint');

    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('mode', fields.mode, 'selected', 'The mode is selected');
        recorder.equals('destination-download', fields.output_action, 'download', 'The destination is download');
        recorder.equals('pages-ascending', JSON.stringify(fields['page_indices[]']), JSON.stringify(['1', '3', '4']),
            'Pages ticked as 4, 2, 5 are posted as indexes 1, 3, 4 in ascending order');
        recorder.equals('name', fields.output_name, 'contract.pdf', 'The filename is sent');
        recorder.assert('session', String(fields.session_id || '').length > 0, 'The session id is sent');
        recorder.equals('pdf-attached', fields.pdf, '<file:current-edited.pdf>', 'The edited PDF is attached');
        recorder.equals('posted-once', captured.count, 1, 'One click posts one split');
    }
    await page.waitForTimeout(1200);
    recorder.equals('modal-closes-on-success', (await modalState(page)).open, false, 'A successful split closes the modal');

    artifacts.push(await capture(page, '19-split-request', 'after-download-request'));

    // Close any popup the download left behind before the second destination.
    for (const extra of page.context().pages()) {
        if (extra !== page) await extra.close().catch(() => {});
    }

    await openModal(page);
    await clickSplitCard(page, 0);
    await openNameDialog(page);
    await clickSplitDestination(page, 'editor');
    await page.waitForTimeout(3000);
    recorder.equals('editor-destination', captured.fields?.output_action, 'editor', 'Open in editor posts output_action=editor');
    recorder.equals('editor-pages', JSON.stringify(captured.fields?.['page_indices[]']), JSON.stringify('0'),
        'A single page is posted as its index');
    const popupWentToEditor = page.context().pages().some((extra) => extra !== page && /\/pdf-editor/.test(extra.url()));
    recorder.assert('popup-navigates', popupWentToEditor, 'The popup is sent to the returned editor URL');
    for (const extra of page.context().pages()) {
        if (extra !== page) await extra.close().catch(() => {});
    }

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 20 — A real split, downloaded. */
async function testRealSplitDownload(page, { recorder, docId }) {
    const artifacts = [];

    await openModal(page);
    await setTab(page, 'split');
    await clickSplitCard(page, 3);
    await clickSplitCard(page, 1);
    await openNameDialog(page);
    await setSplitName(page, 'qa split.pdf');

    const popupPromise = page.context().waitForEvent('page', { timeout: 30000 });
    await clickSplitDestination(page, 'download');
    const popup = await popupPromise;
    const download = await popup.waitForEvent('download', { timeout: 120000 });
    const { buffer } = await saveDownload(download, `20-real-split-${process.pid}.pdf`);
    await page.waitForTimeout(800);

    recorder.equals('suggested-filename', download.suggestedFilename(), 'qa split.pdf', 'The download is named as typed');
    recorder.assert('is-a-pdf', buffer.subarray(0, 5).toString('latin1') === '%PDF-', 'The download is a PDF');
    recorder.equals('two-pages', pdfPageCount(buffer), 2, 'It holds exactly the two selected pages');
    const strings = pdfTextStrings(buffer);
    if (strings.length) {
        recorder.equals('document-order', strings.map((text) => text.replace(/^.*- /, '')).join(','), 'page 2,page 4',
            'The pages are in document order, not the order they were ticked');
    } else {
        recorder.skip('document-order', 'Page order could not be read from the content streams');
    }
    recorder.equals('status', (await statusText(page)).text, '2 pages split into qa split.pdf.', 'The editor reports the split');
    recorder.equals('modal-closed', (await modalState(page)).open, false, 'The modal closes');
    recorder.equals('source-untouched', await editorPageCount(page), 4, 'The open document still has all four pages');

    artifacts.push(await capture(page, '20-real-split-download', 'after-download'));

    await popup.close().catch(() => {});
    await openEditor(page, docId);
    recorder.equals('source-untouched-after-reload', await editorPageCount(page), 4, 'It still has four pages after a reload');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 21 — A real split, opened in the editor. */
async function testRealSplitOpenInEditor(page, { recorder, docId }) {
    const artifacts = [];

    await openModal(page);
    await setTab(page, 'split');
    await clickSplitCard(page, 0);
    await clickSplitCard(page, 2);
    await openNameDialog(page);
    await setSplitName(page, 'part.pdf');

    const popupPromise = page.context().waitForEvent('page', { timeout: 30000 });
    await clickSplitDestination(page, 'editor');
    const popup = await popupPromise;
    await popup.waitForURL(/\/documents\/\d+\/edit-new\?pdfjs=1/, { timeout: 120000 }).catch(() => null);
    const popupUrl = popup.url();
    const match = popupUrl.match(/\/documents\/(\d+)\//);
    const newId = match ? Number(match[1]) : null;
    recorder.assert('opens-a-document', !!newId, 'The popup opens an editor document', popupUrl);
    recorder.assert('new-id', !!newId && newId !== docId, 'It is a new document, not the source', `${newId} vs ${docId}`);

    if (newId) {
        await popup.waitForSelector('#viewer .page canvas', { timeout: 60000 }).catch(() => null);
        await popup.waitForFunction(() => !!window.__enpv?.pdfViewer?.pagesCount, { timeout: 60000 }).catch(() => null);
        await popup.waitForTimeout(800);
        recorder.equals('new-document-pages', await editorPageCount(popup), 2, 'The new document holds the two selected pages');
        recorder.equals('new-document-name', await documentName(popup), 'part.pdf', 'It is named as typed');
        const texts = await editorPageTexts(popup);
        recorder.assert('new-document-order', /page 1$/.test(texts[0] || '') && /page 3$/.test(texts[1] || ''),
            'Its pages are the selected pages in document order', JSON.stringify(texts));
        artifacts.push(await capture(popup, '21-real-split-open-in-editor', 'new-document'));
    }

    await page.waitForTimeout(800);
    recorder.equals('status', (await statusText(page)).text, 'part.pdf opened in the editor.', 'The source editor reports the split');
    recorder.equals('source-untouched', await editorPageCount(page), 4, 'The source keeps its four pages');
    recorder.equals('source-same-id', page.url().includes(`/documents/${docId}/`), true, 'The source keeps its id');

    await popup.close().catch(() => {});
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 22 — Busy state and failure during a split. */
async function testSplitBusyAndFailure(page, { recorder }) {
    const artifacts = [];

    let release = null;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route(/\/split-pdf/, async (route) => {
        await held;
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, message: 'QA suite: split intentionally refused.' }),
        });
    });

    await openModal(page);
    await setTab(page, 'split');
    await clickSplitCard(page, 0);
    await openNameDialog(page);
    await clickSplitDestination(page, 'download');
    await page.waitForTimeout(4000);

    const busy = await modalState(page);
    const cards = await splitCards(page);
    recorder.equals('busy-class', busy.busy, true, 'The modal is marked busy');
    recorder.assert('status-says-what', /Preparing selected pages|Saving current edits/.test(busy.nameStatus.text),
        'The dialog says what it is doing', busy.nameStatus.text);
    recorder.equals('name-disabled', busy.nameDisabled, true, 'The filename field is disabled');
    recorder.equals('buttons-disabled', `${busy.nameCancelDisabled}/${busy.nameDownloadDisabled}/${busy.nameOpenDisabled}`, 'true/true/true',
        'Cancel, download and open are disabled');
    recorder.equals('close-disabled', busy.closeDisabled, true, 'The modal close is disabled');
    recorder.equals('tabs-disabled', `${busy.mergeTab.disabled}/${busy.splitTab.disabled}`, 'true/true', 'Both tabs are disabled');
    recorder.assert('cards-disabled', cards.every((card) => card.checkboxDisabled && card.tabIndex === -1),
        'Every page card is disabled and out of the tab order');
    recorder.assert('popup-opened', page.context().pages().length >= 2, 'A preparing popup is open');

    artifacts.push(await capture(page, '22-split-busy-and-failure', 'busy'));

    release();
    await page.waitForTimeout(3000);
    const failed = await modalState(page);
    recorder.equals('dialog-stays-open', failed.nameDialogOpen, true, 'The dialog stays open for another try');
    recorder.equals('message-in-dialog', failed.nameStatus.text, 'QA suite: split intentionally refused.', 'The server message is shown in the dialog');
    recorder.assert('message-is-error', failed.nameStatus.isError, 'It is styled as an error');
    recorder.equals('message-in-tab', failed.splitStatus.text, 'QA suite: split intentionally refused.', 'It is shown on the tab as well');
    recorder.equals('not-busy', failed.busy, false, 'The busy state clears');
    recorder.equals('controls-back', `${failed.nameDisabled}/${failed.nameDownloadDisabled}/${failed.closeDisabled}`, 'false/false/false',
        'The controls come back');
    recorder.equals('name-refocused', failed.focusedId, 'enpv-split-name-input', 'Focus returns to the filename');
    const editorStatus = await statusText(page);
    recorder.equals('editor-status', editorStatus.text, 'PDF split failed.', 'The editor status line reports the failure');
    recorder.assert('editor-status-error', editorStatus.isError, 'It is flagged as an error');
    recorder.equals('popup-closed', page.context().pages().length, 1, 'The preparing popup is closed on failure');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 23 — Keyboard access and ARIA. */
async function testKeyboardAndAria(page, { recorder }) {
    const artifacts = [];

    await openModal(page);
    await addMergeFiles(page, [pdfFile('alpha.pdf', 'alpha', 1)]);

    const merge = await page.evaluate(() => {
        const card = document.querySelector('#enpv-merge-modal .enpv-merge-card');
        const tablist = document.querySelector('.enpv-merge-split-tabs');
        const tabs = Array.from(document.querySelectorAll('.enpv-merge-split-tabs [role="tab"]'));
        const panels = ['enpv-merge-panel', 'enpv-split-panel'].map((id) => document.getElementById(id));
        const live = (id) => {
            const el = document.getElementById(id);
            return `${el?.getAttribute('role')}/${el?.getAttribute('aria-live')}`;
        };
        return {
            dialogRole: card?.getAttribute('role'),
            modal: card?.getAttribute('aria-modal'),
            labelledBy: card?.getAttribute('aria-labelledby'),
            describedBy: card?.getAttribute('aria-describedby'),
            tablistRole: tablist?.getAttribute('role'),
            tablistLabel: tablist?.getAttribute('aria-label') || '',
            tabsControlPanels: tabs.every((tab) => !!document.getElementById(tab.getAttribute('aria-controls') || '')),
            panelsAreTabpanels: panels.every((panel) => panel?.getAttribute('role') === 'tabpanel'),
            panelsLabelledByTabs: panels.every((panel) => !!document.getElementById(panel?.getAttribute('aria-labelledby') || '')),
            inactiveHidden: panels.filter((panel) => panel.hidden).length === 1,
            closeLabel: document.getElementById('enpv-merge-close')?.getAttribute('aria-label') || '',
            mergeStatusLive: live('enpv-merge-status'),
            splitStatusLive: live('enpv-split-status'),
            nameStatusLive: live('enpv-split-name-status'),
            mergeListLabel: document.getElementById('enpv-merge-list')?.getAttribute('aria-label') || '',
            splitGridLabel: document.getElementById('enpv-split-grid')?.getAttribute('aria-label') || '',
            cardLabels: Array.from(document.querySelectorAll('#enpv-merge-list .enpv-merge-item')).map((row) => row.getAttribute('aria-label')),
            gripLabelled: Array.from(document.querySelectorAll('#enpv-merge-list .enpv-merge-grip')).every((grip) => !!grip.getAttribute('aria-label')),
            nameDialogRole: document.querySelector('#enpv-split-name-dialog [role="dialog"]')?.getAttribute('aria-modal'),
            nameDialogLabelledBy: document.querySelector('#enpv-split-name-dialog [role="dialog"]')?.getAttribute('aria-labelledby'),
            nameLabelFor: document.querySelector('label[for="enpv-split-name-input"]') !== null,
        };
    });

    recorder.equals('dialog-role', merge.dialogRole, 'dialog', 'The card is a dialog');
    recorder.equals('aria-modal', merge.modal, 'true', 'It is marked modal');
    recorder.equals('labelled-by-title', merge.labelledBy, 'enpv-merge-title', 'It is labelled by its title');
    recorder.equals('described-by-subtitle', merge.describedBy, 'enpv-merge-subtitle', 'It is described by its subtitle');
    recorder.equals('tablist-role', merge.tablistRole, 'tablist', 'The tabs are a tablist');
    recorder.assert('tablist-labelled', merge.tablistLabel.length > 0, 'The tablist has a name', merge.tablistLabel);
    recorder.assert('tabs-control-panels', merge.tabsControlPanels, 'Each tab names a panel that exists');
    recorder.assert('panels-are-tabpanels', merge.panelsAreTabpanels, 'Each panel is a tabpanel');
    recorder.assert('panels-labelled', merge.panelsLabelledByTabs, 'Each panel is labelled by its tab');
    recorder.assert('inactive-panel-hidden', merge.inactiveHidden, 'Exactly one panel is hidden');
    recorder.assert('close-labelled', merge.closeLabel.length > 0, 'The close button has a name', merge.closeLabel);
    recorder.equals('merge-status-live', merge.mergeStatusLive, 'status/polite', 'The merge status is a polite live region');
    recorder.equals('split-status-live', merge.splitStatusLive, 'status/polite', 'The split status is a polite live region');
    recorder.equals('name-status-live', merge.nameStatusLive, 'status/polite', 'The filename status is a polite live region');
    recorder.assert('lists-labelled', merge.mergeListLabel.length > 0 && merge.splitGridLabel.length > 0,
        'The document list and the page grid have names', `${merge.mergeListLabel} | ${merge.splitGridLabel}`);
    recorder.assert('cards-state-position', merge.cardLabels.every((label, index) => new RegExp(`, position ${index + 1} of ${merge.cardLabels.length}$`).test(label || '')),
        'Each merge card states its position', merge.cardLabels.join(' | '));
    recorder.assert('grips-labelled', merge.gripLabelled, 'Each drag grip has a name');
    recorder.equals('name-dialog-modal', merge.nameDialogRole, 'true', 'The filename dialog is its own modal dialog');
    recorder.equals('name-dialog-labelled', merge.nameDialogLabelledBy, 'enpv-split-name-title', 'It is labelled by its title');
    recorder.assert('name-input-labelled', merge.nameLabelFor, 'The filename field has a label');

    // Tabs are real buttons: they take focus and activate from the keyboard.
    await page.evaluate(() => document.getElementById('enpv-split-tab')?.focus());
    const focusedTab = await page.evaluate(() => document.activeElement?.id);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(500);
    recorder.equals('tab-takes-focus', focusedTab, 'enpv-split-tab', 'A tab can be focused from the keyboard');
    recorder.equals('tab-activates', (await modalState(page)).activeTab, 'split', 'Enter on a focused tab switches to it');

    const split = await page.evaluate(() => {
        const cards = Array.from(document.querySelectorAll('#enpv-split-grid .enpv-split-page'));
        return {
            pressable: cards.every((card) => card.getAttribute('role') === 'button' && card.hasAttribute('aria-pressed') && card.tabIndex === 0),
            named: cards.every((card, index) => card.getAttribute('aria-label') === `Include page ${index + 1}`
                && card.querySelector('input[type="checkbox"]')?.getAttribute('aria-label') === `Include page ${index + 1}`),
            thumbsHidden: cards.every((card) => card.querySelector('canvas')?.getAttribute('aria-hidden') === 'true'),
        };
    });
    recorder.assert('page-cards-pressable', split.pressable, 'Each page card is a pressable, focusable button');
    recorder.assert('page-cards-named', split.named, 'Each card and its checkbox are named Include page N');
    recorder.assert('thumbnails-decorative', split.thumbsHidden, 'Thumbnails are hidden from assistive technology');

    artifacts.push(await capture(page, '23-keyboard-and-aria', 'split-tab'));

    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    recorder.equals('escape-closes', (await modalState(page)).open, false, 'Escape closes the modal');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Merge / Split tool available', run: testBlankProjectLoads },
    { id: '02-modal-open-close', number: '02', title: 'The modal opens on the Merge tab and closes via X, Cancel, scrim and Escape', run: testModalOpenClose },
    { id: '03-tab-switching', number: '03', title: 'Switching tabs shows one panel at a time, and the last tab is remembered for the session', run: testTabSwitching },
    { id: '04-current-document-card', number: '04', title: 'Merge: the current document is listed first, marked as current, and cannot be removed', run: testCurrentDocumentCard },
    { id: '05-add-pdfs', number: '05', title: 'Merge: adding PDFs appends cards with filename, size, page count and thumbnail, and enables Merge', run: testAddPdfs },
    { id: '06-reorder', number: '06', title: 'Merge: whole documents reorder with Move up / Move down, and the ends are disabled', run: testReorder },
    { id: '07-remove', number: '07', title: 'Merge: an added PDF can be removed, and the summary and Merge button follow', run: testRemove },
    { id: '08-invalid-files-refused', number: '08', title: 'Merge: a non-PDF, an oversize file and a password-protected file are refused before upload', run: testInvalidFilesRefused },
    { id: '09-limits', number: '09', title: 'Merge: the file-count and total-page limits are enforced', run: testLimits },
    { id: '10-merge-request', number: '10', title: 'Merge: the request carries every upload and the displayed order, and declining the confirm sends nothing', run: testMergeRequest },
    { id: '11-real-merge', number: '11', title: 'Merge: a real merge reloads the document with every page in the chosen order, keeping its id and name', run: testRealMerge },
    { id: '12-merge-failure', number: '12', title: "Merge: a failed merge keeps the modal and its order intact and shows the server's message", run: testMergeFailure },
    { id: '13-merge-busy', number: '13', title: 'Merge: a merge in progress disables every control until it settles', run: testMergeBusy },
    { id: '14-split-page-grid', number: '14', title: 'Split: the tab lists one card per page with a checkbox and label, and starts with nothing selected', run: testSplitPageGrid, pages: 4 },
    { id: '15-split-toggles', number: '15', title: 'Split: cards toggle by click, checkbox and keyboard, and the summary and buttons track the count', run: testSplitToggles, pages: 4 },
    { id: '16-select-all-clear', number: '16', title: 'Split: Select all and Clear selection', run: testSelectAllClear, pages: 4 },
    { id: '17-selection-lifecycle', number: '17', title: 'Split: selection survives a tab switch but not closing the modal', run: testSelectionLifecycle, pages: 4 },
    { id: '18-filename-dialog', number: '18', title: 'Split: the filename dialog defaults to <document>_split.pdf and refuses an empty name', run: testFilenameDialog, pages: 2 },
    { id: '19-split-request', number: '19', title: 'Split: the request carries the selected pages in ascending order, the name and the destination', run: testSplitRequest, pages: 5 },
    { id: '20-real-split-download', number: '20', title: 'Split: a real download contains only the selected pages, in document order, and leaves the source untouched', run: testRealSplitDownload, pages: 4 },
    { id: '21-real-split-open-in-editor', number: '21', title: 'Split: Open in editor creates a new document with the selected pages and its own id', run: testRealSplitOpenInEditor, pages: 4 },
    { id: '22-split-busy-and-failure', number: '22', title: 'Split: a split in progress disables the dialog, and a failure reports the reason and stays open', run: testSplitBusyAndFailure, pages: 2 },
    { id: '23-keyboard-and-aria', number: '23', title: 'Keyboard access and ARIA on the modal, tablist, cards and filename dialog', run: testKeyboardAndAria, pages: 2 },
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
                // A fresh context per test so no cookie, session-storage or
                // tool state leaks from one case into another.
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
                docId = await createBlankDocument(page, csrfToken, `Merge Split tool test ${test.number}`, test.pages || 1);
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
    MERGE_LIMITS,
    buildBlankPdfBuffer,
    buildEncryptedPdfBuffer,
    pdfPageCount,
    pdfTextStrings,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    modalState,
    openModal,
    closeModal,
    setTab,
    mergeCards,
    addMergeFiles,
    moveCard,
    removeCard,
    splitCards,
    clickSplitCard,
    openNameDialog,
    setSplitName,
    clickSplitDestination,
    multipartFields,
    interceptPost,
    waitForCapture,
    editorPageCount,
    editorPageTexts,
    documentName,
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
