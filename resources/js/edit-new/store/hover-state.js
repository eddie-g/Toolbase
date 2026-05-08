// Phase 5b store slice: which annotation the pointer is currently hovering
// (visual-only highlight; not the same as the focused/selected
// `activeState`). Same live-binding pattern as ./active-state.js.

export let hoverState = { pi: null, uid: null };

export function setHoverState(pi, uid) {
    hoverState = { pi, uid };
}

export function clearHoverState() {
    hoverState = { pi: null, uid: null };
}
