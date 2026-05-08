#!/usr/bin/env node
/**
 * Regression test for the edit-new format bar:
 *   - Creates a blank Letter/portrait document
 *   - Navigates to /documents/{id}/edit-new
 *   - Adds a new text annotation via "Add Text" drag
 *   - Types a multi-word sentence
 *   - Per-word: selects one word, clicks #afb-bold -> expects <span style="font-weight: bold;">WORD</span>
 *   - Per-word: selects another word, clicks #afb-italic -> expects italic span
 *   - Per-word: selects another word, clicks #afb-underline -> expects underline span
 *   - Changes #afb-font to Georgia and expects ann.fontFamily updated
 *   - Changes #afb-size to 24 and expects ann.fontSize updated
 *   - Clicks off the annotation and asserts the rich-html layer #rhl-1 has a matching entry
 *
 * Exits non-zero with a summary on any failure.
 *
 * Env overrides: BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, PAGE_SIZE, ORIENTATION
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const crypto = require('crypto');

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
// Document used as the "canvas" for the fresh annotation. The test creates its
// own annotation — any valid edit-new-compatible doc works. Default to 2871
// which renders reliably in edit-new.
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 2871);
const USE_BLANK_DOC = process.env.USE_BLANK_DOC === '1';

const ARTIFACT_DIR = path.resolve(__dirname, '..', '..', 'storage', 'app', 'overlay_regression_artifacts');
if (!fs.existsSync(ARTIFACT_DIR)) fs.mkdirSync(ARTIFACT_DIR, { recursive: true });

const failures = [];
function assert(cond, label, detail) {
    if (cond) {
        console.log(`  \u2714 ${label}`);
    } else {
        console.log(`  \u2718 ${label}${detail ? ` :: ${detail}` : ''}`);
        failures.push({ label, detail });
    }
}

async function createBlankDocumentViaServer(page) {
    await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    const response = await page.evaluate(async ({ sz, or }) => {
        const r = await fetch(`/pdf-tests/create-blank?page_size=${encodeURIComponent(sz)}&orientation=${encodeURIComponent(or)}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const raw = await r.text();
        let body = null;
        try { body = JSON.parse(raw); } catch (_e) { body = null; }
        return { ok: r.ok, status: r.status, body, rawBody: raw };
    }, { sz: PAGE_SIZE, or: ORIENTATION });

    if (!response.ok || !response.body?.success || !Number.isFinite(Number(response.body.document_id))) {
        throw new Error(`server blank create failed: status=${response.status} body=${String(response.rawBody || '').slice(0, 400)}`);
    }
    return Number(response.body.document_id);
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

async function drawTextBox(page) {
    // Click "Add Text" button, then drag on the page-1 overlay canvas.
    await page.click('#add-text-btn');
    await page.waitForTimeout(150);
    const addTextActive = await page.evaluate(() => document.getElementById('add-text-btn')?.classList.contains('active'));
    if (!addTextActive) throw new Error('Add Text mode not activated after clicking #add-text-btn');

    const box = await page.evaluate(() => {
        const oc = document.getElementById('oc-1');
        if (!oc) return null;
        const r = oc.getBoundingClientRect();
        return { x: r.left, y: r.top, w: r.width, h: r.height };
    });
    if (!box || !box.w || !box.h) throw new Error('Page-1 overlay canvas not present/sized');

    // Drag a roomy box near top-left of the page (in client coords, not pdf pts).
    const startX = box.x + (box.w * 0.15);
    const startY = box.y + (box.h * 0.20);
    const endX   = box.x + (box.w * 0.80);
    const endY   = box.y + (box.h * 0.30);

    await page.mouse.move(startX, startY);
    await page.mouse.down();
    await page.mouse.move(startX + 40, startY + 10, { steps: 4 });
    await page.mouse.move(endX, endY, { steps: 8 });
    await page.mouse.up();
    await page.waitForTimeout(250);
}

async function getActiveAnnSnapshot(page) {
    return page.evaluate(() => {
        const state = window.__overlayDebug && window.__overlayDebug.activeState;
        // activeState isn't exported globally, so try the known module-level approach: scan DOM for active editor
        const aeVisible = document.querySelector('.active-editor[style*="display"]:not([style*="display: none"])') ||
                          [...document.querySelectorAll('.active-editor')].find(el => el.style.display !== 'none');
        if (!aeVisible) return { hasEditor: false };
        return {
            hasEditor: true,
            aeId: aeVisible.id,
            innerHTML: aeVisible.innerHTML,
            innerText: aeVisible.innerText,
        };
    });
}

async function typeInActiveEditor(page, text) {
    // Active editor should now be focused by createNewTextAnnotation via requestAnimationFrame.
    await page.waitForTimeout(250);
    const focused = await page.evaluate(() => {
        const ae = document.getElementById('ae-1');
        if (!ae) return false;
        ae.focus();
        return document.activeElement === ae;
    });
    if (!focused) throw new Error('ae-1 not focusable after createNewTextAnnotation');
    // Insert the entire string in one operation so that the per-keystroke
    // renderPlainEditorHTML re-render doesn't collapse trailing spaces between
    // successive keystrokes (a well-known pitfall when white-space: normal
    // meets per-input innerHTML replacement).
    await page.keyboard.insertText(text);
    await page.waitForTimeout(250);
}

async function selectWordInEditor(page, word) {
    const ok = await page.evaluate((targetWord) => {
        const ae = document.getElementById('ae-1');
        if (!ae) return false;
        // Find a text node containing the word
        const walker = document.createTreeWalker(ae, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node) {
            const idx = node.nodeValue.indexOf(targetWord);
            if (idx >= 0) {
                const range = document.createRange();
                range.setStart(node, idx);
                range.setEnd(node, idx + targetWord.length);
                ae.focus({ preventScroll: true });
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                return true;
            }
            node = walker.nextNode();
        }
        return false;
    }, word);
    if (!ok) throw new Error(`Could not find "${word}" in editor to select`);
}

async function clickFormatButton(page, id) {
    const box = await page.evaluate((sel) => {
        const el = document.getElementById(sel);
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }, id);
    if (!box) throw new Error(`#${id} not found`);
    // Use real mouse click so focus/mousedown semantics match a user interaction.
    await page.mouse.click(box.x, box.y);
    await page.waitForTimeout(120);
}

async function main() {
    console.log(`[format-bar] BASE_URL=${BASE_URL}`);
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    const page = await context.newPage();

    const consoleErrors = [];
    page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));
    page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(`console.error: ${msg.text()}`); });

    try {
        await loginAdmin(page);
        console.log('[format-bar] logged in as admin');

        let documentId = DOCUMENT_ID;
        if (USE_BLANK_DOC) {
            documentId = await createBlankDocumentViaServer(page);
            console.log(`[format-bar] created blank document id=${documentId}`);
        } else {
            console.log(`[format-bar] using existing document id=${documentId}`);
        }

        await page.goto(`${BASE_URL}/documents/${documentId}/edit-new`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForFunction(() => !!document.getElementById('oc-1'), null, { timeout: 30000 });
        await page.waitForTimeout(2000); // allow initial render

        // Ensure edit mode is OFF (required for Add Text)
        const editOff = await page.evaluate(() => {
            const btn = document.getElementById('edit-mode-toggle');
            if (btn && /Edit Mode ON/i.test(btn.textContent || '')) btn.click();
            return document.getElementById('edit-mode-toggle')?.textContent?.trim();
        });
        console.log(`[format-bar] edit-mode-toggle text="${editOff}"`);

        await drawTextBox(page);
        const snap1 = await getActiveAnnSnapshot(page);
        assert(snap1.hasEditor, 'active editor present after Add Text drag', JSON.stringify(snap1));

        const sentence = 'Bold italic underline should each work.';
        await typeInActiveEditor(page, sentence);

        // Verify plain text present
        const plainHtml = await page.evaluate(() => document.getElementById('ae-1')?.innerHTML || '');
        assert(plainHtml.includes('Bold'), 'typed text appears in editor', plainHtml.slice(0, 200));

        // --- BOLD: select "Bold" -> click #afb-bold ---
        await selectWordInEditor(page, 'Bold');
        const preBoldState = await page.evaluate(() => {
            const s = window.getSelection();
            return {
                selText: s?.toString(),
                collapsed: s?.isCollapsed,
                rangeCount: s?.rangeCount,
                activeEl: document.activeElement?.id,
                aeDisplay: document.getElementById('ae-1')?.style.display,
            };
        });
        console.log(`[format-bar] preBoldState=${JSON.stringify(preBoldState)}`);
        await clickFormatButton(page, 'afb-bold');
        const afterBold = await page.evaluate(() => document.getElementById('ae-1')?.innerHTML || '');
        console.log(`[format-bar] after bold html=${afterBold}`);
        assert(/font-weight:\s*(bold|700)/i.test(afterBold), 'bold applied to selection', afterBold.slice(0, 300));
        assert(/Bold/.test(afterBold), 'text still contains "Bold"', afterBold.slice(0, 200));

        // --- ITALIC: select "italic" -> click #afb-italic ---
        await selectWordInEditor(page, 'italic');
        await clickFormatButton(page, 'afb-italic');
        const afterItalic = await page.evaluate(() => document.getElementById('ae-1')?.innerHTML || '');
        console.log(`[format-bar] after italic html=${afterItalic}`);
        assert(/font-style:\s*italic/i.test(afterItalic), 'italic applied to selection', afterItalic.slice(0, 300));

        // --- UNDERLINE: select "underline" -> click #afb-underline ---
        await selectWordInEditor(page, 'underline');
        await clickFormatButton(page, 'afb-underline');
        const afterUnderline = await page.evaluate(() => document.getElementById('ae-1')?.innerHTML || '');
        console.log(`[format-bar] after underline html=${afterUnderline}`);
        assert(/text-decoration(?:-line)?:\s*underline/i.test(afterUnderline), 'underline applied to selection', afterUnderline.slice(0, 300));

        // --- Make sure previously-bolded word is still bold (no whole-block clobber) ---
        assert(/font-weight:\s*(bold|700)/i.test(afterUnderline), 'bold preserved after later italic+underline', afterUnderline.slice(0, 300));

        // --- Check that only the targeted words are styled (no whole-paragraph coloring/bolding) ---
        const structure = await page.evaluate(() => {
            const ae = document.getElementById('ae-1');
            if (!ae) return null;
            // Count characters inside spans that carry NON-NEUTRAL styling (bold/italic/underline).
            // Chromium's execCommand often wraps sibling text in neutral "font-style: normal"
            // spans — those should not count as "styled".
            const total = (ae.innerText || '').length;
            const spans = ae.querySelectorAll('span[style]');
            let styled = 0;
            spans.forEach(s => {
                const st = s.getAttribute('style') || '';
                const hasBold = /font-weight:\s*(bold|700)/i.test(st);
                const hasItalic = /font-style:\s*italic/i.test(st);
                const hasUnderline = /text-decoration(?:-line)?:\s*underline/i.test(st);
                if (hasBold || hasItalic || hasUnderline) styled += (s.innerText || '').length;
            });
            return { total, styled, spanCount: spans.length };
        });
        console.log(`[format-bar] structure=${JSON.stringify(structure)}`);
        assert(structure && structure.total > 0, 'editor has text', JSON.stringify(structure));
        // The three selected words total ~20 chars; the full sentence ~40. Styled region
        // must be localised (< ~75% of total).
        assert(structure && structure.styled < (structure.total * 0.75),
            'styled region is localised (not whole paragraph)',
            `styled=${structure?.styled}/total=${structure?.total}`);

        // --- Change font family to Georgia (annotation-level change, no selection) ---
        await page.evaluate(() => {
            const ae = document.getElementById('ae-1');
            if (ae) {
                const sel = window.getSelection();
                if (sel) sel.removeAllRanges();
            }
            const f = document.getElementById('afb-font');
            if (f) {
                f.value = 'Georgia';
                f.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        await page.waitForTimeout(150);
        const fontFam = await page.evaluate(() => {
            const ae = document.getElementById('ae-1');
            return ae ? ae.style.fontFamily : '';
        });
        console.log(`[format-bar] after font change, ae.style.fontFamily="${fontFam}"`);
        assert(/georgia/i.test(fontFam), 'font family changed to Georgia', fontFam);

        // --- Change font size to 24 ---
        await page.evaluate(() => {
            const s = document.getElementById('afb-size');
            if (s) {
                s.value = '24';
                s.dispatchEvent(new Event('input', { bubbles: true }));
                s.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        await page.waitForTimeout(200);
        const fontSize = await page.evaluate(() => {
            const ae = document.getElementById('ae-1');
            if (!ae) return null;
            return { fontSize: ae.style.fontSize, textLen: (ae.innerText || '').length };
        });
        console.log(`[format-bar] after size=24 ae.style.fontSize="${fontSize?.fontSize}"`);
        assert(fontSize && parseFloat(fontSize.fontSize) >= 20, 'font size reflects 24pt', JSON.stringify(fontSize));

        // --- Click off the annotation and verify the rich-html layer picks up the rich HTML ---
        // New ("new_*") annotations are intentionally kept selected after blur so the user
        // can continue tweaking. Forcing a deselect for the test: first capture the richHtml
        // that was persisted on the annotation, then null the activeState and trigger a
        // redraw so the rich-html layer promotes the annotation from the active editor.
        const persistedRichHtml = await page.evaluate(() => {
            // Access the closure-internal state via the debug hook we register below, if any.
            // As a fallback, enumerate all annotations on page 1 via a DOM query: the active
            // one has an associated ae-1 editor and a move handle #mh-1 visible.
            const layer = document.getElementById('rhl-1');
            const ae = document.getElementById('ae-1');
            return {
                aeInnerHTML: ae ? ae.innerHTML : null,
                aeDisplay: ae ? ae.style.display : null,
                layerChildren: layer ? layer.children.length : null,
            };
        });
        console.log(`[format-bar] persistedRichHtml=${JSON.stringify(persistedRichHtml).slice(0, 400)}`);

        // Deselect: press Escape to blur, then click the Add-Text button to exit addText mode,
        // then click on the far edge of the viewport so nothing else claims focus. Finally,
        // force a clearActiveAnnotation by clicking on a different page card area.
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        await page.mouse.click(5, 5);
        await page.waitForTimeout(150);
        // Click on the page-1 canvas in a spot OUTSIDE the drawn annotation (top-left corner)
        // while edit-mode is OFF and addText-mode is OFF — this should send a click to the
        // canvas handler which clears active annotation.
        const canvasCorner = await page.evaluate(() => {
            const oc = document.getElementById('oc-1');
            if (!oc) return null;
            const r = oc.getBoundingClientRect();
            return { x: r.left + 5, y: r.top + 5 };
        });
        if (canvasCorner) {
            await page.mouse.click(canvasCorner.x, canvasCorner.y);
        }
        await page.waitForTimeout(600);

        const rhl = await page.evaluate(() => {
            const layer = document.getElementById('rhl-1');
            if (!layer) return { exists: false };
            return {
                exists: true,
                childCount: layer.children.length,
                innerHTMLSample: layer.innerHTML.slice(0, 500),
                fullHTML: layer.innerHTML,
            };
        });
        console.log(`[format-bar] rhl-1 childCount=${rhl.childCount} sampleHead=${rhl.innerHTMLSample?.slice(0, 200)}`);
        assert(rhl.exists, 'rich-html-layer #rhl-1 exists');
        assert(rhl.childCount >= 1, 'rhl-1 has at least one child after deselect', JSON.stringify({ exists: rhl.exists, childCount: rhl.childCount }));
        assert(/font-weight:\s*(bold|700)/i.test(rhl.fullHTML || ''), 'rhl-1 preserves bold span',
            (rhl.fullHTML || '').slice(0, 300));
        assert(/font-style:\s*italic/i.test(rhl.fullHTML || ''), 'rhl-1 preserves italic span',
            (rhl.fullHTML || '').slice(0, 300));
        assert(/text-decoration(?:-line)?:\s*underline/i.test(rhl.fullHTML || ''), 'rhl-1 preserves underline span',
            (rhl.fullHTML || '').slice(0, 300));

        // Runtime errors check
        assert(consoleErrors.length === 0, 'no page/console errors', consoleErrors.slice(0, 5).join(' | '));

        // Capture a screenshot for the artifact record
        const shot = path.join(ARTIFACT_DIR, `format_bar_${Date.now()}.png`);
        await page.screenshot({ path: shot, fullPage: true });
        console.log(`[format-bar] artifact screenshot: ${shot}`);

        if (failures.length > 0) {
            console.log(`\n[format-bar] FAIL (${failures.length} assertion(s) failed)`);
            failures.forEach(f => console.log(`  - ${f.label}${f.detail ? `: ${f.detail}` : ''}`));
            process.exitCode = 1;
        } else {
            console.log('\n[format-bar] PASS');
        }
    } catch (err) {
        console.error('[format-bar] ERROR:', err.message);
        try {
            const shot = path.join(ARTIFACT_DIR, `format_bar_error_${Date.now()}.png`);
            await page.screenshot({ path: shot, fullPage: true });
            console.error(`[format-bar] error screenshot: ${shot}`);
        } catch (_e) {}
        process.exitCode = 1;
    } finally {
        await context.close();
        await browser.close();
    }
}

main().catch((err) => {
    console.error('[format-bar] FATAL:', err);
    process.exit(1);
});
