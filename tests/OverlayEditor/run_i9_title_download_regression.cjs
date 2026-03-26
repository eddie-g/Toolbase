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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'i-9.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const PYTHON = process.env.PYTHON_BIN || 'python3';

const ORIGINAL_TITLE_LINES = [
    'Employment Eligibility Verification',
    'Department of Homeland Security',
    'U.S. Citizenship and Immigration Services',
];
const UPDATED_TITLE_LINES = [
    'Employment Eligibility Verification',
    'Department of Homeland DOGCHENEZ',
    'U.S. Citizenship and Immigration Services',
];
const UPDATED_TITLE_TEXT = UPDATED_TITLE_LINES.join('\n');
const TITLE_TEXT_FRAGMENT = 'Employment Eligibility Verification';

const TITLE_FONT_SIZE_TOLERANCE = 0.6;
const TITLE_LINE_HEIGHT_TOLERANCE = 1.2;
const TITLE_LINE_STEP_TOLERANCE = 1.4;
const TITLE_CENTER_TOLERANCE = 3.0;
const GLYPH_ROW_DIFF_THRESHOLD = 1.5;
const GLYPH_ROW_BOX = [170, 312, 585, 352];

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.evaluate(() => {
        localStorage.removeItem('pdf_session_id');
    });
    await page.waitForTimeout(1000);
    await page.locator('#document-input').setInputFiles(PDF_PATH);
    await page.getByRole('button', { name: 'Upload PDF', exact: true }).click();
    await page.waitForURL(/\/documents\/\d+\/edit/, { timeout: 120000 });
    await page.waitForTimeout(3500);

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`Could not determine document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 90000 });
    await page.waitForTimeout(1500);
}

async function activateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('missing mode-overlay-toggle');
        }
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, {
        timeout: 90000,
    });
    await page.waitForFunction(() => document.querySelectorAll('.annotation.promoted-extraction.has-bounds').length > 0, null, {
        timeout: 90000,
    });
    await page.waitForTimeout(2500);
}

async function enableEditTextMode(page) {
    await page.evaluate(() => {
        const overlayToggle = document.getElementById('mode-overlay-toggle');
        if (overlayToggle?.checked) {
            overlayToggle.checked = false;
            overlayToggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === false, null, {
        timeout: 30000,
    });

    await page.click('#mode-edit-text');
    await page.waitForTimeout(800);
}

async function openPromotedAnnotationEditor(page, textFragment) {
    const locator = page.locator('.annotation.promoted-extraction.has-bounds').filter({ hasText: textFragment }).first();
    await locator.waitFor({ timeout: 15000 });
    await locator.scrollIntoViewIfNeeded();
    await locator.evaluate((element) => {
        element.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true, view: window }));
    });
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 15000 });
    await page.waitForTimeout(500);
}

async function setActivePromotedEditorText(page, nextText) {
    await page.evaluate((text) => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        if (!input) {
            throw new Error('missing active promoted editor input');
        }

        input.focus();
        if (typeof setRichEditableContent === 'function') {
            setRichEditableContent(input, text);
        } else {
            input.textContent = text;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }, nextText);
    await page.waitForTimeout(500);
}

async function commitActivePromotedEditor(page) {
    const okButton = page.locator('.text-box-creator .tbc-ok').first();
    await okButton.waitFor({ timeout: 10000 });
    await okButton.click();
    await page.waitForSelector('.text-box-creator', { state: 'detached', timeout: 15000 });
    await page.waitForTimeout(1000);
}

async function inspectEditedTitleAnnotation(page) {
    return page.evaluate((textFragment) => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const target = allAnnotations.find((annotation) => (
            annotation
            && annotation.promotedFromExtraction
            && String(annotation.text || '').includes(textFragment)
        ));
        if (!target) {
            return null;
        }
        return {
            id: target.id,
            text: target.text,
            fontFamily: target.fontFamily,
            fontSize: target.fontSize,
            requestedFontSize: target.requestedFontSize,
            fontWeight: target.fontWeight,
            fontStyle: target.fontStyle,
            lineHeight: target.lineHeight,
            textAlign: target.textAlign,
            promotedDirty: Boolean(target.promotedDirty),
            promotedReflowEnabled: Boolean(target.promotedReflowEnabled),
            richTextHtml: typeof target.richTextHtml === 'string' ? target.richTextHtml : '',
            sourceTextLines: Array.isArray(target.sourceTextLines) ? target.sourceTextLines : null,
            sourceLineBBoxes: Array.isArray(target.sourceLineBBoxes) ? target.sourceLineBBoxes : null,
        };
    }, TITLE_TEXT_FRAGMENT);
}

async function waitForAnnotationSave(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && (
            response.url().includes('/apply-annotations-direct')
            || response.url().includes('/save-annotations')
            || response.url().includes('/save-annotation-state')
        )
    ), { timeout: 90000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation save failed with ${response.status()}`);
    }

    await page.waitForTimeout(3500);
}

async function downloadPdfViaToolbar(page, outputPath) {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/download-annotated-pdf')
    ), { timeout: 120000 });
    const downloadPromise = page.waitForEvent('download', { timeout: 120000 });

    await page.click('#download-pdf-btn');
    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`download pdf failed with ${response.status()}`);
    }

    const download = await downloadPromise;
    await download.saveAs(outputPath);
    await page.waitForTimeout(1500);
}

function analyzeExportedPdf(originalPdfPath, exportedPdfPath, reportPath, glyphCropPath) {
    const script = `
import json
import math
import sys
import fitz
from PIL import Image, ImageChops, ImageStat

original_pdf_path, exported_pdf_path, report_path, glyph_crop_path, config_json = sys.argv[1:6]
config = json.loads(config_json)

def normalize(text):
    return ' '.join(str(text or '').split()).strip()

def line_metrics(line):
    bbox = line.get('bbox') or [0, 0, 0, 0]
    spans = line.get('spans') or []
    font_sizes = [
        float(span.get('size') or 0)
        for span in spans
        if float(span.get('size') or 0) > 0
    ]
    text = ''.join(span.get('text', '') for span in spans)
    return {
        'text': text,
        'normalized': normalize(text),
        'bbox': [float(value) for value in bbox],
        'left': float(bbox[0]),
        'top': float(bbox[1]),
        'right': float(bbox[2]),
        'bottom': float(bbox[3]),
        'width': float(bbox[2] - bbox[0]),
        'height': float(bbox[3] - bbox[1]),
        'center_x': float((bbox[0] + bbox[2]) / 2.0),
        'font_size': max(font_sizes) if font_sizes else None,
    }

def extract_lines(pdf_path):
    doc = fitz.open(pdf_path)
    page = doc[0]
    lines = []
    for block in page.get_text('dict', sort=True).get('blocks', []):
        if block.get('type') != 0:
            continue
        for line in block.get('lines', []):
            record = line_metrics(line)
            if record['normalized']:
                lines.append(record)
    doc.close()
    return lines

def find_line(lines, expected_text):
    target = normalize(expected_text)
    for line in lines:
        if line['normalized'] == target:
            return line
    return None

def compare_float(actual, expected, tolerance):
    return actual is not None and expected is not None and abs(float(actual) - float(expected)) <= float(tolerance)

def render_crop(pdf_path, crop_box, png_path):
    doc = fitz.open(pdf_path)
    page = doc[0]
    matrix = fitz.Matrix(2, 2)
    pix = page.get_pixmap(matrix=matrix, alpha=False)
    image = Image.frombytes('RGB', [pix.width, pix.height], pix.samples)
    x0, y0, x1, y1 = crop_box
    scale = 2
    crop = image.crop((int(round(x0 * scale)), int(round(y0 * scale)), int(round(x1 * scale)), int(round(y1 * scale))))
    crop.save(png_path)
    doc.close()
    return crop

original_lines = extract_lines(original_pdf_path)
exported_lines = extract_lines(exported_pdf_path)

original_title_lines = []
exported_title_lines = []
missing_lines = []

for expected in config['original_title_lines']:
    line = find_line(original_lines, expected)
    if not line:
        missing_lines.append({'pdf': 'original', 'text': expected})
    else:
        original_title_lines.append(line)

for expected in config['updated_title_lines']:
    line = find_line(exported_lines, expected)
    if not line:
        missing_lines.append({'pdf': 'exported', 'text': expected})
    else:
        exported_title_lines.append(line)

title_checks = []
title_pass = len(missing_lines) == 0 and len(original_title_lines) == len(exported_title_lines) == 3
if title_pass:
    for index, (original_line, exported_line) in enumerate(zip(original_title_lines, exported_title_lines)):
        title_checks.append({
            'line_index': index,
            'original_text': original_line['normalized'],
            'exported_text': exported_line['normalized'],
            'font_size_ok': compare_float(exported_line['font_size'], original_line['font_size'], config['font_size_tolerance']),
            'height_ok': compare_float(exported_line['height'], original_line['height'], config['line_height_tolerance']),
            'center_ok': compare_float(exported_line['center_x'], original_line['center_x'], config['center_tolerance']),
            'original_font_size': original_line['font_size'],
            'exported_font_size': exported_line['font_size'],
            'original_height': original_line['height'],
            'exported_height': exported_line['height'],
            'original_center_x': original_line['center_x'],
            'exported_center_x': exported_line['center_x'],
            'original_top': original_line['top'],
            'exported_top': exported_line['top'],
        })

    original_steps = [
        original_title_lines[1]['top'] - original_title_lines[0]['top'],
        original_title_lines[2]['top'] - original_title_lines[1]['top'],
    ]
    exported_steps = [
        exported_title_lines[1]['top'] - exported_title_lines[0]['top'],
        exported_title_lines[2]['top'] - exported_title_lines[1]['top'],
    ]
    step_checks = [
        compare_float(exported_steps[0], original_steps[0], config['line_step_tolerance']),
        compare_float(exported_steps[1], original_steps[1], config['line_step_tolerance']),
    ]
    title_pass = (
        all(check['font_size_ok'] and check['height_ok'] and check['center_ok'] for check in title_checks)
        and all(step_checks)
    )
else:
    original_steps = []
    exported_steps = []
    step_checks = []

original_crop = render_crop(original_pdf_path, config['glyph_crop_box'], glyph_crop_path.replace('.png', '_original.png'))
exported_crop = render_crop(exported_pdf_path, config['glyph_crop_box'], glyph_crop_path)
diff = ImageChops.difference(original_crop, exported_crop)
glyph_diff_mean = sum(ImageStat.Stat(diff).mean) / 3.0
glyph_pass = glyph_diff_mean <= float(config['glyph_diff_threshold'])

report = {
    'title_pass': title_pass,
    'glyph_pass': glyph_pass,
    'missing_lines': missing_lines,
    'title_checks': title_checks,
    'original_steps': original_steps,
    'exported_steps': exported_steps,
    'step_checks': step_checks,
    'glyph_diff_mean': glyph_diff_mean,
    'glyph_diff_threshold': config['glyph_diff_threshold'],
    'glyph_crop_box': config['glyph_crop_box'],
}

with open(report_path, 'w', encoding='utf-8') as handle:
    json.dump(report, handle, indent=2)

print(json.dumps(report, indent=2))
sys.exit(0 if title_pass and glyph_pass else 1)
`;

    const config = JSON.stringify({
        original_title_lines: ORIGINAL_TITLE_LINES,
        updated_title_lines: UPDATED_TITLE_LINES,
        font_size_tolerance: TITLE_FONT_SIZE_TOLERANCE,
        line_height_tolerance: TITLE_LINE_HEIGHT_TOLERANCE,
        line_step_tolerance: TITLE_LINE_STEP_TOLERANCE,
        center_tolerance: TITLE_CENTER_TOLERANCE,
        glyph_crop_box: GLYPH_ROW_BOX,
        glyph_diff_threshold: GLYPH_ROW_DIFF_THRESHOLD,
    });

    execFileSync(PYTHON, ['-c', script, originalPdfPath, exportedPdfPath, reportPath, glyphCropPath, config], {
        cwd: path.resolve(__dirname, '..', '..'),
        stdio: 'inherit',
    });
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const exportedPdfPath = path.join(OUTPUT_DIR, `i9_title_download_${runToken}.pdf`);
    const reportPath = path.join(OUTPUT_DIR, `i9_title_download_${runToken}.json`);
    const glyphCropPath = path.join(OUTPUT_DIR, `i9_title_download_${runToken}_glyph_crop.png`);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1800 } });

    try {
        console.log('[i9] opening app');
        await page.goto(BASE_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
        console.log('[i9] uploading fixture');
        const documentId = await uploadPdf(page);
        console.log(`[i9] document ${documentId}`);
        await waitForEditorReady(page);
        console.log('[i9] editor ready');
        await activateOverlay(page);
        console.log('[i9] overlay active');
        await enableEditTextMode(page);
        console.log('[i9] edit text active');

        await openPromotedAnnotationEditor(page, TITLE_TEXT_FRAGMENT);
        console.log('[i9] title editor open');
        await setActivePromotedEditorText(page, UPDATED_TITLE_TEXT);
        await commitActivePromotedEditor(page);
        console.log('[i9] title edit committed');
        console.log('[i9] title annotation', JSON.stringify(await inspectEditedTitleAnnotation(page), null, 2));
        await waitForAnnotationSave(page);
        console.log('[i9] save complete');
        await downloadPdfViaToolbar(page, exportedPdfPath);
        console.log('[i9] download complete');

        analyzeExportedPdf(PDF_PATH, exportedPdfPath, reportPath, glyphCropPath);
        console.log('[i9] analysis complete');

        console.log(JSON.stringify({
            success: true,
            document_id: documentId,
            exported_pdf: exportedPdfPath,
            report_path: reportPath,
            glyph_crop_path: glyphCropPath,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
