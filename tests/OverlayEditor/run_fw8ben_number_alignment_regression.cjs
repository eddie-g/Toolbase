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

const POSITION_TOLERANCE_PX = 4.5;
const SIZE_TOLERANCE_PX = 5.5;

const TARGETS = [
    {
        key: 'line_1_name',
        expectedText: '1 Name of individual who is the beneficial owner',
        segments: [
            { key: 'number', text: '1' },
            { key: 'label', text: 'Name of individual who is the beneficial owner' },
        ],
    },
    {
        key: 'line_3_address',
        expectedText: '3 Permanent residence address (street, apt. or suite no., or rural route). Do not use a P.O. box or in-care-of address.',
        segments: [
            { key: 'number', text: '3' },
            { key: 'label', text: 'Permanent residence address (street, apt. or suite no., or rural route).' },
        ],
    },
    {
        key: 'line_4_mailing',
        expectedText: '4 Mailing address (if different from above)',
        segments: [
            { key: 'number', text: '4' },
            { key: 'label', text: 'Mailing address (if different from above)' },
        ],
    },
    {
        key: 'line_5_tin',
        expectedText: '5 U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)',
        segments: [
            { key: 'number', text: '5' },
            { key: 'label', text: 'U.S. taxpayer identification number (SSN or ITIN), if required (see instructions)' },
        ],
    },
    {
        key: 'line_6a_ftin',
        expectedText: '6a Foreign tax identifying number (see instructions)',
        segments: [
            { key: 'number', text: '6a' },
            { key: 'label', text: 'Foreign tax identifying number (see instructions)' },
        ],
    },
    {
        key: 'line_7_reference',
        expectedText: '7 Reference number(s) (see instructions)',
        segments: [
            { key: 'number', text: '7' },
            { key: 'label', text: 'Reference number(s) (see instructions)' },
        ],
    },
];

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

function loadExpectedGeometry() {
    const pythonCode = `
import contextlib
import importlib.util
import io
import json
import sys
from pathlib import Path

pdf_path = Path(sys.argv[1])
targets = json.loads(sys.argv[2])
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def normalize(value):
    return ' '.join(str(value or '').split())

with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))

page = result['extraction_data'][0]
resolved = {}
target_map = {entry['expectedText']: entry for entry in targets}

for block in page.get('blocks', []):
    text = normalize(block.get('text') or '')
    target = target_map.get(text)
    if not target:
        continue

    line_bbox = None
    for bbox in block.get('line_bboxes') or []:
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
            line_bbox = [float(value or 0.0) for value in bbox]
            break
    if line_bbox is None:
        line_bbox = [
            float(block.get('left') or 0.0),
            float(block.get('top') or 0.0),
            float((block.get('left') or 0.0) + (block.get('width') or 0.0)),
            float((block.get('top') or 0.0) + (block.get('height') or 0.0)),
        ]

    spans = []
    for span in block.get('spans') or []:
        bbox = span.get('bbox') or [0, 0, 0, 0]
        spans.append({
            'text': normalize(span.get('render_text') or span.get('text') or ''),
            'left': float(bbox[0] or 0.0),
            'top': float(bbox[1] or 0.0),
            'width': max(0.0, float(bbox[2] or 0.0) - float(bbox[0] or 0.0)),
            'height': max(0.0, float(bbox[3] or 0.0) - float(bbox[1] or 0.0)),
        })

    resolved[target['key']] = {
        'text': text,
        'left': float(line_bbox[0] or 0.0),
        'top': float(line_bbox[1] or 0.0),
        'width': max(0.0, float(line_bbox[2] or 0.0) - float(line_bbox[0] or 0.0)),
        'height': max(0.0, float(line_bbox[3] or 0.0) - float(line_bbox[1] or 0.0)),
        'spans': spans,
    }

print(json.dumps(resolved))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, PDF_PATH, JSON.stringify(TARGETS)], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(raw);
}

async function waitForPromotedTargets(page) {
    await page.waitForFunction(
        (expectedTexts) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];
            return expectedTexts.every((expectedText) => {
                const match = promotedAnnotations.find((annotation) => (
                    normalizeText(annotation.text || annotation.element?.innerText || '') === expectedText
                ));
                if (!match?.element) {
                    return false;
                }
                const textEl = match.element.querySelector('.annotation-text') || match.element;
                return textEl.dataset.exactPromotedGeometry === '1'
                    && match.element.querySelectorAll('.annotation-exact-span').length >= 2;
            });
        },
        TARGETS.map((target) => target.expectedText),
        { timeout: 90000 }
    );
}

async function collectPromotedTargets(page) {
    return page.evaluate((targets) => {
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

        return targets.map((target) => {
            const annotation = promotedAnnotations.find((candidate) => (
                normalizeText(candidate.text || candidate.element?.innerText || '') === target.expectedText
            ));
            if (!annotation?.element) {
                return null;
            }
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const leafSpans = Array.from(annotation.element.querySelectorAll('.annotation-exact-span'))
                .filter((span) => span instanceof HTMLElement && span.querySelector('.annotation-exact-span') === null)
                .map((span) => ({
                    text: normalizeText(span.textContent || ''),
                    ...relativeRect(span),
                }))
                .filter((span) => span.text.length > 0);

            return {
                key: target.key,
                text: normalizeText(textEl.innerText || textEl.textContent || ''),
                exactGeometry: textEl.dataset.exactPromotedGeometry === '1',
                ...relativeRect(annotation.element),
                spans: leafSpans,
            };
        }).filter(Boolean);
    }, TARGETS);
}

function findTarget(targets, key) {
    return targets.find((target) => target.key === key) || null;
}

function findSegment(segments, expectedText) {
    const normalizedExpected = normalize(expectedText);
    return segments.find((segment) => normalize(segment.text) === normalizedExpected) || null;
}

function buildExpectedBox(entry, scale) {
    return {
        left: Number(entry.left || 0) * scale,
        top: Number(entry.top || 0) * scale,
        width: Number(entry.width || 0) * scale,
        height: Number(entry.height || 0) * scale,
    };
}

function buildExpectedGap(leftEntry, rightEntry, scale) {
    const leftRight = (Number(leftEntry.left || 0) + Number(leftEntry.width || 0)) * scale;
    const rightLeft = Number(rightEntry.left || 0) * scale;
    return rightLeft - leftRight;
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

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function main() {
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `fw8ben_number_alignment_${runToken}.png`);
    const reportPath = path.join(OUTPUT_DIR, `fw8ben_number_alignment_${runToken}.json`);

    const expectedGeometry = loadExpectedGeometry();
    const missingExpected = TARGETS.filter((target) => !expectedGeometry[target.key]);
    if (missingExpected.length > 0) {
        throw new Error(`Missing expected geometry targets: ${missingExpected.map((target) => target.key).join(', ')}`);
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

        const promotedTargets = await collectPromotedTargets(page);
        await capturePageScreenshot(page, screenshotPath);

        const firstActualTarget = promotedTargets[0];
        const firstExpectedTarget = expectedGeometry[firstActualTarget?.key];
        const scale = firstActualTarget && firstExpectedTarget
            ? Number(firstActualTarget.width || 0) / Math.max(1, Number(firstExpectedTarget.width || 0))
            : 1;

        const checks = [];
        const failures = [];
        const comparisons = {};

        for (const target of TARGETS) {
            const expected = expectedGeometry[target.key];
            const actualTarget = findTarget(promotedTargets, target.key);
            if (!actualTarget) {
                failures.push(`missing promoted annotation for ${target.key}: ${target.expectedText}`);
                continue;
            }

            const expectedField = buildExpectedBox(expected, scale);
            comparisons[target.key] = {
                annotation: {
                    expected: Object.fromEntries(Object.entries(expectedField).map(([key, value]) => [key, round(value)])),
                    actual: {
                        left: round(actualTarget.left),
                        top: round(actualTarget.top),
                        width: round(actualTarget.width),
                        height: round(actualTarget.height),
                    },
                },
                segments: {},
            };

            const annotationChecks = [
                {
                    item: `${target.key}_uses_exact_geometry`,
                    pass: actualTarget.exactGeometry === true,
                    detail: { exactGeometry: actualTarget.exactGeometry },
                },
                runToleranceCheck(`${target.key}_annotation_left`, actualTarget.left, expectedField.left, POSITION_TOLERANCE_PX),
                runToleranceCheck(`${target.key}_annotation_top`, actualTarget.top, expectedField.top, POSITION_TOLERANCE_PX),
                runToleranceCheck(`${target.key}_annotation_width`, actualTarget.width, expectedField.width, SIZE_TOLERANCE_PX),
                runToleranceCheck(`${target.key}_annotation_height`, actualTarget.height, expectedField.height, SIZE_TOLERANCE_PX),
            ];
            checks.push(...annotationChecks);
            failures.push(...annotationChecks.filter((check) => !check.pass).map((check) => check.item));

            const resolvedSegments = target.segments.map((segment) => {
                const expectedSegment = findSegment(expected.spans, segment.text);
                const actualSegment = findSegment(actualTarget.spans, segment.text);
                return {
                    ...segment,
                    expected: expectedSegment,
                    actual: actualSegment,
                };
            });

            for (const segment of resolvedSegments) {
                comparisons[target.key].segments[segment.key] = {
                    expected_text: segment.text,
                    expected: segment.expected ? {
                        left: round(segment.expected.left * scale),
                        top: round(segment.expected.top * scale),
                        width: round(segment.expected.width * scale),
                        height: round(segment.expected.height * scale),
                    } : null,
                    actual: segment.actual ? {
                        left: round(segment.actual.left),
                        top: round(segment.actual.top),
                        width: round(segment.actual.width),
                        height: round(segment.actual.height),
                    } : null,
                };

                if (!segment.expected) {
                    failures.push(`missing expected segment for ${target.key}:${segment.key}`);
                    checks.push({
                        item: `${target.key}_${segment.key}_expected_present`,
                        pass: false,
                        detail: { expectedText: segment.text },
                    });
                    continue;
                }
                if (!segment.actual) {
                    failures.push(`missing actual segment for ${target.key}:${segment.key}`);
                    checks.push({
                        item: `${target.key}_${segment.key}_actual_present`,
                        pass: false,
                        detail: { expectedText: segment.text, actualSegments: actualTarget.spans },
                    });
                    continue;
                }

                const expectedSegmentBox = buildExpectedBox(segment.expected, scale);
                const segmentChecks = [
                    runToleranceCheck(`${target.key}_${segment.key}_left`, segment.actual.left, expectedSegmentBox.left, POSITION_TOLERANCE_PX),
                    runToleranceCheck(`${target.key}_${segment.key}_top`, segment.actual.top, expectedSegmentBox.top, POSITION_TOLERANCE_PX),
                    runToleranceCheck(`${target.key}_${segment.key}_width`, segment.actual.width, expectedSegmentBox.width, SIZE_TOLERANCE_PX),
                    runToleranceCheck(`${target.key}_${segment.key}_height`, segment.actual.height, expectedSegmentBox.height, SIZE_TOLERANCE_PX),
                ];
                checks.push(...segmentChecks);
                failures.push(...segmentChecks.filter((check) => !check.pass).map((check) => check.item));
            }

            if (resolvedSegments.length >= 2 && resolvedSegments.every((segment) => segment.expected && segment.actual)) {
                const [leftSegment, rightSegment] = resolvedSegments;
                const expectedGap = buildExpectedGap(leftSegment.expected, rightSegment.expected, scale);
                const actualGap = Number(rightSegment.actual.left) - (Number(leftSegment.actual.left) + Number(leftSegment.actual.width));
                const gapCheck = runToleranceCheck(`${target.key}_number_to_label_gap`, actualGap, expectedGap, POSITION_TOLERANCE_PX);
                checks.push(gapCheck);
                if (!gapCheck.pass) {
                    failures.push(gapCheck.item);
                }
                comparisons[target.key].gap = {
                    expected: round(expectedGap),
                    actual: round(actualGap),
                    delta: round(Math.abs(actualGap - expectedGap)),
                };
            }
        }

        const result = {
            status: failures.length === 0 ? 'pass' : 'fail',
            document_id: documentId,
            source_pdf_path: PDF_PATH,
            scale: round(scale),
            screenshot_path: screenshotPath,
            comparisons,
            checks,
            report_path: reportPath,
        };
        fs.writeFileSync(reportPath, `${JSON.stringify(result, null, 2)}\n`);

        if (result.status !== 'pass') {
            throw new Error(`fw8ben number alignment drift detected.\nreport=${reportPath}\nscreenshot=${screenshotPath}\n${failures.join('\n')}`);
        }

        console.log('fw8ben number alignment regression passed');
        console.log(JSON.stringify(result, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
