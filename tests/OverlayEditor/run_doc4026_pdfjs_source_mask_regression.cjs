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
const TARGET_ID = process.env.TARGET_ID || 'pdfjs_4026_0_0:6';
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
    const page = await browser.newPage({ viewport: { width: 2200, height: 1100 } });

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
        await page.waitForTimeout(1000);

        const beforeRect = await page.evaluate((targetId) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            if (!box) return null;
            const pageDiv = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const pageRect = pageDiv?.getBoundingClientRect();
            const rect = box.getBoundingClientRect();
            return {
                left: pageRect ? rect.left - pageRect.left : rect.left,
                top: pageRect ? rect.top - pageRect.top : rect.top,
                right: pageRect ? rect.right - pageRect.left : rect.right,
                bottom: pageRect ? rect.bottom - pageRect.top : rect.bottom,
                width: rect.width,
                height: rect.height,
                x: rect.left + (rect.width / 2),
                y: rect.top + (rect.height / 2),
            };
        }, TARGET_ID);
        if (beforeRect) {
            const point = beforeRect;
            await page.mouse.move(point.x, point.y);
            await page.mouse.down();
            await page.mouse.move(point.x + 36, point.y + 18, { steps: 8 });
            await page.mouse.up();
            await page.waitForTimeout(500);
        }

        const result = await page.evaluate((targetId) => {
            const pageDiv = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const pageRect = pageDiv?.getBoundingClientRect();
            const rel = (rect) => rect && pageRect ? {
                left: rect.left - pageRect.left,
                top: rect.top - pageRect.top,
                right: rect.right - pageRect.left,
                bottom: rect.bottom - pageRect.top,
                width: rect.width,
                height: rect.height,
            } : null;
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            const candidates = Array.from(document.querySelectorAll('.enpv-annotation-box[data-annotation-id]'))
                .map((el) => ({
                    id: el.getAttribute('data-annotation-id'),
                    text: el.querySelector('.enpv-text-content')?.textContent || '',
                    rect: rel(el.getBoundingClientRect()),
                    dataset: {
                        sourceBboxX: el.dataset.sourceBboxX,
                        sourceBboxY: el.dataset.sourceBboxY,
                        sourceBboxW: el.dataset.sourceBboxW,
                        sourceBboxH: el.dataset.sourceBboxH,
                    },
                }))
                .filter((entry) => entry.id === targetId || entry.text.trim() === '25' || entry.text.trim() === '20');
            if (!box) {
                return { found: false, candidates };
            }
            const maskSummary = (el, index) => ({
                index,
                rect: rel(el.getBoundingClientRect()),
                previousId: el.previousElementSibling?.getAttribute?.('data-annotation-id') || '',
                nextId: el.nextElementSibling?.getAttribute?.('data-annotation-id') || '',
                childCount: el.childElementCount,
                children: Array.from(el.children).map((child) => ({
                    top: child.style.top,
                    height: child.style.height,
                })),
                background: getComputedStyle(el).backgroundColor,
            });
            const masks = Array.from(document.querySelectorAll('.enpv-source-mask')).map((el, index) => maskSummary(el, index));
            const mask = box._enpvSourceMask
                || (box.previousElementSibling?.classList?.contains('enpv-source-mask') ? box.previousElementSibling : null)
                || masks.find((entry) => entry.nextId === targetId);
            return {
                found: true,
                target: {
                    id: box.getAttribute('data-annotation-id'),
                    text: box.querySelector('.enpv-text-content')?.textContent || '',
                    rect: rel(box.getBoundingClientRect()),
                    dataset: {
                        sourceBboxX: box.dataset.sourceBboxX,
                        sourceBboxY: box.dataset.sourceBboxY,
                        sourceBboxW: box.dataset.sourceBboxW,
                        sourceBboxH: box.dataset.sourceBboxH,
                    },
                },
                mask: mask instanceof HTMLElement ? rel(mask.getBoundingClientRect()) : (mask?.rect || null),
                masks: masks.filter((entry) => entry.nextId === targetId || entry.previousId === targetId).concat(masks.slice(0, 10)),
                candidates,
            };
        }, TARGET_ID);

        if (!result.found) {
            throw new Error(`Target annotation ${TARGET_ID} was not rendered.`);
        }
        if (!result.mask) {
            throw new Error(`Target annotation ${TARGET_ID} did not attach a source mask after drag.`);
        }
        if (!beforeRect) {
            throw new Error(`Could not measure ${TARGET_ID} before drag.`);
        }
        if (result.mask.bottom > beforeRect.bottom - 0.5) {
            throw new Error(`Source mask still overlaps the lower box edge: mask.bottom=${result.mask.bottom}, original.bottom=${beforeRect.bottom}`);
        }
        console.log(JSON.stringify({
            id: TARGET_ID,
            originalBottom: beforeRect.bottom,
            maskBottom: result.mask.bottom,
            maskHeight: result.mask.height,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
