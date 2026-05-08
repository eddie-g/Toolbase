#!/usr/bin/env node
/**
 * Test that the eraser tool can delete shape annotations in /edit-new.
 *
 * The test:
 *   1. Logs in, creates a blank document, opens /edit-new
 *   2. Injects a shape annotation using the test harness
 *   3. Switches to eraser mode
 *   4. Clicks on the shape with the eraser
 *   5. Verifies the shape annotation was deleted
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
    const response = await page.evaluate(async () => {
        const r = await fetch(`/pdf-tests/create-blank?page_size=Letter&orientation=portrait`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const raw = await r.text();
        let body = null;
        try { body = JSON.parse(raw); } catch (_e) {}
        return { ok: r.ok, status: r.status, body, rawBody: raw };
    });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 400)}`);
    }
    return Number(response.body.document_id);
}

async function main() {
    console.log(`[eraser-shapes] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    const page = await context.newPage();

    try {
        await loginAdmin(page);
        console.log('[eraser-shapes] logged in');

        const documentId = await createBlankDoc(page);
        console.log(`[eraser-shapes] blank doc id=${documentId}`);

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { 
            waitUntil: 'domcontentloaded', 
            timeout: 60000 
        });
        
        // Wait for the editor to be fully loaded
        await page.waitForFunction(() => {
            const state = window.__editorTestState;
            const canvas = document.getElementById('oc-1');
            return state && canvas && state.pageData && Object.keys(state.pageData).length > 0;
        }, { timeout: 30000 });
        
        await page.waitForTimeout(1500);
        console.log('[eraser-shapes] editor loaded');

        // Inject a shape annotation
        const shapeResult = await page.evaluate(() => {
            const state = window.__editorTestState;
            const pageData = state.pageData['0'];
            
            if (!pageData) return { error: 'Page data not found' };
            
            const testShape = {
                _uid: 'test_shape_eraser_' + Date.now(),
                id: state.generateAnnotationId?.() || ('id_' + Date.now()),
                pageIndex: 0,
                type: 'shape',
                shapeType: 'rectangle',
                pdfX: 150,
                pdfY: 400,
                pdfWidth: 200,
                pdfHeight: 100,
                userCreated: true,
                strokeColor: '#dc2626',
                strokeOpacity: 1,
                strokeWidth: 3,
                strokeTransparent: false,
                fillColor: '#fca5a5',
                fillOpacity: 0.3,
                fillTransparent: false,
                text: 'Test Shape'
            };
            
            testShape._originalBox = { x: testShape.pdfX, y: testShape.pdfY, w: testShape.pdfWidth, h: testShape.pdfHeight };
            testShape._originalPdfBox = { x: testShape.pdfX, y: testShape.pdfY, w: testShape.pdfWidth, h: testShape.pdfHeight };
            
            pageData.annotations.push(testShape);
            state.redrawOverlay(0);
            
            return { 
                shapeUid: testShape._uid,
                annotationCountBefore: pageData.annotations.length
            };
        });

        assert(shapeResult.shapeUid, 'shape injection', `uid=${shapeResult.shapeUid}`);
        console.log(`[eraser-shapes] injected shape, before count: ${shapeResult.annotationCountBefore}`);

        // Switch to eraser mode
        const eraserState = await page.evaluate(() => {
            const btn = document.getElementById('ftb-draw-erase');
            if (!btn) return { error: 'ftb-draw-erase button not found' };
            
            // Simulate the click
            btn.click();
            
            // Give it a moment to process
            return new Promise(resolve => {
                setTimeout(() => {
                    const drawPanel = document.getElementById('draw-tool-panel');
                    const eraserBtn = document.querySelector('[data-draw-direct-tool="eraser"]');
                    resolve({
                        drawPanelVisible: drawPanel?.classList?.contains('is-visible'),
                        eraserButtonExists: !!eraserBtn,
                        annotationCount: window.__editorTestState?.pageData?.['0']?.annotations?.length
                    });
                }, 500);
            });
        });

        assert(eraserState.drawPanelVisible, 'eraser mode activated (draw panel visible)');
        console.log(`[eraser-shapes] eraser mode activated: panel visible=${eraserState.drawPanelVisible}`);

        // Click on the eraser button in the draw panel
        const eraserBtnResult = await page.evaluate(() => {
            const eraserBtn = document.querySelector('[data-draw-direct-tool="eraser"]');
            if (!eraserBtn) return { error: 'eraser button not found' };
            eraserBtn.click();
            return { eraserButtonClicked: true };
        });

        assert(!eraserBtnResult.error, 'eraser button clicked');
        console.log(`[eraser-shapes] eraser button clicked`);

        // Now click on the shape annotation to delete it
        const deleteResult = await page.evaluate(() => {
            return new Promise(resolve => {
                setTimeout(() => {
                    const state = window.__editorTestState;
                    const pageData = state.pageData['0'];
                    const scale = pageData.scale;
                    const oc = document.getElementById('oc-1');
                    const canvasHeight = oc.clientHeight;
                    
                    // Shape is at pdfX=150, pdfY=400, pdfWidth=200, pdfHeight=100
                    // Calculate center in canvas coordinates
                    const pdfCenterX = 150 + 100;  // 250
                    const pdfCenterY = 400 + 50;   // 450
                    const canvasX = pdfCenterX * scale;
                    const canvasY = canvasHeight - pdfCenterY * scale;
                    
                    // Create and dispatch pointer event
                    const rect = oc.getBoundingClientRect();
                    const event = new PointerEvent('pointerdown', {
                        bubbles: true,
                        cancelable: true,
                        clientX: rect.left + canvasX,
                        clientY: rect.top + canvasY,
                        pointerId: 123,
                        isPrimary: true,
                        buttons: 1,
                        button: 0,
                    });
                    
                    oc.dispatchEvent(event);
                    
                    // Give the event time to process
                    setTimeout(() => {
                        resolve({
                            annotationCountAfter: pageData.annotations.length,
                            remainingShapes: pageData.annotations.filter(a => a.type === 'shape').length
                        });
                    }, 200);
                }, 100);
            });
        });

        assert(deleteResult.annotationCountAfter === 0, 'shape deleted', `count after=${deleteResult.annotationCountAfter}`);
        assert(deleteResult.remainingShapes === 0, 'no shapes remain', `remaining=${deleteResult.remainingShapes}`);
        console.log(`[eraser-shapes] after eraser click: ${deleteResult.annotationCountAfter} annotations, ${deleteResult.remainingShapes} shapes`);

    } catch (err) {
        console.error('[eraser-shapes] fatal:', err.message);
        failures.push({ label: 'fatal', detail: err.message });
    } finally {
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n[eraser-shapes] FAILED (${failures.length})`);
        process.exit(1);
    } else {
        console.log('\n[eraser-shapes] PASSED');
    }
}

main();
