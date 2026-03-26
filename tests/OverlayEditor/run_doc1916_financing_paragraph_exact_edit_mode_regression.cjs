#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const TARGET_URL = 'http://localhost:8081/documents/1916/edit?loadedSavedPdf=1&savedSession=session_1773894471389_k2b1u2tv3';
const OUTPUT_DIR = path.resolve(__dirname, 'output');
const TEXT_FRAGMENT = 'Third Party Financing Addendum attached to the contract';

function approxEqual(a, b, tolerance = 2) {
    return Math.abs(Number(a || 0) - Number(b || 0)) <= tolerance;
}

async function readDisplayState(page) {
    return page.evaluate(({ textFragment }) => {
        const target = Array.from(document.querySelectorAll('.annotation-text')).find((el) => {
            const text = String(el.textContent || '').replace(/\s+/g, ' ').trim();
            return text.includes(textFragment);
        });
        if (!target) {
            return null;
        }

        const wrapper = target.closest('.annotation') || target.parentElement?.parentElement || target.parentElement;
        const targetRect = target.getBoundingClientRect();
        const wrapperRect = wrapper?.getBoundingClientRect?.();
        const targetStyle = window.getComputedStyle(target);
        const exactLineCount = target.querySelectorAll('.annotation-exact-line').length;

        return {
            text: String(target.textContent || '').replace(/\s+/g, ' ').trim(),
            exactPromotedGeometry: target.dataset.exactPromotedGeometry || null,
            fontFamily: targetStyle.fontFamily,
            fontSizePx: parseFloat(targetStyle.fontSize || '0') || 0,
            lineHeightPx: parseFloat(targetStyle.lineHeight || '0') || 0,
            exactLineCount,
            innerHTML: target.innerHTML,
            wrapperRect: wrapperRect ? {
                x: wrapperRect.x,
                y: wrapperRect.y,
                width: wrapperRect.width,
                height: wrapperRect.height,
            } : null,
            textRect: {
                x: targetRect.x,
                y: targetRect.y,
                width: targetRect.width,
                height: targetRect.height,
            },
        };
    }, { textFragment: TEXT_FRAGMENT });
}

async function enterEditMode(page) {
    await page.locator('#mode-edit-text').click();
    await page.waitForTimeout(2000);
    const target = page.locator('.annotation-text').filter({ hasText: TEXT_FRAGMENT }).first();
    await target.waitFor({ timeout: 10000 });
    await target.dblclick({ force: true });
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.waitForTimeout(750);
}

async function readEditorState(page) {
    return page.evaluate(() => {
        const box = document.querySelector('.text-box-creator');
        const input = box?.querySelector('.tbc-input');
        if (!box || !input) {
            return null;
        }

        const boxRect = box.getBoundingClientRect();
        const inputRect = input.getBoundingClientRect();
        const style = window.getComputedStyle(input);

        return {
            editLayout: input.dataset.editLayout || null,
            annotationSplitParagraphFully: input.dataset.annotationSplitParagraphFully || null,
            fontFamily: style.fontFamily,
            fontSizePx: parseFloat(style.fontSize || '0') || 0,
            lineHeightPx: parseFloat(style.lineHeight || '0') || 0,
            paraCount: input.querySelectorAll('p.para-sel').length,
            charCount: input.querySelectorAll('span._iceni_text-sel').length,
            innerHTML: input.innerHTML,
            boxRect: {
                x: boxRect.x,
                y: boxRect.y,
                width: boxRect.width,
                height: boxRect.height,
            },
            inputRect: {
                x: inputRect.x,
                y: inputRect.y,
                width: inputRect.width,
                height: inputRect.height,
            },
        };
    });
}

async function main() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    const screenshotPath = path.join(OUTPUT_DIR, 'doc1916_financing_paragraph_exact_edit_mode.png');

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 2200 } });

    try {
        await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await page.waitForTimeout(12000);

        const before = await readDisplayState(page);
        if (!before) {
            throw new Error('failed to locate financing paragraph display node');
        }

        await enterEditMode(page);
        const after = await readEditorState(page);
        if (!after) {
            throw new Error('failed to locate active editor');
        }

        await page.screenshot({ path: screenshotPath, fullPage: true });

        const checks = [
            {
                item: 'display_uses_exact_promoted_geometry',
                pass: before.exactPromotedGeometry === '1' && before.exactLineCount >= 2,
                detail: {
                    exactPromotedGeometry: before.exactPromotedGeometry,
                    exactLineCount: before.exactLineCount,
                },
            },
            {
                item: 'display_font_family_is_verdana',
                pass: /verdana/i.test(before.fontFamily),
                detail: { fontFamily: before.fontFamily },
            },
            {
                item: 'editor_uses_split_exact_layout',
                pass: after.editLayout === 'split-paragraph-fully'
                    && after.annotationSplitParagraphFully === '1'
                    && after.paraCount >= 2
                    && after.charCount > 0,
                detail: {
                    editLayout: after.editLayout,
                    annotationSplitParagraphFully: after.annotationSplitParagraphFully,
                    paraCount: after.paraCount,
                    charCount: after.charCount,
                },
            },
            {
                item: 'editor_font_family_is_verdana',
                pass: /verdana/i.test(after.fontFamily),
                detail: { fontFamily: after.fontFamily },
            },
            {
                item: 'editor_rich_text_does_not_fall_back_to_arimo',
                pass: !/arimo/i.test(after.innerHTML),
                detail: { htmlPreview: after.innerHTML.slice(0, 400) },
            },
            {
                item: 'editor_box_tracks_display_bounds',
                pass: approxEqual(after.boxRect.width, before.wrapperRect?.width, 2)
                    && approxEqual(after.boxRect.height, before.wrapperRect?.height, 2),
                detail: {
                    beforeWrapperRect: before.wrapperRect,
                    afterBoxRect: after.boxRect,
                },
            },
        ];

        const failed = checks.filter((check) => !check.pass);
        const result = {
            status: failed.length ? 'fail' : 'pass',
            url: TARGET_URL,
            before,
            after,
            checks,
            screenshot: screenshotPath,
        };

        console.log(JSON.stringify(result, null, 2));
        if (failed.length) {
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
