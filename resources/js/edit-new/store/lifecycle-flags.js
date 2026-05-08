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

// Phase 7g: `markDirty` / `markClean` migrated out of main.js. They wrap
// `setDirty()` and trigger a Save-UI refresh + (for markDirty) an
// auto-save schedule. The two side-effect collaborators still live in
// main.js and are wired in once at boot via `configureLifecycle()`.
let _updateSaveUi = () => {};
let _scheduleAutoSave = () => {};

/**
 * Wire the side-effect callbacks used by markDirty / markClean.
 *
 * @param {object}      deps
 * @param {() => void}  deps.updateSaveUi
 * @param {() => void}  deps.scheduleAutoSave
 */
export function configureLifecycle(deps) {
    if (!deps) return;
    if (typeof deps.updateSaveUi === 'function') _updateSaveUi = deps.updateSaveUi;
    if (typeof deps.scheduleAutoSave === 'function') _scheduleAutoSave = deps.scheduleAutoSave;
}

export function markDirty() {
    setDirty(true);
    _updateSaveUi();
    _scheduleAutoSave();
}

export function markClean() {
    setDirty(false);
    _updateSaveUi();
}
