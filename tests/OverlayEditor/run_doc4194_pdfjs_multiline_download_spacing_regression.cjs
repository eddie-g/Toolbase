#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4194);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const OUTPUT_PATH = process.env.OUTPUT_PATH || '/tmp/doc4194-pdfjs-multiline-download-spacing-regression.pdf';
const TEST_TEXT = 'codex spacing alpha\ncodex spacing beta\n\ncodex spacing gamma';

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const passwords = Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'codex-test-admin-2861',
        'password1',
    ].filter(Boolean)));

    for (const password of passwords) {
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        try {
            await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 5000 });
            return;
        } catch (_) {
            if (!page.url().includes('/admin/login')) return;
        }
    }

    throw new Error('Unable to log in to admin for regression test.');
}

function normalizeNewlines(value) {
    return String(value || '').replace(/\r\n?/g, '\n').replace(/\u200b/g, '');
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1000);

        await page.locator('#add-text-btn').click();
        await page.waitForSelector('body.enpv-add-text-on', { timeout: 5000 });
        const target = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const rect = pageEl?.getBoundingClientRect();
            if (!rect) return null;
            return { x: rect.left + 160, y: rect.top + 190 };
        });
        if (!target) throw new Error('could not locate page 1 for add text');

        await page.mouse.click(target.x, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });
        await page.keyboard.type('codex spacing alpha');
        await page.keyboard.press('Enter');
        await page.keyboard.type('codex spacing beta');
        await page.keyboard.press('Enter');
        await page.keyboard.press('Enter');
        await page.keyboard.type('codex spacing gamma');
        const commitTarget = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const rect = pageEl?.getBoundingClientRect();
            if (!rect) return null;
            return { x: rect.left + Math.min(rect.width - 80, 700), y: rect.top + Math.min(rect.height - 80, 520) };
        });
        if (!commitTarget) throw new Error('could not locate a commit target on page 1');
        await page.mouse.click(commitTarget.x, commitTarget.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected:not(.is-editing) .enpv-text-content', { timeout: 10000 });

        const visible = await page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected');
            const textContent = box?.querySelector('.enpv-text-content');
            if (!box || !textContent) throw new Error('missing selected multiline annotation');
            const range = document.createRange();
            range.selectNodeContents(textContent);
            const rects = Array.from(range.getClientRects()).filter((rect) => rect.width > 0 || rect.height > 0);
            range.detach?.();
            const rowTops = [];
            rects.forEach((rect) => {
                const top = Math.round(rect.top);
                if (!rowTops.some((existing) => Math.abs(existing - top) <= 2)) rowTops.push(top);
            });
            return {
                text: textContent.innerText,
                whiteSpace: window.getComputedStyle(textContent).whiteSpace,
                rowCount: rowTops.length,
            };
        });
        if (normalizeNewlines(visible.text).trim() !== TEST_TEXT || visible.whiteSpace !== 'pre-wrap' || visible.rowCount < 3) {
            throw new Error(`editor did not preserve the authored blank-line text: ${JSON.stringify(visible, null, 2)}`);
        }

        const responsePromise = page.waitForResponse((response) => (
            response.url().includes('/download-annotated-pdf')
            && response.request().method() === 'POST'
        ), { timeout: 90000 });
        await page.locator('#download-pdf-btn').click();
        const response = await responsePromise;
        if (!response.ok()) {
            throw new Error(`Initial download request failed with ${response.status()}`);
        }

        const payload = JSON.parse(response.request().postData() || '{}');
        const annotation = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ].find((ann) => normalizeNewlines(ann.text) === TEST_TEXT);
        if (!annotation) {
            throw new Error(`missing multiline annotation in download payload: ${JSON.stringify(payload).slice(0, 2000)}`);
        }
        if (!String(annotation.text || '').includes('\n\n') || annotation.userCreated !== true) {
            throw new Error(`download payload lost authored blank line metadata: ${JSON.stringify(annotation, null, 2)}`);
        }

        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const manualResponse = await page.context().request.post(response.url(), {
            data: payload,
            headers: {
                Accept: 'application/pdf, application/json',
                'X-CSRF-TOKEN': csrf || '',
            },
            timeout: 90000,
        });
        if (!manualResponse.ok()) {
            throw new Error(`Manual download request failed with ${manualResponse.status()}: ${await manualResponse.text()}`);
        }
        fs.writeFileSync(OUTPUT_PATH, await manualResponse.body());

        const inspect = spawnSync('python3', ['-', OUTPUT_PATH, JSON.stringify(annotation)], {
            input: `
import json
import sys
import fitz

pdf_path, annotation_json = sys.argv[1:3]
ann = json.loads(annotation_json)
doc = fitz.open(pdf_path)
page = doc[int(ann.get("pageIndex") or 0)]
page_height = page.rect.height
top = page_height - (float(ann["pdfY"]) + float(ann["pdfHeight"]))
bottom = page_height - float(ann["pdfY"])
left = float(ann["pdfX"])
right = left + float(ann["pdfWidth"])
wanted = ["codex spacing alpha", "codex spacing beta", "codex spacing gamma"]
rows = []
for block in page.get_text("dict").get("blocks", []):
    for line in block.get("lines", []):
        bbox = line.get("bbox")
        if not bbox:
            continue
        if bbox[3] < top - 4 or bbox[1] > bottom + 4:
            continue
        if bbox[2] < left - 4 or bbox[0] > right + 4:
            continue
        text = "".join(span.get("text", "") for span in line.get("spans", [])).strip()
        if text in wanted:
            rows.append({"text": text, "bbox": bbox, "center_y": (bbox[1] + bbox[3]) / 2.0})
doc.close()
rows.sort(key=lambda row: row["center_y"])
deduped = []
for row in rows:
    if any(existing["text"] == row["text"] and abs(existing["center_y"] - row["center_y"]) <= 0.01 for existing in deduped):
        continue
    deduped.append(row)
rows = deduped
texts = [row["text"] for row in rows]
if texts != wanted:
    print(json.dumps({"actual": rows, "expected": wanted}, indent=2))
    sys.exit(2)
first_step = rows[1]["center_y"] - rows[0]["center_y"]
blank_step = rows[2]["center_y"] - rows[1]["center_y"]
if not (first_step > 0 and blank_step >= first_step * 1.65):
    print(json.dumps({"rows": rows, "first_step": first_step, "blank_step": blank_step}, indent=2))
    sys.exit(3)
print(json.dumps({"rows": rows, "first_step": first_step, "blank_step": blank_step}, indent=2))
`,
            encoding: 'utf8',
        });
        if (inspect.status !== 0) {
            throw new Error(`Downloaded PDF multiline spacing inspection failed:\n${inspect.stdout}\n${inspect.stderr}`);
        }

        console.log('doc4194 pdfjs multiline download spacing regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});