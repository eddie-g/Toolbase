import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildConvertedDownloadUrl,
    estimateConvertedFileBytes,
    readConvertedFileResponse,
    readQueuedConversionResponse,
    waitForQueuedConversion,
} from './converted-export.js';

test('buildConvertedDownloadUrl encodes the single-use token', () => {
    assert.equal(buildConvertedDownloadUrl('/documents/download-converted', 'word token'), '/documents/download-converted?token=word%20token');
    assert.equal(buildConvertedDownloadUrl('/download?from=editor', 'abc'), '/download?from=editor&token=abc');
});

test('estimateConvertedFileBytes covers PDF/A, Word, and Excel options', () => {
    const sourceBytes = 1_000_000;
    const pdfa = estimateConvertedFileBytes({ sourceBytes, format: 'pdfa', pdfaEmbedFonts: true });
    const wordWithImages = estimateConvertedFileBytes({ sourceBytes, format: 'word', wordLayout: 'exact', wordImages: true });
    const wordWithoutImages = estimateConvertedFileBytes({ sourceBytes, format: 'word', wordLayout: 'flow', wordImages: false });
    const excelAll = estimateConvertedFileBytes({ sourceBytes, format: 'excel', excelMode: 'all' });
    const excelTables = estimateConvertedFileBytes({ sourceBytes, format: 'excel', excelMode: 'tables' });

    assert.ok(pdfa > sourceBytes);
    assert.ok(wordWithImages > wordWithoutImages);
    assert.ok(excelAll > excelTables);
    assert.ok(excelAll > 900_000);
});

test('readConvertedFileResponse validates Word conversion responses', async () => {
    const success = await readConvertedFileResponse({
        ok: true,
        status: 200,
        json: async () => ({ success: true, download_token: 'token', download_name: 'document.docx' }),
    }, 'Word');
    assert.equal(success.download_name, 'document.docx');

    await assert.rejects(() => readConvertedFileResponse({
        ok: false,
        status: 500,
        json: async () => ({ success: false, message: 'Word engine unavailable.' }),
    }, 'Word'), /Word engine unavailable/);
});

test('readConvertedFileResponse accepts Excel conversion metadata', async () => {
    const success = await readConvertedFileResponse({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            download_token: 'excel-token',
            download_name: 'document.xlsx',
            tables_found: 3,
            sheets: 2,
            effective_mode: 'tables',
            fallback_used: false,
        }),
    }, 'Excel');

    assert.equal(success.download_name, 'document.xlsx');
    assert.equal(success.tables_found, 3);
    assert.equal(success.sheets, 2);
});

test('readConvertedFileResponse retains Excel no-table fallback metadata', async () => {
    const success = await readConvertedFileResponse({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            download_token: 'fallback-token',
            download_name: 'newsletter.xlsx',
            tables_found: 0,
            effective_mode: 'all',
            fallback_used: true,
        }),
    }, 'Excel');

    assert.equal(success.effective_mode, 'all');
    assert.equal(success.fallback_used, true);
});

test('readConvertedFileResponse retains newsletter layout metadata', async () => {
    const success = await readConvertedFileResponse({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            download_token: 'layout-token',
            download_name: 'newsletter.xlsx',
            tables_found: 0,
            effective_mode: 'layout',
            fallback_used: true,
            layout_engine: 'newsletter',
        }),
    }, 'Excel');

    assert.equal(success.effective_mode, 'layout');
    assert.equal(success.layout_engine, 'newsletter');
});

test('queued conversion responses are polled until a download is ready', async () => {
    const queued = await readQueuedConversionResponse({
        ok: true,
        status: 202,
        json: async () => ({
            success: true,
            status: 'queued',
            status_url: '/documents/1/conversions/job-id',
        }),
    }, 'Word');
    const responses = [
        { success: true, status: 'processing', progress: 45 },
        { success: true, status: 'completed', progress: 100, download_token: 'job-id' },
    ];
    const progress = [];
    const completed = await waitForQueuedConversion(queued, 'Word', {
        pollIntervalMs: 0,
        delay: async () => {},
        onProgress: (data) => progress.push(data.status),
        fetchImpl: async () => ({
            ok: true,
            status: 200,
            json: async () => responses.shift(),
        }),
    });

    assert.equal(completed.download_token, 'job-id');
    assert.deepEqual(progress, ['queued', 'processing']);
});

test('queued conversion polling surfaces worker failures', async () => {
    await assert.rejects(() => waitForQueuedConversion({
        success: true,
        status: 'queued',
        status_url: '/status',
    }, 'Excel', {
        pollIntervalMs: 0,
        delay: async () => {},
        fetchImpl: async () => ({
            ok: true,
            status: 200,
            json: async () => ({ success: false, status: 'failed', message: 'Converter unavailable.' }),
        }),
    }), /Converter unavailable/);
});
