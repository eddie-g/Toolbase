#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 714);
const ORIGINAL_TEXT = 'CONCERNING THE PROPERTY AT:';
const UPDATED_TEXT = 'sdfdsf';

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

async function restoreOriginal(page) {
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const result = await page.evaluate(async ({ url, csrf }) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        });
        const body = await response.json();
        return { status: response.status, body };
    }, {
        url: `${BASE_URL}/documents/${DOCUMENT_ID}/restore-original`,
        csrf,
    });
    if (result.status !== 200 || !result.body?.success) {
        throw new Error(`restore-original failed: ${JSON.stringify(result)}`);
    }
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
}

async function fetchExtraction(page) {
    return page.evaluate(async ({ baseUrl, documentId }) => {
        const response = await fetch(`${baseUrl}/documents/${documentId}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, { baseUrl: BASE_URL, documentId: DOCUMENT_ID });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1400 } });
    let savePayload = null;

    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/save-edits')) {
            try {
                savePayload = request.postDataJSON();
            } catch (error) {
                savePayload = request.postData();
            }
        }
    });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);

        await restoreOriginal(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);

        await activateOverlay(page);
        await page.waitForTimeout(3000);

        const targetField = page.locator('.overlay-field').filter({ hasText: ORIGINAL_TEXT }).first();
        await targetField.dblclick();
        await page.waitForTimeout(400);
        await targetField.evaluate((el, updatedText) => {
            const editor = el.querySelector('[contenteditable]');
            editor.innerText = updatedText;
            editor.dispatchEvent(new Event('input', { bubbles: true }));
        }, UPDATED_TEXT);
        await page.waitForTimeout(600);

        await page.getByRole('button', { name: /Save PDF/i }).click();
        await page.waitForTimeout(6500);

        if (!savePayload || !Array.isArray(savePayload.edits) || savePayload.edits.length !== 1) {
            throw new Error(`unexpected save payload: ${JSON.stringify(savePayload)}`);
        }

        const edit = savePayload.edits[0];
        if (normalize(edit.original_text) !== ORIGINAL_TEXT) {
            throw new Error(`wrong original_text in payload: ${JSON.stringify(edit)}`);
        }
        if (normalize(edit.new_text) !== UPDATED_TEXT) {
            throw new Error(`wrong new_text in payload: ${JSON.stringify(edit)}`);
        }
        if (Number(edit.font_xref) !== 144) {
            throw new Error(`expected font_xref 144 for subset regression, got: ${JSON.stringify(edit)}`);
        }

        const extraction = await fetchExtraction(page);
        if (!extraction?.success) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
        }

        const pageData = extraction.extraction_data?.[0] || {};
        const lines = (pageData.lines || []).map((line) => normalize(line.text));
        if (!lines.includes(UPDATED_TEXT)) {
            throw new Error(`updated text missing from extraction: ${JSON.stringify(lines.filter((line) => line.includes('sdf') || line.includes('PROPERTY')))}\nfull_payload=${JSON.stringify(edit)}`);
        }

        console.log('doc714 font subset regression passed');
        console.log(JSON.stringify({
            save_edit: {
                original_text: edit.original_text,
                new_text: edit.new_text,
                font_xref: edit.font_xref,
                font: edit.font,
            },
            matching_lines: lines.filter((line) => line.includes('sdf') || line.includes('PROPERTY') || line.includes('ddress')),
        }, null, 2));
    } finally {
        try {
            await restoreOriginal(page);
        } catch (error) {
            console.error(`restore cleanup failed: ${error.message}`);
        }
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
