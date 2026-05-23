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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4184);
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
    const page = await browser.newPage({ viewport: { width: 1800, height: 1300 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(1000);

        const before = await page.evaluate(() => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            return Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box'))
                .map((box) => ({
                    id: box.dataset.annotationId || '',
                    text: normalize(box.querySelector('.enpv-text-content')?.textContent || box.textContent || ''),
                }))
                .filter((box) => /NY · SF|Cinematographers|LA · LV|Screening Room/.test(box.text));
        });

        if (!before.some((box) => box.text === 'NY · SF')) {
            throw new Error(`Missing standalone NY/SF source box: ${JSON.stringify(before)}`);
        }
        if (!before.some((box) => box.text.startsWith('Cinematographers Guild · NBC'))) {
            throw new Error(`Missing standalone Cinematographers source box: ${JSON.stringify(before)}`);
        }
        if (before.some((box) => box.text.includes('NY · SF') && box.text.includes('Cinematographers Guild'))) {
            throw new Error(`NY/SF and Cinematographers source boxes collapsed together: ${JSON.stringify(before)}`);
        }
        if (!before.some((box) => box.text === 'LA · LV')) {
            throw new Error(`Missing standalone LA/LV source box: ${JSON.stringify(before)}`);
        }
        if (!before.some((box) => box.text.startsWith('Screening Room · Signiant'))) {
            throw new Error(`Missing standalone Screening Room source box: ${JSON.stringify(before)}`);
        }
        if (before.some((box) => box.text.includes('LA · LV') && box.text.includes('Screening Room'))) {
            throw new Error(`LA/LV and Screening Room source boxes collapsed together: ${JSON.stringify(before)}`);
        }

        const subheaderBox = page.locator('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box')
            .filter({ hasText: 'for investors & friends' })
            .first();
        await subheaderBox.scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const subheaderRect = await subheaderBox.boundingBox();
        if (!subheaderRect) throw new Error('Unable to locate subheader box for drag.');

        await page.mouse.move(subheaderRect.x + (subheaderRect.width / 2), subheaderRect.y + (subheaderRect.height / 2));
        await page.mouse.down();
        await page.mouse.move(subheaderRect.x + (subheaderRect.width / 2) + 90, subheaderRect.y + (subheaderRect.height / 2) + 30, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(700);

        const subheaderAfter = await page.evaluate(() => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const pageRect = pageEl?.getBoundingClientRect();
            const spans = Array.from(pageEl?.querySelectorAll('.textLayer span') || [])
                .filter((span) => normalize(span.textContent).includes('for investors & friends'))
                .map((span) => ({
                    hidden: span.dataset.enpvMovedSourceHidden || '',
                    visibility: window.getComputedStyle(span).visibility,
                    opacity: window.getComputedStyle(span).opacity,
                }));
            const masks = Array.from(pageEl?.querySelectorAll('.enpv-source-mask') || [])
                .map((mask) => {
                    const rect = mask.getBoundingClientRect();
                    return {
                        left: pageRect ? rect.left - pageRect.left : rect.left,
                        top: pageRect ? rect.top - pageRect.top : rect.top,
                        width: rect.width,
                        height: rect.height,
                        children: Array.from(mask.children).map((child) => {
                            const childRect = child.getBoundingClientRect();
                            return {
                                left: childRect.left - rect.left,
                                top: childRect.top - rect.top,
                                width: childRect.width,
                                height: childRect.height,
                            };
                        }),
                    };
                })
                .filter((mask) => Math.abs(mask.left - 1118) < 8 && Math.abs(mask.top - 478) < 12);
            return { spans, masks };
        });

        if (!subheaderAfter.spans.length || subheaderAfter.spans.some((span) => (
            span.hidden !== '1' || span.visibility !== 'hidden' || span.opacity !== '0'
        ))) {
            throw new Error(`Original subheader source spans were not hidden after move: ${JSON.stringify(subheaderAfter)}`);
        }
        if (!subheaderAfter.masks.some((mask) => mask.children.some((child) => (
            child.left <= 1 && child.top <= 1 && child.width > 650 && child.height > 55
        )))) {
            throw new Error(`Subheader source mask was cut by the overlapping title bbox: ${JSON.stringify(subheaderAfter)}`);
        }

        await page.locator('button').filter({ hasText: /^←$/ }).first().click();
        await page.waitForTimeout(1000);

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(1000);

        const nyBox = page.locator('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box')
            .filter({ hasText: 'NY · SF' })
            .first();
        await nyBox.scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const rect = await nyBox.boundingBox();
        if (!rect) throw new Error('Unable to locate NY/SF box for drag.');

        await page.mouse.move(rect.x + (rect.width / 2), rect.y + (rect.height / 2));
        await page.mouse.down();
        await page.mouse.move(rect.x + (rect.width / 2) + 120, rect.y + (rect.height / 2) - 20, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(700);

        const after = await page.evaluate(() => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const nySpans = Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .textLayer span'))
                .filter((span) => normalize(span.textContent) === 'NY · SF')
                .map((span) => ({
                    hidden: span.dataset.enpvMovedSourceHidden || '',
                    visibility: window.getComputedStyle(span).visibility,
                    opacity: window.getComputedStyle(span).opacity,
                }));
            const nyOverlay = Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box'))
                .map((box) => ({
                    text: normalize(box.querySelector('.enpv-text-content')?.textContent || box.textContent || ''),
                    dx: Number(box.dataset.dxPts || 0),
                    moved: box.dataset.movedTextOverlay || '',
                }))
                .find((box) => box.text === 'NY · SF');
            return { nySpans, nyOverlay };
        });

        if (!after.nyOverlay || after.nyOverlay.moved !== '1' || Math.abs(after.nyOverlay.dx) < 1) {
            throw new Error(`NY/SF overlay did not move as its own annotation: ${JSON.stringify(after)}`);
        }
        if (!after.nySpans.length || after.nySpans.some((span) => (
            span.hidden !== '1' || span.visibility !== 'hidden' || span.opacity !== '0'
        ))) {
            throw new Error(`Original NY/SF source spans were not all hidden after move: ${JSON.stringify(after)}`);
        }

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(1000);

        const meetingsBox = page.locator('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box')
            .filter({ hasText: 'meetings' })
            .first();
        await meetingsBox.scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        const meetingsRect = await meetingsBox.boundingBox();
        if (!meetingsRect) throw new Error('Unable to locate meetings box for drag.');

        await page.mouse.move(meetingsRect.x + (meetingsRect.width / 2), meetingsRect.y + (meetingsRect.height / 2));
        await page.mouse.down();
        await page.mouse.move(meetingsRect.x + (meetingsRect.width / 2) + 120, meetingsRect.y + (meetingsRect.height / 2) - 20, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(700);

        const meetingsAfter = await page.evaluate(() => {
            const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const pageRect = pageEl?.getBoundingClientRect();
            const spans = Array.from(pageEl?.querySelectorAll('.textLayer span') || [])
                .filter((span) => normalize(span.textContent) === 'meetings')
                .map((span) => ({
                    hidden: span.dataset.enpvMovedSourceHidden || '',
                    visibility: window.getComputedStyle(span).visibility,
                    opacity: window.getComputedStyle(span).opacity,
                }));
            const overlay = Array.from(pageEl?.querySelectorAll('.enpv-annotation-box') || [])
                .map((box) => ({
                    text: normalize(box.querySelector('.enpv-text-content')?.textContent || box.textContent || ''),
                    dx: Number(box.dataset.dxPts || 0),
                    moved: box.dataset.movedTextOverlay || '',
                    maskAttached: box.dataset.sourceMaskAttached || '',
                }))
                .find((box) => box.text === 'meetings');
            const masks = Array.from(pageEl?.querySelectorAll('.enpv-source-mask') || [])
                .map((mask) => {
                    const rect = mask.getBoundingClientRect();
                    return {
                        left: pageRect ? rect.left - pageRect.left : rect.left,
                        top: pageRect ? rect.top - pageRect.top : rect.top,
                        width: rect.width,
                        height: rect.height,
                        children: Array.from(mask.children).map((child) => {
                            const childRect = child.getBoundingClientRect();
                            return {
                                width: childRect.width,
                                height: childRect.height,
                            };
                        }),
                    };
                })
                .filter((mask) => Math.abs(mask.left - 197) < 6 && Math.abs(mask.top - 2362) < 6);
            return { spans, overlay, masks };
        });

        if (!meetingsAfter.overlay || meetingsAfter.overlay.moved !== '1' || meetingsAfter.overlay.maskAttached !== '1' || Math.abs(meetingsAfter.overlay.dx) < 1) {
            throw new Error(`meetings overlay did not move with an attached source mask: ${JSON.stringify(meetingsAfter)}`);
        }
        if (!meetingsAfter.spans.length || meetingsAfter.spans.some((span) => (
            span.hidden !== '1' || span.visibility !== 'hidden' || span.opacity !== '0'
        ))) {
            throw new Error(`Original meetings text-layer spans were not all hidden after move: ${JSON.stringify(meetingsAfter)}`);
        }
        if (!meetingsAfter.masks.some((mask) => (
            mask.children.some((child) => child.width > 180 && child.height > 40)
        ))) {
            throw new Error(`meetings source mask does not cover the original glyph run: ${JSON.stringify(meetingsAfter)}`);
        }

        console.log('doc4184 pdfjs drylab column grouping regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
