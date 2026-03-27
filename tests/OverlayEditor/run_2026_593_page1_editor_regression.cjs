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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', '2026-593.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1680, height: 1680 };

const TARGET_TEXTS = [
    'TAXABLE YEAR',
    '2026',
    'Real Estate Withholding Statement',
    'CALIFORNIA FORM',
    '593',
    'AMENDED: •',
    'Part I Remitter Information',
    'Escrow or Exchange No.',
];

const BAD_COMPOSITES = [
    'TAXABLE YEAR 2026. Real Estate Withholding Statement. CALIFORNIA FORM',
    '. Real Estate Withholding Statement. CALIFORNIA FORM 593',
    'Part I Remitter Information    Part I Remitter Information',
    'Part I Remitter Information • REEP',
    'REEPQualified IntermediaryBuyer/TransfereeOther',
    'AMENDED: • •',
    'Escrow or Exchange No. _________________________',
    'Initial Last name',
    'Initial Last name/Grantor',
];

const CROP_PADDING = 34;
const VISUAL_DIFF_THRESHOLD = 9.5;
const VISUAL_ALIGNMENT_SEARCH_RADIUS_PX = 12;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function buildArtifactName(stem, runToken, suffix, ext = 'png') {
    return `${stem}_${runToken}_${suffix}.${ext}`;
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

async function activateOverlay(page) {
    await page.waitForSelector('#mode-overlay-toggle', { state: 'attached', timeout: 90000 });
    await page.evaluate(async () => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('Overlay toggle not found');
        }
        if (typeof overlayToggleHandler === 'function') {
            toggle.checked = true;
            await overlayToggleHandler(true);
            return;
        }
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('input', { bubbles: true }));
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(
        () => document.querySelector('.viewer')?.classList.contains('overlay-editor-active') === true,
        null,
        { timeout: 90000 }
    );
    await page.waitForFunction(
        () => document.querySelectorAll('.overlay-field').length > 0,
        null,
        { timeout: 90000 }
    );
    await page.waitForTimeout(2000);
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function fetchExtraction(page, documentId) {
    return page.evaluate(async ({ baseUrl, targetDocumentId }) => {
        const response = await fetch(`${baseUrl}/documents/${targetDocumentId}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, { baseUrl: BASE_URL, targetDocumentId: documentId });
}

async function collectPageOneState(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const pageRect = pageRoot?.getBoundingClientRect() || null;
        const pageNumber = '1';

        const fields = Array.from(document.querySelectorAll('.overlay-field'))
            .filter((field) => String(field.dataset.pageNumber || '') === pageNumber)
            .map((field) => {
                const rect = field.getBoundingClientRect();
                const textEl = field.querySelector('[contenteditable]') || field;
                const text = normalizeText(textEl.innerText || textEl.textContent || '');
                const title = normalizeText(field.title || '');
                return {
                    text,
                    title,
                    key: field.dataset.wordIndex || '',
                    blockNum: field.dataset.blockNum || '',
                    className: field.className || '',
                    rect: {
                        left: pageRect ? rect.left - pageRect.left : rect.left,
                        top: pageRect ? rect.top - pageRect.top : rect.top,
                        width: rect.width,
                        height: rect.height,
                    },
                };
            });

        const previews = Array.from(document.querySelectorAll('.acroform-preview-field'))
            .filter((field) => {
                const rect = field.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 && rect.bottom > 0;
            })
            .map((field) => {
                const rect = field.getBoundingClientRect();
                const style = window.getComputedStyle(field);
                return {
                    fieldType: String(field.dataset.fieldType || ''),
                    fieldName: String(field.dataset.fieldName || ''),
                    className: field.className || '',
                    backgroundColor: style.backgroundColor || '',
                    color: style.color || '',
                    borderColor: style.borderColor || '',
                    rect: {
                        left: pageRect ? rect.left - pageRect.left : rect.left,
                        top: pageRect ? rect.top - pageRect.top : rect.top,
                        width: rect.width,
                        height: rect.height,
                    },
                    text: normalizeText(field.innerText || field.textContent || ''),
                };
            });

        return {
            pageRect: pageRect
                ? {
                    width: pageRect.width,
                    height: pageRect.height,
                }
                : null,
            scale: Number(window.currentScale) || 1,
            fields,
            previews,
        };
    });
}

function getSourceBlockText(block) {
    return normalize(Array.isArray(block?.text_lines) ? block.text_lines.join(' ') : block?.text);
}

function findSourceBlocks(pageData) {
    const blocks = Array.isArray(pageData?.blocks) ? pageData.blocks : [];
    const matches = new Map();
    for (const targetText of TARGET_TEXTS) {
        const match = blocks.find((block) => {
            const text = getSourceBlockText(block);
            return text === targetText || text.includes(targetText) || targetText.includes(text);
        });
        if (match) {
            matches.set(targetText, {
                text: getSourceBlockText(match),
                left: Number(match.left) || 0,
                top: Number(match.top) || 0,
                width: Number(match.width) || 0,
                height: Number(match.height) || 0,
                block_num: match.block_num,
            });
        }
    }
    return matches;
}

function buildCropBox(sourceBlocks, scale) {
    const rects = Array.from(sourceBlocks.values()).filter((block) => block && Number.isFinite(Number(block.left)));
    if (!rects.length) {
        throw new Error('Unable to build crop box: no source blocks found');
    }

    const left = Math.min(...rects.map((block) => block.left));
    const top = Math.min(...rects.map((block) => block.top));
    const right = Math.max(...rects.map((block) => block.left + block.width));
    const bottom = Math.max(...rects.map((block) => block.top + block.height));

    return {
        x: Math.max(0, Math.floor((left * scale) - CROP_PADDING)),
        y: Math.max(0, Math.floor((top * scale) - CROP_PADDING)),
        width: Math.max(1, Math.ceil(((right - left) * scale) + (CROP_PADDING * 2))),
        height: Math.max(1, Math.ceil(((bottom - top) * scale) + (CROP_PADDING * 2))),
    };
}

function compareBrowserScreenshotToSource({
    browserScreenshotPath,
    sourcePdfPath,
    renderedFullPath,
    browserCropPath,
    sourceCropPath,
    diffPath,
    crop,
}) {
    const pythonCode = `
import fitz
import json
import sys
from PIL import Image, ImageChops, ImageStat

browser_png, pdf_path, rendered_png, browser_crop_png, source_crop_png, diff_png, crop_json = sys.argv[1:8]
crop = json.loads(crop_json)

browser = Image.open(browser_png).convert('RGB')
doc = fitz.open(pdf_path)
page = doc[0]
sx = browser.width / float(page.rect.width)
sy = browser.height / float(page.rect.height)
pix = page.get_pixmap(matrix=fitz.Matrix(sx, sy), alpha=False)
doc.close()

render = Image.frombytes('RGB', [pix.width, pix.height], pix.samples)
if render.size != browser.size:
    render = render.resize(browser.size, Image.Resampling.LANCZOS)
render.save(rendered_png)

x0 = max(0, min(browser.width, int(crop.get('x', 0))))
y0 = max(0, min(browser.height, int(crop.get('y', 0))))
x1 = max(x0 + 1, min(browser.width, int(crop.get('x', 0) + crop.get('width', browser.width))))
y1 = max(y0 + 1, min(browser.height, int(crop.get('y', 0) + crop.get('height', browser.height))))
region = (x0, y0, x1, y1)

browser_crop = browser.crop(region)
render_crop = render.crop(region)
browser_crop.save(browser_crop_png)
render_crop.save(source_crop_png)

diff = ImageChops.difference(browser_crop, render_crop)
mean_diff = sum(ImageStat.Stat(diff).mean) / 3.0
best_diff = diff
best_mean_diff = mean_diff
best_shift = (0, 0)
best_diff_bbox = diff.getbbox()
alignment_radius = ${VISUAL_ALIGNMENT_SEARCH_RADIUS_PX}

for dx in range(-alignment_radius, alignment_radius + 1):
    for dy in range(-alignment_radius, alignment_radius + 1):
        if dx == 0 and dy == 0:
            continue
        shifted_render = Image.new('RGB', render_crop.size, (255, 255, 255))
        shifted_render.paste(render_crop, (dx, dy))
        candidate_diff = ImageChops.difference(browser_crop, shifted_render)
        candidate_mean_diff = sum(ImageStat.Stat(candidate_diff).mean) / 3.0
        if candidate_mean_diff < best_mean_diff:
            best_diff = candidate_diff
            best_mean_diff = candidate_mean_diff
            best_shift = (dx, dy)
            best_diff_bbox = candidate_diff.getbbox()

best_diff.save(diff_png)

print(json.dumps({
    'region': region,
    'browser_size': browser.size,
    'render_size': render.size,
    'crop_size': browser_crop.size,
    'mean_diff': mean_diff,
    'diff_bbox': diff.getbbox(),
    'aligned_mean_diff': best_mean_diff,
    'aligned_diff_bbox': best_diff_bbox,
    'alignment_shift': list(best_shift),
    'alignment_search_radius': alignment_radius,
}, indent=2))
`;

    const { execFileSync } = require('child_process');
    const raw = execFileSync('python3', [
        '-c',
        pythonCode,
        browserScreenshotPath,
        sourcePdfPath,
        renderedFullPath,
        browserCropPath,
        sourceCropPath,
        diffPath,
        JSON.stringify(crop),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw.trim());
}

function assertCoreInvariants({ sourceBlocks, editorState, compareSummary }) {
    const fieldTexts = editorState.fields.map((field) => field.text);
    const previewTypes = editorState.previews.map((preview) => preview.fieldType);

    for (const targetText of TARGET_TEXTS) {
        const matched = fieldTexts.some((text) => normalize(text).includes(targetText));
        if (!matched && targetText !== 'Part II Seller/Transferor Information') {
            throw new Error(`Missing page-1 overlay field text: ${targetText}`);
        }
    }

    for (const badText of BAD_COMPOSITES) {
        if (fieldTexts.some((text) => normalize(text).includes(badText))) {
            throw new Error(`Corrupted composite text still rendered in editor: ${badText}`);
        }
    }

    const txPreviews = editorState.previews.filter((preview) => preview.fieldType === 'TX');
    const btnPreviews = editorState.previews.filter((preview) => preview.fieldType === 'BTN');
    if (txPreviews.length === 0) {
        throw new Error('Expected at least one page-1 TX AcroForm preview');
    }
    if (btnPreviews.length === 0) {
        throw new Error('Expected at least one page-1 BTN AcroForm preview');
    }

    const blueFillVisible = txPreviews.some((preview) => {
        const bg = String(preview.backgroundColor || '').toLowerCase();
        return bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent' && !bg.includes('255, 255, 255');
    });
    if (!blueFillVisible) {
        throw new Error(`No visible blue AcroForm fill detected in page-1 TX previews: ${JSON.stringify(txPreviews, null, 2)}`);
    }

    const requiredPreviewTypes = ['TX', 'BTN'];
    for (const type of requiredPreviewTypes) {
        if (!previewTypes.includes(type)) {
            throw new Error(`Expected page-1 preview type ${type} not found`);
        }
    }

    if (!sourceBlocks.size) {
        throw new Error('Source block extraction is empty for page 1');
    }

    if (!Number.isFinite(Number(compareSummary?.aligned_mean_diff))) {
        throw new Error(`Missing image comparison summary: ${JSON.stringify(compareSummary)}`);
    }

    if (Number(compareSummary.aligned_mean_diff) >= VISUAL_DIFF_THRESHOLD) {
        throw new Error(`Page-1 editor crop is visually too far from source PDF: ${JSON.stringify(compareSummary, null, 2)}`);
    }
}

async function main() {
    ensureOutputDir();

    const runToken = buildRunToken();
    const stem = '2026_593_page1_editor';
    const browserScreenshotPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'browser_page'));
    const browserCropPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'browser_crop'));
    const sourceRenderPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'source_render'));
    const sourceCropPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'source_crop'));
    const diffPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'diff'));
    const reportPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'report', 'json'));

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT, acceptDownloads: true });
    const page = await context.newPage();

    let documentId = null;

    try {
        documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await activateOverlay(page);

        const extraction = await fetchExtraction(page, documentId);
        if (!extraction?.success) {
            throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
        }
        const page1 = extraction.extraction_data?.find((entry) => Number(entry?.page_number) === 1);
        if (!page1) {
            throw new Error('page 1 extraction payload missing');
        }

        const sourceBlocks = findSourceBlocks(page1);
        if (sourceBlocks.size < 7) {
            throw new Error(`Expected page 1 extraction to expose the header/Part I anchors, got: ${JSON.stringify(Array.from(sourceBlocks.keys()))}`);
        }

        const editorState = await collectPageOneState(page);
        if (!editorState.pageRect) {
            throw new Error('Missing page 1 editor geometry');
        }

        await capturePageScreenshot(page, browserScreenshotPath);

        const crop = buildCropBox(sourceBlocks, editorState.scale);
        const compareSummary = compareBrowserScreenshotToSource({
            browserScreenshotPath,
            sourcePdfPath: PDF_PATH,
            renderedFullPath: sourceRenderPath,
            browserCropPath,
            sourceCropPath,
            diffPath,
            crop,
        });

        assertCoreInvariants({ sourceBlocks, editorState, compareSummary });

        const report = {
            document_id: documentId,
            page_index: 0,
            source_blocks: Array.from(sourceBlocks.entries()).map(([text, block]) => ({ text, ...block })),
            editor_state: editorState,
            crop,
            compare: compareSummary,
            artifacts: {
                browser_page: path.basename(browserScreenshotPath),
                browser_crop: path.basename(browserCropPath),
                source_render: path.basename(sourceRenderPath),
                source_crop: path.basename(sourceCropPath),
                diff: path.basename(diffPath),
            },
        };
        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

        console.log('2026-593 page 1 editor regression passed');
        console.log(JSON.stringify(report, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
