#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };

const PAGE_SIZE = 'Letter';
const ORIENTATION = 'portrait';

const HEADER_TEXT = 'this is a header line';
const HEADER_FONT = 'Verdana';
const HEADER_SIZE = 49;

const START_X = 88;
const START_Y = 96;
const CROP_PADDING = 32;
const FONT_SIZE_TOLERANCE = 1.0;
const POSITION_TOLERANCE_PX = 8.0;
const WIDTH_TOLERANCE_PX = 12.0;
const HEIGHT_TOLERANCE_PX = 12.0;
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

async function createBlankDocument(page, options = {}) {
    try {
        return await createBlankDocumentViaServer(page, options);
    } catch (_serverError) {
        try {
            return await createBlankDocumentViaCli(page, options);
        } catch (_cliError) {
            return await createBlankDocumentViaBrowser(page, options);
        }
    }
}

async function createBlankDocumentViaServer(page, options = {}) {
    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;

    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });

    const response = await page.evaluate(async ({ nextPageSize, nextOrientation }) => {
        const request = await fetch(`/pdf-tests/create-blank?page_size=${encodeURIComponent(nextPageSize)}&orientation=${encodeURIComponent(nextOrientation)}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const rawBody = await request.text();
        let body = null;
        try {
            body = JSON.parse(rawBody);
        } catch (_error) {
            body = null;
        }

        return {
            ok: request.ok,
            status: request.status,
            body,
            rawBody,
        };
    }, {
        nextPageSize: pageSize,
        nextOrientation: orientation,
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 500)}`);
    }

    const documentId = Number(response.body.document_id);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    return documentId;
}

async function createBlankDocumentViaCli(page, options = {}) {
    const rootDir = path.resolve(__dirname, '..', '..');
    const storageDir = path.join(rootDir, 'storage', 'app', 'documents');
    const originalsDir = path.join(storageDir, 'originals');
    const uuid = typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `pdf-test-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const relativePath = `documents/${uuid}.pdf`;
    const fullPath = path.join(rootDir, 'storage', 'app', relativePath);

    const sizes = {
        A4: [595.28, 841.89],
        Letter: [612, 792],
        Legal: [612, 1008],
        A3: [841.89, 1190.55],
        A5: [419.53, 595.28],
    };

    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;
    const selectedSize = sizes[pageSize] || sizes.Letter;
    let [width, height] = selectedSize;
    if (orientation === 'landscape') {
        [width, height] = [height, width];
    }

    fs.mkdirSync(storageDir, { recursive: true });
    fs.mkdirSync(originalsDir, { recursive: true });

    execFileSync('python3', [
        '-c',
        'import fitz, sys; doc = fitz.open(); doc.new_page(width=float(sys.argv[2]), height=float(sys.argv[3])); doc.save(sys.argv[1]); doc.close()',
        fullPath,
        String(width),
        String(height),
    ], {
        cwd: rootDir,
        stdio: 'pipe',
    });

    const sizeBytes = fs.statSync(fullPath).size;
    const phpCode = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
$storedPath = $argv[1];
$sizeBytes = (int) $argv[2];
$backupPath = 'documents/originals/' . pathinfo($storedPath, PATHINFO_FILENAME) . '_original.pdf';
if (Illuminate\\Support\\Facades\\Storage::exists($storedPath)) {
    Illuminate\\Support\\Facades\\Storage::copy($storedPath, $backupPath);
} else {
    $backupPath = null;
}
$document = App\\Models\\Document::create([
    'user_id' => null,
    'original_name' => 'Blank ${pageSize} ${orientation.charAt(0).toUpperCase() + orientation.slice(1)}.pdf',
    'path' => $storedPath,
    'original_backup_path' => $backupPath,
    'mime_type' => 'application/pdf',
    'size_bytes' => $sizeBytes,
]);
echo $document->id;
`;

    const documentId = Number(execFileSync('php', ['-r', phpCode, '--', relativePath, String(sizeBytes)], {
        cwd: rootDir,
        stdio: ['ignore', 'pipe', 'pipe'],
        encoding: 'utf8',
    }).trim());

    if (!Number.isFinite(documentId) || documentId <= 0) {
        throw new Error(`failed to create blank document record for ${relativePath}`);
    }

    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    return documentId;
}

async function createBlankDocumentViaBrowser(page, options = {}) {
    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;

    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1000);
    await page.selectOption('#blank-page-size', pageSize);
    await page.selectOption('#blank-orientation', orientation);
    await page.getByRole('button', { name: 'Create Blank PDF', exact: true }).click();
    await page.waitForFunction(() => /\/documents\/\d+\/edit\b/.test(window.location.pathname), null, { timeout: 90000 });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`blank PDF creation failed: ${page.url()}`);
    }
    return Number(match[1]);
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 90000 });
    await page.waitForTimeout(1500);
}

async function clearAnnotationSessionState(page, documentId) {
    await page.evaluate((id) => {
        sessionStorage.removeItem(`pdf-annotations-${id}`);
        localStorage.removeItem('pdf_session_id');
    }, documentId);
}

async function applyEditTextBannerStyles(page, styles) {
    return page.evaluate((inputStyles) => {
        const dispatchValue = (element, value, eventName) => {
            if (!element) {
                throw new Error(`missing edit text banner control for ${eventName}`);
            }
            element.value = value;
            element.dispatchEvent(new Event(eventName, { bubbles: true }));
        };

        if (inputStyles.fontFamily) {
            dispatchValue(document.getElementById('etb-font'), inputStyles.fontFamily, 'change');
        }
        if (inputStyles.fontSize !== undefined) {
            const sizeInput = document.getElementById('etb-size');
            if (!sizeInput) {
                throw new Error('missing edit text banner control for font size');
            }
            sizeInput.value = String(inputStyles.fontSize);
            sizeInput.dispatchEvent(new Event('input', { bubbles: true }));
            sizeInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const input = document.querySelector('.text-box-creator .tbc-input');
        return {
            text: input?.textContent || '',
            html: input?.innerHTML || '',
        };
    }, styles);
}

async function commitActiveTextBox(page) {
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);
}

async function createTextAnnotationAtWithStyles(page, text, offsetX, offsetY, styles = {}) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const overlay = page.locator('.page[data-page-index="0"] .overlay, .page-wrapper[data-page-number="1"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + offsetX, overlayBox.y + offsetY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });

    const input = page.locator('.text-box-creator .tbc-input').first();
    await input.fill(text);
    if (styles && Object.keys(styles).length > 0) {
        await applyEditTextBannerStyles(page, styles);
    }
    await commitActiveTextBox(page);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });
    return annotation;
}

async function clickOutsideFirstPage(page) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    const box = await pageLocator.boundingBox();
    if (!box) {
        throw new Error('missing first page bounding box for outside click');
    }

    const targetX = Math.max(5, box.x - 20);
    const targetY = box.y + Math.min(80, Math.max(20, box.height / 10));
    await page.mouse.click(targetX, targetY);
    await page.waitForTimeout(250);
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function saveAnnotationsOnly(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/save-annotation-state')
    ), { timeout: 60000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation-only save failed with ${response.status()}`);
    }

    await page.waitForTimeout(1500);
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

async function readTextAnnotationVisualState(page, targetText) {
    return page.evaluate((expectedText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const wanted = normalizeText(expectedText);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(wanted));
        });

        if (!record?.element) {
            throw new Error(`missing annotation state target for "${expectedText}"`);
        }

        const pageEl = record.element.closest('.page-wrapper, .page');
        const pageRect = pageEl?.getBoundingClientRect();
        const rect = record.element.getBoundingClientRect();
        const textEl = record.element.querySelector('.annotation-text') || record.element;
        const textRect = textEl.getBoundingClientRect();
        const style = window.getComputedStyle(textEl);

        return {
            text: wanted,
            left: rect.left - (pageRect?.left || 0),
            top: rect.top - (pageRect?.top || 0),
            width: rect.width,
            height: rect.height,
            textLeft: textRect.left - (pageRect?.left || 0),
            textTop: textRect.top - (pageRect?.top || 0),
            textWidth: textRect.width,
            textHeight: textRect.height,
            fontSizePx: parseFloat(style.fontSize || '0') || 0,
            fontFamilyCss: style.fontFamily || '',
            requestedFontSize: Number(record.requestedFontSize) || 0,
            storedFontSize: Number(record.fontSize) || 0,
            currentScale: Number(window.currentScale) || 1,
            pdfX: Number(record.pdfX) || 0,
            pdfY: Number(record.pdfY) || 0,
            pdfWidth: Number(record.pdfWidth) || 0,
            pdfHeight: Number(record.pdfHeight) || 0,
        };
    }, targetText);
}

function compareBrowserScreenshotToSavedPdf({
    browserScreenshotPath,
    savedPdfPath,
    renderedFullPath,
    browserCropPath,
    pdfCropPath,
    diffPath,
    crop,
}) {
    const pythonCode = `
import fitz
import json
import sys
from PIL import Image, ImageChops, ImageStat

browser_png, pdf_path, rendered_png, browser_crop_png, pdf_crop_png, diff_png, crop_json = sys.argv[1:8]
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
render_crop.save(pdf_crop_png)

diff = ImageChops.difference(browser_crop, render_crop)
stat = ImageStat.Stat(diff)
mean_diff = sum(stat.mean) / 3.0
non_zero_pixels = 0
for pixel in diff.getdata():
    if pixel[0] or pixel[1] or pixel[2]:
        non_zero_pixels += 1
total_pixels = max(1, diff.width * diff.height)

best_diff = diff
best_mean_diff = mean_diff
best_non_zero_pixels = non_zero_pixels
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
        candidate_stat = ImageStat.Stat(candidate_diff)
        candidate_mean_diff = sum(candidate_stat.mean) / 3.0
        candidate_non_zero_pixels = 0
        for pixel in candidate_diff.getdata():
            if pixel[0] or pixel[1] or pixel[2]:
                candidate_non_zero_pixels += 1
        if (
            candidate_mean_diff < best_mean_diff
            or (
                abs(candidate_mean_diff - best_mean_diff) < 1e-9
                and candidate_non_zero_pixels < best_non_zero_pixels
            )
        ):
            best_diff = candidate_diff
            best_mean_diff = candidate_mean_diff
            best_non_zero_pixels = candidate_non_zero_pixels
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
    'non_zero_pixels': non_zero_pixels,
    'non_zero_ratio': non_zero_pixels / float(total_pixels),
    'aligned_mean_diff': best_mean_diff,
    'aligned_diff_bbox': best_diff_bbox,
    'aligned_non_zero_pixels': best_non_zero_pixels,
    'aligned_non_zero_ratio': best_non_zero_pixels / float(total_pixels),
    'alignment_shift': list(best_shift),
    'alignment_search_radius': alignment_radius,
}, indent=2))
`;

    const raw = execFileSync('python3', [
        '-c',
        pythonCode,
        browserScreenshotPath,
        savedPdfPath,
        renderedFullPath,
        browserCropPath,
        pdfCropPath,
        diffPath,
        JSON.stringify(crop),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw.trim());
}

function inspectSavedPdfLine(savedPdfPath, targetText) {
    const pythonCode = `
import fitz
import json
import sys

pdf_path = sys.argv[1]
target = sys.argv[2]

def normalize(value):
    return ' '.join(str(value or '').split())

doc = fitz.open(pdf_path)
page = doc[0]
raw = page.get_text('dict')
rects = page.search_for(target, quads=False)
result = {
    '__page': {
        'width': float(page.rect.width),
        'height': float(page.rect.height),
    },
    'target': normalize(target),
    'rects': [
        [float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1)]
        for rect in rects
    ],
    'spans': [],
}

for block in raw.get('blocks', []):
    if int(block.get('type', 0) or 0) != 0:
        continue
    for line in block.get('lines', []):
        spans = line.get('spans', []) or []
        line_text = normalize(''.join(str(span.get('text', '')) for span in spans))
        if line_text != result['target']:
            continue
        result['spans'] = [
            {
                'text': span.get('text', ''),
                'font': span.get('font', ''),
                'size': float(span.get('size', 0) or 0),
                'flags': int(span.get('flags', 0) or 0),
                'bbox': span.get('bbox', []),
            }
            for span in spans
        ]
        break
    if result['spans']:
        break

doc.close()
print(json.dumps(result, indent=2))
`;

    const raw = execFileSync('python3', [
        '-c',
        pythonCode,
        savedPdfPath,
        targetText,
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw.trim());
}

function buildCropBox(state) {
    const left = Math.floor(Number(state.left || state.textLeft || 0) - CROP_PADDING);
    const top = Math.floor(Number(state.top || state.textTop || 0) - CROP_PADDING);
    const right = Math.ceil(Number(state.left || state.textLeft || 0) + Number(state.width || state.textWidth || 0) + CROP_PADDING);
    const bottom = Math.ceil(Number(state.top || state.textTop || 0) + Number(state.height || state.textHeight || 0) + CROP_PADDING);
    return {
        x: Math.max(0, left),
        y: Math.max(0, top),
        width: Math.max(1, right - Math.max(0, left)),
        height: Math.max(1, bottom - Math.max(0, top)),
    };
}

function buildChecks(headerState, pdfInspect, compareSummary) {
    const headerSpans = Array.isArray(pdfInspect?.spans) ? pdfInspect.spans : [];
    const headerPrimary = headerSpans[0] || null;
    const headerRect = Array.isArray(pdfInspect?.rects) ? pdfInspect.rects[0] : null;
    const pageWidthPt = Number(pdfInspect?.__page?.width) || 612;
    const pageHeightPt = Number(pdfInspect?.__page?.height) || 792;
    const browserWidthPx = Number(compareSummary?.browser_size?.[0]) || 0;
    const browserHeightPx = Number(compareSummary?.browser_size?.[1]) || 0;
    const pxPerPtX = pageWidthPt > 0 && browserWidthPx > 0 ? browserWidthPx / pageWidthPt : 0;
    const pxPerPtY = pageHeightPt > 0 && browserHeightPx > 0 ? browserHeightPx / pageHeightPt : 0;
    const pdfLeftPx = headerRect && pxPerPtX > 0 ? Number(headerRect[0]) * pxPerPtX : Number.POSITIVE_INFINITY;
    const pdfTopPx = headerRect && pxPerPtY > 0 ? Number(headerRect[1]) * pxPerPtY : Number.POSITIVE_INFINITY;
    const pdfWidthPx = headerRect && pxPerPtX > 0 ? (Number(headerRect[2]) - Number(headerRect[0])) * pxPerPtX : Number.POSITIVE_INFINITY;
    const pdfHeightPx = headerRect && pxPerPtY > 0 ? (Number(headerRect[3]) - Number(headerRect[1])) * pxPerPtY : Number.POSITIVE_INFINITY;
    const leftErrorPx = Number.isFinite(pdfLeftPx) ? Math.abs(pdfLeftPx - Number(headerState.left || 0)) : Number.POSITIVE_INFINITY;
    const topErrorPx = Number.isFinite(pdfTopPx) ? Math.abs(pdfTopPx - Number(headerState.textTop || 0)) : Number.POSITIVE_INFINITY;
    const widthErrorPx = Number.isFinite(pdfWidthPx) ? Math.abs(pdfWidthPx - Number(headerState.textWidth || 0)) : Number.POSITIVE_INFINITY;
    const heightErrorPx = Number.isFinite(pdfHeightPx) ? Math.abs(pdfHeightPx - Number(headerState.textHeight || 0)) : Number.POSITIVE_INFINITY;
    const visualMeanDiff = Number(compareSummary?.aligned_mean_diff ?? compareSummary?.mean_diff ?? Number.POSITIVE_INFINITY);
    const visualNonZeroRatio = Number(compareSummary?.aligned_non_zero_ratio ?? compareSummary?.non_zero_ratio ?? Number.POSITIVE_INFINITY);

    return [
        {
            item: 'browser_header_requested_font_size_is_49px',
            result: Math.abs((Number(headerState.requestedFontSize) || 0) - HEADER_SIZE) <= 0.5 ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                requested: headerState.requestedFontSize,
                rendered_px: headerState.fontSizePx,
                css_family: headerState.fontFamilyCss,
            }),
        },
        {
            item: 'saved_pdf_contains_header_line',
            result: headerSpans.length > 0 ? 'PASS' : 'FAIL',
            detail: JSON.stringify(pdfInspect || {}),
        },
        {
            item: 'saved_pdf_header_font_size_is_49pt',
            result: headerPrimary && Math.abs(Number(headerPrimary.size) - HEADER_SIZE) <= FONT_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
            detail: JSON.stringify({ expected: HEADER_SIZE, actual: headerPrimary?.size ?? null, font: headerPrimary?.font ?? null, spans: headerSpans }),
        },
        {
            item: 'saved_pdf_header_left_matches_editor_text_box',
            result: leftErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: leftErrorPx,
                expected_left_px: headerState.left,
                actual_left_px: Number.isFinite(pdfLeftPx) ? pdfLeftPx : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_header_top_matches_editor_text_box',
            result: topErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: topErrorPx,
                expected_top_px: headerState.textTop,
                actual_top_px: Number.isFinite(pdfTopPx) ? pdfTopPx : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_header_width_matches_editor_text_width',
            result: widthErrorPx <= WIDTH_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: WIDTH_TOLERANCE_PX,
                error_px: widthErrorPx,
                expected_width_px: headerState.textWidth,
                actual_width_px: Number.isFinite(pdfWidthPx) ? pdfWidthPx : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_header_height_matches_editor_text_height',
            result: heightErrorPx <= HEIGHT_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: HEIGHT_TOLERANCE_PX,
                error_px: heightErrorPx,
                expected_height_px: headerState.textHeight,
                actual_height_px: Number.isFinite(pdfHeightPx) ? pdfHeightPx : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_header_visual_diff_is_small',
            result: visualMeanDiff <= VISUAL_DIFF_THRESHOLD ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                threshold: VISUAL_DIFF_THRESHOLD,
                mean_diff: visualMeanDiff,
                raw_mean_diff: compareSummary?.mean_diff ?? null,
                non_zero_ratio: visualNonZeroRatio,
                raw_non_zero_ratio: compareSummary?.non_zero_ratio ?? null,
                diff_bbox: compareSummary?.aligned_diff_bbox ?? compareSummary?.diff_bbox ?? null,
                raw_diff_bbox: compareSummary?.diff_bbox ?? null,
                alignment_shift: compareSummary?.alignment_shift ?? [0, 0],
            }),
        },
    ];
}

async function main() {
    ensureOutputDir();

    const runToken = buildRunToken();
    const stem = 'blank_verdana_header_line_diff';
    const browserScreenshotPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'browser_page'));
    const browserCropPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'browser_crop'));
    const renderedPdfPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'pdf_render'));
    const pdfCropPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'pdf_crop'));
    const diffPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'diff'));
    const savedPdfPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'downloaded', 'pdf'));
    const reportPath = path.join(OUTPUT_DIR, buildArtifactName(stem, runToken, 'report', 'json'));

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT, acceptDownloads: true });
    const page = await context.newPage();

    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: PAGE_SIZE,
            orientation: ORIENTATION,
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);

        await createTextAnnotationAtWithStyles(page, HEADER_TEXT, START_X, START_Y, {
            fontFamily: HEADER_FONT,
            fontSize: HEADER_SIZE,
        });

        const headerState = await readTextAnnotationVisualState(page, HEADER_TEXT);
        const crop = buildCropBox(headerState);

        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, browserScreenshotPath);
        await saveAnnotationsOnly(page);
        await downloadPdfViaToolbar(page, savedPdfPath);

        const compareSummary = compareBrowserScreenshotToSavedPdf({
            browserScreenshotPath,
            savedPdfPath,
            renderedFullPath: renderedPdfPath,
            browserCropPath,
            pdfCropPath,
            diffPath,
            crop,
        });
        const pdfInspect = inspectSavedPdfLine(savedPdfPath, HEADER_TEXT);
        const checks = buildChecks(headerState, pdfInspect, compareSummary);

        const report = {
            document_id: documentId,
            header: {
                text: HEADER_TEXT,
                font_family: HEADER_FONT,
                font_size_px: HEADER_SIZE,
                state: headerState,
                pdf: pdfInspect,
            },
            crop,
            compare: compareSummary,
            diagnostics: {
                visual_compare_threshold: VISUAL_DIFF_THRESHOLD,
                visual_compare_pass: Number(compareSummary.aligned_mean_diff ?? compareSummary.mean_diff) <= VISUAL_DIFF_THRESHOLD,
            },
            artifacts: {
                browser_page: browserScreenshotPath,
                browser_crop: browserCropPath,
                pdf_render: renderedPdfPath,
                pdf_crop: pdfCropPath,
                diff: diffPath,
                saved_pdf: savedPdfPath,
                report: reportPath,
            },
            checks,
        };

        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

        const failures = checks.filter((check) => check.result !== 'PASS');
        if (failures.length > 0) {
            throw new Error(`blank Verdana header line diff regression failed: ${failures.map((item) => item.item).join(', ')}`);
        }

        console.log('blank Verdana header line diff regression passed');
        console.log(JSON.stringify(report, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});