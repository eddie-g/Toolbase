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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4194);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TEST_TEXT = 'line 1\nline 2\nline 3';
const FINAL_TEXT = `${TEST_TEXT}\nline 4`;

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

        const saveUrl = await page.evaluate(() => document.getElementById('edit-new-root')?.dataset?.saveUrl || '');
        if (!saveUrl) throw new Error('save URL missing from edit-new-root dataset');
        await page.route(saveUrl, async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_multiline_text_test' }),
            });
        });

        await page.locator('#add-text-btn').click();
        await page.waitForSelector('body.enpv-add-text-on', { timeout: 5000 });
        const target = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const rect = pageEl?.getBoundingClientRect();
            if (!rect) return null;
            return { x: rect.left + 120, y: rect.top + 140 };
        });
        if (!target) throw new Error('could not locate page 1 for add text');
        await page.mouse.click(target.x, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });

        await page.keyboard.type('line 1');
        await page.keyboard.press('Enter');
        await page.keyboard.type('line 2');
        await page.keyboard.press('Enter');
        await page.keyboard.type('line 3');

        await page.mouse.click(target.x + 220, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected:not(.is-editing) .enpv-text-content', { timeout: 10000 });

        const committed = await page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected');
            const textContent = box?.querySelector('.enpv-text-content');
            if (!box || !textContent) throw new Error('Missing committed selected text box.');
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
                innerText: textContent.innerText,
                textContent: textContent.textContent,
                whiteSpace: window.getComputedStyle(textContent).whiteSpace,
                isEditing: box.classList.contains('is-editing'),
                contentEditable: textContent.contentEditable,
                rowCount: rowTops.length,
            };
        });

        if (committed.isEditing || committed.contentEditable === 'true') {
            throw new Error(`text box did not leave edit mode: ${JSON.stringify(committed, null, 2)}`);
        }
        if (normalizeNewlines(committed.innerText).trim() !== TEST_TEXT) {
            throw new Error(`committed visible text lost hard breaks: ${JSON.stringify(committed, null, 2)}`);
        }
        if (committed.whiteSpace !== 'pre-wrap') {
            throw new Error(`committed text is not rendered with pre-wrap: ${JSON.stringify(committed, null, 2)}`);
        }
        if (committed.rowCount < 3) {
            throw new Error(`committed text did not render as three rows: ${JSON.stringify(committed, null, 2)}`);
        }

        const readSelectedDisplayText = async () => page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected');
            const textContent = box?.querySelector('.enpv-text-content');
            if (!box || !textContent) throw new Error('Missing selected text box.');
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
                innerText: textContent.innerText,
                textContent: textContent.textContent,
                whiteSpace: window.getComputedStyle(textContent).whiteSpace,
                rowCount: rowTops.length,
            };
        });

        await page.locator('#enpv-ann-menu [data-action="uppercase"]').click();
        const uppercased = await readSelectedDisplayText();
        if (normalizeNewlines(uppercased.innerText).trim() !== TEST_TEXT.toUpperCase()
            || uppercased.whiteSpace !== 'pre-wrap'
            || uppercased.rowCount < 3) {
            throw new Error(`uppercase collapsed multiline text: ${JSON.stringify(uppercased, null, 2)}`);
        }

        await page.locator('#enpv-ann-menu [data-action="lowercase"]').click();
        const lowercased = await readSelectedDisplayText();
        if (normalizeNewlines(lowercased.innerText).trim() !== TEST_TEXT
            || lowercased.whiteSpace !== 'pre-wrap'
            || lowercased.rowCount < 3) {
            throw new Error(`lowercase collapsed multiline text: ${JSON.stringify(lowercased, null, 2)}`);
        }

        await page.locator('.enpv-annotation-box.is-selected .enpv-text-content').dblclick();
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });
        const reopened = await page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
            const textContent = box?.querySelector('.enpv-text-content');
            if (!box || !textContent) throw new Error('Missing reopened editable text box.');
            return {
                innerText: textContent.innerText,
                whiteSpace: window.getComputedStyle(textContent).whiteSpace,
            };
        });
        if (normalizeNewlines(reopened.innerText).trim() !== TEST_TEXT || reopened.whiteSpace !== 'pre-wrap') {
            throw new Error(`reopened multiline text did not preserve hard breaks: ${JSON.stringify(reopened, null, 2)}`);
        }

        await page.keyboard.press('Enter');
        const afterSingleEnter = await readSelectedDisplayText();
        if (afterSingleEnter.rowCount < 4 || afterSingleEnter.whiteSpace !== 'pre-wrap') {
            throw new Error(`single Enter did not create a visible hard break: ${JSON.stringify(afterSingleEnter, null, 2)}`);
        }
        await page.keyboard.type('line 4');
        const afterTypingFourthLine = await readSelectedDisplayText();
        if (normalizeNewlines(afterTypingFourthLine.innerText).trim() !== FINAL_TEXT
            || afterTypingFourthLine.rowCount < 4) {
            throw new Error(`typing after one Enter did not land on a new line: ${JSON.stringify(afterTypingFourthLine, null, 2)}`);
        }

        await page.mouse.click(target.x + 220, target.y + 60);
        await page.waitForSelector('.enpv-annotation-box.is-selected:not(.is-editing) .enpv-text-content', { timeout: 10000 });

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url() === saveUrl
        ), { timeout: 30000 });
        await page.locator('#save-btn').click();
        const request = await requestPromise;
        const payload = request.postDataJSON();
        const saved = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ].find((annotation) => normalizeNewlines(annotation.text) === FINAL_TEXT);

        if (!saved) {
            throw new Error(`multiline text annotation missing from save payload: ${JSON.stringify(payload).slice(0, 2000)}`);
        }
        if (saved.richTextHtml) {
            throw new Error(`plain multiline text should not need richTextHtml: ${JSON.stringify(saved, null, 2)}`);
        }

        console.log(JSON.stringify({
            id: saved.id,
            text: saved.text,
            rowCount: committed.rowCount,
            whiteSpace: committed.whiteSpace,
            userCreated: saved.userCreated,
            pdfjsEditorMode: saved.pdfjsEditorMode,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});