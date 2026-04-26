#!/usr/bin/env node
/**
 * Regression test for the line-shape selection box in /edit-new.
 *
 * The selection box (#sb-{pageNum}) for line annotations must always sit
 * clearly OUTSIDE the visible stroke on all four sides — including for
 * axis-aligned (horizontal / vertical) lines whose PDF bbox is degenerate
 * (height==1pt for horizontal, width==1pt for vertical).
 *
 * The test:
 *   1. Logs in, creates a blank Letter/portrait document, opens /edit-new
 *   2. For each of {horizontal, vertical, diagonal} line geometries, programmatically
 *      injects a thick line annotation into pageData[0].annotations using the
 *      `__editorTestState` test harness, selects it, and reads the rendered
 *      #sb-1 bounding rect plus the actual canvas-space line endpoint coordinates.
 *   3. Asserts that every side of the selection rect clears the visible stroke
 *      (line ± strokeWidth/2) by at least MIN_GAP_PX on every side.
 *
 * Exits non-zero with a summary on any failure.
 */

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
const PAGE_SIZE = process.env.PAGE_SIZE || 'Letter';
const ORIENTATION = process.env.ORIENTATION || 'portrait';

// Selection-box clearance requirement: with the editor's clearance constant
// of (strokePx/2 + 14), every dashed border edge must be >=10px clear of the
// visible stroke (including caps). 10px gives slack for sub-pixel rounding,
// not the full 14 used in the source.
const MIN_GAP_PX = 10;

const failures = [];
function assert(cond, label, detail) {
    if (cond) {
        console.log(`  ✔ ${label}`);
    } else {
        console.log(`  ✘ ${label}${detail ? ` :: ${detail}` : ''}`);
        failures.push({ label, detail });
    }
}

async function loginAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#data\\.email', ADMIN_EMAIL);
    await page.fill('#data\\.password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
}

async function createBlankDoc(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const response = await page.evaluate(async ({ sz, or }) => {
        const r = await fetch(`/pdf-tests/create-blank?page_size=${encodeURIComponent(sz)}&orientation=${encodeURIComponent(or)}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const raw = await r.text();
        let body = null;
        try { body = JSON.parse(raw); } catch (_e) {}
        return { ok: r.ok, status: r.status, body, rawBody: raw };
    }, { sz: PAGE_SIZE, or: ORIENTATION });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 400)}`);
    }
    return Number(response.body.document_id);
}

/**
 * Inject a line annotation into pageData[0].annotations and select it.
 * Returns geometric snapshot used by assertions.
 *
 * `geometry` is one of 'horizontal' | 'vertical' | 'diagonal'.
 * `strokeWidth` is in PDF pts.
 */
async function probeLineSelection(page, geometry, strokeWidth) {
    return page.evaluate(({ geo, sw }) => {
        const state = window.__editorTestState;
        if (!state) throw new Error('window.__editorTestState missing');
        const data = state.pageData[0];
        if (!data) throw new Error('pageData[0] missing');

        // Pick PDF-space coordinates that don't sit on the page edge.
        // The blank Letter doc is 612 x 792 pts. PDF y is bottom-origin.
        let x1, y1, x2, y2;
        if (geo === 'horizontal') {
            x1 = 100; y1 = 500; x2 = 400; y2 = 500;
        } else if (geo === 'vertical') {
            x1 = 200; y1 = 300; x2 = 200; y2 = 600;
        } else {
            x1 = 100; y1 = 300; x2 = 400; y2 = 600;
        }
        const left = Math.min(x1, x2);
        const bottom = Math.min(y1, y2);
        const width = Math.max(1, Math.abs(x2 - x1));
        const height = Math.max(1, Math.abs(y2 - y1));
        const ann = {
            _uid: 'test_line_' + geo + '_' + Date.now(),
            id: state.generateAnnotationId ? state.generateAnnotationId() : ('id_' + Date.now()),
            pageIndex: 0,
            type: 'shape',
            shapeType: 'line',
            pdfX: left,
            pdfY: bottom,
            pdfWidth: width,
            pdfHeight: height,
            userCreated: true,
            strokeColor: '#dc2626',
            strokeOpacity: 1,
            strokeWidth: sw,
            strokeTransparent: false,
            fillColor: '#22c55e',
            fillOpacity: 0,
            fillTransparent: true,
            lineStartX: (x1 - left) / width,
            lineStartY: (y1 - bottom) / height,
            lineEndX: (x2 - left) / width,
            lineEndY: (y2 - bottom) / height,
            text: '',
        };
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };

        // Drop any previously injected probe annotations so each run starts clean.
        data.annotations = data.annotations.filter((a) => !(a._uid && String(a._uid).startsWith('test_line_')));
        data.annotations.push(ann);
        state.redrawOverlay(0);
        state.selectAnnotation(ann, 0);

        const sb = document.getElementById('sb-1');
        const oc = document.getElementById('oc-1');
        if (!sb || !oc) throw new Error('missing #sb-1 or #oc-1');
        const sbRect = sb.getBoundingClientRect();
        const ocRect = oc.getBoundingClientRect();

        // Recompute the canvas-space line endpoints exactly the way
        // syncActiveEditor does (annRectPx + lineGeometry projection) so we
        // can independently verify the box clears the stroke.
        const scale = data.scale;
        const canvasH = oc.height / (window.devicePixelRatio || 1);
        const canvasHeightCss = oc.clientHeight;
        // Use clientHeight directly — that matches the CSS rect we measured.
        const ch = canvasHeightCss;
        const boxLeft = ann.pdfX * scale;
        const boxTop = ch - (ann.pdfY + ann.pdfHeight) * scale;
        const boxW = Math.max(2, ann.pdfWidth * scale);
        const boxH = Math.max(2, ann.pdfHeight * scale);
        const cx1 = boxLeft + boxW * ann.lineStartX;
        const cy1 = boxTop + boxH * (1 - ann.lineStartY);
        const cx2 = boxLeft + boxW * ann.lineEndX;
        const cy2 = boxTop + boxH * (1 - ann.lineEndY);
        const strokePx = Math.max(0, ann.strokeWidth * scale);
        const halfStroke = strokePx / 2;
        // Visible stroke bounds in canvas space (axis-aligned envelope of the
        // stroked path including round-cap extension).
        const strokeMinX = Math.min(cx1, cx2) - halfStroke;
        const strokeMaxX = Math.max(cx1, cx2) + halfStroke;
        const strokeMinY = Math.min(cy1, cy2) - halfStroke;
        const strokeMaxY = Math.max(cy1, cy2) + halfStroke;

        // Selection-box bounds in CANVAS-LOCAL coordinates (not page coords).
        const sbLeft = sbRect.left - ocRect.left;
        const sbTop = sbRect.top - ocRect.top;
        const sbRight = sbLeft + sbRect.width;
        const sbBottom = sbTop + sbRect.height;

        return {
            geometry: geo,
            strokeWidth: sw,
            scale,
            sbDisplay: window.getComputedStyle(sb).display,
            sbBorder: window.getComputedStyle(sb).borderTopStyle,
            sb: { left: sbLeft, top: sbTop, right: sbRight, bottom: sbBottom, width: sbRect.width, height: sbRect.height },
            stroke: { minX: strokeMinX, minY: strokeMinY, maxX: strokeMaxX, maxY: strokeMaxY, halfStroke },
            endpoints: { x1: cx1, y1: cy1, x2: cx2, y2: cy2 },
        };
    }, { geo: geometry, sw: strokeWidth });
}

async function probeTextSelectionAfterLine(page) {
    return page.evaluate(() => {
        const state = window.__editorTestState;
        if (!state) throw new Error('window.__editorTestState missing');
        state.setEditModeEnabled?.(true);

        const data = state.pageData[0];
        if (!data) throw new Error('pageData[0] missing');

        data.annotations = data.annotations.filter((a) => {
            const uid = String(a?._uid || '');
            return !uid.startsWith('test_line_') && !uid.startsWith('test_text_after_line_');
        });

        const line = {
            _uid: 'test_line_text_reset_' + Date.now(),
            id: state.generateAnnotationId ? state.generateAnnotationId() : ('id_' + Date.now()),
            pageIndex: 0,
            type: 'shape',
            shapeType: 'line',
            pdfX: 110,
            pdfY: 520,
            pdfWidth: 260,
            pdfHeight: 1,
            userCreated: true,
            strokeColor: '#2563eb',
            strokeOpacity: 1,
            strokeWidth: 8,
            strokeTransparent: false,
            fillColor: '#2563eb',
            fillOpacity: 0,
            fillTransparent: true,
            lineStartX: 0,
            lineStartY: 0,
            lineEndX: 1,
            lineEndY: 0,
            text: '',
        };
        line._originalBox = { x: line.pdfX, y: line.pdfY, w: line.pdfWidth, h: line.pdfHeight };
        line._originalPdfBox = { x: line.pdfX, y: line.pdfY, w: line.pdfWidth, h: line.pdfHeight };
        data.annotations.push(line);
        state.redrawOverlay(0);
        state.selectAnnotation(line, 0);

        const text = {
            _uid: 'test_text_after_line_' + Date.now(),
            id: state.generateAnnotationId ? state.generateAnnotationId() : ('id_' + Date.now() + '_text'),
            pageIndex: 0,
            type: 'text',
            pdfX: 120,
            pdfY: 450,
            pdfWidth: 140,
            pdfHeight: 24,
            text: 'text after line',
            originalText: 'text after line',
            fontSize: 12,
            fontFamily: 'Helvetica',
            textColor: '#111827',
            fontWeight: '400',
            fontStyle: 'normal',
            underline: false,
            textAlign: 'left',
            verticalAlign: 'top',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            opacity: 1,
            userCreated: true,
            sourceSpans: [],
            sourceLineBBoxes: [],
            sourceTextLines: [],
        };
        text._originalBox = { x: text.pdfX, y: text.pdfY, w: text.pdfWidth, h: text.pdfHeight };
        text._originalPdfBox = { x: text.pdfX, y: text.pdfY, w: text.pdfWidth, h: text.pdfHeight };
        data.annotations.push(text);
        state.editedTexts[text._uid] = text.text;
        state.redrawOverlay(0);
        state.selectAnnotation(text, 0);

        const sb = document.getElementById('sb-1');
        const oc = document.getElementById('oc-1');
        if (!sb || !oc) throw new Error('missing #sb-1 or #oc-1');
        const ocRect = oc.getBoundingClientRect();
        const scale = data.scale;
        const top = oc.clientHeight - (text.pdfY + text.pdfHeight) * scale;
        const left = text.pdfX * scale;
        const width = Math.max(2, text.pdfWidth * scale);
        const height = Math.max(18, text.pdfHeight * scale);

        const handles = ['nw', 'ne', 'sw', 'se'].map((dir) => {
            const el = document.getElementById(`rh-1-${dir}`);
            if (!el) throw new Error(`missing handle ${dir}`);
            const rect = el.getBoundingClientRect();
            return {
                expectedDir: dir,
                actualDir: el.dataset.dir || null,
                display: window.getComputedStyle(el).display,
                aria: el.getAttribute('aria-label'),
                left: rect.left - ocRect.left,
                top: rect.top - ocRect.top,
                width: rect.width,
                height: rect.height,
            };
        });

        return {
            sbDisplay: window.getComputedStyle(sb).display,
            sbBorder: window.getComputedStyle(sb).borderTopStyle,
            expected: {
                nw: { left: left - 6, top: top - 6 },
                ne: { left: left + width - 6, top: top - 6 },
                sw: { left: left - 6, top: top + height - 6 },
                se: { left: left + width - 6, top: top + height - 6 },
            },
            handles,
        };
    });
}

function verifyClearance(label, snapshot) {
    const { sb, stroke } = snapshot;
    assert(snapshot.sbDisplay !== 'none', `${label}: selection box visible`, `display=${snapshot.sbDisplay}`);
    assert(snapshot.sbBorder !== 'none' && snapshot.sbBorder !== '', `${label}: selection box has border`, `borderTopStyle=${snapshot.sbBorder}`);

    const leftGap = stroke.minX - sb.left;
    const rightGap = sb.right - stroke.maxX;
    const topGap = stroke.minY - sb.top;
    const bottomGap = sb.bottom - stroke.maxY;

    assert(leftGap >= MIN_GAP_PX, `${label}: left gap >= ${MIN_GAP_PX}px`, `leftGap=${leftGap.toFixed(2)}px`);
    assert(rightGap >= MIN_GAP_PX, `${label}: right gap >= ${MIN_GAP_PX}px`, `rightGap=${rightGap.toFixed(2)}px`);
    assert(topGap >= MIN_GAP_PX, `${label}: top gap >= ${MIN_GAP_PX}px`, `topGap=${topGap.toFixed(2)}px`);
    assert(bottomGap >= MIN_GAP_PX, `${label}: bottom gap >= ${MIN_GAP_PX}px`, `bottomGap=${bottomGap.toFixed(2)}px`);
}

function verifyTextHandlesAfterLine(snapshot) {
    assert(snapshot.sbDisplay !== 'none', 'text-after-line: selection box visible', `display=${snapshot.sbDisplay}`);
    assert(snapshot.sbBorder !== 'none' && snapshot.sbBorder !== '', 'text-after-line: selection box has border', `borderTopStyle=${snapshot.sbBorder}`);

    snapshot.handles.forEach((handle) => {
        const expected = snapshot.expected[handle.expectedDir];
        assert(handle.display !== 'none', `text-after-line: ${handle.expectedDir} handle visible`, `display=${handle.display}`);
        assert(handle.actualDir === handle.expectedDir, `text-after-line: ${handle.expectedDir} handle dir reset`, `actualDir=${handle.actualDir}`);
        assert(handle.aria === `Resize ${handle.expectedDir.toUpperCase()}`, `text-after-line: ${handle.expectedDir} aria reset`, `aria=${handle.aria}`);
        assert(Math.abs(handle.left - expected.left) <= 1.5, `text-after-line: ${handle.expectedDir} left restored`, `left=${handle.left.toFixed(2)} expected=${expected.left.toFixed(2)}`);
        assert(Math.abs(handle.top - expected.top) <= 1.5, `text-after-line: ${handle.expectedDir} top restored`, `top=${handle.top.toFixed(2)} expected=${expected.top.toFixed(2)}`);
    });
}

async function main() {
    console.log(`[line-selection] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    const page = await context.newPage();

    const consoleErrors = [];
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(`console.error: ${msg.text()}`); });

    try {
        await loginAdmin(page);
        console.log('[line-selection] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[line-selection] blank doc id=${documentId}`);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForFunction(() => !!document.getElementById('oc-1') && !!window.__editorTestState, null, { timeout: 30000 });
        await page.waitForTimeout(1500);

        const cases = [
            { geometry: 'horizontal', strokeWidth: 8 },
            { geometry: 'vertical',   strokeWidth: 8 },
            { geometry: 'diagonal',   strokeWidth: 8 },
            { geometry: 'horizontal', strokeWidth: 1 },
            { geometry: 'horizontal', strokeWidth: 24 },
        ];

        for (const { geometry, strokeWidth } of cases) {
            const label = `${geometry} stroke=${strokeWidth}pt`;
            console.log(`[line-selection] case: ${label}`);
            const snap = await probeLineSelection(page, geometry, strokeWidth);
            console.log('  snap:', JSON.stringify({
                sb: snap.sb,
                stroke: snap.stroke,
                endpoints: snap.endpoints,
                scale: snap.scale,
                sbDisplay: snap.sbDisplay,
            }));
            verifyClearance(label, snap);
        }

        console.log('[line-selection] case: text handles restore after line selection');
        const textAfterLine = await probeTextSelectionAfterLine(page);
        console.log('  text-after-line:', JSON.stringify(textAfterLine));
        verifyTextHandlesAfterLine(textAfterLine);

        if (consoleErrors.length) {
            console.log('[line-selection] console errors during run:');
            consoleErrors.slice(0, 10).forEach((e) => console.log('  ', e));
        }
    } catch (err) {
        console.error('[line-selection] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[line-selection] FAILED (${failures.length})`);
        process.exit(1);
    } else {
        console.log('\n[line-selection] PASSED');
    }
}

main();
