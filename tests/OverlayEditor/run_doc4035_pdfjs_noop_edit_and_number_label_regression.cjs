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

async function pageTwoBoxes(page) {
    return page.evaluate(() => {
        const pageEl = document.querySelector('.pdfViewer .page[data-page-number="2"]');
        const pageRect = pageEl?.getBoundingClientRect();
        return Array.from(pageEl?.querySelectorAll('.enpv-annotation-box') || []).map((box) => {
            const rect = box.getBoundingClientRect();
            const tc = box.querySelector('.enpv-text-content');
            return {
                text: tc?.textContent || '',
                cls: box.className,
                display: tc ? getComputedStyle(tc).display : '',
                left: pageRect ? rect.left - pageRect.left : 0,
                top: pageRect ? rect.top - pageRect.top : 0,
                width: rect.width,
                height: rect.height,
                selected: box.classList.contains('is-selected'),
                editing: box.classList.contains('is-editing'),
            };
        });
    });
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
        await page.waitForSelector(
            'body.enpv-viewer-ready .pdfViewer .page .textLayer span:not(.markedContent)',
            { timeout: 90000, state: 'attached' },
        );
        await page.waitForTimeout(1000);
        await page.locator('#edit-mode-toggle').click();
        await page.evaluate(() => document.querySelector('.pdfViewer .page[data-page-number="2"]')?.scrollIntoView());
        await page.waitForSelector('.pdfViewer .page[data-page-number="2"] .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(750);

        const boxes = await pageTwoBoxes(page);
        const mergedDependentId = boxes.find((box) => box.text === "6. Military dependent's ID card");
        if (mergedDependentId) {
            throw new Error(`Numbered label was destructively grouped with text: ${JSON.stringify(mergedDependentId)}`);
        }

        const dependentBody = boxes.find((box) => box.text === "Military dependent's ID card");
        if (!dependentBody) {
            throw new Error(`Missing isolated dependent ID body text. Nearby boxes: ${JSON.stringify(boxes.filter((box) => /dependent|Military|^6\\.$/.test(box.text)))}`);
        }
        const dependentLabel = boxes.find((box) => (
            box.text === '6.'
            && Math.abs(box.top - dependentBody.top) < Math.max(8, dependentBody.height * 0.65)
            && box.left < dependentBody.left
        ));
        if (!dependentLabel) {
            throw new Error(`Missing isolated "6." label beside dependent ID body. Body: ${JSON.stringify(dependentBody)}`);
        }

        await page.locator('.pdfViewer .page[data-page-number="2"] .enpv-annotation-box')
            .filter({ hasText: 'U.S. Military card or draft record' })
            .first()
            .dblclick({ force: true });
        await page.waitForTimeout(400);
        await page.mouse.click(50, 50);
        await page.waitForTimeout(700);

        const after = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="2"]');
            const target = Array.from(pageEl?.querySelectorAll('.enpv-annotation-box') || [])
                .find((box) => (box.querySelector('.enpv-text-content')?.textContent || '') === 'U.S. Military card or draft record');
            const tc = target?.querySelector('.enpv-text-content');
            return {
                maskCount: pageEl?.querySelectorAll('.enpv-source-mask,.enpv-orig-mask,.enpv-delete-erase-mask').length || 0,
                target: target ? {
                    cls: target.className,
                    display: tc ? getComputedStyle(tc).display : '',
                    selected: target.classList.contains('is-selected'),
                    editing: target.classList.contains('is-editing'),
                } : null,
            };
        });

        if (after.maskCount !== 0) {
            throw new Error(`No-op source edit left masks behind: ${JSON.stringify(after)}`);
        }
        if (!after.target || after.target.display !== 'none' || after.target.cls.includes('is-source-fidelity')) {
            throw new Error(`No-op source edit left a visible ghost overlay: ${JSON.stringify(after)}`);
        }
        if (after.target.selected || after.target.editing) {
            throw new Error(`No-op source edit did not fully deselect: ${JSON.stringify(after)}`);
        }

        console.log('doc4035 pdfjs no-op edit and number-label regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
