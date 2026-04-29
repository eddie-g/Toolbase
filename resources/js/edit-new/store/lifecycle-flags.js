// Phase 5c store slice: top-level transient flags for the editor
// lifecycle. `isDirty` tracks unsaved annotation edits, `isSaving`
// guards re-entrant saves, `isDownloadingPdf` guards re-entrant PDF
// downloads. All are read in many places via the bare identifier
// (live binding) and mutated only via the setters below.

export let isDirty = false;
export let isSaving = false;
export let isDownloadingPdf = false;

export function setDirty(value) {
    isDirty = !!value;
}

export function setSaving(value) {
    isSaving = !!value;
}

export function setDownloadingPdf(value) {
    isDownloadingPdf = !!value;
}
