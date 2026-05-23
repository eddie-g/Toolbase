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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4035);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

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
    const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);

        const beforeCount = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box').count();
        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1 for shape drawing.');
        await page.mouse.move(pageBox.x + 180, pageBox.y + 180);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 360, pageBox.y + 300, { steps: 8 });
        await page.mouse.up();

        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected svg.enpv-shape-svg', { timeout: 10000 });
        const afterDraw = await page.evaluate(() => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const selected = pageEl?.querySelector('.enpv-shape-box.is-selected');
            return {
                shapeCount: pageEl?.querySelectorAll('.enpv-shape-box').length || 0,
                selected: Boolean(selected),
                hasSvg: Boolean(selected?.querySelector('svg.enpv-shape-svg')),
                id: selected?.dataset.annotationId || '',
                type: selected?.dataset.shapeType || '',
            };
        });
        if (afterDraw.shapeCount <= beforeCount || !afterDraw.selected || !afterDraw.hasSvg) {
            throw new Error(`Shape was not drawn and selected correctly: ${JSON.stringify({ beforeCount, afterDraw })}`);
        }

        await page.click('#shape-tool-panel .sfb-label');
        const selectedAfterToolbarClick = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected').count();
        if (selectedAfterToolbarClick !== 1) {
            throw new Error('Clicking the shape toolbar cleared the selected shape.');
        }

        await page.evaluate(() => {
            const setInput = (selector, value) => {
                const input = document.querySelector(selector);
                if (!input) throw new Error(`Missing shape inspector input: ${selector}`);
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            };
            setInput('#shape-stroke-color', '#ff0000');
            setInput('#shape-stroke-width', '9');
            setInput('#shape-stroke-opacity', '50');
            setInput('#shape-fill-color', '#00ff00');
            setInput('#shape-fill-opacity', '40');
        });

        const styledShape = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            const drawn = selected?.querySelector('svg.enpv-shape-svg > *');
            return {
                strokeColor: selected?.dataset.strokeColor || '',
                strokeWidth: selected?.dataset.strokeWidth || '',
                strokeOpacity: selected?.dataset.strokeOpacity || '',
                fillColor: selected?.dataset.fillColor || '',
                fillOpacity: selected?.dataset.fillOpacity || '',
                svgStroke: drawn?.getAttribute('stroke') || '',
                svgFill: drawn?.getAttribute('fill') || '',
                svgStrokeOpacity: drawn?.getAttribute('stroke-opacity') || '',
                svgFillOpacity: drawn?.getAttribute('fill-opacity') || '',
            };
        });
        if (
            styledShape.strokeColor.toLowerCase() !== '#ff0000'
            || styledShape.strokeWidth !== '9'
            || Number(styledShape.strokeOpacity) !== 0.5
            || styledShape.fillColor.toLowerCase() !== '#00ff00'
            || Number(styledShape.fillOpacity) !== 0.4
            || styledShape.svgStroke.toLowerCase() !== '#ff0000'
            || styledShape.svgFill.toLowerCase() !== '#00ff00'
        ) {
            throw new Error(`Shape style controls did not update the selected shape: ${JSON.stringify(styledShape)}`);
        }

        const visibleShapeHandles = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            return Array.from(selected?.querySelectorAll('.enpv-resize-handle') || [])
                .filter((handle) => window.getComputedStyle(handle).display !== 'none')
                .map((handle) => handle.dataset.edge || '')
                .sort();
        });
        for (const edge of ['nw', 'ne', 'sw', 'se', 't', 'b', 'l', 'r']) {
            if (!visibleShapeHandles.includes(edge)) {
                throw new Error(`Selected shape resize handle is not visible: ${JSON.stringify({ edge, visibleShapeHandles })}`);
            }
        }

        const widthBeforeResize = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            return selected?.getBoundingClientRect().width || 0;
        });
        const rightHandle = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected .enpv-resize-handle.se').boundingBox();
        if (!rightHandle) throw new Error('Could not locate selected shape southeast resize handle.');
        await page.mouse.move(rightHandle.x + rightHandle.width / 2, rightHandle.y + rightHandle.height / 2);
        await page.mouse.down();
        await page.mouse.move(rightHandle.x + rightHandle.width / 2 + 70, rightHandle.y + rightHandle.height / 2, { steps: 8 });
        await page.mouse.up();
        const widthAfterResize = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            return selected?.getBoundingClientRect().width || 0;
        });
        if (widthAfterResize <= widthBeforeResize + 30) {
            throw new Error(`Shape resize did not increase width enough: before=${widthBeforeResize} after=${widthAfterResize}`);
        }

        const beforeVerticalResize = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            const rect = selected?.getBoundingClientRect();
            return { width: rect?.width || 0, height: rect?.height || 0 };
        });
        const bottomHandle = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected .enpv-resize-handle.b').boundingBox();
        if (!bottomHandle) throw new Error('Could not locate selected shape bottom resize handle.');
        await page.mouse.move(bottomHandle.x + bottomHandle.width / 2, bottomHandle.y + bottomHandle.height / 2);
        await page.mouse.down();
        await page.mouse.move(bottomHandle.x + bottomHandle.width / 2, bottomHandle.y + bottomHandle.height / 2 + 70, { steps: 8 });
        await page.mouse.up();
        const afterVerticalResize = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            const rect = selected?.getBoundingClientRect();
            return { width: rect?.width || 0, height: rect?.height || 0 };
        });
        if (afterVerticalResize.height <= beforeVerticalResize.height + 30 || Math.abs(afterVerticalResize.width - beforeVerticalResize.width) > 4) {
            throw new Error(`Shape bottom handle did not resize vertically only: ${JSON.stringify({ beforeVerticalResize, afterVerticalResize })}`);
        }

        const beforeCornerShrink = afterVerticalResize;
        const shrinkHandle = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected .enpv-resize-handle.se').boundingBox();
        if (!shrinkHandle) throw new Error('Could not locate selected shape southeast resize handle for shrink.');
        await page.mouse.move(shrinkHandle.x + shrinkHandle.width / 2, shrinkHandle.y + shrinkHandle.height / 2);
        await page.mouse.down();
        await page.mouse.move(shrinkHandle.x + shrinkHandle.width / 2 - 55, shrinkHandle.y + shrinkHandle.height / 2 - 55, { steps: 8 });
        await page.mouse.up();
        const afterCornerShrink = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            const rect = selected?.getBoundingClientRect();
            return { width: rect?.width || 0, height: rect?.height || 0 };
        });
        if (afterCornerShrink.width >= beforeCornerShrink.width - 20 || afterCornerShrink.height >= beforeCornerShrink.height - 20) {
            throw new Error(`Shape corner handle did not scale down: ${JSON.stringify({ beforeCornerShrink, afterCornerShrink })}`);
        }

        await page.click('#save-btn');
        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 30000 });
        const saveStatus = await page.textContent('#save-status');
        if (!/Saved/.test(saveStatus || '')) {
            throw new Error(`Shape save did not report Saved: ${saveStatus}`);
        }

        const downloadPredicate = (requestOrResponse) => {
            const request = typeof requestOrResponse.request === 'function'
                ? requestOrResponse.request()
                : requestOrResponse;
            return request.method() === 'POST'
                && request.url().includes(`/documents/${DOCUMENT_ID}/download-annotated-pdf`);
        };
        const [downloadRequest] = await Promise.all([
            page.waitForRequest(downloadPredicate, { timeout: 30000 }),
            page.waitForResponse(downloadPredicate, { timeout: 60000 }),
            page.click('#download-pdf-btn'),
        ]);
        const downloadPayload = downloadRequest.postDataJSON();
        const downloadedShape = Array.isArray(downloadPayload?.annotations)
            ? downloadPayload.annotations.find((annotation) => annotation?.type === 'shape' && annotation?.id === afterDraw.id)
            : null;
        if (
            !downloadedShape
            || String(downloadedShape.strokeColor || '').toLowerCase() !== '#ff0000'
            || Number(downloadedShape.strokeWidth) !== 9
            || Number(downloadedShape.strokeOpacity) !== 0.5
            || String(downloadedShape.fillColor || '').toLowerCase() !== '#00ff00'
            || Number(downloadedShape.fillOpacity) !== 0.4
        ) {
            throw new Error(`Download payload did not include the styled shape: ${JSON.stringify(downloadedShape || downloadPayload)}`);
        }
        await page.waitForFunction(
            () => /PDF ready|PDF generation failed/.test(document.querySelector('#enpv-status')?.textContent || ''),
            { timeout: 60000 },
        );
        const downloadStatus = await page.textContent('#enpv-status');
        if (/failed/i.test(downloadStatus || '')) {
            throw new Error(`Shape download failed: ${downloadStatus}`);
        }

        await page.click(`.pdfViewer .page[data-page-number="1"] .enpv-shape-box[data-annotation-id="${afterDraw.id}"]`);
        await page.keyboard.press('Delete');
        await page.waitForFunction(
            (shapeId) => !Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-shape-box'))
                .some((box) => box.dataset.annotationId === shapeId),
            afterDraw.id,
            { timeout: 5000 },
        );
        await page.click('#save-btn');
        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 30000 });

        if (!await page.evaluate(() => document.body.classList.contains('enpv-shape-on'))) {
            await page.click('#add-shape-btn');
            await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        }
        await page.click('[data-shape-tool="line"]');
        await page.mouse.move(pageBox.x + 420, pageBox.y + 520);
        await page.mouse.down();
        await page.mouse.move(pageBox.x + 920, pageBox.y + 580, { steps: 8 });
        await page.mouse.up();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="line"]', { timeout: 10000 });

        const lineAfterDraw = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="line"]');
            const svgLine = selected?.querySelector('svg.enpv-shape-svg line') || null;
            const visibleHandles = Array.from(selected?.querySelectorAll('.enpv-resize-handle') || [])
                .filter((handle) => window.getComputedStyle(handle).display !== 'none')
                .map((handle) => handle.dataset.edge || '');
            return {
                id: selected?.dataset.annotationId || '',
                width: selected?.getBoundingClientRect().width || 0,
                height: selected?.getBoundingClientRect().height || 0,
                lineStartX: selected?.dataset.lineStartX || '',
                lineStartY: selected?.dataset.lineStartY || '',
                lineEndX: selected?.dataset.lineEndX || '',
                lineEndY: selected?.dataset.lineEndY || '',
                svgY1: Number(svgLine?.getAttribute('y1') || 0),
                svgY2: Number(svgLine?.getAttribute('y2') || 0),
                visibleHandles,
            };
        });
        if (
            !lineAfterDraw.id
            || lineAfterDraw.width < 40
            || lineAfterDraw.height < 10
            || Math.abs(lineAfterDraw.svgY2 - lineAfterDraw.svgY1) < 8
            || lineAfterDraw.visibleHandles.length !== 2
            || !lineAfterDraw.visibleHandles.includes('line-start')
            || !lineAfterDraw.visibleHandles.includes('line-end')
        ) {
            throw new Error(`Line shape did not drag-create with endpoint handles: ${JSON.stringify(lineAfterDraw)}`);
        }

        const lineEndHandle = await page.locator('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="line"] .enpv-resize-handle[data-edge="line-end"]').boundingBox();
        if (!lineEndHandle) throw new Error('Could not locate line endpoint handle.');
        await page.mouse.move(lineEndHandle.x + lineEndHandle.width / 2, lineEndHandle.y + lineEndHandle.height / 2);
        await page.mouse.down();
        await page.mouse.move(lineEndHandle.x + lineEndHandle.width / 2 + 80, lineEndHandle.y + lineEndHandle.height / 2 - 8, { steps: 8 });
        await page.mouse.up();

        const lineAfterEndpointMove = await page.evaluate((lineId) => {
            const selected = document.querySelector(`.pdfViewer .page[data-page-number="1"] .enpv-shape-box[data-annotation-id="${CSS.escape(lineId)}"]`);
            const svgLine = selected?.querySelector('svg.enpv-shape-svg line') || null;
            return {
                selected: Boolean(selected?.classList.contains('is-selected')),
                width: selected?.getBoundingClientRect().width || 0,
                height: selected?.getBoundingClientRect().height || 0,
                lineStartX: selected?.dataset.lineStartX || '',
                lineStartY: selected?.dataset.lineStartY || '',
                lineEndX: selected?.dataset.lineEndX || '',
                lineEndY: selected?.dataset.lineEndY || '',
                svgY1: Number(svgLine?.getAttribute('y1') || 0),
                svgY2: Number(svgLine?.getAttribute('y2') || 0),
            };
        }, lineAfterDraw.id);
        if (
            !lineAfterEndpointMove.selected
            || Math.abs(lineAfterEndpointMove.svgY2 - lineAfterEndpointMove.svgY1) < 4
            || (
                lineAfterEndpointMove.width === lineAfterDraw.width
                && lineAfterEndpointMove.height === lineAfterDraw.height
                && lineAfterEndpointMove.lineEndX === lineAfterDraw.lineEndX
                && lineAfterEndpointMove.lineEndY === lineAfterDraw.lineEndY
            )
        ) {
            throw new Error(`Line endpoint drag did not update geometry: ${JSON.stringify({ lineAfterDraw, lineAfterEndpointMove })}`);
        }

        await page.keyboard.press('Delete');
        await page.waitForFunction(
            (lineId) => !Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-shape-box'))
                .some((box) => box.dataset.annotationId === lineId),
            lineAfterDraw.id,
            { timeout: 5000 },
        );
        await page.click('#save-btn');
        await page.waitForFunction(() => /Saved|No changes/.test(document.querySelector('#save-status')?.textContent || ''), { timeout: 30000 });

        console.log('doc4035 pdfjs shape save/download regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
