#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 5288);
const { requireAdminCredentials } = require('../../tools/admin-credentials.cjs');
// Resolved from AUTOMATED_TESTS_ADMIN_* (the QA admin), never hardcoded:
// a stale default here is what led to real admin passwords being reset.
const { email: ADMIN_EMAIL, password: ADMIN_PASSWORD } = requireAdminCredentials();
const ARTIFACT_DIR = String(process.env.ARTIFACT_DIR || '').trim();
const REPORTED_ANNOTATION_ID = `pdfjs_${DOCUMENT_ID}_0_0:7`;
const REPORTED_TEXT = 'Accelio Present Central 5.4';

function builtPdfjsEditorAsset() {
    const manifestPath = path.resolve(__dirname, '..', '..', 'public', 'build', 'manifest.json');
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    return manifest['resources/js/edit-new-pdfjs/main.js']?.file || '';
}

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

async function alignmentState(page, annotationId = REPORTED_ANNOTATION_ID, expectedText = REPORTED_TEXT) {
    return page.evaluate(({ annotationId, expectedText }) => {
        const comparable = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"]`);
        if (!box) return { error: `target text ${expectedText} missing` };
        const text = box.querySelector('.enpv-text-content');
        const targetText = comparable(text?.textContent);
        const page = box.closest('.page');
        const source = Array.from(page?.querySelectorAll?.('.textLayer span') || []).find((span) => (
            comparable(span.textContent) === targetText
        ));
        const rangeRect = (node) => {
            if (!node) return null;
            const range = document.createRange();
            range.selectNodeContents(node);
            const rects = Array.from(range.getClientRects())
                .filter((rect) => rect.width > 0 && rect.height > 0);
            range.detach?.();
            if (!rects.length) return null;
            return {
                left: Math.min(...rects.map((rect) => rect.left)),
                right: Math.max(...rects.map((rect) => rect.right)),
                top: Math.min(...rects.map((rect) => rect.top)),
                bottom: Math.max(...rects.map((rect) => rect.bottom)),
            };
        };
        const selection = window.getSelection();
        const selectedRange = selection?.rangeCount ? selection.getRangeAt(0) : null;
        const selectionRect = selectedRange && text?.contains(selectedRange.commonAncestorContainer)
            ? selectedRange.getBoundingClientRect().toJSON()
            : null;
        const style = text ? getComputedStyle(text) : null;
        return {
            uid: box.dataset.uid,
            annotationId: box.dataset.annotationId,
            text: targetText,
            editing: box.classList.contains('is-editing'),
            sourceFidelityEditing: box.dataset.sourceFidelityEditing || '',
            boundingBoxSnapped: box.dataset.sourceBoundingBoxSnapped || '',
            snapX: box.dataset.sourceBoundingBoxSnapX || '',
            snapY: box.dataset.sourceBoundingBoxSnapY || '',
            snapSource: box.dataset.sourceBoundingBoxSnapSource || '',
            snapStatus: box.dataset.sourceBoundingBoxSnapStatus || '',
            boxRect: box.getBoundingClientRect().toJSON(),
            sourceElementRect: source?.getBoundingClientRect?.().toJSON?.() || null,
            sourceRect: rangeRect(source),
            editorRect: rangeRect(text),
            selectionText: selection?.toString?.() || '',
            selectionRect,
            textStyle: style ? {
                position: style.position,
                left: style.left,
                top: style.top,
                fontFamily: style.fontFamily,
                fontSize: style.fontSize,
                lineHeight: style.lineHeight,
                transform: style.transform,
            } : null,
        };
    }, { annotationId, expectedText });
}

async function openPdfjsEditor(page) {
    await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
        waitUntil: 'domcontentloaded',
        timeout: 90000,
    });
    await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page canvas', { timeout: 90000 });
    await page.evaluate(() => window.__enpv?.pdfViewer?.scrollPageIntoView?.({ pageNumber: 1 }));
    await page.locator('.pdfViewer .page[data-page-number="1"]').scrollIntoViewIfNeeded();
    await page.locator('#ftb-edit-mode').click();
}

// Emit exactly two physical click cycles. The first cycle contains the small
// pointer movement that caused the reported jump; the second carries a click
// count of 2 so Chromium emits the real dblclick event that opens the editor.
async function wobblyDoubleClick(page, box, bounds, wobbleY = -2) {
    const pointerX = bounds.x + (bounds.width * 0.5);
    const pointerY = bounds.y + (bounds.height * 0.5);
    await page.mouse.move(pointerX, pointerY);
    await page.mouse.down({ clickCount: 1 });
    await page.mouse.move(pointerX, pointerY + wobbleY);
    await page.mouse.up({ clickCount: 1 });
    await page.waitForTimeout(60);
    await page.mouse.down({ clickCount: 2 });
    await page.mouse.up({ clickCount: 2 });
    await box.waitFor({ state: 'attached' });
}

async function verifyEveryRenderedTextAnnotationKeepsPosition(page) {
    const annotations = await page.evaluate(() => Array.from(document.querySelectorAll(
        '.pdfViewer .page[data-page-number="1"] .enpv-annotation-box',
    )).filter((box) => {
        const type = String(box.dataset.annotationType || 'text').toLowerCase();
        const rect = box.getBoundingClientRect();
        return Boolean(
            box.dataset.annotationId
            && box.querySelector('.enpv-text-content')
            && !['shape', 'signature', 'image', 'field'].includes(type)
            && box.dataset.locked !== '1'
            && !box.classList.contains('is-locked')
            && rect.width > 4
            && rect.height > 4
        );
    }).map((box) => ({
        annotationId: box.dataset.annotationId,
        text: String(box.querySelector('.enpv-text-content')?.textContent || '').replace(/\s+/g, ' ').trim(),
    })));

    const results = [];
    for (const annotation of annotations) {
        const selector = `.enpv-annotation-box[data-annotation-id="${annotation.annotationId}"]`;
        const box = page.locator(selector).first();
        await box.scrollIntoViewIfNeeded();
        const before = await box.boundingBox();
        if (!before) {
            results.push({ ...annotation, pass: false, error: 'missing pre-edit bounds' });
            continue;
        }
        const beforePixels = await innerBoxScreenshot(page, box);
        await wobblyDoubleClick(page, box, before);
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
        await box.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            if (text) text.style.caretColor = 'transparent';
            window.getSelection()?.removeAllRanges();
        });
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
        const after = await box.boundingBox();
        const editing = await box.evaluate((node) => node.classList.contains('is-editing'));
        const delta = before && after ? Math.max(
            Math.abs(before.x - after.x),
            Math.abs(before.y - after.y),
        ) : Number.POSITIVE_INFINITY;
        const afterPixels = after ? await innerBoxScreenshot(page, box) : null;
        const beforeInk = darkInkBounds(beforePixels);
        const afterInk = afterPixels ? darkInkBounds(afterPixels) : null;
        const inkVerticalDelta = beforeInk && afterInk
            ? Math.max(
                Math.abs(beforeInk.top - afterInk.top),
                Math.abs(beforeInk.bottom - afterInk.bottom),
            )
            : Number.POSITIVE_INFINITY;
        const inkTopDelta = beforeInk && afterInk
            ? Math.abs(beforeInk.top - afterInk.top)
            : null;
        const diagnostic = await box.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            const style = text ? getComputedStyle(text) : null;
            let editorRange = null;
            if (text) {
                const range = document.createRange();
                range.selectNodeContents(text);
                const rect = range.getBoundingClientRect();
                range.detach?.();
                editorRange = rect.toJSON();
            }
            return {
                className: node.className,
                uid: node.dataset.uid || '',
                sourceFidelityEditing: node.dataset.sourceFidelityEditing || '',
                sourceBoundingBoxSnapped: node.dataset.sourceBoundingBoxSnapped || '',
                sourceBoundingBoxSnapY: node.dataset.sourceBoundingBoxSnapY || '',
                sourceFontFamily: node.dataset.sourceFontFamily || '',
                sourceFontSizePx: node.dataset.sourceFontSizePx || '',
                sourceLineHeightPx: node.dataset.sourceLineHeightPx || '',
                sourceSpanRuns: node.dataset.sourceSpanRuns || '',
                editorRange,
                style: style ? {
                    fontFamily: style.fontFamily,
                    fontSize: style.fontSize,
                    lineHeight: style.lineHeight,
                    top: style.top,
                    transform: style.transform,
                } : null,
            };
        });
        results.push({
            ...annotation,
            // A different editable font outline can add a raster row to the
            // bottom of a glyph without moving its line. The reported jump is
            // positional, so assert the top ink origin (within one antialiasing
            // row) and keep the full extent delta as diagnostic information.
            pass: editing && delta <= 0.25 && (inkTopDelta == null || inkTopDelta <= 1),
            editing,
            positionDelta: delta,
            inkVerticalDelta,
            inkTopDelta,
            beforeInk,
            afterInk,
            diagnostic,
            before: before ? { x: before.x, y: before.y } : null,
            after: after ? { x: after.x, y: after.y } : null,
        });
        await page.keyboard.press('Escape');
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
    }
    return results;
}

function rectEdgeDelta(left, right) {
    if (!left || !right) return Number.POSITIVE_INFINITY;
    return Math.max(...['left', 'right', 'top', 'bottom']
        .map((edge) => Math.abs(Number(left[edge]) - Number(right[edge]))));
}

async function innerBoxScreenshot(page, target) {
    const bounds = await target.boundingBox();
    if (!bounds) throw new Error('Target annotation has no screenshot bounds.');
    const inset = 3;
    return page.screenshot({
        clip: {
            x: bounds.x + inset,
            y: bounds.y + inset,
            width: Math.max(1, bounds.width - (inset * 2)),
            height: Math.max(1, bounds.height - (inset * 2)),
        },
    });
}

function darkInkBounds(buffer) {
    const png = PNG.sync.read(buffer);
    let left = png.width;
    let right = -1;
    let top = png.height;
    let bottom = -1;
    for (let y = 0; y < png.height; y += 1) {
        for (let x = 0; x < png.width; x += 1) {
            const offset = ((y * png.width) + x) * 4;
            const red = png.data[offset];
            const green = png.data[offset + 1];
            const blue = png.data[offset + 2];
            const alpha = png.data[offset + 3];
            const neutral = Math.max(red, green, blue) - Math.min(red, green, blue) <= 20;
            if (alpha < 200 || !neutral || Math.max(red, green, blue) > 135) continue;
            left = Math.min(left, x);
            right = Math.max(right, x);
            top = Math.min(top, y);
            bottom = Math.max(bottom, y);
        }
    }
    return right >= left && bottom >= top ? { left, right, top, bottom } : null;
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 });
    const page = await context.newPage();
    try {
        await loginAdmin(page);
        await page.route('**/*', async (route) => {
            if (route.request().method() !== 'GET') return route.abort();
            return route.continue();
        });
        await openPdfjsEditor(page);
        const expectedEditorAsset = builtPdfjsEditorAsset();
        const loadedEditorAssets = await page.locator('script[src]').evaluateAll((scripts) => (
            scripts.map((script) => script.src)
        ));
        const loadedExpectedEditorAsset = Boolean(
            expectedEditorAsset
            && loadedEditorAssets.some((src) => src.includes(`/${expectedEditorAsset}`)),
        );
        const allTextAnnotationResults = await verifyEveryRenderedTextAnnotationKeepsPosition(page);
        const allTextAnnotationFailures = allTextAnnotationResults.filter((result) => !result.pass);

        // Reload so the exact reported case starts from pristine document state
        // after the annotation-agnostic sweep above.
        await openPdfjsEditor(page);
        const target = page.locator(`.enpv-annotation-box[data-annotation-id="${REPORTED_ANNOTATION_ID}"]`);
        await target.waitFor({ state: 'attached', timeout: 90000 });
        await target.scrollIntoViewIfNeeded();

        const loaded = await alignmentState(page);
        const loadedPixels = await innerBoxScreenshot(page, target);
        const targetBounds = await target.boundingBox();
        if (!targetBounds) throw new Error('Target annotation has no pointer bounds.');
        await wobblyDoubleClick(page, target, targetBounds);
        await page.waitForFunction((annotationId) => document.querySelector(
            `.enpv-annotation-box.is-editing[data-annotation-id="${CSS.escape(annotationId)}"]`,
        ), REPORTED_ANNOTATION_ID, {
            timeout: 10000,
        });
        const editingImmediate = await alignmentState(page);
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(
            () => requestAnimationFrame(resolve),
        )));
        const editing = await alignmentState(page);
        const editingSelectedPixels = await innerBoxScreenshot(page, target);
        const textElement = target.locator('.enpv-text-content');
        await textElement.press('End');
        await page.keyboard.type('X');
        await page.keyboard.press('Backspace');
        await page.evaluate((annotationId) => {
            const box = document.querySelector(
                `.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"]`,
            );
            const text = box?.querySelector('.enpv-text-content');
            if (text) text.style.caretColor = 'transparent';
            window.getSelection()?.removeAllRanges();
        }, REPORTED_ANNOTATION_ID);
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
        const editingAfterNoopMutation = await alignmentState(page);
        const editingPixels = await innerBoxScreenshot(page, target);
        if (ARTIFACT_DIR) {
            fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
            fs.writeFileSync(path.join(ARTIFACT_DIR, 'loaded.png'), loadedPixels);
            fs.writeFileSync(path.join(ARTIFACT_DIR, 'editing-selected.png'), editingSelectedPixels);
            fs.writeFileSync(path.join(ARTIFACT_DIR, 'editing-selection-cleared.png'), editingPixels);
        }
        const sourceEditorDelta = rectEdgeDelta(editing.sourceRect, editing.editorRect);
        const loadedInk = darkInkBounds(loadedPixels);
        const editingInk = darkInkBounds(editingPixels);
        const inkVerticalDelta = loadedInk && editingInk
            ? Math.max(
                Math.abs(loadedInk.top - editingInk.top),
                Math.abs(loadedInk.bottom - editingInk.bottom),
            )
            : Number.POSITIVE_INFINITY;
        await page.keyboard.press('Escape');
        await page.waitForFunction((annotationId) => {
            const box = document.querySelector(
                `.enpv-annotation-box[data-annotation-id="${CSS.escape(annotationId)}"]`,
            );
            return box && !box.classList.contains('is-editing');
        }, REPORTED_ANNOTATION_ID, { timeout: 10000 });
        const afterFirstEdit = await alignmentState(page);
        const afterFirstEditPixels = await innerBoxScreenshot(page, target);
        const reentryBounds = await target.boundingBox();
        if (!reentryBounds) throw new Error('Target annotation has no re-entry bounds.');
        await wobblyDoubleClick(page, target, reentryBounds);
        await page.waitForFunction((annotationId) => document.querySelector(
            `.enpv-annotation-box.is-editing[data-annotation-id="${CSS.escape(annotationId)}"]`,
        ), REPORTED_ANNOTATION_ID, { timeout: 10000 });
        await target.evaluate((node) => {
            const text = node.querySelector('.enpv-text-content');
            if (text) text.style.caretColor = 'transparent';
            window.getSelection()?.removeAllRanges();
        });
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
        const editingReentry = await alignmentState(page);
        const editingReentryPixels = await innerBoxScreenshot(page, target);
        const afterFirstEditInk = darkInkBounds(afterFirstEditPixels);
        const editingReentryInk = darkInkBounds(editingReentryPixels);
        const reentryInkVerticalDelta = afterFirstEditInk && editingReentryInk
            ? Math.max(
                Math.abs(afterFirstEditInk.top - editingReentryInk.top),
                Math.abs(afterFirstEditInk.bottom - editingReentryInk.bottom),
            )
            : Number.POSITIVE_INFINITY;
        const lifecycleRangeDeltas = {
            immediate: rectEdgeDelta(editingImmediate.sourceRect, editingImmediate.editorRect),
            settled: rectEdgeDelta(editing.sourceRect, editing.editorRect),
            afterNoopMutation: rectEdgeDelta(
                editingAfterNoopMutation.sourceRect,
                editingAfterNoopMutation.editorRect,
            ),
            reentry: rectEdgeDelta(editingReentry.sourceRect, editingReentry.editorRect),
        };
        const nativeSelectionTopDelta = editingImmediate.selectionRect && editingImmediate.sourceRect
            ? Math.abs(editingImmediate.selectionRect.top - editingImmediate.sourceRect.top)
            : Number.POSITIVE_INFINITY;
        const checks = [
            {
                item: 'browser_loaded_current_built_pdfjs_editor_asset',
                pass: loadedExpectedEditorAsset,
                detail: { expectedEditorAsset, loadedEditorAssets },
            },
            {
                item: 'target_is_reported_accelio_title',
                pass: loaded.text === REPORTED_TEXT,
                detail: loaded.text,
            },
            {
                item: 'every_rendered_text_annotation_keeps_position_on_edit_entry',
                pass: allTextAnnotationResults.length > 1 && allTextAnnotationFailures.length === 0,
                detail: {
                    tested: allTextAnnotationResults.length,
                    failures: allTextAnnotationFailures,
                },
            },
            {
                item: 'selection_does_not_move_annotation_box',
                pass: Math.abs(Number(loaded.boxRect?.top) - Number(editingImmediate.boxRect?.top)) <= 0.25,
                detail: { loaded: loaded.boxRect, editing: editingImmediate.boxRect },
            },
            {
                item: 'editable_glyph_range_matches_loaded_pdfjs_glyph_range',
                pass: sourceEditorDelta <= 0.5,
                detail: {
                    delta: sourceEditorDelta,
                    source: editing.sourceRect,
                    editor: editing.editorRect,
                },
            },
            {
                item: 'live_text_layer_snap_remains_active_through_edit_lifecycle',
                pass: [editingImmediate, editing, editingAfterNoopMutation, editingReentry]
                    .every((state) => (
                        state.boundingBoxSnapped === '1'
                        && state.snapSource === 'live-text-layer'
                        && state.snapStatus === 'applied'
                    ))
                    && Object.values(lifecycleRangeDeltas).every((delta) => delta <= 0.5),
                detail: {
                    rangeDeltas: lifecycleRangeDeltas,
                    snapSources: {
                        immediate: editingImmediate.snapSource,
                        settled: editing.snapSource,
                        afterNoopMutation: editingAfterNoopMutation.snapSource,
                        reentry: editingReentry.snapSource,
                    },
                },
            },
            {
                item: 'native_double_click_selection_stays_on_source_text_line',
                pass: Boolean(editingImmediate.selectionText)
                    && nativeSelectionTopDelta <= 0.5,
                detail: {
                    selectedText: editingImmediate.selectionText,
                    topDelta: nativeSelectionTopDelta,
                    selection: editingImmediate.selectionRect,
                    source: editingImmediate.sourceRect,
                },
            },
            {
                item: 'editable_ink_keeps_loaded_canvas_vertical_position',
                pass: inkVerticalDelta === 0,
                detail: { delta: inkVerticalDelta, loaded: loadedInk, editing: editingInk },
            },
            {
                item: 'text_does_not_move_when_reentering_a_previously_edited_annotation',
                pass: reentryInkVerticalDelta === 0,
                detail: {
                    delta: reentryInkVerticalDelta,
                    before: afterFirstEditInk,
                    editing: editingReentryInk,
                    beforeState: afterFirstEdit,
                    editingState: editingReentry,
                },
            },
        ];
        const failed = checks.filter((check) => !check.pass);
        console.log(JSON.stringify({
            status: failed.length ? 'fail' : 'pass',
            documentId: DOCUMENT_ID,
            targetUid: loaded.uid,
            checks,
            states: {
                loaded,
                editingImmediate,
                editing,
                editingAfterNoopMutation,
                afterFirstEdit,
                editingReentry,
            },
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
