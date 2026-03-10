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
const FIRST_KEY = 'block-1-4';
const SECOND_KEY = 'block-1-3';

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1600 } });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);
        await activateOverlay(page);
        await page.waitForTimeout(3000);

        await page.click(`.overlay-field[data-word-index="${FIRST_KEY}"]`);
        await page.click(`.overlay-field[data-word-index="${SECOND_KEY}"]`, { modifiers: ['Shift'] });

        const beforeMerge = await page.evaluate(() => ({
            label: document.getElementById('selection-label')?.textContent || '',
            mergeDisabled: !!document.getElementById('selected-merge')?.disabled,
            selectedKeys: Array.from(document.querySelectorAll('.overlay-field.selected')).map((field) => field.dataset.wordIndex),
        }));

        if (normalize(beforeMerge.label) !== '2 blocks selected') {
            throw new Error(`unexpected selection label before merge: ${JSON.stringify(beforeMerge)}`);
        }
        if (beforeMerge.mergeDisabled) {
            throw new Error(`merge button is disabled for a 2-block selection: ${JSON.stringify(beforeMerge)}`);
        }
        if (!beforeMerge.selectedKeys.includes(FIRST_KEY) || !beforeMerge.selectedKeys.includes(SECOND_KEY)) {
            throw new Error(`shift-click did not preserve both selected blocks: ${JSON.stringify(beforeMerge)}`);
        }

        await page.click('#selected-merge');
        await page.waitForFunction(() => {
            const selected = Array.from(document.querySelectorAll('.overlay-field.selected'));
            if (selected.length !== 1) return false;
            const field = selected[0];
            const text = String(field.querySelector('[contenteditable]')?.innerText || field.innerText || '').replace(/\s+/g, ' ').trim();
            return text.includes('1.PARTIES: The parties to this contract are')
                && text.includes('Eddie Grigoreff and Natasha Grigoreff');
        }, { timeout: 5000 });

        const afterMerge = await page.evaluate(({ firstKey, secondKey }) => {
            const fields = Array.from(document.querySelectorAll('.overlay-field')).map((field) => ({
                key: field.dataset.wordIndex || '',
                text: String(field.querySelector('[contenteditable]')?.innerText || field.innerText || '').replace(/\s+/g, ' ').trim(),
                selected: field.classList.contains('selected'),
            }));
            return {
                label: document.getElementById('selection-label')?.textContent || '',
                selectedKeys: fields.filter((field) => field.selected).map((field) => field.key),
                firstExists: fields.some((field) => field.key === firstKey),
                secondExists: fields.some((field) => field.key === secondKey),
                mergedField: fields.find((field) =>
                    field.key !== firstKey
                    && field.key !== secondKey
                    && field.text.includes('1.PARTIES: The parties to this contract are')
                    && field.text.includes('Eddie Grigoreff and Natasha Grigoreff')
                ) || null,
            };
        }, { firstKey: FIRST_KEY, secondKey: SECOND_KEY });

        if (afterMerge.firstExists || afterMerge.secondExists) {
            throw new Error(`original fields still exist after merge: ${JSON.stringify(afterMerge)}`);
        }
        if (!afterMerge.mergedField) {
            throw new Error(`merged synthetic field was not rendered: ${JSON.stringify(afterMerge)}`);
        }
        if (afterMerge.selectedKeys.length !== 1 || afterMerge.selectedKeys[0] !== afterMerge.mergedField.key) {
            throw new Error(`merged field was not selected after merge: ${JSON.stringify(afterMerge)}`);
        }

        console.log('overlay multiselect merge regression passed');
        console.log(JSON.stringify({
            beforeMerge,
            afterMerge,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
