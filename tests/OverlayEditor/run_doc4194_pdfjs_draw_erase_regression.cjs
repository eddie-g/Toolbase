#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4194);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const browserErrors = [];

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const passwords = Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'codex-test-admin-2861',
        'password1',
    ].filter(Boolean)));

    for (const password of passwords) {
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        try {
            await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 5000 });
            return;
        } catch (_) {
            if (!page.url().includes('/admin/login')) return;
        }
    }

    throw new Error('Unable to log in to admin for regression test.');
}

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

function directDrawAnnotations(payload) {
    return (payload?.annotations || [])
        .filter((annotation) => annotation?.type === 'image' && annotation?.imageToolSource === 'direct-draw');
}

async function captureNextSavePayload(page, saveUrl) {
    const savePath = new URL(saveUrl, BASE_URL).pathname;
    let timeout = null;
    let resolvePayload;
    let rejectPayload;
    const payloadPromise = new Promise((resolve, reject) => {
        resolvePayload = resolve;
        rejectPayload = reject;
        timeout = setTimeout(() => reject(new Error('Timed out waiting for intercepted save request')), 15000);
    });
    await page.route((url) => url.pathname === savePath, async (route) => {
        clearTimeout(timeout);
        try {
            const payload = JSON.parse(route.request().postData() || '{}');
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'pdfjs-draw-erase-regression' }),
            });
            resolvePayload(payload);
        } catch (error) {
            rejectPayload(error);
        } finally {
            await page.unroute((url) => url.pathname === savePath).catch(() => {});
        }
    });
    return payloadPromise;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
    page.on('pageerror', (error) => browserErrors.push(error.stack || error.message || String(error)));
    page.on('console', (message) => {
        if (message.type() === 'error') browserErrors.push(message.text());
    });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);

        const toolbarState = await page.evaluate(() => {
            const toolbar = document.querySelector('#floating-tool-bar');
            return {
                drawLabel: document.querySelector('#ftb-draw-erase span')?.textContent?.trim() || '',
                drawEnabled: window.getComputedStyle(document.querySelector('#ftb-draw-erase')).pointerEvents !== 'none',
                drawIconPaths: document.querySelectorAll('#ftb-draw-erase svg path').length,
                signIconPaths: document.querySelectorAll('#ftb-sign svg path').length,
                hasArrow: Boolean(Array.from(toolbar?.querySelectorAll('span') || []).find((span) => span.textContent?.trim() === 'Arrow')),
                hasCheck: Boolean(Array.from(toolbar?.querySelectorAll('span') || []).find((span) => span.textContent?.trim() === 'Check')),
            };
        });
        assertEqual(toolbarState.drawLabel, 'Draw', 'Floating toolbar draw label');
        assertEqual(toolbarState.drawEnabled, true, 'PDF.js draw button should be enabled');
        assert(toolbarState.drawIconPaths >= 3, 'Draw button should use a brush-style icon');
        assert(toolbarState.signIconPaths >= 3, 'Sign button should use a signature-style icon');
        assertEqual(toolbarState.hasArrow, false, 'Floating toolbar should not include Arrow');
        assertEqual(toolbarState.hasCheck, false, 'Floating toolbar should not include Check');

        await page.click('#ftb-draw-erase');
        await page.waitForSelector('#draw-tool-panel.is-visible', { timeout: 5000 });
        await page.waitForFunction(() => document.body.classList.contains('enpv-draw-on'), { timeout: 5000 });
        const panelState = await page.evaluate(() => {
            const toolbar = document.querySelector('#floating-tool-bar')?.getBoundingClientRect();
            const panel = document.querySelector('#draw-tool-panel')?.getBoundingClientRect();
            const style = window.getComputedStyle(document.querySelector('#draw-tool-panel'));
            return {
                hasEraser: Boolean(document.querySelector('#draw-tool-eraser')),
                flexDirection: style.flexDirection,
                panelTop: Math.round(panel?.top || 0),
                toolbarBottom: Math.round(toolbar?.bottom || 0),
                centerDelta: panel && toolbar ? Math.abs((panel.left + (panel.width / 2)) - (toolbar.left + (toolbar.width / 2))) : 999,
            };
        });
        assertEqual(panelState.hasEraser, true, 'Draw panel should include an eraser button');
        assertEqual(panelState.flexDirection, 'row', 'Draw panel should be horizontal');
        assert(panelState.panelTop > panelState.toolbarBottom, `Draw panel should sit below the floating toolbar: ${JSON.stringify(panelState)}`);
        assert(panelState.centerDelta < 4, `Draw panel should be centered under the floating toolbar: ${JSON.stringify(panelState)}`);

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1 for drawing.');
        const startX = pageBox.x + 260;
        const startY = pageBox.y + 240;
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX + 70, startY + 35, { steps: 6 });
        await page.mouse.move(startX + 140, startY + 5, { steps: 6 });
        await page.mouse.up();

        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-image-box.is-selected', { timeout: 10000 });
        const drawnBox = await page.evaluate(() => {
            const box = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-image-box.is-selected');
            const rect = box?.getBoundingClientRect();
            return {
                label: box?.querySelector('.enpv-image-selection-label')?.textContent?.trim() || '',
                zIndex: box?.dataset.zIndex || '',
                imageSrc: box?.querySelector('img.enpv-image-img')?.getAttribute('src') || '',
                width: rect?.width || 0,
                height: rect?.height || 0,
                frontVisible: Boolean(document.querySelector('#enpv-ann-menu [data-action="front"]:not([hidden])')),
                backVisible: Boolean(document.querySelector('#enpv-ann-menu [data-action="back"]:not([hidden])')),
            };
        });
        assertEqual(drawnBox.label, 'drawing', 'Direct-draw image selection label');
        assertEqual(drawnBox.zIndex, '2', 'Direct-draw image should render on the annotation layer below text');
        assert(drawnBox.imageSrc.startsWith('data:image/svg+xml'), 'Vector-backed direct-draw annotations should render as SVG in the editor');
        assert(drawnBox.width > 20 && drawnBox.height > 10, `Unexpected drawing box size: ${JSON.stringify(drawnBox)}`);
        assert(drawnBox.frontVisible && drawnBox.backVisible, 'Direct-draw annotation menu should expose layer controls');

        const saveUrl = await page.locator('#edit-new-root').getAttribute('data-save-url');
        if (!saveUrl) throw new Error('Could not read PDF.js save URL from edit-new root.');
        const firstSave = await captureNextSavePayload(page, saveUrl);
        await page.evaluate(() => document.getElementById('save-btn')?.click());
        const firstPayload = await firstSave;
        const firstDirectDraws = directDrawAnnotations(firstPayload);
        assert(firstDirectDraws.length >= 1, `Save payload did not include a direct-draw image: ${JSON.stringify(firstPayload).slice(0, 1000)}`);
        const initialDataUrl = firstDirectDraws[firstDirectDraws.length - 1].dataUrl || '';
        const vector = firstDirectDraws[firstDirectDraws.length - 1].directDrawVector || null;
        assert(initialDataUrl.startsWith('data:image/png;base64,'), 'Direct-draw annotation should save a PNG data URL');
        assert(vector && Array.isArray(vector.strokes) && vector.strokes.length >= 1, 'Direct-draw annotation should save vector stroke metadata');
        assert(vector.strokes[0].points.length >= 2, 'Direct-draw vector stroke should include path points');

        if (browserErrors.length) {
            throw new Error(`Browser errors during draw panel regression: ${browserErrors.join('\n')}`);
        }

        console.log('PASS pdfjs draw toolbar and payload regression');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
