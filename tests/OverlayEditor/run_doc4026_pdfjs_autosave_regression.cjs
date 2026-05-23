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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4026);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TEST_TEXT = 'PDFJS autosave text';

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
    const page = await browser.newPage({ viewport: { width: 1800, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(750);

        const saveUrl = await page.evaluate(() => document.getElementById('edit-new-root')?.dataset?.saveUrl || '');
        if (!saveUrl) throw new Error('save URL missing from edit-new-root dataset');
        await page.route(saveUrl, async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_autosave_test' }),
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

        const blankAutosaveRequestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url() === saveUrl
        ), { timeout: 30000 });
        await page.mouse.click(target.x, target.y);
        await page.waitForSelector('.enpv-annotation-box.is-selected.is-editing [contenteditable="true"]', { timeout: 10000 });
        const placeholder = await page.evaluate(() => {
            const box = document.querySelector('.enpv-annotation-box.is-selected.is-editing');
            return {
                id: box?.dataset?.annotationId || '',
                exists: Boolean(box),
            };
        });
        if (!placeholder.exists || !placeholder.id) {
            throw new Error(`empty add-text placeholder was not created: ${JSON.stringify(placeholder)}`);
        }

        const blankAutosaveRequest = await blankAutosaveRequestPromise;
        const blankPayload = blankAutosaveRequest.postDataJSON();
        const blankSaved = [
            ...(Array.isArray(blankPayload.annotations) ? blankPayload.annotations : []),
            ...(Array.isArray(blankPayload.session_annotations) ? blankPayload.session_annotations : []),
        ].find((annotation) => String(annotation.id || '') === placeholder.id);
        if (blankSaved) {
            throw new Error(`blank placeholder was included in autosave payload: ${JSON.stringify(blankSaved)}`);
        }
        try {
            await page.waitForFunction((id) => Array.from(document.querySelectorAll('.enpv-annotation-box.is-selected.is-editing'))
                .some((box) => box.dataset.annotationId === id && box.querySelector('[contenteditable="true"]')), placeholder.id, { timeout: 10000 });
        } catch (error) {
            const state = await page.evaluate((id) => ({
                activeTag: document.activeElement?.tagName || '',
                activeClass: document.activeElement?.className || '',
                boxes: Array.from(document.querySelectorAll('.enpv-annotation-box')).map((box) => ({
                    id: box.dataset.annotationId || '',
                    className: box.className,
                    text: box.textContent || '',
                    contentEditable: box.querySelector('[contenteditable]')?.getAttribute('contenteditable') || '',
                    matchesPlaceholder: box.dataset.annotationId === id,
                })),
            }), placeholder.id);
            throw new Error(`blank placeholder did not remain editable after autosave: ${JSON.stringify(state).slice(0, 2000)}`);
        }

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url() === saveUrl
        ), { timeout: 30000 });
        await page.keyboard.type(TEST_TEXT);

        const request = await requestPromise;
        const payload = request.postDataJSON();
        const saved = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ].find((annotation) => String(annotation.text || '') === TEST_TEXT);

        if (!saved) {
            throw new Error(`autosave payload did not include typed annotation: ${JSON.stringify(payload).slice(0, 2000)}`);
        }

        const focusState = await page.evaluate((id) => {
            const box = Array.from(document.querySelectorAll('.enpv-annotation-box.is-selected.is-editing'))
                .find((candidate) => candidate.dataset.annotationId === id) || null;
            const editor = box?.querySelector('[contenteditable="true"]') || null;
            return {
                hasBox: Boolean(box),
                hasEditor: Boolean(editor),
                activeEditor: document.activeElement === editor,
                text: String(editor?.textContent || ''),
                contentEditable: editor?.getAttribute('contenteditable') || '',
            };
        }, placeholder.id);
        if (!focusState.hasBox || !focusState.hasEditor || !focusState.activeEditor || focusState.text !== TEST_TEXT) {
            throw new Error(`autosave blurred or closed the active text editor: ${JSON.stringify(focusState)}`);
        }

        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 10000 });
        console.log(JSON.stringify({
            id: saved.id,
            text: saved.text,
            userCreated: saved.userCreated,
            status: await page.textContent('#save-status'),
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
