#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5289);
const TARGET_ID = process.env.TARGET_ID || `pdfjs_${DOCUMENT_ID}_0_0:22`;
const REPORTED_TEST_ID = process.env.TEST_ID || '37802604-50f6-47b1-951a-f69bd3d35a1a';
const { requireAdminCredentials } = require('../../tools/admin-credentials.cjs');
// Resolved from AUTOMATED_TESTS_ADMIN_* (the QA admin), never hardcoded:
// a stale default here is what led to real admin passwords being reset.
const { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } = requireAdminCredentials();
const ARTIFACT_DIR = String(process.env.ARTIFACT_DIR || '').trim();

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    // One attempt. Trying a list of candidate passwords walked straight
    // into Fortify's five-per-minute login throttle.
    await page.fill('#data\.email', ADMIN_EMAIL);
    await page.fill('#data\.password', ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    try {
        await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 });
    } catch (_) {
        if (page.url().includes('/admin/login')) {
            throw new Error(`Admin login failed for ${ADMIN_EMAIL}. Check AUTOMATED_TESTS_ADMIN_* in .env.`);
        }
    }
}

function pngColorStats(buffer) {
    const png = PNG.sync.read(buffer);
    const buckets = new Map();
    let white = 0;
    let opaque = 0;
    const horizontalRuleRows = [];
    const inset = 7;
    const scanLeft = Math.min(png.width - 1, inset);
    const scanRight = Math.max(scanLeft + 1, png.width - inset);
    const scanWidth = scanRight - scanLeft;
    const rowDarkCounts = [];
    for (let y = 1; y < png.height - 1; y += 1) {
        let rowDark = 0;
        for (let x = 1; x < png.width - 1; x += 1) {
            const offset = ((y * png.width) + x) * 4;
            const alpha = png.data[offset + 3];
            if (alpha < 240) continue;
            const r = png.data[offset];
            const g = png.data[offset + 1];
            const b = png.data[offset + 2];
            if (x >= scanLeft && x < scanRight && r < 170 && g < 170 && b < 170) {
                rowDark += 1;
            }
            opaque += 1;
            if (r >= 248 && g >= 248 && b >= 248) white += 1;
            if (r > 96 && g > 96 && b > 96) {
                const key = [r, g, b]
                    .map((value) => Math.min(255, Math.round(value / 4) * 4))
                    .join(',');
                buckets.set(key, (buckets.get(key) || 0) + 1);
            }
        }
        rowDarkCounts[y] = rowDark;
        if (scanWidth > 0 && rowDark / scanWidth >= 0.85) horizontalRuleRows.push(y);
    }
    const horizontalRuleBands = [];
    for (const row of horizontalRuleRows) {
        const previous = horizontalRuleBands[horizontalRuleBands.length - 1];
        if (previous && row === previous.bottom + 1) previous.bottom = row;
        else horizontalRuleBands.push({ top: row, bottom: row });
    }
    const ruleRows = new Set(horizontalRuleBands.flatMap((band) => {
        const rows = [];
        for (let row = Math.max(0, band.top - 1); row <= Math.min(png.height - 1, band.bottom + 1); row += 1) {
            rows.push(row);
        }
        return rows;
    }));
    let interiorDark = 0;
    for (let y = inset; y < png.height - inset; y += 1) {
        if (ruleRows.has(y)) continue;
        interiorDark += rowDarkCounts[y] || 0;
    }
    const dominant = Array.from(buckets.entries()).sort((left, right) => right[1] - left[1])[0] || ['', 0];
    return {
        width: png.width,
        height: png.height,
        white,
        opaque,
        whiteRatio: opaque ? white / opaque : 0,
        dominant: dominant[0],
        dominantCount: dominant[1],
        horizontalRuleBands,
        interiorDark,
    };
}

async function targetState(page) {
    return page.evaluate((targetId) => {
        const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
        if (!box) return null;
        const pageDiv = box.closest('.page');
        const pageRect = pageDiv.getBoundingClientRect();
        const boxRect = box.getBoundingClientRect();
        const mask = box._enpvSourceMask || null;
        const relativeRect = (rect) => rect ? {
            left: rect.left - pageRect.left,
            top: rect.top - pageRect.top,
            width: rect.width,
            height: rect.height,
            right: rect.right - pageRect.left,
            bottom: rect.bottom - pageRect.top,
        } : null;
        return {
            text: box.querySelector('.enpv-text-content')?.textContent || '',
            boxRect: boxRect.toJSON(),
            pageRect: pageRect.toJSON(),
            sourceRect: {
                left: relativeRect(boxRect).left,
                top: relativeRect(boxRect).top,
                width: boxRect.width,
                height: boxRect.height,
            },
            movedTextOverlay: box.dataset.movedTextOverlay || '',
            sourceMaskAttached: box.dataset.sourceMaskAttached || '',
            mask: mask ? {
                rect: relativeRect(mask.getBoundingClientRect()),
                inlineBackground: mask.style.background,
                background: getComputedStyle(mask).backgroundColor,
                children: Array.from(mask.children).map((child) => ({
                    rect: relativeRect(child.getBoundingClientRect()),
                    inlineBackground: child.style.background,
                    background: getComputedStyle(child).backgroundColor,
                })),
            } : null,
        };
    }, TARGET_ID);
}

async function screenshotSource(page, state, name, sourceOverride = null) {
    const padding = 7;
    const source = sourceOverride || state.mask?.rect || state.sourceRect;
    const clip = {
        x: Math.max(0, state.pageRect.x + source.left - padding),
        y: Math.max(0, state.pageRect.y + source.top - padding),
        width: source.width + (padding * 2),
        height: source.height + (padding * 2),
    };
    await page.evaluate(() => {
        const style = document.createElement('style');
        style.id = 'doc5289-regression-hide-editor-ui';
        style.textContent = '.enpv-ann-menu, .enpv-ann-menu *, .enpv-ann-menu-btn { display: none !important; }';
        document.head.appendChild(style);
        const layers = document.querySelectorAll(
            '.pdfViewer .page[data-page-number="1"] .enpv-annotation-box-layer, '
            + '.pdfViewer .page[data-page-number="1"] .textLayer, '
            + '.pdfViewer .page[data-page-number="1"] .annotationLayer',
        );
        layers.forEach((layer) => {
            layer.dataset.regressionVisibility = layer.style.visibility || '';
            layer.style.visibility = 'hidden';
        });
    });
    const buffer = await page.screenshot({ clip });
    await page.evaluate(() => {
        document.getElementById('doc5289-regression-hide-editor-ui')?.remove();
        const layers = document.querySelectorAll(
            '.pdfViewer .page[data-page-number="1"] .enpv-annotation-box-layer, '
            + '.pdfViewer .page[data-page-number="1"] .textLayer, '
            + '.pdfViewer .page[data-page-number="1"] .annotationLayer',
        );
        layers.forEach((layer) => {
            const visibility = layer.dataset.regressionVisibility || '';
            if (visibility) layer.style.visibility = visibility;
            else layer.style.removeProperty('visibility');
            delete layer.dataset.regressionVisibility;
        });
    });
    if (ARTIFACT_DIR) {
        fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
        fs.writeFileSync(path.join(ARTIFACT_DIR, `${name}.png`), buffer);
    }
    return pngColorStats(buffer);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 2200, height: 1100 }, deviceScaleFactor: 1 });

    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            if (route.request().method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.locator('.pdfViewer .page[data-page-number="1"]').scrollIntoViewIfNeeded();
        await page.locator('#ftb-edit-mode').click();
        const box = page.locator(`.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`).first();
        await box.waitFor({ state: 'visible', timeout: 90000 });
        await box.scrollIntoViewIfNeeded();
        await page.waitForTimeout(800);

        const before = await targetState(page);
        if (!before) throw new Error(`Target annotation ${TARGET_ID} was not rendered.`);
        const beforePixels = await screenshotSource(page, before, 'before-move');
        const bounds = await box.boundingBox();
        if (!bounds) throw new Error(`Could not measure ${TARGET_ID} before drag.`);
        const x = bounds.x + (bounds.width / 2);
        const y = bounds.y + (bounds.height / 2);
        await page.mouse.move(x, y);
        await page.mouse.down();
        await page.mouse.move(x + 70, y + 45, { steps: 10 });
        await page.mouse.up();
        await page.waitForTimeout(1200);

        const after = await targetState(page);
        if (!after?.mask) throw new Error(`${TARGET_ID} did not attach a source mask after drag.`);
        const afterPixels = await screenshotSource(page, after, 'after-move', before.sourceRect);
        const result = {
            testId: REPORTED_TEST_ID,
            id: TARGET_ID,
            before,
            after,
            beforePixels,
            afterPixels,
        };
        console.log(JSON.stringify(result, null, 2));

        if (after.movedTextOverlay !== '1') {
            throw new Error(`${TARGET_ID} was not promoted to a moved text overlay.`);
        }
        if (beforePixels.horizontalRuleBands.length < 2) {
            throw new Error('Fixture no longer contains both horizontal rules around the source annotation.');
        }
        if (afterPixels.horizontalRuleBands.length < beforePixels.horizontalRuleBands.length) {
            throw new Error(
                `Moved source mask erased a table rule: before=${JSON.stringify(beforePixels.horizontalRuleBands)} `
                + `after=${JSON.stringify(afterPixels.horizontalRuleBands)}`,
            );
        }
        const missingRuleBand = beforePixels.horizontalRuleBands.find((beforeBand) => (
            !afterPixels.horizontalRuleBands.some((afterBand) => (
                Math.abs(afterBand.top - beforeBand.top) <= 1
                && Math.abs(afterBand.bottom - beforeBand.bottom) <= 1
            ))
        ));
        if (missingRuleBand) {
            throw new Error(
                `Moved source shifted or erased rule band ${JSON.stringify(missingRuleBand)}; `
                + `after=${JSON.stringify(afterPixels.horizontalRuleBands)}`,
            );
        }
        if (afterPixels.interiorDark > 0) {
            throw new Error(`Moved source left ${afterPixels.interiorDark} dark glyph pixels at its original position.`);
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
