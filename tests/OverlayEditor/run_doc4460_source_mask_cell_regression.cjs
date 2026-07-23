#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4460);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const INFO_PATH = `/pdf-tests/document/${DOCUMENT_ID}/info`;

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
    throw new Error('Unable to log in for doc4460 source-mask-cell regression.');
}

function changedPixels(beforeBuffer, afterBuffer, pageRect, crop) {
    const before = PNG.sync.read(beforeBuffer);
    const after = PNG.sync.read(afterBuffer);
    if (before.width !== after.width || before.height !== after.height) {
        throw new Error(`Screenshot dimensions changed: ${before.width}x${before.height} -> ${after.width}x${after.height}`);
    }
    const x0 = Math.max(0, Math.floor(pageRect.left - crop.relativeLeft));
    const y0 = Math.max(0, Math.floor(pageRect.top - crop.relativeTop));
    const x1 = Math.min(before.width, Math.ceil(pageRect.right - crop.relativeLeft));
    const y1 = Math.min(before.height, Math.ceil(pageRect.bottom - crop.relativeTop));
    let changed = 0;
    for (let y = y0; y < y1; y += 1) {
        for (let x = x0; x < x1; x += 1) {
            const index = ((y * before.width) + x) * 4;
            const delta = Math.max(
                Math.abs(before.data[index] - after.data[index]),
                Math.abs(before.data[index + 1] - after.data[index + 1]),
                Math.abs(before.data[index + 2] - after.data[index + 2]),
            );
            if (delta > 4) changed += 1;
        }
    }
    return changed;
}

function assertNear(actual, expected, tolerance, message) {
    if (!Number.isFinite(actual) || Math.abs(actual - expected) > tolerance) {
        throw new Error(`${message}: expected ${expected} +/- ${tolerance}, received ${actual}`);
    }
}

function assertMaskOwnsCell(state, label) {
    const expectedTop = (state.target.centerY + state.above.centerY) / 2;
    const expectedBottom = (state.target.centerY + state.below.centerY) / 2;
    assertNear(state.mask.top, expectedTop, 0.75, `${label} mask top`);
    assertNear(state.mask.bottom, expectedBottom, 0.75, `${label} mask bottom`);
    if (state.mask.bottom > state.below.centerY || state.mask.top < state.above.centerY) {
        throw new Error(`${label} mask crosses a neighbouring row centre: ${JSON.stringify(state)}`);
    }
}

async function pageMaskState(page, targetIndex, aboveIndex, belowIndex, maskSelector) {
    return page.evaluate(({ targetIndex, aboveIndex, belowIndex, maskSelector }) => {
        const pageEl = document.querySelector('.page[data-page-number="5"]');
        const pageBounds = pageEl?.getBoundingClientRect();
        if (!pageEl || !pageBounds) return null;
        const relativeRect = (rect) => ({
            left: rect.left - pageBounds.left,
            top: rect.top - pageBounds.top,
            right: rect.right - pageBounds.left,
            bottom: rect.bottom - pageBounds.top,
            width: rect.width,
            height: rect.height,
            centerX: ((rect.left + rect.right) / 2) - pageBounds.left,
            centerY: ((rect.top + rect.bottom) / 2) - pageBounds.top,
        });
        const groupRect = (index) => {
            const spans = Array.from(pageEl.querySelectorAll('.textLayer span')).filter((span) => (
                String(span.dataset.enpvGroupIndex || span.dataset.enpvIndex || '') === String(index)
            ));
            if (!spans.length) return null;
            const rects = spans.map((span) => span.getBoundingClientRect());
            const left = Math.min(...rects.map((rect) => rect.left));
            const top = Math.min(...rects.map((rect) => rect.top));
            const right = Math.max(...rects.map((rect) => rect.right));
            const bottom = Math.max(...rects.map((rect) => rect.bottom));
            return relativeRect({ left, top, right, bottom, width: right - left, height: bottom - top });
        };
        const target = groupRect(targetIndex);
        const above = groupRect(aboveIndex);
        const below = groupRect(belowIndex);
        const masks = Array.from(pageEl.querySelectorAll(maskSelector));
        const mask = masks
            .map((element) => ({ element, rect: relativeRect(element.getBoundingClientRect()) }))
            .find(({ rect }) => (
                target
                && Math.min(rect.right, target.right) - Math.max(rect.left, target.left) > 1
            ));
        if (!target || !above || !below || !mask) return null;
        return {
            target,
            above,
            below,
            mask: mask.rect,
            children: Array.from(mask.element.children).map((child) => relativeRect(child.getBoundingClientRect())),
        };
    }, { targetIndex, aboveIndex, belowIndex, maskSelector });
}

function assertMovedMaskHasNoCarvedHole(state) {
    if (!state.children.length) return;
    const sampleStep = 0.5;
    for (let y = state.mask.top + 0.25; y < state.mask.bottom; y += sampleStep) {
        const intervals = state.children
            .filter((rect) => rect.top <= y && rect.bottom >= y)
            .map((rect) => [Math.max(state.mask.left, rect.left), Math.min(state.mask.right, rect.right)])
            .filter(([left, right]) => right > left)
            .sort((left, right) => left[0] - right[0]);
        let covered = 0;
        let cursor = state.mask.left;
        for (const [left, right] of intervals) {
            if (right <= cursor) continue;
            covered += right - Math.max(cursor, left);
            cursor = Math.max(cursor, right);
        }
        if (covered < state.mask.width - 1) {
            throw new Error(`Moved mask has a carved hole at y=${y}: ${JSON.stringify(state)}`);
        }
    }
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();

    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') return route.abort();
            if (url.pathname !== INFO_PATH) return route.continue();
            const response = await route.fetch();
            const payload = await response.json();
            payload.annotations = Array.from(payload.annotations || []).filter((annotation) => (
                String(annotation?.pdfjsAnchorUid || '') !== '4:43'
                && String(annotation?.id || '') !== 'pdfjs_deleted_pdfjs_4460_4_4:43'
            ));
            return route.fulfill({
                response,
                contentType: 'application/json',
                body: JSON.stringify(payload),
            });
        });

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        const pageFive = page.locator('.page[data-page-number="5"]');
        await pageFive.scrollIntoViewIfNeeded();
        await page.waitForTimeout(2500);

        const crop = await pageFive.evaluate((pageEl) => {
            const pageBounds = pageEl.getBoundingClientRect();
            const groupSpans = Array.from(pageEl.querySelectorAll('.textLayer span')).filter((span) => (
                ['38', '39', '42', '43', '46', '47'].includes(
                    String(span.dataset.enpvGroupIndex || span.dataset.enpvIndex || ''),
                )
            ));
            if (!groupSpans.length) return null;
            const rects = groupSpans.map((span) => span.getBoundingClientRect());
            const relativeLeft = Math.min(...rects.map((rect) => rect.left)) - pageBounds.left - 20;
            const relativeTop = Math.min(...rects.map((rect) => rect.top)) - pageBounds.top - 8;
            const relativeRight = Math.max(...rects.map((rect) => rect.right)) - pageBounds.left + 20;
            const relativeBottom = Math.max(...rects.map((rect) => rect.bottom)) - pageBounds.top + 8;
            return {
                x: Math.round(pageBounds.left + relativeLeft),
                y: Math.round(pageBounds.top + relativeTop),
                width: Math.ceil(relativeRight - relativeLeft),
                height: Math.ceil(relativeBottom - relativeTop),
                relativeLeft,
                relativeTop,
            };
        });
        if (!crop || crop.x < 0 || crop.y < 0 || crop.x + crop.width > 1920 || crop.y + crop.height > 1080) {
            throw new Error(`Invalid source screenshot crop: ${JSON.stringify(crop)}`);
        }
        const screenshotClip = {
            x: crop.x,
            y: crop.y,
            width: crop.width,
            height: crop.height,
        };
        const initialScreenshot = await page.screenshot({ clip: screenshotClip });

        await page.locator('#ftb-edit-mode').click();
        const rightTarget = page.locator('.enpv-annotation-box[data-uid="4:43"]');
        await rightTarget.waitFor({ state: 'attached', timeout: 60000 });
        await rightTarget.click({ force: true });
        await page.keyboard.press('Delete');
        await page.waitForTimeout(750);
        const deletedState = await pageMaskState(page, 43, 39, 47, '.enpv-delete-erase-mask');
        if (!deletedState) throw new Error('Missing source group or deletion mask for 4:43.');
        assertMaskOwnsCell(deletedState, 'Deleted 4:43');

        await page.locator('#ftb-edit-mode').click();
        // Source masks have scheduled 250ms/1000ms geometry refreshes. Compare
        // pixels only after the final refresh and the edit-off layer rebuild.
        await page.waitForTimeout(1500);
        const afterDeleteScreenshot = await page.screenshot({ clip: screenshotClip });
        const rightTargetChanged = changedPixels(initialScreenshot, afterDeleteScreenshot, deletedState.mask, crop);
        const rightAboveChanged = changedPixels(initialScreenshot, afterDeleteScreenshot, {
            ...deletedState.above,
            bottom: Math.min(deletedState.above.bottom, deletedState.mask.top - 0.5),
        }, crop);
        const rightBelowChanged = changedPixels(initialScreenshot, afterDeleteScreenshot, {
            ...deletedState.below,
            top: Math.max(deletedState.below.top, deletedState.mask.bottom + 0.75),
        }, crop);
        if (rightTargetChanged < 100 || rightAboveChanged !== 0 || rightBelowChanged !== 0) {
            throw new Error(`4:43 deletion pixel isolation failed: ${JSON.stringify({
                rightTargetChanged,
                rightAboveChanged,
                rightBelowChanged,
            })}`);
        }

        await page.locator('#ftb-edit-mode').click();
        const leftTarget = page.locator('.enpv-annotation-box[data-uid="4:42"]');
        await leftTarget.waitFor({ state: 'attached', timeout: 60000 });
        const leftBounds = await leftTarget.boundingBox();
        if (!leftBounds) throw new Error('Missing visible source box for 4:42.');
        await page.mouse.move(leftBounds.x + (leftBounds.width / 2), leftBounds.y + (leftBounds.height / 2));
        await page.mouse.down();
        await page.mouse.move(
            leftBounds.x + (leftBounds.width / 2) + 100,
            leftBounds.y + (leftBounds.height / 2) + 70,
            { steps: 12 },
        );
        await page.mouse.up();
        await page.waitForTimeout(1000);
        const movedState = await pageMaskState(page, 42, 38, 46, '.enpv-source-mask');
        if (!movedState) throw new Error('Missing source group or moved-source mask for 4:42.');
        assertMaskOwnsCell(movedState, 'Moved 4:42');
        assertMovedMaskHasNoCarvedHole(movedState);

        await page.locator('#ftb-edit-mode').click();
        await page.waitForTimeout(1500);
        const afterMoveScreenshot = await page.screenshot({ clip: screenshotClip });
        const leftTargetChanged = changedPixels(afterDeleteScreenshot, afterMoveScreenshot, movedState.mask, crop);
        const leftAboveChanged = changedPixels(afterDeleteScreenshot, afterMoveScreenshot, {
            ...movedState.above,
            bottom: Math.min(movedState.above.bottom, movedState.mask.top - 0.5),
        }, crop);
        const leftBelowChanged = changedPixels(afterDeleteScreenshot, afterMoveScreenshot, {
            ...movedState.below,
            top: Math.max(movedState.below.top, movedState.mask.bottom + 0.75),
        }, crop);
        if (leftTargetChanged < 100 || leftAboveChanged !== 0 || leftBelowChanged !== 0) {
            throw new Error(`4:42 move pixel isolation failed: ${JSON.stringify({
                leftTargetChanged,
                leftAboveChanged,
                leftBelowChanged,
            })}`);
        }

        console.log(JSON.stringify({
            status: 'pass',
            deleted43: {
                mask: deletedState.mask,
                changedPixels: rightTargetChanged,
                aboveChangedPixels: rightAboveChanged,
                page3ChangedPixels: rightBelowChanged,
            },
            moved42: {
                mask: movedState.mask,
                maskRuns: movedState.children,
                changedPixels: leftTargetChanged,
                aboveChangedPixels: leftAboveChanged,
                page3ChangedPixels: leftBelowChanged,
            },
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
