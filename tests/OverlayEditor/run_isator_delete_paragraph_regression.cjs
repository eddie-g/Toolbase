#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'isrator', 'isator.pdf');
const BASELINE_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'isrator', 'baseline_isator_delete_test.png');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const TARGET_TEXT = 'create PDF/A-conforming documents';
const FULL_TARGET_TEXT = [
    'create PDF/A-conforming documents with uncom-',
    'mon features or combinations of features and',
    'check whether PDF/A-1 validation software cor-',
    'rectly identifies the documents as standard con-',
    'forming',
].join('\n');
const PAGE_INDEX = 0;
const DELETE_DIFF_THRESHOLD = 8.0;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/);
    await page.waitForTimeout(3000);
    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }
    return Number(match[1]);
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
    await page.waitForTimeout(2000);
}

async function selectTargetParagraph(page) {
    const locator = page.locator('.overlay-field').filter({ hasText: TARGET_TEXT }).first();
    if (await locator.count() === 0) {
        throw new Error(`Missing overlay block with text containing: ${TARGET_TEXT}`);
    }

    await locator.click();
    await page.waitForTimeout(400);

    const selectedDelete = page.locator('#selected-delete');
    await selectedDelete.waitFor({ state: 'visible' });
    await page.waitForFunction(() => {
        const btn = document.getElementById('selected-delete');
        return !!btn && !btn.disabled;
    });

    const actualText = await locator.evaluate((el) => {
        const editor = el.querySelector('[contenteditable]');
        return (editor?.innerText || el.innerText || '').trim();
    });

    if (!actualText.toLowerCase().includes(TARGET_TEXT.toLowerCase())) {
        throw new Error(`Selected wrong block. Actual text:\n${actualText}`);
    }

    return { locator, actualText };
}

async function deleteParagraph(page) {
    const { locator, actualText } = await selectTargetParagraph(page);

    await page.locator('#selected-delete').click();
    await page.waitForTimeout(700);

    if (await locator.count() !== 0) {
        const stillVisible = await locator.isVisible().catch(() => false);
        if (stillVisible) {
            throw new Error(`Target paragraph still visible after delete.\nText was:\n${actualText}`);
        }
    }

    return actualText;
}

async function savePdf(page) {
    await page.locator('#save-btn').click();
    await page.waitForTimeout(9000);
}

async function downloadSavedPdf(page, documentId, outputPath) {
    const response = await page.context().request.get(`${BASE_URL}/documents/${documentId}/file?v=${Date.now()}`);
    if (!response.ok()) {
        throw new Error(`Failed to download saved PDF: ${response.status()}`);
    }
    const buffer = await response.body();
    fs.writeFileSync(outputPath, buffer);
}

function renderAndComparePage(savedPdfPath, renderedPngPath) {
    const compareScript = `
import json
import sys
import fitz
from PIL import Image, ImageChops, ImageStat

pdf_path, baseline_path, rendered_png_path, threshold, page_index = sys.argv[1:6]
threshold = float(threshold)
page_index = int(page_index)

doc = fitz.open(pdf_path)
page = doc[page_index]
pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
doc.close()

render = Image.frombytes("RGB", [pix.width, pix.height], pix.samples)
baseline = Image.open(baseline_path).convert("RGB")
render_resized = render.resize(baseline.size)
render_resized.save(rendered_png_path)

diff = ImageChops.difference(baseline, render_resized)
mean_diff = sum(ImageStat.Stat(diff).mean) / 3.0

print(json.dumps({
    "page_index": page_index,
    "mean_diff": mean_diff,
    "threshold": threshold,
    "rendered_png_path": rendered_png_path,
    "baseline_path": baseline_path,
    "baseline_size": baseline.size,
    "render_size": render_resized.size,
}, indent=2))

if mean_diff >= threshold:
    sys.exit(2)
`;

    const result = spawnSync('python3', [
        '-c',
        compareScript,
        savedPdfPath,
        BASELINE_PATH,
        renderedPngPath,
        String(DELETE_DIFF_THRESHOLD),
        String(PAGE_INDEX),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Delete baseline comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return result.stdout.trim();
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1600 } });

    try {
        const documentId = await uploadPdf(page);
        await activateOverlay(page);
        const deletedText = await deleteParagraph(page);
        await savePdf(page);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3500);

        const pageScreenshotPath = path.join(OUTPUT_DIR, `isator_delete_doc${documentId}_browser.png`);
        await page.locator(`.page[data-page-index="${PAGE_INDEX}"]`).first().screenshot({ path: pageScreenshotPath });

        const savedPdfPath = path.join(OUTPUT_DIR, `isator_delete_doc${documentId}.pdf`);
        const renderedPngPath = path.join(OUTPUT_DIR, `isator_delete_doc${documentId}_render.png`);
        await downloadSavedPdf(page, documentId, savedPdfPath);

        const compareOutput = renderAndComparePage(savedPdfPath, renderedPngPath);

        console.log('isator delete paragraph regression passed');
        console.log(JSON.stringify({
            documentId,
            target_text: TARGET_TEXT,
            full_target_text: FULL_TARGET_TEXT,
            deleted_text: deletedText,
            saved_pdf: savedPdfPath,
            rendered_png: renderedPngPath,
            browser_screenshot: pageScreenshotPath,
            baseline: BASELINE_PATH,
            compare: compareOutput,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
