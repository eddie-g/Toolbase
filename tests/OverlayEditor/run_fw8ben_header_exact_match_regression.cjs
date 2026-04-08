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
const SIZE_TOLERANCE_PX = 4.5;
const HEADER_TEXT = 'Form W-8BEN';

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

function loadExpectedHeaderGeometry() {
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
target_block = None
for block in page.get('blocks', []):
    if normalize(block.get('text') or '') == 'Form W-8BEN':
        target_block = block
        break

if target_block is None:
    raise SystemExit('Could not find Form W-8BEN block in extractor output')

line_bbox = None
for bbox in target_block.get('line_bboxes') or []:
    if isinstance(bbox, list) and len(bbox) >= 4:
        line_bbox = [float(value or 0.0) for value in bbox]
        break

spans = []
for span in target_block.get('spans') or []:
    bbox = span.get('bbox') or [0, 0, 0, 0]
    spans.append({
        'text': normalize(span.get('render_text') or span.get('text') or ''),
        'raw_text': str(span.get('render_text') or span.get('text') or ''),
        'left': float(bbox[0] or 0.0),
        'top': float(bbox[1] or 0.0),
        'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
        'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
        'font': str(span.get('font') or ''),
        'embedded_font_name': str(span.get('embedded_font_name') or ''),
        'font_size': float(span.get('font_size') or 0.0),
        'font_weight': str(span.get('font_weight') or ''),
    })

payload = {
    'block': {
        'text': normalize(target_block.get('text') or ''),
        'left': float(target_block.get('left') or 0.0),
        'top': float(target_block.get('top') or 0.0),
        'width': float(target_block.get('width') or 0.0),
        'height': float(target_block.get('height') or 0.0),
    },
    'line_bbox': line_bbox,
    'spans': spans,
}

print(json.dumps(payload))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, PDF_PATH], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw);
}

async function waitForPromotedHeader(page) {
    await page.waitForFunction(
        (headerText) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            const matchedAnnotation = promotedAnnotations.find((annotation) => (
                normalizeText(annotation.text || annotation.element?.innerText || '') === headerText
            ));
            if (!matchedAnnotation?.element) {
                return false;
            }

            const textEl = matchedAnnotation.element.querySelector('.annotation-text') || matchedAnnotation.element;
            return textEl.dataset.exactPromotedGeometry === '1'
                && matchedAnnotation.element.querySelectorAll('.annotation-exact-span').length >= 2;
        },
        HEADER_TEXT,
        { timeout: 90000 }
    );
}

async function collectHeaderGeometry(page) {
    return page.evaluate((headerText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const pageRect = pageRoot?.getBoundingClientRect() || null;

        const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
            : [];
        const headerAnnotation = promotedAnnotations.find((annotation) => (
            normalizeText(annotation.text || annotation.element?.innerText || '') === headerText
        ));
        if (!headerAnnotation?.element) {
            return null;
        }
        const sourceMeta = typeof getPromotedAnnotationSourceMeta === 'function'
            ? getPromotedAnnotationSourceMeta(headerAnnotation)
            : null;

        const relativeRect = (element) => {
            const rect = element.getBoundingClientRect();
            return {
                left: pageRect ? rect.left - pageRect.left : rect.left,
                top: pageRect ? rect.top - pageRect.top : rect.top,
                width: rect.width,
                height: rect.height,
            };
        };

        const annotationEl = headerAnnotation.element;
        const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
        const lineElements = Array.from(annotationEl.querySelectorAll('.annotation-exact-line')).filter((element) => element instanceof HTMLElement);
        const lines = lineElements.map((lineEl) => ({
            text: normalizeText(lineEl.innerText || lineEl.textContent || ''),
            ...relativeRect(lineEl),
            spans: Array.from(lineEl.children)
                .filter((element) => element instanceof HTMLElement)
                .map((spanEl) => {
                    const style = window.getComputedStyle(spanEl);
                    return {
                        text: normalizeText(spanEl.innerText || spanEl.textContent || ''),
                        transform: style.transform,
                        fontFamily: style.fontFamily,
                        fontSize: style.fontSize,
                        fontWeight: style.fontWeight,
                        fontStretch: style.fontStretch,
                        ...relativeRect(spanEl),
                    };
                }),
        }));

        return {
            annotation: {
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                text: normalizeText(textEl.innerText || textEl.textContent || ''),
                ...relativeRect(annotationEl),
            },
            lines,
            fontsLoaded: {
                roman: document.fonts && typeof document.fonts.check === 'function'
                    ? document.fonts.check('normal 400 16px "PDF_HelveticaNeueLTStd-Roman"', 'Form')
                    : null,
                blkCn: document.fonts && typeof document.fonts.check === 'function'
                    ? document.fonts.check('normal 900 condensed 16px "PDF_HelveticaNeueLTStd-BlkCn"', 'W-8BEN')
                    : null,
            },
            sourceSpans: Array.isArray(sourceMeta?.sourceSpans)
                ? sourceMeta.sourceSpans.map((span) => ({
                    text: normalizeText(span?.text || span?.rawText || ''),
                    font: String(span?.font || ''),
                    fontWeight: String(span?.fontWeight || ''),
                    fontStyle: String(span?.fontStyle || ''),
                }))
                : [],
        };
    }, HEADER_TEXT);
}

function buildExpectedRect(entry, scale) {
    return {
        left: Number(entry.left || 0) * scale,
        top: Number(entry.top || 0) * scale,
        width: Number(entry.width || 0) * scale,
        height: Number(entry.height || 0) * scale,
    };
}

function assertWithinTolerance(label, actual, expected, tolerance) {
    const delta = Math.abs(Number(actual) - Number(expected));
    if (delta > tolerance) {
        throw new Error(`${label} drifted by ${round(delta)}px (expected ${round(expected)}, got ${round(actual)})`);
    }
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
    const expected = loadExpectedHeaderGeometry();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    const browserConsole = [];
    page.on('console', (message) => {
        browserConsole.push(`[${message.type()}] ${message.text()}`);
        if (browserConsole.length > 50) {
            browserConsole.shift();
        }
    });
    page.on('pageerror', (error) => {
        browserConsole.push(`[pageerror] ${error?.stack || error?.message || String(error)}`);
        if (browserConsole.length > 50) {
            browserConsole.shift();
        }
    });

    let documentId = null;

    try {
        documentId = await uploadPdf(page);
        await forceRefreshOverlay(page, documentId);
        await clearAnnotationSessionState(page, documentId);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForPromotedHeader(page);
        await page.waitForTimeout(1500);

        const actual = await collectHeaderGeometry(page);
        if (!actual) {
            throw new Error('Could not collect Form W-8BEN promoted annotation geometry from the editor');
        }
        if (!actual.annotation.exactGeometry) {
            throw new Error('Form W-8BEN header did not render with exact promoted geometry');
        }

        const scale = Number(actual.annotation.width || 0) / Math.max(1, Number(expected.block.width || 0));
        const expectedField = buildExpectedRect(expected.block, scale);
        const checks = [
            {
                item: 'roman_embedded_font_loaded',
                pass: actual.fontsLoaded.roman !== false,
                detail: { fontsLoaded: actual.fontsLoaded.roman },
            },
            {
                item: 'blkcn_embedded_font_loaded',
                pass: actual.fontsLoaded.blkCn !== false,
                detail: { fontsLoaded: actual.fontsLoaded.blkCn },
            },
            runToleranceCheck('header_annotation_left', actual.annotation.left, expectedField.left, POSITION_TOLERANCE_PX),
            runToleranceCheck('header_annotation_top', actual.annotation.top, expectedField.top, POSITION_TOLERANCE_PX),
            runToleranceCheck('header_annotation_width', actual.annotation.width, expectedField.width, SIZE_TOLERANCE_PX),
            runToleranceCheck('header_annotation_height', actual.annotation.height, expectedField.height, SIZE_TOLERANCE_PX),
        ];

        const expectedSpans = expected.spans.map((span) => ({
            ...span,
            rect: buildExpectedRect(span, scale),
        }));
        for (const expectedSpan of expectedSpans) {
            const actualSpan = findActualSpan(actual, expectedSpan.text);
            if (!actualSpan) {
                checks.push({
                    item: `span_present_${expectedSpan.text}`,
                    pass: false,
                    detail: { expectedText: expectedSpan.text },
                });
                continue;
            }

            checks.push(runToleranceCheck(`span_left_${expectedSpan.text}`, actualSpan.left, expectedSpan.rect.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`span_top_${expectedSpan.text}`, actualSpan.top, expectedSpan.rect.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`span_width_${expectedSpan.text}`, actualSpan.width, expectedSpan.rect.width, SIZE_TOLERANCE_PX));
            checks.push(runToleranceCheck(`span_height_${expectedSpan.text}`, actualSpan.height, expectedSpan.rect.height, SIZE_TOLERANCE_PX));

            const normalizedFont = String(actualSpan.fontFamily || '');
            const expectedFontName = expectedSpan.embedded_font_name || expectedSpan.font;
            checks.push({
                item: `span_font_${expectedSpan.text}`,
                pass: !expectedFontName || normalizedFont.includes(`PDF_${expectedFontName}`),
                detail: {
                    actualFontFamily: actualSpan.fontFamily,
                    expectedFontFamily: expectedFontName ? `PDF_${expectedFontName}` : null,
                },
            });
        }

        const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_header_exact_match_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `fw8ben_header_exact_match_${runToken}.json`);
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
    } catch (error) {
        if (browserConsole.length > 0) {
            error.message = `${error.message}\nRecent browser logs:\n${browserConsole.join('\n')}`;
        }
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error?.stack || String(error));
    process.exitCode = 1;
});
