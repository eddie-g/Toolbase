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

async function drawLine(page, pageBox, index) {
    const startX = 130;
    const startY = 160 + (index * 170);
    await page.mouse.move(pageBox.x + startX, pageBox.y + startY);
    await page.mouse.down();
    await page.mouse.move(pageBox.x + startX + 180, pageBox.y + startY + 42, { steps: 8 });
    await page.mouse.up();
    await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="line"]', { timeout: 10000 });
    const lineId = await page.evaluate(() => document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected[data-shape-type="line"]')?.dataset.annotationId || '');
    if (!lineId) throw new Error('Line shape did not draw/select.');
    return lineId;
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

        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        await page.click('[data-shape-tool="line"]');

        const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
        if (!pageBox) throw new Error('Could not locate page 1 for line drawing.');

        const targetLineId = await drawLine(page, pageBox, 0);
        const blockerLineIdA = await drawLine(page, pageBox, 1);
        const blockerLineIdB = await drawLine(page, pageBox, 2);

        const clickPoint = await page.evaluate(({ targetLineId, blockerLineIdA, blockerLineIdB }) => {
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            if (!pageEl) throw new Error('Missing page element.');

            const ids = [targetLineId, blockerLineIdA, blockerLineIdB];
            const boxes = ids.map((id) => pageEl.querySelector(`.enpv-shape-box[data-annotation-id="${CSS.escape(id)}"]`));
            if (boxes.some((box) => !box)) throw new Error('Missing one or more drawn line boxes.');

            const [targetBox, blockerBoxA, blockerBoxB] = boxes;
            const baseLeft = 300;
            const baseTop = 260;
            const width = 420;
            const height = 240;

            const configureLine = (box, zIndex, lineStartX, lineStartY, lineEndX, lineEndY, strokeColor) => {
                box.style.left = `${baseLeft}px`;
                box.style.top = `${baseTop}px`;
                box.style.width = `${width}px`;
                box.style.height = `${height}px`;
                box.style.zIndex = String(zIndex);
                box.dataset.zIndex = String(zIndex);
                box.dataset.strokeWidth = '8';
                box.dataset.strokeColor = strokeColor;
                box.dataset.strokeOpacity = '1';
                box.dataset.strokeTransparent = '0';
                box.dataset.lineStartX = String(lineStartX);
                box.dataset.lineStartY = String(lineStartY);
                box.dataset.lineEndX = String(lineEndX);
                box.dataset.lineEndY = String(lineEndY);

                const svg = box.querySelector('svg.enpv-shape-svg');
                const svgLine = svg?.querySelector('line');
                if (!svg || !svgLine) throw new Error('Missing line SVG.');
                svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
                svgLine.setAttribute('x1', String(lineStartX * width));
                svgLine.setAttribute('y1', String(lineStartY * height));
                svgLine.setAttribute('x2', String(lineEndX * width));
                svgLine.setAttribute('y2', String(lineEndY * height));
                svgLine.setAttribute('stroke', strokeColor);
                svgLine.setAttribute('stroke-width', '8');

                box.querySelectorAll('.enpv-resize-handle').forEach((handle) => {
                    handle.style.display = 'none';
                });
                box.classList.remove('is-selected');
            };

            configureLine(targetBox, 4999, 0.06, 0.74, 0.94, 0.74, '#dc2626');
            configureLine(blockerBoxA, 5000, 0.05, 0.08, 0.95, 0.18, '#2563eb');
            configureLine(blockerBoxB, 5001, 0.05, 0.24, 0.95, 0.34, '#16a34a');

            const targetRect = targetBox.getBoundingClientRect();
            const blockerTop = document.elementFromPoint(targetRect.left + (targetRect.width * 0.5), targetRect.top + (targetRect.height * 0.74))?.closest?.('.enpv-shape-box');
            return {
                x: targetRect.left + (targetRect.width * 0.5),
                y: targetRect.top + (targetRect.height * 0.74),
                initialTopId: blockerTop?.dataset.annotationId || '',
            };
        }, { targetLineId, blockerLineIdA, blockerLineIdB });

        if (clickPoint.initialTopId !== blockerLineIdB) {
            throw new Error(`Test setup did not put blocker line on top: ${JSON.stringify(clickPoint)}`);
        }

        await page.mouse.click(clickPoint.x, clickPoint.y);
        await page.waitForFunction((lineId) => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            return selected?.dataset.annotationId === lineId;
        }, targetLineId, { timeout: 5000 });

        const selectedAfterClick = await page.evaluate(() => {
            const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
            return selected?.dataset.annotationId || '';
        });
        if (selectedAfterClick !== targetLineId) {
            throw new Error(`Click-through selected the wrong line: ${selectedAfterClick}`);
        }

        const unlockedState = await page.evaluate(() => {
            const button = document.querySelector('#enpv-ann-menu [data-action="lock"]');
            return {
                title: button?.title || '',
                ariaPressed: button?.getAttribute('aria-pressed') || '',
                iconState: button?.dataset.lockIconState || '',
                active: Boolean(button?.classList.contains('is-active')),
                path: button?.querySelector('path')?.getAttribute('d') || '',
            };
        });
        if (unlockedState.title !== 'Lock annotation' || unlockedState.ariaPressed !== 'false' || unlockedState.iconState !== 'unlocked' || unlockedState.active || !unlockedState.path.includes('V7')) {
            throw new Error(`Unlocked lock button state is wrong: ${JSON.stringify(unlockedState)}`);
        }

        await page.click('#enpv-ann-menu [data-action="lock"]');
        await page.waitForFunction(() => document.querySelector('.enpv-shape-box.is-selected')?.dataset.locked === '1', { timeout: 5000 });
        const lockedState = await page.evaluate(() => {
            const selected = document.querySelector('.enpv-shape-box.is-selected');
            const button = document.querySelector('#enpv-ann-menu [data-action="lock"]');
            return {
                boxLocked: selected?.dataset.locked || '',
                boxClassLocked: Boolean(selected?.classList.contains('is-locked')),
                title: button?.title || '',
                ariaPressed: button?.getAttribute('aria-pressed') || '',
                iconState: button?.dataset.lockIconState || '',
                active: Boolean(button?.classList.contains('is-active')),
                path: button?.querySelector('path')?.getAttribute('d') || '',
            };
        });
        if (lockedState.boxLocked !== '1' || !lockedState.boxClassLocked || lockedState.title !== 'Unlock annotation' || lockedState.ariaPressed !== 'true' || lockedState.iconState !== 'locked' || !lockedState.active || !lockedState.path.includes('V8')) {
            throw new Error(`Locked lock button state is wrong: ${JSON.stringify(lockedState)}`);
        }

        await page.click('#enpv-ann-menu [data-action="lock"]');
        await page.waitForFunction(() => document.querySelector('.enpv-shape-box.is-selected')?.dataset.locked === '0', { timeout: 5000 });
        const relockedState = await page.evaluate(() => {
            const selected = document.querySelector('.enpv-shape-box.is-selected');
            const button = document.querySelector('#enpv-ann-menu [data-action="lock"]');
            return {
                boxLocked: selected?.dataset.locked || '',
                title: button?.title || '',
                ariaPressed: button?.getAttribute('aria-pressed') || '',
                iconState: button?.dataset.lockIconState || '',
                active: Boolean(button?.classList.contains('is-active')),
                path: button?.querySelector('path')?.getAttribute('d') || '',
            };
        });
        if (relockedState.boxLocked !== '0' || relockedState.title !== 'Lock annotation' || relockedState.ariaPressed !== 'false' || relockedState.iconState !== 'unlocked' || relockedState.active || !relockedState.path.includes('V7')) {
            throw new Error(`Re-unlocked lock button state is wrong: ${JSON.stringify(relockedState)}`);
        }

        console.log('doc4194 pdfjs line click-through + lock icon regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});