#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 2861);
const MOVE_DX = Number(process.env.MOVE_DX || 36);
const MOVE_DY = Number(process.env.MOVE_DY || 18);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const SESSION_ID = `codex-edit-new-${DOCUMENT_ID}-${crypto.randomUUID()}`;
const SESSION_KEY = `edit_new_session_${DOCUMENT_ID}`;

async function fetchInfo(page) {
    const payload = await page.evaluate(async ({ baseUrl, documentId, sessionId }) => {
        const response = await fetch(`${baseUrl}/pdf-tests/document/${documentId}/info?session_id=${encodeURIComponent(sessionId)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return {
            ok: response.ok,
            status: response.status,
            body: await response.json().catch(() => ({})),
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

function chooseTargetAnnotation(info) {
    const annotations = Array.isArray(info?.annotations) ? info.annotations : [];
    const candidates = annotations.filter((annotation) => {
        const pageHeight = Number(annotation?.sourcePageHeight) || 0;
        const pdfX = Number(annotation?.pdfX);
        const pdfY = Number(annotation?.pdfY);
        const pdfWidth = Number(annotation?.pdfWidth);
        const pdfHeight = Number(annotation?.pdfHeight);
        return String(annotation?.type || 'text') === 'text'
            && (Number(annotation?.pageIndex) || 0) === 0
            && Number.isFinite(pdfX)
            && Number.isFinite(pdfY)
            && Number.isFinite(pdfWidth)
            && Number.isFinite(pdfHeight)
            && pdfWidth >= 80
            && pdfHeight >= 10
            && pageHeight > 0
            && pdfX >= 60
            && pdfX <= 420
            && pdfY >= 120
            && pdfY <= (pageHeight - 120);
    });
    const target = candidates.sort((a, b) => (Number(b?.pdfWidth) || 0) - (Number(a?.pdfWidth) || 0))[0];

    if (!target) {
        throw new Error('No movable page-1 text annotation found in document info.');
    }

    return {
        id: String(target.id || ''),
        text: String(target.text || ''),
        pageIndex: Number(target.pageIndex) || 0,
        pdfX: Number(target.pdfX) || 0,
        pdfY: Number(target.pdfY) || 0,
        pdfWidth: Number(target.pdfWidth) || 0,
        pdfHeight: Number(target.pdfHeight) || 0,
        sourcePageHeight: Number(target.sourcePageHeight) || 0,
    };
}

async function annotationClientPoint(page, target) {
    const point = await page.evaluate((annotation) => {
        const canvas = document.getElementById(`oc-${annotation.pageIndex + 1}`);
        if (!(canvas instanceof HTMLCanvasElement)) {
            return null;
        }
        const rect = canvas.getBoundingClientRect();
        if (!rect.width || !rect.height || !annotation.sourcePageHeight) {
            return null;
        }
        const scale = rect.height / annotation.sourcePageHeight;
        const topPts = annotation.sourcePageHeight - (annotation.pdfY + annotation.pdfHeight);
        return {
            x: rect.left + ((annotation.pdfX + (annotation.pdfWidth / 2)) * scale),
            y: rect.top + ((topPts + (annotation.pdfHeight / 2)) * scale),
        };
    }, target);

    if (!point || !Number.isFinite(point.x) || !Number.isFinite(point.y)) {
        throw new Error(`Failed to resolve annotation client point for ${JSON.stringify(target)}`);
    }

    return point;
}

async function isMoveHandleVisible(page, pageIndex) {
    const handle = page.locator(`#mh-${pageIndex + 1}`);
    return handle.isVisible().catch(() => false);
}

function findAnnotationById(info, id) {
    const annotations = Array.isArray(info?.annotations) ? info.annotations : [];
    return annotations.find((annotation) => String(annotation?.id || '') === id) || null;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    await context.addInitScript(({ key, value }) => {
        window.localStorage.setItem(key, value);
    }, { key: SESSION_KEY, value: SESSION_ID });
    const page = await context.newPage();

    let lastSaveResponse = null;
    let lastSaveRequest = null;
    page.on('response', async (response) => {
        if (response.request().method() === 'POST' && response.url().includes('/save-annotation-state')) {
            try {
                lastSaveRequest = response.request().postDataJSON();
            } catch (_error) {
                lastSaveRequest = response.request().postData() || null;
            }
            try {
                lastSaveResponse = {
                    status: response.status(),
                    body: await response.json(),
                };
            } catch (_error) {
                lastSaveResponse = { status: response.status(), body: null };
            }
        }
    });

    try {
        await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', ADMIN_PASSWORD);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new`, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelectorAll('.page-content').length > 0);
        await page.waitForTimeout(2500);

        const beforeInfo = await fetchInfo(page);
        const target = chooseTargetAnnotation(beforeInfo);

        const clickPoint = await annotationClientPoint(page, target);
        await page.mouse.click(clickPoint.x, clickPoint.y);
        await page.waitForTimeout(300);

        const moveHandle = page.locator(`#mh-${target.pageIndex + 1}`);
        await moveHandle.waitFor({ state: 'visible', timeout: 5000 });
        const moveBox = await moveHandle.boundingBox();
        if (!moveBox) {
            throw new Error('Move handle did not expose a bounding box.');
        }

        await page.mouse.move(moveBox.x + (moveBox.width / 2), moveBox.y + (moveBox.height / 2));
        await page.mouse.down();
        await page.mouse.move(
            moveBox.x + (moveBox.width / 2) + MOVE_DX,
            moveBox.y + (moveBox.height / 2) + MOVE_DY,
            { steps: 12 }
        );
        await page.mouse.up();
        await page.waitForTimeout(300);
        const movedHandleBox = await moveHandle.boundingBox();

        await page.getByRole('button', { name: 'Save' }).click();
        await page.waitForTimeout(1200);

        const afterDragInfo = await fetchInfo(page);
        const moved = findAnnotationById(afterDragInfo, target.id);
        if (!moved) {
            throw new Error(`Moved annotation ${target.id} not found after drag save.`);
        }

        const blankPoint = await page.evaluate((annotation) => {
            const canvas = document.getElementById(`oc-${annotation.pageIndex + 1}`);
            if (!(canvas instanceof HTMLCanvasElement)) {
                return null;
            }
            const rect = canvas.getBoundingClientRect();
            return {
                x: rect.left + 20,
                y: rect.top + 20,
            };
        }, target);
        if (!blankPoint) {
            throw new Error('Failed to resolve blank click point.');
        }

        const movedClickPoint = await annotationClientPoint(page, {
            ...target,
            pdfX: Number(moved.pdfX) || 0,
            pdfY: Number(moved.pdfY) || 0,
            pdfWidth: Number(moved.pdfWidth) || 0,
            pdfHeight: Number(moved.pdfHeight) || 0,
        });

        await page.mouse.click(blankPoint.x, blankPoint.y);
        await page.waitForTimeout(300);
        const selectableAfterClear = !(await isMoveHandleVisible(page, target.pageIndex));

        await page.mouse.click(movedClickPoint.x, movedClickPoint.y);
        await page.waitForTimeout(300);
        const reselectedAfterMove = await isMoveHandleVisible(page, target.pageIndex);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelectorAll('.page-content').length > 0);
        await page.waitForTimeout(2500);

        const afterReloadInfo = await fetchInfo(page);
        const reloaded = findAnnotationById(afterReloadInfo, target.id);
        if (!reloaded) {
            throw new Error(`Moved annotation ${target.id} not found after reload.`);
        }

        const result = {
            target_id: target.id,
            target_text: target.text,
            before: {
                pdfX: target.pdfX,
                pdfY: target.pdfY,
                pdfWidth: target.pdfWidth,
                pdfHeight: target.pdfHeight,
            },
            after_drag: {
                pdfX: Number(moved.pdfX) || 0,
                pdfY: Number(moved.pdfY) || 0,
                pdfWidth: Number(moved.pdfWidth) || 0,
                pdfHeight: Number(moved.pdfHeight) || 0,
            },
            after_reload: {
                pdfX: Number(reloaded.pdfX) || 0,
                pdfY: Number(reloaded.pdfY) || 0,
                pdfWidth: Number(reloaded.pdfWidth) || 0,
                pdfHeight: Number(reloaded.pdfHeight) || 0,
            },
            save_response: lastSaveResponse,
            save_request: lastSaveRequest,
            move_handle_before: moveBox,
            move_handle_after: movedHandleBox,
            selectable_after_clear: selectableAfterClear,
            reselected_after_move: reselectedAfterMove,
        };

        result.delta_after_drag = {
            dx: result.after_drag.pdfX - result.before.pdfX,
            dy: result.after_drag.pdfY - result.before.pdfY,
        };
        result.delta_after_reload = {
            dx: result.after_reload.pdfX - result.before.pdfX,
            dy: result.after_reload.pdfY - result.before.pdfY,
        };
        result.drag_vs_reload = {
            dx: result.after_reload.pdfX - result.after_drag.pdfX,
            dy: result.after_reload.pdfY - result.after_drag.pdfY,
        };

        console.log(JSON.stringify(result, null, 2));

        const movedMaterially = Math.abs(result.delta_after_drag.dx) >= 3 || Math.abs(result.delta_after_drag.dy) >= 3;
        if (!movedMaterially) {
            throw new Error(`Annotation did not move materially after drag: ${JSON.stringify(result, null, 2)}`);
        }

        const reloadedMatchesDrag = Math.abs(result.drag_vs_reload.dx) <= 1 && Math.abs(result.drag_vs_reload.dy) <= 1;
        if (!reloadedMatchesDrag) {
            throw new Error(`Reloaded position diverged from saved drag position: ${JSON.stringify(result, null, 2)}`);
        }

        if (!reselectedAfterMove) {
            throw new Error(`Moved annotation was not selectable after drag: ${JSON.stringify(result, null, 2)}`);
        }
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
