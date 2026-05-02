// pm-all-layer: mount one ProseMirror editor per text annotation on a page.
//
// Used when window.__pmSpike is on AND the user has toggled edit-text mode.
// Replaces the canvas-painted text + floating annotation toolbar UX with a
// per-annotation PM contenteditable.
//
// Public API:
//   mountPmAllLayer({ pi, getAnnotations, getScale, getCanvasHeight, editedTexts, markDirty, redrawOverlay })
//   unmountPmAllLayer(pi)
//   pmAllLayerActive(pi)
//   pmAllLayerHasAnnotation(pi, uid)
//   pmAllLayerRefresh(pi)  — re-position items (call after pan/zoom)
//
// The layer is the per-page <div id="pml-N"> element. Each annotation gets a
// child wrapper `.pml-item[data-uid=...]` styled with absolute coordinates,
// fonts, etc. mountPmEditor is called with that wrapper.

import { mountPmEditor } from './pm-spike.js';
import { resolveAnnBox } from '../annotations/box.js';

// pi → { wrappers: Map<uid, HTMLElement>, handles: Map<uid, mountHandle>, ctx }
const _state = new Map();

function getLayer(pi) {
    return document.getElementById('pml-' + (pi + 1));
}

export function pmAllLayerActive(pi) {
    return _state.has(pi);
}

export function pmAllLayerHasAnnotation(pi, uid) {
    const s = _state.get(pi);
    return !!(s && s.handles.has(String(uid || '')));
}

function isTextLike(ann) {
    const t = String(ann?.type || 'text').toLowerCase();
    return t === 'text';
}

function buildWrapperStyle(ann, scale, canvasHeight) {
    const box = resolveAnnBox(ann);
    if (!box) return null;
    const left = box.x * scale;
    const top = canvasHeight - (box.y + box.h) * scale;
    const width = Math.max(2, box.w * scale);
    const height = Math.max(2, box.h * scale);
    const fontSizePt = Number(ann?.fontSize) || 0;
    const fontSizePx = (fontSizePt > 0 ? fontSizePt : 12) * scale;
    const fontFamily = ann?.fontFamily || 'Arial, Helvetica, sans-serif';
    const fontWeight = ann?.fontWeight || (ann?._userAuthored ? 'normal' : '400');
    const fontStyle = ann?.fontStyle || 'normal';
    const lineHeightPx = Number(ann?.lineHeight) > 0
        ? Number(ann.lineHeight) * scale
        : fontSizePx * 1.2;
    const color = ann?.textColor || '#111827';
    return [
        'position:absolute',
        `left:${left.toFixed(2)}px`,
        `top:${top.toFixed(2)}px`,
        `width:${width.toFixed(2)}px`,
        `min-height:${height.toFixed(2)}px`,
        `font-family:${fontFamily}`,
        `font-size:${fontSizePx.toFixed(2)}px`,
        `font-weight:${fontWeight}`,
        `font-style:${fontStyle}`,
        `line-height:${lineHeightPx.toFixed(2)}px`,
        `color:${color}`,
        'background:transparent',
        'padding:0',
        'margin:0',
        'box-sizing:content-box',
        'white-space:pre-wrap',
        'overflow:visible',
        'pointer-events:auto',
        'z-index:5',
    ].join(';');
}

export function mountPmAllLayer(opts) {
    const { pi, getAnnotations, getScale, getCanvasHeight, editedTexts, markDirty, redrawOverlay } = opts;
    const layer = getLayer(pi);
    if (!layer) return false;
    if (_state.has(pi)) unmountPmAllLayer(pi);

    layer.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:5';
    layer.classList.add('pml-active');
    document.body.classList.add('pm-all-mode');

    const wrappers = new Map();
    const handles = new Map();
    _state.set(pi, { wrappers, handles, ctx: opts });

    const annotations = getAnnotations() || [];
    const scale = getScale();
    const canvasHeight = getCanvasHeight();

    annotations.forEach((ann) => {
        if (!isTextLike(ann) || !ann?._uid) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'pml-item';
        wrapper.dataset.uid = String(ann._uid);
        const cssText = buildWrapperStyle(ann, scale, canvasHeight);
        if (!cssText) return;
        wrapper.style.cssText = cssText;
        layer.appendChild(wrapper);
        wrappers.set(String(ann._uid), wrapper);
        try {
            const handle = mountPmEditor(wrapper, ann, {
                onChange: (text /*, richHtml */) => {
                    const savedText = String(ann.text ?? '');
                    if (text === savedText) {
                        if (editedTexts[ann._uid] !== undefined) {
                            delete editedTexts[ann._uid];
                            try { markDirty?.(); } catch (_e) {}
                        }
                    } else if (editedTexts[ann._uid] !== text) {
                        editedTexts[ann._uid] = text;
                        try { markDirty?.(); } catch (_e) {}
                    }
                    try { redrawOverlay?.(pi); } catch (_e) {}
                },
            });
            handles.set(String(ann._uid), handle);
        } catch (e) {
            console.warn('[pm-all-layer] mount failed for', ann._uid, e);
        }
    });

    return true;
}

export function pmAllLayerRefresh(pi) {
    const s = _state.get(pi);
    if (!s) return;
    const { ctx, wrappers } = s;
    const annotations = ctx.getAnnotations() || [];
    const scale = ctx.getScale();
    const canvasHeight = ctx.getCanvasHeight();
    annotations.forEach((ann) => {
        const wrapper = wrappers.get(String(ann?._uid));
        if (!wrapper) return;
        const cssText = buildWrapperStyle(ann, scale, canvasHeight);
        if (cssText) wrapper.style.cssText = cssText;
    });
}

export function unmountPmAllLayer(pi) {
    const s = _state.get(pi);
    if (!s) return;
    s.handles.forEach((h) => { try { h.destroy(); } catch (_e) {} });
    s.wrappers.forEach((w) => { try { w.remove(); } catch (_e) {} });
    s.handles.clear();
    s.wrappers.clear();
    _state.delete(pi);
    const layer = getLayer(pi);
    if (layer) {
        layer.innerHTML = '';
        layer.classList.remove('pml-active');
    }
    if (_state.size === 0) {
        document.body.classList.remove('pm-all-mode');
    }
}

export function pmAllLayerUnmountAll() {
    Array.from(_state.keys()).forEach((pi) => unmountPmAllLayer(pi));
}
