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

async function measureSelectedTextBox(page) {
    return page.evaluate(() => {
        const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
        const textContent = box?.querySelector('.enpv-text-content');
        if (!box || !textContent) throw new Error('Missing selected editable text box.');
        const boxRect = box.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textContent);
        const rects = Array.from(range.getClientRects()).filter((rect) => rect.width > 0 || rect.height > 0);
        range.detach?.();
        if (!rects.length) throw new Error('Editable text produced no measurable rects.');
        const textRect = {
            left: Math.min(...rects.map((rect) => rect.left)),
            top: Math.min(...rects.map((rect) => rect.top)),
            right: Math.max(...rects.map((rect) => rect.right)),
            bottom: Math.max(...rects.map((rect) => rect.bottom)),
        };
        return {
            text: String(textContent.innerText || textContent.textContent || ''),
            boxWidth: boxRect.width,
            boxHeight: boxRect.height,
            boxLeft: boxRect.left,
            boxTop: boxRect.top,
            boxRight: boxRect.right,
            boxBottom: boxRect.bottom,
            textWidth: textRect.right - textRect.left,
            textHeight: textRect.bottom - textRect.top,
            textLeft: textRect.left,
            textTop: textRect.top,
            textRight: textRect.right,
            textBottom: textRect.bottom,
        };
    });
}

function assertTextInsideBox(metrics, label) {
    const tolerance = 2;
    const ok = metrics.textLeft >= metrics.boxLeft - tolerance
        && metrics.textTop >= metrics.boxTop - tolerance
        && metrics.textRight <= metrics.boxRight + tolerance
        && metrics.textBottom <= metrics.boxBottom + tolerance;
    if (!ok) {
        throw new Error(`${label}: text is outside the selected box: ${JSON.stringify(metrics, null, 2)}`);
    }
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1000);

        await page.locator('#add-text-btn').click();
        await page.waitForSelector('body.enpv-add-text-on', { timeout: 5000 });
        const target = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const rect = pageEl?.getBoundingClientRect();
            if (!rect) return null;
            return { x: rect.left + 120, y: rect.top + 140 };
        });
        if (!target) throw new Error('Could not locate page 1 for add text.');
        await page.mouse.click(target.x, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });

        await page.keyboard.type('SALE');
        await page.waitForTimeout(100);
        const singleLine = await measureSelectedTextBox(page);
        assertTextInsideBox(singleLine, 'single-line fit');

        await page.keyboard.press('Enter');
        await page.keyboard.type('S');
        await page.waitForFunction(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
            const textContent = box?.querySelector('.enpv-text-content');
            return !!box && !!textContent && String(textContent.innerText || textContent.textContent || '').includes('S')
                && box.getBoundingClientRect().height > 24;
        }, null, { timeout: 5000 });
        const multiLine = await measureSelectedTextBox(page);
        assertTextInsideBox(multiLine, 'multi-line fit');
        if (multiLine.boxHeight <= singleLine.boxHeight + 6) {
            throw new Error(`Box did not grow for the second line: ${JSON.stringify({ singleLine, multiLine }, null, 2)}`);
        }

        await page.keyboard.press('Control+A');
        await page.keyboard.type('I');
        await page.waitForFunction((previousHeight) => {
            const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
            const textContent = box?.querySelector('.enpv-text-content');
            return !!box && String(textContent?.innerText || textContent?.textContent || '').trim() === 'I'
                && box.getBoundingClientRect().height < previousHeight - 4;
        }, multiLine.boxHeight, { timeout: 5000 });
        const shrunk = await measureSelectedTextBox(page);
        assertTextInsideBox(shrunk, 'shrunk single-character fit');
        if (shrunk.boxWidth >= multiLine.boxWidth - 4 || shrunk.boxHeight >= multiLine.boxHeight - 4) {
            throw new Error(`Box did not shrink after replacing text: ${JSON.stringify({ multiLine, shrunk }, null, 2)}`);
        }

        console.log(JSON.stringify({ singleLine, multiLine, shrunk }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
