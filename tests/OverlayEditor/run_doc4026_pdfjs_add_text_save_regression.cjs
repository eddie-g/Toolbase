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
const TEST_TEXT = 'PDFJS add text save';

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
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
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
                body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_add_text_test' }),
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
        await page.keyboard.type(TEST_TEXT);

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url() === saveUrl
        ), { timeout: 30000 });
        await page.locator('#save-btn').click();
        const request = await requestPromise;
        const payload = request.postDataJSON();
        const saved = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ].find((annotation) => String(annotation.text || '') === TEST_TEXT);

        if (!saved) {
            throw new Error(`created text annotation missing from save payload: ${JSON.stringify(payload).slice(0, 2000)}`);
        }
        const checks = [
            ['type', saved.type === 'text'],
            ['userCreated', saved.userCreated === true],
            ['userAuthored', saved.userAuthored === true],
            ['skipPdfjsSourceMask', saved.skipPdfjsSourceMask === true],
            ['styleDirty', saved.styleDirty === true],
            ['editorMode', saved.pdfjsEditorMode === 'rich'],
            ['geometry', Number(saved.pdfWidth) > 0 && Number(saved.pdfHeight) > 0],
            ['font', String(saved.fontFamily || '') === 'Helvetica' && Number(saved.fontSize) === 12],
        ];
        const failed = checks.filter(([, pass]) => !pass).map(([name]) => name);
        if (failed.length) {
            throw new Error(`created text payload checks failed for ${failed.join(', ')}: ${JSON.stringify(saved, null, 2)}`);
        }

        console.log(JSON.stringify({
            id: saved.id,
            text: saved.text,
            pageIndex: saved.pageIndex,
            pdfX: saved.pdfX,
            pdfY: saved.pdfY,
            pdfWidth: saved.pdfWidth,
            pdfHeight: saved.pdfHeight,
            userCreated: saved.userCreated,
            skipPdfjsSourceMask: saved.skipPdfjsSourceMask,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
