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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'drylab_page_1.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const PYTHON = process.env.PYTHON_BIN || 'python3';

const MOVE_DX_PX = 52;
const MOVE_DY_PX = 26;
const POSITION_TOLERANCE = 2;
const MIN_VISIBLE_TOP_SHIFT = 10;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.evaluate(() => localStorage.removeItem('pdf_session_id'));
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
    await page.waitForTimeout(2500);
}

async function moveLargestPromotedAnnotation(page, dxPx, dyPx) {
    const before = await page.evaluate(() => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const annotation = allAnnotations
            .filter((candidate) => candidate && candidate.promotedFromExtraction && candidate.element)
            .sort((left, right) => (
                (Number(right.fontSize) || 0) - (Number(left.fontSize) || 0)
                || (Number(right.pdfY) || 0) - (Number(left.pdfY) || 0)
            ))[0];
        if (!annotation?.id || !annotation?.element) {
            return null;
        }
        const sourceMeta = typeof getPromotedAnnotationSourceMeta === 'function'
            ? getPromotedAnnotationSourceMeta(annotation)
            : null;
        const rect = annotation.element.getBoundingClientRect();
        return {
            id: annotation.id,
            pdfX: Number(annotation.pdfX) || 0,
            pdfY: Number(annotation.pdfY) || 0,
            pdfWidth: Number(annotation.pdfWidth) || 0,
            pdfHeight: Number(annotation.pdfHeight) || 0,
            sourcePageHeight: Number(sourceMeta?.sourcePageHeight) || 0,
            leftPx: rect.left,
            topPx: rect.top,
        };
    });
    if (!before) {
        throw new Error('Could not resolve promoted title annotation');
    }

    await page.evaluate(({ id, dxPx, dyPx }) => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const annotation = allAnnotations.find((candidate) => candidate && candidate.id === id);
        if (!annotation?.element) {
            throw new Error(`missing promoted annotation ${id}`);
        }
        const wrapper = annotation.element.closest('.page-wrapper, .page');
        const canvas = wrapper?.querySelector?.('canvas');
        const overlay = wrapper?.querySelector?.('.overlay');
        const pageInfo = {
            scale: Number(currentScale) || 1,
            canvasHeight: overlay?.clientHeight || canvas?.height || 0,
        };
        if (!(pageInfo.canvasHeight > 0) || typeof setTextAnnotationPosition !== 'function' || typeof getTextAnnotationTopPx !== 'function') {
            throw new Error('missing page info or text annotation position helpers');
        }

        const currentLeft = Number(annotation.pdfX || 0) * pageInfo.scale;
        const currentTop = getTextAnnotationTopPx(annotation, pageInfo);
        const nextLeft = currentLeft + Number(dxPx || 0);
        const nextTop = currentTop + Number(dyPx || 0);

        setTextAnnotationPosition(annotation, pageInfo, nextLeft, nextTop);
        annotation.element.style.left = `${nextLeft}px`;
        annotation.element.style.top = `${nextTop}px`;
        if (typeof markPromotedAnnotationDirty === 'function') {
            markPromotedAnnotationDirty(annotation);
        }
        if (typeof persistAnnotations === 'function') {
            persistAnnotations();
        }
    }, { id: before.id, dxPx, dyPx });
    await page.waitForTimeout(800);

    const after = await page.evaluate((id) => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const annotation = allAnnotations.find((candidate) => candidate && candidate.id === id);
        if (!annotation) {
            return null;
        }
        const sourceMeta = typeof getPromotedAnnotationSourceMeta === 'function'
            ? getPromotedAnnotationSourceMeta(annotation)
            : null;
        return {
            id: annotation.id,
            pdfX: Number(annotation.pdfX) || 0,
            pdfY: Number(annotation.pdfY) || 0,
            pdfWidth: Number(annotation.pdfWidth) || 0,
            pdfHeight: Number(annotation.pdfHeight) || 0,
            sourcePageHeight: Number(sourceMeta?.sourcePageHeight) || 0,
            promotedDirty: Boolean(annotation.promotedDirty),
        };
    }, before.id);
    if (!after) {
        throw new Error('Moved annotation disappeared from state');
    }

    return { before, after };
}

async function downloadPdfViaToolbar(page, outputPath) {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/download-annotated-pdf')
    ), { timeout: 120000 });
    const downloadPromise = page.waitForEvent('download', { timeout: 120000 });

    await page.locator('#download-pdf-btn').click();

    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`Download PDF request failed: ${response.status()} ${await response.text()}`);
    }

    const download = await downloadPromise;
    await download.saveAs(outputPath);
    await page.waitForTimeout(1200);
}

function analyzeExportedPdf(originalPdfPath, exportedPdfPath, movedAnnotation, reportPath) {
    const script = `
import json
import sys
import fitz

original_pdf_path, pdf_path, report_path, moved_json = sys.argv[1:5]
moved = json.loads(moved_json)

def find_text_block(pdf_path, compact_match):
    doc = fitz.open(pdf_path)
    page = doc[0]
    found = None
    for block_index, block in enumerate(page.get_text('dict', sort=True).get('blocks', [])):
        if block.get('type') != 0:
            continue
        lines = block.get('lines', [])
        text = ''.join(''.join(span.get('text', '') for span in line.get('spans', [])) for line in lines)
        compact = ''.join(str(text or '').split()).lower()
        if compact == compact_match:
            bbox = block.get('bbox') or [0, 0, 0, 0]
            found = {
                'block_index': block_index,
                'text': text,
                'bbox': {
                    'left': float(bbox[0]),
                    'top': float(bbox[1]),
                    'right': float(bbox[2]),
                    'bottom': float(bbox[3]),
                    'width': float(bbox[2] - bbox[0]),
                    'height': float(bbox[3] - bbox[1]),
                },
            }
            break
    doc.close()
    return found

def count_visible_nonwhite_pixels(pdf_path, bbox):
    if not bbox:
        return {'count': 0, 'ratio': 0.0, 'width': 0, 'height': 0}
    doc = fitz.open(pdf_path)
    page = doc[0]
    rect = fitz.Rect(
        float(bbox['left']) - 1.0,
        float(bbox['top']) - 1.0,
        float(bbox['right']) + 1.0,
        float(bbox['bottom']) + 1.0,
    ) & page.rect
    pix = page.get_pixmap(matrix=fitz.Matrix(4, 4), clip=rect, alpha=False)
    samples = pix.samples
    channels = max(1, int(getattr(pix, 'n', 3) or 3))
    count = 0
    for offset in range(0, len(samples), channels):
        if offset + 2 >= len(samples):
            continue
        r = samples[offset]
        g = samples[offset + 1]
        b = samples[offset + 2]
        if not (r > 245 and g > 245 and b > 245):
            count += 1
    total = max(1, pix.width * pix.height)
    doc.close()
    return {'count': count, 'ratio': count / total, 'width': pix.width, 'height': pix.height}

original_title_block = find_text_block(original_pdf_path, 'drylabnews')
title_block = find_text_block(pdf_path, 'drylabnews')
strapline_block = find_text_block(pdf_path, 'forinvestors&friends·may2017')
strapline_pixels = count_visible_nonwhite_pixels(pdf_path, strapline_block['bbox'] if strapline_block else None)

expected_left = float(moved['pdfX'])
expected_top = float(moved['sourcePageHeight']) - (float(moved['pdfY']) + float(moved['pdfHeight']))
left_delta = abs(title_block['bbox']['left'] - expected_left) if title_block else None
top_delta = abs(title_block['bbox']['top'] - expected_top) if title_block else None
top_shift_from_original = (title_block['bbox']['top'] - original_title_block['bbox']['top']) if title_block and original_title_block else None

report = {
    'original_title_block': original_title_block,
    'title_block': title_block,
    'expected_left': expected_left,
    'expected_top': expected_top,
    'left_delta': left_delta,
    'top_delta': top_delta,
    'top_shift_from_original': top_shift_from_original,
    'strapline_block': strapline_block,
    'strapline_pixels': strapline_pixels,
    'strapline_visible': bool(strapline_block) and strapline_pixels['count'] >= 1000 and strapline_pixels['ratio'] >= 0.02,
}

with open(report_path, 'w', encoding='utf-8') as handle:
    json.dump(report, handle, indent=2)

print(json.dumps(report, indent=2))
`;

    const stdout = execFileSync(PYTHON, ['-c', script, originalPdfPath, exportedPdfPath, reportPath, JSON.stringify(movedAnnotation)], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(stdout.trim());
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const downloadedPdfPath = path.join(OUTPUT_DIR, `drylab_page1_move_title_download_${runToken}.pdf`);
    const reportPath = path.join(OUTPUT_DIR, `drylab_page1_move_title_download_${runToken}.json`);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1600, height: 1800 },
        acceptDownloads: true,
    });
    const page = await context.newPage();
    const pageErrors = [];

    page.on('pageerror', (error) => {
        pageErrors.push(String(error?.stack || error));
    });

    let documentId = null;
    try {
        documentId = await uploadPdf(page);
        await activateOverlay(page);
        const moveResult = await moveLargestPromotedAnnotation(page, MOVE_DX_PX, MOVE_DY_PX);
        await downloadPdfViaToolbar(page, downloadedPdfPath);

        const report = analyzeExportedPdf(PDF_PATH, downloadedPdfPath, moveResult.after, reportPath);
        const pass = Boolean(report.title_block)
            && typeof report.left_delta === 'number'
            && report.left_delta <= POSITION_TOLERANCE
            && typeof report.top_shift_from_original === 'number'
            && report.top_shift_from_original >= MIN_VISIBLE_TOP_SHIFT
            && report.strapline_visible === true
            && moveResult.after.promotedDirty === true
            && pageErrors.length === 0;

        console.log(JSON.stringify({
            status: pass ? 'pass' : 'fail',
            documentId,
            fixture: PDF_PATH,
            downloadedPdf: downloadedPdfPath,
            reportPath,
            moveBefore: moveResult.before,
            moveAfter: moveResult.after,
            report,
            tolerance: POSITION_TOLERANCE,
            minVisibleTopShift: MIN_VISIBLE_TOP_SHIFT,
            pageErrors,
        }, null, 2));

        if (!pass) {
            process.exitCode = 1;
        }
    } finally {
        await context.close().catch(() => {});
        await browser.close().catch(() => {});
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
