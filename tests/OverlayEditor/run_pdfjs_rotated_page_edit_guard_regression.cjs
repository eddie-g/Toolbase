#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5293);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'TestPwd123!';
const PAGE_NUMBER = 1;

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 10000 });
}

async function setPageRotation(page, rotation) {
    await page.evaluate(({ pageNumber, nextRotation }) => {
        const viewer = window.__enpv?.pdfViewer;
        if (!viewer) throw new Error('PDF.js viewer is unavailable.');
        const pageView = window.__enpv?.pdfViewer?.getPageView?.(pageNumber - 1);
        if (!pageView?.pdfPage) throw new Error('PDF.js page is unavailable.');
        const baseRotation = Number(pageView.pdfPage.rotate || 0);
        viewer.pagesRotation = ((nextRotation - baseRotation) % 360 + 360) % 360;
    }, { pageNumber: PAGE_NUMBER, nextRotation: rotation });
    await page.waitForFunction(({ pageNumber, nextRotation }) => {
        const pageView = window.__enpv?.pdfViewer?.getPageView?.(pageNumber - 1);
        return Number(pageView?.viewport?.rotation) === nextRotation
            && Boolean(pageView?.div?.querySelector?.('canvas'));
    }, { pageNumber: PAGE_NUMBER, nextRotation: rotation }, { timeout: 15000 });
    await page.waitForTimeout(250);
    return page.evaluate((pageNumber) => (
        Number(window.__enpv?.pdfViewer?.getPageView?.(pageNumber - 1)?.viewport?.rotation)
    ), PAGE_NUMBER);
}

async function drawAnnotation(page, buttonSelector, drag) {
    await page.locator(buttonSelector).click();
    const canvas = page.locator(`.pdfViewer .page[data-page-number="${PAGE_NUMBER}"] .canvasWrapper canvas`);
    const rect = await canvas.boundingBox();
    if (!rect) throw new Error('Page canvas is not measurable.');
    const start = {
        x: rect.x + (rect.width * drag.startX),
        y: rect.y + (rect.height * drag.startY),
    };
    const end = {
        x: rect.x + (rect.width * drag.endX),
        y: rect.y + (rect.height * drag.endY),
    };
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    await page.mouse.move(end.x, end.y, { steps: 8 });
    await page.mouse.up();
}

async function annotationGeometry(page, annotationId, kind) {
    return page.evaluate(({ pageNumber, id, annotationKind }) => {
        const pageView = window.__enpv?.pdfViewer?.getPageView?.(pageNumber - 1);
        const viewport = pageView?.viewport;
        const pageDiv = pageView?.div;
        const box = Array.from(pageDiv?.querySelectorAll?.('.enpv-annotation-box') || [])
            .find((candidate) => candidate.dataset.annotationId === id);
        if (!viewport || !box) return null;
        const pdfRect = annotationKind === 'shape'
            ? {
                x: Number(box.dataset.pdfX),
                y: Number(box.dataset.pdfY),
                w: Number(box.dataset.pdfWidth),
                h: Number(box.dataset.pdfHeight),
            }
            : {
                x: Number(box.dataset.sourceBboxX),
                y: Number(box.dataset.sourceBboxY),
                w: Number(box.dataset.sourceBboxW),
                h: Number(box.dataset.sourceBboxH),
            };
        if (!Object.values(pdfRect).every(Number.isFinite)) return null;
        const converted = viewport.convertToViewportRectangle([
            pdfRect.x,
            pdfRect.y,
            pdfRect.x + pdfRect.w,
            pdfRect.y + pdfRect.h,
        ]);
        const expected = {
            left: Math.min(converted[0], converted[2]),
            top: Math.min(converted[1], converted[3]),
            width: Math.abs(converted[2] - converted[0]),
            height: Math.abs(converted[3] - converted[1]),
        };
        const actual = {
            left: Number.parseFloat(box.style.left || ''),
            top: Number.parseFloat(box.style.top || ''),
            width: Number.parseFloat(box.style.width || ''),
            height: Number.parseFloat(box.style.height || ''),
        };
        const errors = Object.keys(expected).map((key) => Math.abs(expected[key] - actual[key]));
        const rotatedContent = box.querySelector('.enpv-page-rotated-content-frame, :scope > svg');
        return {
            rotation: Number(viewport.rotation),
            pageRotationDataset: Number(box.dataset.pageRotation),
            expected,
            actual,
            maxError: Math.max(...errors),
            contentTransform: rotatedContent?.style?.transform || '',
        };
    }, { pageNumber: PAGE_NUMBER, id: annotationId, annotationKind: kind });
}

async function editorState(page) {
    return page.evaluate((pageNumber) => {
        const dialog = document.getElementById('enpv-rotated-edit-dialog');
        const button = document.getElementById('ftb-edit-mode');
        const pageDiv = document.querySelector(`.pdfViewer .page[data-page-number="${pageNumber}"]`);
        const layer = pageDiv?.querySelector(':scope > .enpv-annotation-box-layer');
        const snapshot = pageDiv?.querySelector(':scope > .enpv-page-rotation-snapshot');
        const snapshotCanvas = snapshot?.querySelector(':scope > canvas');
        return {
            editModeOn: document.body.classList.contains('enpv-edit-on'),
            rotationBlocked: document.body.classList.contains('enpv-edit-rotation-blocked'),
            buttonDisabled: button?.getAttribute('aria-disabled') === 'true',
            buttonDisabledClass: button?.classList.contains('is-rotation-disabled') || false,
            dialogVisible: Boolean(dialog && !dialog.hidden && dialog.getAttribute('aria-hidden') === 'false'),
            dialogText: document.getElementById('enpv-rotated-edit-dialog-description')?.textContent || '',
            snapshotActive: pageDiv?.classList.contains('enpv-page-rotation-snapshot-active') || false,
            snapshotRotation: Number(snapshot?.dataset.rotation || 0),
            snapshotCanvasWidth: Number(snapshotCanvas?.width || 0),
            snapshotCanvasHeight: Number(snapshotCanvas?.height || 0),
            layerEditMode: layer?.dataset.editMode || '',
            sourceHandleCount: layer?.querySelectorAll('.is-source-handle').length || 0,
            interactiveBoxCount: layer
                ? Array.from(layer.querySelectorAll('.enpv-annotation-box')).filter((box) => getComputedStyle(box).pointerEvents !== 'none').length
                : 0,
        };
    }, PAGE_NUMBER);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            if (route.request().method() === 'GET') return route.continue();
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true }),
            });
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.evaluate((pageNumber) => {
            window.__enpv.pdfViewer.currentPageNumber = pageNumber;
            window.__enpv.pdfViewer.scrollPageIntoView({ pageNumber });
        }, PAGE_NUMBER);
        await page.locator(`.pdfViewer .page[data-page-number="${PAGE_NUMBER}"]`).scrollIntoViewIfNeeded();

        // Exercise real editor-created text and custom shape annotations on
        // ss-5.pdf, but keep the run local by stubbing mutation requests.
        await setPageRotation(page, 0);
        await page.evaluate(() => { window.__enpv.pdfViewer.currentScaleValue = 'page-fit'; });
        await page.waitForTimeout(500);

        await drawAnnotation(page, '#ftb-add-text', {
            startX: 0.12, startY: 0.13, endX: 0.34, endY: 0.20,
        });
        const textBox = page.locator(`.pdfViewer .page[data-page-number="${PAGE_NUMBER}"] .enpv-annotation-box.is-selected`);
        await textBox.waitFor({ timeout: 5000 });
        const textAnnotationId = await textBox.getAttribute('data-annotation-id');
        await textBox.locator('.enpv-text-content').fill('Rotation anchor');
        await page.keyboard.press('Escape');

        await page.locator('#ftb-add-shape').click();
        await page.locator('[data-shape-tool="square"]').click();
        const shapeCountBefore = await page.locator(`.pdfViewer .page[data-page-number="${PAGE_NUMBER}"] .enpv-shape-box`).count();
        const canvas = page.locator(`.pdfViewer .page[data-page-number="${PAGE_NUMBER}"] .canvasWrapper canvas`);
        const canvasRect = await canvas.boundingBox();
        if (!canvasRect) throw new Error('Page canvas is not measurable for shape creation.');
        await page.mouse.move(canvasRect.x + (canvasRect.width * 0.57), canvasRect.y + (canvasRect.height * 0.66));
        await page.mouse.down();
        await page.mouse.move(canvasRect.x + (canvasRect.width * 0.74), canvasRect.y + (canvasRect.height * 0.75), { steps: 8 });
        await page.mouse.up();
        await page.waitForFunction(({ pageNumber, count }) => (
            document.querySelectorAll(`.pdfViewer .page[data-page-number="${pageNumber}"] .enpv-shape-box`).length > count
        ), { pageNumber: PAGE_NUMBER, count: shapeCountBefore });
        const shapeAnnotationId = await page.locator(
            `.pdfViewer .page[data-page-number="${PAGE_NUMBER}"] .enpv-shape-box.is-selected`,
        ).getAttribute('data-annotation-id');
        await page.locator('#ftb-add-shape').click();

        if (!textAnnotationId || !shapeAnnotationId) {
            throw new Error('Failed to create rotation regression annotations.');
        }

        await page.evaluate(async (pageNumber) => {
            await window.__enpv.captureFlatPageRotationSnapshot(pageNumber - 1);
        }, PAGE_NUMBER);

        const applied90 = await setPageRotation(page, 90);
        await page.waitForFunction(({ pageNumber, textId, shapeId }) => {
            const boxes = Array.from(document.querySelectorAll(
                `.pdfViewer .page[data-page-number="${pageNumber}"] .enpv-annotation-box`,
            ));
            return [textId, shapeId].every((id) => boxes.some((box) => (
                box.dataset.annotationId === id && box.dataset.pageRotation === '90'
            )));
        }, { pageNumber: PAGE_NUMBER, textId: textAnnotationId, shapeId: shapeAnnotationId });
        const textAt90 = await annotationGeometry(page, textAnnotationId, 'text');
        const shapeAt90 = await annotationGeometry(page, shapeAnnotationId, 'shape');
        await page.locator('#ftb-edit-mode').click({ force: true });
        await page.waitForSelector('#enpv-rotated-edit-dialog:not([hidden])', { timeout: 5000 });
        await page.waitForTimeout(100);
        const blocked90 = await editorState(page);
        await page.locator('#enpv-rotated-edit-dialog-close').click();

        const applied180 = await setPageRotation(page, 180);
        await page.locator('#ftb-edit-mode').click({ force: true });
        await page.waitForSelector('#enpv-rotated-edit-dialog:not([hidden])', { timeout: 5000 });
        await page.waitForTimeout(100);
        const blocked180 = await editorState(page);
        await page.locator('#enpv-rotated-edit-dialog-close').click();

        const applied270 = await setPageRotation(page, 270);
        await page.locator('#ftb-edit-mode').click({ force: true });
        await page.waitForSelector('#enpv-rotated-edit-dialog:not([hidden])', { timeout: 5000 });
        const blocked270 = await editorState(page);

        const checks = {
            rotation_90_is_blocked:
                applied90 === 90
                && !blocked90.editModeOn
                && blocked90.rotationBlocked
                && blocked90.buttonDisabled
                && blocked90.buttonDisabledClass
                && blocked90.dialogVisible
                && blocked90.dialogText.includes('rotated 90°')
                && blocked90.layerEditMode === '0'
                && blocked90.sourceHandleCount === 0
                && blocked90.snapshotActive
                && blocked90.snapshotRotation === 90
                && blocked90.snapshotCanvasWidth > 0
                && blocked90.snapshotCanvasHeight > 0,
            text_annotation_stays_anchored_at_90:
                textAt90?.rotation === 90
                && textAt90?.pageRotationDataset === 90
                && textAt90?.maxError <= 0.75
                && textAt90?.contentTransform.includes('rotate(90deg)'),
            custom_shape_stays_anchored_at_90:
                shapeAt90?.rotation === 90
                && shapeAt90?.pageRotationDataset === 90
                && shapeAt90?.maxError <= 0.75
                && shapeAt90?.contentTransform.includes('rotate(90deg)'),
            rotation_180_is_blocked:
                applied180 === 180
                && !blocked180.editModeOn
                && blocked180.rotationBlocked
                && blocked180.buttonDisabled
                && blocked180.dialogVisible
                && blocked180.dialogText.includes('rotated 180°')
                && blocked180.snapshotActive
                && blocked180.snapshotRotation === 180,
            rotation_270_is_blocked:
                applied270 === 270
                && !blocked270.editModeOn
                && blocked270.rotationBlocked
                && blocked270.dialogVisible
                && blocked270.dialogText.includes('rotated 270°')
                && blocked270.snapshotActive
                && blocked270.snapshotRotation === 270,
        };
        const failed = Object.entries(checks).filter(([, pass]) => !pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            pageNumber: PAGE_NUMBER,
            states: { blocked90, blocked180, blocked270 },
            geometry: { textAt90, shapeAt90 },
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
