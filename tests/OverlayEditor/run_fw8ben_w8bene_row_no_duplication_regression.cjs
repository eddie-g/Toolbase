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

const LEFT_FRAGMENT = 'You are NOT an individual';
const RIGHT_FRAGMENT = 'W-8BEN-E';
const POSITION_TOLERANCE_PX = 5;
const KNOWN_DUPLICATED_ARTIFACT_MULTILINE_TEXT =
    `• You are NOT an individual . . W-8BEN-E
. . . . . . . . . . . . . . . . . . . . . . . . . . . . . . .
. W-8BEN-E`;
const KNOWN_DUPLICATED_ARTIFACT_TEXT = normalize(KNOWN_DUPLICATED_ARTIFACT_MULTILINE_TEXT);

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
target_block = None
for block in page.get('blocks', []):
    text = normalize(block.get('text') or '')
    if 'You are NOT an individual' in text and 'W-8BEN-E' in text:
        target_block = block
        break

if target_block is None:
    raise SystemExit('Could not find target fw8ben row block')

label_span = None
for span in target_block.get('spans') or []:
    if 'W-8BEN-E' in str(span.get('text') or ''):
        label_span = span
        break

if label_span is None:
    raise SystemExit('Could not find W-8BEN-E span in target block')

block_text = normalize(target_block.get('text') or '')
block_bbox = {
    'left': float(target_block.get('left') or 0.0),
    'top': float(target_block.get('top') or 0.0),
    'width': float(target_block.get('width') or 0.0),
    'height': float(target_block.get('height') or 0.0),
}
span_bbox = label_span.get('bbox') or [0, 0, 0, 0]

print(json.dumps({
    'block_text': block_text,
    'block_bbox': block_bbox,
    'label_span': {
        'text': normalize(label_span.get('text') or ''),
        'left': float(span_bbox[0] or 0.0),
        'top': float(span_bbox[1] or 0.0),
        'width': max(0.0, float(span_bbox[2] or 0.0) - float(span_bbox[0] or 0.0)),
        'height': max(0.0, float(span_bbox[3] or 0.0) - float(span_bbox[1] or 0.0)),
    },
    'text_lines': [normalize(line) for line in target_block.get('text_lines') or []],
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
        ({ leftFragment, rightFragment }) => {
            const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
                ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
                : [];

            return promotedAnnotations.some((annotation) => {
                const text = normalizeText(annotation.text || annotation.element?.innerText || '');
                return text.includes(leftFragment) && text.includes(rightFragment);
            });
        },
        { leftFragment: LEFT_FRAGMENT, rightFragment: RIGHT_FRAGMENT },
        { timeout: 90000 }
    );
}

async function collectRowState(page) {
    return page.evaluate(({ leftFragment, rightFragment }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const countOccurrences = (haystack, needle) => {
            const source = normalizeText(haystack);
            const target = normalizeText(needle);
            if (!source || !target) return 0;
            let count = 0;
            let offset = 0;
            while (offset <= source.length) {
                const index = source.indexOf(target, offset);
                if (index === -1) break;
                count += 1;
                offset = index + target.length;
            }
            return count;
        };

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

        const targetAnnotation = pageAnnotations.find((entry) => (
            entry.text.includes(leftFragment) && entry.text.includes(rightFragment)
        )) || null;
        if (!targetAnnotation) {
            return null;
        }

        const targetBandTop = targetAnnotation.rect.top - 8;
        const targetBandBottom = targetAnnotation.rect.top + targetAnnotation.rect.height + 8;

        const exactSpans = Array.from(targetAnnotation.element.querySelectorAll('.annotation-exact-span')).map((spanEl) => ({
            text: normalizeText(spanEl.textContent || ''),
            ...relativeRect(spanEl),
        }));
        const targetLabelSpans = exactSpans.filter((span) => span.text.includes(rightFragment));

        const rowAnnotationsWithLabel = pageAnnotations.filter((entry) => (
            entry.text.includes(rightFragment)
            && entry.rect.top >= targetBandTop
            && entry.rect.top <= targetBandBottom
        )).map((entry) => ({
            text: entry.text,
            ...entry.rect,
        }));

        const rowLabelSpans = Array.from(document.querySelectorAll('.annotation-exact-span'))
            .map((spanEl) => ({
                text: normalizeText(spanEl.textContent || ''),
                ...relativeRect(spanEl),
            }))
            .filter((span) => (
                span.text.includes(rightFragment)
                && span.top >= targetBandTop
                && span.top <= targetBandBottom
            ));

        return {
            annotation: {
                text: targetAnnotation.text,
                renderedText: targetAnnotation.renderedText,
                annotationStateText: targetAnnotation.annotationStateText,
                exactGeometry: targetAnnotation.textEl.dataset.exactPromotedGeometry === '1',
                rightFragmentOccurrences: countOccurrences(targetAnnotation.text, rightFragment),
                leftFragmentOccurrences: countOccurrences(targetAnnotation.text, leftFragment),
                ...targetAnnotation.rect,
            },
            targetLabelSpans,
            rowAnnotationsWithLabel,
            rowLabelSpans,
            textLines: Array.from(targetAnnotation.element.querySelectorAll('.annotation-exact-line')).map((lineEl) => ({
                text: normalizeText(lineEl.textContent || ''),
                ...relativeRect(lineEl),
            })),
        };
    }, { leftFragment: LEFT_FRAGMENT, rightFragment: RIGHT_FRAGMENT });
}

async function injectKnownDuplicatedArtifact(page) {
    const result = await page.evaluate(({ artifactText, leftFragment, rightFragment }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const promotedAnnotations = typeof annotations !== 'undefined' && Array.isArray(annotations)
            ? annotations.filter((annotation) => annotation?.promotedFromExtraction && annotation?.element)
            : [];
        const target = promotedAnnotations.find((annotation) => {
            const textEl = annotation.element.querySelector('.annotation-text') || annotation.element;
            const text = normalizeText(annotation.text || textEl.innerText || textEl.textContent || '');
            return text.includes(leftFragment) && text.includes(rightFragment);
        }) || null;
        if (!target) {
            return { found: false };
        }

        target.text = artifactText;
        target.originalText = artifactText;
        target.promotedDirty = false;
        delete target.promotedReflowEnabled;
        delete target.richTextHtml;

        if (typeof applyAnnotationStyle === 'function') {
            applyAnnotationStyle(target);
        } else if (typeof rerenderAnnotations === 'function') {
            rerenderAnnotations();
        }

        return {
            found: true,
            id: target.id || null,
            annotationStateText: normalizeText(target.text || ''),
        };
    }, {
        artifactText: KNOWN_DUPLICATED_ARTIFACT_MULTILINE_TEXT,
        leftFragment: LEFT_FRAGMENT,
        rightFragment: RIGHT_FRAGMENT,
    });

    if (!result?.found) {
        throw new Error('Could not inject the known duplicated artifact into the fw8ben target row');
    }

    await page.waitForTimeout(250);
    return result;
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

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await forceRefreshOverlay(page, documentId);
        await clearAnnotationSessionState(page, documentId);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await waitForTargetRow(page);
        await page.waitForTimeout(1500);

        const baselineActual = await collectRowState(page);
        if (!baselineActual) {
            throw new Error('Could not locate fw8ben W-8BEN-E target row in promoted annotations');
        }

        const injectedArtifact = await injectKnownDuplicatedArtifact(page);
        const actual = await collectRowState(page);
        if (!actual) {
            throw new Error('Could not locate fw8ben W-8BEN-E target row after injecting the duplicated artifact');
        }

        const scale = Number(actual.annotation.width || 0) / Math.max(1, Number(expected.block_bbox.width || 0));
        const expectedLabelRect = buildExpectedRect(expected.label_span, scale);
        const targetLabelSpan = actual.targetLabelSpans[0] || null;

        const checks = [
            {
                item: 'baseline_row_was_present_before_artifact_injection',
                pass: Boolean(baselineActual),
                detail: {
                    renderedText: baselineActual.annotation.renderedText,
                    annotationStateText: baselineActual.annotation.annotationStateText,
                },
            },
            {
                item: 'artifact_injection_used_known_duplicate_text',
                pass: normalize(injectedArtifact.annotationStateText) === KNOWN_DUPLICATED_ARTIFACT_TEXT,
                detail: {
                    actual: injectedArtifact.annotationStateText,
                    expected: KNOWN_DUPLICATED_ARTIFACT_TEXT,
                },
            },
            {
                item: 'target_annotation_uses_exact_geometry',
                pass: actual.annotation.exactGeometry === true,
                detail: { exactGeometry: actual.annotation.exactGeometry },
            },
            {
                item: 'target_annotation_contains_single_left_fragment',
                pass: actual.annotation.leftFragmentOccurrences === 1,
                detail: { occurrences: actual.annotation.leftFragmentOccurrences, text: actual.annotation.renderedText },
            },
            {
                item: 'target_annotation_contains_single_w8bene',
                pass: actual.annotation.rightFragmentOccurrences === 1,
                detail: { occurrences: actual.annotation.rightFragmentOccurrences, text: actual.annotation.renderedText },
            },
            {
                item: 'target_annotation_text_matches_expected_block_text',
                pass: normalize(actual.annotation.renderedText) === normalize(expected.block_text),
                detail: {
                    actual: actual.annotation.renderedText,
                    expected: expected.block_text,
                },
            },
            {
                item: 'target_annotation_text_is_not_known_duplicate_artifact',
                pass: normalize(actual.annotation.renderedText) !== KNOWN_DUPLICATED_ARTIFACT_TEXT,
                detail: {
                    actual: actual.annotation.renderedText,
                    artifact: KNOWN_DUPLICATED_ARTIFACT_TEXT,
                },
            },
            {
                item: 'target_row_has_single_label_span',
                pass: actual.targetLabelSpans.length === 1,
                detail: { count: actual.targetLabelSpans.length, spans: actual.targetLabelSpans },
            },
            {
                item: 'page_row_has_single_w8bene_span',
                pass: actual.rowLabelSpans.length === 1,
                detail: { count: actual.rowLabelSpans.length, spans: actual.rowLabelSpans },
            },
            {
                item: 'page_row_has_single_annotation_with_w8bene',
                pass: actual.rowAnnotationsWithLabel.length === 1,
                detail: { count: actual.rowAnnotationsWithLabel.length, annotations: actual.rowAnnotationsWithLabel },
            },
            {
                item: 'target_row_line_texts_match_expected_source_lines',
                pass: JSON.stringify(actual.textLines.map((line) => normalizeDotLeaderLine(line.text))) === JSON.stringify(expected.text_lines.map((line) => normalizeDotLeaderLine(line))),
                detail: {
                    actual: actual.textLines.map((line) => line.text),
                    expected: expected.text_lines,
                },
            },
            {
                item: 'target_row_first_line_does_not_contain_w8bene',
                pass: !normalize(actual.textLines[0]?.text || '').includes(RIGHT_FRAGMENT),
                detail: {
                    firstLine: actual.textLines[0]?.text || '',
                },
            },
            {
                item: 'target_row_last_line_is_w8bene_label',
                pass: normalize(actual.textLines[actual.textLines.length - 1]?.text || '') === normalize(expected.text_lines[expected.text_lines.length - 1] || ''),
                detail: {
                    actual: actual.textLines[actual.textLines.length - 1]?.text || '',
                    expected: expected.text_lines[expected.text_lines.length - 1] || '',
                },
            },
        ];

        if (targetLabelSpan) {
            checks.push({
                item: 'target_label_left_matches_expected_row',
                pass: Math.abs(targetLabelSpan.left - expectedLabelRect.left) <= POSITION_TOLERANCE_PX,
                detail: {
                    actual: round(targetLabelSpan.left),
                    expected: round(expectedLabelRect.left),
                    delta: round(Math.abs(targetLabelSpan.left - expectedLabelRect.left)),
                },
            });
            checks.push({
                item: 'target_label_top_matches_expected_row',
                pass: Math.abs(targetLabelSpan.top - expectedLabelRect.top) <= POSITION_TOLERANCE_PX,
                detail: {
                    actual: round(targetLabelSpan.top),
                    expected: round(expectedLabelRect.top),
                    delta: round(Math.abs(targetLabelSpan.top - expectedLabelRect.top)),
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
