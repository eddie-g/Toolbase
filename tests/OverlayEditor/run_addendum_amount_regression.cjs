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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'addendum', 'Test_PDF_Save_addendum_1.pdf');
const BASELINE_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'addendum', 'baseline_addendum.png');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const PAGE_INDEX = 0;
const DIFF_THRESHOLD = 8.0;
const ORIGINAL_AMOUNT = '0.00';
const UPDATED_AMOUNT = '440.00';
const ITEM_BLOCK_ORIGINAL = 'Stainless Steel Side by Side Refrigerator White LG washer and dryer';
const ITEM_BLOCK_UPDATED = [
    'Stainless Steel Side by Side Refrigerator',
    'White LG washer and dryer',
].join('\n');

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
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

async function findOverlayField(page, expectedText) {
    const key = await page.evaluate((target) => {
        const normalizeInner = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const wanted = normalizeInner(target);
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((el) => {
            const title = normalizeInner(el.title || '');
            const text = normalizeInner(el.innerText || '');
            return title === wanted || text === wanted;
        });
        return match ? (match.dataset.wordIndex || '') : '';
    }, expectedText);

    if (!key) {
        throw new Error(`Missing overlay field matching: ${expectedText}`);
    }

    return page.locator(`.overlay-field[data-word-index="${key}"]`).first();
}

async function editFieldByTitle(page, title, nextText) {
    const locator = await findOverlayField(page, title);

    await locator.dblclick();
    await page.waitForTimeout(400);
    await locator.evaluate((el, updatedText) => {
        const editor = el.querySelector('[contenteditable]');
        if (!editor) {
            throw new Error(`Missing contenteditable editor for ${el.title}`);
        }
        editor.innerText = updatedText;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }, nextText);
    await page.waitForTimeout(700);
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
        String(DIFF_THRESHOLD),
        String(PAGE_INDEX),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Addendum baseline comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return result.stdout.trim();
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1600 } });
    let savePayload = null;

    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/save-edits')) {
            try {
                savePayload = request.postDataJSON();
            } catch (error) {
                savePayload = request.postData();
            }
        }
    });

    try {
        const documentId = await uploadPdf(page);
        await activateOverlay(page);

        await editFieldByTitle(page, ORIGINAL_AMOUNT, UPDATED_AMOUNT);
        await editFieldByTitle(page, ITEM_BLOCK_ORIGINAL, ITEM_BLOCK_UPDATED);

        await savePdf(page);

        if (!savePayload || !Array.isArray(savePayload.edits) || savePayload.edits.length < 2) {
            throw new Error(`Expected at least 2 overlay edits in save payload, got: ${JSON.stringify(savePayload)}`);
        }

        const amountEdit = savePayload.edits.find((edit) => normalize(edit.original_text) === normalize(ORIGINAL_AMOUNT));
        if (!amountEdit || normalize(amountEdit.new_text) !== normalize(UPDATED_AMOUNT)) {
            throw new Error(`Amount edit missing from payload: ${JSON.stringify(savePayload.edits)}`);
        }

        const itemEdit = savePayload.edits.find((edit) => normalize(edit.original_text) === normalize(ITEM_BLOCK_ORIGINAL));
        if (!itemEdit || String(itemEdit.new_text || '').trim() !== ITEM_BLOCK_UPDATED) {
            throw new Error(`Item block edit missing from payload: ${JSON.stringify(savePayload.edits)}`);
        }

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3500);

        const browserScreenshotPath = path.join(OUTPUT_DIR, `addendum_doc${documentId}_browser.png`);
        await page.locator(`.page[data-page-index="${PAGE_INDEX}"]`).first().screenshot({ path: browserScreenshotPath });

        const savedPdfPath = path.join(OUTPUT_DIR, `addendum_doc${documentId}.pdf`);
        const renderedPngPath = path.join(OUTPUT_DIR, `addendum_doc${documentId}_render.png`);
        await downloadSavedPdf(page, documentId, savedPdfPath);

        const compareOutput = renderAndComparePage(savedPdfPath, renderedPngPath);

        console.log('addendum amount regression passed');
        console.log(JSON.stringify({
            documentId,
            updated_amount: UPDATED_AMOUNT,
            updated_items: ITEM_BLOCK_UPDATED,
            saved_pdf: savedPdfPath,
            rendered_png: renderedPngPath,
            browser_screenshot: browserScreenshotPath,
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
