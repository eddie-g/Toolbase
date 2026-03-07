#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 711);
const ORIGINAL_LINE = '3.  Install new Deck Board.';
const UPDATED_LINE = '3.  Install new Deck Board. 123';

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

async function fetchOverlayFields(page) {
    return page.evaluate(() => {
        const squash = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        return Array.from(document.querySelectorAll('.overlay-field')).map((el) => ({
            text: squash(el.innerText || ''),
            title: squash(el.title || ''),
            key: el.dataset.wordIndex || '',
            blockNum: el.dataset.blockNum || '',
        })).filter((field) => field.text || field.title);
    });
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

        const fields = await fetchOverlayFields(page);
        const numberedFields = fields.filter((field) => /^\d+\./.test(field.title || field.text));
        const targetField = fields.find((field) => normalize(field.title || field.text) === normalize(ORIGINAL_LINE));

        if (numberedFields.length < 6) {
            throw new Error(`expected at least 6 numbered overlay fields, found ${numberedFields.length}: ${JSON.stringify(numberedFields)}`);
        }
        if (!targetField) {
            throw new Error(`missing dedicated overlay field for target line: ${JSON.stringify(numberedFields)}`);
        }

        const locator = page.locator('.overlay-field').filter({ hasText: 'Install new Deck Board.' }).first();
        await locator.dblclick();
        await page.waitForTimeout(400);
        await locator.evaluate((el, updatedText) => {
            const editor = el.querySelector('[contenteditable]');
            editor.innerText = updatedText;
            editor.dispatchEvent(new Event('input', { bubbles: true }));
        }, UPDATED_LINE);
        await page.waitForTimeout(600);

        await page.getByRole('button', { name: /Save PDF/i }).click();
        await page.waitForTimeout(7000);

        if (!savePayload || !Array.isArray(savePayload.edits) || savePayload.edits.length !== 1) {
            throw new Error(`unexpected save payload: ${JSON.stringify(savePayload)}`);
        }
        const edit = savePayload.edits[0];
        if (/\n/.test(String(edit.original_text || '')) || /\n/.test(String(edit.new_text || ''))) {
            throw new Error(`save payload is still multiline: ${JSON.stringify(edit)}`);
        }
        const styleTexts = Array.isArray(edit.word_styles) ? edit.word_styles.map((word) => normalize(word.text)) : [];
        if (!styleTexts.includes(normalize(ORIGINAL_LINE))) {
            throw new Error(`save payload lost original line context: ${JSON.stringify(edit)}`);
        }
        if (!normalize(UPDATED_LINE).includes(normalize(edit.new_text))) {
            throw new Error(`save payload new_text is not derived from updated line: ${JSON.stringify(edit)}`);
        }

        const extraction = await fetchExtraction(page);
        if (!extraction?.success) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
        }
        const pageData = extraction.extraction_data?.[0] || {};
        const lineFound = (pageData.lines || []).some((line) => normalize(line.text) === normalize(UPDATED_LINE));
        if (!lineFound) {
            throw new Error(`updated line missing from extraction: ${UPDATED_LINE}`);
        }

        console.log('doc711 browser regression passed');
        console.log(JSON.stringify({
            numbered_overlay_fields: numberedFields.map((field) => field.title || field.text),
            save_edit: {
                original_text: edit.original_text,
                new_text: edit.new_text,
                bbox: edit.bbox,
                original_bbox: edit.original_bbox,
            },
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
