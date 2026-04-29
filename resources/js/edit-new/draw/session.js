/*
 * draw/session (Phase 7bf).
 *
 * Two leaf helpers for the direct-draw / pen tool:
 *
 * - setDrawToolStatus(statusEl, message): write the status line in
 *   the draw-tool side panel.
 * - clearActiveDrawSession(): tear down any in-flight pen stroke
 *   layer (DOM child) and reset the activeDrawSession store entry.
 *
 * No closure-local state — both take their dependencies as args or
 * read the shared store directly.
 */

import { activeDrawSession, setActiveDrawSession } from '../store/draw-tool-state.js';

export function setDrawToolStatus(statusEl, message) {
    if (statusEl) statusEl.textContent = String(message || '');
}

export function clearActiveDrawSession() {
    if (activeDrawSession?.layer?.parentNode) {
        activeDrawSession.layer.parentNode.removeChild(activeDrawSession.layer);
    }
    setActiveDrawSession(null);
}
