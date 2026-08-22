#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5293);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const TARGET_ID = 'promoted_3_4';
const TARGET_SELECTOR = `.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`;
const LINK_TEXT = 'Legal Alien';
const LINK_URL = 'https://openai.com/research';

function normalizedText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    for (const password of Array.from(new Set([
        ADMIN_PASSWORD,
        'TestPwd123!',
        'codex-test-admin-2861',
        'password1',
    ].filter(Boolean)))) {
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
    throw new Error('Unable to log in for the PDF.js hyperlink regression.');
}

async function selectText(page, selector, needle) {
    await page.locator(`${selector} .enpv-text-content`).evaluate((root, selectedText) => {
        const fullText = String(root.textContent || '');
        const startOffset = fullText.indexOf(selectedText);
        if (startOffset < 0) throw new Error(`Could not find ${selectedText}`);
        const endOffset = startOffset + selectedText.length;
        const pointAtOffset = (target) => {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let total = 0;
            let node;
            while ((node = walker.nextNode())) {
                const length = String(node.nodeValue || '').length;
                if (target <= total + length) return { node, offset: target - total };
                total += length;
            }
            return { node: root, offset: root.childNodes.length };
        };
        const start = pointAtOffset(startOffset);
        const end = pointAtOffset(endOffset);
        const range = document.createRange();
        range.setStart(start.node, start.offset);
        range.setEnd(end.node, end.offset);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
    }, needle);
}

async function linkState(page) {
    return page.locator(TARGET_SELECTOR).evaluate((box) => {
        const root = box.querySelector('.enpv-text-content');
        const anchor = root?.querySelector('a[href]');
        const style = anchor ? getComputedStyle(anchor) : null;
        return {
            text: String(root?.textContent || ''),
            anchorText: String(anchor?.textContent || ''),
            href: anchor?.href || '',
            color: style?.color || '',
            decoration: style?.textDecorationLine || '',
            editing: box.classList.contains('is-editing'),
        };
    });
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    let savePayload = null;
    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            const request = route.request();
            if (request.method() === 'GET') return route.continue();
            if (request.method() === 'POST' && request.postData()) {
                try {
                    const payload = request.postDataJSON();
                    if (Array.isArray(payload?.annotations)) savePayload = payload;
                } catch (_) { /* not an annotation request */ }
            }
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, session_id: 'hyperlink-regression' }),
            });
        });
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
        await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 3 }));
        await page.locator('.pdfViewer .page[data-page-number="3"]').scrollIntoViewIfNeeded();
        await page.locator('#ftb-edit-mode').click();

        const target = page.locator(TARGET_SELECTOR).first();
        await target.waitFor({ state: 'attached', timeout: 90000 });
        await target.scrollIntoViewIfNeeded();
        await target.click({ force: true });
        await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
        await page.waitForSelector(`${TARGET_SELECTOR}.is-editing`, { timeout: 10000 });

        const before = await linkState(page);
        await selectText(page, TARGET_SELECTOR, LINK_TEXT);
        await page.waitForFunction(() => !document.querySelector('#afb-link-apply')?.disabled);
        await page.locator('#afb-link-url').fill('openai.com/research');
        await page.locator('#afb-link-apply').click();
        const applied = await linkState(page);

        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        const deselected = await linkState(page);

        await page.locator('#save-btn').evaluate((button) => button.click());
        await page.waitForFunction(() => document.querySelector('#save-status')?.textContent?.includes('Saved'));
        const savedAnnotation = savePayload?.annotations?.find((annotation) => annotation.id === TARGET_ID) || null;
        const linkedRuns = (savedAnnotation?.richTextRuns || []).filter((run) => run.linkUrl);

        await target.click({ force: true });
        await page.locator('#enpv-ann-menu [data-action="edit"]').click({ force: true });
        await page.waitForSelector(`${TARGET_SELECTOR}.is-editing`, { timeout: 10000 });
        await selectText(page, TARGET_SELECTOR, LINK_TEXT);
        await page.evaluate(() => {
            const selection = window.getSelection();
            const selectedRect = selection?.rangeCount ? selection.getRangeAt(0).getBoundingClientRect() : null;
            const pageDiv = document.querySelector('.pdfViewer .page[data-page-number="3"]');
            const annotationLayer = pageDiv?.querySelector(':scope > .annotationLayer');
            if (!selectedRect || !annotationLayer) throw new Error('Could not create source-link regression hotspot.');
            const layerRect = annotationLayer.getBoundingClientRect();
            const host = document.createElement('section');
            host.id = 'synthetic-source-link';
            host.className = 'linkAnnotation';
            host.style.position = 'absolute';
            host.style.left = `${selectedRect.left - layerRect.left}px`;
            host.style.top = `${selectedRect.top - layerRect.top}px`;
            host.style.width = `${selectedRect.width}px`;
            host.style.height = `${selectedRect.height}px`;
            const anchor = document.createElement('a');
            anchor.href = 'http://www.socialsecurity.gov/';
            anchor.title = anchor.href;
            anchor.style.display = 'block';
            anchor.style.width = '100%';
            anchor.style.height = '100%';
            host.appendChild(anchor);
            annotationLayer.appendChild(host);
        });
        await page.waitForFunction(() => !document.querySelector('#afb-link-remove')?.disabled);
        await page.locator('#afb-link-remove').click();
        const removed = await linkState(page);
        const removedSourceHotspot = await page.locator('#synthetic-source-link').evaluate((host) => ({
            hidden: getComputedStyle(host).display === 'none',
            markedRemoved: host.dataset.enpvRemovedPdfLink === '1',
        }));
        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
        savePayload = null;
        await page.locator('#save-btn').evaluate((button) => button.click());
        await page.waitForTimeout(250);
        const unlinkedAnnotation = savePayload?.annotations?.find((annotation) => annotation.id === TARGET_ID) || null;
        const remainingLinkedRuns = (unlinkedAnnotation?.richTextRuns || []).filter((run) => run.linkUrl);
        const removedLinkRects = unlinkedAnnotation?.pdfjsRemovedLinkRects || [];

        const checks = {
            text_is_unchanged:
                normalizedText(before.text) === normalizedText(applied.text)
                && normalizedText(before.text) === normalizedText(deselected.text),
            selected_text_is_blue_and_underlined:
                applied.anchorText === LINK_TEXT
                && applied.href === LINK_URL
                && applied.color === 'rgb(5, 99, 193)'
                && applied.decoration.includes('underline'),
            hyperlink_survives_deselect:
                !deselected.editing
                && deselected.anchorText === LINK_TEXT
                && deselected.href === LINK_URL,
            save_payload_keeps_destination:
                linkedRuns.map((run) => run.text).join('') === LINK_TEXT
                && linkedRuns.every((run) => run.linkUrl === LINK_URL),
            remove_action_clears_link:
                removed.anchorText === ''
                && normalizedText(removed.text) === normalizedText(before.text)
                && remainingLinkedRuns.length === 0,
            remove_action_hides_source_pdf_hotspot:
                removedSourceHotspot.hidden
                && removedSourceHotspot.markedRemoved
                && removedLinkRects.length > 0,
        };
        const failed = Object.entries(checks).filter(([, pass]) => !pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            annotationId: TARGET_ID,
            states: { before, applied, deselected, removed },
            linkedRuns,
            remainingLinkedRuns,
            removedLinkRects,
            removedSourceHotspot,
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
