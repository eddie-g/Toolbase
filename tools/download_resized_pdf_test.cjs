#!/usr/bin/env node
/**
 * Test: download doc 2855 with the "5. I will notify the DSO..." annotation
 * programmatically resized to a narrow box, to reproduce the user's reported
 * "PDF adds line breaks" bug and verify fixes.
 */
const fs = require('fs');
const path = require('path');

const localBrowsers = path.resolve(__dirname, '..', 'node_modules', 'playwright-core', '.local-browsers');
if (fs.existsSync(localBrowsers)) process.env.PLAYWRIGHT_BROWSERS_PATH = localBrowsers;
const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'eddie.gray.biz@gmail.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'codex-test-admin-2861';

const docId = Number(process.argv[2] || 2855);
const outPath = process.argv[3] || `/tmp/doc${docId}_resized.pdf`;

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    const page = await ctx.newPage();

    try {
        await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', ADMIN_PASSWORD);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        await page.goto(`${BASE_URL}/documents/${docId}/edit-new`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('canvas.pdf-canvas, canvas[data-page-index]', { timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(3500);

        const b64 = await page.evaluate(async (id) => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const collected = window.collectSessionAnnotations();

            // Mutate the "5." annotation: narrow resize + user-authored flags.
            const modified = collected.map((ann) => {
                const text = String(ann?.text || '');
                if (!text.startsWith('5. I will notify the DSO at the earliest')) return ann;
                const out = { ...ann };
                out.pdfWidth = 269.55;
                // Keep height short; the export should REFLOW to multiple lines.
                out.pdfHeight = 75.33;
                out.userAuthored = true;
                out.promotedDirty = true;
                return out;
            });

            const r = await fetch(`/documents/${id}/download-annotated-pdf`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/pdf',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    annotations: modified,
                    session_annotations: modified,
                    acro_form_entries: (window.acroFormEntries || []),
                    deleted_promoted_source_keys: Array.from(window.pendingDeletedPromotedSourceKeys || []),
                    use_exact_download_path: true,
                    session_id: 'cli-resize-test-' + Date.now(),
                }),
            });
            if (!r.ok) throw new Error(`status=${r.status} body=${(await r.text()).slice(0, 400)}`);
            const buf = await r.arrayBuffer();
            const bytes = new Uint8Array(buf);
            let bin = '';
            for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return btoa(bin);
        }, docId);

        const pdfBytes = Buffer.from(b64, 'base64');
        fs.writeFileSync(outPath, pdfBytes);
        console.log(`[resize-test] wrote ${pdfBytes.length} bytes to ${outPath}`);
    } finally {
        await ctx.close();
        await browser.close();
    }
})().catch((err) => {
    console.error('[resize-test] FATAL:', err.message);
    process.exit(1);
});
