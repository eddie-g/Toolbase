// Phase 5g store slice: pointer interaction states. Each one is a single
// object that gets reassigned wholesale (or copied with spread) when a
// gesture starts / progresses / ends. Reads use the live binding; writes
// go through the per-state setter.

export let dragState = { active: false, pi: null, uid: null, offsetXPts: 0, offsetYPts: 0, startPt: null, startBox: null };
export let resizeState = { active: false, pi: null, uid: null, handle: null, startPt: null, startBox: null, startFontSize: null, startLineGeometry: null };
export let rotateState = { active: false, pi: null, uid: null, pointerId: null, grabAngleOffset: 0 };
export let textCreationState = { active: false, pi: null, pointerId: null, startX: 0, startY: 0, rect: null, previewEl: null, moved: false };
export let shapeCreationState = { active: false, pi: null, pointerId: null, startPt: null, currentPt: null, previewAnn: null, constrain: false };
export let shapeCutState = { armed: false, pi: null, uid: null, pointerId: null, startPt: null, currentPt: null };

export function setDragState(next) { dragState = next; }
export function setResizeState(next) { resizeState = next; }
export function setRotateState(next) { rotateState = next; }
export function setTextCreationState(next) { textCreationState = next; }
export function setShapeCreationState(next) { shapeCreationState = next; }
export function setShapeCutState(next) { shapeCutState = next; }
