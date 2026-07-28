#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4455);
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
    throw new Error('Unable to log in for doc4455 edit-entry fidelity regression.');
}

async function openEditor(page) {
    await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });
    await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
    await page.locator('#ftb-edit-mode').click();
    await page.waitForTimeout(750);
}

async function viewerInvariant(page) {
    return page.evaluate(() => {
        const pageEl = document.querySelector('.pdfViewer .page[data-page-number="1"]');
        const canvas = pageEl?.querySelector('canvas');
        return {
            zoom: document.querySelector('#zoom-label')?.textContent?.trim() || '',
            scale: pageEl?.dataset?.scale || '',
            canvasWidth: canvas?.width || 0,
            canvasHeight: canvas?.height || 0,
        };
    });
}

async function enterEdit(page, selector) {
    const box = page.locator(selector).first();
    await box.waitFor({ state: 'attached', timeout: 90000 });
    await box.scrollIntoViewIfNeeded();
    const before = await box.evaluate((node) => {
        const rect = node.getBoundingClientRect();
        return { width: rect.width, height: rect.height };
    });
    await box.click({ force: true });
    await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
    await page.waitForSelector(`${selector}.is-editing`, { timeout: 10000 });
    await page.waitForTimeout(100);
    return { box, before };
}

async function promotedEntryState(box) {
    return box.evaluate((node) => {
        const boxRect = node.getBoundingClientRect();
        const text = node.querySelector('.enpv-text-content');
        const lines = Array.from(text.querySelectorAll('[data-source-span-line="1"]')).map((line) => {
            const rect = line.getBoundingClientRect();
            return {
                text: line.textContent || '',
                top: rect.top - boxRect.top,
                bottom: rect.bottom - boxRect.top,
                width: rect.width,
                clientRects: line.getClientRects().length,
                breakCount: Number(line.dataset.sourceLineBreakCount || 0),
            };
        });
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(text);
        return {
            width: rect.width,
            height: rect.height,
            text: text.textContent || '',
            whiteSpace: style.whiteSpace,
            scrollWidth: text.scrollWidth,
            clientWidth: text.clientWidth,
            scrollHeight: text.scrollHeight,
            clientHeight: text.clientHeight,
            pendingResize: node.dataset.pendingResize || '',
            lines,
        };
    });
}

function approximately(actual, expected, tolerance = 1) {
    return Math.abs(Number(actual) - Number(expected)) <= tolerance;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();
    const checks = [];
    const record = (item, pass, detail = null) => checks.push({ item, pass: Boolean(pass), detail });

    try {
        await loginAdmin(page);
        // Diagnostics must never mutate the saved document.
        await page.route('**/*', async (route) => {
            if (route.request().method() !== 'GET') return route.abort();
            return route.continue();
        });

        await openEditor(page);
        const firstCanvas = await page.locator('.pdfViewer .page[data-page-number="1"] canvas').elementHandle();
        const viewerBefore = await viewerInvariant(page);

        const scopeSelector = '.enpv-annotation-box[data-annotation-id="promoted_1_11"]';
        const { box: scopeBox, before: scopeBefore } = await enterEdit(page, scopeSelector);
        const scope = await promotedEntryState(scopeBox);
        const scopeDeltas = scope.lines.slice(1).map((line, index) => line.top - scope.lines[index].top);
        record('scope_has_seven_captured_rows', scope.lines.length === 7, scope.lines.length);
        record('scope_preserves_double_height_heading_break',
            approximately(scopeDeltas[0], 48.19, 0.8)
                && scopeDeltas.slice(1).every((delta) => approximately(delta, 24.09, 0.8)),
            scopeDeltas);
        record('scope_first_item_does_not_soft_wrap',
            scope.lines[1]?.clientRects === 1 && /Dome Vent\.$/.test(scope.lines[1]?.text || ''),
            scope.lines[1]);
        record('scope_edit_entry_only_absorbs_bounded_font_metric_drift',
            scope.width >= scopeBefore.width
                && scope.width - scopeBefore.width <= 4
                && scope.height >= scopeBefore.height
                && scope.height - scopeBefore.height <= 2,
            { before: scopeBefore, after: { width: scope.width, height: scope.height } });
        record('scope_edit_entry_does_not_mark_resize', scope.pendingResize === '', scope.pendingResize);

        const viewerAfterScope = await viewerInvariant(page);
        const sameCanvasAfterScope = await page.evaluate((canvas) => (
            canvas === document.querySelector('.pdfViewer .page[data-page-number="1"] canvas')
        ), firstCanvas);
        record('scope_edit_entry_does_not_rerender_or_zoom_pdf',
            sameCanvasAfterScope && JSON.stringify(viewerAfterScope) === JSON.stringify(viewerBefore),
            { before: viewerBefore, after: viewerAfterScope, sameCanvasAfterScope });

        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        const scopeAfterNoop = await scopeBox.evaluate((node) => {
            const rect = node.getBoundingClientRect();
            return {
                width: rect.width,
                height: rect.height,
                editing: node.classList.contains('is-editing'),
                pendingEdit: node.dataset.pendingEdit || '',
                pendingResize: node.dataset.pendingResize || '',
            };
        });
        record('scope_noop_exit_restores_exact_source_bounds',
            !scopeAfterNoop.editing
                && scopeAfterNoop.pendingEdit === ''
                && scopeAfterNoop.pendingResize === ''
                && approximately(scopeAfterNoop.width, scopeBefore.width, 0.25)
                && approximately(scopeAfterNoop.height, scopeBefore.height, 0.25),
            { before: scopeBefore, after: scopeAfterNoop });

        await openEditor(page);
        const workSelector = '.enpv-annotation-box[data-annotation-id="promoted_1_4"]';
        const { box: workBox, before: workBefore } = await enterEdit(page, workSelector);
        const work = await promotedEntryState(workBox);
        record('work_scope_stays_six_source_rows', work.lines.length === 6, work.lines.map((line) => line.text));
        record('work_scope_d_row_is_inside_blue_bounds',
            /^d\)/.test(work.lines[5]?.text || '') && work.lines[5].bottom <= work.height + 0.25,
            { last: work.lines[5], height: work.height });
        record('work_scope_entry_has_no_soft_wraps',
            work.lines.every((line) => line.clientRects === 1),
            work.lines.map((line) => line.clientRects));
        record('work_scope_bounds_expand_only_for_small_metric_drift',
            work.width >= workBefore.width
                && work.width - workBefore.width <= workBefore.width * 0.05
                && work.height >= workBefore.height
                && work.height - workBefore.height <= 6,
            { before: workBefore, after: { width: work.width, height: work.height } });

        const workText = workBox.locator('.enpv-text-content');
        const gapMutationReady = await workText.evaluate((text) => {
            const gap = text.querySelector('[data-source-span-gap="1"]');
            const node = gap?.firstChild;
            if (!gap || !node || node.nodeType !== Node.TEXT_NODE) return false;
            const range = document.createRange();
            range.setStart(node, Math.min(1, node.nodeValue.length));
            range.collapse(true);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            return true;
        });
        if (!gapMutationReady) throw new Error('Unable to place caret inside captured source gap');
        await page.keyboard.type('Z');
        await page.waitForTimeout(100);
        const afterGapTyping = await workBox.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            const selection = window.getSelection();
            return {
                text: text.textContent || '',
                scaffoldLines: text.querySelectorAll('[data-source-span-line="1"]').length,
                whiteSpace: getComputedStyle(text).whiteSpace,
                caretInside: Boolean(selection?.focusNode && text.contains(selection.focusNode)),
            };
        });
        record('first_character_typed_inside_source_gap_is_preserved',
            afterGapTyping.text.includes('Z')
                && afterGapTyping.scaffoldLines === 0
                && afterGapTyping.whiteSpace === 'pre-wrap'
                && afterGapTyping.caretInside,
            afterGapTyping);

        await workText.evaluate((text) => {
            const range = document.createRange();
            range.selectNodeContents(text);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        });
        await page.keyboard.type('XY');
        await page.waitForTimeout(100);
        const afterTyping = await workBox.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            const selection = window.getSelection();
            return {
                text: text.textContent || '',
                scaffoldLines: text.querySelectorAll('[data-source-span-line="1"]').length,
                whiteSpace: getComputedStyle(text).whiteSpace,
                scrollHeight: text.scrollHeight,
                clientHeight: text.clientHeight,
                caretInside: Boolean(selection?.focusNode && text.contains(selection.focusNode)),
            };
        });
        record('first_mutation_releases_scaffold_to_fitted_natural_flow',
            afterTyping.text.endsWith('XY')
                && afterTyping.scaffoldLines === 0
                && afterTyping.whiteSpace === 'pre-wrap'
                && afterTyping.scrollHeight <= afterTyping.clientHeight + 2
                && afterTyping.caretInside,
            afterTyping);

        await openEditor(page);
        const satelliteBox = page.locator('.enpv-annotation-box')
            .filter({ hasText: 'Satellite TV antennas will be removed' })
            .first();
        await satelliteBox.waitFor({ state: 'attached', timeout: 90000 });
        const satelliteLoaded = await satelliteBox.evaluate((node) => ({
            text: node.querySelector('.enpv-text-content')?.textContent || '',
            persistedId: node.dataset.persistedAnnotationId || '',
        }));
        const satelliteSelector = `.enpv-annotation-box[data-uid="${await satelliteBox.getAttribute('data-uid')}"]`;
        const { box: satelliteEditBox } = await enterEdit(page, satelliteSelector);
        const satellite = await satelliteEditBox.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            const range = document.createRange();
            range.selectNodeContents(text);
            const rows = new Set(Array.from(range.getClientRects()).map((rect) => Math.round(rect.top * 10) / 10));
            const gapBeforeT = Array.from(text.querySelectorAll('[data-source-span-gap="1"]')).some((gap) => (
                gap.nextElementSibling?.textContent === 'T' && gap.textContent === ' '
            ));
            return {
                text: text.textContent || '',
                rows: rows.size,
                whiteSpace: getComputedStyle(text).whiteSpace,
                gapBeforeT,
            };
        });
        record('satellite_explicit_space_survives_source_capture_and_edit',
            satelliteLoaded.text.startsWith('Satellite TV antennas')
                && satelliteLoaded.persistedId === 'promoted_1_6'
                && satellite.text.startsWith('Satellite TV antennas')
                && satellite.gapBeforeT,
            { loaded: satelliteLoaded, editing: satellite });
        record('satellite_source_row_does_not_wrap_on_edit',
            satellite.rows === 1 && satellite.whiteSpace === 'pre',
            satellite);

        const failed = checks.filter((check) => !check.pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            checks,
        }, null, 2));
        if (failed.length) process.exitCode = 1;
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
