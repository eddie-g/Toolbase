/*
 * Pointer dispatcher (Phase 6d).
 *
 * Owns the four window-level listeners that drive in-flight drag,
 * resize, and rotate gestures plus the two pure helpers used by the
 * resize branch (`shouldScaleFontOnResize`, `scaledResizeFontSize`):
 *
 *   - window mousemove   → drag move / resize move
 *   - window mouseup     → endDrag / endResize
 *   - window pointermove → rotate move
 *   - window pointerup   → endRotate
 *   - window pointercancel → endRotate
 *
 * State + pure helpers are imported directly. The still-monolithic main.js
 * helpers (redrawOverlay, syncActiveEditor, updateFormatBar) flow in via
 * deps and will be cleaned up once those move to modules.
 */

import { dragState, resizeState, rotateState } from '../store/interaction-state.js';
import { pageData } from '../store/page-data.js';
import { pdfPtFromClient } from '../render/coords.js';
import { isShapeAnnotation, isLineShape, isTextAnnotation } from '../annotations/types.js';
import { snapLineEndpoint, computeLineBoxGeometry, normalizeRotationDegrees } from '../util/geometry.js';
import { setAnnotationBox } from '../annotations/geometry-writes.js';
import { annTextIsEdited } from '../annotations/state.js';
import { editedTexts } from '../store/edited-texts.js';
import { measureEditedTextHeightPts } from '../text/measure.js';
import { normalizeTextForDomReflow } from '../text/dom-reflow.js';
import { endDrag } from './drag.js';
import { endResize } from './resize.js';
import { endRotate, getRotationCenterClient, pointerAngleDeg } from './rotate.js';

export function shouldScaleFontOnResize(_ann) {
    return false;
}

export function scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight) {
    const baselineFontSize = Math.max(1, Number(startFontSize) || 12);
    const widthScale = (Number(startBox?.w) || 0) > 0 ? (nextWidth / startBox.w) : 1;
    const heightScale = (Number(startBox?.h) || 0) > 0 ? (nextHeight / startBox.h) : 1;
    const scaleFactor = Math.sqrt(
        Math.max(0.01, Number.isFinite(widthScale) ? widthScale : 1)
        * Math.max(0.01, Number.isFinite(heightScale) ? heightScale : 1)
    );
    return Math.max(6, Math.min(144, baselineFontSize * scaleFactor));
}

function isPromotedExtractionText(ann) {
    return ann?.promotedFromExtraction === true
        || (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
        || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
}

function textForResizeMeasurement(ann, currentText, startBox, nextWidth, nextHeight) {
    if (!isPromotedExtractionText(ann)) return currentText;
    if (annTextIsEdited(ann)) return currentText;
    const sessionText = editedTexts[ann?._uid];
    if (sessionText !== undefined && String(sessionText) !== String(ann?.text ?? '')) return currentText;
    const dimensionsChanging = (Number.isFinite(Number(startBox?.w)) && Math.abs(Number(startBox.w) - nextWidth) > 0.25)
        || (Number.isFinite(Number(startBox?.h)) && Math.abs(Number(startBox.h) - nextHeight) > 0.25);
    return dimensionsChanging ? normalizeTextForDomReflow(currentText) : currentText;
}

/**
 * Attach the window-level pointer listeners that drive in-flight
 * drag / resize / rotate. Call once at editor bootstrap.
 *
 * @param {Object} deps
 * @param {(pi: number) => void} deps.redrawOverlay
 * @param {(forceRebuild?: boolean) => void} deps.syncActiveEditor
 * @param {() => void} deps.updateFormatBar
 */
export function installPointerDispatcher(deps) {
    const {
        redrawOverlay,
        syncActiveEditor,
        updateFormatBar,
    } = deps;

    window.addEventListener('mousemove', (e) => {
        if (dragState.active) {
            const { pi, uid, startPt, startBox, offsetXPts, offsetYPts } = dragState;
            const data = pageData[pi];
            const ann  = data?.annotations.find(a => a._uid === uid);
            if (!ann || !startPt || !startBox) return;
            const pt  = pdfPtFromClient(e.clientX, e.clientY, pi);
            if (!pt) return;
            const fallbackX = pt.x - offsetXPts;
            const fallbackY = pt.y - offsetYPts;
            const nextX = Number.isFinite(startPt.x)
                ? startBox.x + (pt.x - startPt.x)
                : fallbackX;
            const nextY = Number.isFinite(startPt.y)
                ? startBox.y + (pt.y - startPt.y)
                : fallbackY;
            setAnnotationBox(ann, {
                x: Math.min(Math.max(0, nextX), Math.max(0, data.wPts - startBox.w)),
                y: Math.min(Math.max(0, nextY), Math.max(0, data.hPts - startBox.h)),
                w: startBox.w, h: startBox.h,
            });
            redrawOverlay(pi);
            syncActiveEditor();
            return;
        }

        if (!resizeState.active) return;
        const { pi, uid, handle, startPt, startBox, startFontSize, startLineGeometry } = resizeState;
        const data = pageData[pi];
        const ann = data?.annotations.find(a => a._uid === uid);
        const pt = pdfPtFromClient(e.clientX, e.clientY, pi);
        if (!ann || !pt || !startPt || !startBox) return;

        if (isShapeAnnotation(ann) && isLineShape(ann) && (handle === 'line-start' || handle === 'line-end')) {
            const geometry = startLineGeometry || {
                lineStartX: 0,
                lineStartY: 0,
                lineEndX: 1,
                lineEndY: 1,
            };
            // geometry.line*Y is image-y-down (0 = visual top of box,
            // 1 = visual bottom). startBox.y is the PDF-y of the bottom
            // edge (PDF-y-up), so the visual top of the box is
            // startBox.y + startBox.h. Flip to PDF-y-up before passing
            // back into computeLineBoxGeometry, which expects PDF-up.
            const startPoint = {
                x: startBox.x + (startBox.w * geometry.lineStartX),
                y: startBox.y + (startBox.h * (1 - geometry.lineStartY)),
            };
            const endPoint = {
                x: startBox.x + (startBox.w * geometry.lineEndX),
                y: startBox.y + (startBox.h * (1 - geometry.lineEndY)),
            };
            const draggedPoint = {
                x: Math.max(0, Math.min(data.wPts, pt.x)),
                y: Math.max(0, Math.min(data.hPts, pt.y)),
            };
            const nextGeometry = handle === 'line-start'
                ? (() => {
                    const snappedStart = snapLineEndpoint(endPoint.x, endPoint.y, draggedPoint.x, draggedPoint.y);
                    return computeLineBoxGeometry(snappedStart.x, snappedStart.y, endPoint.x, endPoint.y);
                })()
                : (() => {
                    const snappedEnd = snapLineEndpoint(startPoint.x, startPoint.y, draggedPoint.x, draggedPoint.y);
                    return computeLineBoxGeometry(startPoint.x, startPoint.y, snappedEnd.x, snappedEnd.y);
                })();

            setAnnotationBox(ann, {
                x: nextGeometry.left,
                y: nextGeometry.bottom,
                w: nextGeometry.width,
                h: nextGeometry.height,
            });
            ann.lineStartX = nextGeometry.lineStartX;
            ann.lineStartY = nextGeometry.lineStartY;
            ann.lineEndX = nextGeometry.lineEndX;
            ann.lineEndY = nextGeometry.lineEndY;

            redrawOverlay(pi);
            syncActiveEditor();
            updateFormatBar();
            return;
        }

        const minWidthPts = isShapeAnnotation(ann) ? (isLineShape(ann) ? 4 : 8) : 12;
        const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
        const right = startBox.x + startBox.w;
        const top = startBox.y + startBox.h;

        let nextLeft = startBox.x;
        let nextRight = right;
        let nextBottom = startBox.y;
        let nextTop = top;

        if (handle.includes('w')) {
            nextLeft = Math.min(pt.x, right - minWidthPts);
        }
        if (handle.includes('e')) {
            nextRight = Math.max(pt.x, startBox.x + minWidthPts);
        }
        if (handle.includes('s')) {
            nextBottom = pt.y;
        }
        if (handle.includes('n')) {
            nextTop = pt.y;
        }

        nextLeft = Math.max(0, nextLeft);
        nextBottom = Math.max(0, nextBottom);
        nextRight = Math.min(data.wPts, nextRight);
        nextTop = Math.min(data.hPts, nextTop);

        let nextWidth = Math.max(minWidthPts, nextRight - nextLeft);
        let nextHeight = Math.max(isShapeAnnotation(ann) ? (isLineShape(ann) ? 4 : 8) : 8, nextTop - nextBottom);

        if (isShapeAnnotation(ann)) {
            nextBottom = Math.max(0, nextBottom);
            nextTop = Math.min(data.hPts, nextTop);
            nextWidth = Math.max(minWidthPts, nextRight - nextLeft);
            nextHeight = Math.max(isLineShape(ann) ? 4 : 8, nextTop - nextBottom);
        } else if (shouldScaleFontOnResize(ann) && startFontSize) {
            const previousFontSize = ann.fontSize;
            ann.fontSize = scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight);
            const measureText = textForResizeMeasurement(ann, currentText, startBox, nextWidth, nextHeight);
            const scaledMinHeightPts = Math.max(8, measureEditedTextHeightPts(ann, measureText, data.scale, nextWidth));
            ann.fontSize = previousFontSize;

            if (handle.includes('s')) {
                nextBottom = Math.min(nextBottom, nextTop - scaledMinHeightPts);
            }
            if (handle.includes('n')) {
                nextTop = Math.max(nextTop, nextBottom + scaledMinHeightPts);
            }
            nextBottom = Math.max(0, nextBottom);
            nextTop = Math.min(data.hPts, nextTop);
            nextHeight = Math.max(scaledMinHeightPts, nextTop - nextBottom);
        } else {
            const measureText = textForResizeMeasurement(ann, currentText, startBox, nextWidth, nextHeight);
            const minHeightPts = Math.max(8, measureEditedTextHeightPts(ann, measureText, data.scale, nextWidth));
            if (handle.includes('s')) {
                nextBottom = Math.min(nextBottom, nextTop - minHeightPts);
            }
            if (handle.includes('n')) {
                nextTop = Math.max(nextTop, nextBottom + minHeightPts);
            }
            nextBottom = Math.max(0, nextBottom);
            nextTop = Math.min(data.hPts, nextTop);
            nextHeight = Math.max(minHeightPts, nextTop - nextBottom);
        }
        nextWidth = Math.max(minWidthPts, nextRight - nextLeft);

        if (isTextAnnotation(ann)) {
            ann._pageBoundsConstrained = false;
        }
        setAnnotationBox(ann, {
            x: nextLeft,
            y: nextBottom,
            w: nextWidth,
            h: nextHeight,
        });
        if (shouldScaleFontOnResize(ann) && startFontSize) {
            ann.fontSize = scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight);
        }

        redrawOverlay(pi);
        syncActiveEditor();
        updateFormatBar();
    });

    window.addEventListener('mouseup', () => {
        if (dragState.active) endDrag();
        if (resizeState.active) endResize();
    });

    window.addEventListener('pointermove', (e) => {
        if (!rotateState.active) return;
        if (rotateState.pointerId !== null && e.pointerId !== rotateState.pointerId) return;
        const { pi, uid, grabAngleOffset } = rotateState;
        const data = pageData[pi];
        const ann = data?.annotations.find(a => a._uid === uid);
        if (!ann) return;
        const center = getRotationCenterClient(pi, ann);
        if (!center) return;
        const pAngle = pointerAngleDeg(e.clientX, e.clientY, center);
        const handleAngle = normalizeRotationDegrees(pAngle - grabAngleOffset);
        ann.rotation = normalizeRotationDegrees(handleAngle - 90);
        redrawOverlay(pi);
        syncActiveEditor(true);
    });

    window.addEventListener('pointerup', (e) => {
        if (!rotateState.active) return;
        if (rotateState.pointerId !== null && e.pointerId !== rotateState.pointerId) return;
        endRotate();
    });

    window.addEventListener('pointercancel', (e) => {
        if (!rotateState.active) return;
        if (rotateState.pointerId !== null && e.pointerId !== rotateState.pointerId) return;
        endRotate();
    });
}
