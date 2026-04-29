// Phase 5.5a store slice: shared per-page render+annotation state.
//
// `pageData` is keyed by page index (0-based) and holds:
//   { wPts, hPts, fitScale, scale, canvasWidth, canvasHeight, annotations, acroWidgets }
//
// The object is constructed once at module load. Callers mutate it in
// place via `pageData[pi] = {...}` / `delete pageData[pi]`; no
// reassignment of the binding itself is needed, so this slice doesn't
// expose a setter — the live `const` export is sufficient.
export const pageData = {};
