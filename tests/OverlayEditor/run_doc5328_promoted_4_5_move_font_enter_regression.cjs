#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5328);
const TARGET_ID = process.env.TARGET_ID || 'promoted_4_5';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const MOVE_Y = Number(process.env.MOVE_Y || 250);

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    for (const password of Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'password1',
    ].filter(Boolean)))) {
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
    throw new Error('Unable to log in for the doc5328 promoted_4_5 regression.');
}

function maxRectEdgeDelta(left, right) {
    if (!left || !right) return Number.POSITIVE_INFINITY;
    return Math.max(...['left', 'right', 'top', 'bottom'].map((edge) => (
        Math.abs(Number(left[edge]) - Number(right[edge]))
    )));
}

async function targetState(box) {
    return box.evaluate((node) => {
        const content = node.querySelector('.enpv-text-content');
        const range = document.createRange();
        range.selectNodeContents(content);
        const rangeRects = Array.from(range.getClientRects())
            .filter((rect) => rect.width > 0 && rect.height > 0);
        range.detach?.();
        const textRect = rangeRects.length ? {
            left: Math.min(...rangeRects.map((rect) => rect.left)),
            right: Math.max(...rangeRects.map((rect) => rect.right)),
            top: Math.min(...rangeRects.map((rect) => rect.top)),
            bottom: Math.max(...rangeRects.map((rect) => rect.bottom)),
        } : null;
        const boxRect = node.getBoundingClientRect();
        const textStyle = getComputedStyle(content);
        const lineHeightPx = Number.parseFloat(textStyle.lineHeight || '') || 16;
        const textLineTops = Array.from(new Set(rangeRects
            .map((rect) => Math.round(rect.top * 10) / 10)))
            .sort((left, right) => left - right)
            .reduce((lines, top) => {
                if (!lines.length || top - lines.at(-1) > Math.max(4, lineHeightPx * 0.5)) {
                    lines.push(top);
                }
                return lines;
            }, []);
        return {
            boxRect: {
                left: boxRect.left,
                right: boxRect.right,
                top: boxRect.top,
                bottom: boxRect.bottom,
                width: boxRect.width,
                height: boxRect.height,
            },
            textRect,
            text: String(content?.textContent || '').replace(/[\u200b\u200c\u200d\u2060\ufeff]/gu, ''),
            rawText: content?.textContent || '',
            innerHtml: content?.innerHTML || '',
            textLineTops,
            editing: node.classList.contains('is-editing'),
            movedTextOverlay: node.dataset.movedTextOverlay || '',
            dxPts: Number(node.dataset.dxPts || 0),
            dyPts: Number(node.dataset.dyPts || 0),
            editorMode: node.dataset.editorMode || '',
            naturalTextFlow: node.dataset.naturalTextFlow || '',
            sourceSpanEditActive: node.dataset.sourceSpanEditActive || '',
            sourceSpanDisplayActive: node.dataset.sourceSpanDisplayActive || '',
            sourceSpanGlyphAligned: node.dataset.sourceSpanGlyphAligned || '',
            fontFamily: textStyle.fontFamily,
            lineHeight: textStyle.lineHeight,
            whiteSpace: textStyle.whiteSpace,
        };
    });
}

async function dragBox(page, box, deltaY) {
    const bounds = await box.boundingBox();
    if (!bounds) throw new Error('promoted_4_5 has no drag bounds.');
    const x = bounds.x + Math.min(bounds.width - 12, Math.max(12, bounds.width * 0.8));
    const y = bounds.y + Math.min(bounds.height - 8, Math.max(8, bounds.height * 0.5));
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x, y + deltaY, { steps: 20 });
    await page.mouse.up();
    await page.waitForTimeout(150);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1900, height: 1100 } });
    const page = await context.newPage();
    const selector = `.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`;
    try {
        await loginAdmin(page);
        await page.goto(
            `${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`,
            { waitUntil: 'domcontentloaded', timeout: 90000 },
        );
        await page.evaluate(({ documentId, sessionId }) => {
            localStorage.setItem(`edit_new_session_${documentId}`, sessionId);
        }, {
            documentId: DOCUMENT_ID,
            sessionId: `doc5328-nk3-${Date.now()}`,
        });
        await page.reload({ waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 4 }));
        await page.locator('.pdfViewer .page[data-page-number="4"]').scrollIntoViewIfNeeded();
        await page.locator('#ftb-edit-mode').click();
        await page.waitForSelector(`${selector}:visible`, { timeout: 90000 });
        const box = page.locator(`${selector}:visible`).last();
        await box.scrollIntoViewIfNeeded();

        await dragBox(page, box, MOVE_Y);
        const moved = await targetState(box);
        if (moved.movedTextOverlay !== '1' || Math.abs(moved.dyPts) < 1) {
            throw new Error(`Target did not become a moved overlay: ${JSON.stringify(moved)}`);
        }

        const movedBounds = await box.boundingBox();
        await page.mouse.dblclick(
            movedBounds.x + (movedBounds.width * 0.5),
            movedBounds.y + (movedBounds.height * 0.5),
        );
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
        const editing = await targetState(box);
        const editEntryTextDelta = maxRectEdgeDelta(moved.textRect, editing.textRect);
        if (!editing.editing || editEntryTextDelta > 1.5) {
            throw new Error(`Moved promoted text jumped on edit entry: ${JSON.stringify({ editEntryTextDelta, moved, editing })}`);
        }

        await page.keyboard.press('Escape');
        await page.waitForSelector(`${selector}:not(.is-editing)`, { timeout: 10000 });
        await box.click({ force: true });
        await page.locator('#afb-font').selectOption('Georgia');
        await page.waitForTimeout(250);
        const georgia = await targetState(box);
        const expectedText = '•     Place ap_bookmark.mdf in the forms subdirectory accessible to Central.\n•     Place ap_bookmark.bmk in an addressable directory.';
        const movedLineStep = moved.textLineTops[1] - moved.textLineTops[0];
        const georgiaLineStep = georgia.textLineTops[1] - georgia.textLineTops[0];
        if (georgia.text !== expectedText
            || !/Georgia/i.test(georgia.fontFamily)
            || !georgia.text.includes('•     Place ap_bookmark.mdf in')
            || !Number.isFinite(movedLineStep)
            || !Number.isFinite(georgiaLineStep)
            || Math.abs(movedLineStep - georgiaLineStep) > 2) {
            throw new Error(`Georgia formatting lost promoted text spacing: ${JSON.stringify(georgia)}`);
        }

        await box.dblclick({ force: true });
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
        await box.locator('.enpv-text-content').evaluate((content) => {
            content.focus();
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(content);
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        });
        const beforeEnter = await targetState(box);
        await page.keyboard.press('Enter');
        await page.waitForTimeout(150);
        const afterEnter = await targetState(box);
        await page.keyboard.press('Escape');
        await page.waitForSelector(`${selector}:not(.is-editing)`, { timeout: 10000 });
        const committedEnter = await targetState(box);
        if (!afterEnter.text.startsWith(expectedText)
            || !committedEnter.text.endsWith('\n')
            || committedEnter.text !== `${expectedText}\n`
            || committedEnter.boxRect.height <= beforeEnter.boxRect.height) {
            throw new Error(`Enter did not preserve a new blank line: ${JSON.stringify({ beforeEnter, afterEnter, committedEnter })}`);
        }

        let downloadPayload = null;
        await page.route('**/download-annotated-pdf', async (route) => {
            downloadPayload = JSON.parse(route.request().postData() || '{}');
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: '{"message":"captured by NK_3 regression"}',
            });
        });
        await page.locator('#download-pdf-btn').click({ force: true });
        await page.waitForTimeout(500);
        await page.unroute('**/download-annotated-pdf');
        const payloadAnnotation = (downloadPayload?.annotations || []).find(
            (annotation) => annotation.id === TARGET_ID,
        );
        const payloadTrailingBreak = payloadAnnotation?.richTextRuns?.at(-1)?.type === 'break';
        if (!payloadAnnotation
            || payloadAnnotation.text !== `${expectedText}\n`
            || !/Georgia/i.test(String(payloadAnnotation.fontFamily || ''))
            || !payloadTrailingBreak) {
            throw new Error(`Download payload lost the authored blank line: ${JSON.stringify(payloadAnnotation)}`);
        }

        process.stdout.write(`${JSON.stringify({
            status: 'pass',
            documentId: DOCUMENT_ID,
            targetId: TARGET_ID,
            editEntryTextDelta,
            movedDyPts: moved.dyPts,
            georgiaFont: georgia.fontFamily,
            movedLineStep,
            georgiaLineStep,
            committedText: committedEnter.text,
            heightGrowth: committedEnter.boxRect.height - beforeEnter.boxRect.height,
            payloadTrailingBreak,
        }, null, 2)}\n`);
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error?.stack || error);
    process.exit(1);
});
