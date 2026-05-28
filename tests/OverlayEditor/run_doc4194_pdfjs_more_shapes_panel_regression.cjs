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
const browserErrors = [];

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

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

async function assertMoreShapeMenu(page) {
    const collapsedState = await page.evaluate(() => {
        const panel = document.querySelector('#shape-tool-panel');
        const extraGrid = document.querySelector('#shape-more-grid');
        const starInPrimary = document.querySelector('#shape-type-grid [data-shape-tool="star"]');
        const lineButton = document.querySelector('#shape-type-grid [data-shape-tool="line"]');
        const toggle = document.querySelector('#shape-more-toggle');
        return {
            panelCollapsed: panel?.classList.contains('sfb-extra-collapsed') || false,
            extraDisplay: extraGrid ? window.getComputedStyle(extraGrid).display : '',
            starInPrimary: Boolean(starInPrimary),
            lineBeforeToggle: Boolean(lineButton && toggle && (lineButton.compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_FOLLOWING)),
            expanded: toggle?.getAttribute('aria-expanded') || '',
        };
    });
    assertEqual(collapsedState.panelCollapsed, true, 'More shapes panel should start collapsed');
    assertEqual(collapsedState.extraDisplay, 'none', 'Extra shapes should be hidden before toggling');
    assertEqual(collapsedState.starInPrimary, false, 'Star should not remain in the primary shape row');
    assertEqual(collapsedState.lineBeforeToggle, true, 'More shapes toggle should sit after the line tool');
    assertEqual(collapsedState.expanded, 'false', 'More shapes toggle should start collapsed');

    await page.click('#shape-more-toggle');
    const expandedState = await page.evaluate(() => {
        const extraGrid = document.querySelector('#shape-more-grid');
        const buttons = ['star', 'x', 'heart'].map((shapeType) => {
            const button = document.querySelector(`#shape-more-grid [data-shape-tool="${shapeType}"]`);
            return [shapeType, Boolean(button), button ? window.getComputedStyle(button).display : ''];
        });
        const removedButtons = ['checkmark', 'arrow'].map((shapeType) => [shapeType, Boolean(document.querySelector(`#shape-more-grid [data-shape-tool="${shapeType}"]`))]);
        return {
            extraDisplay: extraGrid ? window.getComputedStyle(extraGrid).display : '',
            expanded: document.querySelector('#shape-more-toggle')?.getAttribute('aria-expanded') || '',
            buttons,
            removedButtons,
        };
    });
    assertEqual(expandedState.expanded, 'true', 'More shapes toggle should expand');
    if (expandedState.extraDisplay === 'none') {
        throw new Error(`Extra shape grid stayed hidden: ${JSON.stringify(expandedState)}`);
    }
    for (const [shapeType, exists, display] of expandedState.buttons) {
        if (!exists || display === 'none') {
            throw new Error(`Expected visible more-shape button for ${shapeType}: ${JSON.stringify(expandedState)}`);
        }
    }
    for (const [shapeType, exists] of expandedState.removedButtons) {
        if (exists) {
            throw new Error(`Did not expect ${shapeType} to remain in the more-shapes menu: ${JSON.stringify(expandedState)}`);
        }
    }
}

async function drawShape(page, shapeType, expected) {
    if (await page.locator('#shape-more-toggle[aria-expanded="false"]').count()) {
        await page.click('#shape-more-toggle');
    }
    await page.locator(`#shape-more-grid [data-shape-tool="${shapeType}"]`).click();
    await page.waitForFunction(
        (type) => document.querySelector(`#shape-more-grid [data-shape-tool="${type}"]`)?.classList.contains('is-active'),
        shapeType,
        { timeout: 5000 },
    );
    const inspectorState = await page.evaluate(() => ({
        strokeColor: document.querySelector('#shape-stroke-color')?.value.toLowerCase() || '',
        fillColor: document.querySelector('#shape-fill-color')?.value.toLowerCase() || '',
        strokeWidth: document.querySelector('#shape-stroke-width')?.value || '',
        strokeOpacity: document.querySelector('#shape-stroke-opacity')?.value || '',
        fillOpacity: document.querySelector('#shape-fill-opacity')?.value || '',
        strokeTransparent: document.querySelector('#shape-stroke-transparent')?.checked || false,
        fillTransparent: document.querySelector('#shape-fill-transparent')?.checked || false,
    }));
    for (const [key, expectedValue] of Object.entries(expected.inspector)) {
        assertEqual(inspectorState[key], expectedValue, `${shapeType} default ${key}`);
    }

    const pageBox = await page.locator('.pdfViewer .page[data-page-number="1"]').boundingBox();
    if (!pageBox) throw new Error('Could not locate page 1 for shape drawing.');
    const left = pageBox.x + 130;
    const top = pageBox.y + 180;
    await page.mouse.move(left, top);
    await page.mouse.down();
    await page.mouse.move(left + 115, top + 95, { steps: 8 });
    await page.mouse.up();

    try {
        await page.waitForFunction(
            (type) => document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected')?.dataset.shapeType === type,
            shapeType,
            { timeout: 10000 },
        );
    } catch (error) {
        const debugState = await page.evaluate(() => ({
            shapeOn: document.body.classList.contains('enpv-shape-on'),
            activeTool: document.querySelector('.sfb-shape-btn.is-active')?.dataset.shapeTool || '',
            selectedType: document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected')?.dataset.shapeType || '',
            shapeCount: document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-shape-box').length,
            lastShapes: Array.from(document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-shape-box')).slice(-5).map((box) => ({
                type: box.dataset.shapeType || '',
                selected: box.classList.contains('is-selected'),
                rect: (() => {
                    const rect = box.getBoundingClientRect();
                    return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
                })(),
            })),
        }));
        throw new Error(`${shapeType} was not drawn and selected: ${JSON.stringify({ ...debugState, browserErrors })}`, { cause: error });
    }

    const drawnState = await page.evaluate(() => {
        const selected = document.querySelector('.pdfViewer .page[data-page-number="1"] .enpv-shape-box.is-selected');
        const drawn = selected?.querySelector('svg.enpv-shape-svg > *');
        return {
            shapeType: selected?.dataset.shapeType || '',
            strokeColor: selected?.dataset.strokeColor?.toLowerCase() || '',
            fillColor: selected?.dataset.fillColor?.toLowerCase() || '',
            strokeWidth: selected?.dataset.strokeWidth || '',
            strokeTransparent: selected?.dataset.strokeTransparent || '',
            fillTransparent: selected?.dataset.fillTransparent || '',
            tagName: drawn?.tagName.toLowerCase() || '',
            pathD: drawn?.getAttribute('d') || '',
            svgFill: drawn?.getAttribute('fill')?.toLowerCase() || '',
            svgStroke: drawn?.getAttribute('stroke')?.toLowerCase() || '',
        };
    });

    assertEqual(drawnState.shapeType, shapeType, `${shapeType} selected dataset type`);
    assertEqual(drawnState.strokeColor, expected.dataset.strokeColor, `${shapeType} dataset stroke color`);
    assertEqual(drawnState.strokeWidth, expected.dataset.strokeWidth, `${shapeType} dataset stroke width`);
    assertEqual(drawnState.fillTransparent, expected.dataset.fillTransparent, `${shapeType} dataset fill transparency`);
    assertEqual(drawnState.tagName, 'path', `${shapeType} should render as an SVG path`);
    assertEqual(drawnState.svgStroke, expected.dataset.strokeColor, `${shapeType} SVG stroke color`);
    if (!drawnState.pathD) throw new Error(`${shapeType} SVG path was empty.`);
    if (expected.dataset.fillColor) {
        assertEqual(drawnState.fillColor, expected.dataset.fillColor, `${shapeType} dataset fill color`);
        assertEqual(drawnState.svgFill, expected.dataset.fillColor, `${shapeType} SVG fill color`);
    } else {
        assertEqual(drawnState.svgFill, 'none', `${shapeType} SVG fill`);
    }

    await page.keyboard.press('Escape');
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
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
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', { timeout: 90000 });
        await page.waitForTimeout(750);
        await page.addStyleTag({ content: '.pdfViewer .page .enpv-annotation-box { pointer-events: none !important; }' });

        await page.click('#add-shape-btn');
        await page.waitForSelector('#shape-tool-panel.is-visible', { timeout: 5000 });
        await assertMoreShapeMenu(page);

        const shapeExpectations = [
            ['x', {
                inspector: { strokeColor: '#dc2626', strokeWidth: '4', strokeOpacity: '100', fillOpacity: '0', strokeTransparent: false, fillTransparent: true },
                dataset: { strokeColor: '#dc2626', strokeWidth: '4', fillTransparent: '1' },
            }],
            ['heart', {
                inspector: { strokeColor: '#dc2626', fillColor: '#ef4444', strokeWidth: '3', strokeOpacity: '100', fillOpacity: '100', strokeTransparent: false, fillTransparent: false },
                dataset: { strokeColor: '#dc2626', fillColor: '#ef4444', strokeWidth: '3', fillTransparent: '0' },
            }],
        ];

        for (const [shapeType, expected] of shapeExpectations) {
            await drawShape(page, shapeType, expected);
        }

        console.log('PASS pdfjs more-shapes panel and default shape styling regression');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});