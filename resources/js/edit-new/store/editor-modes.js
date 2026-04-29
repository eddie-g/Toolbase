// Phase 5d store slice: top-level editor mode flags. These are mutually
// constrained booleans that drive the canvas cursor, hit-testing, and
// which tool-panel UI is visible. Reads use the live binding; writes go
// through the setters. Higher-level coordination (mutual exclusion,
// `clearActiveAnnotation`, `redrawAllOverlays` etc.) stays in main.js.

export let editModeEnabled = false;
export let addTextMode = false;
export let shapeMode = false;
export let eraseMode = false;

export function setEditModeEnabledFlag(value) {
    editModeEnabled = !!value;
}

export function setAddTextModeFlag(value) {
    addTextMode = !!value;
}

export function setShapeModeFlag(value) {
    shapeMode = !!value;
}

export function setEraseModeFlag(value) {
    eraseMode = !!value;
}
