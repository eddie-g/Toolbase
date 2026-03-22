#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'isator.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const TARGET_TEXT = process.env.TARGET_TEXT || 'This is the documentation for the Isartor';
const APPEND_TEXT = process.env.APPEND_TEXT || ' sdfdsf';
const PAGE_INDEX = Number(process.env.PAGE_INDEX || 0);
const PYTHON = process.env.PYTHON_BIN || 'python3';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
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

async function activateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('Missing overlay mode toggle');
        }
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, {
        timeout: 30000,
    });
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, {
        timeout: 30000,
    });
    await page.waitForTimeout(5000);
}

async function findTargetField(page) {
    const locator = page.locator('.overlay-field').filter({ hasText: TARGET_TEXT }).first();
    if (await locator.count() === 0) {
        const overlayState = await page.evaluate(() => ({
            fieldCount: document.querySelectorAll('.overlay-field').length,
            domAnnotationCount: document.querySelectorAll('.annotation').length,
            annotationCount: typeof annotations !== 'undefined' && Array.isArray(annotations) ? annotations.length : null,
            overlayEditorActive: typeof overlayEditorActive !== 'undefined' ? overlayEditorActive : null,
            loadedSavedPdfMode: typeof loadedSavedPdfMode !== 'undefined' ? loadedSavedPdfMode : null,
            sampleTexts: Array.from(document.querySelectorAll('.overlay-field'))
                .slice(0, 20)
                .map((field) => {
                    const editor = field.querySelector('[contenteditable]') || field;
                    return {
                        wordIndex: field.dataset.wordIndex || null,
                        text: (editor.innerText || editor.textContent || '').replace(/\s+/g, ' ').trim(),
                    };
                }),
        }));
        throw new Error(`Missing overlay field with text containing: ${TARGET_TEXT}\nOverlay state: ${JSON.stringify(overlayState, null, 2)}`);
    }
    const handle = await locator.elementHandle();
    if (!handle) {
        throw new Error('Missing element handle for target field');
    }
    const info = await locator.evaluate((field) => {
        const editor = field.querySelector('[contenteditable]') || field;
        return {
            wordIndex: field.dataset.wordIndex || null,
            visibleText: editor.innerText || editor.textContent || '',
            stableFlowText: field.dataset.stableFlowText || '',
            rect: {
                left: field.offsetLeft,
                top: field.offsetTop,
                width: field.offsetWidth,
                height: field.offsetHeight,
            },
        };
    });
    return { locator, info };
}

async function appendToParagraph(page, locator) {
    await locator.click();
    await page.waitForTimeout(250);
    await locator.dblclick();
    await page.waitForTimeout(500);

    const editorLocator = locator.locator('[contenteditable]').first();
    await editorLocator.waitFor({ state: 'visible', timeout: 10000 });

    const before = await editorLocator.innerText();
    await page.evaluate((appendText) => {
        const editing = document.querySelector('.overlay-field.editing [contenteditable]');
        if (!editing) {
            throw new Error('Missing contenteditable editor while editing target paragraph');
        }
        const normalized = String(editing.innerText || editing.textContent || '').replace(/\s+$/g, '');
        editing.textContent = `${normalized}${appendText}`;
        editing.dispatchEvent(new Event('input', { bubbles: true }));
        editing.focus();
    }, APPEND_TEXT);
    await page.waitForTimeout(500);

    const after = await editorLocator.innerText();
    return { before, after };
}

async function saveEditor(page) {
    await page.click('#save-btn');
    await page.waitForFunction(() => typeof overlayEditedFields !== 'undefined' && overlayEditedFields.size === 0, null, {
        timeout: 90000,
    });
    await page.waitForTimeout(2500);
}

async function downloadPdfViaToolbar(page, outputPath) {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/download-annotated-pdf')
    ), { timeout: 60000 });
    const downloadPromise = page.waitForEvent('download', { timeout: 60000 });

    await page.click('#download-pdf-btn');
    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`download pdf failed with ${response.status()}`);
    }
    const download = await downloadPromise;
    await download.saveAs(outputPath);
    await page.waitForTimeout(1000);
}

function analyzePdfDiff(originalPdfPath, outputPdfPath, originalPngPath, outputPngPath, diffPngPath, reportPath) {
    const code = `
import json
import math
import sys
import fitz
from PIL import Image, ImageChops, ImageStat

orig_pdf, out_pdf, orig_png, out_png, diff_png, report_path, page_index = sys.argv[1:8]
page_index = int(page_index)

def render_page(pdf_path, png_path):
    doc = fitz.open(pdf_path)
    page = doc[page_index]
    pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
    words = page.get_text('words')
    blocks = page.get_text('blocks')
    doc.close()
    img = Image.frombytes('RGB', [pix.width, pix.height], pix.samples)
    img.save(png_path)
    return img, words, blocks

orig_img, orig_words, orig_blocks = render_page(orig_pdf, orig_png)
out_img, out_words, out_blocks = render_page(out_pdf, out_png)

if out_img.size != orig_img.size:
    out_img = out_img.resize(orig_img.size)

diff = ImageChops.difference(orig_img, out_img)
diff.save(diff_png)
stat = ImageStat.Stat(diff)
mean_diff = sum(stat.mean) / len(stat.mean)
bbox = diff.getbbox()

def normalize(text):
    return ' '.join(str(text or '').split()).strip()

def simplify_blocks(blocks):
    out = []
    for block in blocks:
        text = normalize(block[4] if len(block) > 4 else '')
        if not text:
            continue
        out.append({
            'bbox': [float(block[0]), float(block[1]), float(block[2]), float(block[3])],
            'text': text,
        })
    return out

report = {
    'page_index': page_index,
    'original_png_path': orig_png,
    'output_png_path': out_png,
    'diff_png_path': diff_png,
    'mean_diff': mean_diff,
    'diff_bbox': list(bbox) if bbox else None,
    'original_word_count': len(orig_words),
    'output_word_count': len(out_words),
    'original_blocks': simplify_blocks(orig_blocks),
    'output_blocks': simplify_blocks(out_blocks),
}

with open(report_path, 'w', encoding='utf-8') as handle:
    json.dump(report, handle, indent=2)

print(json.dumps({
    'mean_diff': mean_diff,
    'diff_bbox': report['diff_bbox'],
    'original_word_count': len(orig_words),
    'output_word_count': len(out_words),
    'report_path': report_path,
}, indent=2))
`;

    const stdout = execFileSync(PYTHON, ['-c', code, originalPdfPath, outputPdfPath, originalPngPath, outputPngPath, diffPngPath, reportPath, String(PAGE_INDEX)], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(stdout.trim());
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1700, height: 1600 }, acceptDownloads: true });
    const page = await context.newPage();
    const pageErrors = [];
    const consoleErrors = [];

    page.on('pageerror', (error) => {
        pageErrors.push(String(error?.stack || error));
    });
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });

    try {
        const documentId = await uploadPdf(page);
        await activateOverlay(page);
        const target = await findTargetField(page);
        const editState = await appendToParagraph(page, target.locator);
        await saveEditor(page);

        const browserPngPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_browser.png`);
        await page.locator('.page[data-page-index="0"], .page-wrapper[data-page-number="1"]').first().screenshot({ path: browserPngPath });

        const downloadedPdfPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_download.pdf`);
        await downloadPdfViaToolbar(page, downloadedPdfPath);

        const originalPngPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_original.png`);
        const outputPngPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_output.png`);
        const diffPngPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_diff.png`);
        const reportPath = path.join(OUTPUT_DIR, `isator_paragraph1_doc${documentId}_${runToken}_report.json`);

        const analysis = analyzePdfDiff(PDF_PATH, downloadedPdfPath, originalPngPath, outputPngPath, diffPngPath, reportPath);

        console.log(JSON.stringify({
            documentId,
            target,
            editState,
            browserPngPath,
            downloadedPdfPath,
            originalPngPath,
            outputPngPath,
            diffPngPath,
            reportPath,
            analysis,
            pageErrors,
            consoleErrors,
        }, null, 2));
    } catch (error) {
        const failureScreenshotPath = path.join(OUTPUT_DIR, `isator_paragraph1_failure_${runToken}.png`);
        try {
            await page.screenshot({ path: failureScreenshotPath, fullPage: true });
        } catch (_screenshotError) {
            // Ignore screenshot failures and preserve the original error.
        }
        error.message = `${error.message}\npageErrors=${JSON.stringify(pageErrors, null, 2)}\nconsoleErrors=${JSON.stringify(consoleErrors, null, 2)}\nfailureScreenshotPath=${failureScreenshotPath}`;
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
