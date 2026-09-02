import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createImageExportZip,
    estimateImageExportBytes,
    formatEstimatedFileSize,
    imageDataToTiff,
    normalizeImageExportBaseName,
    parseImageExportPages,
    renderPdfPagesToImages,
    transformImageDataColorModel,
    viewportPixelSize,
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

test('viewportPixelSize does not let floating-point dust add a pixel', () => {
    // 792pt at 150 DPI is exactly 1650px, but scale is computed as 150/72,
    // which is not exact in binary: the product lands just above 1650 and a
    // plain ceil turned that into 1651.
    const scale = 150 / 72;
    assert.ok(792 * scale > 1650, 'the float product really does overshoot');
    assert.equal(viewportPixelSize({ width: 612 * scale, height: 792 * scale }).height, 1650);
    assert.equal(viewportPixelSize({ width: 612 * scale, height: 792 * scale }).width, 1275);
});

test('viewportPixelSize still rounds a genuine fraction up, so no page is clipped', () => {
    assert.equal(viewportPixelSize({ width: 100.5, height: 200.01 }).width, 101);
    assert.equal(viewportPixelSize({ width: 100.5, height: 200.01 }).height, 201);
    assert.equal(viewportPixelSize({ width: 100, height: 200 }).width, 100);
    // A collapsed or missing dimension never produces a zero-sized canvas.
    assert.equal(viewportPixelSize({ width: 0, height: 0 }).width, 1);
    assert.equal(viewportPixelSize({}).height, 1);
});

test('viewportPixelSize holds at every DPI the exporter offers', () => {
    // Screen, Standard and Print against US Letter, plus the 2400 ceiling.
    for (const [dpi, width, height] of [[96, 816, 1056], [150, 1275, 1650], [300, 2550, 3300], [2400, 20400, 26400]]) {
        const scale = dpi / 72;
        const size = viewportPixelSize({ width: 612 * scale, height: 792 * scale });
        assert.equal(size.width, width, `${dpi} DPI width`);
        assert.equal(size.height, height, `${dpi} DPI height`);
    }
});

test('a rendered page is exactly as many pixels as its DPI implies', async () => {
    const canvases = [];
    const fakeCanvas = () => {
        const canvas = {
            width: 0,
            height: 0,
            getContext: () => ({
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'low',
                fillStyle: '',
                fillRect: () => {},
                getImageData: () => ({ data: new Uint8ClampedArray(4), width: 1, height: 1 }),
                putImageData: () => {},
            }),
            toBlob: (callback) => callback(new Blob([new Uint8Array([1, 2, 3])], { type: 'image/png' })),
        };
        canvases.push(canvas);
        return canvas;
    };

    const pdfDocument = {
        getPage: async () => ({
            getViewport: ({ scale }) => ({ width: 612 * scale, height: 792 * scale, scale }),
            render: () => ({ promise: Promise.resolve() }),
            cleanup: () => {},
        }),
    };

    await renderPdfPagesToImages({
        pdfDocument,
        pages: [1],
        format: 'png',
        dpi: 150,
        createCanvas: fakeCanvas,
    });

    assert.equal(canvases.length, 1);
    assert.equal(canvases[0].width, 1275, 'a 612pt page at 150 DPI is 1275px wide');
    assert.equal(canvases[0].height, 1650, 'a 792pt page at 150 DPI is 1650px tall, not 1651');
});
