#!/usr/bin/env node
/**
 * Regression test for sequential Add Text creation in /edit-new.
 *
 * A click-created text annotation used to keep its full default 200pt width
 * after typing a short word, so a nearby follow-up click still landed inside
 * that invisible active editor and failed to spawn a second text box.
 *
 * The test:
 *   1. Creates a blank document and opens /edit-new
 *   2. Enables Add Text, clicks once, types "hello"
 *   3. Clicks a nearby point that should count as blank canvas after the box
 *      auto-fits to the typed text
 *   4. Verifies a second text annotation is created
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
    console.log(`[text-sequential] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 1200 } });

    const consoleErrors = [];
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(`console.error: ${msg.text()}`); });

    try {
        await loginAdmin(page);
        console.log('[text-sequential] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[text-sequential] blank doc id=${documentId}`);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForFunction(() => !!window.__editorTestState && !!document.getElementById('oc-1'), null, { timeout: 30000 });
        await page.waitForTimeout(1000);

        await page.click('#ftb-add-text');
        await page.waitForFunction(() => window.__editorTestState?.addTextMode === true, null, { timeout: 5000 });

        const ocBox = await page.locator('#oc-1').boundingBox();
        if (!ocBox) throw new Error('missing #oc-1 bounding box');

        const firstClick = {
            x: Math.round(ocBox.x + 300),
            y: Math.round(ocBox.y + 520),
        };

        await page.mouse.click(firstClick.x, firstClick.y);
        await page.waitForFunction(() => !!window.__editorTestState?.activeState?.uid && document.getElementById('ae-1')?.dataset?.editing === '1', null, { timeout: 5000 });
        await page.keyboard.type('hello');
        await page.waitForTimeout(150);

        const firstState = await page.evaluate(() => {
            const ae = document.getElementById('ae-1');
            const oc = document.getElementById('oc-1');
            const state = window.__editorTestState;
            if (!ae || !oc || !state?.activeState?.uid) throw new Error('missing active editor');
            const aeRect = ae.getBoundingClientRect();
            const ocRect = oc.getBoundingClientRect();
            return {
                activeUid: state.activeState.uid,
                count: state.pageData[0].annotations.length,
                editorLeft: aeRect.left - ocRect.left,
                editorTop: aeRect.top - ocRect.top,
                editorWidth: aeRect.width,
                editorHeight: aeRect.height,
            };
        });

        const secondClick = {
            x: Math.round(ocBox.x + firstState.editorLeft + 140),
            y: Math.round(ocBox.y + firstState.editorTop + (firstState.editorHeight / 2)),
        };

        await page.mouse.click(secondClick.x, secondClick.y);
        await page.waitForFunction((previousUid) => {
            const state = window.__editorTestState;
            return (state?.pageData?.[0]?.annotations?.length || 0) >= 2
                && state?.activeState?.uid
                && state.activeState.uid !== previousUid;
        }, firstState.activeUid, { timeout: 5000 });

        const secondState = await page.evaluate(() => ({
            count: window.__editorTestState.pageData[0].annotations.length,
            activeUid: window.__editorTestState.activeState.uid,
            activeText: window.__editorTestState.editedTexts[window.__editorTestState.activeState.uid] ?? '',
        }));

        console.log('[text-sequential] first state:', JSON.stringify(firstState));
        console.log('[text-sequential] second state:', JSON.stringify(secondState));
        console.log('[text-sequential] second click:', JSON.stringify(secondClick));

        assert(firstState.count === 1, 'first text annotation created', `count=${firstState.count}`);
        assert(firstState.editorWidth < 200, 'first text box auto-fits narrower than default', `width=${firstState.editorWidth}`);
        assert(secondState.count === 2, 'second click creates a new text annotation', `count=${secondState.count}`);
        assert(secondState.activeUid !== firstState.activeUid, 'new annotation becomes active', `activeUid=${secondState.activeUid}`);
        assert(String(secondState.activeText || '') === '', 'new annotation starts empty', `text=${JSON.stringify(secondState.activeText)}`);

        if (consoleErrors.length) {
            console.log('[text-sequential] console errors during run:');
            consoleErrors.slice(0, 10).forEach((entry) => console.log('  ', entry));
        }
    } catch (err) {
        console.error('[text-sequential] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[text-sequential] FAILED (${failures.length})`);
        process.exit(1);
    }

    console.log('\n[text-sequential] PASSED');
}

main();
