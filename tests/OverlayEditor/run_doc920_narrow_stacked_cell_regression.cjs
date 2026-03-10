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
const ORIGINAL_TEXT = 'no';
const REPLACEMENT_TEXT = 'jones';
const REQUIRED_NEIGHBOR_TEXTS = ['1', 'yes', 'PDF/A-1a FAIL', 'PDF/A-1b PASS'];

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

async function collectCellFields(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
            key: field.dataset.wordIndex || '',
            text: normalizeText(field.querySelector('[contenteditable]')?.innerText || field.innerText || ''),
            title: field.title || '',
            top: parseFloat(field.style.top || '0'),
            left: parseFloat(field.style.left || '0'),
        }));
        return {
            fields,
            cellFields: fields
                .filter((field) => field.top >= 620 && field.top <= 760 && field.left >= 330 && field.left <= 390)
                .sort((a, b) => a.top - b.top),
        };
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

        const before = await collectCellFields(page);
        const target = before.cellFields.find((field) => field.text === ORIGINAL_TEXT);
        if (!target) {
            throw new Error(`could not find "${ORIGINAL_TEXT}" field before edit: ${JSON.stringify(before, null, 2)}`);
        }

        const selector = `.overlay-field[data-word-index="${target.key}"]`;
        await page.click(selector);
        await page.dblclick(selector);
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

        const saveResult = await saveResponse.json();
        if (!saveResult.success) {
            throw new Error(`save-edits returned failure payload: ${JSON.stringify(saveResult, null, 2)}`);
        }
        if (!/Material edits:\s*1\s*\/\s*1/i.test(String(saveResult.debug_output || ''))) {
            throw new Error(`unexpected targeted_save material-edit summary: ${saveResult.debug_output}`);
        }

        const capturedEdits = Array.isArray(capturedSaveBody?.edits) ? capturedSaveBody.edits : [];
        const targetEdit = capturedEdits.find((edit) => normalize(edit?.original_text) === ORIGINAL_TEXT);
        if (!targetEdit) {
            throw new Error(`save payload did not include edited "${ORIGINAL_TEXT}" field: ${JSON.stringify(capturedSaveBody, null, 2)}`);
        }

        await page.waitForTimeout(5000);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await forceRefreshOverlay(page);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);
        await activateOverlay(page);

        const after = await collectCellFields(page);
        const replacement = after.cellFields.find((field) => field.text === REPLACEMENT_TEXT);
        if (!replacement) {
            throw new Error(`replacement "${REPLACEMENT_TEXT}" missing after save: ${JSON.stringify(after, null, 2)}`);
        }
        for (const text of REQUIRED_NEIGHBOR_TEXTS) {
            if (!after.fields.some((field) => field.text === text)) {
                throw new Error(`neighbor text "${text}" missing after save: ${JSON.stringify(after, null, 2)}`);
            }
        }

        console.log('doc920 narrow stacked cell regression passed');
        console.log(JSON.stringify({
            before: before.cellFields,
            targetEdit: {
                original_text: targetEdit.original_text,
                new_text: targetEdit.new_text,
                source_content_ops: targetEdit.source_content_ops,
            },
            after: after.cellFields,
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
