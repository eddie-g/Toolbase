function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

export function buildPdfaDownloadUrl(baseUrl, token) {
    const base = cleanText(baseUrl);
    const downloadToken = cleanText(token);
    if (!base || !downloadToken) throw new Error('The PDF/A download link is unavailable.');

    const hashIndex = base.indexOf('#');
    const path = hashIndex >= 0 ? base.slice(0, hashIndex) : base;
    const hash = hashIndex >= 0 ? base.slice(hashIndex) : '';
    return `${path}${path.includes('?') ? '&' : '?'}token=${encodeURIComponent(downloadToken)}${hash}`;
}

export async function readPdfaConversionResponse(response) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data?.success) {
        throw new Error(cleanText(data?.message, `PDF/A conversion failed (${response.status || 'unknown status'}).`));
    }
    if (!cleanText(data.download_token)) throw new Error('The server did not return a PDF/A download token.');
    return data;
}

export function normalizePdfaReport(report, label = 'PDF/A') {
    const source = report && typeof report === 'object' ? report : {};
    const checks = Array.isArray(source.checks) ? source.checks : [];
    return {
        compliant: cleanText(source.status).toLowerCase() === 'compliant',
        label: cleanText(label, cleanText(source.standard, 'PDF/A')),
        iso: cleanText(source.iso, 'ISO 19005'),
        pageCount: Math.max(0, Number.parseInt(source.page_count, 10) || 0),
        fileSize: Math.max(0, Number(source.file_size) || 0),
        timestamp: cleanText(source.timestamp),
        summary: cleanText(source.summary, checks.length ? `${checks.length} compliance checks completed` : 'Conversion completed'),
        checks: checks.map((check) => ({
            passed: cleanText(check?.result).toUpperCase() === 'PASS',
            item: cleanText(check?.item, 'Compliance check'),
            description: cleanText(check?.description),
            detail: cleanText(check?.detail),
            fonts: (Array.isArray(check?.fonts) ? check.fonts : []).map((font) => ({
                name: cleanText(font?.name, 'Unknown font'),
                type: cleanText(font?.type, 'Unknown type'),
                embedded: Boolean(font?.embedded),
            })),
        })),
    };
}
