#!/usr/bin/env node
/**
 * Regression test for Add Text mode semantics in /edit-new.
 *
 * When the Text tool is active, clicking the canvas should always start a new
 * text annotation. It must not re-select the previously typed annotation just
 * because the click lands inside that annotation's bounds.
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
    console.log(`[text-mode-spawn] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 1200 } });

    try {
        await loginAdmin(page);
        console.log('[text-mode-spawn] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[text-mode-spawn] blank doc id=${documentId}`);

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
            if (!ae || !oc || !state?.activeState?.uid) throw new Error('missing first active editor');
            const aeRect = ae.getBoundingClientRect();
            const ocRect = oc.getBoundingClientRect();
            return {
                activeUid: state.activeState.uid,
                count: state.pageData[0].annotations.length,
                left: aeRect.left - ocRect.left,
                top: aeRect.top - ocRect.top,
                width: aeRect.width,
                height: aeRect.height,
            };
        });

        const secondClick = {
            x: Math.round(ocBox.x + firstState.left + (firstState.width * 0.45)),
            y: Math.round(ocBox.y + firstState.top + (firstState.height * 0.5)),
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

        console.log('[text-mode-spawn] first state:', JSON.stringify(firstState));
        console.log('[text-mode-spawn] second click:', JSON.stringify(secondClick));
        console.log('[text-mode-spawn] second state:', JSON.stringify(secondState));

        assert(firstState.count === 1, 'first text annotation created', `count=${firstState.count}`);
        assert(secondState.count === 2, 'clicking existing text area creates a new annotation in Add Text mode', `count=${secondState.count}`);
        assert(secondState.activeUid !== firstState.activeUid, 'new annotation becomes active instead of re-selecting the old one', `activeUid=${secondState.activeUid}`);
        assert(String(secondState.activeText || '') === '', 'new annotation starts empty', `text=${JSON.stringify(secondState.activeText)}`);
    } catch (err) {
        console.error('[text-mode-spawn] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[text-mode-spawn] FAILED (${failures.length})`);
        process.exit(1);
    }

    console.log('\n[text-mode-spawn] PASSED');
}

main();
