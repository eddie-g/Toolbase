#!/usr/bin/env node

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const localBrowsers = path.resolve(__dirname, '..', '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) {
    process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
}

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const DOCUMENT_ID = Number(process.env.DOCUMENT_ID || 4174);
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';
const OUTPUT_DIR = path.resolve(__dirname, 'output');
const TARGET_LINE_ID = `pdfjs_${DOCUMENT_ID}_0_shape_974371de-829a-4b3a-8704-111813c25902`;
const TARGET_ITEMS_ID = `pdfjs_${DOCUMENT_ID}_0_new_ab99a509-b52d-4dbd-a9eb-e6ed3b16f19c`;

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

    throw new Error('Unable to log in to admin for regression test.');
}

function analyzePdf(pdfPath) {
    const python = `
import fitz, json, sys
pdf_path = sys.argv[1]
doc = fitz.open(pdf_path)
page = doc[0]
red_lines = []
for drawing in page.get_drawings():
    color = drawing.get('color')
    if not color or color[0] <= .8 or color[1] >= .2 or color[2] >= .2:
        continue
    xs = []
    ys = []
    for item in drawing.get('items', []):
        for value in item[1:]:
            if isinstance(value, fitz.Point):
                xs.append(value.x)
                ys.append(value.y)
    if xs:
        red_lines.append({
            'rect': [round(v, 3) for v in drawing.get('rect')],
            'width': drawing.get('width'),
            'dx': max(xs) - min(xs),
            'dy': max(ys) - min(ys),
        })

zoom = 2
pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), alpha=False)
# TARGET_ITEMS_ID bbox from the saved annotation payload for doc 4174.
x, y, w, h = 138.28125, 494.8125, 39.84375, 15.46875
rect = fitz.Rect(x, page.rect.height - (y + h), x + w, page.rect.height - y)
slate = red = white = other = 0
for yy in range(max(0, int(rect.y0 * zoom)), min(pix.height, int(rect.y1 * zoom))):
    for xx in range(max(0, int(rect.x0 * zoom)), min(pix.width, int(rect.x1 * zoom))):
        r, g, b = pix.pixel(xx, yy)[:3]
        if abs(r - 44) <= 35 and abs(g - 62) <= 35 and abs(b - 80) <= 35:
            slate += 1
        elif r > 180 and g < 110 and b < 110:
            red += 1
        elif r > 220 and g > 220 and b > 220:
            white += 1
        else:
            other += 1
print(json.dumps({
    'red_lines': red_lines,
    'items_pixels': {'slate': slate, 'red': red, 'white': white, 'other': other},
}))
`;
    return JSON.parse(execFileSync('python3', ['-c', python, pdfPath], {
        cwd: path.resolve(__dirname, '..', '..'),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    }));
}

async function downloadPdfViaToolbarRequest(page, outputPath) {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
        && response.url().includes('/download-annotated-pdf')
    ), { timeout: 90000 });
    await page.locator('#download-pdf-btn').click();
    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`download request failed with ${response.status()}: ${await response.text()}`);
    }
    const payload = JSON.parse(response.request().postData() || '{}');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content').catch(() => '');
    const manualResponse = await page.context().request.post(response.url(), {
        data: payload,
        headers: {
            Accept: 'application/pdf, application/json',
            'X-CSRF-TOKEN': csrf || '',
        },
        timeout: 90000,
    });
    if (!manualResponse.ok()) {
        throw new Error(`manual download failed with ${manualResponse.status()}: ${await manualResponse.text()}`);
    }
    fs.writeFileSync(outputPath, await manualResponse.body());
    return payload;
}

async function main() {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    const pdfPath = path.join(OUTPUT_DIR, `doc4174_pdfjs_invoice_shape_text_${Date.now()}.pdf`);
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });

    try {
        await loginAdmin(page);
        await page.goto(`${BASE_URL}/documents/${DOCUMENT_ID}/edit-new?pdfjs=1`, {
            waitUntil: 'domcontentloaded',
            timeout: 90000,
        });
        await page.waitForSelector('body.enpv-viewer-ready .pdfViewer .page[data-page-number="1"]', { timeout: 90000 });
        await page.waitForTimeout(1500);

        const dom = await page.evaluate(({ lineId, itemsId }) => {
            const line = document.querySelector(`[data-annotation-id="${CSS.escape(lineId)}"]`);
            const items = document.querySelector(`[data-annotation-id="${CSS.escape(itemsId)}"]`);
            const svgLine = line?.querySelector('svg line');
            return {
                line: line ? {
                    width: line.getBoundingClientRect().width,
                    lineStartX: line.dataset.lineStartX || '',
                    lineEndX: line.dataset.lineEndX || '',
                    lineStartY: line.dataset.lineStartY || '',
                    lineEndY: line.dataset.lineEndY || '',
                    x1: svgLine?.getAttribute('x1') || '',
                    x2: svgLine?.getAttribute('x2') || '',
                    y1: svgLine?.getAttribute('y1') || '',
                    y2: svgLine?.getAttribute('y2') || '',
                } : null,
                items: items ? {
                    backgroundColor: items.dataset.backgroundColor || '',
                    computedBackground: getComputedStyle(items).backgroundColor,
                    textBackground: getComputedStyle(items.querySelector('.enpv-text-content')).backgroundColor,
                } : null,
            };
        }, { lineId: TARGET_LINE_ID, itemsId: TARGET_ITEMS_ID });

        if (!dom.line) throw new Error(`missing target line ${TARGET_LINE_ID}`);
        if (dom.line.x1 !== dom.line.x2 || Number(dom.line.width) > 8) {
            throw new Error(`line is not vertical in editor state: ${JSON.stringify(dom.line)}`);
        }
        if (!dom.items) throw new Error(`missing ITEMS text ${TARGET_ITEMS_ID}`);
        if (dom.items.backgroundColor !== 'transparent' || dom.items.computedBackground !== 'rgba(0, 0, 0, 0)' || dom.items.textBackground !== 'rgba(0, 0, 0, 0)') {
            throw new Error(`ITEMS has a frontend background: ${JSON.stringify(dom.items)}`);
        }

        const payload = await downloadPdfViaToolbarRequest(page, pdfPath);
        const payloadLine = (payload.annotations || []).find((ann) => ann.id === TARGET_LINE_ID);
        const payloadItems = (payload.annotations || []).find((ann) => ann.id === TARGET_ITEMS_ID);
        if (!payloadLine || Math.abs(Number(payloadLine.lineEndX) - Number(payloadLine.lineStartX)) > 0.001 || Number(payloadLine.pdfWidth) > 3) {
            throw new Error(`download payload did not normalize vertical line: ${JSON.stringify(payloadLine)}`);
        }
        if (!payloadItems || String(payloadItems.backgroundColor || '').toLowerCase() !== 'transparent') {
            throw new Error(`download payload gave ITEMS a background: ${JSON.stringify(payloadItems)}`);
        }

        const pdf = analyzePdf(pdfPath);
        const verticalRedLine = pdf.red_lines.find((line) => line.dy > 40 && Math.abs(line.dx) <= 0.75);
        if (!verticalRedLine) {
            throw new Error(`downloaded PDF does not contain the vertical red line: ${JSON.stringify(pdf.red_lines)}`);
        }
        if (pdf.items_pixels.slate > 0 || pdf.items_pixels.red < 500) {
            throw new Error(`ITEMS downloaded PDF background is wrong: ${JSON.stringify(pdf.items_pixels)}`);
        }

        console.log(JSON.stringify({
            dom,
            payload: {
                lineStartX: payloadLine.lineStartX,
                lineEndX: payloadLine.lineEndX,
                lineWidth: payloadLine.pdfWidth,
                itemsBackgroundColor: payloadItems.backgroundColor,
            },
            pdf,
            pdfPath,
        }, null, 2));
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
