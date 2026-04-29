/*
 * ui/save-ui (Phase 7bl).
 *
 * Pure DOM-write helper that syncs the save button + download-pdf
 * button + save status text against the lifecycle flags
 * (isSaving / isDownloadingPdf / isDirty). Takes the three DOM refs
 * as a single object so the module stays free of closure-local DOM
 * deps; main.js declares a zero-arg wrapper so existing call sites
 * stay unchanged.
 */

import { isSaving, isDownloadingPdf, isDirty } from '../store/lifecycle-flags.js';

export function updateSaveUi({ saveButton, downloadPdfButton, saveStatus }) {
    if (saveButton) {
        saveButton.disabled = isSaving;
        saveButton.textContent = isSaving ? 'Saving...' : 'Save';
    }
    if (downloadPdfButton) {
        downloadPdfButton.disabled = isDownloadingPdf;
        downloadPdfButton.textContent = isDownloadingPdf ? 'Preparing PDF...' : 'Download PDF';
    }
    if (saveStatus) {
        saveStatus.textContent = isDownloadingPdf
            ? 'Preparing PDF...'
            : (isSaving ? 'Saving...' : (isDirty ? 'Unsaved changes' : 'Saved'));
    }
}
