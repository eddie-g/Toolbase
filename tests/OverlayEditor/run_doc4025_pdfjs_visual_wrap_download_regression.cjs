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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4025);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const OUTPUT_PATH = process.env.OUTPUT_PATH || '/tmp/doc4025-pdfjs-visual-wrap-regression.pdf';

const TARGET_TEXT = 'that I engage in a STEM training opportunity, and any decrease in hours below the 20-hours-per-week minimum required under this rule.';
const EXPECTED_LINES = [
    'that I engage in a STEM training opportunity,',
    'and any decrease in hours below the 20-hours-',
    'per-week minimum required under this rule.',
];

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

function assertLines(actual, expected, label) {
    const actualJson = JSON.stringify(actual);
    const expectedJson = JSON.stringify(expected);
    if (actualJson !== expectedJson) {
        throw new Error(`${label} mismatch.\nActual:   ${actualJson}\nExpected: ${expectedJson}`);
    }
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 2200, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(500);

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
        const annotation = (payload.annotations || []).find((ann) => String(ann.text || '') === TARGET_TEXT);
        if (!annotation) {
            throw new Error('Missing target wrapped PDF.js annotation in download payload.');
        }
        assertLines(annotation.pdfjsVisualLines || [], EXPECTED_LINES, 'Payload visual lines');

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

        const inspect = spawnSync('python3', ['-', OUTPUT_PATH, JSON.stringify(annotation), JSON.stringify(EXPECTED_LINES)], {
            input: `
import json
import sys
import fitz

pdf_path, annotation_json, expected_json = sys.argv[1:4]
ann = json.loads(annotation_json)
expected = json.loads(expected_json)
doc = fitz.open(pdf_path)
page = doc[int(ann.get("pageIndex") or 0)]
page_height = page.rect.height
top = page_height - (float(ann["pdfY"]) + float(ann["pdfHeight"]))
bottom = page_height - float(ann["pdfY"])
left = float(ann["pdfX"])
right = left + float(ann["pdfWidth"])
lines = []
for block in page.get_text("dict").get("blocks", []):
    for line in block.get("lines", []):
        bbox = line.get("bbox")
        if not bbox:
            continue
        if bbox[3] < top - 2 or bbox[1] > bottom + 2:
            continue
        if bbox[2] < left - 2 or bbox[0] > right + 2:
            continue
        text = "".join(span.get("text", "") for span in line.get("spans", [])).replace("\\xad", "-")
        if text.strip():
            lines.append(text.strip())
doc.close()
if lines != expected:
    print(json.dumps({"actual": lines, "expected": expected}, indent=2))
    sys.exit(2)
print(json.dumps({"lines": lines}, indent=2))
`,
            encoding: 'utf8',
        });
        if (inspect.status !== 0) {
            throw new Error(`Downloaded PDF visual-wrap inspection failed:\n${inspect.stdout}\n${inspect.stderr}`);
        }

        console.log('doc4025 pdfjs visual wrap download regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
