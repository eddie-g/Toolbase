#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 717);
const HEADER_ORIGINAL = 'Purpose of the Isartor Test Suite';
const HEADER_UPDATED = 'Purpose of the Isartor Test Suitef';
const FOOTER_ORIGINAL = 'page 2';
const FOOTER_UPDATED = 'page 3';

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
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });
        return { status: response.status, body: await response.json() };
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

async function editFieldByTitle(page, title, updatedText) {
    const locator = page.locator(`.overlay-field[title="${title}"]`).first();
    if (await locator.count() === 0) {
        throw new Error(`missing overlay field with title: ${title}`);
    }
    await locator.dblclick();
    await page.waitForTimeout(400);
    await locator.evaluate((el, nextText) => {
        const editor = el.querySelector('[contenteditable]');
        if (!editor) {
            throw new Error(`contenteditable child missing for ${el.title}`);
        }
        editor.innerText = nextText;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }, updatedText);
    await page.waitForTimeout(600);
}

async function savePdf(page) {
    await page.getByRole('button', { name: /Save PDF/i }).click();
    await page.waitForTimeout(7000);
}

async function reloadEditor(page) {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    await activateOverlay(page);
    await page.waitForTimeout(2500);
}

async function fetchExtractionLines(page) {
    const extraction = await page.evaluate(async ({ baseUrl, documentId }) => {
        const response = await fetch(`${baseUrl}/documents/${documentId}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, { baseUrl: BASE_URL, documentId: DOCUMENT_ID });

    if (!extraction?.success) {
        throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
    }

    const pageData = extraction.extraction_data?.[0] || {};
    return (pageData.lines || []).map((line) => normalize(line.text));
}

function countLine(lines, target) {
    const normalizedTarget = normalize(target);
    return lines.filter((line) => line === normalizedTarget).length;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1400 } });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);

        await restoreOriginal(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);

        await activateOverlay(page);
        await page.waitForTimeout(2500);

        await editFieldByTitle(page, HEADER_ORIGINAL, HEADER_UPDATED);
        await savePdf(page);

        let lines = await fetchExtractionLines(page);
        if (countLine(lines, HEADER_UPDATED) !== 1) {
            throw new Error(`header update missing or duplicated after first save: ${JSON.stringify(lines.filter((line) => line.includes('Isartor Test Suite')))} `);
        }
        if (countLine(lines, HEADER_ORIGINAL) !== 0) {
            throw new Error(`old header text survived first save: ${JSON.stringify(lines.filter((line) => line.includes('Purpose of the Isartor Test Suite')))} `);
        }

        await reloadEditor(page);

        await editFieldByTitle(page, FOOTER_ORIGINAL, FOOTER_UPDATED);
        await savePdf(page);

        lines = await fetchExtractionLines(page);
        if (countLine(lines, FOOTER_UPDATED) !== 1) {
            throw new Error(`updated footer missing or duplicated: ${JSON.stringify(lines.filter((line) => /^page \d+$/i.test(line)))}`);
        }
        if (countLine(lines, FOOTER_ORIGINAL) !== 0) {
            throw new Error(`old footer text survived second save: ${JSON.stringify(lines.filter((line) => /^page \d+$/i.test(line)))}`);
        }
        if (countLine(lines, HEADER_UPDATED) !== 1 || countLine(lines, HEADER_ORIGINAL) !== 0) {
            throw new Error(`header regressed after second save: ${JSON.stringify(lines.filter((line) => line.includes('Isartor Test Suite')))} `);
        }

        console.log('doc717 sequential save regression passed');
        console.log(JSON.stringify({
            header: {
                original: HEADER_ORIGINAL,
                updated: HEADER_UPDATED,
            },
            footer: {
                original: FOOTER_ORIGINAL,
                updated: FOOTER_UPDATED,
            },
            matching_lines: lines.filter((line) => line.includes('Isartor Test Suite') || /^page \d+$/i.test(line)),
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
