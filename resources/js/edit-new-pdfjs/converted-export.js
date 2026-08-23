function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

export function buildConvertedDownloadUrl(baseUrl, token) {
    const base = cleanText(baseUrl);
    const downloadToken = cleanText(token);
    if (!base || !downloadToken) throw new Error('The converted-file download link is unavailable.');

    const hashIndex = base.indexOf('#');
    const path = hashIndex >= 0 ? base.slice(0, hashIndex) : base;
    const hash = hashIndex >= 0 ? base.slice(hashIndex) : '';
    return `${path}${path.includes('?') ? '&' : '?'}token=${encodeURIComponent(downloadToken)}${hash}`;
}

export async function readConvertedFileResponse(response, conversionName = 'Document') {
    const name = cleanText(conversionName, 'Document');
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data?.success) {
        throw new Error(cleanText(data?.message, `${name} conversion failed (${response.status || 'unknown status'}).`));
    }
    if (!cleanText(data.download_token)) throw new Error(`The server did not return a ${name} download token.`);
    return data;
}

export async function readQueuedConversionResponse(response, conversionName = 'Document') {
    const name = cleanText(conversionName, 'Document');
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data?.success) {
        throw new Error(cleanText(data?.message, `${name} conversion failed (${response.status || 'unknown status'}).`));
    }
    if (cleanText(data.download_token)) return data;
    if (!['queued', 'processing'].includes(cleanText(data.status).toLowerCase())) {
        throw new Error(`The server returned an invalid ${name} conversion status.`);
    }
    if (!cleanText(data.status_url)) {
        throw new Error(`The server did not return a ${name} conversion status URL.`);
    }
    return data;
}

export async function waitForQueuedConversion(initial, conversionName = 'Document', {
    fetchImpl = globalThis.fetch,
    pollIntervalMs = 1000,
    maxAttempts = 900,
    onProgress = null,
    delay = (milliseconds) => new Promise((resolve) => globalThis.setTimeout(resolve, milliseconds)),
} = {}) {
    const name = cleanText(conversionName, 'Document');
    let data = initial || {};

    for (let attempt = 0; attempt <= maxAttempts; attempt += 1) {
        if (cleanText(data.download_token)) return data;

        const status = cleanText(data.status).toLowerCase();
        if (status === 'failed' || data.success === false) {
            throw new Error(cleanText(data.message, `${name} conversion failed.`));
        }
        if (!['queued', 'processing'].includes(status)) {
            throw new Error(`The server returned an invalid ${name} conversion status.`);
        }
        if (attempt === maxAttempts) {
            throw new Error(`${name} conversion is taking longer than expected. Check Horizon and try again.`);
        }

        if (typeof onProgress === 'function') onProgress(data);
        await delay(Math.max(0, Number(pollIntervalMs) || 0));
        const response = await fetchImpl(cleanText(data.status_url || initial?.status_url), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(cleanText(data?.message, `${name} conversion status check failed (${response.status || 'unknown status'}).`));
        }
        if (!data.status_url && initial?.status_url) data.status_url = initial.status_url;
    }

    throw new Error(`${name} conversion did not complete.`);
}

export function estimateConvertedFileBytes({
    sourceBytes,
    format,
    pdfaEmbedFonts = true,
    wordLayout = 'exact',
    wordImages = true,
    excelMode = 'all',
} = {}) {
    const inputBytes = Math.max(1, Number(sourceBytes) || 1);
    let factor = 1;
    let overhead = 24_000;

    if (format === 'pdfa') {
        factor = pdfaEmbedFonts ? 1.16 : 1.04;
        overhead = 48_000;
    } else if (format === 'word') {
        factor = wordLayout === 'exact'
            ? (wordImages ? 0.95 : 0.28)
            : (wordImages ? 0.62 : 0.18);
        overhead = 36_000;
    } else if (format === 'excel') {
        factor = excelMode === 'all' ? 0.98 : 0.16;
        overhead = excelMode === 'all' ? 28_000 : 16_000;
    }

    return Math.max(overhead, Math.round(inputBytes * factor + overhead));
}
