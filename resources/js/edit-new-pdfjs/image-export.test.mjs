import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createImageExportZip,
    estimateImageExportBytes,
    formatEstimatedFileSize,
    imageDataToTiff,
    normalizeImageExportBaseName,
    parseImageExportPages,
    transformImageDataColorModel,
} from './image-export.js';

test('image export size estimates reflect format, quality, transparency, and page overhead', () => {
    const jpgLow = estimateImageExportBytes({ totalPixels: 1_000_000, format: 'jpg', quality: 30 });
    const jpgHigh = estimateImageExportBytes({ totalPixels: 1_000_000, format: 'jpg', quality: 92 });
    const tiffRgb = estimateImageExportBytes({ totalPixels: 1_000_000, format: 'tiff', colorModel: 'rgb' });
    const tiffRgba = estimateImageExportBytes({ totalPixels: 1_000_000, format: 'tiff', colorModel: 'rgba' });
    const zipped = estimateImageExportBytes({ totalPixels: 1_000_000, pageCount: 3, format: 'png' });
    const single = estimateImageExportBytes({ totalPixels: 1_000_000, pageCount: 1, format: 'png' });

    assert.ok(jpgHigh > jpgLow);
    assert.ok(tiffRgba > tiffRgb);
    assert.ok(zipped > single);
    assert.equal(formatEstimatedFileSize(5 * 1024 * 1024), '5.0 MB');
});

test('parseImageExportPages handles all, range, and sorted custom selections', () => {
    assert.deepEqual(parseImageExportPages({ mode: 'all', totalPages: 3 }), [1, 2, 3]);
    assert.deepEqual(parseImageExportPages({ mode: 'range', totalPages: 8, from: 3, to: 5 }), [3, 4, 5]);
    assert.deepEqual(parseImageExportPages({ mode: 'custom', totalPages: 10, custom: '7, 2-4, 2' }), [2, 3, 4, 7]);
});

test('parseImageExportPages rejects malformed and out-of-range selections', () => {
    assert.throws(() => parseImageExportPages({ mode: 'range', totalPages: 4, from: 4, to: 2 }), /between 1 and 4/);
    assert.throws(() => parseImageExportPages({ mode: 'custom', totalPages: 4, custom: '1, nope' }), /not a valid/);
    assert.throws(() => parseImageExportPages({ mode: 'custom', totalPages: 4, custom: '5' }), /outside/);
});

test('normalizeImageExportBaseName removes paths, PDF extensions, and unsafe characters', () => {
    assert.equal(normalizeImageExportBaseName('../Quarterly: Report?.PDF'), 'Quarterly Report');
    assert.equal(normalizeImageExportBaseName(''), 'document');
});

test('grayscale conversion applies the legacy luminance formula', () => {
    const imageData = { data: new Uint8ClampedArray([255, 0, 0, 255]) };
    transformImageDataColorModel(imageData, 'grayscale');
    assert.deepEqual(Array.from(imageData.data), [76, 76, 76, 255]);
});

test('TIFF encoder writes a little-endian TIFF header and preserves alpha when requested', async () => {
    const imageData = { data: new Uint8ClampedArray([10, 20, 30, 40]) };
    const blob = imageDataToTiff(imageData, 1, 1, { dpi: 300, preserveAlpha: true });
    const bytes = new Uint8Array(await blob.arrayBuffer());
    assert.deepEqual(Array.from(bytes.slice(0, 4)), [0x49, 0x49, 42, 0]);
    assert.equal(blob.type, 'image/tiff');
    assert.deepEqual(Array.from(bytes.slice(-4)), [10, 20, 30, 40]);
});

test('multi-page image ZIP contains local entries, a central directory, and one end record', async () => {
    const blob = await createImageExportZip([
        { name: 'report_page-1.png', blob: new Blob([new Uint8Array([1, 2, 3])]) },
        { name: 'report_page-2.png', blob: new Blob([new Uint8Array([4, 5])]) },
    ], { date: new Date('2026-01-02T03:04:06') });
    const bytes = new Uint8Array(await blob.arrayBuffer());
    const view = new DataView(bytes.buffer);
    const text = new TextDecoder().decode(bytes);
    assert.equal(view.getUint32(0, true), 0x04034b50);
    assert.equal(view.getUint32(bytes.length - 22, true), 0x06054b50);
    assert.equal(view.getUint16(bytes.length - 14, true), 2);
    assert.match(text, /report_page-1\.png/);
    assert.match(text, /report_page-2\.png/);
    assert.equal(blob.type, 'application/zip');
});
