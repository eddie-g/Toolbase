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
const DOCUMENT_ID = 925;
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function activateOverlay(page) {
    await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit`, { waitUntil: 'domcontentloaded' });
    await page.getByText('Overlay Editor', { exact: true }).click();
    await page.waitForFunction(() => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true);
    await page.waitForFunction(() => document.querySelectorAll('.overlay-field').length > 0);
    await page.waitForTimeout(2500);
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });

    try {
        await activateOverlay(page);

        const screenshotPath = path.join(OUTPUT_DIR, 'doc925_form_grouping_regression.png');
        await page.screenshot({ path: screenshotPath });

        const state = await page.evaluate(() => {
            const normalizeInner = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            return {
                maskCount: document.querySelectorAll('.overlay-underline-mask').length,
                fields: Array.from(document.querySelectorAll('.overlay-field')).map((field) => {
                    const rect = field.getBoundingClientRect();
                    return {
                        text: normalizeInner(field.innerText || ''),
                        title: normalizeInner(field.title || ''),
                        left: Math.round(rect.left),
                        top: Math.round(rect.top),
                        width: Math.round(rect.width),
                        height: Math.round(rect.height),
                    };
                }),
            };
        });

        if (state.maskCount !== 0) {
            throw new Error(`expected no underline masks, found ${state.maskCount}`);
        }

        const headerField = state.fields.find((field) =>
            normalize(field.text || field.title).includes('APPROVED BY THE TEXAS REAL ESTATE COMMISSION (TREC) FOR VOLUNTARY USE')
        );
        if (!headerField) {
            throw new Error(`missing trimmed approval header field: ${JSON.stringify(state.fields, null, 2)}`);
        }

        if (headerField.width >= 700 || headerField.height >= 90) {
            throw new Error(`approval header field still too large: ${JSON.stringify(headerField)}`);
        }

        const mergedHeader = state.fields.find((field) => {
            const text = normalize(field.text || field.title);
            return text.includes('APPROVED BY THE TEXAS REAL ESTATE COMMISSION') && text.includes('TO CONTRACT CONCERNING THE PROPERTY AT');
        });
        if (mergedHeader) {
            throw new Error(`approval header is still merged with tracked property line: ${JSON.stringify(mergedHeader)}`);
        }

        const propertyLineField = state.fields.find((field) =>
            normalize(field.text || field.title) === 'TO CONTRACT CONCERNING THE PROPERTY AT'
        );
        if (!propertyLineField) {
            throw new Error(`missing tracked property line field: ${JSON.stringify(state.fields, null, 2)}`);
        }

        const addressField = state.fields.find((field) => normalize(field.text || field.title) === '2255 Braeswood Park Dr, 104, Houston, TX 77030');
        if (!addressField) {
            throw new Error('missing address field');
        }

        const amountField = state.fields.find((field) => normalize(field.text || field.title) === '0.00');
        if (!amountField) {
            throw new Error('missing amount field');
        }

        const cleanPdfPath = path.resolve(__dirname, '..', '..', 'storage', 'app', 'private', 'temp', 'clean_925.pdf');
        const cleanPdfCheck = spawnSync('python3', [
            '-c',
            `
import fitz, json, os, sys
pdf_path = sys.argv[1]
tokens = sys.argv[2:]
if not os.path.exists(pdf_path):
    raise SystemExit(2)
doc = fitz.open(pdf_path)
page = doc[0]
text = page.get_text("text")
doc.close()
print(json.dumps({token: (token in text) for token in tokens}))
            `,
            cleanPdfPath,
            '10-10-11',
            'A.',
            'B.',
            'C.',
        ], {
            cwd: path.resolve(__dirname, '..', '..'),
            encoding: 'utf8',
        });
        if (cleanPdfCheck.status !== 0) {
            throw new Error(`clean PDF verification failed\nstdout:\n${cleanPdfCheck.stdout}\nstderr:\n${cleanPdfCheck.stderr}`);
        }
        const cleanPdfTokens = JSON.parse(cleanPdfCheck.stdout.trim());
        const missingCleanTokens = Object.entries(cleanPdfTokens).filter(([, present]) => !present).map(([token]) => token);
        if (missingCleanTokens.length > 0) {
            throw new Error(`clean PDF is still missing baseline labels: ${missingCleanTokens.join(', ')}`);
        }

        console.log('doc 925 form grouping regression passed');
        console.log(JSON.stringify({
            documentId: DOCUMENT_ID,
            screenshot: screenshotPath,
            headerField,
            propertyLineField,
            addressField,
            amountField,
            cleanPdfPath,
            cleanPdfTokens,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
