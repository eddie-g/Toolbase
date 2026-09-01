#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5293);
const { requireAdminCredentials } = require('../../tools/admin-credentials.cjs');
// Resolved from AUTOMATED_TESTS_ADMIN_* (the QA admin), never hardcoded:
// a stale default here is what led to real admin passwords being reset.
const { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } = requireAdminCredentials();
const TARGET_ID = 'promoted_3_4';
const TARGET_SELECTOR = `.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`;

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    // One attempt. Trying a list of candidate passwords walked straight
    // into Fortify's five-per-minute login throttle.
    await page.fill('#data\.email', ADMIN_EMAIL);
    await page.fill('#data\.password', ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    try {
        await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 });
    } catch (_) {
        if (page.url().includes('/admin/login')) {
            throw new Error(`Admin login failed for ${ADMIN_EMAIL}. Check AUTOMATED_TESTS_ADMIN_* in .env.`);
        }
    }
}

async function paragraphState(page) {
    return page.evaluate(({ targetId, selector }) => {
        const box = document.querySelector(selector);
        const text = box?.querySelector('.enpv-text-content');
        if (!box || !text) return { error: 'target missing' };
        const rangeRects = (node) => {
            const range = document.createRange();
            range.selectNodeContents(node);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 1 && rect.height > 1)
                .map((rect) => ({
                    left: rect.left,
                    right: rect.right,
                    top: rect.top,
                    bottom: rect.bottom,
                    width: rect.width,
                    height: rect.height,
                }));
            range.detach?.();
            return rects;
        };
        const groupRows = (rects) => {
            const rows = [];
            rects.slice().sort((left, right) => left.top - right.top || left.left - right.left).forEach((rect) => {
                let row = rows.find((candidate) => Math.abs(candidate.top - rect.top) <= 1.5);
                if (!row) {
                    row = { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
                    rows.push(row);
                } else {
                    row.left = Math.min(row.left, rect.left);
                    row.right = Math.max(row.right, rect.right);
                    row.top = Math.min(row.top, rect.top);
                    row.bottom = Math.max(row.bottom, rect.bottom);
                }
            });
            return rows.sort((left, right) => left.top - right.top).map((row) => ({
                ...row,
                width: row.right - row.left,
                height: row.bottom - row.top,
            }));
        };
        const sourceRows = Array.from(document.querySelectorAll(
            `[data-enpv-persistent-id="${targetId}"]`,
        )).flatMap(rangeRects);
        const scaffoldRows = Array.from(text.querySelectorAll('[data-source-span-run="1"]'))
            .flatMap(rangeRects);
        const textNodeRects = [];
        const walker = document.createTreeWalker(text, NodeFilter.SHOW_TEXT);
        let textNode;
        while ((textNode = walker.nextNode())) {
            if (!String(textNode.nodeValue || '').trim()) continue;
            textNodeRects.push(...rangeRects(textNode));
        }
        const scaffoldGlyphRows = groupRows(scaffoldRows);
        const computed = getComputedStyle(text);
        return {
            text: String(text.textContent || '').replace(/\r\n?/g, '\n'),
            editing: box.classList.contains('is-editing'),
            selected: box.classList.contains('is-selected'),
            naturalTextFlow: box.dataset.naturalTextFlow || '',
            sourceSpanEditActive: box.dataset.sourceSpanEditActive || '',
            sourceSpanDisplayActive: box.dataset.sourceSpanDisplayActive || '',
            sourceRows: groupRows(sourceRows),
            scaffoldRows: scaffoldGlyphRows,
            renderedRows: scaffoldGlyphRows.length ? scaffoldGlyphRows : groupRows(textNodeRects),
            style: {
                paddingLeft: computed.paddingLeft,
                paddingTop: computed.paddingTop,
                textIndent: computed.textIndent,
                lineHeight: computed.lineHeight,
                whiteSpace: computed.whiteSpace,
            },
        };
    }, { targetId: TARGET_ID, selector: TARGET_SELECTOR });
}

function rowEdgeDelta(left, right) {
    if (left.length !== right.length) return Number.POSITIVE_INFINITY;
    let delta = 0;
    left.forEach((row, index) => {
        for (const edge of ['left', 'right', 'top', 'bottom']) {
            delta = Math.max(delta, Math.abs(Number(row[edge]) - Number(right[index][edge])));
        }
    });
    return delta;
}

function normalizedText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    const checks = [];
    const record = (item, pass, detail = null) => checks.push({ item, pass: Boolean(pass), detail });
    try {
        await loginAdmin(page);
        // This probe changes the live DOM only; never persist its 5 -> 6 edit.
        await page.route('**/*', async (route) => {
            if (route.request().method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 3 }));
        await page.locator('.pdfViewer .page[data-page-number="3"]').scrollIntoViewIfNeeded();
        await page.locator('#ftb-edit-mode').click();

        const target = page.locator(TARGET_SELECTOR).first();
        await target.waitFor({ state: 'attached', timeout: 90000 });
        await target.scrollIntoViewIfNeeded();
        const initial = await paragraphState(page);

        await target.click({ force: true });
        await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
        await page.waitForSelector(`${TARGET_SELECTOR}.is-editing`, { timeout: 10000 });
        const editing = await paragraphState(page);

        const text = target.locator('.enpv-text-content');
        const replaceLeadingDigit = async (digit) => text.evaluate((node, replacement) => {
            const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            let textNode;
            while ((textNode = walker.nextNode())) {
                const index = String(textNode.nodeValue || '').search(/\d/);
                if (index < 0) continue;
                const range = document.createRange();
                range.setStart(textNode, index);
                range.setEnd(textNode, index + 1);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                return;
            }
            throw new Error('Could not select the leading digit in promoted_3_4.');
        }, digit).then(() => page.keyboard.type(digit));

        // The shared test document currently contains an earlier saved "1."
        // state. Restore "5." in this browser-only probe before recording the
        // requested initial state; all non-GET requests are blocked above.
        await replaceLeadingDigit('5');
        await page.waitForTimeout(100);
        const fiveState = await paragraphState(page);

        await replaceLeadingDigit('6');
        await page.waitForTimeout(100);
        const changed = await paragraphState(page);

        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        const deselected = await paragraphState(page);

        const expectedText = normalizedText(fiveState.text).replace(/^5\./, '6.');
        record('only_the_5_changes_to_6',
            normalizedText(changed.text) === expectedText
                && normalizedText(deselected.text) === expectedText,
            { initial: fiveState.text, changed: changed.text, deselected: deselected.text });
        record('source_and_edit_entry_have_five_rows',
            initial.sourceRows.length === 5
                && editing.scaffoldRows.length === 5
                && fiveState.renderedRows.length === 5,
            { source: initial.sourceRows, editing: editing.scaffoldRows, five: fiveState.renderedRows });
        record('row_spacing_survives_change_and_deselect',
            changed.renderedRows.length === 5
                && deselected.renderedRows.length === 5
                && rowEdgeDelta(initial.sourceRows, changed.renderedRows) <= 3
                && rowEdgeDelta(initial.sourceRows, deselected.renderedRows) <= 3,
            {
                initial: initial.sourceRows,
                changed: changed.renderedRows,
                deselected: deselected.renderedRows,
                changedDelta: rowEdgeDelta(initial.sourceRows, changed.renderedRows),
                deselectedDelta: rowEdgeDelta(initial.sourceRows, deselected.renderedRows),
                styles: { editing: editing.style, changed: changed.style, deselected: deselected.style },
            });

        const failed = checks.filter((check) => !check.pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            annotationId: TARGET_ID,
            states: { initial, editing, fiveState, changed, deselected },
            checks,
        }, null, 2));
        if (failed.length) process.exitCode = 1;
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
