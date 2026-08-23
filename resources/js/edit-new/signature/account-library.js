/*
 * signature/account-library (NK_Dev_4).
 *
 * Account-scoped saved signatures, backing the "Save signature" button beside
 * Clear and the "Your account" list in the modal's Saved tab.
 *
 * This is deliberately separate from persistence/signature-library.js: that one
 * is localStorage and therefore per-browser, while these follow the user to any
 * browser they sign in on. Both are shown in the Saved tab.
 */

import { escapeHtml } from '../util/html.js';
import { signatureModeLabel } from '../annotations/types.js';

/** In-memory mirror of the account list, per editor instance. */
export function createAccountLibraryStore() {
    return { signedIn: false, entries: [], loaded: false };
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function request(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(options.method && options.method !== 'GET' ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        ...options,
    });

    let payload = null;
    try {
        payload = await response.json();
    } catch (_) {
        payload = null;
    }

    return { ok: response.ok, status: response.status, payload };
}

/**
 * Load the account's signatures. A 401 simply means "not signed in", which is
 * a normal state for the guest editor, not an error to surface.
 */
export async function fetchAccountSignatures(url, store) {
    if (!url) return store;
    const { ok, status, payload } = await request(url);

    store.loaded = true;
    if (status === 401 || (payload && payload.signed_in === false)) {
        store.signedIn = false;
        store.entries = [];
        return store;
    }
    if (ok && payload?.success) {
        store.signedIn = true;
        store.entries = Array.isArray(payload.signatures) ? payload.signatures : [];
    }
    return store;
}

/** Persist the current composer asset against the account. */
export async function saveAccountSignature(url, store, asset, name) {
    const { ok, status, payload } = await request(url, {
        method: 'POST',
        body: JSON.stringify({
            name: name || '',
            source_mode: asset?.signatureSourceMode || 'draw',
            data_url: asset?.dataUrl || '',
            composer: asset?.signatureComposer || null,
            width: Math.max(1, Number(asset?.width) || 1),
            height: Math.max(1, Number(asset?.height) || 1),
        }),
    });

    if (status === 401) {
        store.signedIn = false;
        return { ok: false, message: payload?.message || 'Sign in to save signatures to your account.' };
    }
    if (!ok || !payload?.success) {
        return { ok: false, message: payload?.message || 'Could not save that signature to your account.' };
    }

    store.signedIn = true;
    store.entries = [payload.signature, ...store.entries];
    return { ok: true, signature: payload.signature };
}

/** Remove one of the account's signatures. */
export async function deleteAccountSignature(baseUrl, store, id) {
    const { ok, status, payload } = await request(`${baseUrl}/${encodeURIComponent(id)}`, { method: 'DELETE' });

    if (status === 401) {
        store.signedIn = false;
        return { ok: false, message: payload?.message || 'Sign in to manage your saved signatures.' };
    }
    if (!ok || !payload?.success) {
        return { ok: false, message: payload?.message || 'Could not delete that signature.' };
    }

    store.entries = store.entries.filter((entry) => String(entry.id) !== String(id));
    return { ok: true };
}

/**
 * Paint the account list. Entry names come from user input, so every value is
 * escaped before it reaches innerHTML.
 */
export function renderAccountLibrary(store, { listEl, copyEl }) {
    if (!listEl) return;

    if (!store.signedIn) {
        listEl.innerHTML = '<div class="signature-library__empty">Sign in to save signatures to your account.</div>';
        if (copyEl) copyEl.textContent = 'Account signatures follow you to any browser you sign in on.';
        return;
    }

    if (copyEl) {
        copyEl.textContent = 'Signatures saved to your account are available on any browser you sign in on.';
    }

    if (!store.entries.length) {
        listEl.innerHTML = '<div class="signature-library__empty">No account signatures yet.</div>';
        return;
    }

    listEl.innerHTML = store.entries.map((entry) => {
        const id = escapeHtml(String(entry.id || ''));
        const label = String(entry.name || 'Signature');
        const mode = signatureModeLabel(entry.sourceMode || entry.composer?.mode || 'draw');
        const preview = String(entry.dataUrl || '').trim();
        const updated = entry.updatedAt ? new Date(entry.updatedAt) : null;
        const updatedLabel = updated && !Number.isNaN(updated.getTime()) ? updated.toLocaleDateString() : 'Saved';

        return (
            `<div class="signature-library__item" data-account-signature-id="${id}">`
                + `<div class="signature-library__thumb">`
                    + (preview ? `<img src="${escapeHtml(preview)}" alt="${escapeHtml(label)}">` : '')
                + `</div>`
                + `<div class="signature-library__meta">`
                    + `<div class="signature-library__name-text" title="${escapeHtml(label)}">${escapeHtml(label)}</div>`
                    + `<div class="signature-library__detail">${escapeHtml(mode)} signature • ${escapeHtml(updatedLabel)}</div>`
                    + `<div class="signature-library__actions">`
                        + `<button type="button" class="signature-library__btn" data-account-signature-action="load">Load</button>`
                        + `<button type="button" class="signature-library__btn is-danger" data-account-signature-action="delete">Delete</button>`
                    + `</div>`
                + `</div>`
            + `</div>`
        );
    }).join('');
}

/** Shape an account entry so the existing composer loader can consume it. */
export function accountEntryAsAnnotation(entry) {
    return {
        type: 'signature',
        signatureSourceMode: entry.sourceMode || entry.composer?.mode || 'draw',
        signatureComposer: entry.composer || null,
        dataUrl: entry.dataUrl || '',
        src: '',
        fileName: 'signature.png',
        mimeType: 'image/png',
        intrinsicWidth: entry.width || 1,
        intrinsicHeight: entry.height || 1,
        assetPath: null,
    };
}
