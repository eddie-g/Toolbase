// Phase 5k store slice: markup-tool composer state. Mirrors
// signature-state shape — wholesale reassignments route through setters,
// reads use the bare identifier via ES module live binding. The
// markupToolStrokes array is mutated in place (push/splice) by callers.

export let markupToolMode = 'draw';
export let markupToolDirty = false;
export let markupToolDrawing = false;
export let markupToolStrokes = [];
export let markupToolActiveStroke = null;
export let markupToolCtx = null;

export function setMarkupToolMode(value) { markupToolMode = value; }
export function setMarkupToolDirty(value) { markupToolDirty = !!value; }
export function setMarkupToolDrawing(value) { markupToolDrawing = !!value; }
export function setMarkupToolStrokes(value) { markupToolStrokes = value; }
export function setMarkupToolActiveStroke(value) { markupToolActiveStroke = value; }
export function setMarkupToolCtx(value) { markupToolCtx = value; }
