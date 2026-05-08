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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4024);
const TARGET_ZOOM = process.env.TARGET_ZOOM || '130';
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
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(750);

        await page.waitForFunction((targetZoom) => {
            return document.getElementById('zoom-label')?.textContent?.trim() === `${targetZoom}%`;
        }, TARGET_ZOOM, { timeout: 30000 });
        await page.waitForTimeout(1200);

        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });

        const metrics = await page.evaluate(() => {
            const ids = [
                'pdfjs_4024_0_0:48',
                'pdfjs_4024_0_0:56',
                'pdfjs_4024_0_0:58',
                'pdfjs_4024_0_0:61',
            ];
            return ids.map((id) => {
                const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(id)}"]`);
                if (!box) {
                    return {
                        id,
                        missing: true,
                        availableIds: Array.from(document.querySelectorAll('.enpv-annotation-box[data-annotation-id]'))
                            .slice(0, 20)
                            .map((el) => el.getAttribute('data-annotation-id')),
                    };
                }
                const text = box.querySelector('.enpv-text-content');
                const cs = text ? getComputedStyle(text) : null;
                const boxRect = box.getBoundingClientRect();
                const source = {
                    fontSizePts: Number.parseFloat(box.dataset.fontSizePts || ''),
                    sourceFontSizePx: Number.parseFloat(box.dataset.sourceFontSizePx || ''),
                    sourceLineHeightPx: Number.parseFloat(box.dataset.sourceLineHeightPx || ''),
                };
                return {
                    id,
                    text: text?.textContent || '',
                    editorMode: box.dataset.editorMode || '',
                    boxHeight: boxRect.height,
                    computedFontSizePx: Number.parseFloat(cs?.fontSize || ''),
                    computedLineHeightPx: Number.parseFloat(cs?.lineHeight || ''),
                    cssFontSizeVar: box.style.getPropertyValue('--enpv-font-size'),
                    cssLineHeightVar: box.style.getPropertyValue('--enpv-line-height'),
                    ...source,
                };
            });
        });

        const missing = metrics.filter((m) => m.missing);
        if (missing.length) {
            throw new Error(`Missing expected overlay boxes: ${missing.map((m) => m.id).join(', ')}`);
        }

        const bad = metrics.filter((m) => !(m.computedFontSizePx >= 13.5));
        console.log(JSON.stringify(metrics, null, 2));
        if (bad.length) {
            throw new Error(`Edited overlay font too small at ${TARGET_ZOOM}%: ${bad.map((m) => `${m.id}=${m.computedFontSizePx}px`).join(', ')}`);
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
