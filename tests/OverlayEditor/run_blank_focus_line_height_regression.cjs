#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };
const PAGE_SIZE = 'Letter';
const ORIENTATION = 'portrait';
const LINE_HEIGHT_TOLERANCE_PX = 0.8;
const PARAGRAPH_TEXT = 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function approxEqual(actual, expected, tolerance = LINE_HEIGHT_TOLERANCE_PX) {
    return Number.isFinite(actual)
        && Number.isFinite(expected)
        && Math.abs(actual - expected) <= tolerance;
}

async function createBlankDocument(page, options = {}) {
    try {
        return await createBlankDocumentViaServer(page, options);
    } catch (_serverError) {
        try {
            return await createBlankDocumentViaCli(page, options);
        } catch (_cliError) {
            return await createBlankDocumentViaBrowser(page, options);
        }
    }
}

async function createBlankDocumentViaServer(page, options = {}) {
    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;

    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });

    const response = await page.evaluate(async ({ nextPageSize, nextOrientation }) => {
        const request = await fetch(`/pdf-tests/create-blank?page_size=${encodeURIComponent(nextPageSize)}&orientation=${encodeURIComponent(nextOrientation)}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const rawBody = await request.text();
        let body = null;
        try {
            body = JSON.parse(rawBody);
        } catch (_error) {
            body = null;
        }

        return {
            ok: request.ok,
            status: request.status,
            body,
            rawBody,
        };
    }, {
        nextPageSize: pageSize,
        nextOrientation: orientation,
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 500)}`);
    }

    const documentId = Number(response.body.document_id);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    return documentId;
}

async function createBlankDocumentViaCli(page, options = {}) {
    const rootDir = path.resolve(__dirname, '..', '..');
    const storageDir = path.join(rootDir, 'storage', 'app', 'documents');
    const originalsDir = path.join(storageDir, 'originals');
    const uuid = typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `pdf-test-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const relativePath = `documents/${uuid}.pdf`;
    const fullPath = path.join(rootDir, 'storage', 'app', relativePath);

    const sizes = {
        A4: [595.28, 841.89],
        Letter: [612, 792],
        Legal: [612, 1008],
        A3: [841.89, 1190.55],
        A5: [419.53, 595.28],
    };

    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;
    const selectedSize = sizes[pageSize] || sizes.Letter;
    let [width, height] = selectedSize;
    if (orientation === 'landscape') {
        [width, height] = [height, width];
    }

    fs.mkdirSync(storageDir, { recursive: true });
    fs.mkdirSync(originalsDir, { recursive: true });

    execFileSync('python3', [
        '-c',
        'import fitz, sys; doc = fitz.open(); doc.new_page(width=float(sys.argv[2]), height=float(sys.argv[3])); doc.save(sys.argv[1]); doc.close()',
        fullPath,
        String(width),
        String(height),
    ], {
        cwd: rootDir,
        stdio: 'pipe',
    });

    const sizeBytes = fs.statSync(fullPath).size;
    const phpCode = `
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();
$storedPath = $argv[1];
$sizeBytes = (int) $argv[2];
$backupPath = 'documents/originals/' . pathinfo($storedPath, PATHINFO_FILENAME) . '_original.pdf';
if (Illuminate\\Support\\Facades\\Storage::exists($storedPath)) {
    Illuminate\\Support\\Facades\\Storage::copy($storedPath, $backupPath);
} else {
    $backupPath = null;
}
$document = App\\Models\\Document::create([
    'user_id' => null,
    'original_name' => 'Blank ${pageSize} ${orientation.charAt(0).toUpperCase() + orientation.slice(1)}.pdf',
    'path' => $storedPath,
    'original_backup_path' => $backupPath,
    'mime_type' => 'application/pdf',
    'size_bytes' => $sizeBytes,
]);
echo $document->id;
`;

    const documentId = Number(execFileSync('php', ['-r', phpCode, '--', relativePath, String(sizeBytes)], {
        cwd: rootDir,
        stdio: ['ignore', 'pipe', 'pipe'],
        encoding: 'utf8',
    }).trim());

    if (!Number.isFinite(documentId) || documentId <= 0) {
        throw new Error(`failed to create blank document record for ${relativePath}`);
    }

    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    return documentId;
}

async function createBlankDocumentViaBrowser(page, options = {}) {
    const pageSize = options.pageSize || PAGE_SIZE;
    const orientation = options.orientation || ORIENTATION;

    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1000);
    await page.selectOption('#blank-page-size', pageSize);
    await page.selectOption('#blank-orientation', orientation);
    await page.getByRole('button', { name: 'Create Blank PDF', exact: true }).click();
    await page.waitForFunction(() => /\/documents\/\d+\/edit\b/.test(window.location.pathname), null, { timeout: 90000 });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`blank PDF creation failed: ${page.url()}`);
    }
    return Number(match[1]);
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 90000 });
    await page.waitForTimeout(1500);
}

async function saveAnnotationsOnly(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/save-annotation-state')
    ), { timeout: 60000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation-only save failed with ${response.status()}`);
    }

    await page.waitForTimeout(1500);
}

async function openBoundedParagraphEditor(page) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const overlay = page.locator('.page[data-page-index="0"] .overlay, .page-wrapper[data-page-number="1"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    const startX = overlayBox.x + 96;
    const startY = overlayBox.y + 110;
    const endX = overlayBox.x + 420;
    const endY = overlayBox.y + 250;

    await page.mouse.move(startX, startY);
    await page.mouse.down();
    await page.mouse.move(endX, endY, { steps: 12 });
    await page.mouse.up();
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });

    await page.evaluate((value) => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        if (!input) {
            throw new Error('missing active editor');
        }
        input.textContent = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }, PARAGRAPH_TEXT);
}

async function inspectCommittedAnnotation(page) {
    return page.evaluate(() => {
        const annotationEl = document.querySelector('.annotation');
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => item && (item.type === 'text' || !item.type)) || null;
        if (!annotationEl || !record) {
            return null;
        }

        const textEl = annotationEl.querySelector('.annotation-text') || annotationEl;
        const annotationStyle = window.getComputedStyle(annotationEl);
        const textStyle = window.getComputedStyle(textEl);
        const rect = annotationEl.getBoundingClientRect();
        const lineHeightPx = parseFloat(textStyle.lineHeight || '0') || 0;
        const textRect = textEl.getBoundingClientRect();

        return {
            box: {
                width: rect.width,
                height: rect.height,
            },
            lineCount: lineHeightPx > 0
                ? Math.max(1, Math.round((textRect.height || rect.height) / lineHeightPx))
                : 0,
            style: {
                fontSizePx: parseFloat(textStyle.fontSize || '0') || 0,
                lineHeightPx,
                whiteSpace: textStyle.whiteSpace,
                overflowWrap: textStyle.overflowWrap,
            },
            record: {
                keepBounds: Boolean(record.keepBounds),
                pdfWidth: Number(record.pdfWidth) || 0,
                pdfHeight: Number(record.pdfHeight) || 0,
                lineHeight: Number(record.lineHeight) || 0,
                requestedFontSize: Number(record.requestedFontSize) || 0,
                fontSize: Number(record.fontSize) || 0,
            },
            annotationLineHeightPx: parseFloat(annotationStyle.lineHeight || '0') || 0,
        };
    });
}

async function inspectActiveEditor(page) {
    return page.evaluate(() => {
        const box = document.querySelector('.text-box-creator');
        const input = box?.querySelector('.tbc-input');
        if (!box || !input) {
            return null;
        }

        const boxRect = box.getBoundingClientRect();
        const style = window.getComputedStyle(input);
        const lineHeightPx = parseFloat(style.lineHeight || '0') || 0;

        return {
            box: {
                width: boxRect.width,
                height: boxRect.height,
                left: box.offsetLeft,
                top: box.offsetTop,
            },
            input: {
                clientWidth: input.clientWidth,
                scrollWidth: input.scrollWidth,
                clientHeight: input.clientHeight,
                scrollHeight: input.scrollHeight,
                fontSizePx: parseFloat(style.fontSize || '0') || 0,
                lineHeightPx,
                lineCount: lineHeightPx > 0
                    ? Math.max(1, Math.round((input.scrollHeight || input.clientHeight || 0) / lineHeightPx))
                    : 0,
                textLength: (input.innerText || input.textContent || '').length,
            },
        };
    });
}

async function main() {
    ensureOutputDir();
    const runToken = buildRunToken();
    const reportPath = path.join(OUTPUT_DIR, `blank_focus_line_height_${runToken}_report.json`);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        const documentId = await createBlankDocument(page);
        await waitForEditorReady(page);

        await openBoundedParagraphEditor(page);
        const initialEditor = await inspectActiveEditor(page);
        await page.locator('.text-box-creator .tbc-ok').click();
        await page.waitForTimeout(1200);

        const committedBeforeSave = await inspectCommittedAnnotation(page);
        const annotation = page.locator('.annotation').first();
        await annotation.dblclick();
        await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
        const reopenedBeforeSave = await inspectActiveEditor(page);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await saveAnnotationsOnly(page);
        await annotation.dblclick();
        await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
        const reopenedAfterSave = await inspectActiveEditor(page);

        const expectedLineHeightPx = committedBeforeSave?.style?.lineHeightPx || committedBeforeSave?.annotationLineHeightPx || 0;
        const checks = [
            {
                name: 'Committed paragraph stays multi-line',
                pass: Boolean(committedBeforeSave && committedBeforeSave.lineCount > 3 && committedBeforeSave.record.keepBounds),
                details: committedBeforeSave,
            },
            {
                name: 'Initial editor line height matches committed annotation',
                pass: approxEqual(initialEditor?.input?.lineHeightPx, expectedLineHeightPx),
                details: {
                    actual: initialEditor?.input?.lineHeightPx || 0,
                    expected: expectedLineHeightPx,
                    fontSizePx: initialEditor?.input?.fontSizePx || 0,
                },
            },
            {
                name: 'Pre-save re-entry preserves line height',
                pass: approxEqual(reopenedBeforeSave?.input?.lineHeightPx, expectedLineHeightPx),
                details: {
                    actual: reopenedBeforeSave?.input?.lineHeightPx || 0,
                    expected: expectedLineHeightPx,
                    fontSizePx: reopenedBeforeSave?.input?.fontSizePx || 0,
                },
            },
            {
                name: 'Post-save re-entry preserves line height',
                pass: approxEqual(reopenedAfterSave?.input?.lineHeightPx, expectedLineHeightPx),
                details: {
                    actual: reopenedAfterSave?.input?.lineHeightPx || 0,
                    expected: expectedLineHeightPx,
                    fontSizePx: reopenedAfterSave?.input?.fontSizePx || 0,
                },
            },
        ];

        const failedCheck = checks.find((check) => !check.pass);
        const report = {
            documentId,
            paragraphText: PARAGRAPH_TEXT,
            initialEditor,
            committedBeforeSave,
            reopenedBeforeSave,
            reopenedAfterSave,
            checks,
        };

        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

        if (failedCheck) {
            throw new Error(`${failedCheck.name} failed. Report: ${reportPath}`);
        }

        console.log('blank focus line-height regression passed');
        console.log(JSON.stringify({ reportPath, checks }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});