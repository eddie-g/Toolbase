#!/usr/bin/env node

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT = { width: 1600, height: 1800 };

const POSITION_TEXT = 'This is a test PDF example 1';
const FIRST_MOVE_Y = -250;
const SECOND_MOVE_Y = 250;

const STYLE_TEXT = 'RedWord SerifWord BoldWord UnderlineWord';
const STYLE_SEGMENTS = {
    color: {
        text: 'RedWord',
        textColor: '#c62828',
    },
    font: {
        text: 'SerifWord',
        fontFamily: 'Garamond',
        fontRegex: /(garamond|times|nimbusroman|roman|charissil|charis|tinos)/i,
    },
    bold: {
        text: 'BoldWord',
        bold: true,
    },
    underline: {
        text: 'UnderlineWord',
        underline: true,
    },
};

const PARAGRAPH_SEED_TEXT = 'Paragraph seed';
const PARAGRAPH_TEXT = [
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
    'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
    'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
    'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
    'I’d recommend this direction: normal “Save” updates DB state, and a separate “Flatten annotations” export step is available when you want them permanently baked in.',
].join(' ');
const PARAGRAPH_TEXT_FRAGMENT = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.';
const PARAGRAPH_PUNCTUATION_EXPECTED = `I'd recommend this direction: normal "Save" updates DB state, and a separate "Flatten annotations" export step is available when you want them permanently baked in.`;
const PARAGRAPH_BLANK_LINE_TEXT = [
    'Updated the save path and the regression suite.',
    '',
    'In apply_annotations_direct.py (line 737), text save now expands the effective drawing height for wrapped paragraph annotations before stamping, so long paragraphs no longer get clipped at save. In run_pdf_tests.cjs (line 774), the test helper now reconstructs contiguous extracted blocks into a paragraph candidate when MuPDF splits one saved paragraph across multiple neighboring blocks.',
].join('\n');
const PARAGRAPH_BLANK_LINE_START = 'Updated the save path and the regression suite.';
const PARAGRAPH_BLANK_LINE_END = 'the test helper now reconstructs contiguous extracted blocks into a paragraph candidate when MuPDF splits one saved paragraph across multiple neighboring blocks.';
const PARAGRAPH_SHORT_INTRO_BLANK_LINE_TEXT = [
    'Fixed the blank-line split in extraction.',
    '',
    'The merge pass in extract_pdf_pymupdf.py (line 1299) now allows a larger vertical gap when two synthetic same-style fragments clearly belong to the same multiline paragraph column. That covers the \\n\\n case where MuPDF was returning two separate blocks after save.',
    '',
    'I also added a dedicated regression for this in run_pdf_tests.cjs (line 53) and run_pdf_tests.cjs (line 2248). It creates one text annotation with an explicit blank line, saves it, and verifies extraction keeps both paragraphs in a single raw block.',
].join('\n');
const PARAGRAPH_SHORT_INTRO_START = 'Fixed the blank-line split in extraction.';
const PARAGRAPH_SHORT_INTRO_END = 'It creates one text annotation with an explicit blank line, saves it, and verifies extraction keeps both paragraphs in a single raw block.';
const PARAGRAPH_EDITED_NEWLINES_TEXT = [
    'Line 1',
    '',
    'Line 2',
    '',
    'Line 3',
    '',
    'Line 4',
].join('\n');
const PARAGRAPH_INDENTED_TEXT = [
    '    Andreas was able to secure us an',
    'invitation to the DIT-WIT party, with some of',
    "the world's leading DITs in attendance. It was",
    'a great place for informal feedback on Drylab',
    'Viewer. The pattern was the same as for',
    'other users: Initial polite interest turns to',
    'real enthusiasm the moment someone is able',
    'to personally try Drylab Viewer! We also',
    'met with Pomfort and Apple about our on-',
    'going collaborations; ARRI and Teradek/',
].join('\n');
const PARAGRAPH_INDENTED_START = 'Andreas was able to secure us an';
const PARAGRAPH_SEPARATE_LINE_CASES = [
    {
        fragment: 'Separate Alpha',
        text: 'This is a line.. Alpha',
        left: 70,
        top: 85,
        width: 180,
        height: 42,
    },
    {
        fragment: 'Separate Beta',
        text: 'This is a line.. Beta',
        left: 70,
        top: 135,
        width: 180,
        height: 42,
    },
    {
        fragment: 'Separate Gamma',
        text: 'This is a line.. Gamma',
        left: 70,
        top: 185,
        width: 180,
        height: 42,
    },
];
const PARAGRAPH_TINY_SEPARATE_LINE_CASES = [
    {
        seed: 'Tiny Seed 1',
        text: 'no',
        left: 58,
        top: 118,
        width: 36,
        height: 24,
    },
    {
        seed: 'Tiny Seed 2',
        text: 'yes',
        left: 57,
        top: 130,
        width: 42,
        height: 24,
    },
    {
        seed: 'Tiny Seed 3',
        text: 'no',
        left: 57,
        top: 145,
        width: 36,
        height: 24,
    },
    {
        seed: 'Tiny Seed 4',
        text: 'yes',
        left: 59,
        top: 159,
        width: 42,
        height: 24,
    },
];
const PARAGRAPH_POSITION_CASES = [
    {
        fragment: 'Position Alpha',
        text: 'Position Alpha Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
        left: 36,
        top: 72,
        width: 220,
        height: 180,
    },
    {
        fragment: 'Position Beta',
        text: 'Position Beta Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
        left: 292,
        top: 312,
        width: 230,
        height: 190,
    },
    {
        fragment: 'Position Gamma',
        text: 'Position Gamma Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
        left: 116,
        top: 676,
        width: 240,
        height: 170,
    },
];
const PARAGRAPH_POSITION_TOLERANCE = 3;
const PARAGRAPH_NARROW_WIDTH = 200;
const PARAGRAPH_NARROW_HEIGHT = 500;
const PARAGRAPH_WIDE_WIDTH = 800;
const PARAGRAPH_SIZE_TOLERANCE = 20;
const PARAGRAPH_LAYOUT_TOLERANCE = 4;
const PARAGRAPH_COLUMN_WIDTH = 200;
const PARAGRAPH_COLUMN_HEIGHT = 800;
const PARAGRAPH_COLUMN_TEXTS = [
    `Column One ${PARAGRAPH_TEXT}`,
    `Column Two ${PARAGRAPH_TEXT}`,
    `Column Three ${PARAGRAPH_TEXT}`,
];
const PARAGRAPH_COLUMN_FRAGMENTS = [
    'Column One',
    'Column Two',
    'Column Three',
];
const PARAGRAPH_COLUMN_LEFTS = [6, 206, 406];

const TESTS = {
    test_1_text_position: {
        key: 'test_1_text_position',
        label: 'Test 1 : Text Position',
        description: 'Create blank PDFs, save one centered text annotation after moving it 250px up, save a second after moving it back down, and confirm the annotation save path preserves the position difference.',
        run: runTextPositionFlow,
    },
    test_2_text_styling: {
        key: 'test_2_text_styling',
        label: 'Test 2 : Text Styling',
        description: 'Create one combined text box, style individual words with different color, font, boldness, and underline, save, and confirm the mixed styling survives in the saved PDF.',
        run: runTextStylingFlow,
    },
    test_3_paragraphs: {
        key: 'test_3_paragraphs',
        label: 'Test 3 : Paragraphs',
        description: 'Create a lorem ipsum paragraph as a text annotation, resize it from a tall 200px column to 800px wide and back, move it, save through the annotation path, verify an unsaved deleted paragraph does not get stamped, and save three side-by-side 200px by 800px paragraph columns.',
        run: runParagraphFlow,
    },
    test_4_three_paragraph_columns: {
        key: 'test_4_three_paragraph_columns',
        label: 'Test 4 : Three Paragraph Columns',
        description: 'Create three 200px by 800px paragraph annotations side by side, save through the annotation path, and verify each column is stamped into the saved PDF in left-to-right order.',
        run: runThreeParagraphColumnsFlow,
    },
    test_5_paragraph_block_integrity: {
        key: 'test_5_paragraph_block_integrity',
        label: 'Test 5 : Paragraph Block Integrity',
        description: 'Create one paragraph annotation, save it, and verify the saved extraction keeps it as a single paragraph block instead of splitting it into individual line blocks.',
        run: runParagraphBlockIntegrityFlow,
    },
    test_6_paragraph_blank_line_integrity: {
        key: 'test_6_paragraph_blank_line_integrity',
        label: 'Test 6 : Paragraph Blank Line Integrity',
        description: 'Create one paragraph annotation with an explicit blank line, save it, and verify the saved extraction keeps both paragraphs in a single block.',
        run: runParagraphBlankLineIntegrityFlow,
    },
    test_7_short_intro_blank_line_integrity: {
        key: 'test_7_short_intro_blank_line_integrity',
        label: 'Test 7 : Short Intro Blank Line Integrity',
        description: 'Create one annotation with a short first paragraph, blank lines, and longer following paragraphs, then verify save keeps it as one raw block.',
        run: runShortIntroBlankLineIntegrityFlow,
    },
    test_8_edited_paragraph_newlines_preserved: {
        key: 'test_8_edited_paragraph_newlines_preserved',
        label: 'Test 8 : Edited Paragraph Newlines',
        description: 'Edit a text annotation into short lines separated by blank lines, save it, and verify the explicit newlines are preserved after save.',
        run: runEditedParagraphNewlinesPreservedFlow,
    },
    test_9_paragraph_position_accuracy: {
        key: 'test_9_paragraph_position_accuracy',
        label: 'Test 9 : Paragraph Position Accuracy',
        description: 'Create multiple paragraph annotations at distinct positions, save them, and verify their positions remain accurate after save and reload.',
        run: runParagraphPositionAccuracyFlow,
    },
    test_10_delete_all_promoted_text_save: {
        key: 'test_10_delete_all_promoted_text_save',
        label: 'Test 10 : Delete All Promoted Text Save',
        description: 'Save a paragraph, reload it as promoted text, delete all promoted annotations, save again, and verify the PDF is saved without the deleted paragraph.',
        run: runDeleteAllPromotedTextSaveFlow,
    },
    test_11_separate_lines_not_grouped: {
        key: 'test_11_separate_lines_not_grouped',
        label: 'Test 11 : Separate Lines Not Grouped',
        description: 'Create three separate one-line text boxes in the same column, save them, and verify they remain separate raw blocks instead of being grouped into one paragraph block.',
        run: runSeparateLinesNotGroupedFlow,
    },
    test_12_tiny_saved_labels_reload_separately: {
        key: 'test_12_tiny_saved_labels_reload_separately',
        label: 'Test 12 : Tiny Saved Labels Reload Separately',
        description: 'Create tiny stacked one-line text boxes, save them, reload the editor, and verify they come back as separate saved text annotations instead of one merged text area.',
        run: runTinySavedLabelsReloadSeparatelyFlow,
    },
    test_13_paragraph_indent_preserved: {
        key: 'test_13_paragraph_indent_preserved',
        label: 'Test 13 : Paragraph Indent Preserved',
        description: 'Create one multiline paragraph annotation with an indented first line, save it, and verify extraction keeps it as one block with the first-line indent preserved.',
        run: runParagraphIndentPreservedFlow,
    },
};

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
}

function normalizeTypographicAscii(text) {
    return normalize(String(text || '')
        .replace(/[\u00ad\u2010\u2011]/g, '-')
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201C\u201D]/g, '"')
        .replace(/[\u2013\u2014]/g, '-'));
}

function normalizeHex(value, fallback = '#000000') {
    const hex = String(value || fallback).trim().toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(hex)) {
        return hex;
    }
    return fallback;
}

function approxEqual(a, b, tolerance = 1) {
    return Math.abs(Number(a || 0) - Number(b || 0)) <= tolerance;
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function buildArtifactName(testKey, runToken, suffix, ext = 'png') {
    return `${testKey}_${runToken}_${suffix}.${ext}`;
}

function outputJson(payload) {
    process.stdout.write(`${JSON.stringify(payload)}\n`);
}

function lineBBox(line) {
    if (Array.isArray(line?.bbox) && line.bbox.length >= 4) {
        return line.bbox.map((value) => Number(value) || 0);
    }
    const left = Number(line?.left) || 0;
    const top = Number(line?.top) || 0;
    const width = Number(line?.width) || 0;
    const height = Number(line?.height) || 0;
    return [left, top, left + width, top + height];
}

function bboxCenter(bbox) {
    return {
        x: (bbox[0] + bbox[2]) / 2,
        y: (bbox[1] + bbox[3]) / 2,
    };
}

function blockBBox(block) {
    const left = Number(block?.left) || 0;
    const top = Number(block?.top) || 0;
    const width = Number(block?.width) || 0;
    const height = Number(block?.height) || 0;

    return [left, top, left + width, top + height];
}

function buildResult({
    testKey,
    label,
    description,
    status,
    checks = [],
    error = null,
    warnings = [],
    artifacts = [],
    fileSize = 0,
    metadata = {},
}) {
    const checksPassed = checks.filter((check) => check.result === 'PASS').length;
    return {
        filename: `${testKey}.pdf`,
        description,
        test_category: 'PDF Tests',
        section_name: label,
        status,
        checks,
        checks_passed: checksPassed,
        checks_total: checks.length,
        page_count: 1,
        file_size: fileSize,
        error,
        warnings,
        artifacts,
        metadata,
    };
}

async function submitJson(page, url, method = 'POST', body = null) {
    return page.evaluate(async ({ targetUrl, requestMethod, requestBody }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch(targetUrl, {
            method: requestMethod,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                ...(requestBody ? { 'Content-Type': 'application/json' } : {}),
            },
            body: requestBody ? JSON.stringify(requestBody) : null,
        });

        let parsed = null;
        try {
            parsed = await response.json();
        } catch (_error) {
            parsed = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body: parsed,
        };
    }, {
        targetUrl: url,
        requestMethod: method,
        requestBody: body,
    });
}

async function createBlankDocument(page, options = {}) {
    try {
        return await createBlankDocumentViaServer(page, options);
    } catch (serverError) {
        try {
            return await createBlankDocumentViaCli(page, options);
        } catch (cliError) {
            return await createBlankDocumentViaBrowser(page, {
                serverError,
                cliError,
            }, options);
        }
    }
}

async function createBlankDocumentViaServer(page, options = {}) {
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    if (!page.url().startsWith(BASE_URL)) {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    }

    const response = await page.evaluate(async ({ nextPageSize, nextOrientation }) => {
        const targetUrl = `/pdf-tests/create-blank?page_size=${encodeURIComponent(nextPageSize)}&orientation=${encodeURIComponent(nextOrientation)}`;
        const request = await fetch(targetUrl, {
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
            rawBody,
            body,
        };
    }, {
        nextPageSize: pageSize,
        nextOrientation: orientation,
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 500)}`);
    }

    const documentId = Number(response.body.document_id);
    await page.goto(`${BASE_URL}/documents/${documentId}/edit`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`server blank create opened unexpected URL: ${page.url()}`);
    }

    return Number(match[1]);
}

async function createBlankDocumentViaCli(page, options = {}) {
    const rootDir = process.cwd();
    const storageDir = path.join(rootDir, 'storage', 'app', 'documents');
    const originalsDir = path.join(storageDir, 'originals');
    const uuid = typeof crypto?.randomUUID === 'function'
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
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
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

    const editUrl = `${BASE_URL}/documents/${documentId}/edit`;
    await page.goto(editUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        throw new Error(`could not determine blank document id from URL: ${page.url()}`);
    }

    return Number(match[1]);
}


async function createBlankDocumentViaBrowser(page, bootstrapErrors, options = {}) {
    const pageSize = options.pageSize || 'Letter';
    const orientation = options.orientation || 'portrait';
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);

    await page.selectOption('#blank-page-size', pageSize);
    await page.selectOption('#blank-orientation', orientation);
    await page.getByRole('button', { name: 'Create Blank PDF', exact: true }).click();

    await page.waitForFunction(() => /\/documents\/\d+\/edit\b/.test(window.location.pathname), null, {
        timeout: 90000,
    });

    const match = page.url().match(/\/documents\/(\d+)\/edit/);
    if (!match) {
        const bodyText = (await page.textContent('body').catch(() => '')) || '';
        const serverMessage = bootstrapErrors?.serverError?.message || bootstrapErrors?.serverError || '';
        const cliMessage = bootstrapErrors?.cliError?.message || bootstrapErrors?.cliError || '';
        throw new Error(`blank PDF creation failed after server+CLI fallback. server_error=${serverMessage} cli_error=${cliMessage} browser_url=${page.url()} body=${bodyText.slice(0, 400)}`);
    }

    return Number(match[1]);
}

async function deleteDocument(page, documentId) {
    const response = await submitJson(page, `/documents/${documentId}`, 'DELETE');
    if (!response.ok) {
        throw new Error(`delete-document failed: ${JSON.stringify(response)}`);
    }
}

async function forceRefreshOverlay(page, documentId) {
    const response = await submitJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 30000 });
    await page.waitForTimeout(1500);
}

async function clearAnnotationSessionState(page, documentId) {
    await page.evaluate((id) => {
        sessionStorage.removeItem(`pdf-annotations-${id}`);
    }, documentId);
}

async function getBasePageGeometry(page) {
    return page.evaluate(() => {
        const wrapper = document.querySelector('.page[data-page-index="0"]');
        const overlay = wrapper?.querySelector('.overlay');
        const canvas = wrapper?.querySelector('canvas');
        if (!wrapper || !overlay || !canvas) {
            return null;
        }
        const overlayRect = overlay.getBoundingClientRect();
        return {
            centerX: overlayRect.width / 2,
            centerY: overlayRect.height / 2,
        };
    });
}

async function createCenterTextAnnotation(page, text) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const pageGeometry = await getBasePageGeometry(page);
    if (!pageGeometry) {
        throw new Error('missing base page geometry for Add Text');
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + pageGeometry.centerX, overlayBox.y + pageGeometry.centerY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.locator('.text-box-creator .tbc-input').fill(text);
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });

    return annotation;
}

async function createTextAnnotationAt(page, text, offsetX, offsetY) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + offsetX, overlayBox.y + offsetY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.locator('.text-box-creator .tbc-input').fill(text);
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);

    const annotation = page.locator('.annotation').filter({ hasText: text }).first();
    await annotation.waitFor({ timeout: 10000 });

    return annotation;
}

async function updateTextAnnotation(page, textFragment, options = {}) {
    const result = await page.evaluate(async ({ targetTextFragment, nextOptions }) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record || !record.element) {
            throw new Error(`missing text annotation containing "${targetTextFragment}"`);
        }

        if (Object.prototype.hasOwnProperty.call(nextOptions, 'text')) {
            record.text = nextOptions.text;
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'richTextHtml')) {
            if (typeof nextOptions.richTextHtml === 'string' && nextOptions.richTextHtml.trim()) {
                record.richTextHtml = nextOptions.richTextHtml;
            } else {
                delete record.richTextHtml;
            }
        }
        if (Object.prototype.hasOwnProperty.call(nextOptions, 'keepBounds')) {
            record.keepBounds = Boolean(nextOptions.keepBounds);
        }

        const pageContext = resolveAnnotationPageContext(record);
        if (!pageContext) {
            throw new Error('missing annotation page context');
        }

        const leftPx = Number.isFinite(Number(nextOptions.left)) ? Number(nextOptions.left) : record.element.offsetLeft;
        const topPx = Number.isFinite(Number(nextOptions.top)) ? Number(nextOptions.top) : record.element.offsetTop;
        const widthPx = Number.isFinite(Number(nextOptions.width)) ? Number(nextOptions.width) : null;
        const heightPx = Number.isFinite(Number(nextOptions.height)) ? Number(nextOptions.height) : null;

        if (widthPx !== null && heightPx !== null) {
            setTextAnnotationBounds(record, pageContext.pageInfo, leftPx, topPx, widthPx, heightPx);
        } else if (Object.prototype.hasOwnProperty.call(nextOptions, 'left') || Object.prototype.hasOwnProperty.call(nextOptions, 'top')) {
            setTextAnnotationPosition(record, pageContext.pageInfo, leftPx, topPx);
        }

        applyAnnotationStyle(record);
        persistAnnotations();
        if (typeof saveAnnotationToDatabase === 'function') {
            await saveAnnotationToDatabase(record);
        }

        return {
            id: record.id,
            text: record.text,
            left: record.element.offsetLeft,
            top: record.element.offsetTop,
            width: record.element.getBoundingClientRect().width,
            height: record.element.getBoundingClientRect().height,
        };
    }, { targetTextFragment: textFragment, nextOptions: options });

    await page.waitForTimeout(250);
    return result;
}

async function readTextAnnotationMetrics(page, textFragment) {
    return page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const record = records.find((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!record?.element) {
            throw new Error(`missing text annotation metrics target for "${targetTextFragment}"`);
        }

        const field = record.element;
        const textElement = field.querySelector('.annotation-text');
        if (!textElement) {
            throw new Error(`missing annotation text element for "${targetTextFragment}"`);
        }

        const fieldRect = field.getBoundingClientRect();
        const textRect = textElement.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textElement);

        const rectMap = new Map();
        for (const rect of Array.from(range.getClientRects())) {
            if (!rect || rect.width < 1 || rect.height < 1) {
                continue;
            }
            const key = [
                Math.round(rect.left - fieldRect.left),
                Math.round(rect.top - fieldRect.top),
                Math.round(rect.width),
                Math.round(rect.height),
            ].join(':');
            if (!rectMap.has(key)) {
                rectMap.set(key, {
                    width: rect.width,
                    height: rect.height,
                    rightGap: Math.max(0, textRect.right - rect.right),
                });
            }
        }

        const lineRects = Array.from(rectMap.values());

        return {
            left: field.offsetLeft,
            top: field.offsetTop,
            width: fieldRect.width,
            height: fieldRect.height,
            overlayWidth: field.parentElement?.clientWidth || 0,
            overlayHeight: field.parentElement?.clientHeight || 0,
            contentWidth: textRect.width,
            contentHeight: textRect.height,
            lineCount: lineRects.length,
            lineWidths: lineRects.map((rect) => rect.width),
            lineRightGaps: lineRects.map((rect) => rect.rightGap),
            text: textElement.innerText || textElement.textContent || '',
        };
    }, textFragment);
}

async function deleteTextAnnotation(page, textFragment) {
    const result = await page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const index = records.findIndex((item) => {
            const candidates = [
                item?.text,
                item?.element?.innerText,
                item?.element?.textContent,
                item?.element?.querySelector?.('.annotation-text')?.textContent,
            ];
            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (index < 0) {
            throw new Error(`missing text annotation to delete for "${targetTextFragment}"`);
        }

        const [record] = records.splice(index, 1);
        if (record?.element?.parentElement) {
            record.element.parentElement.removeChild(record.element);
        } else if (record?.element?.remove) {
            record.element.remove();
        }

        if (window.selectedAnnotation === record) {
            setSelection(null);
        }

        persistAnnotations();
        return {
            remaining: records.length,
        };
    }, textFragment);

    await page.waitForTimeout(250);
    return result;
}

async function dragLocator(page, locator, deltaX, deltaY) {
    const box = await locator.boundingBox();
    if (!box) {
        throw new Error('missing locator bounding box for drag');
    }

    const fromX = box.x + (box.width / 2);
    const fromY = box.y + (box.height / 2);
    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 16 });
    await page.mouse.up();
    await page.waitForTimeout(1000);
}

async function waitForAnnotationSave(page) {
    const saveResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && (
            response.url().includes('/apply-annotations-direct')
            || response.url().includes('/save-annotations')
        )
    ), { timeout: 60000 });

    await page.click('#save-btn');
    const response = await saveResponsePromise;
    if (!response.ok()) {
        throw new Error(`annotation save failed with ${response.status()}`);
    }

    await page.waitForFunction(() => typeof annotations !== 'undefined' && annotations.length === 0, null, { timeout: 60000 });
    await waitForEditorReady(page);
}

async function clickOutsideFirstPage(page) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    const box = await pageLocator.boundingBox();
    if (!box) {
        throw new Error('missing first page bounding box for outside click');
    }

    const targetX = Math.max(5, box.x - 20);
    const targetY = box.y + Math.min(80, Math.max(20, box.height / 10));
    await page.mouse.click(targetX, targetY);
    await page.waitForTimeout(250);
}

async function activateOverlay(page) {
    const overlayToggle = page.locator('#mode-overlay-toggle');
    if (!(await overlayToggle.isChecked())) {
        await page.getByText('Overlay Editor', { exact: true }).click();
    }
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true, null, { timeout: 30000 });
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0, null, { timeout: 30000 });
    await page.waitForTimeout(1500);
}

async function waitForOverlaySaveComplete(page) {
    await page.click('#save-btn');
    await page.waitForFunction(() => typeof overlayEditedFields !== 'undefined' && overlayEditedFields.size === 0, null, { timeout: 90000 });
    await page.waitForTimeout(2000);
}

async function capturePageScreenshot(page, outputPath) {
    const pageLocator = page.locator('.page-wrapper[data-page-number="1"], .page[data-page-index="0"]').first();
    await pageLocator.screenshot({ path: outputPath });
}

async function downloadSavedPdf(page, documentId, outputPath) {
    const response = await page.context().request.get(`${BASE_URL}/documents/${documentId}/file?v=${Date.now()}`);
    if (!response.ok()) {
        throw new Error(`failed to download saved PDF: ${response.status()}`);
    }
    const buffer = await response.body();
    fs.writeFileSync(outputPath, buffer);
}

async function fetchExtraction(page, documentId) {
    const extraction = await page.evaluate(async (id) => {
        const response = await fetch(`/documents/${id}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, documentId);

    if (!extraction?.success) {
        throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
    }

    return extraction;
}

function extractMatchingBBoxes(extraction, targetText) {
    const pageData = extraction?.extraction_data?.[0] || {};
    return (pageData.lines || [])
        .filter((line) => normalize(line?.text) === normalize(targetText))
        .map((line) => lineBBox(line));
}

function extractMatchingLine(extraction, targetText) {
    const pageData = extraction?.extraction_data?.[0] || {};
    return (pageData.lines || []).find((line) => normalize(line?.text) === normalize(targetText)) || null;
}

function mergeParagraphBlocks(blocks) {
    const normalizedBlocks = (Array.isArray(blocks) ? blocks : [])
        .map((block, index) => ({
            ...block,
            __index: index,
            __left: Number(block?.left) || 0,
            __top: Number(block?.top) || 0,
            __width: Number(block?.width) || 0,
            __height: Number(block?.height) || 0,
            __right: (Number(block?.left) || 0) + (Number(block?.width) || 0),
            __bottom: (Number(block?.top) || 0) + (Number(block?.height) || 0),
            __font: String(block?.font || ''),
            __fontSize: Number(block?.font_size) || 0,
            __lineHeight: Number(block?.avg_line_height) || Number(block?.line_height) || Number(block?.height) || 0,
        }))
        .sort((a, b) => {
            const leftDelta = a.__left - b.__left;
            if (Math.abs(leftDelta) > 4) {
                return leftDelta;
            }
            return a.__top - b.__top;
        });

    const candidates = [];
    for (let startIndex = 0; startIndex < normalizedBlocks.length; startIndex += 1) {
        const start = normalizedBlocks[startIndex];
        let merged = [start];
        let prev = start;
        candidates.push(start);

        for (let nextIndex = startIndex + 1; nextIndex < normalizedBlocks.length; nextIndex += 1) {
            const next = normalizedBlocks[nextIndex];
            const sameColumn = Math.abs(next.__left - start.__left) <= 3;
            const sameFont = normalize(next.__font) === normalize(start.__font);
            const similarSize = Math.abs(next.__fontSize - start.__fontSize) <= 0.75;
            const verticalGap = next.__top - prev.__bottom;
            const allowedGap = Math.max(8, prev.__lineHeight * 1.8);
            const overlapsHorizontally = next.__right > (start.__left + 20);

            if (!sameColumn || !sameFont || !similarSize || !overlapsHorizontally || verticalGap < -1 || verticalGap > allowedGap) {
                break;
            }

            merged.push(next);
            prev = next;
            const mergedTextLines = merged.flatMap((block) => (
                Array.isArray(block.text_lines) && block.text_lines.length
                    ? block.text_lines.map((line) => String(line))
                    : String(block.text || '').split(/\r?\n/)
            )).map((line) => String(line || '').trim()).filter(Boolean);
            const mergedLeft = Math.min(...merged.map((block) => block.__left));
            const mergedTop = Math.min(...merged.map((block) => block.__top));
            const mergedRight = Math.max(...merged.map((block) => block.__right));
            const mergedBottom = Math.max(...merged.map((block) => block.__bottom));
            candidates.push({
                ...start,
                left: mergedLeft,
                top: mergedTop,
                width: mergedRight - mergedLeft,
                height: mergedBottom - mergedTop,
                text: mergedTextLines.join('\n'),
                text_lines: mergedTextLines,
                line_count: mergedTextLines.length,
                avg_line_height: start.__lineHeight,
                line_height: start.__lineHeight,
                block_num: `${start.block_num}-${next.block_num}`,
                merged_block_nums: merged.map((block) => block.block_num),
            });
        }
    }

    return candidates;
}

function extractMatchingBlock(extraction, targetTextFragment) {
    const pageData = extraction?.extraction_data?.[0] || {};
    const fragment = normalize(targetTextFragment);
    const candidates = mergeParagraphBlocks(pageData.blocks || []);

    return candidates
        .filter((block) => normalize(block?.text).includes(fragment))
        .sort((a, b) => normalize(b?.text).length - normalize(a?.text).length)[0] || null;
}

function extractBlockLines(extraction, block) {
    if (!block) {
        return [];
    }

    const explicitLines = Array.isArray(block.text_lines)
        ? block.text_lines.map((line) => normalize(line)).filter(Boolean)
        : [];
    if (explicitLines.length > 0) {
        return explicitLines;
    }

    const pageData = extraction?.extraction_data?.[0] || {};

    return (pageData.lines || [])
        .filter((line) => String(line?.block_num) === String(block?.block_num))
        .map((line) => normalize(line?.text))
        .filter(Boolean);
}

function extractWordsForLine(extraction, line) {
    if (!line) {
        return [];
    }
    const pageData = extraction?.extraction_data?.[0] || {};
    return (pageData.words || []).filter((word) => (
        String(word?.block_num) === String(line?.block_num)
        && String(word?.line_num) === String(line?.line_num)
    ));
}

function extractMatchingWord(words, targetText) {
    const expected = normalize(targetText);
    return (Array.isArray(words) ? words : []).find((word) => normalize(word?.text) === expected) || null;
}

async function waitForSelectionToolbarSelection(page, selectionType) {
    await page.waitForFunction((expectedType) => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.getState !== 'function') {
            return false;
        }
        const state = api.getState();
        return state && state.selectionType === expectedType && state.enabled === true;
    }, selectionType, { timeout: 15000 });
}

async function readSelectionToolbarState(page) {
    return page.evaluate(() => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.getState !== 'function') {
            throw new Error('pdfSelectionToolbarApi is unavailable');
        }
        return api.getState();
    });
}

async function applySelectionToolbarStyles(page, styles) {
    return page.evaluate((inputStyles) => {
        const api = window.pdfSelectionToolbarApi;
        if (!api || typeof api.applyStyles !== 'function') {
            throw new Error('pdfSelectionToolbarApi.applyStyles is unavailable');
        }
        return api.applyStyles(inputStyles);
    }, styles);
}

async function openCenterTextCreator(page) {
    await page.click('#mode-text');
    await page.waitForTimeout(300);

    const pageGeometry = await getBasePageGeometry(page);
    if (!pageGeometry) {
        throw new Error('missing base page geometry for Add Text');
    }

    const overlay = page.locator('.page[data-page-index="0"] .overlay').first();
    const overlayBox = await overlay.boundingBox();
    if (!overlayBox) {
        throw new Error('missing overlay box for Add Text');
    }

    await page.mouse.click(overlayBox.x + pageGeometry.centerX, overlayBox.y + pageGeometry.centerY);
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    return page.locator('.text-box-creator .tbc-input').first();
}

async function commitActiveTextBox(page) {
    await page.locator('.text-box-creator .tbc-ok').click();
    await page.waitForTimeout(1200);
}

async function selectActiveEditorText(page, targetText) {
    return page.evaluate((needle) => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        if (!input) {
            throw new Error('missing active text editor');
        }

        const target = String(needle || '');
        const fullText = input.textContent || '';
        const start = fullText.indexOf(target);
        if (start === -1) {
            throw new Error(`could not find "${target}" in active editor text`);
        }
        const end = start + target.length;

        const walker = document.createTreeWalker(input, NodeFilter.SHOW_TEXT);
        let currentOffset = 0;
        let startNode = null;
        let startNodeOffset = 0;
        let endNode = null;
        let endNodeOffset = 0;

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const length = node.nodeValue?.length || 0;
            const nextOffset = currentOffset + length;

            if (!startNode && start >= currentOffset && start <= nextOffset) {
                startNode = node;
                startNodeOffset = start - currentOffset;
            }

            if (!endNode && end >= currentOffset && end <= nextOffset) {
                endNode = node;
                endNodeOffset = end - currentOffset;
            }

            currentOffset = nextOffset;
        }

        if (!startNode || !endNode) {
            throw new Error(`failed to resolve text nodes for "${target}"`);
        }

        const range = document.createRange();
        range.setStart(startNode, startNodeOffset);
        range.setEnd(endNode, endNodeOffset);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        if (typeof activeEditorSelectionRange !== 'undefined') {
            activeEditorSelectionRange = range.cloneRange();
        }
        input.focus();

        return { target, start, end };
    }, targetText);
}

async function applyEditTextBannerStyles(page, styles) {
    return page.evaluate((inputStyles) => {
        const dispatchValue = (element, value, eventName) => {
            if (!element) {
                throw new Error(`missing edit text banner control for ${eventName}`);
            }
            element.value = value;
            element.dispatchEvent(new Event(eventName, { bubbles: true }));
        };

        const setToggle = (element, desiredState) => {
            if (!element) {
                throw new Error('missing edit text banner toggle');
            }
            const nextState = Boolean(desiredState);
            if (element.classList.contains('active') !== nextState) {
                element.click();
            }
        };

        if (inputStyles.fontFamily) {
            dispatchValue(document.getElementById('etb-font'), inputStyles.fontFamily, 'change');
        }
        if (inputStyles.textColor) {
            dispatchValue(document.getElementById('etb-text-color'), inputStyles.textColor, 'input');
        }
        if (inputStyles.bold !== undefined) {
            setToggle(document.getElementById('etb-bold'), inputStyles.bold);
        }
        if (inputStyles.underline !== undefined) {
            setToggle(document.getElementById('etb-underline'), inputStyles.underline);
        }

        const input = document.querySelector('.text-box-creator .tbc-input');
        return {
            html: input?.innerHTML || '',
            text: input?.textContent || '',
        };
    }, styles);
}

async function readCommittedAnnotationRichState(page, targetText) {
    return page.evaluate((expectedText) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const annotation = Array.from(document.querySelectorAll('.annotation')).find((candidate) => {
            const textEl = candidate.querySelector('.annotation-text');
            return normalizeText(textEl?.innerText || textEl?.textContent || '') === normalizeText(expectedText);
        });
        if (!annotation) {
            throw new Error(`missing committed annotation for "${expectedText}"`);
        }

        const textEl = annotation.querySelector('.annotation-text');
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations))
            ? annotations
            : [];
        const record = Array.isArray(records)
            ? records.find((item) => normalizeText(item?.text || '') === normalizeText(expectedText))
            : null;

        return {
            html: textEl?.innerHTML || '',
            text: textEl?.textContent || '',
            richTextHtml: record?.richTextHtml || '',
        };
    }, targetText);
}

async function findOverlayFieldDescriptor(page, textFragment) {
    return page.evaluate((targetTextFragment) => {
        const normalizeText = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const fragment = normalizeText(targetTextFragment);
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((field) => {
            const candidates = [
                field.innerText,
                field.textContent,
                field.dataset.stableFlowText,
                field.dataset.originalText,
            ];

            return candidates.some((candidate) => normalizeText(candidate).includes(fragment));
        });

        if (!match) {
            return null;
        }

        return {
            key: match.dataset.wordIndex || '',
            text: match.innerText || match.textContent || '',
        };
    }, textFragment);
}

async function getOverlayFieldSelector(page, textFragment) {
    const descriptor = await findOverlayFieldDescriptor(page, textFragment);
    if (!descriptor?.key) {
        throw new Error(`could not find overlay field containing "${textFragment}"`);
    }

    return `.overlay-field[data-word-index="${descriptor.key}"]`;
}

async function replaceOverlayFieldText(page, fieldSelector, newText) {
    const field = page.locator(fieldSelector).first();
    await field.dblclick();
    await page.waitForTimeout(250);

    await page.evaluate(({ selector, text }) => {
        const fieldElement = document.querySelector(selector);
        const textElement = fieldElement?.querySelector('[contenteditable]');
        if (!fieldElement || !textElement) {
            throw new Error(`missing overlay field for selector ${selector}`);
        }

        fieldElement.classList.add('active', 'editing');
        textElement.contentEditable = 'true';
        textElement.focus();
        textElement.textContent = text;
        textElement.dispatchEvent(new Event('input', { bubbles: true }));
    }, { selector: fieldSelector, text: newText });

    await page.waitForTimeout(250);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
    await field.click();
    await page.waitForTimeout(250);
}

async function readOverlayFieldMetrics(page, fieldSelector) {
    return page.evaluate((selector) => {
        const field = document.querySelector(selector);
        const textElement = field?.querySelector('[contenteditable]');
        if (!field || !textElement) {
            throw new Error(`missing overlay field metrics target for selector ${selector}`);
        }

        const fieldRect = field.getBoundingClientRect();
        const textRect = textElement.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textElement);

        const rectMap = new Map();
        for (const rect of Array.from(range.getClientRects())) {
            if (!rect || rect.width < 1 || rect.height < 1) {
                continue;
            }

            const key = [
                Math.round(rect.left - fieldRect.left),
                Math.round(rect.top - fieldRect.top),
                Math.round(rect.width),
                Math.round(rect.height),
            ].join(':');
            if (!rectMap.has(key)) {
                rectMap.set(key, {
                    width: rect.width,
                    height: rect.height,
                    rightGap: Math.max(0, textRect.right - rect.right),
                });
            }
        }

        const lineRects = Array.from(rectMap.values());

        return {
            left: parseFloat(field.style.left || '0') || 0,
            top: parseFloat(field.style.top || '0') || 0,
            width: fieldRect.width,
            height: fieldRect.height,
            overlayWidth: field.parentElement?.clientWidth || 0,
            overlayHeight: field.parentElement?.clientHeight || 0,
            contentWidth: textRect.width,
            contentHeight: textRect.height,
            lineCount: lineRects.length,
            lineWidths: lineRects.map((rect) => rect.width),
            lineRightGaps: lineRects.map((rect) => rect.rightGap),
            text: textElement.innerText || textElement.textContent || '',
        };
    }, fieldSelector);
}

async function resizeOverlayFieldTo(page, fieldSelector, targetWidth, targetHeight) {
    const field = page.locator(fieldSelector).first();

    for (let attempt = 0; attempt < 3; attempt += 1) {
        await field.click();
        await page.waitForTimeout(150);

        const fieldBox = await field.boundingBox();
        const handle = page.locator(`${fieldSelector} .resize-handle.se`).first();
        const handleBox = await handle.boundingBox();
        if (!fieldBox || !handleBox) {
            throw new Error(`missing overlay resize handle for selector ${fieldSelector}`);
        }

        const deltaX = targetWidth - fieldBox.width;
        const deltaY = targetHeight - fieldBox.height;
        if (Math.abs(deltaX) <= 3 && Math.abs(deltaY) <= 3) {
            break;
        }

        const fromX = handleBox.x + (handleBox.width / 2);
        const fromY = handleBox.y + (handleBox.height / 2);
        await page.mouse.move(fromX, fromY);
        await page.mouse.down();
        await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 18 });
        await page.mouse.up();
        await page.waitForTimeout(250);
    }

    const metrics = await readOverlayFieldMetrics(page, fieldSelector);
    if (Math.abs(metrics.width - targetWidth) > PARAGRAPH_SIZE_TOLERANCE || Math.abs(metrics.height - targetHeight) > PARAGRAPH_SIZE_TOLERANCE) {
        throw new Error(`overlay field resize missed target: ${JSON.stringify({ metrics, targetWidth, targetHeight })}`);
    }

    return metrics;
}

function buildDeterministicParagraphTarget(metrics) {
    const maxLeft = Math.max(0, metrics.overlayWidth - metrics.width);
    const maxTop = Math.max(0, metrics.overlayHeight - metrics.height);

    return {
        left: Math.round(maxLeft * 0.61),
        top: Math.round(maxTop * 0.37),
    };
}

async function moveOverlayFieldTo(page, fieldSelector, targetLeft, targetTop) {
    const field = page.locator(fieldSelector).first();
    await field.click();
    await page.waitForTimeout(150);

    const before = await readOverlayFieldMetrics(page, fieldSelector);
    const dragHandle = page.locator(`${fieldSelector} .menu-drag`).first();
    const dragBox = await dragHandle.boundingBox();
    if (!dragBox) {
        throw new Error(`missing overlay drag handle for selector ${fieldSelector}`);
    }

    const deltaX = targetLeft - before.left;
    const deltaY = targetTop - before.top;
    const fromX = dragBox.x + (dragBox.width / 2);
    const fromY = dragBox.y + (dragBox.height / 2);

    await page.mouse.move(fromX, fromY);
    await page.mouse.down();
    await page.mouse.move(fromX + deltaX, fromY + deltaY, { steps: 24 });
    await page.mouse.up();
    await page.waitForTimeout(250);

    const after = await readOverlayFieldMetrics(page, fieldSelector);
    return {
        before,
        after,
        deltaX: after.left - before.left,
        deltaY: after.top - before.top,
    };
}

async function deleteOverlayField(page, fieldSelector) {
    const field = page.locator(fieldSelector).first();
    await field.click();
    await page.waitForTimeout(150);
    await page.locator(`${fieldSelector} .menu-delete`).first().click();
    await page.waitForTimeout(250);
}

function countWideLines(metrics, threshold = 0.72) {
    const comparable = metrics.lineWidths.filter((width) => width >= 20);
    if (comparable.length === 0 || !metrics.contentWidth) {
        return 0;
    }

    return comparable.filter((width) => width >= (metrics.contentWidth * threshold)).length;
}

async function runTextPositionFlow() {
    const test = TESTS.test_1_text_position;
    ensureOutputDir();

    const runToken = buildRunToken();
    const firstScreenshotName = buildArtifactName(test.key, runToken, 'after_first_save');
    const secondScreenshotName = buildArtifactName(test.key, runToken, 'after_second_save');
    const secondPdfName = buildArtifactName(test.key, runToken, 'after_second_save', 'pdf');
    const firstScreenshotPath = path.join(OUTPUT_DIR, firstScreenshotName);
    const secondScreenshotPath = path.join(OUTPUT_DIR, secondScreenshotName);
    const secondPdfPath = path.join(OUTPUT_DIR, secondPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let firstDocumentId = null;
    let secondDocumentId = null;

    try {
        firstDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, firstDocumentId);

        await createCenterTextAnnotation(page, POSITION_TEXT);
        const firstStartMetrics = await readTextAnnotationMetrics(page, POSITION_TEXT);
        await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(firstStartMetrics.top + FIRST_MOVE_Y),
        });
        await waitForAnnotationSave(page);

        await forceRefreshOverlay(page, firstDocumentId);
        const firstExtraction = await fetchExtraction(page, firstDocumentId);
        const firstMatches = extractMatchingBBoxes(firstExtraction, POSITION_TEXT);
        if (firstMatches.length !== 1) {
            throw new Error(`expected 1 first-save text match, found ${firstMatches.length}`);
        }
        const firstBBox = firstMatches[0];
        await capturePageScreenshot(page, firstScreenshotPath);

        secondDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, secondDocumentId);

        await createCenterTextAnnotation(page, POSITION_TEXT);
        const secondStartMetrics = await readTextAnnotationMetrics(page, POSITION_TEXT);
        const secondMoveUpState = await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(secondStartMetrics.top + FIRST_MOVE_Y),
        });
        const secondMoveDownState = await updateTextAnnotation(page, POSITION_TEXT, {
            top: Math.round(secondMoveUpState.top + SECOND_MOVE_Y),
        });

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, secondDocumentId, secondPdfPath);
        await forceRefreshOverlay(page, secondDocumentId);
        const secondExtraction = await fetchExtraction(page, secondDocumentId);
        const secondMatches = extractMatchingBBoxes(secondExtraction, POSITION_TEXT);
        if (secondMatches.length !== 1) {
            throw new Error(`expected 1 final text match, found ${secondMatches.length}`);
        }
        const secondBBox = secondMatches[0];
        await capturePageScreenshot(page, secondScreenshotPath);

        const firstCenter = bboxCenter(firstBBox);
        const secondCenter = bboxCenter(secondBBox);
        const savedDelta = secondCenter.y - firstCenter.y;
        const browserDelta = SECOND_MOVE_Y;

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `documents=${firstDocumentId},${secondDocumentId}`,
            },
            {
                item: 'first_save_visible',
                result: firstMatches.length === 1 ? 'PASS' : 'FAIL',
                description: 'The first annotation save produced one visible text line in the blank PDF.',
                detail: `bbox=${JSON.stringify(firstBBox)}`,
            },
            {
                item: 'second_save_visible',
                result: secondMatches.length === 1 ? 'PASS' : 'FAIL',
                description: 'The second annotation save preserved one visible text line after the browser move.',
                detail: `bbox=${JSON.stringify(secondBBox)}`,
            },
            {
                item: 'second_move_browser_delta',
                result: browserDelta >= 220 && browserDelta <= 280 ? 'PASS' : 'FAIL',
                description: 'The annotation move request asked for about 250px downward before the second save.',
                detail: `browser_delta_y=${browserDelta.toFixed(2)}`,
            },
            {
                item: 'second_move_saved_delta',
                result: savedDelta >= 100 ? 'PASS' : 'FAIL',
                description: 'The saved PDF moved the line materially downward between the two annotation-only saves.',
                detail: `saved_delta_y=${savedDelta.toFixed(2)} first_center=${firstCenter.y.toFixed(2)} second_center=${secondCenter.y.toFixed(2)}`,
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const secondPdfStats = fs.statSync(secondPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: secondPdfStats.size,
            artifacts: [
                {
                    label: 'After First Save',
                    kind: 'image',
                    filename: firstScreenshotName,
                },
                {
                    label: 'After Second Save',
                    kind: 'image',
                    filename: secondScreenshotName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: secondPdfName,
                },
            ],
            metadata: {
                document_ids: [firstDocumentId, secondDocumentId],
                target_text: POSITION_TEXT,
                first_bbox: firstBBox,
                second_bbox: secondBBox,
                browser_second_move_delta_y: browserDelta,
                second_move_delta_y: savedDelta,
            },
        });
    } finally {
        if (firstDocumentId) {
            try {
                await deleteDocument(page, firstDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (secondDocumentId) {
            try {
                await deleteDocument(page, secondDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runTextStylingFlow() {
    const test = TESTS.test_2_text_styling;
    ensureOutputDir();

    const runToken = buildRunToken();
    const beforeSaveName = buildArtifactName(test.key, runToken, 'before_save');
    const afterSaveName = buildArtifactName(test.key, runToken, 'after_save');
    const finalPdfName = buildArtifactName(test.key, runToken, 'final', 'pdf');
    const beforeSavePath = path.join(OUTPUT_DIR, beforeSaveName);
    const afterSavePath = path.join(OUTPUT_DIR, afterSaveName);
    const finalPdfPath = path.join(OUTPUT_DIR, finalPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        const editor = await openCenterTextCreator(page);
        await editor.fill(STYLE_TEXT);

        const colorSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.color.text);
        const colorEditorState = await applyEditTextBannerStyles(page, {
            textColor: STYLE_SEGMENTS.color.textColor,
        });

        const fontSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.font.text);
        const fontEditorState = await applyEditTextBannerStyles(page, {
            fontFamily: STYLE_SEGMENTS.font.fontFamily,
        });

        const boldSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.bold.text);
        const boldEditorState = await applyEditTextBannerStyles(page, {
            bold: STYLE_SEGMENTS.bold.bold,
        });

        const underlineSelection = await selectActiveEditorText(page, STYLE_SEGMENTS.underline.text);
        const underlineEditorState = await applyEditTextBannerStyles(page, {
            underline: STYLE_SEGMENTS.underline.underline,
        });

        await commitActiveTextBox(page);
        const annotation = page.locator('.annotation').filter({ hasText: STYLE_TEXT }).first();
        await annotation.waitFor({ timeout: 10000 });
        await annotation.click();
        await waitForSelectionToolbarSelection(page, 'annotation');

        const beforeSaveAnnotationState = await readCommittedAnnotationRichState(page, STYLE_TEXT);
        await page.waitForTimeout(500);
        const beforeSaveToolbarState = await readSelectionToolbarState(page);
        await capturePageScreenshot(page, beforeSavePath);

        await waitForAnnotationSave(page);
        await capturePageScreenshot(page, afterSavePath);
        await downloadSavedPdf(page, documentId, finalPdfPath);

        await forceRefreshOverlay(page, documentId);
        const savedExtraction = await fetchExtraction(page, documentId);
        const savedLine = extractMatchingLine(savedExtraction, STYLE_TEXT);
        const savedWords = extractWordsForLine(savedExtraction, savedLine);
        const savedColorWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.color.text);
        const savedFontWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.font.text);
        const savedBoldWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.bold.text);
        const savedUnderlineWord = extractMatchingWord(savedWords, STYLE_SEGMENTS.underline.text);

        const expectedColor = normalizeHex(STYLE_SEGMENTS.color.textColor);
        const serializedAnnotationHtml = String(beforeSaveAnnotationState.richTextHtml || beforeSaveAnnotationState.html || '');
        const savedColor = normalizeHex(savedColorWord?.hex_color || savedColorWord?.color);
        const savedFontName = String(savedFontWord?.font || '');
        const boldDetected = Boolean(savedBoldWord?.bold)
            || parseInt(savedBoldWord?.font_weight || '0', 10) >= 600;
        const underlineDetected = Boolean(savedUnderlineWord?.has_drawn_underline);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'editor_mixed_styles_applied_before_save',
                result: (
                    serializedAnnotationHtml.includes('color:#c62828')
                    && /(times new roman|garamond|baskerville)/i.test(serializedAnnotationHtml)
                    && serializedAnnotationHtml.includes('font-weight:700')
                    && serializedAnnotationHtml.includes('text-decoration:underline')
                ) ? 'PASS' : 'FAIL',
                description: 'The combined text box contained mixed rich-text spans for color, font, boldness, and underline before save.',
                detail: JSON.stringify({
                    selections: {
                        color: colorSelection,
                        font: fontSelection,
                        bold: boldSelection,
                        underline: underlineSelection,
                    },
                    editor_states: {
                        color: colorEditorState,
                        font: fontEditorState,
                        bold: boldEditorState,
                        underline: underlineEditorState,
                    },
                    annotation_state: beforeSaveAnnotationState,
                    before_save: beforeSaveToolbarState,
                }),
            },
            {
                item: 'saved_line_visible',
                result: savedLine ? 'PASS' : 'FAIL',
                description: 'The saved PDF exposes the combined styled text as one extracted line.',
                detail: savedLine ? JSON.stringify({
                    text: savedLine.text,
                    font: savedLine.font,
                    font_size: savedLine.font_size,
                    hex_color: savedLine.hex_color,
                }) : 'no matching saved line',
            },
            {
                item: 'saved_color_word_matches',
                result: savedColorWord && savedColor === expectedColor ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps the requested color on the color-specific word.',
                detail: JSON.stringify({
                    word: savedColorWord?.text || null,
                    saved_color: savedColor,
                    expected_color: expectedColor,
                }),
            },
            {
                item: 'saved_font_word_matches',
                result: savedFontWord && STYLE_SEGMENTS.font.fontRegex.test(savedFontName) ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps the requested alternate font on the font-specific word.',
                detail: JSON.stringify({
                    word: savedFontWord?.text || null,
                    saved_font: savedFontName,
                    expected_regex: String(STYLE_SEGMENTS.font.fontRegex),
                }),
            },
            {
                item: 'saved_bold_word_matches',
                result: savedBoldWord && boldDetected === true ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps bold styling on the bold-specific word.',
                detail: JSON.stringify({
                    word: savedBoldWord?.text || null,
                    saved_bold: Boolean(savedBoldWord?.bold),
                    saved_font_weight: savedBoldWord?.font_weight || null,
                }),
            },
            {
                item: 'saved_underline_word_matches',
                result: savedUnderlineWord && underlineDetected === true ? 'PASS' : 'FAIL',
                description: 'The saved PDF keeps underline styling on the underline-specific word.',
                detail: JSON.stringify({
                    word: savedUnderlineWord?.text || null,
                    saved_underline: underlineDetected,
                }),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(finalPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Before Save',
                    kind: 'image',
                    filename: beforeSaveName,
                },
                {
                    label: 'After Save',
                    kind: 'image',
                    filename: afterSaveName,
                },
                {
                    label: 'Final PDF',
                    kind: 'pdf',
                    filename: finalPdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                target_text: STYLE_TEXT,
                expected_style_segments: {
                    ...STYLE_SEGMENTS,
                    font: {
                        ...STYLE_SEGMENTS.font,
                        fontRegex: String(STYLE_SEGMENTS.font.fontRegex),
                    },
                },
                before_save_annotation_state: beforeSaveAnnotationState,
                before_save_toolbar_state: beforeSaveToolbarState,
                extracted_line: savedLine,
                extracted_words: savedWords,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphFlow() {
    const test = TESTS.test_3_paragraphs;
    ensureOutputDir();

    const runToken = buildRunToken();
    const narrowScreenshotName = buildArtifactName(test.key, runToken, 'narrow_200px');
    const wideScreenshotName = buildArtifactName(test.key, runToken, 'wide_800px');
    const movedScreenshotName = buildArtifactName(test.key, runToken, 'after_move_save');
    const deletedScreenshotName = buildArtifactName(test.key, runToken, 'after_delete_save');
    const movedPdfName = buildArtifactName(test.key, runToken, 'after_move_save', 'pdf');
    const deletedPdfName = buildArtifactName(test.key, runToken, 'after_delete_save', 'pdf');
    const narrowScreenshotPath = path.join(OUTPUT_DIR, narrowScreenshotName);
    const wideScreenshotPath = path.join(OUTPUT_DIR, wideScreenshotName);
    const movedScreenshotPath = path.join(OUTPUT_DIR, movedScreenshotName);
    const deletedScreenshotPath = path.join(OUTPUT_DIR, deletedScreenshotName);
    const movedPdfPath = path.join(OUTPUT_DIR, movedPdfName);
    const deletedPdfPath = path.join(OUTPUT_DIR, deletedPdfName);
    const columnsScreenshotName = buildArtifactName(test.key, runToken, 'three_columns_save');
    const columnsPdfName = buildArtifactName(test.key, runToken, 'three_columns_save', 'pdf');
    const columnsScreenshotPath = path.join(OUTPUT_DIR, columnsScreenshotName);
    const columnsPdfPath = path.join(OUTPUT_DIR, columnsPdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let mainDocumentId = null;
    let deleteDocumentId = null;
    let columnsDocumentId = null;

    try {
        mainDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, mainDocumentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        const narrowMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, narrowScreenshotPath);

        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            width: PARAGRAPH_WIDE_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        const wideMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, wideScreenshotPath);

        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        const narrowAgainMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        const targetPosition = buildDeterministicParagraphTarget(narrowAgainMetrics);
        await updateTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT, {
            left: targetPosition.left,
            top: targetPosition.top,
        });
        const movedMetrics = await readTextAnnotationMetrics(page, PARAGRAPH_TEXT_FRAGMENT);
        const moveResult = {
            before: narrowAgainMetrics,
            after: movedMetrics,
            deltaX: targetPosition.left - narrowAgainMetrics.left,
            deltaY: targetPosition.top - narrowAgainMetrics.top,
        };

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, mainDocumentId, movedPdfPath);
        await forceRefreshOverlay(page, mainDocumentId);
        const movedExtraction = await fetchExtraction(page, mainDocumentId);
        const movedBlock = extractMatchingBlock(movedExtraction, PARAGRAPH_TEXT_FRAGMENT);
        if (!movedBlock) {
            throw new Error(`paragraph block missing after annotation save: ${JSON.stringify(movedExtraction?.extraction_data?.[0]?.blocks || [], null, 2)}`);
        }
        const movedLines = extractBlockLines(movedExtraction, movedBlock);
        if (movedLines.length === 0) {
            throw new Error(`paragraph block has no extracted lines after annotation save: ${JSON.stringify(movedBlock, null, 2)}`);
        }
        const movedBlockText = normalize(movedBlock?.text || '');
        const punctuationPreserved = normalizeTypographicAscii(movedBlockText).includes(normalizeTypographicAscii(PARAGRAPH_PUNCTUATION_EXPECTED));
        const punctuationSubstituted = movedBlockText.includes('?');
        await clickOutsideFirstPage(page);
        await capturePageScreenshot(page, movedScreenshotPath);

        deleteDocumentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, deleteDocumentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        await deleteTextAnnotation(page, PARAGRAPH_TEXT_FRAGMENT);
        await clickOutsideFirstPage(page);
        await downloadSavedPdf(page, deleteDocumentId, deletedPdfPath);
        await forceRefreshOverlay(page, deleteDocumentId);
        const deletedExtraction = await fetchExtraction(page, deleteDocumentId);
        const deletedBlock = extractMatchingBlock(deletedExtraction, PARAGRAPH_TEXT_FRAGMENT);
        await capturePageScreenshot(page, deletedScreenshotPath);

        columnsDocumentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, columnsDocumentId);

        for (let index = 0; index < PARAGRAPH_COLUMN_TEXTS.length; index += 1) {
            const fragment = PARAGRAPH_COLUMN_FRAGMENTS[index];
            await createTextAnnotationAt(page, fragment, 30, 120);
            await updateTextAnnotation(page, fragment, {
                text: PARAGRAPH_COLUMN_TEXTS[index],
                keepBounds: true,
                left: PARAGRAPH_COLUMN_LEFTS[index],
                top: 80,
                width: PARAGRAPH_COLUMN_WIDTH,
                height: PARAGRAPH_COLUMN_HEIGHT,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, columnsDocumentId, columnsPdfPath);
        await forceRefreshOverlay(page, columnsDocumentId);
        const columnsExtraction = await fetchExtraction(page, columnsDocumentId);
        const columnBlocks = PARAGRAPH_COLUMN_FRAGMENTS.map((fragment) => extractMatchingBlock(columnsExtraction, fragment));
        await capturePageScreenshot(page, columnsScreenshotPath);

        const movedBlockBBox = blockBBox(movedBlock);
        const meaningfulNarrowLineWidths = narrowAgainMetrics.lineWidths.filter((value) => value >= 20);
        const narrowFillLines = countWideLines(narrowAgainMetrics);
        const narrowFillTarget = Math.max(2, Math.floor(meaningfulNarrowLineWidths.length * 0.7));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: 'PASS',
                description: 'Fresh blank PDFs were created through the frontend blank-PDF flow.',
                detail: `documents=${mainDocumentId},${deleteDocumentId}`,
            },
            {
                item: 'paragraph_reflowed_wider',
                result: wideMetrics.lineCount < narrowMetrics.lineCount ? 'PASS' : 'FAIL',
                description: 'Expanding the paragraph to 800px reduced the number of wrapped annotation lines.',
                detail: `narrow_lines=${narrowMetrics.lineCount} wide_lines=${wideMetrics.lineCount}`,
            },
            {
                item: 'paragraph_reflowed_back_to_narrow',
                result: Math.abs(narrowAgainMetrics.lineCount - narrowMetrics.lineCount) <= 1 ? 'PASS' : 'FAIL',
                description: 'Shrinking the paragraph back to 200px restored the narrow wrapped annotation layout.',
                detail: `first_narrow_lines=${narrowMetrics.lineCount} second_narrow_lines=${narrowAgainMetrics.lineCount}`,
            },
            {
                item: 'narrow_layout_fills_box_edges',
                result: narrowFillLines >= narrowFillTarget ? 'PASS' : 'FAIL',
                description: 'The 200px paragraph uses most of the available width on its wrapped lines before save.',
                detail: `wide_lines_in_box=${narrowFillLines} target=${narrowFillTarget} line_widths=${JSON.stringify(narrowAgainMetrics.lineWidths.map((value) => Number(value.toFixed(2))))} content_width=${narrowAgainMetrics.contentWidth.toFixed(2)}`,
            },
            {
                item: 'typographic_punctuation_not_converted_to_question_marks',
                result: punctuationPreserved && !punctuationSubstituted ? 'PASS' : 'FAIL',
                description: 'After the annotation save, typographic apostrophes and smart quotes are preserved as readable punctuation instead of being converted to question marks.',
                detail: JSON.stringify({
                    expected_saved_text: PARAGRAPH_PUNCTUATION_EXPECTED,
                    extracted_block_text: movedBlockText,
                    found_question_mark: punctuationSubstituted,
                }),
            },
            {
                item: 'narrow_browser_box_matches_target',
                result: Math.abs(narrowAgainMetrics.width - PARAGRAPH_NARROW_WIDTH) <= PARAGRAPH_SIZE_TOLERANCE
                    && Math.abs(narrowAgainMetrics.height - PARAGRAPH_NARROW_HEIGHT) <= PARAGRAPH_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The live paragraph annotation was resized to the requested 200px by 500px narrow size.',
                detail: `browser_size=${narrowAgainMetrics.width.toFixed(2)}x${narrowAgainMetrics.height.toFixed(2)}`,
            },
            {
                item: 'wide_browser_box_matches_target',
                result: Math.abs(wideMetrics.width - PARAGRAPH_WIDE_WIDTH) <= PARAGRAPH_SIZE_TOLERANCE
                    && Math.abs(wideMetrics.height - PARAGRAPH_NARROW_HEIGHT) <= PARAGRAPH_SIZE_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The live paragraph annotation was resized to the requested 800px width without changing height.',
                detail: `browser_size=${wideMetrics.width.toFixed(2)}x${wideMetrics.height.toFixed(2)}`,
            },
            {
                item: 'moved_paragraph_saved_new_position',
                result: moveResult.deltaX >= PARAGRAPH_LAYOUT_TOLERANCE || moveResult.deltaY >= PARAGRAPH_LAYOUT_TOLERANCE ? 'PASS' : 'FAIL',
                description: 'The paragraph annotation moved to a materially different browser position before save.',
                detail: `browser_delta=(${moveResult.deltaX.toFixed(2)}, ${moveResult.deltaY.toFixed(2)}) target=${JSON.stringify(targetPosition)} saved_bbox=${JSON.stringify(movedBlockBBox)}`,
            },
            {
                item: 'paragraph_saved_after_move',
                result: movedBlock ? 'PASS' : 'FAIL',
                description: 'The moved paragraph was stamped into the saved PDF through the annotation path.',
                detail: `saved_bbox=${JSON.stringify(movedBlockBBox)}`,
            },
            {
                item: 'paragraph_deleted',
                result: deletedBlock ? 'FAIL' : 'PASS',
                description: 'Deleting the unsaved paragraph before save prevented it from being stamped into the PDF.',
                detail: deletedBlock ? JSON.stringify(deletedBlock) : 'paragraph text absent after delete save',
            },
            {
                item: 'three_side_by_side_paragraphs_saved',
                result: columnBlocks.every(Boolean) ? 'PASS' : 'FAIL',
                description: 'Three side-by-side 200px by 800px paragraph annotations were all stamped into the saved PDF.',
                detail: JSON.stringify(columnBlocks.map((block, index) => ({
                    fragment: PARAGRAPH_COLUMN_FRAGMENTS[index],
                    found: Boolean(block),
                    bbox: block ? blockBBox(block) : null,
                }))),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(movedPdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Narrow 200px Paragraph',
                    kind: 'image',
                    filename: narrowScreenshotName,
                },
                {
                    label: 'Wide 800px Paragraph',
                    kind: 'image',
                    filename: wideScreenshotName,
                },
                {
                    label: 'After Move Save',
                    kind: 'image',
                    filename: movedScreenshotName,
                },
                {
                    label: 'After Delete Save',
                    kind: 'image',
                    filename: deletedScreenshotName,
                },
                {
                    label: 'Moved PDF',
                    kind: 'pdf',
                    filename: movedPdfName,
                },
                {
                    label: 'Deleted PDF',
                    kind: 'pdf',
                    filename: deletedPdfName,
                },
                {
                    label: 'Three Columns Save',
                    kind: 'image',
                    filename: columnsScreenshotName,
                },
                {
                    label: 'Three Columns PDF',
                    kind: 'pdf',
                    filename: columnsPdfName,
                },
            ],
            metadata: {
                document_ids: [mainDocumentId, deleteDocumentId, columnsDocumentId].filter(Boolean),
                paragraph_text: PARAGRAPH_TEXT,
                narrow_metrics: narrowMetrics,
                wide_metrics: wideMetrics,
                narrow_again_metrics: narrowAgainMetrics,
                move_target: targetPosition,
                browser_move: moveResult,
                moved_saved_lines: movedLines,
                moved_saved_text: movedBlockText,
                moved_saved_bbox: movedBlockBBox,
                column_saved_blocks: columnBlocks,
            },
        });
    } finally {
        if (mainDocumentId) {
            try {
                await deleteDocument(page, mainDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (deleteDocumentId) {
            try {
                await deleteDocument(page, deleteDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        if (columnsDocumentId) {
            try {
                await deleteDocument(page, columnsDocumentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runThreeParagraphColumnsFlow() {
    const test = TESTS.test_4_three_paragraph_columns;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'three_columns_save');
    const pdfName = buildArtifactName(test.key, runToken, 'three_columns_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (let index = 0; index < PARAGRAPH_COLUMN_TEXTS.length; index += 1) {
            const fragment = PARAGRAPH_COLUMN_FRAGMENTS[index];
            await createTextAnnotationAt(page, fragment, 30, 120);
            await updateTextAnnotation(page, fragment, {
                text: PARAGRAPH_COLUMN_TEXTS[index],
                keepBounds: true,
                left: PARAGRAPH_COLUMN_LEFTS[index],
                top: 80,
                width: PARAGRAPH_COLUMN_WIDTH,
                height: PARAGRAPH_COLUMN_HEIGHT,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const columnBlocks = PARAGRAPH_COLUMN_FRAGMENTS.map((fragment) => extractMatchingBlock(extraction, fragment));
        await capturePageScreenshot(page, screenshotPath);

        const leftPositions = columnBlocks.map((block) => (block ? blockBBox(block)[0] : null));
        const leftToRightOrdered = leftPositions.every((value, index) => (
            index === 0 || value === null || leftPositions[index - 1] === null || value > leftPositions[index - 1]
        ));

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'three_side_by_side_paragraphs_saved',
                result: columnBlocks.every(Boolean) ? 'PASS' : 'FAIL',
                description: 'Three side-by-side 200px by 800px paragraph annotations were all stamped into the saved PDF.',
                detail: JSON.stringify(columnBlocks.map((block, index) => ({
                    fragment: PARAGRAPH_COLUMN_FRAGMENTS[index],
                    found: Boolean(block),
                    bbox: block ? blockBBox(block) : null,
                }))),
            },
            {
                item: 'three_columns_preserve_left_to_right_order',
                result: leftToRightOrdered ? 'PASS' : 'FAIL',
                description: 'The saved paragraph columns remain ordered from left to right after save.',
                detail: JSON.stringify(leftPositions),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(pdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Three Columns Save',
                    kind: 'image',
                    filename: screenshotName,
                },
                {
                    label: 'Three Columns PDF',
                    kind: 'pdf',
                    filename: pdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                left_positions: leftPositions,
                column_saved_blocks: columnBlocks,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphBlockIntegrityFlow() {
    const test = TESTS.test_5_paragraph_block_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_block_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_block_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_TEXT_FRAGMENT);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_PUNCTUATION_EXPECTED);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        const paragraphLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw paragraph block that includes both the start and end of the paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 220),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'paragraph_raw_block_has_multiple_lines',
                result: rawParagraphBlock && paragraphLines.length > 1 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph block exposes multiple wrapped lines inside one block.',
                detail: JSON.stringify(paragraphLines),
            },
            {
                item: 'paragraph_not_split_into_raw_line_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block?.text,
                    block_num: block?.block_num,
                    line_count: block?.line_count,
                }))),
            },
        ];

        const hasFailure = checks.some((check) => check.result !== 'PASS');
        const finalPdfStats = fs.statSync(pdfPath);

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: hasFailure ? 'fail' : 'pass',
            checks,
            fileSize: finalPdfStats.size,
            artifacts: [
                {
                    label: 'Paragraph Block Save',
                    kind: 'image',
                    filename: screenshotName,
                },
                {
                    label: 'Paragraph Block PDF',
                    kind: 'pdf',
                    filename: pdfName,
                },
            ],
            metadata: {
                document_id: documentId,
                raw_paragraph_block: rawParagraphBlock,
                sibling_paragraph_blocks: siblingParagraphBlocks,
                paragraph_lines: paragraphLines,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphBlankLineIntegrityFlow() {
    const test = TESTS.test_6_paragraph_blank_line_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_blank_line_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_blank_line_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_BLANK_LINE_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_BLANK_LINE_START);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_BLANK_LINE_END);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        const paragraphLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'blank_line_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block that includes both the first paragraph and the post-blank-line paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 240),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'blank_line_paragraph_not_split_into_raw_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved annotation with an explicit blank line is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block.text,
                    block_num: block.block_num,
                    line_count: block.line_count,
                }))),
            },
            {
                item: 'blank_line_separator_preserved_in_raw_text',
                result: rawParagraphBlock && String(rawParagraphBlock.text || '').includes('\n\n') ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves the explicit blank-line separator as a double newline.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
            {
                item: 'blank_line_paragraph_has_multiple_lines',
                result: paragraphLines.length >= 4 ? 'PASS' : 'FAIL',
                description: 'The saved blank-line paragraph still exposes multiple wrapped lines inside the merged block.',
                detail: JSON.stringify(paragraphLines),
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Paragraph Blank Line Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Blank Line PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_blank_line_block: rawParagraphBlock,
                paragraph_lines: paragraphLines,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphIndentPreservedFlow() {
    const test = TESTS.test_13_paragraph_indent_preserved;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_indent_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_indent_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_INDENTED_TEXT,
            keepBounds: true,
            left: 48,
            top: 96,
            width: 320,
            height: 280,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const rawParagraphBlock = rawBlocks.find((block) => normalizeTypographicAscii(block?.text || '').includes(normalizeTypographicAscii(PARAGRAPH_INDENTED_START))) || null;
        const blockLines = rawParagraphBlock ? extractBlockLines(extraction, rawParagraphBlock) : [];
        const lineBBoxes = Array.isArray(rawParagraphBlock?.line_bboxes) ? rawParagraphBlock.line_bboxes : [];
        const normalizedLineLefts = lineBBoxes
            .filter((bbox) => Array.isArray(bbox) && bbox.length >= 4)
            .map((bbox) => Number(bbox[0]) || 0);
        const firstLineLeft = normalizedLineLefts[0] ?? null;
        const followingLineLefts = normalizedLineLefts.slice(1);
        const minimumFollowingLeft = followingLineLefts.length ? Math.min(...followingLineLefts) : null;
        const indentDelta = (firstLineLeft !== null && minimumFollowingLeft !== null)
            ? firstLineLeft - minimumFollowingLeft
            : null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'indented_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block for the indented paragraph.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 220),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'indented_paragraph_has_multiple_lines',
                result: rawParagraphBlock && blockLines.length >= 4 ? 'PASS' : 'FAIL',
                description: 'The saved indented paragraph still exposes multiple lines inside one block.',
                detail: JSON.stringify(blockLines),
            },
            {
                item: 'first_line_indent_preserved',
                result: indentDelta !== null && indentDelta >= 8 ? 'PASS' : 'FAIL',
                description: 'The saved block preserves a measurable first-line indent instead of flattening all lines to the same left edge.',
                detail: JSON.stringify({
                    first_line_left: firstLineLeft,
                    minimum_following_left: minimumFollowingLeft,
                    indent_delta: indentDelta,
                    line_bboxes: lineBBoxes,
                }),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Paragraph Indent Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Indent PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_paragraph_block: rawParagraphBlock,
                block_lines: blockLines,
                line_bboxes: lineBBoxes,
                indent_delta: indentDelta,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runShortIntroBlankLineIntegrityFlow() {
    const test = TESTS.test_7_short_intro_blank_line_integrity;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'short_intro_blank_line_save');
    const pdfName = buildArtifactName(test.key, runToken, 'short_intro_blank_line_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_SHORT_INTRO_BLANK_LINE_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: 420,
            height: PARAGRAPH_NARROW_HEIGHT,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const expectedStart = normalizeTypographicAscii(PARAGRAPH_SHORT_INTRO_START);
        const expectedTail = normalizeTypographicAscii(PARAGRAPH_SHORT_INTRO_END);
        const rawParagraphBlock = rawBlocks.find((block) => {
            const text = normalizeTypographicAscii(block?.text || '');
            return text.includes(expectedStart) && text.includes(expectedTail);
        }) || null;

        const siblingParagraphBlocks = rawParagraphBlock
            ? rawBlocks.filter((block) => (
                Math.abs((Number(block?.left) || 0) - (Number(rawParagraphBlock?.left) || 0)) <= 3
                && Math.abs((Number(block?.font_size) || 0) - (Number(rawParagraphBlock?.font_size) || 0)) <= 0.75
                && normalize(block?.font || '') === normalize(rawParagraphBlock?.font || '')
                && (Number(block?.top) || 0) >= ((Number(rawParagraphBlock?.top) || 0) - 1)
                && ((Number(block?.top) || 0) + (Number(block?.height) || 0)) <= (((Number(rawParagraphBlock?.top) || 0) + (Number(rawParagraphBlock?.height) || 0)) + 1)
            ))
            : [];

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'short_intro_paragraph_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The saved extraction contains one raw block spanning the short intro paragraph and later paragraph text.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text_preview: String(rawParagraphBlock.text || '').slice(0, 260),
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'short_intro_paragraph_not_split_into_raw_blocks',
                result: rawParagraphBlock && siblingParagraphBlocks.length === 1 ? 'PASS' : 'FAIL',
                description: 'The saved short-intro blank-line annotation is not split into multiple raw blocks on the same column and font.',
                detail: JSON.stringify(siblingParagraphBlocks.map((block) => ({
                    bbox: blockBBox(block),
                    text: block.text,
                    block_num: block.block_num,
                    line_count: block.line_count,
                }))),
            },
            {
                item: 'short_intro_blank_line_separator_preserved',
                result: rawParagraphBlock && (String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length >= 2 ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves both explicit blank-line separators as double newlines.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Short Intro Blank Line Save', kind: 'image', filename: screenshotName },
                { label: 'Short Intro Blank Line PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_short_intro_block: rawParagraphBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runEditedParagraphNewlinesPreservedFlow() {
    const test = TESTS.test_8_edited_paragraph_newlines_preserved;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'edited_paragraph_newlines_save');
    const pdfName = buildArtifactName(test.key, runToken, 'edited_paragraph_newlines_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_EDITED_NEWLINES_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: 180,
            height: 260,
        });

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];
        const rawParagraphBlock = rawBlocks.find((block) => (
            normalizeTypographicAscii(block?.text || '') === normalizeTypographicAscii(PARAGRAPH_EDITED_NEWLINES_TEXT)
        )) || null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'edited_newlines_saved_as_single_raw_block',
                result: rawParagraphBlock ? 'PASS' : 'FAIL',
                description: 'The edited multiline annotation is saved back as one raw extraction block.',
                detail: rawParagraphBlock
                    ? JSON.stringify({
                        bbox: blockBBox(rawParagraphBlock),
                        line_count: rawParagraphBlock.line_count,
                        text: rawParagraphBlock.text,
                    })
                    : JSON.stringify(rawBlocks, null, 2),
            },
            {
                item: 'edited_newlines_text_exactly_preserved',
                result: rawParagraphBlock && String(rawParagraphBlock.text || '') === PARAGRAPH_EDITED_NEWLINES_TEXT ? 'PASS' : 'FAIL',
                description: 'The saved raw block preserves the explicit edited blank lines exactly.',
                detail: rawParagraphBlock ? JSON.stringify(rawParagraphBlock.text) : 'null',
            },
            {
                item: 'edited_newlines_separator_count_preserved',
                result: rawParagraphBlock && (String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length === 3 ? 'PASS' : 'FAIL',
                description: 'The saved raw block still contains the three explicit blank-line separators.',
                detail: rawParagraphBlock ? String((String(rawParagraphBlock.text || '').match(/\n\n/g) || []).length) : 'null',
            },
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Edited Paragraph Newlines Save', kind: 'image', filename: screenshotName },
                { label: 'Edited Paragraph Newlines PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                raw_edited_newlines_block: rawParagraphBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runParagraphPositionAccuracyFlow() {
    const test = TESTS.test_9_paragraph_position_accuracy;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'paragraph_position_save');
    const pdfName = buildArtifactName(test.key, runToken, 'paragraph_position_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Legal',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const positionCase of PARAGRAPH_POSITION_CASES) {
            await createTextAnnotationAt(page, positionCase.fragment, 30, 120);
            await updateTextAnnotation(page, positionCase.fragment, {
                text: positionCase.text,
                keepBounds: true,
                left: positionCase.left,
                top: positionCase.top,
                width: positionCase.width,
                height: positionCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);

        const afterSaveMetrics = {};
        for (const positionCase of PARAGRAPH_POSITION_CASES) {
            afterSaveMetrics[positionCase.fragment] = await readTextAnnotationMetrics(page, positionCase.fragment);
        }
        await capturePageScreenshot(page, screenshotPath);

        const referenceCase = PARAGRAPH_POSITION_CASES[0];
        const referenceAfter = afterSaveMetrics[referenceCase.fragment];
        const referenceOffsetX = (referenceAfter?.left || 0) - referenceCase.left;
        const referenceOffsetY = (referenceAfter?.top || 0) - referenceCase.top;

        const positionChecks = PARAGRAPH_POSITION_CASES.map((positionCase) => {
            const after = afterSaveMetrics[positionCase.fragment];
            const offsetX = (after?.left || 0) - positionCase.left;
            const offsetY = (after?.top || 0) - positionCase.top;
            const offsetErrorX = Math.abs(offsetX - referenceOffsetX);
            const offsetErrorY = Math.abs(offsetY - referenceOffsetY);

            return {
                item: `${normalize(positionCase.fragment).toLowerCase().replace(/\s+/g, '_')}_anchor_offset_consistent`,
                result: offsetErrorX <= PARAGRAPH_POSITION_TOLERANCE && offsetErrorY <= PARAGRAPH_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: `${positionCase.fragment} keeps a consistent placement offset after save and reload.`,
                detail: `target=(${positionCase.left}, ${positionCase.top}) after=(${after?.left?.toFixed?.(2) ?? after?.left}, ${after?.top?.toFixed?.(2) ?? after?.top}) offset=(${offsetX.toFixed(2)}, ${offsetY.toFixed(2)}) reference_offset=(${referenceOffsetX.toFixed(2)}, ${referenceOffsetY.toFixed(2)}) error=(${offsetErrorX.toFixed(2)}, ${offsetErrorY.toFixed(2)})`,
            };
        });

        const relativeChecks = [];
        for (let index = 1; index < PARAGRAPH_POSITION_CASES.length; index += 1) {
            const current = PARAGRAPH_POSITION_CASES[index];
            const previous = PARAGRAPH_POSITION_CASES[index - 1];
            const afterCurrent = afterSaveMetrics[current.fragment];
            const afterPrevious = afterSaveMetrics[previous.fragment];
            const targetDeltaX = current.left - previous.left;
            const targetDeltaY = current.top - previous.top;
            const afterDeltaX = (afterCurrent?.left || 0) - (afterPrevious?.left || 0);
            const afterDeltaY = (afterCurrent?.top || 0) - (afterPrevious?.top || 0);
            const deltaXError = Math.abs(afterDeltaX - targetDeltaX);
            const deltaYError = Math.abs(afterDeltaY - targetDeltaY);
            relativeChecks.push({
                item: `${normalize(previous.fragment).toLowerCase().replace(/\s+/g, '_')}_to_${normalize(current.fragment).toLowerCase().replace(/\s+/g, '_')}_relative_position_preserved`,
                result: deltaXError <= PARAGRAPH_POSITION_TOLERANCE && deltaYError <= PARAGRAPH_POSITION_TOLERANCE ? 'PASS' : 'FAIL',
                description: `${previous.fragment} and ${current.fragment} keep their relative spacing after save and reload.`,
                detail: `target_delta=(${targetDeltaX.toFixed(2)}, ${targetDeltaY.toFixed(2)}) after_delta=(${afterDeltaX.toFixed(2)}, ${afterDeltaY.toFixed(2)}) error=(${deltaXError.toFixed(2)}, ${deltaYError.toFixed(2)})`,
            });
        }

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            ...positionChecks,
            ...relativeChecks,
        ];

        const status = checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail';
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status,
            checks,
            artifacts: [
                { label: 'Paragraph Position Save', kind: 'image', filename: screenshotName },
                { label: 'Paragraph Position PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                reference_offset: {
                    left: referenceOffsetX,
                    top: referenceOffsetY,
                },
                after_save_metrics: afterSaveMetrics,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runDeleteAllPromotedTextSaveFlow() {
    const test = TESTS.test_10_delete_all_promoted_text_save;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'delete_all_promoted_text_save');
    const pdfName = buildArtifactName(test.key, runToken, 'delete_all_promoted_text_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page, {
            pageSize: 'Letter',
            orientation: 'portrait',
        });
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        await createTextAnnotationAt(page, PARAGRAPH_SEED_TEXT, 30, 120);
        await updateTextAnnotation(page, PARAGRAPH_SEED_TEXT, {
            text: PARAGRAPH_TEXT,
            keepBounds: true,
            left: 30,
            top: 120,
            width: PARAGRAPH_NARROW_WIDTH,
            height: PARAGRAPH_NARROW_HEIGHT,
        });
        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await page.waitForFunction(() => (
            typeof annotations !== 'undefined'
            && Array.isArray(annotations)
            && annotations.some((annotation) => annotation?.promotedFromExtraction || annotation?.savedTextOverlay)
        ), null, { timeout: 60000 });

        const reloadedTextAnnotationState = await page.evaluate(() => ({
            promotedCount: annotations.filter((annotation) => annotation?.promotedFromExtraction).length,
            savedOverlayCount: annotations.filter((annotation) => annotation?.savedTextOverlay).length,
        }));

        await page.evaluate(() => {
            const originalConfirm = window.confirm;
            window.confirm = () => true;
            try {
                document.getElementById('remove-all-annotations')?.click();
            } finally {
                window.confirm = originalConfirm;
            }
        });
        await page.waitForFunction(() => typeof annotations !== 'undefined' && annotations.length === 0, null, { timeout: 30000 });

        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await capturePageScreenshot(page, screenshotPath);

        const extraction = await fetchExtraction(page, documentId);
        const paragraphBlock = extractMatchingBlock(extraction, PARAGRAPH_TEXT_FRAGMENT);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'text_annotations_loaded_before_delete',
                result: (reloadedTextAnnotationState.promotedCount + reloadedTextAnnotationState.savedOverlayCount) > 0 ? 'PASS' : 'FAIL',
                description: 'The saved paragraph reloaded as editable text annotations before deletion.',
                detail: JSON.stringify(reloadedTextAnnotationState),
            },
            {
                item: 'empty_annotation_save_completed',
                result: 'PASS',
                description: 'Saving after deleting all promoted annotations completed through the PDF save flow.',
                detail: 'save button accepted an empty remaining annotation set',
            },
            {
                item: 'deleted_promoted_paragraph_absent_after_save',
                result: paragraphBlock ? 'FAIL' : 'PASS',
                description: 'The deleted promoted paragraph is absent from the saved PDF extraction after save.',
                detail: paragraphBlock ? JSON.stringify(paragraphBlock) : 'paragraph text absent after delete-all save',
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Delete All Promoted Text Save', kind: 'image', filename: screenshotName },
                { label: 'Delete All Promoted Text PDF', kind: 'pdf', filename: pdfName },
            ],
            metadata: {
                document_id: documentId,
                reloaded_text_annotation_state: reloadedTextAnnotationState,
                residual_block: paragraphBlock,
            },
        });
    } catch (error) {
        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'fail',
            checks: [],
            error: error instanceof Error ? error.message : String(error),
            metadata: {
                document_id: documentId,
            },
        });
    } finally {
        await browser.close();
    }
}

async function runSeparateLinesNotGroupedFlow() {
    const test = TESTS.test_11_separate_lines_not_grouped;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'separate_lines_save');
    const pdfName = buildArtifactName(test.key, runToken, 'separate_lines_save', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const lineCase of PARAGRAPH_SEPARATE_LINE_CASES) {
            await createTextAnnotationAt(page, lineCase.fragment, 30, 120);
            await updateTextAnnotation(page, lineCase.fragment, {
                text: lineCase.text,
                keepBounds: true,
                left: lineCase.left,
                top: lineCase.top,
                width: lineCase.width,
                height: lineCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);
        await forceRefreshOverlay(page, documentId);
        const extraction = await fetchExtraction(page, documentId);
        const rawBlocks = extraction?.extraction_data?.[0]?.blocks || [];

        const matchedBlocks = PARAGRAPH_SEPARATE_LINE_CASES.map((lineCase) => {
            const block = rawBlocks.find((candidate) => normalizeTypographicAscii(candidate?.text || '').includes(normalizeTypographicAscii(lineCase.text)));
            return {
                fragment: lineCase.fragment,
                text: lineCase.text,
                block,
            };
        });

        const uniqueBlockNums = new Set(
            matchedBlocks
                .map((entry) => entry.block?.block_num)
                .filter((value) => value !== undefined && value !== null)
        );
        const combinedBlock = rawBlocks.find((block) => PARAGRAPH_SEPARATE_LINE_CASES.every((lineCase) => (
            normalizeTypographicAscii(block?.text || '').includes(normalizeTypographicAscii(lineCase.text))
        ))) || null;

        await capturePageScreenshot(page, screenshotPath);

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'separate_lines_each_saved_as_raw_block',
                result: matchedBlocks.every((entry) => entry.block) ? 'PASS' : 'FAIL',
                description: 'Each one-line text box is present in the saved extraction as its own raw block candidate.',
                detail: JSON.stringify(matchedBlocks.map((entry) => ({
                    fragment: entry.fragment,
                    block_num: entry.block?.block_num ?? null,
                    bbox: entry.block ? blockBBox(entry.block) : null,
                    text: entry.block?.text ?? null,
                }))),
            },
            {
                item: 'separate_lines_do_not_share_one_raw_block',
                result: uniqueBlockNums.size === PARAGRAPH_SEPARATE_LINE_CASES.length ? 'PASS' : 'FAIL',
                description: 'The three separate one-line text boxes do not collapse into the same raw block after save.',
                detail: JSON.stringify({
                    unique_block_count: uniqueBlockNums.size,
                    block_nums: Array.from(uniqueBlockNums),
                }),
            },
            {
                item: 'no_combined_paragraph_block_contains_all_three_lines',
                result: combinedBlock ? 'FAIL' : 'PASS',
                description: 'The saved extraction does not contain one merged paragraph block holding all three independent lines.',
                detail: combinedBlock ? JSON.stringify({
                    block_num: combinedBlock.block_num,
                    bbox: blockBBox(combinedBlock),
                    text: combinedBlock.text,
                }) : 'no combined block',
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Separate Lines Save', kind: 'image', filename: screenshotName },
                { label: 'Separate Lines PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                matched_blocks: matchedBlocks.map((entry) => ({
                    fragment: entry.fragment,
                    block: entry.block,
                })),
                combined_block: combinedBlock,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

async function runTinySavedLabelsReloadSeparatelyFlow() {
    const test = TESTS.test_12_tiny_saved_labels_reload_separately;
    ensureOutputDir();

    const runToken = buildRunToken();
    const screenshotName = buildArtifactName(test.key, runToken, 'tiny_saved_labels_reload');
    const pdfName = buildArtifactName(test.key, runToken, 'tiny_saved_labels_reload', 'pdf');
    const screenshotPath = path.join(OUTPUT_DIR, screenshotName);
    const pdfPath = path.join(OUTPUT_DIR, pdfName);

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });
    let documentId = null;

    try {
        documentId = await createBlankDocument(page);
        await waitForEditorReady(page);
        await clearAnnotationSessionState(page, documentId);

        for (const lineCase of PARAGRAPH_TINY_SEPARATE_LINE_CASES) {
            await createTextAnnotationAt(page, lineCase.seed, 30, 120);
            await updateTextAnnotation(page, lineCase.seed, {
                text: lineCase.text,
                keepBounds: true,
                left: lineCase.left,
                top: lineCase.top,
                width: lineCase.width,
                height: lineCase.height,
            });
        }

        await clickOutsideFirstPage(page);
        await waitForAnnotationSave(page);
        await downloadSavedPdf(page, documentId, pdfPath);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);
        await page.waitForFunction(() => (
            typeof annotations !== 'undefined'
            && Array.isArray(annotations)
            && annotations.length >= 4
        ), null, { timeout: 60000 });

        const reloadedAnnotations = await page.evaluate(() => annotations.map((annotation) => ({
            id: annotation?.id || null,
            text: annotation?.text || '',
            left: Number(annotation?.pdfX) || 0,
            top: Number(annotation?.pdfY) || 0,
            savedTextOverlay: Boolean(annotation?.savedTextOverlay),
            promotedFromExtraction: Boolean(annotation?.promotedFromExtraction),
        })));
        await capturePageScreenshot(page, screenshotPath);

        const tinyLabels = reloadedAnnotations.filter((annotation) => (
            annotation.text === 'no' || annotation.text === 'yes'
        ));
        const mergedTextAreas = reloadedAnnotations.filter((annotation) => /\n/.test(annotation.text || ''));
        const savedOverlayCount = tinyLabels.filter((annotation) => annotation.savedTextOverlay).length;

        const checks = [
            {
                item: 'blank_pdf_created',
                result: documentId ? 'PASS' : 'FAIL',
                description: 'A fresh blank PDF was created through the frontend blank-PDF flow.',
                detail: `document=${documentId}`,
            },
            {
                item: 'tiny_labels_reload_as_four_annotations',
                result: tinyLabels.length === 4 ? 'PASS' : 'FAIL',
                description: 'The four tiny stacked labels reload as four individual annotations.',
                detail: JSON.stringify(tinyLabels),
            },
            {
                item: 'tiny_labels_reload_from_saved_annotation_state',
                result: savedOverlayCount === 4 ? 'PASS' : 'FAIL',
                description: 'The reloaded tiny labels come from saved annotation state rather than promoted extraction grouping.',
                detail: JSON.stringify(tinyLabels.map((annotation) => ({
                    text: annotation.text,
                    savedTextOverlay: annotation.savedTextOverlay,
                    promotedFromExtraction: annotation.promotedFromExtraction,
                }))),
            },
            {
                item: 'tiny_labels_not_merged_into_multiline_annotation',
                result: mergedTextAreas.length === 0 ? 'PASS' : 'FAIL',
                description: 'Reloading the editor does not merge the tiny labels into one multiline text area.',
                detail: JSON.stringify(mergedTextAreas),
            },
        ];

        return buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: checks.every((check) => check.result === 'PASS') ? 'pass' : 'fail',
            checks,
            artifacts: [
                { label: 'Tiny Saved Labels Reload', kind: 'image', filename: screenshotName },
                { label: 'Tiny Saved Labels PDF', kind: 'pdf', filename: pdfName },
            ],
            fileSize: fs.existsSync(pdfPath) ? fs.statSync(pdfPath).size : 0,
            metadata: {
                document_id: documentId,
                reloaded_annotations: reloadedAnnotations,
            },
        });
    } finally {
        if (documentId) {
            try {
                await deleteDocument(page, documentId);
            } catch (_error) {
                // Ignore cleanup failures so the primary test result survives.
            }
        }
        await browser.close();
    }
}

function listFiles() {
    const files = Object.values(TESTS).map((test) => ({
        filename: `${test.key}.pdf`,
        path: test.key,
        description: test.description,
        test_category: 'PDF Tests',
        section_name: test.label,
    }));

    return {
        total: files.length,
        files,
    };
}

async function runSingleTest(key) {
    const test = TESTS[key];
    if (!test) {
        outputJson(buildResult({
            testKey: key || 'unknown',
            label: 'Unknown PDF Test',
            description: '',
            status: 'error',
            error: `Unknown PDF test: ${key || '(missing)'}`,
        }));
        process.exit(1);
    }

    try {
        const result = await test.run();
        outputJson(result);
        process.exit(result.status === 'pass' ? 0 : 1);
    } catch (error) {
        outputJson(buildResult({
            testKey: test.key,
            label: test.label,
            description: test.description,
            status: 'error',
            error: error.stack || String(error),
        }));
        process.exit(1);
    }
}

async function main() {
    const args = process.argv.slice(2);

    if (args.includes('--list-files')) {
        outputJson(listFiles());
        return;
    }

    if (args.includes('--single-test')) {
        const index = args.indexOf('--single-test');
        const key = args[index + 1];
        await runSingleTest(key);
        return;
    }

    outputJson({
        success: false,
        message: 'Usage: --list-files | --single-test <key>',
    });
    process.exit(1);
}

main();
