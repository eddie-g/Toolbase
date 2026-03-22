#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const TARGET_URL = 'http://localhost:8081/documents/1775/edit?loadedSavedPdf=1&savedSession=session_1773894471389_k2b1u2tv3';
const VIEWPORT = { width: 1800, height: 2400 };
const OUTPUT_DIR = path.join(__dirname, 'output');

function approxEqual(a, b, tolerance = 0.25) {
    return Math.abs(Number(a || 0) - Number(b || 0)) <= tolerance;
}

async function activateOverlay(page) {
    await page.evaluate(() => {
        const toggle = document.getElementById('mode-overlay-toggle');
        if (!toggle) {
            throw new Error('missing mode-overlay-toggle');
        }
        if (!toggle.checked) {
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    await page.waitForFunction(
        () => typeof overlayEditorActive !== 'undefined' && overlayEditorActive === true,
        null,
        { timeout: 120000 }
    );
    await page.waitForTimeout(15000);
}

async function readTargetParagraphState(page) {
    return page.evaluate(() => {
        const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const records = (typeof annotations !== 'undefined' && Array.isArray(annotations)) ? annotations : [];
        const target = records
            .filter((record) => (
                record?.type === 'text'
                && record?.savedTextOverlay
                && /lorem ipsum/i.test(normalize(record?.text))
                && typeof annotationRichTextHasFormatting === 'function'
                && annotationRichTextHasFormatting(record?.richTextHtml || '')
            ))
            .sort((a, b) => (Number(b?.pdfHeight) || 0) - (Number(a?.pdfHeight) || 0))[0];

        if (!target?.element) {
            return null;
        }

        const textEl = target.element.querySelector('.annotation-text');
        if (!textEl) {
            return null;
        }

        const style = window.getComputedStyle(textEl);
        const annotationRect = target.element.getBoundingClientRect();
        const textRect = textEl.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(textEl);
        const lineRects = Array.from(range.getClientRects());

        return {
            text: normalize(textEl.innerText || textEl.textContent),
            fontFamily: style.fontFamily,
            fontSizePx: parseFloat(style.fontSize || '0') || 0,
            lineHeightPx: parseFloat(style.lineHeight || '0') || 0,
            lineCount: lineRects.length,
            annotationRect: {
                x: annotationRect.x,
                y: annotationRect.y,
                width: annotationRect.width,
                height: annotationRect.height,
            },
            textRect: {
                width: textRect.width,
                height: textRect.height,
            },
            record: {
                id: target.id || null,
                fontSize: Number(target.fontSize) || 0,
                requestedFontSize: Number(target.requestedFontSize) || 0,
                lineHeight: Number(target.lineHeight) || 0,
                fontFamily: target.fontFamily || null,
            },
        };
    });
}

async function enterEditModeForTarget(page, textFragment) {
    const locator = page.locator('.annotation.has-bounds').filter({ hasText: textFragment }).first();
    await locator.waitFor({ timeout: 10000 });
    await locator.dispatchEvent('dblclick');
    await page.waitForSelector('.text-box-creator .tbc-input', { timeout: 10000 });
    await page.waitForTimeout(500);
}

async function readActiveEditorState(page) {
    return page.evaluate(() => {
        const input = document.querySelector('.text-box-creator .tbc-input');
        const box = document.querySelector('.text-box-creator');
        if (!input || !box) {
            return null;
        }

        const style = window.getComputedStyle(input);
        const boxRect = box.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(input);
        const lineRects = Array.from(range.getClientRects());
        const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();

        return {
            text: normalize(input.innerText || input.textContent),
            fontFamily: style.fontFamily,
            fontSizePx: parseFloat(style.fontSize || '0') || 0,
            lineHeightPx: parseFloat(style.lineHeight || '0') || 0,
            lineCount: lineRects.length,
            boxRect: {
                x: boxRect.x,
                y: boxRect.y,
                width: boxRect.width,
                height: boxRect.height,
            },
            padding: {
                top: parseFloat(style.paddingTop || '0') || 0,
                right: parseFloat(style.paddingRight || '0') || 0,
                bottom: parseFloat(style.paddingBottom || '0') || 0,
                left: parseFloat(style.paddingLeft || '0') || 0,
            },
            fontSizeScaleMode: input.dataset.fontSizeScaleMode || null,
        };
    });
}

async function main() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    const screenshotPath = path.join(OUTPUT_DIR, 'saved_pdf_1775_paragraph_edit_mode_regression.png');

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: VIEWPORT });

    try {
        await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await activateOverlay(page);

        const before = await readTargetParagraphState(page);
        if (!before) {
            throw new Error('failed to locate rich saved Lorem Ipsum paragraph before edit mode');
        }

        await enterEditModeForTarget(page, 'It is a long established fact that a reader will be distracted');

        const after = await readActiveEditorState(page);
        if (!after) {
            throw new Error('failed to locate active editor for rich saved Lorem Ipsum paragraph');
        }

        await page.screenshot({ path: screenshotPath, fullPage: true });

        const checks = [
            {
                item: 'paragraph_text_preserved',
                pass: after.text.includes(before.text.slice(0, 80)),
                detail: { beforeTextStart: before.text.slice(0, 120), afterTextStart: after.text.slice(0, 120) },
            },
            {
                item: 'editor_font_family_matches_display',
                pass: after.fontFamily === before.fontFamily,
                detail: { beforeFontFamily: before.fontFamily, afterFontFamily: after.fontFamily },
            },
            {
                item: 'editor_font_size_matches_display',
                pass: approxEqual(after.fontSizePx, before.fontSizePx, 0.25),
                detail: { beforeFontSizePx: before.fontSizePx, afterFontSizePx: after.fontSizePx, fontSizeScaleMode: after.fontSizeScaleMode },
            },
            {
                item: 'editor_line_height_matches_display',
                pass: approxEqual(after.lineHeightPx, before.lineHeightPx, 0.25),
                detail: { beforeLineHeightPx: before.lineHeightPx, afterLineHeightPx: after.lineHeightPx },
            },
            {
                item: 'editor_wrap_count_matches_display',
                pass: after.lineCount === before.lineCount,
                detail: { beforeLineCount: before.lineCount, afterLineCount: after.lineCount },
            },
            {
                item: 'editor_box_tracks_annotation_bounds',
                pass: approxEqual(after.boxRect.width, before.annotationRect.width, 2)
                    && approxEqual(after.boxRect.height, before.annotationRect.height, 2),
                detail: { beforeAnnotationRect: before.annotationRect, afterBoxRect: after.boxRect },
            },
        ];

        const failed = checks.filter((check) => !check.pass);
        const result = {
            status: failed.length ? 'fail' : 'pass',
            url: TARGET_URL,
            checks,
            before,
            after,
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
    console.error(error);
    process.exit(1);
});
