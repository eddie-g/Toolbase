#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { performance } = require('perf_hooks');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4455);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const PDF_HOLD_MS = Math.max(500, Number(process.env.PDF_HOLD_MS || 2000));
const READY_AFTER_PDF_BUDGET_MS = Math.max(
    5000,
    Number(process.env.READY_AFTER_PDF_BUDGET_MS || 5000),
);

const endpointPaths = {
    pdf: `/documents/${DOCUMENT_ID}/file`,
    fonts: `/documents/${DOCUMENT_ID}/fonts`,
    info: `/pdf-tests/document/${DOCUMENT_ID}/info`,
};

function requestKind(rawUrl) {
    const pathname = new URL(rawUrl).pathname.replace(/\/$/, '');
    return Object.entries(endpointPaths).find(([, expected]) => pathname === expected)?.[0] || '';
}

function isGeneralAppScript(rawUrl) {
    const pathname = new URL(rawUrl).pathname;
    const filename = pathname.split('/').pop() || '';
    return filename === 'app.js' || /^app-[A-Za-z0-9_-]+\.js$/.test(filename);
}

function isExternalPdfjsAsset(rawUrl) {
    const url = new URL(rawUrl);
    const localOrigin = new URL(BASE_URL).origin;
    if (url.origin === localOrigin) return false;
    return /pdfjs-dist|pdf(?:\.worker)?(?:\.min)?\.m?js/i.test(`${url.hostname}${url.pathname}`);
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

    throw new Error('Unable to log in for doc4455 PDF.js startup regression.');
}

function relativeMs(value, origin) {
    return Number.isFinite(value) ? Math.round((value - origin) * 10) / 10 : null;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const loginPage = await context.newPage();
    let page = null;
    const requests = [];
    const requestRecords = new Map();
    const milestones = {
        navigationStartedAt: NaN,
        pdfStartedAt: NaN,
        pdfHeldAt: NaN,
        pdfReleasedAt: NaN,
        pdfResponseAt: NaN,
        pdfFinishedAt: NaN,
        fontsStartedAt: NaN,
        fontsResponseAt: NaN,
        fontsFinishedAt: NaN,
        infoStartedAt: NaN,
        infoResponseAt: NaN,
        infoFinishedAt: NaN,
        viewerReadyAt: NaN,
    };
    const blockedNonGetRequests = [];
    const checks = [];
    const check = (name, pass, detail = null) => checks.push({ name, pass: Boolean(pass), detail });

    try {
        await loginAdmin(loginPage);
        page = await context.newPage();
        await loginPage.close();

        page.on('request', (request) => {
            const startedAt = performance.now();
            const kind = requestKind(request.url());
            const record = {
                url: request.url(),
                method: request.method(),
                resourceType: request.resourceType(),
                kind,
                startedAt,
                responseAt: NaN,
                finishedAt: NaN,
                failure: '',
            };
            requests.push(record);
            requestRecords.set(request, record);
            if (kind && !Number.isFinite(milestones[`${kind}StartedAt`])) {
                milestones[`${kind}StartedAt`] = startedAt;
            }
        });
        page.on('response', (response) => {
            const receivedAt = performance.now();
            const request = response.request();
            const record = requestRecords.get(request);
            if (record) record.responseAt = receivedAt;
            const kind = record?.kind || requestKind(request.url());
            if (kind && !Number.isFinite(milestones[`${kind}ResponseAt`])) {
                milestones[`${kind}ResponseAt`] = receivedAt;
            }
        });
        page.on('requestfinished', (request) => {
            const finishedAt = performance.now();
            const record = requestRecords.get(request);
            if (record) record.finishedAt = finishedAt;
            const kind = record?.kind || requestKind(request.url());
            if (kind && !Number.isFinite(milestones[`${kind}FinishedAt`])) {
                milestones[`${kind}FinishedAt`] = finishedAt;
            }
        });
        page.on('requestfailed', (request) => {
            const record = requestRecords.get(request);
            if (record) record.failure = request.failure()?.errorText || 'request failed';
        });

        // The regression is deliberately read-only. Holding the PDF response
        // gives the editor entry module a deterministic window in which to
        // start its independent annotation-info and embedded-font requests.
        await page.route('**/*', async (route) => {
            const request = route.request();
            if (request.method() !== 'GET') {
                blockedNonGetRequests.push({ method: request.method(), url: request.url() });
                await route.abort('blockedbyclient');
                return;
            }

            if (requestKind(request.url()) === 'pdf' && !Number.isFinite(milestones.pdfHeldAt)) {
                milestones.pdfHeldAt = performance.now();
                await new Promise((resolve) => setTimeout(resolve, PDF_HOLD_MS));
                milestones.pdfReleasedAt = performance.now();
            }
            await route.continue();
        });

        milestones.navigationStartedAt = performance.now();
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForFunction(() => document.body.classList.contains('enpv-viewer-loading'));
        const actionsWhileLoading = await page.evaluate(() => ({
            saveDisabled: document.querySelector('#save-btn')?.disabled === true,
            downloadDisabled: document.querySelector('#enpv-download')?.disabled === true,
            topDownloadDisabled: document.querySelector('#download-pdf-btn')?.disabled === true,
        }));
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"] canvas', {
            timeout: 120000,
        });
        milestones.viewerReadyAt = performance.now();
        await page.waitForSelector('.pdfViewer .page[data-page-number="1"] .enpv-annotation-box', {
            timeout: 30000,
        });

        const viewer = await page.evaluate(() => {
            const pageElement = document.querySelector('.pdfViewer .page[data-page-number="1"]');
            const annotationBoxes = Array.from(
                document.querySelectorAll('.pdfViewer .page .enpv-annotation-box[data-annotation-id]'),
            );
            return {
                ready: document.body.classList.contains('enpv-viewer-ready'),
                zoomLabel: document.querySelector('#zoom-label')?.textContent?.trim() || '',
                viewerScale: Number(window.__enpv?.pdfViewer?.currentScale || 0),
                pageScale: Number(pageElement?.dataset?.scale || 0),
                canvas: {
                    width: pageElement?.querySelector('canvas')?.width || 0,
                    height: pageElement?.querySelector('canvas')?.height || 0,
                },
                annotationCount: annotationBoxes.length,
                annotationIds: annotationBoxes.map((box) => box.dataset.annotationId || ''),
                actions: {
                    saveDisabled: document.querySelector('#save-btn')?.disabled === true,
                    downloadDisabled: document.querySelector('#enpv-download')?.disabled === true,
                    topDownloadDisabled: document.querySelector('#download-pdf-btn')?.disabled === true,
                },
                scriptSources: Array.from(document.scripts)
                    .map((script) => script.src)
                    .filter(Boolean),
            };
        });

        const endpointRequests = Object.fromEntries(
            Object.keys(endpointPaths).map((kind) => [
                kind,
                requests.filter((request) => request.kind === kind).map((request) => ({
                    method: request.method,
                    url: request.url,
                    startedAt: relativeMs(request.startedAt, milestones.navigationStartedAt),
                    responseAt: relativeMs(request.responseAt, milestones.navigationStartedAt),
                    finishedAt: relativeMs(request.finishedAt, milestones.navigationStartedAt),
                    failure: request.failure,
                })),
            ]),
        );
        const forbiddenPdfjsRequests = requests
            .filter((request) => isExternalPdfjsAsset(request.url))
            .map((request) => request.url);
        const generalAppScriptRequests = requests
            .filter((request) => request.resourceType === 'script' && isGeneralAppScript(request.url))
            .map((request) => request.url);
        const generalAppDomScripts = viewer.scriptSources.filter(isGeneralAppScript);

        check('initial_pdf_request_started', endpointRequests.pdf.length >= 1, endpointRequests.pdf);
        const initialInfoParams = endpointRequests.info.length >= 1
            ? new URL(endpointRequests.info[0].url).searchParams
            : null;
        check('initial_annotation_request_loads_complete_safe_snapshot',
            initialInfoParams
                && initialInfoParams.get('skip_embedded_fonts') === '1'
                && !initialInfoParams.has('page')
                && !initialInfoParams.has('pages_exclude')
                && !initialInfoParams.has('skip_meta'),
            endpointRequests.info[0] || null);
        check('annotation_info_started_before_held_pdf_was_released',
            Number.isFinite(milestones.infoStartedAt)
                && milestones.infoStartedAt >= milestones.navigationStartedAt
                && milestones.infoStartedAt < milestones.pdfReleasedAt,
            endpointRequests.info);
        check('embedded_fonts_started_before_held_pdf_was_released',
            Number.isFinite(milestones.fontsStartedAt)
                && milestones.fontsStartedAt >= milestones.navigationStartedAt
                && milestones.fontsStartedAt < milestones.pdfReleasedAt,
            endpointRequests.fonts);
        check('metadata_requests_started_before_pdf_response_became_available',
            Number.isFinite(milestones.pdfResponseAt)
                && milestones.infoStartedAt < milestones.pdfResponseAt
                && milestones.fontsStartedAt < milestones.pdfResponseAt,
            {
                pdfResponseAt: relativeMs(milestones.pdfResponseAt, milestones.navigationStartedAt),
                infoStartedAt: relativeMs(milestones.infoStartedAt, milestones.navigationStartedAt),
                fontsStartedAt: relativeMs(milestones.fontsStartedAt, milestones.navigationStartedAt),
            });
        check('pdf_was_held_for_deterministic_parallelism_probe',
            milestones.pdfReleasedAt - milestones.pdfHeldAt >= PDF_HOLD_MS - 100,
            relativeMs(milestones.pdfReleasedAt - milestones.pdfHeldAt, 0));
        check('no_external_pdfjs_asset_loaded', forbiddenPdfjsRequests.length === 0, forbiddenPdfjsRequests);
        check('general_app_entry_script_not_loaded',
            generalAppScriptRequests.length === 0 && generalAppDomScripts.length === 0,
            { requests: generalAppScriptRequests, dom: generalAppDomScripts });
        check('viewer_reached_ready_state', viewer.ready && viewer.canvas.width > 0 && viewer.canvas.height > 0, viewer);
        check('save_and_download_are_locked_until_full_hydration',
            actionsWhileLoading.saveDisabled
                && actionsWhileLoading.downloadDisabled
                && actionsWhileLoading.topDownloadDisabled,
            actionsWhileLoading);
        check('save_and_download_unlock_after_full_hydration',
            !viewer.actions.saveDisabled
                && !viewer.actions.downloadDisabled
                && !viewer.actions.topDownloadDisabled,
            viewer.actions);
        check('viewer_ready_promptly_after_delayed_pdf_completed',
            Number.isFinite(milestones.pdfReleasedAt)
                && milestones.viewerReadyAt - milestones.pdfReleasedAt <= READY_AFTER_PDF_BUDGET_MS,
            {
                elapsedMs: relativeMs(milestones.viewerReadyAt - milestones.pdfReleasedAt, 0),
                budgetMs: READY_AFTER_PDF_BUDGET_MS,
            });
        check('initial_zoom_remains_190_percent',
            viewer.zoomLabel === '190%'
                && Math.abs(viewer.viewerScale - 1.9) < 0.001,
            { zoomLabel: viewer.zoomLabel, viewerScale: viewer.viewerScale, pageScale: viewer.pageScale });
        check('saved_annotations_are_present_at_ready',
            viewer.annotationCount > 0
                && viewer.annotationIds.some((id) => id.startsWith(`pdfjs_${DOCUMENT_ID}_`)),
            { count: viewer.annotationCount, ids: viewer.annotationIds });

        const result = {
            status: checks.every((item) => item.pass) ? 'pass' : 'fail',
            documentId: DOCUMENT_ID,
            readOnly: true,
            blockedNonGetRequests,
            timingsMsFromNavigation: {
                pdfStarted: relativeMs(milestones.pdfStartedAt, milestones.navigationStartedAt),
                pdfHeld: relativeMs(milestones.pdfHeldAt, milestones.navigationStartedAt),
                pdfReleased: relativeMs(milestones.pdfReleasedAt, milestones.navigationStartedAt),
                pdfResponse: relativeMs(milestones.pdfResponseAt, milestones.navigationStartedAt),
                pdfFinished: relativeMs(milestones.pdfFinishedAt, milestones.navigationStartedAt),
                fontsStarted: relativeMs(milestones.fontsStartedAt, milestones.navigationStartedAt),
                fontsResponse: relativeMs(milestones.fontsResponseAt, milestones.navigationStartedAt),
                fontsFinished: relativeMs(milestones.fontsFinishedAt, milestones.navigationStartedAt),
                infoStarted: relativeMs(milestones.infoStartedAt, milestones.navigationStartedAt),
                infoResponse: relativeMs(milestones.infoResponseAt, milestones.navigationStartedAt),
                infoFinished: relativeMs(milestones.infoFinishedAt, milestones.navigationStartedAt),
                viewerReady: relativeMs(milestones.viewerReadyAt, milestones.navigationStartedAt),
            },
            endpointRequests,
            viewer,
            checks,
        };
        console.log(JSON.stringify(result, null, 2));

        const failed = checks.filter((item) => !item.pass);
        if (failed.length) {
            throw new Error(`PDF.js startup regression failed: ${failed.map((item) => item.name).join(', ')}`);
        }
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
