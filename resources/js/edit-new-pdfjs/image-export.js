const MAX_IMAGE_EXPORT_PIXELS = 64_000_000;

export function parseImageExportPages({ mode = 'all', totalPages, from = 1, to = totalPages, custom = '' }) {
    const total = Number.parseInt(totalPages, 10);
    if (!Number.isInteger(total) || total < 1) throw new Error('The PDF does not contain any pages.');

    if (mode === 'all') return Array.from({ length: total }, (_, index) => index + 1);

    if (mode === 'range') {
        const first = Number.parseInt(from, 10);
        const last = Number.parseInt(to, 10);
        if (!Number.isInteger(first) || !Number.isInteger(last) || first < 1 || last > total || first > last) {
            throw new Error(`Enter a page range between 1 and ${total}.`);
        }
        return Array.from({ length: last - first + 1 }, (_, index) => first + index);
    }

    if (mode !== 'custom') throw new Error('Choose which pages to export.');
    const value = String(custom || '').trim();
    if (!value) throw new Error('Enter at least one page number or range.');

    const pages = new Set();
    for (const rawToken of value.split(',')) {
        const token = rawToken.trim();
        const range = token.match(/^(\d+)\s*-\s*(\d+)$/);
        if (range) {
            const first = Number.parseInt(range[1], 10);
            const last = Number.parseInt(range[2], 10);
            if (first < 1 || last > total || first > last) {
                throw new Error(`Page range "${token}" must be between 1 and ${total}.`);
            }
            for (let pageNumber = first; pageNumber <= last; pageNumber += 1) pages.add(pageNumber);
            continue;
        }

        if (!/^\d+$/.test(token)) throw new Error(`"${token || 'empty value'}" is not a valid page number or range.`);
        const pageNumber = Number.parseInt(token, 10);
        if (pageNumber < 1 || pageNumber > total) {
            throw new Error(`Page ${pageNumber} is outside the 1-${total} page range.`);
        }
        pages.add(pageNumber);
    }

    return Array.from(pages).sort((left, right) => left - right);
}

export function normalizeImageExportBaseName(value) {
    const filename = String(value || 'document.pdf').split(/[\\/]/).pop() || 'document.pdf';
    const withoutExtension = filename.replace(/\.pdf$/i, '');
    const safe = withoutExtension
        .replace(/[<>:"|?*\u0000-\u001f]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return safe || 'document';
}

export function estimateImageExportBytes({
    totalPixels,
    pageCount = 1,
    format = 'jpg',
    quality = 92,
    colorModel = 'rgb',
} = {}) {
    const pixels = Math.max(0, Number(totalPixels) || 0);
    const pages = Math.max(1, Number.parseInt(pageCount, 10) || 1);
    const normalizedFormat = ['jpg', 'png', 'tiff'].includes(format) ? format : 'jpg';
    const normalizedQuality = Math.min(100, Math.max(1, Number.parseInt(quality, 10) || 92));
    const grayscale = colorModel === 'grayscale';
    const preserveAlpha = colorModel === 'rgba' && normalizedFormat !== 'jpg';

    let bytesPerPixel;
    if (normalizedFormat === 'tiff') {
        bytesPerPixel = preserveAlpha ? 4 : 3;
    } else if (normalizedFormat === 'png') {
        bytesPerPixel = grayscale ? 0.35 : (preserveAlpha ? 1 : 0.8);
    } else {
        bytesPerPixel = (0.08 + (0.92 * ((normalizedQuality / 100) ** 2))) * (grayscale ? 0.65 : 1);
    }

    const imageBytes = pixels * bytesPerPixel;
    const archiveOverhead = pages > 1 ? (22 + (pages * 140)) : 0;
    const encoderOverhead = pages * (normalizedFormat === 'tiff' ? 256 : 1024);
    return Math.max(0, Math.round(imageBytes + archiveOverhead + encoderOverhead));
}

export function formatEstimatedFileSize(bytes) {
    const value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) return `${Math.round(value)} B`;
    if (value < (1024 ** 2)) return `${(value / 1024).toFixed(value < 10240 ? 1 : 0)} KB`;
    if (value < (1024 ** 3)) return `${(value / (1024 ** 2)).toFixed(value < (10 * 1024 ** 2) ? 1 : 0)} MB`;
    return `${(value / (1024 ** 3)).toFixed(1)} GB`;
}

export function transformImageDataColorModel(imageData, model) {
    const pixels = imageData?.data;
    if (!pixels || model === 'rgb' || model === 'rgba') return imageData;

    for (let index = 0; index < pixels.length; index += 4) {
        if (model === 'grayscale') {
            const gray = Math.round((0.299 * pixels[index]) + (0.587 * pixels[index + 1]) + (0.114 * pixels[index + 2]));
            pixels[index] = gray;
            pixels[index + 1] = gray;
            pixels[index + 2] = gray;
            continue;
        }

    }
    return imageData;
}

export function imageDataToTiff(imageData, width, height, { dpi = 150, preserveAlpha = false } = {}) {
    const pixelWidth = Number.parseInt(width, 10);
    const pixelHeight = Number.parseInt(height, 10);
    if (!imageData?.data || pixelWidth < 1 || pixelHeight < 1) throw new Error('TIFF image data is invalid.');

    const samplesPerPixel = preserveAlpha ? 4 : 3;
    const entryCount = preserveAlpha ? 13 : 12;
    const ifdOffset = 8;
    const ifdSize = 2 + (entryCount * 12) + 4;
    const bitsOffset = ifdOffset + ifdSize;
    const xResolutionOffset = bitsOffset + (samplesPerPixel * 2);
    const yResolutionOffset = xResolutionOffset + 8;
    const pixelOffset = yResolutionOffset + 8;
    const pixelByteCount = pixelWidth * pixelHeight * samplesPerPixel;
    const buffer = new ArrayBuffer(pixelOffset + pixelByteCount);
    const view = new DataView(buffer);
    const bytes = new Uint8Array(buffer);

    view.setUint8(0, 0x49);
    view.setUint8(1, 0x49);
    view.setUint16(2, 42, true);
    view.setUint32(4, ifdOffset, true);
    view.setUint16(ifdOffset, entryCount, true);

    let entryOffset = ifdOffset + 2;
    const writeEntry = (tag, type, count, value) => {
        view.setUint16(entryOffset, tag, true);
        view.setUint16(entryOffset + 2, type, true);
        view.setUint32(entryOffset + 4, count, true);
        view.setUint32(entryOffset + 8, value, true);
        entryOffset += 12;
    };
    writeEntry(256, 4, 1, pixelWidth);
    writeEntry(257, 4, 1, pixelHeight);
    writeEntry(258, 3, samplesPerPixel, bitsOffset);
    writeEntry(259, 3, 1, 1);
    writeEntry(262, 3, 1, 2);
    writeEntry(273, 4, 1, pixelOffset);
    writeEntry(277, 3, 1, samplesPerPixel);
    writeEntry(278, 4, 1, pixelHeight);
    writeEntry(279, 4, 1, pixelByteCount);
    writeEntry(282, 5, 1, xResolutionOffset);
    writeEntry(283, 5, 1, yResolutionOffset);
    writeEntry(296, 3, 1, 2);
    if (preserveAlpha) writeEntry(338, 3, 1, 2);
    view.setUint32(entryOffset, 0, true);

    for (let sample = 0; sample < samplesPerPixel; sample += 1) view.setUint16(bitsOffset + (sample * 2), 8, true);
    const normalizedDpi = Math.max(1, Math.round(Number(dpi) || 150));
    view.setUint32(xResolutionOffset, normalizedDpi, true);
    view.setUint32(xResolutionOffset + 4, 1, true);
    view.setUint32(yResolutionOffset, normalizedDpi, true);
    view.setUint32(yResolutionOffset + 4, 1, true);

    let target = pixelOffset;
    for (let source = 0; source < pixelWidth * pixelHeight * 4; source += 4) {
        bytes[target++] = imageData.data[source];
        bytes[target++] = imageData.data[source + 1];
        bytes[target++] = imageData.data[source + 2];
        if (preserveAlpha) bytes[target++] = imageData.data[source + 3];
    }
    return new Blob([buffer], { type: 'image/tiff' });
}

function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) resolve(blob);
            else reject(new Error('The browser could not encode the rendered page.'));
        }, type, quality);
    });
}

/**
 * Slack allowed when rounding a scaled dimension up to whole pixels.
 *
 * Large enough to swallow any floating-point error (at the 2400 DPI ceiling
 * that error is around 4e-12), and small enough that no fraction anyone could
 * mean to keep falls inside it.
 */
const PIXEL_ROUNDING_EPSILON = 1e-6;

/**
 * The pixel size a viewport should be rasterised at.
 *
 * Rounding up is deliberate: a page whose scaled size lands on a genuine
 * fraction must not be clipped. But the scale is dpi/72, which is not exact
 * in binary -- at 150 DPI a 792pt page measures 1650.0000000000002, and a
 * plain ceil turns that into an image one pixel taller than the page. So the
 * ceil is taken with a hair of slack, which snaps values that are only a
 * float artefact away from an integer without touching a real fraction.
 *
 * Shared by the exporter and the size estimate so the two cannot disagree
 * about how big a page is.
 */
export function viewportPixelSize(viewport) {
    const ceil = (value) => Math.max(1, Math.ceil((Number(value) || 0) - PIXEL_ROUNDING_EPSILON));
    return { width: ceil(viewport?.width), height: ceil(viewport?.height) };
}

export async function renderPdfPagesToImages({
    pdfDocument,
    pages,
    format = 'jpg',
    dpi = 150,
    quality = 92,
    colorModel = 'rgb',
    smoothing = true,
    baseName = 'document',
    createCanvas = () => document.createElement('canvas'),
    onProgress = () => {},
}) {
    if (!pdfDocument?.getPage) throw new Error('No PDF is available for image export.');
    const normalizedFormat = ['jpg', 'png', 'tiff'].includes(format) ? format : 'jpg';
    const normalizedDpi = Math.min(2400, Math.max(12, Number.parseInt(dpi, 10) || 150));
    const normalizedQuality = Math.min(100, Math.max(1, Number.parseInt(quality, 10) || 92));
    const scale = normalizedDpi / 72;
    const results = [];

    for (let index = 0; index < pages.length; index += 1) {
        const pageNumber = pages[index];
        onProgress({ pageNumber, index, total: pages.length, percent: Math.round((index / pages.length) * 100) });
        const page = await pdfDocument.getPage(pageNumber);
        try {
            const viewport = page.getViewport({ scale });
            const { width, height } = viewportPixelSize(viewport);
            if ((width * height) > MAX_IMAGE_EXPORT_PIXELS) {
                throw new Error(`Page ${pageNumber} would exceed the safe browser canvas size at ${normalizedDpi} DPI. Choose a lower resolution.`);
            }

            const canvas = createCanvas();
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d', { alpha: normalizedFormat !== 'jpg' });
            if (!context) throw new Error('The browser could not create an image canvas.');
            context.imageSmoothingEnabled = Boolean(smoothing);
            context.imageSmoothingQuality = 'high';

            const preserveAlpha = colorModel === 'rgba' && normalizedFormat !== 'jpg';
            if (!preserveAlpha) {
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, height);
            }
            await page.render({ canvasContext: context, viewport, background: preserveAlpha ? undefined : '#ffffff' }).promise;

            if (!['rgb', 'rgba'].includes(colorModel)) {
                const imageData = context.getImageData(0, 0, width, height);
                transformImageDataColorModel(imageData, colorModel);
                context.putImageData(imageData, 0, 0);
            }

            let blob;
            if (normalizedFormat === 'tiff') {
                blob = imageDataToTiff(context.getImageData(0, 0, width, height), width, height, {
                    dpi: normalizedDpi,
                    preserveAlpha,
                });
            } else if (normalizedFormat === 'png') {
                blob = await canvasToBlob(canvas, 'image/png');
            } else {
                blob = await canvasToBlob(canvas, 'image/jpeg', normalizedQuality / 100);
            }

            const suffix = pages.length === 1 ? '' : `_page-${pageNumber}`;
            results.push({ blob, name: `${normalizeImageExportBaseName(baseName)}${suffix}.${normalizedFormat}` });
        } finally {
            page.cleanup?.();
        }
    }
    onProgress({ pageNumber: pages.at(-1), index: pages.length, total: pages.length, percent: 100 });
    return results;
}

function zipCrc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit += 1) {
            crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
        }
    }
    return (crc ^ 0xffffffff) >>> 0;
}

function zipDosTimestamp(date) {
    const value = date instanceof Date && !Number.isNaN(date.getTime()) ? date : new Date();
    const year = Math.max(1980, value.getFullYear());
    return {
        time: (value.getHours() << 11) | (value.getMinutes() << 5) | Math.floor(value.getSeconds() / 2),
        date: ((year - 1980) << 9) | ((value.getMonth() + 1) << 5) | value.getDate(),
    };
}

function safeZipEntryName(value, fallback) {
    const name = String(value || '').split(/[\\/]/).pop()?.trim() || fallback;
    return name.replace(/[\u0000-\u001f]/g, '_');
}

export async function createImageExportZip(files, { date = new Date() } = {}) {
    if (!Array.isArray(files) || files.length < 1) throw new Error('There are no image files to add to the ZIP archive.');
    if (files.length > 0xffff) throw new Error('The image export contains too many files for a browser ZIP archive.');

    const encoder = new TextEncoder();
    const timestamp = zipDosTimestamp(date);
    const localParts = [];
    const entries = [];
    let localOffset = 0;

    for (let index = 0; index < files.length; index += 1) {
        const file = files[index];
        const data = new Uint8Array(await file.blob.arrayBuffer());
        if (data.byteLength > 0xffffffff || localOffset > 0xffffffff) {
            throw new Error('The image export is too large for a browser ZIP archive.');
        }
        const name = safeZipEntryName(file.name, `page-${index + 1}`);
        const nameBytes = encoder.encode(name);
        const crc = zipCrc32(data);
        const localHeader = new Uint8Array(30 + nameBytes.length);
        const localView = new DataView(localHeader.buffer);
        localView.setUint32(0, 0x04034b50, true);
        localView.setUint16(4, 20, true);
        localView.setUint16(6, 0x0800, true);
        localView.setUint16(8, 0, true);
        localView.setUint16(10, timestamp.time, true);
        localView.setUint16(12, timestamp.date, true);
        localView.setUint32(14, crc, true);
        localView.setUint32(18, data.byteLength, true);
        localView.setUint32(22, data.byteLength, true);
        localView.setUint16(26, nameBytes.length, true);
        localView.setUint16(28, 0, true);
        localHeader.set(nameBytes, 30);
        localParts.push(localHeader, data);
        entries.push({ nameBytes, crc, size: data.byteLength, localOffset });
        localOffset += localHeader.byteLength + data.byteLength;
    }

    const centralParts = [];
    let centralSize = 0;
    for (const entry of entries) {
        const header = new Uint8Array(46 + entry.nameBytes.length);
        const view = new DataView(header.buffer);
        view.setUint32(0, 0x02014b50, true);
        view.setUint16(4, 20, true);
        view.setUint16(6, 20, true);
        view.setUint16(8, 0x0800, true);
        view.setUint16(10, 0, true);
        view.setUint16(12, timestamp.time, true);
        view.setUint16(14, timestamp.date, true);
        view.setUint32(16, entry.crc, true);
        view.setUint32(20, entry.size, true);
        view.setUint32(24, entry.size, true);
        view.setUint16(28, entry.nameBytes.length, true);
        view.setUint16(30, 0, true);
        view.setUint16(32, 0, true);
        view.setUint16(34, 0, true);
        view.setUint16(36, 0, true);
        view.setUint32(38, 0, true);
        view.setUint32(42, entry.localOffset, true);
        header.set(entry.nameBytes, 46);
        centralParts.push(header);
        centralSize += header.byteLength;
    }

    if (localOffset + centralSize > 0xffffffff) throw new Error('The image export is too large for a browser ZIP archive.');
    const end = new Uint8Array(22);
    const endView = new DataView(end.buffer);
    endView.setUint32(0, 0x06054b50, true);
    endView.setUint16(4, 0, true);
    endView.setUint16(6, 0, true);
    endView.setUint16(8, entries.length, true);
    endView.setUint16(10, entries.length, true);
    endView.setUint32(12, centralSize, true);
    endView.setUint32(16, localOffset, true);
    endView.setUint16(20, 0, true);
    return new Blob([...localParts, ...centralParts, end], { type: 'application/zip' });
}

function triggerImageExportDownload(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export async function downloadImageExportFiles(files, { archiveName = 'images.zip' } = {}) {
    if (!Array.isArray(files) || files.length < 1) throw new Error('There are no image files to download.');
    if (files.length === 1) {
        triggerImageExportDownload(files[0].blob, files[0].name);
        return { name: files[0].name, zipped: false };
    }

    const zip = await createImageExportZip(files);
    const name = safeZipEntryName(archiveName, 'images.zip').replace(/\.zip$/i, '') + '.zip';
    triggerImageExportDownload(zip, name);
    return { name, zipped: true };
}
