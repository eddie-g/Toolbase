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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'drylab_full.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const PAGE_INDEX = 0;
const ORIGINAL_PAGE_ONE_LINES = [
    'the 2.05 MNOK loan from Innovation',
    'Norway. Including the development',
    'agreement with Filmlance International, the',
    'total new capital is 5 MNOK, partly tied to',
    'the successful completion of milestones. All',
    'formalities associated with this process are',
    'now finalized.',
];
const UPDATED_PAGE_ONE_LINES = [
    'the 2.05 MNOK grant from Innovation',
    'Norway. Including the development',
    'agreement with Filmlance International, the',
    'total new capital is 5 MNOK, partly tied to',
    'the successful completion of milestones. All',
    'formalities associated with this process are',
    'now finalized.',
];
const ORIGINAL_PAGE_ONE_TEXT = ORIGINAL_PAGE_ONE_LINES.join(' ');
const UPDATED_PAGE_ONE_TEXT = UPDATED_PAGE_ONE_LINES.join(' ');
const PARAGRAPH_LEFT_TOLERANCE = 3.25;
const PARAGRAPH_TOP_TOLERANCE = 2.5;
const PARAGRAPH_LINE_HEIGHT_TOLERANCE = 2.0;
const PARAGRAPH_LINE_STEP_TOLERANCE = 2.0;
const UNEDITED_PAGE_AVG_DIFF_TOLERANCE = 0.03;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function postJson(page, url) {
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

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
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
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 90000 });
    await page.waitForFunction(() => {
        const overlayFieldCount = document.querySelectorAll('.overlay-field').length;
        const promotedAnnotationCount = document.querySelectorAll('.annotation.promoted-extraction').length;
        return overlayFieldCount > 0 || promotedAnnotationCount > 0;
    }, null, { timeout: 90000 });
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

async function forceRefreshOverlay(page, documentId) {
    const response = await postJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 30000 });
    await page.waitForTimeout(1500);
}

async function captureCurrentPdfBaseline(page, outputPath) {
    const currentPdfUrl = await page.evaluate(() => {
        if (typeof pdfUrl === 'undefined' || !pdfUrl) {
            throw new Error('pdfUrl is not available in the editor context');
        }
        return String(pdfUrl);
    });
    const response = await page.context().request.get(currentPdfUrl);
    if (!response.ok()) {
        throw new Error(`Failed to fetch current PDF baseline: ${response.status()} ${response.statusText()}`);
    }
    fs.writeFileSync(outputPath, Buffer.from(await response.body()));
}

async function findPageOneTextTarget(page, textFragment) {
    const target = await page.evaluate((targetText) => {
        const normalizeInner = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const wanted = normalizeInner(targetText);
        const wantedPrefix = wanted.slice(0, 120);
        document.querySelectorAll('[data-test-edit-target="drylab"]').forEach((el) => {
            el.removeAttribute('data-test-edit-target');
        });

        const candidates = [
            ...Array.from(document.querySelectorAll('.overlay-field')).map((el) => ({
                kind: 'overlay-field',
                element: el,
                selector: '.overlay-field[data-test-edit-target="drylab"]',
                primaryText: normalizeInner(el.innerText || el.textContent || ''),
                secondaryText: normalizeInner(
                    el.dataset.originalText
                    || el.title
                    || el?._overlaySourceBlock?.text
                    || ''
                ),
            })),
            ...Array.from(document.querySelectorAll('.annotation.promoted-extraction')).map((el) => {
                const textEl = el.querySelector('.annotation-text') || el;
                return {
                    kind: 'annotation',
                    element: el,
                    selector: '.annotation.promoted-extraction[data-test-edit-target="drylab"]',
                    primaryText: normalizeInner(textEl.innerText || textEl.textContent || ''),
                    secondaryText: '',
                };
            }),
        ];

        const match = candidates.find((entry) => (
            entry.primaryText === wanted
            || entry.secondaryText === wanted
            || entry.primaryText.includes(wanted)
            || entry.secondaryText.includes(wanted)
            || entry.primaryText.includes(wantedPrefix)
            || entry.secondaryText.includes(wantedPrefix)
        ));

        if (!match) {
            return null;
        }

        match.element.setAttribute('data-test-edit-target', 'drylab');
        return {
            kind: match.kind,
            selector: match.selector,
        };
    }, textFragment);

    if (!target?.selector) {
        throw new Error(`Missing text target matching page-1 paragraph: ${textFragment}`);
    }

    const locator = page.locator(target.selector).first();
    await locator.waitFor({ timeout: 15000 });
    await locator.scrollIntoViewIfNeeded();
    return {
        kind: target.kind,
        locator,
    };
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

async function editPageOneParagraph(page, nextText) {
    let target = null;
    try {
        target = await findPageOneTextTarget(page, ORIGINAL_PAGE_ONE_TEXT);
    } catch (_error) {
        target = await findPageOneTextTarget(page, ORIGINAL_PAGE_ONE_LINES[0]);
    }
    if (target.kind === 'overlay-field') {
        await target.locator.click();
        await page.waitForTimeout(250);
        await target.locator.dblclick();
        await page.waitForTimeout(400);

        const editorLocator = target.locator.locator('[contenteditable]').first();
        await editorLocator.waitFor({ state: 'visible', timeout: 10000 });
        await page.evaluate((text) => {
            const editing = document.querySelector('.overlay-field.editing [contenteditable]');
            if (!editing) {
                throw new Error('Missing inline overlay editor while editing target paragraph');
            }
            editing.textContent = text;
            editing.dispatchEvent(new Event('input', { bubbles: true }));
            editing.focus();
        }, nextText);
        await page.waitForTimeout(500);
        await page.mouse.click(20, 20);
        await page.waitForTimeout(1200);
        return;
    }

    await target.locator.dispatchEvent('dblclick');
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 15000 });
    await page.waitForTimeout(500);
    await setActivePromotedEditorText(page, nextText);
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
    await page.waitForTimeout(1500);
}

async function collectPendingPromotedEdits(page) {
    return page.evaluate(() => {
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        return records
            .filter((annotation) => annotation && annotation.type === 'text' && annotation.promotedFromExtraction)
            .map((annotation) => ({
                page_number: Number(annotation?.pageIndex) + 1,
                original_text: String(annotation?.originalText || ''),
                new_text: String(annotation?.text || annotation?.element?.textContent || ''),
                promoted_dirty: Boolean(annotation?.promotedDirty),
            }))
            .filter((entry) => Number.isFinite(entry.page_number) && entry.new_text && entry.promoted_dirty);
    });
}

function compareUneditedPagesStrictly(originalPdfPath, savedPdfPath, outputDir, documentId) {
    const compareScript = `
import hashlib
import json
import os
import sys
import fitz
from PIL import Image

original_pdf_path, saved_pdf_path, output_dir, document_id = sys.argv[1:5]
matrix = fitz.Matrix(2, 2)

original_doc = fitz.open(original_pdf_path)
saved_doc = fitz.open(saved_pdf_path)

if original_doc.page_count != saved_doc.page_count:
    print(json.dumps({
        "ok": False,
        "error": "page_count_mismatch",
        "original_page_count": original_doc.page_count,
        "saved_page_count": saved_doc.page_count,
    }, indent=2))
    sys.exit(2)

mismatches = []
checked = []

for page_index in range(1, original_doc.page_count):
    original_page = original_doc[page_index]
    saved_page = saved_doc[page_index]
    original_pix = original_page.get_pixmap(matrix=matrix, alpha=False)
    saved_pix = saved_page.get_pixmap(matrix=matrix, alpha=False)

    if (original_pix.width, original_pix.height) != (saved_pix.width, saved_pix.height):
        mismatches.append({
            "page_number": page_index + 1,
            "reason": "dimension_mismatch",
            "original_size": [original_pix.width, original_pix.height],
            "saved_size": [saved_pix.width, saved_pix.height],
        })
        continue

    original_hash = hashlib.sha256(original_pix.samples).hexdigest()
    saved_hash = hashlib.sha256(saved_pix.samples).hexdigest()
    channel_total = sum(abs(a - b) for a, b in zip(original_pix.samples, saved_pix.samples))
    avg_channel_abs_diff = channel_total / max(1, len(original_pix.samples))
    checked.append({
        "page_number": page_index + 1,
        "original_hash": original_hash,
        "saved_hash": saved_hash,
        "avg_channel_abs_diff": avg_channel_abs_diff,
    })

    if avg_channel_abs_diff > ${UNEDITED_PAGE_AVG_DIFF_TOLERANCE}:
        original_png = os.path.join(output_dir, f"drylab_doc{document_id}_page{page_index + 1}_original.png")
        saved_png = os.path.join(output_dir, f"drylab_doc{document_id}_page{page_index + 1}_saved.png")
        Image.frombytes("RGB", [original_pix.width, original_pix.height], original_pix.samples).save(original_png)
        Image.frombytes("RGB", [saved_pix.width, saved_pix.height], saved_pix.samples).save(saved_png)
        mismatches.append({
            "page_number": page_index + 1,
            "reason": "visual_diff_above_tolerance",
            "original_hash": original_hash,
            "saved_hash": saved_hash,
            "avg_channel_abs_diff": avg_channel_abs_diff,
            "original_png": original_png,
            "saved_png": saved_png,
        })

original_doc.close()
saved_doc.close()

result = {
    "ok": len(mismatches) == 0,
    "checked_pages": checked,
    "mismatches": mismatches,
}
print(json.dumps(result, indent=2))
if mismatches:
    sys.exit(2)
`;

    const result = spawnSync('python3', [
        '-c',
        compareScript,
        originalPdfPath,
        savedPdfPath,
        outputDir,
        String(documentId),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Drylab non-edited page comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return JSON.parse(result.stdout);
}

function analyzeEditedPageAlignment(originalPdfPath, savedPdfPath, outputDir, documentId) {
    const reportPath = path.join(outputDir, `drylab_doc${documentId}_page1_alignment.json`);
    const compareScript = `
import json
import sys
import fitz
from PIL import Image

original_pdf_path, saved_pdf_path, report_path, config_json = sys.argv[1:5]
config = json.loads(config_json)

def normalize(text):
    return ' '.join(str(text or '').split()).strip()

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
                'bbox': bbox_metrics(line_bbox),
                'max_font_size': max(font_sizes) if font_sizes else None,
            })
        block_text = ' '.join(line['normalized'] for line in line_records if line['normalized'])
        if not block_text:
            continue
        records.append({
            'block_index': block_index,
            'text': block_text,
            'normalized': normalize(block_text),
            'bbox': bbox_metrics(block_bbox),
            'lines': line_records,
        })
    doc.close()
    return records

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

def render_crop(pdf_path, crop_bbox, scale=4):
    doc = fitz.open(pdf_path)
    page = doc[0]
    rect = fitz.Rect(*crop_bbox)
    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), clip=rect, alpha=False)
    image = Image.frombytes("RGB", [pix.width, pix.height], pix.samples).convert("L")
    doc.close()
    return image, rect, scale

def extract_visible_line_metrics(pdf_path, crop_bbox):
    image, crop_rect, scale = render_crop(pdf_path, crop_bbox)
    width, height = image.size
    pixels = image.load()
    dark_threshold = 215
    min_dark_pixels_per_row = max(12, int(width * 0.04))
    min_dark_pixels_per_col = 3

    dark_rows = []
    for y in range(height):
        dark_count = 0
        for x in range(width):
            if pixels[x, y] < dark_threshold:
                dark_count += 1
        if dark_count >= min_dark_pixels_per_row:
            dark_rows.append(y)

    if not dark_rows:
        return []

    bands = []
    start = dark_rows[0]
    prev = dark_rows[0]
    max_gap = 3
    for y in dark_rows[1:]:
        if y - prev <= max_gap:
            prev = y
            continue
        bands.append((start, prev))
        start = y
        prev = y
    bands.append((start, prev))

    lines = []
    for line_index, (top_px, bottom_px) in enumerate(bands):
        if (bottom_px - top_px + 1) < 6:
            continue

        dark_cols = []
        for x in range(width):
            dark_count = 0
            for y in range(top_px, bottom_px + 1):
                if pixels[x, y] < dark_threshold:
                    dark_count += 1
            if dark_count >= min_dark_pixels_per_col:
                dark_cols.append(x)

        if not dark_cols:
            continue

        left_px = min(dark_cols)
        right_px = max(dark_cols) + 1
        lines.append({
            'line_index': len(lines),
            'bbox': {
                'left': float(crop_rect.x0 + (left_px / scale)),
                'top': float(crop_rect.y0 + (top_px / scale)),
                'right': float(crop_rect.x0 + (right_px / scale)),
                'bottom': float(crop_rect.y0 + ((bottom_px + 1) / scale)),
                'width': float((right_px - left_px) / scale),
                'height': float((bottom_px - top_px + 1) / scale),
            },
        })

    return lines

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
original_paragraph = find_paragraph_block(original_blocks, config['original_lines'])
original_lines = []

crop_bbox = None
if original_paragraph:
    bbox = original_paragraph['bbox']
    crop_bbox = [
        max(0.0, float(bbox['left']) - 3.0),
        max(0.0, float(bbox['top']) - 8.0),
        float(bbox['right']) + 12.0,
        float(bbox['bottom']) + 8.0,
    ]

saved_lines = extract_visible_line_metrics(saved_pdf_path, crop_bbox) if crop_bbox else []
original_lines = extract_visible_line_metrics(original_pdf_path, crop_bbox) if crop_bbox else []

report = {
    'original_paragraph': original_paragraph,
    'saved_paragraph': {
        'bbox': {
            'left': min((line['bbox']['left'] for line in saved_lines), default=0.0),
            'top': min((line['bbox']['top'] for line in saved_lines), default=0.0),
            'right': max((line['bbox']['right'] for line in saved_lines), default=0.0),
            'bottom': max((line['bbox']['bottom'] for line in saved_lines), default=0.0),
        },
        'line_count': len(saved_lines),
    } if saved_lines else None,
    'original_lines': original_lines,
    'saved_lines': saved_lines,
    'crop_bbox': crop_bbox,
    'metrics': {
        'left_deltas': deltas(
            [line['bbox']['left'] for line in original_lines],
            [line['bbox']['left'] for line in saved_lines],
        ) if len(original_lines) == len(saved_lines) and original_lines else [],
        'top_deltas': deltas(
            [line['bbox']['top'] for line in original_lines],
            [line['bbox']['top'] for line in saved_lines],
        ) if len(original_lines) == len(saved_lines) and original_lines else [],
        'height_deltas': deltas(
            [line['bbox']['height'] for line in original_lines],
            [line['bbox']['height'] for line in saved_lines],
        ) if len(original_lines) == len(saved_lines) and original_lines else [],
        'step_deltas': deltas(
            consecutive_steps(original_lines),
            consecutive_steps(saved_lines),
        ) if len(original_lines) == len(saved_lines) and original_lines else [],
    },
}

with open(report_path, 'w', encoding='utf-8') as handle:
    json.dump(report, handle, indent=2)

print(json.dumps(report, indent=2))
`;

    const result = spawnSync('python3', [
        '-c',
        compareScript,
        originalPdfPath,
        savedPdfPath,
        reportPath,
        JSON.stringify({
            original_lines: ORIGINAL_PAGE_ONE_LINES,
            updated_lines: UPDATED_PAGE_ONE_LINES,
        }),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Drylab page-1 alignment comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return {
        reportPath,
        report: JSON.parse(result.stdout),
    };
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1600, height: 1800 },
        acceptDownloads: true,
    });
    const page = await context.newPage();
    page.setDefaultTimeout(30000);
    page.setDefaultNavigationTimeout(90000);
    const directAnnotationRequests = [];
    let downloadRequestPayload = null;

    page.on('request', (request) => {
        if (request.method() !== 'POST') {
            return;
        }
        if (request.url().includes('/apply-annotations-direct')) {
            directAnnotationRequests.push({
                url: request.url(),
                body: request.postData(),
            });
            return;
        }
        if (request.url().includes('/download-annotated-pdf')) {
            try {
                downloadRequestPayload = request.postDataJSON();
            } catch (_error) {
                downloadRequestPayload = request.postData();
            }
        }
    });

    try {
        console.log('Uploading drylab_full.pdf...');
        const documentId = await uploadPdf(page);
        console.log(`Uploaded as document ${documentId}`);
        console.log('Waiting for editor canvas...');
        await waitForEditorReady(page);
        const baselinePdfPath = path.join(OUTPUT_DIR, `drylab_doc${documentId}_baseline.pdf`);
        await captureCurrentPdfBaseline(page, baselinePdfPath);
        console.log('Preparing overlay extraction...');
        await forceRefreshOverlay(page, documentId);
        await waitForEditorReady(page);
        console.log('Activating overlay editor...');
        await activateOverlay(page);
        await enableEditTextMode(page);
        console.log('Editing page 1 paragraph...');
        await editPageOneParagraph(page, UPDATED_PAGE_ONE_LINES.join('\n'));
        const pendingOverlayEdits = await collectPendingPromotedEdits(page);
        const downloadedPdfPath = path.join(OUTPUT_DIR, `drylab_doc${documentId}_download.pdf`);
        console.log('Downloading PDF with page 1 edit...');
        await downloadPdfViaToolbar(page, downloadedPdfPath);

        if (pendingOverlayEdits.length < 1) {
            throw new Error(`Expected pending promoted edits before download, got pending=${JSON.stringify(pendingOverlayEdits)}`);
        }

        const editedPages = Array.from(new Set(pendingOverlayEdits.map((edit) => Number(edit.page_number)).filter(Number.isFinite))).sort((a, b) => a - b);
        if (editedPages.length !== 1 || editedPages[0] !== 1) {
            throw new Error(`Expected pending overlay edits to touch only page 1, got pages: ${JSON.stringify(editedPages)} edits=${JSON.stringify(pendingOverlayEdits)}`);
        }
        if (directAnnotationRequests.length > 0) {
            throw new Error(`Overlay-only page-1 download should not call apply-annotations-direct from the browser, got ${JSON.stringify(directAnnotationRequests)}`);
        }

        const targetEdit = pendingOverlayEdits.find((edit) =>
            normalize(edit.original_text).includes(normalize(ORIGINAL_PAGE_ONE_TEXT).slice(0, 120))
            || normalize(edit.new_text).includes(normalize(UPDATED_PAGE_ONE_TEXT).slice(0, 120))
        );
        if (!targetEdit) {
            throw new Error(`Missing expected page-1 paragraph edit in pending edits: ${JSON.stringify(pendingOverlayEdits)}`);
        }
        if (!downloadRequestPayload || typeof downloadRequestPayload !== 'object') {
            throw new Error('Missing structured download request payload');
        }

        const redrawPageIndices = Array.isArray(downloadRequestPayload.redraw_page_indices)
            ? downloadRequestPayload.redraw_page_indices.map((value) => Number(value)).filter(Number.isInteger)
            : [];
        if (redrawPageIndices.length !== 1 || redrawPageIndices[0] !== 0) {
            throw new Error(`Expected full page redraw to target only page 1, got ${JSON.stringify(downloadRequestPayload.redraw_page_indices)}`);
        }

        const renderAnnotations = Array.isArray(downloadRequestPayload.render_annotations)
            ? downloadRequestPayload.render_annotations
            : [];
        const sessionAnnotations = Array.isArray(downloadRequestPayload.session_annotations)
            ? downloadRequestPayload.session_annotations
            : [];
        const materialAnnotations = Array.isArray(downloadRequestPayload.annotations)
            ? downloadRequestPayload.annotations
            : [];

        const hasUpdatedParagraph = (records) => records.some((annotation) => (
            Number(annotation?.pageIndex) === 0
            && String(annotation?.text || '').includes(UPDATED_PAGE_ONE_LINES[0])
        ));

        const matchingRenderAnnotation = renderAnnotations.find((annotation) => (
            Number(annotation?.pageIndex) === 0
            && String(annotation?.text || '').includes(UPDATED_PAGE_ONE_LINES[0])
        )) || null;
        const matchingSessionAnnotation = sessionAnnotations.find((annotation) => (
            Number(annotation?.pageIndex) === 0
            && String(annotation?.text || '').includes(UPDATED_PAGE_ONE_LINES[0])
        )) || null;
        const matchingMaterialAnnotation = materialAnnotations.find((annotation) => (
            Number(annotation?.pageIndex) === 0
            && String(annotation?.text || '').includes(UPDATED_PAGE_ONE_LINES[0])
        )) || null;

        fs.writeFileSync(
            path.join(OUTPUT_DIR, `drylab_doc${documentId}_download_request.json`),
            JSON.stringify({
                documentId,
                redraw_page_indices: downloadRequestPayload.redraw_page_indices,
                matching_render_annotation: matchingRenderAnnotation,
                matching_session_annotation: matchingSessionAnnotation,
                matching_material_annotation: matchingMaterialAnnotation,
            }, null, 2)
        );

        if (!hasUpdatedParagraph(renderAnnotations)) {
            throw new Error(`render_annotations missing updated page-1 paragraph: ${JSON.stringify(downloadRequestPayload.render_annotations)}`);
        }
        if (!hasUpdatedParagraph(sessionAnnotations)) {
            throw new Error(`session_annotations missing updated page-1 paragraph: ${JSON.stringify(downloadRequestPayload.session_annotations)}`);
        }
        if (!hasUpdatedParagraph(materialAnnotations)) {
            throw new Error(`annotations payload missing updated page-1 paragraph: ${JSON.stringify(downloadRequestPayload.annotations)}`);
        }

        console.log('Comparing edited page-1 paragraph alignment...');
        const alignmentResult = analyzeEditedPageAlignment(baselinePdfPath, downloadedPdfPath, OUTPUT_DIR, documentId);
        const alignmentMetrics = alignmentResult.report.metrics || {};
        const leftDeltas = Array.isArray(alignmentMetrics.left_deltas) ? alignmentMetrics.left_deltas : [];
        const topDeltas = Array.isArray(alignmentMetrics.top_deltas) ? alignmentMetrics.top_deltas : [];
        const heightDeltas = Array.isArray(alignmentMetrics.height_deltas) ? alignmentMetrics.height_deltas : [];
        const stepDeltas = Array.isArray(alignmentMetrics.step_deltas) ? alignmentMetrics.step_deltas : [];
        if (leftDeltas.length !== UPDATED_PAGE_ONE_LINES.length || Math.max(...leftDeltas, 0) > PARAGRAPH_LEFT_TOLERANCE) {
            throw new Error(`Drylab page-1 paragraph left alignment drifted: ${JSON.stringify({ leftDeltas, tolerance: PARAGRAPH_LEFT_TOLERANCE, reportPath: alignmentResult.reportPath })}`);
        }
        if (topDeltas.length !== UPDATED_PAGE_ONE_LINES.length || Math.max(...topDeltas, 0) > PARAGRAPH_TOP_TOLERANCE) {
            throw new Error(`Drylab page-1 paragraph top alignment drifted: ${JSON.stringify({ topDeltas, tolerance: PARAGRAPH_TOP_TOLERANCE, reportPath: alignmentResult.reportPath })}`);
        }
        if (heightDeltas.length !== UPDATED_PAGE_ONE_LINES.length || Math.max(...heightDeltas, 0) > PARAGRAPH_LINE_HEIGHT_TOLERANCE) {
            throw new Error(`Drylab page-1 paragraph line heights drifted: ${JSON.stringify({ heightDeltas, tolerance: PARAGRAPH_LINE_HEIGHT_TOLERANCE, reportPath: alignmentResult.reportPath })}`);
        }
        if (stepDeltas.length !== Math.max(0, UPDATED_PAGE_ONE_LINES.length - 1) || Math.max(...stepDeltas, 0) > PARAGRAPH_LINE_STEP_TOLERANCE) {
            throw new Error(`Drylab page-1 paragraph line step drifted: ${JSON.stringify({ stepDeltas, tolerance: PARAGRAPH_LINE_STEP_TOLERANCE, reportPath: alignmentResult.reportPath })}`);
        }

        console.log('Comparing non-edited pages against original...');
        const compareResult = compareUneditedPagesStrictly(baselinePdfPath, downloadedPdfPath, OUTPUT_DIR, documentId);

        console.log('drylab page-1-only download regression passed');
        console.log(JSON.stringify({
            documentId,
            edited_pages: editedPages,
            downloaded_pdf: downloadedPdfPath,
            download_request: {
                redraw_page_indices: downloadRequestPayload.redraw_page_indices,
                annotations_count: materialAnnotations.length,
                render_annotations_count: renderAnnotations.length,
                session_annotations_count: sessionAnnotations.length,
            },
            alignment_report: alignmentResult.reportPath,
            alignment_metrics: alignmentMetrics,
            compare: compareResult,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
