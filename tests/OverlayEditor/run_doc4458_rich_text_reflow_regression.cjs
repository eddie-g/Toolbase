#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4458);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    for (const password of Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'codex-test-admin-2861',
        'password1',
    ]))) {
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
    throw new Error('Unable to log in for doc4458 rich-text regression.');
}

async function enterEdit(page, box, selector) {
    await box.scrollIntoViewIfNeeded();
    await box.click({ force: true });
    await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
    try {
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 2500 });
    } catch (_) {
        await box.dblclick({ force: true });
        try {
            await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
        } catch (error) {
            const diagnostic = await box.evaluate((node) => ({
                className: node.className,
                hidden: node.hidden,
                bodyClass: document.body.className,
                selected: node.classList.contains('is-selected'),
                editorMode: node.dataset.editorMode,
                locked: node.dataset.locked,
            })).catch(() => null);
            throw new Error(`${error.message}\nEdit diagnostic: ${JSON.stringify(diagnostic)}`);
        }
    }
    await page.waitForTimeout(75);
}

async function stateForBox(box) {
    return box.evaluate((node) => {
        const text = node.querySelector('.enpv-text-content');
        const boxRect = node.getBoundingClientRect();
        const textRect = text.getBoundingClientRect();
        const style = getComputedStyle(text);
        const scale = Number(node.parentElement?.dataset?.scale || 1) || 1;
        const range = document.createRange();
        range.selectNodeContents(text);
        const paintedRects = Array.from(range.getClientRects()).filter((rect) => rect.width > 0.5);
        range.detach?.();
        const paintedRows = [];
        paintedRects.forEach((rect) => {
            let row = paintedRows.find((candidate) => Math.abs(candidate.top - rect.top) <= 2);
            if (!row) {
                row = { top: rect.top, left: rect.left, right: rect.right };
                paintedRows.push(row);
            } else {
                row.left = Math.min(row.left, rect.left);
                row.right = Math.max(row.right, rect.right);
            }
        });
        return {
            width: boxRect.width,
            height: boxRect.height,
            fontSizePts: (Number.parseFloat(style.fontSize) || 0) / scale,
            lineHeightPts: (Number.parseFloat(style.lineHeight) || 0) / scale,
            brCount: text.querySelectorAll('br').length,
            newlineCount: (String(text.textContent || '').match(/\n/g) || []).length,
            sourceLineCount: text.querySelectorAll('[data-source-span-line="1"]').length,
            maxPaintedWidthRatio: Math.max(0, ...paintedRows.map((row) => row.right - row.left)) / Math.max(1, textRect.width),
            scrollHeight: text.scrollHeight,
            clientHeight: text.clientHeight,
            naturalTextFlow: node.dataset.naturalTextFlow || '',
            promotedReflowEnabled: node.dataset.promotedReflowEnabled || '',
            sourceSpanNaturalized: node.dataset.sourceSpanNaturalized || '',
            text: text.textContent || '',
        };
    });
}

async function mutateWithoutChangingText(page, box) {
    const text = box.locator('.enpv-text-content');
    await text.evaluate((node) => {
        const range = document.createRange();
        range.selectNodeContents(node);
        range.collapse(false);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    });
    await page.keyboard.type('x');
    await page.keyboard.press('Backspace');
    await page.waitForTimeout(100);
}

function closeEnough(actual, expected, tolerance = 0.15) {
    return Math.abs(Number(actual) - Number(expected)) <= tolerance;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();
    const checks = [];
    const record = (item, pass, detail = null) => checks.push({ item, pass: Boolean(pass), detail });
    let downloadPayload = null;

    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            const request = route.request();
            if (request.method() === 'GET') return route.continue();
            if (request.method() === 'POST' && /download/i.test(request.url())) {
                try { downloadPayload = JSON.parse(request.postData() || '{}'); } catch (_) { downloadPayload = null; }
                return route.fulfill({
                    status: 200,
                    contentType: 'application/pdf',
                    body: Buffer.from('%PDF-1.4\n%%EOF\n'),
                });
            }
            return route.abort();
        });

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.locator('#ftb-edit-mode').click();

        const symbolSelector = '.enpv-annotation-box[data-annotation-id="promoted_1_29"]';
        await page.waitForSelector(symbolSelector, { timeout: 90000 });
        const symbolBox = page.locator(`${symbolSelector}:visible`).last();
        const loaded = await stateForBox(symbolBox);
        record('symbol_annotation_loads_at_10pt', closeEnough(loaded.fontSizePts, 10), loaded);

        // Exercise the clean/legacy source-run path too. This is the path that
        // exposed the original bug: its 12pt bullet glyphs used to make every
        // 10pt body run `0.8333em`, compounding on every commit.
        await symbolBox.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            text.textContent = node.dataset.originalText || text.textContent || '';
        });

        const cycleSizes = [];
        for (let cycle = 0; cycle < 4; cycle += 1) {
            await enterEdit(page, symbolBox, symbolSelector);
            if (cycle === 0) {
                const runSizes = await symbolBox.locator('[data-source-span-run="1"]').evaluateAll((runs) => (
                    runs.map((run) => ({ text: run.textContent, fontSize: run.style.fontSize || '1em' }))
                ));
                record('bullet_glyph_does_not_become_the_paragraph_font_reference',
                    runSizes.some((run) => run.text === '•' && Math.abs(Number.parseFloat(run.fontSize) - 1.2) < 0.01)
                        && runSizes.some((run) => /Household employers/.test(run.text) && run.fontSize === '1em'),
                    runSizes);
            }
            await mutateWithoutChangingText(page, symbolBox);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(100);
            cycleSizes.push((await stateForBox(symbolBox)).fontSizePts);
        }
        record('special_symbols_do_not_compound_font_shrink',
            cycleSizes.every((size) => closeEnough(size, 10)), cycleSizes);

        await enterEdit(page, symbolBox, symbolSelector);
        const beforeDecrease = await stateForBox(symbolBox);
        await page.locator('#afb-size').evaluate((slider) => {
            // fontPtToSliderValue(8) rounds to 39.
            slider.value = '39';
            slider.dispatchEvent(new Event('input', { bubbles: true }));
            slider.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.waitForTimeout(200);
        const afterDecrease = await stateForBox(symbolBox);
        record('font_decrease_keeps_blue_box_width_fixed',
            closeEnough(afterDecrease.width, beforeDecrease.width, 0.5),
            { before: beforeDecrease.width, after: afterDecrease.width });
        record('font_decrease_uses_stable_8pt_1_2_line_height',
            closeEnough(afterDecrease.fontSizePts, 8)
                && closeEnough(afterDecrease.lineHeightPts, 9.6, 0.2),
            afterDecrease);
        record('font_decrease_releases_only_visual_source_wraps',
            afterDecrease.brCount === 0
                && afterDecrease.newlineCount === 2
                && afterDecrease.sourceLineCount === 0
                && afterDecrease.naturalTextFlow === '1'
                && afterDecrease.promotedReflowEnabled === '1',
            afterDecrease);
        record('smaller_text_reflows_across_the_blue_box',
            afterDecrease.maxPaintedWidthRatio >= 0.88
                && afterDecrease.scrollHeight <= afterDecrease.clientHeight + 2,
            afterDecrease);
        await page.keyboard.press('Escape');

        await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 2 }));
        const paragraphSelector = '.enpv-annotation-box[data-annotation-id="promoted_2_30"]';
        await page.waitForSelector(paragraphSelector, { timeout: 90000 });
        await page.waitForTimeout(750);
        const paragraphBox = page.locator(`${paragraphSelector}:visible`).last();
        const paragraphText = await paragraphBox.locator('.enpv-text-content').evaluate((node) => node.textContent || '');
        record('long_paragraph_is_complete_in_editor',
            paragraphText.includes('Deduction for educator expenses.')
                && paragraphText.includes('deduction is slightly different.'),
            paragraphText);

        await enterEdit(page, paragraphBox, paragraphSelector);
        const paragraphBeforeDecrease = await stateForBox(paragraphBox);
        await page.locator('#afb-size').evaluate((slider) => {
            slider.value = '39';
            slider.dispatchEvent(new Event('input', { bubbles: true }));
            slider.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.waitForTimeout(200);
        const paragraphAfterDecrease = await stateForBox(paragraphBox);
        record('long_paragraph_font_decrease_reflows_to_fixed_width',
            closeEnough(paragraphAfterDecrease.width, paragraphBeforeDecrease.width, 0.5)
                && paragraphAfterDecrease.brCount === 0
                && paragraphAfterDecrease.newlineCount === 0
                && paragraphAfterDecrease.maxPaintedWidthRatio >= 0.88
                && paragraphAfterDecrease.text.includes('deduction is slightly different.'),
            { before: paragraphBeforeDecrease, after: paragraphAfterDecrease });
        await page.keyboard.press('Escape');

        await page.locator('#download-pdf-btn').click({ force: true });
        await page.waitForFunction(() => document.querySelector('#download-pdf-btn')?.textContent?.includes('Download PDF'), null, { timeout: 10000 });
        const payloadAnnotations = downloadPayload?.annotations || [];
        const symbolPayload = payloadAnnotations.find((annotation) => annotation.id === 'promoted_1_29');
        const paragraphPayload = payloadAnnotations.find((annotation) => annotation.id === 'promoted_2_30');
        const mixedPayload = payloadAnnotations.find((annotation) => annotation.id === 'promoted_2_27');
        record('download_payload_keeps_complete_long_paragraph',
            paragraphPayload?.text?.includes('Deduction for educator expenses.')
                && paragraphPayload?.text?.includes('deduction is slightly different.'),
            paragraphPayload?.text || null);
        record('download_payload_marks_natural_reflow',
            symbolPayload?.promotedReflowEnabled === true
                && Number(symbolPayload?.fontSize) === 8,
            symbolPayload ? {
                promotedReflowEnabled: symbolPayload.promotedReflowEnabled,
                fontSize: symbolPayload.fontSize,
                pdfWidth: symbolPayload.pdfWidth,
            } : null);
        record('download_payload_preserves_inline_bold_and_color_runs',
            Array.isArray(mixedPayload?.richTextRuns)
                && mixedPayload.richTextRuns.some((run) => /permanent/.test(run.text || '') && String(run.fontWeight) === '700')
                && mixedPayload.richTextRuns.some((run) => /addition, beginning/.test(run.text || '') && String(run.color).toLowerCase() === '#b23857'),
            mixedPayload?.richTextRuns || null);
    } finally {
        await browser.close();
    }

    const failed = checks.filter((check) => !check.pass);
    process.stdout.write(`${JSON.stringify({ ok: failed.length === 0, checks }, null, 2)}\n`);
    if (failed.length) process.exitCode = 1;
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
