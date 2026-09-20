#!/usr/bin/env node
/*
 * Delta saves, in a real editor (resources/js/edit-new-pdfjs/delta-save.js).
 *
 * The suites run with the legacy full-state saves (the editor turns deltas off
 * for automated browsers, because the suites read the whole state out of save
 * requests). This script opts in with window.__enpvDeltaSaves and checks:
 *
 *   - the first save after a load carries everything, later ones carry what
 *     changed, what is gone and the count, in a few KB on a 500-annotation page;
 *   - an inserted image is uploaded on its own and never travels as base64
 *     inside a save;
 *   - after a reload everything is there.
 *
 * Usage: AUTOMATED_TEST_BASE_URL=http://localhost:8081 node tests/OverlayEditor/run_delta_save_regression.cjs
 */
'use strict';

const path = require('path');
const { chromium } = require('playwright');

const BASE_URL = process.env.AUTOMATED_TEST_BASE_URL || 'http://localhost';
const IMAGE = path.resolve(__dirname, '..', '..', 'public', 'images', 'sky_bg_dark_mode.png');
const SEEDED = 500;

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.addInitScript(() => { window.__enpvDeltaSaves = true; });
    const saves = [];
    const uploads = [];
    let lastBody = null;
    const checks = [];
    const check = (name, ok, detail = '') => { checks.push({ name, ok: !!ok, detail }); };

    try {
        await page.goto(`${BASE_URL}/pdf-editor`, { waitUntil: 'domcontentloaded' });
        const token = await page.evaluate(() => document.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content);
        const created = await context.request.post(`${BASE_URL}/documents/create-blank`, { form: { _token: token, page_size: 'A4' }, maxRedirects: 0 });
        const docId = Number((created.headers().location || '').match(/\/documents\/(\d+)/)?.[1]);
        if (!docId) throw new Error(`could not create a document (HTTP ${created.status()})`);

        page.on('response', (response) => {
            const request = response.request();
            if (request.method() !== 'POST') return;
            const body = request.postData() || '';
            if (request.url().endsWith('/save-annotation-state')) {
                let json = {};
                try { json = JSON.parse(body); } catch (_) { json = {}; }
                lastBody = json;
                saves.push({ status: response.status(), bytes: body.length, delta: json.delta === true, sent: (json.annotations || []).length, removed: (json.removed_ids || []).length, expected: json.expected_count ?? null, base64: body.includes('data:image/') });
            } else if (/\/annotation-assets$/.test(request.url())) {
                uploads.push({ status: response.status(), bytes: body.length });
            }
        });

        await page.goto(`${BASE_URL}/documents/${docId}/edit-pdfjs`, { waitUntil: 'load' });
        await page.waitForSelector('#viewerContainer .page', { timeout: 30000 });
        await page.waitForTimeout(1200);

        // What a long editing session leaves behind: 500 text annotations. They are
        // copies of one the editor made itself (hand-built objects lack fields the
        // editor sets, and its own filters then do not treat them as its state).
        const pageBoxBefore = await page.locator('#viewerContainer .page').first().boundingBox();
        await page.click('#ftb-add-text');
        await page.mouse.click(pageBoxBefore.x + pageBoxBefore.width * 0.75, pageBoxBefore.y + pageBoxBefore.height * 0.04);
        await page.keyboard.type('Template');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(3500);
        const template = lastBody?.annotations?.find((annotation) => String(annotation.text || '').includes('Template'));
        if (!template) throw new Error('the editor did not save the template box');

        const seeded = await page.evaluate(async ([id, count, source]) => {
            const annotations = [source];
            for (let i = 0; i < count - 1; i += 1) {
                const dx = -Number(source.pdfX) + 30 + (i % 10) * 55;
                const dy = -Number(source.pdfY) + 330 + Math.floor(i / 10) * 10;
                const copy = JSON.parse(JSON.stringify(source));
                copy.id = String(source.id).replace(/[0-9a-f]{8}-[0-9a-f-]{27}$/i, crypto.randomUUID());
                copy.text = `Item ${i}`;
                if ('richTextHtml' in copy) copy.richTextHtml = '';
                for (const [key, delta] of [['pdfX', dx], ['pdfY', dy]]) copy[key] = Number(source[key]) + delta;
                annotations.push(copy);
            }
            const response = await fetch(`/documents/${id}/save-annotation-state`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ annotations, session_id: localStorage.getItem(`edit_new_session_${id}`), acro_form_entries: [] }),
            });
            return response.status;
        }, [docId, SEEDED, template]);
        check('seeded', seeded === 200, `HTTP ${seeded}`);

        saves.length = 0;
        await page.reload({ waitUntil: 'load' });
        await page.waitForSelector('#viewerContainer .page', { timeout: 30000 });
        await page.waitForTimeout(3000);

        const loaded = await page.evaluate(() => document.querySelectorAll('.enpv-annotation-box').length);
        check('seeded-state-loaded', loaded === 500, `${loaded} boxes after the reload`);

        const pageBox = await page.locator('#viewerContainer .page').first().boundingBox();
        const typeAt = async (fx, fy, text) => {
            await page.click('#ftb-add-text');
            await page.mouse.click(pageBox.x + pageBox.width * fx, pageBox.y + pageBox.height * fy);
            await page.keyboard.type(text);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(3500);
        };
        await typeAt(0.3, 0.05, 'first new box');    // the first save after a load: everything
        // The copies above were moved by hand, so the editor re-syncs them once
        // after rendering: let that pass before measuring.
        await typeAt(0.3, 0.12, 'second new box');
        const settled = saves.length;
        await typeAt(0.3, 0.19, 'third new box');
        await typeAt(0.3, 0.26, 'fourth new box');

        const first = saves[0];
        const later = saves.slice(settled);
        check('every-save-accepted', saves.length >= 4 && saves.every((save) => save.status === 200), JSON.stringify(saves.map((save) => save.status)));
        check('first-save-is-full', first && !first.delta && first.sent >= SEEDED, JSON.stringify(first));
        check('later-saves-are-deltas', later.length >= 2 && later.every((save) => save.delta && save.sent <= 3 && save.expected >= SEEDED), JSON.stringify(later));
        check('a-delta-is-a-few-kb', later.every((save) => save.bytes < 8 * 1024) && first && first.bytes > 100 * 1024, `first ${first?.bytes} B, then ${later.map((save) => save.bytes).join(', ')} B`);

        // Removing: the id travels in removed_ids and the count goes down by one.
        await typeAt(0.3, 0.33, 'to be deleted');   // saved, and still selected after Escape
        const beforeDelete = saves.length;
        // Undo takes the box away again: for the save that is a removal.
        await page.evaluate(() => document.body.click());
        await page.keyboard.press('Control+z');
        await page.waitForTimeout(4500);
        const deleteSaves = saves.slice(beforeDelete);
        check('a-deletion-travels-as-a-removed-id', deleteSaves.some((save) => save.status === 200 && save.delta && save.removed === 1 && save.expected === SEEDED + 4), JSON.stringify(deleteSaves));

        // An image: uploaded on its own, then referenced.
        const beforeImage = saves.length;
        await page.evaluate(() => document.getElementById('ftb-add-image')?.click());
        await page.waitForSelector('#image-import-file', { state: 'attached', timeout: 10000 });
        await page.setInputFiles('#image-import-file', IMAGE);
        await page.waitForFunction(() => !document.getElementById('image-import-apply')?.disabled, null, { timeout: 15000 });
        await page.evaluate(() => document.getElementById('image-import-apply')?.click());
        await page.waitForTimeout(800);
        await page.mouse.click(pageBox.x + pageBox.width * 0.7, pageBox.y + pageBox.height * 0.1);
        await page.waitForTimeout(6000);

        const imageSaves = saves.slice(beforeImage);
        check('image-uploaded-once-on-its-own', uploads.length === 1 && uploads[0].status === 201, JSON.stringify(uploads));
        check('no-base64-in-any-save', saves.every((save) => !save.base64), JSON.stringify(imageSaves));
        check('image-save-is-a-delta', imageSaves.length >= 1 && imageSaves.every((save) => save.delta && save.bytes < 8 * 1024), JSON.stringify(imageSaves));

        await page.reload({ waitUntil: 'load' });
        await page.waitForSelector('#viewerContainer .page', { timeout: 30000 });
        await page.waitForTimeout(3500);
        const after = await page.evaluate(() => ({
            boxes: document.querySelectorAll('.enpv-annotation-box').length,
            texts: ['first new box', 'second new box', 'third new box', 'fourth new box', 'Template', 'Item 0', 'Item 498'].map((text) => Array.from(document.querySelectorAll('.enpv-annotation-box')).some((box) => box.textContent.includes(text))),
            image: !!document.querySelector('.enpv-annotation-box img[src*="/annotation-assets/"]'),
            deletedIsBack: Array.from(document.querySelectorAll('.enpv-annotation-box')).some((box) => box.textContent.includes('to be deleted')),
        }));
        check('everything-is-there-after-a-reload', after.boxes === SEEDED + 5 && !after.deletedIsBack && after.texts.every(Boolean) && after.image, JSON.stringify(after));
    } catch (error) {
        check('ran-to-the-end', false, String(error.stack || error).slice(0, 500));
    } finally {
        await browser.close();
    }

    const failed = checks.filter((entry) => !entry.ok);
    console.log(JSON.stringify({ success: failed.length === 0, checks }, null, 1));
    process.exit(failed.length === 0 ? 0 : 1);
})();
