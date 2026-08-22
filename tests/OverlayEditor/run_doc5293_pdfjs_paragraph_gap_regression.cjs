#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5293);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TARGET_SELECTOR = '.enpv-annotation-box[data-annotation-id="promoted_3_7"]';

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    for (const password of Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'codex-test-admin-2861',
        'password1',
    ].filter(Boolean)))) {
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
    throw new Error('Unable to log in for doc5293 paragraph-gap regression.');
}

async function targetState(page) {
    return page.evaluate((selector) => {
        const box = document.querySelector(selector);
        const text = box?.querySelector('.enpv-text-content');
        const value = String(text?.textContent || '').replace(/\r\n?/g, '\n');
        return {
            present: Boolean(box && text),
            editing: box?.classList.contains('is-editing') || false,
            naturalTextFlow: box?.dataset.naturalTextFlow || '',
            sourceSpanEditActive: box?.dataset.sourceSpanEditActive || '',
            breakCounts: Array.from(text?.querySelectorAll?.('[data-source-span-line="1"]') || [])
                .map((line) => Number.parseInt(line.dataset.sourceLineBreakCount || '0', 10) || 0),
            text: value,
            paragraphGapPreserved: /item 4\.\n\n\d+\.\s+Show an address/.test(value),
        };
    }, TARGET_SELECTOR);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    const checks = [];
    const record = (item, pass, detail = null) => checks.push({ item, pass: Boolean(pass), detail });

    try {
        await loginAdmin(page);
        // Exercise the live edit path without allowing the probe to save.
        await page.route('**/*', async (route) => {
            if (route.request().method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 3 }));
        await page.locator('.pdfViewer .page[data-page-number="3"]').scrollIntoViewIfNeeded();
        await page.locator('#ftb-edit-mode').click();

        const target = page.locator(TARGET_SELECTOR).first();
        await target.waitFor({ state: 'attached', timeout: 90000 });
        await target.scrollIntoViewIfNeeded();
        await target.click({ force: true });
        await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
        await page.waitForSelector(`${TARGET_SELECTOR}.is-editing`, { timeout: 10000 });

        const entered = await targetState(page);
        record('edit_entry_recognizes_blank_paragraph_row',
            entered.breakCounts.join(',') === '1,1,2,0', entered);

        const text = target.locator('.enpv-text-content');
        await text.evaluate((node) => {
            const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            let textNode;
            while ((textNode = walker.nextNode())) {
                const index = String(textNode.nodeValue || '').indexOf('provide');
                if (index < 0) continue;
                const range = document.createRange();
                range.setStart(textNode, index + 'provide'.length);
                range.collapse(true);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                return;
            }
            throw new Error('Could not place the caret in promoted_3_7.');
        });
        await page.keyboard.type('X');
        await page.waitForTimeout(100);

        const mutated = await targetState(page);
        record('first_text_change_keeps_two_newlines_before_next_item',
            mutated.text.includes('provideX')
                && mutated.paragraphGapPreserved
                && mutated.naturalTextFlow === '1', mutated);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        const committed = await targetState(page);
        record('edit_commit_keeps_the_paragraph_gap',
            !committed.editing
                && committed.text.includes('provideX')
                && committed.paragraphGapPreserved, committed);

        const failed = checks.filter((check) => !check.pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            checks,
        }, null, 2));
        if (failed.length) process.exitCode = 1;
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
