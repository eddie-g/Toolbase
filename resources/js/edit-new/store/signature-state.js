// Phase 5j store slice: signature composer + library + canvas-side
// rendering tokens. signatureStrokes is an array reference that is
// mutated in place (push/splice) in many spots — those reads stay as
// `signatureStrokes`. Actual reassignments route through setters.

export let signatureMode = 'draw';
export let signatureDirty = false;
export let signatureDrawing = false;
export let signatureStrokes = [];
export let signatureActiveStroke = null;
export let signatureImageAsset = null;
export let signatureEditTarget = null;
export let savedSignatureLibrary = [];
export let signaturePlacementState = { active: false, asset: null, type: 'signature', toolSource: null };
export let signatureCtx = null;
export let signatureTypedRenderToken = 0;

export function setSignatureMode(value) { signatureMode = value; }
export function setSignatureDirty(value) { signatureDirty = !!value; }
export function setSignatureDrawing(value) { signatureDrawing = !!value; }
export function setSignatureStrokes(value) { signatureStrokes = value; }
export function setSignatureActiveStroke(value) { signatureActiveStroke = value; }
export function setSignatureImageAsset(value) { signatureImageAsset = value; }
export function setSignatureEditTarget(value) { signatureEditTarget = value; }
export function setSavedSignatureLibrary(value) { savedSignatureLibrary = value; }
export function setSignaturePlacementState(value) { signaturePlacementState = value; }
export function setSignatureCtx(value) { signatureCtx = value; }
export function bumpSignatureTypedRenderToken() { signatureTypedRenderToken += 1; return signatureTypedRenderToken; }
