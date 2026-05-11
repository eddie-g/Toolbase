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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4032);
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
    const page = await browser.newPage({ viewport: { width: 1800, height: 1100 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="2"]', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.locator('.pdfViewer .page[data-page-number="2"]').scrollIntoViewIfNeeded();
        await page.waitForTimeout(800);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(800);

        const boxes = await page.evaluate(() => Array.from(
            document.querySelectorAll('.pdfViewer .page[data-page-number="2"] .enpv-annotation-box'),
        ).map((box) => ({
            id: box.dataset.annotationId || '',
            text: box.querySelector('.enpv-text-content')?.textContent || box.textContent || '',
        })).filter((box) => /SALES TAX|596\.45/.test(box.text)));

        if (!boxes.some((box) => box.text === 'SALES TAX')) {
            throw new Error(`Missing separate SALES TAX source box: ${JSON.stringify(boxes)}`);
        }
        if (!boxes.some((box) => box.text === '596.45')) {
            throw new Error(`Missing separate 596.45 source box: ${JSON.stringify(boxes)}`);
        }
        if (boxes.some((box) => box.text.includes('SALES TAX') && box.text.includes('596.45'))) {
            throw new Error(`Rendered stale combined SALES TAX source box: ${JSON.stringify(boxes)}`);
        }

        const salesBox = page.locator('.pdfViewer .page[data-page-number="2"] .enpv-annotation-box')
            .filter({ hasText: 'SALES TAX' })
            .first();
        await salesBox.scrollIntoViewIfNeeded();
        const before = await salesBox.boundingBox();
        if (!before) throw new Error('Unable to locate SALES TAX box for drag.');

        await page.mouse.move(before.x + (before.width / 2), before.y + (before.height / 2));
        await page.mouse.down();
        await page.mouse.move(before.x + (before.width / 2) - 80, before.y + (before.height / 2), { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(800);

        const responsePromise = page.waitForResponse((response) => (
            response.url().includes('/download-annotated-pdf')
            && response.request().method() === 'POST'
        ), { timeout: 90000 });
        await page.locator('#download-pdf-btn').click();
        const response = await responsePromise;
        if (!response.ok()) {
            throw new Error(`Download payload request failed with ${response.status()}`);
        }
        const payload = JSON.parse(response.request().postData() || '{}');
        const visibleSalesEntries = (payload.annotations || [])
            .filter((annotation) => /SALES TAX|596\.45/.test(String(annotation.text || annotation.pdfjsSourceText || '')))
            .map((annotation) => ({
                id: annotation.id,
                text: annotation.text,
                source: annotation.pdfjsSourceText,
                anchor: annotation.pdfjsAnchorUid,
            }));

        if (!visibleSalesEntries.some((annotation) => annotation.text === 'SALES TAX' && annotation.source === 'SALES TAX')) {
            throw new Error(`Dragged SALES TAX was not sent as its own annotation: ${JSON.stringify(visibleSalesEntries)}`);
        }
        if (visibleSalesEntries.some((annotation) => (
            String(annotation.text || '').includes('SALES TAX')
            && String(annotation.text || '').includes('596.45')
        ))) {
            throw new Error(`Download payload still contains combined SALES TAX/596.45 annotation: ${JSON.stringify(visibleSalesEntries)}`);
        }

        console.log('doc4032 pdfjs label/value grouping regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
