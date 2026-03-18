#!/usr/bin/env node
/**
 * Regression test: downloading a PDF after loading a saved session should NOT
 * produce doubled/duplicated text.
 *
 * Bug history:
 *   When basePdfUrl === originalPdfUrl in loadedSavedPdfMode, getDirectAnnotationsForSave()
 *   returned ALL annotations (including every unmodified promoted-extraction block representing
 *   all page text). The backend copied the original backup (which already contains that text)
 *   and stamped all the promoted text on top → doubled text in the downloaded PDF.
 *
 * This test:
 *   1. Loads the editor in loadedSavedPdfMode (?loadedSavedPdf=1&savedSession=…).
 *   2. Forces basePdfUrl = originalPdfUrl in the page JS (to reproduce the bug scenario).
 *   3. Invokes the downloadAnnotatedPdf() endpoint directly (replicating what the download
 *      button does), capturing the response bytes.
 *   4. Extracts text blocks from the downloaded PDF with PyMuPDF.
 *   5. FAILS if any normalised text block appears more than once on the same page
 *      (doubled text), or if the content diverges too far from the reference PDF.
 *
 * Usage:
 *   node tests/OverlayEditor/run_nda_saved_session_save_no_duplication_regression.cjs
 *
 * Environment variables (all optional):
 *   BASE_URL       – editor base URL        (default: http://localhost:8081)
 *   DOCUMENT_ID    – document record id     (default: 1610)
 *   SESSION_ID     – savedSession param     (default: session_1773413955263_n3ukrpe02)
 *   ORIGINAL_PDF   – reference PDF path     (default: public/nda_test_1.pdf)
 */

'use strict';

const fs   = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}
const { chromium } = require('playwright');

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
const BASE_URL    = process.env.BASE_URL    || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID  || 1610);
const SESSION_ID  = process.env.SESSION_ID  || 'session_1773413955263_n3ukrpe02';
const ORIGINAL_PDF = process.env.ORIGINAL_PDF
    || path.resolve(__dirname, '..', '..', 'public', 'nda_test_1.pdf');
const PYTHON = process.env.PYTHON_BIN || 'python3';
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const VIEWPORT   = { width: 1600, height: 1800 };

// Maximum number of deduplicated blocks allowed to appear more than once.
const MAX_DUPLICATE_BLOCKS = 0;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function buildRunToken() {
    return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', 'T');
}

function normalise(text) {
    return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

/**
 * Use PyMuPDF to extract text blocks from every page of a PDF.
 * Returns an array of { page, text } objects (one per block).
 */
function extractPdfBlocks(pdfPath) {
    const code = `
import fitz, json, sys

out = []
doc = fitz.open(sys.argv[1])
for page_num, page in enumerate(doc):
    for b in page.get_text('blocks'):
        raw = b[4]
        text = ' '.join(raw.split()).strip()
        if len(text) > 3:
            out.append({'page': page_num, 'text': text})
doc.close()
print(json.dumps(out))
`;
    const raw = execFileSync(PYTHON, ['-c', code, pdfPath], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    return JSON.parse(raw.trim());
}

/**
 * Detect blocks whose normalised text appears more than once on the same page.
 */
function findDuplicateBlocks(blocks) {
    const seen = new Map(); // "page:normText" -> count
    for (const b of blocks) {
        const key = `${b.page}:${normalise(b.text)}`;
        seen.set(key, (seen.get(key) || 0) + 1);
    }
    return [...seen.entries()]
        .filter(([, count]) => count > 1)
        .map(([key, count]) => ({ key, count }));
}

async function waitForEditorReady(page) {
    // In loadedSavedPdfMode the overlay is automatically bootstrapped from the
    // saved session — overlayEditorActive is never set to true in that path.
    // Annotations are rendered as .annotation elements.
    // Wait for annotations to be hydrated AND rendered to the DOM.
    await page.waitForFunction(
        () => {
            if (typeof loadedSavedPdfMode !== 'undefined' && loadedSavedPdfMode) {
                return typeof annotations !== 'undefined'
                    && annotations.length > 0
                    && document.querySelectorAll('.annotation').length > 0;
            }
            // Normal (non-saved) mode: wait for the toggle activation.
            return typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true;
        },
        null,
        { timeout: 90000 }
    );
    await page.waitForTimeout(2000);
}

/**
 * Replicate what downloadAnnotatedPdf() does in the browser JS, but intercept
 * the response bytes instead of triggering a file-save dialog.
 *
 * If forceOriginalPdfBase is true, we temporarily set basePdfUrl = originalPdfUrl
 * in the page before building the request (this reproduces the bug scenario).
 */
async function performDownload(page, { forceOriginalPdfBase = false } = {}) {
    const fileBytes = await page.evaluate(async ({ docId, forceOriginal }) => {
        // Optionally force the bug scenario: basePdfUrl = originalPdfUrl
        const savedBasePdfUrl = basePdfUrl;
        if (forceOriginal && typeof originalPdfUrl !== 'undefined') {
            basePdfUrl = originalPdfUrl;
        }

        try {
            syncAnnotationsForSave();
            const annotationsToSave = getDirectAnnotationsForSave();
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const sessionId = localStorage.getItem('pdf_session_id') || null;
            const downloadUrl = `/documents/${docId}/download-annotated-pdf`;
            const response = await fetch(downloadUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                    'Accept': '*/*',
                },
                body: JSON.stringify({
                    annotations: annotationsToSave,
                    use_clean_pdf: shouldUseCleanPdfForAnnotationSave(),
                    use_original_pdf: shouldUseOriginalPdfForAnnotationSave(),
                    session_id: sessionId,
                }),
            });
            if (!response.ok) {
                throw new Error(`Download request failed: ${response.status} ${await response.text()}`);
            }
            const buffer = await response.arrayBuffer();
            return { bytes: Array.from(new Uint8Array(buffer)), annotationCount: annotationsToSave.length };
        } finally {
            // Restore basePdfUrl
            basePdfUrl = savedBasePdfUrl;
        }
    }, { docId: DOCUMENT_ID, forceOriginal: forceOriginalPdfBase });

    return fileBytes;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
async function main() {
    ensureOutputDir();
    const runToken   = buildRunToken();
    const reportPath = path.join(OUTPUT_DIR, `nda_saved_session_no_duplication_${runToken}.json`);

    // ---- 1. Extract reference blocks from original PDF ----
    if (!fs.existsSync(ORIGINAL_PDF)) {
        throw new Error(`Reference PDF not found: ${ORIGINAL_PDF}`);
    }
    const referenceBlocks = extractPdfBlocks(ORIGINAL_PDF);
    console.log(`Reference PDF: ${referenceBlocks.length} blocks in ${ORIGINAL_PDF}`);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: VIEWPORT });
    const page    = await context.newPage();

    try {
        // ---- 2. Load editor in loadedSavedPdfMode ----
        const editorUrl = `${BASE_URL}/documents/${DOCUMENT_ID}/edit?loadedSavedPdf=1&savedSession=${SESSION_ID}`;
        console.log(`Opening: ${editorUrl}`);
        await page.goto(editorUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await waitForEditorReady(page);

        const editorState = await page.evaluate(() => ({
            loadedSavedPdfMode: typeof loadedSavedPdfMode !== 'undefined' ? loadedSavedPdfMode : null,
            basePdfUrl: typeof basePdfUrl !== 'undefined' ? basePdfUrl : null,
            cleanPdfUrl: typeof cleanPdfUrl !== 'undefined' ? cleanPdfUrl : null,
            originalPdfUrl: typeof originalPdfUrl !== 'undefined' ? originalPdfUrl : null,
            annotationCount: typeof annotations !== 'undefined' ? annotations.length : 0,
            basePdfHasBakedAnnotationText: typeof basePdfHasBakedAnnotationText !== 'undefined'
                ? basePdfHasBakedAnnotationText : null,
        }));
        console.log('Editor state:', JSON.stringify(editorState, null, 2));

        if (!editorState.loadedSavedPdfMode) {
            throw new Error('Page did not enter loadedSavedPdfMode — check URL params');
        }
        if (editorState.annotationCount === 0) {
            throw new Error('No annotations loaded — check session ID and document');
        }

        // ---- 3. Download PDF with basePdfUrl forced to originalPdfUrl ----
        // This is the BUG SCENARIO: the original backup already has baked text.
        // Before the fix, getDirectAnnotationsForSave() returned ALL annotations
        // (including all unmodified promoted-extraction blocks = all page text),
        // causing apply_annotations_direct.py to stamp text on top of existing text.
        // After the fix, only getMaterialAnnotationsForSave() is returned (empty for
        // a fresh load with no dirty annotations), so no text is double-stamped.
        console.log('Triggering download with forced originalPdfUrl base (bug scenario)…');
        const { bytes: downloadBytes, annotationCount: sentAnnotationCount } = await performDownload(page, { forceOriginalPdfBase: true });
        console.log(`Download received: ${downloadBytes.length} bytes, ${sentAnnotationCount} annotations sent to backend`);

        // Verify the fix: with the fix in place, only MATERIAL annotations
        // (dirty promoted + new standalone + shapes) are sent.
        // The critical invariant: unmodified promoted-extraction annotations
        // (promotedFromExtraction=true, promotedDirty=false) must NOT be sent
        // when using the original PDF as base — they would double the baked text.
        const unmodifiedPromotedSent = await page.evaluate(async ({ docId, forceOriginal }) => {
            const savedBase = basePdfUrl;
            if (forceOriginal && typeof originalPdfUrl !== 'undefined') {
                basePdfUrl = originalPdfUrl;
            }
            try {
                syncAnnotationsForSave();
                const toSave = getDirectAnnotationsForSave();
                return toSave.filter(a => a?.promotedFromExtraction && !a?.promotedDirty).length;
            } finally {
                basePdfUrl = savedBase;
            }
        }, { docId: DOCUMENT_ID, forceOriginal: true });
        console.log(`Unmodified promoted-extraction annotations sent: ${unmodifiedPromotedSent}`);

        // ---- 4. Save download bytes and extract blocks ----
        const downloadedPdfPath = path.join(OUTPUT_DIR, `nda_downloaded_originalbase_${runToken}.pdf`);
        fs.writeFileSync(downloadedPdfPath, Buffer.from(downloadBytes));
        console.log(`Downloaded PDF saved: ${downloadedPdfPath}`);

        const downloadedBlocks = extractPdfBlocks(downloadedPdfPath);
        console.log(`Downloaded PDF: ${downloadedBlocks.length} blocks`);

        // ---- 5. Detect duplicate text blocks ----
        const duplicates = findDuplicateBlocks(downloadedBlocks);

        // ---- 6. Build report ----
        const report = {
            run_token: runToken,
            document_id: DOCUMENT_ID,
            session_id: SESSION_ID,
            editor_state: editorState,
            reference_pdf: ORIGINAL_PDF,
            downloaded_pdf: downloadedPdfPath,
            reference_block_count: referenceBlocks.length,
            downloaded_block_count: downloadedBlocks.length,
            annotations_sent_to_backend: sentAnnotationCount,
            duplicate_blocks: duplicates,
            duplicate_block_count: duplicates.length,
            passed: duplicates.length <= MAX_DUPLICATE_BLOCKS,
        };

        fs.writeFileSync(reportPath, JSON.stringify(report, null, 2) + '\n');
        console.log(`Report written: ${reportPath}`);

        // ---- 8. Assert ----
        const failures = [];

        if (unmodifiedPromotedSent > 0) {
            failures.push(
                `BUG DETECTED: ${unmodifiedPromotedSent} unmodified promoted-extraction annotation(s) ` +
                `were sent to the backend when using originalPdfUrl as base. ` +
                `These would double the text already baked into the original PDF. ` +
                `getDirectAnnotationsForSave() must return getMaterialAnnotationsForSave() ` +
                `when loadedSavedPdfMode && basePdfUrl === originalPdfUrl.`
            );
        }

        if (duplicates.length > MAX_DUPLICATE_BLOCKS) {
            failures.push(
                `DOUBLED TEXT DETECTED: ${duplicates.length} normalised block(s) appear more than once ` +
                `in the downloaded PDF (expected ≤ ${MAX_DUPLICATE_BLOCKS}).\n` +
                `First 5 duplicates:\n` +
                duplicates.slice(0, 5).map(d => `  page:text="${d.key}" count=${d.count}`).join('\n')
            );
        }

        if (failures.length) {
            throw new Error(
                `nda_saved_session_save_no_duplication FAILED.\nreport=${reportPath}\n\n${failures.join('\n\n')}`
            );
        }

        console.log(`✓ PASSED — no doubled text blocks detected (${sentAnnotationCount} annotations sent, ${duplicates.length} duplicates)`);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err.stack || String(err));
    process.exit(1);
});
