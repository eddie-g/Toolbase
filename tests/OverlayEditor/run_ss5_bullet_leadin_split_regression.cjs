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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'ss-5.pdf');

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1200);
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

async function postJson(page, url) {
    return page.evaluate(async (targetUrl) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(targetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
        let body = null;
        try {
            body = await response.json();
        } catch (_error) {
            body = await response.text();
        }
        return {
            ok: response.ok,
            status: response.status,
            body,
        };
    }, url);
}

async function forceRefreshOverlay(page, documentId) {
    const response = await postJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function enableEditTextMode(page) {
    await page.evaluate(() => {
        const button = document.getElementById('mode-edit-text');
        if (!button) {
            throw new Error('Could not find Edit Text button.');
        }
        if (document.querySelector('#viewer')?.classList.contains('edit-text-mode')) {
            return;
        }
        button.click();
    });

    await page.waitForFunction(() => document.querySelector('#viewer')?.classList.contains('edit-text-mode') === true, {
        timeout: 30000,
    });
    await page.waitForTimeout(2500);
}

async function collectPageOnePromotedState(page) {
    return page.evaluate(() => {
        const normalize = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
        const promotedElements = Array.from(
            document.querySelectorAll('.page[data-page-index="0"] .annotation.promoted-extraction, .page-wrapper[data-page-number="1"] .annotation.promoted-extraction')
        );

        const sample = promotedElements.map((element) => ({
            id: String(element.dataset.annotationId || element.dataset.id || ''),
            text: String(element.innerText || element.textContent || ''),
            normalizedText: normalize(element.innerText || element.textContent || ''),
        }));

        return {
            sample,
            bulletOnly: sample.filter((entry) => /^(?:•|●)(?:\s*(?:•|●))*$/.test(entry.normalizedText)),
            importantWithBullet: sample.filter((entry) => /IMPORTANT:/.test(entry.normalizedText) && /[•●]/.test(entry.text)),
            cleanImportant: sample.filter((entry) => /IMPORTANT:/.test(entry.normalizedText) && !/[•●]/.test(entry.text)),
        };
    });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1800 } });

    try {
        const documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await enableEditTextMode(page);
        await page.waitForTimeout(1500);

        const state = await collectPageOnePromotedState(page);
        if (state.bulletOnly.length > 0) {
            throw new Error(`Found bullet-only promoted annotations on page 1: ${JSON.stringify(state.bulletOnly, null, 2)}`);
        }
        if (state.importantWithBullet.length > 0) {
            throw new Error(`Found IMPORTANT paragraph polluted by bullet lead-in: ${JSON.stringify(state.importantWithBullet, null, 2)}`);
        }
        if (state.cleanImportant.length === 0) {
            throw new Error(`Could not find clean IMPORTANT paragraph annotation: ${JSON.stringify(state, null, 2)}`);
        }

        console.log('ss-5 bullet lead-in split regression passed');
        console.log(JSON.stringify({
            documentId,
            cleanImportant: state.cleanImportant,
            promotedCount: state.sample.length,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
