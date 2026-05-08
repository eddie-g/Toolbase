#!/usr/bin/env node
/**
 * Regression test for click-to-create text annotations in /edit-new.
 *
 * On HiDPI displays the overlay canvas backing store is larger than its CSS
 * size. Text creation must use the canvas's logical coordinates, otherwise a
 * click-created text box lands away from the cursor.
 *
 * The test:
 *   1. Logs in and creates a blank Letter/portrait document
 *   2. Opens /edit-new in a deviceScaleFactor=2 context
 *   3. Enables Add Text, clicks the canvas once, and waits for the new editor
 *   4. Asserts the new text box starts at the clicked canvas-local position
 */

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

const POSITION_TOLERANCE_PX = 2.5;
const failures = [];

function assert(cond, label, detail = '') {
    if (cond) {
        console.log(`  ✔ ${label}`);
        return;
    }
    console.log(`  ✘ ${label}${detail ? ` :: ${detail}` : ''}`);
    failures.push({ label, detail });
}

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function createBlankDoc(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const response = await page.evaluate(async () => {
        const r = await fetch('/pdf-tests/create-blank?page_size=Letter&orientation=portrait', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const raw = await r.text();
        let body = null;
        try { body = JSON.parse(raw); } catch (_err) {}
        return { ok: r.ok, status: r.status, body, raw };
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`blank create failed: status=${response.status} body=${String(response.raw || '').slice(0, 400)}`);
    }
    return Number(response.body.document_id);
}

async function main() {
    console.log(`[text-spawn] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1600, height: 1400 },
        deviceScaleFactor: 2,
    });
    const page = await context.newPage();

    const consoleErrors = [];
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(`console.error: ${msg.text()}`); });

    try {
        await loginAdmin(page);
        console.log('[text-spawn] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[text-spawn] blank doc id=${documentId}`);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForFunction(() => !!document.getElementById('oc-1') && !!window.__editorTestState, null, { timeout: 30000 });
        await page.waitForTimeout(1200);

        await page.click('#ftb-add-text');
        await page.waitForFunction(() => window.__editorTestState?.addTextMode === true, null, { timeout: 5000 });

        const canvasBox = await page.locator('#oc-1').boundingBox();
        if (!canvasBox) throw new Error('missing #oc-1 bounding box');

        const click = {
            x: Math.round(canvasBox.x + (canvasBox.width * 0.38)),
            y: Math.round(canvasBox.y + (canvasBox.height * 0.31)),
        };

        await page.mouse.click(click.x, click.y);
        await page.waitForFunction(() => {
            const state = window.__editorTestState;
            return !!(state?.activeState?.uid && document.getElementById('ae-1')?.dataset?.editing === '1');
        }, null, { timeout: 5000 });

        const snapshot = await page.evaluate(({ x, y }) => {
            const oc = document.getElementById('oc-1');
            const ae = document.getElementById('ae-1');
            const state = window.__editorTestState;
            if (!oc || !ae || !state?.activeState?.uid) {
                throw new Error('editor state missing after click');
            }
            const ocRect = oc.getBoundingClientRect();
            const aeRect = ae.getBoundingClientRect();
            const ann = state.pageData[0]?.annotations?.find((item) => item._uid === state.activeState.uid);
            return {
                clickLocalX: x - ocRect.left,
                clickLocalY: y - ocRect.top,
                editorLeft: aeRect.left - ocRect.left,
                editorTop: aeRect.top - ocRect.top,
                editorWidth: aeRect.width,
                editorHeight: aeRect.height,
                activeUid: state.activeState.uid,
                annPdfX: ann?.pdfX ?? null,
                annPdfY: ann?.pdfY ?? null,
            };
        }, click);

        console.log('[text-spawn] snapshot:', JSON.stringify(snapshot));
        assert(snapshot.editorWidth > 0, 'text editor rendered', `width=${snapshot.editorWidth}`);
        assert(snapshot.editorHeight > 0, 'text editor has height', `height=${snapshot.editorHeight}`);
        assert(
            Math.abs(snapshot.editorLeft - snapshot.clickLocalX) <= POSITION_TOLERANCE_PX,
            `editor left matches click within ${POSITION_TOLERANCE_PX}px`,
            `editorLeft=${snapshot.editorLeft.toFixed(2)} clickX=${snapshot.clickLocalX.toFixed(2)}`
        );
        assert(
            Math.abs(snapshot.editorTop - snapshot.clickLocalY) <= POSITION_TOLERANCE_PX,
            `editor top matches click within ${POSITION_TOLERANCE_PX}px`,
            `editorTop=${snapshot.editorTop.toFixed(2)} clickY=${snapshot.clickLocalY.toFixed(2)}`
        );

        if (consoleErrors.length) {
            console.log('[text-spawn] console errors during run:');
            consoleErrors.slice(0, 10).forEach((entry) => console.log('  ', entry));
        }
    } catch (err) {
        console.error('[text-spawn] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[text-spawn] FAILED (${failures.length})`);
        process.exit(1);
    }

    console.log('\n[text-spawn] PASSED');
}

main();
