/*
 * util/image-load (Phase 7ci).
 *
 * Two zero-dependency Promise wrappers used by the signature, image
 * import, and white-matte cleanup paths to bridge to the legacy
 * FileReader / HTMLImageElement APIs.
 *
 * - readFileAsDataUrl(file): resolve to a base64 data: URL, or reject.
 * - loadImageElement(src): resolve to a fully-decoded <img>, or reject.
 *
 * Both are pure: no DOM mutation, no editor state, no config.
 */

export const readFileAsDataUrl = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Failed to read image file.'));
    reader.readAsDataURL(file);
});

export const loadImageElement = (src) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Failed to load image.'));
    img.src = src;
});
