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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'f2290.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1700 };
const TARGET_PAGE_INDEX = 3;
const TARGET_PAGE_NUMBER = 4;
const EXPECTED_LINES = [
    '55,000',
    '55,001 - 56,000',
    '56,001 - 57,000',
    '57,001 - 58,000',
    '58,001 - 59,000',
    '59,001 - 60,000',
    '60,001 - 61,000',
    '61,001 - 62,000',
    '62,001 - 63,000',
    '63,001 - 64,000',
    '64,001 - 65,000',
    '65,001 - 66,000',
    '66,001 - 67,000',
    '67,001 - 68,000',
    '68,001 - 69,000',
    '69,001 - 70,000',
    '70,001 - 71,000',
    '71,001 - 72,000',
    '72,001 - 73,000',
    '73,001 - 74,000',
    '74,001 - 75,000',
    'over 75,000',
];
const EXPECTED_ANNUAL_TAX_LINES = [
    '$100.00',
    '122.00',
    '144.00',
    '166.00',
    '188.00',
    '210.00',
    '232.00',
    '254.00',
    '276.00',
    '298.00',
    '320.00',
    '342.00',
    '364.00',
    '386.00',
    '408.00',
    '430.00',
    '452.00',
    '474.00',
    '496.00',
    '518.00',
    '540.00',
    '550.00',
];
const EXPECTED_LOGGING_TAX_LINES = [
    '$75.00',
    '91.50',
    '108.00',
    '124.50',
    '141.00',
    '157.50',
    '174.00',
    '190.50',
    '207.00',
    '223.50',
    '240.00',
    '256.50',
    '273.00',
    '289.50',
    '306.00',
    '322.50',
    '339.00',
    '355.50',
    '372.00',
    '388.50',
    '405.00',
    '412.50',
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
    await page.waitForSelector(`.page[data-page-index="${TARGET_PAGE_INDEX}"] canvas, .page-wrapper[data-page-number="${TARGET_PAGE_NUMBER}"] canvas`, {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function waitForWeightRanges(page) {
    await page.waitForFunction(
        ({ expectedLines, expectedAnnualTaxLines, expectedLoggingTaxLines, targetPageIndex }) => {
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
            return expectedLines.every((line) => texts.includes(line))
                && expectedAnnualTaxLines.every((line) => texts.includes(line))
                && expectedLoggingTaxLines.every((line) => texts.includes(line));
        },
        {
            expectedLines: EXPECTED_LINES,
            expectedAnnualTaxLines: EXPECTED_ANNUAL_TAX_LINES,
            expectedLoggingTaxLines: EXPECTED_LOGGING_TAX_LINES,
            targetPageIndex: TARGET_PAGE_INDEX,
        },
        { timeout: 90000 }
    );
}

async function collectWeightRanges(page) {
    return page.evaluate(({ expectedLines, expectedAnnualTaxLines, expectedLoggingTaxLines, targetPageIndex, targetPageNumber }) => {
        const normalizeText = (value) => String(value || '')
            .replace(/[−–—]/g, '-')
            .replace(/\s+/g, ' ')
            .trim();
        const pageRoot = document.querySelector(`.page-wrapper[data-page-number="${targetPageNumber}"], .page[data-page-index="${targetPageIndex}"]`);
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
                && Number(annotation.pageIndex) === Number(targetPageIndex)
            ))
            : [];

        const summary = promoted.map((annotation) => {
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

        const matchedEntries = expectedLines.map((line) => ({
            expected: line,
            entry: summary.find((item) => item.text === line) || null,
        }));
        const annualTaxEntries = expectedAnnualTaxLines.map((line) => ({
            expected: line,
            entry: summary.find((item) => item.text === line) || null,
        }));
        const loggingTaxEntries = expectedLoggingTaxLines.map((line) => ({
            expected: line,
            entry: summary.find((item) => item.text === line) || null,
        }));
        const giantCombined = summary.filter((entry) => (
            entry.text.includes('55,000')
            && entry.text.includes('over 75,000')
        ));
        const giantAnnualTaxCombined = summary.filter((entry) => (
            entry.text.includes('$100.00')
            && entry.text.includes('550.00')
        ));
        const giantLoggingTaxCombined = summary.filter((entry) => (
            entry.text.includes('$75.00')
            && entry.text.includes('412.50')
        ));

        return {
            matchedEntries,
            annualTaxEntries,
            loggingTaxEntries,
            giantCombined,
            giantAnnualTaxCombined,
            giantLoggingTaxCombined,
            candidates: summary.slice(0, 180),
        };
    }, {
        expectedLines: EXPECTED_LINES,
        expectedAnnualTaxLines: EXPECTED_ANNUAL_TAX_LINES,
        expectedLoggingTaxLines: EXPECTED_LOGGING_TAX_LINES,
        targetPageIndex: TARGET_PAGE_INDEX,
        targetPageNumber: TARGET_PAGE_NUMBER,
    });
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
        await waitForWeightRanges(page);
        await page.waitForTimeout(1500);

        const actual = await collectWeightRanges(page);
        const checks = [];
        const missing = actual.matchedEntries.filter((item) => !item.entry).map((item) => item.expected);
        const nonExact = actual.matchedEntries.filter((item) => item.entry && item.entry.exactGeometry !== true);
        const missingAnnualTax = actual.annualTaxEntries.filter((item) => !item.entry).map((item) => item.expected);
        const nonExactAnnualTax = actual.annualTaxEntries.filter((item) => item.entry && item.entry.exactGeometry !== true);
        const missingLoggingTax = actual.loggingTaxEntries.filter((item) => !item.entry).map((item) => item.expected);
        const nonExactLoggingTax = actual.loggingTaxEntries.filter((item) => item.entry && item.entry.exactGeometry !== true);

        checks.push(buildCheck('all_weight_range_lines_present', missing.length === 0, { missing }));
        checks.push(buildCheck('no_giant_combined_weight_block', (actual.giantCombined || []).length === 0, {
            giantCombined: actual.giantCombined,
        }));
        checks.push(buildCheck('all_weight_range_lines_exact_geometry', nonExact.length === 0, {
            nonExact,
        }));
        checks.push(buildCheck('all_annual_tax_lines_present', missingAnnualTax.length === 0, {
            missingAnnualTax,
        }));
        checks.push(buildCheck('no_giant_combined_annual_tax_block', (actual.giantAnnualTaxCombined || []).length === 0, {
            giantAnnualTaxCombined: actual.giantAnnualTaxCombined,
        }));
        checks.push(buildCheck('all_annual_tax_lines_exact_geometry', nonExactAnnualTax.length === 0, {
            nonExactAnnualTax,
        }));
        checks.push(buildCheck('all_logging_tax_lines_present', missingLoggingTax.length === 0, {
            missingLoggingTax,
        }));
        checks.push(buildCheck('no_giant_combined_logging_tax_block', (actual.giantLoggingTaxCombined || []).length === 0, {
            giantLoggingTaxCombined: actual.giantLoggingTaxCombined,
        }));
        checks.push(buildCheck('all_logging_tax_lines_exact_geometry', nonExactLoggingTax.length === 0, {
            nonExactLoggingTax,
        }));

        const screenshotPath = path.join(OUTPUT_DIR, `f2290_weight_range_split_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `f2290_weight_range_split_${runToken}.json`);
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
        const screenshotPath = path.join(OUTPUT_DIR, `f2290_weight_range_split_${runToken}_error.png`);
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
