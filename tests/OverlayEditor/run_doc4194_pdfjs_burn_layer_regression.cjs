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

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

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

    throw new Error('Unable to log in to admin for burn-layer regression test.');
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });
    const browserErrors = [];
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

        const urls = await page.evaluate(() => ({
            save: document.getElementById('edit-new-root')?.dataset?.saveUrl || '',
            burn: document.getElementById('enpv-root')?.dataset?.burnUrl || '',
            pdf: document.getElementById('enpv-root')?.dataset?.currentPdfUrl || '',
        }));
        assert(urls.save && urls.burn && urls.pdf, `Missing editor URL dataset: ${JSON.stringify(urls)}`);
        const pdfResponse = await page.request.get(new URL(urls.pdf, BASE_URL).toString());
        assert(pdfResponse.ok(), `Could not fetch fixture PDF: ${pdfResponse.status()}`);
        const fixturePdf = await pdfResponse.body();
        const burnRequests = [];

        await page.route(new URL(urls.save, BASE_URL).toString(), async (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_burn_layer_test' }),
        }));
        await page.route(new URL(urls.burn, BASE_URL).toString(), async (route) => {
            burnRequests.push(route.request());
            await route.fulfill({
                status: 200,
                contentType: 'application/pdf',
                body: fixturePdf,
            });
        });
        page.on('dialog', async (dialog) => {
            assert(dialog.type() === 'confirm', `Unexpected dialog type: ${dialog.type()}`);
            await dialog.accept();
        });

        await page.evaluate(() => document.querySelector('#add-shape-btn')?.click());
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        await page.click('[data-shape-tool="square"]');
        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        assert(pageBox, 'Could not locate page 1 for shape drawing.');
        await page.mouse.move(pageBox.x + 260, pageBox.y + 210);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 455, pageBox.y + 350, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]', { timeout: 10000 });

        const shapeId = await page.evaluate(() => (
            document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]')
                ?.dataset?.annotationId || ''
        ));
        assert(shapeId, 'Drawn square has no annotation id.');
        await page.locator('#enpv-ann-menu [data-action="burn"]').waitFor({ state: 'visible', timeout: 5000 });
        assert(!(await page.locator('#undo-btn').isDisabled()), 'Adding a square should make undo available before burn.');

        await page.evaluate(() => document.querySelector('#add-shape-btn')?.click());
        await page.click('#ftb-draw-erase');
        await page.waitForSelector('#draw-tool-panel.is-visible', { timeout: 5000 });
        await page.mouse.move(pageBox.x + 570, pageBox.y + 225);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 660, pageBox.y + 280, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-image-box.is-selected', { timeout: 10000 });
        const drawingId = await page.evaluate(() => (
            document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-image-box.is-selected')
                ?.dataset?.annotationId || ''
        ));
        assert(drawingId, 'Drawn pen stroke has no annotation id.');
        await page.locator('#enpv-ann-menu [data-action="burn"]').waitFor({ state: 'visible', timeout: 5000 });
        await page.click('#ftb-draw-erase');

        await page.click('#enpv-layers-open');
        await page.waitForSelector('#enpv-layers-panel:not([hidden])');
        await page.locator(
            `#enpv-layers-list .enpv-layer-row[data-annotation-id="${drawingId}"] `
            + '.enpv-layer-icon-button[aria-label="Burn into PDF"]',
        ).waitFor({ state: 'visible', timeout: 5000 });
        const burnButton = page.locator(
            `#enpv-layers-list .enpv-layer-row[data-annotation-id="${shapeId}"] `
            + '.enpv-layer-icon-button[aria-label="Burn into PDF"]',
        );
        await burnButton.waitFor({ state: 'visible', timeout: 5000 });
        await burnButton.click();
        await page.waitForFunction((id) => {
            const row = document.querySelector(`#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(id)}"]`);
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(id)}"]`);
            return !row && !box && document.querySelector('#undo-btn')?.disabled === true;
        }, shapeId, { timeout: 30000 });
        await page.waitForFunction(() => (
            /Layer burned into PDF/.test(document.querySelector('#enpv-status')?.textContent || '')
        ), { timeout: 30000 });

        assert(burnRequests.length === 1, `Expected one burn request, received ${burnRequests.length}.`);
        const multipart = burnRequests[0].postData() || '';
        assert(multipart.includes(shapeId), 'Burn request did not include the selected shape annotation.');
        const finalState = await page.evaluate((preservedDrawingId) => ({
            selected: document.querySelectorAll('.enpv-annotation-box.is-selected').length,
            undoDisabled: document.querySelector('#undo-btn')?.disabled,
            redoDisabled: document.querySelector('#redo-btn')?.disabled,
            drawingRowPreserved: Boolean(document.querySelector(
                `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(preservedDrawingId)}"]`,
            )),
            textBurnButtonsVisible: [...document.querySelectorAll('#enpv-layers-list .enpv-layer-row')]
                .filter((row) => row.querySelector('.enpv-layer-kind-icon svg rect[stroke-dasharray]'))
                .some((row) => {
                    const button = row.querySelector('[aria-label="Burn into PDF"]');
                    return button && window.getComputedStyle(button).display !== 'none';
                }),
        }), drawingId);
        assert(finalState.selected === 0, 'Burned layer should not leave a selected annotation box.');
        assert(finalState.undoDisabled === true && finalState.redoDisabled === true, 'Burn should clear undo and redo history.');
        assert(finalState.drawingRowPreserved === true, 'Burn reload should preserve unrelated in-memory drawing layers.');
        assert(finalState.textBurnButtonsVisible === false, 'Text rows must not expose burn actions.');
        if (browserErrors.length) throw new Error(`Browser errors:\n${browserErrors.join('\n')}`);
        console.log('PASS doc4194 pdfjs burn-layer hover menu, Layers action, and irreversible editor state regression');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
