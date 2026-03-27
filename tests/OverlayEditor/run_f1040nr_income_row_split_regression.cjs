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
const FIRST_ROW_TEXT = 'Total amount from Form(s) W-2, box 1 (see instructions)';
const SECOND_ROW_TEXT = 'Household employee wages not reported on Form(s) W-2';
const ROLLOVER_ROW_TEXT = 'Taxable amount .';

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

async function waitForIncomeRows(page) {
    await page.waitForFunction(
        ({ firstRowText, secondRowText, rolloverRowText }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            return promoted.some((annotation) => normalizeText(annotation.text || '').includes(firstRowText))
                && promoted.some((annotation) => normalizeText(annotation.text || '').includes(secondRowText));
        },
        { firstRowText: FIRST_ROW_TEXT, secondRowText: SECOND_ROW_TEXT, rolloverRowText: ROLLOVER_ROW_TEXT },
        { timeout: 90000 }
    );
}

async function collectIncomeGrouping(page) {
    return page.evaluate(({ firstRowText, secondRowText }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
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
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
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

        const firstRow = summary.find((entry) => entry.text.includes(firstRowText)) || null;
        const secondRow = summary.find((entry) => entry.text.includes(secondRowText)) || null;
        const combined = summary.filter((entry) => entry.text.includes(firstRowText) && entry.text.includes(secondRowText));
        const combinedLabel = summary.find((entry) => /^1\s*a\s*b$/i.test(entry.text)) || null;
        const firstLabel = summary.find((entry) => /^1\s*a$/i.test(entry.text) || /^1a$/i.test(entry.text)) || null;
        const secondLabel = summary.find((entry) => /^b$/i.test(entry.text)) || null;
        const combinedRollover = summary.find((entry) => normalizeText(entry.text) === 'Rollover b Taxable amount . Rollover') || null;
        const rolloverWords = summary.filter((entry) => normalizeText(entry.text) === 'Rollover');
        const taxableAmountRow = summary.find((entry) => normalizeText(entry.text) === 'b Taxable amount .') || null;
        const combined1fDots = summary.find((entry) => /^1f\s+[. ]+$/i.test(normalizeText(entry.text))) || null;
        const combined1g1h = summary.find((entry) => /^1g\s+1h$/i.test(normalizeText(entry.text))) || null;
        const leaderOnly = summary.find((entry) => /^[. ]{6,}$/.test(entry.text)) || null;
        const label1f = summary.find((entry) => /^1f$/i.test(normalizeText(entry.text))) || null;
        const label1g = summary.find((entry) => /^1g$/i.test(normalizeText(entry.text))) || null;
        const label1h = summary.find((entry) => /^1h$/i.test(normalizeText(entry.text))) || null;

        return {
            firstRow,
            secondRow,
            combined,
            combinedLabel,
            firstLabel,
            secondLabel,
            combinedRollover,
            rolloverWords,
            taxableAmountRow,
            combined1fDots,
            combined1g1h,
            leaderOnly,
            label1f,
            label1g,
            label1h,
            candidates: summary.slice(0, 100),
        };
    }, { firstRowText: FIRST_ROW_TEXT, secondRowText: SECOND_ROW_TEXT });
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
        await waitForIncomeRows(page);
        await page.waitForTimeout(1500);

        const actual = await collectIncomeGrouping(page);
        const checks = [];

        checks.push(buildCheck('first_row_present', Boolean(actual.firstRow), {
            available: (actual.candidates || []).map((entry) => entry.text),
        }));
        checks.push(buildCheck('second_row_present', Boolean(actual.secondRow), {
            available: (actual.candidates || []).map((entry) => entry.text),
        }));
        checks.push(buildCheck('no_combined_income_rows', (actual.combined || []).length === 0, {
            combined: actual.combined,
        }));
        checks.push(buildCheck('no_combined_label_stack', !actual.combinedLabel, {
            combinedLabel: actual.combinedLabel,
        }));

        if (actual.firstRow) {
            checks.push(buildCheck('first_row_exact_geometry', actual.firstRow.exactGeometry === true, {
                exactGeometry: actual.firstRow.exactGeometry,
                text: actual.firstRow.text,
            }));
            checks.push(buildCheck('first_row_not_grouped_with_second', !actual.firstRow.text.includes(SECOND_ROW_TEXT), {
                text: actual.firstRow.text,
            }));
        }

        if (actual.secondRow) {
            checks.push(buildCheck('second_row_exact_geometry', actual.secondRow.exactGeometry === true, {
                exactGeometry: actual.secondRow.exactGeometry,
                text: actual.secondRow.text,
            }));
            checks.push(buildCheck('second_row_not_grouped_with_first', !actual.secondRow.text.includes(FIRST_ROW_TEXT), {
                text: actual.secondRow.text,
            }));
        }

        checks.push(buildCheck('first_label_present', Boolean(actual.firstLabel), {
            text: actual.firstLabel?.text || null,
        }));
        checks.push(buildCheck('second_label_present', Boolean(actual.secondLabel), {
            text: actual.secondLabel?.text || null,
        }));
        checks.push(buildCheck('rollover_row_not_combined', !actual.combinedRollover, {
            combinedRollover: actual.combinedRollover,
        }));
        checks.push(buildCheck('rollover_words_split', Array.isArray(actual.rolloverWords) && actual.rolloverWords.length === 2, {
            rolloverWords: actual.rolloverWords,
        }));
        checks.push(buildCheck('taxable_amount_row_present', Boolean(actual.taxableAmountRow), {
            taxableAmountRow: actual.taxableAmountRow,
        }));
        checks.push(buildCheck('no_combined_1f_dots', !actual.combined1fDots, {
            combined1fDots: actual.combined1fDots,
        }));
        checks.push(buildCheck('no_combined_1g_1h', !actual.combined1g1h, {
            combined1g1h: actual.combined1g1h,
        }));
        checks.push(buildCheck('leader_only_present', Boolean(actual.leaderOnly), {
            leaderOnly: actual.leaderOnly,
        }));
        checks.push(buildCheck('label_1f_present', Boolean(actual.label1f), {
            label1f: actual.label1f,
        }));
        checks.push(buildCheck('label_1g_present', Boolean(actual.label1g), {
            label1g: actual.label1g,
        }));
        checks.push(buildCheck('label_1h_present', Boolean(actual.label1h), {
            label1h: actual.label1h,
        }));

        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_income_row_split_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `f1040nr_income_row_split_${runToken}.json`);
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
        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_income_row_split_${runToken}_error.png`);
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
