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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4025);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

const EXPECTED_CELL_TEXTS = [
    'Name of School Recommending',
    'Name of School Where STEM',
    'SEVIS School Code of School Recommending STEM OPT (including 3-',
];

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
    const page = await browser.newPage({ viewport: { width: 2200, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(500);

        const boxes = await page.evaluate(() => Array.from(
            document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box'),
        ).map((box) => {
            const r = box.getBoundingClientRect();
            return {
                id: box.dataset.annotationId || '',
                text: box.querySelector('.enpv-text-content')?.textContent || '',
                left: r.left,
                top: r.top,
                width: r.width,
            };
        }));

        const tableCells = boxes
            .filter((box) => EXPECTED_CELL_TEXTS.includes(box.text))
            .sort((a, b) => a.left - b.left);

        for (const expectedText of EXPECTED_CELL_TEXTS) {
            if (!tableCells.some((box) => box.text === expectedText)) {
                throw new Error(`Missing separate PDF.js table-cell group: ${expectedText}`);
            }
        }

        const collapsed = boxes.find((box) => (
            box.text.includes(EXPECTED_CELL_TEXTS[0])
            && box.text.includes(EXPECTED_CELL_TEXTS[1])
            && box.text.includes(EXPECTED_CELL_TEXTS[2])
        ));
        if (collapsed) {
            throw new Error(`Table header cells collapsed into one group: ${collapsed.id} ${collapsed.text}`);
        }

        const sameRow = tableCells.every((box) => Math.abs(box.top - tableCells[0].top) <= 3);
        if (!sameRow) {
            throw new Error('Expected table-cell groups are not aligned on the same row.');
        }

        console.log('doc4025 pdfjs table cell grouping regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
