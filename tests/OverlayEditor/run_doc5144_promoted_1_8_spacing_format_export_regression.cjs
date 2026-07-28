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
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const PYTHON_BIN = process.env.PYTHON_BIN
    || path.resolve(__dirname, '..', '..', 'python', 'venv', 'bin', 'python');

async function login(page) {
    await page.goto(`${BASE_URL}/admin/login`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });
    for (const password of Array.from(new Set([
        ADMIN_PASSWORD,
        'codex-test-admin-2861',
        'TestPwd123!',
        'password1',
    ]))) {
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        try {
            await page.waitForURL(
                (url) => !url.pathname.endsWith('/admin/login'),
                { timeout: 5000 },
            );
            return;
        } catch (_) {
            if (!page.url().includes('/admin/login')) return;
        }
    }
    throw new Error('Unable to log in for the document 5144 promoted-paragraph regression.');
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
        await login(page);
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
        const box = page.locator(`${selector}:visible`).last();

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
