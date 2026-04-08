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
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'ss-5.pdf');
const TRAINING_DIR = path.resolve(__dirname, '..', '..', 'public', 'overlay_training', 'ss-5');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };
const DEFAULT_PADDING = 16;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function parseArgs(argv) {
    const parsed = {
        page: null,
        annotationIndex: null,
        listAnnotations: false,
        isolateAnnotation: false,
        padding: DEFAULT_PADDING,
        failOnDiff: true,
    };

    for (let index = 0; index < argv.length; index += 1) {
        const arg = argv[index];
        if (arg === '--page' && argv[index + 1]) {
            parsed.page = Number(argv[++index]);
        } else if (arg === '--annotation-index' && argv[index + 1]) {
            parsed.annotationIndex = Number(argv[++index]);
            parsed.isolateAnnotation = true;
        } else if (arg === '--list-annotations') {
            parsed.listAnnotations = true;
        } else if (arg === '--isolate-annotation') {
            parsed.isolateAnnotation = true;
        } else if (arg === '--padding' && argv[index + 1]) {
            parsed.padding = Math.max(0, Number(argv[++index]) || DEFAULT_PADDING);
        } else if (arg === '--no-fail-on-diff') {
            parsed.failOnDiff = false;
        }
    }

    return parsed;
}

function getTrainingPages() {
    return fs.readdirSync(TRAINING_DIR)
        .map((fileName) => {
            const match = fileName.match(/^ss_page_(\d+)_overlay\.png$/);
            if (!match) {
                return null;
            }
            return {
                pageNumber: Number(match[1]),
                imagePath: path.join(TRAINING_DIR, fileName),
            };
        })
        .filter(Boolean)
        .sort((left, right) => left.pageNumber - right.pageNumber);
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

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', {
        timeout: 90000,
    });
    await page.waitForTimeout(1500);
}

async function enableEditTextMode(page) {
    await page.evaluate(() => {
        const viewer = document.getElementById('viewer');
        if (viewer?.classList.contains('edit-text-mode')) {
            return;
        }
        const button = document.getElementById('mode-edit-text');
        if (!button) {
            throw new Error('Could not find Edit Text button.');
        }
        button.click();
    });

    await page.waitForFunction(() => document.getElementById('viewer')?.classList.contains('edit-text-mode') === true, {
        timeout: 30000,
    });
    await page.waitForTimeout(1200);
}

async function scrollPageIntoView(page, pageNumber) {
    const selector = `.page-wrapper[data-page-number="${pageNumber}"], .page[data-page-index="${pageNumber - 1}"]`;
    const locator = page.locator(selector).first();
    await locator.waitFor({ state: 'attached', timeout: 90000 });
    await locator.scrollIntoViewIfNeeded();
    await page.waitForTimeout(600);
    return locator;
}

async function collectPageAnnotationState(page, pageNumber) {
    return page.evaluate((targetPageNumber) => {
        const normalize = (value) => String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim();
        const selector = `.page-wrapper[data-page-number="${targetPageNumber}"], .page[data-page-index="${targetPageNumber - 1}"]`;
        const pageNode = document.querySelector(selector);
        if (!pageNode) {
            return {
                pageFound: false,
                annotations: [],
            };
        }

        const pageRect = pageNode.getBoundingClientRect();
        const annotations = Array.from(pageNode.querySelectorAll('.annotation.edit-mode-region')).map((element, index) => {
            const rect = element.getBoundingClientRect();
            return {
                index,
                annotationId: String(element.dataset.annotationId || element.dataset.id || ''),
                className: element.className,
                text: String(element.innerText || element.textContent || ''),
                normalizedText: normalize(element.innerText || element.textContent || ''),
                left: rect.left - pageRect.left,
                top: rect.top - pageRect.top,
                width: rect.width,
                height: rect.height,
            };
        });

        return {
            pageFound: true,
            pageWidth: pageRect.width,
            pageHeight: pageRect.height,
            annotations,
        };
    }, pageNumber);
}

async function setPageAnnotationIsolation(page, pageNumber, annotationIndex = null) {
    return page.evaluate(({ targetPageNumber, targetAnnotationIndex }) => {
        const selector = `.page-wrapper[data-page-number="${targetPageNumber}"], .page[data-page-index="${targetPageNumber - 1}"]`;
        const pageNode = document.querySelector(selector);
        if (!pageNode) {
            throw new Error(`Missing page node for page ${targetPageNumber}`);
        }

        Array.from(pageNode.querySelectorAll('.annotation.edit-mode-region')).forEach((element, index) => {
            if (targetAnnotationIndex == null) {
                element.style.removeProperty('visibility');
                element.style.removeProperty('opacity');
                return;
            }
            if (index === targetAnnotationIndex) {
                element.style.removeProperty('visibility');
                element.style.removeProperty('opacity');
            } else {
                element.style.visibility = 'hidden';
                element.style.opacity = '0';
            }
        });
    }, {
        targetPageNumber: pageNumber,
        targetAnnotationIndex: annotationIndex,
    });
}

async function screenshotPageLocator(pageLocator, outputPath) {
    await pageLocator.screenshot({ path: outputPath });
}

function compareImages({
    actualPath,
    expectedPath,
    diffPath,
    actualCropPath = '',
    expectedCropPath = '',
    crop = null,
}) {
    const pythonCode = `
import json
import sys
from PIL import Image, ImageChops, ImageStat

actual_path, expected_path, diff_path, actual_crop_path, expected_crop_path, crop_json = sys.argv[1:7]
crop = json.loads(crop_json)

actual = Image.open(actual_path).convert('RGBA')
expected = Image.open(expected_path).convert('RGBA')
if actual.size != expected.size:
    actual = actual.resize(expected.size, Image.Resampling.LANCZOS)

if crop:
    x = int(max(0, crop.get('x', 0)))
    y = int(max(0, crop.get('y', 0)))
    w = int(max(1, crop.get('width', expected.width)))
    h = int(max(1, crop.get('height', expected.height)))
    x2 = min(expected.width, x + w)
    y2 = min(expected.height, y + h)
    region = (x, y, x2, y2)
    actual = actual.crop(region)
    expected = expected.crop(region)
    if actual_crop_path:
        actual.save(actual_crop_path)
    if expected_crop_path:
        expected.save(expected_crop_path)

diff = ImageChops.difference(actual, expected)
diff.save(diff_path)
stat = ImageStat.Stat(diff)
mean_diff = sum(stat.mean) / len(stat.mean)
non_zero_pixels = 0
for pixel in diff.getdata():
    if any(channel != 0 for channel in pixel):
        non_zero_pixels += 1
total_pixels = max(1, diff.width * diff.height)
print(json.dumps({
    'size': list(actual.size),
    'mean_diff': mean_diff,
    'non_zero_pixels': non_zero_pixels,
    'non_zero_ratio': non_zero_pixels / float(total_pixels),
    'bbox': diff.getbbox(),
}))
`;

    return JSON.parse(execFileSync('python3', [
        '-c',
        pythonCode,
        actualPath,
        expectedPath,
        diffPath,
        actualCropPath,
        expectedCropPath,
        JSON.stringify(crop || null),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim());
}

function buildAnnotationCrop(annotation, pageState, padding) {
    const maxWidth = Math.max(1, Math.round(pageState.pageWidth || 0));
    const maxHeight = Math.max(1, Math.round(pageState.pageHeight || 0));
    const left = Math.max(0, Math.floor(annotation.left - padding));
    const top = Math.max(0, Math.floor(annotation.top - padding));
    const right = Math.min(maxWidth, Math.ceil(annotation.left + annotation.width + padding));
    const bottom = Math.min(maxHeight, Math.ceil(annotation.top + annotation.height + padding));

    return {
        x: left,
        y: top,
        width: Math.max(1, right - left),
        height: Math.max(1, bottom - top),
    };
}

async function runPageComparison(page, {
    pageNumber,
    referencePath,
    runToken,
    annotationIndex = null,
    isolateAnnotation = false,
    padding = DEFAULT_PADDING,
    listAnnotations = false,
}) {
    const pageLocator = await scrollPageIntoView(page, pageNumber);
    const pageState = await collectPageAnnotationState(page, pageNumber);
    if (!pageState.pageFound) {
        throw new Error(`Page ${pageNumber} was not found in the editor.`);
    }

    if (listAnnotations) {
        console.log(JSON.stringify({
            pageNumber,
            annotations: pageState.annotations,
        }, null, 2));
    }

    if (annotationIndex != null && (!Number.isInteger(annotationIndex) || annotationIndex < 0 || annotationIndex >= pageState.annotations.length)) {
        throw new Error(`Annotation index ${annotationIndex} is out of range for page ${pageNumber} (count=${pageState.annotations.length}).`);
    }

    await setPageAnnotationIsolation(page, pageNumber, isolateAnnotation ? annotationIndex : null);
    await page.waitForTimeout(300);

    const pageStem = `ss5_edit_text_page_${pageNumber}`;
    const suffix = annotationIndex == null ? 'full_page' : `annotation_${String(annotationIndex).padStart(3, '0')}`;
    const actualPath = path.join(OUTPUT_DIR, `${pageStem}_${runToken}_${suffix}_actual.png`);
    const diffPath = path.join(OUTPUT_DIR, `${pageStem}_${runToken}_${suffix}_diff.png`);
    const actualCropPath = path.join(OUTPUT_DIR, `${pageStem}_${runToken}_${suffix}_actual_crop.png`);
    const expectedCropPath = path.join(OUTPUT_DIR, `${pageStem}_${runToken}_${suffix}_expected_crop.png`);

    await screenshotPageLocator(pageLocator, actualPath);

    const crop = annotationIndex == null
        ? null
        : buildAnnotationCrop(pageState.annotations[annotationIndex], pageState, padding);

    const comparison = compareImages({
        actualPath,
        expectedPath: referencePath,
        diffPath,
        actualCropPath,
        expectedCropPath,
        crop,
    });

    await setPageAnnotationIsolation(page, pageNumber, null);

    return {
        pageNumber,
        referencePath,
        actualPath,
        diffPath,
        actualCropPath,
        expectedCropPath,
        comparison,
        annotationIndex,
        annotation: annotationIndex == null ? null : pageState.annotations[annotationIndex],
        annotationCount: pageState.annotations.length,
    };
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const args = parseArgs(process.argv.slice(2));
    const trainingPages = getTrainingPages();
    const targetPages = args.page == null
        ? trainingPages
        : trainingPages.filter((entry) => entry.pageNumber === args.page);

    if (!targetPages.length) {
        throw new Error(`No training overlays found for page ${args.page == null ? '(all pages)' : args.page}.`);
    }

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await uploadPdf(page);
        await waitForEditorReady(page);
        await forceRefreshOverlay(page, documentId);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await enableEditTextMode(page);

        const results = [];
        for (const targetPage of targetPages) {
            results.push(await runPageComparison(page, {
                pageNumber: targetPage.pageNumber,
                referencePath: targetPage.imagePath,
                runToken,
                annotationIndex: args.annotationIndex,
                isolateAnnotation: args.isolateAnnotation,
                padding: args.padding,
                listAnnotations: args.listAnnotations,
            }));
        }

        const summary = {
            documentId,
            runToken,
            results: results.map((result) => ({
                pageNumber: result.pageNumber,
                annotationIndex: result.annotationIndex,
                annotationCount: result.annotationCount,
                comparison: result.comparison,
                actualPath: result.actualPath,
                diffPath: result.diffPath,
                actualCropPath: result.annotationIndex == null ? null : result.actualCropPath,
                expectedCropPath: result.annotationIndex == null ? null : result.expectedCropPath,
                annotation: result.annotation == null ? null : {
                    index: result.annotation.index,
                    annotationId: result.annotation.annotationId,
                    normalizedText: result.annotation.normalizedText,
                    left: result.annotation.left,
                    top: result.annotation.top,
                    width: result.annotation.width,
                    height: result.annotation.height,
                },
            })),
        };

        const hasAnyDiff = results.some((result) => result.comparison.non_zero_pixels > 0);
        console.log(JSON.stringify(summary, null, 2));

        if (hasAnyDiff && args.failOnDiff) {
            throw new Error('ss-5 edit-text outline comparison found visual differences');
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
