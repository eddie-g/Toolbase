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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'vba-20-0995-are.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const EXPECTED_LINE = 'used if you DISAGREE with a decision you received.';
const EXPECTED_NOTE_FIRST_LINE = 'Note: A supplemental claim is a new review of an issue(s) previously decided by the Department of Veterans Affairs (VA) based on';
const EXPECTED_SUBMIT_LINE = 'Use this form, VA Form 20-0995, Decision Review Request: Supplemental Claim, to submit a supplemental claim of the decision you received that you';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
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

        const state = await page.evaluate((expectedLine) => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const annotations = Array.from(document.querySelectorAll('.annotation.promoted-extraction'));
            const target = annotations.find((element) => normalize(element.textContent || '').includes(expectedLine));
            if (!target) {
                return { found: false };
            }

            const textElement = target.querySelector('.annotation-text') || target;
            const lines = Array.from(textElement.querySelectorAll('.annotation-exact-line')).map((line) => ({
                text: normalize(line.textContent || ''),
                spanCount: line.querySelectorAll('.annotation-exact-span').length,
                spans: Array.from(line.querySelectorAll('.annotation-exact-span')).map((span) => ({
                    text: normalize(span.textContent || ''),
                    transform: span.style.transform || '',
                    fontWeight: window.getComputedStyle(span).fontWeight || '',
                    fontStyle: window.getComputedStyle(span).fontStyle || '',
                })),
                height: line.style.height,
                lineHeight: line.style.lineHeight,
            }));

            const matchedLine = lines.find((line) => line.text === expectedLine) || null;

            return {
                found: true,
                annotationText: normalize(textElement.textContent || ''),
                lineCount: lines.length,
                lines,
                matchedLine,
            };
        }, EXPECTED_LINE);

        if (!state.found) {
            throw new Error('failed to locate promoted note annotation containing the expected line');
        }
        if (!state.matchedLine) {
            throw new Error(`expected line missing from annotation: ${JSON.stringify(state, null, 2)}`);
        }
        if (state.matchedLine.spanCount < 3) {
            throw new Error(`expected styled fallback source spans for matched line: ${JSON.stringify(state.matchedLine, null, 2)}`);
        }
        if (state.matchedLine.spans.some((span) => /scaleX/i.test(String(span.transform || '')))) {
            throw new Error(`expected no span scale transform on fallback line: ${JSON.stringify(state.matchedLine, null, 2)}`);
        }
        const disagreeSpan = state.matchedLine.spans.find((span) => span.text === 'DISAGREE');
        if (!disagreeSpan || Number(disagreeSpan.fontWeight || '0') < 600) {
            throw new Error(`expected bold DISAGREE span on matched line: ${JSON.stringify(state.matchedLine, null, 2)}`);
        }

        const noteLine = state.lines.find((line) => line.text === EXPECTED_NOTE_FIRST_LINE);
        if (!noteLine) {
            throw new Error(`expected note lead line missing: ${JSON.stringify(state.lines, null, 2)}`);
        }
        const noteSpan = noteLine.spans.find((span) => span.text === 'Note');
        const noteClaimSpan = noteLine.spans.find((span) => span.text === 'supplemental claim');
        if (!noteSpan || Number(noteSpan.fontWeight || '0') < 600 || !noteClaimSpan || Number(noteClaimSpan.fontWeight || '0') < 600) {
            throw new Error(`expected bold Note / supplemental claim spans on note line: ${JSON.stringify(noteLine, null, 2)}`);
        }

        const submitAnnotation = await page.evaluate((expectedLine) => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const annotations = Array.from(document.querySelectorAll('.annotation.promoted-extraction'));
            const target = annotations.find((element) => {
                const textElement = element.querySelector('.annotation-text') || element;
                const lines = Array.from(textElement.querySelectorAll('.annotation-exact-line'));
                return lines.some((line) => normalize(line.textContent || '') === expectedLine);
            });
            if (!target) {
                return null;
            }
            const textElement = target.querySelector('.annotation-text') || target;
            const line = Array.from(textElement.querySelectorAll('.annotation-exact-line')).find((node) => normalize(node.textContent || '') === expectedLine);
            if (!line) {
                return null;
            }
            return {
                text: normalize(line.textContent || ''),
                spans: Array.from(line.querySelectorAll('.annotation-exact-span')).map((span) => ({
                    text: normalize(span.textContent || ''),
                    transform: span.style.transform || '',
                    fontWeight: window.getComputedStyle(span).fontWeight || '',
                    fontStyle: window.getComputedStyle(span).fontStyle || '',
                })),
            };
        }, EXPECTED_SUBMIT_LINE);
        if (!submitAnnotation) {
            throw new Error(`expected submit line annotation missing: ${EXPECTED_SUBMIT_LINE}`);
        }
        const submitBoldClaimSpan = submitAnnotation.spans.find((span) => span.text === 'supplemental claim');
        const submitItalicTitleSpan = submitAnnotation.spans.find((span) => span.text === 'Decision Review Request: Supplemental Claim');
        if (!submitBoldClaimSpan || Number(submitBoldClaimSpan.fontWeight || '0') < 600) {
            throw new Error(`expected bold supplemental claim span on submit line: ${JSON.stringify(submitAnnotation, null, 2)}`);
        }
        if (!submitItalicTitleSpan || !/italic/i.test(submitItalicTitleSpan.fontStyle || '')) {
            throw new Error(`expected italic decision review title span on submit line: ${JSON.stringify(submitAnnotation, null, 2)}`);
        }

        const screenshotPath = path.join(OUTPUT_DIR, `vba_20995_doc${documentId}_note_span_fallback.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        console.log('vba 20-0995 note span fallback regression passed');
        console.log(JSON.stringify({
            documentId,
            screenshotPath,
            matchedLine: state.matchedLine,
            lineCount: state.lineCount,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
