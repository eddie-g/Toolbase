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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'fw8ben.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1600 };

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

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
        return { ok: response.ok, status: response.status, body };
    }, url);
}

async function forceRefreshOverlay(page, documentId) {
    const response = await postJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function clearAnnotationSessionState(page, documentId) {
    await page.evaluate((id) => {
        sessionStorage.removeItem(`pdf-annotations-${id}`);
        localStorage.removeItem('pdf_session_id');
    }, documentId);
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_edit_mode_probe_${runToken}.png`);
    const reportPath = path.join(OUTPUT_DIR, `fw8ben_edit_mode_probe_${runToken}.json`);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await page.waitForTimeout(10000);
        await page.locator('#mode-edit-text').click();
        await page.waitForTimeout(2500);

        const payload = await page.evaluate(() => {
            const normalize = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
            const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
            const pageRect = pageRoot?.getBoundingClientRect() || null;
            const relativeRect = (element) => {
                const rect = element.getBoundingClientRect();
                return {
                    left: pageRect ? rect.left - pageRect.left : rect.left,
                    top: pageRect ? rect.top - pageRect.top : rect.top,
                    width: rect.width,
                    height: rect.height,
                };
            };

            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            return {
                toolMode: typeof toolMode !== 'undefined' ? toolMode : null,
                overlayEditorActive: typeof overlayEditorActive !== 'undefined' ? overlayEditorActive : null,
                promotedCount: promotedAnnotations.length,
                promoted: promotedAnnotations.map((annotation) => {
                    const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
                    return {
                        text: normalize(textEl.innerText || textEl.textContent || annotation.text || ''),
                        exact: textEl?.dataset?.exactPromotedGeometry || null,
                        rect: relativeRect(annotation.element),
                    };
                }).filter((entry) => (
                    /NOT an individual|citizen|W-9|W-8BEN-E|W-8ECI|8233 or W-4|W-8IMY/.test(entry.text)
                    || entry.rect.top < 340
                )),
            };
        });

        await page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first().screenshot({ path: screenshotPath });
        fs.writeFileSync(reportPath, `${JSON.stringify({ documentId, payload, screenshotPath }, null, 2)}\n`);
        console.log(JSON.stringify({ documentId, payload, screenshotPath, reportPath }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
