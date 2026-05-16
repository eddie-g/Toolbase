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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4026);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TEST_TEXT = "Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.";

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
    const context = await browser.newContext({ viewport: { width: 1900, height: 1000 } });
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE_URL });
    const page = await context.newPage();

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });

        await page.locator('#add-text-btn').click();
        await page.waitForSelector('body.enpv-add-text-on', { timeout: 5000 });
        const target = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const rect = pageEl?.getBoundingClientRect();
            if (!rect) return null;
            return { x: rect.left + 120, y: rect.top + 160 };
        });
        if (!target) throw new Error('Could not locate page 1 for add text.');

        const drawnSize = { width: 360, height: 110 };
        await page.mouse.move(target.x, target.y);
        await page.mouse.down();
        await page.mouse.move(target.x + drawnSize.width, target.y + drawnSize.height, { steps: 8 });
        await page.mouse.up();
        const editor = page.locator('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]').first();
        await editor.waitFor({ timeout: 10000 });
        await page.evaluate((text) => {
            const targetEditor = document.querySelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]');
            if (!targetEditor) throw new Error('Missing editor for paste event.');
            targetEditor.focus();
            const clipboardData = new DataTransfer();
            clipboardData.setData('text/plain', text);
            clipboardData.setData('text/html', `<p><strong>${text}</strong></p>`);
            targetEditor.dispatchEvent(new ClipboardEvent('paste', {
                bubbles: true,
                cancelable: true,
                clipboardData,
            }));
        }, TEST_TEXT);
        await page.waitForTimeout(500);

        const metrics = await page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
            const textContent = box?.querySelector('.enpv-text-content');
            const pageEl = box?.closest('.pdfViewer .page');
            if (!box || !textContent || !pageEl) {
                throw new Error('Missing pasted text box.');
            }
            const boxRect = box.getBoundingClientRect();
            const pageRect = pageEl.getBoundingClientRect();
            const range = document.createRange();
            range.selectNodeContents(textContent);
            const tops = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 0 && rect.height > 0)
                .map((rect) => Math.round(rect.top));
            range.detach?.();
            return {
                textLength: String(textContent.innerText || textContent.textContent || '').length,
                boxWidth: boxRect.width,
                boxHeight: boxRect.height,
                pageWidth: pageRect.width,
                boxRightWithinPage: boxRect.right <= pageRect.right + 1,
                visualLineCount: new Set(tops).size,
                whiteSpace: getComputedStyle(textContent).whiteSpace,
                richMarkupCount: textContent.querySelectorAll('strong,b,span,font,[style]').length,
                fontSizePts: Number(box.dataset.fontSizePts || 0),
                overflow: getComputedStyle(textContent).overflow,
                userSizedTextBox: box.dataset.userSizedTextBox,
            };
        });

        if (metrics.textLength < 500) {
            throw new Error(`Pasted text was not inserted: ${JSON.stringify(metrics)}`);
        }
        if (!metrics.boxRightWithinPage) {
            throw new Error(`Pasted text box overflowed the page: ${JSON.stringify(metrics)}`);
        }
        if (metrics.visualLineCount < 2) {
            throw new Error(`Pasted text did not wrap: ${JSON.stringify(metrics)}`);
        }
        if (metrics.richMarkupCount !== 0) {
            throw new Error(`Pasted text kept rich markup: ${JSON.stringify(metrics)}`);
        }
        if (Math.abs(metrics.boxWidth - drawnSize.width) > 4 || Math.abs(metrics.boxHeight - drawnSize.height) > 4) {
            throw new Error(`Pasted text box did not keep drawn size: ${JSON.stringify(metrics)}`);
        }
        if (metrics.fontSizePts !== 12) {
            throw new Error(`Pasted text did not use 12pt font: ${JSON.stringify(metrics)}`);
        }
        if (metrics.userSizedTextBox !== '1' || metrics.overflow !== 'hidden') {
            throw new Error(`Pasted text is not confined to the drawn box: ${JSON.stringify(metrics)}`);
        }

        console.log(JSON.stringify(metrics, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
