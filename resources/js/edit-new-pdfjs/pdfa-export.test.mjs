import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildPdfaDownloadUrl,
    normalizePdfaReport,
    readPdfaConversionResponse,
} from './pdfa-export.js';

test('buildPdfaDownloadUrl preserves existing query strings and encodes tokens', () => {
    assert.equal(buildPdfaDownloadUrl('/documents/download-pdfa', 'abc 123'), '/documents/download-pdfa?token=abc%20123');
    assert.equal(buildPdfaDownloadUrl('/download?source=editor', 'abc'), '/download?source=editor&token=abc');
});

test('normalizePdfaReport supplies safe display defaults and normalizes checks', () => {
    const report = normalizePdfaReport({
        status: 'Compliant',
        page_count: '2',
        file_size: '4096',
        checks: [{ result: 'PASS', item: 'Fonts', fonts: [{ name: '<Font>', embedded: true }] }],
    }, 'PDF/A-2b');

    assert.equal(report.compliant, true);
    assert.equal(report.pageCount, 2);
    assert.equal(report.fileSize, 4096);
    assert.equal(report.checks[0].fonts[0].name, '<Font>');
    assert.equal(report.checks[0].passed, true);
});

test('readPdfaConversionResponse accepts success and surfaces server errors', async () => {
    const success = await readPdfaConversionResponse({
        ok: true,
        status: 200,
        json: async () => ({ success: true, download_token: 'token' }),
    });
    assert.equal(success.download_token, 'token');

    await assert.rejects(() => readPdfaConversionResponse({
        ok: false,
        status: 422,
        json: async () => ({ success: false, message: 'Invalid PDF.' }),
    }), /Invalid PDF/);
});
