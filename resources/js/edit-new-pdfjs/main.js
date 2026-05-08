/*
 * /edit-new?pdfjs=1 — pdf.js PDFViewer integration.
 *
 * Hosted inside the same /edit-new chrome (topbar / floating toolbar /
 * panels / modals included as blade partials, but their legacy main.js is
 * NOT loaded — every legacy button is visually disabled via CSS in
 * resources/css/edit-new-pdfjs/index.css).
 *
 * Active features here:
 *   - PDFViewer renders the original PDF inside #viewerContainer.
 *   - Click any text span → inline editor → POST surgical /Tj rewrite to
 *     documents.editPdfjsRewriteTj → re-feed returned bytes into the viewer.
 *   - Download posts the current saved/session overlays to the existing
 *     /edit-new writer endpoint so the exported PDF matches that pipeline.
 *
 * Step 4 will progressively re-enable the legacy chrome buttons by
 * routing them through the AnnotationEditorUIManager that PDFViewer
 * already exposes.
 */

import * as pdfjsLib from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import {
    PDFViewer,
    EventBus,
    PDFLinkService,
    PDFFindController,
} from 'pdfjs-dist/web/pdf_viewer.mjs';
import { generateUuidV4 } from '../edit-new/util/uuid.js';
import { installSignatureFeature, isSignatureAnnotation } from './signature.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

const root = document.getElementById('enpv-root');
const editNewRoot = document.getElementById('edit-new-root');
const container = document.getElementById('viewerContainer');
const statusEl = document.getElementById('enpv-status');
const loadingScreen = document.getElementById('enpv-loading-screen');
const editBar = document.getElementById('enpv-edit-bar');
const editInput = document.getElementById('enpv-input');
const editOrig = document.getElementById('enpv-orig');
const editApply = document.getElementById('enpv-apply');
const editCancel = document.getElementById('enpv-cancel');
const downloadBtn = document.getElementById('enpv-download');
const downloadPdfButton = document.getElementById('download-pdf-btn');
const saveButton = document.getElementById('save-btn');
const undoButton = document.getElementById('undo-btn');
const redoButton = document.getElementById('redo-btn');
const saveStatus = document.getElementById('save-status');
const saveToast = document.getElementById('save-toast');
const annFormatBar = document.getElementById('ann-format-bar');
const afbFont = document.getElementById('afb-font');

const PDF_URL = root.dataset.pdfUrl;
const BAKED_URL = root.dataset.bakedUrl;
const REWRITE_URL = root.dataset.rewriteUrl;
const REDACT_URL = root.dataset.redactUrl;
const MOVE_URL = root.dataset.moveUrl;
const REFLOW_URL = root.dataset.reflowUrl;
const CSRF = root.dataset.csrf;
const DOC_ID = root.dataset.docId;
const INFO_URL = editNewRoot?.dataset?.infoUrl;
const SAVE_URL = editNewRoot?.dataset?.saveUrl;
const DOWNLOAD_URL = editNewRoot?.dataset?.downloadUrl;

let currentPdfDoc = null;
let currentPdfBytes = null;
let activeRun = null;
let pendingEditCount = 0;
let annotationBoxesByPage = new Map();
let persistedAnnotationsById = new Map();
const canvasRewrittenAnnotationIds = new Set();
const sourceRedactedAnnotationIds = new Set();
const pendingDeletedAnnotationIds = new Set();
const undoStack = [];
const redoStack = [];
const MAX_HISTORY_DEPTH = 100;
let applyingHistory = false;
let saveToastTimer = null;
let annotationBoxesLoadPromise = null;
let viewerLoadGeneration = 0;
let revealedLoadGeneration = 0;
let hydratingPersistedAnnotations = false;
// AcroForm entries echoed by documentInfo (server-side persisted state).
// We hold them so loadAcroFormEntriesIntoStorage can re-apply previously
// saved field values into pdf.js's annotationStorage every time the PDF
// is (re)loaded — reload happens on initial load, after a move/reflow,
// and after annotation rebuilds. Each entry shape mirrors what /edit-new
// posts: { key, fieldName, value, pageIndex, fieldType, rect, ... }.
let acroFormEntries = [];

function nextAnimationFrame() {
    return new Promise((resolve) => {
        let resolved = false;
        const finish = () => {
            if (resolved) return;
            resolved = true;
            resolve();
        };
        requestAnimationFrame(finish);
        window.setTimeout(finish, 100);
    });
}

function hideViewerUntilOverlaysReady() {
    document.body.classList.remove('enpv-viewer-ready');
    document.body.classList.add('enpv-viewer-loading');
}

function revealViewer() {
    document.body.classList.add('enpv-viewer-ready');
    document.body.classList.remove('enpv-viewer-loading');
}

function setLoadingScreenMessage(message) {
    const label = loadingScreen?.querySelector?.('.enpv-loading-title');
    if (label) label.textContent = message;
}

function isCurrentViewerLoad(loadGeneration) {
    return loadGeneration === viewerLoadGeneration;
}

function waitMs(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function waitForViewerPagesReady(loadGeneration, timeoutMs = 8000) {
    const started = performance.now();
    while (isCurrentViewerLoad(loadGeneration) && performance.now() - started < timeoutMs) {
        const expectedPages = Number(currentPdfDoc?.numPages || 0);
        if (expectedPages > 0
            && pdfViewer.pagesCount >= expectedPages
            && pdfViewer.getPageView(0)?.div) {
            return true;
        }
        await waitMs(50);
    }
    return false;
}

function setDownloadButtonsDisabled(disabled, label = null) {
    for (const button of [downloadBtn, downloadPdfButton].filter(Boolean)) {
        button.disabled = Boolean(disabled);
        if (label) button.textContent = label;
    }
}

function setStatus(msg, isError = false) {
    statusEl.textContent = msg;
    statusEl.style.color = isError ? '#f88' : '#ddd';
}

function showError(msg) {
    let el = document.querySelector('.enpv-error');
    if (el) el.remove();
    el = document.createElement('div');
    el.className = 'enpv-error';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 7000);
}

function setSaveStatus(text, isError = false) {
    if (!saveStatus) return;
    saveStatus.textContent = text;
    saveStatus.style.color = isError ? '#fca5a5' : '';
}

function markManualSaveNeeded() {
    if (hydratingPersistedAnnotations) return;
    setSaveStatus('Unsaved changes');
    setStatus('Unsaved changes. Click Save when ready.');
}

function flashSaveToast(message) {
    if (!saveToast) return;
    saveToast.textContent = message;
    saveToast.classList.add('show');
    if (saveToastTimer) window.clearTimeout(saveToastTimer);
    saveToastTimer = window.setTimeout(() => {
        saveToast.classList.remove('show');
        saveToastTimer = null;
    }, 1800);
}

function normalizeComparableText(value) {
    return String(value ?? '').replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').trim();
}

function approxEqual(a, b, tolerance = 0.75) {
    return Math.abs((Number(a) || 0) - (Number(b) || 0)) <= tolerance;
}

function buildPdfjsAnnotationId(pageIndex, anchor) {
    const safeAnchor = String(anchor || '0').replace(/[^a-zA-Z0-9:_-]/g, '_');
    return `pdfjs_${DOC_ID}_${pageIndex}_${safeAnchor}`;
}

function sourceHandleUid(pageIndex, uid, matchedAnnotation = null) {
    if (matchedAnnotation?.id) return String(uid);
    const id = buildPdfjsAnnotationId(pageIndex, uid);
    return persistedAnnotationsById.has(id)
        ? `source:${uid}`
        : String(uid);
}

function sourceHandleAnnotationId(pageIndex, uid, matchedAnnotation = null) {
    if (matchedAnnotation?.id) return String(matchedAnnotation.id);
    return buildPdfjsAnnotationId(pageIndex, sourceHandleUid(pageIndex, uid, matchedAnnotation));
}

function annotationCurrentPdfBox(annotation) {
    const pX = Number(annotation?.pdfX);
    const pY = Number(annotation?.pdfY);
    const pW = Number(annotation?.pdfWidth);
    const pH = Number(annotation?.pdfHeight);
    if ([pX, pY, pW, pH].every(Number.isFinite) && pW > 0 && pH > 0) {
        return { x: pX, y: pY, w: pW, h: pH };
    }
    return null;
}

function annotationBaselinePdfBox(annotation) {
    const bX = Number(annotation?.pdfjsSourceX);
    const bY = Number(annotation?.pdfjsSourceY);
    const bW = Number(annotation?.pdfjsSourceW);
    const bH = Number(annotation?.pdfjsSourceH);
    if ([bX, bY, bW, bH].every(Number.isFinite) && bW > 0 && bH > 0) {
        return { x: bX, y: bY, w: bW, h: bH };
    }
    return null;
}

function scorePdfRectDistance(left, right) {
    if (!left || !right) return Number.POSITIVE_INFINITY;
    return Math.abs(left.x - right.x)
        + Math.abs(left.y - right.y)
        + Math.abs(left.w - right.w)
        + Math.abs(left.h - right.h);
}

function stripTransientAnnotationFields(annotation) {
    if (!annotation || typeof annotation !== 'object') return annotation;
    const cleaned = { ...annotation };
    for (const key of Object.keys(cleaned)) {
        if (key.startsWith('_')) delete cleaned[key];
    }
    delete cleaned.db_id;
    delete cleaned.db_page_number;
    delete cleaned.db_state;
    delete cleaned.db_updated_at;
    delete cleaned.savedDatabaseAnnotation;
    if (boolish(cleaned.pdfjsDeleted)) {
        cleaned.text = '';
        delete cleaned.richTextHtml;
        delete cleaned.sourceTextLines;
    }
    return cleaned;
}

function boolish(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

function cssColorToHex(value, fallback = '#000000') {
    const raw = String(value || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(raw)) return raw.toLowerCase();
    if (/^#[0-9a-f]{3}$/i.test(raw)) {
        const [, r, g, b] = raw;
        return `#${r}${r}${g}${g}${b}${b}`.toLowerCase();
    }
    const match = raw.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
    if (!match) return fallback;
    const clampByte = (component) => Math.max(0, Math.min(255, Math.round(Number(component) || 0)));
    return `#${[clampByte(match[1]), clampByte(match[2]), clampByte(match[3])]
        .map((component) => component.toString(16).padStart(2, '0'))
        .join('')}`;
}

function rgbToHex(r, g, b) {
    const clampByte = (component) => Math.max(0, Math.min(255, Math.round(Number(component) || 0)));
    return `#${[clampByte(r), clampByte(g), clampByte(b)]
        .map((component) => component.toString(16).padStart(2, '0'))
        .join('')}`;
}

function parseCssRgb(value) {
    const raw = String(value || '').trim();
    const hex = raw.match(/^#([0-9a-f]{6})$/i);
    if (hex) {
        const intValue = Number.parseInt(hex[1], 16);
        return {
            r: (intValue >> 16) & 255,
            g: (intValue >> 8) & 255,
            b: intValue & 255,
        };
    }
    const rgb = raw.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
    if (!rgb) return null;
    return { r: Number(rgb[1]) || 0, g: Number(rgb[2]) || 0, b: Number(rgb[3]) || 0 };
}

function isPromotedExtractionAnnotation(annotation) {
    if (!annotation || typeof annotation !== 'object') return false;
    return boolish(annotation.promotedFromExtraction)
        || String(annotation.id || '').startsWith('promoted_');
}

function shouldIncludeInPdfjsVisibleExport(annotation) {
    if (!annotation || typeof annotation !== 'object') return false;
    if (isPromotedExtractionAnnotation(annotation)) return false;
    if (String(annotation.db_state || annotation.state || '').toLowerCase() === 'deleted' && !boolish(annotation.pdfjsDeleted)) return false;
    return ['text', 'image', 'signature'].includes(String(annotation.type || '').toLowerCase());
}

function rebuildPersistedAnnotationPageMap() {
    const byPage = new Map();
    let index = 0;
    for (const annotation of persistedAnnotationsById.values()) {
        if (!annotation) continue;
        const t = String(annotation.type || '').toLowerCase();
        if (t !== 'text' && t !== 'signature') continue;
        const hydrated = t === 'signature' ? annotation : hydrateAnnotationForBoxes(annotation, index++);
        const pageIndex = annotationPageIndex(hydrated);
        if (!byPage.has(pageIndex)) byPage.set(pageIndex, []);
        byPage.get(pageIndex).push(hydrated);
    }
    annotationBoxesByPage = byPage;
}

function replacePersistedAnnotations(records) {
    const next = new Map();
    (Array.isArray(records) ? records : []).forEach((annotation, index) => {
        if (!annotation || typeof annotation !== 'object') return;
        const id = String(annotation.id || annotation.db_id || buildPdfjsAnnotationId(annotationPageIndex(annotation), `loaded_${index}`));
        const hydrated = { ...annotation, id };
        if (isRedundantPdfjsSourceOverlay(hydrated)) return;
        if (canvasRewrittenAnnotationIds.has(id)) {
            hydrated._pdfjsCanvasRewritten = true;
            hydrated._pdfjsCanvasText = String(hydrated.text || '');
        }
        if (sourceRedactedAnnotationIds.has(id)) {
            hydrated._pdfjsSourceRedacted = true;
        }
        next.set(id, hydrated);
    });
    persistedAnnotationsById = next;
    rebuildPersistedAnnotationPageMap();
}

function upsertPersistedAnnotation(annotation) {
    if (!annotation || typeof annotation !== 'object') return;
    const id = String(annotation.id || '');
    if (!id) return;
    const existing = persistedAnnotationsById.get(id) || null;
    persistedAnnotationsById.set(id, { ...(existing || {}), ...annotation, id });
    rebuildPersistedAnnotationPageMap();
}

function deletePersistedAnnotation(annotationId) {
    if (!annotationId) return;
    if (persistedAnnotationsById.delete(String(annotationId))) {
        rebuildPersistedAnnotationPageMap();
    }
}

function markAnnotationCanvasRewritten(annotationId, rewrittenText = '') {
    if (!annotationId) return;
    const annotation = persistedAnnotationsById.get(String(annotationId));
    if (!annotation) return;
    canvasRewrittenAnnotationIds.add(String(annotationId));
    annotation._pdfjsCanvasRewritten = true;
    annotation._pdfjsCanvasText = String(rewrittenText || annotation.text || '');
    rebuildPersistedAnnotationPageMap();
}

function markAnnotationSourceRedacted(annotationId) {
    if (!annotationId) return;
    sourceRedactedAnnotationIds.add(String(annotationId));
    const annotation = persistedAnnotationsById.get(String(annotationId));
    if (!annotation) return;
    annotation._pdfjsSourceRedacted = true;
    rebuildPersistedAnnotationPageMap();
}

function cloneForHistory(value) {
    try {
        return JSON.parse(JSON.stringify(value));
    } catch (_) {
        return value;
    }
}

function captureHistorySnapshot() {
    return {
        annotations: Array.from(persistedAnnotationsById.values()).map((annotation) => cloneForHistory(annotation)),
        deletedAnnotationIds: Array.from(pendingDeletedAnnotationIds),
        offsets: Array.from(annotationOffsetsPts.entries()).map(([uid, offset]) => [uid, { ...offset }]),
    };
}

function updateHistoryButtons() {
    if (undoButton) undoButton.disabled = undoStack.length === 0;
    if (redoButton) redoButton.disabled = redoStack.length === 0;
}

function pushHistorySnapshot(_label = '') {
    if (applyingHistory) return;
    undoStack.push(captureHistorySnapshot());
    if (undoStack.length > MAX_HISTORY_DEPTH) undoStack.shift();
    redoStack.length = 0;
    updateHistoryButtons();
}

function restoreHistorySnapshot(snapshot) {
    applyingHistory = true;
    try {
        closeEditor();
        if (annMenu) annMenu.hidden = true;
        hideAnnotationFormatBar();
        selectedAnnBoxUid = null;
        selectedAnnBoxIsEditing = false;
        document.querySelectorAll('.enpv-annotation-box.is-selected').forEach((box) => box.classList.remove('is-selected'));
        replacePersistedAnnotations(cloneForHistory(snapshot?.annotations || []));
        pendingDeletedAnnotationIds.clear();
        (snapshot?.deletedAnnotationIds || []).forEach((id) => pendingDeletedAnnotationIds.add(String(id)));
        annotationOffsetsPts.clear();
        (snapshot?.offsets || []).forEach(([uid, offset]) => {
            if (!uid || !offset) return;
            annotationOffsetsPts.set(String(uid), {
                dx: Number(offset.dx) || 0,
                dy: Number(offset.dy) || 0,
            });
        });
        renderAllAnnotationBoxLayers();
        markManualSaveNeeded();
    } finally {
        applyingHistory = false;
        updateHistoryButtons();
    }
}

function undoHistory() {
    if (!undoStack.length) return;
    redoStack.push(captureHistorySnapshot());
    restoreHistorySnapshot(undoStack.pop());
}

function redoHistory() {
    if (!redoStack.length) return;
    undoStack.push(captureHistorySnapshot());
    restoreHistorySnapshot(redoStack.pop());
}

function shouldPersistPdfjsAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return true;
    if (boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return true;
    const baseline = annotationBaselinePdfBox(annotation);
    const current = annotationCurrentPdfBox(annotation);
    const sameText = normalizeComparableText(annotation.text) === normalizeComparableText(
        annotation.pdfjsSourceText || annotation.originalText || ''
    );
    const sameGeometry = baseline && current
        ? approxEqual(current.x, baseline.x)
            && approxEqual(current.y, baseline.y)
            && approxEqual(current.w, baseline.w)
            && approxEqual(current.h, baseline.h)
        : false;
    return !(sameText && sameGeometry);
}

function isRedundantPdfjsSourceOverlay(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (!boolish(annotation.savedTextOverlay) || boolish(annotation.pdfjsDeleted)) return false;
    if (boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return false;
    const sameText = normalizeComparableText(annotation.text) === normalizeComparableText(
        annotation.pdfjsSourceText || annotation.originalText || ''
    );
    if (!sameText) return false;
    const baseline = annotationBaselinePdfBox(annotation);
    const current = annotationCurrentPdfBox(annotation);
    if (!baseline || !current) return !shouldPersistPdfjsAnnotation(annotation);
    return approxEqual(current.x, baseline.x, 3.0)
        && approxEqual(current.y, baseline.y, 3.0)
        && approxEqual(current.w, baseline.w, 3.0)
        && approxEqual(current.h, baseline.h, 3.0);
}

function pdfjsSourceBoxesMatch(left, right) {
    const leftBox = annotationBaselinePdfBox(left);
    const rightBox = annotationBaselinePdfBox(right);
    if (!leftBox || !rightBox) return false;
    if (scorePdfRectDistance(leftBox, rightBox) <= 6) return true;
    const overlap = pdfRectOverlapArea(leftBox, rightBox);
    const minArea = Math.max(0.0001, Math.min(pdfRectArea(leftBox), pdfRectArea(rightBox)));
    return (overlap / minArea) >= 0.72;
}

function deletedPdfjsMaskIsOwnedByReplacement(deletedAnnotation, replacementAnnotations) {
    if (!boolish(deletedAnnotation?.pdfjsDeleted)) return false;
    const deletedText = normalizeComparableText(
        deletedAnnotation.pdfjsSourceText
        || deletedAnnotation.originalText
        || '',
    );
    return replacementAnnotations.some((replacement) => {
        if (!replacement || boolish(replacement.pdfjsDeleted)) return false;
        if (annotationPageIndex(replacement) !== annotationPageIndex(deletedAnnotation)) return false;
        if (isRedundantPdfjsSourceOverlay(replacement)) return false;
        const replacementText = normalizeComparableText(
            replacement.pdfjsSourceText
            || replacement.originalText
            || '',
        );
        if (deletedText && replacementText && deletedText !== replacementText) return false;
        return pdfjsSourceBoxesMatch(deletedAnnotation, replacement);
    });
}

function cleanPromotedSourceText(annotation) {
    if (!isPromotedExtractionAnnotation(annotation)) return '';
    if (boolish(annotation.promotedDirty) || boolish(annotation.userAuthored) || boolish(annotation.promotedReflowEnabled)) return '';
    const rawText = String(annotation.text || '');
    if (/\r|\n/.test(rawText)) return '';
    const text = normalizeComparableText(rawText);
    if (!text) return '';
    if (text.length > 12) return '';
    const sourceBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || annotation._originalBox;
    if (!sourceBox || sourceBox.w > 40 || sourceBox.h > 18) return '';
    const original = normalizeComparableText(annotation.originalText || text);
    if (original && original !== text) return '';
    return text;
}

function promotedFallbackIsCoveredByPdfjsText(annotation, textLayerEl, viewport, scale) {
    const text = cleanPromotedSourceText(annotation);
    if (!text || !textLayerEl || !viewport || !scale) return false;
    const sourceBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || annotation._originalBox;
    const sourceRect = pdfRectToCanvasRect(sourceBox, viewport, scale);
    if (!sourceRect || sourceRect.width <= 0 || sourceRect.height <= 0) return false;
    const layerRect = textLayerEl.getBoundingClientRect();
    const targetCenterX = sourceRect.left + (sourceRect.width / 2);
    const targetCenterY = sourceRect.top + (sourceRect.height / 2);
    const maxCenterDx = Math.max(12, sourceRect.width * 1.6);
    const maxCenterDy = Math.max(12, sourceRect.height * 1.6);
    const spans = Array.from(textLayerEl.querySelectorAll('span'));
    return spans.some((span) => {
        const spanText = normalizeComparableText(span.dataset.enpvOriginal || span.textContent || '');
        if (spanText !== text) return false;
        const spanRect = span.getBoundingClientRect();
        const spanBox = {
            left: spanRect.left - layerRect.left,
            top: spanRect.top - layerRect.top,
            width: spanRect.width,
            height: spanRect.height,
        };
        if (rectsOverlap(sourceRect, spanBox, 0.25)) return true;
        const spanCenterX = spanBox.left + (spanBox.width / 2);
        const spanCenterY = spanBox.top + (spanBox.height / 2);
        return Math.abs(spanCenterX - targetCenterX) <= maxCenterDx
            && Math.abs(spanCenterY - targetCenterY) <= maxCenterDy;
    });
}

function promotedFallbackIsCoveredByVisibleOverlay(annotation, overlays) {
    const text = cleanPromotedSourceText(annotation);
    if (!text || !Array.isArray(overlays)) return false;
    const sourceBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || annotation._originalBox;
    if (!sourceBox) return false;
    return overlays.some((overlay) => {
        if (!overlay || isPromotedExtractionAnnotation(overlay)) return false;
        const overlayText = normalizeComparableText(overlay.pdfjsSourceText || overlay.originalText || overlay.text || '');
        if (overlayText && overlayText !== text) return false;
        const overlaySource = annotationBaselinePdfBox(overlay) || annotationCurrentPdfBox(overlay) || overlay._originalPdfBox || overlay._originalBox;
        return Number.isFinite(scorePdfRectDistance(sourceBox, overlaySource))
            && scorePdfRectDistance(sourceBox, overlaySource) <= 8;
    });
}

function pdfRectArea(rect) {
    if (!rect || !(rect.w > 0) || !(rect.h > 0)) return 0;
    return rect.w * rect.h;
}

function pdfRectOverlapArea(left, right) {
    if (!left || !right) return 0;
    const x0 = Math.max(left.x, right.x);
    const y0 = Math.max(left.y, right.y);
    const x1 = Math.min(left.x + left.w, right.x + right.w);
    const y1 = Math.min(left.y + left.h, right.y + right.h);
    return Math.max(0, x1 - x0) * Math.max(0, y1 - y0);
}

function pdfRectCenterDistance(left, right) {
    if (!left || !right) return Number.POSITIVE_INFINITY;
    const leftCx = left.x + (left.w / 2);
    const leftCy = left.y + (left.h / 2);
    const rightCx = right.x + (right.w / 2);
    const rightCy = right.y + (right.h / 2);
    return Math.hypot(leftCx - rightCx, leftCy - rightCy);
}

function sourceOwnerText(annotation) {
    return normalizeComparableText(
        annotation?.pdfjsSourceText
        || annotation?.originalText
        || annotation?.text
        || ''
    );
}

function promotedSourcePdfBox(annotation) {
    return annotationCurrentPdfBox(annotation)
        || annotationBaselinePdfBox(annotation)
        || annotation?._originalPdfBox
        || annotation?._originalBox
        || null;
}

function sourceOwnerPdfBox(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return null;
    return annotationBaselinePdfBox(annotation)
        || annotation?._originalPdfBox
        || annotation?._originalBox
        || annotationCurrentPdfBox(annotation)
        || null;
}

function sourceOwnerForAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return null;
    if (isPromotedExtractionAnnotation(annotation)) return null;
    const rect = sourceOwnerPdfBox(annotation);
    if (!rect) return null;
    const text = sourceOwnerText(annotation);
    const isDeleted = boolish(annotation.pdfjsDeleted);
    const isSavedPdfjs = boolish(annotation.savedTextOverlay)
        && (annotation.pdfjsSourceX != null || annotation.pdfjsSourceText != null || annotation.pdfjsAnchorUid != null);
    const isCanvasBacked = annotation._pdfjsCanvasRewritten === true || annotation._pdfjsSourceRedacted === true;
    const isSourceTiedOverlay = !isSavedPdfjs && Boolean(text)
        && (annotation.pdfjsSourceX != null || annotation.sourceBlockLeft != null || annotation.sourceSpans != null);

    if (isDeleted) return { annotation, rect, text, priority: 100, kind: 'deleted-pdfjs-source' };
    if (isSavedPdfjs) return { annotation, rect, text, priority: isCanvasBacked ? 95 : 90, kind: 'saved-pdfjs-source' };
    if (isCanvasBacked) return { annotation, rect, text, priority: 85, kind: 'canvas-backed-source' };
    if (isSourceTiedOverlay) return { annotation, rect, text, priority: 70, kind: 'saved-source-overlay' };
    return null;
}

function sourceOwnerMatches(owner, targetRect, targetText) {
    if (!owner || !targetRect) return false;
    const ownerRect = owner.rect;
    const overlap = pdfRectOverlapArea(ownerRect, targetRect);
    const ownerRatio = overlap / Math.max(0.0001, pdfRectArea(ownerRect));
    const targetRatio = overlap / Math.max(0.0001, pdfRectArea(targetRect));
    const centerDistance = pdfRectCenterDistance(ownerRect, targetRect);
    const size = Math.max(ownerRect.w, ownerRect.h, targetRect.w, targetRect.h, 1);
    const closeCenters = centerDistance <= Math.max(4, size * 0.65);
    const textMatches = !owner.text || !targetText || owner.text === targetText;
    const strongGeometry = ownerRatio >= 0.55 || targetRatio >= 0.55 || centerDistance <= Math.max(2, size * 0.25);
    const usableGeometry = ownerRatio >= 0.25 || targetRatio >= 0.25 || closeCenters;
    return textMatches ? usableGeometry : strongGeometry;
}

function buildSourceOwnershipRegistry(annotations) {
    const owners = (Array.isArray(annotations) ? annotations : [])
        .map((annotation) => sourceOwnerForAnnotation(annotation))
        .filter(Boolean)
        .sort((left, right) => right.priority - left.priority);
    return {
        owners,
        ownerFor(annotation) {
            const targetText = cleanPromotedSourceText(annotation) || sourceOwnerText(annotation);
            const targetRect = promotedSourcePdfBox(annotation);
            if (!targetText || !targetRect) return null;
            return owners.find((owner) => sourceOwnerMatches(owner, targetRect, targetText)) || null;
        },
        isOwned(annotation) {
            return Boolean(this.ownerFor(annotation));
        },
    };
}

function promotedFallbackIsCoveredByLayerBox(annotation, layer, viewport, scale) {
    const text = cleanPromotedSourceText(annotation);
    if (!text || !layer || !viewport || !scale) return false;
    const sourceBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || annotation._originalBox;
    const sourceRect = pdfRectToCanvasRect(sourceBox, viewport, scale);
    if (!sourceRect || sourceRect.width <= 0 || sourceRect.height <= 0) return false;
    return Array.from(layer.querySelectorAll('.enpv-annotation-box')).some((box) => {
        if (String(box.dataset.annotationId || '') === String(annotation.id || '')) return true;
        const boxText = normalizeComparableText(box.querySelector('.enpv-text-content')?.textContent || box.textContent || '');
        if (boxText !== text) return false;
        const left = Number.parseFloat(box.style.left || '');
        const top = Number.parseFloat(box.style.top || '');
        const width = Number.parseFloat(box.style.width || '') || box.offsetWidth || 0;
        const height = Number.parseFloat(box.style.height || '') || box.offsetHeight || 0;
        if (![left, top, width, height].every(Number.isFinite) || width <= 0 || height <= 0) return false;
        return rectsOverlap(sourceRect, { left, top, width, height }, 0.25);
    });
}

function appendPromotedFallbackOverlays(layer, annotations, pageIndex, viewport, scale, editModeOn) {
    if (!layer || !Array.isArray(annotations) || annotations.length === 0) return;
    for (const annotation of annotations) {
        if (!cleanPromotedSourceText(annotation)) continue;
        if (promotedFallbackIsCoveredByLayerBox(annotation, layer, viewport, scale)) continue;
        const rendered = createPersistedOverlayBox(annotation, pageIndex, viewport, scale, editModeOn);
        if (!rendered) continue;
        if (rendered.mask) layer.appendChild(rendered.mask);
        layer.appendChild(rendered.box);
    }
}

function buildAnnotationFromSpan(spanEl, nextText, existingAnnotation = null) {
    if (!spanEl) return null;
    const pageIndex = Number.parseInt(spanEl.dataset.enpvPage || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!viewport || !layerEl) return null;

    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    const layerRect = layerEl.getBoundingClientRect();
    const spanRect = spanEl.getBoundingClientRect();
    const left = spanRect.left - layerRect.left;
    const top = spanRect.top - layerRect.top;
    const width = spanRect.width;
    const height = spanRect.height;
    const pdfRect = {
        x: left / scale,
        y: (Number(viewport.height) - (top + height)) / scale,
        w: width / scale,
        h: height / scale,
    };
    const cs = window.getComputedStyle(spanEl);
    const fontSizePx = Number.parseFloat(cs.fontSize || '');
    const lineHeightPx = Number.parseFloat(cs.lineHeight || '');
    const annotationId = String(existingAnnotation?.id || spanEl.dataset.enpvPersistentId || buildPdfjsAnnotationId(pageIndex, spanEl.dataset.enpvIndex || spanEl.dataset.enpvOccurrence || '0'));
    const sourceText = String(existingAnnotation?.pdfjsSourceText || existingAnnotation?.originalText || spanEl.dataset.enpvOriginal || spanEl.textContent || '');

    const sourceTextColor = existingAnnotation?.pdfjsSourceTextColor || '';
    const preferSourceColor = sourceTextColor
        && !boolish(existingAnnotation?.styleDirty)
        && !boolish(existingAnnotation?.userForcedRichText)
        && String(existingAnnotation?.pdfjsEditorMode || '') !== 'rich';
    const textColor = cssColorToHex(
        (preferSourceColor && sourceTextColor)
        || existingAnnotation?.textColor
        || existingAnnotation?.color
        || cs.color
        || '#000000',
    );
    const annotation = {
        ...(existingAnnotation || {}),
        id: annotationId,
        type: 'text',
        pageIndex,
        text: String(nextText ?? spanEl.textContent ?? ''),
        originalText: String(existingAnnotation?.originalText || sourceText),
        pdfX: pdfRect.x,
        pdfY: pdfRect.y,
        pdfWidth: pdfRect.w,
        pdfHeight: pdfRect.h,
        fontSize: Number.isFinite(fontSizePx) && scale > 0 ? (fontSizePx / scale) : (Number(existingAnnotation?.fontSize) || 12),
        requestedFontSize: Number.isFinite(fontSizePx) && scale > 0 ? (fontSizePx / scale) : (Number(existingAnnotation?.requestedFontSize) || undefined),
        lineHeight: Number.isFinite(lineHeightPx) && scale > 0 ? (lineHeightPx / scale) : (Number(existingAnnotation?.lineHeight) || undefined),
        fontFamily: String(existingAnnotation?.fontFamily || cs.fontFamily || ''),
        fontWeight: String(existingAnnotation?.fontWeight || cs.fontWeight || 'normal'),
        fontStyle: String(existingAnnotation?.fontStyle || cs.fontStyle || 'normal'),
        opacity: Number(existingAnnotation?.opacity ?? 1) || 1,
        textColor,
        color: textColor,
        savedTextOverlay: true,
        pdfjsSourceX: Number(existingAnnotation?.pdfjsSourceX ?? pdfRect.x),
        pdfjsSourceY: Number(existingAnnotation?.pdfjsSourceY ?? pdfRect.y),
        pdfjsSourceW: Number(existingAnnotation?.pdfjsSourceW ?? pdfRect.w),
        pdfjsSourceH: Number(existingAnnotation?.pdfjsSourceH ?? pdfRect.h),
        pdfjsSourcePageHeight: Number(existingAnnotation?.pdfjsSourcePageHeight ?? (Number(viewport.height) / scale)),
        pdfjsSourceText: sourceText,
        pdfjsAnchorUid: String(existingAnnotation?.pdfjsAnchorUid || `${pageIndex}:${spanEl.dataset.enpvIndex || spanEl.dataset.enpvOccurrence || '0'}`),
        pdfjsSourceOccurrence: String(existingAnnotation?.pdfjsSourceOccurrence || spanEl.dataset.enpvOccurrence || '0'),
    };

    return shouldPersistPdfjsAnnotation(annotation) ? annotation : null;
}

function buildAnnotationFromBox(box, existingAnnotation = null) {
    if (!box) return null;
    const layer = box.parentElement;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    if (!layer || !viewport) return null;

    const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
    const dxPts = Number.parseFloat(box.dataset.dxPts || '0') || 0;
    const dyPts = Number.parseFloat(box.dataset.dyPts || '0') || 0;
    const leftPx = Number.parseFloat(box.style.left || '0') || 0;
    const topPx = Number.parseFloat(box.style.top || '0') || 0;
    const widthPx = Number.parseFloat(box.style.width || '0') || box.offsetWidth || 0;
    const heightPx = Number.parseFloat(box.style.height || '0') || box.offsetHeight || 0;
    const pdfRect = {
        x: (leftPx / scale) + dxPts,
        y: (Number(viewport.height) - (topPx + heightPx)) / scale - dyPts,
        w: widthPx / scale,
        h: heightPx / scale,
    };

    // Signature annotations carry an image payload — only update geometry.
    if (box.dataset.annotationType === 'signature' || isSignatureAnnotation(existingAnnotation)) {
        if (!existingAnnotation) return null;
        return {
            ...existingAnnotation,
            pageIndex,
            pdfX: pdfRect.x,
            pdfY: pdfRect.y,
            pdfWidth: pdfRect.w,
            pdfHeight: pdfRect.h,
            locked: box.dataset.locked != null ? box.dataset.locked === '1' : Boolean(existingAnnotation.locked),
            zIndex: Number.parseInt(box.style.zIndex || box.dataset.zIndex || existingAnnotation.zIndex || '6', 10) || 6,
        };
    }
    const baseRect = {
        x: Number.parseFloat(box.dataset.baseBboxX || '') || Number.parseFloat(box.dataset.sourceBboxX || '0') || 0,
        y: Number.parseFloat(box.dataset.baseBboxY || '') || Number.parseFloat(box.dataset.sourceBboxY || '0') || 0,
        w: Number.parseFloat(box.dataset.baseBboxW || '') || Number.parseFloat(box.dataset.sourceBboxW || '0') || 0,
        h: Number.parseFloat(box.dataset.baseBboxH || '') || Number.parseFloat(box.dataset.sourceBboxH || '0') || 0,
    };
    const tc = box.querySelector('.enpv-text-content');
    const cs = tc ? window.getComputedStyle(tc) : window.getComputedStyle(box);
    const fontSizePts = Number.parseFloat(box.dataset.fontSizePts || '') || (Number.parseFloat(cs.fontSize || '') / scale) || Number(existingAnnotation?.fontSize) || 12;
    const lineHeightPx = Number.parseFloat(cs.lineHeight || '');
    const annotationId = String(existingAnnotation?.id || box.dataset.annotationId || buildPdfjsAnnotationId(pageIndex, box.dataset.uid || '0'));
    const sourceText = String(existingAnnotation?.pdfjsSourceText || box.dataset.baseText || box.dataset.originalText || '');
    const sourceTextColor = box.dataset.sourceTextColor || existingAnnotation?.pdfjsSourceTextColor || '';
    const preferSourceColor = sourceTextColor
        && box.dataset.styleDirty !== '1'
        && !boolish(existingAnnotation?.styleDirty)
        && box.dataset.userForcedRichText !== '1'
        && !boolish(existingAnnotation?.userForcedRichText)
        && String(box.dataset.editorMode || existingAnnotation?.pdfjsEditorMode || '') !== 'rich';
    const textColor = cssColorToHex(
        (preferSourceColor && sourceTextColor)
        || existingAnnotation?.textColor
        || existingAnnotation?.color
        || cs.color
        || '#000000',
    );
    const annotation = {
        ...(existingAnnotation || {}),
        id: annotationId,
        type: 'text',
        pageIndex,
        text: String(tc?.innerText || tc?.textContent || ''),
        originalText: String(existingAnnotation?.originalText || box.dataset.originalText || sourceText),
        pdfX: pdfRect.x,
        pdfY: pdfRect.y,
        pdfWidth: pdfRect.w,
        pdfHeight: pdfRect.h,
        fontSize: fontSizePts,
        requestedFontSize: Number(existingAnnotation?.requestedFontSize) || fontSizePts,
        lineHeight: Number.isFinite(lineHeightPx) && scale > 0 ? (lineHeightPx / scale) : (Number(existingAnnotation?.lineHeight) || undefined),
        fontFamily: String(box.style.getPropertyValue('--enpv-font-family') || existingAnnotation?.fontFamily || cs.fontFamily || ''),
        fontWeight: String(existingAnnotation?.fontWeight || box.style.getPropertyValue('--enpv-font-weight') || cs.fontWeight || 'normal'),
        fontStyle: String(existingAnnotation?.fontStyle || box.style.getPropertyValue('--enpv-font-style') || cs.fontStyle || 'normal'),
        locked: box.dataset.locked != null ? box.dataset.locked === '1' : Boolean(existingAnnotation?.locked),
        zIndex: Number.parseInt(box.style.zIndex || box.dataset.zIndex || existingAnnotation?.zIndex || '2', 10) || 2,
        opacity: Number(existingAnnotation?.opacity ?? 1) || 1,
        textColor,
        color: textColor,
        savedTextOverlay: true,
        pdfjsSourceX: Number(existingAnnotation?.pdfjsSourceX ?? baseRect.x),
        pdfjsSourceY: Number(existingAnnotation?.pdfjsSourceY ?? baseRect.y),
        pdfjsSourceW: Number(existingAnnotation?.pdfjsSourceW ?? baseRect.w),
        pdfjsSourceH: Number(existingAnnotation?.pdfjsSourceH ?? baseRect.h),
        pdfjsSourcePageHeight: Number(existingAnnotation?.pdfjsSourcePageHeight ?? box.dataset.basePageHeight ?? (Number(viewport.height) / scale)),
        pdfjsSourceText: sourceText,
        pdfjsAnchorUid: String(existingAnnotation?.pdfjsAnchorUid || box.dataset.uid || ''),
        pdfjsSourceOccurrence: String(existingAnnotation?.pdfjsSourceOccurrence || box.dataset.occurrence || '0'),
        pdfjsEditorMode: box.dataset.editorMode || editorModeForBox(box),
        userForcedRichText: box.dataset.userForcedRichText === '1' || existingAnnotation?.userForcedRichText === true,
        styleDirty: box.dataset.styleDirty === '1' || boolish(existingAnnotation?.styleDirty),
        movedTextOverlay: (Math.abs(dxPts) > 0.01 || Math.abs(dyPts) > 0.01) || existingAnnotation?.movedTextOverlay === true,
        richTextPromotionReason: box.dataset.richTextPromotionReason || existingAnnotation?.richTextPromotionReason || undefined,
    };
    copySourceSpanMetricsToAnnotation(annotation, box);

    return shouldPersistPdfjsAnnotation(annotation) ? annotation : null;
}

function findPersistedAnnotationForSpan(pageIndex, currentRect, text, originalText, consume = false, sourceUid = '') {
    const candidates = annotationBoxesByPage.get(pageIndex) || [];
    const targetText = normalizeComparableText(text);
    const targetOriginal = normalizeComparableText(originalText);
    const targetUid = String(sourceUid || '').trim();
    let best = null;
    let bestScore = Number.POSITIVE_INFINITY;

    for (const annotation of candidates) {
        if (!annotation || (consume && annotation._renderMatched)) continue;
        if (isPromotedExtractionAnnotation(annotation)) continue;
        if (annotation.pdfjsDeleted === true) continue;
        const anchorUid = String(annotation.pdfjsAnchorUid || '').trim();
        if (targetUid && anchorUid && anchorUid !== targetUid) continue;
        const currentScore = scorePdfRectDistance(currentRect, annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || null);
        const baselineScore = scorePdfRectDistance(currentRect, annotationBaselinePdfBox(annotation) || annotation._originalBox || null);
        const uidMatches = targetUid && anchorUid && anchorUid === targetUid;
        const textMatches = [annotation.text, annotation.originalText, annotation.pdfjsSourceText]
            .map((value) => normalizeComparableText(value))
            .some((value) => value && (value === targetText || value === targetOriginal));
        const score = Math.min(currentScore, baselineScore) + ((uidMatches || textMatches) ? 0 : 48);
        if (score < bestScore) {
            best = annotation;
            bestScore = score;
        }
    }

    if (!best) return null;
    if (bestScore > 96) return null;
    if (consume) best._renderMatched = true;
    return best;
}

function pdfRectToCanvasRect(pdfRect, viewport, scale) {
    if (!pdfRect || !viewport || !scale) return null;
    return {
        left: pdfRect.x * scale,
        top: Number(viewport.height) - ((pdfRect.y + pdfRect.h) * scale),
        width: pdfRect.w * scale,
        height: pdfRect.h * scale,
    };
}

function samplePageBackgroundColor(pageDiv, rect, fallback = '#fff') {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return fallback;
    const canvas = pageDiv.querySelector(':scope canvas');
    if (!canvas) return fallback;
    let ctx = null;
    try {
        ctx = canvas.getContext('2d', { willReadFrequently: true });
    } catch (_) {
        return fallback;
    }
    if (!ctx) return fallback;

    const pageBounds = pageDiv.getBoundingClientRect();
    const canvasBounds = canvas.getBoundingClientRect();
    if (!canvasBounds.width || !canvasBounds.height || !canvas.width || !canvas.height) return fallback;

    const scaleX = canvas.width / canvasBounds.width;
    const scaleY = canvas.height / canvasBounds.height;
    const canvasLeft = rect.left - (canvasBounds.left - pageBounds.left);
    const canvasTop = rect.top - (canvasBounds.top - pageBounds.top);
    const sx = Math.max(0, Math.floor(canvasLeft * scaleX));
    const sy = Math.max(0, Math.floor(canvasTop * scaleY));
    const sw = Math.max(1, Math.min(canvas.width - sx, Math.ceil(rect.width * scaleX)));
    const sh = Math.max(1, Math.min(canvas.height - sy, Math.ceil(rect.height * scaleY)));
    if (sw <= 0 || sh <= 0) return fallback;

    let data;
    try {
        data = ctx.getImageData(sx, sy, sw, sh).data;
    } catch (_) {
        return fallback;
    }

    const buckets = new Map();
    const step = Math.max(1, Math.floor(Math.sqrt((sw * sh) / 5000)));
    for (let y = 0; y < sh; y += step) {
        for (let x = 0; x < sw; x += step) {
            const offset = ((y * sw) + x) * 4;
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
            if (luminance < 155) continue;
            const qr = Math.min(255, Math.round(r / 8) * 8);
            const qg = Math.min(255, Math.round(g / 8) * 8);
            const qb = Math.min(255, Math.round(b / 8) * 8);
            const key = `${qr},${qg},${qb}`;
            buckets.set(key, (buckets.get(key) || 0) + 1);
        }
    }
    if (!buckets.size) return fallback;
    let bestKey = null;
    let bestCount = -1;
    for (const [key, count] of buckets.entries()) {
        if (count > bestCount) {
            bestKey = key;
            bestCount = count;
        }
    }
    return bestKey ? `rgb(${bestKey})` : fallback;
}

function samplePageForegroundColor(pageDiv, rect, fallback = '#000000') {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return fallback || '';
    const canvas = pageDiv.querySelector(':scope canvas');
    if (!canvas) return fallback || '';
    let ctx = null;
    try {
        ctx = canvas.getContext('2d', { willReadFrequently: true });
    } catch (_) {
        return fallback || '';
    }
    if (!ctx) return fallback || '';

    const pageBounds = pageDiv.getBoundingClientRect();
    const canvasBounds = canvas.getBoundingClientRect();
    if (!canvasBounds.width || !canvasBounds.height || !canvas.width || !canvas.height) return fallback || '';

    const scaleX = canvas.width / canvasBounds.width;
    const scaleY = canvas.height / canvasBounds.height;
    const canvasLeft = rect.left - (canvasBounds.left - pageBounds.left);
    const canvasTop = rect.top - (canvasBounds.top - pageBounds.top);
    const sx = Math.max(0, Math.floor(canvasLeft * scaleX));
    const sy = Math.max(0, Math.floor(canvasTop * scaleY));
    const sw = Math.max(1, Math.min(canvas.width - sx, Math.ceil(rect.width * scaleX)));
    const sh = Math.max(1, Math.min(canvas.height - sy, Math.ceil(rect.height * scaleY)));
    if (sw <= 0 || sh <= 0) return fallback || '';

    let data;
    try {
        data = ctx.getImageData(sx, sy, sw, sh).data;
    } catch (_) {
        return fallback || '';
    }

    const background = parseCssRgb(samplePageBackgroundColor(pageDiv, rect, '#ffffff')) || { r: 255, g: 255, b: 255 };
    const buckets = new Map();
    const step = Math.max(1, Math.floor(Math.sqrt((sw * sh) / 6000)));
    for (let y = 0; y < sh; y += step) {
        for (let x = 0; x < sw; x += step) {
            const offset = ((y * sw) + x) * 4;
            const alpha = data[offset + 3];
            if (alpha < 24) continue;
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            const bgDistance = Math.hypot(r - background.r, g - background.g, b - background.b);
            const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
            const saturation = Math.max(r, g, b) - Math.min(r, g, b);
            if (bgDistance < 38 && luminance > 185 && saturation < 45) continue;
            if (bgDistance < 22) continue;
            const key = [
                Math.min(255, Math.round(r / 8) * 8),
                Math.min(255, Math.round(g / 8) * 8),
                Math.min(255, Math.round(b / 8) * 8),
            ].join(',');
            buckets.set(key, (buckets.get(key) || 0) + Math.max(1, bgDistance / 24));
        }
    }
    if (!buckets.size) return fallback || '';
    let bestKey = null;
    let bestScore = -1;
    for (const [key, score] of buckets.entries()) {
        if (score > bestScore) {
            bestKey = key;
            bestScore = score;
        }
    }
    if (!bestKey) return fallback || '';
    const [r, g, b] = bestKey.split(',').map((part) => Number.parseInt(part, 10) || 0);
    return rgbToHex(r, g, b);
}

function detectForegroundCanvasInkBounds(pageDiv, rect) {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return null;
    const canvas = pageDiv.querySelector(':scope canvas');
    if (!canvas) return null;
    let ctx = null;
    try {
        ctx = canvas.getContext('2d', { willReadFrequently: true });
    } catch (_) {
        return null;
    }
    if (!ctx) return null;

    const pageBounds = pageDiv.getBoundingClientRect();
    const canvasBounds = canvas.getBoundingClientRect();
    if (!canvasBounds.width || !canvasBounds.height || !canvas.width || !canvas.height) return null;

    const scaleX = canvas.width / canvasBounds.width;
    const scaleY = canvas.height / canvasBounds.height;
    const canvasLeft = rect.left - (canvasBounds.left - pageBounds.left);
    const canvasTop = rect.top - (canvasBounds.top - pageBounds.top);
    const sx = Math.max(0, Math.floor(canvasLeft * scaleX));
    const sy = Math.max(0, Math.floor(canvasTop * scaleY));
    const sw = Math.max(1, Math.min(canvas.width - sx, Math.ceil(rect.width * scaleX)));
    const sh = Math.max(1, Math.min(canvas.height - sy, Math.ceil(rect.height * scaleY)));
    if (sw <= 0 || sh <= 0) return null;

    let data;
    try {
        data = ctx.getImageData(sx, sy, sw, sh).data;
    } catch (_) {
        return null;
    }

    const background = parseCssRgb(samplePageBackgroundColor(pageDiv, rect, '#ffffff')) || { r: 255, g: 255, b: 255 };
    let minX = sw;
    let minY = sh;
    let maxX = -1;
    let maxY = -1;
    let count = 0;
    for (let y = 0; y < sh; y += 1) {
        const rowOffset = y * sw * 4;
        for (let x = 0; x < sw; x += 1) {
            const offset = rowOffset + (x * 4);
            if (data[offset + 3] < 24) continue;
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            const bgDistance = Math.hypot(r - background.r, g - background.g, b - background.b);
            const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
            const saturation = Math.max(r, g, b) - Math.min(r, g, b);
            if (bgDistance < 38 && luminance > 185 && saturation < 45) continue;
            if (bgDistance < 22) continue;
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
            count += 1;
        }
    }
    if (count <= 0 || maxX < minX || maxY < minY) return null;
    return {
        count,
        width: (maxX - minX + 1) / scaleX,
        height: (maxY - minY + 1) / scaleY,
    };
}

function inflatedCanvasRect(rect, paddingPx = 1) {
    if (!rect) return null;
    const pad = Number(paddingPx) || 0;
    return {
        left: rect.left - pad,
        top: rect.top - pad,
        width: rect.width + (pad * 2),
        height: rect.height + (pad * 2),
    };
}

function sourceSpanCanvasRect(spanEl, pageDiv) {
    if (!spanEl || !pageDiv) return null;
    const spanRect = spanEl.getBoundingClientRect();
    const pageRect = pageDiv.getBoundingClientRect();
    return {
        left: spanRect.left - pageRect.left,
        top: spanRect.top - pageRect.top,
        width: spanRect.width,
        height: spanRect.height,
    };
}

function spanHasVisibleCanvasInk(spanEl, pageDiv) {
    if (!spanEl || !pageDiv) return true;
    if (!pageDiv.querySelector(':scope canvas')) return true;
    const rect = sourceSpanCanvasRect(spanEl, pageDiv);
    if (!rect || rect.width <= 0 || rect.height <= 0) return false;
    const inflated = inflatedCanvasRect(rect, 0.75);
    const ink = detectForegroundCanvasInkBounds(pageDiv, inflated);
    if (!ink) return false;
    if (ink.height <= 4 && rect.height > 12 && ink.width > Math.max(12, rect.width * 0.4)) return false;
    if (ink.count < 3) return false;
    return true;
}

function sourceBoxDisplayRect(rect) {
    if (!rect) return rect;
    return {
        ...rect,
        width: rect.width + SOURCE_BOX_VISUAL_PADDING_PX,
        height: rect.height + SOURCE_BOX_VISUAL_PADDING_PX,
    };
}

function sourceBboxForSpan(spanEl) {
    if (!spanEl) return null;
    const pageIndex = Number.parseInt(spanEl.dataset.enpvPage || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!viewport || !layerEl) return null;
    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!(scale > 0)) return null;
    const layerRect = layerEl.getBoundingClientRect();
    const spanRect = spanEl.getBoundingClientRect();
    return {
        x: (spanRect.left - layerRect.left) / scale,
        y: (Number(viewport.height) - ((spanRect.top - layerRect.top) + spanRect.height)) / scale,
        w: spanRect.width / scale,
        h: spanRect.height / scale,
    };
}

const SOURCE_MASK_PADDING_PTS = 1.5;
const SOURCE_BOX_VISUAL_PADDING_PX = 2;
const ENABLE_PDFJS_DOM_SOURCE_MASKS = true;

function inflatePdfRect(pdfRect, paddingPts = SOURCE_MASK_PADDING_PTS) {
    if (!pdfRect) return null;
    const pad = Number(paddingPts) || 0;
    return {
        x: pdfRect.x - pad,
        y: pdfRect.y - pad,
        w: pdfRect.w + (pad * 2),
        h: pdfRect.h + (pad * 2),
    };
}

function applyAnnotationTypographyToBox(box, annotation, scale, sourceStyle = null) {
    if (!box) return;
    const fontSizePts = Number(annotation?.fontSize ?? annotation?.requestedFontSize ?? box.dataset.fontSizePts);
    if (Number.isFinite(fontSizePts) && fontSizePts > 0 && scale > 0) {
        box.dataset.fontSizePts = String(fontSizePts);
        box.style.setProperty('--enpv-font-size', `${fontSizePts * scale}px`);
    } else if (sourceStyle?.fontSize) {
        box.style.setProperty('--enpv-font-size', sourceStyle.fontSize);
    }

    const lineHeightPts = Number(annotation?.lineHeight);
    if (Number.isFinite(lineHeightPts) && lineHeightPts > 0 && scale > 0) {
        box.style.setProperty('--enpv-line-height', `${lineHeightPts * scale}px`);
    }

    const fontFamily = String(annotation?.fontFamily || sourceStyle?.fontFamily || 'sans-serif');
    box.style.setProperty('--enpv-font-family', fontFamily);
    box.style.setProperty('--enpv-font-weight', String(annotation?.fontWeight || sourceStyle?.fontWeight || 'normal'));
    box.style.setProperty('--enpv-font-style', String(annotation?.fontStyle || sourceStyle?.fontStyle || 'normal'));
    const preferSourceColor = !boolish(annotation?.styleDirty)
        && !boolish(annotation?.userForcedRichText)
        && String(annotation?.pdfjsEditorMode || '') !== 'rich';
    const color = cssColorToHex(
        (preferSourceColor && annotation?.pdfjsSourceTextColor)
        || annotation?.textColor
        || annotation?.color
        || annotation?.pdfjsSourceTextColor
        || sourceStyle?.textColor
        || sourceStyle?.color
        || '#000000',
    );
    box.style.setProperty('--enpv-text-color', color);
}

function captureSourceSpanMetrics(box, spanEl, spanRect, scale, pageDiv = null) {
    if (!box || !spanEl) return;
    const cs = window.getComputedStyle(spanEl);
    const rect = spanRect || spanEl.getBoundingClientRect();
    const fontSizePx = Math.max(1, rect.height || Number.parseFloat(cs.fontSize || '') || 12);
    const transform = cs.transform && cs.transform !== 'none' ? cs.transform : '';
    let transformScaleX = 1;
    if (transform) {
        try {
            const matrix = new DOMMatrixReadOnly(transform);
            transformScaleX = Math.hypot(matrix.a, matrix.b) || 1;
        } catch (_) {
            transformScaleX = 1;
        }
    }
    box.dataset.sourceFontFamily = cs.fontFamily || '';
    box.dataset.sourceFontWeight = cs.fontWeight || '';
    box.dataset.sourceFontStyle = cs.fontStyle || '';
    box.dataset.sourceLetterSpacing = cs.letterSpacing || 'normal';
    box.dataset.sourceTransform = transform;
    box.dataset.sourceTransformScaleX = String(transformScaleX);
    box.dataset.sourceTextWidthPx = String(rect.width / transformScaleX);
    box.dataset.sourceTransformOrigin = cs.transformOrigin || '0 0';
    box.dataset.sourceFontSizePx = String(fontSizePx);
    box.dataset.sourceLineHeightPx = `${fontSizePx}px`;
    const pageRect = pageDiv?.getBoundingClientRect?.();
    const sourceColor = pageRect
        ? samplePageForegroundColor(pageDiv, inflatedCanvasRect({
            left: rect.left - pageRect.left,
            top: rect.top - pageRect.top,
            width: rect.width,
            height: rect.height,
        }, SOURCE_BOX_VISUAL_PADDING_PX), '')
        : '';
    const textColor = cssColorToHex(sourceColor || cs.color || '#000000');
    box.dataset.sourceTextColor = textColor;
    box.dataset.fontSizePts = String(fontSizePx / (scale || 1));
    box.style.setProperty('--enpv-font-family', box.dataset.sourceFontFamily || 'sans-serif');
    box.style.setProperty('--enpv-font-size', `${fontSizePx}px`);
    box.style.setProperty('--enpv-font-weight', box.dataset.sourceFontWeight || 'normal');
    box.style.setProperty('--enpv-font-style', box.dataset.sourceFontStyle || 'normal');
    box.style.setProperty('--enpv-text-color', textColor);
}

function restoreSourceSpanMetricsFromAnnotation(box, annotation) {
    if (!box || !annotation) return;
    const mappings = [
        ['sourceFontFamily', 'pdfjsSourceFontFamily'],
        ['sourceFontWeight', 'pdfjsSourceFontWeight'],
        ['sourceFontStyle', 'pdfjsSourceFontStyle'],
        ['sourceLetterSpacing', 'pdfjsSourceLetterSpacing'],
        ['sourceTransform', 'pdfjsSourceTransform'],
        ['sourceTransformScaleX', 'pdfjsSourceTransformScaleX'],
        ['sourceTextWidthPx', 'pdfjsSourceTextWidthPx'],
        ['sourceTransformOrigin', 'pdfjsSourceTransformOrigin'],
        ['sourceFontSizePx', 'pdfjsSourceFontSizePx'],
        ['sourceLineHeightPx', 'pdfjsSourceLineHeightPx'],
        ['sourceTextColor', 'pdfjsSourceTextColor'],
    ];
    for (const [datasetKey, annotationKey] of mappings) {
        const value = annotation[annotationKey];
        if (value != null && String(value) !== '') box.dataset[datasetKey] = String(value);
    }
}

function copySourceSpanMetricsToAnnotation(annotation, box) {
    if (!annotation || !box) return annotation;
    if (!box.dataset.sourceFontFamily && !box.dataset.sourceTransform && !box.dataset.sourceFontSizePx) return annotation;
    annotation.pdfjsSourceFidelity = true;
    annotation.pdfjsSourceFontFamily = box.dataset.sourceFontFamily || annotation.pdfjsSourceFontFamily || '';
    annotation.pdfjsSourceFontWeight = box.dataset.sourceFontWeight || annotation.pdfjsSourceFontWeight || '';
    annotation.pdfjsSourceFontStyle = box.dataset.sourceFontStyle || annotation.pdfjsSourceFontStyle || '';
    annotation.pdfjsSourceLetterSpacing = box.dataset.sourceLetterSpacing || annotation.pdfjsSourceLetterSpacing || '';
    annotation.pdfjsSourceTransform = box.dataset.sourceTransform || annotation.pdfjsSourceTransform || '';
    annotation.pdfjsSourceTransformScaleX = box.dataset.sourceTransformScaleX || annotation.pdfjsSourceTransformScaleX || '';
    annotation.pdfjsSourceTextWidthPx = box.dataset.sourceTextWidthPx || annotation.pdfjsSourceTextWidthPx || '';
    annotation.pdfjsSourceTransformOrigin = box.dataset.sourceTransformOrigin || annotation.pdfjsSourceTransformOrigin || '';
    annotation.pdfjsSourceFontSizePx = box.dataset.sourceFontSizePx || annotation.pdfjsSourceFontSizePx || '';
    annotation.pdfjsSourceLineHeightPx = box.dataset.sourceLineHeightPx || annotation.pdfjsSourceLineHeightPx || '';
    annotation.pdfjsSourceTextColor = box.dataset.sourceTextColor || annotation.pdfjsSourceTextColor || '';
    return annotation;
}

function applySourceFidelityTypography(box, options = {}) {
    if (!box) return;
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    box.classList.add('is-source-fidelity');
    const fontFamily = box.dataset.sourceFontFamily || box.style.getPropertyValue('--enpv-font-family') || 'sans-serif';
    const scale = Number.parseFloat(options.scale ?? '')
        || Number.parseFloat(box.parentElement?.dataset?.scale || '')
        || Number.parseFloat(box.dataset.renderScale || '')
        || 1;
    const fontSizePts = Number.parseFloat(box.dataset.fontSizePts || '');
    const originalFontSizePx = Number.parseFloat(box.dataset.sourceFontSizePx || '');
    const fallbackFontSizePx = Number.parseFloat(box.style.getPropertyValue('--enpv-font-size') || '') || box.offsetHeight || 12;
    const fontSizePx = Number.isFinite(fontSizePts) && fontSizePts > 0 && scale > 0
        ? fontSizePts * scale
        : (originalFontSizePx || fallbackFontSizePx);
    const originalLineHeightPx = Number.parseFloat(box.dataset.sourceLineHeightPx || '');
    const sourceRatio = Number.isFinite(originalFontSizePx) && originalFontSizePx > 0
        ? fontSizePx / originalFontSizePx
        : 1;
    const lineHeightPx = Number.isFinite(originalLineHeightPx) && originalLineHeightPx > 0
        ? `${originalLineHeightPx * sourceRatio}px`
        : `${fontSizePx}px`;
    const sourceTextWidthPx = Number.parseFloat(box.dataset.sourceTextWidthPx || '');
    box.style.setProperty('--enpv-font-family', fontFamily);
    box.style.setProperty('--enpv-font-size', `${fontSizePx}px`);
    box.style.setProperty('--enpv-font-weight', box.dataset.sourceFontWeight || box.style.getPropertyValue('--enpv-font-weight') || 'normal');
    box.style.setProperty('--enpv-font-style', box.dataset.sourceFontStyle || box.style.getPropertyValue('--enpv-font-style') || 'normal');
    box.style.setProperty('--enpv-line-height', lineHeightPx);
    const textColor = cssColorToHex(box.dataset.sourceTextColor || box.style.getPropertyValue('--enpv-text-color') || '#000000');
    box.style.setProperty('--enpv-text-color', textColor);
    tc.style.color = textColor;
    tc.style.letterSpacing = box.dataset.sourceLetterSpacing || '';
    tc.style.whiteSpace = 'pre';
    tc.style.wordBreak = 'normal';
    tc.style.overflowWrap = 'normal';
    tc.style.display = 'block';
    tc.style.alignItems = '';
    if (Number.isFinite(sourceTextWidthPx) && sourceTextWidthPx > 0) {
        tc.style.width = `${sourceTextWidthPx * sourceRatio}px`;
    }
    tc.style.lineHeight = lineHeightPx;
    tc.style.transformOrigin = box.dataset.sourceTransformOrigin || '0 0';
    tc.style.transform = box.dataset.sourceTransform || '';
    if (options.editing) {
        box.dataset.sourceFidelityEditing = '1';
        tc.style.height = 'auto';
        tc.style.minHeight = lineHeightPx;
        tc.style.overflow = 'visible';
    }
}

function boxHasSourceTypography(box) {
    return Boolean(box?.dataset?.sourceFontFamily || box?.dataset?.sourceTransform || box?.dataset?.sourceFontSizePx);
}

function boxTextHasNewline(box) {
    const text = selectedBoxTextElement(box)?.innerText || selectedBoxTextElement(box)?.textContent || '';
    return /\r|\n/.test(String(text));
}

function editorModeForBox(box) {
    if (!box) return 'rich';
    if (box.dataset.userForcedRichText === '1') return 'rich';
    if (!boxHasSourceTypography(box)) return 'rich';
    if (boxTextHasNewline(box)) return 'rich';
    return 'source';
}

function resetSourceFidelityInlineStyles(box) {
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    tc.style.letterSpacing = '';
    tc.style.whiteSpace = '';
    tc.style.wordBreak = '';
    tc.style.overflowWrap = '';
    tc.style.display = '';
    tc.style.alignItems = '';
    tc.style.lineHeight = '';
    tc.style.transformOrigin = '';
    tc.style.transform = '';
    tc.style.width = '';
    tc.style.height = '';
    tc.style.minHeight = '';
    tc.style.overflow = '';
}

function setBoxEditorMode(box, mode) {
    if (!box) return;
    const resolved = mode === 'source' ? 'source' : 'rich';
    box.dataset.editorMode = resolved;
    box.classList.toggle('is-source-editor', resolved === 'source');
    box.classList.toggle('is-rich-editor', resolved === 'rich');
    if (resolved === 'source') {
        if (box.classList.contains('is-editing') || box.classList.contains('is-persisted-overlay')) {
            applySourceFidelityTypography(box, { editing: box.classList.contains('is-editing') });
        }
    } else {
        delete box.dataset.sourceFidelityEditing;
        box.classList.remove('is-source-fidelity');
        resetSourceFidelityInlineStyles(box);
    }
}

function promoteBoxToRichTextMode(box, reason = '') {
    if (!box) return;
    if (box.dataset.editorMode === 'rich' && box.dataset.userForcedRichText === '1') return;
    box.dataset.userForcedRichText = '1';
    if (reason) box.dataset.richTextPromotionReason = reason;
    setBoxEditorMode(box, 'rich');
}

function richTextContentHeightPx(box) {
    const tc = selectedBoxTextElement(box);
    if (!tc) return 0;
    const cs = window.getComputedStyle(tc);
    const lineHeight = Number.parseFloat(cs.lineHeight || '') || Number.parseFloat(cs.fontSize || '') || 0;
    const paddingY = (Number.parseFloat(cs.paddingTop || '') || 0) + (Number.parseFloat(cs.paddingBottom || '') || 0);
    let rangeHeight = 0;
    try {
        const range = document.createRange();
        range.selectNodeContents(tc);
        const rects = Array.from(range.getClientRects()).filter((rect) => rect.width > 0 || rect.height > 0);
        if (rects.length) {
            const top = Math.min(...rects.map((rect) => rect.top));
            const bottom = Math.max(...rects.map((rect) => rect.bottom));
            rangeHeight = Math.max(0, bottom - top);
        }
        range.detach?.();
    } catch (_) { /* noop */ }
    const clientHeight = tc.clientHeight || 0;
    const scrollHeight = tc.scrollHeight || 0;
    const overflowHeight = scrollHeight > clientHeight + 1 ? scrollHeight : 0;
    return Math.ceil(Math.max(rangeHeight + paddingY, overflowHeight, lineHeight));
}

function fitRichTextBoxToContent(box, anchor = 'top') {
    if (!box || editorModeForBox(box) !== 'rich') return false;
    const contentHeight = richTextContentHeightPx(box);
    if (!(contentHeight > 0)) return false;
    const targetHeight = Math.max(12, contentHeight + 2);
    const currentHeight = Number.parseFloat(box.style.height || '') || box.getBoundingClientRect().height || box.offsetHeight || 0;
    if (currentHeight >= targetHeight - 0.5) return false;
    if (anchor === 'bottom') {
        const top = Number.parseFloat(box.style.top || '') || 0;
        box.style.top = `${top - (targetHeight - currentHeight)}px`;
    }
    box.style.height = `${targetHeight}px`;
    return true;
}

function fitPersistedRichOverlayBoxesToContent(layer) {
    if (!layer) return;
    layer.querySelectorAll('.enpv-annotation-box.is-persisted-overlay.is-rich-editor').forEach((box) => {
        if (!fitRichTextBoxToContent(box)) return;
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        const annotation = buildAnnotationFromBox(box, existing);
        if (annotation) {
            upsertPersistedAnnotation(annotation);
        }
    });
}

function prepareBoxForLiveResize(box) {
    if (!box) return;
    const isSignatureBox = box.dataset.annotationType === 'signature';
    if (!isSignatureBox) {
        box.dataset.userForcedRichText = '1';
        box.dataset.richTextPromotionReason = box.dataset.richTextPromotionReason || 'manual-resize';
    }
    const renderedHeight = box.getBoundingClientRect().height
        || Number.parseFloat(box.style.height || '')
        || box.offsetHeight
        || 0;
    if (renderedHeight > 0) box.style.height = `${renderedHeight}px`;
    box.style.minHeight = '';
    delete box.dataset.preEditHeight;
    if (editorModeForBox(box) === 'source') {
        promoteBoxToRichTextMode(box, 'manual-resize');
    } else {
        setBoxEditorMode(box, 'rich');
    }
    fitRichTextBoxToContent(box);
}

function onTextContentBeforeInput(ev) {
    const box = ev.currentTarget?.closest?.('.enpv-annotation-box');
    if (!box || editorModeForBox(box) !== 'source') return;
    const inputType = String(ev.inputType || '');
    if (inputType === 'insertParagraph' || inputType === 'insertLineBreak') {
        promoteBoxToRichTextMode(box, 'newline');
    }
}

function onTextContentPaste(ev) {
    const box = ev.currentTarget?.closest?.('.enpv-annotation-box');
    if (!box || editorModeForBox(box) !== 'source') return;
    const text = ev.clipboardData?.getData('text/plain') || '';
    const html = ev.clipboardData?.getData('text/html') || '';
    if (/\r|\n/.test(text) || html.trim()) {
        promoteBoxToRichTextMode(box, html.trim() ? 'rich-paste' : 'multiline-paste');
    }
}

function rectsOverlap(left, right, tolerance = 1) {
    if (!left || !right) return false;
    const leftRight = left.left + left.width;
    const leftBottom = left.top + left.height;
    const rightRight = right.left + right.width;
    const rightBottom = right.top + right.height;
    return left.left < (rightRight - tolerance)
        && leftRight > (right.left + tolerance)
        && left.top < (rightBottom - tolerance)
        && leftBottom > (right.top + tolerance);
}

function createPersistedOverlayBox(annotation, pageIndex, viewport, scale, editModeOn) {
    const currentPdfBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox;
    if (!currentPdfBox) return null;
    const rect = pdfRectToCanvasRect(currentPdfBox, viewport, scale);
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box is-persisted-overlay';
    box.dataset.uid = String(annotation.pdfjsAnchorUid || annotation.id || `${pageIndex}:persisted`);
    box.dataset.annotationId = String(annotation.id || buildPdfjsAnnotationId(pageIndex, box.dataset.uid));
    box.dataset.pageIndex = String(pageIndex);
    box.dataset.originalText = String(annotation.originalText || annotation.pdfjsSourceText || annotation.text || '');
    box.dataset.occurrence = String(annotation.pdfjsSourceOccurrence || '0');
    box.dataset.sourceBboxX = String(Number(annotation.pdfjsSourceX ?? currentPdfBox.x));
    box.dataset.sourceBboxY = String(Number(annotation.pdfjsSourceY ?? currentPdfBox.y));
    box.dataset.sourceBboxW = String(Number(annotation.pdfjsSourceW ?? currentPdfBox.w));
    box.dataset.sourceBboxH = String(Number(annotation.pdfjsSourceH ?? currentPdfBox.h));
    box.dataset.baseBboxX = box.dataset.sourceBboxX;
    box.dataset.baseBboxY = box.dataset.sourceBboxY;
    box.dataset.baseBboxW = box.dataset.sourceBboxW;
    box.dataset.baseBboxH = box.dataset.sourceBboxH;
    box.dataset.basePageHeight = String(Number(annotation.pdfjsSourcePageHeight || (Number(viewport.height) / scale)));
    box.dataset.baseText = String(annotation.pdfjsSourceText || annotation.originalText || annotation.text || '');
    box.dataset.fontSizePts = String(Number(annotation.fontSize) || 12);
    box.dataset.renderScale = String(scale);
    box.dataset.locked = annotation.locked ? '1' : '0';
    box.dataset.zIndex = String(Number(annotation.zIndex) || 2);
    if (boolish(annotation.styleDirty)) box.dataset.styleDirty = '1';
    if (annotation.userForcedRichText === true) box.dataset.userForcedRichText = '1';
    if (annotation.richTextPromotionReason) box.dataset.richTextPromotionReason = String(annotation.richTextPromotionReason);
    if (annotation.pdfjsEditorMode) box.dataset.editorMode = String(annotation.pdfjsEditorMode);
    restoreSourceSpanMetricsFromAnnotation(box, annotation);
    const displayRect = collapsedPersistedOverlayRect(rect, box, annotation, scale);
    box.style.left = `${displayRect.left}px`;
    box.style.top = `${displayRect.top}px`;
    box.style.width = `${Math.max(1, displayRect.width)}px`;
    box.style.height = `${Math.max(1, displayRect.height)}px`;
    box.style.zIndex = box.dataset.zIndex;
    box.classList.toggle('is-locked', annotation.locked === true);
    applyAnnotationTypographyToBox(box, annotation, scale);

    const tc = document.createElement('div');
    tc.className = 'enpv-text-content';
    tc.setAttribute('role', 'textbox');
    tc.setAttribute('aria-multiline', 'true');
    tc.contentEditable = 'false';
    tc.spellcheck = false;
    tc.textContent = String(annotation.text || '');
    box.appendChild(tc);
    if ((annotation.pdfjsSourceFidelity === true || box.dataset.sourceFontFamily || box.dataset.sourceTransform)
        && annotation.userForcedRichText !== true
        && annotation.pdfjsEditorMode !== 'rich') {
        applySourceFidelityTypography(box, { scale });
        setBoxEditorMode(box, 'source');
    } else {
        setBoxEditorMode(box, 'rich');
    }

    if (editModeOn) {
        box.addEventListener('pointerdown', onAnnBoxPointerDown);
        box.addEventListener('dblclick', onAnnBoxDblClick);
        for (const edge of ['t', 'r', 'b', 'l']) {
            const handle = document.createElement('div');
            handle.className = `enpv-resize-handle ${edge}`;
            handle.dataset.edge = edge;
            handle.addEventListener('pointerdown', onResizeHandlePointerDown);
            box.appendChild(handle);
        }
    } else {
        box.classList.add('is-readonly');
    }

    const maskRect = shouldMaskSourceForAnnotation(annotation)
        ? sourceMaskCanvasRectForAnnotation(annotation, viewport, scale)
        : null;
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    const mask = createSourceMaskElement(maskRect, pageDiv);
    return { box, rect, mask, maskRect };
}

function deletedMaskCanvasRect(annotation, viewport, scale) {
    if (!annotation || !viewport || !scale) return null;
    const sourceRect = deletedSourceRedactionBbox(annotation) || {
        x: Number(annotation.pdfjsSourceX ?? annotation.pdfX),
        y: Number(annotation.pdfjsSourceY ?? annotation.pdfY),
        w: Number(annotation.pdfjsSourceW ?? annotation.pdfWidth),
        h: Number(annotation.pdfjsSourceH ?? annotation.pdfHeight),
    };
    if (![sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        || sourceRect.w <= 0 || sourceRect.h <= 0) {
        return null;
    }
    return pdfRectToCanvasRect(inflatePdfRect(sourceRect, 0.5), viewport, scale);
}

function deletedSourceRedactionBbox(annotation) {
    const baseline = annotationBaselinePdfBox(annotation);
    if (!baseline) return null;
    const sourceX = Number(annotation.pdfjsSourceX ?? baseline.x);
    const sourceY = Number(annotation.pdfjsSourceY ?? baseline.y);
    const sourceW = Number(annotation.pdfjsSourceW ?? baseline.w);
    const sourceH = Number(annotation.pdfjsSourceH ?? baseline.h);
    if (![sourceX, sourceY, sourceW, sourceH].every(Number.isFinite) || sourceW <= 0 || sourceH <= 0) return null;
    return { x: sourceX, y: sourceY, w: sourceW, h: sourceH };
}

function applyDeletedAcroWidgetVisibility(pageIndex) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    if (!pageDiv) return;
    const annLayerEl = pageView?.annotationLayer?.div || pageDiv.querySelector(':scope > .annotationLayer');
    if (!annLayerEl) return;
    const widgets = Array.from(annLayerEl.querySelectorAll(
        '.textWidgetAnnotation, .buttonWidgetAnnotation, .choiceWidgetAnnotation, .signatureWidgetAnnotation'
    ));
    widgets.forEach((widget) => {
        if (widget.dataset.enpvDeletedHidden !== '1') return;
        widget.style.visibility = '';
        widget.style.pointerEvents = '';
        delete widget.dataset.enpvDeletedHidden;
    });
}

function deletedMaskAnnotationFromBox(box) {
    if (!box) return null;
    const layer = box.parentElement;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    if (!layer || !viewport) return null;
    const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
    const sourceRect = {
        x: Number.parseFloat(box.dataset.sourceBboxX || box.dataset.baseBboxX || ''),
        y: Number.parseFloat(box.dataset.sourceBboxY || box.dataset.baseBboxY || ''),
        w: Number.parseFloat(box.dataset.sourceBboxW || box.dataset.baseBboxW || ''),
        h: Number.parseFloat(box.dataset.sourceBboxH || box.dataset.baseBboxH || ''),
    };
    if (![sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        || sourceRect.w <= 0 || sourceRect.h <= 0) {
        return null;
    }
    const originalId = String(box.dataset.annotationId || box.dataset.uid || generateUuidV4()).replace(/[^a-zA-Z0-9:_-]/g, '_');
    const text = String(box.dataset.baseText || box.dataset.originalText || selectedBoxTextElement(box)?.textContent || '');
    return {
        id: `pdfjs_deleted_${originalId}`,
        type: 'text',
        pageIndex,
        text: '',
        originalText: text,
        pdfX: sourceRect.x,
        pdfY: sourceRect.y,
        pdfWidth: sourceRect.w,
        pdfHeight: sourceRect.h,
        fontSize: Number.parseFloat(box.dataset.fontSizePts || '') || 12,
        savedTextOverlay: true,
        pdfjsDeleted: true,
        pdfjsDeletedAnnotationId: String(box.dataset.annotationId || ''),
        pdfjsSourceX: sourceRect.x,
        pdfjsSourceY: sourceRect.y,
        pdfjsSourceW: sourceRect.w,
        pdfjsSourceH: sourceRect.h,
        pdfjsSourcePageHeight: Number.parseFloat(box.dataset.basePageHeight || '') || (Number(viewport.height) / scale),
        pdfjsSourceText: text,
        pdfjsAnchorUid: String(box.dataset.uid || ''),
        pdfjsSourceOccurrence: String(box.dataset.occurrence || '0'),
    };
}

function sourceMaskRectForBox(box) {
    if (!box) return null;
    if (!String(box.dataset.baseText || box.dataset.originalText || '').trim()) return null;
    const layer = box.parentElement;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    if (!layer || !viewport) return null;
    const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
    const sourceRect = {
        x: Number.parseFloat(box.dataset.sourceBboxX || box.dataset.baseBboxX || ''),
        y: Number.parseFloat(box.dataset.sourceBboxY || box.dataset.baseBboxY || ''),
        w: Number.parseFloat(box.dataset.sourceBboxW || box.dataset.baseBboxW || ''),
        h: Number.parseFloat(box.dataset.sourceBboxH || box.dataset.baseBboxH || ''),
    };
    if (![sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        || sourceRect.w <= 0 || sourceRect.h <= 0) {
        return null;
    }
    return pdfRectToCanvasRect(inflatePdfRect(sourceRect), viewport, scale);
}

function sourceMaskCanvasRectForAnnotation(annotation, viewport, scale) {
    if (!annotation || !viewport || !scale) return null;
    const sourceRect = annotationBaselinePdfBox(annotation);
    if (!sourceRect) return null;
    return pdfRectToCanvasRect(inflatePdfRect(sourceRect), viewport, scale);
}

function shouldMaskSourceForAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (boolish(annotation.pdfjsDeleted)) return false;
    if (!boolish(annotation.savedTextOverlay)) return false;
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    if (!sourceText) return false;
    const baseline = annotationBaselinePdfBox(annotation);
    if (!baseline) return false;
    return true;
}

function createSourceMaskElement(rect, pageDiv = null) {
    if (!ENABLE_PDFJS_DOM_SOURCE_MASKS) return null;
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;
    const mask = document.createElement('div');
    mask.className = 'enpv-orig-mask enpv-source-mask';
    mask.style.left = `${rect.left}px`;
    mask.style.top = `${rect.top}px`;
    mask.style.width = `${rect.width}px`;
    mask.style.height = `${rect.height}px`;
    mask.style.background = samplePageBackgroundColor(pageDiv, rect);
    mask.style.zIndex = '0';
    scheduleRuleAwareMaskRefresh(mask, rect, pageDiv, { minRectWidth: 18 });
    return mask;
}

function detectHorizontalCanvasRuleGaps(pageDiv, rect, options = {}) {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return [];
    const minRectWidth = Number(options.minRectWidth ?? 0) || 0;
    if (rect.width < minRectWidth) return [];
    const minDarkRatio = Number(options.minDarkRatio ?? 0.72) || 0.72;
    const canvas = pageDiv.querySelector(':scope canvas');
    if (!canvas) return [];
    let ctx = null;
    try {
        ctx = canvas.getContext('2d', { willReadFrequently: true });
    } catch (_) {
        return [];
    }
    if (!ctx) return [];
    const pageBounds = pageDiv.getBoundingClientRect();
    const canvasBounds = canvas.getBoundingClientRect();
    if (!canvasBounds.width || !canvasBounds.height || !canvas.width || !canvas.height) return [];

    const scaleX = canvas.width / canvasBounds.width;
    const scaleY = canvas.height / canvasBounds.height;
    const canvasLeft = rect.left - (canvasBounds.left - pageBounds.left);
    const canvasTop = rect.top - (canvasBounds.top - pageBounds.top);
    const sx = Math.max(0, Math.floor(canvasLeft * scaleX));
    const sy = Math.max(0, Math.floor(canvasTop * scaleY));
    const sw = Math.max(1, Math.min(canvas.width - sx, Math.ceil(rect.width * scaleX)));
    const sh = Math.max(1, Math.min(canvas.height - sy, Math.ceil(rect.height * scaleY)));
    if (sw <= 0 || sh <= 0) return [];

    let data;
    try {
        data = ctx.getImageData(sx, sy, sw, sh).data;
    } catch (_) {
        return [];
    }

    const darkRows = [];
    for (let y = 0; y < sh; y += 1) {
        let dark = 0;
        const rowOffset = y * sw * 4;
        for (let x = 0; x < sw; x += 1) {
            const offset = rowOffset + (x * 4);
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            if (r < 170 && g < 170 && b < 170) dark += 1;
        }
        if (dark / sw >= minDarkRatio) darkRows.push(y);
    }
    if (!darkRows.length) return [];

    const groups = [];
    let start = darkRows[0];
    let prev = darkRows[0];
    for (const row of darkRows.slice(1)) {
        if (row <= prev + 1) {
            prev = row;
            continue;
        }
        groups.push([start, prev]);
        start = row;
        prev = row;
    }
    groups.push([start, prev]);

    return groups
        .map(([a, b]) => ({
            top: Math.max(0, (a / scaleY) - 0.5),
            height: Math.min(rect.height, ((b - a + 1) / scaleY) + 1.0),
        }))
        .filter((gap) => gap.height > 0 && gap.height <= 4);
}

function applyDeletedEraseMaskSegments(mask, rect, pageDiv = null, options = {}) {
    if (!mask || !rect) return;
    mask.replaceChildren();
    let sampleRect = rect;
    if (mask.isConnected && pageDiv) {
        const maskBounds = mask.getBoundingClientRect();
        const pageBounds = pageDiv.getBoundingClientRect();
        sampleRect = {
            left: maskBounds.left - pageBounds.left,
            top: maskBounds.top - pageBounds.top,
            width: maskBounds.width,
            height: maskBounds.height,
        };
    }
    const background = samplePageBackgroundColor(pageDiv, sampleRect);
    mask.style.background = background;
    const gaps = detectHorizontalCanvasRuleGaps(pageDiv, sampleRect, options);
    if (!gaps.length) return;

    mask.style.background = 'transparent';
    let cursor = 0;
    for (const gap of gaps) {
        const next = Math.max(cursor, Math.min(rect.height, gap.top));
        if (next > cursor + 0.25) {
            const segment = document.createElement('div');
            segment.style.position = 'absolute';
            segment.style.left = '0';
            segment.style.top = `${cursor}px`;
            segment.style.width = '100%';
            segment.style.height = `${next - cursor}px`;
            segment.style.background = background;
            mask.appendChild(segment);
        }
        cursor = Math.max(cursor, Math.min(rect.height, gap.top + gap.height));
    }
    if (cursor < rect.height - 0.25) {
        const segment = document.createElement('div');
        segment.style.position = 'absolute';
        segment.style.left = '0';
        segment.style.top = `${cursor}px`;
        segment.style.width = '100%';
        segment.style.height = `${rect.height - cursor}px`;
        segment.style.background = background;
        mask.appendChild(segment);
    }
    if (!mask.childElementCount) mask.style.background = background;
}

function scheduleRuleAwareMaskRefresh(mask, rect, pageDiv = null, options = {}) {
    if (!mask || !pageDiv) return;
    applyDeletedEraseMaskSegments(mask, rect, pageDiv, options);
    window.requestAnimationFrame(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv, options));
    window.setTimeout(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv, options), 250);
    window.setTimeout(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv, options), 1000);
}

function createDeletedEraseElement(rect, pageDiv = null) {
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;
    const mask = document.createElement('div');
    mask.className = 'enpv-delete-erase-mask';
    mask.dataset.deletedEraseMask = '1';
    mask.style.left = `${rect.left}px`;
    mask.style.top = `${rect.top}px`;
    mask.style.width = `${rect.width}px`;
    mask.style.height = `${rect.height}px`;
    mask.style.position = 'absolute';
    mask.style.background = samplePageBackgroundColor(pageDiv, rect);
    mask.style.pointerEvents = 'none';
    mask.style.zIndex = '1';
    applyDeletedEraseMaskSegments(mask, rect, pageDiv);
    if (pageDiv) {
        window.requestAnimationFrame(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv));
        window.setTimeout(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv), 250);
        window.setTimeout(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv), 1000);
        window.setTimeout(() => applyDeletedEraseMaskSegments(mask, rect, pageDiv), 2000);
        let refreshes = 0;
        const refreshTimer = window.setInterval(() => {
            if (!mask.isConnected || refreshes >= 10) {
                window.clearInterval(refreshTimer);
                return;
            }
            refreshes += 1;
            applyDeletedEraseMaskSegments(mask, rect, pageDiv);
        }, 500);
    }
    return mask;
}

function attachSourceMaskForBox(box) {
    if (!box) return null;
    const layer = box.parentElement;
    if (!layer) return null;
    const rect = sourceMaskRectForBox(box);
    if (!rect) return null;
    let mask = box._enpvSourceMask?.isConnected ? box._enpvSourceMask : null;
    if (!mask) {
        const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
        const pageDiv = Number.isFinite(pageIndex) && pageIndex >= 0
            ? pdfViewer.getPageView(pageIndex)?.div
            : layer.closest('.page');
        mask = createSourceMaskElement(rect, pageDiv);
        if (!mask) return null;
        layer.insertBefore(mask, box);
    }
    if (!mask) return null;
    mask.style.left = `${rect.left}px`;
    mask.style.top = `${rect.top}px`;
    mask.style.width = `${rect.width}px`;
    mask.style.height = `${rect.height}px`;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageDiv = Number.isFinite(pageIndex) && pageIndex >= 0
        ? pdfViewer.getPageView(pageIndex)?.div
        : layer.closest('.page');
    mask.style.background = samplePageBackgroundColor(pageDiv, rect);
    scheduleRuleAwareMaskRefresh(mask, rect, pageDiv, { minRectWidth: 18 });
    box._enpvSourceMask = mask;
    box.dataset.sourceMaskAttached = '1';
    return mask;
}

function collapsedPersistedOverlayRect(rect, box, annotation, scale) {
    if (!rect || !box || !annotation) return rect;
    if (boolish(annotation.userForcedRichText)
        || String(annotation.pdfjsEditorMode || '') === 'rich'
        || String(annotation.richTextPromotionReason || '') === 'manual-resize') {
        return rect;
    }
    const currentPdfBox = annotationCurrentPdfBox(annotation);
    const sourcePdfBox = annotationBaselinePdfBox(annotation);
    if (currentPdfBox && sourcePdfBox
        && (!approxEqual(currentPdfBox.w, sourcePdfBox.w, 1)
            || !approxEqual(currentPdfBox.h, sourcePdfBox.h, 1))) {
        return rect;
    }
    const text = String(annotation.text || '');
    if (!text.trim() || /\r|\n/.test(text)) return rect;

    const sourceWidthPts = Number(annotation.pdfjsSourceW);
    const sourceHeightPts = Number(annotation.pdfjsSourceH);
    const widthFromSource = Number.isFinite(sourceWidthPts) && sourceWidthPts > 0 && scale > 0
        ? sourceWidthPts * scale
        : 0;
    const heightFromSource = Number.isFinite(sourceHeightPts) && sourceHeightPts > 0 && scale > 0
        ? sourceHeightPts * scale
        : 0;
    const sourceTextWidthPx = Number.parseFloat(box.dataset.sourceTextWidthPx || '');
    const sourceScaleX = Number.parseFloat(box.dataset.sourceTransformScaleX || '1') || 1;
    const widthFromTextMetrics = Number.isFinite(sourceTextWidthPx) && sourceTextWidthPx > 0
        ? sourceTextWidthPx * sourceScaleX
        : 0;
    const sourceLineHeightPx = Number.parseFloat(box.dataset.sourceLineHeightPx || '')
        || Number.parseFloat(box.dataset.sourceFontSizePx || '')
        || heightFromSource;
    const currentLooksWrapped = Number.isFinite(sourceLineHeightPx)
        && sourceLineHeightPx > 0
        && rect.height > (sourceLineHeightPx * 1.6);
    if (currentLooksWrapped) return rect;

    const nextWidth = Math.max(widthFromSource, widthFromTextMetrics);
    const nextHeight = heightFromSource;
    return {
        ...rect,
        width: nextWidth > 1 && nextWidth < rect.width ? nextWidth : rect.width,
        height: nextHeight > 1 && nextHeight < rect.height ? nextHeight : rect.height,
    };
}

function addEditableBoxChrome(box) {
    box.addEventListener('pointerdown', onAnnBoxPointerDown);
    box.addEventListener('dblclick', onAnnBoxDblClick);
    for (const edge of ['t', 'r', 'b', 'l']) {
        const handle = document.createElement('div');
        handle.className = `enpv-resize-handle ${edge}`;
        handle.dataset.edge = edge;
        handle.addEventListener('pointerdown', onResizeHandlePointerDown);
        box.appendChild(handle);
    }
}

function ensureAnnotationBoxLayer(pageIndex, scale) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    if (!pageDiv) return null;
    let layer = pageDiv.querySelector(':scope > .enpv-annotation-box-layer');
    if (!layer) {
        layer = document.createElement('div');
        layer.className = 'enpv-annotation-box-layer';
        layer.dataset.pageIndex = String(pageIndex);
        pageDiv.appendChild(layer);
    }
    layer.dataset.scale = String(scale);
    return layer;
}

function createAnnotationBoxFromSpan(spanEl) {
    if (!spanEl) return null;
    const pageIndex = Number.parseInt(spanEl.dataset.enpvPage || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    const textLayerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!pageView?.div || !viewport || !textLayerEl) return null;

    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    const layer = ensureAnnotationBoxLayer(pageIndex, scale);
    if (!layer) return null;

    const uid = `${pageIndex}:${spanEl.dataset.enpvIndex || spanEl.dataset.enpvOccurrence || '0'}`;
    const textLayerRect = textLayerEl.getBoundingClientRect();
    const spanRect = spanEl.getBoundingClientRect();
    if (!spanHasVisibleCanvasInk(spanEl, pageView.div)) return null;
    const rect = {
        left: spanRect.left - textLayerRect.left,
        top: spanRect.top - textLayerRect.top,
        width: spanRect.width,
        height: spanRect.height,
    };
    if (rect.width <= 0 || rect.height <= 0) return null;

    const sourceBbox = {
        x: rect.left / scale,
        y: (Number(viewport.height) - (rect.top + rect.height)) / scale,
        w: rect.width / scale,
        h: rect.height / scale,
    };
    const matchedAnnotation = findPersistedAnnotationForSpan(
        pageIndex,
        sourceBbox,
        spanEl.textContent || '',
        spanEl.dataset.enpvOriginal || spanEl.textContent || '',
        false,
        uid,
    );
    const handleUid = sourceHandleUid(pageIndex, uid, matchedAnnotation);
    const annotationId = sourceHandleAnnotationId(pageIndex, uid, matchedAnnotation);
    const existingBox = layer.querySelector(`.enpv-annotation-box[data-uid="${cssEscape(handleUid)}"]`);
    if (existingBox) return existingBox;
    const baseRect = matchedAnnotation?._baselinePdfBox || sourceBbox;
    const baseText = String(matchedAnnotation?.pdfjsSourceText || matchedAnnotation?.originalText || spanEl.dataset.enpvOriginal || spanEl.textContent || '');

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box is-on-demand';
    box.dataset.uid = handleUid;
    box.dataset.annotationId = annotationId;
    box.dataset.pageIndex = String(pageIndex);
    box.dataset.originalText = String(matchedAnnotation?.originalText || baseText);
    box.dataset.occurrence = spanEl.dataset.enpvOccurrence || '0';
    box.dataset.sourceBboxX = String(sourceBbox.x);
    box.dataset.sourceBboxY = String(sourceBbox.y);
    box.dataset.sourceBboxW = String(sourceBbox.w);
    box.dataset.sourceBboxH = String(sourceBbox.h);
    box.dataset.baseBboxX = String(baseRect.x);
    box.dataset.baseBboxY = String(baseRect.y);
    box.dataset.baseBboxW = String(baseRect.w);
    box.dataset.baseBboxH = String(baseRect.h);
    box.dataset.basePageHeight = String(Number(matchedAnnotation?.pdfjsSourcePageHeight) || (Number(viewport.height) / scale));
    box.dataset.baseText = baseText;
    box.dataset.renderScale = String(scale);
    if (boolish(matchedAnnotation?.styleDirty)) box.dataset.styleDirty = '1';
    const displayRect = sourceBoxDisplayRect(rect);
    box.style.left = `${rect.left}px`;
    box.style.top = `${rect.top}px`;
    box.style.width = `${Math.max(1, displayRect.width)}px`;
    box.style.height = `${Math.max(1, displayRect.height)}px`;
    spanEl.dataset.enpvPersistentId = annotationId;

    const cs = window.getComputedStyle(spanEl);
    let fsPx = parseFloat(cs.fontSize) || 0;
    if (spanRect.height > fsPx) fsPx = spanRect.height;
    if (!(fsPx > 1)) fsPx = Math.max(8, rect.height);
    captureSourceSpanMetrics(box, spanEl, spanRect, scale, pageView.div);
    if (matchedAnnotation?.fontFamily) box.style.setProperty('--enpv-font-family', matchedAnnotation.fontFamily);
    if (Number(matchedAnnotation?.fontSize) > 0) box.dataset.fontSizePts = String(Number(matchedAnnotation.fontSize));
    const matchedTextColor = cssColorToHex(
        (!boolish(matchedAnnotation?.styleDirty) && (matchedAnnotation?.pdfjsSourceTextColor || box.dataset.sourceTextColor))
        || matchedAnnotation?.textColor
        || matchedAnnotation?.color
        || '#000000',
    );
    box.style.setProperty('--enpv-text-color', matchedTextColor);

    const tc = document.createElement('div');
    tc.className = 'enpv-text-content';
    tc.setAttribute('role', 'textbox');
    tc.setAttribute('aria-multiline', 'true');
    tc.contentEditable = 'false';
    tc.spellcheck = false;
    tc.textContent = String(matchedAnnotation?.text || spanEl.textContent || spanEl.dataset.enpvOriginal || '');
    box.appendChild(tc);
    if (matchedAnnotation?.userForcedRichText === true || matchedAnnotation?.pdfjsEditorMode === 'rich') {
        box.dataset.userForcedRichText = '1';
        setBoxEditorMode(box, 'rich');
    } else {
        setBoxEditorMode(box, editorModeForBox(box));
    }
    addEditableBoxChrome(box);
    layer.appendChild(box);
    return box;
}

function syncSelectedBoxToPersistedAnnotations() {
    const selectedBox = findSelectedBox()
        || document.querySelector('.enpv-annotation-box.is-editing')
        || document.querySelector('.enpv-annotation-box.is-selected');
    if (!selectedBox) return;
    syncAnnotationBoxToPersistedAnnotations(selectedBox);
}

function syncAnnotationBoxToPersistedAnnotations(box, options = {}) {
    if (!box) return;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (box.classList.contains('is-editing') && !options.preserveEditMode) {
        endEditMode(box);
    }
    const annotation = buildAnnotationFromBox(box, existing);
    if (annotation) {
        upsertPersistedAnnotation(annotation);
        box.classList.add('is-persisted-overlay');
        if (shouldMaskSourceForAnnotation(annotation)) {
            attachSourceMaskForBox(box);
        } else {
            removeAnnBoxSourceMasks(box);
        }
        delete box.dataset.pendingEdit;
        delete box.dataset.pendingResize;
    } else if (box.dataset.annotationId) {
        deletePersistedAnnotation(box.dataset.annotationId);
        box.classList.remove('is-persisted-overlay');
        if (box._enpvSourceMask) {
            box._enpvSourceMask.remove();
            box._enpvSourceMask = null;
            delete box.dataset.sourceMaskAttached;
        }
    }
}

function promoteSourceBoxToMovedOverlay(box) {
    if (!box || box.classList.contains('is-persisted-overlay')) return;
    const tc = selectedBoxTextElement(box);
    if (tc && !String(tc.textContent || '').trim()) {
        tc.textContent = String(box.dataset.baseText || box.dataset.originalText || '');
    }
    box.dataset.movedTextOverlay = '1';
    box.classList.add('is-persisted-overlay');
    applySourceFidelityTypography(box);
    attachSourceMaskForBox(box);
}

function syncDirtyBoxesToPersistedAnnotations() {
    document.querySelectorAll(
        '.enpv-annotation-box.is-editing, ' +
        '.enpv-annotation-box[data-pending-edit="1"], ' +
        '.enpv-annotation-box[data-pending-resize="1"]'
    ).forEach((box) => syncAnnotationBoxToPersistedAnnotations(box));
}

function buildBakedPdfUrl() {
    if (!BAKED_URL) return '';
    const url = new URL(BAKED_URL, window.location.origin);
    const sessionId = getSessionId();
    if (sessionId) url.searchParams.set('session_id', sessionId);
    url.searchParams.set('v', String(Date.now()));
    return url.toString();
}

async function loadInitialPdf() {
    const candidates = [];
    if (PDF_URL) candidates.push(PDF_URL);
    const bakedUrl = buildBakedPdfUrl();
    if (bakedUrl) candidates.push(bakedUrl);

    let lastError = null;
    for (const url of candidates) {
        try {
            await loadPdfFromUrl(url);
            return;
        } catch (err) {
            lastError = err;
            console.warn('Failed to load PDF candidate', url, err);
        }
    }

    if (lastError) throw lastError;
}

const eventBus = new EventBus();
const linkService = new PDFLinkService({ eventBus });
const findController = new PDFFindController({ eventBus, linkService });

const pdfViewer = new PDFViewer({
    container,
    eventBus,
    linkService,
    findController,
    annotationEditorMode: pdfjsLib.AnnotationEditorType?.NONE ?? 0,
    annotationMode: pdfjsLib.AnnotationMode?.ENABLE_FORMS ?? 2,
    textLayerMode: 2,
});
linkService.setViewer(pdfViewer);

// Per-page occurrence counters for identical text strings.
// Map<pageIndex, Map<text, count>>.
const pageOccurrenceCounts = new Map();
const MAX_SOURCE_SPAN_BOXES_PER_PAGE = 2500;

eventBus.on('textlayerrendered', (evt) => {
    const pageIndex = evt.pageNumber - 1;
    const pageView = pdfViewer.getPageView(pageIndex);
    if (!pageView || !pageView.textLayer) return;
    const layerEl = pageView.textLayer.div || pageView.textLayer.textLayerDiv;
    if (!layerEl) return;
    wireTextLayerClicks(layerEl, pageIndex);
    renderAnnotationBoxLayer(pageIndex);
});

// AcroForm widgets render in pdf.js's annotationLayer, which can finish
// after textlayerrendered. Re-render our overlay so widget rects are
// available for the suppress-overlap pass below.
eventBus.on('annotationlayerrendered', (evt) => {
    applyAcroFormEntriesToDom(evt.pageNumber - 1);
    renderAnnotationBoxLayer(evt.pageNumber - 1);
});

function wireTextLayerClicks(layerEl, pageIndex) {
    const counters = new Map();
    pageOccurrenceCounts.set(pageIndex, counters);
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div || null;
    const spans = layerEl.querySelectorAll('span');
    let textIndex = 0;
    for (const span of spans) {
        const text = span.textContent;
        if (!text || !text.trim()) continue;
        const occ = counters.get(text) || 0;
        counters.set(text, occ + 1);
        const currentIndex = textIndex++;
        if (!spanHasVisibleCanvasInk(span, pageDiv)) continue;
        span.dataset.enpvIndex = String(currentIndex);
        span.dataset.enpvPage = String(pageIndex);
        span.dataset.enpvOccurrence = String(occ);
        span.dataset.enpvOriginal = text;
        span.addEventListener('click', onSpanClick);
        span.addEventListener('dblclick', onSpanDblClick);
    }
}

function safeLocalStorageGet(key) {
    try { return window.localStorage.getItem(key); } catch (_) { return null; }
}

function safeLocalStorageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (_) {}
}

function getSessionId() {
    const key = `edit_new_session_${DOC_ID}`;
    let id = safeLocalStorageGet(key);
    if (!id) {
        id = generateUuidV4();
        safeLocalStorageSet(key, id);
    }
    return id;
}

function annotationPageIndex(ann) {
    const candidates = [
        ann?.pageIndex,
        ann?.page_index,
        Number.isFinite(Number(ann?.db_page_number)) ? Number(ann.db_page_number) - 1 : null,
        Number.isFinite(Number(ann?.pageNumber)) ? Number(ann.pageNumber) - 1 : null,
        Number.isFinite(Number(ann?.page)) ? Number(ann.page) : null,
    ];
    for (const candidate of candidates) {
        const n = Number(candidate);
        if (Number.isFinite(n) && n >= 0) return Math.floor(n);
    }
    return 0;
}

function hydrateAnnotationForBoxes(ann, index) {
    const hydrated = { ...ann };
    hydrated._uid = String(hydrated.id || hydrated.db_id || `ann_${index}`);
    const sL = Number(hydrated.sourceBlockLeft);
    const sT = Number(hydrated.sourceBlockTop);
    const sW = Number(hydrated.sourceBlockWidth);
    const sH = Number(hydrated.sourceBlockHeight);
    const pageH = Number(hydrated.sourcePageHeight);
    if ([sL, sT, sW, sH, pageH].every(Number.isFinite) && sW > 0 && sH > 0) {
        hydrated._originalBox = { x: sL, y: pageH - (sT + sH), w: sW, h: sH };
    }
    const pX = Number(hydrated.pdfX);
    const pY = Number(hydrated.pdfY);
    const pW = Number(hydrated.pdfWidth);
    const pH = Number(hydrated.pdfHeight);
    if ([pX, pY, pW, pH].every(Number.isFinite) && pW > 0 && pH > 0) {
        hydrated._originalPdfBox = { x: pX, y: pY, w: pW, h: pH };
        hydrated._currentPdfBox = { x: pX, y: pY, w: pW, h: pH };
    }
    const bX = Number(hydrated.pdfjsSourceX);
    const bY = Number(hydrated.pdfjsSourceY);
    const bW = Number(hydrated.pdfjsSourceW);
    const bH = Number(hydrated.pdfjsSourceH);
    if ([bX, bY, bW, bH].every(Number.isFinite) && bW > 0 && bH > 0) {
        hydrated._baselinePdfBox = { x: bX, y: bY, w: bW, h: bH };
    }
    return hydrated;
}

async function loadAnnotationBoxes() {
    if (annotationBoxesLoadPromise) return annotationBoxesLoadPromise;
    annotationBoxesLoadPromise = loadAnnotationBoxesOnce();
    return annotationBoxesLoadPromise;
}

async function loadAnnotationBoxesOnce() {
    if (!INFO_URL) {
        hydratingPersistedAnnotations = true;
        try {
            renderAllAnnotationBoxLayers();
            await nextAnimationFrame();
        } finally {
            hydratingPersistedAnnotations = false;
        }
        return true;
    }
    hydratingPersistedAnnotations = true;
    try {
        const url = new URL(INFO_URL, window.location.origin);
        url.searchParams.set('session_id', getSessionId());
        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (!data?.success) throw new Error(data?.message || 'Failed to load annotation boxes.');

        const raw = Array.isArray(data.annotations) ? data.annotations : [];
        replacePersistedAnnotations(raw.filter((ann) => ann && ann.db_state !== 'deleted'));
        // Capture echoed AcroForm entries so a) we can re-populate the
        // pdf.js form fields with previously-saved values, and b) the
        // Save round-trip preserves entries the user didn't touch this
        // session (server normalizes by `key`).
        acroFormEntries = Array.isArray(data.acro_form_entries) ? data.acro_form_entries : [];
        applyAcroFormEntriesToStorage().catch((err) => {
            console.warn('Failed to apply persisted AcroForm values to pdf.js storage', err);
        });
        const rewroteSavedSourceText = await rewritePersistedSourceAnnotationsToCanvas().catch((err) => {
            console.warn('Failed to rewrite saved source text into pdf.js canvas', err);
            setStatus('Saved source text is shown as an overlay because stream rewrite failed.', true);
            return false;
        });
        if (rewroteSavedSourceText) return true;
        renderAllAnnotationBoxLayers();
        await nextAnimationFrame();
        renderAllAnnotationBoxLayers();
        await nextAnimationFrame();
        return true;
    } catch (err) {
        console.warn('Failed to load edit-new annotation boxes for pdf.js edit mode', err);
        setStatus('Failed to load saved edits; raw PDF hidden.', true);
        showError('Failed to load saved edits, so the raw PDF was not shown.');
        return false;
    } finally {
        hydratingPersistedAnnotations = false;
    }
}

// ---- AcroForm persistence (parity with /edit-new) -----------------------
//
// pdf.js renders AcroForm widgets natively when annotationMode is set to
// ENABLE_FORMS, and accumulates user input in `pdfDoc.annotationStorage`.
// We bridge that storage to the same `acro_form_entries` payload shape
// /edit-new posts, so saved values:
//   1. round-trip through the existing /save-annotation-state endpoint, and
//   2. re-populate the form fields when the document is re-opened.

// Walk every page's widget annotations once and run `cb(widget, pageIndex)`
// for each. Keeps annotationLookup logic in one place.
async function forEachWidget(cb) {
    if (!currentPdfDoc) return;
    const numPages = currentPdfDoc.numPages || 0;
    for (let pi = 0; pi < numPages; pi += 1) {
        let page;
        try { page = await currentPdfDoc.getPage(pi + 1); } catch (_) { continue; }
        let widgets;
        try { widgets = await page.getAnnotations({ intent: 'display' }); }
        catch (_) { continue; }
        for (const w of (Array.isArray(widgets) ? widgets : [])) {
            if (!w || w.subtype !== 'Widget' || w.hidden) continue;
            cb(w, pi);
        }
    }
}

function acroFieldKeyFromWidget(w) {
    return String(w?.fieldName || w?.id || w?.fullName || '').trim();
}

// Apply previously-saved values from `acroFormEntries` into pdf.js's
// annotationStorage so the rendered form widgets show the persisted
// state. Looks up by fieldName; falls back to widget.id if needed.
async function applyAcroFormEntriesToStorage() {
    if (!currentPdfDoc || !Array.isArray(acroFormEntries) || acroFormEntries.length === 0) return;
    const storage = currentPdfDoc.annotationStorage;
    if (!storage || typeof storage.setValue !== 'function') return;
    const byFieldName = new Map();
    const byKey = new Map();
    for (const entry of acroFormEntries) {
        if (!entry) continue;
        const k = String(entry.key || '').trim();
        const fn = String(entry.fieldName || '').trim();
        if (k) byKey.set(k, entry);
        if (fn) byFieldName.set(fn, entry);
    }
    await forEachWidget((w) => {
        const fn = String(w.fieldName || '').trim();
        const entry = (fn && byFieldName.get(fn)) || (w.id && byKey.get(String(w.id))) || null;
        if (!entry) return;
        const isCheck = w.checkBox === true || entry.checkBox === true;
        const isRadio = w.radioButton === true || entry.radioButton === true;
        let storageValue;
        if (isCheck || isRadio) {
            // pdf.js storage for buttons expects { value: true/false } or
            // (radio) the export value of the selected button.
            const v = entry.value;
            if (isRadio) {
                storageValue = { value: typeof v === 'string' ? v : (v ? String(w.exportValue || v) : '') };
            } else {
                const truthy = (v === true) || v === '1' || v === 'true' || v === w.exportValue;
                storageValue = { value: !!truthy };
            }
        } else {
            const v = entry.value;
            storageValue = { value: v == null ? '' : (Array.isArray(v) ? v : String(v)) };
        }
        try { storage.setValue(w.id, storageValue); } catch (_) { /* noop */ }
    });
    applyAcroFormEntriesToDom();
}

function acroEntryTruthyValue(value, exportValue = '') {
    return value === true
        || value === 1
        || value === '1'
        || value === 'true'
        || (exportValue && String(value) === String(exportValue));
}

function applyAcroFormEntriesToDom(pageIndex = null) {
    if (!Array.isArray(acroFormEntries) || acroFormEntries.length === 0) return;
    const byFieldName = new Map();
    const byKey = new Map();
    for (const entry of acroFormEntries) {
        if (!entry) continue;
        const key = String(entry.key || '').trim();
        const fieldName = String(entry.fieldName || '').trim();
        if (key) byKey.set(key, entry);
        if (fieldName) byFieldName.set(fieldName, entry);
    }
    const selector = pageIndex == null
        ? '.annotationLayer input, .annotationLayer textarea, .annotationLayer select'
        : `.page[data-page-number="${Number(pageIndex) + 1}"] .annotationLayer input, `
            + `.page[data-page-number="${Number(pageIndex) + 1}"] .annotationLayer textarea, `
            + `.page[data-page-number="${Number(pageIndex) + 1}"] .annotationLayer select`;
    document.querySelectorAll(selector).forEach((el) => {
        const host = el.closest('[data-annotation-id]');
        const annotationId = String(host?.dataset?.annotationId || '').trim();
        const fieldName = String(el.getAttribute('name') || '').trim();
        const entry = (fieldName && byFieldName.get(fieldName))
            || (annotationId && byKey.get(annotationId))
            || null;
        if (!entry) return;
        const rawValue = entry.value;
        const type = String(el.getAttribute('type') || '').toLowerCase();
        if (type === 'checkbox') {
            el.checked = acroEntryTruthyValue(rawValue, el.value || entry.exportValue || '');
        } else if (type === 'radio') {
            const value = String(rawValue ?? '');
            el.checked = value !== '' && (value === String(el.value || '') || value === String(entry.exportValue || ''));
        } else if (el instanceof HTMLSelectElement && el.multiple && Array.isArray(rawValue)) {
            const values = new Set(rawValue.map((value) => String(value ?? '')));
            Array.from(el.options).forEach((option) => { option.selected = values.has(String(option.value)); });
        } else {
            el.value = rawValue == null ? '' : (Array.isArray(rawValue) ? rawValue.join('\n') : String(rawValue));
        }
    });
}

// Snapshot the live AcroForm state out of pdf.js's annotationStorage and
// build entries shaped like the server's `acro_form_entries` payload.
// Untouched widgets (no storage value) fall back to the entry already
// echoed by documentInfo, so the round-trip is loss-less.
async function collectAcroFormEntriesForSave() {
    if (!currentPdfDoc) return Array.isArray(acroFormEntries) ? acroFormEntries : [];
    const storage = currentPdfDoc.annotationStorage;
    const storageMap = (storage && typeof storage.getAll === 'function') ? (storage.getAll() || {}) : {};
    const existingByKey = new Map();
    for (const entry of (Array.isArray(acroFormEntries) ? acroFormEntries : [])) {
        if (!entry) continue;
        const k = String(entry.key || entry.fieldName || '').trim();
        if (k) existingByKey.set(k, entry);
    }
    const out = [];
    const seenKeys = new Set();
    await forEachWidget((w, pageIndex) => {
        const key = acroFieldKeyFromWidget(w);
        if (!key) return;
        if (seenKeys.has(key)) return; // dedupe widgets that share a field
        seenKeys.add(key);
        const stored = storageMap[w.id];
        const existing = existingByKey.get(key) || existingByKey.get(String(w.fieldName || '')) || null;
        const isCheck = w.checkBox === true;
        const isRadio = w.radioButton === true;
        let value;
        if (stored && Object.prototype.hasOwnProperty.call(stored, 'value')) {
            value = stored.value;
            if (isCheck) {
                value = stored.value ? (w.exportValue || '1') : '';
            } else if (isRadio) {
                value = stored.value ? String(stored.value) : '';
            } else if (Array.isArray(value)) {
                value = value.map((s) => String(s ?? ''));
            } else if (value == null) {
                value = '';
            } else {
                value = String(value);
            }
        } else if (existing) {
            value = existing.value;
        } else {
            return; // never touched and no prior entry — skip
        }
        out.push({
            key,
            fieldName: String(w.fieldName || key),
            pageIndex,
            fieldType: String(w.fieldType || existing?.fieldType || '').toUpperCase(),
            checkBox: isCheck || existing?.checkBox === true,
            radioButton: isRadio || existing?.radioButton === true,
            combo: w.combo === true || existing?.combo === true,
            multiLine: w.multiLine === true || existing?.multiLine === true,
            multiSelect: w.multiSelect === true || existing?.multiSelect === true,
            exportValue: String(w.exportValue || existing?.exportValue || ''),
            rect: Array.isArray(w.rect) ? w.rect.slice(0, 4) : (existing?.rect || null),
            textColor: existing?.textColor || null,
            value,
        });
    });
    // Preserve any persisted entries that don't correspond to a widget
    // currently in the rendered PDF (defensive — keeps server state
    // intact even if a widget couldn't be resolved this round).
    for (const [key, entry] of existingByKey) {
        if (!seenKeys.has(key)) out.push(entry);
    }
    return out;
}

function pageNeedsOverlayProtection(pageIndex) {
    const annotations = annotationBoxesByPage.get(pageIndex) || [];
    return annotations.some((annotation) => {
        if (!annotation) return false;
        if (isSignatureAnnotation(annotation)) return true;
        if (String(annotation.type || '').toLowerCase() !== 'text') return false;
        if (isPromotedExtractionAnnotation(annotation)) return cleanPromotedSourceText(annotation) !== '';
        if (boolish(annotation.pdfjsDeleted)) return true;
        if (annotation._pdfjsCanvasRewritten === true) return false;
        if (isRedundantPdfjsSourceOverlay(annotation)) return false;
        return shouldMaskSourceForAnnotation(annotation)
            || boolish(annotation.savedTextOverlay)
            || Boolean(annotation.pdfjsSourceText || annotation.originalText);
    });
}

function markPageOverlaysPending(pageIndex) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    if (!pageDiv) return;
    if (pageNeedsOverlayProtection(pageIndex)) {
        pageDiv.classList.add('enpv-overlays-pending');
        pageDiv.classList.remove('enpv-overlays-ready');
    }
}

function markPageOverlaysReady(pageIndex) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    if (!pageDiv) return;
    pageDiv.classList.remove('enpv-overlays-pending');
    if (pageNeedsOverlayProtection(pageIndex)) {
        pageDiv.classList.add('enpv-overlays-ready');
    } else {
        pageDiv.classList.remove('enpv-overlays-ready');
    }
}

function removeAnnotationBoxLayer(pageIndex) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    pageDiv?.querySelector(':scope > .enpv-annotation-box-layer')?.remove();
    markPageOverlaysPending(pageIndex);
}

function renderAnnotationBoxLayer(pageIndex) {
    const editModeOn = document.body.classList.contains('enpv-edit-on');
    const allAnnotations = annotationBoxesByPage.get(pageIndex) || [];
    const allTextAnnotations = allAnnotations.filter(
        (annotation) => annotation && String(annotation.type || '').toLowerCase() === 'text',
    );
    const persistedTextAnnotations = allTextAnnotations.filter((annotation) => !isPromotedExtractionAnnotation(annotation));
    const promotedTextAnnotations = allTextAnnotations.filter((annotation) => isPromotedExtractionAnnotation(annotation));
    const persistedSignatureAnnotations = allAnnotations.filter(isSignatureAnnotation);
    const visibleTextAnnotations = persistedTextAnnotations.filter(
        (annotation) => annotation.pdfjsDeleted !== true
            && annotation._pdfjsCanvasRewritten !== true
            && !isRedundantPdfjsSourceOverlay(annotation),
    );
    const deletedTextMasks = persistedTextAnnotations.filter(
        (annotation) => annotation.pdfjsDeleted === true
            && !deletedPdfjsMaskIsOwnedByReplacement(annotation, visibleTextAnnotations),
    );
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    const viewport = pageView?.viewport;
    const textLayerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!pageDiv || !viewport) return;
    markPageOverlaysPending(pageIndex);
    if (editModeOn && !textLayerEl) return;

    removeAnnotationBoxLayer(pageIndex);

    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!scale) return;
    applyDeletedAcroWidgetVisibility(pageIndex);

    const sourceOwners = buildSourceOwnershipRegistry(persistedTextAnnotations);
    const promotedFallbackCandidates = promotedTextAnnotations.filter((annotation) => cleanPromotedSourceText(annotation));
    const promotedFallbackTextAnnotations = promotedFallbackCandidates.filter((annotation) => {
        if (!cleanPromotedSourceText(annotation)) return false;
        if (sourceOwners.isOwned(annotation)) return false;
        return textLayerEl
            ? !promotedFallbackIsCoveredByPdfjsText(annotation, textLayerEl, viewport, scale)
            : true;
    });
    if (!editModeOn
        && visibleTextAnnotations.length === 0
        && deletedTextMasks.length === 0
        && persistedSignatureAnnotations.length === 0
        && promotedFallbackTextAnnotations.length === 0) {
        removeAnnotationBoxLayer(pageIndex);
        markPageOverlaysReady(pageIndex);
        return;
    }

    const layer = document.createElement('div');
    layer.className = 'enpv-annotation-box-layer';
    layer.dataset.pageIndex = String(pageIndex);
    layer.dataset.scale = String(scale);
    layer.dataset.editMode = editModeOn ? '1' : '0';

    const persistedOverlayRects = [];
    for (const annotation of deletedTextMasks) {
        const maskRect = deletedMaskCanvasRect(annotation, viewport, scale);
        const mask = createDeletedEraseElement(maskRect, pageDiv);
        if (!mask) continue;
        layer.appendChild(mask);
        persistedOverlayRects.push(maskRect);
    }
    for (const annotation of visibleTextAnnotations) {
        const rendered = createPersistedOverlayBox(annotation, pageIndex, viewport, scale, editModeOn);
        if (!rendered) continue;
        persistedOverlayRects.push(rendered.maskRect || rendered.rect);
        if (rendered.mask) layer.appendChild(rendered.mask);
        layer.appendChild(rendered.box);
    }
    // Signature annotations: render as image-backed boxes that reuse the
    // text box drag/select/lock infrastructure (onAnnBoxPointerDown).
    if (signatureFeature) {
        for (const annotation of persistedSignatureAnnotations) {
            const sigBox = signatureFeature.createSignatureBoxElement(annotation, pageIndex, viewport, scale, editModeOn, {
                onAnnBoxPointerDown,
                onResizeHandlePointerDown,
                selectAnnBox,
            });
            if (sigBox) layer.appendChild(sigBox);
        }
    }

    if (!editModeOn) {
        appendPromotedFallbackOverlays(layer, promotedFallbackTextAnnotations, pageIndex, viewport, scale, editModeOn);
        pageDiv.appendChild(layer);
        fitPersistedRichOverlayBoxesToContent(layer);
        markPageOverlaysReady(pageIndex);
        return;
    }

    const textLayerRect = textLayerEl.getBoundingClientRect();
    const spans = Array.from(textLayerEl.querySelectorAll('span'))
        .filter((span) => String(span.dataset.enpvOriginal || '').trim() !== '');
    if (!spans.length) {
        if (layer.childElementCount) pageDiv.appendChild(layer);
        markPageOverlaysReady(pageIndex);
        return;
    }
    if (spans.length > MAX_SOURCE_SPAN_BOXES_PER_PAGE) {
        if (layer.childElementCount) pageDiv.appendChild(layer);
        markPageOverlaysReady(pageIndex);
        return;
    }

    // Collect AcroForm widget rectangles (text/button/choice/signature
    // widgets render inside pageView.annotationLayer.div). We suppress
    // any text-layer-derived box that overlaps a widget so the editing
    // bounding box does not paint over interactive form fields.
    const widgetRects = [];
    const annLayerEl = pageView?.annotationLayer?.div
        || pageDiv.querySelector(':scope > .annotationLayer');
    if (annLayerEl) {
        const widgetEls = annLayerEl.querySelectorAll(
            '.textWidgetAnnotation, .buttonWidgetAnnotation, ' +
            '.choiceWidgetAnnotation, .signatureWidgetAnnotation'
        );
        for (const w of widgetEls) {
            const wr = w.getBoundingClientRect();
            if (wr.width <= 0 || wr.height <= 0) continue;
            widgetRects.push({
                left: wr.left - textLayerRect.left,
                top: wr.top - textLayerRect.top,
                right: wr.right - textLayerRect.left,
                bottom: wr.bottom - textLayerRect.top,
            });
        }
    }
    // Cache widget rects in PDF points for this page so postBboxMove can
    // forward them to the server, which uses them to lock acroform-field
    // text against being relocated.
    const widgetRectsPts = widgetRects.map((w) => ({
        x: w.left / scale,
        y: (Number(viewport.height) - w.bottom) / scale,
        w: (w.right - w.left) / scale,
        h: (w.bottom - w.top) / scale,
    }));
    widgetRectsPtsByPage.set(pageIndex, widgetRectsPts);
    persistedTextAnnotations.forEach((annotation) => {
        annotation._renderMatched = false;
    });
    function intersectsWidget(r) {
        for (const w of widgetRects) {
            const ix0 = Math.max(r.left, w.left);
            const iy0 = Math.max(r.top, w.top);
            const ix1 = Math.min(r.right, w.right);
            const iy1 = Math.min(r.bottom, w.bottom);
            // Any overlap at all: never paint a draggable box over an
            // acroform widget. Form fields are locked from being moved
            // so giving them a draggable box would be misleading.
            if (ix1 > ix0 && iy1 > iy0) return true;
        }
        return false;
    }

    for (const span of spans) {
        if (!spanHasVisibleCanvasInk(span, pageDiv)) continue;
        const spanRect = span.getBoundingClientRect();
        const rect = {
            left: spanRect.left - textLayerRect.left,
            top: spanRect.top - textLayerRect.top,
            width: spanRect.width,
            height: spanRect.height,
        };
        if (!rect || rect.width <= 0 || rect.height <= 0) continue;
        if (persistedOverlayRects.some((overlayRect) => rectsOverlap(rect, overlayRect))) continue;
        if (intersectsWidget({
            left: rect.left, top: rect.top,
            right: rect.left + rect.width, bottom: rect.top + rect.height,
        })) continue;

        const sourceBbox = {
            x: rect.left / scale,
            y: (Number(viewport.height) - (rect.top + rect.height)) / scale,
            w: rect.width / scale,
            h: rect.height / scale,
        };
        const uid = `${pageIndex}:${span.dataset.enpvIndex || span.dataset.enpvOccurrence || '0'}`;
        const matchedAnnotation = findPersistedAnnotationForSpan(
            pageIndex,
            sourceBbox,
            span.textContent || '',
            span.dataset.enpvOriginal || span.textContent || '',
            true,
            uid,
        );

        const offset = annotationOffsetsPts.get(uid);
        const handleUid = sourceHandleUid(pageIndex, uid, matchedAnnotation);
        const annotationId = sourceHandleAnnotationId(pageIndex, uid, matchedAnnotation);
        const baseRect = matchedAnnotation?._baselinePdfBox || sourceBbox;
        const baseText = String(matchedAnnotation?.pdfjsSourceText || matchedAnnotation?.originalText || span.dataset.enpvOriginal || span.textContent || '');

        const box = document.createElement('div');
        box.className = 'enpv-annotation-box';
        box.dataset.uid = handleUid;
        box.dataset.annotationId = annotationId;
        box.dataset.pageIndex = String(pageIndex);
        box.dataset.originalText = String(matchedAnnotation?.originalText || baseText);
        box.dataset.occurrence = span.dataset.enpvOccurrence || '0';
        box.dataset.sourceBboxX = String(sourceBbox.x);
        box.dataset.sourceBboxY = String(sourceBbox.y);
        box.dataset.sourceBboxW = String(sourceBbox.w);
        box.dataset.sourceBboxH = String(sourceBbox.h);
        box.dataset.baseBboxX = String(baseRect.x);
        box.dataset.baseBboxY = String(baseRect.y);
        box.dataset.baseBboxW = String(baseRect.w);
        box.dataset.baseBboxH = String(baseRect.h);
        box.dataset.basePageHeight = String(Number(matchedAnnotation?.pdfjsSourcePageHeight) || (Number(viewport.height) / scale));
        box.dataset.baseText = baseText;
        box.dataset.locked = matchedAnnotation?.locked ? '1' : '0';
        box.dataset.zIndex = String(Number(matchedAnnotation?.zIndex) || 2);
        box.dataset.renderScale = String(scale);
        if (boolish(matchedAnnotation?.styleDirty)) box.dataset.styleDirty = '1';
        const displayRect = sourceBoxDisplayRect(rect);
        box.style.left = `${rect.left}px`;
        box.style.top = `${rect.top}px`;
        box.style.width = `${Math.max(1, displayRect.width)}px`;
        box.style.height = `${Math.max(1, displayRect.height)}px`;
        box.style.zIndex = box.dataset.zIndex;
        box.classList.toggle('is-locked', matchedAnnotation?.locked === true);
        span.dataset.enpvPersistentId = annotationId;

        captureSourceSpanMetrics(box, span, spanRect, scale, pageDiv);
        if (matchedAnnotation?.fontFamily) box.style.setProperty('--enpv-font-family', matchedAnnotation.fontFamily);
        const fontSizePts = Number(matchedAnnotation?.fontSize);
        if (Number.isFinite(fontSizePts) && fontSizePts > 0) {
            box.dataset.fontSizePts = String(fontSizePts);
        }
        const matchedTextColor = cssColorToHex(
            (!boolish(matchedAnnotation?.styleDirty) && (matchedAnnotation?.pdfjsSourceTextColor || box.dataset.sourceTextColor))
            || matchedAnnotation?.textColor
            || matchedAnnotation?.color
            || '#000000',
        );
        box.style.setProperty('--enpv-text-color', matchedTextColor);

        // Inner contenteditable element — initially read-only (only
        // becomes editable when the box is selected) so single-clicks
        // still trigger drag / select rather than placing a caret.
        const tc = document.createElement('div');
        tc.className = 'enpv-text-content';
        tc.setAttribute('role', 'textbox');
        tc.setAttribute('aria-multiline', 'true');
        tc.contentEditable = 'false';
        tc.spellcheck = false;
        tc.textContent = String(matchedAnnotation?.text || span.textContent || span.dataset.enpvOriginal || '');
        box.appendChild(tc);
        if (matchedAnnotation?.userForcedRichText === true || matchedAnnotation?.pdfjsEditorMode === 'rich') {
            box.dataset.userForcedRichText = '1';
            setBoxEditorMode(box, 'rich');
        } else {
            setBoxEditorMode(box, editorModeForBox(box));
        }

        // Re-apply any session-tracked drag offset (kept in PDF points so
        // it survives zoom changes the same way legacy /edit-new keeps
        // pdfX/pdfY semantically correct across re-render).
        //
        // NOTE: dragging this overlay box does NOT relocate the underlying
        // PDF glyphs — pdf.js renders them into the page canvas which we
        // cannot reflow client-side. Actually moving the text needs a
        // server-side content-stream rewrite (see editPdfjsRewriteTj for
        // the analogous text-edit path). Until that is wired up the box
        // is a visual selection rectangle only; we deliberately do NOT
        // paint a white eraser over the source location because the page
        // background may not be white (form rules, shading, scans).
        if (offset) {
            box.style.transform = `translate(${offset.dx * scale}px, ${offset.dy * scale}px)`;
            box.dataset.dxPts = String(offset.dx);
            box.dataset.dyPts = String(offset.dy);
        }
        addEditableBoxChrome(box);
        layer.appendChild(box);
    }

    appendPromotedFallbackOverlays(layer, promotedFallbackTextAnnotations, pageIndex, viewport, scale, editModeOn);

    pageDiv.appendChild(layer);
    fitPersistedRichOverlayBoxesToContent(layer);
    markPageOverlaysReady(pageIndex);

    // Re-anchor the floating menu if the selected box belongs to this page
    // (it gets detached when the layer is removed/re-rendered, e.g. on zoom).
    if (selectedAnnBoxUid) {
        const reselected = layer.querySelector(`.enpv-annotation-box[data-uid="${cssEscape(selectedAnnBoxUid)}"]`);
        if (reselected) {
            reselected.classList.add('is-selected');
            positionAnnMenuOver(reselected);
            updateAnnotationFormatBarForBox(reselected);
            // Restore in-place edit mode if the user was mid-edit before
            // this layer got rebuilt (e.g. by a `pagerendered` from
            // pdf.js's streaming text-layer render).
            if (selectedAnnBoxIsEditing && !reselected.classList.contains('is-editing')) {
                beginEditMode(reselected);
            }
        }
    }
}

function pageHasCurrentAnnotationBoxLayer(pageIndex) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    const layer = pageDiv?.querySelector(':scope > .enpv-annotation-box-layer');
    if (!layer) return false;
    const pageView = pdfViewer.getPageView(pageIndex);
    const currentScale = Number(pageView?.viewport?.scale) || Number(pdfViewer.currentScale) || 1;
    const layerScale = Number.parseFloat(layer.dataset.scale || '0') || 0;
    const editMode = document.body.classList.contains('enpv-edit-on') ? '1' : '0';
    return Math.abs(layerScale - currentScale) <= 0.0001
        && String(layer.dataset.editMode || '0') === editMode
        && !pageDiv.classList.contains('enpv-overlays-pending');
}

function renderAllAnnotationBoxLayers(options = {}) {
    const preserveCurrent = options.preserveCurrent === true;
    for (let i = 0; i < pdfViewer.pagesCount; i += 1) {
        if (pageIsNearViewport(i)) {
            if (preserveCurrent && pageHasCurrentAnnotationBoxLayer(i)) continue;
            renderAnnotationBoxLayer(i);
        } else {
            removeAnnotationBoxLayer(i);
        }
    }
}

const OVERLAY_VIEWPORT_BUFFER_PX = 150;
let overlayRenderRaf = 0;

function pageIsNearViewport(pageIndex) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    if (!pageDiv) return false;
    const pageRect = pageDiv.getBoundingClientRect();
    const viewportRect = container.getBoundingClientRect();
    return pageRect.bottom >= viewportRect.top - OVERLAY_VIEWPORT_BUFFER_PX
        && pageRect.top <= viewportRect.bottom + OVERLAY_VIEWPORT_BUFFER_PX;
}

function scheduleRenderAllAnnotationBoxLayers() {
    if (overlayRenderRaf) return;
    overlayRenderRaf = requestAnimationFrame(() => {
        overlayRenderRaf = 0;
        renderAllAnnotationBoxLayers({ preserveCurrent: true });
    });
}

container.addEventListener('scroll', scheduleRenderAllAnnotationBoxLayers, { passive: true });

// ---- Annotation box drag / select ---------------------------------------
//
// Mirrors the legacy /edit-new drag affordance:
//   - Click an annotation box → it becomes selected; a small floating
//     hover menu (#enpv-ann-menu) appears anchored above it.
//   - Pointer-down on the box (or on the menu's grip) starts a drag.
//   - Window pointermove translates the box; the menu is hidden during
//     the drag so it does not follow the cursor and re-trigger hover
//     transitions every tick (matches legacy behaviour).
//   - On pointerup the box stays at its new position; the menu is
//     re-anchored above it.
//
// "Underlying PDF handled the same way" → no PDF stream mutation on
// drag, exactly like legacy. The original /Tj operators stay untouched;
// the move is a pure overlay reposition tracked in PDF points (so it
// survives zoom changes) on the in-session `annotationOffsetsPts` map.
const annotationOffsetsPts = new Map(); // uid -> { dx, dy } in PDF points
// Per-page AcroForm widget rectangles in PDF points. Sent with each
// move so the server can lock those Tj origins (form-field captions /
// glyphs that visually belong to the widget) against being shifted.
const widgetRectsPtsByPage = new Map(); // pageIndex -> [{x,y,w,h}, ...]
const annMenu = document.getElementById('enpv-ann-menu');
let selectedAnnBoxUid = null;
// True while the selected box is in in-place edit mode (.is-editing,
// contenteditable). pdf.js fires `pagerendered` repeatedly during
// streaming render and zoom, and each fire rebuilds the page's
// annotation-box layer — wiping the edit state. We track this flag so
// the re-anchor block in renderAnnotationBoxLayer can restore edit
// mode on the new box rather than silently dropping the user mid-type.
let selectedAnnBoxIsEditing = false;
let dragState = null;
// Signature feature handle — installed below once viewer is wired.
let signatureFeature = null;

function cssEscape(s) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
}

function findSelectedBox() {
    if (!selectedAnnBoxUid) return null;
    return document.querySelector(`.enpv-annotation-box.is-selected[data-uid="${cssEscape(selectedAnnBoxUid)}"]`);
}

function isAnnBoxLocked(box) {
    return box?.dataset?.locked === '1' || box?.classList?.contains('is-locked');
}

function formatBarAvailableOptions(select) {
    return Array.from(select?.options || [])
        .filter((option) => !option.disabled && String(option.value || '').trim());
}

function ensureFormatBarFontOption(value) {
    if (!afbFont) return '';
    const normalized = String(value || '').trim();
    if (!normalized) return '';
    const existing = formatBarAvailableOptions(afbFont)
        .find((option) => option.value.toLowerCase() === normalized.toLowerCase());
    if (existing) return existing.value;

    const option = document.createElement('option');
    option.value = normalized;
    option.textContent = normalized;
    option.dataset.pdfjsDynamic = '1';
    afbFont.insertBefore(option, afbFont.firstChild);
    return option.value;
}

function hideAnnotationFormatBar() {
    annFormatBar?.classList.remove('is-visible');
}

function selectedBoxFontFamily(box) {
    if (!box) return '';
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    return String(
        box.style.getPropertyValue('--enpv-font-family')
        || existing?.fontFamily
        || box.dataset.sourceFontFamily
        || 'Helvetica'
    ).trim();
}

function updateAnnotationFormatBarForBox(box) {
    if (!annFormatBar || !afbFont) return;
    const type = String(box?.dataset?.annotationType || 'text').toLowerCase();
    if (!box || type === 'signature' || type === 'image') {
        hideAnnotationFormatBar();
        return;
    }

    const value = ensureFormatBarFontOption(selectedBoxFontFamily(box));
    if (value) {
        afbFont.value = value;
    } else {
        const first = formatBarAvailableOptions(afbFont)[0];
        if (first) afbFont.value = first.value;
    }
    annFormatBar.classList.add('is-visible');
}

function applyFontFamilyToSelectedBox(fontFamily) {
    const box = findSelectedBox();
    const normalized = String(fontFamily || '').trim();
    if (!box || !normalized) return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        updateAnnotationFormatBarForBox(box);
        return;
    }

    pushHistorySnapshot('change annotation font');
    promoteBoxToRichTextMode(box, 'font-family');
    box.style.setProperty('--enpv-font-family', normalized);
    box.dataset.styleDirty = '1';
    box.dataset.pendingEdit = '1';
    syncAnnotationBoxToPersistedAnnotations(box, { preserveEditMode: true });
    updateAnnotationFormatBarForBox(box);
    markManualSaveNeeded();
}

function setAnnBoxLocked(box, locked) {
    if (!box) return;
    pushHistorySnapshot('lock annotation');
    box.dataset.locked = locked ? '1' : '0';
    box.classList.toggle('is-locked', locked);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const annotation = buildAnnotationFromBox(box, existing);
    if (annotation) upsertPersistedAnnotation(annotation);
    box.dataset.pendingEdit = '1';
    markManualSaveNeeded();
}

function refreshAnnMenuState(box) {
    if (!annMenu || !box) return;
    const locked = isAnnBoxLocked(box);
    const lockButton = annMenu.querySelector('[data-action="lock"]');
    if (lockButton) {
        lockButton.setAttribute('aria-pressed', locked ? 'true' : 'false');
        lockButton.title = locked ? 'Unlock annotation' : 'Lock annotation';
        lockButton.setAttribute('aria-label', locked ? 'Unlock annotation' : 'Lock annotation');
    }
}

function selectedBoxTextElement(box) {
    return box?.querySelector?.('.enpv-text-content') || null;
}

function syncMenuMutation(box) {
    if (!box) return;
    box.dataset.pendingEdit = '1';
    syncAnnotationBoxToPersistedAnnotations(box);
    box.classList.add('is-selected');
    selectedAnnBoxUid = box.dataset.uid || selectedAnnBoxUid;
    positionAnnMenuOver(box);
    markManualSaveNeeded();
}

function transformAnnBoxText(box, transformer) {
    if (!box || typeof transformer !== 'function') return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        return;
    }
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    const current = String(tc.innerText || tc.textContent || '');
    const next = transformer(current);
    if (next === current) return;
    pushHistorySnapshot('transform annotation text');
    tc.textContent = next;
    syncMenuMutation(box);
}

function setAnnBoxLayerOrder(box, direction) {
    if (!box) return;
    const layer = box.parentElement;
    if (!layer) return;
    pushHistorySnapshot('reorder annotation');
    const boxes = Array.from(layer.querySelectorAll('.enpv-annotation-box'));
    const zValues = boxes.map((item) => Number.parseInt(item.style.zIndex || item.dataset.zIndex || '2', 10) || 2);
    const nextZ = direction === 'front'
        ? Math.max(2, ...zValues) + 1
        : 2;
    box.style.zIndex = String(nextZ);
    box.dataset.zIndex = String(nextZ);
    if (direction === 'front') {
        layer.appendChild(box);
        if (annMenu?.parentElement === layer) layer.appendChild(annMenu);
    } else {
        const firstBox = boxes.find((item) => item !== box);
        if (firstBox) layer.insertBefore(box, firstBox);
    }
    syncMenuMutation(box);
}

function rememberDeletedAnnotation(box) {
    if (!box) return;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    [existing?.db_id, existing?.id, box.dataset.annotationId]
        .map((value) => String(value || '').trim())
        .filter(Boolean)
        .forEach((value) => pendingDeletedAnnotationIds.add(value));
}

function removeAnnBoxSourceMasks(box) {
    if (box?._enpvOrigMask) {
        box._enpvOrigMask.remove();
        box._enpvOrigMask = null;
    }
    if (box?._enpvSourceMask) {
        box._enpvSourceMask.remove();
        box._enpvSourceMask = null;
        delete box.dataset.sourceMaskAttached;
    }
}

function deleteAnnBox(box) {
    if (!box) return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        return;
    }
    pushHistorySnapshot('delete annotation');
    rememberDeletedAnnotation(box);
    const deletionMaskAnnotation = deletedMaskAnnotationFromBox(box);
    if (deletionMaskAnnotation) upsertPersistedAnnotation(deletionMaskAnnotation);
    if (box.dataset.annotationId) deletePersistedAnnotation(box.dataset.annotationId);
    if (box.dataset.uid) annotationOffsetsPts.delete(String(box.dataset.uid));
    if (box._enpvOrigMask) {
        box._enpvOrigMask.remove();
        box._enpvOrigMask = null;
    }
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    selectedAnnBoxUid = null;
    selectedAnnBoxIsEditing = false;
    box.remove();
    if (Number.isFinite(pageIndex) && pageIndex >= 0) renderAnnotationBoxLayer(pageIndex);
    markManualSaveNeeded();
}

function copyAnnBox(box) {
    if (!box) return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        return;
    }
    const layer = box.parentElement;
    if (!layer) return;
    pushHistorySnapshot('copy annotation');
    const copy = document.createElement('div');
    copy.className = 'enpv-annotation-box is-persisted-overlay';
    const uid = generateUuidV4();
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    copy.dataset.uid = uid;
    copy.dataset.annotationId = buildPdfjsAnnotationId(pageIndex, uid);
    copy.dataset.pageIndex = box.dataset.pageIndex || '0';
    copy.dataset.originalText = '';
    copy.dataset.occurrence = '0';
    copy.dataset.baseText = '';
    copy.dataset.fontSizePts = box.dataset.fontSizePts || '';
    copy.dataset.locked = '0';
    copy.dataset.zIndex = String((Number.parseInt(box.style.zIndex || box.dataset.zIndex || '2', 10) || 2) + 1);
    const left = (Number.parseFloat(box.style.left || '0') || 0) + 12;
    const top = (Number.parseFloat(box.style.top || '0') || 0) + 12;
    const width = Number.parseFloat(box.style.width || '') || box.offsetWidth || 1;
    const height = Number.parseFloat(box.style.height || '') || box.offsetHeight || 1;
    copy.style.left = `${left}px`;
    copy.style.top = `${top}px`;
    copy.style.width = `${Math.max(1, width)}px`;
    copy.style.height = `${Math.max(1, height)}px`;
    copy.style.zIndex = copy.dataset.zIndex;
    ['--enpv-font-family', '--enpv-font-size', '--enpv-font-weight', '--enpv-font-style', '--enpv-line-height', '--enpv-text-color']
        .forEach((name) => {
            const value = box.style.getPropertyValue(name);
            if (value) copy.style.setProperty(name, value);
        });
    const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
    copy.dataset.sourceBboxX = String(left / scale);
    copy.dataset.sourceBboxY = String(top / scale);
    copy.dataset.sourceBboxW = String(width / scale);
    copy.dataset.sourceBboxH = String(height / scale);
    copy.dataset.baseBboxX = copy.dataset.sourceBboxX;
    copy.dataset.baseBboxY = copy.dataset.sourceBboxY;
    copy.dataset.baseBboxW = copy.dataset.sourceBboxW;
    copy.dataset.baseBboxH = copy.dataset.sourceBboxH;
    copy.dataset.basePageHeight = box.dataset.basePageHeight || '';
    const tc = document.createElement('div');
    tc.className = 'enpv-text-content';
    tc.setAttribute('role', 'textbox');
    tc.setAttribute('aria-multiline', 'true');
    tc.contentEditable = 'false';
    tc.spellcheck = false;
    tc.textContent = String(selectedBoxTextElement(box)?.innerText || selectedBoxTextElement(box)?.textContent || '');
    copy.appendChild(tc);
    addEditableBoxChrome(copy);
    layer.appendChild(copy);
    selectAnnBox(copy);
    syncMenuMutation(copy);
}

function positionAnnMenuOver(box) {
    if (!annMenu || !box) return;
    const layer = box.parentElement;
    if (!layer) return;
    if (annMenu.parentElement !== layer) layer.appendChild(annMenu);
    const left = parseFloat(box.style.left) || 0;
    const top = parseFloat(box.style.top) || 0;
    const width = parseFloat(box.style.width) || box.offsetWidth;
    // Account for any in-flight transform translate (during/after drag).
    const dxPts = parseFloat(box.dataset.dxPts || '0') || 0;
    const dyPts = parseFloat(box.dataset.dyPts || '0') || 0;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    const dxPx = dxPts * scale;
    const dyPx = dyPts * scale;
    annMenu.hidden = false;
    const menuHeight = Math.max(annMenu.offsetHeight || 38, 32);
    const menuWidth = Math.max(annMenu.offsetWidth || 1, 1);
    const layerWidth = layer.clientWidth || pdfViewer.getPageView(Number.parseInt(box.dataset.pageIndex || '-1', 10))?.div?.clientWidth || 0;
    const gap = 4;
    const rawLeft = left + dxPx + (width / 2);
    const halfWidth = menuWidth / 2;
    const finalLeft = layerWidth > menuWidth
        ? Math.max(halfWidth + 4, Math.min(layerWidth - halfWidth - 4, rawLeft))
        : rawLeft;
    const menuTop = top + dyPx - menuHeight - gap;
    const minGutterTop = -(menuHeight + gap);
    const finalTop = Math.max(minGutterTop, menuTop);
    annMenu.style.left = `${finalLeft}px`;
    annMenu.style.top = `${finalTop}px`;
    annMenu.classList.toggle('is-below', false);
    annMenu.classList.toggle('is-top-gutter', finalTop < 0);
    refreshAnnMenuState(box);
}

function selectAnnBox(box) {
    if (!box) return;
    // If this box is already the selected one, do nothing — calling
    // deselect+select would tear down in-place edit mode (.is-editing,
    // contenteditable, focus) and trigger a commit, which we don't want
    // when the user is just starting a resize gesture on the same box.
    if (box.classList.contains('is-selected')) {
        return;
    }
    deselectAnnBox();
    selectedAnnBoxUid = box.dataset.uid || null;
    box.classList.add('is-selected');
    positionAnnMenuOver(box);
    updateAnnotationFormatBarForBox(box);
}

function onTextContentInput(ev) {
    const tc = ev.currentTarget;
    const box = tc.closest('.enpv-annotation-box');
    if (box) {
        attachSourceMaskForBox(box);
        if (editorModeForBox(box) === 'rich') {
            setBoxEditorMode(box, 'rich');
            fitRichTextBoxToContent(box);
        }
        box.dataset.pendingEdit = '1';
        markManualSaveNeeded();
    }
}

// Toggle a single annotation box into in-place edit mode. Triggered by
// the pencil button on the floating menu. The box stays transparent so
// editing never paints over form geometry; final export removes the source
// glyphs with text-only redaction in the writer.
function beginEditMode(box) {
    if (!box) return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        return;
    }
    if (box.dataset.annotationType === 'signature') {
        const ann = persistedAnnotationsById.get(String(box.dataset.annotationId || ''));
        if (ann && signatureFeature) {
            signatureFeature.openSignatureEditModalForAnnotation(ann);
        }
        return;
    }
    const preEditHeightPx = box.getBoundingClientRect().height
        || Number.parseFloat(box.style.height || '')
        || box.offsetHeight
        || 1;
    box.classList.add('is-editing');
    selectedAnnBoxIsEditing = true;
    attachSourceMaskForBox(box);
    // Allow vertical growth as the inner text wraps, but keep the
    // edit-mode minimum anchored to the original box height. If we set
    // min-height after `height:auto`, a temporarily wrapped single-line
    // title can measure as two lines and that whitespace gets committed.
    box.dataset.preEditHeight = `${preEditHeightPx}px`;
    box.style.minHeight = `${preEditHeightPx}px`;
    box.style.height = 'auto';

    const tc = box.querySelector('.enpv-text-content');
    if (tc) {
        tc.contentEditable = 'true';
        tc.dataset.preEdit = tc.innerText;
        tc.addEventListener('beforeinput', onTextContentBeforeInput);
        tc.addEventListener('paste', onTextContentPaste);
        tc.addEventListener('input', onTextContentInput);
        const originalText = String(tc.innerText || '');
        const hasNewline = /\r|\n/.test(originalText);
        const hasSourceMetrics = Boolean(box.dataset.sourceFontFamily || box.dataset.sourceTransform || box.dataset.sourceFontSizePx);
        const mode = editorModeForBox(box);
        setBoxEditorMode(box, mode);
        if (mode === 'source' && hasSourceMetrics && !hasNewline) {
            applySourceFidelityTypography(box, { editing: true });
        }
        // Auto-grow box WIDTH if our HTML rendering of the same text is
        // wider than the canvas-glyph rendering captured in the spanRect
        // (different font fallback / metrics make HTML reflow wider than
        // pdf.js's exact glyph layout). Without this, a single-line run
        // like "Real Estate Withholding Statement" wraps to 2 lines the
        // moment edit mode turns on. Only grow when the source text has
        // no explicit newlines — otherwise we'd un-wrap intentional
        // multi-line annotations.
        if (!hasNewline && !hasSourceMetrics) {
            // Measure the unwrapped HTML width by temporarily forcing
            // nowrap. Without this, scrollWidth equals clientWidth
            // because the CSS-default `white-space: normal` already
            // wrapped the text to fit the box.
            const prevWhiteSpace = tc.style.whiteSpace;
            tc.style.whiteSpace = 'nowrap';
            const naturalWidth = tc.scrollWidth;
            tc.style.whiteSpace = prevWhiteSpace;
            const overflow = naturalWidth - tc.clientWidth;
            if (overflow > 1) {
                const layer = box.parentElement;
                const layerWidth = layer ? layer.clientWidth : Number.POSITIVE_INFINITY;
                const currentLeft = parseFloat(box.style.left) || 0;
                const desiredWidth = naturalWidth + 4; // small buffer for caret
                const maxWidth = Math.max(0, layerWidth - currentLeft - 1);
                const newWidth = Math.min(desiredWidth, maxWidth);
                if (newWidth > parseFloat(box.style.width || '0')) {
                    box.dataset.preEditWidth = box.style.width || '';
                    box.style.width = `${newWidth}px`;
                    box.style.minHeight = box.dataset.preEditHeight || `${preEditHeightPx}px`;
                }
            }
        }
        // Focus and place caret at end so the user can start typing.
        tc.focus();
        try {
            const range = document.createRange();
            range.selectNodeContents(tc);
            range.collapse(false);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        } catch (_) { /* noop */ }
    }
}

function endEditMode(box) {
    if (!box) return;
    const tc = box.querySelector('.enpv-text-content');
    if (tc) {
        tc.removeEventListener('beforeinput', onTextContentBeforeInput);
        tc.removeEventListener('paste', onTextContentPaste);
        tc.removeEventListener('input', onTextContentInput);
        tc.contentEditable = 'false';
    }
    // Capture the auto-grown rendered height as the new explicit height
    // so commit sees the actual on-screen geometry of the wrapped text.
    if (box.style.height === 'auto') {
        const baselineHeight = Number.parseFloat(box.dataset.preEditHeight || '') || 0;
        const oldMinHeight = box.style.minHeight;
        box.style.minHeight = '';
        const contentHeight = Math.max(
            box.getBoundingClientRect().height || 0,
            box.offsetHeight || 0,
            tc?.scrollHeight || 0,
        );
        const nextHeight = Math.max(1, baselineHeight, Math.ceil(contentHeight));
        box.style.height = `${nextHeight}px`;
        box.style.minHeight = oldMinHeight;
    }
    if (editorModeForBox(box) === 'rich') {
        fitRichTextBoxToContent(box);
    }
    box.style.minHeight = '';
    delete box.dataset.preEditHeight;
    if (box._enpvOrigMask) {
        box._enpvOrigMask.remove();
        box._enpvOrigMask = null;
        delete box.dataset.maskAttached;
    }
    box.classList.remove('is-editing');
    selectedAnnBoxIsEditing = false;
}

function deselectAnnBox() {
    // Deselect is local-only: preserve edited text/geometry in memory so
    // zoom/scroll/page re-renders do not resurrect the original span, but
    // leave the explicit topbar Save button as the only server write.
    const cur = findSelectedBox();
    if (cur) {
        if (cur.classList.contains('is-editing') || cur.dataset.pendingEdit === '1' || cur.dataset.pendingResize === '1') {
            pushHistorySnapshot('commit annotation edit');
        }
        syncAnnotationBoxToPersistedAnnotations(cur);
    }
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    selectedAnnBoxUid = null;
    selectedAnnBoxIsEditing = false;
    document.querySelectorAll('.enpv-annotation-box.is-selected').forEach((b) => b.classList.remove('is-selected'));
}

function onAnnBoxPointerDown(ev) {
    if (ev.button !== 0) return;
    const box = ev.currentTarget;
    const isSignatureBox = box?.dataset?.annotationType === 'signature';
    if (!document.body.classList.contains('enpv-edit-on') && !isSignatureBox) return;
    // While the box is in in-place edit mode, suppress drag/select
    // entirely so the user can interact with the inner text without
    // accidentally moving the box. The user exits edit mode by clicking
    // outside the box (which fires the global outside-click handler →
    // deselect → endEditMode + commit) or pressing Escape.
    if (box.classList.contains('is-editing')) {
        if (ev.target?.classList?.contains('enpv-text-content')) {
            // Let caret/selection through to the contenteditable.
            return;
        }
        // Box chrome / padding click while editing: just keep focus on
        // the editable text — don't start a drag, don't deselect.
        ev.stopPropagation();
        ev.preventDefault();
        const tc = box.querySelector('.enpv-text-content');
        if (tc) tc.focus();
        return;
    }
    ev.stopPropagation();
    ev.preventDefault();
    selectAnnBox(box);
    if (isAnnBoxLocked(box)) return;
    beginAnnBoxDrag(box, ev);
}

let resizeState = null;

function onResizeHandlePointerDown(ev) {
    if (ev.button !== 0) return;
    ev.stopPropagation();
    ev.preventDefault();
    const handle = ev.currentTarget;
    const box = handle.parentElement;
    if (!box) return;
    const isSignatureBox = box.dataset.annotationType === 'signature';
    if (!document.body.classList.contains('enpv-edit-on') && !isSignatureBox) return;
    if (isAnnBoxLocked(box)) return;
    // Only call selectAnnBox if the box isn't already selected — it's
    // a no-op for the selected box now, but being explicit makes the
    // intent clear: a resize gesture must NEVER tear down in-place edit
    // mode on the box being resized.
    if (!box.classList.contains('is-selected')) {
        selectAnnBox(box);
    }
    prepareBoxForLiveResize(box);
    beginResize(box, handle.dataset.edge, ev);
}

function beginResize(box, edge, ev) {
    const layer = box.parentElement;
    if (!layer) return;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    // Snapshot starting geometry in CSS-px so the live update is just an
    // additive delta. Final commit converts to PDF points.
    resizeState = {
        box, layer, scale, edge,
        startClientX: ev.clientX,
        startClientY: ev.clientY,
        startLeft: parseFloat(box.style.left) || 0,
        startTop: parseFloat(box.style.top) || 0,
        startWidth: parseFloat(box.style.width) || box.offsetWidth,
        startHeight: parseFloat(box.style.height) || box.offsetHeight,
    };
    document.body.classList.add('enpv-resizing');
    if (annMenu) annMenu.hidden = true;
    try { box.setPointerCapture(ev.pointerId); } catch (_) {}
    window.addEventListener('pointermove', onResizePointerMove);
    window.addEventListener('pointerup', onResizePointerUp, { once: true });
    window.addEventListener('pointercancel', onResizePointerUp, { once: true });
}

function onResizePointerMove(ev) {
    if (!resizeState) return;
    const { box, edge, startClientX, startClientY,
            startLeft, startTop, startWidth, startHeight } = resizeState;
    const dx = ev.clientX - startClientX;
    const dy = ev.clientY - startClientY;
    const MIN = 12; // px
    let left = startLeft, top = startTop, w = startWidth, h = startHeight;
    if (edge === 'r') { w = Math.max(MIN, startWidth + dx); }
    else if (edge === 'l') {
        const nw = Math.max(MIN, startWidth - dx);
        left = startLeft + (startWidth - nw);
        w = nw;
    } else if (edge === 'b') { h = Math.max(MIN, startHeight + dy); }
    else if (edge === 't') {
        const nh = Math.max(MIN, startHeight - dy);
        top = startTop + (startHeight - nh);
        h = nh;
    }
    box.style.left = `${left}px`;
    box.style.top = `${top}px`;
    box.style.width = `${w}px`;
    box.style.height = `${h}px`;
    if (editorModeForBox(box) === 'rich' && (edge === 'l' || edge === 'r')) {
        fitRichTextBoxToContent(box, edge === 't' ? 'bottom' : 'top');
        left = parseFloat(box.style.left) || left;
        top = parseFloat(box.style.top) || top;
        w = parseFloat(box.style.width) || w;
        h = parseFloat(box.style.height) || h;
    }
    resizeState.endLeft = left;
    resizeState.endTop = top;
    resizeState.endWidth = w;
    resizeState.endHeight = h;
}

function onResizePointerUp() {
    if (!resizeState) return;
    document.body.classList.remove('enpv-resizing');
    const { box, edge, startLeft, startTop, startWidth, startHeight,
            endLeft, endTop, endWidth, endHeight } = resizeState;
    window.removeEventListener('pointermove', onResizePointerMove);
    resizeState = null;
    positionAnnMenuOver(box);
    if (endLeft == null) return; // no movement
    if (Math.abs(endLeft - startLeft) < 0.5
        && Math.abs(endTop - startTop) < 0.5
        && Math.abs(endWidth - startWidth) < 0.5
        && Math.abs(endHeight - startHeight) < 0.5) {
        return;
    }
    pushHistorySnapshot('resize annotation');
    if (editorModeForBox(box) === 'rich' && (edge === 'l' || edge === 'r')) {
        fitRichTextBoxToContent(box, endTop !== startTop ? 'bottom' : 'top');
    }
    // Keep the DOM as the edit source of truth, but immediately upsert the
    // resized geometry into the local save payload. The actual network save
    // still waits for the user's Save action.
    box.dataset.pendingResize = '1';
    box.dataset.preResizeLeft = String(startLeft);
    box.dataset.preResizeTop = String(startTop);
    box.dataset.preResizeWidth = String(startWidth);
    box.dataset.preResizeHeight = String(startHeight);
    syncAnnotationBoxToPersistedAnnotations(box, { preserveEditMode: true });
    markManualSaveNeeded();
}

// Find the textLayer span that originated this annotation box; we use
// it to pick up the actual font-family and font-size pdf.js applied,
// so DOM measurement uses the same metrics as the rendered glyphs.
function findOriginSpanForBox(box) {
    const uid = box.dataset.uid || '';
    const [pi, idx] = uid.split(':');
    let span = null;
    if (pi != null && idx != null) {
        span = document.querySelector(
            `.textLayer span[data-enpv-page="${pi}"][data-enpv-index="${idx}"]`,
        );
    }
    if (!span && pi != null) {
        const orig = box.dataset.originalText || '';
        const occ = box.dataset.occurrence || '0';
        const all = document.querySelectorAll(
            `.textLayer span[data-enpv-page="${pi}"]`,
        );
        for (const s of all) {
            if ((s.dataset.enpvOriginal || '') === orig
                && (s.dataset.enpvOccurrence || '0') === occ) { span = s; break; }
        }
    }
    return span;
}

// Greedy word-wrap by measuring with a real DOM span clone of the
// origin glyph run. Returns an array of {text, width_px}. The browser's
// text shaper does the work — no Python heuristics involved.
function wrapTextWithDom(text, originSpan, maxWidthPx) {
    if (!text || !originSpan) return [{ text: text || '', width_px: 0 }];
    const cs = window.getComputedStyle(originSpan);
    const meas = document.createElement('span');
    meas.style.position = 'absolute';
    meas.style.visibility = 'hidden';
    meas.style.whiteSpace = 'pre';
    meas.style.fontFamily = cs.fontFamily;
    meas.style.fontSize = cs.fontSize;
    meas.style.fontWeight = cs.fontWeight;
    meas.style.fontStyle = cs.fontStyle;
    meas.style.letterSpacing = cs.letterSpacing;
    meas.style.transform = cs.transform; // pdf.js sometimes scaleX()'s spans
    document.body.appendChild(meas);
    const measure = (s) => { meas.textContent = s; return meas.getBoundingClientRect().width; };

    const words = String(text).split(/\s+/).filter(Boolean);
    const lines = [];
    let cur = '';
    for (const w of words) {
        const cand = cur ? `${cur} ${w}` : w;
        if (!cur) {
            cur = cand;
            continue;
        }
        if (measure(cand) <= maxWidthPx) {
            cur = cand;
        } else {
            lines.push({ text: cur, width_px: measure(cur) });
            cur = w;
        }
    }
    if (cur) lines.push({ text: cur, width_px: measure(cur) });
    document.body.removeChild(meas);
    return lines;
}

// Roll back a stale pending resize (network failure, etc.).
function rollbackResize(box) {
    if (box.dataset.preResizeLeft) box.style.left = `${box.dataset.preResizeLeft}px`;
    if (box.dataset.preResizeTop) box.style.top = `${box.dataset.preResizeTop}px`;
    if (box.dataset.preResizeWidth) box.style.width = `${box.dataset.preResizeWidth}px`;
    if (box.dataset.preResizeHeight) box.style.height = `${box.dataset.preResizeHeight}px`;
}

// Read the visual line breaks the browser actually rendered inside the
// box (post-CSS-reflow). Walks the text node, advancing one character
// at a time and grouping by getClientRects() top — every distinct top
// is a new visual line. This gives us the EXACT lines the user sees,
// produced by HarfBuzz/the browser shaper, not a Python heuristic.
function readVisualLinesFromBox(tc) {
    const text = tc.innerText.replace(/\r\n?/g, '\n');
    if (!text) return [];
    // Walk every text node; for each character, find its client rect
    // top and append the character to the current line whenever the
    // top matches the previous one. Newline chars start a new line.
    const lines = [];
    let curLine = '';
    let curTop = null;
    const walker = document.createTreeWalker(tc, NodeFilter.SHOW_TEXT);
    const range = document.createRange();
    let node;
    while ((node = walker.nextNode())) {
        const s = node.nodeValue || '';
        for (let i = 0; i < s.length; i += 1) {
            const ch = s[i];
            if (ch === '\n') {
                lines.push(curLine);
                curLine = '';
                curTop = null;
                continue;
            }
            range.setStart(node, i);
            range.setEnd(node, i + 1);
            const rects = range.getClientRects();
            const top = rects.length ? Math.round(rects[0].top) : curTop;
            if (curTop != null && top != null && Math.abs(top - curTop) > 1) {
                lines.push(curLine);
                curLine = ch;
            } else {
                curLine += ch;
            }
            curTop = top;
        }
    }
    if (curLine) lines.push(curLine);
    return lines.map((l) => l.trim()).filter((l) => l.length > 0);
}

function commitPendingResize(box) {
    delete box.dataset.pendingResize;
    delete box.dataset.pendingEdit;
    const layer = box.parentElement;
    if (!layer) return;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    const pageIndex = parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = (Number.isFinite(pageIndex) && pageIndex >= 0)
        ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    if (!viewport) return;

    const left = parseFloat(box.style.left) || 0;
    const top = parseFloat(box.style.top) || 0;
    const width = parseFloat(box.style.width) || box.offsetWidth;
    const height = parseFloat(box.style.height) || box.offsetHeight;

    const newBboxPts = {
        x: left / scale,
        y: (Number(viewport.height) - (top + height)) / scale,
        w: width / scale,
        h: height / scale,
    };
    const sourceBbox = {
        x: parseFloat(box.dataset.sourceBboxX),
        y: parseFloat(box.dataset.sourceBboxY),
        w: parseFloat(box.dataset.sourceBboxW),
        h: parseFloat(box.dataset.sourceBboxH),
    };

    // Read what the browser actually rendered — these are the visual
    // line breaks produced by the browser's text shaper.
    const tc = box.querySelector('.enpv-text-content');
    const wrapped = tc ? readVisualLinesFromBox(tc) : [box.dataset.originalText || ''];
    const text = tc ? tc.innerText : (box.dataset.originalText || '');
    const fontSizePts = parseFloat(box.dataset.fontSizePts || 'NaN');

    postReflow({
        pageIndex,
        sourceBbox,
        newBbox: newBboxPts,
        originalText: box.dataset.originalText || '',
        lockRects: widgetRectsPtsByPage.get(pageIndex) || [],
        lines: wrapped.length ? wrapped : [text],
        fontSizePts: Number.isFinite(fontSizePts) && fontSizePts > 0 ? fontSizePts : undefined,
    }, box);
}

async function postReflow(req, box) {
    if (!REFLOW_URL) {
        showError('Reflow not configured');
        rollbackResize(box);
        return;
    }
    setStatus('Reflowing text…');
    try {
        const persistedAnnotation = box
            ? buildAnnotationFromBox(
                box,
                persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null,
            )
            : null;
        const reflows = [{
            page: req.pageIndex,
            source_bbox: req.sourceBbox,
            new_bbox: req.newBbox,
            original_text: req.originalText || undefined,
            lock_rects: req.lockRects || [],
            lines: req.lines || undefined,
            font_size_pts: req.fontSizePts || undefined,
        }];
        let r;
        if (currentPdfBytes) {
            const fd = new FormData();
            fd.append('pdf', new Blob([currentPdfBytes], { type: 'application/pdf' }), 'current.pdf');
            fd.append('reflows', JSON.stringify(reflows));
            r = await fetch(REFLOW_URL, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/pdf' },
                body: fd,
            });
        } else {
            r = await fetch(REFLOW_URL, {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF, Accept: 'application/pdf',
                },
                body: JSON.stringify({ reflows }),
            });
        }
        if (!r.ok) {
            let body = '';
            try { body = await r.text(); } catch (_) {}
            throw new Error(`Reflow failed (${r.status}): ${body.slice(0, 400)}`);
        }
        const buf = await r.arrayBuffer();
        if (box) {
            if (persistedAnnotation) {
                upsertPersistedAnnotation(persistedAnnotation);
            } else if (box.dataset.annotationId) {
                deletePersistedAnnotation(box.dataset.annotationId);
            }
        }
        await loadPdfFromBytes(buf);
        annotationOffsetsPts.clear();
        setStatus('Reflowed.');
    } catch (err) {
        console.error(err);
        showError(err.message);
        // Roll back visual resize using the snapshot stashed by onResizePointerUp.
        rollbackResize(box);
        setStatus('Reflow failed.', true);
    }
}

function beginAnnBoxDrag(box, ev) {
    const layer = box.parentElement;
    if (!layer) return;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    const startDxPts = parseFloat(box.dataset.dxPts || '0') || 0;
    const startDyPts = parseFloat(box.dataset.dyPts || '0') || 0;
    // Compute clamp limits in PDF points so the moved box stays inside
    // the page rect (with a 4pt margin). Without this, dragging off the
    // page edge produces source bounding boxes that no longer correspond
    // to any text-layer span on the next render — so a follow-up move
    // fails with "no text ops found inside bbox".
    const pageIndex = parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = (Number.isFinite(pageIndex) && pageIndex >= 0)
        ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    const pageWPts = viewport ? (Number(viewport.width) / scale) : null;
    const pageHPts = viewport ? (Number(viewport.height) / scale) : null;
    const margin = 4; // pts
    // Use the box's CURRENT visual geometry for clamps, not the
    // original `sourceBbox*` (which is fixed to the source text run
    // captured at creation). After a resize — e.g. user drags the left
    // edge in to make the box narrower+taller — the source bbox no
    // longer reflects what the user can see, and clamping against it
    // would refuse legitimate horizontal drags. The box's `style.left/
    // top/width/height` are kept up to date by `beginResize` /
    // `onResizePointerMove`, so they're the correct source of truth.
    const leftPx = parseFloat(box.style.left) || 0;
    const topPx = parseFloat(box.style.top) || 0;
    const widthPx = parseFloat(box.style.width) || box.offsetWidth || 0;
    const heightPx = parseFloat(box.style.height) || box.offsetHeight || 0;
    // Note: dxPts in pointermove is the absolute offset relative to
    // box.style.left (it is computed as `startDxPts + dxPx/scale` and
    // clamped against [dxMin, dxMax]). So the bounds are derived from
    // box.style.left/top alone — they do not include startDxPts.
    const visXPts = leftPx / scale;
    const visYPts = topPx / scale; // screen y-down pts (matches dyPts)
    const visWPts = widthPx / scale;
    const visHPts = heightPx / scale;
    let dxMin = -Infinity, dxMax = Infinity, dyMin = -Infinity, dyMax = Infinity;
    if (pageWPts && pageHPts && [visXPts, visYPts, visWPts, visHPts].every(Number.isFinite)) {
        // Keep the visual box inside [margin, page-margin] on both axes.
        // dx/dy are y-down screen-pt offsets relative to the box's
        // current `style.left/top` placement.
        dxMin = margin - visXPts;
        dxMax = (pageWPts - margin) - (visXPts + visWPts);
        dyMin = margin - visYPts;
        dyMax = (pageHPts - margin) - (visYPts + visHPts);
    }
    dragState = {
        box,
        layer,
        scale,
        startClientX: ev.clientX,
        startClientY: ev.clientY,
        startDxPts,
        startDyPts,
        dxMin, dxMax, dyMin, dyMax,
    };
    box.classList.add('is-dragging');
    document.body.classList.add('enpv-dragging');
    if (annMenu) annMenu.hidden = true;
    try { box.setPointerCapture(ev.pointerId); } catch (_) {}
    window.addEventListener('pointermove', onAnnBoxPointerMove);
    window.addEventListener('pointerup', onAnnBoxPointerUp, { once: true });
    window.addEventListener('pointercancel', onAnnBoxPointerUp, { once: true });
}

function onAnnBoxPointerMove(ev) {
    if (!dragState) return;
    const { scale, startClientX, startClientY, startDxPts, startDyPts,
            dxMin, dxMax, dyMin, dyMax } = dragState;
    const dxPx = ev.clientX - startClientX;
    const dyPx = ev.clientY - startClientY;
    let dxPts = startDxPts + (dxPx / scale);
    let dyPts = startDyPts + (dyPx / scale);
    // Clamp to keep the moved box inside the page rect.
    if (Number.isFinite(dxMin)) dxPts = Math.max(dxMin, Math.min(dxMax, dxPts));
    if (Number.isFinite(dyMin)) dyPts = Math.max(dyMin, Math.min(dyMax, dyPts));
    dragState.pendingDxPts = dxPts;
    dragState.pendingDyPts = dyPts;
    if (!dragState.rafScheduled) {
        dragState.rafScheduled = true;
        requestAnimationFrame(flushDragTransform);
    }
}

function flushDragTransform() {
    if (!dragState) return;
    dragState.rafScheduled = false;
    const { box, scale, pendingDxPts, pendingDyPts } = dragState;
    if (pendingDxPts == null || pendingDyPts == null) return;
    if (!dragState.promotedForMove
        && (Math.abs(pendingDxPts - dragState.startDxPts) > 0.25
            || Math.abs(pendingDyPts - dragState.startDyPts) > 0.25)) {
        promoteSourceBoxToMovedOverlay(box);
        dragState.promotedForMove = true;
    }
    // translate3d forces compositor-only updates (no layout/paint).
    box.style.transform = `translate3d(${pendingDxPts * scale}px, ${pendingDyPts * scale}px, 0)`;
}

function onAnnBoxPointerUp() {
    if (!dragState) return;
    const { box, scale, pendingDxPts, pendingDyPts } = dragState;
    box.classList.remove('is-dragging');
    document.body.classList.remove('enpv-dragging');
    const uid = box.dataset.uid;
    const pageIndex = parseInt(box.dataset.pageIndex || '-1', 10);
    // Commit the final pending offset (if any) into dataset for the
    // POST step below.
    const dxPts = pendingDxPts != null ? pendingDxPts : (parseFloat(box.dataset.dxPts || '0') || 0);
    const dyPts = pendingDyPts != null ? pendingDyPts : (parseFloat(box.dataset.dyPts || '0') || 0);
    const moved = Math.abs(dxPts - dragState.startDxPts) > 0.25 || Math.abs(dyPts - dragState.startDyPts) > 0.25;
    if (moved) pushHistorySnapshot('move annotation');
    box.dataset.dxPts = String(dxPts);
    box.dataset.dyPts = String(dyPts);
    box.style.transform = `translate3d(${dxPts * scale}px, ${dyPts * scale}px, 0)`;
    if (moved) {
        promoteSourceBoxToMovedOverlay(box);
        box.dataset.pendingEdit = '1';
        syncAnnotationBoxToPersistedAnnotations(box);
        box.classList.add('is-selected');
    }
    if (uid && moved) {
        if (Math.abs(dxPts) < 0.01 && Math.abs(dyPts) < 0.01) {
            annotationOffsetsPts.delete(uid);
        } else {
            annotationOffsetsPts.set(uid, { dx: dxPts, dy: dyPts });
        }
    }
    window.removeEventListener('pointermove', onAnnBoxPointerMove);
    dragState = null;
    positionAnnMenuOver(box);
    if (moved) markManualSaveNeeded();

}

async function postBboxMove(target, pageIndex, dxPts, dyPtsPdf, box) {
    if (!MOVE_URL) return;
    setStatus('Moving text…');
    try {
        const persistedAnnotation = box
            ? buildAnnotationFromBox(
                box,
                persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null,
            )
            : null;
        const movesPayload = [{
            page: pageIndex,
            source_bbox: {
                x: target.sourceBbox.x,
                y: target.sourceBbox.y,
                w: target.sourceBbox.w,
                h: target.sourceBbox.h,
            },
            // When supplied, the server only shifts Tj's whose decoded
            // text matches this string — prevents adjacent form-field
            // captions or text moved on top of one another from being
            // swept along by the bbox match.
            original_text: target.originalText || undefined,
            // AcroForm widget rects on this page (PDF points). Server
            // skips any Tj whose origin lies inside one of these so
            // form-field labels/glyphs are locked from being moved.
            lock_rects: widgetRectsPtsByPage.get(pageIndex) || [],
            dx_pts: dxPts,
            dy_pts: dyPtsPdf,
        }];

        let r;
        if (currentPdfBytes) {
            // Multipart upload: server applies the move to the *current*
            // PDF state, not the pristine original. Required so chained
            // moves don't all reference the original bytes.
            const fd = new FormData();
            fd.append('pdf', new Blob([currentPdfBytes], { type: 'application/pdf' }), 'current.pdf');
            fd.append('moves', JSON.stringify(movesPayload));
            r = await fetch(MOVE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/pdf' },
                body: fd,
            });
        } else {
            r = await fetch(MOVE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    Accept: 'application/pdf',
                },
                body: JSON.stringify({ moves: movesPayload }),
            });
        }
        if (!r.ok) {
            let body = '';
            try { body = await r.text(); } catch (_) {}
            throw new Error(`Move failed (${r.status}): ${body.slice(0, 400)}`);
        }
        const buf = await r.arrayBuffer();
        if (target.uid) annotationOffsetsPts.delete(String(target.uid));
        if (box) {
            if (persistedAnnotation) {
                upsertPersistedAnnotation(persistedAnnotation);
            } else if (box.dataset.annotationId) {
                deletePersistedAnnotation(box.dataset.annotationId);
            }
        }
        await loadPdfFromBytes(buf);
        setStatus('Move applied.');
    } catch (err) {
        console.error(err);
        showError(err.message);
        if (target.uid) annotationOffsetsPts.delete(String(target.uid));
        if (box) {
            box.style.transform = '';
            box.dataset.dxPts = '0';
            box.dataset.dyPts = '0';
        }
        setStatus('Move failed.', true);
    }
}

// Pick the occurrence index (among matching textLayer spans on this page)
// closest to the annotation's source bounding box. Falls back to 0 if the
// page's textLayer has not been wired yet or no match is found.
function computeAnnotationOccurrence(ann, pageIndex, text) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!layerEl) return 0;
    const spans = Array.from(layerEl.querySelectorAll('span'))
        .filter((s) => s.dataset.enpvOriginal === text);
    if (spans.length <= 1) return 0;
    const sBox = ann._originalBox;
    if (!sBox) return 0;
    const viewport = pageView.viewport;
    const scale = Number(viewport?.scale) || 1;
    const canvasHeight = Number(viewport?.height) || 0;
    const targetLeft = sBox.x * scale;
    const targetTop = canvasHeight - (sBox.y + sBox.h) * scale;
    const layerRect = layerEl.getBoundingClientRect();
    let bestOcc = 0;
    let bestDist = Infinity;
    for (const span of spans) {
        const r = span.getBoundingClientRect();
        const sl = r.left - layerRect.left;
        const st = r.top - layerRect.top;
        const dx = sl - targetLeft;
        const dy = st - targetTop;
        const d = (dx * dx) + (dy * dy);
        if (d < bestDist) {
            bestDist = d;
            bestOcc = parseInt(span.dataset.enpvOccurrence || '0', 10) || 0;
        }
    }
    return bestOcc;
}

if (annMenu) {
    annMenu.addEventListener('pointerdown', (ev) => {
        const action = ev.target?.closest('[data-action]')?.dataset?.action;
        const box = findSelectedBox();
        if (action === 'move') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) {
                if (isAnnBoxLocked(box)) setStatus('Annotation is locked.', true);
                else beginAnnBoxDrag(box, ev);
            }
        } else if (action === 'lock') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) {
                setAnnBoxLocked(box, !isAnnBoxLocked(box));
                positionAnnMenuOver(box);
            }
        } else if (action === 'front') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) setAnnBoxLayerOrder(box, 'front');
        } else if (action === 'back') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) setAnnBoxLayerOrder(box, 'back');
        } else if (action === 'edit') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) beginEditMode(box);
        } else if (action === 'uppercase') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) transformAnnBoxText(box, (text) => text.toUpperCase());
        } else if (action === 'lowercase') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) transformAnnBoxText(box, (text) => text.toLowerCase());
        } else if (action === 'copy') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) copyAnnBox(box);
        } else if (action === 'delete') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) deleteAnnBox(box);
        } else if (action === 'deselect') {
            ev.stopPropagation();
            ev.preventDefault();
            deselectAnnBox();
        }
    });
}

// Click outside any box deselects.
window.addEventListener('pointerdown', (ev) => {
    if (dragState) return;
    if (ev.target?.closest('.enpv-annotation-box')) return;
    if (ev.target?.closest('.enpv-ann-menu')) return;
    if (ev.target?.closest('#save-btn')) return;
    if (ev.target?.closest('#undo-btn')) return;
    if (ev.target?.closest('#redo-btn')) return;
    if (ev.target?.closest('#ann-format-bar')) return;
    deselectAnnBox();
});

window.addEventListener('keydown', (ev) => {
    const key = String(ev.key || '').toLowerCase();
    const isHistoryShortcut = (ev.ctrlKey || ev.metaKey) && !ev.altKey
        && (key === 'z' || key === 'y');
    if (isHistoryShortcut) {
        const active = document.activeElement;
        if (active?.isContentEditable || active?.closest?.('[contenteditable="true"]')
            || active?.tagName === 'INPUT' || active?.tagName === 'TEXTAREA') {
            return;
        }
        ev.preventDefault();
        if (key === 'z' && ev.shiftKey) redoHistory();
        else if (key === 'z') undoHistory();
        else if (key === 'y') redoHistory();
        return;
    }
    if (ev.key === 'Escape' && selectedAnnBoxUid && !dragState) deselectAnnBox();
    if (ev.key === 'Delete' && selectedAnnBoxUid && !dragState) {
        const active = document.activeElement;
        if (active?.isContentEditable || active?.closest?.('[contenteditable="true"]')) return;
        const box = findSelectedBox();
        if (box) {
            ev.preventDefault();
            deleteAnnBox(box);
        }
    }
});

function onSpanClick(ev) {
    // Click-to-edit is gated behind the legacy "Edit PDF" toggle in the
    // topbar (#edit-mode-toggle). When edit mode is off the textLayer
    // behaves like the stock pdf.js viewer (selectable, no edit hooks).
    if (!document.body.classList.contains('enpv-edit-on')) return;
    const sel = window.getSelection();
    if (sel && sel.toString().length > 0) return;
    ev.stopPropagation();
    ev.preventDefault();
    const box = createAnnotationBoxFromSpan(ev.currentTarget);
    if (box) {
        selectAnnBox(box);
    } else {
        showError('Could not create an annotation box for this text run.');
    }
}

// Double-click on a textLayer span: create the annotation box for that
// run AND immediately enter in-place edit mode, skipping the
// select-then-Edit-menu step. Lets the user start typing the moment they
// double-click anywhere in the rendered PDF text while Edit PDF is on.
function onSpanDblClick(ev) {
    if (!document.body.classList.contains('enpv-edit-on')) return;
    const sel = window.getSelection();
    // dblclick natively selects a word; clear that selection so the
    // contenteditable doesn't inherit it and replace the word on first
    // keystroke.
    if (sel && sel.removeAllRanges) sel.removeAllRanges();
    ev.stopPropagation();
    ev.preventDefault();
    const box = createAnnotationBoxFromSpan(ev.currentTarget);
    if (!box) {
        showError('Could not create an annotation box for this text run.');
        return;
    }
    selectAnnBox(box);
    beginEditMode(box);
}

// Double-click on an existing annotation box (persisted overlay or the
// on-demand box from a single click) → enter in-place edit mode.
function onAnnBoxDblClick(ev) {
    if (!document.body.classList.contains('enpv-edit-on')) return;
    const box = ev.currentTarget;
    if (!box || box.classList.contains('is-editing')) return;
    ev.stopPropagation();
    ev.preventDefault();
    selectAnnBox(box);
    beginEditMode(box);
}

function openEditorForBox(box) {
    if (!box) return;
    // uid is `${pageIndex}:${enpvIndex}` — locate the textLayer span
    // that originated this annotation box and open the inline editor
    // over it. Falls back to matching by (page, original_text, occurrence)
    // if textLayer indices have drifted after a re-render.
    const uid = box.dataset.uid || '';
    const [pi, idx] = uid.split(':');
    let span = null;
    if (pi != null && idx != null) {
        span = document.querySelector(
            `.textLayer span[data-enpv-page="${pi}"][data-enpv-index="${idx}"]`,
        );
    }
    if (!span && pi != null) {
        const orig = box.dataset.originalText || '';
        const occ = box.dataset.occurrence || '0';
        const all = document.querySelectorAll(
            `.textLayer span[data-enpv-page="${pi}"]`,
        );
        for (const s of all) {
            if ((s.dataset.enpvOriginal || '') === orig
                && (s.dataset.enpvOccurrence || '0') === occ) {
                span = s; break;
            }
        }
    }
    if (!span) {
        showError('Could not locate the source text run for this annotation.');
        return;
    }
    deselectAnnBox();
    openEditor(span);
}

function openEditor(spanEl) {
    closeEditor();
    activeRun = spanEl;
    spanEl.classList.add('enpv-editing');

    const original = spanEl.dataset.enpvOriginal || spanEl.textContent;
    const pageNum = parseInt(spanEl.dataset.enpvPage, 10) + 1;
    editOrig.textContent = `was: "${original}" — page ${pageNum}`;
    editInput.value = original;

    if (editBar.parentElement !== container) container.appendChild(editBar);

    const containerRect = container.getBoundingClientRect();
    const spanRect = spanEl.getBoundingClientRect();
    editBar.hidden = false;
    const left = spanRect.left - containerRect.left + container.scrollLeft;
    const top = spanRect.bottom - containerRect.top + container.scrollTop + 4;
    editBar.style.left = `${Math.max(8, left)}px`;
    editBar.style.top = `${top}px`;

    editInput.focus();
    editInput.select();
}

function closeEditor() {
    if (activeRun) activeRun.classList.remove('enpv-editing');
    activeRun = null;
    editBar.hidden = true;
}

editCancel.addEventListener('click', closeEditor);
editInput.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeEditor();
    else if (ev.key === 'Enter') applyEdit();
});
undoButton?.addEventListener('click', () => undoHistory());
redoButton?.addEventListener('click', () => redoHistory());
afbFont?.addEventListener('change', () => {
    applyFontFamilyToSelectedBox(afbFont.value);
});
updateHistoryButtons();

function buildSourceRewriteEditForBox(box, annotation, previousText) {
    if (!REWRITE_URL || !box || !annotation) return null;
    if (box.dataset.sourceRewriteInFlight === '1') return null;
    if (editorModeForBox(box) !== 'source') return null;
    if (box.dataset.userForcedRichText === '1') return null;
    const dxPts = Number.parseFloat(box.dataset.dxPts || '0') || 0;
    const dyPts = Number.parseFloat(box.dataset.dyPts || '0') || 0;
    if (Math.abs(dxPts) > 0.01 || Math.abs(dyPts) > 0.01) return null;
    const tc = selectedBoxTextElement(box);
    const newText = String(tc?.innerText ?? tc?.textContent ?? annotation.text ?? '');
    const originalText = String(previousText ?? '');
    if (!originalText || newText === originalText) return null;
    if (/\r|\n/.test(originalText) || /\r|\n/.test(newText)) return null;
    const page = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (!Number.isFinite(page) || page < 0) return null;
    const occurrence = Number.parseInt(box.dataset.occurrence || annotation.pdfjsSourceOccurrence || '0', 10);
    return {
        page,
        original_text: originalText,
        new_text: newText,
        occurrence: Number.isFinite(occurrence) && occurrence >= 0 ? occurrence : 0,
        source_bbox: {
            x: Number(annotation.pdfjsSourceX ?? box.dataset.sourceBboxX ?? box.dataset.baseBboxX ?? 0),
            y: Number(annotation.pdfjsSourceY ?? box.dataset.sourceBboxY ?? box.dataset.baseBboxY ?? 0),
            w: Number(annotation.pdfjsSourceW ?? box.dataset.sourceBboxW ?? box.dataset.baseBboxW ?? 0),
            h: Number(annotation.pdfjsSourceH ?? box.dataset.sourceBboxH ?? box.dataset.baseBboxH ?? 0),
        },
    };
}

async function postPdfjsTextRewrite(edits) {
    if (!REWRITE_URL) throw new Error('Rewrite endpoint not configured.');
    if (currentPdfBytes) {
        const fd = new FormData();
        fd.append('pdf', new Blob([currentPdfBytes], { type: 'application/pdf' }), 'current.pdf');
        fd.append('edits', JSON.stringify(edits));
        return fetch(REWRITE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/pdf' },
            body: fd,
        });
    }
    return fetch(REWRITE_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            Accept: 'application/pdf',
        },
        body: JSON.stringify({ edits }),
    });
}

async function postPdfjsSourceRedaction(edits) {
    if (!REDACT_URL) throw new Error('Source redaction endpoint not configured.');
    if (currentPdfBytes) {
        const fd = new FormData();
        fd.append('pdf', new Blob([currentPdfBytes], { type: 'application/pdf' }), 'current.pdf');
        fd.append('edits', JSON.stringify(edits));
        return fetch(REDACT_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/pdf' },
            body: fd,
        });
    }
    return fetch(REDACT_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            Accept: 'application/pdf',
        },
        body: JSON.stringify({ edits }),
    });
}

async function redactSourceTextForAnnotations(entries) {
    const usable = entries.filter((entry) => entry?.annotation && entry?.edit);
    if (!usable.length) return false;
    setStatus('Removing original source text…');
    const r = await postPdfjsSourceRedaction(usable.map((entry) => entry.edit));
    if (!r.ok) {
        let body = '';
        try { body = await r.text(); } catch (_) {}
        throw new Error(`Source text redaction failed (${r.status}): ${body.slice(0, 400)}`);
    }
    const buf = await r.arrayBuffer();
    usable.forEach((entry) => markAnnotationSourceRedacted(String(entry.annotation.id || '')));
    await loadPdfFromBytes(buf);
    setDownloadButtonsDisabled(false, 'Download PDF');
    setStatus('Original source text removed.');
    return true;
}

async function rewriteSourceAnnotationBoxText(annotationId, edit) {
    const current = annotationId ? persistedAnnotationsById.get(annotationId) : null;
    if (current?._pdfjsSourceRedacted === true) return;
    if (current?._pdfjsCanvasRewritten === true && String(current._pdfjsCanvasText || '') === String(edit.new_text || '')) {
        return;
    }
    setStatus('Applying source text…');
    const r = await postPdfjsTextRewrite([edit]);
    if (!r.ok) {
        if (current) {
            await redactSourceTextForAnnotations([{ annotation: current, edit }]);
            return;
        }
        let body = '';
        try { body = await r.text(); } catch (_) {}
        throw new Error(`Server rewrite failed (${r.status}): ${body.slice(0, 400)}`);
    }
    const buf = await r.arrayBuffer();
    pendingEditCount += 1;
    markAnnotationCanvasRewritten(annotationId, edit.new_text);
    await loadPdfFromBytes(buf);
    setDownloadButtonsDisabled(false, 'Download PDF');
    setStatus(`Applied ${pendingEditCount} edit${pendingEditCount === 1 ? '' : 's'}.`);
}

function buildPersistedSourceRewriteEdit(annotation) {
    if (!annotation || annotation._pdfjsCanvasRewritten === true) return null;
    if (annotation._pdfjsSourceRedacted === true) return null;
    if (String(annotation.type || '').toLowerCase() !== 'text') return null;
    if (annotation.pdfjsDeleted === true || isPromotedExtractionAnnotation(annotation)) return null;
    if (annotation.userForcedRichText === true || annotation.pdfjsEditorMode === 'rich') return null;
    const sourceText = String(annotation.pdfjsSourceText || annotation.originalText || '');
    const newText = String(annotation.text || '');
    if (!sourceText || !newText || sourceText === newText) return null;
    if (/\r|\n/.test(sourceText) || /\r|\n/.test(newText)) return null;
    const baseline = annotationBaselinePdfBox(annotation);
    const current = annotationCurrentPdfBox(annotation);
    if (!baseline || !current) return null;
    const sameGeometry = approxEqual(current.x, baseline.x)
        && approxEqual(current.y, baseline.y)
        && approxEqual(current.w, baseline.w)
        && approxEqual(current.h, baseline.h);
    if (!sameGeometry) return null;
    const page = annotationPageIndex(annotation);
    if (!Number.isFinite(page) || page < 0) return null;
    const occurrence = Number.parseInt(annotation.pdfjsSourceOccurrence || '0', 10);
    return {
        page,
        original_text: sourceText,
        new_text: newText,
        occurrence: Number.isFinite(occurrence) && occurrence >= 0 ? occurrence : 0,
        source_bbox: {
            x: Number(annotation.pdfjsSourceX ?? baseline.x),
            y: Number(annotation.pdfjsSourceY ?? baseline.y),
            w: Number(annotation.pdfjsSourceW ?? baseline.w),
            h: Number(annotation.pdfjsSourceH ?? baseline.h),
        },
    };
}

function buildPersistedSourceRedactionEdit(annotation) {
    if (!annotation || annotation._pdfjsCanvasRewritten === true || annotation._pdfjsSourceRedacted === true) return null;
    if (String(annotation.type || '').toLowerCase() !== 'text') return null;
    const isDeletedSource = boolish(annotation.pdfjsDeleted);
    if (!isDeletedSource) return null;
    if (!isDeletedSource && isPromotedExtractionAnnotation(annotation)) return null;
    if (!boolish(annotation.savedTextOverlay) && !isDeletedSource) return null;
    if (!isDeletedSource && isRedundantPdfjsSourceOverlay(annotation)) return null;
    const sourceText = String(annotation.pdfjsSourceText || annotation.originalText || '').trim();
    if (!sourceText) return null;
    const baseline = annotationBaselinePdfBox(annotation);
    if (!baseline) return null;
    const page = annotationPageIndex(annotation);
    if (!Number.isFinite(page) || page < 0) return null;
    const occurrence = Number.parseInt(annotation.pdfjsSourceOccurrence || '0', 10);
    const bbox = isDeletedSource
        ? deletedSourceRedactionBbox(annotation)
        : {
            x: Number(annotation.pdfjsSourceX ?? baseline.x),
            y: Number(annotation.pdfjsSourceY ?? baseline.y),
            w: Number(annotation.pdfjsSourceW ?? baseline.w),
            h: Number(annotation.pdfjsSourceH ?? baseline.h),
        };
    if (!bbox) return null;
    return {
        page,
        original_text: sourceText,
        occurrence: Number.isFinite(occurrence) && occurrence >= 0 ? occurrence : 0,
        source_bbox: bbox,
        redact_bbox_only: false,
        remove_graphics: false,
        fill_white: isDeletedSource,
    };
}

async function rewritePersistedSourceAnnotationsToCanvas() {
    const redactionEntries = Array.from(persistedAnnotationsById.values())
        .map((annotation) => ({ annotation, edit: buildPersistedSourceRedactionEdit(annotation) }))
        .filter((entry) => entry.edit);
    if (redactionEntries.length) {
        await redactSourceTextForAnnotations(redactionEntries);
        return true;
    }
    const entries = Array.from(persistedAnnotationsById.values())
        .map((annotation) => ({ annotation, edit: buildPersistedSourceRewriteEdit(annotation) }))
        .filter((entry) => entry.edit);
    if (!entries.length) return false;
    setStatus('Applying saved source text…');
    const r = await postPdfjsTextRewrite(entries.map((entry) => entry.edit));
    if (!r.ok) {
        await redactSourceTextForAnnotations(entries);
        return true;
    }
    const buf = await r.arrayBuffer();
    entries.forEach((entry) => markAnnotationCanvasRewritten(String(entry.annotation.id || ''), entry.edit.new_text));
    await loadPdfFromBytes(buf);
    return true;
}

async function applyEdit() {
    if (!activeRun) return;
    const newText = editInput.value;
    const original = activeRun.dataset.enpvOriginal;
    if (newText === original) { closeEditor(); return; }
    const existingAnnotation = (() => {
        const pageIndex = parseInt(activeRun.dataset.enpvPage || '-1', 10);
        const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
        const viewport = pageView?.viewport;
        const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
        if (!viewport || !layerEl) {
            const persistentId = activeRun.dataset.enpvPersistentId;
            return persistentId ? (persistedAnnotationsById.get(String(persistentId)) || null) : null;
        }
        const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
        const layerRect = layerEl.getBoundingClientRect();
        const spanRect = activeRun.getBoundingClientRect();
        const currentRect = {
            x: (spanRect.left - layerRect.left) / scale,
            y: (Number(viewport.height) - ((spanRect.top - layerRect.top) + spanRect.height)) / scale,
            w: spanRect.width / scale,
            h: spanRect.height / scale,
        };
        const persistentId = activeRun.dataset.enpvPersistentId;
        if (persistentId) return persistedAnnotationsById.get(String(persistentId)) || null;
        const sourceUid = `${pageIndex}:${activeRun.dataset.enpvIndex || activeRun.dataset.enpvOccurrence || '0'}`;
        return findPersistedAnnotationForSpan(pageIndex, currentRect, original, original, false, sourceUid);
    })();
    const edit = {
        page: parseInt(activeRun.dataset.enpvPage, 10),
        original_text: original,
        new_text: newText,
        occurrence: parseInt(activeRun.dataset.enpvOccurrence, 10),
        source_bbox: sourceBboxForSpan(activeRun) || undefined,
    };
    setStatus('Applying edit…');
    editApply.disabled = true;
    try {
        const r = await postPdfjsTextRewrite([edit]);
        if (!r.ok) {
            const fallbackAnnotation = buildAnnotationFromSpan(activeRun, newText, existingAnnotation);
            if (fallbackAnnotation) {
                upsertPersistedAnnotation(fallbackAnnotation);
                await redactSourceTextForAnnotations([{ annotation: fallbackAnnotation, edit }]);
                pendingEditCount += 1;
                closeEditor();
                setStatus(`Applied ${pendingEditCount} edit${pendingEditCount === 1 ? '' : 's'}.`);
                return;
            }
            let body = '';
            try { body = await r.text(); } catch (_) {}
            throw new Error(`Server rewrite failed (${r.status}): ${body.slice(0, 400)}`);
        }
        const buf = await r.arrayBuffer();
        pendingEditCount += 1;
        const persistedAnnotation = buildAnnotationFromSpan(activeRun, newText, existingAnnotation);
        closeEditor();
        if (persistedAnnotation) {
            persistedAnnotation._pdfjsCanvasRewritten = true;
            persistedAnnotation._pdfjsCanvasText = newText;
            upsertPersistedAnnotation(persistedAnnotation);
        } else if (existingAnnotation?.id) {
            deletePersistedAnnotation(existingAnnotation.id);
        }
        await loadPdfFromBytes(buf);
        setDownloadButtonsDisabled(false, 'Download PDF');
        setStatus(`Applied ${pendingEditCount} edit${pendingEditCount === 1 ? '' : 's'}.`);
    } catch (err) {
        console.error(err);
        showError(err.message);
        setStatus('Edit failed.', true);
    } finally {
        editApply.disabled = false;
    }
}
editApply.addEventListener('click', applyEdit);

async function buildPdfjsDownloadPayload() {
    syncSelectedBoxToPersistedAnnotations();
    syncDirtyBoxesToPersistedAnnotations();
    const sessionAnnotationsPayload = Array.from(persistedAnnotationsById.values())
        .filter((annotation) => !isRedundantPdfjsSourceOverlay(annotation))
        .map((annotation) => stripTransientAnnotationFields(annotation))
        .filter(Boolean);
    const visibleAnnotationsPayload = sessionAnnotationsPayload
        .filter((annotation) => shouldIncludeInPdfjsVisibleExport(annotation));
    const acroPayload = await collectAcroFormEntriesForSave().catch((err) => {
        console.warn('Failed to collect AcroForm entries for download', err);
        return Array.isArray(acroFormEntries) ? acroFormEntries : [];
    });
    return {
        annotations: visibleAnnotationsPayload,
        session_annotations: sessionAnnotationsPayload,
        acro_form_entries: acroPayload,
        deleted_promoted_source_keys: Array.from(pendingDeletedAnnotationIds),
        use_exact_download_path: true,
        use_pdfjs_visible_export: true,
        session_id: getSessionId(),
    };
}

async function downloadStampedPdf() {
    if (!DOWNLOAD_URL || !currentPdfDoc) return;
    const popup = window.open('', '_blank');
    if (popup) {
        popup.document.write('<!DOCTYPE html><title>Preparing PDF</title><body style="font-family:system-ui,sans-serif;padding:24px;color:#111827;">Preparing PDF...</body>');
        popup.document.close();
    }
    try {
        setDownloadButtonsDisabled(true, 'Preparing PDF...');
        setStatus('Preparing PDF...');
        const payload = await buildPdfjsDownloadPayload();
        const response = await fetch(DOWNLOAD_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                'X-CSRF-TOKEN': CSRF,
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            let message = `PDF generation failed (${response.status})`;
            const contentType = String(response.headers.get('content-type') || '');
            if (contentType.includes('application/json')) {
                const result = await response.json().catch(() => ({}));
                message = result?.message || result?.error || message;
            } else {
                const text = await response.text().catch(() => '');
                if (text) message = text.slice(0, 500);
            }
            throw new Error(message);
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        if (popup && !popup.closed) {
            popup.location.replace(url);
        } else if (!window.open(url, '_blank', 'noopener')) {
            window.location.assign(url);
        }
        setTimeout(() => { URL.revokeObjectURL(url); }, 60000);
        pendingDeletedAnnotationIds.clear();
        setSaveStatus(payload.annotations.length ? 'Saved' : 'No changes');
        setStatus('PDF ready.');
        flashSaveToast('PDF ready');
    } catch (err) {
        if (popup && !popup.closed) popup.close();
        console.error(err);
        showError(err.message);
        setStatus('PDF generation failed.', true);
    } finally {
        setDownloadButtonsDisabled(false, 'Download PDF');
    }
}

for (const button of [downloadBtn, downloadPdfButton].filter(Boolean)) {
    button.addEventListener('click', () => {
        downloadStampedPdf().catch(() => {});
    });
}

async function saveAnnotationStateToDb() {
    if (!SAVE_URL) {
        showError('Save endpoint not configured');
        return;
    }

    syncSelectedBoxToPersistedAnnotations();
    syncDirtyBoxesToPersistedAnnotations();
    const sessionId = getSessionId();
    const annotationsPayload = Array.from(persistedAnnotationsById.values())
        .filter((annotation) => !isRedundantPdfjsSourceOverlay(annotation))
        .map((annotation) => stripTransientAnnotationFields(annotation))
        .filter(Boolean);
    // Snapshot AcroForm field state from pdf.js's annotationStorage so
    // anything the user typed/checked since load is included in this
    // save. Falls back to the entries echoed by documentInfo when the
    // walk fails (e.g. no PDF loaded).
    const acroPayload = await collectAcroFormEntriesForSave().catch((err) => {
        console.warn('Failed to collect AcroForm entries for save', err);
        return Array.isArray(acroFormEntries) ? acroFormEntries : [];
    });

    setSaveStatus('Saving…');
    setStatus('Saving annotation state…');
    if (saveButton) saveButton.disabled = true;
    try {
        const response = await fetch(SAVE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': CSRF,
            },
            body: JSON.stringify({
                annotations: annotationsPayload,
                session_annotations: annotationsPayload,
                acro_form_entries: acroPayload,
                deleted_annotation_ids: Array.from(pendingDeletedAnnotationIds),
                session_id: sessionId,
            }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result?.success) {
            throw new Error(result?.message || `Save failed (${response.status})`);
        }
        if (result.session_id && String(result.session_id).trim()) {
            safeLocalStorageSet(`edit_new_session_${DOC_ID}`, String(result.session_id).trim());
        }
        setSaveStatus(annotationsPayload.length ? 'Saved' : 'No changes');
        setStatus(annotationsPayload.length ? 'Annotation state saved.' : 'No annotation changes to save.');
        flashSaveToast(annotationsPayload.length ? 'Saved' : 'No changes to save');
        pendingDeletedAnnotationIds.clear();
    } catch (err) {
        console.error(err);
        setSaveStatus('Save failed', true);
        setStatus('Save failed.', true);
        showError(err.message || 'Save failed.');
        throw err;
    } finally {
        if (saveButton) saveButton.disabled = false;
    }
}

if (saveButton) {
    saveButton.addEventListener('click', () => {
        saveAnnotationStateToDb().catch(() => {});
    });
}

eventBus.on('pagesinit', () => {
    applyZoom(initialZoomPercent);
});

// ---- Zoom bar (legacy /edit-new chrome) ---------------------------------
//
// Mirrors the constants from resources/js/edit-new/main.js: 50% min,
// 400% max, 30% step. The pdf.js route starts zoomed in for text-box
// editing and controls PDFViewer.currentScale via a percent number.
const ZOOM_MIN_PERCENT = 50;
const ZOOM_MAX_PERCENT = 400;
const ZOOM_STEP_PERCENT = 30;
const initialZoomPercent = 250;
let currentZoomPercent = initialZoomPercent;
let suppressNextScaleEvent = false;

const zoomLabel = document.getElementById('zoom-label');
const zoomInBtn = document.getElementById('zoom-in');
const zoomOutBtn = document.getElementById('zoom-out');
const pageJumpInput = document.getElementById('page-jump');
const pageTotalLabel = document.getElementById('page-total');
const pagePrevBtn = document.getElementById('page-prev');
const pageNextBtn = document.getElementById('page-next');

function clampZoom(p) {
    return Math.max(ZOOM_MIN_PERCENT, Math.min(ZOOM_MAX_PERCENT, Math.round(p)));
}

function applyZoom(percent, opts = {}) {
    currentZoomPercent = clampZoom(percent);
    if (zoomLabel) zoomLabel.textContent = `${currentZoomPercent}%`;
    if (zoomInBtn) zoomInBtn.disabled = currentZoomPercent >= ZOOM_MAX_PERCENT;
    if (zoomOutBtn) zoomOutBtn.disabled = currentZoomPercent <= ZOOM_MIN_PERCENT;
    if (!opts.skipViewer) {
        suppressNextScaleEvent = true;
        // pdf.js uses a multiplier (1.0 = 100%).
        pdfViewer.currentScale = currentZoomPercent / 100;
    }
}

if (zoomInBtn) {
    zoomInBtn.addEventListener('click', () => applyZoom(currentZoomPercent + ZOOM_STEP_PERCENT));
}
if (zoomOutBtn) {
    zoomOutBtn.addEventListener('click', () => applyZoom(currentZoomPercent - ZOOM_STEP_PERCENT));
}

// React to viewer-side scale changes (e.g. page-width fit, ctrl+wheel) so
// the label tracks the actual rendered scale.
eventBus.on('scalechanging', (evt) => {
    if (suppressNextScaleEvent) {
        suppressNextScaleEvent = false;
        return;
    }
    const percent = Math.round((evt.scale || pdfViewer.currentScale) * 100);
    if (percent && percent !== currentZoomPercent) {
        applyZoom(percent, { skipViewer: true });
    }
    scheduleRenderAllAnnotationBoxLayers();
});

// Ctrl/Cmd + wheel zoom anchored to the cursor (matches pdf.js viewer UX).
container.addEventListener('wheel', (ev) => {
    if (!ev.ctrlKey && !ev.metaKey) return;
    ev.preventDefault();
    const delta = ev.deltaY < 0 ? ZOOM_STEP_PERCENT : -ZOOM_STEP_PERCENT;
    applyZoom(currentZoomPercent + delta);
}, { passive: false });

// ---- Page nav (prev / next / jump-to) -----------------------------------

function refreshPageBar(pageNum, total) {
    if (pageJumpInput) {
        pageJumpInput.value = String(pageNum);
        pageJumpInput.max = String(total);
    }
    if (pageTotalLabel) pageTotalLabel.textContent = String(total);
    if (pagePrevBtn) pagePrevBtn.disabled = pageNum <= 1;
    if (pageNextBtn) pageNextBtn.disabled = pageNum >= total;
}

if (pagePrevBtn) {
    pagePrevBtn.addEventListener('click', () => {
        if (pdfViewer.currentPageNumber > 1) pdfViewer.currentPageNumber -= 1;
    });
}
if (pageNextBtn) {
    pageNextBtn.addEventListener('click', () => {
        if (pdfViewer.currentPageNumber < pdfViewer.pagesCount) pdfViewer.currentPageNumber += 1;
    });
}
if (pageJumpInput) {
    const jump = () => {
        const v = parseInt(pageJumpInput.value, 10);
        if (Number.isFinite(v) && v >= 1 && v <= pdfViewer.pagesCount) {
            pdfViewer.currentPageNumber = v;
        } else {
            pageJumpInput.value = String(pdfViewer.currentPageNumber);
        }
    };
    pageJumpInput.addEventListener('change', jump);
    pageJumpInput.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') jump(); });
}

eventBus.on('pagechanging', (evt) => {
    refreshPageBar(evt.pageNumber, pdfViewer.pagesCount);
});
eventBus.on('pagesloaded', async () => {
    const loadGeneration = viewerLoadGeneration;
    refreshPageBar(pdfViewer.currentPageNumber || 1, pdfViewer.pagesCount);
    // Apply our initial zoom % once pages exist (overrides the page-width
    // fit set in pagesinit).
    applyZoom(initialZoomPercent);
    await revealWhenEditedDocumentReady(loadGeneration);
});

eventBus.on('pagerendered', (evt) => {
    renderAnnotationBoxLayer(evt.pageNumber - 1);
});

async function loadPdfFromUrl(url) {
    setStatus('Fetching PDF…');
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) throw new Error(`PDF fetch failed: ${r.status}`);
    const buf = await r.arrayBuffer();
    await loadPdfFromBytes(buf);
}

async function loadPdfFromBytes(buf) {
    setStatus('Rendering…');
    hideViewerUntilOverlaysReady();
    setLoadingScreenMessage('Preparing PDF...');
    viewerLoadGeneration += 1;
    annotationBoxesLoadPromise = null;
    pageOccurrenceCounts.clear();
    const data = buf.slice(0); // pdf.js mutates the input buffer
    // Keep a copy of the current PDF bytes so subsequent moves can be
    // applied to the latest state instead of the pristine original.
    currentPdfBytes = buf.slice(0);
    const loadingTask = pdfjsLib.getDocument({ data, isEvalSupported: false });
    const pdfDoc = await loadingTask.promise;
    currentPdfDoc = pdfDoc;
    pdfViewer.setDocument(pdfDoc);
    linkService.setDocument(pdfDoc, null);
    const loadGeneration = viewerLoadGeneration;
    window.setTimeout(() => {
        revealWhenEditedDocumentReady(loadGeneration).catch((err) => {
            console.warn('Fallback ready reveal failed', err);
        });
    }, 250);
    setStatus(`Loaded ${pdfDoc.numPages} page${pdfDoc.numPages === 1 ? '' : 's'}.`);
}

async function revealWhenEditedDocumentReady(loadGeneration) {
    if (!isCurrentViewerLoad(loadGeneration)) return;
    if (revealedLoadGeneration === loadGeneration) return;
    const pagesReady = await waitForViewerPagesReady(loadGeneration);
    if (!pagesReady || !isCurrentViewerLoad(loadGeneration)) return;
    setLoadingScreenMessage('Loading saved edits...');
    setStatus('Loading saved edits...');
    const annotationsLoaded = await loadAnnotationBoxes();
    if (!annotationsLoaded || !isCurrentViewerLoad(loadGeneration)) return;

    renderAllAnnotationBoxLayers();
    applyAcroFormEntriesToDom();
    await nextAnimationFrame();
    renderAllAnnotationBoxLayers();
    await nextAnimationFrame();
    if (!isCurrentViewerLoad(loadGeneration)) return;
    setStatus(`Loaded ${currentPdfDoc.numPages} page${currentPdfDoc.numPages === 1 ? '' : 's'}.`);
    setDownloadButtonsDisabled(false, 'Download PDF');
    revealedLoadGeneration = loadGeneration;
    revealViewer();
}

// Anchor #pages-wrap below the legacy top-bar accurately.
function syncPagesTop() {
    const wrap = document.getElementById('pages-wrap');
    const top = document.querySelector('.top-bar');
    if (!wrap || !top) return;
    wrap.style.top = `${top.offsetHeight}px`;
}
window.addEventListener('load', syncPagesTop);
window.addEventListener('resize', syncPagesTop);
syncPagesTop();

// ---- Edit-mode toggle (legacy topbar + floating toolbar controls) --------
//
// Mirrors the classic /edit-new affordance: clicking "Edit PDF" turns
// the click-to-edit hover/click on text spans on or off. The legacy
// CSS already swaps the button label/colour via the .is-active class,
// so we just toggle that and a body class our textLayer styles look at.
const editModeBtn = document.getElementById('edit-mode-toggle');
const editModeLabel = document.getElementById('edit-mode-toggle-label');
const floatingEditModeBtn = document.getElementById('ftb-edit-mode');
const editModeButtons = [editModeBtn, floatingEditModeBtn].filter(Boolean);

function setEditMode(on) {
    document.body.classList.toggle('enpv-edit-on', on);
    for (const button of editModeButtons) {
        button.classList.toggle('is-active', on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    if (editModeLabel) editModeLabel.textContent = on ? 'Edit Mode ON' : 'Edit Mode OFF';
    if (!on) { closeEditor(); deselectAnnBox(); }
    if (on && annotationBoxesByPage.size === 0) {
        loadAnnotationBoxes();
    }
    renderAllAnnotationBoxLayers();
    scheduleRenderAllAnnotationBoxLayers();
}

for (const button of editModeButtons) {
    button.addEventListener('click', () => {
        setEditMode(!document.body.classList.contains('enpv-edit-on'));
    });
}
setEditMode(false);

// Install signature feature (Sign button in floating toolbar, modal,
// click-to-place, image-backed annotation rendering, edit-on-dblclick).
signatureFeature = installSignatureFeature({
    pdfViewer,
    container,
    upsertPersistedAnnotation,
    deletePersistedAnnotation,
    rebuildPageMap: rebuildPersistedAnnotationPageMap,
    pushHistorySnapshot,
    markManualSaveNeeded,
    setStatus,
    renderAnnotationBoxLayer,
    renderAllAnnotationBoxLayers: () => {
        const total = pdfViewer?.pagesCount || 0;
        for (let pi = 0; pi < total; pi += 1) renderAnnotationBoxLayer(pi);
    },
    isEditModeOn: () => document.body.classList.contains('enpv-edit-on'),
    getPersistedAnnotation: (id) => persistedAnnotationsById.get(String(id || '')) || null,
    selectAnnBox,
    annMenu,
});

(async () => {
    try {
        await loadInitialPdf();
        window.__enpv = { pdfViewer, eventBus, linkService };
    } catch (err) {
        console.error(err);
        showError(err.message);
        setStatus('Failed to load PDF.', true);
    }
})();

void CSRF;
