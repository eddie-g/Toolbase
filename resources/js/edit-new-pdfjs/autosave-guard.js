// What keeps an autosave honest: which state version this tab loaded, whether
// another tab has saved since, what a refused save means for the user, and
// sending an image's stored reference instead of its base64 data.

const IMAGE_BACKED_TYPES = new Set(['image', 'signature']);

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

function asVersion(value) {
    const number = Number(value);
    return Number.isInteger(number) && number >= 0 ? number : null;
}

/**
 * The document's state version as this tab knows it. The server refuses a save
 * whose base_version is not the current one, so the version only moves forward
 * through this tab's own successful saves. Anything else that shows a newer
 * version (a later page of the load, another tab's broadcast) makes it stale.
 */
export function createStateVersionTracker() {
    let version = null;
    let stale = false;

    return {
        /** A load response. Returns true if it shows this tab is already behind. */
        noteLoaded(value) {
            const loaded = asVersion(value);
            if (loaded === null) return stale;
            if (version === null) version = loaded;
            else if (loaded > version) stale = true;
            return stale;
        },
        noteSaved(value) {
            const saved = asVersion(value);
            if (saved !== null && (version === null || saved > version)) version = saved;
        },
        /** Another tab announced a save. Returns true if that makes this tab stale. */
        noteRemoteSave(value) {
            const remote = asVersion(value);
            if (remote !== null && (version === null || remote > version)) stale = true;
            return stale;
        },
        markStale() { stale = true; },
        reset() { version = null; stale = false; },
        get baseVersion() { return version; },
        get isStale() { return stale; },
    };
}

/**
 * The annotation as it should travel: once the server has stored an inline
 * image, the reference goes instead of the data. The in-memory annotation is
 * left alone (the page still draws from its dataUrl). A replaced image has a
 * different data string, so it is sent in full again.
 */
export function slimAnnotationForSave(annotation, storedAssets) {
    if (!annotation || typeof annotation !== 'object') return annotation;
    if (!IMAGE_BACKED_TYPES.has(cleanText(annotation.type).toLowerCase())) return annotation;
    const stored = storedAssets?.get?.(String(annotation.id ?? ''));
    if (!stored?.assetPath) return annotation;
    const inline = [annotation.dataUrl, annotation.src].find((value) => typeof value === 'string' && value.startsWith('data:'));
    if (!inline || inline !== stored.data) return annotation;
    // The same shape the server stored, so an unchanged image rewrites nothing:
    // inline data nulled where the key exists, plus what the server derived.
    const slim = { ...annotation, assetPath: stored.assetPath };
    for (const key of ['dataUrl', 'src']) {
        if (key in slim) slim[key] = null;
    }
    for (const key of ['mimeType', 'fileName']) {
        if (slim[key] == null && stored[key] != null) slim[key] = stored[key];
    }
    return slim;
}

/** Records what the server stored, keyed to the exact data that was sent. */
export function rememberStoredAssets(storedAssets, sentAnnotations, responseAssets) {
    if (!storedAssets || !responseAssets || typeof responseAssets !== 'object') return 0;
    let remembered = 0;
    for (const annotation of sentAnnotations || []) {
        const id = String(annotation?.id ?? '');
        const assetPath = cleanText(responseAssets[id]?.assetPath);
        if (!id || !assetPath) continue;
        const inline = [annotation.dataUrl, annotation.src].find((value) => typeof value === 'string' && value.startsWith('data:'));
        if (!inline) continue;
        storedAssets.set(id, {
            assetPath,
            data: inline,
            mimeType: responseAssets[id].mimeType ?? null,
            fileName: responseAssets[id].fileName ?? null,
        });
        remembered += 1;
    }
    return remembered;
}

function firstValidationMessage(body) {
    const errors = body?.errors;
    if (errors && typeof errors === 'object') {
        for (const messages of Object.values(errors)) {
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (cleanText(message)) return cleanText(message);
        }
    }
    return cleanText(body?.message);
}

/**
 * What a refused save means. kind: resync | stale | throttled | too_large | invalid |
 * session | error. What happens next is planAfterSaveFailure() in save-resilience.js.
 */
export function classifySaveFailure(status, body = {}, retryAfterHeader = '') {
    const code = cleanText(body?.code);
    // A delta save the server could not apply to its rows: not a conflict,
    // the editor just sends the whole state (delta-save.js).
    if (code === 'delta_base_missing') {
        return { kind: 'resync', message: 'Saving everything again…' };
    }
    if (status === 409 || code === 'stale_state') {
        return {
            kind: 'stale',
            message: cleanText(body?.message, 'This document was changed in another tab or window. Reload to get the latest version.'),
        };
    }
    if (status === 429) {
        const seconds = Number.parseInt(retryAfterHeader, 10);
        const retryAfterMs = (Number.isFinite(seconds) && seconds > 0 ? Math.min(seconds, 120) : 15) * 1000;
        return { kind: 'throttled', retryAfterMs, message: 'Saving is paused for a moment; your latest changes will be saved shortly.' };
    }
    if (status === 413) {
        return {
            kind: 'too_large',
            message: cleanText(body?.message, 'This document has too much edited content to save at once. Remove very large images and try again.'),
        };
    }
    if (status === 422) {
        return { kind: 'invalid', message: firstValidationMessage(body) || 'Some edited content could not be saved.' };
    }
    // An expired session shows up as 419 (CSRF), 401, or, once the token has
    // been refreshed under the new session, as 403/404: the document is no
    // longer this visitor's.
    if ([401, 403, 404, 419].includes(status)) {
        return { kind: 'session', message: 'Your session has expired. Sign in again to keep saving.' };
    }
    return { kind: 'error', message: cleanText(body?.message, `Save failed (${status || 'network error'}).`) };
}

/**
 * Tabs of the same document tell each other when they save. Falls back to a
 * no-op where BroadcastChannel is missing; the server check still holds there.
 */
export function createTabChannel(documentId, { onRemoteSave, ChannelImpl = globalThis.BroadcastChannel } = {}) {
    const tabId = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    if (typeof ChannelImpl !== 'function' || !cleanText(documentId)) {
        return { tabId, announceSave() {}, close() {} };
    }
    let channel;
    try {
        channel = new ChannelImpl(`netkit-editor-state-${documentId}`);
    } catch (_) {
        return { tabId, announceSave() {}, close() {} };
    }
    channel.onmessage = (event) => {
        const message = event?.data;
        if (!message || message.tabId === tabId || message.type !== 'saved') return;
        if (typeof onRemoteSave === 'function') onRemoteSave(asVersion(message.version));
    };
    return {
        tabId,
        announceSave(version) {
            try { channel.postMessage({ type: 'saved', tabId, version: asVersion(version) }); } catch (_) {}
        },
        close() {
            try { channel.close(); } catch (_) {}
        },
    };
}
