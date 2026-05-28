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

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);

        const saveUrl = await page.evaluate(() => document.getElementById('edit-new-root')?.dataset?.saveUrl || '');
        if (!saveUrl) throw new Error('save URL missing from edit-new-root dataset');
        await page.route(saveUrl, async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_shape_cut_test' }),
            });
        });

        const saveRequests = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && request.url() === saveUrl) saveRequests.push(request);
        });

        const beforeCount = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box').count();
        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        await page.click('[data-shape-tool="square"]');

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1 for shape drawing.');
        await page.mouse.move(pageBox.x + 220, pageBox.y + 190);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 420, pageBox.y + 330, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]', { timeout: 10000 });

        const drawn = await page.evaluate(() => {
            const box = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]');
            if (!box) throw new Error('Missing selected square after draw.');
            const rect = box.getBoundingClientRect();
            return {
                id: box.dataset.annotationId || '',
                rect: { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom, width: rect.width, height: rect.height },
            };
        });
        if (!drawn.id) throw new Error('Drawn square has no annotation id.');

        const cutButton = page.locator('#enpv-ann-menu [data-action="cut"]');
        await cutButton.waitFor({ state: 'visible', timeout: 5000 });
        await cutButton.click();
        await page.waitForFunction(() => document.body.classList.contains('enpv-shape-cut-armed'), { timeout: 5000 });
        const cutButtonActive = await cutButton.evaluate((button) => button.classList.contains('is-active'));
        if (!cutButtonActive) throw new Error('Shape cut menu button did not enter active state.');

        const cutY = drawn.rect.top + (drawn.rect.height / 2);
        await page.mouse.move(drawn.rect.left - 30, cutY);
        await page.mouse.down();
        await page.mouse.move(drawn.rect.right + 30, cutY, { steps: 12 });
        await page.waitForSelector('.enpv-shape-cut-overlay line', { state: 'attached', timeout: 5000 });
        await page.mouse.up();

        await page.waitForFunction(({ originalId, before }) => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const boxes = Array.from(pageEl?.querySelectorAll('.enpv-shape-box') || []);
            return boxes.length === before + 2
                && !boxes.some((box) => box.dataset.annotationId === originalId)
                && boxes.filter((box) => box.dataset.shapeType === 'polygon').length >= 2;
        }, { originalId: drawn.id, before: beforeCount }, { timeout: 10000 });

        const afterCut = await page.evaluate((originalId) => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const boxes = Array.from(pageEl?.querySelectorAll('.enpv-shape-box') || []);
            const polygons = boxes
                .filter((box) => box.dataset.shapeType === 'polygon')
                .map((box) => ({ id: box.dataset.annotationId || '', selected: box.classList.contains('is-selected') }));
            return {
                bodyArmed: document.body.classList.contains('enpv-shape-cut-armed'),
                originalExists: boxes.some((box) => box.dataset.annotationId === originalId),
                count: boxes.length,
                polygons,
                overlayCount: document.querySelectorAll('.enpv-shape-cut-overlay').length,
            };
        }, drawn.id);

        if (afterCut.bodyArmed || afterCut.originalExists || afterCut.count !== beforeCount + 2 || afterCut.polygons.length < 2 || afterCut.overlayCount !== 0) {
            throw new Error(`Shape cut did not replace one square with two polygon annotations: ${JSON.stringify(afterCut)}`);
        }

        await page.click('#save-btn');
        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 30000 });
        if (!saveRequests.length) throw new Error('No save request was captured after cutting shape.');
        const payload = saveRequests[saveRequests.length - 1].postDataJSON();
        const savedAnnotations = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ];
        const savedPieces = savedAnnotations.filter((annotation) => annotation.type === 'shape' && annotation.shapeType === 'polygon' && afterCut.polygons.some((piece) => piece.id === annotation.id));
        const deletedIds = [
            ...(Array.isArray(payload.deleted_annotation_ids) ? payload.deleted_annotation_ids : []),
            ...(Array.isArray(payload.deletedAnnotationIds) ? payload.deletedAnnotationIds : []),
        ].map(String);
        if (savedPieces.length < 2 || !deletedIds.includes(String(drawn.id))) {
            throw new Error(`Shape cut save payload missing pieces or original deletion: ${JSON.stringify({ savedPieces, deletedIds, originalId: drawn.id }).slice(0, 2000)}`);
        }

        console.log('doc4194 pdfjs shape cut menu regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
