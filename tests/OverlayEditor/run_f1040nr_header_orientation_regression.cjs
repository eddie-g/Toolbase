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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'f1040nr.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1440, height: 1700 };

const POSITION_TOLERANCE_PX = 4.5;
const SIZE_TOLERANCE_PX = 5.5;
const FORM_TEXT = 'Form';
const NUMBER_TEXT = '1040-NR';

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

function classifyOrientation(width, height) {
    const safeWidth = Math.max(Number(width) || 0, 0.001);
    const safeHeight = Math.max(Number(height) || 0, 0.001);
    if (safeHeight > safeWidth * 1.2) {
        return 'vertical';
    }
    if (safeWidth > safeHeight * 1.2) {
        return 'horizontal';
    }
    return 'square';
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

import fitz

pdf_path = Path(sys.argv[1])
doc = fitz.open(pdf_path)
page = doc[0]
raw = page.get_text('rawdict')

module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def normalize(value):
    return ' '.join(str(value or '').split())

def classify_orientation(bbox):
    x0, y0, x1, y1 = [float(v or 0.0) for v in bbox]
    width = max(0.0, x1 - x0)
    height = max(0.0, y1 - y0)
    if height > width * 1.2:
        return 'vertical'
    if width > height * 1.2:
        return 'horizontal'
    return 'square'

targets = {
    'Form': None,
    '1040-NR': None,
}
extracted_targets = {
    'Form': None,
    '1040-NR': None,
}

for block in raw.get('blocks', []):
    if int(block.get('type', 0) or 0) != 0:
        continue
    for line in block.get('lines', []):
        line_dir = [float(v or 0.0) for v in (line.get('dir') or [1.0, 0.0])[:2]]
        line_bbox = [float(v or 0.0) for v in (line.get('bbox') or [0.0, 0.0, 0.0, 0.0])[:4]]
        for span in line.get('spans', []):
            chars = ''.join(ch.get('c', '') for ch in span.get('chars', []))
            text = normalize(chars)
            if text not in targets:
                continue
            bbox = [float(v or 0.0) for v in (span.get('bbox') or line_bbox)[:4]]
            entry = {
                'text': text,
                'left': bbox[0],
                'top': bbox[1],
                'width': max(0.0, bbox[2] - bbox[0]),
                'height': max(0.0, bbox[3] - bbox[1]),
                'bbox': bbox,
                'line_bbox': line_bbox,
                'dir': line_dir,
                'orientation': classify_orientation(bbox),
                'font': str(span.get('font') or ''),
                'size': float(span.get('size') or 0.0),
            }
            current = targets[text]
            if current is None or bbox[1] < current['top']:
                targets[text] = entry

missing = [key for key, value in targets.items() if value is None]
if missing:
    raise SystemExit('Could not find expected header spans: ' + ', '.join(missing))

with contextlib.redirect_stdout(io.StringIO()):
    extracted = module.extract_text_with_pymupdf(str(pdf_path))

page_data = extracted['extraction_data'][0]
for block in page_data.get('blocks', []):
    text = normalize(block.get('text') or '')
    if text not in extracted_targets:
        continue
    extracted_targets[text] = {
        'text': text,
        'left': float(block.get('left') or 0.0),
        'top': float(block.get('top') or 0.0),
        'width': float(block.get('width') or 0.0),
        'height': float(block.get('height') or 0.0),
        'rotation': float(block.get('rotation') or 0.0),
        'direction': [float(v or 0.0) for v in (block.get('direction') or [0.0, 0.0])[:2]] if block.get('direction') else None,
        'font': str((block.get('spans') or [{}])[0].get('embedded_font_name') or (block.get('spans') or [{}])[0].get('font') or ''),
    }

missing_extracted = [key for key, value in extracted_targets.items() if value is None]
if missing_extracted:
    raise SystemExit('Could not find expected extracted header blocks: ' + ', '.join(missing_extracted))

payload = {
    'page': {
        'width': float(page.rect.width),
        'height': float(page.rect.height),
    },
    'raw_entries': targets,
    'extracted_entries': extracted_targets,
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
        ({ formText, numberText }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            if (promoted.length === 0) {
                return false;
            }
            const matches = promoted.map((annotation) => normalizeText(annotation.text || annotation.element?.innerText || ''));
            return matches.some((text) => (
                text === formText
                || text === 'mroF'
                || text === numberText
                || text === `${formText} ${numberText}`
                || text.includes(numberText)
            ));
        },
        { formText: FORM_TEXT, numberText: NUMBER_TEXT },
        { timeout: 90000 }
    );
}

async function collectHeaderGeometry(page) {
    return page.evaluate(({ formText, numberText }) => {
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

        const promoted = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
            : [];

        const summarizeAnnotation = (annotation) => {
            if (!annotation?.element) {
                return null;
            }
            const annotationEl = annotation.element;
            const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
            const lineElements = Array.from(annotationEl.querySelectorAll('.annotation-exact-line')).filter((element) => element instanceof HTMLElement);
            const spanElements = Array.from(annotationEl.querySelectorAll('.annotation-exact-span')).filter((element) => element instanceof HTMLElement);
            const spans = spanElements.map((spanEl) => {
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
            });
            const firstSpan = spans[0] || null;
            return {
                text: normalizeText(textEl.innerText || textEl.textContent || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                lines: lineElements.map((lineEl) => ({
                    text: normalizeText(lineEl.innerText || lineEl.textContent || ''),
                    ...relativeRect(lineEl),
                })),
                spans,
                firstSpan,
                ...relativeRect(annotationEl),
            };
        };

        const byText = (targetText) => promoted.find((annotation) => (
            normalizeText(annotation.text || annotation.element?.innerText || '') === targetText
        ));

        const candidates = promoted
            .map((annotation) => summarizeAnnotation(annotation))
            .filter(Boolean)
            .sort((a, b) => (
                (a.top - b.top) || (a.left - b.left)
            ))
            .slice(0, 12);

        return {
            page: pageRect ? { width: pageRect.width, height: pageRect.height } : null,
            form: summarizeAnnotation(byText(formText)),
            number: summarizeAnnotation(byText(numberText)),
            candidates,
            fontsLoaded: {
                roman: document.fonts && typeof document.fonts.check === 'function'
                    ? document.fonts.check('normal 400 16px "PDF_HelveticaNeueLTStd-Roman"', formText)
                    : null,
                blkCn: document.fonts && typeof document.fonts.check === 'function'
                    ? document.fonts.check('normal 900 condensed 16px "PDF_HelveticaNeueLTStd-BlkCn"', numberText)
                    : null,
            },
        };
    }, { formText: FORM_TEXT, numberText: NUMBER_TEXT });
}

function buildExpectedRect(entry, scaleX, scaleY) {
    return {
        left: Number(entry.left || 0) * scaleX,
        top: Number(entry.top || 0) * scaleY,
        width: Number(entry.width || 0) * scaleX,
        height: Number(entry.height || 0) * scaleY,
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

function buildOrientationCheck(label, actualRect, expectedOrientation) {
    const actualOrientation = actualRect
        ? classifyOrientation(actualRect.width, actualRect.height)
        : 'missing';
    return {
        item: `${label}_orientation`,
        pass: actualOrientation === expectedOrientation,
        detail: {
            actualOrientation,
            expectedOrientation,
            actualWidth: round(actualRect?.width),
            actualHeight: round(actualRect?.height),
        },
    };
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
        if (browserConsole.length > 60) {
            browserConsole.shift();
        }
    });
    page.on('pageerror', (error) => {
        browserConsole.push(`[pageerror] ${error?.stack || error?.message || String(error)}`);
        if (browserConsole.length > 60) {
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
        if (!actual?.page) {
            throw new Error('Could not collect page geometry from the editor');
        }

        const checks = [];
        const scaleX = Number(actual.page.width || 0) / Math.max(1, Number(expected.page.width || 0));
        const scaleY = Number(actual.page.height || 0) / Math.max(1, Number(expected.page.height || 0));

        checks.push({
            item: 'roman_embedded_font_loaded',
            pass: actual.fontsLoaded.roman !== false,
            detail: { fontsLoaded: actual.fontsLoaded.roman },
        });
        checks.push({
            item: 'blkcn_embedded_font_loaded',
            pass: actual.fontsLoaded.blkCn !== false,
            detail: { fontsLoaded: actual.fontsLoaded.blkCn },
        });

        for (const key of [FORM_TEXT, NUMBER_TEXT]) {
            const rawEntry = expected.raw_entries?.[key];
            const expectedEntry = expected.extracted_entries?.[key];
            const actualEntry = key === FORM_TEXT ? actual.form : actual.number;
            if (!actualEntry) {
                checks.push({
                    item: `${key}_present`,
                    pass: false,
                    detail: {
                        expectedText: key,
                        candidateTexts: (actual.candidates || []).map((entry) => entry.text),
                    },
                });
                continue;
            }

            checks.push({
                item: `${key}_exact_geometry`,
                pass: actualEntry.exactGeometry === true,
                detail: { exactGeometry: actualEntry.exactGeometry },
            });
            checks.push({
                item: `${key}_text_exact`,
                pass: normalize(actualEntry.text) === key,
                detail: { actualText: actualEntry.text, expectedText: key },
            });
            if (key === FORM_TEXT) {
                checks.push({
                    item: 'form_not_reversed',
                    pass: normalize(actualEntry.text) !== 'mroF',
                    detail: { actualText: actualEntry.text },
                });
            }

            const firstSpan = actualEntry.firstSpan;
            const actualRect = key === FORM_TEXT && firstSpan ? firstSpan : actualEntry;
            const expectedRect = buildExpectedRect(key === FORM_TEXT ? (rawEntry || expectedEntry) : expectedEntry, scaleX, scaleY);
            checks.push(runToleranceCheck(`${key}_left`, actualRect.left, expectedRect.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`${key}_top`, actualRect.top, expectedRect.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`${key}_width`, actualRect.width, expectedRect.width, SIZE_TOLERANCE_PX));
            checks.push(runToleranceCheck(`${key}_height`, actualRect.height, expectedRect.height, SIZE_TOLERANCE_PX));
            checks.push(buildOrientationCheck(key, actualRect, rawEntry.orientation));

            if (firstSpan) {
                const expectedFont = String(expectedEntry.font || rawEntry.font || '');
                checks.push({
                    item: `${key}_font_family`,
                    pass: !expectedFont || String(firstSpan.fontFamily || '').includes(`PDF_${expectedFont}`),
                    detail: {
                        actualFontFamily: firstSpan.fontFamily,
                        expectedFontFamily: expectedFont ? `PDF_${expectedFont}` : null,
                    },
                });
                if (key === FORM_TEXT) {
                    checks.push({
                        item: 'form_transform_applied',
                        pass: String(firstSpan.transform || '').toLowerCase() !== 'none',
                        detail: { transform: firstSpan.transform },
                    });
                }
            } else {
                checks.push({
                    item: `${key}_first_span_present`,
                    pass: false,
                    detail: { text: actualEntry.text },
                });
            }
        }

        const screenshotPath = path.join(OUTPUT_DIR, `f1040nr_header_orientation_${runToken}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const reportPath = path.join(OUTPUT_DIR, `f1040nr_header_orientation_${runToken}.json`);
        const result = {
            status: checks.every((check) => check.pass) ? 'pass' : 'fail',
            documentId,
            expected,
            actual,
            browserConsole,
            scale: {
                x: round(scaleX),
                y: round(scaleY),
            },
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
