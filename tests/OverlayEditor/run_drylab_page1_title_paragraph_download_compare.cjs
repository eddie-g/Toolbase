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

const ORIGINAL_TITLE_TEXT = 'Drylab News';
const UPDATED_TITLE_TEXT = 'Drylab Update';
const ORIGINAL_PARAGRAPH_LINES = [
    'the 2.05 MNOK loan from Innovation',
    'Norway. Including the development',
    'agreement with Filmlance International, the',
    'total new capital is 5 MNOK, partly tied to',
    'the successful completion of milestones. All',
    'formalities associated with this process are',
    'now finalized.',
];
const UPDATED_PARAGRAPH_LINES = [
    'the 2.05 MNOK grant from Innovation',
    'Norway. Including the development',
    'agreement with Filmlance International, the',
    'total new capital is 5 MNOK, partly tied to',
    'the successful completion of milestones. All',
    'formalities associated with this process are',
    'now finalized.',
];
const UPDATED_PARAGRAPH_TEXT = UPDATED_PARAGRAPH_LINES.join('\n');

const TITLE_LEFT_TOLERANCE = 4;
const TITLE_TOP_TOLERANCE = 6;
const TITLE_FONT_SIZE_TOLERANCE = 0.5;
const PARAGRAPH_LEFT_TOLERANCE = 4;
const PARAGRAPH_TOP_TOLERANCE = 4;
const PARAGRAPH_LINE_HEIGHT_TOLERANCE = 2;
const PARAGRAPH_LINE_STEP_TOLERANCE = 2;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.evaluate(() => {
        localStorage.removeItem('pdf_session_id');
    });
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
    await page.waitForFunction(() => document.querySelectorAll('.annotation.promoted-extraction.has-bounds').length > 0, null, {
        timeout: 90000,
    });
    await page.waitForTimeout(2500);
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

async function openLargestPromotedAnnotationEditor(page) {
    const annotationId = await page.evaluate(() => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const promoted = allAnnotations
            .filter((annotation) => annotation && annotation.promotedFromExtraction && annotation.element)
            .sort((left, right) => (
                (Number(right.fontSize) || 0) - (Number(left.fontSize) || 0)
                || (Number(right.pdfY) || 0) - (Number(left.pdfY) || 0)
            ));
        return promoted[0]?.id || null;
    });
    if (!annotationId) {
        throw new Error('Could not resolve largest promoted annotation');
    }

    await page.evaluate((id) => {
        const allAnnotations = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : (window.annotations || []);
        const annotation = allAnnotations.find((candidate) => candidate && candidate.id === id);
        if (!annotation?.element) {
            throw new Error(`missing promoted annotation element for ${id}`);
        }
        annotation.element.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true, view: window }));
    }, annotationId);
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
        if (input.dataset.annotationSplitParagraphFully === '1') {
            const deactivateEvent = typeof InputEvent === 'function'
                ? new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertText', data: 'x' })
                : new Event('beforeinput', { bubbles: true, cancelable: true });
            input.dispatchEvent(deactivateEvent);
        }

        if (typeof setRichEditableContent === 'function') {
            setRichEditableContent(input, text);
        } else {
            input.textContent = text;
        }

        input.dispatchEvent(new Event('input', { bubbles: true }));
    }, nextText);
    await page.waitForTimeout(400);
}

async function commitActivePromotedEditor(page) {
    const okButton = page.locator('.text-box-creator .tbc-ok').first();
    await okButton.waitFor({ timeout: 10000 });
    await okButton.click();
    await page.waitForSelector('.text-box-creator', { state: 'detached', timeout: 15000 });
    await page.waitForTimeout(800);
}

async function editPromotedAnnotation(page, originalTextFragment, replacementText) {
    await openPromotedAnnotationEditor(page, originalTextFragment);
    await setActivePromotedEditorText(page, replacementText);
    await commitActivePromotedEditor(page);
}

async function editLargestPromotedAnnotation(page, replacementText) {
    await openLargestPromotedAnnotationEditor(page);
    await setActivePromotedEditorText(page, replacementText);
    await commitActivePromotedEditor(page);
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

function analyzeExportedPdf(originalPdfPath, exportedPdfPath, reportPath) {
    const script = `
import json
import sys
import fitz

original_pdf_path, exported_pdf_path, report_path, config_json = sys.argv[1:5]
config = json.loads(config_json)

def normalize(text):
    return ' '.join(str(text or '').split()).strip()

def normalize_no_space(text):
    return ''.join(str(text or '').split()).strip().lower()

def bbox_metrics(bbox):
    return {
        'left': float(bbox[0]),
        'top': float(bbox[1]),
        'right': float(bbox[2]),
        'bottom': float(bbox[3]),
        'width': float(bbox[2] - bbox[0]),
        'height': float(bbox[3] - bbox[1]),
    }

def extract_blocks(pdf_path):
    doc = fitz.open(pdf_path)
    page = doc[0]
    records = []
    for block_index, block in enumerate(page.get_text('dict', sort=True).get('blocks', [])):
        if block.get('type') != 0:
            continue
        block_bbox = block.get('bbox') or [0, 0, 0, 0]
        line_records = []
        for line_index, line in enumerate(block.get('lines', [])):
            line_bbox = line.get('bbox') or [0, 0, 0, 0]
            text = ''.join(span.get('text', '') for span in line.get('spans', []))
            font_sizes = [
                float(span.get('size') or 0)
                for span in line.get('spans', [])
                if float(span.get('size') or 0) > 0
            ]
            line_records.append({
                'line_index': line_index,
                'text': text,
                'normalized': normalize(text),
                'normalized_no_space': normalize_no_space(text),
                'bbox': bbox_metrics(line_bbox),
                'max_font_size': max(font_sizes) if font_sizes else None,
            })
        block_text = ' '.join(line['normalized'] for line in line_records if line['normalized'])
        if not block_text:
            continue
        block_font_sizes = [line.get('max_font_size') for line in line_records if line.get('max_font_size') is not None]
        records.append({
            'block_index': block_index,
            'text': block_text,
            'normalized': normalize(block_text),
            'normalized_no_space': normalize_no_space(block_text),
            'bbox': bbox_metrics(block_bbox),
            'max_font_size': max(block_font_sizes) if block_font_sizes else None,
            'lines': line_records,
        })
    doc.close()
    return records

def find_title_block(blocks, expected_text):
    expected_key = normalize_no_space(expected_text)
    for block in blocks:
        if expected_key and expected_key in block['normalized_no_space']:
            return block
    return None

def find_paragraph_block(blocks, expected_lines):
    if not expected_lines:
        return None
    expected_first_line = normalize(expected_lines[0])
    for block in blocks:
        block_lines = block.get('lines', [])
        if not block_lines:
            continue
        first_line = normalize(block_lines[0].get('text', ''))
        if first_line == expected_first_line:
            return block
    return None

def deltas(values_a, values_b):
    return [abs(float(a) - float(b)) for a, b in zip(values_a, values_b)]

def consecutive_steps(lines):
    if len(lines) < 2:
        return []
    return [
        float(lines[index + 1]['bbox']['top']) - float(lines[index]['bbox']['top'])
        for index in range(len(lines) - 1)
    ]

original_blocks = extract_blocks(original_pdf_path)
exported_blocks = extract_blocks(exported_pdf_path)

original_title = find_title_block(original_blocks, config['original_title_text'])
exported_title = find_title_block(exported_blocks, config['updated_title_text'])

original_paragraph = find_paragraph_block(original_blocks, config['original_paragraph_lines'])
exported_paragraph = find_paragraph_block(exported_blocks, config['updated_paragraph_lines'])
original_paragraph_lines = original_paragraph.get('lines', []) if original_paragraph else []
exported_paragraph_lines = exported_paragraph.get('lines', []) if exported_paragraph else []
original_paragraph_complete = len(original_paragraph_lines) == len(config['original_paragraph_lines'])
exported_paragraph_complete = len(exported_paragraph_lines) == len(config['updated_paragraph_lines'])

title_left_delta = abs(exported_title['bbox']['left'] - original_title['bbox']['left']) if original_title and exported_title else None
title_top_delta = abs(exported_title['bbox']['top'] - original_title['bbox']['top']) if original_title and exported_title else None
title_font_size_delta = abs(float(exported_title.get('max_font_size') or 0) - float(original_title.get('max_font_size') or 0)) if original_title and exported_title else None
title_line_count_delta = abs(len(exported_title.get('lines', [])) - len(original_title.get('lines', []))) if original_title and exported_title else None

paragraph_left_deltas = deltas(
    [line['bbox']['left'] for line in original_paragraph_lines],
    [line['bbox']['left'] for line in exported_paragraph_lines],
) if len(original_paragraph_lines) == len(exported_paragraph_lines) and original_paragraph_lines else []
paragraph_top_deltas = deltas(
    [line['bbox']['top'] for line in original_paragraph_lines],
    [line['bbox']['top'] for line in exported_paragraph_lines],
) if len(original_paragraph_lines) == len(exported_paragraph_lines) and original_paragraph_lines else []
paragraph_height_deltas = deltas(
    [line['bbox']['height'] for line in original_paragraph_lines],
    [line['bbox']['height'] for line in exported_paragraph_lines],
) if len(original_paragraph_lines) == len(exported_paragraph_lines) and original_paragraph_lines else []
original_steps = consecutive_steps(original_paragraph_lines) if original_paragraph_complete else []
exported_steps = consecutive_steps(exported_paragraph_lines) if exported_paragraph_complete else []
paragraph_step_deltas = deltas(original_steps, exported_steps) if len(original_steps) == len(exported_steps) else []

report = {
    'original_title': original_title,
    'exported_title': exported_title,
    'original_paragraph': original_paragraph,
    'exported_paragraph': exported_paragraph,
    'original_paragraph_lines': original_paragraph_lines,
    'exported_paragraph_lines': exported_paragraph_lines,
    'original_paragraph_complete': original_paragraph_complete,
    'exported_paragraph_complete': exported_paragraph_complete,
    'metrics': {
        'title_left_delta': title_left_delta,
        'title_top_delta': title_top_delta,
        'title_font_size_delta': title_font_size_delta,
        'title_line_count_delta': title_line_count_delta,
        'paragraph_left_deltas': paragraph_left_deltas,
        'paragraph_top_deltas': paragraph_top_deltas,
        'paragraph_height_deltas': paragraph_height_deltas,
        'original_steps': original_steps,
        'exported_steps': exported_steps,
        'paragraph_step_deltas': paragraph_step_deltas,
    },
}

with open(report_path, 'w', encoding='utf-8') as handle:
    json.dump(report, handle, indent=2)

print(json.dumps(report, indent=2))
`;

    const config = {
        original_title_text: ORIGINAL_TITLE_TEXT,
        updated_title_text: UPDATED_TITLE_TEXT,
        original_paragraph_lines: ORIGINAL_PARAGRAPH_LINES,
        updated_paragraph_lines: UPDATED_PARAGRAPH_LINES,
    };

    const stdout = execFileSync(PYTHON, ['-c', script, originalPdfPath, exportedPdfPath, reportPath, JSON.stringify(config)], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(stdout.trim());
}

function buildChecks(report) {
    const metrics = report.metrics || {};
    const paragraphLeftDeltas = Array.isArray(metrics.paragraph_left_deltas) ? metrics.paragraph_left_deltas : [];
    const paragraphTopDeltas = Array.isArray(metrics.paragraph_top_deltas) ? metrics.paragraph_top_deltas : [];
    const paragraphHeightDeltas = Array.isArray(metrics.paragraph_height_deltas) ? metrics.paragraph_height_deltas : [];
    const paragraphStepDeltas = Array.isArray(metrics.paragraph_step_deltas) ? metrics.paragraph_step_deltas : [];

    return [
        {
            item: 'updated_title_found_in_export',
            pass: Boolean(report.exported_title),
            detail: report.exported_title,
        },
        {
            item: 'updated_paragraph_lines_found_in_export',
            pass: Boolean(report.exported_paragraph_complete),
            detail: {
                expected_line_count: UPDATED_PARAGRAPH_LINES.length,
                exported_line_count: Array.isArray(report.exported_paragraph_lines) ? report.exported_paragraph_lines.length : 0,
            },
        },
        {
            item: 'title_left_alignment_matches_original',
            pass: typeof metrics.title_left_delta === 'number' && metrics.title_left_delta <= TITLE_LEFT_TOLERANCE,
            detail: {
                left_delta: metrics.title_left_delta,
                tolerance: TITLE_LEFT_TOLERANCE,
            },
        },
        {
            item: 'title_top_alignment_matches_original',
            pass: typeof metrics.title_top_delta === 'number' && metrics.title_top_delta <= TITLE_TOP_TOLERANCE,
            detail: {
                top_delta: metrics.title_top_delta,
                tolerance: TITLE_TOP_TOLERANCE,
            },
        },
        {
            item: 'title_font_size_matches_original',
            pass: typeof metrics.title_font_size_delta === 'number' && metrics.title_font_size_delta <= TITLE_FONT_SIZE_TOLERANCE,
            detail: {
                font_size_delta: metrics.title_font_size_delta,
                tolerance: TITLE_FONT_SIZE_TOLERANCE,
            },
        },
        {
            item: 'title_line_count_matches_original',
            pass: typeof metrics.title_line_count_delta === 'number' && metrics.title_line_count_delta === 0,
            detail: {
                line_count_delta: metrics.title_line_count_delta,
            },
        },
        {
            item: 'paragraph_left_alignment_matches_original',
            pass: paragraphLeftDeltas.length === UPDATED_PARAGRAPH_LINES.length
                && Math.max(...paragraphLeftDeltas) <= PARAGRAPH_LEFT_TOLERANCE,
            detail: {
                deltas: paragraphLeftDeltas,
                tolerance: PARAGRAPH_LEFT_TOLERANCE,
            },
        },
        {
            item: 'paragraph_top_alignment_matches_original',
            pass: paragraphTopDeltas.length === UPDATED_PARAGRAPH_LINES.length
                && Math.max(...paragraphTopDeltas) <= PARAGRAPH_TOP_TOLERANCE,
            detail: {
                deltas: paragraphTopDeltas,
                tolerance: PARAGRAPH_TOP_TOLERANCE,
            },
        },
        {
            item: 'paragraph_line_heights_match_original',
            pass: paragraphHeightDeltas.length === UPDATED_PARAGRAPH_LINES.length
                && Math.max(...paragraphHeightDeltas) <= PARAGRAPH_LINE_HEIGHT_TOLERANCE,
            detail: {
                deltas: paragraphHeightDeltas,
                tolerance: PARAGRAPH_LINE_HEIGHT_TOLERANCE,
            },
        },
        {
            item: 'paragraph_line_steps_match_original',
            pass: paragraphStepDeltas.length === Math.max(0, UPDATED_PARAGRAPH_LINES.length - 1)
                && Math.max(...paragraphStepDeltas, 0) <= PARAGRAPH_LINE_STEP_TOLERANCE,
            detail: {
                deltas: paragraphStepDeltas,
                tolerance: PARAGRAPH_LINE_STEP_TOLERANCE,
            },
        },
    ];
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const downloadedPdfPath = path.join(OUTPUT_DIR, `drylab_page1_title_paragraph_download_${runToken}.pdf`);
    const reportPath = path.join(OUTPUT_DIR, `drylab_page1_title_paragraph_download_${runToken}.json`);

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

        await editLargestPromotedAnnotation(page, UPDATED_TITLE_TEXT);
        await editPromotedAnnotation(page, 'the 2.05 MNOK loan from Innovation', UPDATED_PARAGRAPH_TEXT);

        await downloadPdfViaToolbar(page, downloadedPdfPath);

        const report = analyzeExportedPdf(PDF_PATH, downloadedPdfPath, reportPath);
        const checks = buildChecks(report);
        const failedChecks = checks.filter((check) => !check.pass);
        const result = {
            status: failedChecks.length === 0 && pageErrors.length === 0 ? 'pass' : 'fail',
            documentId,
            fixture: PDF_PATH,
            downloadedPdf: downloadedPdfPath,
            reportPath,
            checks,
            pageErrors,
        };

        console.log(JSON.stringify(result, null, 2));

        if (result.status !== 'pass') {
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
