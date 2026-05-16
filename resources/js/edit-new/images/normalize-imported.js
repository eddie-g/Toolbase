/*
 * normalizeImportedImageAsset (Phase 7cj).
 *
 * Reads an uploaded image file, decodes it, and downscales the
 * largest edge to 2048px so the resulting data: URL stays under the
 * size budget for inline annotation storage. Always re-encodes as PNG
 * regardless of input format. Throws on non-image inputs.
 *
 * Used by the signature upload tab today; safe for any "stamp"-style
 * image-asset import path.
 */

import { readFileAsDataUrl, loadImageElement } from '../util/image-load.js';

const IMPORTABLE_IMAGE_EXTENSION_RE = /\.(png|jpe?g|gif|webp|svg)$/i;

export async function normalizeImportedImageAsset(file) {
    if (!file || (!String(file.type || '').startsWith('image/') && !IMPORTABLE_IMAGE_EXTENSION_RE.test(String(file.name || '')))) {
        throw new Error('Choose an image file to import.');
    }
    const sourceDataUrl = await readFileAsDataUrl(file);
    const img = await loadImageElement(sourceDataUrl);
    const naturalWidth = Math.max(1, img.naturalWidth || img.width || 1);
    const naturalHeight = Math.max(1, img.naturalHeight || img.height || 1);
    const maxDimension = 2048;
    const scale = Math.min(1, maxDimension / Math.max(naturalWidth, naturalHeight));
    const targetWidth = Math.max(1, Math.round(naturalWidth * scale));
    const targetHeight = Math.max(1, Math.round(naturalHeight * scale));
    const normalizeCanvas = document.createElement('canvas');
    normalizeCanvas.width = targetWidth;
    normalizeCanvas.height = targetHeight;
    const normalizeCtx = normalizeCanvas.getContext('2d');
    normalizeCtx.clearRect(0, 0, targetWidth, targetHeight);
    normalizeCtx.drawImage(img, 0, 0, targetWidth, targetHeight);
    return {
        dataUrl: normalizeCanvas.toDataURL('image/png'),
        width: targetWidth,
        height: targetHeight,
        fileName: file.name || 'signature.png',
        mimeType: 'image/png',
    };
}
