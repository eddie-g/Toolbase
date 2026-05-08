#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 2873);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const SESSION_ID = `codex-edit-new-font-${DOCUMENT_ID}-${crypto.randomUUID()}`;
const SESSION_KEY = `edit_new_session_${DOCUMENT_ID}`;
const OUTPUT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
const TITLE_TEXT = 'Drylab News';

function ensureOutputDir() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

async function fetchInfo(page) {
    const payload = await page.evaluate(async ({ baseUrl, documentId, sessionId }) => {
        const response = await fetch(`${baseUrl}/pdf-tests/document/${documentId}/info?session_id=${encodeURIComponent(sessionId)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        let body = null;
        try {
            body = await response.json();
        } catch (_error) {
            body = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body,
        };
    }, {
        baseUrl: BASE_URL,
        documentId: DOCUMENT_ID,
        sessionId: SESSION_ID,
    });

    if (!payload.ok || !payload.body?.success) {
        throw new Error(`documentInfo failed: ${JSON.stringify(payload)}`);
    }

    return payload.body;
}

function findTitleAnnotation(info) {
    const annotations = Array.isArray(info?.annotations) ? info.annotations : [];
    return annotations.find((annotation) => (
        String(annotation?.text || '').trim() === TITLE_TEXT
        && (Number(annotation?.pageIndex) || 0) === 0
    )) || null;
}

async function annotationClientPoint(page, annotation) {
    const point = await page.evaluate((ann) => {
        const canvas = document.getElementById(`oc-${(Number(ann.pageIndex) || 0) + 1}`);
        if (!(canvas instanceof HTMLCanvasElement)) {
            return null;
        }

        const rect = canvas.getBoundingClientRect();
        const pageHeight = Number(ann.__sourcePdfPageHeight || ann.sourcePageHeight || 0);
        if (!rect.width || !rect.height || !pageHeight) {
            return null;
        }

        const scale = rect.height / pageHeight;
        const topPts = pageHeight - (Number(ann.pdfY || 0) + Number(ann.pdfHeight || 0));
        return {
            x: rect.left + ((Number(ann.pdfX || 0) + (Number(ann.pdfWidth || 0) / 2)) * scale),
            y: rect.top + ((topPts + (Number(ann.pdfHeight || 0) / 2)) * scale),
        };
    }, annotation);

    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) {
        throw new Error(`Failed to resolve annotation client point for ${JSON.stringify(annotation)}`);
    }

    return point;
}

async function readActiveEditorFonts(page) {
    return page.evaluate(() => {
        const editor = document.getElementById('ae-1');
        if (!editor || editor.style.display === 'none') return null;

        const spans = Array.from(editor.querySelectorAll('span'))
            .map((span) => {
                const text = String(span.textContent || '').replace(/\s+/g, ' ').trim();
                const style = window.getComputedStyle(span);
                return {
                    text,
                    fontFamily: style.fontFamily || '',
                    fontWeight: style.fontWeight || '',
                    fontStyle: style.fontStyle || '',
                };
            })
            .filter((span) => span.text);

        return {
            drylab: spans.find((span) => span.text === 'Drylab') || null,
            news: spans.find((span) => span.text === 'News') || null,
            romanLoaded: document.fonts && typeof document.fonts.check === 'function'
                ? document.fonts.check('normal 700 16px "PDF_MontserratThin_700wght"', 'Drylab')
                : null,
            thinLoaded: document.fonts && typeof document.fonts.check === 'function'
                ? document.fonts.check('normal 100 16px "PDF_Montserrat-Thin"', 'News')
                : null,
            html: editor.innerHTML,
        };
    });
}

async function main() {
    ensureOutputDir();

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1800 } });
    await context.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
    }, { key: SESSION_KEY, value: SESSION_ID });
    const page = await context.newPage();

    try {
        await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', ADMIN_PASSWORD);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForFunction(() => document.querySelectorAll('.page-content').length > 0, null, {
            timeout: 90000,
        });
        await page.waitForTimeout(2500);

        const info = await fetchInfo(page);
        const titleAnnotation = findTitleAnnotation(info);
        if (!titleAnnotation) {
            throw new Error(`missing "${TITLE_TEXT}" annotation in document info`);
        }

        await page.locator('#edit-mode-toggle').click();
        await page.waitForTimeout(400);

        const clickPoint = await annotationClientPoint(page, titleAnnotation);
        await page.mouse.click(clickPoint.x, clickPoint.y);
        await page.waitForFunction(() => {
            const editor = document.getElementById('ae-1');
            return Boolean(editor && editor.style.display !== 'none' && editor.querySelector('span'));
        }, null, { timeout: 30000 });
        await page.waitForTimeout(800);

        const actual = await readActiveEditorFonts(page);
        if (!actual) {
            throw new Error('failed to inspect active editor font state');
        }

        const screenshotPath = path.join(OUTPUT_DIR, `edit_new_drylab_font_exact_match_${DOCUMENT_ID}.png`);
        await page.screenshot({ path: screenshotPath, fullPage: true });

        const checks = [
            {
                item: 'title_editor_loaded_exact_bold_embedded_font',
                pass: actual.romanLoaded !== false,
                detail: { romanLoaded: actual.romanLoaded },
            },
            {
                item: 'title_editor_loaded_exact_thin_embedded_font',
                pass: actual.thinLoaded !== false,
                detail: { thinLoaded: actual.thinLoaded },
            },
            {
                item: 'drylab_span_uses_pdf_montserrat_bold_family',
                pass: String(actual.drylab?.fontFamily || '').includes('PDF_MontserratThin_700wght'),
                detail: actual.drylab,
            },
            {
                item: 'news_span_uses_pdf_montserrat_thin_family',
                pass: String(actual.news?.fontFamily || '').includes('PDF_Montserrat-Thin'),
                detail: actual.news,
            },
        ];

        const failed = checks.filter((check) => !check.pass);
        const result = {
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            checks,
            screenshotPath,
            actual,
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
