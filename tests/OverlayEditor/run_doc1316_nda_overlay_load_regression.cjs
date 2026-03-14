#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 1316);
const ORIGINAL_PDF_PATH = process.env.ORIGINAL_PDF_PATH || path.resolve(__dirname, '..', '..', 'public', 'nda_test_1.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };

const PREAMBLE_START = 'THIS AGREEMENT';
const LEADIN_LINE = '1. Confidential and Proprietary Information. (a) Company acknowledges and agrees that in';
const ANCHOR_LINE = 'a) Confidential Information shall not include:';

const POSITION_TOLERANCE_PX = 3.5;
const SIZE_TOLERANCE_PX = 18;

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function round(value, places = 3) {
    const factor = 10 ** places;
    return Math.round((Number(value) || 0) * factor) / factor;
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

async function forceRefreshOverlay(page) {
    const response = await postJson(page, `/documents/${DOCUMENT_ID}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 30000 });
    await page.waitForTimeout(1500);
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
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 30000 });
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 30000 });
    await page.waitForTimeout(2000);
}

function loadOriginalGeometry() {
    const pythonCode = `
import importlib.util
import json
import sys
import io
import contextlib
from pathlib import Path

pdf_path = Path(sys.argv[1])
module_path = Path('python/pdf-editor/extract_pdf_pymupdf.py')
spec = importlib.util.spec_from_file_location('extract_pdf_pymupdf', module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with contextlib.redirect_stdout(io.StringIO()):
    result = module.extract_text_with_pymupdf(str(pdf_path))
page = result['extraction_data'][0]

targets = {
    'preamble': None,
    'leadin': None,
    'anchor': None,
}

for block in page.get('blocks', []):
    text = ' '.join(str(block.get('text', '')).split())
    if text.startswith(${JSON.stringify(PREAMBLE_START)}):
        targets['preamble'] = {
            'text': text,
            'left': block.get('left'),
            'top': block.get('top'),
            'width': block.get('width'),
            'height': block.get('height'),
            'line_count': block.get('line_count'),
            'text_lines': block.get('text_lines') or [],
            'line_bboxes': block.get('line_bboxes') or [],
        }
        break

for line in page.get('lines', []):
    text = ' '.join(str(line.get('text', '')).split())
    if text == ${JSON.stringify(LEADIN_LINE)}:
        targets['leadin'] = {
            'text': text,
            'left': line.get('left'),
            'top': line.get('top'),
            'width': line.get('width'),
            'height': line.get('height'),
        }
    if text == ${JSON.stringify(ANCHOR_LINE)}:
        targets['anchor'] = {
            'text': text,
            'left': line.get('left'),
            'top': line.get('top'),
            'width': line.get('width'),
            'height': line.get('height'),
        }

print(json.dumps(targets))
`;

    const raw = execFileSync('python3', ['-c', pythonCode, ORIGINAL_PDF_PATH], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw);
}

async function collectOverlayGeometry(page) {
    return page.evaluate(({ preambleStart, leadinLine, anchorLine }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        const groupLineRects = (field) => {
            const root = field.querySelector('[contenteditable]') || field;
            const range = document.createRange();
            range.selectNodeContents(root);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 1 && rect.height > 1)
                .map((rect) => ({
                    left: rect.left,
                    top: rect.top,
                    right: rect.right,
                    bottom: rect.bottom,
                    width: rect.width,
                    height: rect.height,
                }))
                .sort((a, b) => {
                    if (Math.abs(a.top - b.top) > 2) {
                        return a.top - b.top;
                    }
                    return a.left - b.left;
                });

            const groups = [];
            for (const rect of rects) {
                let group = groups.find((candidate) => {
                    const overlap = Math.min(candidate.bottom, rect.bottom) - Math.max(candidate.top, rect.top);
                    return overlap >= Math.min(candidate.bottom - candidate.top, rect.bottom - rect.top) * 0.45
                        || Math.abs(candidate.top - rect.top) <= 3;
                });
                if (!group) {
                    group = {
                        left: rect.left,
                        top: rect.top,
                        right: rect.right,
                        bottom: rect.bottom,
                    };
                    groups.push(group);
                    continue;
                }
                group.left = Math.min(group.left, rect.left);
                group.top = Math.min(group.top, rect.top);
                group.right = Math.max(group.right, rect.right);
                group.bottom = Math.max(group.bottom, rect.bottom);
            }

            const fieldRect = field.getBoundingClientRect();
            return groups
                .sort((a, b) => a.top - b.top)
                .map((group) => ({
                    left: field.offsetLeft + (group.left - fieldRect.left),
                    top: field.offsetTop + (group.top - fieldRect.top),
                    width: group.right - group.left,
                    height: group.bottom - group.top,
                }));
        };

        const pageEl = document.querySelector('.page[data-page-index="0"]') || document.querySelector('.page-wrapper[data-page-number="1"]');
        const canvas = pageEl?.querySelector('canvas');
        const overlay = pageEl?.querySelector('.overlay');
        const scale = typeof currentScale !== 'undefined' ? Number(currentScale) || null : null;

        const fields = Array.from(document.querySelectorAll('.overlay-field'))
            .map((field) => {
                const text = normalizeText(field.innerText || field.textContent || '');
                const rect = field.getBoundingClientRect();
                return {
                    text,
                    left: field.offsetLeft,
                    top: field.offsetTop,
                    width: rect.width,
                    height: rect.height,
                    line_boxes: groupLineRects(field),
                };
            })
            .filter((entry) => entry.text);

        const preamble = fields.find((entry) => entry.text.startsWith(preambleStart)) || null;
        const leadin = fields.find((entry) => entry.text.startsWith(leadinLine)) || null;
        const anchor = fields.find((entry) => entry.text.includes(anchorLine)) || null;

        return {
            scale,
            canvas_width: canvas?.width || null,
            canvas_height: canvas?.height || null,
            overlay_width: overlay?.clientWidth || null,
            overlay_height: overlay?.clientHeight || null,
            targets: { preamble, leadin, anchor },
        };
    }, {
        preambleStart: PREAMBLE_START,
        leadinLine: LEADIN_LINE,
        anchorLine: ANCHOR_LINE,
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

function compareBoxes(label, expected, actual) {
    const deltas = {
        left: round(actual.left - expected.left),
        top: round(actual.top - expected.top),
        width: round(actual.width - expected.width),
        height: round(actual.height - expected.height),
    };
    return {
        label,
        expected: Object.fromEntries(Object.entries(expected).map(([key, value]) => [key, round(value)])),
        actual: Object.fromEntries(Object.entries(actual).map(([key, value]) => [key, round(value)])),
        deltas,
        within_tolerance: (
            Math.abs(deltas.left) <= POSITION_TOLERANCE_PX
            && Math.abs(deltas.top) <= POSITION_TOLERANCE_PX
            && Math.abs(deltas.width) <= SIZE_TOLERANCE_PX
            && Math.abs(deltas.height) <= SIZE_TOLERANCE_PX
        ),
    };
}

function comparePreamble(originalPreamble, overlayPreamble, scale) {
    const expectedLines = (originalPreamble.line_bboxes || []).map((bbox) => ({
        left: Number(bbox[0] || 0) * scale,
        top: Number(bbox[1] || 0) * scale,
        width: (Number(bbox[2] || 0) - Number(bbox[0] || 0)) * scale,
        height: (Number(bbox[3] || 0) - Number(bbox[1] || 0)) * scale,
    }));
    const actualLines = Array.isArray(overlayPreamble.line_boxes) ? overlayPreamble.line_boxes : [];
    const pairs = expectedLines.slice(0, Math.min(expectedLines.length, actualLines.length)).map((expected, index) => (
        compareBoxes(`preamble_line_${index + 1}`, expected, actualLines[index])
    ));

    return {
        expected_line_count: expectedLines.length,
        actual_line_count: actualLines.length,
        line_count_match: expectedLines.length === actualLines.length,
        line_differences: pairs,
    };
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const screenshotPath = path.join(OUTPUT_DIR, `doc1316_nda_overlay_load_${runToken}.png`);
    const reportPath = path.join(OUTPUT_DIR, `doc1316_nda_overlay_load_${runToken}.json`);

    const original = loadOriginalGeometry();
    if (!original.preamble || !original.leadin || !original.anchor) {
        throw new Error(`missing original NDA geometry targets: ${JSON.stringify(original, null, 2)}`);
    }

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await forceRefreshOverlay(page);
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await activateOverlay(page);

        const overlay = await collectOverlayGeometry(page);
        await capturePageScreenshot(page, screenshotPath);

        if (!overlay.scale) {
            throw new Error(`missing overlay scale: ${JSON.stringify(overlay, null, 2)}`);
        }
        if (!overlay.targets?.preamble || !overlay.targets?.leadin || !overlay.targets?.anchor) {
            throw new Error(`missing overlay NDA targets: ${JSON.stringify(overlay, null, 2)}`);
        }

        const report = {
            document_id: DOCUMENT_ID,
            original_pdf_path: ORIGINAL_PDF_PATH,
            overlay_scale: round(overlay.scale),
            screenshot_path: screenshotPath,
            comparisons: {
                preamble_block: compareBoxes(
                    'preamble_block',
                    buildExpectedBox(original.preamble, overlay.scale),
                    overlay.targets.preamble,
                ),
                preamble_lines: comparePreamble(original.preamble, overlay.targets.preamble, overlay.scale),
                leadin_line: compareBoxes(
                    'leadin_line',
                    buildExpectedBox(original.leadin, overlay.scale),
                    overlay.targets.leadin.line_boxes?.[0] || overlay.targets.leadin,
                ),
                anchor_line: compareBoxes(
                    'anchor_line',
                    buildExpectedBox(original.anchor, overlay.scale),
                    overlay.targets.anchor.line_boxes?.[0] || overlay.targets.anchor,
                ),
            },
        };

        fs.writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`);

        const failures = [];
        if (!report.comparisons.preamble_block.within_tolerance) {
            failures.push(`preamble block drift: ${JSON.stringify(report.comparisons.preamble_block.deltas)}`);
        }
        if (!report.comparisons.preamble_lines.line_count_match) {
            failures.push(`preamble line count mismatch: expected ${report.comparisons.preamble_lines.expected_line_count}, got ${report.comparisons.preamble_lines.actual_line_count}`);
        }
        for (const lineDiff of report.comparisons.preamble_lines.line_differences) {
            if (!lineDiff.within_tolerance) {
                failures.push(`${lineDiff.label} drift: ${JSON.stringify(lineDiff.deltas)}`);
            }
        }
        if (!report.comparisons.leadin_line.within_tolerance) {
            failures.push(`lead-in line drift: ${JSON.stringify(report.comparisons.leadin_line.deltas)}`);
        }
        if (!report.comparisons.anchor_line.within_tolerance) {
            failures.push(`anchor line drift: ${JSON.stringify(report.comparisons.anchor_line.deltas)}`);
        }

        if (failures.length) {
            throw new Error(`NDA initial overlay drift detected.\nreport=${reportPath}\nscreenshot=${screenshotPath}\n${failures.join('\n')}`);
        }

        console.log(JSON.stringify(report, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
