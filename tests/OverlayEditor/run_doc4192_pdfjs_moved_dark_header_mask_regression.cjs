#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4192);
const TARGET_ID = process.env.TARGET_ID || 'pdfjs_4192_0_0:14';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const OUTPUT_DIR = path.resolve(__dirname, 'output');

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

    throw new Error('Unable to log in to admin for doc4192 regression test.');
}

async function main() {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1800, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1&t=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 120000 });
        await page.evaluate(() => {
            const toggle = document.getElementById('edit-mode-toggle') || document.getElementById('ftb-edit-mode');
            if (!toggle) throw new Error('Missing PDF.js edit mode toggle');
            if (!document.body.classList.contains('enpv-edit-on')) toggle.click();
        });
        await page.waitForSelector('body.enpv-edit-on .enpv-annotation-box-layer .enpv-annotation-box', { timeout: 120000 });
        await page.waitForTimeout(500);

        const target = page.locator(`.enpv-annotation-box[data-annotation-id="${TARGET_ID}"]`).first();
        await target.waitFor({ timeout: 60000 });
        const beforeBox = await target.boundingBox();
        if (!beforeBox) throw new Error(`Unable to locate annotation ${TARGET_ID}`);

        await page.mouse.move(beforeBox.x + beforeBox.width / 2, beforeBox.y + beforeBox.height / 2);
        await page.mouse.down();
        await page.mouse.move(beforeBox.x + beforeBox.width / 2 + 36, beforeBox.y + beforeBox.height / 2 + 4, { steps: 8 });
        await page.mouse.up();
        await page.waitForTimeout(1200);

        const result = await page.evaluate((targetId) => {
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${CSS.escape(targetId)}"]`);
            const pageDiv = box?.closest('.page');
            const mask = box?._enpvSourceMask || null;
            if (!box || !pageDiv || !mask) return { error: 'missing moved annotation source mask' };
            const bg = getComputedStyle(mask).backgroundColor;
            const match = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
            const rgb = match ? match.slice(1, 4).map((value) => Number.parseInt(value, 10) || 0) : null;
            const luminance = rgb ? ((0.299 * rgb[0]) + (0.587 * rgb[1]) + (0.114 * rgb[2])) : 255;
            const pageRect = pageDiv.getBoundingClientRect();
            const maskRect = mask.getBoundingClientRect();
            return {
                backgroundColor: bg,
                luminance,
                movedTextOverlay: box.dataset.movedTextOverlay || '',
                dxPts: Number(box.dataset.dxPts || 0),
                mask: {
                    left: maskRect.left - pageRect.left,
                    top: maskRect.top - pageRect.top,
                    width: maskRect.width,
                    height: maskRect.height,
                },
            };
        }, TARGET_ID);

        if (result.error) throw new Error(result.error);
        if (result.movedTextOverlay !== '1' || Math.abs(result.dxPts) <= 1) {
            throw new Error(`Annotation did not move as a source overlay: ${JSON.stringify(result)}`);
        }
        if (!(result.luminance < 80)) {
            throw new Error(`Moved dark-header source mask should stay dark, got ${JSON.stringify(result)}`);
        }

        const requestPromise = page.waitForRequest((request) => (
            request.method() === 'POST' && request.url().includes('/download-annotated-pdf')
        ), { timeout: 120000 });
        await page.evaluate(() => {
            const button = document.getElementById('download-pdf-btn') || document.getElementById('enpv-download');
            if (!button) throw new Error('Missing PDF.js download button');
            button.click();
        });
        const request = await requestPromise;
        const payload = request.postDataJSON();
        const movedAnnotation = payloadAnnotations(payload).find((annotation) => String(annotation.id || '') === TARGET_ID);
        if (!movedAnnotation) throw new Error(`Missing moved annotation ${TARGET_ID} from download payload`);

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
        fs.mkdirSync(OUTPUT_DIR, { recursive: true });
        const outputPdf = path.join(OUTPUT_DIR, `doc4192_pdfjs_moved_dark_header_${Date.now()}.pdf`);
        fs.writeFileSync(outputPdf, await response.body());

        const sourceMask = {
            x: Number(movedAnnotation.pdfjsSourceMaskX ?? movedAnnotation.pdfjsSourceX),
            y: Number(movedAnnotation.pdfjsSourceMaskY ?? movedAnnotation.pdfjsSourceY),
            w: Number(movedAnnotation.pdfjsSourceMaskW ?? movedAnnotation.pdfjsSourceW),
            h: Number(movedAnnotation.pdfjsSourceMaskH ?? movedAnnotation.pdfjsSourceH),
            text: String(movedAnnotation.pdfjsSourceText || movedAnnotation.originalText || movedAnnotation.text || ''),
        };
        if (![sourceMask.x, sourceMask.y, sourceMask.w, sourceMask.h].every(Number.isFinite)
            || sourceMask.w <= 0 || sourceMask.h <= 0) {
            throw new Error(`Missing source-mask geometry in download payload: ${JSON.stringify(movedAnnotation)}`);
        }
        const inspect = spawnSync('python3', ['-'], {
            encoding: 'utf8',
            input: `
import fitz, json, sys
pdf_path = ${JSON.stringify(outputPdf)}
mask = json.loads(${JSON.stringify(JSON.stringify(sourceMask))})
doc = fitz.open(pdf_path)
page = doc[0]
rect = fitz.Rect(mask['x'], page.rect.height - (mask['y'] + mask['h']), mask['x'] + mask['w'], page.rect.height - mask['y']) & page.rect
if rect.is_empty:
    raise SystemExit('empty source mask rect')
pad_x = 2.0
pad_y = max(1.0, float(mask['h']) * 0.18)
drawn_rect = fitz.Rect(mask['x'] - pad_x, page.rect.height - (mask['y'] + mask['h']) - pad_y, mask['x'] + mask['w'] + pad_x, page.rect.height - mask['y'] + pad_y) & page.rect
pix = page.get_pixmap(matrix=fitz.Matrix(6, 6), clip=rect, alpha=False)
channels = max(1, int(getattr(pix, 'n', 3) or 3))
samples = pix.samples
dark = 0
bright = 0
total = max(1, pix.width * pix.height)
for offset in range(0, len(samples), channels):
    if offset + 2 >= len(samples):
        continue
    luminance = (0.299 * samples[offset]) + (0.587 * samples[offset + 1]) + (0.114 * samples[offset + 2])
    if luminance < 80:
        dark += 1
    if luminance > 220:
        bright += 1
dark_ratio = dark / total
bright_ratio = bright / total
if dark_ratio < 0.62 or bright_ratio > 0.22:
    raise SystemExit(f'downloaded source mask left a white/light box: dark_ratio={dark_ratio:.4f} bright_ratio={bright_ratio:.4f} rect={rect}')
source_tokens = set(str(mask.get('text') or '').replace('/', ' ').split())
if not source_tokens:
    source_tokens = set(str(mask.get('sourceText') or '').replace('/', ' ').split())
word_rects = []
search_rect = fitz.Rect(drawn_rect.x0 - 1, drawn_rect.y0 - 1, drawn_rect.x1 + 1, drawn_rect.y1 + 1) & page.rect
for word in page.get_text('words'):
    x0, y0, x1, y1, text, *_ = word
    word_rect = fitz.Rect(x0, y0, x1, y1)
    if word_rect.is_empty or (word_rect & search_rect).is_empty:
        continue
    if source_tokens and str(text) not in source_tokens:
        continue
    word_rects.append(word_rect)
if word_rects:
    word_union = fitz.Rect(word_rects[0])
    for word_rect in word_rects[1:]:
        word_union |= word_rect
    top_band = fitz.Rect(word_union.x0 - 0.5, word_union.y0 - 0.25, word_union.x1 + 0.5, min(word_union.y1 + 0.5, word_union.y0 + 3.25)) & drawn_rect
    if top_band.is_empty:
        top_band = word_union & drawn_rect
    band_pix = page.get_pixmap(matrix=fitz.Matrix(8, 8), clip=top_band, alpha=False)
    band_channels = max(1, int(getattr(band_pix, 'n', 3) or 3))
    band_total = max(1, band_pix.width * band_pix.height)
    band_bright = 0
    for offset in range(0, len(band_pix.samples), band_channels):
        if offset + 2 >= len(band_pix.samples):
            continue
        luminance = (0.299 * band_pix.samples[offset]) + (0.587 * band_pix.samples[offset + 1]) + (0.114 * band_pix.samples[offset + 2])
        if luminance > 180:
            band_bright += 1
    band_bright_ratio = band_bright / band_total
    if band_bright_ratio > 0.08:
        raise SystemExit(f'downloaded source mask left bright source-glyph fragments: band_bright_ratio={band_bright_ratio:.4f} word_union={word_union} rect={rect}')
else:
    band_bright_ratio = None
print({'dark_ratio': round(dark_ratio, 4), 'bright_ratio': round(bright_ratio, 4), 'band_bright_ratio': None if band_bright_ratio is None else round(band_bright_ratio, 4), 'pdf': pdf_path})
`,
        });
        fs.rmSync(outputPdf, { force: true });
        if (inspect.status !== 0) {
            throw new Error(`Downloaded PDF dark header mask inspection failed:\n${inspect.stdout || ''}${inspect.stderr || ''}`);
        }

        console.log('doc4192 pdfjs moved dark header mask regression passed');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});