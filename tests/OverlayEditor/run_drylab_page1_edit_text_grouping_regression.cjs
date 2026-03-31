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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'drylab_page_1.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1000);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/, { timeout: 90000 });
    await page.waitForTimeout(3000);

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function enableEditTextMode(page) {
    await page.evaluate(() => {
        const overlayToggle = document.getElementById('mode-overlay-toggle');
        if (overlayToggle?.checked) {
            overlayToggle.checked = false;
            overlayToggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === false, null, {
        timeout: 30000,
    });
    await page.click('#mode-edit-text');
    await page.waitForFunction(() => document.querySelectorAll('.annotation.promoted-extraction').length > 0, null, {
        timeout: 90000,
    });
    await page.waitForTimeout(2500);
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 2200 } });

    try {
        const documentId = await uploadPdf(page);
        await enableEditTextMode(page);

        const state = await page.evaluate(() => {
            const normalizeInner = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            return Array.from(document.querySelectorAll('.annotation.promoted-extraction')).map((element) => {
                const textElement = element.querySelector('.annotation-text') || element;
                const rect = element.getBoundingClientRect();
                const lines = String(textElement.innerText || textElement.textContent || '')
                    .split('\n')
                    .map((line) => normalizeInner(line))
                    .filter(Boolean);
                return {
                    text: lines.join('\n'),
                    lines,
                    left: rect.left,
                    top: rect.top,
                    width: rect.width,
                    height: rect.height,
                };
            }).sort((left, right) => (
                left.top - right.top || left.left - right.left
            ));
        });

        const meetingsAnnotation = state.find((entry) => entry.lines.length === 1 && entry.lines[0] === 'meetings');
        if (!meetingsAnnotation) {
            throw new Error(`missing standalone meetings annotation: ${JSON.stringify(state, null, 2)}`);
        }

        const cityAnnotation = state.find((entry) =>
            entry.lines.length === 2
            && entry.lines[0] === 'NY · SF'
            && entry.lines[1] === 'LA · LV'
        );
        if (!cityAnnotation) {
            throw new Error(`missing NY · SF / LA · LV annotation: ${JSON.stringify(state, null, 2)}`);
        }

        const companyAnnotation = state.find((entry) =>
            entry.lines.length === 6
            && entry.lines[0].startsWith('Academy of Motion Picture Arts and Sciences')
            && entry.lines[3].startsWith('Google · IBM')
            && entry.lines[4].startsWith('Cinematographers Guild')
            && entry.lines[5].startsWith('Screening Room')
        );
        if (!companyAnnotation) {
            throw new Error(`missing 6-line company annotation: ${JSON.stringify(state, null, 2)}`);
        }

        const forbiddenFragments = [
            'meetings\nGoogle · IBM',
            'NY · SF\nCinematographers Guild',
            'LA · LV\nScreening Room',
            'meetings Google · IBM',
            'NY · SF Cinematographers Guild',
            'LA · LV Screening Room',
        ];
        const forbiddenMatches = state
            .map((entry) => entry.text)
            .filter((text) => forbiddenFragments.some((fragment) => text.includes(fragment)));
        if (forbiddenMatches.length > 0) {
            throw new Error(`found forbidden merged promoted annotations: ${JSON.stringify(forbiddenMatches)}`);
        }

        if (!(companyAnnotation.left > meetingsAnnotation.left + 40)) {
            throw new Error(`company annotation is not positioned to the right of meetings label: ${JSON.stringify({ meetingsAnnotation, companyAnnotation })}`);
        }

        const screenshotPath = path.join(OUTPUT_DIR, `drylab_doc${documentId}_page1_edit_text_grouping.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        console.log('drylab page 1 edit-text grouping regression passed');
        console.log(JSON.stringify({
            documentId,
            screenshotPath,
            meetingsAnnotation,
            cityAnnotation,
            companyAnnotation,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
