// Phase 5i store slice: shape inspector defaults. These eight values
// drive the live "what will the next shape look like" preview and feed
// every newly-created shape annotation. Reads use the bare identifier;
// writes go through the per-field setter or applyShapeDefaults().

export let currentShapeType = 'circle';
export let currentShapeStrokeColor = '#0f172a';
export let currentShapeStrokeOpacity = 1;
export let currentShapeStrokeWidth = 3;
export let currentShapeStrokeTransparent = false;
export let currentShapeFillColor = '#22c55e';
export let currentShapeFillOpacity = 0.22;
export let currentShapeFillTransparent = false;

export function setCurrentShapeType(value) { currentShapeType = value; }
export function setCurrentShapeStrokeColor(value) { currentShapeStrokeColor = value; }
export function setCurrentShapeStrokeOpacity(value) { currentShapeStrokeOpacity = value; }
export function setCurrentShapeStrokeWidth(value) { currentShapeStrokeWidth = value; }
export function setCurrentShapeStrokeTransparent(value) { currentShapeStrokeTransparent = !!value; }
export function setCurrentShapeFillColor(value) { currentShapeFillColor = value; }
export function setCurrentShapeFillOpacity(value) { currentShapeFillOpacity = value; }
export function setCurrentShapeFillTransparent(value) { currentShapeFillTransparent = !!value; }
