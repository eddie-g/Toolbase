#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 905);
const TARGET_TEXT_FRAGMENT = 'This is the documentation for the Isartor';
const APPEND_TEXT = ' test';

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

async function restoreOriginal(page) {
    const response = await postJson(page, `/documents/${DOCUMENT_ID}/restore-original`);
    if (!response.ok) {
        throw new Error(`restore-original failed: ${JSON.stringify(response)}`);
    }
}

async function forceRefreshOverlay(page) {
    const response = await postJson(page, `/documents/${DOCUMENT_ID}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function fetchExtraction(page) {
    return page.evaluate(async (documentId) => {
        const response = await fetch(`/documents/${documentId}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, DOCUMENT_ID);
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 20000 });
    await page.waitForTimeout(1500);
}

async function findTargetField(page) {
    const target = await page.evaluate((targetTextFragment) => {
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((field) => {
            const sourceSegments = Array.isArray(field?._overlaySaveSegments) ? field._overlaySaveSegments : [];
            return sourceSegments.some((segment) => String(segment?.text || '').includes(targetTextFragment));
        });
        if (!match) {
            return null;
        }
        return {
            key: match.dataset.wordIndex,
            visibleText: match.innerText || '',
            stableFlowText: match.dataset.stableFlowText || '',
            sourceSegments: (match._overlaySaveSegments || []).map((segment) => ({
                block_num: segment.block_num,
                text: segment.text,
                text_lines: segment.text_lines,
            })),
        };
    }, TARGET_TEXT_FRAGMENT);

    if (!target) {
        throw new Error(`could not find overlay field containing "${TARGET_TEXT_FRAGMENT}"`);
    }
    return target;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1600 } });
    let capturedSaveBody = null;

    page.on('request', (request) => {
        if (request.method() !== 'POST') return;
        if (!request.url().includes(`/documents/${DOCUMENT_ID}/save-edits`)) return;
        try {
            capturedSaveBody = request.postDataJSON();
        } catch (_error) {
            capturedSaveBody = null;
        }
    });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        await restoreOriginal(page);
        await forceRefreshOverlay(page);

        const initialExtraction = await fetchExtraction(page);
        const initialPage = initialExtraction?.extraction_data?.[0];
        if (!initialPage) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(initialExtraction)}`);
        }

        const initialParagraphBlock = (initialPage.blocks || []).find((block) =>
            String(block?.text || '').includes(TARGET_TEXT_FRAGMENT)
        );
        if (!initialParagraphBlock) {
            throw new Error(`missing paragraph block in extraction: ${JSON.stringify(initialPage.blocks || [], null, 2)}`);
        }
        const duplicatePrefixLines = (initialParagraphBlock.text_lines || []).filter((line) => {
            const normalized = normalize(line);
            return normalized === 'This is the documentation for the Isartor'
                || normalized === 'This is the documentation for the Isartor 1';
        });
        if (duplicatePrefixLines.length > 0) {
            throw new Error(`paragraph extraction still contains cumulative duplicate lines: ${JSON.stringify(initialParagraphBlock, null, 2)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const targetField = await findTargetField(page);
        const selector = `.overlay-field[data-word-index="${targetField.key}"]`;

        await page.click(selector);
        await page.dblclick(selector);
        await page.waitForTimeout(300);

        const editorSelector = `${selector} [contenteditable]`;
        const editText = await page.locator(editorSelector).innerText();
        if (!normalize(editText).startsWith('This is the documentation for the Isartor1')) {
            throw new Error(`edit text did not normalize into stable paragraph text: ${JSON.stringify({ editText, targetField }, null, 2)}`);
        }

        await page.evaluate(({ selector, appendText }) => {
            const editor = document.querySelector(selector);
            if (!editor) {
                throw new Error(`missing editor for selector ${selector}`);
            }
            const normalized = String(editor.innerText || '').replace(/\s+$/g, '');
            editor.textContent = `${normalized}${appendText}`;
            editor.dispatchEvent(new Event('input', { bubbles: true }));
            editor.focus();
        }, { selector: editorSelector, appendText: APPEND_TEXT });

        const saveResponsePromise = page.waitForResponse((response) => (
            response.request().method() === 'POST'
            && response.url().includes(`/documents/${DOCUMENT_ID}/save-edits`)
        ), { timeout: 30000 });
        await page.click('#save-btn');
        const saveResponse = await saveResponsePromise;
        if (!saveResponse.ok()) {
            throw new Error(`save-edits request failed with ${saveResponse.status()}`);
        }

        await page.waitForTimeout(5000);

        const paragraphEdits = (capturedSaveBody?.edits || []).filter((edit) =>
            String(edit?.original_text || '').includes(TARGET_TEXT_FRAGMENT)
            || String(edit?.original_text || '').includes('PDF/A-1 test files can be organized')
        );
        if (paragraphEdits.length !== 2) {
            throw new Error(`expected 2 paragraph source-segment edits, got ${paragraphEdits.length}: ${JSON.stringify(paragraphEdits, null, 2)}`);
        }
        const firstSegmentEdit = paragraphEdits.find((edit) =>
            String(edit?.original_text || '').includes(TARGET_TEXT_FRAGMENT)
        );
        const secondSegmentEdit = paragraphEdits.find((edit) =>
            String(edit?.original_text || '').includes('PDF/A-1 test files can be organized')
        );
        if (!firstSegmentEdit || !secondSegmentEdit) {
            throw new Error(`missing paragraph source-segment edits in save payload: ${JSON.stringify(paragraphEdits, null, 2)}`);
        }
        if (!String(secondSegmentEdit.new_text || '').includes('table: test')) {
            throw new Error(`merged tail edit did not land in the second source segment as expected: ${JSON.stringify(paragraphEdits, null, 2)}`);
        }

        await forceRefreshOverlay(page);
        const afterSaveExtraction = await fetchExtraction(page);
        const afterPage = afterSaveExtraction?.extraction_data?.[0];
        if (!afterPage) {
            throw new Error(`fitz-extraction-data after save failed: ${JSON.stringify(afterSaveExtraction)}`);
        }

        const topCorruptionBlocks = (afterPage.blocks || []).filter((block) => {
            const text = String(block?.text || '');
            return Number(block?.top || 0) < 100
                && (
                    text.includes(TARGET_TEXT_FRAGMENT)
                    || text.includes('validate the validators')
                    || text.includes('PDF/A-1 test files can be organized')
                );
        });
        if (topCorruptionBlocks.length > 0) {
            throw new Error(`paragraph text leaked to top of page after save: ${JSON.stringify(topCorruptionBlocks, null, 2)}`);
        }

        const afterParagraphBlock = (afterPage.blocks || []).find((block) =>
            String(block?.text || '').includes(TARGET_TEXT_FRAGMENT)
        );
        if (!afterParagraphBlock) {
            throw new Error(`paragraph block missing after save: ${JSON.stringify(afterPage.blocks || [], null, 2)}`);
        }
        const afterTrailingBlock = (afterPage.blocks || []).find((block) =>
            String(block?.text || '').includes('PDF/A-1 test files can be organized')
        );
        if (!afterTrailingBlock) {
            throw new Error(`trailing paragraph block missing after save: ${JSON.stringify(afterPage.blocks || [], null, 2)}`);
        }
        if (!String(afterTrailingBlock.text || '').includes('table: test')) {
            throw new Error(`merged tail append missing after save: ${JSON.stringify({ afterParagraphBlock, afterTrailingBlock }, null, 2)}`);
        }

        console.log('doc905 Isartor paragraph append regression passed');
        console.log(JSON.stringify({
            targetField,
            editText,
            paragraphEdits: paragraphEdits.map((edit) => ({
                original_text: edit.original_text,
                new_text: edit.new_text,
                source_content_ops: edit.source_content_ops,
            })),
            afterParagraphBlock,
            afterTrailingBlock,
        }, null, 2));
    } finally {
        try {
            await restoreOriginal(page);
        } catch (_error) {
            // Ignore teardown restore failures.
        }
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
