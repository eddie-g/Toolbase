#!/usr/bin/env node
/**
 * Regression test for the image background-removal action in /edit-new.
 *
 * Verifies that:
 *   1. Selecting a regular image annotation shows the BG action.
 *   2. Clicking BG rewrites the annotation asset as a transparent PNG.
 *   3. Border pixels that were matte white become transparent.
 */

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

const failures = [];
function assert(cond, label, detail = '') {
    if (cond) {
        console.log(`  ✔ ${label}`);
        return;
    }
    console.log(`  ✘ ${label}${detail ? ` :: ${detail}` : ''}`);
    failures.push({ label, detail });
}

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function createBlankDoc(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const response = await page.evaluate(async () => {
        const r = await fetch('/pdf-tests/create-blank?page_size=Letter&orientation=portrait', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const raw = await r.text();
        let body = null;
        try { body = JSON.parse(raw); } catch (_err) {}
        return { ok: r.ok, status: r.status, body, raw };
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`blank create failed: status=${response.status} body=${String(response.raw || '').slice(0, 400)}`);
    }
    return Number(response.body.document_id);
}

async function main() {
    console.log(`[image-remove-bg] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 1200 } });

    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            console.log(`[browser:${msg.type()}] ${msg.text()}`);
        }
    });

    try {
        await loginAdmin(page);
        console.log('[image-remove-bg] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[image-remove-bg] blank doc id=${documentId}`);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForFunction(() => !!window.__editorTestState && !!window.__editorTestState.pageData?.[0], null, { timeout: 30000 });
        await page.waitForTimeout(1000);

        const initial = await page.evaluate(() => {
            const state = window.__editorTestState;
            const data = state.pageData[0];
            const canvas = document.createElement('canvas');
            canvas.width = 96;
            canvas.height = 96;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#111827';
            ctx.fillRect(22, 22, 52, 52);
            const dataUrl = canvas.toDataURL('image/png');
            const ann = {
                _uid: `bg_test_${Date.now()}`,
                id: state.generateAnnotationId(),
                pageIndex: 0,
                type: 'image',
                pdfX: 96,
                pdfY: 540,
                pdfWidth: 120,
                pdfHeight: 120,
                rotation: 0,
                opacity: 1,
                userCreated: true,
                dataUrl,
                src: '',
                fileName: 'matte-sample.png',
                mimeType: 'image/png',
                intrinsicWidth: 96,
                intrinsicHeight: 96,
                text: '',
                _originalBox: { x: 96, y: 540, w: 120, h: 120 },
                _originalPdfBox: { x: 96, y: 540, w: 120, h: 120 },
            };
            data.annotations.push(ann);
            state.redrawOverlay(0);
            state.selectAnnotation(ann, 0);
            return { uid: ann._uid, dataUrl };
        });

        await page.waitForFunction(() => {
            const btn = document.getElementById('sh-1-bg');
            return !!btn && getComputedStyle(btn).display !== 'none' && !btn.disabled;
        }, null, { timeout: 5000 });

        await page.click('#sh-1-bg');

        await page.waitForFunction((uid, previousDataUrl) => {
            const ann = window.__editorTestState.pageData[0].annotations.find((item) => item._uid === uid);
            return !!ann && typeof ann.dataUrl === 'string' && ann.dataUrl.length > 0 && ann.dataUrl !== previousDataUrl;
        }, initial.uid, initial.dataUrl, { timeout: 5000 });

        const result = await page.evaluate(async (uid) => {
            const ann = window.__editorTestState.pageData[0].annotations.find((item) => item._uid === uid);
            if (!ann) throw new Error('annotation missing after BG removal');
            const img = await new Promise((resolve, reject) => {
                const probe = new Image();
                probe.onload = () => resolve(probe);
                probe.onerror = () => reject(new Error('failed to decode transformed asset'));
                probe.src = ann.dataUrl;
            });
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth || img.width || 1;
            canvas.height = img.naturalHeight || img.height || 1;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            const corner = Array.from(ctx.getImageData(0, 0, 1, 1).data);
            const center = Array.from(ctx.getImageData(Math.floor(canvas.width / 2), Math.floor(canvas.height / 2), 1, 1).data);
            return {
                fileName: ann.fileName,
                mimeType: ann.mimeType,
                corner,
                center,
            };
        }, initial.uid);

        console.log('[image-remove-bg] transformed asset:', JSON.stringify(result));

        assert(result.mimeType === 'image/png', 'transformed asset stays PNG', `mimeType=${result.mimeType}`);
        assert(String(result.fileName || '').endsWith('.png'), 'transformed asset keeps a PNG filename', `fileName=${result.fileName}`);
        assert((result.corner?.[3] || 0) === 0, 'white matte corner becomes transparent', `corner=${JSON.stringify(result.corner)}`);
        assert((result.center?.[3] || 0) > 200, 'foreground stays opaque', `center=${JSON.stringify(result.center)}`);
    } catch (err) {
        console.error('[image-remove-bg] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[image-remove-bg] FAILED (${failures.length})`);
        process.exit(1);
    }

    console.log('\n[image-remove-bg] PASSED');
}

main();
