// Phase 5e store slice: zoom + autofit-suppression state. The
// `suppressEditorAutofitResetToken` is bumped each time
// `beginViewportScaleUpdate` runs so that any in-flight resets are
// cancelled (only the most recent caller should re-enable autofit).

export let currentZoomPercent = 100;
export let suppressEditorAutofit = false;
export let suppressEditorAutofitResetToken = 0;

export function setCurrentZoomPercent(value) {
    currentZoomPercent = Number(value) || 0;
}

export function setSuppressEditorAutofit(value) {
    suppressEditorAutofit = !!value;
}

export function bumpSuppressEditorAutofitResetToken() {
    suppressEditorAutofitResetToken += 1;
    return suppressEditorAutofitResetToken;
}
