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
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TARGET_URL = `${BASE_URL}/documents/3267/edit-new`;
const TARGET_TEXT = 'the 2.05 MNOK loan from Innovation';

function approxEqual(left, right, tolerance = 0.75) {
    return Math.abs(Number(left || 0) - Number(right || 0)) <= tolerance;
}

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1500, height: 1400 } });

    try {
        await loginAdmin(page);
        await page.goto(TARGET_URL, { waitUntil: 'domcontentloaded', timeout: 120000 });
        await page.waitForFunction(() => {
            return !!window.__editorTestState
                && !!window.__editorTestState.pageData?.[0]
                && !!document.getElementById('ae-1');
        }, null, { timeout: 120000 });
        await page.waitForTimeout(2500);

        const state = await page.evaluate((targetText) => {
            const st = window.__editorTestState;
            const data = st.pageData[0];
            const ann = data.annotations.find((item) => String(item.text || '').includes(targetText));
            if (!ann) {
                throw new Error(`missing annotation containing ${targetText}`);
            }

            st.setEditModeEnabled(true);
            st.selectAnnotation(ann, 0);

            const ae = document.getElementById('ae-1');
            ae.focus({ preventScroll: true });

            const paragraph = ae.querySelector('[data-promoted-paragraph-editor="1"]');
            const paragraphStyle = paragraph ? window.getComputedStyle(paragraph) : null;
            const range = document.createRange();
            if (paragraph) range.selectNodeContents(paragraph);
            const rawLineRects = paragraph ? Array.from(range.getClientRects()).map((rect) => ({
                left: rect.left,
                top: rect.top,
                width: rect.width,
                height: rect.height,
            })) : [];
            const editorRect = ae.getBoundingClientRect();
            const lineRects = [];
            for (const rect of rawLineRects) {
                if (!lineRects.some((existing) => Math.abs(existing.top - rect.top) < 0.5)) {
                    lineRects.push(rect);
                }
            }
            const pageEl = document.getElementById('pc-1');
            const pageRect = pageEl?.getBoundingClientRect();
            const visualScale = pageRect && data.canvasWidth ? (pageRect.width / data.canvasWidth) : 1;
            const sourceTopDeltas = ann.sourceLineBBoxes.map((bbox) => (
                (Number(bbox[1]) - Number(ann.sourceLineBBoxes[0][1])) * data.scale * visualScale
            ));
            const rawActualTopDeltas = lineRects.map((rect) => rect.top - editorRect.top);
            const firstActualTop = rawActualTopDeltas[0] || 0;
            const actualTopDeltas = rawActualTopDeltas.map((top) => top - firstActualTop);

            return {
                uid: ann._uid,
                renderMode: ae.dataset.renderMode || null,
                canvasOwns: ae.dataset.canvasOwns || null,
                paragraphFound: Boolean(paragraph),
                paragraphText: String(paragraph?.textContent || ''),
                lineCount: lineRects.length,
                rawLineCount: rawLineRects.length,
                sourceLineCount: ann.sourceLineBBoxes.length,
                visualScale,
                sourceTopDeltas,
                actualTopDeltas,
                paragraphStyle: paragraphStyle ? {
                    fontFamily: paragraphStyle.fontFamily,
                    fontSize: paragraphStyle.fontSize,
                    lineHeight: paragraphStyle.lineHeight,
                    position: paragraphStyle.position,
                    whiteSpace: paragraphStyle.whiteSpace,
                } : null,
                htmlPreview: ae.innerHTML.slice(0, 500),
            };
        }, TARGET_TEXT);

        const checks = [
            {
                item: 'active_editor_uses_promoted_paragraph_layout',
                pass: state.renderMode === 'paragraph',
                detail: { renderMode: state.renderMode },
            },
            {
                item: 'paragraph_node_is_rendered',
                pass: state.paragraphFound === true
                    && !state.htmlPreview.includes('data-line-index')
                    && state.paragraphText.includes('Norway. Including the development agreement'),
                detail: {
                    paragraphFound: state.paragraphFound,
                    paragraphTextStart: state.paragraphText.slice(0, 160),
                    htmlPreview: state.htmlPreview,
                },
            },
            {
                item: 'paragraph_wraps_to_source_line_count',
                pass: state.lineCount === state.sourceLineCount,
                detail: { lineCount: state.lineCount, sourceLineCount: state.sourceLineCount },
            },
            {
                item: 'paragraph_line_tops_follow_pdf_source_leading',
                pass: state.actualTopDeltas.length === state.sourceTopDeltas.length
                    && state.actualTopDeltas.every((top, index) => approxEqual(top, state.sourceTopDeltas[index], 1.5)),
                detail: {
                    actualTopDeltas: state.actualTopDeltas,
                    sourceTopDeltas: state.sourceTopDeltas,
                    paragraphStyle: state.paragraphStyle,
                },
            },
        ];

        const failed = checks.filter((check) => !check.pass);
        const result = {
            status: failed.length ? 'fail' : 'pass',
            url: TARGET_URL,
            state,
            checks,
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
