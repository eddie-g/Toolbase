#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 2861);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const SESSION_ID = `codex-edit-new-delete-${DOCUMENT_ID}-${crypto.randomUUID()}`;
const SESSION_KEY = `edit_new_session_${DOCUMENT_ID}`;

async function fetchInfo(page) {
    const payload = await page.evaluate(async ({ baseUrl, documentId, sessionId }) => {
        const response = await fetch(`${baseUrl}/pdf-tests/document/${documentId}/info?session_id=${encodeURIComponent(sessionId)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return {
            ok: response.ok,
            status: response.status,
            body: await response.json().catch(() => ({})),
        };
    }, {
        baseUrl: BASE_URL,
        documentId: DOCUMENT_ID,
        sessionId: SESSION_ID,
    });

    if (!payload.ok || !payload.body?.success) {
        throw new Error(`documentInfo failed: ${JSON.stringify(payload)}`);
    }

    return payload.body;
}

function chooseTargetAnnotation(info) {
    const annotations = Array.isArray(info?.annotations) ? info.annotations : [];
    const target = annotations.find((annotation) => String(annotation?.id || '').startsWith('promoted_'));
    if (!target) {
        throw new Error('No promoted annotation found to delete.');
    }
    return {
        id: String(target.id || ''),
        pageIndex: Number(target.pageIndex) || 0,
        pdfX: Number(target.pdfX) || 0,
        pdfY: Number(target.pdfY) || 0,
        pdfWidth: Number(target.pdfWidth) || 0,
        pdfHeight: Number(target.pdfHeight) || 0,
        sourcePageHeight: Number(target.sourcePageHeight) || 0,
    };
}

async function annotationClientPoint(page, target) {
    const point = await page.evaluate((annotation) => {
        const canvas = document.getElementById(`oc-${annotation.pageIndex + 1}`);
        if (!(canvas instanceof HTMLCanvasElement)) return null;
        const rect = canvas.getBoundingClientRect();
        if (!rect.width || !rect.height || !annotation.sourcePageHeight) return null;
        const scale = rect.height / annotation.sourcePageHeight;
        const topPts = annotation.sourcePageHeight - (annotation.pdfY + annotation.pdfHeight);
        return {
            x: rect.left + ((annotation.pdfX + (annotation.pdfWidth / 2)) * scale),
            y: rect.top + ((topPts + (annotation.pdfHeight / 2)) * scale),
        };
    }, target);

    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) {
        throw new Error(`Failed to resolve annotation client point for ${JSON.stringify(target)}`);
    }

    return point;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    await context.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
    }, { key: SESSION_KEY, value: SESSION_ID });
    const page = await context.newPage();

    try {
        await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', ADMIN_PASSWORD);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new`, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelectorAll('.page-content').length > 0);
        await page.waitForTimeout(2500);

        const beforeInfo = await fetchInfo(page);
        const target = chooseTargetAnnotation(beforeInfo);
        const clickPoint = await annotationClientPoint(page, target);
        await page.mouse.click(clickPoint.x, clickPoint.y);
        await page.waitForTimeout(300);

        const deleteHandle = page.locator(`#dh-${target.pageIndex + 1}`);
        await deleteHandle.waitFor({ state: 'visible', timeout: 5000 });
        await deleteHandle.click();
        await page.waitForTimeout(300);

        await page.getByRole('button', { name: 'Save' }).click();
        await page.waitForTimeout(1200);

        const afterDeleteInfo = await fetchInfo(page);
        const stillPresentAfterDelete = (afterDeleteInfo.annotations || []).some((annotation) => String(annotation?.id || '') === target.id);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelectorAll('.page-content').length > 0);
        await page.waitForTimeout(2500);

        const afterReloadInfo = await fetchInfo(page);
        const stillPresentAfterReload = (afterReloadInfo.annotations || []).some((annotation) => String(annotation?.id || '') === target.id);

        const result = {
            target_id: target.id,
            present_before: true,
            present_after_delete: stillPresentAfterDelete,
            present_after_reload: stillPresentAfterReload,
        };
        console.log(JSON.stringify(result, null, 2));

        if (stillPresentAfterDelete || stillPresentAfterReload) {
            throw new Error(`Deleted annotation returned: ${JSON.stringify(result, null, 2)}`);
        }
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
