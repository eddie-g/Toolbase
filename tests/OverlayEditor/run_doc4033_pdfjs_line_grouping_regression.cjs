#!/usr/bin/env node

'use strict';

const fs = require('fs');
const { spawnSync } = require('child_process');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4033);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

const EXPECTED = new Map([
    ['pdfjs_4033_0_0:31', '4 Customer file number (if applicable) (see instructions)'],
    ['pdfjs_4033_0_0:32', '5 Previous address shown on the last return filed if different from line 3 (see instructions)'],
]);
const MOVE_PROBE_ID = 'pdfjs_4033_0_0:31';
const LOWERCASE_LABEL_TEXT = 'a';
const RETURN_TRANSCRIPT_TEXT = 'Return Transcript, which includes most of the line items of a tax return as filed with the IRS. A tax return transcript does not reflect';

function approxEqual(left, right, tolerance = 0.02) {
    return Math.abs(Number(left) - Number(right)) <= tolerance;
}

function collapseWhitespace(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function payloadAnnotations(payload) {
    if (Array.isArray(payload?.annotations)) return payload.annotations;
    if (typeof payload?.annotations === 'string') {
        try {
            const parsed = JSON.parse(payload.annotations);
            if (Array.isArray(parsed)) return parsed;
        } catch (_) {}
    }
    return [];
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

    throw new Error('Unable to log in to admin for regression test.');
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
        await page.evaluate(() => {
            const toggle = document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode');
            if (!toggle) throw new Error('Missing PDF.js edit mode toggle');
            if (!document.body.classList.contains('enpv-edit-on')) toggle.click();
        });
        await page.waitForSelector('body.enpv-edit-on', { timeout: 10000 });
        await page.waitForSelector('.enpv-annotation-box-layer .enpv-annotation-box', { timeout: 90000 });
        await page.waitForTimeout(750);

        const rows = await page.evaluate(({ ids, returnTranscriptText }) => {
            const collapse = (value) => String(value || '').replace(/\s+/g, ' ').trim();
            return Array.from(
                document.querySelectorAll('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box'),
            )
                .filter((box) => {
                    const text = box.querySelector('.enpv-text-content')?.textContent || '';
                    return ids.includes(box.dataset.annotationId || '')
                        || collapse(text) === 'a'
                        || collapse(text) === returnTranscriptText;
                })
                .map((box) => ({
                    id: box.dataset.annotationId || '',
                    text: box.querySelector('.enpv-text-content')?.textContent || '',
                    original: box.dataset.originalText || '',
                }));
        }, {
            ids: Array.from(EXPECTED.keys()),
            returnTranscriptText: RETURN_TRANSCRIPT_TEXT,
        });

        for (const [id, expectedText] of EXPECTED) {
            const row = rows.find((candidate) => candidate.id === id);
            if (!row) throw new Error(`Missing PDF.js source row ${id}`);
            if (collapseWhitespace(row.text) !== expectedText || row.original !== expectedText) {
                throw new Error([
                    `Bad grouped text for ${id}`,
                    `expected: ${expectedText}`,
                    `actual text: ${row.text}`,
                    `actual original: ${row.original}`,
                ].join('\n'));
            }
        }
        const labelRow = rows.find((candidate) => collapseWhitespace(candidate.text) === LOWERCASE_LABEL_TEXT);
        if (!labelRow || labelRow.original !== LOWERCASE_LABEL_TEXT) {
            throw new Error('Lowercase line label "a" was not preserved as its own PDF.js source row.');
        }
        const transcriptRow = rows.find((candidate) => collapseWhitespace(candidate.text) === RETURN_TRANSCRIPT_TEXT);
        if (!transcriptRow || transcriptRow.original !== RETURN_TRANSCRIPT_TEXT) {
            throw new Error('Return Transcript row was not preserved as its own PDF.js source row.');
        }

        const moveProbe = page.locator(`.enpv-annotation-box[data-annotation-id="${MOVE_PROBE_ID}"]`).first();
        const beforeMove = await moveProbe.evaluate((box) => ({
            sourceX: Number(box.dataset.sourceBboxX),
            sourceY: Number(box.dataset.sourceBboxY),
            sourceW: Number(box.dataset.sourceBboxW),
            sourceH: Number(box.dataset.sourceBboxH),
            sourceText: box.dataset.baseText || '',
        }));
        const moveBox = await moveProbe.boundingBox();
        if (!moveBox) throw new Error(`Unable to locate move probe box ${MOVE_PROBE_ID}`);
        await page.evaluate(() => {
            window.__pdfjsMoveReloadProbe = { loadingToggled: false, redactionRequests: [] };
            const originalFetch = window.fetch.bind(window);
            window.fetch = (...args) => {
                const rawUrl = typeof args[0] === 'string' ? args[0] : (args[0]?.url || '');
                if (String(rawUrl).includes('/edit-pdfjs/redact-source-text')) {
                    window.__pdfjsMoveReloadProbe.redactionRequests.push(String(rawUrl));
                }
                return originalFetch(...args);
            };
            const observer = new MutationObserver(() => {
                if (document.body.classList.contains('enpv-viewer-loading')
                    || !document.body.classList.contains('enpv-viewer-ready')) {
                    window.__pdfjsMoveReloadProbe.loadingToggled = true;
                }
            });
            observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            window.__pdfjsMoveReloadProbe.disconnect = () => observer.disconnect();
        });
        await page.mouse.move(moveBox.x + (moveBox.width / 2), moveBox.y + (moveBox.height / 2));
        await page.mouse.down();
        await page.mouse.move(moveBox.x + (moveBox.width / 2) + 36, moveBox.y + (moveBox.height / 2), { steps: 5 });
        await page.mouse.up();
        await page.waitForTimeout(2500);
        const moveReloadProbe = await page.evaluate(() => {
            window.__pdfjsMoveReloadProbe?.disconnect?.();
            return {
                loadingToggled: Boolean(window.__pdfjsMoveReloadProbe?.loadingToggled),
                redactionRequests: Array.from(window.__pdfjsMoveReloadProbe?.redactionRequests || []),
            };
        });
        if (moveReloadProbe.loadingToggled || moveReloadProbe.redactionRequests.length) {
            throw new Error(`Moving a PDF.js annotation triggered a viewer reload/source redaction: ${JSON.stringify(moveReloadProbe)}`);
        }
        const afterMove = await moveProbe.evaluate((box) => ({
            sourceX: Number(box.dataset.sourceBboxX),
            sourceY: Number(box.dataset.sourceBboxY),
            sourceW: Number(box.dataset.sourceBboxW),
            sourceH: Number(box.dataset.sourceBboxH),
            sourceText: box.dataset.baseText || '',
            dxPts: Number(box.dataset.dxPts || 0),
        }));
        for (const key of ['sourceX', 'sourceY', 'sourceW', 'sourceH']) {
            if (!approxEqual(beforeMove[key], afterMove[key])) {
                throw new Error(`Drag changed immutable source ${key}: before=${beforeMove[key]} after=${afterMove[key]}`);
            }
        }
        if (beforeMove.sourceText !== afterMove.sourceText) {
            throw new Error('Drag changed immutable source text snapshot');
        }
        if (!(Math.abs(afterMove.dxPts) > 1)) {
            throw new Error('Move probe did not register a drag offset');
        }

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url().includes('/download-annotated-pdf')
        ), { timeout: 120000 });
        await page.locator('#download-pdf-btn').click();
        const request = await requestPromise;
        const payload = request.postDataJSON();
        const movedAnnotation = payloadAnnotations(payload).find((annotation) => String(annotation.id || '') === MOVE_PROBE_ID);
        if (!movedAnnotation) throw new Error(`Missing moved annotation ${MOVE_PROBE_ID} from download payload`);
        if (!approxEqual(movedAnnotation.pdfjsSourceX, beforeMove.sourceX)
            || !approxEqual(movedAnnotation.pdfjsSourceY, beforeMove.sourceY)
            || !approxEqual(movedAnnotation.pdfjsSourceW, beforeMove.sourceW)
            || !approxEqual(movedAnnotation.pdfjsSourceH, beforeMove.sourceH)
            || String(movedAnnotation.pdfjsSourceText || '') !== beforeMove.sourceText) {
            throw new Error('Download payload recomputed immutable source fields after drag');
        }
        if (approxEqual(movedAnnotation.pdfX, beforeMove.sourceX) && approxEqual(movedAnnotation.pdfY, beforeMove.sourceY)) {
            throw new Error('Download payload did not move current geometry after drag');
        }
        const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content').catch(() => '');
        const response = await page.request.post(request.url(), {
            data: payload,
            headers: {
                Accept: 'application/pdf, application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            timeout: 120000,
        });
        if (!response.ok()) {
            throw new Error(`Download PDF request failed: ${response.status()} ${await response.text().catch(() => '')}`);
        }
        const outputDir = path.resolve(__dirname, 'output');
        fs.mkdirSync(outputDir, { recursive: true });
        const outputPdf = path.join(outputDir, `doc4033_pdfjs_download_${Date.now()}.pdf`);
        fs.writeFileSync(outputPdf, await response.body());
        const inspect = spawnSync('python3', ['-c', `
import fitz, sys
doc = fitz.open(sys.argv[1])
page = doc[0]
april = page.search_for('(April 2025)')
title = [rect for rect in page.search_for('4506-T') if rect.y0 < 80 and rect.height > 20]
if not april:
    raise SystemExit('(April 2025) missing from downloaded PDF')
if not title:
    raise SystemExit('top 4506-T title missing or rendered at wrong size in downloaded PDF')
` , outputPdf], { encoding: 'utf8' });
        fs.rmSync(outputPdf, { force: true });
        if (inspect.status !== 0) {
            throw new Error(`Downloaded PDF inspection failed:\n${inspect.stdout || ''}${inspect.stderr || ''}`);
        }

        console.log('doc4033 pdfjs line grouping regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
