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

const HEADER_TEXT = 'This is a header line';
const HEADER_FONT = 'Garamond';
const HEADER_SIZE = 39;

const SUBHEADER_TEXT = 'Testing Font Size';
const SUBHEADER_FONT = 'Georgia';
const SUBHEADER_SIZE = 38;

const START_X = 88;
const START_Y = 108;
const VERTICAL_GAP = 24;
const CROP_PADDING = 28;
const VISUAL_DIFF_THRESHOLD = 9.5;
const VISUAL_ALIGNMENT_SEARCH_RADIUS_PX = 12;
const FONT_SIZE_TOLERANCE = 1.0;
const POSITION_TOLERANCE_PX = 8.0;
const WIDTH_TOLERANCE_PX = 8.0;
const HEADER_EXPECTED_PDF_FONT = 'Tinos';
const SUBHEADER_EXPECTED_PDF_FONT = 'Gelasio';
const ADDITIONAL_SCENARIOS = [
    {
        name: 'blank Verdana header line diff',
        script: 'run_blank_verdana_header_line_diff_regression.cjs',
    },
];

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

function coerceCommandOutput(value) {
    if (!value) {
        return '';
    }
    if (Buffer.isBuffer(value)) {
        return value.toString('utf8');
    }
    return String(value);
}

function summarizeCommandOutput(value, maxLength = 4000) {
    const text = coerceCommandOutput(value).trim();
    if (!text) {
        return '';
    }
    if (text.length <= maxLength) {
        return text;
    }
    return text.slice(text.length - maxLength);
}

function runAdditionalScenario(scenario) {
    const rootDir = path.resolve(__dirname, '..', '..');
    const scriptPath = path.resolve(__dirname, scenario.script);
    try {
        const stdout = execFileSync('node', [scriptPath], {
            cwd: rootDir,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        return {
            name: scenario.name,
            script: scenario.script,
            result: 'PASS',
            detail: summarizeCommandOutput(stdout, 1200),
        };
    } catch (error) {
        return {
            name: scenario.name,
            script: scenario.script,
            result: 'FAIL',
            detail: summarizeCommandOutput(`${coerceCommandOutput(error.stdout)}\n${coerceCommandOutput(error.stderr)}`),
        };
    }
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
max_diff = max(best_diff_bbox or (0, 0, 0, 0))

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
    'max_diff_coord': max_diff,
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

function inspectSavedPdfLines(savedPdfPath, targetLines) {
    const pythonCode = `
import fitz
import json
import sys

pdf_path = sys.argv[1]
targets = json.loads(sys.argv[2])

def normalize(value):
    return ' '.join(str(value or '').split())

doc = fitz.open(pdf_path)
page = doc[0]
raw = page.get_text('dict')
output = {
    '__page': {
        'width': float(page.rect.width),
        'height': float(page.rect.height),
    }
}
output.update({target: {'line_text': '', 'spans': [], 'rects': []} for target in targets})

for target in targets:
    rects = page.search_for(target, quads=False)
    output[target]['rects'] = [
        [float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1)]
        for rect in rects
    ]

for block in raw.get('blocks', []):
    if int(block.get('type', 0) or 0) != 0:
        continue
    for line in block.get('lines', []):
        spans = line.get('spans', []) or []
        line_text = normalize(''.join(str(span.get('text', '')) for span in spans))
        if line_text not in output:
            continue
        output[line_text] = {
            'line_text': line_text,
            'rects': output[line_text]['rects'],
            'spans': [
                {
                    'text': span.get('text', ''),
                    'font': span.get('font', ''),
                    'size': float(span.get('size', 0) or 0),
                    'flags': int(span.get('flags', 0) or 0),
                    'bbox': span.get('bbox', []),
                }
                for span in spans
            ],
        }

doc.close()
print(json.dumps(output, indent=2))
`;

    const raw = execFileSync('python3', [
        '-c',
        pythonCode,
        savedPdfPath,
        JSON.stringify(targetLines),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw.trim());
}

function buildCropBox(states) {
    const left = Math.floor(Math.min(...states.map((state) => state.left)) - CROP_PADDING);
    const top = Math.floor(Math.min(...states.map((state) => state.top)) - CROP_PADDING);
    const right = Math.ceil(Math.max(...states.map((state) => state.left + state.width)) + CROP_PADDING);
    const bottom = Math.ceil(Math.max(...states.map((state) => state.top + state.height)) + CROP_PADDING);
    return {
        x: Math.max(0, left),
        y: Math.max(0, top),
        width: Math.max(1, right - Math.max(0, left)),
        height: Math.max(1, bottom - Math.max(0, top)),
    };
}

function buildChecks(headerState, subheaderState, pdfInspect, compareSummary) {
    const headerSpans = Array.isArray(pdfInspect?.[HEADER_TEXT]?.spans) ? pdfInspect[HEADER_TEXT].spans : [];
    const subheaderSpans = Array.isArray(pdfInspect?.[SUBHEADER_TEXT]?.spans) ? pdfInspect[SUBHEADER_TEXT].spans : [];
    const headerPrimary = headerSpans[0] || null;
    const subheaderPrimary = subheaderSpans[0] || null;
    const headerRect = Array.isArray(pdfInspect?.[HEADER_TEXT]?.rects) ? pdfInspect[HEADER_TEXT].rects[0] : null;
    const subheaderRect = Array.isArray(pdfInspect?.[SUBHEADER_TEXT]?.rects) ? pdfInspect[SUBHEADER_TEXT].rects[0] : null;
    const pageWidthPt = Number(pdfInspect?.__page?.width) || 612;
    const pageHeightPt = Number(pdfInspect?.__page?.height) || 792;
    const browserWidthPx = Number(compareSummary?.browser_size?.[0]) || 0;
    const browserHeightPx = Number(compareSummary?.browser_size?.[1]) || 0;
    const pxPerPtX = pageWidthPt > 0 && browserWidthPx > 0 ? browserWidthPx / pageWidthPt : 0;
    const pxPerPtY = pageHeightPt > 0 && browserHeightPx > 0 ? browserHeightPx / pageHeightPt : 0;
    const headerFontMatches = Boolean(headerPrimary?.font) && String(headerPrimary.font).toLowerCase().includes(HEADER_EXPECTED_PDF_FONT.toLowerCase());
    const subheaderFontMatches = Boolean(subheaderPrimary?.font) && String(subheaderPrimary.font).toLowerCase().includes(SUBHEADER_EXPECTED_PDF_FONT.toLowerCase());
    const headerLeftErrorPx = headerRect && pxPerPtX > 0 ? Math.abs((Number(headerRect[0]) * pxPerPtX) - Number(headerState.left || 0)) : Number.POSITIVE_INFINITY;
    const subheaderLeftErrorPx = subheaderRect && pxPerPtX > 0 ? Math.abs((Number(subheaderRect[0]) * pxPerPtX) - Number(subheaderState.left || 0)) : Number.POSITIVE_INFINITY;
    const headerTopErrorPx = headerRect && pxPerPtY > 0 ? Math.abs((Number(headerRect[1]) * pxPerPtY) - Number(headerState.textTop || 0)) : Number.POSITIVE_INFINITY;
    const subheaderTopErrorPx = subheaderRect && pxPerPtY > 0 ? Math.abs((Number(subheaderRect[1]) * pxPerPtY) - Number(subheaderState.textTop || 0)) : Number.POSITIVE_INFINITY;
    const headerWidthErrorPx = headerRect && pxPerPtX > 0 ? Math.abs(((Number(headerRect[2]) - Number(headerRect[0])) * pxPerPtX) - Number(headerState.textWidth || 0)) : Number.POSITIVE_INFINITY;
    const subheaderWidthErrorPx = subheaderRect && pxPerPtX > 0 ? Math.abs(((Number(subheaderRect[2]) - Number(subheaderRect[0])) * pxPerPtX) - Number(subheaderState.textWidth || 0)) : Number.POSITIVE_INFINITY;
    const editorLineGapPx = Number(subheaderState.top || 0) - Number(headerState.top || 0);
    const pdfLineGapPx = headerRect && subheaderRect && pxPerPtY > 0
        ? (Number(subheaderRect[1]) - Number(headerRect[1])) * pxPerPtY
        : Number.POSITIVE_INFINITY;
    const lineGapErrorPx = Number.isFinite(pdfLineGapPx) ? Math.abs(pdfLineGapPx - editorLineGapPx) : Number.POSITIVE_INFINITY;

    return [
        {
            item: 'browser_header_requested_font_size_is_39px',
            result: Math.abs((Number(headerState.requestedFontSize) || 0) - HEADER_SIZE) <= 0.5 ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                requested: headerState.requestedFontSize,
                rendered_px: headerState.fontSizePx,
                css_family: headerState.fontFamilyCss,
            }),
        },
        {
            item: 'browser_subheader_requested_font_size_is_38px',
            result: Math.abs((Number(subheaderState.requestedFontSize) || 0) - SUBHEADER_SIZE) <= 0.5 ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                requested: subheaderState.requestedFontSize,
                rendered_px: subheaderState.fontSizePx,
                css_family: subheaderState.fontFamilyCss,
            }),
        },
        {
            item: 'saved_pdf_contains_header_line',
            result: headerSpans.length > 0 ? 'PASS' : 'FAIL',
            detail: JSON.stringify(pdfInspect?.[HEADER_TEXT] || {}),
        },
        {
            item: 'saved_pdf_contains_subheader_line',
            result: subheaderSpans.length > 0 ? 'PASS' : 'FAIL',
            detail: JSON.stringify(pdfInspect?.[SUBHEADER_TEXT] || {}),
        },
        {
            item: 'saved_pdf_header_font_maps_to_tinos',
            result: headerFontMatches ? 'PASS' : 'FAIL',
            detail: JSON.stringify({ expected_family: HEADER_EXPECTED_PDF_FONT, actual_font: headerPrimary?.font ?? null, spans: headerSpans }),
        },
        {
            item: 'saved_pdf_subheader_font_maps_to_gelasio',
            result: subheaderFontMatches ? 'PASS' : 'FAIL',
            detail: JSON.stringify({ expected_family: SUBHEADER_EXPECTED_PDF_FONT, actual_font: subheaderPrimary?.font ?? null, spans: subheaderSpans }),
        },
        {
            item: 'saved_pdf_header_font_size_is_39pt',
            result: headerPrimary && Math.abs(Number(headerPrimary.size) - HEADER_SIZE) <= FONT_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
            detail: JSON.stringify({ expected: HEADER_SIZE, actual: headerPrimary?.size ?? null, font: headerPrimary?.font ?? null, spans: headerSpans }),
        },
        {
            item: 'saved_pdf_subheader_font_size_is_38pt',
            result: subheaderPrimary && Math.abs(Number(subheaderPrimary.size) - SUBHEADER_SIZE) <= FONT_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
            detail: JSON.stringify({ expected: SUBHEADER_SIZE, actual: subheaderPrimary?.size ?? null, font: subheaderPrimary?.font ?? null, spans: subheaderSpans }),
        },
        {
            item: 'saved_pdf_header_left_matches_editor',
            result: headerLeftErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: headerLeftErrorPx,
                expected_left_px: headerState.left,
                actual_left_px: headerRect && pxPerPtX > 0 ? Number(headerRect[0]) * pxPerPtX : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_subheader_left_matches_editor',
            result: subheaderLeftErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: subheaderLeftErrorPx,
                expected_left_px: subheaderState.left,
                actual_left_px: subheaderRect && pxPerPtX > 0 ? Number(subheaderRect[0]) * pxPerPtX : null,
                rect: subheaderRect,
            }),
        },
        {
            item: 'saved_pdf_header_top_matches_editor_text_box',
            result: headerTopErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: headerTopErrorPx,
                expected_top_px: headerState.textTop,
                actual_top_px: headerRect && pxPerPtY > 0 ? Number(headerRect[1]) * pxPerPtY : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_subheader_top_matches_editor_text_box',
            result: subheaderTopErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: subheaderTopErrorPx,
                expected_top_px: subheaderState.textTop,
                actual_top_px: subheaderRect && pxPerPtY > 0 ? Number(subheaderRect[1]) * pxPerPtY : null,
                rect: subheaderRect,
            }),
        },
        {
            item: 'saved_pdf_header_width_matches_editor_text_width',
            result: headerWidthErrorPx <= WIDTH_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: WIDTH_TOLERANCE_PX,
                error_px: headerWidthErrorPx,
                expected_width_px: headerState.textWidth,
                actual_width_px: headerRect && pxPerPtX > 0 ? (Number(headerRect[2]) - Number(headerRect[0])) * pxPerPtX : null,
                rect: headerRect,
            }),
        },
        {
            item: 'saved_pdf_subheader_width_matches_editor_text_width',
            result: subheaderWidthErrorPx <= WIDTH_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: WIDTH_TOLERANCE_PX,
                error_px: subheaderWidthErrorPx,
                expected_width_px: subheaderState.textWidth,
                actual_width_px: subheaderRect && pxPerPtX > 0 ? (Number(subheaderRect[2]) - Number(subheaderRect[0])) * pxPerPtX : null,
                rect: subheaderRect,
            }),
        },
        {
            item: 'saved_pdf_interline_gap_matches_editor',
            result: lineGapErrorPx <= POSITION_TOLERANCE_PX ? 'PASS' : 'FAIL',
            detail: JSON.stringify({
                tolerance_px: POSITION_TOLERANCE_PX,
                error_px: lineGapErrorPx,
                expected_gap_px: editorLineGapPx,
                actual_gap_px: pdfLineGapPx,
                header_rect: headerRect,
                subheader_rect: subheaderRect,
            }),
        },
    ];
}

async function main() {
    ensureOutputDir();

    const runToken = buildRunToken();
    const stem = 'blank_font_visual_parity';
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
        const subheaderY = Math.round(headerState.top + headerState.height + VERTICAL_GAP);

        await createTextAnnotationAtWithStyles(page, SUBHEADER_TEXT, START_X, subheaderY, {
            fontFamily: SUBHEADER_FONT,
            fontSize: SUBHEADER_SIZE,
        });

        const finalHeaderState = await readTextAnnotationVisualState(page, HEADER_TEXT);
        const subheaderState = await readTextAnnotationVisualState(page, SUBHEADER_TEXT);
        const crop = buildCropBox([finalHeaderState, subheaderState]);

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
        const pdfInspect = inspectSavedPdfLines(savedPdfPath, [HEADER_TEXT, SUBHEADER_TEXT]);
        const checks = buildChecks(finalHeaderState, subheaderState, pdfInspect, compareSummary);

        const report = {
            document_id: documentId,
            header: {
                text: HEADER_TEXT,
                font_family: HEADER_FONT,
                font_size_px: HEADER_SIZE,
                state: finalHeaderState,
                pdf: pdfInspect[HEADER_TEXT],
            },
            subheader: {
                text: SUBHEADER_TEXT,
                font_family: SUBHEADER_FONT,
                font_size_px: SUBHEADER_SIZE,
                state: subheaderState,
                pdf: pdfInspect[SUBHEADER_TEXT],
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

        const additionalScenarios = ADDITIONAL_SCENARIOS.map(runAdditionalScenario);
        report.additional_scenarios = additionalScenarios;

        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

        const failures = checks.filter((check) => check.result !== 'PASS');
        const scenarioFailures = additionalScenarios.filter((scenario) => scenario.result !== 'PASS');
        if (failures.length > 0 || scenarioFailures.length > 0) {
            const failureItems = [
                ...failures.map((item) => item.item),
                ...scenarioFailures.map((item) => `${item.name} (${item.script})`),
            ];
            throw new Error(`blank font visual parity suite failed: ${failureItems.join(', ')}`);
        }

        console.log('blank font visual parity suite passed');
        console.log(JSON.stringify(report, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});