// Phase 5 store slice: which annotation is currently the editor's "active"
// (selected / focused) target. Lives in its own module so it can be shared
// without sprinkling globals; main.js still drives all transitions.
//
// Reads use the live `activeState` binding directly (ES module exports are
// live bindings, so reassignments inside this module are visible to all
// importers). Writes MUST go through `setActiveState` because importers
// cannot reassign an imported binding.

export let activeState = { pi: null, uid: null };

export function setActiveState(next) {
    activeState = next || { pi: null, uid: null };
}

export function clearActiveState() {
    activeState = { pi: null, uid: null };
}
