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
const BASELINE_PATH = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'isrator', 'baseline_isator_page_footer_change.png');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const FOOTER_ORIGINAL = 'page 2';
const FOOTER_UPDATED = 'page 3';
const FOOTER_DIFF_THRESHOLD = 10.0;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
    await page.waitForTimeout(2000);
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

async function editFooter(page) {
    const locator = page.locator('.overlay-field').filter({ hasText: FOOTER_ORIGINAL }).first();
    if (await locator.count() === 0) {
        throw new Error(`Missing footer overlay field with text: ${FOOTER_ORIGINAL}`);
    }
    await locator.dblclick();
    await page.waitForTimeout(400);
    await locator.evaluate((el, nextText) => {
        const editor = el.querySelector('[contenteditable]');
        if (!editor) {
            throw new Error('contenteditable child missing');
        }
        editor.innerText = nextText;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }, FOOTER_UPDATED);
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

function renderAndCompareFooter(savedPdfPath, renderedPngPath) {
    const compareScript = `
import json
import sys
import fitz
from PIL import Image, ImageChops, ImageStat

pdf_path, baseline_path, rendered_png_path, threshold = sys.argv[1:5]
threshold = float(threshold)

doc = fitz.open(pdf_path)
page = doc[0]
pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
doc.close()

render = Image.frombytes("RGB", [pix.width, pix.height], pix.samples)
render.save(rendered_png_path)

baseline = Image.open(baseline_path).convert("RGB")
render_resized = render.resize(baseline.size)

region = (600, 880, 713, 928)
baseline_crop = baseline.crop(region)
render_crop = render_resized.crop(region)
diff = ImageChops.difference(baseline_crop, render_crop)
mean_diff = sum(ImageStat.Stat(diff).mean) / 3.0

print(json.dumps({
    "region": region,
    "mean_diff": mean_diff,
    "threshold": threshold,
    "rendered_png_path": rendered_png_path,
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
        String(FOOTER_DIFF_THRESHOLD),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Footer baseline comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return result.stdout.trim();
}

function inspectFooterText(savedPdfPath) {
    const inspectScript = `
import json
import sys
import fitz

pdf_path = sys.argv[1]
doc = fitz.open(pdf_path)
page = doc[0]
rows = []
for block in page.get_text("rawdict").get("blocks", []):
    if block.get("type") != 0:
        continue
    for line in block.get("lines", []):
        for span in line.get("spans", []):
            text = ''.join(ch.get("c", "") for ch in span.get("chars", []))
            text = text.strip()
            if not text:
                continue
            if "page 2" in text or "page 3" in text:
                rows.append({
                    "text": text,
                    "size": span.get("size"),
                    "font": span.get("font"),
                    "bbox": span.get("bbox"),
                })

has_old = any("page 2" in row["text"] for row in rows)
has_new = any("page 3" in row["text"] for row in rows)
print(json.dumps({"footer_rows": rows}, indent=2))
if has_old or not has_new:
    sys.exit(2)
`;

    const result = spawnSync('python3', ['-c', inspectScript, savedPdfPath], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Footer text inspection failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
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
        await editFooter(page);
        await savePdf(page);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3500);

        const browserScreenshotPath = path.join(OUTPUT_DIR, `isator_footer_doc${documentId}_browser.png`);
        await page.locator('.page[data-page-index="0"]').first().screenshot({ path: browserScreenshotPath });

        const savedPdfPath = path.join(OUTPUT_DIR, `isator_footer_doc${documentId}.pdf`);
        const renderedPngPath = path.join(OUTPUT_DIR, `isator_footer_doc${documentId}_render.png`);
        await downloadSavedPdf(page, documentId, savedPdfPath);

        const compareOutput = renderAndCompareFooter(savedPdfPath, renderedPngPath);
        const inspectOutput = inspectFooterText(savedPdfPath);

        console.log('isator footer surgical regression passed');
        console.log(JSON.stringify({
            documentId,
            footer_original: FOOTER_ORIGINAL,
            footer_updated: FOOTER_UPDATED,
            saved_pdf: savedPdfPath,
            rendered_png: renderedPngPath,
            browser_screenshot: browserScreenshotPath,
            baseline: BASELINE_PATH,
            compare: compareOutput,
            inspect: inspectOutput,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
