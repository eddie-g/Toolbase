#!/usr/bin/env node

'use strict';

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4458);
const TARGET_ID = process.env.TARGET_ID || 'promoted_1_27';
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
    throw new Error('Unable to log in for doc4458 edit-bounds regression.');
}

async function enterEdit(page, box, selector) {
    await box.scrollIntoViewIfNeeded();
    await box.click({ force: true });
    await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
    try {
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 2500 });
    } catch (_) {
        await box.dblclick({ force: true });
        await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
    }
    await page.waitForTimeout(75);
}

async function readState(box) {
    return box.evaluate((node) => {
        const text = node.querySelector('.enpv-text-content');
        const page = node.closest('.page');
        const pageRect = page.getBoundingClientRect();
        const boxRect = node.getBoundingClientRect();
        const textRect = text.getBoundingClientRect();
        const selection = window.getSelection();
        const describeSelectionNode = (selectionNode) => {
            if (!selectionNode) return null;
            if (selectionNode.nodeType === Node.TEXT_NODE) {
                return {
                    type: 'text',
                    value: String(selectionNode.nodeValue || ''),
                    parent: selectionNode.parentElement?.outerHTML?.slice(0, 300) || '',
                    connected: selectionNode.isConnected,
                };
            }
            return {
                type: selectionNode.nodeName,
                html: selectionNode.outerHTML?.slice(0, 300) || '',
                connected: selectionNode.isConnected,
            };
        };
        const range = document.createRange();
        range.selectNodeContents(text);
        const paintedRects = Array.from(range.getClientRects())
            .filter((rect) => rect.width > 0.25 && rect.height > 0.25);
        range.detach?.();
        const maxPaintedRight = Math.max(textRect.left, ...paintedRects.map((rect) => rect.right));
        return {
            text: text.textContent || '',
            editing: node.classList.contains('is-editing'),
            activeIsText: document.activeElement === text,
            selectionInside: Boolean(
                selection?.rangeCount
                && text.contains(selection.anchorNode)
                && text.contains(selection.focusNode)
            ),
            selectionCollapsed: Boolean(selection?.isCollapsed),
            anchorOffset: selection?.anchorOffset ?? null,
            focusOffset: selection?.focusOffset ?? null,
            anchorNode: describeSelectionNode(selection?.anchorNode),
            focusNode: describeSelectionNode(selection?.focusNode),
            pageRight: pageRect.right,
            boxRight: boxRect.right,
            textRight: textRect.right,
            maxPaintedRight,
            pageOverflow: Math.max(0, maxPaintedRight - pageRect.right),
            boxOverflow: Math.max(0, maxPaintedRight - boxRect.right),
            scrollWidth: text.scrollWidth,
            clientWidth: text.clientWidth,
            whiteSpace: getComputedStyle(text).whiteSpace,
            overflowWrap: getComputedStyle(text).overflowWrap,
            editorMode: node.dataset.editorMode || '',
            naturalTextFlow: node.dataset.naturalTextFlow || '',
            promotedReflowEnabled: node.dataset.promotedReflowEnabled || '',
            sourceSpanEditActive: node.dataset.sourceSpanEditActive || '',
            sourceLineCount: text.querySelectorAll('[data-source-span-line="1"]').length,
            className: node.className,
        };
    });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();
    const selector = `.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`;

    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            if (route.request().method() === 'GET') return route.continue();
            return route.abort();
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.locator('#ftb-edit-mode').click();
        await page.waitForSelector(selector, { timeout: 90000 });
        const box = page.locator(`${selector}:visible`).last();

        await enterEdit(page, box, selector);
        const onEntry = await readState(box);
        const originalText = onEntry.text;

        await page.keyboard.type('x');
        await page.waitForTimeout(75);
        const afterFirstInput = await readState(box);
        await page.keyboard.type('y');
        await page.waitForTimeout(75);
        const afterSecondInput = await readState(box);

        const checks = [
            {
                item: 'edit_entry_text_stays_inside_annotation_and_page',
                pass: onEntry.boxOverflow <= 1 && onEntry.pageOverflow <= 1,
                detail: onEntry,
            },
            {
                item: 'first_input_keeps_focus_and_caret_inside_editor',
                pass: afterFirstInput.activeIsText
                    && afterFirstInput.selectionInside
                    && afterFirstInput.selectionCollapsed,
                detail: afterFirstInput,
            },
            {
                item: 'typing_continues_at_same_caret_after_first_input',
                pass: afterFirstInput.text.endsWith('x')
                    && afterSecondInput.text === `${afterFirstInput.text}y`,
                detail: {
                    originalText,
                    afterFirstInput: afterFirstInput.text,
                    afterSecondInput: afterSecondInput.text,
                },
            },
            {
                item: 'edited_text_stays_inside_annotation_and_page',
                pass: afterSecondInput.boxOverflow <= 1 && afterSecondInput.pageOverflow <= 1,
                detail: afterSecondInput,
            },
        ];
        const failed = checks.filter((check) => !check.pass);
        process.stdout.write(`${JSON.stringify({
            ok: failed.length === 0,
            documentId: DOCUMENT_ID,
            targetId: TARGET_ID,
            checks,
        }, null, 2)}\n`);
        if (failed.length) process.exitCode = 1;
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exitCode = 1;
});
