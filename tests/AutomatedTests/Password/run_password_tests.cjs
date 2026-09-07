/**
 * Password Tool Automated Tests (Playwright)
 *
 * Automates the "[QA] - Password tool" Asana task (Netkit -> Claude QA Agent).
 * Each run creates a fresh blank PDF, uploads it as a new document, opens it
 * in the pdf.js editor, and drives the real Password dialog.
 *
 * Scope: the floating toolbar's Password button, the dialog with its Set and
 * Remove tabs, the Unlock prompt a protected document shows before it
 * renders, and what protection does to reopening and to Download PDF.
 *
 * WHY MOST OF THIS RUNS FOR REAL
 * Setting, updating and removing a password with persist_protection only
 * hashes the password onto the documents row and issues a session-scoped
 * unlock token; no PDF bytes change and nothing is billed. So those cases run
 * against the real endpoint and prove the effect by reloading -- in the same
 * session and in a fresh one. Only Download PDF encrypts the edited copy
 * through encrypt_pdf.py, which case 11 exercises. The contract, busy and
 * failure cases intercept the POST.
 *
 * Usage:
 *   node run_password_tests.cjs --list
 *   node run_password_tests.cjs --run 01-blank-project-loads,06-protected-reopen
 *   node run_password_tests.cjs --run-all
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

/** The messages the dialog and the editor status line are expected to show. */
const MESSAGES = {
    set: 'Password set. Reopening and downloads now require it.',
    updated: 'Password updated. Reopening and downloads now require it.',
    removed: 'Password removed. This PDF now opens without a password.',
    editorSet: 'PDF password set.',
    editorUpdated: 'PDF password updated.',
    editorRemoved: 'PDF password removed.',
    wrongPassword: 'Password is incorrect.',
    wrongCurrent: 'Current password is incorrect.',
    removeHelpUnprotected: 'Set a password first.',
    removeHelpProtected: 'Enter the current password to remove protection from this PDF.',
    protectedDownload: 'Password-protected PDF ready.',
    plainDownload: 'PDF ready.',
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

/** Build a minimal valid 612x792 PDF as raw bytes, one line of text per page. */
function buildBlankPdfBuffer(label, pageCount = 1) {
    const title = String(label || 'Password tool automated test').replace(/[()\\]/g, '');
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

/** The page count of a PDF, from the largest /Count in its page tree. */
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
 * Whether a PDF is encrypted: its trailer references an /Encrypt dictionary
 * and that dictionary names the standard security handler. MuPDF writes both
 * without padding, so match on the names rather than on surrounding space.
 */
function pdfIsEncrypted(buffer) {
    const text = buffer.toString('latin1');
    return /\/Encrypt(?=[\s\/<\[\d])/.test(text) && /\/Filter\s*\/Standard/.test(text);
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
    const name = `password_tool_test_${process.pid}_${uploadCounter}.pdf`;

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

function attachConsoleRecorder(page) {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('pageerror', (error) => consoleErrors.push(String(error && error.message ? error.message : error)));
    return consoleErrors;
}

/**
 * Open the pdf.js editor and wait until page 1 has rasterised and the editor
 * has published its viewer. Returns the console errors collected during load.
 */
async function openEditor(page, docId) {
    const consoleErrors = attachConsoleRecorder(page);
    await page.goto(`${BASE_URL}/documents/${docId}/edit-new?pdfjs=1`, { waitUntil: 'load', timeout: 45000 });
    await waitForRender(page);
    return consoleErrors;
}

async function waitForRender(page) {
    await page.waitForSelector('#viewer', { timeout: 30000 });
    await page.waitForSelector('#viewer .page canvas', { timeout: 30000 });
    await page.waitForFunction(() => {
        const canvas = document.querySelector('#viewer .page canvas');
        return !!canvas && canvas.width > 0 && canvas.height > 0 && !!window.__enpv?.pdfViewer;
    }, { timeout: 20000 });
    // Let the tool bindings attach before driving the UI.
    await page.waitForTimeout(600);
}

/**
 * Open a protected document: the Unlock prompt has to appear before any page
 * is drawn, so wait for the dialog rather than the canvas.
 */
async function openEditorExpectingUnlock(page, docId) {
    attachConsoleRecorder(page);
    await page.goto(`${BASE_URL}/documents/${docId}/edit-new?pdfjs=1`, { waitUntil: 'load', timeout: 45000 });
    await page.waitForFunction(() => {
        const modal = document.getElementById('enpv-encrypt-modal');
        const unlock = document.getElementById('enpv-encrypt-unlock-panel');
        return !!modal && modal.hidden === false && !!unlock && unlock.hidden === false;
    }, { timeout: 30000 });
    await page.waitForTimeout(400);
    return dialogState(page);
}

function meaningfulConsoleErrors(consoleErrors) {
    return (consoleErrors || []).filter((text) => !/fonts\.(googleapis|gstatic)|favicon|ERR_INTERNET_DISCONNECTED|ERR_NAME_NOT_RESOLVED/i.test(text));
}

function pageRendered(page) {
    return page.evaluate(() => {
        const canvas = document.querySelector('#viewer .page canvas');
        return !!canvas && canvas.width > 0 && canvas.height > 0;
    });
}

function rootProtectedFlag(page) {
    return page.evaluate(() => document.getElementById('enpv-root')?.dataset?.passwordProtected || '');
}

/** The status line under the toolbar; #f88 marks a failure. */
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

async function waitForStatus(page, pattern, timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        const status = await statusText(page);
        if (pattern.test(status.text)) return status;
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(200);
    }
    return statusText(page);
}

// ---------------------------------------------------------------------------
// Dialog probes and drivers
// ---------------------------------------------------------------------------

function dialogState(page) {
    return page.evaluate(() => {
        const byId = (id) => document.getElementById(id);
        const modal = byId('enpv-encrypt-modal');
        const tab = (id) => {
            const el = byId(id);
            return {
                selected: el?.getAttribute('aria-selected'),
                active: !!el?.classList.contains('is-active'),
                tabIndex: el ? el.tabIndex : null,
                disabled: !!el?.disabled,
            };
        };
        const input = (id) => {
            const el = byId(id);
            return { value: el?.value || '', disabled: !!el?.disabled };
        };
        const error = byId('enpv-encrypt-error');
        const button = byId('ftb-encrypt');
        return {
            present: !!modal,
            open: !!modal && modal.hidden === false,
            title: byId('enpv-encrypt-title')?.textContent?.trim() || '',
            tabsHidden: byId('enpv-encrypt-tabs')?.hidden,
            setTab: tab('enpv-encrypt-set-tab'),
            removeTab: tab('enpv-encrypt-remove-tab'),
            activeTab: byId('enpv-encrypt-remove-tab')?.getAttribute('aria-selected') === 'true' ? 'remove' : 'set',
            setPanelHidden: byId('enpv-encrypt-set-panel')?.hidden,
            removePanelHidden: byId('enpv-encrypt-remove-panel')?.hidden,
            unlockPanelHidden: byId('enpv-encrypt-unlock-panel')?.hidden,
            currentWrapHidden: byId('enpv-encrypt-current-wrap')?.hidden,
            confirmWrapHidden: byId('enpv-encrypt-confirm-wrap')?.hidden,
            removeHelp: byId('enpv-encrypt-remove-help')?.textContent?.trim() || '',
            error: { text: error?.textContent?.trim() || '', hidden: error ? error.hidden : null },
            status: byId('enpv-encrypt-status')?.textContent?.trim() || '',
            submitLabel: byId('enpv-encrypt-accept-label')?.textContent?.trim() || '',
            submitDisabled: !!byId('enpv-encrypt-accept')?.disabled,
            submitBusy: !!byId('enpv-encrypt-accept')?.classList.contains('is-busy'),
            closeDisabled: !!byId('enpv-encrypt-close')?.disabled,
            current: input('enpv-encrypt-current-password'),
            next: input('enpv-encrypt-password'),
            confirm: input('enpv-encrypt-confirm'),
            remove: input('enpv-encrypt-remove-password'),
            unlock: input('enpv-encrypt-unlock-password'),
            focusedId: document.activeElement?.id || '',
            buttonPressed: button?.getAttribute('aria-pressed'),
            buttonActive: !!button?.classList.contains('is-active'),
            notices: byId('enpv-encrypt-description')?.textContent?.replace(/\s+/g, ' ').trim() || '',
        };
    });
}

async function openDialog(page) {
    await page.evaluate(() => document.getElementById('ftb-encrypt')?.click());
    await page.waitForTimeout(500);
    return dialogState(page);
}

async function closeDialog(page, how = 'close') {
    if (how === 'close') {
        await page.evaluate(() => document.getElementById('enpv-encrypt-close')?.click());
    } else if (how === 'escape') {
        await page.keyboard.press('Escape');
    } else {
        await page.evaluate(() => document.getElementById('enpv-encrypt-modal')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        ));
    }
    await page.waitForTimeout(400);
    return dialogState(page);
}

async function setTab(page, tab) {
    await page.evaluate((id) => document.getElementById(id)?.click(), tab === 'remove' ? 'enpv-encrypt-remove-tab' : 'enpv-encrypt-set-tab');
    await page.waitForTimeout(300);
    return dialogState(page);
}

const FIELD = {
    current: '#enpv-encrypt-current-password',
    next: '#enpv-encrypt-password',
    confirm: '#enpv-encrypt-confirm',
    remove: '#enpv-encrypt-remove-password',
    unlock: '#enpv-encrypt-unlock-password',
};

/** Type into a field the way a user does, so the dialog's input handler runs. */
async function fill(page, field, value) {
    await page.fill(FIELD[field], value);
    await page.waitForTimeout(150);
    return dialogState(page);
}

async function submit(page) {
    await page.evaluate(() => document.getElementById('enpv-encrypt-accept')?.click());
}

async function waitForDialogStatus(page, expected, timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        const state = await dialogState(page);
        if (state.status === expected || state.error.text) return state;
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(200);
    }
    return dialogState(page);
}

/** Set a password for real and leave the dialog open on its Update state. */
async function setPasswordForReal(page, password) {
    await openDialog(page);
    await fill(page, 'next', password);
    await fill(page, 'confirm', password);
    await submit(page);
    return waitForDialogStatus(page, MESSAGES.set);
}

/** Answer the Unlock prompt. */
async function unlock(page, password) {
    await fill(page, 'unlock', password);
    await submit(page);
    await page.waitForTimeout(1200);
    return dialogState(page);
}

// ---------------------------------------------------------------------------
// Request capture
// ---------------------------------------------------------------------------

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
 * Intercept one POST, capture its fields, and answer with a canned response.
 * The password endpoint takes multipart; the unlock endpoint takes JSON.
 */
async function interceptPost(page, urlPattern, respondWith) {
    const captured = { fields: null, url: null, count: 0 };
    await page.route(urlPattern, async (route) => {
        const request = route.request();
        if (request.method() !== 'POST') {
            await route.continue();
            return;
        }
        captured.count += 1;
        captured.url = request.url();
        const contentType = String(request.headers()['content-type'] || '');
        captured.fields = contentType.includes('application/json')
            ? (request.postDataJSON() || {})
            : multipartFields(request.postData());
        await route.fulfill({
            status: respondWith.status || 200,
            contentType: 'application/json',
            body: JSON.stringify(respondWith.body || {}),
        });
    });
    return captured;
}

async function waitForCapture(page, captured, timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    while (captured.count === 0 && Date.now() < deadline) {
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(200);
    }
    return captured.count > 0;
}

/**
 * Download PDF hands its result to a popup as a blob: URL rather than as a
 * download, so hook URL.createObjectURL and keep the blobs the page builds.
 */
async function captureObjectUrls(page) {
    await page.evaluate(() => {
        if (window.__qaBlobs) return;
        window.__qaBlobs = [];
        const original = URL.createObjectURL.bind(URL);
        URL.createObjectURL = (blob) => {
            window.__qaBlobs.push(blob);
            return original(blob);
        };
    });
}

async function lastCapturedBlob(page) {
    const base64 = await page.evaluate(async () => {
        const blob = window.__qaBlobs?.[window.__qaBlobs.length - 1];
        if (!blob) return null;
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(binary);
    });
    return base64 ? Buffer.from(base64, 'base64') : null;
}

async function closeExtraPages(page) {
    for (const extra of page.context().pages()) {
        if (extra !== page) await extra.close().catch(() => {});
    }
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

/** 01 — Setup: the blank project loads with the Password tool available. */
async function testBlankProjectLoads(page, { consoleErrors, recorder }) {
    const artifacts = [];

    const setup = await page.evaluate(() => {
        const button = document.getElementById('ftb-encrypt');
        const modal = document.getElementById('enpv-encrypt-modal');
        return {
            buttonPresent: !!button,
            buttonEnabled: !!button && !button.disabled,
            premiumLocked: !!button?.classList.contains('is-premium-locked'),
            label: button?.getAttribute('aria-label') || '',
            haspopup: button?.getAttribute('aria-haspopup') || '',
            controls: button?.getAttribute('aria-controls') || '',
            text: button?.textContent?.replace(/\s+/g, ' ').trim() || '',
            modalPresent: !!modal,
            modalHidden: modal ? modal.hidden : null,
            protectedFlag: document.getElementById('enpv-root')?.dataset?.passwordProtected || '',
            encryptUrl: document.querySelector('[data-encrypt-pdf-url]')?.dataset?.encryptPdfUrl || '',
            unlockUrl: document.getElementById('enpv-root')?.dataset?.passwordUnlockUrl || '',
            canvasPainted: (() => {
                const canvas = document.querySelector('#viewer .page canvas');
                return !!canvas && canvas.width > 0;
            })(),
        };
    });

    recorder.assert('page-rasterised', setup.canvasPainted, 'Page 1 rasterises');
    recorder.assert('button-present', setup.buttonPresent, 'The floating toolbar has the Password button');
    recorder.assert('button-enabled', setup.buttonEnabled, 'The button is enabled');
    recorder.equals('not-premium-locked', setup.premiumLocked, false, 'The tool is not premium-locked');
    recorder.equals('button-label', setup.label, 'Set or remove a PDF password', 'The button has an accessible name');
    recorder.equals('button-haspopup', setup.haspopup, 'dialog', 'The button announces that it opens a dialog');
    recorder.equals('button-controls', setup.controls, 'enpv-encrypt-modal', 'The button names the dialog it controls');
    recorder.equals('button-text', setup.text, 'Password', 'The button reads Password');
    recorder.assert('modal-present', setup.modalPresent, 'The dialog is in the DOM');
    recorder.equals('modal-closed', setup.modalHidden, true, 'It starts closed');
    recorder.equals('starts-unprotected', setup.protectedFlag, '0', 'A fresh document is not password protected');
    recorder.assert('endpoints-wired', /encrypt-pdf$/.test(setup.encryptUrl) && /pdf-password\/unlock$/.test(setup.unlockUrl),
        'Both endpoints are wired on the root', `${setup.encryptUrl} ${setup.unlockUrl}`);
    const noise = meaningfulConsoleErrors(consoleErrors);
    recorder.equals('console-clean', noise.length, 0, 'The console is clean on load', noise.slice(0, 3).join(' | '));

    artifacts.push(await capture(page, '01-blank-project-loads', 'loaded'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 02 — The dialog opens on Set and closes via X, scrim and Escape. */
async function testDialogOpenClose(page, { recorder }) {
    const artifacts = [];

    const opened = await openDialog(page);
    recorder.assert('opens', opened.open, 'The Password button opens the dialog');
    recorder.equals('title', opened.title, 'Password', 'It is titled Password');
    recorder.equals('opens-on-set', opened.activeTab, 'set', 'It opens on the Set tab');
    recorder.equals('set-panel-shown', opened.setPanelHidden, false, 'The Set panel is on show');
    recorder.equals('remove-panel-hidden', opened.removePanelHidden, true, 'The Remove panel is hidden');
    recorder.equals('unlock-panel-hidden', opened.unlockPanelHidden, true, 'The Unlock panel is hidden');
    recorder.equals('tabs-shown', opened.tabsHidden, false, 'The tabs are on show');
    recorder.equals('focus-on-new-password', opened.focusedId, 'enpv-encrypt-password', 'Focus lands in New password');
    recorder.equals('button-pressed', opened.buttonPressed, 'true', 'The toolbar button reports itself pressed');
    recorder.assert('button-active', opened.buttonActive, 'The toolbar button is styled active');
    recorder.assert('notices', /no recovery method/i.test(opened.notices) && /AES-128/.test(opened.notices),
        'The recovery and encryption notices are on show', opened.notices);
    recorder.equals('submit-label', opened.submitLabel, 'Set password', 'The action reads Set password');
    recorder.equals('submit-disabled', opened.submitDisabled, true, 'It starts disabled');

    artifacts.push(await capture(page, '02-dialog-open-close', 'open'));

    for (const how of ['close', 'scrim', 'escape']) {
        // eslint-disable-next-line no-await-in-loop
        await openDialog(page);
        // eslint-disable-next-line no-await-in-loop
        const closed = await closeDialog(page, how);
        recorder.assert(`closes-via-${how}`, !closed.open, `The dialog closes via ${how}`);
    }

    await openDialog(page);
    await page.evaluate(() => document.querySelector('.enpv-encrypt-card')?.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    await page.waitForTimeout(300);
    recorder.assert('card-click-keeps-it-open', (await dialogState(page)).open, 'Clicking inside the card does not close it');

    await fill(page, 'next', 'typed-but-abandoned');
    const closed = await closeDialog(page, 'close');
    recorder.equals('focus-returns', closed.focusedId, 'ftb-encrypt', 'Closing returns focus to the Password button');
    recorder.equals('button-unpressed', closed.buttonPressed, 'false', 'The button is no longer pressed');
    const reopened = await openDialog(page);
    recorder.equals('inputs-cleared', reopened.next.value, '', 'What was typed is cleared on reopen');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 03 — Tabs, and Remove being inert on an unprotected document. */
async function testTabs(page, { recorder }) {
    const artifacts = [];

    await openDialog(page);
    let state = await setTab(page, 'remove');
    recorder.equals('remove-active', state.activeTab, 'remove', 'Remove becomes the active tab');
    recorder.equals('remove-panel-shown', state.removePanelHidden, false, 'The Remove panel is on show');
    recorder.equals('set-panel-hidden', state.setPanelHidden, true, 'The Set panel is hidden');
    recorder.equals('aria-selected', `${state.removeTab.selected}/${state.setTab.selected}`, 'true/false', 'aria-selected marks Remove');
    recorder.equals('roving-tabindex', `${state.removeTab.tabIndex}/${state.setTab.tabIndex}`, '0/-1', 'Only the active tab is in the tab order');
    recorder.equals('focus-on-remove-field', state.focusedId, 'enpv-encrypt-remove-password', 'Focus moves to the Remove field');
    recorder.equals('remove-label', state.submitLabel, 'Remove password', 'The action reads Remove password');
    recorder.equals('remove-help', state.removeHelp, MESSAGES.removeHelpUnprotected, 'An unprotected document is told to set a password first');

    state = await fill(page, 'remove', 'anything');
    recorder.equals('remove-inert', state.submitDisabled, true, 'Remove never enables on an unprotected document');

    artifacts.push(await capture(page, '03-tabs', 'remove-tab'));

    await page.evaluate(() => document.getElementById('enpv-encrypt-remove-tab')?.focus());
    await page.keyboard.press('ArrowLeft');
    await page.waitForTimeout(300);
    recorder.equals('arrow-left-to-set', (await dialogState(page)).activeTab, 'set', 'ArrowLeft moves to Set');
    await page.evaluate(() => document.getElementById('enpv-encrypt-set-tab')?.focus());
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    recorder.equals('arrow-right-to-remove', (await dialogState(page)).activeTab, 'remove', 'ArrowRight moves to Remove');

    state = await setTab(page, 'set');
    recorder.equals('back-to-set', state.activeTab, 'set', 'Clicking Set brings it back');
    recorder.equals('set-panel-back', state.setPanelHidden, false, 'The Set panel returns');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 04 — Set gating and Enter. */
async function testSetGating(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptPost(page, /\/encrypt-pdf/, {
        status: 422,
        body: { success: false, message: 'QA suite: refused.' },
    });

    let state = await openDialog(page);
    recorder.equals('starts-disabled', state.submitDisabled, true, 'Set password starts disabled');
    state = await fill(page, 'next', 'hunter22');
    recorder.equals('new-alone-disabled', state.submitDisabled, true, 'A new password alone does not enable it');
    state = await fill(page, 'confirm', 'nope');
    recorder.equals('mismatch-disabled', state.submitDisabled, true, 'A mismatched confirmation does not enable it');
    state = await fill(page, 'confirm', 'hunter22');
    recorder.equals('match-enables', state.submitDisabled, false, 'A matching confirmation enables it');
    state = await fill(page, 'next', 'hunter23');
    recorder.equals('later-mismatch-disables', state.submitDisabled, true, 'Changing the new password after confirming disables it again');
    state = await fill(page, 'next', 'hunter22');
    recorder.equals('fixed-enables', state.submitDisabled, false, 'Restoring the match enables it');

    artifacts.push(await capture(page, '04-set-gating', 'enabled'));

    await page.focus(FIELD.confirm);
    await page.keyboard.press('Enter');
    const arrived = await waitForCapture(page, captured, 15000);
    recorder.assert('enter-submits', arrived, 'Enter in a field submits when the action is enabled');
    await page.waitForTimeout(600);
    state = await dialogState(page);
    recorder.equals('refusal-shown', state.error.text, 'QA suite: refused.', 'The refusal is shown');
    recorder.equals('refusal-visible', state.error.hidden, false, 'The error is visible');

    state = await fill(page, 'confirm', 'hunter2');
    recorder.equals('typing-clears-error', state.error.hidden, true, 'Typing clears the error');
    recorder.equals('now-disabled', state.submitDisabled, true, 'The mismatch disables the action');
    await page.focus(FIELD.confirm);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(800);
    recorder.equals('enter-when-disabled-does-nothing', captured.count, 1, 'Enter does nothing while the action is disabled');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 05 — The Set request contract, and the flip to Update. */
async function testSetRequest(page, { recorder }) {
    const artifacts = [];

    const captured = await interceptPost(page, /\/encrypt-pdf/, {
        status: 200,
        body: { success: true, action: 'set', persisted: true, protected: true, algorithm: 'aes-128', unlock_token: 'qa-token' },
    });

    await openDialog(page);
    await fill(page, 'next', 'Secret-1');
    await fill(page, 'confirm', 'Secret-1');
    await submit(page);
    const arrived = await waitForCapture(page, captured, 30000);
    recorder.assert('request-made', arrived, 'Set posts to the password endpoint');
    if (arrived) {
        const fields = captured.fields || {};
        recorder.equals('action', fields.action, 'set', 'action=set');
        recorder.equals('algorithm', fields.algorithm, 'aes-128', 'algorithm=aes-128');
        recorder.equals('persist', fields.persist_protection, '1', 'persist_protection=1, so the row is protected rather than a copy encrypted');
        recorder.equals('password', fields.password, 'Secret-1', 'The password is sent');
        recorder.equals('confirmation', fields.password_confirmation, 'Secret-1', 'The confirmation is sent');
        recorder.equals('no-current', fields.current_password, undefined, 'No current password is sent for a first set');
        recorder.equals('no-pdf', fields.pdf, undefined, 'No PDF is attached: nothing is encrypted yet');
        recorder.equals('posted-once', captured.count, 1, 'One click posts one request');
    }

    const state = await waitForDialogStatus(page, MESSAGES.set);
    recorder.equals('dialog-stays-open', state.open, true, 'The dialog stays open to report the result');
    recorder.equals('status', state.status, MESSAGES.set, 'It reports the password as set');
    recorder.equals('editor-status', (await statusText(page)).text, MESSAGES.editorSet, 'The editor status agrees');
    recorder.equals('fields-cleared', `${state.next.value}|${state.confirm.value}`, '|', 'The fields are cleared');
    recorder.equals('label-flips', state.submitLabel, 'Update password', 'The Set tab now reads Update password');
    recorder.equals('current-shown', state.currentWrapHidden, false, 'A Current password field appears');
    recorder.equals('confirm-hidden', state.confirmWrapHidden, true, 'The confirmation field hides');
    recorder.equals('back-on-set', state.activeTab, 'set', 'The dialog stays on the Set tab');

    artifacts.push(await capture(page, '05-set-request', 'after-set'));

    const removeState = await setTab(page, 'remove');
    recorder.equals('remove-help-flips', removeState.removeHelp, MESSAGES.removeHelpProtected, 'Remove now asks for the current password');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 06 — A protected document asks for its password before it renders. */
async function testProtectedReopen(page, { recorder, docId }) {
    const artifacts = [];

    const set = await setPasswordForReal(page, 'Secret-1');
    recorder.equals('set-for-real', set.status, MESSAGES.set, 'The setup sets a password for real');
    await closeDialog(page, 'close');

    const prompt = await openEditorExpectingUnlock(page, docId);
    recorder.equals('prompt-title', prompt.title, 'Unlock PDF', 'Reloading shows the Unlock prompt');
    recorder.equals('tabs-hidden', prompt.tabsHidden, true, 'The Set / Remove tabs are hidden on the prompt');
    recorder.equals('unlock-panel', prompt.unlockPanelHidden, false, 'The Unlock panel is on show');
    recorder.equals('open-label', prompt.submitLabel, 'Open PDF', 'The action reads Open PDF');
    recorder.equals('open-disabled', prompt.submitDisabled, true, 'It is disabled until a password is typed');
    recorder.equals('focus-on-unlock', prompt.focusedId, 'enpv-encrypt-unlock-password', 'Focus lands in the password field');
    recorder.equals('not-rendered-yet', await pageRendered(page), false, 'No page is drawn before the password is given');
    recorder.equals('root-protected', await rootProtectedFlag(page), '1', 'The root reports the document protected');

    artifacts.push(await capture(page, '06-protected-reopen', 'prompt'));

    let state = await unlock(page, 'wrong-password');
    recorder.equals('wrong-refused', state.error.text, MESSAGES.wrongPassword, 'A wrong password is refused');
    recorder.equals('prompt-stays', state.open, true, 'The prompt stays');
    recorder.equals('still-not-rendered', await pageRendered(page), false, 'Still nothing is drawn');

    state = await unlock(page, 'Secret-1');
    await waitForRender(page).catch(() => null);
    recorder.equals('right-opens', state.open, false, 'The right password closes the prompt');
    recorder.equals('renders', await pageRendered(page), true, 'The PDF renders');

    const dialog = await openDialog(page);
    recorder.equals('dialog-in-update-mode', dialog.submitLabel, 'Update password', 'The dialog now opens in Update mode');
    recorder.equals('current-field-shown', dialog.currentWrapHidden, false, 'With a Current password field');

    artifacts.push(await capture(page, '06-protected-reopen', 'unlocked'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 07 — Cancelling the unlock prompt leaves the editor. */
async function testUnlockCancelLeaves(page, { recorder, docId }) {
    const artifacts = [];

    await setPasswordForReal(page, 'Secret-1');
    await closeDialog(page, 'close');

    for (const how of ['escape', 'close', 'scrim']) {
        // eslint-disable-next-line no-await-in-loop
        await openEditorExpectingUnlock(page, docId);
        if (how === 'escape') {
            // eslint-disable-next-line no-await-in-loop
            await page.keyboard.press('Escape');
        } else if (how === 'close') {
            // eslint-disable-next-line no-await-in-loop
            await page.evaluate(() => document.getElementById('enpv-encrypt-close')?.click());
        } else {
            // eslint-disable-next-line no-await-in-loop
            await page.evaluate(() => document.getElementById('enpv-encrypt-modal')?.dispatchEvent(new MouseEvent('click', { bubbles: true })));
        }
        // eslint-disable-next-line no-await-in-loop
        await page.waitForURL(/\/pdf-editor(\?|$)/, { timeout: 20000 }).catch(() => null);
        recorder.assert(`${how}-leaves`, /\/pdf-editor(\?|$)/.test(page.url()),
            `${how} on the Unlock prompt leaves for the editor home`, page.url());
    }

    artifacts.push(await capture(page, '07-unlock-cancel-leaves', 'left'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 08 — Protection is per session. */
async function testPerSession(page, { recorder, docId }) {
    const artifacts = [];

    await setPasswordForReal(page, 'Secret-1');
    await closeDialog(page, 'close');

    // Even the session that set it must present its token: a bare request is refused.
    const bareStatus = await page.evaluate(async (id) => {
        const response = await fetch(`/documents/${id}/file`, { credentials: 'same-origin' });
        return response.status;
    }, docId);
    recorder.equals('bare-file-request-423', bareStatus, 423, 'The file URL answers 423 without the unlock token');

    const fresh = await page.context().browser().newContext({ viewport: { width: 1500, height: 1000 } });
    try {
        const freshPage = await fresh.newPage();
        const prompt = await openEditorExpectingUnlock(freshPage, docId);
        recorder.equals('fresh-session-prompted', prompt.title, 'Unlock PDF', 'A fresh session is asked for the password');
        const freshStatus = await freshPage.evaluate(async (id) => {
            const response = await fetch(`/documents/${id}/file`, { credentials: 'same-origin' });
            return response.status;
        }, docId);
        recorder.equals('fresh-file-request-423', freshStatus, 423, 'Its file request answers 423 before it unlocks');
        await unlock(freshPage, 'Secret-1');
        await waitForRender(freshPage).catch(() => null);
        recorder.equals('fresh-session-unlocks', await pageRendered(freshPage), true, 'The right password opens it there too');
        artifacts.push(await capture(freshPage, '08-per-session', 'fresh-session'));
    } finally {
        await fresh.close().catch(() => {});
    }

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 09 — Update. */
async function testUpdate(page, { recorder, docId }) {
    const artifacts = [];

    await setPasswordForReal(page, 'Secret-1');
    let state = await dialogState(page);
    recorder.equals('update-label', state.submitLabel, 'Update password', 'With protection active the Set tab reads Update password');
    recorder.equals('current-shown', state.currentWrapHidden, false, 'Current password is shown');
    recorder.equals('confirm-hidden', state.confirmWrapHidden, true, 'The confirmation is hidden');
    recorder.equals('starts-disabled', state.submitDisabled, true, 'It starts disabled');

    state = await fill(page, 'next', 'Secret-2');
    recorder.equals('new-alone-disabled', state.submitDisabled, true, 'A new password alone does not enable it');
    state = await fill(page, 'current', 'wrong-password');
    recorder.equals('both-enable', state.submitDisabled, false, 'Current and new together enable it');
    await submit(page);
    state = await waitForDialogStatus(page, MESSAGES.updated);
    recorder.equals('wrong-current-refused', state.error.text, MESSAGES.wrongCurrent, 'A wrong current password is refused');

    artifacts.push(await capture(page, '09-update', 'refused'));

    await fill(page, 'current', 'Secret-1');
    await fill(page, 'next', 'Secret-2');
    await submit(page);
    state = await waitForDialogStatus(page, MESSAGES.updated);
    recorder.equals('updated', state.status, MESSAGES.updated, 'The right current password updates it');
    recorder.equals('editor-status', (await statusText(page)).text, MESSAGES.editorUpdated, 'The editor status agrees');
    await closeDialog(page, 'close');

    const fresh = await page.context().browser().newContext({ viewport: { width: 1500, height: 1000 } });
    try {
        const freshPage = await fresh.newPage();
        await openEditorExpectingUnlock(freshPage, docId);
        const old = await unlock(freshPage, 'Secret-1');
        recorder.equals('old-password-refused', old.error.text, MESSAGES.wrongPassword, 'The old password no longer unlocks');
        await unlock(freshPage, 'Secret-2');
        await waitForRender(freshPage).catch(() => null);
        recorder.equals('new-password-opens', await pageRendered(freshPage), true, 'The new password does');
    } finally {
        await fresh.close().catch(() => {});
    }

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 10 — Remove. */
async function testRemove(page, { recorder, docId }) {
    const artifacts = [];

    await setPasswordForReal(page, 'Secret-1');
    let state = await setTab(page, 'remove');
    recorder.equals('remove-help', state.removeHelp, MESSAGES.removeHelpProtected, 'Remove asks for the current password');
    recorder.equals('starts-disabled', state.submitDisabled, true, 'It starts disabled');
    state = await fill(page, 'remove', 'wrong-password');
    recorder.equals('enables-with-input', state.submitDisabled, false, 'A value enables it');
    await submit(page);
    state = await waitForDialogStatus(page, MESSAGES.removed);
    recorder.equals('wrong-refused', state.error.text, MESSAGES.wrongCurrent, 'A wrong password is refused');
    // The root's flag is rendered at page load; the dialog is what knows now.
    recorder.equals('still-protected', state.removeHelp, MESSAGES.removeHelpProtected, 'The document is still protected');

    artifacts.push(await capture(page, '10-remove', 'refused'));

    await fill(page, 'remove', 'Secret-1');
    await submit(page);
    state = await waitForDialogStatus(page, MESSAGES.removed);
    recorder.equals('removed', state.status, MESSAGES.removed, 'The right password removes protection');
    recorder.equals('editor-status', (await statusText(page)).text, MESSAGES.editorRemoved, 'The editor status agrees');
    recorder.equals('back-to-set', state.activeTab, 'set', 'The dialog returns to the Set tab');
    recorder.equals('set-label', state.submitLabel, 'Set password', 'Which reads Set password again');
    recorder.equals('confirm-back', state.confirmWrapHidden, false, 'The confirmation field is back');
    recorder.equals('current-gone', state.currentWrapHidden, true, 'The Current password field is gone');
    await closeDialog(page, 'close');

    await openEditor(page, docId);
    recorder.equals('reopens-without-prompt', (await dialogState(page)).open, false, 'A reload shows no prompt');
    recorder.equals('renders', await pageRendered(page), true, 'The PDF renders straight away');
    recorder.equals('root-unprotected', await rootProtectedFlag(page), '0', 'The root reports the document unprotected');

    artifacts.push(await capture(page, '10-remove', 'reopened'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 11 — Download PDF on a protected document is encrypted. */
async function testProtectedDownload(page, { recorder }) {
    const artifacts = [];

    await captureObjectUrls(page);
    await setPasswordForReal(page, 'Secret-1');
    await closeDialog(page, 'close');

    await page.evaluate(() => document.getElementById('download-pdf-btn')?.click());
    let status = await waitForStatus(page, /PDF ready|PDF generation failed/, 90000);
    recorder.equals('protected-status', status.text, MESSAGES.protectedDownload, 'The download reports itself password-protected');
    let bytes = await lastCapturedBlob(page);
    recorder.assert('protected-is-pdf', !!bytes && bytes.subarray(0, 5).toString('latin1') === '%PDF-', 'A PDF was built');
    recorder.assert('protected-encrypted', !!bytes && pdfIsEncrypted(bytes), 'It carries an /Encrypt dictionary');
    recorder.equals('protected-page-count', bytes ? pdfPageCount(bytes) : 0, 1, 'It keeps its page count');
    await closeExtraPages(page);

    artifacts.push(await capture(page, '11-protected-download', 'protected'));

    await openDialog(page);
    await setTab(page, 'remove');
    await fill(page, 'remove', 'Secret-1');
    await submit(page);
    await waitForDialogStatus(page, MESSAGES.removed);
    await closeDialog(page, 'close');

    await page.evaluate(() => document.getElementById('download-pdf-btn')?.click());
    status = await waitForStatus(page, /PDF ready|PDF generation failed/, 90000);
    recorder.equals('plain-status', status.text, MESSAGES.plainDownload, 'After removal the download is plain');
    bytes = await lastCapturedBlob(page);
    recorder.assert('plain-not-encrypted', !!bytes && !pdfIsEncrypted(bytes), 'And carries no /Encrypt dictionary');
    await closeExtraPages(page);

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 12 — Busy state. */
async function testBusyState(page, { recorder }) {
    const artifacts = [];

    let release = null;
    const held = new Promise((resolve) => { release = resolve; });
    await page.route(/\/encrypt-pdf/, async (route) => {
        await held;
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, message: 'QA suite: released.' }),
        });
    });

    await openDialog(page);
    await fill(page, 'next', 'Secret-1');
    await fill(page, 'confirm', 'Secret-1');
    await submit(page);
    await page.waitForTimeout(1500);

    const busy = await dialogState(page);
    recorder.equals('submit-disabled', busy.submitDisabled, true, 'The action is disabled while it runs');
    recorder.equals('submit-busy', busy.submitBusy, true, 'And marked busy');
    recorder.assert('status-says-what', /Setting password|Saving password protection/.test(busy.status), 'The status says what it is doing', busy.status);
    recorder.assert('fields-disabled', busy.next.disabled && busy.confirm.disabled && busy.current.disabled && busy.remove.disabled && busy.unlock.disabled,
        'Every field is disabled');
    recorder.equals('tabs-disabled', `${busy.setTab.disabled}/${busy.removeTab.disabled}`, 'true/true', 'Both tabs are disabled');
    recorder.equals('close-disabled', busy.closeDisabled, true, 'Close is disabled');
    const stillOpen = await closeDialog(page, 'escape');
    recorder.equals('escape-ignored', stillOpen.open, true, 'Escape does not close it mid-request');

    artifacts.push(await capture(page, '12-busy-state', 'busy'));

    release();
    await page.waitForTimeout(2000);
    const settled = await dialogState(page);
    recorder.equals('submit-back', settled.submitBusy, false, 'The busy mark clears once the response lands');
    recorder.equals('fields-back', settled.next.disabled, false, 'The fields come back');
    recorder.equals('close-back', settled.closeDisabled, false, 'Close comes back');
    recorder.equals('released-message', settled.error.text, 'QA suite: released.', 'The response is reported');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 13 — Failure handling. */
async function testFailureHandling(page, { recorder }) {
    const artifacts = [];

    let response = { status: 500, body: { success: false, message: 'QA suite: encryption service unavailable.' } };
    await page.route(/\/encrypt-pdf/, (route) => route.fulfill({
        status: response.status,
        contentType: 'application/json',
        body: JSON.stringify(response.body),
    }));

    await openDialog(page);
    await fill(page, 'next', 'Secret-1');
    await fill(page, 'confirm', 'Secret-1');
    await submit(page);
    await page.waitForTimeout(1500);

    let state = await dialogState(page);
    recorder.equals('message-shown', state.error.text, 'QA suite: encryption service unavailable.', "The server's message is shown");
    recorder.equals('error-visible', state.error.hidden, false, 'In the visible alert');
    recorder.equals('status-cleared', state.status, '', 'The progress status is cleared');
    recorder.equals('dialog-open', state.open, true, 'The dialog stays open');
    recorder.equals('values-kept', `${state.next.value}/${state.confirm.value}`, 'Secret-1/Secret-1', 'The typed values are kept for another try');
    recorder.equals('submit-usable', state.submitDisabled, false, 'The action is usable again');
    recorder.equals('close-usable', state.closeDisabled, false, 'Close is usable again');
    recorder.equals('still-unprotected', await rootProtectedFlag(page), '0', 'Nothing was protected');

    artifacts.push(await capture(page, '13-failure-handling', 'failed'));

    response = { status: 422, body: { message: 'The given data was invalid.', errors: { password: ['The password confirmation does not match.'] } } };
    await submit(page);
    await page.waitForTimeout(1500);
    state = await dialogState(page);
    recorder.equals('validation-message', state.error.text, 'The password confirmation does not match.',
        'A validation error shows its first message rather than a generic one');

    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

/** 14 — Keyboard access and ARIA. */
async function testKeyboardAndAria(page, { recorder }) {
    const artifacts = [];

    await openDialog(page);
    const a11y = await page.evaluate(() => {
        const card = document.querySelector('#enpv-encrypt-modal [role="dialog"]');
        const tablist = document.getElementById('enpv-encrypt-tabs');
        const tabs = Array.from(document.querySelectorAll('#enpv-encrypt-tabs [role="tab"]'));
        const panels = ['enpv-encrypt-set-panel', 'enpv-encrypt-remove-panel', 'enpv-encrypt-unlock-panel'].map((id) => document.getElementById(id));
        const inputs = Array.from(document.querySelectorAll('#enpv-encrypt-modal input'));
        const error = document.getElementById('enpv-encrypt-error');
        return {
            dialogRole: card?.getAttribute('role'),
            modal: card?.getAttribute('aria-modal'),
            labelledBy: card?.getAttribute('aria-labelledby'),
            describedBy: card?.getAttribute('aria-describedby'),
            describedByResolves: !!document.getElementById(card?.getAttribute('aria-describedby') || ''),
            tablistRole: tablist?.getAttribute('role'),
            tablistLabel: tablist?.getAttribute('aria-label') || '',
            tabCount: tabs.length,
            tabsControlPanels: tabs.every((tab) => !!document.getElementById(tab.getAttribute('aria-controls') || '')),
            panelsAreTabpanels: panels.every((panel) => panel?.getAttribute('role') === 'tabpanel'),
            labelledPanels: panels.slice(0, 2).every((panel) => !!document.getElementById(panel?.getAttribute('aria-labelledby') || '')),
            inputCount: inputs.length,
            allPasswordType: inputs.every((input) => input.type === 'password' && input.maxLength === 255),
            allLabelled: inputs.every((input) => !!input.closest('label')?.querySelector('.enpv-password-label')?.textContent?.trim()),
            autocomplete: inputs.map((input) => input.getAttribute('autocomplete')).join(','),
            errorRole: error?.getAttribute('role'),
            errorHiddenWhenEmpty: error ? error.hidden : null,
            statusLive: document.getElementById('enpv-encrypt-status')?.getAttribute('aria-live'),
            closeLabel: document.getElementById('enpv-encrypt-close')?.getAttribute('aria-label') || '',
        };
    });

    recorder.equals('dialog-role', a11y.dialogRole, 'dialog', 'The card is a dialog');
    recorder.equals('aria-modal', a11y.modal, 'true', 'It is marked modal');
    recorder.equals('labelled-by-title', a11y.labelledBy, 'enpv-encrypt-title', 'It is labelled by its title');
    recorder.assert('described-by-notices', a11y.describedBy === 'enpv-encrypt-description' && a11y.describedByResolves, 'It is described by its notices');
    recorder.equals('tablist-role', a11y.tablistRole, 'tablist', 'The tabs are a tablist');
    recorder.assert('tablist-labelled', a11y.tablistLabel.length > 0, 'The tablist has a name', a11y.tablistLabel);
    recorder.equals('two-tabs', a11y.tabCount, 2, 'There are two tabs');
    recorder.assert('tabs-control-panels', a11y.tabsControlPanels, 'Each tab names a panel that exists');
    recorder.assert('panels-are-tabpanels', a11y.panelsAreTabpanels, 'Each panel is a tabpanel');
    recorder.assert('panels-labelled', a11y.labelledPanels, 'The Set and Remove panels are labelled by their tabs');
    recorder.equals('five-fields', a11y.inputCount, 5, 'There are five password fields across the panels');
    recorder.assert('fields-are-passwords', a11y.allPasswordType, 'Every field is a password input capped at 255 characters');
    recorder.assert('fields-labelled', a11y.allLabelled, 'Every field has a visible label');
    recorder.equals('autocomplete-hints', a11y.autocomplete, 'current-password,new-password,new-password,current-password,current-password',
        'Each field carries the right autocomplete hint');
    recorder.equals('error-is-alert', a11y.errorRole, 'alert', 'The error is an alert');
    recorder.equals('error-hidden-when-empty', a11y.errorHiddenWhenEmpty, true, 'And hidden while empty');
    recorder.equals('status-is-live', a11y.statusLive, 'polite', 'The status is a polite live region');
    recorder.assert('close-labelled', a11y.closeLabel.length > 0, 'The close button has a name', a11y.closeLabel);

    await page.evaluate(() => document.getElementById('enpv-encrypt-remove-tab')?.focus());
    const focused = await page.evaluate(() => document.activeElement?.id);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);
    recorder.equals('tab-takes-focus', focused, 'enpv-encrypt-remove-tab', 'A tab can be focused from the keyboard');
    recorder.equals('tab-activates', (await dialogState(page)).activeTab, 'remove', 'Enter on a focused tab switches to it');

    artifacts.push(await capture(page, '14-keyboard-and-aria', 'dialog'));
    return { checks: recorder.checks, artifacts: artifacts.filter(Boolean) };
}

// ---------------------------------------------------------------------------
// Registry and runner
// ---------------------------------------------------------------------------

const TESTS = [
    { id: '01-blank-project-loads', number: '01', title: 'Setup: blank PDF project loads with the Password tool available', run: testBlankProjectLoads },
    { id: '02-dialog-open-close', number: '02', title: 'The dialog opens on Set, focuses New password, and closes via X, scrim and Escape', run: testDialogOpenClose },
    { id: '03-tabs', number: '03', title: 'Set and Remove tabs switch by click and arrow keys, and Remove is inert until a password exists', run: testTabs },
    { id: '04-set-gating', number: '04', title: 'Set is gated on a new password with a matching confirmation, and Enter submits', run: testSetGating },
    { id: '05-set-request', number: '05', title: 'Set: the request carries action, algorithm, persist_protection and both passwords, and the dialog flips to Update', run: testSetRequest },
    { id: '06-protected-reopen', number: '06', title: 'A protected document asks for its password before it renders; a wrong password is refused, the right one opens it', run: testProtectedReopen },
    { id: '07-unlock-cancel-leaves', number: '07', title: 'Cancelling the unlock prompt leaves the editor', run: testUnlockCancelLeaves },
    { id: '08-per-session', number: '08', title: 'Protection is per session: a fresh session must unlock again, and the file URL is 423 without a token', run: testPerSession },
    { id: '09-update', number: '09', title: 'Update: the current password is required and checked; after updating, only the new password unlocks', run: testUpdate },
    { id: '10-remove', number: '10', title: 'Remove: the current password is required and checked; after removing, the document reopens without a prompt', run: testRemove },
    { id: '11-protected-download', number: '11', title: 'Download PDF on a protected document is encrypted with its password, and plain again after removal', run: testProtectedDownload },
    { id: '12-busy-state', number: '12', title: 'A request in progress disables the dialog until it settles', run: testBusyState },
    { id: '13-failure-handling', number: '13', title: "A failed request shows the server's message and leaves the dialog usable", run: testFailureHandling },
    { id: '14-keyboard-and-aria', number: '14', title: 'Keyboard access and ARIA on the dialog, tabs, fields and messages', run: testKeyboardAndAria },
];

function summarise(test, checks, artifacts, error, startedAt) {
    const passed = checks.filter((check) => check.result === 'PASS').length;
    const skipped = checks.filter((check) => check.result === 'SKIP').length;
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
                // A fresh context per test: the unlock token is session state,
                // so nothing may leak from one case into another.
                context = await browser.newContext({
                    viewport: { width: 1500, height: 1000 },
                    ignoreHTTPSErrors: true,
                });

                await context.route(/fonts\.(googleapis|gstatic)\.com/, (route) => {
                    route.fulfill({ status: 200, contentType: 'text/css', body: '' }).catch(() => {});
                });

                const page = await context.newPage();
                const csrfToken = await fetchCsrfToken(page);
                docId = await createBlankDocument(page, csrfToken, `Password tool test ${test.number}`, test.pages || 1);
                const consoleErrors = await openEditor(page, docId);

                const outcome = await test.run(page, { consoleErrors, docId, recorder });
                results.push({
                    ...summarise(test, outcome.checks, outcome.artifacts, null, startedAt),
                    document_id: docId,
                });
            } catch (error) {
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
    MESSAGES,
    buildBlankPdfBuffer,
    pdfPageCount,
    pdfIsEncrypted,
    fetchCsrfToken,
    createBlankDocument,
    openEditor,
    openEditorExpectingUnlock,
    dialogState,
    openDialog,
    closeDialog,
    setTab,
    fill,
    submit,
    setPasswordForReal,
    unlock,
    interceptPost,
    waitForCapture,
    statusText,
    runTests,
};

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
