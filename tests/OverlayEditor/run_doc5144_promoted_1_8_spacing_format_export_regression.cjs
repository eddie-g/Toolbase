#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const localBrowsers = path.resolve(
    __dirname,
    '..',
    '..',
    'node_modules',
    'playwright-core',
    '.local-browsers',
);
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5144);
const TARGET_ID = process.env.TARGET_ID || 'promoted_1_8';
const { requireAdminCredentials } = require('../../tools/admin-credentials.cjs');
// Resolved from AUTOMATED_TESTS_ADMIN_* (the QA admin), never hardcoded:
// a stale default here is what led to real admin passwords being reset.
const { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } = requireAdminCredentials();
const ADMIN_SESSION_COOKIE_NAME = process.env.ADMIN_SESSION_COOKIE_NAME || '';
const ADMIN_SESSION_COOKIE_VALUE = process.env.ADMIN_SESSION_COOKIE_VALUE || '';
const SELECTION_ONLY = process.env.SELECTION_ONLY === '1';
const PYTHON_BIN = process.env.PYTHON_BIN
    || path.resolve(__dirname, '..', '..', 'python', 'venv', 'bin', 'python');

async function login(page) {
    await page.goto(`${BASE_URL}/admin/login`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });
    // One attempt. Trying a list of candidate passwords walked straight
    // into Fortify's five-per-minute login throttle.
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    try {
        await page.waitForURL(
            (url) => !url.pathname.endsWith('/admin/login'),
            { timeout: 15000 },
        );
    } catch (_) {
        if (page.url().includes('/admin/login')) {
            throw new Error(`Admin login failed for ${ADMIN_EMAIL}. Check AUTOMATED_TESTS_ADMIN_* in .env.`);
        }
    }
}

async function enterEditMode(page, box, selector) {
    await box.scrollIntoViewIfNeeded();
    await box.click({ force: true });
    const editButton = page.locator('#enpv-ann-menu [data-action="edit"]');
    if (await editButton.isVisible().catch(() => false)) {
        await editButton.click({ force: true });
    } else {
        await box.dblclick({ force: true });
    }
    await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
}

function normalizedRunText(runs) {
    return (runs || []).map((run) => (
        run?.type === 'break' ? '\n' : String(run?.text || '')
    )).join('').replace(/\s+/g, ' ').trim();
}

async function assertPromotedParagraphSelectionContract(page) {
    const selectionSelector = '.enpv-annotation-box[data-annotation-id="promoted_1_2"]';
    await page.waitForSelector(selectionSelector, { timeout: 90000 });
    const selectionBox = page.locator(`${selectionSelector}:visible`).last();
    await selectionBox.scrollIntoViewIfNeeded();

    // A selected text annotation is a paragraph-scoped Select All target even
    // before its hidden source-backed contenteditable has been focused.
    await selectionBox.click({ force: true });
    await page.keyboard.press('Control+A');
    await page.waitForSelector(`${selectionSelector}.is-editing`, { timeout: 10000 });

    const selectAllState = await selectionBox.evaluate((node) => {
        const content = node.querySelector('.enpv-text-content');
        const selection = window.getSelection();
        const selectedText = String(selection?.toString() || '');
        const fullText = String(content?.textContent || '');
        const selectionStyle = getComputedStyle(content, '::selection');
        return {
            selectedText,
            fullText,
            selectedNormalized: selectedText.replace(/\s+/g, ' ').trim(),
            fullNormalized: fullText.replace(/\s+/g, ' ').trim(),
            selectionInside: Boolean(
                selection?.rangeCount
                && content?.contains(selection.anchorNode)
                && content?.contains(selection.focusNode)
            ),
            active: document.activeElement === content,
            contentEditable: content?.contentEditable,
            selectionBackground: selectionStyle.backgroundColor,
            selectionColor: selectionStyle.color,
        };
    });
    if (selectAllState.selectedNormalized !== selectAllState.fullNormalized
        || !selectAllState.selectionInside
        || !selectAllState.active
        || selectAllState.contentEditable !== 'true'
        || selectAllState.selectionBackground === 'rgba(0, 0, 0, 0)'
        || selectAllState.selectionBackground === 'transparent'
        || selectAllState.selectionColor === 'rgba(0, 0, 0, 0)'
        || selectAllState.selectionColor === 'transparent') {
        throw new Error(`Promoted paragraph Ctrl+A contract failed: ${JSON.stringify(selectAllState)}`);
    }

    // Collapse the full range before starting an independent pointer gesture.
    // This avoids platform-specific drag-the-existing-selection behavior.
    await page.keyboard.press('ArrowLeft');
    const dragGeometry = await selectionBox.evaluate((node) => {
        const content = node.querySelector('.enpv-text-content');
        const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
        let textNode = walker.nextNode();
        while (textNode && String(textNode.nodeValue || '').trim().length < 24) {
            textNode = walker.nextNode();
        }
        if (!textNode) throw new Error('No selectable text run found in promoted_1_2.');
        const value = String(textNode.nodeValue || '');
        const startOffset = Math.max(0, value.search(/\S/));
        const endOffset = Math.min(value.length, startOffset + 22);
        const startRange = document.createRange();
        startRange.setStart(textNode, startOffset);
        startRange.setEnd(textNode, Math.min(value.length, startOffset + 1));
        const endRange = document.createRange();
        endRange.setStart(textNode, Math.max(startOffset, endOffset - 1));
        endRange.setEnd(textNode, endOffset);
        const startRect = startRange.getBoundingClientRect();
        const endRect = endRange.getBoundingClientRect();
        return {
            start: { x: startRect.left + 1, y: startRect.top + (startRect.height / 2) },
            end: { x: endRect.right - 1, y: endRect.top + (endRect.height / 2) },
        };
    });
    await page.mouse.move(dragGeometry.start.x, dragGeometry.start.y);
    await page.mouse.down();
    await page.mouse.move(dragGeometry.end.x, dragGeometry.end.y, { steps: 12 });
    await page.mouse.up();
    await page.waitForTimeout(50);

    const dragState = await selectionBox.evaluate((node) => {
        const content = node.querySelector('.enpv-text-content');
        const selection = window.getSelection();
        return {
            selectedText: String(selection?.toString() || ''),
            fullText: String(content?.textContent || ''),
            selectionInside: Boolean(
                selection?.rangeCount
                && content?.contains(selection.anchorNode)
                && content?.contains(selection.focusNode)
            ),
        };
    });
    if (dragState.selectedText.length < 5
        || dragState.selectedText.length >= dragState.fullText.length
        || !dragState.selectionInside) {
        throw new Error(`Promoted paragraph pointer selection contract failed: ${JSON.stringify(dragState)}`);
    }

    await page.keyboard.press('Control+A');
    const repeatSelectAll = await selectionBox.evaluate((node) => (
        String(window.getSelection()?.toString() || '').replace(/\s+/g, ' ').trim()
        === String(node.querySelector('.enpv-text-content')?.textContent || '').replace(/\s+/g, ' ').trim()
    ));
    if (!repeatSelectAll) throw new Error('Ctrl+A did not reselect the complete promoted paragraph.');
    await page.keyboard.press('Escape');
    await page.waitForSelector(`${selectionSelector}:not(.is-editing)`, { timeout: 10000 });
    return {
        ctrlASelectedLength: selectAllState.selectedText.length,
        pointerSelectedText: dragState.selectedText,
        selectionBackground: selectAllState.selectionBackground,
        selectionColor: selectAllState.selectionColor,
    };
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1900, height: 1100 },
        acceptDownloads: true,
    });
    const page = await context.newPage();
    const selector = `.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`;
    const outputPath = path.join(
        os.tmpdir(),
        `doc-${DOCUMENT_ID}-${TARGET_ID}-${Date.now()}.pdf`,
    );
    let keepOutput = process.env.KEEP_OUTPUT === '1';

    try {
        if (ADMIN_SESSION_COOKIE_NAME && ADMIN_SESSION_COOKIE_VALUE) {
            const baseUrl = new URL(BASE_URL);
            await context.addCookies([{
                name: ADMIN_SESSION_COOKIE_NAME,
                value: ADMIN_SESSION_COOKIE_VALUE,
                domain: baseUrl.hostname,
                path: '/',
                httpOnly: true,
                secure: baseUrl.protocol === 'https:',
                sameSite: 'Lax',
            }]);
        } else {
            await login(page);
        }
        await page.goto(
            `${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`,
            { waitUntil: 'domcontentloaded', timeout: 90000 },
        );
        await page.evaluate(({ documentId, sessionId }) => {
            localStorage.setItem(`edit_new_session_${documentId}`, sessionId);
        }, {
            documentId: DOCUMENT_ID,
            sessionId: `doc5144-promoted-spacing-${Date.now()}`,
        });
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForSelector(
            'body.enpv-viewer-ready .pdfViewer .page canvas',
            { timeout: 90000 },
        );
        await page.locator('#ftb-edit-mode').click();
        await page.waitForSelector(selector, { timeout: 90000 });
        const selectionContract = await assertPromotedParagraphSelectionContract(page);
        if (SELECTION_ONLY) {
            process.stdout.write(`${JSON.stringify({
                status: 'pass',
                documentId: DOCUMENT_ID,
                targetId: 'promoted_1_2',
                selectionContract,
            }, null, 2)}\n`);
            return;
        }
        const box = page.locator(`${selector}:visible`).last();

        await box.scrollIntoViewIfNeeded();
        const editPoint = await box.evaluate((node) => {
            const forbiddenBoxAttributes = [
                'data-source-span-runs',
                'data-source-underline-segments',
                'data-original-text',
                'data-base-text',
            ];
            const present = forbiddenBoxAttributes.filter((name) => node.hasAttribute(name));
            if (present.length) {
                throw new Error(`Large runtime values leaked into annotation DOM attributes: ${present.join(', ')}`);
            }
            const content = node.querySelector('.enpv-text-content');
            const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
            let textNode = walker.nextNode();
            while (textNode && !String(textNode.nodeValue || '').includes('information')) {
                textNode = walker.nextNode();
            }
            if (!textNode) throw new Error('Could not locate the direct-edit word in promoted_1_8.');
            const index = String(textNode.nodeValue || '').indexOf('information');
            const range = document.createRange();
            range.setStart(textNode, index + 3);
            range.setEnd(textNode, index + 4);
            const rect = range.getBoundingClientRect();
            return {
                x: rect.left + (rect.width / 2),
                y: rect.top + (rect.height / 2),
            };
        });
        await page.mouse.dblclick(editPoint.x, editPoint.y);
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
        const directEdit = await box.evaluate((node) => {
            const content = node.querySelector('.enpv-text-content');
            const selection = window.getSelection();
            return {
                selectedText: selection?.toString() || '',
                selectionInside: Boolean(
                    selection?.rangeCount
                    && content?.contains(selection.anchorNode)
                    && content?.contains(selection.focusNode)
                ),
                contentEditable: content?.contentEditable,
                leakedAttributes: [
                    ...[
                        'data-source-span-runs',
                        'data-source-underline-segments',
                        'data-original-text',
                        'data-base-text',
                    ].filter((name) => node.hasAttribute(name)),
                    ...['data-pre-edit', 'data-pre-edit-flattened']
                        .filter((name) => content?.hasAttribute(name)),
                ],
            };
        });
        if (directEdit.selectedText !== 'information'
            || !directEdit.selectionInside
            || directEdit.contentEditable !== 'true'
            || directEdit.leakedAttributes.length) {
            throw new Error(`Direct inline edit contract failed: ${JSON.stringify(directEdit)}`);
        }
        await page.keyboard.press('Escape');
        await page.waitForSelector(`${selector}:not(.is-editing)`, { timeout: 10000 });

        await enterEditMode(page, box, selector);
        const before = await box.evaluate((node) => {
            const content = node.querySelector('.enpv-text-content');
            const spans = Array.from(content?.querySelectorAll('span') || []);
            return {
                text: content?.textContent || '',
                sourceRunCount: content?.querySelectorAll('[data-source-span-run="1"]').length || 0,
                weights: Array.from(new Set(spans.map((span) => getComputedStyle(span).fontWeight))),
                semanticWeights: Array.from(new Set(spans.map(
                    (span) => span.dataset.sourceSemanticFontWeight || '',
                ).filter(Boolean))),
                fontFamilies: Array.from(new Set(spans.map(
                    (span) => getComputedStyle(span).fontFamily,
                ).filter(Boolean))),
            };
        });
        if (!before.text.trim().endsWith('notification of death.')) {
            throw new Error(`Unexpected promoted_1_8 source ending: ${JSON.stringify(before.text.slice(-160))}`);
        }
        if (before.sourceRunCount < 2
            || (
                !before.semanticWeights.some((weight) => Number(weight) >= 600)
                && !before.fontFamilies.some((family) => /bdcn|bold/i.test(family))
                && before.fontFamilies.length < 2
            )) {
            throw new Error(`The promoted paragraph entered edit mode without its mixed source runs: ${JSON.stringify(before)}`);
        }

        // These must be separate input events. The production bug occurred
        // when the first event inserted a root-level space and scaffold
        // teardown moved the caret to the left of it.
        await page.keyboard.press('Space');
        await page.waitForTimeout(50);
        await page.keyboard.type('test');
        await page.waitForTimeout(100);

        const after = await box.evaluate((node) => {
            const content = node.querySelector('.enpv-text-content');
            const selection = window.getSelection();
            const spans = Array.from(content?.querySelectorAll('span') || []);
            return {
                text: content?.textContent || '',
                active: document.activeElement === content,
                caretInside: Boolean(
                    selection?.rangeCount
                    && content?.contains(selection.anchorNode)
                    && content?.contains(selection.focusNode)
                ),
                weights: Array.from(new Set(spans.map((span) => getComputedStyle(span).fontWeight))),
            };
        });
        if (!after.text.endsWith('notification of death. test')) {
            throw new Error(`First-input caret drift corrupted the suffix: ${JSON.stringify(after)}`);
        }
        if (after.text.includes('notification of death.test')) {
            throw new Error(`The appended word lost its leading space: ${JSON.stringify(after.text.slice(-160))}`);
        }
        if (!after.active || !after.caretInside) {
            throw new Error(`The caret escaped the contenteditable after source naturalization: ${JSON.stringify(after)}`);
        }

        await page.keyboard.press('Escape');
        let payload = null;
        let downloadUrl = '';
        await page.route('**/download-annotated-pdf', async (route) => {
            payload = JSON.parse(route.request().postData() || '{}');
            downloadUrl = route.request().url();
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: '{"message":"captured by doc5144 regression"}',
            });
        });
        await page.locator('#download-pdf-btn').click({ force: true });
        await page.waitForTimeout(500);
        await page.unroute('**/download-annotated-pdf');
        if (!payload || !downloadUrl) throw new Error('The download annotation payload was not captured.');

        const target = (payload.annotations || []).find(
            (annotation) => annotation.id === TARGET_ID,
        );
        if (!target) throw new Error(`${TARGET_ID} was absent from the download payload.`);
        const normalizedText = String(target.text || '').replace(/\s+/g, ' ').trim();
        const runText = normalizedRunText(target.richTextRuns);
        const weights = new Set(
            (target.richTextRuns || [])
                .filter((run) => run?.type === 'text')
                .map((run) => String(run.fontWeight || '')),
        );
        const payloadChecks = {
            exactSuffix: normalizedText.endsWith('notification of death. test'),
            dirty: target.promotedDirty === true,
            preserveSourceTypography: target.preserveSourceTypography === true,
            richRunsMatchText: runText === normalizedText,
            hasRegularAndBoldRuns: Array.from(weights).some((weight) => Number(weight) < 600)
                && Array.from(weights).some((weight) => Number(weight) >= 600),
        };
        if (Object.values(payloadChecks).some((value) => value !== true)) {
            throw new Error(`Promoted paragraph download contract failed: ${JSON.stringify({
                payloadChecks,
                text: target.text,
                runs: target.richTextRuns,
            })}`);
        }

        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const response = await page.context().request.post(downloadUrl, {
            data: payload,
            headers: {
                Accept: 'application/pdf, application/json',
                'X-CSRF-TOKEN': csrf || '',
            },
            timeout: 90000,
        });
        if (!response.ok()) {
            throw new Error(`Document 5144 PDF export failed: ${response.status()} ${await response.text()}`);
        }
        fs.writeFileSync(outputPath, await response.body());

        const inspection = spawnSync(PYTHON_BIN, ['-', outputPath], {
            input: String.raw`
import json
import re
import sys
import fitz

doc = fitz.open(sys.argv[1])
page = doc[0]
plain = re.sub(r"\s+", " ", page.get_text("text")).strip()
spans = [
    span
    for block in page.get_text("dict").get("blocks", [])
    for line in block.get("lines", [])
    for span in line.get("spans", [])
    if str(span.get("text") or "").strip()
]
bold = [
    span for span in spans
    if "I may be charged" in str(span.get("text") or "")
    and (
        int(span.get("flags") or 0) & int(fitz.TEXT_FONT_BOLD)
        or "bold" in str(span.get("font") or "").lower()
        or "bdcn" in str(span.get("font") or "").lower()
    )
]
regular = [
    span for span in spans
    if "understand the information" in str(span.get("text") or "")
    and not (int(span.get("flags") or 0) & int(fitz.TEXT_FONT_BOLD))
]
paragraph_sizes = [
    float(span.get("size") or 0.0)
    for span in spans
    if (
        "understand the information" in str(span.get("text") or "")
        or "I may be charged" in str(span.get("text") or "")
        or "notification of death. test" in str(span.get("text") or "")
    )
]
death_rects = page.search_for("notification of death. test")
attention_rects = page.search_for("Attention")
result = {
    "paragraph_copies": len(page.search_for("I understand the information")),
    "has_spaced_suffix": "notification of death. test" in plain,
    "has_collapsed_suffix": "notification of death.test" in plain,
    "bold_run_count": len(bold),
    "regular_run_count": len(regular),
    "paragraph_sizes": paragraph_sizes,
    "paragraph_keeps_10pt_size": bool(
        paragraph_sizes
        and all(abs(size - 10.0) <= 0.05 for size in paragraph_sizes)
    ),
    "attention_count": len(attention_rects),
    "paragraph_above_form": bool(
        death_rects
        and attention_rects
        and max(rect.y1 for rect in death_rects) < min(rect.y0 for rect in attention_rects)
    ),
    "bold_fonts": sorted({str(span.get("font") or "") for span in bold}),
    "regular_fonts": sorted({str(span.get("font") or "") for span in regular}),
}
doc.close()
print(json.dumps(result))
if not (
    result["paragraph_copies"] == 1
    and result["has_spaced_suffix"]
    and not result["has_collapsed_suffix"]
    and result["bold_run_count"] >= 1
    and result["regular_run_count"] >= 1
    and result["paragraph_keeps_10pt_size"]
    and result["attention_count"] >= 1
    and result["paragraph_above_form"]
):
    raise SystemExit(2)
`,
            encoding: 'utf8',
        });
        if (inspection.status !== 0) {
            keepOutput = true;
            throw new Error(`Downloaded PDF inspection failed:\n${inspection.stdout}\n${inspection.stderr}\nPDF: ${outputPath}`);
        }

        process.stdout.write(`${JSON.stringify({
            status: 'pass',
            documentId: DOCUMENT_ID,
            targetId: TARGET_ID,
            before,
            after,
            payloadChecks,
            inspection: JSON.parse(inspection.stdout),
        }, null, 2)}\n`);
    } finally {
        await browser.close();
        if (!keepOutput) fs.rmSync(outputPath, { force: true });
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exitCode = 1;
});
