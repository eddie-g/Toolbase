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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'f1040nr.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1700 };

const ROW22_TEXT = 'Subtract line 21 from line 18. If zero or less, enter -0-';
const ROW23A_TEXT = 'Tax on income not effectively connected with a U.S. trade or business';
const ROW23B_TEXT = 'Other taxes, including self-employment tax';
const ROW23C_TEXT = 'Transportation tax';
const ROW23D_TEXT = 'Add lines 23a through 23c';
const ROW24_TEXT = 'Add lines 22 and 23d. This is your total tax';
const HEADER_WORDS = ['Payments', 'and', 'Refundable', 'Credits'];

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
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
    await page.waitForSelector('.page[data-page-index="1"] canvas, .page-wrapper[data-page-number="2"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function waitForSection(page) {
    await page.waitForFunction(
        ({ row22Text, row23aText, row24Text }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            return promoted.some((annotation) => normalizeText(annotation.text || '').includes(row22Text))
                && promoted.some((annotation) => normalizeText(annotation.text || '').includes(row23aText))
                && promoted.some((annotation) => normalizeText(annotation.text || '').includes(row24Text));
        },
        { row22Text: ROW22_TEXT, row23aText: ROW23A_TEXT, row24Text: ROW24_TEXT },
        { timeout: 90000 }
    );
}

async function collectSection(page) {
    return page.evaluate(({ row22Text, row23aText, row23bText, row23cText, row23dText, row24Text, headerWords }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="2"], .page[data-page-index="1"]');
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
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element && Number(annotation.pageIndex) === 1)
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

        const giantCombined = summary.filter((entry) => (
            entry.text.includes(row22Text)
            && entry.text.includes(row23aText)
            && entry.text.includes(row24Text)
        ));

        return {
            row22: summary.find((entry) => entry.text.includes(row22Text)) || null,
            row23a: summary.find((entry) => entry.text.includes(row23aText)) || null,
            row23b: summary.find((entry) => entry.text.includes(row23bText)) || null,
            row23c: summary.find((entry) => entry.text.includes(row23cText)) || null,
            row23d: summary.find((entry) => entry.text.includes(row23dText)) || null,
            row24: summary.find((entry) => entry.text.includes(row24Text)) || null,
            headerWords: headerWords.map((word) => ({
                word,
                entry: summary.find((entry) => entry.text === word) || null,
            })),
            giantCombined,
            candidates: summary.slice(0, 180),
        };
    }, {
        row22Text: ROW22_TEXT,
        row23aText: ROW23A_TEXT,
        row23bText: ROW23B_TEXT,
        row23cText: ROW23C_TEXT,
        row23dText: ROW23D_TEXT,
        row24Text: ROW24_TEXT,
        headerWords: HEADER_WORDS,
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
        await waitForSection(page);
        await page.waitForTimeout(1500);

        const actual = await collectSection(page);
        const checks = [];
        const available = (actual.candidates || []).map((entry) => entry.text);

        checks.push(buildCheck('row22_present', Boolean(actual.row22), { available }));
        checks.push(buildCheck('row23a_present', Boolean(actual.row23a), { available }));
        checks.push(buildCheck('row23b_present', Boolean(actual.row23b), { available }));
        checks.push(buildCheck('row23c_present', Boolean(actual.row23c), { available }));
        checks.push(buildCheck('row23d_present', Boolean(actual.row23d), { available }));
        checks.push(buildCheck('row24_present', Boolean(actual.row24), { available }));
        checks.push(buildCheck('no_giant_combined_section', (actual.giantCombined || []).length === 0, { combined: actual.giantCombined }));

        HEADER_WORDS.forEach((word) => {
            const match = (actual.headerWords || []).find((entry) => entry.word === word);
            checks.push(buildCheck(`header_${word}_present`, Boolean(match?.entry), { entry: match?.entry || null }));
        });

        ['row22', 'row23a', 'row23b', 'row23c', 'row23d', 'row24'].forEach((key) => {
            if (actual[key]) {
                checks.push(buildCheck(`${key}_exact_geometry`, actual[key].exactGeometry === true, actual[key]));
            }
        });

        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_tax_section_split_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `f1040nr_tax_section_split_${runToken}.json`);
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
        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_tax_section_split_${runToken}_error.png`);
        try {
            await page.screenshot({ path: screenshotPath, fullPage: true });
        } catch (_error) {
            // ignore
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
