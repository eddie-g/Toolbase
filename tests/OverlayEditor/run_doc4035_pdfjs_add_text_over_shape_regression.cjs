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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4035);
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
    const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);

        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1.');

        await page.mouse.move(pageBox.x + 220, pageBox.y + 220);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 460, pageBox.y + 360, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected', { timeout: 10000 });

        const target = await page.evaluate(() => {
            const shape = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            const rect = shape?.getBoundingClientRect();
            if (!rect) return null;
            return {
                x: rect.left + rect.width / 2,
                y: rect.top + rect.height / 2,
                shapeId: shape.dataset.annotationId || '',
            };
        });
        if (!target) throw new Error('Could not locate created shape.');

        await page.click('#add-text-btn');
        await page.waitForSelector('body.enpv-add-text-on', { timeout: 5000 });
        await page.mouse.click(target.x, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });
        await page.keyboard.type('TEXT OVER SHAPE');

        const result = await page.evaluate(({ shapeId }) => {
            const shape = document.querySelector(`.pdfViewer .page[data-page-number="1"] .enpv-shape-box[data-annotation-id="${CSS.escape(shapeId)}"]`);
            const textBox = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box.is-selected.is-editing');
            const textContent = textBox?.querySelector('.enpv-text-content');
            const shapeRect = shape?.getBoundingClientRect();
            const textRect = textBox?.getBoundingClientRect();
            return {
                shapeExists: Boolean(shape),
                text: String(textContent?.innerText || textContent?.textContent || '').trim(),
                textBoxExists: Boolean(textBox),
                textAnchorInsideShape: Boolean(shapeRect && textRect
                    && textRect.left >= shapeRect.left
                    && textRect.left <= shapeRect.right
                    && textRect.top >= shapeRect.top
                    && textRect.top <= shapeRect.bottom),
                textOverlapsShape: Boolean(shapeRect && textRect
                    && Math.min(textRect.right, shapeRect.right) > Math.max(textRect.left, shapeRect.left)
                    && Math.min(textRect.bottom, shapeRect.bottom) > Math.max(textRect.top, shapeRect.top)),
            };
        }, { shapeId: target.shapeId });

        if (!result.shapeExists || !result.textBoxExists || result.text !== 'TEXT OVER SHAPE' || !result.textAnchorInsideShape || !result.textOverlapsShape) {
            throw new Error(`Add Text did not create editable text over the shape: ${JSON.stringify(result)}`);
        }

        console.log('doc4035 pdfjs add-text-over-shape regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
