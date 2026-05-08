/**
 * editedTexts — per-session uid → string map of text edits made in the
 * current editor session. Keyed by `ann._uid`. Mutated in place; never
 * reassigned (snapshot/restore for undo copies via `{ ...editedTexts }`
 * and rebuilds with `delete` + `Object.assign`).
 */
export const editedTexts = {};
