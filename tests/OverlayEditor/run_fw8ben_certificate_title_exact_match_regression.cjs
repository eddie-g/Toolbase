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

const POSITION_TOLERANCE_PX = 3.5;
const SIZE_TOLERANCE_PX = 5;
const TITLE_TEXT = 'Certificate of Foreign Status of Beneficial Owner for United States Tax Withholding and Reporting (Individuals)';
const TITLE_FONT_NAME = 'ITCFranklinGothicStd-Demi';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
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

function loadExpectedTitleGeometry() {
    const pythonCode = `
import contextlib
import importlib.util
import io
import json
import sys
from pathlib import Path

pdf_path = Path(sys.argv[1])
target_text = sys.argv[2]
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def normalize(value):
    return ' '.join(str(value or '').split())

with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))

page = result['extraction_data'][0]
target_block = None
for block in page.get('blocks', []):
    if normalize(block.get('text') or '') == target_text:
        target_block = block
        break

if target_block is None:
    raise SystemExit('Could not find certificate title block in extractor output')

payload = {
    'block': {
        'text': normalize(target_block.get('text') or ''),
        'left': float(target_block.get('left') or 0.0),
        'top': float(target_block.get('top') or 0.0),
        'width': float(target_block.get('width') or 0.0),
        'height': float(target_block.get('height') or 0.0),
    },
    'text_lines': [normalize(line) for line in target_block.get('text_lines') or []],
    'line_bboxes': [
        [float(value or 0.0) for value in bbox]
        for bbox in (target_block.get('line_bboxes') or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ],
    'spans': [],
}

for span in target_block.get('spans') or []:
    bbox = span.get('bbox') or [0, 0, 0, 0]
    payload['spans'].append({
        'text': normalize(span.get('render_text') or span.get('text') or ''),
        'left': float(bbox[0] or 0.0),
        'top': float(bbox[1] or 0.0),
        'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
        'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
        'font': str(span.get('font') or ''),
        'embedded_font_name': str(span.get('embedded_font_name') or ''),
    })

print(json.dumps(payload))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, PDF_PATH, TITLE_TEXT], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw);
}

async function waitForPromotedTitle(page) {
    await page.waitForFunction(
        (titleText) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            const matchedAnnotation = promotedAnnotations.find((annotation) => (
                normalizeText(annotation.text || annotation.element?.innerText || '') === titleText
            ));
            if (!matchedAnnotation?.element) {
                return false;
            }
            const textEl = matchedAnnotation.element.querySelector('.annotation-text') || matchedAnnotation.element;
            return textEl.dataset.exactPromotedGeometry === '1'
                && matchedAnnotation.element.querySelectorAll('.annotation-exact-line').length === 2;
        },
        TITLE_TEXT,
        { timeout: 90000 }
    );
}

async function collectTitleGeometry(page) {
    return page.evaluate(({ titleText, fontName }) => {
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
        const titleAnnotation = promotedAnnotations.find((annotation) => (
            normalizeText(annotation.text || annotation.element?.innerText || '') === titleText
        ));
        if (!titleAnnotation?.element) {
            return null;
        }

        const textEl = titleAnnotation.element.querySelector('.annotation-text') || titleAnnotation.element;
        const lines = Array.from(titleAnnotation.element.querySelectorAll('.annotation-exact-line'))
            .filter((element) => element instanceof HTMLElement)
            .map((lineEl) => ({
                text: normalizeText(lineEl.innerText || lineEl.textContent || ''),
                ...relativeRect(lineEl),
                spans: Array.from(lineEl.querySelectorAll('.annotation-exact-span'))
                    .filter((element) => element instanceof HTMLElement)
                    .map((spanEl) => ({
                        text: normalizeText(spanEl.innerText || spanEl.textContent || ''),
                        fontFamily: window.getComputedStyle(spanEl).fontFamily,
                        fontStretch: window.getComputedStyle(spanEl).fontStretch,
                        ...relativeRect(spanEl),
                    })),
            }));

        return {
            annotation: {
                text: normalizeText(textEl.innerText || textEl.textContent || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                ...relativeRect(titleAnnotation.element),
            },
            lines,
            fontLoaded: document.fonts && typeof document.fonts.check === 'function'
                ? document.fonts.check(`normal 400 16px "PDF_${fontName}"`, 'Certificate')
                : null,
        };
    }, { titleText: TITLE_TEXT, fontName: TITLE_FONT_NAME });
}

function buildExpectedRect(entry, scale) {
    return {
        left: Number(entry.left || 0) * scale,
        top: Number(entry.top || 0) * scale,
        width: Number(entry.width || 0) * scale,
        height: Number(entry.height || 0) * scale,
    };
}

function runToleranceCheck(item, actual, expected, tolerance) {
    const delta = Math.abs(Number(actual) - Number(expected));
    return {
        item,
        pass: delta <= tolerance,
        detail: {
            actual: round(actual),
            expected: round(expected),
            delta: round(delta),
            tolerance,
        },
    };
}

function findActualSpan(actualGeometry, expectedText) {
    const target = normalize(expectedText);
    for (const line of actualGeometry.lines || []) {
        for (const span of line.spans || []) {
            if (normalize(span.text) === target) {
                return span;
            }
        }
    }
    return null;
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const expected = loadExpectedTitleGeometry();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await forceRefreshOverlay(page, documentId);
        await clearAnnotationSessionState(page, documentId);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForPromotedTitle(page);
        await page.waitForTimeout(1500);

        const actual = await collectTitleGeometry(page);
        if (!actual) {
            throw new Error('Could not collect certificate title geometry from the editor');
        }

        const scale = Number(actual.annotation.width || 0) / Math.max(1, Number(expected.block.width || 0));
        const expectedField = buildExpectedRect(expected.block, scale);
        const checks = [
            {
                item: 'certificate_title_uses_exact_geometry',
                pass: actual.annotation.exactGeometry === true,
                detail: { exactGeometry: actual.annotation.exactGeometry },
            },
            {
                item: 'certificate_title_embedded_font_loaded',
                pass: actual.fontLoaded !== false,
                detail: { fontLoaded: actual.fontLoaded },
            },
            runToleranceCheck('certificate_title_annotation_left', actual.annotation.left, expectedField.left, POSITION_TOLERANCE_PX),
            runToleranceCheck('certificate_title_annotation_top', actual.annotation.top, expectedField.top, POSITION_TOLERANCE_PX),
            runToleranceCheck('certificate_title_annotation_width', actual.annotation.width, expectedField.width, SIZE_TOLERANCE_PX),
            runToleranceCheck('certificate_title_annotation_height', actual.annotation.height, expectedField.height, SIZE_TOLERANCE_PX),
            {
                item: 'certificate_title_line_count_matches_source',
                pass: actual.lines.length === expected.line_bboxes.length,
                detail: { actual: actual.lines.length, expected: expected.line_bboxes.length },
            },
        ];

        expected.line_bboxes.forEach((lineBBox, index) => {
            const expectedLine = {
                left: lineBBox[0],
                top: lineBBox[1],
                width: lineBBox[2] - lineBBox[0],
                height: lineBBox[3] - lineBBox[1],
            };
            const actualLine = actual.lines[index];
            if (!actualLine) {
                checks.push({
                    item: `certificate_title_line_${index}_present`,
                    pass: false,
                    detail: { index },
                });
                return;
            }
            const expectedLineRect = buildExpectedRect(expectedLine, scale);
            checks.push({
                item: `certificate_title_line_${index}_text_matches`,
                pass: normalize(actualLine.text) === normalize(expected.text_lines[index] || ''),
                detail: {
                    actual: actualLine.text,
                    expected: expected.text_lines[index] || '',
                },
            });
            checks.push(runToleranceCheck(`certificate_title_line_${index}_left`, actualLine.left, expectedLineRect.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_line_${index}_top`, actualLine.top, expectedLineRect.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_line_${index}_width`, actualLine.width, expectedLineRect.width, SIZE_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_line_${index}_height`, actualLine.height, expectedLineRect.height, SIZE_TOLERANCE_PX));
        });

        expected.spans.forEach((expectedSpan) => {
            const actualSpan = findActualSpan(actual, expectedSpan.text);
            if (!actualSpan) {
                checks.push({
                    item: `certificate_title_span_present_${expectedSpan.text}`,
                    pass: false,
                    detail: { expectedText: expectedSpan.text },
                });
                return;
            }
            const expectedSpanRect = buildExpectedRect(expectedSpan, scale);
            checks.push(runToleranceCheck(`certificate_title_span_left_${expectedSpan.text}`, actualSpan.left, expectedSpanRect.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_span_top_${expectedSpan.text}`, actualSpan.top, expectedSpanRect.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_span_width_${expectedSpan.text}`, actualSpan.width, expectedSpanRect.width, SIZE_TOLERANCE_PX));
            checks.push(runToleranceCheck(`certificate_title_span_height_${expectedSpan.text}`, actualSpan.height, expectedSpanRect.height, SIZE_TOLERANCE_PX));
            checks.push({
                item: `certificate_title_span_font_${expectedSpan.text}`,
                pass: String(actualSpan.fontFamily || '').includes(`PDF_${expectedSpan.embedded_font_name || expectedSpan.font}`),
                detail: {
                    actualFontFamily: actualSpan.fontFamily,
                    expectedFontFamily: `PDF_${expectedSpan.embedded_font_name || expectedSpan.font}`,
                },
            });
        });

        const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_certificate_title_exact_match_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `fw8ben_certificate_title_exact_match_${runToken}.json`);
        const result = {
            status: checks.every((check) => check.pass) ? 'pass' : 'fail',
            documentId,
            scale: round(scale),
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
