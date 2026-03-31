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
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1800 };

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

async function ensureOverlayEditorOff(page) {
    const toggled = await page.evaluate(() => {
        if (typeof overlayEditorActive === 'undefined' || overlayEditorActive !== true) {
            return false;
        }
        const toggle = document.getElementById('mode-overlay-toggle');
        const checkbox = document.getElementById('mode-overlay');
        const target = toggle || checkbox;
        if (!target) {
            return false;
        }
        target.click();
        return true;
    });

    if (toggled) {
        await page.waitForFunction(() => typeof overlayEditorActive === 'undefined' || overlayEditorActive === false, {
            timeout: 20000,
        });
        await page.waitForTimeout(1200);
    }
}

async function renderAllPages(page) {
    await page.evaluate(() => window.scrollTo(0, 0));
    for (let pageNumber = 1; pageNumber <= 5; pageNumber += 1) {
        const selector = `.page-wrapper[data-page-number="${pageNumber}"], .page[data-page-index="${pageNumber - 1}"]`;
        const target = page.locator(selector).first();
        await target.waitFor({ state: 'attached', timeout: 90000 });
        await target.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(800);
}

async function collectWidgetState(page) {
    return page.evaluate(() => {
        const resolvePageIndex = (element) => {
            const pageNode = element.closest('.page[data-page-index], .page-wrapper[data-page-number]');
            if (!pageNode) {
                return null;
            }
            if (pageNode.dataset.pageIndex != null) {
                return Number(pageNode.dataset.pageIndex);
            }
            if (pageNode.dataset.pageNumber != null) {
                return Number(pageNode.dataset.pageNumber) - 1;
            }
            return null;
        };

        const previews = Array.from(document.querySelectorAll('.acroform-preview-field')).map((element) => {
            const rect = element.getBoundingClientRect();
            const control = element.querySelector('input, textarea, select');
            let value = '';
            let disabled = false;

            if (control instanceof HTMLInputElement) {
                value = control.type === 'checkbox' || control.type === 'radio'
                    ? String(control.checked)
                    : String(control.value || '');
                disabled = control.disabled;
            } else if (control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement) {
                value = String(control.value || '');
                disabled = control.disabled;
            }

            return {
                pageIndex: resolvePageIndex(element),
                fieldType: String(element.dataset.fieldType || ''),
                className: element.className,
                isInteractive: element.classList.contains('is-interactive'),
                width: rect.width,
                height: rect.height,
                left: rect.left,
                top: rect.top,
                value,
                disabled,
            };
        });

        const phantomTextWidgets = previews.filter((preview) => (
            preview.fieldType === 'TX'
            && preview.disabled
            && !preview.isInteractive
            && String(preview.value || '').trim() === ''
            && preview.width <= 18.5
            && preview.height <= 18.5
        ));

        const pageCounts = previews.reduce((accumulator, preview) => {
            const key = String(preview.pageIndex);
            accumulator[key] = (accumulator[key] || 0) + 1;
            return accumulator;
        }, {});

        const annotationsArray = Array.isArray(window.annotations) ? window.annotations : [];
        const autoDetectedDrawnCheckboxes = annotationsArray.filter((annotation) => annotation?.autoDetectedDrawnCheckbox);

        return {
            previews,
            phantomTextWidgets,
            pageCounts,
            autoDetectedDrawnCheckboxCount: autoDetectedDrawnCheckboxes.length,
            autoDetectedDrawnCheckboxSample: autoDetectedDrawnCheckboxes.slice(0, 10).map((annotation) => ({
                id: annotation.id,
                pageIndex: annotation.pageIndex,
                rect: [annotation.pdfX, annotation.pdfY, annotation.pdfWidth, annotation.pdfHeight],
            })),
        };
    });
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `ss5_no_phantom_widget_${runToken}.png`);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await ensureOverlayEditorOff(page);
        await renderAllPages(page);

        const state = await collectWidgetState(page);
        const pageOneToFourPreviewCount = [0, 1, 2, 3]
            .reduce((total, pageIndex) => total + Number(state.pageCounts[String(pageIndex)] || 0), 0);
        const pageFivePreviewCount = Number(state.pageCounts['4'] || 0);
        const pageOneToFourPreviewSample = state.previews
            .filter((preview) => preview.pageIndex != null && preview.pageIndex >= 0 && preview.pageIndex <= 3)
            .slice(0, 20);

        if (state.autoDetectedDrawnCheckboxCount !== 0) {
            throw new Error(`Expected no auto-detected drawn checkboxes for ss-5.pdf: ${JSON.stringify(state.autoDetectedDrawnCheckboxSample, null, 2)}`);
        }

        if (state.phantomTextWidgets.length !== 0) {
            throw new Error(`Found phantom passive AcroForm text widgets: ${JSON.stringify(state.phantomTextWidgets, null, 2)}`);
        }

        if (pageOneToFourPreviewCount !== 0) {
            throw new Error(
                `Expected no passive AcroForm previews on pages 1-4 of ss-5.pdf, found ${pageOneToFourPreviewCount}: `
                + `${JSON.stringify({ pageCounts: state.pageCounts, pageOneToFourPreviewSample }, null, 2)}`
            );
        }

        if (pageFivePreviewCount < 10) {
            throw new Error(`Expected real AcroForm widgets on page 5, found ${pageFivePreviewCount}: ${JSON.stringify(state.pageCounts, null, 2)}`);
        }

        console.log('ss-5 phantom widget regression passed');
        console.log(JSON.stringify({
            documentId,
            pageCounts: state.pageCounts,
            previewCount: state.previews.length,
            phantomTextWidgets: state.phantomTextWidgets,
        }, null, 2));
    } catch (error) {
        try {
            await page.screenshot({ path: screenshotPath, fullPage: true });
        } catch (_screenshotError) {}
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
