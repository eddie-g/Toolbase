#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4379);
const TARGET_ID = process.env.TARGET_ID || 'pdfjs_4379_0_shape_ac31c9ca-dfdd-48c1-9e10-854e739116c9';
const TEST_EMAIL = process.env.TEST_EMAIL || 'eddie.grig@gmail.com';
const TEST_PASSWORD = process.env.TEST_PASSWORD || 'password1';

async function loginUser(page) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.fill('input[name="email"]', TEST_EMAIL);
    await page.fill('input[name="password"]', TEST_PASSWORD);
    await page.getByRole('button', { name: /sign in/i }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 10000 });
}

async function renderedStrokeState(page) {
    return page.evaluate((id) => {
        const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${id}"]`);
        const shape = box?.querySelector('svg > *');
        return box ? {
            selected: box.classList.contains('is-selected'),
            datasetStrokeTransparent: box.dataset.strokeTransparent || '',
            datasetStrokeOpacity: box.dataset.strokeOpacity || '',
            datasetStrokeWidth: box.dataset.strokeWidth || '',
            svgStroke: shape?.getAttribute('stroke') || '',
            svgStrokeOpacity: shape?.getAttribute('stroke-opacity') || '',
            svgStrokeWidth: shape?.getAttribute('stroke-width') || '',
            inspectorOpacity: document.querySelector('#shape-stroke-opacity')?.value || '',
            inspectorTransparentChecked: document.querySelector('#shape-stroke-transparent')?.checked ?? null,
        } : null;
    }, TARGET_ID);
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });

    try {
        await loginUser(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector(`.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`, { timeout: 90000 });
        await page.evaluate(() => {
            const button = document.querySelector('#ftb-edit-mode') || document.querySelector('#edit-mode-toggle');
            button?.click();
        });
        await page.waitForFunction(() => document.body.classList.contains('enpv-edit-on'), null, { timeout: 10000 });
        await page.evaluate((id) => {
            document.querySelector(`.enpv-annotation-box[data-annotation-id="${id}"]`)
                ?.scrollIntoView({ block: 'center', inline: 'center' });
        }, TARGET_ID);
        await page.waitForTimeout(250);
        const rect = await page.evaluate((id) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${id}"]`);
            const r = box?.getBoundingClientRect();
            return r ? { x: r.left + (r.width / 2), y: r.top + (r.height / 2) } : null;
        }, TARGET_ID);
        if (!rect) throw new Error('Target shape box was not measurable.');

        await page.mouse.click(rect.x, rect.y);
        await page.waitForTimeout(250);
        const selectedState = await renderedStrokeState(page);
        if (!selectedState?.selected) {
            throw new Error(`Target shape did not select: ${JSON.stringify(selectedState)}`);
        }

        await page.evaluate(() => {
            const input = document.querySelector('#shape-stroke-opacity');
            input.value = '100';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.waitForTimeout(250);

        const finalState = await renderedStrokeState(page);
        if (finalState.datasetStrokeTransparent !== '0'
            || finalState.inspectorTransparentChecked !== false
            || finalState.svgStroke === 'none'
            || Number(finalState.svgStrokeWidth) <= 0
            || Number(finalState.svgStrokeOpacity) <= 0) {
            throw new Error(`Stroke stayed invisible after opacity edit: ${JSON.stringify(finalState)}`);
        }

        console.log('PASS doc4379 shape stroke opacity regression', JSON.stringify(finalState));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
