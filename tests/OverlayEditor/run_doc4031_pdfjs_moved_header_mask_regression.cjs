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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4031);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const OUTPUT_PATH = process.env.OUTPUT_PATH || '/tmp/doc4031-pdfjs-moved-header-mask-regression.pdf';

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

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForTimeout(800);

        const responsePromise = page.waitForResponse((response) => (
            response.url().includes('/download-annotated-pdf')
            && response.request().method() === 'POST'
        ), { timeout: 90000 });
        await page.locator('#download-pdf-btn').click();
        const response = await responsePromise;
        if (!response.ok()) {
            throw new Error(`Initial download payload request failed with ${response.status()}`);
        }

        const payload = JSON.parse(response.request().postData() || '{}');
        const april = (payload.annotations || []).find((annotation) => (
            annotation.id === 'pdfjs_4031_0_0:4'
            && annotation.text === '(April 2025)'
            && annotation.movedTextOverlay === true
        ));
        if (!april) {
            throw new Error('Missing moved PDF.js April 2025 annotation in visible export payload.');
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

        const inspect = spawnSync('python3', ['-', OUTPUT_PATH, JSON.stringify(april)], {
            input: `
import json
import sys
import fitz

pdf_path, ann_json = sys.argv[1:3]
ann = json.loads(ann_json)
doc = fitz.open(pdf_path)
page = doc[int(ann.get("pageIndex") or 0)]
ph = page.rect.height
x = float(ann["pdfjsSourceX"])
y = float(ann["pdfjsSourceY"])
w = float(ann["pdfjsSourceW"])
h = float(ann["pdfjsSourceH"])
rect = fitz.Rect(x - 2, ph - (y + h) - 1, x + w + 2, ph - y + 1) & page.rect
pix = page.get_pixmap(matrix=fitz.Matrix(8, 8), clip=rect, alpha=False)
samples = pix.samples
channels = max(1, int(getattr(pix, "n", 3) or 3))
dark = 0
total = 0
for offset in range(0, len(samples), channels):
    r = samples[offset]
    g = samples[offset + 1]
    b = samples[offset + 2]
    total += 1
    if (0.299 * r + 0.587 * g + 0.114 * b) < 180:
        dark += 1
doc.close()
ratio = dark / max(1, total)
print(json.dumps({"dark": dark, "total": total, "ratio": ratio}, indent=2))
if ratio > 0.002:
    sys.exit(2)
`,
            encoding: 'utf8',
        });
        if (inspect.status !== 0) {
            throw new Error(`Moved April source mask left visible pixels:\n${inspect.stdout}\n${inspect.stderr}`);
        }

        console.log('doc4031 pdfjs moved header mask regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
