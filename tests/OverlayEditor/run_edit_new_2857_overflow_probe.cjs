#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const URL = 'http://localhost:8081/documents/2857/edit-new';
const TARGET = 'Under penalties of perjury';

async function openTargetEditor(page) {
    await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForTimeout(5000);

    const directHit = await page.evaluate(({ target }) => {
        const candidates = Array.from(document.querySelectorAll('body *')).filter((el) => {
            if (!(el instanceof HTMLElement)) return false;
            const text = String(el.innerText || '').replace(/\s+/g, ' ').trim();
            if (!text.includes(target)) return false;
            const rect = el.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0;
        }).sort((a, b) => {
            const ar = a.getBoundingClientRect();
            const br = b.getBoundingClientRect();
            return (ar.width * ar.height) - (br.width * br.height);
        });

        const el = candidates[0];
        if (!(el instanceof HTMLElement)) return false;
        const rect = el.getBoundingClientRect();
        const clientX = rect.left + Math.min(rect.width / 2, 24);
        const clientY = rect.top + Math.min(rect.height / 2, 12);
        el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX, clientY }));
        el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX, clientY }));
        el.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX, clientY }));
        el.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX, clientY }));
        const editor = document.querySelector('.active-editor[style*="display:block"]');
        return Boolean(editor && String(editor.innerText || '').includes(target));
    }, { target: TARGET });

    if (directHit) {
        await page.waitForTimeout(250);
        return;
    }

    const opened = await page.evaluate(({ target }) => {
        const canvases = Array.from(document.querySelectorAll('.overlay-canvas'));
        for (const canvas of canvases) {
            const rect = canvas.getBoundingClientRect();
            for (let y = 4; y < rect.height; y += 4) {
                for (let x = 4; x < rect.width; x += 4) {
                    const clientX = rect.left + x;
                    const clientY = rect.top + y;
                    const hit = document.elementFromPoint(clientX, clientY);
                    if (hit !== canvas) continue;
                    canvas.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX, clientY }));
                    canvas.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX, clientY }));
                    const editor = document.querySelector('.active-editor[style*="display:block"]');
                    if (editor && String(editor.innerText || '').includes(target)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }, { target: TARGET });

    if (!opened) {
        const debug = await page.evaluate(({ target }) => {
            const bodyTextHasTarget = String(document.body.innerText || '').includes(target);
            const matching = Array.from(document.querySelectorAll('body *'))
                .filter((el) => {
                    if (!(el instanceof HTMLElement)) return false;
                    const text = String(el.innerText || '').replace(/\s+/g, ' ').trim();
                    if (!text.includes(target)) return false;
                    const rect = el.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0;
                })
                .slice(0, 10)
                .map((el) => {
                    const rect = el.getBoundingClientRect();
                    return {
                        tag: el.tagName,
                        className: el.className,
                        text: String(el.innerText || '').slice(0, 200),
                        rect: { left: rect.left, top: rect.top, width: rect.width, height: rect.height },
                    };
                });
            return {
                bodyTextHasTarget,
                canvasCount: document.querySelectorAll('.overlay-canvas').length,
                pageContentCount: document.querySelectorAll('.page-content').length,
                activeEditors: document.querySelectorAll('.active-editor').length,
                matching,
            };
        }, { target: TARGET });
        throw new Error(`failed to activate target editor: ${JSON.stringify(debug)}`);
    }

    await page.waitForTimeout(250);
}

async function readState(page) {
    return page.evaluate(() => {
        const editor = document.querySelector('.active-editor[style*="display:block"]');
        if (!(editor instanceof HTMLElement)) {
            return { found: false };
        }

        const editorRect = editor.getBoundingClientRect();
        const lines = Array.from(editor.children).map((line, index) => {
            const rect = line.getBoundingClientRect();
            const style = getComputedStyle(line);
            return {
                index,
                text: String(line.textContent || ''),
                width: rect.width,
                height: rect.height,
                scrollWidth: line.scrollWidth || 0,
                offsetWidth: line.offsetWidth || 0,
                transform: style.transform,
                fontFamily: style.fontFamily,
                fontSize: style.fontSize,
                lineHeight: style.lineHeight,
                whiteSpace: style.whiteSpace,
                overflowWrap: style.overflowWrap,
                wordBreak: style.wordBreak,
            };
        });

        return {
            found: true,
            editorRect: {
                width: editorRect.width,
                height: editorRect.height,
                left: editorRect.left,
                top: editorRect.top,
            },
            renderMode: editor.dataset.renderMode || null,
            lines,
            html: editor.innerHTML,
        };
    });
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
    try {
        await openTargetEditor(page);
        const state = await readState(page);
        console.log(JSON.stringify(state, null, 2));
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error.stack || String(error));
    process.exit(1);
});
