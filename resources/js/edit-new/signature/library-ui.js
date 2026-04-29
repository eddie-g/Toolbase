/*
 * signature/library-ui (Phase 7bi).
 *
 * DOM-write helpers that paint the saved-signature library:
 * - updateSignatureLibraryLoadUi: rebuilds the <select> options and
 *   syncs the load button enabled state against the current store.
 * - renderSavedSignatureLibrary: paints the list of saved signatures
 *   (or an empty-state message), then delegates to the load-UI sync.
 *
 * Both depend on closure-local DOM refs in main.js
 * (signatureLibrarySelect, signatureLibraryLoadBtn, signatureLibraryList);
 * main.js re-binds zero-arg wrappers so existing call sites stay
 * unchanged.
 */

import { savedSignatureLibrary } from '../store/signature-state.js';
import { signatureModeLabel } from '../annotations/types.js';
import { escapeHtml } from '../util/html.js';

export function updateSignatureLibraryLoadUi({ signatureLibrarySelect, signatureLibraryLoadBtn }) {
    if (!signatureLibrarySelect) return;
    const hasEntries = savedSignatureLibrary.length > 0;
    const currentValue = String(signatureLibrarySelect.value || '');
    signatureLibrarySelect.innerHTML = '<option value="">Load a saved signature</option>';
    savedSignatureLibrary.forEach((entry) => {
        const option = document.createElement('option');
        option.value = String(entry.id || '');
        option.textContent = String(entry.name || 'Saved signature');
        signatureLibrarySelect.appendChild(option);
    });
    if (hasEntries && currentValue && savedSignatureLibrary.some((entry) => String(entry.id || '') === currentValue)) {
        signatureLibrarySelect.value = currentValue;
    } else {
        signatureLibrarySelect.value = '';
    }
    signatureLibrarySelect.disabled = !hasEntries;
    if (signatureLibraryLoadBtn) {
        signatureLibraryLoadBtn.disabled = !signatureLibrarySelect.value;
    }
}

export function renderSavedSignatureLibrary({ signatureLibraryList, signatureLibrarySelect, signatureLibraryLoadBtn }) {
    if (!signatureLibraryList) return;
    updateSignatureLibraryLoadUi({ signatureLibrarySelect, signatureLibraryLoadBtn });
    if (!savedSignatureLibrary.length) {
        signatureLibraryList.innerHTML = '<div class="signature-library__empty">No saved signatures yet.</div>';
        return;
    }

    signatureLibraryList.innerHTML = savedSignatureLibrary.map((entry) => {
        const entryId = String(entry.id || '');
        const label = String(entry.name || 'Saved signature');
        const modeLabel = signatureModeLabel(entry.asset?.signatureSourceMode || entry.asset?.signatureComposer?.mode || 'draw');
        const previewSrc = String(entry.previewDataUrl || entry.asset?.dataUrl || '').trim();
        const updatedAt = entry.updatedAt ? new Date(entry.updatedAt) : null;
        const updatedLabel = updatedAt && !Number.isNaN(updatedAt.getTime())
            ? updatedAt.toLocaleDateString()
            : 'Saved';
        return (
            `<div class="signature-library__item" data-signature-library-id="${entryId}">` +
                `<div class="signature-library__thumb">` +
                    (previewSrc ? `<img src="${previewSrc}" alt="${escapeHtml(label)}">` : '') +
                `</div>` +
                `<div class="signature-library__meta">` +
                    `<div class="signature-library__name-text" title="${escapeHtml(label)}">${escapeHtml(label)}</div>` +
                    `<div class="signature-library__detail">${modeLabel} signature • ${escapeHtml(updatedLabel)}</div>` +
                    `<div class="signature-library__actions">` +
                        `<button type="button" class="signature-library__btn" data-signature-library-action="load">Load</button>` +
                        `<button type="button" class="signature-library__btn is-danger" data-signature-library-action="delete">Delete</button>` +
                    `</div>` +
                `</div>` +
            `</div>`
        );
    }).join('');
}
