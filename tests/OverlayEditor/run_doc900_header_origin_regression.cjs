#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 900);
const HIDDEN_HEADER_TEXTS = ['Contract Concerning', '(Address of Property)', 'Page of 10', 'Contract Concerning Page of 10'];

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
}

async function fetchOverlayTargets(page) {
    return page.evaluate(({ hiddenTexts }) => {
        const squash = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageWrapper = document.querySelector('.overlay-page[data-page-number="1"]');
        const pageRect = pageWrapper ? pageWrapper.getBoundingClientRect() : null;
        const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
            const rect = field.getBoundingClientRect();
            const editor = field.querySelector('[contenteditable]');
            return {
                key: field.dataset.wordIndex || '',
                blockNum: field.dataset.blockNum || '',
                pageNumber: field.dataset.pageNumber || '',
                title: squash(field.title || ''),
                text: squash(editor?.innerText || field.innerText || ''),
                rect: {
                    x: rect.x,
                    y: rect.y,
                    width: rect.width,
                    height: rect.height,
                },
                relativeToPage: pageRect ? {
                    x: rect.x - pageRect.x,
                    y: rect.y - pageRect.y,
                    width: rect.width,
                    height: rect.height,
                } : null,
                style: {
                    top: field.style.top,
                    left: field.style.left,
                    width: field.style.width,
                    height: field.style.height,
                },
            };
        }).filter((field) => field.pageNumber === '1');

        return {
            suppressedMatches: fields.filter((field) => hiddenTexts.includes(field.text) || hiddenTexts.includes(field.title)),
        };
    }, { hiddenTexts: HIDDEN_HEADER_TEXTS });
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

async function forceRefreshOverlayExtraction(page) {
    return page.evaluate(async ({ baseUrl, documentId }) => {
        const response = await fetch(`${baseUrl}/documents/${documentId}/prepare-overlay?force_refresh=1&v=${Date.now()}`, {
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
        await page.waitForTimeout(2500);

        await activateOverlay(page);
        await page.waitForTimeout(3000);

        const prepared = await forceRefreshOverlayExtraction(page);
        if (!prepared?.success) {
            throw new Error(`prepare-overlay failed: ${JSON.stringify(prepared)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);
        await activateOverlay(page);
        await page.waitForTimeout(3000);

        const overlayTargets = await fetchOverlayTargets(page);
        if (overlayTargets.suppressedMatches.length > 0) {
            throw new Error(`hidden header text is still rendered in overlay mode: ${JSON.stringify(overlayTargets.suppressedMatches)}`);
        }

        const extraction = await fetchExtraction(page);
        if (!extraction?.success) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
        }

        const pageData = extraction.extraction_data?.find((entry) => Number(entry?.page_number) === 1);
        if (!pageData) {
            throw new Error('page 1 extraction payload missing');
        }

        const topWords = (pageData.words || []).filter((word) => Number(word.top || 0) < 50);
        const topBlocks = (pageData.blocks || []).filter((block) => Number(block.top || 0) < 70);
        const extractionStillContainsHiddenHeader = topBlocks.some((block) => {
            const text = normalize(Array.isArray(block.text_lines) ? block.text_lines.join(' ') : block.text);
            return HIDDEN_HEADER_TEXTS.includes(text);
        });
        const hiddenTopWords = topWords.filter((word) => HIDDEN_HEADER_TEXTS.includes(normalize(word.text)));
        if (extractionStillContainsHiddenHeader || hiddenTopWords.length > 0) {
            throw new Error(`hidden header text is still present in extraction: ${JSON.stringify({
                topBlocks: topBlocks.map((block) => ({
                    text: normalize(Array.isArray(block.text_lines) ? block.text_lines.join(' ') : block.text),
                    block_num: block.block_num,
                    top: block.top,
                    left: block.left,
                    width: block.width,
                    height: block.height,
                })),
                hiddenTopWords,
            })}`);
        }

        console.log('doc900 hidden-header regression passed');
        console.log(JSON.stringify({
            overlay_suppressed_matches: overlayTargets.suppressedMatches,
            extraction_top_words: topWords.map((word) => ({
                text: word.text,
                block_num: word.block_num,
                top: word.top,
                left: word.left,
                width: word.width,
                height: word.height,
            })),
            extraction_top_blocks: topBlocks.map((block) => ({
                text: normalize(Array.isArray(block.text_lines) ? block.text_lines.join(' ') : block.text),
                block_num: block.block_num,
                top: block.top,
                left: block.left,
                width: block.width,
                height: block.height,
            })),
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
