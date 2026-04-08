#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const PDF_PATH = path.resolve(__dirname, '..', '..', 'public', 'drylab_page_1.pdf');
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function uploadPdf(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForTimeout(1000);
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

async function fetchExtraction(page, documentId) {
    const extraction = await page.evaluate(async (id) => {
        const response = await fetch(`/documents/${id}/fitz-extraction-data`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const body = await response.json();
        return {
            ok: response.ok,
            status: response.status,
            body,
        };
    }, documentId);

    if (!extraction.ok || !extraction.body?.success) {
        throw new Error(`fitz-extraction-data failed: ${JSON.stringify(extraction)}`);
    }

    return extraction.body.extraction_data || [];
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 2200 } });

    try {
        const documentId = await uploadPdf(page);
        const extractionPages = await fetchExtraction(page, documentId);
        const pageOne = extractionPages[0];
        if (!pageOne) {
            throw new Error(`missing page 1 extraction for document ${documentId}`);
        }

        const blocks = Array.isArray(pageOne.blocks) ? pageOne.blocks : [];
        const normalizedBlocks = blocks.map((block) => ({
            block_num: Number(block?.block_num) || 0,
            text_lines: Array.isArray(block?.text_lines) ? block.text_lines.map((line) => String(line || '')) : [],
            normalized_lines: Array.isArray(block?.text_lines) ? block.text_lines.map((line) => normalize(line)) : [],
            from_xgap_split: Boolean(block?._from_xgap_split),
            left: Number(block?.left) || 0,
            top: Number(block?.top) || 0,
            width: Number(block?.width) || 0,
            height: Number(block?.height) || 0,
        }));

        const meetingsBlock = normalizedBlocks.find((block) => block.normalized_lines.length === 1 && block.normalized_lines[0] === 'meetings');
        if (!meetingsBlock) {
            throw new Error(`missing standalone meetings block: ${JSON.stringify(normalizedBlocks, null, 2)}`);
        }

        const cityBlock = normalizedBlocks.find((block) =>
            block.normalized_lines.length === 2
            && block.normalized_lines[0] === 'NY · SF'
            && block.normalized_lines[1] === 'LA · LV'
        );
        if (!cityBlock) {
            throw new Error(`missing NY · SF / LA · LV block: ${JSON.stringify(normalizedBlocks, null, 2)}`);
        }

        const companyBlock = normalizedBlocks.find((block) =>
            block.normalized_lines.length === 6
            && block.normalized_lines[0].startsWith('Academy of Motion Picture Arts and Sciences')
            && block.normalized_lines[3].startsWith('Google · IBM')
            && block.normalized_lines[4].startsWith('Cinematographers Guild')
            && block.normalized_lines[5].startsWith('Screening Room')
        );
        if (!companyBlock) {
            throw new Error(`missing 6-line company block: ${JSON.stringify(normalizedBlocks, null, 2)}`);
        }

        const forbiddenLineFragments = [
            'meetings Google · IBM',
            'NY · SF Cinematographers Guild',
            'LA · LV Screening Room',
        ];
        const forbiddenMatches = normalizedBlocks.flatMap((block) => block.normalized_lines.filter((line) => (
            forbiddenLineFragments.some((fragment) => line.includes(fragment))
        )));
        if (forbiddenMatches.length > 0) {
            throw new Error(`found forbidden merged drylab lines: ${JSON.stringify(forbiddenMatches)}`);
        }

        const artifactPath = path.join(OUTPUT_DIR, `drylab_doc${documentId}_page1_extraction_grouping.json`);
        fs.writeFileSync(artifactPath, JSON.stringify({
            documentId,
            meetingsBlock,
            cityBlock,
            companyBlock,
            blocks: normalizedBlocks,
        }, null, 2));

        console.log('drylab page 1 extraction grouping regression passed');
        console.log(JSON.stringify({
            documentId,
            artifactPath,
            meetingsBlock,
            cityBlock,
            companyBlock,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
