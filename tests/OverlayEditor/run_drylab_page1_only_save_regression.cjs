#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'drylab_full.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

const PAGE_INDEX = 0;
const ORIGINAL_PAGE_ONE_TEXT = [
    "Welcome to our first newsletter of 2017! It's",
    'been a while since the last one, and a lot has',
    'happened. We promise to keep them coming',
    'every two months hereafter, and permit',
    'ourselves to make this one rather long. The',
    'big news is the beginnings of our launch in',
    'the American market, but there are also',
    'interesting updates on sales, development,',
    'mentors and (of course) the investment',
    'round that closed in January.',
].join(' ');
const UPDATED_PAGE_ONE_TEXT = `${ORIGINAL_PAGE_ONE_TEXT} Test`;

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
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

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
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
    await page.waitForTimeout(2500);
}

async function forceRefreshOverlay(page, documentId) {
    const response = await postJson(page, `/documents/${documentId}/prepare-overlay?force_refresh=1`);
    if (!response.ok) {
        throw new Error(`prepare-overlay failed: ${JSON.stringify(response)}`);
    }
}

async function waitForEditorReady(page) {
    await page.waitForSelector('.page[data-page-index="0"] canvas, .page-wrapper[data-page-number="1"] canvas', { timeout: 30000 });
    await page.waitForTimeout(1500);
}

async function findOverlayField(page, expectedText) {
    const key = await page.evaluate((target) => {
        const normalizeInner = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const wanted = normalizeInner(target);
        const wantedPrefix = wanted.slice(0, 120);
        const fields = Array.from(document.querySelectorAll('.overlay-field'));
        const match = fields.find((el) => {
            const title = normalizeInner(el.title || '');
            const text = normalizeInner(el.innerText || '');
            return title === wanted
                || text === wanted
                || title.includes(wanted)
                || text.includes(wanted)
                || title.includes(wantedPrefix)
                || text.includes(wantedPrefix);
        });
        return match ? (match.dataset.wordIndex || '') : '';
    }, expectedText);

    if (!key) {
        throw new Error(`Missing overlay field matching page-1 paragraph: ${expectedText}`);
    }

    return page.locator(`.overlay-field[data-word-index="${key}"]`).first();
}

async function editField(page, expectedText, nextText) {
    const locator = await findOverlayField(page, expectedText);
    await locator.scrollIntoViewIfNeeded();
    await locator.dblclick();
    await page.waitForTimeout(500);
    await locator.evaluate((el, updatedText) => {
        const editor = el.querySelector('[contenteditable]');
        if (!editor) {
            throw new Error(`Missing contenteditable editor for ${el.title}`);
        }
        editor.innerText = updatedText;
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }, nextText);
    await page.waitForTimeout(900);
}

async function savePdf(page) {
    const [response] = await Promise.all([
        page.waitForResponse((candidate) => {
            if (candidate.request().method() !== 'POST') {
                return false;
            }
            const url = candidate.url();
            return url.includes('/save-edits') || url.includes('/live-save');
        }, { timeout: 120000 }),
        page.locator('#save-btn').click(),
    ]);

    if (!response.ok()) {
        throw new Error(`Save request failed: ${response.status()} ${await response.text()}`);
    }

    await page.waitForTimeout(3000);
}

async function collectPendingOverlayEdits(page) {
    return page.evaluate(() => {
        if (typeof overlayEditedFields === 'undefined' || !(overlayEditedFields instanceof Map)) {
            return [];
        }
        return Array.from(overlayEditedFields.values()).map((edit) => ({
            page_number: Number(edit?.page_number),
            original_text: String(edit?.original_text || ''),
            new_text: String(edit?.new_text || ''),
        }));
    });
}

async function downloadSavedPdf(page, documentId, outputPath) {
    const result = spawnSync('curl', [
        '-fsSL',
        `${BASE_URL}/documents/${documentId}/file?v=${Date.now()}`,
        '-o',
        outputPath,
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Failed to download saved PDF via curl.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }
}

function compareUneditedPagesStrictly(originalPdfPath, savedPdfPath, outputDir, documentId) {
    const compareScript = `
import hashlib
import json
import os
import sys
import fitz
from PIL import Image

original_pdf_path, saved_pdf_path, output_dir, document_id = sys.argv[1:5]
matrix = fitz.Matrix(2, 2)

original_doc = fitz.open(original_pdf_path)
saved_doc = fitz.open(saved_pdf_path)

if original_doc.page_count != saved_doc.page_count:
    print(json.dumps({
        "ok": False,
        "error": "page_count_mismatch",
        "original_page_count": original_doc.page_count,
        "saved_page_count": saved_doc.page_count,
    }, indent=2))
    sys.exit(2)

mismatches = []
checked = []

for page_index in range(1, original_doc.page_count):
    original_page = original_doc[page_index]
    saved_page = saved_doc[page_index]
    original_pix = original_page.get_pixmap(matrix=matrix, alpha=False)
    saved_pix = saved_page.get_pixmap(matrix=matrix, alpha=False)

    if (original_pix.width, original_pix.height) != (saved_pix.width, saved_pix.height):
        mismatches.append({
            "page_number": page_index + 1,
            "reason": "dimension_mismatch",
            "original_size": [original_pix.width, original_pix.height],
            "saved_size": [saved_pix.width, saved_pix.height],
        })
        continue

    original_hash = hashlib.sha256(original_pix.samples).hexdigest()
    saved_hash = hashlib.sha256(saved_pix.samples).hexdigest()
    checked.append({
        "page_number": page_index + 1,
        "original_hash": original_hash,
        "saved_hash": saved_hash,
    })

    if original_hash != saved_hash:
        original_png = os.path.join(output_dir, f"drylab_doc{document_id}_page{page_index + 1}_original.png")
        saved_png = os.path.join(output_dir, f"drylab_doc{document_id}_page{page_index + 1}_saved.png")
        Image.frombytes("RGB", [original_pix.width, original_pix.height], original_pix.samples).save(original_png)
        Image.frombytes("RGB", [saved_pix.width, saved_pix.height], saved_pix.samples).save(saved_png)
        mismatches.append({
            "page_number": page_index + 1,
            "reason": "pixel_hash_mismatch",
            "original_hash": original_hash,
            "saved_hash": saved_hash,
            "original_png": original_png,
            "saved_png": saved_png,
        })

original_doc.close()
saved_doc.close()

result = {
    "ok": len(mismatches) == 0,
    "checked_pages": checked,
    "mismatches": mismatches,
}
print(json.dumps(result, indent=2))
if mismatches:
    sys.exit(2)
`;

    const result = spawnSync('python3', [
        '-c',
        compareScript,
        originalPdfPath,
        savedPdfPath,
        outputDir,
        String(documentId),
    ], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`Drylab non-edited page comparison failed.\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`);
    }

    return JSON.parse(result.stdout);
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1800 } });
    page.setDefaultTimeout(30000);
    page.setDefaultNavigationTimeout(90000);
    let savePayload = null;
    const directAnnotationRequests = [];
    const capturedEdits = [];
    const capturedEditPages = new Set();

    page.on('request', (request) => {
        if (request.method() !== 'POST') {
            return;
        }
        if (request.url().includes('/apply-annotations-direct')) {
            directAnnotationRequests.push({
                url: request.url(),
                body: request.postData(),
            });
            return;
        }
        if (request.url().includes('/live-save')) {
            try {
                const payload = request.postDataJSON();
                if (payload && payload.edit && Number.isFinite(Number(payload.edit.page_number))) {
                    capturedEdits.push(payload.edit);
                    capturedEditPages.add(Number(payload.edit.page_number));
                }
            } catch (_error) {
                // Ignore malformed debug payloads here.
            }
            return;
        }
        if (request.url().includes('/save-edits')) {
            try {
                const payload = request.postDataJSON();
                if (payload && Array.isArray(payload.edits) && payload.edits.length > 0) {
                    savePayload = payload;
                    payload.edits.forEach((edit) => {
                        capturedEdits.push(edit);
                        if (Number.isFinite(Number(edit.page_number))) {
                            capturedEditPages.add(Number(edit.page_number));
                        }
                    });
                } else if (savePayload === null) {
                    savePayload = payload;
                }
            } catch (_error) {
                if (savePayload === null) {
                    savePayload = request.postData();
                }
            }
        }
    });

    try {
        console.log('Uploading drylab_full.pdf...');
        const documentId = await uploadPdf(page);
        console.log(`Uploaded as document ${documentId}`);
        console.log('Waiting for editor canvas...');
        await waitForEditorReady(page);
        console.log('Preparing overlay extraction...');
        await forceRefreshOverlay(page, documentId);
        await waitForEditorReady(page);
        console.log('Activating overlay editor...');
        await activateOverlay(page);
        console.log('Editing page 1 paragraph...');
        await editField(page, ORIGINAL_PAGE_ONE_TEXT, UPDATED_PAGE_ONE_TEXT);
        const pendingOverlayEdits = await collectPendingOverlayEdits(page);
        console.log('Saving page 1 edit...');
        await savePdf(page);

        if (pendingOverlayEdits.length < 1) {
            throw new Error(`Expected pending overlay edits before save, got pending=${JSON.stringify(pendingOverlayEdits)} savePayload=${JSON.stringify(savePayload)}`);
        }

        const editedPages = Array.from(new Set(pendingOverlayEdits.map((edit) => Number(edit.page_number)).filter(Number.isFinite))).sort((a, b) => a - b);
        if (editedPages.length !== 1 || editedPages[0] !== 1) {
            throw new Error(`Expected pending overlay edits to touch only page 1, got pages: ${JSON.stringify(editedPages)} edits=${JSON.stringify(pendingOverlayEdits)}`);
        }
        if (directAnnotationRequests.length > 0) {
            throw new Error(`Overlay-only page-1 save should not call apply-annotations-direct, got ${JSON.stringify(directAnnotationRequests)}`);
        }

        const targetEdit = pendingOverlayEdits.find((edit) =>
            normalize(edit.original_text).includes(normalize(ORIGINAL_PAGE_ONE_TEXT).slice(0, 120))
            || normalize(edit.new_text).includes(normalize(UPDATED_PAGE_ONE_TEXT).slice(0, 120))
        );
        if (!targetEdit) {
            throw new Error(`Missing expected page-1 paragraph edit in pending edits: ${JSON.stringify(pendingOverlayEdits)}`);
        }

        const savedPdfPath = path.join(OUTPUT_DIR, `drylab_doc${documentId}.pdf`);
        console.log(`Downloading saved PDF for document ${documentId}...`);
        await downloadSavedPdf(page, documentId, savedPdfPath);

        console.log('Comparing non-edited pages against original...');
        const compareResult = compareUneditedPagesStrictly(PDF_PATH, savedPdfPath, OUTPUT_DIR, documentId);

        console.log('drylab page-1-only save regression passed');
        console.log(JSON.stringify({
            documentId,
            edited_pages: editedPages,
            saved_pdf: savedPdfPath,
            compare: compareResult,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
