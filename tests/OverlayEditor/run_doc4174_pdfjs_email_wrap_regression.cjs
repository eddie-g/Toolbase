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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4174);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TARGET_EMAIL_ID = `pdfjs_${DOCUMENT_ID}_0_new_f9569c40-abe3-4a43-b99e-a811640d281a`;

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
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('body.enpv-edit-on', { timeout: 5000 });
        await page.waitForTimeout(500);

        const metrics = await page.evaluate((emailId) => {
            const box = document.querySelector(`[data-annotation-id="${CSS.escape(emailId)}"]`);
            const textContent = box?.querySelector('.enpv-text-content');
            if (!box || !textContent) return null;
            const boxRect = box.getBoundingClientRect();
            const range = document.createRange();
            range.selectNodeContents(textContent);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 0 && rect.height > 0)
                .map((rect) => ({
                    left: rect.left,
                    top: rect.top,
                    right: rect.right,
                    bottom: rect.bottom,
                    width: rect.width,
                    height: rect.height,
                }));
            range.detach?.();
            const style = getComputedStyle(textContent);
            return {
                text: String(textContent.innerText || textContent.textContent || ''),
                box: {
                    left: boxRect.left,
                    top: boxRect.top,
                    right: boxRect.right,
                    bottom: boxRect.bottom,
                    width: boxRect.width,
                    height: boxRect.height,
                },
                className: box.className,
                display: style.display,
                whiteSpace: style.whiteSpace,
                lineCount: new Set(rects.map((rect) => Math.round(rect.top))).size,
                textRight: rects.length ? Math.max(...rects.map((rect) => rect.right)) : 0,
                textBottom: rects.length ? Math.max(...rects.map((rect) => rect.bottom)) : 0,
                rects,
            };
        }, TARGET_EMAIL_ID);

        if (!metrics) throw new Error(`Missing target email annotation ${TARGET_EMAIL_ID}`);
        if (metrics.lineCount !== 1) {
            throw new Error(`Email annotation wrapped in edit mode: ${JSON.stringify(metrics, null, 2)}`);
        }
        if (metrics.textRight > metrics.box.right + 2 || metrics.textBottom > metrics.box.bottom + 2) {
            throw new Error(`Email annotation overflowed its box: ${JSON.stringify(metrics, null, 2)}`);
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
