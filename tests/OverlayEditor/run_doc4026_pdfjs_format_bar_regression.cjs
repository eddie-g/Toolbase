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
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4026);
const TARGET_ID = process.env.TARGET_ID || 'pdfjs_4026_0_0:6';
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

async function setColorInput(page, selector, value) {
    await page.evaluate(({ selector: inputSelector, value: nextValue }) => {
        const input = document.querySelector(inputSelector);
        if (!(input instanceof HTMLInputElement)) throw new Error(`missing ${inputSelector}`);
        input.value = nextValue;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, { selector, value });
}

async function setPressed(page, selector, pressed) {
    const current = await page.locator(selector).getAttribute('aria-pressed');
    if ((current === 'true') !== pressed) {
        await page.locator(selector).click();
    }
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 2200, height: 1100 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1500);
        await page.locator('#edit-mode-toggle').click();
        await page.waitForSelector(`.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`, { timeout: 90000 });

        await page.evaluate((targetId) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            if (!(box instanceof HTMLElement)) throw new Error(`missing target ${targetId}`);
            const rect = box.getBoundingClientRect();
            box.dispatchEvent(new PointerEvent('pointerdown', {
                bubbles: true,
                button: 0,
                clientX: rect.left + (rect.width / 2),
                clientY: rect.top + (rect.height / 2),
                pointerId: 1,
                pointerType: 'mouse',
            }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, button: 0, pointerId: 1, pointerType: 'mouse' }));
        }, TARGET_ID);
        await page.waitForSelector('#ann-format-bar.is-visible', { timeout: 5000 });

        await page.selectOption('#afb-font', 'Courier');
        await page.locator('#afb-size').evaluate((input) => {
            input.value = '85';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        const largeSizeBox = await page.evaluate((targetId) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            if (!(box instanceof HTMLElement)) return null;
            const rect = box.getBoundingClientRect();
            return {
                width: rect.width,
                height: rect.height,
                fontSize: Number(box.dataset.fontSizePts || 0),
            };
        }, TARGET_ID);
        await page.locator('#afb-size').evaluate((input) => {
            input.value = '45';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await setColorInput(page, '#afb-text-color', '#123456');
        await setColorInput(page, '#afb-bg-color', '#fedcba');
        const colorPreviewMs = await page.evaluate(() => {
            const textColor = document.querySelector('#afb-text-color');
            const bgColor = document.querySelector('#afb-bg-color');
            if (!(textColor instanceof HTMLInputElement) || !(bgColor instanceof HTMLInputElement)) {
                throw new Error('missing color controls');
            }
            const started = performance.now();
            for (let i = 0; i < 40; i += 1) {
                textColor.value = `#12${(50 + i).toString(16).padStart(2, '0')}56`;
                textColor.dispatchEvent(new Event('input', { bubbles: true }));
                bgColor.value = `#fe${(180 - i).toString(16).padStart(2, '0')}ba`;
                bgColor.dispatchEvent(new Event('input', { bubbles: true }));
            }
            textColor.value = '#123456';
            textColor.dispatchEvent(new Event('change', { bubbles: true }));
            bgColor.value = '#fedcba';
            bgColor.dispatchEvent(new Event('change', { bubbles: true }));
            return performance.now() - started;
        });
        if (colorPreviewMs > 500) {
            throw new Error(`color preview input path is too slow: ${colorPreviewMs.toFixed(1)}ms`);
        }
        await page.selectOption('#afb-opacity', '0.6');
        await setPressed(page, '#afb-bold', true);
        await setPressed(page, '#afb-italic', true);
        await setPressed(page, '#afb-underline', true);
        await page.selectOption('#afb-align', 'center');
        await page.selectOption('#afb-valign', 'bottom');

        const boxState = await page.evaluate((targetId) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            const text = box?.querySelector('.enpv-text-content');
            if (!(box instanceof HTMLElement) || !(text instanceof HTMLElement)) return null;
            const cs = getComputedStyle(text);
            return {
                fontFamily: box.style.getPropertyValue('--enpv-font-family'),
                fontSize: box.dataset.fontSizePts,
                color: box.style.getPropertyValue('--enpv-text-color'),
                background: box.style.getPropertyValue('--enpv-bg-color'),
                opacity: box.style.getPropertyValue('--enpv-opacity'),
                fontWeight: box.style.getPropertyValue('--enpv-font-weight'),
                fontStyle: box.style.getPropertyValue('--enpv-font-style'),
                decoration: box.style.getPropertyValue('--enpv-text-decoration'),
                textAlign: box.style.getPropertyValue('--enpv-text-align') || cs.textAlign,
                verticalAlign: box.dataset.verticalAlign,
                display: cs.display,
                justifyContent: cs.justifyContent,
                width: box.getBoundingClientRect().width,
                height: box.getBoundingClientRect().height,
            };
        }, TARGET_ID);
        if (!boxState) throw new Error('target box disappeared after formatting');
        if (!largeSizeBox) throw new Error('target box disappeared after large font sizing');
        if (!(Number(boxState.fontSize) < Number(largeSizeBox.fontSize))) {
            throw new Error(`font size did not decrease after scaling down: ${JSON.stringify({ largeSizeBox, boxState }, null, 2)}`);
        }
        if (!(Number(boxState.height) < Number(largeSizeBox.height))) {
            throw new Error(`box height did not shrink after scaling font down: ${JSON.stringify({ largeSizeBox, boxState }, null, 2)}`);
        }

        await page.locator('#afb-copy').click();
        const copiedId = await page.evaluate(() => document.querySelector('.enpv-annotation-box.is-selected')?.getAttribute('data-annotation-id') || '');
        if (!copiedId || copiedId === TARGET_ID) throw new Error(`copy did not select a new annotation: ${copiedId}`);
        await page.locator('#afb-delete').click();
        await page.waitForTimeout(250);
        const copyStillExists = await page.evaluate((id) => Boolean(document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(id)}"]`)), copiedId);
        if (copyStillExists) throw new Error(`toolbar delete did not remove copied annotation ${copiedId}`);

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url().includes('/download-annotated-pdf')
        ), { timeout: 120000 });
        await page.locator('#download-pdf-btn').click();
        const request = await requestPromise;
        const payload = request.postDataJSON();
        const annotation = (payload.annotations || []).find((item) => String(item.id || '') === TARGET_ID);
        if (!annotation) throw new Error(`formatted annotation ${TARGET_ID} missing from download payload`);

        const checks = [
            ['fontFamily', String(annotation.fontFamily) === 'Courier'],
            ['fontSize', Math.round(Number(annotation.fontSize)) === Math.round(Number(boxState.fontSize))],
            ['textColor', annotation.textColor === '#123456'],
            ['backgroundColor', annotation.backgroundColor === '#fedcba'],
            ['opacity', Math.abs(Number(annotation.opacity) - 0.6) < 0.001],
            ['fontWeight', String(annotation.fontWeight) === '700'],
            ['fontStyle', annotation.fontStyle === 'italic'],
            ['underline', annotation.underline === true],
            ['textAlign', annotation.textAlign === 'center'],
            ['verticalAlign', annotation.verticalAlign === 'bottom'],
            ['verticalAlignCss', boxState.display === 'flex' && boxState.justifyContent === 'flex-end'],
        ];
        const failed = checks.filter(([, pass]) => !pass).map(([name]) => name);
        if (failed.length) {
            throw new Error(`format payload checks failed for ${failed.join(', ')}: ${JSON.stringify({ boxState, annotation }, null, 2)}`);
        }

        console.log(JSON.stringify({
            id: TARGET_ID,
            copiedId,
            boxState,
            payload: {
                fontFamily: annotation.fontFamily,
                fontSize: annotation.fontSize,
                textColor: annotation.textColor,
                backgroundColor: annotation.backgroundColor,
                opacity: annotation.opacity,
                fontWeight: annotation.fontWeight,
                fontStyle: annotation.fontStyle,
                underline: annotation.underline,
                textAlign: annotation.textAlign,
                verticalAlign: annotation.verticalAlign,
            },
            colorPreviewMs,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
