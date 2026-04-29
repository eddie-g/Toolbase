// Phase 5m store slice: leftover top-level mutable state that doesn't
// fit in any cohesive group. Each value lives behind its own setter so
// the strangler-fig pattern stays consistent with the other store/
// modules.

export let measureCanvas = null;
export let _annClipboard = null;
export let imageImportPendingAsset = null;
export let imageBackgroundRemovalState = { active: false, uid: null };
export let _formatBarMousedownTs = 0;
export let shapeConstrainShiftAttached = false;
export let shapeConstrainUserDismissedTip = false;

export function setMeasureCanvas(value) { measureCanvas = value; }
export function setAnnClipboard(value) { _annClipboard = value; }
export function setImageImportPendingAsset(value) { imageImportPendingAsset = value; }
export function setImageBackgroundRemovalState(value) { imageBackgroundRemovalState = value; }
export function setFormatBarMousedownTs(value) { _formatBarMousedownTs = Number(value) || 0; }
export function setShapeConstrainShiftAttached(value) { shapeConstrainShiftAttached = !!value; }
export function setShapeConstrainUserDismissedTip(value) { shapeConstrainUserDismissedTip = !!value; }
