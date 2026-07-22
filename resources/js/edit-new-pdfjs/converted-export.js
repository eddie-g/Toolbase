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
