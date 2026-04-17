#!/usr/bin/env node
/**
 * Download the annotated PDF for a document without triggering a browser download popup.
 *
 *   node tools/download_annotated_pdf.cjs <document_id> [output_path]
 *
 * Defaults: document_id=2855, output_path=/tmp/doc<id>_download.pdf
 * Env: BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD
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
const outPath = process.argv[3] || `/tmp/doc${docId}_download.pdf`;

(async () => {
    const browser = await chromium.launch({ headless: true });
    const ctx = await browser.newContext({ viewport: { width: 1600, height: 1400 } });
    const page = await ctx.newPage();

    try {
        // Login
        await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('#data\\.email', ADMIN_EMAIL);
        await page.fill('#data\\.password', ADMIN_PASSWORD);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 }),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        // Land on edit-new to populate DB state + grab CSRF
        await page.goto(`${BASE_URL}/documents/${docId}/edit-new`, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2000);

        // POST to the download endpoint from within the page, ask for the saved
        // server-side state (empty session_annotations means "use what's in the DB").
        const b64 = await page.evaluate(async (id) => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const r = await fetch(`/documents/${id}/download-annotated-pdf`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/pdf',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    annotations: [],
                    session_annotations: [],
                    acro_form_entries: [],
                    deleted_promoted_source_keys: [],
                    use_exact_download_path: true,
                    session_id: 'cli-download-' + Date.now(),
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
        console.log(`[download] wrote ${pdfBytes.length} bytes to ${outPath}`);
    } finally {
        await ctx.close();
        await browser.close();
    }
})().catch((err) => {
    console.error('[download] FATAL:', err.message);
    process.exit(1);
});
