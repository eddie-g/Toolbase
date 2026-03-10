#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 909);
const TARGET_VALUE = 'Natasha Grigoreff';
const TARGET_LABEL = 'Name:';
const TARGET_RENDERED = `${TARGET_LABEL} ${TARGET_VALUE}`;

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

async function postJson(page, url) {
    return page.evaluate(async (targetUrl) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(targetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });
        return {
            ok: response.ok,
            status: response.status,
            body: await response.text(),
        };
    }, url);
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 30000 });
    await page.waitForTimeout(1500);
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
    const page = await browser.newPage({ viewport: { width: 1800, height: 1600 } });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        const prepared = await postJson(page, `/documents/${DOCUMENT_ID}/prepare-overlay?force_refresh=1`);
        if (!prepared.ok) {
            throw new Error(`prepare-overlay failed: ${JSON.stringify(prepared)}`);
        }

        const extraction = await fetchExtraction(page);
        if (!extraction?.success) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
        }
        const page1 = extraction.extraction_data?.find((entry) => Number(entry?.page_number) === 1);
        if (!page1) {
            throw new Error('page 1 extraction payload missing');
        }
        const sourceNameBlock = (page1.blocks || []).find((block) => {
            const blockText = normalize(Array.isArray(block.text_lines) ? block.text_lines.join(' ') : block.text);
            return blockText.includes(TARGET_LABEL) && blockText.includes(TARGET_VALUE);
        });
        if (!sourceNameBlock) {
            throw new Error('upstream extraction no longer contains the Name source block');
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const overlayState = await page.evaluate(({ targetRendered, targetValue }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
                key: field.dataset.wordIndex || '',
                pageNumber: field.dataset.pageNumber || '',
                text: normalizeText(field.querySelector('[contenteditable]')?.innerText || field.innerText || ''),
                title: normalizeText(field.title || ''),
                originalText: normalizeText(field.dataset.originalText || ''),
                flowText: normalizeText(field.dataset.stableFlowText || ''),
                rect: {
                    left: field.style.left,
                    top: field.style.top,
                    width: field.style.width,
                    height: field.style.height,
                },
            })).filter((field) => field.pageNumber === '1');

            return {
                targetFields: fields.filter((field) => field.text === targetRendered),
                natashaOnlyFields: fields.filter((field) => field.text === targetValue),
                underscoreLeakFields: fields.filter((field) => /^Name:.*_{6,}/.test(field.text)),
                sourceMatches: fields.filter((field) =>
                    field.originalText.includes('Name:')
                    && field.originalText.includes(targetValue)
                ),
            };
        }, {
            targetRendered: TARGET_RENDERED,
            targetValue: TARGET_VALUE,
        });

        if (overlayState.targetFields.length !== 1) {
            throw new Error(`expected exactly one rendered Name field: ${JSON.stringify(overlayState, null, 2)}`);
        }
        if (overlayState.natashaOnlyFields.length !== 0) {
            throw new Error(`standalone value-only Natasha field should be suppressed: ${JSON.stringify(overlayState, null, 2)}`);
        }
        if (overlayState.underscoreLeakFields.length !== 0) {
            throw new Error(`underline placeholders leaked into rendered Name field: ${JSON.stringify(overlayState, null, 2)}`);
        }
        if (overlayState.sourceMatches.length !== 1) {
            throw new Error(`expected exactly one page-1 source match for the Name field: ${JSON.stringify(overlayState, null, 2)}`);
        }

        console.log('doc909 name overlay regression passed');
        console.log(JSON.stringify({
            sourceNameBlock: {
                block_num: sourceNameBlock.block_num,
                text_lines: sourceNameBlock.text_lines,
                top: sourceNameBlock.top,
                left: sourceNameBlock.left,
                width: sourceNameBlock.width,
                height: sourceNameBlock.height,
            },
            overlayState,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
