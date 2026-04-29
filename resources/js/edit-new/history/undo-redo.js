/**
 * Undo / redo history for the edit-new editor.
 *
 * Snapshots capture the mutable parts of editor state that can change as
 * a result of a user action: per-page annotation arrays, the
 * `editedTexts` uid->string map, and the `pendingDeleted*` sets.
 *
 * `pushUndo()` is the single entry point for "I'm about to mutate state".
 * `performUndo()` / `performRedo()` swap the live state with the snapshot
 * at the top of the corresponding stack.
 *
 * The bootstrap entrypoint `configureHistory(deps)` wires in the still-
 * monolithic main.js helpers (`clearActiveAnnotation`, `redrawOverlay`)
 * and the two undo/redo `<button>` elements. Once those
 * helpers move to modules the deps wiring evaporates without changing
 * this module's API.
 */

import { cloneSerializableValue } from '../util/clone.js';
import { pageData } from '../store/page-data.js';
import { editedTexts } from '../store/edited-texts.js';
import {
    pendingDeletedAnnotationIds,
    pendingDeletedPromotedSourceKeys,
} from '../store/pending-deletes.js';
import { markDirty } from '../store/lifecycle-flags.js';

const HISTORY_LIMIT = 100;
const undoStack = [];
const redoStack = [];

let undoButton = null;
let redoButton = null;
let clearActiveAnnotation = () => {};
let redrawOverlay = () => {};

/**
 * Wire collaborators. Call once during editor bootstrap.
 *
 * @param {Object} deps
 * @param {HTMLButtonElement|null} deps.undoButton
 * @param {HTMLButtonElement|null} deps.redoButton
 * @param {() => void} deps.clearActiveAnnotation
 * @param {(pi: number) => void} deps.redrawOverlay
 */
export function configureHistory(deps) {
    undoButton = deps.undoButton ?? null;
    redoButton = deps.redoButton ?? null;
    if (typeof deps.clearActiveAnnotation === 'function') clearActiveAnnotation = deps.clearActiveAnnotation;
    if (typeof deps.redrawOverlay === 'function') redrawOverlay = deps.redrawOverlay;
    updateHistoryUi();
}

function captureSnapshot() {
    const annsByPage = {};
    for (const [pi, data] of Object.entries(pageData)) {
        annsByPage[pi] = data.annotations.map((ann) => cloneSerializableValue(ann, { ...ann }));
    }
    return {
        annsByPage,
        editedTexts: { ...editedTexts },
        deletedIds: new Set(pendingDeletedAnnotationIds),
        deletedKeys: new Set(pendingDeletedPromotedSourceKeys),
    };
}

function applySnapshot(snap) {
    for (const [pi, anns] of Object.entries(snap.annsByPage)) {
        const data = pageData[pi];
        if (data) data.annotations = anns.map((ann) => cloneSerializableValue(ann, { ...ann }));
    }
    for (const key of Object.keys(editedTexts)) delete editedTexts[key];
    Object.assign(editedTexts, snap.editedTexts);
    pendingDeletedAnnotationIds.clear();
    for (const id of snap.deletedIds) pendingDeletedAnnotationIds.add(id);
    pendingDeletedPromotedSourceKeys.clear();
    for (const key of snap.deletedKeys) pendingDeletedPromotedSourceKeys.add(key);
}

export function updateHistoryUi() {
    if (undoButton) undoButton.disabled = undoStack.length === 0;
    if (redoButton) redoButton.disabled = redoStack.length === 0;
}

/** Call BEFORE a state-mutating user action. */
export function pushUndo() {
    undoStack.push(captureSnapshot());
    if (undoStack.length > HISTORY_LIMIT) undoStack.shift();
    redoStack.length = 0;
    updateHistoryUi();
}

export function performUndo() {
    if (undoStack.length === 0) return;
    redoStack.push(captureSnapshot());
    const snap = undoStack.pop();
    clearActiveAnnotation();
    applySnapshot(snap);
    for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
    markDirty();
    updateHistoryUi();
}

export function performRedo() {
    if (redoStack.length === 0) return;
    undoStack.push(captureSnapshot());
    const snap = redoStack.pop();
    clearActiveAnnotation();
    applySnapshot(snap);
    for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
    markDirty();
    updateHistoryUi();
}
