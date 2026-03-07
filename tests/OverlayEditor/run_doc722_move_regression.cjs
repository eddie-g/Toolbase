#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 722);
const TARGET_TEXT = 'my cool title';

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function lineBBox(line) {
    if (Array.isArray(line?.bbox) && line.bbox.length >= 4) {
        return line.bbox;
    }
    const left = Number(line?.left) || 0;
    const top = Number(line?.top) || 0;
    const width = Number(line?.width) || 0;
    const height = Number(line?.height) || 0;
    return [left, top, left + width, top + height];
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

async function fetchExtraction(page) {
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

    return extraction;
}

function findMatchingLines(extraction, targetText) {
    const pageData = extraction.extraction_data?.[0] || {};
    return (pageData.lines || []).filter((line) => normalize(line.text) === normalize(targetText));
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
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

        const beforeExtraction = await fetchExtraction(page);
        const beforeMatches = findMatchingLines(beforeExtraction, TARGET_TEXT);
        if (beforeMatches.length !== 1) {
            throw new Error(`expected 1 original line for '${TARGET_TEXT}', found ${beforeMatches.length}`);
        }

        const beforeLine = beforeMatches[0];
        const beforeBBox = lineBBox(beforeLine);
        const span = Array.isArray(beforeLine.spans) && beforeLine.spans.length > 0 ? beforeLine.spans[0] : null;
        if (!span) {
            throw new Error(`missing span data for '${TARGET_TEXT}'`);
        }

        const originalOrigin = Array.isArray(span.origin) ? span.origin : [beforeBBox[0], beforeBBox[3]];
        await activateOverlay(page);
        await page.waitForTimeout(2000);

        const locator = page.locator('.overlay-field').filter({ hasText: TARGET_TEXT }).first();
        const box = await locator.boundingBox();
        if (!box) {
            throw new Error(`missing overlay field for '${TARGET_TEXT}'`);
        }

        await page.mouse.move(box.x + (box.width / 2), box.y + (box.height / 2));
        await page.mouse.down();
        await page.mouse.move(box.x + (box.width / 2) + 50, box.y + (box.height / 2) + 15, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(800);

        const dirtyState = await page.evaluate(() => ({
            editedCount: overlayEditedFields.size,
            saveLabel: document.getElementById('save-btn')?.innerText || '',
        }));
        if (dirtyState.editedCount < 1) {
            throw new Error(`move did not register in overlayEditedFields: ${JSON.stringify(dirtyState)}`);
        }

        await page.getByRole('button', { name: /Save PDF/i }).click();
        await page.waitForTimeout(1500);

        if (!savePayload || !Array.isArray(savePayload.edits) || savePayload.edits.length !== 1) {
            throw new Error(`unexpected save payload: ${JSON.stringify(savePayload)}`);
        }

        const savedEdit = savePayload.edits[0];
        if (normalize(savedEdit.original_text) !== normalize(TARGET_TEXT) || normalize(savedEdit.new_text) !== normalize(TARGET_TEXT)) {
            throw new Error(`move save payload mutated text: ${JSON.stringify(savedEdit)}`);
        }
        if (!savedEdit.position_changed && !savedEdit.size_changed) {
            throw new Error(`move save payload did not mark position change: ${JSON.stringify(savedEdit)}`);
        }
        const payloadBBox = lineBBox(savedEdit);

        let afterBBox = null;
        for (let attempt = 0; attempt < 20; attempt += 1) {
            const afterExtraction = await fetchExtraction(page);
            const afterMatches = findMatchingLines(afterExtraction, TARGET_TEXT);
            if (afterMatches.length === 1) {
                const candidateBBox = lineBBox(afterMatches[0]);
                const candidateDelta = Math.max(
                    Math.abs(beforeBBox[0] - candidateBBox[0]),
                    Math.abs(beforeBBox[1] - candidateBBox[1]),
                    Math.abs(beforeBBox[2] - candidateBBox[2]),
                    Math.abs(beforeBBox[3] - candidateBBox[3]),
                );
                if (candidateDelta >= 5) {
                    afterBBox = candidateBBox;
                    break;
                }
            }
            await page.waitForTimeout(1000);
        }

        if (!afterBBox) {
            throw new Error(`saved extraction never reflected the move for '${TARGET_TEXT}'`);
        }

        const lineDelta = Math.max(
            Math.abs(beforeBBox[0] - afterBBox[0]),
            Math.abs(beforeBBox[1] - afterBBox[1]),
            Math.abs(beforeBBox[2] - afterBBox[2]),
            Math.abs(beforeBBox[3] - afterBBox[3]),
        );
        if ((afterBBox[0] - beforeBBox[0]) < 10 || (afterBBox[1] - beforeBBox[1]) < 5) {
            throw new Error(`saved extraction did not move materially down-right: before=${JSON.stringify(beforeBBox)} after=${JSON.stringify(afterBBox)}`);
        }

        console.log('doc722 move regression passed');
        console.log(JSON.stringify({
            before_bbox: beforeBBox,
            save_payload_bbox: payloadBBox,
            after_bbox: afterBBox,
            dirty_state: dirtyState,
            saved_origin: [savedEdit.origin_x, savedEdit.origin_y],
            original_origin: originalOrigin,
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
