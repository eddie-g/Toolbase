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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4373);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TARGET_TEXT = 'To change the information on your Social Security number record';

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
    const page = await browser.newPage({ viewport: { width: 2200, height: 1200 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.evaluate(() => {
            const toggle = document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode');
            if (!toggle) throw new Error('Missing PDF.js edit mode toggle');
            if (!document.body.classList.contains('enpv-edit-on')) toggle.click();
        });
        await page.waitForSelector('body.enpv-edit-on', { timeout: 10000 });
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(1000);

        const state = await page.evaluate(({ targetText }) => {
            const collapse = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const rectFor = (box) => {
                const rect = box.getBoundingClientRect();
                return {
                    left: rect.left,
                    top: rect.top,
                    width: rect.width,
                    height: rect.height,
                };
            };
            const centerInside = (outer, inner) => {
                const cx = inner.left + (inner.width / 2);
                const cy = inner.top + (inner.height / 2);
                return cx >= outer.left
                    && cx <= outer.left + outer.width
                    && cy >= outer.top
                    && cy <= outer.top + outer.height;
            };
            const boxes = Array.from(document.querySelectorAll('.pdfViewer .page .enpv-annotation-box'));
            const paragraph = boxes.find((box) => (
                box.classList.contains('is-promoted-source-block')
                && collapse(box.querySelector('.enpv-text-content')?.textContent || '').includes(targetText)
            ));
            if (!paragraph) {
                return { paragraphFound: false, conflicts: [] };
            }
            const paragraphRect = rectFor(paragraph);
            const paragraphText = collapse(paragraph.querySelector('.enpv-text-content')?.textContent || '');
            const conflicts = boxes
                .filter((box) => box !== paragraph && !box.classList.contains('is-promoted-source-block'))
                .map((box) => ({
                    id: box.dataset.annotationId || '',
                    persistedId: box.dataset.persistedAnnotationId || '',
                    className: box.className,
                    text: collapse(box.querySelector('.enpv-text-content')?.textContent || ''),
                    rect: rectFor(box),
                }))
                .filter((box) => {
                    if (!box.text) return false;
                    if (!centerInside(paragraphRect, box.rect)) return false;
                    return paragraphText.includes(box.text);
                });

            return {
                paragraphFound: true,
                paragraphId: paragraph.dataset.annotationId || '',
                paragraphText,
                conflicts,
            };
        }, { targetText: TARGET_TEXT });

        if (!state.paragraphFound) {
            throw new Error(`Missing promoted paragraph block containing: ${TARGET_TEXT}`);
        }
        if (state.conflicts.length) {
            throw new Error(`Promoted paragraph also rendered line-level boxes: ${JSON.stringify(state.conflicts, null, 2)}`);
        }

        console.log(JSON.stringify({
            status: 'pass',
            documentId: DOCUMENT_ID,
            paragraphId: state.paragraphId,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
