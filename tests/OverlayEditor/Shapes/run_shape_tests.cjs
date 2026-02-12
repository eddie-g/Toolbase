/**
 * Shape Tool Browser Tests (Playwright)
 *
 * Tests the actual frontend shape drawing tool by:
 * 1. Creating a blank PDF document via API upload
 * 2. Opening the editor in a headless Chromium browser
 * 3. For each shape type: opening the shape modal, selecting the shape,
 *    configuring colors/stroke, clicking Apply, dragging on the page to draw,
 *    then clicking Save
 * 4. Downloading the saved PDF and validating with PyMuPDF that drawing
 *    operators exist in the content stream
 *
 * Usage:
 *   node run_shape_tests.cjs --list-files
 *   node run_shape_tests.cjs --single-shape <type>
 *   node run_shape_tests.cjs --run-all
 *
 * --run-all reuses a single browser instance across all shapes for speed.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Set permissive umask so all files created (PDFs, screenshots, etc.)
// are writable by any user (root, sail, www-data) on subsequent runs
process.umask(0o000);

// Ensure Playwright finds the browsers bundled in node_modules
// (avoids looking in ~/.cache/ms-playwright which may not exist in containers)
const localBrowsers = path.resolve(__dirname, '..', '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

// Always use localhost:80 inside the container — APP_URL may have the host-side port (8081)
const BASE_URL = 'http://localhost';
const SHAPES_DIR = path.resolve(__dirname);

/**
 * Write a file with 0666 permissions so any user (root, sail, www-data) can overwrite it later.
 */
function writeFileOpen(filePath, data) {
    fs.writeFileSync(filePath, data, { mode: 0o666 });
}

const SHAPE_TYPES = [
    {
        type: 'rect',
        label: 'Rectangle',
        stroke: '#000000',
        fill: '#3366cc',
        fillTransparent: false,
        strokeTransparent: false,
        strokeWidth: 2,
        expectedDrawingType: 'l',  // Frontend draws rects as line paths, not PDF 're' operator
    },
    {
        type: 'circle',
        label: 'Circle / Ellipse',
        stroke: '#000000',
        fill: '#cc3333',
        fillTransparent: false,
        strokeTransparent: false,
        strokeWidth: 2,
        expectedDrawingType: 'c',  // Bezier curves for circle/ellipse
    },
    {
        type: 'triangle',
        label: 'Triangle',
        stroke: '#000000',
        fill: '#33b344',
        fillTransparent: false,
        strokeTransparent: false,
        strokeWidth: 2,
        expectedDrawingType: 'l',  // PDF operator for lines
    },
    {
        type: 'arrow',
        label: 'Arrow',
        stroke: '#1a1a99',
        fill: '#000000',
        fillTransparent: true,
        strokeTransparent: false,
        strokeWidth: 3,
        expectedDrawingType: 'l',
    },
    {
        type: 'star',
        label: 'Star (5-pointed)',
        stroke: '#996600',
        fill: '#ffd700',
        fillTransparent: false,
        strokeTransparent: false,
        strokeWidth: 2,
        expectedDrawingType: 'l',
    },
    {
        type: 'checkmark',
        label: 'Checkmark',
        stroke: '#009900',
        fill: '#000000',
        fillTransparent: true,
        strokeTransparent: false,
        strokeWidth: 4,
        expectedDrawingType: 'l',
    },
    {
        type: 'polygon',
        label: 'Polygon (Hexagon)',
        stroke: '#800080',
        fill: '#cc99ff',
        fillTransparent: false,
        strokeTransparent: false,
        strokeWidth: 2,
        expectedDrawingType: 'l',
    },
    {
        type: 'x',
        label: 'X Mark',
        stroke: '#cc0000',
        fill: '#000000',
        fillTransparent: true,
        strokeTransparent: false,
        strokeWidth: 4,
        expectedDrawingType: 'l',
    },
];

/**
 * Helper: Build a check object in the format the blade template expects.
 * Blade reads: check.result ('PASS'/'FAIL'), check.item, check.description, check.detail
 */
function makeCheck(item, passed, description, detail) {
    return {
        result: passed ? 'PASS' : 'FAIL',
        item,
        description,
        detail: detail || '',
    };
}

/**
 * Wait for PDF editor to fully load (pages rendered in viewer).
 * Uses smart polling instead of a fixed 3000ms delay.
 */
async function waitForEditorLoad(page) {
    await page.waitForSelector('#viewer', { timeout: 30000 });
    await page.waitForSelector('#viewer .page[data-page-index="0"] canvas', { timeout: 30000 });
    // Wait until the canvas has actual pixel content (pdf.js rendered something)
    await page.waitForFunction(() => {
        const canvas = document.querySelector('#viewer .page[data-page-index="0"] canvas');
        if (!canvas) return false;
        return canvas.width > 0 && canvas.height > 0;
    }, { timeout: 10000 });
    // Brief settle time for UI event handlers to attach
    await page.waitForTimeout(500);
}

/**
 * Set a color input pair (type="color" + type="text" hex) via JS evaluate,
 * since Playwright can't natively set type="color" inputs.
 */
async function setColorInput(page, colorId, hexId, hexValue) {
    await page.evaluate(({ colorId, hexId, hexValue }) => {
        const colorEl = document.getElementById(colorId);
        const hexEl = document.getElementById(hexId);
        if (colorEl) {
            colorEl.value = hexValue;
            colorEl.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (hexEl) {
            hexEl.value = hexValue;
            hexEl.dispatchEvent(new Event('input', { bubbles: true }));
            hexEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, { colorId, hexId, hexValue });
}

/**
 * Set a range input value via JS evaluate.
 */
async function setRangeInput(page, inputId, value) {
    await page.evaluate(({ inputId, value }) => {
        const el = document.getElementById(inputId);
        if (el) {
            // Use the native setter to properly trigger React/JS listeners
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
                window.HTMLInputElement.prototype, 'value'
            ).set;
            nativeInputValueSetter.call(el, value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, { inputId, value: String(value) });
}

/**
 * Draw a shape on the page by:
 * 1. Opening the shape modal (#mode-shape)
 * 2. Selecting the shape type button
 * 3. Configuring stroke/fill colors via evaluate
 * 4. Setting checkbox states for transparent
 * 5. Setting stroke width (range input)
 * 6. Clicking "Apply & Draw Shape" (#shape-apply)
 * 7. Performing mouse drag on the page overlay to draw
 */
async function drawShape(page, shapeDef) {
    // Step 1: Open the shape modal
    await page.click('#mode-shape');
    // Wait for modal to be visible
    await page.waitForSelector('#shape-modal:not([aria-hidden="true"])', { timeout: 5000 }).catch(() => {
        // Modal might not use aria-hidden, wait a beat
    });
    await page.waitForTimeout(200);

    // Step 2: Select shape type
    const shapeBtn = `button.shape-type-btn[data-shape="${shapeDef.type}"]`;
    await page.waitForSelector(shapeBtn, { timeout: 5000 });
    await page.click(shapeBtn);
    await page.waitForTimeout(100);

    // Step 3: Configure stroke color
    await setColorInput(page, 'shape-stroke-color', 'shape-stroke-hex', shapeDef.stroke);

    // Step 4: Stroke transparent checkbox (default: unchecked = stroke visible)
    if (shapeDef.strokeTransparent) {
        const strokeTransChecked = await page.isChecked('#shape-stroke-transparent');
        if (!strokeTransChecked) await page.click('#shape-stroke-transparent');
    } else {
        const strokeTransChecked = await page.isChecked('#shape-stroke-transparent');
        if (strokeTransChecked) await page.click('#shape-stroke-transparent');
    }

    // Step 5: Fill transparent checkbox (default: checked = fill transparent)
    if (shapeDef.fillTransparent) {
        // Ensure fill-transparent IS checked
        const fillTransChecked = await page.isChecked('#shape-fill-transparent');
        if (!fillTransChecked) await page.click('#shape-fill-transparent');
    } else {
        // Uncheck fill-transparent, then set fill color
        const fillTransChecked = await page.isChecked('#shape-fill-transparent');
        if (fillTransChecked) await page.click('#shape-fill-transparent');
        await page.waitForTimeout(100);
        await setColorInput(page, 'shape-fill-color', 'shape-fill-hex', shapeDef.fill);
    }

    // Step 6: Set stroke width (range input)
    await setRangeInput(page, 'shape-stroke-width', shapeDef.strokeWidth);

    // Step 7: Click "Apply & Draw Shape"
    await page.click('#shape-apply');
    await page.waitForTimeout(300);

    // Step 8: Draw the shape via pointer events on the page overlay
    // Key: handlers use pointerdown/pointermove/pointerup on the .overlay element
    const overlay = await page.$('#viewer .page[data-page-index="0"] .overlay');
    if (!overlay) {
        throw new Error('Could not find .overlay element inside page');
    }

    const box = await overlay.boundingBox();
    if (!box) {
        throw new Error('Overlay has no bounding box (not visible?)');
    }

    // Draw a shape covering roughly the center 50% of the page
    const startX = box.x + box.width * 0.25;
    const startY = box.y + box.height * 0.25;
    const endX = box.x + box.width * 0.75;
    const endY = box.y + box.height * 0.75;

    // Use dispatchEvent to fire pointer events directly on the overlay
    // This is more reliable since the handlers use overlay.addEventListener('pointerdown/up/move')
    await page.evaluate(({ startX, startY, endX, endY }) => {
        const overlay = document.querySelector('#viewer .page[data-page-index="0"] .overlay');
        if (!overlay) throw new Error('No overlay found');

        const rect = overlay.getBoundingClientRect();

        // Helper to create PointerEvent with correct coordinates
        function makePointerEvent(type, clientX, clientY) {
            return new PointerEvent(type, {
                bubbles: true,
                cancelable: true,
                clientX,
                clientY,
                pointerId: 1,
                pointerType: 'mouse',
                isPrimary: true,
                button: 0,
                buttons: type === 'pointerup' ? 0 : 1,
            });
        }

        // Dispatch pointerdown
        overlay.dispatchEvent(makePointerEvent('pointerdown', startX, startY));

        // Dispatch several pointermove events
        const steps = 10;
        for (let i = 1; i <= steps; i++) {
            const x = startX + (endX - startX) * (i / steps);
            const y = startY + (endY - startY) * (i / steps);
            overlay.dispatchEvent(makePointerEvent('pointermove', x, y));
        }

        // Dispatch pointerup
        overlay.dispatchEvent(makePointerEvent('pointerup', endX, endY));
    }, { startX, startY, endX, endY });

    await page.waitForTimeout(500);
    await page.mouse.up();
    await page.waitForTimeout(500);

    // Check the actual JS annotations array and DOM state
    const state = await page.evaluate(() => {
        const wrapper = document.querySelector('#viewer .page[data-page-index="0"]');
        const domAnnotations = wrapper
            ? wrapper.querySelectorAll('.annotation').length
            : 0;
        const domSvgs = wrapper
            ? wrapper.querySelectorAll('.annotation svg').length
            : 0;

        // Check the JS-level annotations array
        let jsAnnotationsLength = 0;
        let jsToolMode = 'unknown';
        try {
            jsAnnotationsLength = typeof annotations !== 'undefined' ? annotations.length : -1;
        } catch (e) { jsAnnotationsLength = -2; }
        try {
            jsToolMode = typeof toolMode !== 'undefined' ? toolMode : 'undefined';
        } catch (e) { jsToolMode = 'error'; }

        // Also check for shape preview elements (during drag) vs finalized annotations
        const shapePreview = document.querySelector('.shape-preview, .drawing-shape');
        
        return {
            domAnnotations,
            domSvgs,
            jsAnnotationsLength,
            jsToolMode,
            hasShapePreview: !!shapePreview,
        };
    });

    return {
        drawn: state.domAnnotations > 0 || state.jsAnnotationsLength > 0,
        state
    };
}

/**
 * Save the PDF and wait for the AJAX POST to complete.
 * The editor does: fetch POST with the PDF blob → on success, it sets
 * window.location.href to redirect after 500ms. We monitor the network
 * response rather than waiting for full navigation.
 */
async function savePdf(page) {
    const saveBtn = await page.$('#save-btn');
    if (!saveBtn) throw new Error('Save button not found');

    // Capture console errors to debug save issues
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', msg => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', err => pageErrors.push(err.message));

    // Start monitoring for the save POST response before clicking
    const responsePromise = page.waitForResponse(
        resp => resp.url().includes('/save') && resp.request().method() === 'POST',
        { timeout: 60000 }
    ).catch(err => null); // Catch timeout, return null

    // Click save
    await saveBtn.click();

    // Wait for JS to build the PDF blob and fire the POST
    await page.waitForTimeout(1000);

    // Check if there were JS errors
    if (consoleErrors.length > 0 || pageErrors.length > 0) {
        const allErrors = [...consoleErrors, ...pageErrors].join('; ');
        throw new Error(`JS errors during save: ${allErrors}`);
    }

    // Check if response came back
    const response = await responsePromise;
    if (!response) {
        // Save POST never fired. Check status text for clues
        const statusText = await page.evaluate(() => {
            const el = document.getElementById('status');
            return el ? el.textContent : 'no status element';
        });
        throw new Error(`Save POST never fired. Status text: "${statusText}". Console errors: ${consoleErrors.join('; ') || 'none'}`);
    }

    const status = response.status();
    if (status !== 200 && status !== 302 && status !== 301) {
        let body = '';
        try { body = await response.text(); } catch (_) {}
        throw new Error(`Save returned status ${status}: ${body.substring(0, 500)}`);
    }

    // Wait for the JS redirect (window.location.href after 500ms setTimeout)
    await page.waitForNavigation({ timeout: 15000, waitUntil: 'load' }).catch(() => {});
    await page.waitForTimeout(500);
    return response;
}

/**
 * Download the saved PDF and validate it contains drawings using PyMuPDF.
 * Returns an object with validation results.
 */
function validateSavedPdf(pdfPath) {
    const pythonValidate = `python3 -c "
import fitz, json, sys

doc = fitz.open('${pdfPath}')
page = doc[0]
drawings = page.get_drawings()

results = {
    'drawing_count': len(drawings),
    'has_drawings': len(drawings) > 0,
    'item_types': [],
    'has_fill': False,
    'has_stroke': False,
    'has_width': False,
    'fill_colors': [],
    'stroke_colors': [],
}

for d in drawings:
    for item in d.get('items', []):
        t = item[0]
        if t not in results['item_types']:
            results['item_types'].append(t)
    if d.get('fill') is not None:
        results['has_fill'] = True
        fc = d['fill']
        if fc not in results['fill_colors']:
            results['fill_colors'].append(list(fc) if hasattr(fc, '__iter__') else fc)
    if d.get('color') is not None:
        results['has_stroke'] = True
        sc = d['color']
        if sc not in results['stroke_colors']:
            results['stroke_colors'].append(list(sc) if hasattr(sc, '__iter__') else sc)
    if d.get('width', 0) > 0:
        results['has_width'] = True

doc.close()
print(json.dumps(results))
"`;

    try {
        const output = execSync(pythonValidate, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
        return JSON.parse(output.trim());
    } catch (err) {
        return {
            error: err.stderr || err.message,
            has_drawings: false,
            drawing_count: 0,
            item_types: [],
            has_fill: false,
            has_stroke: false,
        };
    }
}

/**
 * Enhanced PDF validation that also detects rotation (cm matrix operators),
 * opacity (ExtGState with ca/CA), stroke widths, and multiple drawing groups.
 * Used by the rotate, color/opacity, and all-shapes tests.
 */
function validateSavedPdfDetailed(pdfPath) {
    const pythonValidate = `python3 -c "
import fitz, json, sys, re

doc = fitz.open('${pdfPath}')
page = doc[0]
drawings = page.get_drawings()

# Parse ALL content streams (pages can have multiple xrefs)
content = ''
for xref in page.get_contents():
    content += doc.xref_stream(xref).decode('latin-1', errors='replace') + chr(10)

# Check for cm (concat matrix) operators indicating rotation
cm_ops = re.findall(r'[\\d.eE+-]+ [\\d.eE+-]+ [\\d.eE+-]+ [\\d.eE+-]+ [\\d.eE+-]+ [\\d.eE+-]+ cm', content)
has_rotation_matrix = False
for cm in cm_ops:
    parts = cm.replace(' cm', '').split()
    if len(parts) == 6:
        try:
            b, c = float(parts[1]), float(parts[2])
            if abs(b) > 0.001 or abs(c) > 0.001:
                has_rotation_matrix = True
                break
        except:
            pass

# Check for ExtGState references in content stream (format: /GS<name> gs)
gs_refs = re.findall(r'/GS[\\w-]+ gs', content)
has_ext_gstate = len(gs_refs) > 0

# Check page resources for ExtGState with ca/CA opacity values
opacity_values = []
try:
    xref_page = page.xref
    res_str = doc.xref_object(xref_page)
    ca_matches = re.findall(r'/ca\\s+\\.?(\\d+\\.?\\d*)', res_str)
    CA_matches = re.findall(r'/CA\\s+\\.?(\\d+\\.?\\d*)', res_str)
    for v in ca_matches + CA_matches:
        fv = float(v) if not v.startswith('.') else float('0' + v)
        if fv not in opacity_values:
            opacity_values.append(fv)
except:
    pass

results = {
    'drawing_count': len(drawings),
    'has_drawings': len(drawings) > 0,
    'item_types': [],
    'has_fill': False,
    'has_stroke': False,
    'has_width': False,
    'fill_colors': [],
    'stroke_colors': [],
    'stroke_widths': [],
    'has_rotation_matrix': has_rotation_matrix,
    'cm_operator_count': len(cm_ops),
    'has_ext_gstate': has_ext_gstate,
    'opacity_values': opacity_values,
    'drawing_groups': len(drawings),
}

for d in drawings:
    for item in d.get('items', []):
        t = item[0]
        if t not in results['item_types']:
            results['item_types'].append(t)
    if d.get('fill') is not None:
        results['has_fill'] = True
        fc = d['fill']
        if fc not in results['fill_colors']:
            results['fill_colors'].append(list(fc) if hasattr(fc, '__iter__') else fc)
    if d.get('color') is not None:
        results['has_stroke'] = True
        sc = d['color']
        if sc not in results['stroke_colors']:
            results['stroke_colors'].append(list(sc) if hasattr(sc, '__iter__') else sc)
    w = d.get('width', 0)
    if w > 0:
        results['has_width'] = True
        if w not in results['stroke_widths']:
            results['stroke_widths'].append(w)

doc.close()
print(json.dumps(results))
"`;

    try {
        const output = execSync(pythonValidate, { encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] });
        return JSON.parse(output.trim());
    } catch (err) {
        return {
            error: err.stderr || err.message,
            has_drawings: false,
            drawing_count: 0,
            item_types: [],
            has_fill: false,
            has_stroke: false,
            has_rotation_matrix: false,
            has_ext_gstate: false,
            opacity_values: [],
            stroke_widths: [],
            drawing_groups: 0,
        };
    }
}

/**
 * Draw a shape at a SPECIFIC region of the page overlay.
 * region = { xStart: 0-1, yStart: 0-1, xEnd: 0-1, yEnd: 0-1 } as fractions.
 * This is used by the all-shapes test to place shapes in different areas.
 */
async function drawShapeAtPosition(page, shapeDef, region) {
    // Open shape modal
    await page.click('#mode-shape');
    await page.waitForSelector('#shape-modal:not([aria-hidden="true"])', { timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(200);

    // Select shape type
    const shapeBtn = `button.shape-type-btn[data-shape="${shapeDef.type}"]`;
    await page.waitForSelector(shapeBtn, { timeout: 5000 });
    await page.click(shapeBtn);
    await page.waitForTimeout(100);

    // Configure colors
    await setColorInput(page, 'shape-stroke-color', 'shape-stroke-hex', shapeDef.stroke);
    if (shapeDef.strokeTransparent) {
        const checked = await page.isChecked('#shape-stroke-transparent');
        if (!checked) await page.click('#shape-stroke-transparent');
    } else {
        const checked = await page.isChecked('#shape-stroke-transparent');
        if (checked) await page.click('#shape-stroke-transparent');
    }
    if (shapeDef.fillTransparent) {
        const checked = await page.isChecked('#shape-fill-transparent');
        if (!checked) await page.click('#shape-fill-transparent');
    } else {
        const checked = await page.isChecked('#shape-fill-transparent');
        if (checked) await page.click('#shape-fill-transparent');
        await page.waitForTimeout(100);
        await setColorInput(page, 'shape-fill-color', 'shape-fill-hex', shapeDef.fill);
    }
    await setRangeInput(page, 'shape-stroke-width', shapeDef.strokeWidth);

    // Set opacity if provided
    if (shapeDef.opacity !== undefined) {
        await setRangeInput(page, 'shape-opacity', Math.round(shapeDef.opacity * 100));
    }

    // Apply
    await page.click('#shape-apply');
    await page.waitForTimeout(300);

    // Draw at the specified region
    const overlay = await page.$('#viewer .page[data-page-index="0"] .overlay');
    if (!overlay) throw new Error('Could not find .overlay element');
    const box = await overlay.boundingBox();
    if (!box) throw new Error('Overlay has no bounding box');

    const startX = box.x + box.width * region.xStart;
    const startY = box.y + box.height * region.yStart;
    const endX = box.x + box.width * region.xEnd;
    const endY = box.y + box.height * region.yEnd;

    await page.evaluate(({ startX, startY, endX, endY }) => {
        const overlay = document.querySelector('#viewer .page[data-page-index="0"] .overlay');
        if (!overlay) throw new Error('No overlay found');
        function makePointerEvent(type, clientX, clientY) {
            return new PointerEvent(type, {
                bubbles: true, cancelable: true, clientX, clientY,
                pointerId: 1, pointerType: 'mouse', isPrimary: true,
                button: 0, buttons: type === 'pointerup' ? 0 : 1,
            });
        }
        overlay.dispatchEvent(makePointerEvent('pointerdown', startX, startY));
        const steps = 8;
        for (let i = 1; i <= steps; i++) {
            const x = startX + (endX - startX) * (i / steps);
            const y = startY + (endY - startY) * (i / steps);
            overlay.dispatchEvent(makePointerEvent('pointermove', x, y));
        }
        overlay.dispatchEvent(makePointerEvent('pointerup', endX, endY));
    }, { startX, startY, endX, endY });

    await page.waitForTimeout(500);
    await page.mouse.up();
    await page.waitForTimeout(300);
}

/**
 * Rotate an existing shape annotation by setting rotation via JS.
 * The editor stores rotation in annotation.rotation and applies via
 * shapeSvg.style.transform. We set it directly and trigger persistence.
 * Returns the rotation value applied.
 */
async function rotateAnnotation(page, degrees) {
    const rotated = await page.evaluate((degrees) => {
        // Find the last annotation (just drawn)
        if (typeof annotations === 'undefined' || annotations.length === 0) {
            return { success: false, error: 'No annotations found' };
        }
        const ann = annotations[annotations.length - 1];
        ann.rotation = degrees;
        if (ann.shapeSvg) {
            ann.shapeSvg.style.transform = `rotate(${degrees}deg)`;
            ann.shapeSvg.style.transformOrigin = 'center';
        }
        // Trigger persistence
        if (typeof persistAnnotations === 'function') persistAnnotations();
        return { success: true, rotation: ann.rotation, id: ann.id };
    }, degrees);
    await page.waitForTimeout(200);
    return rotated;
}

/**
 * Create a blank test PDF using PyMuPDF. Returns the path.
 * (Extracted so we can batch-create PDFs without hitting CSRF.)
 */
function createBlankPdf(shapeType) {
    const blankPdfPath = path.join(SHAPES_DIR, `${shapeType}_frontend_test.pdf`);
    const pythonCmd = `python3 -c "
import fitz
doc = fitz.open()
p = doc.new_page(width=612, height=792)
p.insert_text(fitz.Point(20, 30), 'Shape Test: ${shapeType}', fontsize=14, color=(0.3, 0.3, 0.3))
doc.save('${blankPdfPath}')
doc.close()
"`;
    execSync(pythonCmd, { stdio: 'pipe' });
    return blankPdfPath;
}

/**
 * Upload a PDF to create a document. Requires a page with an active session
 * and a CSRF token already obtained.
 * Returns the document ID.
 */
async function uploadDocument(page, csrfToken, pdfPath, shapeType) {
    const fileBuffer = fs.readFileSync(pdfPath);
    const response = await page.request.post(`${BASE_URL}/documents`, {
        multipart: {
            _token: csrfToken,
            document: {
                name: `${shapeType}_frontend_test.pdf`,
                mimeType: 'application/pdf',
                buffer: fileBuffer,
            },
        },
        maxRedirects: 0,
    });

    const location = response.headers()['location'] || '';
    const match = location.match(/\/documents\/(\d+)\/edit/);
    if (match) return parseInt(match[1]);

    const urlText = response.url();
    const urlMatch = urlText.match(/\/documents\/(\d+)/);
    if (urlMatch) return parseInt(urlMatch[1]);

    throw new Error(`Failed to create document. Status: ${response.status()}`);
}

/**
 * Obtain CSRF token by visiting a page with a form.
 * Stores the session cookie in the browser context for reuse.
 */
async function fetchCsrfToken(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    const csrfToken = await page.evaluate(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        const input = document.querySelector('input[name="_token"]');
        if (input) return input.value;
        return null;
    });
    if (!csrfToken) throw new Error('Could not extract CSRF token from page');
    return csrfToken;
}

/**
 * Run the test steps for a single shape using an EXISTING page + context.
 * This avoids launching a new browser for each shape.
 * Returns the result object.
 */
async function runShapeOnPage(page, csrfToken, shapeDef) {
    const shapeType = shapeDef.type;
    const checks = [];
    let docId = null;
    let pdfSize = 0;

    try {
        // Step 1: Create blank PDF + upload (reuses existing session)
        try {
            const pdfPath = createBlankPdf(shapeType);
            docId = await uploadDocument(page, csrfToken, pdfPath, shapeType);
            checks.push(makeCheck('Document Created', true, 'Blank PDF uploaded via API', `Document ID: ${docId}`));
        } catch (err) {
            checks.push(makeCheck('Document Created', false, 'Failed to create blank PDF document', err.message));
            throw err;
        }

        // Step 2: Open the editor
        try {
            await page.goto(`${BASE_URL}/documents/${docId}/edit`, { waitUntil: 'load', timeout: 30000 });
            await waitForEditorLoad(page);
            checks.push(makeCheck('Editor Loaded', true, 'PDF editor opened and page rendered', `URL: /documents/${docId}/edit`));
        } catch (err) {
            const ssPath = path.join(SHAPES_DIR, `${shapeType}_error_editor.png`);
            await page.screenshot({ path: ssPath }).catch(() => {});
            checks.push(makeCheck('Editor Loaded', false, 'PDF editor failed to load', err.message));
            throw err;
        }

        // Step 3: Draw the shape
        let shapeDrawn = false;
        let drawState = {};
        try {
            const result = await drawShape(page, shapeDef);
            shapeDrawn = result.drawn;
            drawState = result.state;
            checks.push(makeCheck(
                `${shapeDef.label} Drawn`, shapeDrawn,
                shapeDrawn ? `${shapeDef.label} shape drawn on page via mouse drag` : 'Shape annotation not found in DOM after drawing',
                `DOM annotations: ${drawState.domAnnotations}, SVGs: ${drawState.domSvgs}, JS annotations: ${drawState.jsAnnotationsLength}, toolMode: ${drawState.jsToolMode}`
            ));
            if (!shapeDrawn) {
                const ssPath = path.join(SHAPES_DIR, `${shapeType}_error_draw.png`);
                await page.screenshot({ path: ssPath }).catch(() => {});
            }
        } catch (err) {
            const ssPath = path.join(SHAPES_DIR, `${shapeType}_error_draw.png`);
            await page.screenshot({ path: ssPath }).catch(() => {});
            checks.push(makeCheck(`${shapeDef.label} Drawn`, false, 'Failed to draw shape on page', err.message));
            throw err;
        }

        // Step 4: Save
        try {
            await savePdf(page);
            checks.push(makeCheck('PDF Saved', true, 'PDF saved via editor save button', 'AJAX POST + page redirect completed'));
        } catch (err) {
            const ssPath = path.join(SHAPES_DIR, `${shapeType}_error_save.png`);
            await page.screenshot({ path: ssPath }).catch(() => {});
            checks.push(makeCheck('PDF Saved', false, 'PDF save failed', err.message));
            throw err;
        }

        // Step 5: Download saved PDF
        const savedPdfPath = path.join(SHAPES_DIR, `${shapeType}_frontend_saved.pdf`);
        try {
            const pdfResponse = await page.request.get(`${BASE_URL}/documents/${docId}/file`);
            const pdfBuffer = await pdfResponse.body();
            writeFileOpen(savedPdfPath, pdfBuffer);
            pdfSize = pdfBuffer.length;
            checks.push(makeCheck('PDF Downloaded', true, 'Saved PDF retrieved from server', `${pdfSize} bytes`));
        } catch (err) {
            checks.push(makeCheck('PDF Downloaded', false, 'Failed to download saved PDF', err.message));
            throw err;
        }

        // Step 6: Validate with PyMuPDF
        const validation = validateSavedPdf(savedPdfPath);

        if (validation.error) {
            checks.push(makeCheck('PyMuPDF Validation', false, 'PDF validation encountered an error', validation.error));
        } else {
            checks.push(makeCheck(
                'Drawings Present', validation.has_drawings,
                validation.has_drawings ? `${validation.drawing_count} drawing path(s) found in saved PDF` : 'No drawings found — shape was not burned into PDF content stream',
                `Drawing types: ${validation.item_types.join(', ') || 'none'}`
            ));

            const hasExpectedType = validation.item_types.includes(shapeDef.expectedDrawingType);
            checks.push(makeCheck(
                `Expected Operator "${shapeDef.expectedDrawingType}"`, hasExpectedType,
                hasExpectedType ? `PDF operator "${shapeDef.expectedDrawingType}" found for ${shapeDef.label}` : `Expected operator "${shapeDef.expectedDrawingType}" not found`,
                `All types: ${validation.item_types.join(', ') || 'none'}`
            ));

            if (!shapeDef.fillTransparent) {
                checks.push(makeCheck('Fill Color', validation.has_fill,
                    validation.has_fill ? 'Fill color present in saved PDF drawings' : 'No fill color detected (shape was configured with fill)',
                    validation.fill_colors ? JSON.stringify(validation.fill_colors) : ''
                ));
            }

            if (!shapeDef.strokeTransparent) {
                checks.push(makeCheck('Stroke Color', validation.has_stroke,
                    validation.has_stroke ? 'Stroke color present in saved PDF drawings' : 'No stroke detected in saved PDF',
                    validation.stroke_colors ? JSON.stringify(validation.stroke_colors) : ''
                ));
            }

            checks.push(makeCheck('Stroke Width', validation.has_width,
                validation.has_width ? 'Non-zero stroke width found' : 'No stroke width > 0 found',
                `Configured: ${shapeDef.strokeWidth}px`
            ));
        }

        const passed = checks.filter(c => c.result === 'PASS').length;
        return {
            filename: `${shapeType}_frontend_test.pdf`,
            description: `${shapeDef.label} — drawn via frontend shape tool, saved, and validated`,
            test_category: 'Shapes',
            section_name: shapeDef.label,
            status: passed === checks.length ? 'pass' : 'fail',
            checks,
            checks_passed: passed,
            checks_total: checks.length,
            page_count: 1,
            file_size: pdfSize,
            error: null,
            warnings: [],
        };
    } catch (err) {
        const passed = checks.filter(c => c.result === 'PASS').length;
        return {
            filename: `${shapeType}_frontend_test.pdf`,
            description: `${shapeDef.label} — frontend shape test`,
            test_category: 'Shapes',
            section_name: shapeDef.label,
            status: 'error',
            checks,
            checks_passed: passed,
            checks_total: checks.length,
            page_count: 0,
            file_size: 0,
            error: err.message,
            warnings: [],
        };
    }
}

// ============================================================
// ADVANCED TEST: Rotation
// ============================================================

/**
 * Test that drawing a rectangle, rotating it 45°, saving, and
 * downloading produces a PDF with a rotation matrix (cm operator).
 */
async function runRotateTest(page, csrfToken) {
    const testName = 'rotate_test';
    const checks = [];
    let pdfSize = 0;

    const shapeDef = {
        type: 'rect', label: 'Rectangle (Rotated)',
        stroke: '#0000ff', fill: '#ffcc00',
        fillTransparent: false, strokeTransparent: false,
        strokeWidth: 3, expectedDrawingType: 'l',
    };

    try {
        // Create & upload doc
        const pdfPath = createBlankPdf(testName);
        const docId = await uploadDocument(page, csrfToken, pdfPath, testName);
        checks.push(makeCheck('Document Created', true, 'Blank PDF uploaded', `ID: ${docId}`));

        // Open editor
        await page.goto(`${BASE_URL}/documents/${docId}/edit`, { waitUntil: 'load', timeout: 30000 });
        await waitForEditorLoad(page);
        checks.push(makeCheck('Editor Loaded', true, 'PDF editor opened', ''));

        // Draw rectangle
        const drawResult = await drawShape(page, shapeDef);
        checks.push(makeCheck('Shape Drawn', drawResult.drawn, 'Rectangle drawn on page', `annotations: ${drawResult.state.jsAnnotationsLength}`));

        // Rotate it 45 degrees
        const rotResult = await rotateAnnotation(page, 45);
        checks.push(makeCheck('Rotation Applied', rotResult.success, rotResult.success ? 'Rotation set to 45°' : 'Failed to apply rotation', rotResult.error || `rotation=${rotResult.rotation}`));

        // Save
        await savePdf(page);
        checks.push(makeCheck('PDF Saved', true, 'Saved with rotation', ''));

        // Download & validate
        const savedPdfPath = path.join(SHAPES_DIR, `${testName}_saved.pdf`);
        const pdfResponse = await page.request.get(`${BASE_URL}/documents/${docId}/file`);
        const pdfBuffer = await pdfResponse.body();
        writeFileOpen(savedPdfPath, pdfBuffer);
        pdfSize = pdfBuffer.length;

        const v = validateSavedPdfDetailed(savedPdfPath);
        if (v.error) {
            checks.push(makeCheck('Validation', false, 'PyMuPDF error', v.error));
        } else {
            checks.push(makeCheck('Drawings Present', v.has_drawings, v.has_drawings ? `${v.drawing_count} drawings found` : 'No drawings', ''));
            checks.push(makeCheck('Rotation Matrix (cm)', v.has_rotation_matrix, v.has_rotation_matrix ? 'cm operator with non-zero sin/cos found — rotation confirmed' : 'No rotation matrix found in content stream', `cm operators: ${v.cm_operator_count}`));
            checks.push(makeCheck('Fill Color', v.has_fill, v.has_fill ? 'Fill preserved after rotation' : 'No fill found', JSON.stringify(v.fill_colors)));
            checks.push(makeCheck('Stroke Color', v.has_stroke, v.has_stroke ? 'Stroke preserved after rotation' : 'No stroke found', JSON.stringify(v.stroke_colors)));
        }
    } catch (err) {
        const ssPath = path.join(SHAPES_DIR, `${testName}_error.png`);
        await page.screenshot({ path: ssPath }).catch(() => {});
        checks.push(makeCheck('Test Error', false, 'Unexpected error', err.message));
    }

    const passed = checks.filter(c => c.result === 'PASS').length;
    return {
        filename: `${testName}.pdf`, description: 'Rectangle drawn, rotated 45°, saved — validates rotation matrix in PDF',
        test_category: 'Shapes', section_name: 'Rotation Test',
        status: passed === checks.length ? 'pass' : 'fail',
        checks, checks_passed: passed, checks_total: checks.length,
        page_count: 1, file_size: pdfSize, error: null, warnings: [],
    };
}

// ============================================================
// ADVANCED TEST: Color, Border & Opacity
// ============================================================

/**
 * Test custom fill color (#ff6600), stroke color (#006633), thick border (8px),
 * and 50% opacity. Validates all properties survive save.
 */
async function runColorBorderOpacityTest(page, csrfToken) {
    const testName = 'color_border_opacity_test';
    const checks = [];
    let pdfSize = 0;

    const shapeDef = {
        type: 'rect', label: 'Rectangle (Color/Border/Opacity)',
        stroke: '#006633', fill: '#ff6600',
        fillTransparent: false, strokeTransparent: false,
        strokeWidth: 8, opacity: 0.5, expectedDrawingType: 'l',
    };

    try {
        const pdfPath = createBlankPdf(testName);
        const docId = await uploadDocument(page, csrfToken, pdfPath, testName);
        checks.push(makeCheck('Document Created', true, 'Blank PDF uploaded', `ID: ${docId}`));

        await page.goto(`${BASE_URL}/documents/${docId}/edit`, { waitUntil: 'load', timeout: 30000 });
        await waitForEditorLoad(page);
        checks.push(makeCheck('Editor Loaded', true, 'PDF editor opened', ''));

        // Draw shape with custom colors + opacity
        await drawShapeAtPosition(page, shapeDef, { xStart: 0.15, yStart: 0.15, xEnd: 0.85, yEnd: 0.85 });

        // Check annotations were created
        const annState = await page.evaluate(() => {
            if (typeof annotations === 'undefined') return { count: 0 };
            const ann = annotations[annotations.length - 1];
            return {
                count: annotations.length,
                opacity: ann ? ann.opacity : null,
                strokeWidth: ann ? ann.strokeWidth : null,
                fillColor: ann ? ann.fillColor : null,
                strokeColor: ann ? ann.strokeColor : null,
            };
        });
        checks.push(makeCheck('Shape Drawn', annState.count > 0, annState.count > 0 ? 'Shape created with custom properties' : 'No annotation found',
            `opacity=${annState.opacity}, stroke=${annState.strokeColor}, fill=${annState.fillColor}, strokeWidth=${annState.strokeWidth}`));

        // Save
        await savePdf(page);
        checks.push(makeCheck('PDF Saved', true, 'Saved with color/border/opacity', ''));

        // Download & validate
        const savedPdfPath = path.join(SHAPES_DIR, `${testName}_saved.pdf`);
        const pdfResponse = await page.request.get(`${BASE_URL}/documents/${docId}/file`);
        const pdfBuffer = await pdfResponse.body();
        writeFileOpen(savedPdfPath, pdfBuffer);
        pdfSize = pdfBuffer.length;

        const v = validateSavedPdfDetailed(savedPdfPath);
        if (v.error) {
            checks.push(makeCheck('Validation', false, 'PyMuPDF error', v.error));
        } else {
            checks.push(makeCheck('Drawings Present', v.has_drawings, v.has_drawings ? `${v.drawing_count} drawings` : 'No drawings', ''));

            // Fill color: #ff6600 → RGB (1.0, 0.4, 0.0)
            checks.push(makeCheck('Custom Fill Color', v.has_fill, v.has_fill ? 'Fill color present in saved PDF' : 'No fill color — custom fill lost on save', JSON.stringify(v.fill_colors)));

            // Stroke color: #006633 → RGB (0.0, 0.4, 0.2)
            checks.push(makeCheck('Custom Stroke Color', v.has_stroke, v.has_stroke ? 'Stroke color present in saved PDF' : 'No stroke — custom stroke lost on save', JSON.stringify(v.stroke_colors)));

            // Stroke width: 8px (scaled to PDF units, should be > default)
            checks.push(makeCheck('Thick Border Width', v.has_width, v.has_width ? 'Non-zero stroke width found' : 'No stroke width', `Widths: ${JSON.stringify(v.stroke_widths)}`));

            // Opacity: 50% should produce ExtGState with ca=0.5 or similar
            checks.push(makeCheck('Opacity (ExtGState)', v.has_ext_gstate || v.opacity_values.length > 0,
                (v.has_ext_gstate || v.opacity_values.length > 0) ? 'ExtGState or opacity value found — opacity applied to PDF' : 'No opacity indicators found in PDF (50% opacity may not have been saved)',
                `ExtGState: ${v.has_ext_gstate}, opacity values: ${JSON.stringify(v.opacity_values)}`));
        }
    } catch (err) {
        const ssPath = path.join(SHAPES_DIR, `${testName}_error.png`);
        await page.screenshot({ path: ssPath }).catch(() => {});
        checks.push(makeCheck('Test Error', false, 'Unexpected error', err.message));
    }

    const passed = checks.filter(c => c.result === 'PASS').length;
    return {
        filename: `${testName}.pdf`, description: 'Custom fill #ff6600, stroke #006633, border 8px, 50% opacity — validates color/border/opacity in PDF',
        test_category: 'Shapes', section_name: 'Color/Border/Opacity Test',
        status: passed === checks.length ? 'pass' : 'fail',
        checks, checks_passed: passed, checks_total: checks.length,
        page_count: 1, file_size: pdfSize, error: null, warnings: [],
    };
}

// ============================================================
// ADVANCED TEST: All Shapes At Once
// ============================================================

/**
 * Draw all 8 shape types on a single page at different positions,
 * save once, and validate ALL drawing types exist in the PDF.
 */
async function runAllShapesAtOnceTest(page, csrfToken) {
    const testName = 'all_shapes_at_once';
    const checks = [];
    let pdfSize = 0;

    // Grid layout: 4 columns x 2 rows — map each shape to a region
    const regions = [
        { xStart: 0.02, yStart: 0.05, xEnd: 0.23, yEnd: 0.45 },  // top-left
        { xStart: 0.26, yStart: 0.05, xEnd: 0.47, yEnd: 0.45 },  // top-mid-left
        { xStart: 0.52, yStart: 0.05, xEnd: 0.73, yEnd: 0.45 },  // top-mid-right
        { xStart: 0.76, yStart: 0.05, xEnd: 0.97, yEnd: 0.45 },  // top-right
        { xStart: 0.02, yStart: 0.52, xEnd: 0.23, yEnd: 0.92 },  // bottom-left
        { xStart: 0.26, yStart: 0.52, xEnd: 0.47, yEnd: 0.92 },  // bottom-mid-left
        { xStart: 0.52, yStart: 0.52, xEnd: 0.73, yEnd: 0.92 },  // bottom-mid-right
        { xStart: 0.76, yStart: 0.52, xEnd: 0.97, yEnd: 0.92 },  // bottom-right
    ];

    try {
        const pdfPath = createBlankPdf(testName);
        const docId = await uploadDocument(page, csrfToken, pdfPath, testName);
        checks.push(makeCheck('Document Created', true, 'Blank PDF uploaded', `ID: ${docId}`));

        await page.goto(`${BASE_URL}/documents/${docId}/edit`, { waitUntil: 'load', timeout: 30000 });
        await waitForEditorLoad(page);
        checks.push(makeCheck('Editor Loaded', true, 'PDF editor opened', ''));

        // Draw each shape at its grid position
        let drawCount = 0;
        for (let i = 0; i < SHAPE_TYPES.length; i++) {
            try {
                await drawShapeAtPosition(page, SHAPE_TYPES[i], regions[i]);
                drawCount++;
            } catch (err) {
                checks.push(makeCheck(`Draw ${SHAPE_TYPES[i].label}`, false, `Failed to draw ${SHAPE_TYPES[i].label}`, err.message));
            }
        }

        // Verify annotation count
        const annCount = await page.evaluate(() => typeof annotations !== 'undefined' ? annotations.length : 0);
        checks.push(makeCheck('All 8 Shapes Drawn', annCount >= 8, annCount >= 8 ? `${annCount} annotations created (expected 8)` : `Only ${annCount} annotations — some shapes may have failed`,
            `Drew ${drawCount}/8 shapes successfully`));

        // Save
        await savePdf(page);
        checks.push(makeCheck('PDF Saved', true, 'Single PDF with all 8 shapes saved', ''));

        // Download & validate
        const savedPdfPath = path.join(SHAPES_DIR, `${testName}_saved.pdf`);
        const pdfResponse = await page.request.get(`${BASE_URL}/documents/${docId}/file`);
        const pdfBuffer = await pdfResponse.body();
        writeFileOpen(savedPdfPath, pdfBuffer);
        pdfSize = pdfBuffer.length;

        const v = validateSavedPdfDetailed(savedPdfPath);
        if (v.error) {
            checks.push(makeCheck('Validation', false, 'PyMuPDF error', v.error));
        } else {
            checks.push(makeCheck('Multiple Drawings', v.drawing_count >= 8,
                v.drawing_count >= 8 ? `${v.drawing_count} drawing groups found (8+ expected)` : `Only ${v.drawing_count} drawing groups — some shapes missing`,
                `Types found: ${v.item_types.join(', ')}`));

            // Must have line operators (l) — used by rect, triangle, arrow, star, checkmark, polygon, x
            const hasLines = v.item_types.includes('l');
            checks.push(makeCheck('Line Operators (l)', hasLines, hasLines ? 'Line drawing operators found' : 'No line operators — most shapes should produce these', ''));

            // Must have curve operators (c) — used by circle/ellipse
            const hasCurves = v.item_types.includes('c');
            checks.push(makeCheck('Curve Operators (c)', hasCurves, hasCurves ? 'Bezier curve operators found (circle/ellipse)' : 'No curve operators — circle may not have been drawn', ''));

            checks.push(makeCheck('Fill Colors Present', v.has_fill, v.has_fill ? 'Fill colors found in multi-shape PDF' : 'No fills', JSON.stringify(v.fill_colors)));
            checks.push(makeCheck('Stroke Colors Present', v.has_stroke, v.has_stroke ? 'Stroke colors found in multi-shape PDF' : 'No strokes', JSON.stringify(v.stroke_colors)));
        }
    } catch (err) {
        const ssPath = path.join(SHAPES_DIR, `${testName}_error.png`);
        await page.screenshot({ path: ssPath }).catch(() => {});
        checks.push(makeCheck('Test Error', false, 'Unexpected error', err.message));
    }

    const passed = checks.filter(c => c.result === 'PASS').length;
    return {
        filename: `${testName}.pdf`, description: 'All 8 shapes drawn on one page in a 4x2 grid, saved once — validates all drawing types present',
        test_category: 'Shapes', section_name: 'All Shapes At Once',
        status: passed === checks.length ? 'pass' : 'fail',
        checks, checks_passed: passed, checks_total: checks.length,
        page_count: 1, file_size: pdfSize, error: null, warnings: [],
    };
}

// ============================================================
// ADVANCED TEST DEFINITIONS (for listing / run-all)
// ============================================================

const ADVANCED_TESTS = [
    { key: 'rotate', label: 'Rotation Test', description: 'Rectangle rotated 45° — validates cm matrix in PDF', runner: runRotateTest },
    { key: 'color_border_opacity', label: 'Color/Border/Opacity Test', description: 'Custom colors, 8px border, 50% opacity', runner: runColorBorderOpacityTest },
    { key: 'all_shapes_at_once', label: 'All Shapes At Once', description: 'All 8 shapes on one page in a grid', runner: runAllShapesAtOnceTest },
];

/**
 * Run an advanced test by key (rotate, color_border_opacity, all_shapes_at_once).
 * Launches its own browser for --single-shape usage.
 */
async function runAdvancedTest(testKey) {
    const testDef = ADVANCED_TESTS.find(t => t.key === testKey);
    if (!testDef) {
        return {
            filename: `${testKey}_test.pdf`, description: `Unknown advanced test: ${testKey}`,
            test_category: 'Shapes', section_name: testKey, status: 'error',
            checks: [], checks_passed: 0, checks_total: 0, page_count: 0,
            file_size: 0, error: `Unknown test key: ${testKey}`, warnings: [],
        };
    }

    let browser = null;
    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
        });
        const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        const csrfToken = await fetchCsrfToken(page);

        const result = await testDef.runner(page, csrfToken);
        await browser.close();
        return result;
    } catch (err) {
        if (browser) try { await browser.close(); } catch (_) {}
        return {
            filename: `${testKey}_test.pdf`, description: `${testDef.label} — failed`,
            test_category: 'Shapes', section_name: testDef.label, status: 'error',
            checks: [], checks_passed: 0, checks_total: 0, page_count: 0,
            file_size: 0, error: err.message, warnings: [],
        };
    }
}

/**
 * Run a single shape test end-to-end (launches its own browser).
 * Used by --single-shape for individual debugging.
 */
async function runSingleShapeTest(shapeType) {
    // Check if it's an advanced test key
    const advTest = ADVANCED_TESTS.find(t => t.key === shapeType);
    if (advTest) return runAdvancedTest(shapeType);

    const shapeDef = SHAPE_TYPES.find(s => s.type === shapeType);
    if (!shapeDef) {
        return {
            filename: `${shapeType}_frontend_test.pdf`,
            description: `Unknown shape type: ${shapeType}`,
            test_category: 'Shapes',
            section_name: shapeType,
            status: 'error',
            checks: [],
            checks_passed: 0,
            checks_total: 0,
            page_count: 0,
            file_size: 0,
            error: `Unknown shape type: ${shapeType}`,
            warnings: [],
        };
    }

    let browser = null;
    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
        });
        const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const page = await context.newPage();

        const csrfToken = await fetchCsrfToken(page);
        const result = await runShapeOnPage(page, csrfToken, shapeDef);

        await browser.close();
        return result;
    } catch (err) {
        if (browser) try { await browser.close(); } catch (_) {}
        return {
            filename: `${shapeType}_frontend_test.pdf`,
            description: `${shapeDef.label} — frontend shape test`,
            test_category: 'Shapes',
            section_name: shapeDef.label,
            status: 'error',
            checks: [],
            checks_passed: 0,
            checks_total: 0,
            page_count: 0,
            file_size: 0,
            error: err.message,
            warnings: [],
        };
    }
}

/**
 * Run ALL shape tests in PARALLEL using concurrent browser pages.
 * Launches one browser, gets CSRF once, then runs N shapes at a time
 * each in their own page (tab). Much faster than sequential.
 *
 * CONCURRENCY controls how many shapes run simultaneously.
 * Too high may overwhelm the Laravel server; 4 is a good balance.
 */
async function runAllShapeTests() {
    const CONCURRENCY = 4;
    const totalCount = SHAPE_TYPES.length + ADVANCED_TESTS.length;
    const results = new Array(totalCount);
    let browser = null;

    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
        });

        // Get CSRF token once using a throwaway page
        const initContext = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const initPage = await initContext.newPage();
        const csrfToken = await fetchCsrfToken(initPage);
        // Extract cookies so we can inject them into parallel contexts
        const cookies = await initContext.cookies();
        await initContext.close();

        // ALL tasks: 8 basic shapes + 3 advanced tests = 11 total
        const allTasks = [
            ...SHAPE_TYPES.map(sd => ({ type: 'shape', shapeDef: sd })),
            ...ADVANCED_TESTS.map(at => ({ type: 'advanced', advTest: at })),
        ];

        // Process in parallel batches
        let idx = 0;
        while (idx < totalCount) {
            const batch = allTasks.slice(idx, idx + CONCURRENCY);
            const batchPromises = batch.map(async (task, batchIdx) => {
                const globalIdx = idx + batchIdx;
                const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
                await ctx.addCookies(cookies);
                const page = await ctx.newPage();

                try {
                    if (task.type === 'shape') {
                        results[globalIdx] = await runShapeOnPage(page, csrfToken, task.shapeDef);
                    } else {
                        results[globalIdx] = await task.advTest.runner(page, csrfToken);
                    }
                } catch (err) {
                    const label = task.type === 'shape' ? task.shapeDef.label : task.advTest.label;
                    const fname = task.type === 'shape' ? `${task.shapeDef.type}_frontend_test.pdf` : `${task.advTest.key}_test.pdf`;
                    results[globalIdx] = {
                        filename: fname,
                        description: `${label} — test failed`,
                        test_category: 'Shapes',
                        section_name: label,
                        status: 'error',
                        checks: [],
                        checks_passed: 0,
                        checks_total: 0,
                        page_count: 0,
                        file_size: 0,
                        error: err.message,
                        warnings: [],
                    };
                } finally {
                    await ctx.close().catch(() => {});
                }
            });
            await Promise.all(batchPromises);
            idx += CONCURRENCY;
        }

        await browser.close();
    } catch (err) {
        if (browser) try { await browser.close(); } catch (_) {}
        const allTasks = [
            ...SHAPE_TYPES.map(sd => ({ label: sd.label, fname: `${sd.type}_frontend_test.pdf` })),
            ...ADVANCED_TESTS.map(at => ({ label: at.label, fname: `${at.key}_test.pdf` })),
        ];
        for (let i = 0; i < allTasks.length; i++) {
            if (!results[i]) {
                results[i] = {
                    filename: allTasks[i].fname,
                    description: `${allTasks[i].label} — test failed`,
                    test_category: 'Shapes',
                    section_name: allTasks[i].label,
                    status: 'error',
                    checks: [],
                    checks_passed: 0,
                    checks_total: 0,
                    page_count: 0,
                    file_size: 0,
                    error: `Browser-level failure: ${err.message}`,
                    warnings: [],
                };
            }
        }
    }

    return results;
}

/**
 * List available shape tests (called by ShapeTestController)
 * Includes the 8 basic shape tests + 3 advanced tests.
 */
function listFiles() {
    const files = [
        ...SHAPE_TYPES.map(s => ({
            filename: `${s.type}_frontend_test.pdf`,
            path: s.type,
            description: `${s.label} — frontend shape tool test`,
            test_category: 'Shapes',
            section_name: s.label,
        })),
        ...ADVANCED_TESTS.map(t => ({
            filename: `${t.key}_test.pdf`,
            path: t.key,
            description: t.description,
            test_category: 'Shapes',
            section_name: t.label,
        })),
    ];

    return {
        total: files.length,
        files,
    };
}

// ======= CLI entry point =======
async function main() {
    const args = process.argv.slice(2);

    if (args.includes('--list-files')) {
        console.log(JSON.stringify(listFiles()));
    } else if (args.includes('--single-shape')) {
        const idx = args.indexOf('--single-shape');
        const shapeType = args[idx + 1];
        if (!shapeType) {
            console.error('Missing shape type argument');
            process.exit(1);
        }
        const result = await runSingleShapeTest(shapeType);
        console.log(JSON.stringify(result));
    } else if (args.includes('--run-all')) {
        const results = await runAllShapeTests();
        console.log(JSON.stringify(results));
    } else {
        console.error('Usage: node run_shape_tests.cjs --list-files | --single-shape <type> | --run-all');
        process.exit(1);
    }
}

main().catch(err => {
    console.error(JSON.stringify({ error: err.message }));
    process.exit(1);
});
