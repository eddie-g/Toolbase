#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 920);
const ORIGINAL_CELL_TEXT = 'yes';
const REPLACEMENT_CELL_TEXT = '123';
const REQUIRED_NEIGHBOR_TEXT = 'PDF/A-1b PASS';
const REQUIRED_STACKED_TEXTS = ['no', '1'];

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

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 20000 });
    await page.waitForTimeout(1500);
}

async function findTopYesField(page) {
    const field = await page.evaluate((targetText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fields = Array.from(document.querySelectorAll('.overlay-field'))
            .map((fieldEl) => {
                const text = normalizeText(fieldEl.querySelector('[contenteditable]')?.innerText || fieldEl.innerText || '');
                const top = parseFloat(fieldEl.style.top || '0');
                const ops = fieldEl?._overlaySourceBlock?.source_content_ops || [];
                return {
                    key: fieldEl.dataset.wordIndex || '',
                    text,
                    top,
                    sourceOps: ops.map((op) => ({
                        xref: op.xref,
                        start: op.start,
                        end: op.end,
                        operator: op.operator,
                        matched_text: op.matched_text,
                    })),
                };
            })
            .filter((entry) => entry.text === targetText && entry.sourceOps.length > 0)
            .sort((a, b) => a.top - b.top);
        return fields[0] || null;
    }, ORIGINAL_CELL_TEXT);

    if (!field) {
        throw new Error(`could not find top "${ORIGINAL_CELL_TEXT}" field with source_content_ops`);
    }
    return field;
}

async function collectInterestingFields(page) {
    return page.evaluate(({ replacementText, originalText, requiredNeighborText, requiredStackedTexts }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
            key: field.dataset.wordIndex || '',
            text: normalizeText(field.querySelector('[contenteditable]')?.innerText || field.innerText || ''),
        }));
        return {
            fields,
            replacementMatches: fields.filter((field) => field.text === replacementText),
            remainingYesMatches: fields.filter((field) => field.text === originalText),
            neighborMatches: fields.filter((field) => field.text === requiredNeighborText),
            stackedMatches: requiredStackedTexts.map((text) => ({
                text,
                count: fields.filter((field) => field.text === text).length,
            })),
            sample: fields.filter((field) =>
                field.text === replacementText
                || field.text === originalText
                || field.text === requiredNeighborText
                || requiredStackedTexts.includes(field.text)
            ),
        };
    }, {
        replacementText: REPLACEMENT_CELL_TEXT,
        originalText: ORIGINAL_CELL_TEXT,
        requiredNeighborText: REQUIRED_NEIGHBOR_TEXT,
        requiredStackedTexts: REQUIRED_STACKED_TEXTS,
    });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1400 } });
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

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const targetField = await findTopYesField(page);
        const selector = `.overlay-field[data-word-index="${targetField.key}"]`;

        await page.click(selector);
        await page.dblclick(selector);
        await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
        await page.keyboard.type(REPLACEMENT_CELL_TEXT);

        const saveResponsePromise = page.waitForResponse((response) => (
            response.request().method() === 'POST'
            && response.url().includes(`/documents/${DOCUMENT_ID}/save-edits`)
        ), { timeout: 30000 });
        await page.click('#save-btn');
        const saveResponse = await saveResponsePromise;
        if (!saveResponse.ok()) {
            throw new Error(`save-edits request failed with ${saveResponse.status()}`);
        }

        const saveResult = await saveResponse.json();
        if (!saveResult.success) {
            throw new Error(`save-edits returned failure payload: ${JSON.stringify(saveResult, null, 2)}`);
        }
        if (!/Material edits:\s*1\s*\/\s*\d+/i.test(String(saveResult.debug_output || ''))) {
            throw new Error(`targeted_save did not report a single material edit: ${saveResult.debug_output}`);
        }

        await page.waitForTimeout(5000);

        const capturedEdits = Array.isArray(capturedSaveBody?.edits) ? capturedSaveBody.edits : [];
        const targetEdit = capturedEdits.find((edit) => normalize(edit?.original_text) === ORIGINAL_CELL_TEXT);
        if (!targetEdit) {
            throw new Error(`save payload did not include edited "${ORIGINAL_CELL_TEXT}" field: ${JSON.stringify(capturedSaveBody, null, 2)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await forceRefreshOverlay(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const afterSave = await collectInterestingFields(page);
        if (afterSave.replacementMatches.length !== 1) {
            throw new Error(`expected exactly one "${REPLACEMENT_CELL_TEXT}" after save: ${JSON.stringify(afterSave, null, 2)}`);
        }
        if (afterSave.neighborMatches.length !== 1) {
            throw new Error(`neighbor row "${REQUIRED_NEIGHBOR_TEXT}" was lost or duplicated: ${JSON.stringify(afterSave, null, 2)}`);
        }
        for (const required of afterSave.stackedMatches) {
            if (required.count !== 1) {
                throw new Error(`stacked table text "${required.text}" was lost or duplicated: ${JSON.stringify(afterSave, null, 2)}`);
            }
        }
        if (afterSave.remainingYesMatches.length < 2) {
            throw new Error(`expected untouched yes cells to remain after save: ${JSON.stringify(afterSave, null, 2)}`);
        }

        console.log('doc920 targeted-save regression passed');
        console.log(JSON.stringify({
            targetField,
            targetEdit: {
                original_text: targetEdit.original_text,
                new_text: targetEdit.new_text,
                source_content_ops: targetEdit.source_content_ops,
            },
            afterSave,
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
