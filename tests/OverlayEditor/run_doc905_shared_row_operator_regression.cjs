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
const ORIGINAL_CELL_TEXT = 'no';
const REPLACEMENT_CELL_TEXT = 'maybe';
const REQUIRED_NEIGHBOR_TEXT = 'PDF/A-1b FAIL';
const REQUIRED_HEADER_TEXT = 'purpose';
const REQUIRED_BODY_FRAGMENT = 'deliberately violate all possible aspects of PDF/A-1';

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

async function findEditableNoField(page) {
    const field = await page.evaluate((targetText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((fieldEl) => {
            const text = normalizeText(fieldEl.querySelector('[contenteditable]')?.innerText || fieldEl.innerText || '');
            const ops = fieldEl?._overlaySourceBlock?.source_content_ops || [];
            return text === targetText && ops.length > 0;
        });
        if (!match) {
            return null;
        }
        return {
            key: match.dataset.wordIndex,
            text: normalizeText(match.querySelector('[contenteditable]')?.innerText || match.innerText || ''),
            sourceOps: (match?._overlaySourceBlock?.source_content_ops || []).map((op) => ({
                xref: op.xref,
                start: op.start,
                end: op.end,
                operator: op.operator,
                matched_text: op.matched_text,
            })),
        };
    }, ORIGINAL_CELL_TEXT);

    if (!field) {
        throw new Error(`could not find "${ORIGINAL_CELL_TEXT}" field with source_content_ops`);
    }
    return field;
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

        const targetField = await findEditableNoField(page);

        await page.click(`.overlay-field[data-word-index="${targetField.key}"]`);
        await page.dblclick(`.overlay-field[data-word-index="${targetField.key}"]`);
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

        await page.waitForTimeout(5000);

        const targetEdit = (capturedSaveBody?.edits || []).find((edit) => normalize(edit?.original_text) === ORIGINAL_CELL_TEXT);
        if (!targetEdit) {
            throw new Error(`save payload did not include edited "${ORIGINAL_CELL_TEXT}" field: ${JSON.stringify(capturedSaveBody, null, 2)}`);
        }
        if (!Array.isArray(targetEdit.source_content_ops) || targetEdit.source_content_ops.length === 0) {
            throw new Error(`edited "${ORIGINAL_CELL_TEXT}" payload is missing source_content_ops: ${JSON.stringify(targetEdit, null, 2)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await forceRefreshOverlay(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const afterSave = await page.evaluate(({ replacementText, neighborText, headerText, bodyFragment }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
                key: field.dataset.wordIndex || '',
                text: normalizeText(field.querySelector('[contenteditable]')?.innerText || field.innerText || ''),
            }));
            return {
                replacementMatches: fields.filter((field) => field.text === replacementText),
                originalMatches: fields.filter((field) => field.text === 'no'),
                hasNeighbor: fields.some((field) => field.text === neighborText),
                hasHeader: fields.some((field) => field.text === headerText),
                hasBody: fields.some((field) => field.text.includes(bodyFragment)),
                sample: fields.filter((field) =>
                    field.text === replacementText
                    || field.text === 'no'
                    || field.text === neighborText
                    || field.text === headerText
                    || field.text.includes(bodyFragment)
                ).slice(0, 20),
            };
        }, {
            replacementText: REPLACEMENT_CELL_TEXT,
            neighborText: REQUIRED_NEIGHBOR_TEXT,
            headerText: REQUIRED_HEADER_TEXT,
            bodyFragment: REQUIRED_BODY_FRAGMENT,
        });

        if (afterSave.replacementMatches.length !== 1) {
            throw new Error(`expected exactly one "${REPLACEMENT_CELL_TEXT}" after save: ${JSON.stringify(afterSave, null, 2)}`);
        }
        if (!afterSave.hasNeighbor) {
            throw new Error(`neighbor text "${REQUIRED_NEIGHBOR_TEXT}" disappeared after save: ${JSON.stringify(afterSave, null, 2)}`);
        }
        if (!afterSave.hasHeader) {
            throw new Error(`header text "${REQUIRED_HEADER_TEXT}" disappeared after save: ${JSON.stringify(afterSave, null, 2)}`);
        }
        if (!afterSave.hasBody) {
            throw new Error(`body fragment "${REQUIRED_BODY_FRAGMENT}" disappeared after save: ${JSON.stringify(afterSave, null, 2)}`);
        }

        console.log('doc905 shared-row operator regression passed');
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
