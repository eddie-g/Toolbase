/*
 * Edit-New PDF editor — persistence API.
 *
 * Centralises every fetch() call against the document save / info /
 * download endpoints. Other modules import these functions instead of
 * calling fetch() directly, so request shape + headers + error mapping
 * live in exactly one place.
 *
 * Each request automatically attaches the CSRF header and the
 * `credentials: 'same-origin'` cookie hint. Callers pass the bare
 * payload object; this module owns the JSON serialisation, header set,
 * and HTTP-status error throwing.
 */

import { CSRF, INFO_URL, SAVE_URL, DOWNLOAD_URL } from '../config.js';

const JSON_HEADERS = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': CSRF,
};

/**
 * Build the documentInfo URL with the session id query param attached.
 * Returns a string suitable for fetch().
 */
export function buildDocumentInfoUrl(sessionId) {
    const url = new URL(INFO_URL, window.location.origin);
    if (sessionId) url.searchParams.set('session_id', sessionId);
    return url.toString();
}

/**
 * GET the document info payload (page metadata, annotations, fonts,
 * AcroForm entries). Throws on non-200 or `success: false`.
 */
export async function fetchDocumentInfo(sessionId) {
    const resp = await fetch(buildDocumentInfoUrl(sessionId), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const data = await resp.json();
    if (!data.success) throw new Error(data.message || 'Failed to load document info.');
    return data;
}

/**
 * POST the current annotation snapshot. Returns the parsed JSON
 * response; throws on non-2xx or `success: false`.
 */
export async function saveAnnotations(payload) {
    const response = await fetch(SAVE_URL, {
        method: 'POST',
        headers: JSON_HEADERS,
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) {
        throw new Error(result.message || 'Save failed');
    }
    return result;
}

/**
 * POST the current annotation snapshot to render an annotated PDF.
 * Returns a `Blob` of the generated PDF on success. On failure throws
 * an Error whose message is taken from the JSON body when possible,
 * otherwise the response text.
 */
export async function downloadAnnotatedPdf(payload) {
    const response = await fetch(DOWNLOAD_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/pdf, application/json',
            'X-CSRF-TOKEN': CSRF,
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        let message = 'Failed to generate PDF.';
        const contentType = String(response.headers.get('content-type') || '');
        if (contentType.includes('application/json')) {
            const result = await response.json().catch(() => ({}));
            message = result.message || message;
        } else {
            const text = await response.text().catch(() => '');
            if (text) message = text;
        }
        throw new Error(message);
    }

    return await response.blob();
}
