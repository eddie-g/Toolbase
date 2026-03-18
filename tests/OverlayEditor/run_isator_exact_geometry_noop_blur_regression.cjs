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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'isrator', 'isator.pdf');
const TARGET_TEXT = 'This is the documentation for the Isartor';

function normalizeText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1000);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/, { timeout: 90000 });
    await page.waitForTimeout(2500);

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }
    return Number(match[1]);
}

async function activateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('Missing overlay mode toggle');
        }
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));
    });

    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 30000 });
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 30000 });
    await page.waitForTimeout(1500);
}

async function captureFieldState(locator) {
    return locator.evaluate((field) => {
        const key = String(field.dataset.wordIndex || '');
        const textEl = field.querySelector('[contenteditable]') || field;
        return {
            key,
            className: field.className,
            flowNormalized: field.dataset.flowNormalized || null,
            hasPendingEdit: overlayEditedFields.has(key),
            hasAbsoluteSpans: Array.from(textEl.querySelectorAll('span')).some((span) => span.style.position === 'absolute'),
            contentEditable: textEl.contentEditable,
            html: textEl.innerHTML,
            text: textEl.innerText || textEl.textContent || '',
        };
    });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1400 } });
    let saveEditsRequestCount = 0;

    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/save-edits')) {
            saveEditsRequestCount += 1;
        }
    });

    try {
        const documentId = await uploadPdf(page);
        await activateOverlay(page);

        const locator = page.locator('.overlay-field').filter({ hasText: TARGET_TEXT }).first();
        if (await locator.count() === 0) {
            throw new Error(`Missing overlay field with text containing: ${TARGET_TEXT}`);
        }

        const before = await captureFieldState(locator);
        if (!before.hasAbsoluteSpans) {
            throw new Error(`Expected exact-geometry field before edit, got: ${JSON.stringify(before, null, 2)}`);
        }

        await locator.click();
        await page.waitForTimeout(250);
        await locator.dblclick();
        await page.waitForTimeout(600);
        await page.mouse.click(20, 20);
        await page.waitForTimeout(1000);

        const after = await captureFieldState(locator);
        if (after.hasPendingEdit) {
            throw new Error(`No-op double click created a pending overlay edit: ${JSON.stringify(after, null, 2)}`);
        }
        if (after.flowNormalized !== null) {
            throw new Error(`Exact-geometry field stayed flow-normalized after blur: ${JSON.stringify(after, null, 2)}`);
        }
        if (!after.hasAbsoluteSpans) {
            throw new Error(`Exact-geometry field did not restore absolute spans after blur: ${JSON.stringify(after, null, 2)}`);
        }
        if (normalizeText(after.text) !== normalizeText(before.text)) {
            throw new Error(`Field text changed after no-op double click: ${JSON.stringify({ before, after }, null, 2)}`);
        }

        await page.locator('#save-btn').click();
        await page.waitForTimeout(1500);

        if (saveEditsRequestCount !== 0) {
            throw new Error(`No-op double click still triggered ${saveEditsRequestCount} overlay save request(s)`);
        }

        console.log('isator exact geometry no-op blur regression passed');
        console.log(JSON.stringify({
            documentId,
            targetText: TARGET_TEXT,
            before: {
                flowNormalized: before.flowNormalized,
                hasPendingEdit: before.hasPendingEdit,
                hasAbsoluteSpans: before.hasAbsoluteSpans,
            },
            after: {
                flowNormalized: after.flowNormalized,
                hasPendingEdit: after.hasPendingEdit,
                hasAbsoluteSpans: after.hasAbsoluteSpans,
            },
            saveEditsRequestCount,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});