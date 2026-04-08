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

const POSITION_TOLERANCE_PX = 5;
const SIZE_TOLERANCE_PX = 8;
const HEADER_GAP_TOLERANCE_PX = 6;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizeCompact(value) {
    return String(value || '').replace(/\s+/g, '').trim();
}

function normalizeLeader(value) {
    return String(value || '').replace(/\s+/g, '').trim();
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

function loadExpectedGeometry() {
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
payload = {
    'header': None,
    'line5': None,
    'labels': {},
    'leader': None,
}

for block in page.get('blocks', []):
    text_lines = [normalize(line) for line in (block.get('text_lines') or [])]
    block_text = normalize(block.get('text') or block.get('text_single_line') or '')
    block_spans = []
    for span in block.get('spans') or []:
        bbox = span.get('bbox') or [0, 0, 0, 0]
        block_spans.append({
            'text': normalize(span.get('text') or span.get('render_text') or ''),
            'left': float(bbox[0] or 0.0),
            'top': float(bbox[1] or 0.0),
            'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
            'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
        })

    if block_text == 'Part I Identification of Beneficial Owner (see instructions)':
        spans = []
        for span in block.get('spans') or []:
            bbox = span.get('bbox') or [0, 0, 0, 0]
            spans.append({
                'text': normalize(span.get('text') or span.get('render_text') or ''),
                'left': float(bbox[0] or 0.0),
                'top': float(bbox[1] or 0.0),
                'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
                'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
            })
        line_bbox = (block.get('line_bboxes') or [[block.get('left') or 0.0, block.get('top') or 0.0, (block.get('left') or 0.0) + (block.get('width') or 0.0), (block.get('top') or 0.0) + (block.get('height') or 0.0)]])[0]
        payload['header'] = {
            'text': block_text,
            'left': float(line_bbox[0] or 0.0),
            'top': float(line_bbox[1] or 0.0),
            'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
            'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
            'spans': spans,
        }

    if block_text == '5 U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)':
        spans = []
        for span in block.get('spans') or []:
            bbox = span.get('bbox') or [0, 0, 0, 0]
            spans.append({
                'text': normalize(span.get('text') or span.get('render_text') or ''),
                'left': float(bbox[0] or 0.0),
                'top': float(bbox[1] or 0.0),
                'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
                'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
            })
        line_bbox = (block.get('line_bboxes') or [[block.get('left') or 0.0, block.get('top') or 0.0, (block.get('left') or 0.0) + (block.get('width') or 0.0), (block.get('top') or 0.0) + (block.get('height') or 0.0)]])[0]
        payload['line5'] = {
            'text': block_text,
            'left': float(line_bbox[0] or 0.0),
            'top': float(line_bbox[1] or 0.0),
            'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
            'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
            'spans': spans,
        }

    for index, line in enumerate(text_lines):
        line_bbox = (block.get('line_bboxes') or [])[index] if index < len(block.get('line_bboxes') or []) else None
        if not line_bbox or not isinstance(line_bbox, (list, tuple)) or len(line_bbox) < 4:
            continue
        compact = line.replace(' ', '')
        line_entry = {
            'text': line,
            'left': float(line_bbox[0] or 0.0),
            'top': float(line_bbox[1] or 0.0),
            'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
            'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
        }
        if compact and set(compact) == {'.'} and len(compact) >= 12:
            payload['leader'] = line_entry
        if 'W-8BEN-E' in line:
            payload['labels']['W-8BEN-E'] = line_entry
        if line.endswith('W-9') or ' W-9' in line:
            payload['labels']['W-9'] = line_entry
        if 'W-8ECI' in line:
            payload['labels']['W-8ECI'] = line_entry
        if '8233 or W-4' in line:
            payload['labels']['8233 or W-4'] = next((
                span for span in block_spans
                if '8233 or W-4' in span.get('text', '')
            ), line_entry)
        if 'W-8IMY' in line:
            payload['labels']['W-8IMY'] = line_entry

print(json.dumps(payload))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, PDF_PATH], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw);
}

async function waitForPromotedTargets(page) {
    await page.waitForFunction(
        () => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const normalizeLeaderText = (value) => String(value || '').replace(/\s+/g, '').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            let hasHeader = false;
            let hasLine5 = false;
            let hasLeader = false;
            const labelTargets = new Set(['W-8BEN-E', 'W-9', 'W-8ECI', '8233 or W-4', 'W-8IMY']);

            promotedAnnotations.forEach((annotation) => {
                const text = normalizeText(annotation.text || annotation.element?.innerText || '');
                if (text === 'Part I Identification of Beneficial Owner (see instructions)') {
                    hasHeader = true;
                }
                if (text === '5 U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)') {
                    hasLine5 = true;
                }
                const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
                Array.from(textEl.querySelectorAll('.annotation-exact-line')).forEach((line) => {
                    const lineText = normalizeText(line.textContent || '');
                    const compact = normalizeLeaderText(line.textContent || '');
                    if (compact && /^[.]+$/.test(compact) && compact.length >= 12) {
                        hasLeader = true;
                    }
                    labelTargets.forEach((label) => {
                        if (lineText.includes(label)) {
                            labelTargets.delete(label);
                        }
                    });
                });
            });

            return hasHeader && hasLine5 && hasLeader && labelTargets.size === 0;
        },
        { timeout: 90000 }
    );
}

async function collectLayoutState(page) {
    return page.evaluate(() => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const normalizeLeaderText = (value) => String(value || '').replace(/\s+/g, '').trim();
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

        const allLines = [];
        const allSpans = [];
        const promotedEntries = promotedAnnotations.map((annotation) => {
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const lines = Array.from(textEl.querySelectorAll('.annotation-exact-line')).map((line) => {
                const payload = {
                    text: normalizeText(line.textContent || ''),
                    compact: normalizeLeaderText(line.textContent || ''),
                    rect: relativeRect(line),
                };
                allLines.push(payload);
                return payload;
            });
            const spans = Array.from(textEl.querySelectorAll('.annotation-exact-span'))
                .filter((span) => span instanceof HTMLElement && span.querySelector('.annotation-exact-span') === null)
                .map((span) => {
                    const payload = {
                        text: normalizeText(span.textContent || ''),
                        rect: relativeRect(span),
                    };
                    allSpans.push(payload);
                    return payload;
                });

            return {
                text: normalizeText(textEl.textContent || annotation.text || ''),
                rect: relativeRect(annotation.element),
                exact: textEl.dataset.exactPromotedGeometry === '1',
                lines,
                spans,
            };
        });

        return { annotations: promotedEntries, allLines, allSpans };
    });
}

function buildExpectedBox(entry, scale) {
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

function findByText(entries, expectedText) {
    const normalizedExpected = normalize(expectedText);
    const compactExpected = normalizeCompact(expectedText);
    return (Array.isArray(entries) ? entries : []).find((entry) => {
        const entryText = entry?.text || '';
        return normalize(entryText) === normalizedExpected
            || normalizeCompact(entryText) === compactExpected;
    }) || null;
}

function findContainingText(entries, expectedText) {
    const normalizedExpected = normalize(expectedText);
    const compactExpected = normalizeCompact(expectedText);
    return (Array.isArray(entries) ? entries : []).find((entry) => {
        const entryText = entry?.text || '';
        return normalize(entryText).includes(normalizedExpected)
            || normalizeCompact(entryText).includes(compactExpected);
    }) || null;
}

function findClosestContainingText(entries, expectedText, expectedBox) {
    const normalizedExpected = normalize(expectedText);
    const compactExpected = normalizeCompact(expectedText);
    const matches = (Array.isArray(entries) ? entries : []).filter((entry) => {
        const entryText = entry?.text || '';
        return normalize(entryText).includes(normalizedExpected)
            || normalizeCompact(entryText).includes(compactExpected);
    });
    if (!matches.length) {
        return null;
    }
    if (!expectedBox) {
        return matches[0];
    }
    return matches.slice().sort((leftEntry, rightEntry) => {
        const leftDelta = Math.abs((Number(leftEntry?.rect?.left) || 0) - Number(expectedBox.left || 0))
            + Math.abs((Number(leftEntry?.rect?.top) || 0) - Number(expectedBox.top || 0));
        const rightDelta = Math.abs((Number(rightEntry?.rect?.left) || 0) - Number(expectedBox.left || 0))
            + Math.abs((Number(rightEntry?.rect?.top) || 0) - Number(expectedBox.top || 0));
        return leftDelta - rightDelta;
    })[0] || null;
}

function findLeaderLine(entries, expectedBox = null) {
    const matches = (Array.isArray(entries) ? entries : []).filter((entry) => {
        const compact = String(entry?.compact || '');
        return compact.length >= 12 && /^[.]+$/.test(compact);
    });
    if (!matches.length) {
        return null;
    }
    if (!expectedBox) {
        return matches[0];
    }
    return matches.slice().sort((leftEntry, rightEntry) => {
        const leftDelta = Math.abs((Number(leftEntry?.rect?.left) || 0) - Number(expectedBox.left || 0))
            + Math.abs((Number(leftEntry?.rect?.top) || 0) - Number(expectedBox.top || 0));
        const rightDelta = Math.abs((Number(rightEntry?.rect?.left) || 0) - Number(expectedBox.left || 0))
            + Math.abs((Number(rightEntry?.rect?.top) || 0) - Number(expectedBox.top || 0));
        return leftDelta - rightDelta;
    })[0] || null;
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function main() {
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_overlay_layout_${runToken}.png`);
    const reportPath = path.join(OUTPUT_DIR, `fw8ben_overlay_layout_${runToken}.json`);
    const expected = loadExpectedGeometry();

    if (!expected.header || !expected.line5 || !expected.leader) {
        throw new Error('Missing expected fw8ben overlay geometry targets from extractor output.');
    }

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT });
    const page = await context.newPage();

    let documentId = null;

    try {
        documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForPromotedTargets(page);
        await page.waitForTimeout(1500);

        const actual = await collectLayoutState(page);
        await capturePageScreenshot(page, screenshotPath);

        const headerAnnotation = findByText(actual.annotations, expected.header.text);
        if (!headerAnnotation) {
            throw new Error(`Could not locate promoted header annotation: ${expected.header.text}`);
        }

        const scale = Number(headerAnnotation.rect.width || 0) / Math.max(1, Number(expected.header.width || 0));
        const checks = [];
        const failures = [];

        checks.push({
            item: 'header_exact_geometry',
            pass: headerAnnotation.exact === true,
            detail: { exactGeometry: headerAnnotation.exact },
        });
        if (headerAnnotation.exact !== true) {
            failures.push('header_exact_geometry');
        }

        const expectedHeaderBox = buildExpectedBox(expected.header, scale);
        checks.push(runToleranceCheck('header_left', headerAnnotation.rect.left, expectedHeaderBox.left, POSITION_TOLERANCE_PX));
        checks.push(runToleranceCheck('header_top', headerAnnotation.rect.top, expectedHeaderBox.top, POSITION_TOLERANCE_PX));

        const expectedHeaderSpans = {
            part: findByText(expected.header.spans, 'Part I'),
            title: findByText(expected.header.spans, 'Identification of Beneficial Owner'),
            note: findByText(expected.header.spans, '(see instructions)'),
        };
        const actualHeaderSpans = {
            part: findByText(actual.allSpans, 'Part I'),
            title: findByText(actual.allSpans, 'Identification of Beneficial Owner'),
            note: findByText(actual.allSpans, '(see instructions)'),
        };

        Object.entries(expectedHeaderSpans).forEach(([key, expectedSpan]) => {
            const actualSpan = actualHeaderSpans[key];
            if (!expectedSpan || !actualSpan) {
                checks.push({
                    item: `header_${key}_present`,
                    pass: false,
                    detail: {
                        expected: Boolean(expectedSpan),
                        actual: Boolean(actualSpan),
                    },
                });
                failures.push(`header_${key}_present`);
                return;
            }
            const expectedBox = buildExpectedBox(expectedSpan, scale);
            checks.push(runToleranceCheck(`header_${key}_left`, actualSpan.rect.left, expectedBox.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`header_${key}_top`, actualSpan.rect.top, expectedBox.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`header_${key}_width`, actualSpan.rect.width, expectedBox.width, SIZE_TOLERANCE_PX));
        });

        if (expectedHeaderSpans.part && expectedHeaderSpans.title && actualHeaderSpans.part && actualHeaderSpans.title) {
            const expectedGap = (expectedHeaderSpans.title.left - (expectedHeaderSpans.part.left + expectedHeaderSpans.part.width)) * scale;
            const actualGap = actualHeaderSpans.title.rect.left - (actualHeaderSpans.part.rect.left + actualHeaderSpans.part.rect.width);
            checks.push(runToleranceCheck('header_part_to_title_gap', actualGap, expectedGap, HEADER_GAP_TOLERANCE_PX));
        }

        const line5Annotation = findByText(actual.annotations, expected.line5.text);
        if (!line5Annotation) {
            throw new Error(`Could not locate promoted line 5 annotation: ${expected.line5.text}`);
        }
        checks.push({
            item: 'line5_exact_geometry',
            pass: line5Annotation.exact === true,
            detail: { exactGeometry: line5Annotation.exact },
        });
        if (line5Annotation.exact !== true) {
            failures.push('line5_exact_geometry');
        }

        const expectedLine5Spans = {
            number: findByText(expected.line5.spans, '5'),
            label: findByText(expected.line5.spans, 'U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)'),
        };
        const actualLine5Spans = {
            number: findByText(actual.allSpans, '5'),
            label: findByText(actual.allSpans, 'U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)'),
        };
        Object.entries(expectedLine5Spans).forEach(([key, expectedSpan]) => {
            const actualSpan = actualLine5Spans[key];
            if (!expectedSpan || !actualSpan) {
                checks.push({
                    item: `line5_${key}_present`,
                    pass: false,
                    detail: {
                        expected: Boolean(expectedSpan),
                        actual: Boolean(actualSpan),
                    },
                });
                failures.push(`line5_${key}_present`);
                return;
            }
            const expectedBox = buildExpectedBox(expectedSpan, scale);
            checks.push(runToleranceCheck(`line5_${key}_left`, actualSpan.rect.left, expectedBox.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`line5_${key}_top`, actualSpan.rect.top, expectedBox.top, POSITION_TOLERANCE_PX));
        });
        if (expectedLine5Spans.number && expectedLine5Spans.label && actualLine5Spans.number && actualLine5Spans.label) {
            const expectedGap = (expectedLine5Spans.label.left - (expectedLine5Spans.number.left + expectedLine5Spans.number.width)) * scale;
            const actualGap = actualLine5Spans.label.rect.left - (actualLine5Spans.number.rect.left + actualLine5Spans.number.rect.width);
            checks.push(runToleranceCheck('line5_gap', actualGap, expectedGap, POSITION_TOLERANCE_PX));
        }

        const expectedLeaderBox = buildExpectedBox(expected.leader, scale);
        const actualLeader = findLeaderLine(actual.allLines, expectedLeaderBox);
        if (!actualLeader) {
            checks.push({ item: 'leader_present', pass: false, detail: {} });
            failures.push('leader_present');
        } else {
            checks.push(runToleranceCheck('leader_left', actualLeader.rect.left, expectedLeaderBox.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck('leader_top', actualLeader.rect.top, expectedLeaderBox.top, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck('leader_width', actualLeader.rect.width, expectedLeaderBox.width, SIZE_TOLERANCE_PX * 1.5));
        }

        ['W-8BEN-E', 'W-9', 'W-8ECI', '8233 or W-4', 'W-8IMY'].forEach((label) => {
            const expectedEntry = expected.labels[label];
            const expectedLabelBox = expectedEntry ? buildExpectedBox(expectedEntry, scale) : null;
            const actualEntry = findClosestContainingText(actual.allLines, label, expectedLabelBox);
            if (!expectedEntry || !actualEntry) {
                checks.push({
                    item: `label_${label}_present`,
                    pass: false,
                    detail: {
                        expected: Boolean(expectedEntry),
                        actual: Boolean(actualEntry),
                    },
                });
                failures.push(`label_${label}_present`);
                return;
            }
            checks.push(runToleranceCheck(`label_${label}_left`, actualEntry.rect.left, expectedLabelBox.left, POSITION_TOLERANCE_PX));
            checks.push(runToleranceCheck(`label_${label}_top`, actualEntry.rect.top, expectedLabelBox.top, POSITION_TOLERANCE_PX));
        });

        checks.forEach((check) => {
            if (!check.pass) {
                failures.push(check.item);
            }
        });

        const result = {
            status: failures.length === 0 ? 'pass' : 'fail',
            document_id: documentId,
            source_pdf_path: PDF_PATH,
            scale: round(scale),
            expected,
            screenshot_path: screenshotPath,
            checks,
            report_path: reportPath,
        };
        fs.writeFileSync(reportPath, `${JSON.stringify(result, null, 2)}\n`);

        if (result.status !== 'pass') {
            throw new Error(`fw8ben overlay layout regression failed.\nreport=${reportPath}\nscreenshot=${screenshotPath}\n${failures.join('\n')}`);
        }

        console.log('fw8ben overlay layout regression passed');
        console.log(JSON.stringify(result, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
