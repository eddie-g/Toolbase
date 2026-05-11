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
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector(
            'body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] .textLayer span:not(.markedContent)',
            { timeout: 90000, state: 'attached' },
        );
        await page.waitForTimeout(1000);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(750);

        const headerBoxes = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            return Array.from(pageEl?.querySelectorAll('.enpv-annotation-box') || [])
                .map((box) => {
                    const pageRect = pageEl.getBoundingClientRect();
                    const rect = box.getBoundingClientRect();
                    return {
                        uid: box.dataset.uid || '',
                        id: box.dataset.annotationId || '',
                        text: box.querySelector('.enpv-text-content')?.textContent || '',
                        left: rect.left - pageRect.left,
                        top: rect.top - pageRect.top,
                        width: rect.width,
                        height: rect.height,
                    };
                })
                .filter((box) => box.top < 240 && box.left < 1350);
        });

        const destructiveMerge = headerBoxes.find((box) => (
            box.text.includes('Form1040-NR')
            || box.text.includes('1040-NR U.S. Nonresident')
            || box.text.includes('Form1040-NR U.S. Nonresident')
        ));
        if (destructiveMerge) {
            throw new Error(`Mixed-typography header was grouped into one destructive box: ${JSON.stringify(destructiveMerge)}`);
        }

        const expected = [
            'Form',
            '1040-NR',
            'Department of the Treasury—Internal Revenue Service',
            'U.S. Nonresident Alien Income Tax Return',
        ];
        for (const text of expected) {
            const box = headerBoxes.find((candidate) => candidate.text === text);
            if (!box) throw new Error(`Missing isolated header box "${text}". Boxes: ${JSON.stringify(headerBoxes)}`);
            if (text === 'U.S. Nonresident Alien Income Tax Return' && box.width > 850) {
                throw new Error(`Title box still spans too much header area: ${JSON.stringify(box)}`);
            }
        }

        console.log('doc4026 pdfjs mixed typography grouping regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
