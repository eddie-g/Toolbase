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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'spiders.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const TARGET_TEXT = 'Shelob: The Great Spider of Cirith sddsf';
const PAGE_INDEX = 0;
const MOVE_DX = 80;
const MOVE_DY = 45;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function lineBBox(line) {
    if (Array.isArray(line?.bbox) && line.bbox.length >= 4) {
        return line.bbox.map((value) => Number(value) || 0);
    }
    const left = Number(line?.left) || 0;
    const top = Number(line?.top) || 0;
    const width = Number(line?.width) || 0;
    const height = Number(line?.height) || 0;
    return [left, top, left + width, top + height];
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

async function fetchExtraction(page, documentId) {
    const extraction = await page.evaluate(async ({ baseUrl, docId }) => {
        const response = await fetch(`${baseUrl}/documents/${docId}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, { baseUrl: BASE_URL, docId: documentId });

    if (!extraction?.success) {
        throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
    }

    return extraction;
}

function findMatchingLines(extraction, targetText) {
    const pageData = extraction.extraction_data?.[PAGE_INDEX] || {};
    return (pageData.lines || []).filter((line) => normalize(line.text) === normalize(targetText));
}

async function downloadSavedPdf(page, documentId, outputPath) {
    const response = await page.context().request.get(`${BASE_URL}/documents/${documentId}/file?v=${Date.now()}`);
    if (!response.ok()) {
        throw new Error(`Failed to download saved PDF: ${response.status()}`);
    }
    const buffer = await response.body();
    fs.writeFileSync(outputPath, buffer);
}

function extractSavedPdfLineBBox(savedPdfPath, targetText) {
    const script = `
import json
import sys
import fitz

pdf_path, target_text = sys.argv[1:3]
doc = fitz.open(pdf_path)
page = doc[0]
matches = []
for block in page.get_text("dict").get("blocks", []):
    if block.get("type") != 0:
        continue
    for line in block.get("lines", []):
        text = "".join(span.get("text", "") for span in line.get("spans", []))
        normalized = " ".join(text.split())
        if normalized == target_text:
            matches.append({
                "text": normalized,
                "bbox": line.get("bbox"),
            })
doc.close()
print(json.dumps(matches))
`;

    const result = spawnSync('python3', ['-c', script, savedPdfPath, normalize(targetText)], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`saved PDF extraction failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    const matches = JSON.parse(result.stdout || '[]');
    if (!Array.isArray(matches) || matches.length !== 1) {
        throw new Error(`expected 1 saved PDF line for '${targetText}', found ${JSON.stringify(matches)}`);
    }

    return lineBBox(matches[0]);
}

async function activateOverlay(page) {
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
    await page.waitForTimeout(1500);
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1400 } });
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
        const beforeExtraction = await fetchExtraction(page, documentId);
        const beforeMatches = findMatchingLines(beforeExtraction, TARGET_TEXT);
        if (beforeMatches.length !== 1) {
            throw new Error(`expected 1 original line for '${TARGET_TEXT}', found ${beforeMatches.length}`);
        }

        const beforeBBox = lineBBox(beforeMatches[0]);

        await activateOverlay(page);

        const locator = page.locator('.overlay-field').filter({ hasText: TARGET_TEXT }).first();
        const box = await locator.boundingBox();
        if (!box) {
            throw new Error(`missing overlay field for '${TARGET_TEXT}'`);
        }

        await page.mouse.move(box.x + (box.width / 2), box.y + (box.height / 2));
        await page.mouse.down();
        await page.mouse.move(box.x + (box.width / 2) + MOVE_DX, box.y + (box.height / 2) + MOVE_DY, { steps: 10 });
        await page.mouse.up();
        await page.waitForTimeout(1200);

        const dirtyState = await page.evaluate(() => ({
            editedCount: overlayEditedFields.size,
            saveLabel: document.getElementById('save-btn')?.innerText || '',
        }));
        if (dirtyState.editedCount < 1) {
            throw new Error(`move did not register in overlayEditedFields: ${JSON.stringify(dirtyState)}`);
        }

        await page.locator('#save-btn').click();
        await page.waitForTimeout(9000);

        if (!savePayload || !Array.isArray(savePayload.edits) || savePayload.edits.length < 1) {
            throw new Error(`unexpected save payload: ${JSON.stringify(savePayload)}`);
        }

        const savedEdit = savePayload.edits.find((edit) => normalize(edit.original_text) === normalize(TARGET_TEXT));
        if (!savedEdit) {
            throw new Error(`missing move edit for '${TARGET_TEXT}' in payload: ${JSON.stringify(savePayload.edits)}`);
        }
        if (normalize(savedEdit.new_text) !== normalize(TARGET_TEXT)) {
            throw new Error(`move save payload mutated text: ${JSON.stringify(savedEdit)}`);
        }
        const payloadBBox = lineBBox(savedEdit);
        const payloadDelta = Math.max(
            Math.abs(beforeBBox[0] - payloadBBox[0]),
            Math.abs(beforeBBox[1] - payloadBBox[1]),
            Math.abs(beforeBBox[2] - payloadBBox[2]),
            Math.abs(beforeBBox[3] - payloadBBox[3]),
        );
        if (!savedEdit.position_changed && !savedEdit.size_changed && payloadDelta < 5) {
            throw new Error(`move save payload did not reflect a material bbox change: ${JSON.stringify(savedEdit)}`);
        }

        const savedPdfPath = path.join(OUTPUT_DIR, `spiders_move_doc${documentId}.pdf`);
        await downloadSavedPdf(page, documentId, savedPdfPath);
        const afterBBox = extractSavedPdfLineBBox(savedPdfPath, TARGET_TEXT);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3500);

        const browserScreenshotPath = path.join(OUTPUT_DIR, `spiders_move_doc${documentId}_browser.png`);
        await page.locator(`.page[data-page-index="${PAGE_INDEX}"]`).first().screenshot({ path: browserScreenshotPath });

        const moveRight = afterBBox[0] - beforeBBox[0];
        const moveDown = afterBBox[1] - beforeBBox[1];
        if (moveRight < 20 || moveDown < 10) {
            throw new Error(`saved extraction did not move materially down-right: before=${JSON.stringify(beforeBBox)} after=${JSON.stringify(afterBBox)} saved_pdf=${savedPdfPath} browser_screenshot=${browserScreenshotPath}`);
        }

        console.log('spiders move regression passed');
        console.log(JSON.stringify({
            documentId,
            target_text: TARGET_TEXT,
            before_bbox: beforeBBox,
            after_bbox: afterBBox,
            payload_bbox: payloadBBox,
            dirty_state: dirtyState,
            saved_pdf: savedPdfPath,
            browser_screenshot: browserScreenshotPath,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
