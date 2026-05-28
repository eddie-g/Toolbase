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

function rotationDelta(left, right) {
    return Math.abs(((Number(left) - Number(right) + 540) % 360) - 180);
}

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
    const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);

        const saveUrl = await page.evaluate(() => document.getElementById('edit-new-root')?.dataset?.saveUrl || '');
        if (!saveUrl) throw new Error('save URL missing from edit-new-root dataset');
        await page.route(saveUrl, async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'codex_pdfjs_shape_rotate_test' }),
            });
        });

        const saveRequests = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && request.url() === saveUrl) saveRequests.push(request);
        });

        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        await page.click('[data-shape-tool="square"]');

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1 for shape drawing.');
        await page.mouse.move(pageBox.x + 700, pageBox.y + 260);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 900, pageBox.y + 400, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"] .enpv-shape-rotate-handle', { timeout: 10000 });

        const initial = await page.evaluate(() => {
            const box = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]');
            const handle = box?.querySelector('.enpv-shape-rotate-handle');
            const frame = box?.querySelector('.enpv-shape-selection-frame');
            if (!box || !handle) throw new Error('Missing selected square or rotate handle.');
            if (!frame) throw new Error('Missing selected square rotated selection frame.');
            const boxRect = box.getBoundingClientRect();
            const handleRect = handle.getBoundingClientRect();
            return {
                id: box.dataset.annotationId || '',
                rotation: Number(box.dataset.rotation || 0),
                box: { left: boxRect.left, top: boxRect.top, right: boxRect.right, bottom: boxRect.bottom, width: boxRect.width, height: boxRect.height },
                handle: { left: handleRect.left, top: handleRect.top, width: handleRect.width, height: handleRect.height, centerX: handleRect.left + handleRect.width / 2, centerY: handleRect.top + handleRect.height / 2 },
                frameTransform: frame.style.transform || '',
                frameDisplay: window.getComputedStyle(frame).display,
                display: window.getComputedStyle(handle).display,
            };
        });

        if (!initial.id || initial.display !== 'flex' || initial.frameDisplay !== 'block' || initial.rotation !== 0 || initial.handle.centerY <= initial.box.bottom) {
            throw new Error(`Rotate handle did not start below the selected shape: ${JSON.stringify(initial)}`);
        }

        const targetPoint = {
            x: initial.box.left + initial.box.width + (initial.box.height * 0.18),
            y: initial.box.top + (initial.box.height / 2),
        };
        await page.mouse.move(initial.handle.centerX, initial.handle.centerY);
        await page.mouse.down();
        await page.mouse.move(targetPoint.x, targetPoint.y, { steps: 12 });
        await page.mouse.up();

        await page.waitForFunction(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]');
            return Math.abs(Number(selected?.dataset.rotation || 0)) > 1;
        }, { timeout: 5000 });

        const rotated = await page.evaluate(() => {
            const box = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="square"]');
            const handle = box?.querySelector('.enpv-shape-rotate-handle');
            const frame = box?.querySelector('.enpv-shape-selection-frame');
            const drawn = box?.querySelector('svg.enpv-shape-svg > *');
            if (!box || !handle || !frame || !drawn) throw new Error('Missing rotated square, handle, frame, or SVG element.');
            const boxRect = box.getBoundingClientRect();
            const handleRect = handle.getBoundingClientRect();
            const frameRect = frame.getBoundingClientRect();
            return {
                id: box.dataset.annotationId || '',
                rotation: Number(box.dataset.rotation || 0),
                svgTransform: drawn.getAttribute('transform') || '',
                frameTransform: frame.style.transform || '',
                frame: { width: frameRect.width, height: frameRect.height },
                handle: { centerX: handleRect.left + handleRect.width / 2, centerY: handleRect.top + handleRect.height / 2 },
                box: { left: boxRect.left, top: boxRect.top, right: boxRect.right, bottom: boxRect.bottom, width: boxRect.width, height: boxRect.height },
            };
        });

        const rotatedCenterX = rotated.box.left + (rotated.box.width / 2);
        const rotatedCenterY = rotated.box.top + (rotated.box.height / 2);
        if (rotationDelta(rotated.rotation, 270) > 18
            || !/rotate\(/.test(rotated.svgTransform)
            || !/rotate\(/.test(rotated.frameTransform)
            || rotated.frame.width >= rotated.box.width - 20
            || rotated.frame.height <= rotated.box.height + 20
            || rotated.handle.centerX <= rotatedCenterX
            || Math.abs(rotated.handle.centerY - rotatedCenterY) > 12) {
            throw new Error(`Shape did not rotate with the handle arc: ${JSON.stringify(rotated)}`);
        }

        await page.click('#save-btn');
        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 30000 });
        if (!saveRequests.length) {
            throw new Error('No save request was captured after rotating shape.');
        }
        const payload = saveRequests[saveRequests.length - 1].postDataJSON();
        const saved = [
            ...(Array.isArray(payload.annotations) ? payload.annotations : []),
            ...(Array.isArray(payload.session_annotations) ? payload.session_annotations : []),
        ].find((annotation) => annotation.id === rotated.id);
        if (!saved || rotationDelta(saved.rotation, rotated.rotation) > 0.75) {
            throw new Error(`Rotated shape missing from save payload: ${JSON.stringify({ rotated, saved }).slice(0, 2000)}`);
        }

        console.log('doc4194 pdfjs shape rotate handle regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});