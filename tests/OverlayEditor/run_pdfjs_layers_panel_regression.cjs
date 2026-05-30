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

function assert(condition, message) {
    if (!condition) throw new Error(message);
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

    throw new Error('Unable to log in to admin for layers panel regression test.');
}

async function clickLayerAction(page, annotationId, label) {
    const point = await page.evaluate(({ annotationId, label }) => {
        const button = document.querySelector(
            `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(annotationId)}"] `
            + `.enpv-layer-icon-button[aria-label="${label}"]`,
        );
        if (!button) throw new Error(`Missing ${label} button for ${annotationId}`);
        const rect = button.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, { annotationId, label });
    await page.mouse.click(point.x, point.y);
}

async function clickLayerRow(page, annotationId) {
    const point = await page.evaluate((id) => {
        const row = document.querySelector(
            `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(id)}"]`,
        );
        if (!row) throw new Error(`Missing layer row for ${id}`);
        row.scrollIntoView({ block: 'center' });
        const name = row.querySelector('.enpv-layer-name') || row;
        const rect = name.getBoundingClientRect();
        return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
    }, annotationId);
    await page.mouse.click(point.x, point.y);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
    const browserErrors = [];
    page.on('pageerror', (error) => browserErrors.push(error.stack || error.message || String(error)));
    page.on('console', (message) => {
        if (message.type() === 'error') browserErrors.push(message.text());
    });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', {
            timeout: 120000,
        });

        const saveUrl = await page.locator('#edit-new-root').getAttribute('data-save-url');
        const savePath = new URL(saveUrl, BASE_URL).pathname;
        await page.route((url) => url.pathname === savePath, async (route) => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ success: true, session_id: 'pdfjs-layers-panel-regression' }),
        }));

        await page.click('#enpv-layers-open');
        await page.waitForSelector('#enpv-layers-panel:not([hidden])');
        const state = await page.evaluate(() => {
            const pagesButton = document.querySelector('#enpv-page-manager-open')?.getBoundingClientRect();
            const layersButton = document.querySelector('#enpv-layers-open')?.getBoundingClientRect();
            const sections = [...document.querySelectorAll('#enpv-layers-list .enpv-layer-page')].map((section) => ({
                page: section.querySelector('strong')?.textContent || '',
                ids: [...section.querySelectorAll('.enpv-layer-row')].map((row) => row.dataset.annotationId),
            }));
            return {
                pagesLeft: pagesButton?.left ?? Infinity,
                layersLeft: layersButton?.left ?? -Infinity,
                viewportWidth: window.innerWidth,
                panelHidden: document.querySelector('#enpv-layers-panel')?.hidden,
                sections,
                textNames: [...document.querySelectorAll('#enpv-layers-list .enpv-layer-kind-icon + .enpv-layer-name')]
                    .filter((name) => name.parentElement?.querySelector('.enpv-layer-kind-icon svg rect[stroke-dasharray]'))
                    .map((name) => name.textContent || ''),
            };
        });
        assert(state.pagesLeft < 100, 'Pages button should remain on the left rail.');
        assert(state.layersLeft > state.viewportWidth - 100, 'Layers button should sit on the opposite right rail.');
        assert(state.panelHidden === false, 'Layers panel should be visible after clicking its rail button.');
        assert(state.textNames.length > 0, 'Layers panel should include a text layer for preview coverage.');
        assert(
            state.textNames.some((name) => name && !/^New Text \d+$/.test(name)),
            `Text layers should show content previews instead of generic names: ${JSON.stringify(state.textNames)}`,
        );

        const lowerPageSection = state.sections.find((entry, index) => index > 0 && entry.ids.length > 0);
        assert(lowerPageSection, 'Layers panel needs a lower-page annotation for scroll coverage.');
        const lowerPageId = lowerPageSection.ids[0];
        await page.evaluate(() => {
            document.querySelector('#viewerContainer').scrollTo({ top: 0, behavior: 'auto' });
        });
        await clickLayerRow(page, lowerPageId);
        await page.waitForTimeout(100);
        const scrollState = await page.evaluate((annotationId) => {
            const container = document.querySelector('#viewerContainer');
            const selected = document.querySelector(
                `.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"].is-selected`,
            );
            const containerRect = container?.getBoundingClientRect();
            const selectedRect = selected?.getBoundingClientRect();
            return {
                scrollTop: container?.scrollTop || 0,
                selected: Boolean(selected),
                visible: Boolean(
                    containerRect && selectedRect
                    && selectedRect.bottom >= containerRect.top
                    && selectedRect.top <= containerRect.bottom
                ),
            };
        }, lowerPageId);
        assert(scrollState.scrollTop > 0, 'Clicking a lower-page layer should scroll the PDF viewer down.');
        assert(scrollState.selected, 'Clicking a layer row should select the matching canvas annotation.');
        assert(scrollState.visible, 'Clicking a layer row should bring its canvas annotation into view.');

        const upperPageId = state.sections[0]?.ids[0];
        assert(upperPageId, 'Layers panel needs an upper-page annotation for upward scroll coverage.');
        await clickLayerRow(page, upperPageId);
        await page.waitForTimeout(100);
        const upwardScrollState = await page.evaluate((annotationId) => {
            const container = document.querySelector('#viewerContainer');
            const selected = document.querySelector(
                `.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"].is-selected`,
            );
            const containerRect = container?.getBoundingClientRect();
            const selectedRect = selected?.getBoundingClientRect();
            return {
                scrollTop: container?.scrollTop || 0,
                selected: Boolean(selected),
                visible: Boolean(
                    containerRect && selectedRect
                    && selectedRect.bottom >= containerRect.top
                    && selectedRect.top <= containerRect.bottom
                ),
            };
        }, upperPageId);
        assert(
            upwardScrollState.scrollTop < scrollState.scrollTop,
            'Clicking an upper-page layer should scroll the PDF viewer back up.',
        );
        assert(upwardScrollState.selected, 'Clicking an upper-page layer should select its canvas annotation.');
        assert(upwardScrollState.visible, 'Clicking an upper-page layer should bring its canvas annotation into view.');

        const section = state.sections.find((entry) => entry.ids.length >= 2);
        assert(section, 'Layers panel needs at least two same-page annotations for reorder coverage.');
        const [firstId, secondId] = section.ids;

        await page.evaluate(({ firstId, secondId }) => {
            const source = document.querySelector(
                `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(firstId)}"]`,
            );
            const target = document.querySelector(
                `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(secondId)}"]`,
            );
            const transfer = new DataTransfer();
            source.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: transfer }));
            target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: transfer }));
            target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }));
            source.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: transfer }));
        }, { firstId, secondId });
        await page.waitForTimeout(100);
        const reordered = await page.evaluate(({ firstId, secondId }) => (
            [...document.querySelectorAll('#enpv-layers-list .enpv-layer-row')]
                .map((row) => row.dataset.annotationId)
                .filter((id) => id === firstId || id === secondId)
        ), { firstId, secondId });
        assert(
            reordered[0] === secondId && reordered[1] === firstId,
            `Drag reorder should change same-page layer order: ${JSON.stringify(reordered)}`,
        );

        await clickLayerAction(page, firstId, 'Edit layer');
        await page.waitForTimeout(100);
        const selected = await page.evaluate((annotationId) => Boolean(document.querySelector(
            `.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"].is-selected`,
        )), firstId);
        assert(selected, 'Edit layer action should focus the corresponding canvas annotation.');

        await clickLayerAction(page, secondId, 'Delete layer');
        await page.waitForTimeout(100);
        const afterDelete = await page.evaluate((annotationId) => ({
            exists: Boolean(document.querySelector(
                `#enpv-layers-list .enpv-layer-row[data-annotation-id="${CSS.escape(annotationId)}"]`,
            )),
            panelHidden: document.querySelector('#enpv-layers-panel')?.hidden,
        }), secondId);
        assert(!afterDelete.exists, 'Delete layer action should remove the drawer row.');
        assert(afterDelete.panelHidden === false, 'Layers panel should stay open after deleting a row.');

        const removeAllPoint = await page.evaluate((pageLabel) => {
            const section = [...document.querySelectorAll('#enpv-layers-list .enpv-layer-page')]
                .find((entry) => entry.querySelector('strong')?.textContent === pageLabel);
            const button = section?.querySelector('.enpv-layer-remove-all');
            if (!button) throw new Error(`Missing Remove all button for ${pageLabel}`);
            const rect = button.getBoundingClientRect();
            return { x: rect.left + (rect.width / 2), y: rect.top + (rect.height / 2) };
        }, section.page);
        await page.mouse.click(removeAllPoint.x, removeAllPoint.y);
        await page.waitForTimeout(100);
        const remainingPageRows = await page.evaluate((pageLabel) => {
            const section = [...document.querySelectorAll('#enpv-layers-list .enpv-layer-page')]
                .find((entry) => entry.querySelector('strong')?.textContent === pageLabel);
            return section?.querySelectorAll('.enpv-layer-row').length || 0;
        }, section.page);
        assert(remainingPageRows === 0, 'Remove all should clear every layer row for that page.');

        if (browserErrors.length) {
            throw new Error(`Browser errors during layers panel regression:\n${browserErrors.join('\n')}`);
        }
        console.log('PASS pdfjs layers panel rail, reorder, focus, delete, and remove-all regression');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
