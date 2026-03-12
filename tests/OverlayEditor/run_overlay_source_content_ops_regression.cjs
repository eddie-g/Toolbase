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
const TARGET_TEXT = '104';
const REPLACEMENT_TEXT = '105';
const NEIGHBOR_TEXT = 'Houston';

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
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 20000 });
    await page.waitForTimeout(1500);
}

async function forceRefreshOverlay(page) {
    const refresh = await postJson(page, `/documents/${DOCUMENT_ID}/prepare-overlay?force_refresh=1`);
    if (!refresh.ok) {
        throw new Error(`prepare-overlay refresh failed: ${JSON.stringify(refresh)}`);
    }
}

async function restoreOriginal(page) {
    const restore = await postJson(page, `/documents/${DOCUMENT_ID}/restore-original`);
    if (!restore.ok) {
        throw new Error(`restore-original failed: ${JSON.stringify(restore)}`);
    }
}

async function findTargetField(page) {
    const fieldInfo = await page.evaluate((targetText) => {
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const target = fields.find((field) => {
            const text = String(field.querySelector('[contenteditable]')?.innerText || field.innerText || '').replace(/\s+/g, ' ').trim();
            const ops = field?._overlaySourceBlock?.source_content_ops || [];
            return text === targetText && ops.length > 0;
        });
        if (!target) return null;
        return {
            key: target.dataset.wordIndex,
            text: String(target.querySelector('[contenteditable]')?.innerText || target.innerText || '').replace(/\s+/g, ' ').trim(),
            sourceOps: (target?._overlaySourceBlock?.source_content_ops || []).map((op) => ({
                xref: op.xref,
                start: op.start,
                end: op.end,
                operator: op.operator,
                matched_text: op.matched_text,
            })),
        };
    }, TARGET_TEXT);

    if (!fieldInfo) {
        throw new Error(`could not find overlay field with text "${TARGET_TEXT}" carrying source_content_ops`);
    }
    return fieldInfo;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1700, height: 1500 } });
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
        await page.waitForTimeout(1500);

        await restoreOriginal(page);
        await forceRefreshOverlay(page);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await activateOverlay(page);

        const beforeEdit = await findTargetField(page);

        await page.click(`.overlay-field[data-word-index="${beforeEdit.key}"]`);
        await page.dblclick(`.overlay-field[data-word-index="${beforeEdit.key}"]`);
        await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
        await page.keyboard.type(REPLACEMENT_TEXT);

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

        const capturedEdits = Array.isArray(capturedSaveBody?.edits) ? capturedSaveBody.edits : [];
        const targetEdit = capturedEdits.find((edit) => normalize(edit?.original_text) === TARGET_TEXT);
        if (!targetEdit) {
            throw new Error(`save payload did not include edited "${TARGET_TEXT}" field: ${JSON.stringify(capturedSaveBody, null, 2)}`);
        }
        if (!Array.isArray(targetEdit.source_content_ops) || targetEdit.source_content_ops.length === 0) {
            throw new Error(`save payload for "${TARGET_TEXT}" is missing source_content_ops: ${JSON.stringify(targetEdit, null, 2)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await forceRefreshOverlay(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        await activateOverlay(page);

        const afterSave = await page.evaluate(({ replacementText, neighborText }) => {
            const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
                key: field.dataset.wordIndex || '',
                text: String(field.querySelector('[contenteditable]')?.innerText || field.innerText || '').replace(/\s+/g, ' ').trim(),
            }));
            return {
                replacementMatches: fields.filter((field) => field.text === replacementText),
                hasNeighbor: fields.some((field) => field.text === neighborText),
                neighborMatches: fields.filter((field) => field.text === neighborText),
            };
        }, { replacementText: REPLACEMENT_TEXT, neighborText: NEIGHBOR_TEXT });

        if (afterSave.replacementMatches.length === 0) {
            throw new Error(`did not find saved replacement text "${REPLACEMENT_TEXT}" after reload: ${JSON.stringify(afterSave, null, 2)}`);
        }
        if (!afterSave.hasNeighbor) {
            throw new Error(`neighbor text "${NEIGHBOR_TEXT}" disappeared after save: ${JSON.stringify(afterSave, null, 2)}`);
        }

        console.log('overlay source content ops regression passed');
        console.log(JSON.stringify({
            beforeEdit,
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
            // Ignore cleanup failures in regression teardown.
        }
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
