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

const POSITION_TOLERANCE_PX = 4;
const SIZE_TOLERANCE_PX = 5;
const CLIP_PATH_FRAGMENT = 'polygon(0 0, 100% 50%, 0 100%)';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizeClipPath(value) {
    return String(value || '')
        .replace(/0px/g, '0')
        .replace(/\s+/g, ' ')
        .trim();
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function buildExpectedRect(block, scaleX, scaleY) {
    return {
        left: block.left * scaleX,
        top: block.top * scaleY,
        width: block.width * scaleX,
        height: block.height * scaleY,
    };
}

function runToleranceCheck(item, actual, expected, tolerance) {
    const passed = Number.isFinite(actual) && Number.isFinite(expected) && Math.abs(actual - expected) <= tolerance;
    return {
        item,
        passed,
        actual: round(actual),
        expected: round(expected),
        tolerance,
    };
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

function loadExpectedSignArrowGeometry() {
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
sign_block = None
arrow_block = None

for block in page.get('blocks', []):
    text = normalize(block.get('text') or '')
    if text == 'Sign Here':
        sign_block = block
    if text == '▲' and normalize(block.get('font') or '') == 'AdobePiStd':
        arrow_block = block

if sign_block is None:
    raise SystemExit('Could not find Sign Here block in extractor output')
if arrow_block is None:
    raise SystemExit('Could not find AdobePiStd arrow block in extractor output')

def pack(block):
    return {
        'block_num': int(block.get('block_num') or 0),
        'text': normalize(block.get('text') or ''),
        'left': float(block.get('left') or 0.0),
        'top': float(block.get('top') or 0.0),
        'width': float(block.get('width') or 0.0),
        'height': float(block.get('height') or 0.0),
        'font': str(block.get('font') or ''),
        'embedded_font_name': str(block.get('embedded_font_name') or ''),
    }

payload = {
    'page_width': float(page.get('width') or 0.0),
    'page_height': float(page.get('height') or 0.0),
    'sign': pack(sign_block),
    'arrow': pack(arrow_block),
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

async function waitForPromotedSignAndArrow(page, expected) {
    await page.waitForFunction(
        ({ signBlockNum, arrowBlockNum }) => {
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            const signAnnotation = promotedAnnotations.find((annotation) => Number(annotation?.promotedSourceBlockNum) === Number(signBlockNum));
            const arrowAnnotation = promotedAnnotations.find((annotation) => Number(annotation?.promotedSourceBlockNum) === Number(arrowBlockNum));
            if (!signAnnotation?.element || !arrowAnnotation?.element) {
                return false;
            }

            const signText = signAnnotation.element.querySelector('.annotation-text') || signAnnotation.element;
            const arrowText = arrowAnnotation.element.querySelector('.annotation-text') || arrowAnnotation.element;
            return signText?.dataset?.exactPromotedGeometry === '1'
                && arrowText?.dataset?.exactPromotedGeometry === '1'
                && arrowAnnotation.element.querySelector('.annotation-exact-span');
        },
        {
            signBlockNum: expected.sign.block_num,
            arrowBlockNum: expected.arrow.block_num,
        },
        { timeout: 90000 }
    );
}

async function collectSignArrowGeometry(page, expected) {
    return page.evaluate(({ signBlockNum, arrowBlockNum, pageWidth, pageHeight }) => {
        const pageRoot = document.querySelector('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]');
        const pageRect = pageRoot?.getBoundingClientRect() || null;
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
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

        const getAnnotationPayload = (annotation) => {
            if (!annotation?.element) {
                return null;
            }
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const spanEl = annotation.element.querySelector('.annotation-exact-span');
            const spanStyle = spanEl ? window.getComputedStyle(spanEl) : null;
            return {
                annotationRect: relativeRect(annotation.element),
                textRect: relativeRect(textEl),
                text: normalizeText(annotation.text || textEl.textContent || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                span: spanEl ? {
                    text: normalizeText(spanEl.textContent || ''),
                    rawText: spanEl.textContent || '',
                    clipPath: spanStyle?.clipPath || spanStyle?.webkitClipPath || '',
                    backgroundColor: spanStyle?.backgroundColor || '',
                    color: spanStyle?.color || '',
                    fontFamily: spanStyle?.fontFamily || '',
                    transform: spanStyle?.transform || '',
                    ...relativeRect(spanEl),
                } : null,
            };
        };

        const signAnnotation = promotedAnnotations.find((annotation) => Number(annotation?.promotedSourceBlockNum) === Number(signBlockNum));
        const arrowAnnotation = promotedAnnotations.find((annotation) => Number(annotation?.promotedSourceBlockNum) === Number(arrowBlockNum));

        return {
            pageScaleX: pageRect && pageWidth > 0 ? pageRect.width / pageWidth : 0,
            pageScaleY: pageRect && pageHeight > 0 ? pageRect.height / pageHeight : 0,
            sign: getAnnotationPayload(signAnnotation),
            arrow: getAnnotationPayload(arrowAnnotation),
        };
    }, {
        signBlockNum: expected.sign.block_num,
        arrowBlockNum: expected.arrow.block_num,
        pageWidth: expected.page_width,
        pageHeight: expected.page_height,
    });
}

async function main() {
    ensureOutputDir();
    const expected = loadExpectedSignArrowGeometry();
    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_sign_here_arrow_${runToken}.png`);
    const reportPath = path.join(OUTPUT_DIR, `fw8ben_sign_here_arrow_${runToken}.json`);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForPromotedSignAndArrow(page, expected);

        const actual = await collectSignArrowGeometry(page, expected);
        if (!actual?.sign || !actual?.arrow) {
            throw new Error('Could not locate promoted Sign Here or arrow annotations in the editor');
        }

        const expectedSignRect = buildExpectedRect(expected.sign, actual.pageScaleX, actual.pageScaleY);
        const expectedArrowRect = buildExpectedRect(expected.arrow, actual.pageScaleX, actual.pageScaleY);

        const checks = [
            {
                item: 'sign_here_exact_geometry_enabled',
                passed: Boolean(actual.sign.exactGeometry),
                actual: actual.sign.exactGeometry,
                expected: true,
            },
            {
                item: 'sign_here_text_matches',
                passed: normalize(actual.sign.text) === normalize(expected.sign.text),
                actual: actual.sign.text,
                expected: expected.sign.text,
            },
            {
                item: 'sign_here_arrow_exact_geometry_enabled',
                passed: Boolean(actual.arrow.exactGeometry),
                actual: actual.arrow.exactGeometry,
                expected: true,
            },
            {
                item: 'sign_here_arrow_span_present',
                passed: Boolean(actual.arrow.span),
                actual: Boolean(actual.arrow.span),
                expected: true,
            },
        ];

        if (actual.arrow.span) {
            checks.push(
                {
                    item: 'sign_here_arrow_is_shape',
                    passed: normalizeClipPath(actual.arrow.span.clipPath || '').includes(normalizeClipPath(CLIP_PATH_FRAGMENT)),
                    actual: normalizeClipPath(actual.arrow.span.clipPath),
                    expected: normalizeClipPath(CLIP_PATH_FRAGMENT),
                },
                {
                    item: 'sign_here_arrow_text_hidden',
                    passed: normalize(actual.arrow.span.rawText || '') === '',
                    actual: actual.arrow.span.rawText,
                    expected: '',
                },
                {
                    item: 'sign_here_arrow_no_glyph_transform',
                    passed: !String(actual.arrow.span.transform || '').includes('matrix'),
                    actual: actual.arrow.span.transform,
                    expected: 'none',
                }
            );
        }

        checks.push(
            runToleranceCheck('sign_here_text_left', actual.sign.textRect.left, expectedSignRect.left, POSITION_TOLERANCE_PX),
            runToleranceCheck('sign_here_text_top', actual.sign.textRect.top, expectedSignRect.top, POSITION_TOLERANCE_PX),
            runToleranceCheck('sign_here_text_width', actual.sign.textRect.width, expectedSignRect.width, SIZE_TOLERANCE_PX),
            runToleranceCheck('sign_here_text_height', actual.sign.textRect.height, expectedSignRect.height, SIZE_TOLERANCE_PX),
            runToleranceCheck('sign_here_arrow_left', actual.arrow.textRect.left, expectedArrowRect.left, POSITION_TOLERANCE_PX),
            runToleranceCheck('sign_here_arrow_top', actual.arrow.textRect.top, expectedArrowRect.top, POSITION_TOLERANCE_PX),
            runToleranceCheck('sign_here_arrow_width', actual.arrow.textRect.width, expectedArrowRect.width, SIZE_TOLERANCE_PX),
            runToleranceCheck('sign_here_arrow_height', actual.arrow.textRect.height, expectedArrowRect.height, SIZE_TOLERANCE_PX),
            {
                item: 'sign_here_arrow_is_to_right_of_label',
                passed: actual.arrow.textRect.left > (actual.sign.textRect.left + actual.sign.textRect.width + 6),
                actual: round(actual.arrow.textRect.left - (actual.sign.textRect.left + actual.sign.textRect.width)),
                expected: '> 6',
            },
            {
                item: 'sign_here_arrow_taller_than_label',
                passed: actual.arrow.textRect.height > (actual.sign.textRect.height * 1.8),
                actual: round(actual.arrow.textRect.height / Math.max(1, actual.sign.textRect.height)),
                expected: '> 1.8x',
            }
        );

        await page.screenshot({ path: screenshotPath, fullPage: true });

        const report = {
            documentId,
            expected,
            actual: {
                pageScaleX: round(actual.pageScaleX),
                pageScaleY: round(actual.pageScaleY),
                sign: actual.sign,
                arrow: actual.arrow,
            },
            checks,
            screenshotPath,
        };
        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

        const failures = checks.filter((check) => !check.passed);
        if (failures.length) {
            throw new Error(
                `fw8ben Sign Here arrow regression failed.\nreport=${reportPath}\nscreenshot=${screenshotPath}\n`
                + failures.map((failure) => JSON.stringify(failure)).join('\n')
            );
        }

        console.log('fw8ben Sign Here arrow regression passed');
        console.log(reportPath);
        console.log(screenshotPath);
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
