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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'fw8ben.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1600 };
const PDF_PAGE_WIDTH = 612;

const LEFT_FRAGMENT = 'You are NOT an individual';
const SECOND_ROW_FRAGMENT = 'You are a U.S. citizen or other U.S. person';
const THIRD_ROW_FRAGMENT = 'You are a beneficial owner claiming that income is effectively connected';
const RIGHT_FRAGMENT = 'W-8BEN-E';
const POSITION_TOLERANCE_PX = 5;
function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizeDotLeaderLine(value) {
    const normalized = normalize(value);
    if (/^[.\s]+$/.test(normalized)) {
        return normalized.replace(/\s+/g, '');
    }
    return normalized;
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function countNormalizedOccurrences(haystack, needle) {
    const source = normalize(haystack);
    const target = normalize(needle);
    if (!source || !target) {
        return 0;
    }
    let count = 0;
    let offset = 0;
    while (offset <= source.length) {
        const index = source.indexOf(target, offset);
        if (index === -1) {
            break;
        }
        count += 1;
        offset = index + target.length;
    }
    return count;
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

async function getRenderedPageWidth(page) {
    return page.evaluate(() => {
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        return pageRoot?.getBoundingClientRect()?.width || 0;
    });
}

function loadExpectedRowGeometry() {
    const pythonCode = `
import contextlib
import importlib.util
import io
import json
import sys
from pathlib import Path

pdf_path = Path(sys.argv[1])
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def normalize(value):
    return ' '.join(str(value or '').split())

with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))

page = result['extraction_data'][0]
target_line = None
citizen_line = None
label_span = None
label_line = None
for block in page.get('blocks', []):
    text_lines = [normalize(line) for line in (block.get('text_lines') or [])]
    line_bboxes = block.get('line_bboxes') or []
    for index, line in enumerate(text_lines):
        line_bbox = line_bboxes[index] if index < len(line_bboxes) else [block.get('left') or 0.0, block.get('top') or 0.0, (block.get('left') or 0.0) + (block.get('width') or 0.0), (block.get('top') or 0.0) + (block.get('height') or 0.0)]
        if 'You are NOT an individual' in line and 'W-8BEN-E' not in line:
            target_line = {
                'text': line,
                'left': float(line_bbox[0] or 0.0),
                'top': float(line_bbox[1] or 0.0),
                'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
                'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
            }
        if 'You are a U.S. citizen or other U.S. person' in line:
            citizen_line = {
                'text': line,
                'left': float(line_bbox[0] or 0.0),
                'top': float(line_bbox[1] or 0.0),
                'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
                'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
            }
        if 'W-8BEN-E' in line:
            label_line = {
                'text': line,
                'left': float(line_bbox[0] or 0.0),
                'top': float(line_bbox[1] or 0.0),
                'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
                'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
            }
    if label_span is None and any('W-8BEN-E' in line for line in text_lines):
        for span in block.get('spans') or []:
            if 'W-8BEN-E' in str(span.get('text') or ''):
                label_span = span
                break

if target_line is None:
    raise SystemExit('Could not find target fw8ben left row')
if label_span is None or label_line is None:
    raise SystemExit('Could not find W-8BEN-E label span/line')

span_bbox = label_span.get('bbox') or [0, 0, 0, 0]

print(json.dumps({
    'page_width': float(page.get('width') or 0.0),
    'left_line': target_line,
    'citizen_line': citizen_line,
    'label_line': label_line,
    'label_span': {
        'text': normalize(label_span.get('text') or ''),
        'left': float(span_bbox[0] or 0.0),
        'top': float(span_bbox[1] or 0.0),
        'width': max(0.0, float(span_bbox[2] or 0.0) - float(span_bbox[0] or 0.0)),
        'height': max(0.0, float(span_bbox[3] or 0.0) - float(span_bbox[1] or 0.0)),
    },
    'text_lines': [target_line['text']],
}))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, PDF_PATH], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw);
}

async function waitForTargetRow(page) {
    await page.waitForFunction(
        ({ leftFragment, secondRowFragment, rightFragment }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            const hasLeftRow = promotedAnnotations.some((annotation) => {
                const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
                const text = normalizeText(annotation.text || textEl?.innerText || textEl?.textContent || '');
                return text.includes(leftFragment);
            });
            const hasExactLabel = promotedAnnotations.some((annotation) => {
                const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
                if (textEl?.dataset?.exactPromotedGeometry !== '1') {
                    return false;
                }
                const exactLines = Array.from(textEl.querySelectorAll('.annotation-exact-line')).map((lineEl) => (
                    normalizeText(lineEl.textContent || '')
                ));
                return exactLines.some((lineText) => lineText.includes(rightFragment));
            });
            const hasCitizenRow = promotedAnnotations.some((annotation) => {
                const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
                const text = normalizeText(annotation.text || textEl?.innerText || textEl?.textContent || '');
                return text.includes(secondRowFragment);
            });

            return hasLeftRow && hasExactLabel && hasCitizenRow;
        },
        { leftFragment: LEFT_FRAGMENT, secondRowFragment: SECOND_ROW_FRAGMENT, rightFragment: RIGHT_FRAGMENT },
        { timeout: 90000 }
    );
}

async function collectRowState(page, expectedRowBox, expectedCitizenBox) {
    return page.evaluate(({ leftFragment, secondRowFragment, rightFragment, expectedRowBox, expectedCitizenBox }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const pageRect = pageRoot?.getBoundingClientRect() || null;
        const relativeRect = (element) => {
            const rect = element.getBoundingClientRect();
            return {
                left: pageRect ? rect.left - pageRect.left : rect.left,
                top: pageRect ? rect.top - pageRect.top : rect.top,
                width: rect.width,
                height: rect.height,
            };
        };

        const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
            : [];
        const pageAnnotations = promotedAnnotations.map((annotation) => {
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const renderedText = normalizeText(textEl.innerText || textEl.textContent || '');
            const annotationStateText = normalizeText(annotation.text || '');
            const text = renderedText || annotationStateText;
            return {
                annotation,
                element: annotation.element,
                textEl,
                text,
                renderedText,
                annotationStateText,
                rect: relativeRect(annotation.element),
            };
        });

        const targetBandTop = (expectedRowBox?.top || 0) - 12;
        const targetBandBottom = (expectedRowBox?.top || 0) + Math.max(expectedRowBox?.height || 0, 18) + 12;
        const citizenBandTop = (expectedCitizenBox?.top || 0) - 12;
        const citizenBandBottom = (expectedCitizenBox?.top || 0) + Math.max(expectedCitizenBox?.height || 0, 18) + 12;

        const rowAnnotationsWithLabel = pageAnnotations.filter((entry) => (
            entry.text.includes(rightFragment)
            && entry.rect.top >= targetBandTop
            && entry.rect.top <= targetBandBottom
        )).map((entry) => ({
                text: entry.text,
                ...entry.rect,
            }));

        const rowLabelLines = Array.from(document.querySelectorAll('.annotation-exact-line'))
            .map((lineEl) => ({
                text: normalizeText(lineEl.textContent || ''),
                ...relativeRect(lineEl),
            }))
            .filter((line) => (
                line.text.includes(rightFragment)
                && line.top >= targetBandTop
                && line.top <= targetBandBottom
            ));

        return {
            rowAnnotationsWithBothFragments: pageAnnotations.filter((entry) => (
                entry.text.includes(leftFragment)
                && entry.text.includes(rightFragment)
                && entry.rect.top >= targetBandTop
                && entry.rect.top <= targetBandBottom
            )).map((entry) => ({
                text: entry.text,
                ...entry.rect,
            })),
            rowAnnotationsWithLabel,
            rowLeftFragmentAnnotations: pageAnnotations.filter((entry) => (
                entry.text.includes(leftFragment)
                && entry.rect.top >= targetBandTop
                && entry.rect.top <= targetBandBottom
            )).map((entry) => ({
                text: entry.text,
                renderedText: entry.renderedText,
                exactGeometry: entry.textEl.dataset.exactPromotedGeometry === '1',
                ...entry.rect,
            })),
            rowLabelLines,
            citizenRowAnnotations: pageAnnotations.filter((entry) => (
                entry.text.includes(secondRowFragment)
                && entry.rect.top >= citizenBandTop
                && entry.rect.top <= citizenBandBottom
            )).map((entry) => ({
                text: entry.text,
                renderedText: entry.renderedText,
                exactGeometry: entry.textEl.dataset.exactPromotedGeometry === '1',
                ...entry.rect,
            })),
            page: {
                width: pageRect?.width || 0,
                height: pageRect?.height || 0,
            },
        };
    }, {
        leftFragment: LEFT_FRAGMENT,
        secondRowFragment: SECOND_ROW_FRAGMENT,
        rightFragment: RIGHT_FRAGMENT,
        expectedRowBox,
        expectedCitizenBox,
    });
}

function buildExpectedRect(entry, scale) {
    return {
        left: Number(entry.left || 0) * scale,
        top: Number(entry.top || 0) * scale,
        width: Number(entry.width || 0) * scale,
        height: Number(entry.height || 0) * scale,
    };
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const expected = loadExpectedRowGeometry();
    if (!expected.citizen_line) {
        throw new Error('Could not find the fw8ben W-9 row text in extractor output.');
    }

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await page.waitForTimeout(10000);

        const renderedPageWidth = await getRenderedPageWidth(page);
        const pageScale = Number(renderedPageWidth || 0) / Math.max(1, Number(expected.page_width || PDF_PAGE_WIDTH));
        const expectedRowBox = buildExpectedRect(expected.left_line, pageScale);
        const expectedCitizenBox = buildExpectedRect(expected.citizen_line, pageScale);
        const actual = await collectRowState(page, expectedRowBox, expectedCitizenBox);

        const scale = Number(actual.page.width || 0) / Math.max(1, Number(expected.page_width || PDF_PAGE_WIDTH));
        const expectedLabelRect = buildExpectedRect(expected.label_line, scale);
        const rowLabelLine = actual.rowLabelLines[0] || null;

        const checks = [
            {
                item: 'page_row_has_single_w8bene_exact_line',
                pass: actual.rowLabelLines.length === 1,
                detail: { count: actual.rowLabelLines.length, lines: actual.rowLabelLines },
            },
            {
                item: 'page_row_has_single_annotation_with_w8bene',
                pass: actual.rowAnnotationsWithLabel.length === 1,
                detail: { count: actual.rowAnnotationsWithLabel.length, annotations: actual.rowAnnotationsWithLabel },
            },
            {
                item: 'page_row_has_no_annotation_with_both_fragments',
                pass: actual.rowAnnotationsWithBothFragments.length === 0,
                detail: { count: actual.rowAnnotationsWithBothFragments.length, annotations: actual.rowAnnotationsWithBothFragments },
            },
            {
                item: 'page_row_left_fragment_never_inlines_w8bene',
                pass: actual.rowLeftFragmentAnnotations.every((entry) => !normalize(entry.text).includes(RIGHT_FRAGMENT)),
                detail: {
                    annotations: actual.rowLeftFragmentAnnotations,
                },
            },
            {
                item: 'page_row_has_left_fragment_annotation',
                pass: actual.rowLeftFragmentAnnotations.length >= 1,
                detail: {
                    count: actual.rowLeftFragmentAnnotations.length,
                    annotations: actual.rowLeftFragmentAnnotations,
                },
            },
            {
                item: 'page_row_left_fragment_never_inlines_following_beneficial_owner_row',
                pass: actual.rowLeftFragmentAnnotations.every((entry) => !normalize(entry.text).includes(THIRD_ROW_FRAGMENT)),
                detail: {
                    annotations: actual.rowLeftFragmentAnnotations,
                },
            },
            {
                item: 'page_has_us_citizen_w9_row_annotation',
                pass: actual.citizenRowAnnotations.length >= 1,
                detail: {
                    count: actual.citizenRowAnnotations.length,
                    annotations: actual.citizenRowAnnotations,
                },
            },
        ];

        if (rowLabelLine) {
            checks.push({
                item: 'row_label_left_matches_expected_row',
                pass: Math.abs(rowLabelLine.left - expectedLabelRect.left) <= POSITION_TOLERANCE_PX,
                detail: {
                    actual: round(rowLabelLine.left),
                    expected: round(expectedLabelRect.left),
                    delta: round(Math.abs(rowLabelLine.left - expectedLabelRect.left)),
                },
            });
            checks.push({
                item: 'row_label_top_matches_expected_row',
                pass: Math.abs(rowLabelLine.top - expectedLabelRect.top) <= POSITION_TOLERANCE_PX,
                detail: {
                    actual: round(rowLabelLine.top),
                    expected: round(expectedLabelRect.top),
                    delta: round(Math.abs(rowLabelLine.top - expectedLabelRect.top)),
                },
            });
        }

        const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_w8bene_row_no_duplication_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `fw8ben_w8bene_row_no_duplication_${runToken}.json`);
        const result = {
            status: checks.every((check) => check.pass) ? 'pass' : 'fail',
            expected,
            actual,
            checks,
            reportPath,
            screenshotPath,
        };
        fs.writeFileSync(reportPath, JSON.stringify(result, null, 2));

        console.log(JSON.stringify(result, null, 2));
        if (result.status !== 'pass') {
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error?.stack || String(error));
    process.exitCode = 1;
});
