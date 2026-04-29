/*
 * cursor/canvas-cursor (Phase 7be).
 *
 * Resolve which CSS cursor the page-overlay canvases should display
 * based on which interaction mode is currently active. Pure read-only
 * function over the shared edit-mode + interaction stores.
 *
 * Mode precedence (first match wins):
 *   signaturePlacementState.active        \u2192 'copy'
 *   drawModeActive | eraseMode            \u2192 'crosshair'
 *   shapeCutState.armed                   \u2192 'crosshair'
 *   addTextMode | shapeMode               \u2192 'crosshair'
 *   default                               \u2192 'default'
 */

import { addTextMode, shapeMode, eraseMode } from '../store/editor-modes.js';
import { drawModeActive } from '../store/draw-tool-state.js';
import { signaturePlacementState } from '../store/signature-state.js';
import { shapeCutState } from '../store/interaction-state.js';

export function currentCanvasCursor() {
    if (signaturePlacementState.active) return 'copy';
    if (drawModeActive || eraseMode) return 'crosshair';
    if (shapeCutState.armed) return 'crosshair';
    if (addTextMode || shapeMode) return 'crosshair';
    return 'default';
}
