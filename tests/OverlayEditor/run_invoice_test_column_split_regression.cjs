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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'invoice_test.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1700 };

const PAGE_ONE_EXPECTED = [
    'SALESPERSON',
    'P.O. NUMBER',
    'TERMS',
    'UNIT PRICE',
    'TOTAL',
    '12.45',
    '124.50',
    '16.00',
    '400.00',
];

const PAGE_TWO_EXPECTED = [
    '12.45',
    '186.75',
    'SUBTOTAL',
    '5964.50',
    'TOTAL DUE',
    '6610.95',
];

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '')
        .replace(/[−–—]/g, '-')
        .replace(/\s+/g, ' ')
        .trim();
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
    await page.waitForSelector('.page[data-page-index="1"] canvas, .page-wrapper[data-page-number="2"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function scrollPageIntoView(page, pageNumber) {
    await page.evaluate((targetPageNumber) => {
        const pageRoot = document.querySelector(`.page-wrapper[data-page-number="${targetPageNumber}"], .page[data-page-index="${targetPageNumber - 1}"]`);
        if (pageRoot instanceof HTMLElement) {
            pageRoot.scrollIntoView({ block: 'center' });
        }
    }, pageNumber);
    await page.waitForTimeout(1200);
}

async function waitForInvoiceColumns(page) {
    await scrollPageIntoView(page, 1);
    await page.waitForFunction(
        ({ expectedTexts, targetPageIndex }) => {
            const normalizeText = (value) => String(value || '')
                .replace(/[−–—]/g, '-')
                .replace(/\s+/g, ' ')
                .trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => (
                    annotation?.promotedFromExtraction
                    && annotation?.element
                    && Number(annotation.pageIndex) === Number(targetPageIndex)
                ))
                : [];
            const texts = promoted.map((annotation) => normalizeText(annotation.text || ''));
            return expectedTexts.every((item) => texts.includes(item));
        },
        {
            expectedTexts: ['SALESPERSON', 'UNIT PRICE', 'TOTAL', '12.45', '124.50'],
            targetPageIndex: 0,
        },
        { timeout: 90000 }
    );

    await scrollPageIntoView(page, 2);
    await page.waitForFunction(
        ({ expectedTexts, targetPageIndex }) => {
            const normalizeText = (value) => String(value || '')
                .replace(/[−–—]/g, '-')
                .replace(/\s+/g, ' ')
                .trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => (
                    annotation?.promotedFromExtraction
                    && annotation?.element
                    && Number(annotation.pageIndex) === Number(targetPageIndex)
                ))
                : [];
            const texts = promoted.map((annotation) => normalizeText(annotation.text || ''));
            return expectedTexts.every((item) => texts.includes(item));
        },
        {
            expectedTexts: ['SUBTOTAL', '5964.50', 'TOTAL DUE', '6610.95', '12.45', '186.75'],
            targetPageIndex: 1,
        },
        { timeout: 90000 }
    );
}

async function collectInvoiceColumns(page) {
    return page.evaluate(({ pageOneExpected, pageTwoExpected }) => {
        const normalizeText = (value) => String(value || '')
            .replace(/[−–—]/g, '-')
            .replace(/\s+/g, ' ')
            .trim();

        const collectPage = (pageIndex, pageNumber) => {
            const pageRoot = document.querySelector(`.page-wrapper[data-page-number="${pageNumber}"], .page[data-page-index="${pageIndex}"]`);
            const pageRect = pageRoot?.getBoundingClientRect() || null;
            const relativeRect = (element) => {
                if (!(element instanceof HTMLElement)) {
                    return null;
                }
                const rect = element.getBoundingClientRect();
                return {
                    left: pageRect ? rect.left - pageRect.left : rect.left,
                    top: pageRect ? rect.top - pageRect.top : rect.top,
                    width: rect.width,
                    height: rect.height,
                };
            };

            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => (
                    annotation?.promotedFromExtraction
                    && annotation?.element
                    && Number(annotation.pageIndex) === Number(pageIndex)
                ))
                : [];

            return promoted.map((annotation) => {
                const annotationEl = annotation.element;
                if (!(annotationEl instanceof HTMLElement)) {
                    return null;
                }
                const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
                return {
                    text: normalizeText(annotation.text || annotationEl.innerText || ''),
                    exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                    ...relativeRect(annotationEl),
                };
            }).filter(Boolean);
        };

        const pageOneSummary = collectPage(0, 1);
        const pageTwoSummary = collectPage(1, 2);
        return {
            pageOneEntries: pageOneExpected.map((item) => ({
                expected: item,
                entry: pageOneSummary.find((entry) => entry.text === item) || null,
            })),
            pageTwoEntries: pageTwoExpected.map((item) => ({
                expected: item,
                entry: pageTwoSummary.find((entry) => entry.text === item) || null,
            })),
            groupedHeaders: pageOneSummary.filter((entry) => entry.text.includes('SALESPERSON') && entry.text.includes('TERMS')),
            groupedPriceRows: pageOneSummary.filter((entry) => entry.text.includes('12.45') && entry.text.includes('124.50')),
            groupedAmountRows: pageTwoSummary.filter((entry) => entry.text.includes('SUBTOTAL') && entry.text.includes('5964.50')),
            pageOneCandidates: pageOneSummary.slice(0, 120),
            pageTwoCandidates: pageTwoSummary.slice(0, 120),
        };
    }, { pageOneExpected: PAGE_ONE_EXPECTED, pageTwoExpected: PAGE_TWO_EXPECTED });
}

function buildCheck(item, pass, detail) {
    return { item, pass, detail };
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    const browserConsole = [];
    page.on('console', (message) => {
        browserConsole.push(`[${message.type()}] ${message.text()}`);
        if (browserConsole.length > 60) browserConsole.shift();
    });
    page.on('pageerror', (error) => {
        browserConsole.push(`[pageerror] ${error?.stack || error?.message || String(error)}`);
        if (browserConsole.length > 60) browserConsole.shift();
    });

    let documentId = null;

    try {
        documentId = await uploadPdf(page);
        await forceRefreshOverlay(page, documentId);
        await clearAnnotationSessionState(page, documentId);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForInvoiceColumns(page);
        await page.waitForTimeout(1500);

        const actual = await collectInvoiceColumns(page);
        const checks = [];

        const missingPageOne = actual.pageOneEntries.filter((item) => !item.entry).map((item) => item.expected);
        const missingPageTwo = actual.pageTwoEntries.filter((item) => !item.entry).map((item) => item.expected);
        const nonExactPageOne = actual.pageOneEntries.filter((item) => item.entry && item.entry.exactGeometry !== true);
        const nonExactPageTwo = actual.pageTwoEntries.filter((item) => item.entry && item.entry.exactGeometry !== true);

        checks.push(buildCheck('page_one_column_entries_present', missingPageOne.length === 0, { missingPageOne }));
        checks.push(buildCheck('page_two_column_entries_present', missingPageTwo.length === 0, { missingPageTwo }));
        checks.push(buildCheck('page_one_column_entries_exact_geometry', nonExactPageOne.length === 0, { nonExactPageOne }));
        checks.push(buildCheck('page_two_column_entries_exact_geometry', nonExactPageTwo.length === 0, { nonExactPageTwo }));
        checks.push(buildCheck('headers_not_grouped', (actual.groupedHeaders || []).length === 0, { groupedHeaders: actual.groupedHeaders }));
        checks.push(buildCheck('price_row_not_grouped', (actual.groupedPriceRows || []).length === 0, { groupedPriceRows: actual.groupedPriceRows }));
        checks.push(buildCheck('amount_rows_not_grouped', (actual.groupedAmountRows || []).length === 0, { groupedAmountRows: actual.groupedAmountRows }));

        const screenshotPath = path.join(OUTPUT_DIR, `invoice_test_column_split_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `invoice_test_column_split_${runToken}.json`);
        const result = {
            status: checks.every((check) => check.pass) ? 'pass' : 'fail',
            documentId,
            actual,
            browserConsole,
            checks,
            reportPath,
            screenshotPath,
        };

        fs.writeFileSync(reportPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
        console.log(JSON.stringify(result, null, 2));

        await browser.close();
        if (!checks.every((check) => check.pass)) {
            process.exitCode = 1;
        }
    } catch (error) {
        const screenshotPath = path.join(OUTPUT_DIR, `invoice_test_column_split_${runToken}_error.png`);
        try {
            await page.screenshot({ path: screenshotPath, fullPage: true });
        } catch (_screenshotError) {
            // ignore screenshot failure
        }
        const result = {
            status: 'error',
            documentId,
            error: {
                message: error?.message || String(error),
                stack: error?.stack || null,
            },
            browserConsole,
            screenshotPath,
        };
        console.log(JSON.stringify(result, null, 2));
        await browser.close();
        process.exitCode = 1;
    }
}

main();
