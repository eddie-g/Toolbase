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
import { sliderValueToFontPt, fontPtToSliderValue } from '../edit-new/text/font-slider.js';
import { computeLineBoxGeometry, constrainLineEndpointTo45, normalizeRotationDegrees } from '../edit-new/util/geometry.js';
import { currentShapeDefaults } from '../edit-new/shapes/defaults.js';
import { reflectShapeStateToInputs, readShapeInspectorState } from '../edit-new/shapes/inspector-ui.js';
import { normalizeShapeAnnotation, normalizeImageAnnotation, applyShapeStateToAnnotation } from '../edit-new/annotations/normalize.js';
import { getImageAnnotationSource } from '../edit-new/annotations/image-source.js';
import { paintDrawDot, paintDrawSegment } from '../edit-new/draw/primitives.js';
import {
    normalizeShapeType,
    defaultPolygonUnitPoints,
    normalizePolygonPointList,
} from '../edit-new/annotations/shape-geometry.js';
import { getShapePolygonPointsPdf } from '../edit-new/annotations/polygon-points.js';
import { clipPolygonAgainstInfiniteLine, computePolygonArea, dedupePolygonVertices } from '../edit-new/util/polygon-clip.js';
import { clamp01 } from '../edit-new/util/math.js';
import {
    clearImageImportSelection as _clearImageImportSelection,
    openImageImportModal as _openImageImportModal,
    closeImageImportModal as _closeImageImportModal,
    imageImportLoadFile as _imageImportLoadFile,
} from '../edit-new/image-import/modal.js';
import { setImageImportStatus as _setImageImportStatus } from '../edit-new/image-import/status.js';
import { imageImportPendingAsset } from '../edit-new/store/misc-state.js';
import { installSignatureFeature, isSignatureAnnotation } from './signature.js';
import {
    pdfjsPromotedOverlayShouldRenderAsPersistedOverlay,
    pdfjsSourceOverlayShouldUseSourceBoxInEditMode,
} from './source-edit-contract.js';

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
const addTextButton = document.getElementById('add-text-btn');
const floatingAddTextButton = document.getElementById('ftb-add-text');
const addShapeButton = document.getElementById('add-shape-btn');
const floatingAddShapeButton = document.getElementById('ftb-add-shape');
const floatingAddImageButton = document.getElementById('ftb-add-image');
const floatingDrawButton = document.getElementById('ftb-draw-erase');
const saveStatus = document.getElementById('save-status');
const saveToast = document.getElementById('save-toast');
const annFormatBar = document.getElementById('ann-format-bar');
const afbFont = document.getElementById('afb-font');
const afbSize = document.getElementById('afb-size');
const afbSizeValue = document.getElementById('afb-size-value');
const afbTextColor = document.getElementById('afb-text-color');
const afbBgColor = document.getElementById('afb-bg-color');
const afbOpacity = document.getElementById('afb-opacity');
const afbBold = document.getElementById('afb-bold');
const afbItalic = document.getElementById('afb-italic');
const afbUnderline = document.getElementById('afb-underline');
const afbAlign = document.getElementById('afb-align');
const afbValign = document.getElementById('afb-valign');
const afbCopy = document.getElementById('afb-copy');
const afbDelete = document.getElementById('afb-delete');
const shapeToolPanel = document.getElementById('shape-tool-panel');
const shapeTypeButtons = Array.from(document.querySelectorAll('[data-shape-tool]'));
const shapeMoreToggle = document.getElementById('shape-more-toggle');
const shapeStrokeColorInput = document.getElementById('shape-stroke-color');
const shapeStrokeHexInput = document.getElementById('shape-stroke-hex');
const shapeStrokeTransparentInput = document.getElementById('shape-stroke-transparent');
const shapeStrokeWidthInput = document.getElementById('shape-stroke-width');
const shapeStrokeWidthValue = document.getElementById('shape-stroke-width-value');
const shapeStrokeOpacityInput = document.getElementById('shape-stroke-opacity');
const shapeStrokeOpacityValue = document.getElementById('shape-stroke-opacity-value');
const shapeFillColorInput = document.getElementById('shape-fill-color');
const shapeFillHexInput = document.getElementById('shape-fill-hex');
const shapeFillTransparentInput = document.getElementById('shape-fill-transparent');
const shapeFillOpacityInput = document.getElementById('shape-fill-opacity');
const shapeFillOpacityValue = document.getElementById('shape-fill-opacity-value');
const drawToolPanel = document.getElementById('draw-tool-panel');
const drawToolClose = document.getElementById('draw-tool-close');
const drawToolButtons = Array.from(document.querySelectorAll('[data-draw-direct-tool]'));
const drawToolSizeInput = document.getElementById('draw-tool-size');
const drawToolSizeValue = document.getElementById('draw-tool-size-value');
const drawToolOpacityInput = document.getElementById('draw-tool-opacity');
const drawToolOpacityValue = document.getElementById('draw-tool-opacity-value');
const drawToolColorInput = document.getElementById('draw-tool-color');
const drawColorSwatches = Array.from(document.querySelectorAll('[data-draw-color]'));
const drawToolStatus = document.getElementById('draw-tool-status');
const imageImportModal = document.getElementById('image-import-modal');
const imageImportScrim = document.getElementById('image-import-scrim');
const imageImportClose = document.getElementById('image-import-close');
const imageImportCancel = document.getElementById('image-import-cancel');
const imageImportApply = document.getElementById('image-import-apply');
const imageImportFileInput = document.getElementById('image-import-file');
const imageImportDropzone = document.getElementById('image-import-dropzone');
const imageImportDropzoneInner = document.getElementById('image-import-dropzone-inner');
const imageImportPreview = document.getElementById('image-import-preview');
const imageImportPreviewImg = document.getElementById('image-import-preview-img');
const imageImportPreviewName = document.getElementById('image-import-preview-name');
const imageImportPreviewDims = document.getElementById('image-import-preview-dims');
const imageImportClear = document.getElementById('image-import-clear');
const imageImportStatus = document.getElementById('image-import-status');

const PDF_URL = root.dataset.pdfUrl;
const BAKED_URL = root.dataset.bakedUrl;
const REWRITE_URL = root.dataset.rewriteUrl;
const REDACT_URL = root.dataset.redactUrl;
const MOVE_URL = root.dataset.moveUrl;
const REFLOW_URL = root.dataset.reflowUrl;
const CSRF = root.dataset.csrf;
const DOC_ID = root.dataset.docId;
const INFO_URL = editNewRoot?.dataset?.infoUrl;
const FONTS_URL = editNewRoot?.dataset?.fontsUrl || (DOC_ID ? `/documents/${encodeURIComponent(DOC_ID)}/fonts` : '');
const SAVE_URL = editNewRoot?.dataset?.saveUrl;
const OVERWRITE_TEXT_URL = editNewRoot?.dataset?.overwriteUrl || '/documents/overwrite-annotation-text';
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
let autoSaveTimer = 0;
let saveInFlight = false;
let saveAgainAfterCurrent = false;
let suppressAutoSaveForNavigation = false;
const AUTO_SAVE_DELAY_MS = 1800;
let annotationBoxesLoadPromise = null;
let viewerLoadGeneration = 0;
let revealedLoadGeneration = 0;
let movedSourceLiveRedactionScheduled = false;
let movedSourceLiveRedactionInFlight = false;
let hydratingPersistedAnnotations = false;
let drawModeActive = false;
let drawToolType = 'pen';
let drawStrokeColor = '#111827';
let drawOpacity = 1;
let drawBrushSize = 10;
let activeDrawSession = null;
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

function scheduleAutoSave() {
    if (suppressAutoSaveForNavigation) return;
    if (!SAVE_URL) return;
    if (autoSaveTimer) window.clearTimeout(autoSaveTimer);
    autoSaveTimer = window.setTimeout(() => {
        autoSaveTimer = 0;
        if (suppressAutoSaveForNavigation) return;
        saveAnnotationStateToDb({ source: 'autosave' }).catch(() => {});
    }, AUTO_SAVE_DELAY_MS);
}

function cancelPendingAutoSaveForNavigation() {
    suppressAutoSaveForNavigation = true;
    saveAgainAfterCurrent = false;
    if (autoSaveTimer) {
        window.clearTimeout(autoSaveTimer);
        autoSaveTimer = 0;
    }
}

window.addEventListener('pagehide', cancelPendingAutoSaveForNavigation);
window.addEventListener('beforeunload', cancelPendingAutoSaveForNavigation);
window.addEventListener('popstate', cancelPendingAutoSaveForNavigation);

function markManualSaveNeeded() {
    if (hydratingPersistedAnnotations) return;
    if (suppressAutoSaveForNavigation) return;
    setSaveStatus('Autosaving…');
    setStatus('Unsaved changes. Autosaving…');
    scheduleAutoSave();
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

function normalizeVisualLineComparableText(value) {
    return normalizeComparableText(value).replace(/-\s+/g, '-');
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

function immutableSourceNumber(existingValue, fallbackValue) {
    const existing = Number(existingValue);
    if (Number.isFinite(existing)) return existing;
    const fallback = Number(fallbackValue);
    return Number.isFinite(fallback) ? fallback : 0;
}

function immutableSourceString(existingValue, fallbackValue = '') {
    if (existingValue != null && String(existingValue) !== '') return String(existingValue);
    if (fallbackValue != null && String(fallbackValue) !== '') return String(fallbackValue);
    return '';
}

function scorePdfRectDistance(left, right) {
    if (!left || !right) return Number.POSITIVE_INFINITY;
    return Math.abs(left.x - right.x)
        + Math.abs(left.y - right.y)
        + Math.abs(left.w - right.w)
        + Math.abs(left.h - right.h);
}

function pdfRectsNearlyEqual(left, right, tolerance = 3) {
    if (!left || !right) return false;
    return approxEqual(left.x, right.x, tolerance)
        && approxEqual(left.y, right.y, tolerance)
        && approxEqual(left.w, right.w, tolerance)
        && approxEqual(left.h, right.h, tolerance);
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

function normalizeOpacity(value, fallback = 1) {
    const raw = String(value ?? '').trim();
    if (!raw) return Math.max(0.1, Math.min(1, Number(fallback) || 1));
    const parsed = Number.parseFloat(raw.replace('%', ''));
    if (!Number.isFinite(parsed)) return Math.max(0.1, Math.min(1, Number(fallback) || 1));
    return Math.max(0.1, Math.min(1, raw.includes('%') || parsed > 1 ? parsed / 100 : parsed));
}

function normalizeTextAlign(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return ['left', 'center', 'right'].includes(normalized) ? normalized : 'left';
}

function normalizeVerticalAlign(value) {
    const normalized = String(value || '').trim().toLowerCase();
    return ['top', 'middle', 'bottom'].includes(normalized) ? normalized : 'top';
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

// Find a persisted promoted (extracted-source) annotation on the given page
// whose originalText / pdfjsSourceText / text matches `text`. Used to expose
// the stable persisted id (e.g. "promoted_1_25") on source-editor boxes via
// `data-persisted-annotation-id` so external callers can target the right
// PdfState row without guessing at the editor's runtime anchor id.
function findPersistedPromotedAnnotationByText(pageIndex, text) {
    const target = String(text ?? '').replace(/\s+/g, ' ').trim();
    if (!target) return null;
    for (const annotation of persistedAnnotationsById.values()) {
        if (!isPromotedExtractionAnnotation(annotation)) continue;
        if (Number(annotation.pageIndex) !== Number(pageIndex)) continue;
        const candidates = [annotation.originalText, annotation.pdfjsSourceText, annotation.text]
            .map((v) => String(v ?? '').replace(/\s+/g, ' ').trim());
        if (candidates.includes(target)) return annotation;
    }
    return null;
}

function shouldIncludeInPdfjsVisibleExport(annotation) {
    if (!annotation || typeof annotation !== 'object') return false;
    if (isEmptyUserCreatedTextAnnotation(annotation)) return false;
    if (
        boolish(annotation.imageToPdfOcr)
        && !boolish(annotation.userAuthored)
        && !boolish(annotation.styleDirty)
        && !boolish(annotation.movedTextOverlay)
        && !boolish(annotation.userCreated)
    ) {
        return false;
    }
    if (isPromotedExtractionAnnotation(annotation)) {
        return pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation);
    }
    if (String(annotation.db_state || annotation.state || '').toLowerCase() === 'deleted' && !boolish(annotation.pdfjsDeleted)) return false;
    return ['text', 'image', 'signature', 'shape'].includes(String(annotation.type || '').toLowerCase());
}

function shouldIncludeInPdfjsSessionPayload(annotation) {
    if (!annotation || typeof annotation !== 'object') return false;
    return !isEmptyUserCreatedTextAnnotation(annotation);
}

function isShapeAnnotation(annotation) {
    return String(annotation?.type || '').toLowerCase() === 'shape';
}

function isImageAnnotation(annotation) {
    return !!annotation && String(annotation.type || '').toLowerCase() === 'image';
}

function isDirectDrawAnnotation(annotation) {
    return isImageAnnotation(annotation) && String(annotation.imageToolSource || '').toLowerCase() === 'direct-draw';
}

function pdfjsAnnotationLayerKey(annotation) {
    if (isShapeAnnotation(annotation)) return 0;
    if (isDirectDrawAnnotation(annotation)) return 1;
    if (String(annotation?.type || '').toLowerCase() === 'text') return 2;
    if (isSignatureAnnotation(annotation) || isImageAnnotation(annotation)) return 3;
    return 2;
}

function isImageBox(box, existingAnnotation = null) {
    return String(box?.dataset?.annotationType || '').toLowerCase() === 'image'
        || isImageAnnotation(existingAnnotation);
}

function isImageBackedBox(box) {
    const type = String(box?.dataset?.annotationType || '').toLowerCase();
    return type === 'signature' || type === 'image';
}

function isShapeBox(box, existingAnnotation = null) {
    return String(box?.dataset?.annotationType || '').toLowerCase() === 'shape'
        || isShapeAnnotation(existingAnnotation);
}

function isUserCreatedTextAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    return boolish(annotation.userCreated)
        || boolish(annotation.userAuthored)
        || boolish(annotation.skipPdfjsSourceMask);
}

function isEmptyUserCreatedTextAnnotation(annotation) {
    if (!isUserCreatedTextAnnotation(annotation)) return false;
    return normalizeComparableText(annotation.text || '') === ''
        && normalizeComparableText(annotation.richTextHtml || '') === '';
}

function stripUserCreatedPdfjsSourceMetadata(annotation) {
    if (!annotation || typeof annotation !== 'object') return annotation;
    const hasSourceText = String(annotation.pdfjsSourceText || '').trim() !== '';
    if (!boolish(annotation.userCreated) && !(boolish(annotation.skipPdfjsSourceMask) && !hasSourceText)) return annotation;
    [
        'pdfjsSourceX',
        'pdfjsSourceY',
        'pdfjsSourceW',
        'pdfjsSourceH',
        'pdfjsSourceMaskX',
        'pdfjsSourceMaskY',
        'pdfjsSourceMaskW',
        'pdfjsSourceMaskH',
        'pdfjsSourceMaskAscenderPadding',
        'pdfjsSourceText',
        'pdfjsSourcePageHeight',
        'pdfjsSourceOccurrence',
        'pdfjsSourceFidelity',
        'sourceSpanRuns',
        'sourceTextLines',
        'movedTextOverlay',
    ].forEach((key) => delete annotation[key]);
    annotation.skipPdfjsSourceMask = true;
    return annotation;
}

function isUserCreatedTextBox(box, existingAnnotation = null) {
    if (!box) return false;
    if (isShapeBox(box, existingAnnotation)) return false;
    return box.dataset.userCreated === '1'
        || box.dataset.userAuthored === '1'
        || box.dataset.skipPdfjsSourceMask === '1'
        || isUserCreatedTextAnnotation(existingAnnotation);
}

function textContentForBox(box) {
    const tc = selectedBoxTextElement(box);
    const preserveLineBreaks = box?.dataset?.editorMode === 'rich'
        || box?.dataset?.userForcedRichText === '1'
        || box?.dataset?.userCreated === '1'
        || box?.dataset?.userAuthored === '1';
    const text = preserveLineBreaks ? plainTextFromRichTextElement(tc) : String(tc?.textContent ?? '');
    return preserveLineBreaks
        ? normalizeRichPlainText(text)
        : String(text).replace(/[ \t]*\r?\n[ \t]*/g, ' ');
}

function plainTextFromRichTextElement(root) {
    if (!root) return '';
    let out = '';
    const blockTags = new Set(['ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DIV', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'LI', 'MAIN', 'NAV', 'OL', 'P', 'PRE', 'SECTION', 'TABLE', 'TBODY', 'TD', 'TFOOT', 'TH', 'THEAD', 'TR', 'UL']);
    const appendNewline = () => {
        if (out && !out.endsWith('\n')) out += '\n';
    };
    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            out += node.nodeValue || '';
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (node.nodeName === 'BR') {
            out += '\n';
            return;
        }
        const isBlock = blockTags.has(node.nodeName);
        if (isBlock) appendNewline();
        const before = out.length;
        for (const child of node.childNodes) walk(child);
        if (isBlock && out.length > before) appendNewline();
    };
    for (const child of root.childNodes) walk(child);
    return out;
}

function normalizeRichPlainText(value) {
    return String(value || '')
        .replace(/\r\n?/g, '\n')
        .replace(/\u00a0/g, ' ')
        .replace(/\u200b/g, '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n[ \t]+/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/\n$/g, '');
}

function annotationPreservesDisplayLineBreaks(annotation) {
    return Boolean(
        boolish(annotation?.userCreated)
        || boolish(annotation?.userAuthored)
        || annotation?.userForcedRichText === true
        || String(annotation?.pdfjsEditorMode || '').toLowerCase() === 'rich'
    );
}

function normalizeAnnotationTextForDisplay(value, options = {}) {
    const text = String(value || '').replace(/\r\n?/g, '\n');
    if (options.preserveLineBreaks === true) return normalizeRichPlainText(text);
    return text.replace(/[ \t]*\n[ \t]*/g, ' ');
}

function sanitizeRichTextHtmlForAnnotation(html, options = {}) {
    const raw = String(html || '').trim();
    if (!raw) return '';
    const template = document.createElement('template');
    template.innerHTML = raw;
    const allowedTags = new Set(['B', 'BR', 'DIV', 'EM', 'I', 'P', 'SPAN', 'STRONG', 'U']);
    const allowedStyles = new Set([
        'background-color',
        'color',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'line-height',
        'text-decoration',
        'text-decoration-line',
        'white-space',
        'word-spacing',
    ]);
    const scrub = (node) => {
        Array.from(node.childNodes || []).forEach((child) => {
            if (child.nodeType === Node.ELEMENT_NODE) {
                if (!allowedTags.has(child.tagName)) {
                    child.replaceWith(...Array.from(child.childNodes));
                    return;
                }
                Array.from(child.attributes || []).forEach((attr) => {
                    if (attr.name.toLowerCase() !== 'style') child.removeAttribute(attr.name);
                });
                const style = child.getAttribute('style') || '';
                if (style) {
                    const kept = style.split(';')
                        .map((part) => part.trim())
                        .filter(Boolean)
                        .filter((part) => {
                            const name = part.split(':')[0]?.trim()?.toLowerCase();
                            return allowedStyles.has(name);
                        });
                    if (kept.length) child.setAttribute('style', kept.join('; '));
                    else child.removeAttribute('style');
                }
                scrub(child);
            } else if (child.nodeType === Node.TEXT_NODE) {
                child.nodeValue = options.preserveLineBreaks === true
                    ? normalizeRichPlainText(child.nodeValue || '')
                    : String(child.nodeValue || '').replace(/[ \t]*\r?\n[ \t]*/g, ' ');
            } else {
                child.remove();
            }
        });
    };
    scrub(template.content);
    return template.innerHTML.trim();
}

function richTextHtmlForBox(box) {
    if (box?.dataset?.editorMode === 'source' && box.dataset.userForcedRichText !== '1') {
        return '';
    }
    const tc = selectedBoxTextElement(box);
    if (!tc) return '';
    const html = sanitizeRichTextHtmlForAnnotation(tc.innerHTML, {
        preserveLineBreaks: /\r|\n/.test(textContentForBox(box)),
    });
    if (!html) return '';
    const probe = document.createElement('div');
    probe.innerHTML = html;
    const hasInlineFormatting = Boolean(probe.querySelector('[style],b,strong,i,em,u'));
    return hasInlineFormatting ? html : '';
}

function parseCssFontFamily(value) {
    return String(value || '')
        .split(',')[0]
        .trim()
        .replace(/^["']|["']$/g, '');
}

function dominantRichTextStyleForBox(box) {
    const tc = selectedBoxTextElement(box);
    if (!box || !tc || !tc.querySelector('[style],b,strong,i,em,u')) return null;
    const walker = document.createTreeWalker(tc, NodeFilter.SHOW_TEXT);
    let node;
    let best = null;
    let bestWeight = 0;
    while ((node = walker.nextNode())) {
        const text = String(node.nodeValue || '');
        const weight = text.replace(/\s+/g, '').length;
        if (!weight) continue;
        const element = node.parentElement || tc;
        const cs = window.getComputedStyle(element);
        if (weight > bestWeight) {
            bestWeight = weight;
            best = {
                fontFamily: parseCssFontFamily(cs.fontFamily || element.style?.fontFamily || ''),
                fontSizePx: Number.parseFloat(cs.fontSize || '') || 0,
                lineHeightPx: Number.parseFloat(cs.lineHeight || '') || 0,
                fontWeight: String(cs.fontWeight || element.style?.fontWeight || '').trim(),
                fontStyle: String(cs.fontStyle || element.style?.fontStyle || '').trim(),
                color: cssColorToHex(cs.color || '', ''),
            };
        }
    }
    return best;
}

function syncRichTextBoxTypographyFromContent(box) {
    const style = dominantRichTextStyleForBox(box);
    if (!style) return null;
    const scale = Number.parseFloat(box?.parentElement?.dataset?.scale || '1') || 1;
    if (style.fontFamily) {
        box.style.setProperty('--enpv-font-family', style.fontFamily);
    }
    if (Number.isFinite(style.fontSizePx) && style.fontSizePx > 0) {
        const pts = Math.max(1, Math.min(300, Math.round((style.fontSizePx / scale) * 10) / 10));
        box.dataset.fontSizePts = String(pts);
        box.style.setProperty('--enpv-font-size', `${style.fontSizePx}px`);
    }
    if (Number.isFinite(style.lineHeightPx) && style.lineHeightPx > 0) {
        box.style.setProperty('--enpv-line-height', `${style.lineHeightPx}px`);
    }
    if (style.fontWeight) box.style.setProperty('--enpv-font-weight', style.fontWeight);
    if (style.fontStyle) box.style.setProperty('--enpv-font-style', style.fontStyle);
    if (style.color) box.style.setProperty('--enpv-text-color', style.color);
    return style;
}

function userCreatedBoxHasText(box) {
    return normalizeComparableText(textContentForBox(box)) !== '';
}

function rebuildPersistedAnnotationPageMap() {
    const byPage = new Map();
    let index = 0;
    for (const annotation of persistedAnnotationsById.values()) {
        if (!annotation) continue;
        const t = String(annotation.type || '').toLowerCase();
        if (t !== 'text' && t !== 'signature' && t !== 'image' && t !== 'shape') continue;
        const hydrated = t === 'text' ? hydrateAnnotationForBoxes(annotation, index++) : annotation;
        const pageIndex = annotationPageIndex(hydrated);
        if (!byPage.has(pageIndex)) byPage.set(pageIndex, []);
        byPage.get(pageIndex).push(hydrated);
    }
    for (const [pageIndex, annotations] of byPage) {
        byPage.set(pageIndex, annotations
            .map((annotation, order) => ({ annotation, order, key: pdfjsAnnotationLayerKey(annotation) }))
            .sort((left, right) => (left.key - right.key) || (left.order - right.order))
            .map((entry) => entry.annotation));
    }
    annotationBoxesByPage = byPage;
}

function replacePersistedAnnotations(records) {
    const next = new Map();
    (Array.isArray(records) ? records : []).forEach((annotation, index) => {
        if (!annotation || typeof annotation !== 'object') return;
        const id = String(annotation.id || annotation.db_id || buildPdfjsAnnotationId(annotationPageIndex(annotation), `loaded_${index}`));
        const hydrated = { ...annotation, id };
        if (isShapeAnnotation(hydrated)) normalizePdfjsLineAnnotationBox(normalizeLegacyPdfjsLineEndpointPreview(normalizeShapeAnnotation(hydrated)));
        if (isSignatureAnnotation(hydrated) || isImageAnnotation(hydrated)) normalizeImageAnnotation(hydrated);
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
    if (isShapeAnnotation(annotation)) normalizePdfjsLineAnnotationBox(normalizeLegacyPdfjsLineEndpointPreview(normalizeShapeAnnotation(annotation)));
    if (isSignatureAnnotation(annotation) || isImageAnnotation(annotation)) normalizeImageAnnotation(annotation);
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
        ? pdfRectsNearlyEqual(current, baseline, 0.75)
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
    return pdfRectsNearlyEqual(current, baseline, 3.0);
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
        const spanTexts = [
            normalizeComparableText(span.textContent || ''),
            normalizeComparableText(span.dataset.enpvOriginal || ''),
        ].filter(Boolean);
        if (!spanTexts.includes(text)) return false;
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

function hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale) {
    const text = cleanPromotedSourceText(annotation);
    if (!text || !viewport || !scale) return;
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    const textLayerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!pageDiv || !textLayerEl) return;
    const sourceBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || annotation._originalBox;
    const sourceRect = pdfRectToCanvasRect(sourceBox, viewport, scale);
    if (!sourceRect || sourceRect.width <= 0 || sourceRect.height <= 0) return;
    const pageRect = pageDiv.getBoundingClientRect();
    const targetCenterX = sourceRect.left + (sourceRect.width / 2);
    const targetCenterY = sourceRect.top + (sourceRect.height / 2);
    const maxCenterDx = Math.max(18, sourceRect.width * 1.75);
    const maxCenterDy = Math.max(18, sourceRect.height * 1.75);
    let best = null;
    let bestDistance = Number.POSITIVE_INFINITY;
    for (const span of textLayerEl.querySelectorAll('span')) {
        const spanTexts = [
            normalizeComparableText(span.textContent || ''),
            normalizeComparableText(span.dataset.enpvOriginal || ''),
        ].filter(Boolean);
        if (!spanTexts.includes(text)) continue;
        const spanRect = span.getBoundingClientRect();
        const spanBox = {
            left: spanRect.left - pageRect.left,
            top: spanRect.top - pageRect.top,
            width: spanRect.width,
            height: spanRect.height,
        };
        const spanCenterX = spanBox.left + (spanBox.width / 2);
        const spanCenterY = spanBox.top + (spanBox.height / 2);
        const dx = Math.abs(spanCenterX - targetCenterX);
        const dy = Math.abs(spanCenterY - targetCenterY);
        if (dx > maxCenterDx || dy > maxCenterDy) continue;
        const distance = dx + dy;
        if (distance < bestDistance) {
            best = span;
            bestDistance = distance;
        }
    }
    if (best) best.dataset.enpvCleanPromotedHidden = '1';
}

function hideTextLayerSpanUnderCleanPromotedBox(box) {
    if (!box?.classList?.contains('is-clean-promoted-fallback')) return;
    const text = normalizeComparableText(box.querySelector('.enpv-text-content')?.textContent || box.textContent || '');
    if (!text) return;
    const pageDiv = box.closest('.page');
    const textLayerEl = pageDiv?.querySelector('.textLayer');
    if (!pageDiv || !textLayerEl) return;
    const pageRect = pageDiv.getBoundingClientRect();
    const boxRect = box.getBoundingClientRect();
    const target = {
        left: boxRect.left - pageRect.left,
        top: boxRect.top - pageRect.top,
        width: boxRect.width,
        height: boxRect.height,
    };
    const targetCenterX = target.left + (target.width / 2);
    const targetCenterY = target.top + (target.height / 2);
    let best = null;
    let bestDistance = Number.POSITIVE_INFINITY;
    for (const span of textLayerEl.querySelectorAll('span')) {
        const spanTexts = [
            normalizeComparableText(span.textContent || ''),
            normalizeComparableText(span.dataset.enpvOriginal || ''),
        ].filter(Boolean);
        if (!spanTexts.includes(text)) continue;
        const spanRect = span.getBoundingClientRect();
        const spanBox = {
            left: spanRect.left - pageRect.left,
            top: spanRect.top - pageRect.top,
            width: spanRect.width,
            height: spanRect.height,
        };
        if (!rectsOverlap(target, spanBox, 0.1)) continue;
        const spanCenterX = spanBox.left + (spanBox.width / 2);
        const spanCenterY = spanBox.top + (spanBox.height / 2);
        const distance = Math.abs(spanCenterX - targetCenterX) + Math.abs(spanCenterY - targetCenterY);
        if (distance < bestDistance) {
            best = span;
            bestDistance = distance;
        }
    }
    if (best) best.dataset.enpvCleanPromotedHidden = '1';
}

function scheduleHideTextLayerSpanUnderCleanPromotedBox(box) {
    hideTextLayerSpanUnderCleanPromotedBox(box);
    window.requestAnimationFrame(() => hideTextLayerSpanUnderCleanPromotedBox(box));
    window.setTimeout(() => hideTextLayerSpanUnderCleanPromotedBox(box), 250);
    window.setTimeout(() => hideTextLayerSpanUnderCleanPromotedBox(box), 1000);
}

function hideTextLayerSpansUnderCleanPromotedBoxes(layer) {
    if (!layer) return;
    layer.querySelectorAll('.enpv-annotation-box.is-clean-promoted-fallback').forEach((box) => {
        hideTextLayerSpanUnderCleanPromotedBox(box);
    });
}

function scheduleHideTextLayerSpansUnderCleanPromotedBoxes(layer) {
    hideTextLayerSpansUnderCleanPromotedBoxes(layer);
    window.requestAnimationFrame(() => hideTextLayerSpansUnderCleanPromotedBoxes(layer));
    window.setTimeout(() => hideTextLayerSpansUnderCleanPromotedBoxes(layer), 250);
    window.setTimeout(() => hideTextLayerSpansUnderCleanPromotedBoxes(layer), 1000);
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
    if (boolish(annotation.userCreated)
        || (boolish(annotation.skipPdfjsSourceMask) && !String(annotation.pdfjsSourceText || '').trim())) {
        return null;
    }
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
    // Text runs are wide and short, so using `max(w, h)` for the
    // proximity threshold lets a single line claim ownership of the
    // entire paragraph above/below it (centerDistance ~= line height
    // is still << w*0.25). Use the line height (rect height) for the
    // distance threshold so adjacent paragraph lines don't get
    // suppressed as 'owned' by their neighbor.
    const lineSize = Math.max(ownerRect.h, targetRect.h, 1);
    const closeCenters = centerDistance <= Math.max(4, lineSize * 1.5);
    const textMatches = !owner.text || !targetText || owner.text === targetText;
    const strongGeometry = ownerRatio >= 0.55 || targetRatio >= 0.55 || centerDistance <= Math.max(2, lineSize * 0.5);
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
        ownerForSourceRect(targetRect, targetText = '') {
            const normalizedText = normalizeComparableText(targetText);
            if (!targetRect) return null;
            return owners.find((owner) => sourceOwnerMatches(owner, targetRect, normalizedText)) || null;
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

function appendPromotedFallbackOverlays(layer, annotations, pageIndex, viewport, scale, editModeOn, maskLayer = null) {
    if (!layer || !Array.isArray(annotations) || annotations.length === 0) return;
    for (const annotation of annotations) {
        if (!cleanPromotedSourceText(annotation)) continue;
        if (promotedFallbackIsCoveredByLayerBox(annotation, layer, viewport, scale)) continue;
        const rendered = createPersistedOverlayBox(annotation, pageIndex, viewport, scale, editModeOn);
        if (!rendered) continue;
        rendered.box.classList.add('is-clean-promoted-fallback');
        rendered.box.dataset.cleanPromotedFallback = '1';
        hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale);
        window.requestAnimationFrame(() => hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale));
        window.setTimeout(() => hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale), 250);
        if (rendered.mask) (maskLayer || layer).appendChild(rendered.mask);
        layer.appendChild(rendered.box);
        scheduleHideTextLayerSpanUnderCleanPromotedBox(rendered.box);
        refreshAttachedSourceFidelityTextFit(rendered.box);
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
    const sourceInfo = sourceInfoForSpan(spanEl);
    if (!sourceInfo) return null;
    const sourceRect = sourceInfo.rect;
    const left = sourceRect.left;
    const top = sourceRect.top;
    const width = sourceRect.width;
    const height = sourceRect.height;
    const pdfRect = {
        x: left / scale,
        y: (Number(viewport.height) - (top + height)) / scale,
        w: width / scale,
        h: height / scale,
    };
    const anchorSpan = sourceInfo.anchor || spanEl;
    const cs = window.getComputedStyle(anchorSpan);
    const fontSizePx = Number.parseFloat(cs.fontSize || '');
    const lineHeightPx = Number.parseFloat(cs.lineHeight || '');
    const sourceTransform = cs.transform && cs.transform !== 'none' ? cs.transform : '';
    const sourceScaleX = sourceTransformScaleX(sourceTransform);
    const sourceRotation = sourceTransformRotationDegrees(sourceTransform);
    const annotationId = String(existingAnnotation?.id || anchorSpan.dataset.enpvPersistentId || spanEl.dataset.enpvPersistentId || buildPdfjsAnnotationId(pageIndex, sourceInfo.index));
    const sourceText = String(existingAnnotation?.pdfjsSourceText || existingAnnotation?.originalText || sourceInfo.text || spanEl.dataset.enpvOriginal || spanEl.textContent || '');
    const sourceStyle = promotedSourceStyleForPdfjsSource(
        pageIndex,
        pdfRect,
        sourceText,
        Number(viewport.height) / scale,
    );

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
        text: String(nextText ?? sourceInfo.visualText ?? sourceInfo.text ?? spanEl.textContent ?? ''),
        originalText: String(existingAnnotation?.originalText || sourceText),
        pdfX: pdfRect.x,
        pdfY: pdfRect.y,
        pdfWidth: pdfRect.w,
        pdfHeight: pdfRect.h,
        fontSize: Number.isFinite(fontSizePx) && scale > 0 ? (fontSizePx / scale) : (Number(existingAnnotation?.fontSize) || 12),
        requestedFontSize: Number.isFinite(fontSizePx) && scale > 0 ? (fontSizePx / scale) : (Number(existingAnnotation?.requestedFontSize) || undefined),
        lineHeight: Number.isFinite(lineHeightPx) && scale > 0 ? (lineHeightPx / scale) : (Number(existingAnnotation?.lineHeight) || undefined),
        fontFamily: String(existingAnnotation?.fontFamily || cs.fontFamily || ''),
        fontWeight: String(existingAnnotation?.fontWeight || sourceStyle?.fontWeight || cs.fontWeight || 'normal'),
        fontStyle: String(existingAnnotation?.fontStyle || sourceStyle?.fontStyle || cs.fontStyle || 'normal'),
        opacity: Number(existingAnnotation?.opacity ?? 1) || 1,
        textColor,
        color: textColor,
        savedTextOverlay: true,
        pdfjsSourceX: immutableSourceNumber(existingAnnotation?.pdfjsSourceX, pdfRect.x),
        pdfjsSourceY: immutableSourceNumber(existingAnnotation?.pdfjsSourceY, pdfRect.y),
        pdfjsSourceW: immutableSourceNumber(existingAnnotation?.pdfjsSourceW, pdfRect.w),
        pdfjsSourceH: immutableSourceNumber(existingAnnotation?.pdfjsSourceH, pdfRect.h),
        pdfjsSourcePageHeight: immutableSourceNumber(existingAnnotation?.pdfjsSourcePageHeight, Number(viewport.height) / scale),
        pdfjsSourceText: immutableSourceString(existingAnnotation?.pdfjsSourceText, sourceText),
        pdfjsAnchorUid: immutableSourceString(existingAnnotation?.pdfjsAnchorUid, sourceInfo.uid),
        pdfjsSourceOccurrence: immutableSourceString(existingAnnotation?.pdfjsSourceOccurrence, sourceInfo.occurrence || '0'),
        pdfjsSourceFidelity: existingAnnotation?.pdfjsSourceFidelity ?? true,
        pdfjsSourceFontFamily: immutableSourceString(existingAnnotation?.pdfjsSourceFontFamily, cs.fontFamily || ''),
        pdfjsSourceFontWeight: immutableSourceString(existingAnnotation?.pdfjsSourceFontWeight, sourceStyle?.fontWeight || cs.fontWeight || ''),
        pdfjsSourceFontStyle: immutableSourceString(existingAnnotation?.pdfjsSourceFontStyle, sourceStyle?.fontStyle || cs.fontStyle || ''),
        pdfjsSourceLetterSpacing: immutableSourceString(existingAnnotation?.pdfjsSourceLetterSpacing, cs.letterSpacing || 'normal'),
        pdfjsSourceTransform: immutableSourceString(existingAnnotation?.pdfjsSourceTransform, sourceTransform),
        pdfjsSourceTransformScaleX: immutableSourceString(existingAnnotation?.pdfjsSourceTransformScaleX, sourceScaleX),
        pdfjsSourceTransformRotation: immutableSourceString(existingAnnotation?.pdfjsSourceTransformRotation, sourceRotation),
        pdfjsSourceTextWidthPx: immutableSourceString(existingAnnotation?.pdfjsSourceTextWidthPx, sourceTextAdvanceWidthFromRect(sourceRect, sourceScaleX, sourceTransform)),
        pdfjsSourceTransformOrigin: immutableSourceString(existingAnnotation?.pdfjsSourceTransformOrigin, cs.transformOrigin || '0 0'),
        pdfjsSourceFontSizePx: immutableSourceString(existingAnnotation?.pdfjsSourceFontSizePx, Number.isFinite(fontSizePx) ? fontSizePx : height),
        pdfjsSourceLineHeightPx: immutableSourceString(existingAnnotation?.pdfjsSourceLineHeightPx, Number.isFinite(lineHeightPx) ? lineHeightPx : (Number.isFinite(fontSizePx) ? fontSizePx : height)),
        pdfjsSourceTextColor: immutableSourceString(existingAnnotation?.pdfjsSourceTextColor, textColor),
        pdfjsSourceSpanRuns: immutableSourceString(
            existingAnnotation?.pdfjsSourceSpanRuns,
            (() => {
                const groupForAnchor = sourceGroupForSpan(anchorSpan);
                if (!groupForAnchor) return '';
                const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv || null;
                if (!layerEl) return '';
                const layerRect = layerEl.getBoundingClientRect?.();
                if (!layerRect) return '';
                try {
                    const items = Array.from(groupForAnchor.spans || [])
                        .map((sp) => {
                            const t = String(sp?.textContent || '').trim();
                            if (!t || !sp) return null;
                            const sCs = window.getComputedStyle(sp);
                            const r = sp.getBoundingClientRect();
                            return {
                                text: t,
                                fontFamily: sCs.fontFamily || '',
                                fontWeight: sCs.fontWeight || '',
                                fontStyle: sCs.fontStyle || '',
                                fontSizePx: Number.parseFloat(sCs.fontSize || '') || 0,
                                leftPx: r.left - layerRect.left,
                                rightPx: r.right - layerRect.left,
                                topPx: r.top - layerRect.top,
                                bottomPx: r.bottom - layerRect.top,
                            };
                        })
                        .filter(Boolean)
                        .sort((a, b) => a.leftPx - b.leftPx);
                    return items.length >= 2 ? JSON.stringify(items) : '';
                } catch (_) { return ''; }
            })(),
        ),
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
    const isUserBox = isUserCreatedTextBox(box, existingAnnotation);
    const isStandaloneUserTextBox = box.dataset.userCreated === '1'
        || boolish(existingAnnotation?.userCreated)
        || (box.dataset.skipPdfjsSourceMask === '1'
            && !String(existingAnnotation?.pdfjsSourceText || box.dataset.baseText || '').trim());

    if (isShapeBox(box, existingAnnotation)) {
        const shape = normalizeShapeAnnotation({
            ...(existingAnnotation || {}),
            id: String(existingAnnotation?.id || box.dataset.annotationId || buildPdfjsAnnotationId(pageIndex, box.dataset.uid || generateUuidV4())),
            type: 'shape',
            pageIndex,
            pdfX: pdfRect.x,
            pdfY: pdfRect.y,
            pdfWidth: pdfRect.w,
            pdfHeight: pdfRect.h,
            shapeType: box.dataset.shapeType || existingAnnotation?.shapeType || 'circle',
            strokeColor: box.dataset.strokeColor || existingAnnotation?.strokeColor || '#0f172a',
            strokeOpacity: Number.parseFloat(box.dataset.strokeOpacity || String(existingAnnotation?.strokeOpacity ?? '1')),
            strokeWidth: Number.parseFloat(box.dataset.strokeWidth || String(existingAnnotation?.strokeWidth ?? '3')),
            strokeTransparent: box.dataset.strokeTransparent != null
                ? box.dataset.strokeTransparent === '1'
                : Boolean(existingAnnotation?.strokeTransparent),
            fillColor: box.dataset.fillColor || existingAnnotation?.fillColor || '#22c55e',
            fillOpacity: Number.parseFloat(box.dataset.fillOpacity || String(existingAnnotation?.fillOpacity ?? '0.22')),
            fillTransparent: box.dataset.fillTransparent != null
                ? box.dataset.fillTransparent === '1'
                : Boolean(existingAnnotation?.fillTransparent),
            lineStartX: Number.parseFloat(box.dataset.lineStartX || String(existingAnnotation?.lineStartX ?? '0')),
            lineStartY: Number.parseFloat(box.dataset.lineStartY || String(existingAnnotation?.lineStartY ?? '0')),
            lineEndX: Number.parseFloat(box.dataset.lineEndX || String(existingAnnotation?.lineEndX ?? '1')),
            lineEndY: Number.parseFloat(box.dataset.lineEndY || String(existingAnnotation?.lineEndY ?? '1')),
            lineCap: box.dataset.lineCap || existingAnnotation?.lineCap || 'round',
            rotation: Number.parseFloat(box.dataset.rotation || String(existingAnnotation?.rotation ?? '0')) || 0,
            polygonPoints: existingAnnotation?.polygonPoints,
            text: '',
            userCreated: true,
            locked: box.dataset.locked != null ? box.dataset.locked === '1' : Boolean(existingAnnotation?.locked),
            zIndex: Number.parseInt(box.style.zIndex || box.dataset.zIndex || existingAnnotation?.zIndex || '2', 10) || 2,
        });
        return shape;
    }

    // Image-backed annotations carry a pixel payload — only update geometry.
    if (box.dataset.annotationType === 'signature' || isSignatureAnnotation(existingAnnotation) || isImageBox(box, existingAnnotation)) {
        if (!existingAnnotation) return null;
        return normalizeImageAnnotation({
            ...existingAnnotation,
            pageIndex,
            pdfX: pdfRect.x,
            pdfY: pdfRect.y,
            pdfWidth: pdfRect.w,
            pdfHeight: pdfRect.h,
            locked: box.dataset.locked != null ? box.dataset.locked === '1' : Boolean(existingAnnotation.locked),
            zIndex: Number.parseInt(box.style.zIndex || box.dataset.zIndex || existingAnnotation.zIndex || '6', 10) || 6,
        });
    }
    const baseRect = {
        x: Number.parseFloat(box.dataset.baseBboxX || '') || Number.parseFloat(box.dataset.sourceBboxX || '0') || 0,
        y: Number.parseFloat(box.dataset.baseBboxY || '') || Number.parseFloat(box.dataset.sourceBboxY || '0') || 0,
        w: Number.parseFloat(box.dataset.baseBboxW || '') || Number.parseFloat(box.dataset.sourceBboxW || '0') || 0,
        h: Number.parseFloat(box.dataset.baseBboxH || '') || Number.parseFloat(box.dataset.sourceBboxH || '0') || 0,
    };
    const tc = box.querySelector('.enpv-text-content');
    syncRichTextBoxTypographyFromContent(box);
    const cs = tc ? window.getComputedStyle(tc) : window.getComputedStyle(box);
    const fontSizePts = Number.parseFloat(box.dataset.fontSizePts || '') || (Number.parseFloat(cs.fontSize || '') / scale) || Number(existingAnnotation?.fontSize) || 12;
    const lineHeightPx = Number.parseFloat(cs.lineHeight || '');
    const annotationId = String(existingAnnotation?.id || box.dataset.annotationId || buildPdfjsAnnotationId(pageIndex, box.dataset.uid || '0'));
    const sourceText = isStandaloneUserTextBox ? '' : String(existingAnnotation?.pdfjsSourceText || box.dataset.baseText || box.dataset.originalText || '');
    const textValue = textContentForBox(box);
    const richTextHtml = richTextHtmlForBox(box);
    const visualLines = tc ? readVisualLinesFromBox(tc) : [];
    const shouldPersistVisualLines = visualLines.length > 1
        && normalizeVisualLineComparableText(visualLines.join(' ')) === normalizeVisualLineComparableText(textValue);
    const sourceTextColor = existingAnnotation?.pdfjsSourceTextColor || box.dataset.sourceTextColor || '';
    const preferSourceColor = sourceTextColor
        && box.dataset.styleDirty !== '1'
        && !boolish(existingAnnotation?.styleDirty)
        && box.dataset.userForcedRichText !== '1'
        && !boolish(existingAnnotation?.userForcedRichText)
        && String(box.dataset.editorMode || existingAnnotation?.pdfjsEditorMode || '') !== 'rich';
    const existingSourceMask = annotationSourceMaskPdfBox(existingAnnotation);
    const sourceGeometryMoved = !pdfRectsNearlyEqual(pdfRect, baseRect, 3.0)
        && !(existingSourceMask && pdfRectsNearlyEqual(pdfRect, existingSourceMask, 3.0));
    const styleTextColor = box.style.getPropertyValue('--enpv-text-color') || cs.color || '';
    const textColor = cssColorToHex(
        (preferSourceColor && sourceTextColor)
        || styleTextColor
        || existingAnnotation?.textColor
        || existingAnnotation?.color
        || cs.color
        || '#000000',
    );
    const hasCustomBackground = box.classList.contains('has-custom-bg')
        || (box.dataset.backgroundColor && box.dataset.backgroundColor !== 'transparent'
            && cssColorToHex(box.dataset.backgroundColor, '') !== '#ffffff');
    const backgroundColor = hasCustomBackground
        ? String(
            box.dataset.backgroundColor
            || box.style.getPropertyValue('--enpv-bg-color')
            || existingAnnotation?.backgroundColor
            || 'transparent',
        ).trim()
        : 'transparent';
    const underline = box.dataset.underline != null
        ? box.dataset.underline === '1'
        : boolish(existingAnnotation?.underline);
    const textAlign = normalizeTextAlign(
        box.dataset.textAlign
        || box.style.getPropertyValue('--enpv-text-align')
        || existingAnnotation?.textAlign
        || cs.textAlign,
    );
    const verticalAlign = normalizeVerticalAlign(
        box.dataset.verticalAlign
        || existingAnnotation?.verticalAlign,
    );
    const opacity = normalizeOpacity(box.dataset.opacity || box.style.opacity || existingAnnotation?.opacity, 1);
    const styleFontWeight = box.style.getPropertyValue('--enpv-font-weight');
    const styleFontStyle = box.style.getPropertyValue('--enpv-font-style');
    const annotationFontFamily = String(box.style.getPropertyValue('--enpv-font-family') || existingAnnotation?.fontFamily || cs.fontFamily || '');
    const selectedEmbeddedFont = embeddedFontOptionForValue(box.dataset.fontSourceName || annotationFontFamily);
    const existingEmbeddedFont = embeddedFontOptionForValue(existingAnnotation?.fontSourceName || '');
    const existingFontStillSelected = existingEmbeddedFont
        && normalizeFontKey(annotationFontFamily) === normalizeFontKey(existingAnnotation?.fontFamily || existingAnnotation?.fontSourceName || '');
    const annotationFontSourceName = String(
        box.dataset.fontSourceName
        || selectedEmbeddedFont?.cleanName
        || (existingFontStillSelected ? existingAnnotation?.fontSourceName : '')
        || '',
    ).trim();
    const annotation = {
        ...(existingAnnotation || {}),
        id: annotationId,
        type: 'text',
        pageIndex,
        text: textValue,
        richTextHtml: richTextHtml || undefined,
        pdfjsVisualLines: shouldPersistVisualLines ? visualLines : undefined,
        originalText: String(existingAnnotation?.originalText || box.dataset.originalText || sourceText),
        pdfX: pdfRect.x,
        pdfY: pdfRect.y,
        pdfWidth: pdfRect.w,
        pdfHeight: pdfRect.h,
        fontSize: fontSizePts,
        requestedFontSize: fontSizePts,
        lineHeight: Number.isFinite(lineHeightPx) && scale > 0 ? (lineHeightPx / scale) : (Number(existingAnnotation?.lineHeight) || undefined),
        fontFamily: annotationFontFamily,
        fontSourceName: annotationFontSourceName || undefined,
        forceEmbeddedFont: box.dataset.forceEmbeddedFont === '1' || Boolean(selectedEmbeddedFont) || boolish(existingAnnotation?.forceEmbeddedFont),
        pdfjsForceEmbeddedFont: box.dataset.forceEmbeddedFont === '1' || Boolean(selectedEmbeddedFont) || boolish(existingAnnotation?.pdfjsForceEmbeddedFont),
        fontWeight: String(styleFontWeight || selectedEmbeddedFont?.weight || existingAnnotation?.fontWeight || cs.fontWeight || 'normal'),
        fontStyle: String(styleFontStyle || selectedEmbeddedFont?.style || existingAnnotation?.fontStyle || cs.fontStyle || 'normal'),
        locked: box.dataset.locked != null ? box.dataset.locked === '1' : Boolean(existingAnnotation?.locked),
        zIndex: Number.parseInt(box.style.zIndex || box.dataset.zIndex || existingAnnotation?.zIndex || '2', 10) || 2,
        opacity,
        textColor,
        color: textColor,
        backgroundColor,
        underline,
        textAlign,
        verticalAlign,
        userSizedTextBox: box.dataset.userSizedTextBox === '1' || boolish(existingAnnotation?.userSizedTextBox),
        userCreated: isUserBox || boolish(existingAnnotation?.userCreated),
        userAuthored: isUserBox
            || boolish(existingAnnotation?.userAuthored)
            || (box.dataset.imageToPdfOcr === '1' && (box.dataset.pendingEdit === '1' || box.dataset.pendingResize === '1')),
        imageToPdfOcr: box.dataset.imageToPdfOcr === '1' || boolish(existingAnnotation?.imageToPdfOcr),
        skipPdfjsSourceMask: isUserBox || boolish(existingAnnotation?.skipPdfjsSourceMask),
        savedTextOverlay: true,
        pdfjsSourceX: isStandaloneUserTextBox ? undefined : immutableSourceNumber(existingAnnotation?.pdfjsSourceX, baseRect.x),
        pdfjsSourceY: isStandaloneUserTextBox ? undefined : immutableSourceNumber(existingAnnotation?.pdfjsSourceY, baseRect.y),
        pdfjsSourceW: isStandaloneUserTextBox ? undefined : immutableSourceNumber(existingAnnotation?.pdfjsSourceW, baseRect.w),
        pdfjsSourceH: isStandaloneUserTextBox ? undefined : immutableSourceNumber(existingAnnotation?.pdfjsSourceH, baseRect.h),
        pdfjsSourcePageHeight: isStandaloneUserTextBox ? undefined : immutableSourceNumber(existingAnnotation?.pdfjsSourcePageHeight, box.dataset.basePageHeight ?? (Number(viewport.height) / scale)),
        pdfjsSourceText: isStandaloneUserTextBox ? undefined : immutableSourceString(existingAnnotation?.pdfjsSourceText, sourceText),
        pdfjsAnchorUid: immutableSourceString(existingAnnotation?.pdfjsAnchorUid, box.dataset.uid || ''),
        pdfjsSourceOccurrence: immutableSourceString(existingAnnotation?.pdfjsSourceOccurrence, box.dataset.occurrence || '0'),
        pdfjsEditorMode: box.dataset.editorMode || editorModeForBox(box),
        userForcedRichText: box.dataset.userForcedRichText === '1' || existingAnnotation?.userForcedRichText === true,
        styleDirty: box.dataset.styleDirty === '1' || boolish(existingAnnotation?.styleDirty),
        movedTextOverlay: isStandaloneUserTextBox ? false : ((Math.abs(dxPts) > 0.01 || Math.abs(dyPts) > 0.01) || (existingAnnotation?.movedTextOverlay === true && sourceGeometryMoved)),
        richTextPromotionReason: box.dataset.richTextPromotionReason || existingAnnotation?.richTextPromotionReason || undefined,
    };
    const sourceMaskBox = (!isStandaloneUserTextBox && sourceGeometryMoved)
        ? (boxSourceMaskPdfBox(box) || annotationSourceMaskPdfBox(existingAnnotation))
        : null;
    if (sourceMaskBox) {
        annotation.pdfjsSourceMaskX = sourceMaskBox.x;
        annotation.pdfjsSourceMaskY = sourceMaskBox.y;
        annotation.pdfjsSourceMaskW = sourceMaskBox.w;
        annotation.pdfjsSourceMaskH = sourceMaskBox.h;
        if (box.dataset.sourceMaskAscenderPadding === '1' || boolish(existingAnnotation?.pdfjsSourceMaskAscenderPadding)) {
            annotation.pdfjsSourceMaskAscenderPadding = true;
        }
    }
    copySourceSpanMetricsToAnnotation(annotation, box);
    if (isStandaloneUserTextBox) stripUserCreatedPdfjsSourceMetadata(annotation);

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
        const currentScore = scorePdfRectDistance(currentRect, annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || null);
        const baselineScore = scorePdfRectDistance(currentRect, annotationBaselinePdfBox(annotation) || annotation._originalBox || null);
        const geometryScore = Math.min(currentScore, baselineScore);
        const uidMatches = targetUid && anchorUid && anchorUid === targetUid;
        const uidMismatches = targetUid && anchorUid && anchorUid !== targetUid;
        const textMatches = [annotation.text, annotation.originalText, annotation.pdfjsSourceText]
            .map((value) => normalizeComparableText(value))
            .some((value) => value && (value === targetText || value === targetOriginal));
        if (uidMismatches && !textMatches) continue;
        const score = geometryScore + ((uidMatches || textMatches) ? 0 : 48) + (uidMismatches && !textMatches ? 24 : 0);
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

function deletedPdfjsSourceOwnsSpan(pageIndex, currentRect, text, originalText, sourceUid = '', annotationId = '') {
    const targetId = String(annotationId || '').trim();
    if (targetId && pendingDeletedAnnotationIds.has(targetId)) return true;

    const candidates = annotationBoxesByPage.get(pageIndex) || [];
    const targetText = normalizeComparableText(text || originalText || '');
    const targetUid = String(sourceUid || '').trim();
    const spanSourceProxy = {
        pdfjsSourceX: currentRect?.x,
        pdfjsSourceY: currentRect?.y,
        pdfjsSourceW: currentRect?.w,
        pdfjsSourceH: currentRect?.h,
    };

    return candidates.some((annotation) => {
        if (!annotation || !boolish(annotation.pdfjsDeleted)) return false;
        const deletedOriginalId = String(annotation.pdfjsDeletedAnnotationId || '').trim();
        if (targetId && deletedOriginalId && deletedOriginalId === targetId) return true;
        const deletedUid = String(annotation.pdfjsAnchorUid || '').trim();
        if (targetUid && deletedUid && deletedUid === targetUid) return true;

        const deletedText = normalizeComparableText(
            annotation.pdfjsSourceText
            || annotation.originalText
            || annotation.text
            || '',
        );
        if (targetText && deletedText && targetText !== deletedText) return false;
        return pdfjsSourceBoxesMatch(annotation, spanSourceProxy);
    });
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

function expandCanvasRect(rect, paddingPx = 1) {
    if (!rect) return null;
    const pad = Math.max(0, Number(paddingPx) || 0);
    const left = Math.floor(rect.left - pad);
    const top = Math.floor(rect.top - pad);
    const right = Math.ceil(rect.left + rect.width + pad);
    const bottom = Math.ceil(rect.top + rect.height + pad);
    return {
        left,
        top,
        width: Math.max(1, right - left),
        height: Math.max(1, bottom - top),
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
    const lightBuckets = new Map();
    let sampleCount = 0;
    const step = Math.max(1, Math.floor(Math.sqrt((sw * sh) / 5000)));
    for (let y = 0; y < sh; y += step) {
        for (let x = 0; x < sw; x += step) {
            const offset = ((y * sw) + x) * 4;
            const alpha = data[offset + 3];
            if (alpha < 24) continue;
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            const luminance = (0.299 * r) + (0.587 * g) + (0.114 * b);
            const qr = Math.min(255, Math.round(r / 8) * 8);
            const qg = Math.min(255, Math.round(g / 8) * 8);
            const qb = Math.min(255, Math.round(b / 8) * 8);
            const key = `${qr},${qg},${qb}`;
            sampleCount += 1;
            buckets.set(key, (buckets.get(key) || 0) + 1);
            if (luminance >= 155) {
                lightBuckets.set(key, (lightBuckets.get(key) || 0) + 1);
            }
        }
    }
    if (!buckets.size || sampleCount <= 0) return fallback;
    const bestBucket = (map) => {
        let bestKey = null;
        let bestCount = -1;
        for (const [key, count] of map.entries()) {
            if (count > bestCount) {
                bestKey = key;
                bestCount = count;
            }
        }
        if (!bestKey) return null;
        const [r, g, b] = bestKey.split(',').map((part) => Number.parseInt(part, 10) || 0);
        return { key: bestKey, count: bestCount, r, g, b };
    };
    const bestAll = bestBucket(buckets);
    const bestLight = bestBucket(lightBuckets);
    if (!bestAll) return fallback;

    const allLuminance = (0.299 * bestAll.r) + (0.587 * bestAll.g) + (0.114 * bestAll.b);
    if (allLuminance >= 155) return `rgb(${bestAll.key})`;
    if (bestLight) {
        const allRatio = bestAll.count / sampleCount;
        const saturation = Math.max(bestAll.r, bestAll.g, bestAll.b) - Math.min(bestAll.r, bestAll.g, bestAll.b);
        const coloredBackground = saturation >= 28 && (allRatio >= 0.45 || bestAll.count >= bestLight.count * 1.35);
        const darkBackground = allRatio >= 0.62 && bestAll.count >= bestLight.count * 1.5;
        return (coloredBackground || darkBackground) ? `rgb(${bestAll.key})` : `rgb(${bestLight.key})`;
    }
    return `rgb(${bestAll.key})`;
}

function samplePageSurroundingBackgroundColor(pageDiv, rect, fallback = '') {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return fallback;
    const pageBounds = pageDiv.getBoundingClientRect?.();
    if (!pageBounds || pageBounds.width <= 0 || pageBounds.height <= 0) return fallback;
    const gap = 1;
    const band = Math.max(2, Math.min(10, Math.min(rect.width, rect.height) * 0.35));
    const pad = Math.max(1, Math.min(8, Math.min(rect.width, rect.height) * 0.15));
    const clampRect = (candidate) => {
        const left = Math.max(0, Math.min(pageBounds.width, candidate.left));
        const top = Math.max(0, Math.min(pageBounds.height, candidate.top));
        const right = Math.max(left, Math.min(pageBounds.width, candidate.left + candidate.width));
        const bottom = Math.max(top, Math.min(pageBounds.height, candidate.top + candidate.height));
        const width = right - left;
        const height = bottom - top;
        return width > 0.5 && height > 0.5 ? { left, top, width, height } : null;
    };
    const candidates = [
        { left: rect.left - pad, top: rect.top - gap - band, width: rect.width + (pad * 2), height: band },
        { left: rect.left - pad, top: rect.top + rect.height + gap, width: rect.width + (pad * 2), height: band },
        { left: rect.left - gap - band, top: rect.top - pad, width: band, height: rect.height + (pad * 2) },
        { left: rect.left + rect.width + gap, top: rect.top - pad, width: band, height: rect.height + (pad * 2) },
    ].map(clampRect).filter(Boolean);
    if (!candidates.length) return fallback;

    const buckets = new Map();
    for (const candidate of candidates) {
        const color = parseCssRgb(samplePageBackgroundColor(pageDiv, candidate, ''));
        if (!color) continue;
        const key = [
            Math.min(255, Math.round(color.r / 8) * 8),
            Math.min(255, Math.round(color.g / 8) * 8),
            Math.min(255, Math.round(color.b / 8) * 8),
        ].join(',');
        buckets.set(key, (buckets.get(key) || 0) + (candidate.width * candidate.height));
    }
    if (!buckets.size) return fallback;
    let bestKey = null;
    let bestScore = -1;
    for (const [key, score] of buckets.entries()) {
        if (score > bestScore) {
            bestKey = key;
            bestScore = score;
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

    const localBackground = parseCssRgb(samplePageBackgroundColor(pageDiv, rect, '#ffffff')) || { r: 255, g: 255, b: 255 };
    const surroundingBackground = parseCssRgb(samplePageSurroundingBackgroundColor(pageDiv, rect, ''));
    let background = localBackground;
    if (surroundingBackground) {
        const localLuminance = (0.299 * localBackground.r) + (0.587 * localBackground.g) + (0.114 * localBackground.b);
        const surroundingLuminance = (0.299 * surroundingBackground.r) + (0.587 * surroundingBackground.g) + (0.114 * surroundingBackground.b);
        const localSaturation = Math.max(localBackground.r, localBackground.g, localBackground.b) - Math.min(localBackground.r, localBackground.g, localBackground.b);
        const surroundingSaturation = Math.max(surroundingBackground.r, surroundingBackground.g, surroundingBackground.b) - Math.min(surroundingBackground.r, surroundingBackground.g, surroundingBackground.b);
        const distance = Math.hypot(
            localBackground.r - surroundingBackground.r,
            localBackground.g - surroundingBackground.g,
            localBackground.b - surroundingBackground.b,
        );
        if (distance > 40 && (surroundingSaturation >= 28 || surroundingLuminance < localLuminance - 35 || localSaturation < 35)) {
            background = surroundingBackground;
        }
    }
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
    const left = (canvasBounds.left - pageBounds.left) + (sx + minX) / scaleX;
    const top = (canvasBounds.top - pageBounds.top) + (sy + minY) / scaleY;
    const width = (maxX - minX + 1) / scaleX;
    const height = (maxY - minY + 1) / scaleY;
    return {
        count,
        left,
        top,
        right: left + width,
        bottom: top + height,
        width,
        height,
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

function paintedSourceGroupLayerRect(group, layerEl, pageDiv) {
    const rect = group?.rect || null;
    if (!rect || !layerEl || !pageDiv || rect.width <= 0 || rect.height <= 0) return rect;
    const pageRect = textLayerRectToPageRect(rect, layerEl, pageDiv);
    if (!pageRect) return rect;
    const pad = Math.max(1.5, Math.min(8, rect.height * 0.08));
    const ink = detectForegroundCanvasInkBounds(pageDiv, inflatedCanvasRect(pageRect, pad));
    if (!ink || ink.count < 3 || ink.width <= 0 || ink.height <= 0) return rect;

    const pageBounds = pageDiv.getBoundingClientRect();
    const layerBounds = layerEl.getBoundingClientRect();
    const layerOffsetX = layerBounds.left - pageBounds.left;
    const layerOffsetY = layerBounds.top - pageBounds.top;
    const originalLeft = rect.left;
    const originalTop = rect.top;
    const originalRight = rect.left + rect.width;
    const originalBottom = rect.top + rect.height;
    const left = Math.max(originalLeft, ink.left - layerOffsetX - pad);
    const top = Math.max(originalTop, ink.top - layerOffsetY - pad);
    const right = Math.min(originalRight, ink.right - layerOffsetX + pad);
    const bottom = Math.min(originalBottom, ink.bottom - layerOffsetY + pad);
    const width = right - left;
    const height = bottom - top;
    if (width <= 1 || height <= 1) return rect;
    if (width < Math.min(12, rect.width * 0.12) || height < Math.min(8, rect.height * 0.12)) return rect;
    return { left, top, width, height };
}

function clampSourceGroupLayerRectAgainstNeighbors(group, rect, groups) {
    if (!group?.rect || !rect || !Array.isArray(groups)) return rect;
    let bottom = rect.top + rect.height;
    const left = rect.left;
    const right = rect.left + rect.width;
    for (const other of groups) {
        if (!other || other === group || !other.rect) continue;
        const otherTop = Number(other.rect.top);
        if (!Number.isFinite(otherTop) || otherTop <= rect.top + 1 || otherTop >= bottom) continue;
        const otherLeft = Number(other.rect.left);
        const otherRight = otherLeft + Number(other.rect.width || 0);
        const overlap = Math.min(right, otherRight) - Math.max(left, otherLeft);
        if (overlap <= Math.min(4, rect.width * 0.02)) continue;
        bottom = Math.min(bottom, otherTop - 1);
    }
    const height = bottom - rect.top;
    if (height <= 1) return rect;
    return { ...rect, height };
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
    const sourceInfo = sourceInfoForSpan(spanEl);
    const sourceRect = sourceInfo?.rect || null;
    if (sourceRect) {
        return {
            x: sourceRect.left / scale,
            y: (Number(viewport.height) - (sourceRect.top + sourceRect.height)) / scale,
            w: sourceRect.width / scale,
            h: sourceRect.height / scale,
        };
    }
    const layerRect = layerEl.getBoundingClientRect();
    const spanRect = spanEl.getBoundingClientRect();
    return {
        x: (spanRect.left - layerRect.left) / scale,
        y: (Number(viewport.height) - ((spanRect.top - layerRect.top) + spanRect.height)) / scale,
        w: spanRect.width / scale,
        h: spanRect.height / scale,
    };
}

const SOURCE_MASK_PADDING_PTS = 0.35;
const SOURCE_BOX_VISUAL_PADDING_PX = 0;
const MOVED_SOURCE_MASK_VISUAL_PADDING_PX = 1.5;
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

function movedSourceMaskCanvasRect(rect) {
    if (!rect) return null;
    return {
        left: rect.left,
        top: rect.top,
        width: Math.max(1, rect.width),
        height: Math.max(1, rect.height),
    };
}

function movedSourceMaskCanvasRectFromTypography(rect, source = null) {
    if (!rect) return null;
    return movedSourceMaskCanvasRect(rect);
}

// Source bboxes captured from PDF text-stream metrics describe the
// baseline-to-x-height extent of a run, not the full visible glyph
// rectangle that is painted on the canvas. Ascenders/cap-height extend
// above the bbox top and descenders sit below the baseline. When we
// drop a background-colored mask over the source location to hide the
// original glyphs (after a move or for a saved overlay), the bbox-sized
// mask leaves a thin band of glyph tops/bottoms visible. Pad the rect
// proportionally to the source height so the mask covers the full
// painted extent without depending on font-specific metrics we don't
// have client-side.
function inflateSourceMaskPdfRect(pdfRect, topMultiplier = 0.35) {
    if (!pdfRect) return null;
    const sidePad = Number(SOURCE_MASK_PADDING_PTS) || 0;
    const h = Number(pdfRect.h) || 0;
    const topPad = Math.max(sidePad, h * topMultiplier);
    const bottomPad = Math.max(sidePad, h * 0.15);
    return {
        x: pdfRect.x - sidePad,
        // PDF coords: y is bottom-origin, so subtracting from y extends
        // the rect downward (covering descenders below the baseline).
        y: pdfRect.y - bottomPad,
        w: pdfRect.w + (sidePad * 2),
        h: h + topPad + bottomPad,
    };
}

function explicitSourceMaskNeedsAscenderPadding(source) {
    if (!source) return false;
    const dataset = source.dataset || null;
    if (boolish(source.pdfjsSourceMaskAscenderPadding) || dataset?.sourceMaskAscenderPadding === '1') return true;
    const candidates = dataset
        ? [dataset.sourceText, dataset.originalText]
        : [source.pdfjsSourceText, source.originalText];
    return candidates.every((value) => String(value ?? '').trim() === '');
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
    if (annotation?.fontSourceName) {
        box.dataset.fontSourceName = String(annotation.fontSourceName);
    }
    if (boolish(annotation?.forceEmbeddedFont) || boolish(annotation?.pdfjsForceEmbeddedFont)) {
        box.dataset.forceEmbeddedFont = '1';
    }
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
    const backgroundColor = String(annotation?.backgroundColor || '').trim();
    const isDefaultWhiteBackground = cssColorToHex(backgroundColor, '') === '#ffffff'
        && !boolish(annotation?.backgroundColorExplicit)
        && !boolish(annotation?.hasCustomBackground);
    if (backgroundColor && backgroundColor.toLowerCase() !== 'transparent' && !isDefaultWhiteBackground) {
        const bgHex = cssColorToHex(backgroundColor, backgroundColor);
        box.dataset.backgroundColor = bgHex;
        box.style.setProperty('--enpv-bg-color', bgHex);
        box.classList.add('has-custom-bg');
    } else {
        box.dataset.backgroundColor = 'transparent';
        box.style.setProperty('--enpv-bg-color', 'transparent');
        box.classList.remove('has-custom-bg');
    }
    const opacity = normalizeOpacity(annotation?.opacity, 1);
    box.dataset.opacity = String(opacity);
    box.style.opacity = String(opacity);
    box.style.setProperty('--enpv-opacity', String(opacity));
    const underline = boolish(annotation?.underline);
    box.dataset.underline = underline ? '1' : '0';
    box.style.setProperty('--enpv-text-decoration-line', underline ? 'underline' : 'none');
    box.style.setProperty('--enpv-text-decoration', underline ? 'underline' : 'none');
    const textAlign = normalizeTextAlign(annotation?.textAlign);
    box.dataset.textAlign = textAlign;
    box.style.setProperty('--enpv-text-align', textAlign);
    const verticalAlign = normalizeVerticalAlign(annotation?.verticalAlign);
    box.dataset.verticalAlign = verticalAlign;
}

function captureSourceSpanRunsForBox(box, group, layerEl) {
    if (!box || !group || !layerEl) return;
    try {
        const layerRect = layerEl.getBoundingClientRect?.();
        if (!layerRect) return;
        const items = Array.from(group.spans || [])
            .map((span) => {
                const text = String(span?.textContent || '');
                const trimmed = text.trim();
                if (!trimmed || !span) return null;
                const cs = window.getComputedStyle(span);
                const clientRect = span.getBoundingClientRect();
                return {
                    text: trimmed,
                    fontFamily: cs.fontFamily || '',
                    fontWeight: cs.fontWeight || '',
                    fontStyle: cs.fontStyle || '',
                    fontSizePx: Number.parseFloat(cs.fontSize || '') || 0,
                    leftPx: clientRect.left - layerRect.left,
                    rightPx: clientRect.right - layerRect.left,
                    topPx: clientRect.top - layerRect.top,
                    bottomPx: clientRect.bottom - layerRect.top,
                };
            })
            .filter(Boolean)
            .sort((a, b) => a.leftPx - b.leftPx);
        if (items.length < 2) {
            delete box.dataset.sourceSpanRuns;
            return;
        }
        box.dataset.sourceSpanRuns = JSON.stringify(items);
    } catch (_) { /* noop */ }
}

function captureSourceSpanMetrics(box, spanEl, spanRect, scale, pageDiv = null, sourceStyle = null) {
    if (!box || !spanEl) return;
    const cs = window.getComputedStyle(spanEl);
    const rect = spanRect || spanEl.getBoundingClientRect();
    const fontSizePx = Math.max(1, Number.parseFloat(cs.fontSize || '') || rect.height || 12);
    const transform = cs.transform && cs.transform !== 'none' ? cs.transform : '';
    const transformScaleX = sourceTransformScaleX(transform);
    const transformRotation = sourceTransformRotationDegrees(transform);
    box.dataset.sourceFontFamily = cs.fontFamily || '';
    box.dataset.sourceFontWeight = sourceStyle?.fontWeight || cs.fontWeight || '';
    box.dataset.sourceFontStyle = sourceStyle?.fontStyle || cs.fontStyle || '';
    box.dataset.sourceLetterSpacing = cs.letterSpacing || 'normal';
    box.dataset.sourceTransform = transform;
    box.dataset.sourceTransformScaleX = String(transformScaleX);
    box.dataset.sourceTransformRotation = Number.isFinite(transformRotation) ? String(transformRotation) : '';
    box.dataset.sourceTextWidthPx = String(sourceTextAdvanceWidthFromRect(rect, transformScaleX, transform));
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
    const restoredScaleX = Number.parseFloat(annotation.pdfjsSourceTransformScaleX || '');
    const hasValidRestoredScaleX = !Number.isFinite(restoredScaleX) || (restoredScaleX >= 0.25 && restoredScaleX <= 3);
    const mappings = [
        ['sourceFontFamily', 'pdfjsSourceFontFamily'],
        ['sourceFontWeight', 'pdfjsSourceFontWeight'],
        ['sourceFontStyle', 'pdfjsSourceFontStyle'],
        ['sourceLetterSpacing', 'pdfjsSourceLetterSpacing'],
        ['sourceTransform', 'pdfjsSourceTransform'],
        ['sourceTransformScaleX', 'pdfjsSourceTransformScaleX'],
        ['sourceTransformRotation', 'pdfjsSourceTransformRotation'],
        ['sourceTextWidthPx', 'pdfjsSourceTextWidthPx'],
        ['sourceTransformOrigin', 'pdfjsSourceTransformOrigin'],
        ['sourceFontSizePx', 'pdfjsSourceFontSizePx'],
        ['sourceLineHeightPx', 'pdfjsSourceLineHeightPx'],
        ['sourceTextColor', 'pdfjsSourceTextColor'],
        ['sourceSpanRuns', 'pdfjsSourceSpanRuns'],
    ];
    for (const [datasetKey, annotationKey] of mappings) {
        if (!hasValidRestoredScaleX && ['sourceTransform', 'sourceTransformScaleX', 'sourceTextWidthPx', 'sourceTransformOrigin'].includes(datasetKey)) {
            continue;
        }
        const value = annotation[annotationKey];
        if (value != null && String(value) !== '') box.dataset[datasetKey] = String(value);
    }
}

function sourceTransformScaleX(transform) {
    if (!transform || transform === 'none') return 1;
    try {
        const matrix = new DOMMatrixReadOnly(transform);
        return Math.hypot(matrix.a, matrix.b) || 1;
    } catch (_) {
        return 1;
    }
}

function sourceTransformRotationDegrees(transform) {
    if (!transform || transform === 'none') return 0;
    try {
        const matrix = new DOMMatrixReadOnly(transform);
        const angle = Math.atan2(matrix.b, matrix.a) * (180 / Math.PI);
        return Number.isFinite(angle) ? angle : 0;
    } catch (_) {
        const match = String(transform).match(/matrix\(\s*([-\d.]+)\s*,\s*([-\d.]+)/i);
        if (!match) return 0;
        const a = Number.parseFloat(match[1]);
        const b = Number.parseFloat(match[2]);
        if (!Number.isFinite(a) || !Number.isFinite(b)) return 0;
        return Math.atan2(b, a) * (180 / Math.PI);
    }
}

function sourceTextAdvanceWidthFromRect(rect, transformScaleX = 1, transform = '') {
    if (!rect) return 0;
    const scaleX = Number(transformScaleX) || 1;
    const visualAdvance = sourceItemTransformIsVertical(transform)
        ? Number(rect.height)
        : Number(rect.width);
    return Math.max(1, visualAdvance / Math.max(0.0001, scaleX));
}

function sourceFidelityTextIsVertical(box) {
    const transform = String(box?.dataset?.sourceTransform || '');
    if (sourceItemTransformIsVertical(transform)) return true;
    const rotation = Number.parseFloat(box?.dataset?.sourceTransformRotation || '');
    return Number.isFinite(rotation) && Math.abs(Math.sin(rotation * (Math.PI / 180))) > 0.5;
}

function sourceFidelityVerticalTransform(box, textAdvancePx, fitScaleX = 1) {
    const rotation = Number.parseFloat(box?.dataset?.sourceTransformRotation || '');
    const angle = Number.isFinite(rotation) && Math.abs(rotation) > 1
        ? rotation
        : sourceTransformRotationDegrees(String(box?.dataset?.sourceTransform || ''));
    const scale = Number.isFinite(Number(fitScaleX)) && Number(fitScaleX) > 0 ? Number(fitScaleX) : 1;
    const scalePart = Math.abs(scale - 1) < 0.0001 ? '' : ` scaleX(${scale})`;
    if (angle > 0) {
        const lineHeightPx = Number.parseFloat(selectedBoxTextElement(box)?.style?.lineHeight || '')
            || Number.parseFloat(box?.style?.getPropertyValue?.('--enpv-line-height') || '')
            || Number.parseFloat(box?.style?.getPropertyValue?.('--enpv-font-size') || '')
            || Number(box?.clientWidth)
            || Number.parseFloat(box?.style?.width || '')
            || 1;
        return `translate(${lineHeightPx}px, 0px) rotate(90deg)${scalePart}`;
    }
    const advancePx = Math.max(1, Number(textAdvancePx) || 1) * scale;
    return `translate(0px, ${advancePx}px) rotate(-90deg)${scalePart}`;
}

function sourceSpanPdfRectFromPromotedSpan(sourceSpan, pageHeight) {
    const bbox = sourceSpan?.bbox;
    if (!Array.isArray(bbox) || bbox.length < 4 || !(pageHeight > 0)) return null;
    const x0 = Number(bbox[0]);
    const y0 = Number(bbox[1]);
    const x1 = Number(bbox[2]);
    const y1 = Number(bbox[3]);
    if (![x0, y0, x1, y1].every(Number.isFinite) || x1 <= x0 || y1 <= y0) return null;
    return { x: x0, y: pageHeight - y1, w: x1 - x0, h: y1 - y0 };
}

function fontWeightFromPromotedSourceSpan(sourceSpan) {
    const explicit = String(sourceSpan?.fontWeight || sourceSpan?.font_weight || '').trim();
    const parsed = Number.parseInt(explicit, 10);
    if (Number.isFinite(parsed) && parsed > 0) return String(parsed);
    if (boolish(sourceSpan?.bold)) return '700';
    const fontName = String(sourceSpan?.font || sourceSpan?.embedded_font_name || '').toLowerCase();
    if (/bold|black|heavy|semibold|demibold/.test(fontName)) return '700';
    return '';
}

function fontStyleFromPromotedSourceSpan(sourceSpan) {
    if (boolish(sourceSpan?.italic)) return 'italic';
    const fontName = String(sourceSpan?.font || sourceSpan?.embedded_font_name || '').toLowerCase();
    return /italic|oblique/.test(fontName) ? 'italic' : '';
}

function promotedSourceStyleForPdfjsSource(pageIndex, sourceRect, sourceText, pageHeight) {
    if (!sourceRect || !(pageHeight > 0)) return null;
    const targetText = normalizeComparableText(sourceText);
    let best = null;
    let bestScore = Number.POSITIVE_INFINITY;
    for (const annotation of annotationBoxesByPage.get(pageIndex) || []) {
        if (!isPromotedExtractionAnnotation(annotation) || !Array.isArray(annotation.sourceSpans)) continue;
        for (const sourceSpan of annotation.sourceSpans) {
            const spanRect = sourceSpanPdfRectFromPromotedSpan(sourceSpan, pageHeight);
            if (!spanRect) continue;
            const overlap = pdfRectOverlapArea(sourceRect, spanRect);
            const targetRatio = overlap / Math.max(0.0001, pdfRectArea(sourceRect));
            const spanRatio = overlap / Math.max(0.0001, pdfRectArea(spanRect));
            if (targetRatio < 0.45 && spanRatio < 0.45) continue;
            const spanText = normalizeComparableText(sourceSpan.text || sourceSpan.rawText || sourceSpan.render_text || '');
            const textMatches = !targetText || !spanText || targetText === spanText;
            const score = scorePdfRectDistance(sourceRect, spanRect) + (textMatches ? 0 : 12);
            if (score < bestScore) {
                bestScore = score;
                best = sourceSpan;
            }
        }
    }
    if (!best) return null;
    return {
        fontWeight: fontWeightFromPromotedSourceSpan(best),
        fontStyle: fontStyleFromPromotedSourceSpan(best),
    };
}

function applySourceFidelityTextFit(box, tc) {
    if (!box || !tc) return;
    const rawSourceScaleX = Number.parseFloat(box.dataset.sourceTransformScaleX || '1') || 1;
    const sourceScaleX = rawSourceScaleX >= 0.25 && rawSourceScaleX <= 3 ? rawSourceScaleX : 1;
    const isVertical = sourceFidelityTextIsVertical(box);
    const targetWidth = Math.max(
        1,
        Number(isVertical ? box.clientHeight : box.clientWidth) || 0,
        Number(isVertical ? box.getBoundingClientRect?.().height : box.getBoundingClientRect?.().width) || 0,
        Number.parseFloat(isVertical ? box.style.height : box.style.width || '') || 0,
    );
    const sourceLayoutWidth = Math.max(
        Number.parseFloat(tc.style.width || '') || 0,
        Number.parseFloat(box.dataset.sourceTextWidthPx || '') || 0,
    );
    let rangeContentWidth = 0;
    if (tc.isConnected) {
        const previousTransform = tc.style.transform;
        tc.style.transform = '';
        const range = document.createRange();
        range.selectNodeContents(tc);
        rangeContentWidth = range.getBoundingClientRect().width || 0;
        range.detach?.();
        tc.style.transform = previousTransform;
    }
    const measuredContentWidth = Math.max(
        rangeContentWidth,
        Number(tc.scrollWidth) || 0,
        sourceLayoutWidth,
    );
    if (!(targetWidth > 0) || !(measuredContentWidth > 0)) return;
    if (sourceLayoutWidth > 0 && measuredContentWidth > sourceLayoutWidth * 1.35) return;
    const fitScaleX = Math.min(sourceScaleX, targetWidth / measuredContentWidth);
    if (!(fitScaleX > 0) || !Number.isFinite(fitScaleX)) return;
    tc.style.width = `${measuredContentWidth}px`;
    tc.style.transformOrigin = '0 0';
    if (isVertical) {
        tc.style.height = tc.style.lineHeight || box.style.getPropertyValue('--enpv-line-height') || box.style.getPropertyValue('--enpv-font-size') || '';
        tc.style.transform = sourceFidelityVerticalTransform(box, measuredContentWidth, fitScaleX);
    } else {
        tc.style.transform = Math.abs(fitScaleX - 1) < 0.0001
            ? ''
            : `matrix(${fitScaleX}, 0, 0, 1, 0, 0)`;
    }
}

function refreshAttachedSourceFidelityTextFit(box) {
    if (!box?.classList?.contains('is-source-fidelity')) return;
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    applySourceFidelityTextFit(box, tc);
    requestAnimationFrame?.(() => {
        if (!box.isConnected) return;
        applySourceFidelityTextFit(box, tc);
    });
}

function copySourceSpanMetricsToAnnotation(annotation, box, options = {}) {
    if (!annotation || !box) return annotation;
    if (!box.dataset.sourceFontFamily && !box.dataset.sourceTransform && !box.dataset.sourceFontSizePx && !box.dataset.sourceSpanRuns) return annotation;
    const allowSourceRefresh = options.allowSourceRefresh === true;
    const fillMetric = (annotationKey, datasetKey) => {
        const existing = annotation[annotationKey];
        if (!allowSourceRefresh && existing != null && String(existing) !== '') return;
        const value = box.dataset[datasetKey];
        if (value != null && String(value) !== '') {
            annotation[annotationKey] = String(value);
        } else if (annotation[annotationKey] == null) {
            annotation[annotationKey] = '';
        }
    };
    annotation.pdfjsSourceFidelity = true;
    fillMetric('pdfjsSourceFontFamily', 'sourceFontFamily');
    fillMetric('pdfjsSourceFontWeight', 'sourceFontWeight');
    fillMetric('pdfjsSourceFontStyle', 'sourceFontStyle');
    fillMetric('pdfjsSourceLetterSpacing', 'sourceLetterSpacing');
    fillMetric('pdfjsSourceTransform', 'sourceTransform');
    fillMetric('pdfjsSourceTransformScaleX', 'sourceTransformScaleX');
    fillMetric('pdfjsSourceTransformRotation', 'sourceTransformRotation');
    fillMetric('pdfjsSourceTextWidthPx', 'sourceTextWidthPx');
    fillMetric('pdfjsSourceTransformOrigin', 'sourceTransformOrigin');
    fillMetric('pdfjsSourceFontSizePx', 'sourceFontSizePx');
    fillMetric('pdfjsSourceLineHeightPx', 'sourceLineHeightPx');
    fillMetric('pdfjsSourceTextColor', 'sourceTextColor');
    fillMetric('pdfjsSourceSpanRuns', 'sourceSpanRuns');
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
    const isVertical = sourceFidelityTextIsVertical(box);
    if (Number.isFinite(sourceTextWidthPx) && sourceTextWidthPx > 0) {
        tc.style.width = `${sourceTextWidthPx * sourceRatio}px`;
    }
    tc.style.lineHeight = lineHeightPx;
    tc.style.transformOrigin = isVertical ? '0 0' : (box.dataset.sourceTransformOrigin || '0 0');
    tc.style.transform = isVertical
        ? sourceFidelityVerticalTransform(box, Number.isFinite(sourceTextWidthPx) ? sourceTextWidthPx * sourceRatio : box.clientHeight || 1)
        : (box.dataset.sourceTransform || '');
    applySourceFidelityTextFit(box, tc);
    if (options.editing) {
        box.dataset.sourceFidelityEditing = '1';
        tc.style.height = isVertical ? lineHeightPx : 'auto';
        tc.style.minHeight = lineHeightPx;
        tc.style.overflow = 'visible';
    }
}

// --- Source-fidelity per-span edit markup (Option B) -----------------------
//
// In source-fidelity mode the visible PDF text is a sequence of pdf.js spans
// at absolute x-positions, often with mixed font weights (e.g. bold "MON"
// followed by regular "6A-10P"). The committed annotation `text` collapses
// that to a single-spaced string. When the user enters edit mode we'd
// otherwise paint the whole line in one font-weight with single spaces, which
// destroys both the per-word weight AND the visible inter-word gaps.
//
// On entering edit mode we replace `tc.textContent` with structured spans —
// one `.enpv-edit-run` per source span (carrying font-weight/font-style),
// with `.enpv-edit-gap` separator spans containing enough literal spaces to
// cover the actual pdf.js gap. `white-space: pre-wrap` keeps those spaces
// from collapsing, and `textContent` then matches the visual offset.
//
// On endEditMode we strip the markup and restore plain text so the
// source-fidelity render path (which uses one font-weight) takes over again.

const HTML_ESCAPE_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
function escapeHtmlForSpanEdit(value) {
    return String(value).replace(/[&<>"']/g, (ch) => HTML_ESCAPE_MAP[ch] || ch);
}

function measureStandardSpaceWidthPx(fontFamily, fontWeight, fontStyle, fontSizePx) {
    const canvas = measureStandardSpaceWidthPx._canvas
        || (measureStandardSpaceWidthPx._canvas = document.createElement('canvas'));
    const ctx = canvas.getContext('2d');
    if (!ctx) return 0;
    const family = fontFamily || 'sans-serif';
    const weight = fontWeight || 'normal';
    const style = fontStyle || 'normal';
    const size = Number(fontSizePx) > 0 ? Number(fontSizePx) : 12;
    ctx.font = `${style} ${weight} ${size}px ${family}`;
    const metrics = ctx.measureText(' ');
    return metrics?.width || size * 0.27;
}

function cssQuoteFontFamily(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const normalized = raw.replace(/"/g, "'");
    if (normalized.includes(',')) return normalized;
    if (/^["'].*["']$/.test(normalized)) return normalized;
    if (/^[a-zA-Z_][\w-]*$/.test(normalized)) return normalized;
    return `'${normalized.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function normalizeSourceRunText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function sourceRunTextWithSyntheticGaps(items, start = 0, end = items.length) {
    let output = '';
    let prev = null;
    for (const item of items.slice(start, end)) {
        if (prev) {
            const gapPx = Math.max(0, Number(item.leftPx) - Number(prev.rightPx));
            const standardSpacePx = measureStandardSpaceWidthPx(
                item.fontFamily,
                item.fontWeight,
                item.fontStyle,
                item.fontSizePx,
            );
            if (gapPx > Math.max(1, standardSpacePx * 0.45)) output += ' ';
        }
        output += String(item.text || '');
        prev = item;
    }
    return normalizeSourceRunText(output);
}

function remapSourceRunItemsForCurrentText(items, currentText) {
    const normalizedCurrent = normalizeSourceRunText(currentText);
    if (!normalizedCurrent) return items;
    const normalizedOriginal = sourceRunTextWithSyntheticGaps(items);
    if (normalizedCurrent === normalizedOriginal) return items;

    const cloneItems = () => items.map((item) => ({ ...item, sourceText: String(item.text || '') }));
    const applyChangedText = (nextItems, index, text) => {
        const normalized = normalizeSourceRunText(text);
        if (!normalized) return null;
        nextItems[index].text = normalized;
        nextItems[index].textChangedFromSource = normalized !== normalizeSourceRunText(nextItems[index].sourceText);
        return nextItems;
    };

    if (items.length >= 2) {
        const suffix = sourceRunTextWithSyntheticGaps(items, 1);
        if (suffix && normalizedCurrent.endsWith(suffix)) {
            const changedPrefix = normalizedCurrent.slice(0, normalizedCurrent.length - suffix.length).trim();
            const nextItems = applyChangedText(cloneItems(), 0, changedPrefix);
            if (nextItems) return nextItems;
        }

        const prefix = sourceRunTextWithSyntheticGaps(items, 0, items.length - 1);
        if (prefix && normalizedCurrent.startsWith(prefix)) {
            const changedSuffix = normalizedCurrent.slice(prefix.length).trim();
            const nextItems = applyChangedText(cloneItems(), items.length - 1, changedSuffix);
            if (nextItems) return nextItems;
        }

        if (items.length === 2) {
            const match = normalizedCurrent.match(/^(\S+)\s+(.+)$/u);
            if (match) {
                const nextItems = cloneItems();
                if (applyChangedText(nextItems, 0, match[1]) && applyChangedText(nextItems, 1, match[2])) {
                    return nextItems;
                }
            }
        }
    }

    return null;
}

function buildSourceEditSpanItems(group, layerEl) {
    if (!group || !layerEl) return [];
    const items = liveSourceGroupItems(group, layerEl);
    if (!items.length) return [];
    const layerRect = layerEl.getBoundingClientRect?.();
    return items.map((item, index) => {
        const span = item.span;
        const cs = span ? window.getComputedStyle(span) : null;
        const text = String(item.text || '').replace(/\s+/g, ' ');
        const trimmed = text.trim();
        if (!trimmed) return null;
        const clientRect = span?.getBoundingClientRect?.();
        const leftPx = layerRect && clientRect ? (clientRect.left - layerRect.left) : item.rect.left;
        const rightPx = layerRect && clientRect ? (clientRect.right - layerRect.left) : (item.rect.left + item.rect.width);
        const topPx = layerRect && clientRect ? (clientRect.top - layerRect.top) : item.rect.top;
        const bottomPx = layerRect && clientRect ? (clientRect.bottom - layerRect.top) : (item.rect.top + item.rect.height);
        return {
            text: trimmed,
            fontFamily: cs?.fontFamily || '',
            fontWeight: cs?.fontWeight || '',
            fontStyle: cs?.fontStyle || '',
            fontSizePx: Number.parseFloat(cs?.fontSize || '') || 0,
            leftPx,
            rightPx,
            topPx,
            bottomPx,
            index,
        };
    }).filter(Boolean);
}

function sourceGroupForBox(box) {
    if (!box) return null;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return null;
    const uid = String(box.dataset.uid || '');
    let cached = sourceGroupsByPage.get(pageIndex);
    // If cache miss, try to build from the live pdf.js text layer.
    if (!cached) {
        try {
            const pageView = pdfViewer?.getPageView?.(pageIndex);
            const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
            if (layerEl) {
                getSourceGroupsForPage(pageIndex, layerEl, pageView.div || null);
                cached = sourceGroupsByPage.get(pageIndex);
            }
        } catch (_) { /* noop */ }
    }
    if (!cached) return null;
    const groups = cached.groups || [];
    const annotation = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (annotation) {
        const matched = sourceGroupForAnnotation(annotation, pageIndex, cached.layerEl);
        if (matched) return { group: matched, layerEl: cached.layerEl };
    }
    const direct = groups.find((g) => `${pageIndex}:${g.index}` === uid);
    if (direct) return { group: direct, layerEl: cached.layerEl };
    return null;
}

function buildSourceSpanItemsFromAnnotation(annotation, scale = 1) {
    const sourceSpans = Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans : [];
    return sourceSpans
        .map((span, index) => {
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            if (!bbox || bbox.length < 4) return null;
            const text = normalizeSourceRunText(span?.text || span?.rawText || span?.render_text || '');
            if (!text) return null;
            const x0 = Number(bbox[0]);
            const y0 = Number(bbox[1]);
            const x1 = Number(bbox[2]);
            const y1 = Number(bbox[3]);
            if (![x0, y0, x1, y1].every(Number.isFinite) || x1 <= x0 || y1 <= y0) return null;
            const explicitWeight = String(span?.fontWeight || span?.font_weight || '').trim();
            const parsedWeight = Number.parseInt(explicitWeight, 10);
            const fontWeight = Number.isFinite(parsedWeight) && parsedWeight > 0
                ? String(parsedWeight)
                : (boolish(span?.bold) ? '700' : '400');
            const fontStyle = boolish(span?.italic) ? 'italic' : 'normal';
            return {
                text,
                fontFamily: String(span?.embedded_font_name || span?.font || span?.embedded_font_family || ''),
                fontWeight,
                fontStyle,
                fontSizePx: (Number(span?.fontSize || span?.font_size) || 0) * (Number(scale) || 1),
                leftPx: x0 * (Number(scale) || 1),
                rightPx: x1 * (Number(scale) || 1),
                topPx: y0 * (Number(scale) || 1),
                bottomPx: y1 * (Number(scale) || 1),
                index,
            };
        })
        .filter(Boolean)
        .sort((a, b) => a.leftPx - b.leftPx);
}

function applySourceFidelitySpanEditMarkup(box, options = {}) {
    if (!box) return false;
    const tc = box.querySelector('.enpv-text-content');
    if (!tc) return false;
    if (boxTextHasNewline(box)) return false;
    const purpose = options.purpose === 'display' ? 'display' : 'edit';
    let items = null;
    const raw = box.dataset.sourceSpanRuns;
    if (raw) {
        try { items = JSON.parse(raw); } catch (_) { items = null; }
    }
    if (!Array.isArray(items) || items.length < 2) {
        // Fallback: try to recompute from the live pdf.js text layer if it
        // is currently in the DOM. Persisted overlays saved before per-span
        // capture was added will hit this path on first edit.
        const lookup = sourceGroupForBox(box);
        if (lookup) {
            items = buildSourceEditSpanItems(lookup.group, lookup.layerEl);
            if (Array.isArray(items) && items.length >= 2) {
                try { box.dataset.sourceSpanRuns = JSON.stringify(items); } catch (_) { /* noop */ }
            }
        }
    }
    if ((!Array.isArray(items) || items.length < 2) && box.dataset.annotationId) {
        const annotation = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '')
            || Number.parseFloat(box.dataset.renderScale || '')
            || 1;
        items = buildSourceSpanItemsFromAnnotation(annotation, scale);
        if (Array.isArray(items) && items.length >= 2) {
            try { box.dataset.sourceSpanRuns = JSON.stringify(items); } catch (_) { /* noop */ }
        }
    }
    if (!Array.isArray(items) || items.length < 2) return false;

    // Convert captured per-run size/top offsets to the current render scale.
    const referenceFontSizePx = Math.max(1, ...items.map((item) => Number(item.fontSizePx) || 0));
    const baselineItems = items.filter((item) => {
        const size = Number(item.fontSizePx) || referenceFontSizePx;
        return size >= referenceFontSizePx * 0.9 && Number.isFinite(Number(item.topPx));
    });
    const referenceTopPx = baselineItems.length
        ? Math.min(...baselineItems.map((item) => Number(item.topPx)))
        : Math.min(...items.map((item) => Number(item.topPx)).filter(Number.isFinite));
    const currentScale = Number.parseFloat(box.parentElement?.dataset?.scale || '') || 0;
    const originalScale = Number.parseFloat(box.dataset.renderScale || '') || currentScale || 0;
    const sourceRunScaleRatio = currentScale > 0 && originalScale > 0 ? currentScale / originalScale : 1;

    // Skip if user previously edited the text away from the original. Insert a
    // synthetic space only when the captured visual gap is at least space-like;
    // superscripts are often separate PDF.js spans with no real text gap.
    let reconstructed = '';
    let previousItem = null;
    for (const item of items) {
        if (previousItem) {
            const gapPx = Math.max(0, Number(item.leftPx) - Number(previousItem.rightPx));
            const standardSpacePx = measureStandardSpaceWidthPx(
                item.fontFamily,
                item.fontWeight,
                item.fontStyle,
                item.fontSizePx,
            );
            if (gapPx > Math.max(1, standardSpacePx * 0.45)) reconstructed += ' ';
        }
        reconstructed += String(item.text || '');
        previousItem = item;
    }
    const currentText = String(tc.textContent || '').replace(/\s+/g, ' ').trim();
    reconstructed = reconstructed.replace(/\s+/g, ' ').trim();
    if (currentText && currentText !== reconstructed) {
        const whitespaceOnlySourceDifference = purpose === 'display'
            && currentText.replace(/\s+/g, '') === reconstructed.replace(/\s+/g, '');
        if (!whitespaceOnlySourceDifference) {
            items = remapSourceRunItemsForCurrentText(items, currentText);
            if (!Array.isArray(items) || items.length < 2) return false;
        }
    }

    let html = '';
    let prev = null;
    for (const item of items) {
        if (prev) {
            const gapPx = Math.max(0, item.leftPx - prev.rightPx);
            const standardSpacePx = measureStandardSpaceWidthPx(
                item.fontFamily,
                item.fontWeight,
                item.fontStyle,
                item.fontSizePx,
            );
            if (gapPx > Math.max(1, standardSpacePx * 0.45)) {
                const spaceCount = Math.max(1, Math.min(120, Math.round(gapPx / Math.max(0.1, standardSpacePx))));
                html += `<span class="enpv-edit-gap" data-source-span-gap="1" data-source-span-gap-spaces="${spaceCount}">${' '.repeat(spaceCount)}</span>`;
            }
        }
        const styleParts = [];
        const fontFamily = String(item.fontFamily || '').trim();
        if (fontFamily) {
            styleParts.push(`font-family:${item.textChangedFromSource ? 'Arial, sans-serif' : cssQuoteFontFamily(fontFamily)}`);
        } else if (item.textChangedFromSource) {
            styleParts.push('font-family:Arial, sans-serif');
        }
        if (item.fontWeight) styleParts.push(`font-weight:${item.fontWeight}`);
        if (item.fontStyle && item.fontStyle !== 'normal') styleParts.push(`font-style:${item.fontStyle}`);
        const itemFontSizePx = Number(item.fontSizePx) || 0;
        const itemTopPx = Number(item.topPx);
        if (itemFontSizePx > 0 && referenceFontSizePx > 0 && Math.abs(itemFontSizePx - referenceFontSizePx) > 0.25) {
            styleParts.push(`font-size:${(itemFontSizePx / referenceFontSizePx).toFixed(4)}em`);
        }
        if (Number.isFinite(itemTopPx) && Number.isFinite(referenceTopPx)) {
            const topDelta = (itemTopPx - referenceTopPx) * sourceRunScaleRatio;
            if (Math.abs(topDelta) > 0.35) {
                styleParts.push('position:relative');
                styleParts.push(`top:${topDelta.toFixed(2)}px`);
            }
        }
        const styleAttr = styleParts.length ? ` style="${styleParts.join(';')}"` : '';
        html += `<span class="enpv-edit-run" data-source-span-run="1"${styleAttr}>${escapeHtmlForSpanEdit(String(item.text || ''))}</span>`;
        prev = item;
    }
    tc.innerHTML = html;
    if (purpose === 'display') {
        // Display path: keep whitespace handling that the source-fidelity
        // typography already configured (`pre`) so the line never wraps and
        // the gap-spans preserve the captured horizontal positions.
        box.dataset.sourceSpanDisplayActive = '1';
    } else {
        tc.style.whiteSpace = 'pre-wrap';
        box.dataset.sourceSpanEditActive = '1';
    }
    return true;
}

function clearSourceFidelitySpanEditMarkup(box) {
    if (!box) return;
    const isEdit = box.dataset.sourceSpanEditActive === '1';
    const isDisplay = box.dataset.sourceSpanDisplayActive === '1';
    if (!isEdit && !isDisplay) return;
    const tc = box.querySelector('.enpv-text-content');
    if (tc) {
        const flat = String(tc.textContent || '');
        tc.textContent = flat;
    }
    if (isEdit) delete box.dataset.sourceSpanEditActive;
    if (isDisplay) delete box.dataset.sourceSpanDisplayActive;
}

function clearSourceFidelitySpanState(box) {
    if (!box) return;
    delete box.dataset.sourceSpanEditActive;
    delete box.dataset.sourceSpanDisplayActive;
}

function boxHasSourceTypography(box) {
    return Boolean(box?.dataset?.sourceFontFamily || box?.dataset?.sourceTransform || box?.dataset?.sourceFontSizePx);
}

function boxTextHasNewline(box) {
    const text = selectedBoxTextElement(box)?.textContent || '';
    return /\r|\n/.test(String(text));
}

function editorModeForBox(box) {
    if (!box) return 'rich';
    if (box.dataset.userSizedTextBox === '1') return 'rich';
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

function clearTransientSourceFidelity(box) {
    if (!box || box.classList.contains('is-persisted-overlay')) return;
    box.classList.remove('is-source-fidelity');
    delete box.dataset.sourceFidelityEditing;
    resetSourceFidelityInlineStyles(box);
    // The box is reverting to canvas-rendered text. Strip any leftover
    // per-span display markup (which would otherwise render with no font-size
    // because `.is-source-fidelity` typography is gone) and detach the source
    // mask so the underlying PDF.js canvas glyphs become visible again.
    // Without these, double-click → edit → deselect leaves the box with
    // display:none text content layered over a white mask that hides the
    // original glyphs — the row appears empty.
    clearSourceFidelitySpanEditMarkup(box);
    removeAnnBoxSourceMasks(box);
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

function sourceGroupPdfRect(group, viewport, scale) {
    if (!group?.rect || !viewport || !(scale > 0)) return null;
    return {
        x: group.rect.left / scale,
        y: (Number(viewport.height) - (group.rect.top + group.rect.height)) / scale,
        w: group.rect.width / scale,
        h: group.rect.height / scale,
    };
}

function currentSourceGroupForAnnotation(annotation, pageIndex, viewport, scale) {
    if (!annotation || !viewport || !(scale > 0)) return null;
    const anchor = String(annotation.pdfjsAnchorUid || '').trim();
    const match = anchor.match(/^(\d+):(\d+)$/);
    if (!match || Number.parseInt(match[1], 10) !== pageIndex) return null;
    const groupIndex = Number.parseInt(match[2], 10);
    if (!Number.isFinite(groupIndex)) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div || null;
    const textLayerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!textLayerEl) return null;
    return getSourceGroupsForPage(pageIndex, textLayerEl, pageDiv)
        .find((group) => group.index === groupIndex) || null;
}

function currentSourceGroupsOverlappingAnnotationSource(annotation, pageIndex, viewport, scale) {
    if (!annotation || !viewport || !(scale > 0)) return [];
    const sourceRect = annotationBaselinePdfBox(annotation) || annotationCurrentPdfBox(annotation);
    if (!sourceRect) return [];
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div || null;
    const textLayerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!textLayerEl) return [];
    return getSourceGroupsForPage(pageIndex, textLayerEl, pageDiv)
        .map((group) => ({ group, pdfRect: sourceGroupPdfRect(group, viewport, scale) }))
        .filter((entry) => {
            const groupRect = entry.pdfRect;
            if (!groupRect) return false;
            const overlap = pdfRectOverlapArea(sourceRect, groupRect);
            const groupRatio = overlap / Math.max(0.0001, pdfRectArea(groupRect));
            const sourceRatio = overlap / Math.max(0.0001, pdfRectArea(sourceRect));
            return groupRatio >= 0.45 || sourceRatio >= 0.18;
        })
        .sort((left, right) => left.pdfRect.x - right.pdfRect.x)
        .map((entry) => entry.group);
}

function isStaleCompositePdfjsSourceOverlay(annotation, pageIndex, viewport, scale) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (!boolish(annotation.savedTextOverlay) || boolish(annotation.pdfjsDeleted)) return false;
    if (boolish(annotation.movedTextOverlay)) return false;
    if (boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return false;
    if (String(annotation.pdfjsEditorMode || 'source') === 'rich') return false;
    const sourceText = normalizeVisualLineComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    const annotationText = normalizeVisualLineComparableText(annotation.text || '');
    if (!sourceText || (annotationText && annotationText !== sourceText)) return false;
    const currentGroup = currentSourceGroupForAnnotation(annotation, pageIndex, viewport, scale);
    if (currentGroup && normalizeVisualLineComparableText(currentGroup.text || '') === sourceText) return false;
    const overlappingGroups = currentSourceGroupsOverlappingAnnotationSource(annotation, pageIndex, viewport, scale);
    if (overlappingGroups.length < 2) return false;
    const combined = normalizeVisualLineComparableText(overlappingGroups.map((group) => group.text || '').join(' '));
    return combined === sourceText;
}

function isSuppressedStalePdfjsOverlay(annotation) {
    const id = String(annotation?.id || '');
    if (id === '' || !suppressedStalePdfjsOverlayIds.has(id)) return false;
    if (boolish(annotation?.movedTextOverlay)
        || boolish(annotation?.styleDirty)
        || boolish(annotation?.userForcedRichText)) {
        suppressedStalePdfjsOverlayIds.delete(id);
        return false;
    }
    return true;
}

function isNoopMovedPdfjsSourceOverlay(annotation, pageIndex, viewport, scale) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (!boolish(annotation.savedTextOverlay) || !boolish(annotation.movedTextOverlay)) return false;
    if (boolish(annotation.pdfjsDeleted) || boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return false;
    if (String(annotation.pdfjsEditorMode || 'source').trim().toLowerCase() === 'rich') return false;
    const sourceText = normalizeVisualLineComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    const annotationText = normalizeVisualLineComparableText(annotation.text || '');
    if (!sourceText || (annotationText && annotationText !== sourceText)) return false;
    const current = annotationCurrentPdfBox(annotation);
    const baseline = annotationBaselinePdfBox(annotation);
    if (current && baseline && pdfRectsNearlyEqual(current, baseline, 3.0)) return true;
    const sourceMask = annotationSourceMaskPdfBox(annotation);
    if (current && sourceMask && pdfRectsNearlyEqual(current, sourceMask, 3.0)) return true;
    if (sourceMask) return false;
    const currentGroup = currentSourceGroupForAnnotation(annotation, pageIndex, viewport, scale);
    const freshRect = sourceGroupPdfRect(currentGroup, viewport, scale);
    return Boolean(current && freshRect
        && normalizeVisualLineComparableText(currentGroup?.text || '') === sourceText
        && pdfRectsNearlyEqual(current, freshRect, 3.0));
}

function shouldRefreshPdfjsAnnotationFromCurrentSource(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (!boolish(annotation.savedTextOverlay)) return false;
    if (boolish(annotation.pdfjsDeleted) || annotation._pdfjsCanvasRewritten === true || annotation._pdfjsSourceRedacted === true) return false;
    if (boolish(annotation.movedTextOverlay) || boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return false;
    if (String(annotation.pdfjsEditorMode || '') === 'rich') return false;
    const text = normalizeComparableText(annotation.text || '');
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    return !text || !sourceText || text === sourceText;
}

function refreshPdfjsAnnotationFromCurrentSource(annotation, pageIndex, viewport, scale) {
    if (!shouldRefreshPdfjsAnnotationFromCurrentSource(annotation)) return annotation;
    const group = currentSourceGroupForAnnotation(annotation, pageIndex, viewport, scale);
    const freshText = String(group?.text || '');
    const freshRect = sourceGroupPdfRect(group, viewport, scale);
    if (!freshText || !freshRect) return annotation;
    const normalizedFresh = normalizeComparableText(freshText);
    const normalizedSource = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || annotation.text || '');
    const baseline = annotationBaselinePdfBox(annotation);
    const current = annotationCurrentPdfBox(annotation);
    const sourceChanged = normalizedFresh && normalizedFresh !== normalizedSource;
    const geometryChanged = baseline ? scorePdfRectDistance(baseline, freshRect) > 1.5 : true;
    if (!sourceChanged && !geometryChanged) return annotation;
    // This is the only path that intentionally replaces pdfjsSource* after
    // creation, and only for unedited saved boxes matching their source text.
    return {
        ...annotation,
        text: freshText,
        originalText: freshText,
        pdfjsSourceText: freshText,
        pdfX: freshRect.x,
        pdfY: freshRect.y,
        pdfWidth: freshRect.w,
        pdfHeight: freshRect.h,
        pdfjsSourceX: freshRect.x,
        pdfjsSourceY: freshRect.y,
        pdfjsSourceW: freshRect.w,
        pdfjsSourceH: freshRect.h,
        pdfjsSourcePageHeight: Number(viewport.height) / scale,
        _baselinePdfBox: freshRect,
        _originalPdfBox: current && !geometryChanged ? current : freshRect,
        _currentPdfBox: current && !geometryChanged ? current : freshRect,
    };
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

function minTextResizeHeightPx(box) {
    if (!box) return 12;
    if (box.dataset.annotationType === 'shape' || isImageBackedBox(box)) return 12;
    const contentHeight = richTextContentHeightPx(box);
    const computedHeight = (() => {
        const tc = selectedBoxTextElement(box);
        if (!tc) return 0;
        const cs = window.getComputedStyle(tc);
        const lineHeight = Number.parseFloat(cs.lineHeight || '') || 0;
        const fontSize = Number.parseFloat(cs.fontSize || '') || 0;
        return Math.max(lineHeight, fontSize);
    })();
    return Math.max(12, Math.ceil(Math.max(contentHeight, computedHeight) + 2));
}

function richTextContentWidthPx(box) {
    const tc = selectedBoxTextElement(box);
    if (!tc) return 0;
    const text = String(tc.innerText || tc.textContent || '');
    if (text.trim()) {
        const cs = window.getComputedStyle(tc);
        const canvas = richTextContentWidthPx._canvas || document.createElement('canvas');
        richTextContentWidthPx._canvas = canvas;
        const ctx = canvas.getContext('2d');
        if (ctx) {
            const fontStyle = cs.fontStyle || 'normal';
            const fontWeight = cs.fontWeight || '400';
            const fontSize = cs.fontSize || '12px';
            const fontFamily = cs.fontFamily || 'sans-serif';
            ctx.font = `${fontStyle} ${fontWeight} ${fontSize} ${fontFamily}`;
            const letterSpacing = Number.parseFloat(cs.letterSpacing || '') || 0;
            const widths = text.split(/\r?\n/).map((line) => {
                const rawWidth = ctx.measureText(line || ' ').width;
                return rawWidth + Math.max(0, line.length - 1) * letterSpacing;
            });
            return Math.ceil(Math.max(0, ...widths) + 4);
        }
    }
    const previousWhiteSpace = tc.style.whiteSpace;
    const previousWidth = tc.style.width;
    tc.style.whiteSpace = 'nowrap';
    tc.style.width = 'max-content';
    const width = Math.ceil(Math.max(
        tc.scrollWidth || 0,
        tc.getBoundingClientRect().width || 0,
    ));
    tc.style.whiteSpace = previousWhiteSpace;
    tc.style.width = previousWidth;
    return width;
}

function fitRichTextBoxToContent(box, anchor = 'top', options = {}) {
    if (!box || editorModeForBox(box) !== 'rich') return false;
    const allowShrink = options.allowShrink === true;
    let changed = false;
    if (options.fitWidth === true) {
        const maxWidth = Number.parseFloat(options.maxWidthPx ?? '');
        const naturalWidth = Math.max(12, richTextContentWidthPx(box) + 2);
        const targetWidth = Number.isFinite(maxWidth) && maxWidth > 0
            ? Math.min(naturalWidth, Math.max(12, maxWidth))
            : naturalWidth;
        const currentWidth = Number.parseFloat(box.style.width || '') || box.getBoundingClientRect().width || box.offsetWidth || 0;
        if (targetWidth > 0 && (allowShrink || currentWidth < targetWidth - 0.5) && Math.abs(currentWidth - targetWidth) > 0.5) {
            box.style.width = `${targetWidth}px`;
            changed = true;
        }
    }
    const contentHeight = richTextContentHeightPx(box);
    if (!(contentHeight > 0)) return changed;
    const targetHeight = Math.max(12, contentHeight + 2);
    const currentHeight = Number.parseFloat(box.style.height || '') || box.getBoundingClientRect().height || box.offsetHeight || 0;
    if (allowShrink || currentHeight < targetHeight - 0.5) {
        if (Math.abs(currentHeight - targetHeight) > 0.5) {
            if (anchor === 'bottom') {
                const top = Number.parseFloat(box.style.top || '') || 0;
                box.style.top = `${top - (targetHeight - currentHeight)}px`;
            }
            box.style.height = `${targetHeight}px`;
            changed = true;
        }
    }
    return changed;
}

function maxUserCreatedTextBoxWidthPx(box) {
    if (!box?.parentElement) return null;
    const layerWidth = box.parentElement.clientWidth || box.parentElement.getBoundingClientRect?.().width || 0;
    if (!(layerWidth > 0)) return null;
    const left = Number.parseFloat(box.style.left || '') || 0;
    return Math.max(12, layerWidth - left - 1);
}

function clampBoxInsidePage(box) {
    if (!box) return false;
    const layer = box.parentElement;
    if (!layer) return false;
    const layerWidth = layer.clientWidth || 0;
    const layerHeight = layer.clientHeight || 0;
    if (!(layerWidth > 0) || !(layerHeight > 0)) return false;
    const width = Number.parseFloat(box.style.width || '') || box.offsetWidth || 0;
    const height = Number.parseFloat(box.style.height || '') || box.offsetHeight || 0;
    const left = Number.parseFloat(box.style.left || '') || 0;
    const top = Number.parseFloat(box.style.top || '') || 0;
    const nextWidth = Math.max(1, Math.min(width, layerWidth));
    const nextHeight = Math.max(1, Math.min(height, layerHeight));
    const nextLeft = Math.max(0, Math.min(left, Math.max(0, layerWidth - nextWidth)));
    const nextTop = Math.max(0, Math.min(top, Math.max(0, layerHeight - nextHeight)));
    if (Math.abs(nextLeft - left) <= 0.5
        && Math.abs(nextTop - top) <= 0.5
        && Math.abs(nextWidth - width) <= 0.5
        && Math.abs(nextHeight - height) <= 0.5) return false;
    box.style.left = `${nextLeft}px`;
    box.style.top = `${nextTop}px`;
    box.style.width = `${nextWidth}px`;
    box.style.height = `${nextHeight}px`;
    return true;
}

function pageLayerSizeForBox(box) {
    const layer = box?.parentElement || null;
    if (!layer) return null;
    const width = layer.clientWidth || layer.getBoundingClientRect?.().width || 0;
    const height = layer.clientHeight || layer.getBoundingClientRect?.().height || 0;
    if (!(width > 0) || !(height > 0)) return null;
    return { width, height };
}

function clampResizeScalar(value, min, max) {
    const finiteValue = Number(value);
    if (!Number.isFinite(finiteValue)) return min;
    return Math.max(min, Math.min(finiteValue, Math.max(min, max)));
}

function fitUserCreatedTextBoxToContent(box, options = {}) {
    const existing = persistedAnnotationsById.get(String(box?.dataset?.annotationId || '')) || null;
    if (!isUserCreatedTextBox(box, existing)) return false;
    if (!userCreatedBoxHasText(box)) return false;
    if (box.dataset.userSizedTextBox === '1' || boolish(existing?.userSizedTextBox)) {
        return clampBoxInsidePage(box);
    }
    const changed = fitRichTextBoxToContent(box, options.anchor || 'top', {
        allowShrink: true,
        fitWidth: true,
        maxWidthPx: maxUserCreatedTextBoxWidthPx(box),
    });
    const clamped = clampBoxInsidePage(box);
    return changed || clamped;
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
    const isShape = box.dataset.annotationType === 'shape';
    if (isShape) return;
    if (!isImageBackedBox(box)) {
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
    if (isImageBackedBox(box)) {
        return;
    }
    if (editorModeForBox(box) === 'source') {
        promoteBoxToRichTextMode(box, 'manual-resize');
    } else {
        setBoxEditorMode(box, 'rich');
    }
    fitRichTextBoxToContent(box);
}

function onTextContentBeforeInput(ev) {
    const box = ev.currentTarget?.closest?.('.enpv-annotation-box');
    if (!box) return;
    const inputType = String(ev.inputType || '');
    if (inputType === 'insertParagraph' || inputType === 'insertLineBreak') {
        if (editorModeForBox(box) === 'source') {
            promoteBoxToRichTextMode(box, 'newline');
        }
        if (editorModeForBox(box) === 'rich') {
            ev.preventDefault();
            ev.stopPropagation();
            if (insertHardLineBreakIntoContentEditable(ev.currentTarget)) {
                ev.currentTarget.dispatchEvent(new InputEvent('input', {
                    bubbles: true,
                    inputType,
                    data: '\n',
                }));
            }
        }
    }
}

function plainTextFromClipboardData(clipboardData) {
    const text = clipboardData?.getData?.('text/plain') || '';
    if (text) return text.replace(/\r\n?/g, '\n');
    const html = clipboardData?.getData?.('text/html') || '';
    if (!html) return '';
    const div = document.createElement('div');
    div.innerHTML = html;
    return String(div.innerText || div.textContent || '').replace(/\r\n?/g, '\n');
}

function ensureSelectionInsideContentEditable(target) {
    if (!target) return null;
    const sel = window.getSelection();
    if (sel?.rangeCount && target.contains(sel.anchorNode) && target.contains(sel.focusNode)) {
        return sel.getRangeAt(0);
    }
    target.focus();
    const range = document.createRange();
    range.selectNodeContents(target);
    range.collapse(false);
    sel?.removeAllRanges();
    sel?.addRange(range);
    return range;
}

function insertHardLineBreakIntoContentEditable(target) {
    if (!target) return false;
    target.focus();
    const range = ensureSelectionInsideContentEditable(target);
    if (!range) return false;
    range.deleteContents();
    const marker = document.createTextNode('\n\u200b');
    range.insertNode(marker);
    range.setStart(marker, marker.nodeValue.length);
    range.collapse(true);
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    return true;
}

function insertPlainTextIntoContentEditable(target, text) {
    if (!target || !text) return false;
    target.focus();
    if (document.queryCommandSupported?.('insertText') && document.execCommand('insertText', false, text)) {
        return true;
    }
    const range = ensureSelectionInsideContentEditable(target);
    if (!range) return false;
    range.deleteContents();
    const parts = String(text).split('\n');
    parts.forEach((part, index) => {
        if (index > 0) range.insertNode(document.createElement('br'));
        if (part) range.insertNode(document.createTextNode(part));
        range.collapse(false);
    });
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    return true;
}

function forcePastedUserTextToDefaultSize(box) {
    if (!box) return;
    const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '')
        || Number.parseFloat(box.dataset.renderScale || '')
        || Number(pdfViewer.currentScale)
        || 1;
    const fontSizePts = 12;
    box.dataset.fontSizePts = String(fontSizePts);
    box.style.setProperty('--enpv-font-size', `${fontSizePts * scale}px`);
    box.style.setProperty('--enpv-line-height', `${fontSizePts * 1.2 * scale}px`);
}

function onTextContentPaste(ev) {
    const box = ev.currentTarget?.closest?.('.enpv-annotation-box');
    if (!box) return;
    const text = plainTextFromClipboardData(ev.clipboardData);
    if (!text) return;
    ev.preventDefault();
    ev.stopPropagation();
    if (editorModeForBox(box) === 'source' && /\n/.test(text)) {
        promoteBoxToRichTextMode(box, 'plain-text-paste');
    }
    if (isUserCreatedTextBox(box, persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null)) {
        forcePastedUserTextToDefaultSize(box);
    }
    insertPlainTextIntoContentEditable(ev.currentTarget, text);
    ev.currentTarget.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'insertText',
        data: text,
    }));
}

function onTextContentBlur(ev) {
    const box = ev.currentTarget?.closest?.('.enpv-annotation-box');
    if (!box) return;
    window.setTimeout(() => {
        if (!box.isConnected || !box.classList.contains('is-editing')) return;
        if (document.activeElement?.closest?.('.enpv-annotation-box') === box) return;
        // If focus moved to the format bar (font/size/color dropdown etc.), keep
        // the edit session alive so per-selection inline mutations can apply
        // against the saved range instead of being demoted to a whole-box change.
        if (document.activeElement?.closest?.('#ann-format-bar')) return;
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        if (isUserCreatedTextBox(box, existing)) {
            if (userCreatedBoxHasText(box)) {
                commitUserCreatedTextBoxAndKeepSelected(box);
            }
        }
    }, 0);
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

function shapeSvgPointList(points, width, height) {
    return points
        .map((point) => `${(Number(point.x) || 0) * width},${(Number(point.y) || 0) * height}`)
        .join(' ');
}

function shapeSvgPathFromUnitCommands(commands, width, height) {
    return commands.map((command) => {
        if (command[0] === 'Z') return 'Z';
        const op = command[0];
        const values = command.slice(1).map((value, index) => {
            const axisScale = index % 2 === 0 ? width : height;
            return String((Number(value) || 0) * axisScale);
        });
        return `${op}${values.join(' ')}`;
    }).join(' ');
}

function heartShapePathD(width, height) {
    return shapeSvgPathFromUnitCommands([
        ['M', 0.5, 0.9],
        ['C', 0.18, 0.66, 0.06, 0.48, 0.06, 0.3],
        ['C', 0.06, 0.16, 0.17, 0.06, 0.31, 0.06],
        ['C', 0.4, 0.06, 0.47, 0.11, 0.5, 0.18],
        ['C', 0.53, 0.11, 0.6, 0.06, 0.69, 0.06],
        ['C', 0.83, 0.06, 0.94, 0.16, 0.94, 0.3],
        ['C', 0.94, 0.48, 0.82, 0.66, 0.5, 0.9],
        ['Z'],
    ], width, height);
}

function openIconShapePathD(type, width, height) {
    if (type === 'arrow') {
        return shapeSvgPathFromUnitCommands([
            ['M', 0.1, 0.5], ['L', 0.8, 0.5],
            ['M', 0.65, 0.35], ['L', 0.8, 0.5], ['L', 0.65, 0.65],
        ], width, height);
    }
    if (type === 'x') {
        return shapeSvgPathFromUnitCommands([
            ['M', 0.15, 0.15], ['L', 0.85, 0.85],
            ['M', 0.85, 0.15], ['L', 0.15, 0.85],
        ], width, height);
    }
    return shapeSvgPathFromUnitCommands([
        ['M', 0.15, 0.5], ['L', 0.4, 0.75], ['L', 0.85, 0.15],
    ], width, height);
}

function ensureShapeSvg(box) {
    let svg = box?.querySelector?.(':scope > svg.enpv-shape-svg') || null;
    if (!svg) {
        svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.classList.add('enpv-shape-svg');
        svg.setAttribute('aria-hidden', 'true');
        box.appendChild(svg);
    }
    return svg;
}

function updateShapeSvgForBox(box, annotation, scale = 1) {
    if (!box || !annotation) return;
    const ann = normalizeShapeAnnotation({ ...annotation });
    const svg = ensureShapeSvg(box);
    const width = Math.max(1, Number.parseFloat(box.style.width || '') || box.offsetWidth || 1);
    const height = Math.max(1, Number.parseFloat(box.style.height || '') || box.offsetHeight || 1);
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.replaceChildren();

    const type = normalizeShapeType(ann.shapeType);
    const strokeWidth = Math.max(1, (Number(ann.strokeWidth) || 3) * (Number(scale) || 1));
    const strokeColor = ann.strokeTransparent ? 'none' : (ann.strokeColor || '#0f172a');
    const openIconShape = type === 'arrow' || type === 'x' || type === 'checkmark';
    const fillColor = (ann.fillTransparent || type === 'line' || openIconShape) ? 'none' : (ann.fillColor || '#22c55e');
    const strokeOpacity = normalizeOpacity(ann.strokeOpacity, 1);
    const fillOpacity = normalizeOpacity(ann.fillOpacity, 0.22);
    let el;

    if (type === 'circle') {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'ellipse');
        el.setAttribute('cx', String(width / 2));
        el.setAttribute('cy', String(height / 2));
        el.setAttribute('rx', String(width / 2));
        el.setAttribute('ry', String(height / 2));
    } else if (type === 'triangle') {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        el.setAttribute('points', `${width / 2},0 ${width},${height} 0,${height}`);
    } else if (type === 'star') {
        const outer = Math.min(width, height) / 2;
        const inner = outer * 0.45;
        const cx = width / 2;
        const cy = height / 2;
        const points = [];
        for (let i = 0; i < 10; i += 1) {
            const radius = i % 2 === 0 ? outer : inner;
            const angle = (-Math.PI / 2) + (i * Math.PI / 5);
            points.push(`${cx + Math.cos(angle) * radius},${cy + Math.sin(angle) * radius}`);
        }
        el = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        el.setAttribute('points', points.join(' '));
    } else if (type === 'heart') {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        el.setAttribute('d', heartShapePathD(width, height));
    } else if (openIconShape) {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        el.setAttribute('d', openIconShapePathD(type, width, height));
    } else if (type === 'polygon') {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        el.setAttribute('points', shapeSvgPointList(
            normalizePolygonPointList(ann.polygonPoints, defaultPolygonUnitPoints()),
            width,
            height,
        ));
    } else if (type === 'line') {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        el.setAttribute('x1', String(clampLineUnit(ann.lineStartX, 0) * width));
        el.setAttribute('y1', String(clampLineUnit(ann.lineStartY, 0) * height));
        el.setAttribute('x2', String(clampLineUnit(ann.lineEndX, 1) * width));
        el.setAttribute('y2', String(clampLineUnit(ann.lineEndY, 1) * height));
    } else {
        el = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        el.setAttribute('x', '0');
        el.setAttribute('y', '0');
        el.setAttribute('width', String(width));
        el.setAttribute('height', String(height));
    }

    el.setAttribute('fill', fillColor);
    el.setAttribute('fill-opacity', fillColor === 'none' ? '0' : String(fillOpacity));
    el.setAttribute('stroke', strokeColor);
    el.setAttribute('stroke-opacity', strokeColor === 'none' ? '0' : String(strokeOpacity));
    el.setAttribute('stroke-width', String(strokeColor === 'none' ? 0 : strokeWidth));
    el.setAttribute('stroke-linecap', ann.lineCap || 'round');
    el.setAttribute('stroke-linejoin', 'round');
    if (Number(ann.rotation) && type !== 'line') {
        el.setAttribute('transform', `rotate(${Number(ann.rotation) || 0} ${width / 2} ${height / 2})`);
    }
    svg.appendChild(el);
    updateShapeSelectionChromeForBox(box);
}

function applyShapeAnnotationToBoxDataset(box, annotation) {
    if (!box || !annotation) return;
    const ann = normalizeShapeAnnotation({ ...annotation });
    box.dataset.annotationType = 'shape';
    box.dataset.shapeType = ann.shapeType;
    box.dataset.strokeColor = ann.strokeColor;
    box.dataset.strokeOpacity = String(ann.strokeOpacity);
    box.dataset.strokeWidth = String(ann.strokeWidth);
    box.dataset.strokeTransparent = ann.strokeTransparent ? '1' : '0';
    box.dataset.fillColor = ann.fillColor;
    box.dataset.fillOpacity = String(ann.fillOpacity);
    box.dataset.fillTransparent = ann.fillTransparent ? '1' : '0';
    box.dataset.rotation = String(normalizeRotationDegrees(ann.rotation || 0));
    box.dataset.lineStartX = String(ann.lineStartX ?? 0);
    box.dataset.lineStartY = String(ann.lineStartY ?? 0);
    box.dataset.lineEndX = String(ann.lineEndX ?? 1);
    box.dataset.lineEndY = String(ann.lineEndY ?? 1);
    box.dataset.lineCap = ann.lineCap || 'round';
}

function clampLineUnit(value, fallback) {
    const n = Number.parseFloat(value);
    if (!Number.isFinite(n)) return fallback;
    return Math.max(0, Math.min(1, n));
}

function normalizeLegacyPdfjsLineEndpointPreview(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'shape') return annotation;
    if (normalizeShapeType(annotation.shapeType) !== 'line') return annotation;
    const width = Number(annotation.pdfWidth);
    const height = Number(annotation.pdfHeight);
    if (!(width > 0) || !(height > 0) || width / height > 0.25) return annotation;
    const sx = clampLineUnit(annotation.lineStartX, 0);
    const sy = clampLineUnit(annotation.lineStartY, 0);
    const ex = clampLineUnit(annotation.lineEndX, 1);
    const ey = clampLineUnit(annotation.lineEndY, 1);
    if (sx > 0.99 && ex < 0.01 && sy < 0.01 && ey > 0.99) {
        annotation.lineEndX = 1;
        annotation._legacyLineEndpointPreviewCorrected = true;
    }
    return annotation;
}

function normalizePdfjsLineAnnotationBox(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'shape') return annotation;
    if (normalizeShapeType(annotation.shapeType) !== 'line') return annotation;
    const x = Number(annotation.pdfX);
    const y = Number(annotation.pdfY);
    const w = Number(annotation.pdfWidth);
    const h = Number(annotation.pdfHeight);
    if (![x, y, w, h].every(Number.isFinite) || w <= 0 || h <= 0) return annotation;
    const sx = clampLineUnit(annotation.lineStartX, 0);
    const sy = clampLineUnit(annotation.lineStartY, 0);
    const ex = clampLineUnit(annotation.lineEndX, 1);
    const ey = clampLineUnit(annotation.lineEndY, 1);
    const start = {
        x: x + (w * sx),
        y: y + (h * (1 - sy)),
    };
    const end = {
        x: x + (w * ex),
        y: y + (h * (1 - ey)),
    };
    const lineBox = computeLineBoxGeometry(start.x, start.y, end.x, end.y);
    annotation.pdfX = lineBox.left;
    annotation.pdfY = lineBox.bottom;
    annotation.pdfWidth = lineBox.width;
    annotation.pdfHeight = lineBox.height;
    annotation.lineStartX = lineBox.lineStartX;
    annotation.lineStartY = lineBox.lineStartY;
    annotation.lineEndX = lineBox.lineEndX;
    annotation.lineEndY = lineBox.lineEndY;
    annotation._originalBox = {
        x: annotation.pdfX,
        y: annotation.pdfY,
        w: annotation.pdfWidth,
        h: annotation.pdfHeight,
    };
    annotation._originalPdfBox = { ...annotation._originalBox };
    return annotation;
}

function lineEndpointPointsForBox(box, viewport, scale) {
    if (!box || !viewport || !(scale > 0)) return null;
    const leftPx = Number.parseFloat(box.style.left || '0') || 0;
    const topPx = Number.parseFloat(box.style.top || '0') || 0;
    const widthPx = Number.parseFloat(box.style.width || '0') || box.offsetWidth || 0;
    const heightPx = Number.parseFloat(box.style.height || '0') || box.offsetHeight || 0;
    const dxPts = Number.parseFloat(box.dataset.dxPts || '0') || 0;
    const dyPts = Number.parseFloat(box.dataset.dyPts || '0') || 0;
    const pdfLeft = (leftPx / scale) + dxPts;
    const pdfBottom = (Number(viewport.height) - (topPx + heightPx)) / scale - dyPts;
    const pdfWidth = widthPx / scale;
    const pdfHeight = heightPx / scale;
    const lineStartX = clampLineUnit(box.dataset.lineStartX, 0);
    const lineStartY = clampLineUnit(box.dataset.lineStartY, 0);
    const lineEndX = clampLineUnit(box.dataset.lineEndX, 1);
    const lineEndY = clampLineUnit(box.dataset.lineEndY, 1);
    return {
        start: {
            x: pdfLeft + (pdfWidth * lineStartX),
            y: pdfBottom + (pdfHeight * (1 - lineStartY)),
        },
        end: {
            x: pdfLeft + (pdfWidth * lineEndX),
            y: pdfBottom + (pdfHeight * (1 - lineEndY)),
        },
    };
}

function updateShapeResizeHandlesForBox(box) {
    if (!box || box.dataset.annotationType !== 'shape') return;
    if (normalizeShapeType(box.dataset.shapeType) !== 'line') return;
    const width = Math.max(1, Number.parseFloat(box.style.width || '') || box.offsetWidth || 1);
    const height = Math.max(1, Number.parseFloat(box.style.height || '') || box.offsetHeight || 1);
    const startHandle = box.querySelector('.enpv-resize-handle[data-edge="line-start"]');
    const endHandle = box.querySelector('.enpv-resize-handle[data-edge="line-end"]');
    if (startHandle) {
        startHandle.style.left = `${clampLineUnit(box.dataset.lineStartX, 0) * width}px`;
        startHandle.style.top = `${clampLineUnit(box.dataset.lineStartY, 0) * height}px`;
    }
    if (endHandle) {
        endHandle.style.left = `${clampLineUnit(box.dataset.lineEndX, 1) * width}px`;
        endHandle.style.top = `${clampLineUnit(box.dataset.lineEndY, 1) * height}px`;
    }
}

function isLineShapeBox(box) {
    return box?.dataset?.annotationType === 'shape'
        && normalizeShapeType(box.dataset.shapeType) === 'line';
}

function distanceFromClientPointToSegment(clientX, clientY, startX, startY, endX, endY) {
    const segmentDx = endX - startX;
    const segmentDy = endY - startY;
    const lengthSquared = (segmentDx * segmentDx) + (segmentDy * segmentDy);
    if (lengthSquared <= 0.0001) {
        const pointDx = clientX - startX;
        const pointDy = clientY - startY;
        return Math.hypot(pointDx, pointDy);
    }
    const projectedUnit = Math.max(0, Math.min(1,
        (((clientX - startX) * segmentDx) + ((clientY - startY) * segmentDy)) / lengthSquared,
    ));
    const projectedX = startX + (projectedUnit * segmentDx);
    const projectedY = startY + (projectedUnit * segmentDy);
    return Math.hypot(clientX - projectedX, clientY - projectedY);
}

function lineShapeHitInfoAtPoint(box, clientX, clientY) {
    if (!isLineShapeBox(box)) return null;
    if (box.dataset.strokeTransparent === '1') return null;
    const rect = box.getBoundingClientRect();
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;
    const startX = rect.left + (clampLineUnit(box.dataset.lineStartX, 0) * rect.width);
    const startY = rect.top + (clampLineUnit(box.dataset.lineStartY, 0) * rect.height);
    const endX = rect.left + (clampLineUnit(box.dataset.lineEndX, 1) * rect.width);
    const endY = rect.top + (clampLineUnit(box.dataset.lineEndY, 1) * rect.height);
    const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1;
    const strokePx = Math.max(1, (Number.parseFloat(box.dataset.strokeWidth || '3') || 3) * scale);
    const tolerance = Math.max(6, Math.min(18, (strokePx / 2) + 5));
    const distance = distanceFromClientPointToSegment(clientX, clientY, startX, startY, endX, endY);
    return distance <= tolerance ? { distance, tolerance } : null;
}

function annotationBoxContainsClientPoint(box, clientX, clientY) {
    if (!box) return false;
    const rect = box.getBoundingClientRect();
    return rect
        && clientX >= rect.left
        && clientX <= rect.right
        && clientY >= rect.top
        && clientY <= rect.bottom;
}

function annotationBoxStackRank(box) {
    const zIndex = Number.parseInt(box?.style?.zIndex || box?.dataset?.zIndex || window.getComputedStyle(box).zIndex || '0', 10) || 0;
    const siblings = Array.from(box?.parentElement?.children || []);
    return { zIndex, domIndex: siblings.indexOf(box) };
}

function resolveAnnotationBoxForLinePointerDown(currentBox, ev) {
    if (!isLineShapeBox(currentBox)) return currentBox;
    if (ev.target?.closest?.('.enpv-resize-handle')) return currentBox;
    const layer = currentBox.parentElement;
    if (!layer) return currentBox;
    const candidates = [];
    Array.from(layer.querySelectorAll(':scope > .enpv-annotation-box')).forEach((candidateBox) => {
        if (!candidateBox?.isConnected || candidateBox.classList.contains('is-readonly')) return;
        if (window.getComputedStyle(candidateBox).pointerEvents === 'none') return;
        let distance = 0;
        if (isLineShapeBox(candidateBox)) {
            const hitInfo = lineShapeHitInfoAtPoint(candidateBox, ev.clientX, ev.clientY);
            if (!hitInfo) return;
            distance = hitInfo.distance;
        } else if (!annotationBoxContainsClientPoint(candidateBox, ev.clientX, ev.clientY)) {
            return;
        }
        candidates.push({ box: candidateBox, distance, stack: annotationBoxStackRank(candidateBox) });
    });
    if (!candidates.length) return null;
    candidates.sort((leftCandidate, rightCandidate) => {
        const zDiff = leftCandidate.stack.zIndex - rightCandidate.stack.zIndex;
        if (zDiff !== 0) return zDiff;
        const domDiff = leftCandidate.stack.domIndex - rightCandidate.stack.domIndex;
        if (domDiff !== 0) return domDiff;
        return rightCandidate.distance - leftCandidate.distance;
    });
    return candidates[candidates.length - 1]?.box || null;
}

function shapeBoxCanRotate(box) {
    return box?.dataset?.annotationType === 'shape'
        && normalizeShapeType(box.dataset.shapeType) !== 'line';
}

const CUTTABLE_SHAPE_TYPES = new Set(['square', 'circle', 'triangle', 'star', 'polygon']);

function canCutShapeBox(box) {
    if (!shapeBoxCanRotate(box)) return false;
    if (isAnnBoxLocked(box)) return false;
    return CUTTABLE_SHAPE_TYPES.has(normalizeShapeType(box.dataset.shapeType));
}

function isShapeCutModeArmedForBox(box) {
    return Boolean(shapeCutState?.armed && box && String(shapeCutState.annotationId || '') === String(box.dataset.annotationId || ''));
}

function currentShapeCutBox() {
    if (!shapeCutState?.armed) return null;
    const pageIndex = Number.parseInt(String(shapeCutState.pageIndex ?? '-1'), 10);
    const annotationId = String(shapeCutState.annotationId || '');
    const pageDiv = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex)?.div : null;
    return pageDiv?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(annotationId)}"]`) || null;
}

function ensureShapeCutOverlay(layer) {
    if (!layer) return null;
    let overlay = layer.querySelector(':scope > svg.enpv-shape-cut-overlay');
    if (!overlay) {
        overlay = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        overlay.classList.add('enpv-shape-cut-overlay');
        overlay.setAttribute('aria-hidden', 'true');
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        overlay.appendChild(line);
        layer.appendChild(overlay);
    }
    const width = Math.max(1, layer.clientWidth || layer.offsetWidth || 1);
    const height = Math.max(1, layer.clientHeight || layer.offsetHeight || 1);
    overlay.setAttribute('viewBox', `0 0 ${width} ${height}`);
    return overlay;
}

function updateShapeCutOverlay() {
    if (!shapeCutState?.armed || !shapeCutState.startPoint || !shapeCutState.currentPoint) return;
    const layer = currentShapeCutBox()?.parentElement;
    const overlay = ensureShapeCutOverlay(layer);
    const line = overlay?.querySelector('line');
    if (!line) return;
    line.setAttribute('x1', String(shapeCutState.startPoint.canvasX));
    line.setAttribute('y1', String(shapeCutState.startPoint.canvasY));
    line.setAttribute('x2', String(shapeCutState.currentPoint.canvasX));
    line.setAttribute('y2', String(shapeCutState.currentPoint.canvasY));
}

function removeShapeCutOverlay() {
    document.querySelectorAll('svg.enpv-shape-cut-overlay').forEach((overlay) => overlay.remove());
}

function refreshShapeCutUi() {
    document.querySelectorAll('.enpv-annotation-box.is-shape-cut-armed').forEach((box) => {
        box.classList.toggle('is-shape-cut-armed', isShapeCutModeArmedForBox(box));
    });
    const box = currentShapeCutBox();
    if (box) box.classList.add('is-shape-cut-armed');
    document.body.classList.toggle('enpv-shape-cut-armed', Boolean(shapeCutState?.armed));
    const selected = findSelectedBox();
    if (selected) refreshAnnMenuState(selected);
}

function cancelShapeCutMode(options = {}) {
    if (!shapeCutState?.armed) return;
    removeShapeCutOverlay();
    shapeCutState = null;
    document.body.classList.remove('enpv-shape-cutting');
    refreshShapeCutUi();
    if (options.status) setStatus(options.status, options.error === true);
}

function armShapeCutModeForBox(box) {
    if (!box) return false;
    if (!canCutShapeBox(box)) {
        setStatus(isAnnBoxLocked(box) ? 'Annotation is locked.' : 'This shape cannot be cut.', true);
        return false;
    }
    if (shapeCutState?.armed) cancelShapeCutMode();
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    shapeCutState = {
        armed: true,
        annotationId: String(box.dataset.annotationId || ''),
        pageIndex,
        pointerId: null,
        startPoint: null,
        currentPoint: null,
    };
    if (!box.classList.contains('is-selected')) selectAnnBox(box);
    refreshShapeCutUi();
    positionAnnMenuOver(box);
    setStatus('Cut mode: drag a line through the selected shape.');
    return true;
}

function toggleShapeCutModeForBox(box) {
    if (isShapeCutModeArmedForBox(box)) {
        cancelShapeCutMode({ status: 'Shape cut cancelled.' });
        return;
    }
    armShapeCutModeForBox(box);
}

function createPolygonAnnotationFromPdfPoints(templateAnn, pageIndex, points) {
    const cleaned = dedupePolygonVertices(points);
    if (cleaned.length < 3 || Math.abs(computePolygonArea(cleaned)) < 1) return null;
    const xs = cleaned.map((point) => point.x);
    const ys = cleaned.map((point) => point.y);
    const left = Math.min(...xs);
    const right = Math.max(...xs);
    const bottom = Math.min(...ys);
    const top = Math.max(...ys);
    const width = Math.max(1, right - left);
    const height = Math.max(1, top - bottom);
    const uid = `shape_${generateUuidV4()}`;
    const polygonPoints = cleaned.map((point) => ({
        x: clamp01((point.x - left) / width, 0.5),
        y: clamp01((top - point.y) / height, 0.5),
    }));
    return normalizeShapeAnnotation({
        id: buildPdfjsAnnotationId(pageIndex, uid),
        _uid: uid,
        pageIndex,
        type: 'shape',
        shapeType: 'polygon',
        pdfX: left,
        pdfY: bottom,
        pdfWidth: width,
        pdfHeight: height,
        rotation: 0,
        userCreated: true,
        strokeColor: templateAnn.strokeColor,
        strokeOpacity: templateAnn.strokeOpacity,
        strokeWidth: templateAnn.strokeWidth,
        strokeTransparent: templateAnn.strokeTransparent,
        fillColor: templateAnn.fillColor,
        fillOpacity: templateAnn.fillOpacity,
        fillTransparent: templateAnn.fillTransparent,
        polygonPoints,
        locked: false,
        zIndex: Number(templateAnn.zIndex) || 2,
        text: '',
    });
}

function cutShapeBoxWithLine(box, startPoint, endPoint) {
    if (!canCutShapeBox(box)) return { ok: false, message: 'This shape cannot be cut.' };
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const source = normalizeShapeAnnotation(buildAnnotationFromBox(box, existing) || existing || {});
    const polygon = getShapePolygonPointsPdf(source);
    if (polygon.length < 3) return { ok: false, message: 'Shape outline is unavailable.' };
    const startPt = { x: Number(startPoint?.pdfX), y: Number(startPoint?.pdfY) };
    const endPt = { x: Number(endPoint?.pdfX), y: Number(endPoint?.pdfY) };
    const lineDx = endPt.x - startPt.x;
    const lineDy = endPt.y - startPt.y;
    if (![startPt.x, startPt.y, endPt.x, endPt.y].every(Number.isFinite) || Math.hypot(lineDx, lineDy) < 4) {
        return { ok: false, message: 'Drag a longer cut line through the shape.' };
    }
    const firstHalf = clipPolygonAgainstInfiniteLine(polygon, startPt, endPt, true);
    const secondHalf = clipPolygonAgainstInfiniteLine(polygon, startPt, endPt, false);
    const cutA = createPolygonAnnotationFromPdfPoints(source, pageIndex, firstHalf);
    const cutB = createPolygonAnnotationFromPdfPoints(source, pageIndex, secondHalf);
    if (!cutA || !cutB) return { ok: false, message: 'The cut line must pass through the shape.' };

    pushHistorySnapshot('cut shape');
    rememberDeletedAnnotation(box);
    if (source.id) deletePersistedAnnotation(source.id);
    if (box.dataset.annotationId && box.dataset.annotationId !== source.id) deletePersistedAnnotation(box.dataset.annotationId);
    upsertPersistedAnnotation(cutA);
    upsertPersistedAnnotation(cutB);
    selectedAnnBoxUid = cutA._uid || cutA.id || null;
    selectedAnnBoxIsEditing = false;
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    renderAnnotationBoxLayer(pageIndex);
    const cutBox = pdfViewer.getPageView(pageIndex)?.div
        ?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(cutA.id)}"]`) || null;
    if (cutBox) selectAnnBox(cutBox);
    markManualSaveNeeded();
    return { ok: true, annotations: [cutA, cutB] };
}

const NON_LINE_SHAPE_HANDLE_ANCHORS = {
    nw: { x: 0, y: 0 },
    ne: { x: 1, y: 0 },
    sw: { x: 0, y: 1 },
    se: { x: 1, y: 1 },
    t: { x: 0.5, y: 0 },
    b: { x: 0.5, y: 1 },
    l: { x: 0, y: 0.5 },
    r: { x: 1, y: 0.5 },
};

function shapeBoxRotationDegrees(box) {
    return normalizeRotationDegrees(Number.parseFloat(box?.dataset?.rotation || '0') || 0);
}

function ensureShapeSelectionFrame(box) {
    if (!box || box.dataset.annotationType !== 'shape') return null;
    let frame = box.querySelector(':scope > .enpv-shape-selection-frame');
    if (frame) return frame;
    frame = document.createElement('div');
    frame.className = 'enpv-shape-selection-frame';
    frame.setAttribute('aria-hidden', 'true');
    const svg = box.querySelector(':scope > svg.enpv-shape-svg');
    if (svg?.nextSibling) box.insertBefore(frame, svg.nextSibling);
    else box.appendChild(frame);
    return frame;
}

function rotateShapeLocalPoint(pointX, pointY, width, height, rotationDegrees) {
    const radians = rotationDegrees * (Math.PI / 180);
    const centerX = width / 2;
    const centerY = height / 2;
    const offsetX = pointX - centerX;
    const offsetY = pointY - centerY;
    return {
        x: centerX + (Math.cos(radians) * offsetX) - (Math.sin(radians) * offsetY),
        y: centerY + (Math.sin(radians) * offsetX) + (Math.cos(radians) * offsetY),
    };
}

function updateNonLineShapeResizeHandlesForBox(box, width, height, rotationDegrees) {
    Object.entries(NON_LINE_SHAPE_HANDLE_ANCHORS).forEach(([edge, anchor]) => {
        const handle = box.querySelector(`:scope > .enpv-resize-handle[data-edge="${edge}"]`);
        if (!handle) return;
        const point = rotateShapeLocalPoint(anchor.x * width, anchor.y * height, width, height, rotationDegrees);
        handle.style.left = `${point.x}px`;
        handle.style.top = `${point.y}px`;
    });
}

function updateShapeSelectionChromeForBox(box) {
    if (!box || box.dataset.annotationType !== 'shape') return;
    const canRotate = shapeBoxCanRotate(box);
    const frame = canRotate
        ? ensureShapeSelectionFrame(box)
        : box.querySelector(':scope > .enpv-shape-selection-frame');
    if (frame) frame.hidden = !canRotate;
    if (canRotate) {
        const width = Math.max(1, Number.parseFloat(box.style.width || '') || box.offsetWidth || 1);
        const height = Math.max(1, Number.parseFloat(box.style.height || '') || box.offsetHeight || 1);
        const rotation = shapeBoxRotationDegrees(box);
        if (frame) {
            frame.style.transform = rotation ? `rotate(${rotation}deg)` : 'none';
            frame.dataset.rotation = String(Math.round(rotation * 10) / 10);
        }
        updateNonLineShapeResizeHandlesForBox(box, width, height, rotation);
    }
    updateShapeRotateHandleForBox(box);
}

function ensureShapeRotateHandle(box) {
    if (!box || box.dataset.annotationType !== 'shape') return null;
    let handle = box.querySelector(':scope > .enpv-shape-rotate-handle');
    if (handle) return handle;
    handle = document.createElement('button');
    handle.type = 'button';
    handle.className = 'enpv-shape-rotate-handle';
    handle.dataset.action = 'rotate-shape';
    handle.title = 'Rotate shape';
    handle.setAttribute('aria-label', 'Rotate shape');
    handle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"></path><path d="M21 3v6h-6"></path></svg>';
    handle.addEventListener('pointerdown', onShapeRotateHandlePointerDown);
    box.appendChild(handle);
    return handle;
}

function updateShapeRotateHandleForBox(box) {
    if (!box || box.dataset.annotationType !== 'shape') return;
    const canRotate = shapeBoxCanRotate(box);
    const handle = canRotate
        ? ensureShapeRotateHandle(box)
        : box.querySelector(':scope > .enpv-shape-rotate-handle');
    if (!handle) return;
    handle.hidden = !canRotate;
    if (!canRotate) return;
    const width = Math.max(1, Number.parseFloat(box.style.width || '') || box.offsetWidth || 1);
    const height = Math.max(1, Number.parseFloat(box.style.height || '') || box.offsetHeight || 1);
    const rotation = normalizeRotationDegrees(Number.parseFloat(box.dataset.rotation || '0') || 0);
    const orbitRadius = (height / 2) + (height * 0.15);
    const handleAngle = normalizeRotationDegrees(rotation + 90) * (Math.PI / 180);
    const centerX = width / 2;
    const centerY = height / 2;
    const handleX = centerX + (Math.cos(handleAngle) * orbitRadius);
    const handleY = centerY + (Math.sin(handleAngle) * orbitRadius);
    handle.style.left = `${handleX}px`;
    handle.style.top = `${handleY}px`;
    handle.dataset.rotation = String(Math.round(rotation * 10) / 10);
}

function applyLineGeometryToBox(box, lineBox, viewport, scale) {
    const rect = pdfRectToCanvasRect({
        x: lineBox.left,
        y: lineBox.bottom,
        w: lineBox.width,
        h: lineBox.height,
    }, viewport, scale);
    if (!rect) return null;
    box.style.left = `${rect.left}px`;
    box.style.top = `${rect.top}px`;
    box.style.width = `${Math.max(1, rect.width)}px`;
    box.style.height = `${Math.max(1, rect.height)}px`;
    box.dataset.dxPts = '0';
    box.dataset.dyPts = '0';
    box.dataset.lineStartX = String(lineBox.lineStartX);
    box.dataset.lineStartY = String(lineBox.lineStartY);
    box.dataset.lineEndX = String(lineBox.lineEndX);
    box.dataset.lineEndY = String(lineBox.lineEndY);
    updateShapeResizeHandlesForBox(box);
    return rect;
}

function createShapeOverlayBox(annotation, pageIndex, viewport, scale, allowInteraction) {
    const ann = normalizeShapeAnnotation({ ...annotation, pageIndex });
    const currentPdfBox = annotationCurrentPdfBox(ann) || {
        x: Number(ann.pdfX),
        y: Number(ann.pdfY),
        w: Number(ann.pdfWidth),
        h: Number(ann.pdfHeight),
    };
    if (![currentPdfBox.x, currentPdfBox.y, currentPdfBox.w, currentPdfBox.h].every(Number.isFinite)
        || currentPdfBox.w <= 0 || currentPdfBox.h <= 0) return null;
    const rect = pdfRectToCanvasRect(currentPdfBox, viewport, scale);
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box enpv-shape-box is-persisted-overlay';
    box.dataset.uid = String(ann._uid || ann.id || `${pageIndex}:shape`);
    box.dataset.annotationId = String(ann.id || buildPdfjsAnnotationId(pageIndex, box.dataset.uid));
    box.dataset.pageIndex = String(pageIndex);
    box.dataset.locked = ann.locked ? '1' : '0';
    box.dataset.zIndex = String(Number(ann.zIndex) || 2);
    box.dataset.userCreated = '1';
    applyShapeAnnotationToBoxDataset(box, ann);
    box.style.left = `${rect.left}px`;
    box.style.top = `${rect.top}px`;
    box.style.width = `${Math.max(1, rect.width)}px`;
    box.style.height = `${Math.max(1, rect.height)}px`;
    box.style.zIndex = box.dataset.zIndex;
    box.classList.toggle('is-locked', ann.locked === true);
    updateShapeSvgForBox(box, ann, scale);

    if (allowInteraction) {
        addEditableBoxChrome(box);
    } else {
        box.classList.add('is-readonly');
    }
    return { box, rect };
}

function createPersistedOverlayBox(annotation, pageIndex, viewport, scale, editModeOn) {
    annotation = refreshPdfjsAnnotationFromCurrentSource(annotation, pageIndex, viewport, scale);
    const currentPdfBox = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox;
    if (!currentPdfBox) return null;
    const rect = pdfRectToCanvasRect(currentPdfBox, viewport, scale);
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box is-persisted-overlay';
    if (cleanPromotedSourceText(annotation)) {
        box.classList.add('is-clean-promoted-fallback');
        box.dataset.cleanPromotedFallback = '1';
    }
    if (boolish(annotation.imageToPdfOcr)) {
        box.classList.add('is-image-ocr-handle');
        box.dataset.imageToPdfOcr = '1';
    }
    box.dataset.uid = String(annotation.pdfjsAnchorUid || annotation.id || `${pageIndex}:persisted`);
    box.dataset.annotationId = String(annotation.id || buildPdfjsAnnotationId(pageIndex, box.dataset.uid));
    box.dataset.pageIndex = String(pageIndex);
    const isStandaloneUserTextBox = boolish(annotation.userCreated)
        || (boolish(annotation.skipPdfjsSourceMask) && !String(annotation.pdfjsSourceText || '').trim());
    box.dataset.originalText = String(annotation.originalText || annotation.pdfjsSourceText || annotation.text || '');
    box.dataset.occurrence = String(annotation.pdfjsSourceOccurrence || '0');
    const sourceSnapshotRect = annotationBaselinePdfBox(annotation) || currentPdfBox;
    box.dataset.sourceBboxX = String(sourceSnapshotRect.x);
    box.dataset.sourceBboxY = String(sourceSnapshotRect.y);
    box.dataset.sourceBboxW = String(sourceSnapshotRect.w);
    box.dataset.sourceBboxH = String(sourceSnapshotRect.h);
    box.dataset.baseBboxX = box.dataset.sourceBboxX;
    box.dataset.baseBboxY = box.dataset.sourceBboxY;
    box.dataset.baseBboxW = box.dataset.sourceBboxW;
    box.dataset.baseBboxH = box.dataset.sourceBboxH;
    box.dataset.basePageHeight = String(Number(annotation.pdfjsSourcePageHeight || (Number(viewport.height) / scale)));
    box.dataset.baseText = isStandaloneUserTextBox ? '' : String(annotation.pdfjsSourceText || annotation.originalText || annotation.text || '');
    box.dataset.fontSizePts = String(Number(annotation.fontSize) || 12);
    box.dataset.renderScale = String(scale);
    box.dataset.locked = annotation.locked ? '1' : '0';
    box.dataset.zIndex = String(Number(annotation.zIndex) || 2);
    if (boolish(annotation.userCreated)) box.dataset.userCreated = '1';
    if (boolish(annotation.userAuthored)) box.dataset.userAuthored = '1';
    if (boolish(annotation.skipPdfjsSourceMask)) box.dataset.skipPdfjsSourceMask = '1';
    if (boolish(annotation.movedTextOverlay)) box.dataset.movedTextOverlay = '1';
    const sourceMaskBox = annotationSourceMaskPdfBox(annotation);
    if (sourceMaskBox) {
        box.dataset.sourceMaskX = String(sourceMaskBox.x);
        box.dataset.sourceMaskY = String(sourceMaskBox.y);
        box.dataset.sourceMaskW = String(sourceMaskBox.w);
        box.dataset.sourceMaskH = String(sourceMaskBox.h);
        if (explicitSourceMaskNeedsAscenderPadding(annotation)) {
            box.dataset.sourceMaskAscenderPadding = '1';
        }
    }
    if (boolish(annotation.userSizedTextBox)) {
        box.dataset.userSizedTextBox = '1';
        box.classList.add('is-user-sized-text');
    }
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
    const richTextHtml = sanitizeRichTextHtmlForAnnotation(annotation.richTextHtml || '', {
        preserveLineBreaks: annotationPreservesDisplayLineBreaks(annotation),
    });
    if (richTextHtml) tc.innerHTML = richTextHtml;
    else tc.textContent = normalizeAnnotationTextForDisplay(annotation.text || '', {
        preserveLineBreaks: annotationPreservesDisplayLineBreaks(annotation),
    });
    box.appendChild(tc);
    if ((annotation.pdfjsSourceFidelity === true || box.dataset.sourceFontFamily || box.dataset.sourceTransform)
        && annotation.userForcedRichText !== true
        && annotation.pdfjsEditorMode !== 'rich'
        && box.dataset.userSizedTextBox !== '1') {
        applySourceFidelityTypography(box, { scale });
        // Mixed font-weight rows (e.g. bold "5a" label followed by regular
        // body text) need per-span markup applied at display time too —
        // otherwise the single annotation-level fontWeight paints the
        // entire line bold/regular and destroys the original typography.
        applySourceFidelitySpanEditMarkup(box, { purpose: 'display' });
        setBoxEditorMode(box, 'source');
    } else {
        setBoxEditorMode(box, 'rich');
        syncRichTextBoxTypographyFromContent(box);
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
        ? sourceMaskCanvasRectForAnnotation(annotation, viewport, scale, pageIndex)
        : null;
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    const mask = createSourceMaskElement(maskRect, pageDiv, {
        preserveRules: !boolish(annotation.movedTextOverlay),
    });
    if (mask && boolish(annotation.movedTextOverlay)) {
        applyMovedOverlayRunMaskSegments(mask, box, maskRect, pageDiv);
        scheduleMovedOverlayRunMaskRefresh(mask, box, maskRect, pageDiv);
    }
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
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (isUserCreatedTextBox(box, existing)) return null;
    const sourceText = String(existing?.pdfjsSourceText || existing?.originalText || box.dataset.baseText || box.dataset.originalText || '').trim();
    if (!sourceText) return null;
    const layer = box.parentElement;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    if (!layer || !viewport) return null;
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
    const text = sourceText;
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
    if (box.dataset.skipPdfjsSourceMask === '1'
        || box.dataset.userCreated === '1') {
        return null;
    }
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
    const explicitMaskRect = boxSourceMaskPdfBox(box);
    if (explicitMaskRect) {
        const rect = pdfRectToCanvasRect(explicitMaskRect, viewport, scale);
        return box.dataset.movedTextOverlay === '1'
            ? movedSourceMaskCanvasRectFromTypography(rect, box)
            : rect;
    }
    if (box.dataset.movedTextOverlay === '1'
        && [sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        && sourceRect.w > 0
        && sourceRect.h > 0) {
        return movedSourceMaskCanvasRect(pdfRectToCanvasRect(sourceRect, viewport, scale));
    }
    if (![sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        || sourceRect.w <= 0 || sourceRect.h <= 0) {
        return null;
    }
    return pdfRectToCanvasRect(sourceRect, viewport, scale);
}

function sourceMaskCanvasRectForAnnotation(annotation, viewport, scale, pageIndex = null) {
    if (!annotation || !viewport || !scale) return null;
    if (boolish(annotation.skipPdfjsSourceMask) || boolish(annotation.userCreated)) return null;
    const sourceRect = annotationBaselinePdfBox(annotation);
    const moved = boolish(annotation.movedTextOverlay);
    const explicitMaskRect = annotationSourceMaskPdfBox(annotation);
    if (explicitMaskRect) {
        const rect = pdfRectToCanvasRect(explicitMaskRect, viewport, scale);
        return moved
            ? movedSourceMaskCanvasRectFromTypography(rect, annotation)
            : rect;
    }
    if (moved && sourceRect) return movedSourceMaskCanvasRect(pdfRectToCanvasRect(sourceRect, viewport, scale));
    if (!sourceRect) return null;
    return pdfRectToCanvasRect(sourceRect, viewport, scale);
}

function annotationSourceMaskPdfBox(annotation) {
    const mX = Number(annotation?.pdfjsSourceMaskX);
    const mY = Number(annotation?.pdfjsSourceMaskY);
    const mW = Number(annotation?.pdfjsSourceMaskW);
    const mH = Number(annotation?.pdfjsSourceMaskH);
    if ([mX, mY, mW, mH].every(Number.isFinite) && mW > 0 && mH > 0) {
        return { x: mX, y: mY, w: mW, h: mH };
    }
    return null;
}

function boxSourceMaskPdfBox(box) {
    const mX = Number(box?.dataset?.sourceMaskX);
    const mY = Number(box?.dataset?.sourceMaskY);
    const mW = Number(box?.dataset?.sourceMaskW);
    const mH = Number(box?.dataset?.sourceMaskH);
    if ([mX, mY, mW, mH].every(Number.isFinite) && mW > 0 && mH > 0) {
        return { x: mX, y: mY, w: mW, h: mH };
    }
    return null;
}

function rememberSourceMaskRectForCurrentBox(box) {
    if (!box || boxSourceMaskPdfBox(box)) return;
    const sourceRect = {
        x: Number.parseFloat(box.dataset.sourceBboxX || box.dataset.baseBboxX || ''),
        y: Number.parseFloat(box.dataset.sourceBboxY || box.dataset.baseBboxY || ''),
        w: Number.parseFloat(box.dataset.sourceBboxW || box.dataset.baseBboxW || ''),
        h: Number.parseFloat(box.dataset.sourceBboxH || box.dataset.baseBboxH || ''),
    };
    if ([sourceRect.x, sourceRect.y, sourceRect.w, sourceRect.h].every(Number.isFinite)
        && sourceRect.w > 0
        && sourceRect.h > 0) {
        box.dataset.sourceMaskX = String(sourceRect.x);
        box.dataset.sourceMaskY = String(sourceRect.y);
        box.dataset.sourceMaskW = String(sourceRect.w);
        box.dataset.sourceMaskH = String(sourceRect.h);
        return;
    }
    const layer = box.parentElement;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    const scale = Number.parseFloat(layer?.dataset?.scale || '') || Number(viewport?.scale) || 1;
    if (!viewport || !scale) return;
    const left = Number.parseFloat(box.style.left || '') || 0;
    const top = Number.parseFloat(box.style.top || '') || 0;
    const width = Number.parseFloat(box.style.width || '') || box.offsetWidth || 0;
    const height = Number.parseFloat(box.style.height || '') || box.offsetHeight || 0;
    if (![left, top, width, height].every(Number.isFinite) || width <= 0 || height <= 0) return;
    const insetBottomPx = Math.min(1, Math.max(0, height - 1));
    box.dataset.sourceMaskX = String(left / scale);
    box.dataset.sourceMaskY = String((Number(viewport.height) - (top + height - insetBottomPx)) / scale);
    box.dataset.sourceMaskW = String(width / scale);
    box.dataset.sourceMaskH = String(Math.max(1, height - insetBottomPx) / scale);
}

function shouldMaskSourceForAnnotation(annotation) {
    if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') return false;
    if (annotation._pdfjsSourceRedacted === true) return false;
    if (boolish(annotation.pdfjsDeleted)) return false;
    if (!boolish(annotation.savedTextOverlay)) return false;
    const sourceText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || '');
    if (!sourceText) return false;
    const baseline = annotationBaselinePdfBox(annotation);
    if (!baseline) return false;
    if (annotationSourceMaskPdfBox(annotation)) return true;
    if (boolish(annotation.movedTextOverlay)) return true;
    if (boolish(annotation.styleDirty) || boolish(annotation.userForcedRichText)) return true;
    if (String(annotation.pdfjsEditorMode || '').trim().toLowerCase() === 'rich') return true;
    const annotationText = normalizeComparableText(annotation.text || '');
    return Boolean(annotationText && annotationText !== sourceText);
}

function sourceMaskOverlapsAcroWidget(pageDiv, rect) {
    if (!pageDiv || !rect) return false;
    const pageBounds = pageDiv.getBoundingClientRect?.();
    if (!pageBounds) return false;
    const annLayerEl = pageDiv.querySelector(':scope > .annotationLayer');
    if (!annLayerEl) return false;
    const widgets = annLayerEl.querySelectorAll(
        '.textWidgetAnnotation, .buttonWidgetAnnotation, .choiceWidgetAnnotation, .signatureWidgetAnnotation'
    );
    for (const widget of widgets) {
        const widgetBounds = widget.getBoundingClientRect?.();
        if (!widgetBounds || widgetBounds.width <= 0 || widgetBounds.height <= 0) continue;
        const widgetRect = {
            left: widgetBounds.left - pageBounds.left,
            top: widgetBounds.top - pageBounds.top,
            width: widgetBounds.width,
            height: widgetBounds.height,
        };
        const ix0 = Math.max(rect.left, widgetRect.left);
        const iy0 = Math.max(rect.top, widgetRect.top);
        const ix1 = Math.min(rect.left + rect.width, widgetRect.left + widgetRect.width);
        const iy1 = Math.min(rect.top + rect.height, widgetRect.top + widgetRect.height);
        if (ix1 > ix0 && iy1 > iy0) return true;
    }
    return false;
}

function ensureSourceMaskLayer(pageDiv) {
    if (!pageDiv) return null;
    let layer = pageDiv.querySelector(':scope > .enpv-source-mask-layer');
    if (!layer) {
        layer = document.createElement('div');
        layer.className = 'enpv-source-mask-layer';
        pageDiv.appendChild(layer);
    }
    normalizeEditorPaneOrder(pageDiv);
    return layer;
}

function normalizeEditorPaneOrder(pageDiv) {
    if (!pageDiv) return;
    const maskLayer = pageDiv.querySelector(':scope > .enpv-source-mask-layer');
    const pdfAnnotationLayer = pageDiv.querySelector(':scope > .annotationLayer');
    const editorLayer = pageDiv.querySelector(':scope > .enpv-annotation-box-layer');

    if (maskLayer) {
        const before = pdfAnnotationLayer || editorLayer || null;
        if (before && maskLayer.nextSibling !== before) {
            pageDiv.insertBefore(maskLayer, before);
        } else if (!before && maskLayer.parentElement !== pageDiv) {
            pageDiv.appendChild(maskLayer);
        }
    }

    if (pdfAnnotationLayer && editorLayer) {
        const annotationAfterEditor = Boolean(
            editorLayer.compareDocumentPosition(pdfAnnotationLayer) & Node.DOCUMENT_POSITION_FOLLOWING,
        );
        if (annotationAfterEditor) pageDiv.insertBefore(pdfAnnotationLayer, editorLayer);
    }

    if (editorLayer && editorLayer.parentElement === pageDiv) pageDiv.appendChild(editorLayer);
}

function appendEditorAnnotationLayer(pageDiv, layer) {
    if (!pageDiv || !layer) return;
    if (layer.parentElement !== pageDiv) pageDiv.appendChild(layer);
    normalizeEditorPaneOrder(pageDiv);
}

function removeSourceMaskLayer(pageDiv) {
    pageDiv?.querySelector(':scope > .enpv-source-mask-layer')?.remove();
}

function createSourceMaskElement(rect, pageDiv = null, options = {}) {
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
    if (options.preserveRules !== false) {
        scheduleRuleAwareMaskRefresh(mask, rect, pageDiv, { minRectWidth: 18 });
    }
    return mask;
}

function removeSourceMasksOverlappingAcroWidgets(layer, pageDiv) {
    if (!layer || !pageDiv) return;
    const pageBounds = pageDiv.getBoundingClientRect?.();
    const layerBounds = layer.getBoundingClientRect?.();
    const layerOffsetLeft = pageBounds && layerBounds ? (layerBounds.left - pageBounds.left) : 0;
    const layerOffsetTop = pageBounds && layerBounds ? (layerBounds.top - pageBounds.top) : 0;
    Array.from(layer.querySelectorAll('.enpv-source-mask,.enpv-orig-mask')).forEach((mask) => {
        const rect = {
            left: layerOffsetLeft + (Number.parseFloat(mask.style.left || '') || 0),
            top: layerOffsetTop + (Number.parseFloat(mask.style.top || '') || 0),
            width: Number.parseFloat(mask.style.width || '') || mask.offsetWidth || 0,
            height: Number.parseFloat(mask.style.height || '') || mask.offsetHeight || 0,
        };
        if (rect.width <= 0 || rect.height <= 0) return;
        if (sourceMaskOverlapsAcroWidget(pageDiv, rect) && !mask.closest('.enpv-source-mask-layer')) mask.remove();
    });
}

function detectHorizontalCanvasRuleGaps(pageDiv, rect, options = {}) {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return [];
    const minRectWidth = Number(options.minRectWidth ?? 0) || 0;
    if (rect.width < minRectWidth) return [];
    const minDarkRatio = Number(options.minDarkRatio ?? 0.72) || 0.72;
    const minContiguousDarkRatio = Number(options.minContiguousDarkRatio ?? 0) || 0;
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
        let contiguousDark = 0;
        let longestContiguousDark = 0;
        const rowOffset = y * sw * 4;
        for (let x = 0; x < sw; x += 1) {
            const offset = rowOffset + (x * 4);
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            if (r < 170 && g < 170 && b < 170) {
                dark += 1;
                contiguousDark += 1;
                if (contiguousDark > longestContiguousDark) longestContiguousDark = contiguousDark;
            } else {
                contiguousDark = 0;
            }
        }
        if (dark / sw >= minDarkRatio
            && (!minContiguousDarkRatio || longestContiguousDark / sw >= minContiguousDarkRatio)) {
            darkRows.push(y);
        }
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

function movedOverlayRunItemsForBox(box, pageDiv = null) {
    if (!box) return [];
    const groupLookup = sourceGroupForBox(box);
    if (groupLookup?.group && groupLookup?.layerEl) {
        const group = groupLookup.group;
        const boxSourceText = normalizeComparableText(box.dataset.baseText || box.dataset.originalText || '');
        const groupText = normalizeComparableText(group.text || group.visualText || '');
        if (boxSourceText && groupText && boxSourceText !== groupText) return [];
        const groupSpans = Array.from(new Set([
            ...(group.spans || []),
            ...(group.relatedSpans || []),
        ]));
        const items = liveSourceGroupItems({ ...group, spans: groupSpans }, groupLookup.layerEl);
        if (items.length) {
            return items.map((item) => ({
                leftPx: item.rect.left,
                rightPx: item.rect.right,
                topPx: item.rect.top,
                bottomPx: item.rect.bottom,
            }));
        }
    }
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const annotationId = String(box.dataset.annotationId || '').trim();
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    const layerRect = layerEl?.getBoundingClientRect?.();
    if (annotationId && layerEl && layerRect) {
        const spans = Array.from(layerEl.querySelectorAll('span[data-enpv-persistent-id]'))
            .filter((span) => String(span.dataset.enpvPersistentId || '') === annotationId);
        const items = spans
            .map((span) => {
                const text = String(span.textContent || '').trim();
                const rect = textLayerRelativeRect(span, layerRect);
                if (!text || !rect) return null;
                return {
                    leftPx: rect.left,
                    rightPx: rect.right,
                    topPx: rect.top,
                    bottomPx: rect.bottom,
                };
            })
            .filter(Boolean)
            .sort((left, right) => left.leftPx - right.leftPx);
        if (items.length) return items;
    }
    const raw = box.dataset.sourceSpanRuns;
    if (!raw) return [];
    let items = null;
    try { items = JSON.parse(raw); } catch (_) { items = null; }
    if (!Array.isArray(items)) return [];
    const layer = box.parentElement;
    const currentScale = Number.parseFloat(layer?.dataset?.scale || '') || 0;
    const originalScale = Number.parseFloat(box.dataset.renderScale || '') || currentScale || 0;
    const ratio = currentScale > 0 && originalScale > 0 ? currentScale / originalScale : 1;
    return items.map((item) => ({
        leftPx: Number(item?.leftPx) * ratio,
        rightPx: Number(item?.rightPx) * ratio,
        topPx: Number(item?.topPx) * ratio,
        bottomPx: Number(item?.bottomPx) * ratio,
    })).filter((item) => [item.leftPx, item.rightPx].every(Number.isFinite));
}

function rectToEdges(rect) {
    if (!rect) return null;
    const left = Number(rect.left);
    const top = Number(rect.top);
    const right = Number(rect.right ?? (Number(rect.left) + Number(rect.width)));
    const bottom = Number(rect.bottom ?? (Number(rect.top) + Number(rect.height)));
    if (![left, top, right, bottom].every(Number.isFinite) || right <= left || bottom <= top) return null;
    return { left, top, right, bottom, width: right - left, height: bottom - top };
}

function subtractRectByRect(rect, cut) {
    const source = rectToEdges(rect);
    const blocker = rectToEdges(cut);
    if (!source || !blocker) return source ? [source] : [];
    const ix0 = Math.max(source.left, blocker.left);
    const iy0 = Math.max(source.top, blocker.top);
    const ix1 = Math.min(source.right, blocker.right);
    const iy1 = Math.min(source.bottom, blocker.bottom);
    if (ix1 <= ix0 || iy1 <= iy0) return [source];
    return [
        { left: source.left, top: source.top, right: source.right, bottom: iy0 },
        { left: source.left, top: iy1, right: source.right, bottom: source.bottom },
        { left: source.left, top: iy0, right: ix0, bottom: iy1 },
        { left: ix1, top: iy0, right: source.right, bottom: iy1 },
    ].map(rectToEdges).filter((piece) => piece && piece.width > 0.25 && piece.height > 0.25);
}

function subtractRects(rects, cuts) {
    let pieces = rects.map(rectToEdges).filter(Boolean);
    for (const cut of cuts || []) {
        pieces = pieces.flatMap((piece) => subtractRectByRect(piece, cut));
        if (!pieces.length) break;
    }
    return pieces;
}

function shouldSkipTallNeighborProtectionForMask(maskRect, candidate) {
    const mask = rectToEdges(maskRect);
    const rect = rectToEdges(candidate);
    if (!mask || !rect) return false;
    if (rect.height < mask.height * 3) return false;
    const overlapTop = Math.max(mask.top, rect.top);
    const overlapBottom = Math.min(mask.bottom, rect.bottom);
    const overlapHeight = overlapBottom - overlapTop;
    if (!(overlapHeight > 0)) return false;
    const overlapsFromAbove = rect.top < mask.top && rect.bottom < mask.bottom;
    const overlapsFromBelow = rect.top > mask.top && rect.bottom > mask.bottom;
    if (!overlapsFromAbove && !overlapsFromBelow) return false;
    return overlapHeight <= mask.height * 0.6;
}

function sourceGroupIsHiddenForMovedOverlay(group, movedSourceAnnotationIds = null) {
    if (!group) return false;
    const spans = [
        ...(group.spans || []),
        ...(group.relatedSpans || []),
    ];
    return spans.some((span) => {
        if (!span?.dataset) return false;
        if (span.dataset.enpvMovedSourceHidden === '1') return true;
        const persistentId = String(span.dataset.enpvPersistentId || '').trim();
        return Boolean(persistentId && movedSourceAnnotationIds?.has(persistentId));
    });
}

function movedOverlayProtectedRectsForBox(box, maskRect) {
    if (!box || !maskRect) return [];
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return [];
    const cached = sourceGroupsByPage.get(pageIndex);
    const groups = cached?.groups || [];
    const ownGroup = sourceGroupForBox(box)?.group || null;
    const ownIndex = ownGroup ? String(ownGroup.index) : String(box.dataset.uid || '').split(':').pop();
    const annotationId = String(box.dataset.annotationId || '').trim();
    const ownAnchorUid = String(box.dataset.uid || '').trim();
    const ownSourceText = normalizeComparableText(box.dataset.baseText || box.dataset.originalText || '');
    const ownSourceRect = {
        x: Number.parseFloat(box.dataset.sourceBboxX || box.dataset.baseBboxX || ''),
        y: Number.parseFloat(box.dataset.sourceBboxY || box.dataset.baseBboxY || ''),
        w: Number.parseFloat(box.dataset.sourceBboxW || box.dataset.baseBboxW || ''),
        h: Number.parseFloat(box.dataset.sourceBboxH || box.dataset.baseBboxH || ''),
    };
    const hasOwnSourceRect = [ownSourceRect.x, ownSourceRect.y, ownSourceRect.w, ownSourceRect.h]
        .every(Number.isFinite) && ownSourceRect.w > 0 && ownSourceRect.h > 0;
    const movedSourceAnnotationIds = new Set();
    for (const otherAnnotation of persistedAnnotationsById.values()) {
        if (!otherAnnotation || !boolish(otherAnnotation.movedTextOverlay)) continue;
        const otherId = String(otherAnnotation.id || '').trim();
        if (annotationId && otherId === annotationId) continue;
        const otherPage = Number(otherAnnotation.pageIndex);
        if (Number.isFinite(otherPage) && otherPage !== pageIndex) continue;
        if (otherId) movedSourceAnnotationIds.add(otherId);
    }
    const ownSourceSpans = new Set([
        ...(ownGroup?.spans || []),
        ...(ownGroup?.relatedSpans || []),
    ]);
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport || null;
    const scale = Number(viewport?.scale) || Number(pdfViewer.currentScale) || 1;
    const ownSourceCanvasRect = hasOwnSourceRect && viewport && scale
        ? pdfRectToCanvasRect(ownSourceRect, viewport, scale)
        : null;
    const matchesOwnSourceCanvasRect = (candidate, text = '') => {
        const source = rectToEdges(ownSourceCanvasRect);
        const rect = rectToEdges(candidate);
        if (!source || !rect) return false;
        const normalizedText = normalizeComparableText(text || '');
        const textIsOwnSource = !ownSourceText
            || !normalizedText
            || normalizedText === ownSourceText
            || ownSourceText.includes(normalizedText)
            || normalizedText.includes(ownSourceText);
        if (!textIsOwnSource) return false;
        const ix0 = Math.max(source.left, rect.left);
        const iy0 = Math.max(source.top, rect.top);
        const ix1 = Math.min(source.right, rect.right);
        const iy1 = Math.min(source.bottom, rect.bottom);
        if (ix1 <= ix0 || iy1 <= iy0) return false;
        const overlap = (ix1 - ix0) * (iy1 - iy0);
        const sourceArea = Math.max(0.0001, source.width * source.height);
        const rectArea = Math.max(0.0001, rect.width * rect.height);
        const sourceRatio = overlap / sourceArea;
        const rectRatio = overlap / rectArea;
        if (ownSourceText && normalizedText && normalizedText !== ownSourceText && rectRatio >= 0.7) return true;
        const sourceCenterX = (source.left + source.right) / 2;
        const sourceCenterY = (source.top + source.bottom) / 2;
        const rectCenterX = (rect.left + rect.right) / 2;
        const rectCenterY = (rect.top + rect.bottom) / 2;
        const centerDistance = Math.hypot(sourceCenterX - rectCenterX, sourceCenterY - rectCenterY);
        return (sourceRatio >= 0.45 && rectRatio >= 0.55)
            || centerDistance <= Math.max(4, Math.max(source.height, rect.height) * 0.6);
    };
    const protectedRects = [];
    const seen = new Set();
    const pushRect = (candidate) => {
        if (!candidate || !rectsOverlap(maskRect, candidate, 0.25)) return;
        if (shouldSkipTallNeighborProtectionForMask(maskRect, candidate)) return;
        const key = `${Math.round(candidate.left)}:${Math.round(candidate.top)}:${Math.round(candidate.left + candidate.width)}:${Math.round(candidate.top + candidate.height)}`;
        if (seen.has(key)) return;
        seen.add(key);
        protectedRects.push(candidate);
    };
    for (const group of groups) {
        if (!group || String(group.index) === ownIndex) continue;
        if (sourceGroupIsHiddenForMovedOverlay(group, movedSourceAnnotationIds)) continue;
        if (!group.rect) continue;
        const groupRect = {
            left: group.rect.left,
            top: group.rect.top,
            width: group.rect.width,
            height: group.rect.height,
        };
        if (matchesOwnSourceCanvasRect(groupRect, group.text || group.visualText || '')) continue;
        pushRect(expandCanvasRect(groupRect, 0.75));
    }
    // Also protect any text-layer span that belongs to a different annotation
    // (or no annotation at all) and overlaps the mask. The cached source
    // groups can miss neighbours when font glyph bboxes vertically overlap
    // (e.g. a large header whose source bbox extends down into the strapline
    // row); without this safeguard the moved-overlay mask would paint over
    // the neighbouring text in the editor.
    // Protect every other persisted annotation's source rect on this page.
    // This is the most reliable signal because the rects are in the same
    // PDF coordinate system as our maskRect and don't depend on PDF.js text
    // layer rendering quirks.
    if (viewport && scale) {
        for (const otherAnnotation of persistedAnnotationsById.values()) {
            if (!otherAnnotation || String(otherAnnotation.id || '') === annotationId) continue;
            if (boolish(otherAnnotation.movedTextOverlay)) continue;
            const otherAnchorUid = String(otherAnnotation.pdfjsAnchorUid || '').trim();
            if (ownAnchorUid && otherAnchorUid && otherAnchorUid === ownAnchorUid) continue;
            const otherPage = Number(otherAnnotation.pageIndex);
            if (Number.isFinite(otherPage) && otherPage !== pageIndex) continue;
            if (boolish(otherAnnotation.pdfjsDeleted)) continue;
            const otherSource = annotationBaselinePdfBox(otherAnnotation);
            if (!otherSource) continue;
            const otherSourceText = normalizeComparableText(
                otherAnnotation.pdfjsSourceText || otherAnnotation.originalText || otherAnnotation.text || '',
            );
            if (hasOwnSourceRect
                && pdfRectsNearlyEqual(otherSource, ownSourceRect, 1.5)
                && (!ownSourceText || !otherSourceText || ownSourceText === otherSourceText)) {
                continue;
            }
            const otherCanvas = pdfRectToCanvasRect(otherSource, viewport, scale);
            if (!otherCanvas) continue;
            pushRect(expandCanvasRect(otherCanvas, 0.75));
        }
    }
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv || null;
    const layerRect = layerEl?.getBoundingClientRect?.();
    if (layerEl && layerRect) {
        const spans = layerEl.querySelectorAll('span');
        for (const span of spans) {
            if (!span || !span.textContent || !span.textContent.trim()) continue;
            if (ownSourceSpans.has(span)) continue;
            const spanId = String(span.dataset.enpvPersistentId || '').trim();
            if (annotationId && spanId === annotationId) continue;
            if (span.dataset.enpvMovedSourceHidden === '1') continue;
            if (spanId && movedSourceAnnotationIds.has(spanId)) continue;
            const spanGroupIndex = String(span.dataset.enpvGroupIndex || span.dataset.enpvIndex || '').trim();
            if (ownIndex && spanGroupIndex && spanGroupIndex === ownIndex) {
                continue;
            }
            const spanRect = textLayerRelativeRect(span, layerRect);
            if (!spanRect) continue;
            if (matchesOwnSourceCanvasRect(spanRect, span.textContent || '')) continue;
            pushRect(expandCanvasRect({
                left: spanRect.left,
                top: spanRect.top,
                width: spanRect.width,
                height: spanRect.height,
            }, 0.75));
        }
    }
    return protectedRects;
}

function movedOverlayRuleGapRectsForMask(rect, pageDiv = null) {
    if (!rect || !pageDiv) return [];
    return detectHorizontalCanvasRuleGaps(pageDiv, rect, {
        minRectWidth: 18,
        minDarkRatio: 0.55,
        minContiguousDarkRatio: 0.65,
    }).map((gap) => rectToEdges({
        left: rect.left,
        top: rect.top + gap.top,
        right: rect.left + rect.width,
        bottom: rect.top + gap.top + gap.height,
    })).filter(Boolean);
}

function applyMovedOverlayRunMaskSegments(mask, box, rect, pageDiv = null) {
    if (!mask || !box || !rect) return false;
    if (box.dataset.movedTextOverlay === '1') {
        applyMovedSourceVisibilityForBox(box);
        mask.style.left = `${rect.left}px`;
        mask.style.top = `${rect.top}px`;
        mask.style.width = `${rect.width}px`;
        mask.style.height = `${rect.height}px`;
        mask.replaceChildren();
        mask.style.background = samplePageSurroundingBackgroundColor(pageDiv, rect, '')
            || samplePageBackgroundColor(pageDiv, rect)
            || '#ffffff';
        return true;
    }
    const items = movedOverlayRunItemsForBox(box, pageDiv);
    const protectedRects = movedOverlayProtectedRectsForBox(box, rect);
    const applyFallbackPieces = () => {
        const cutRects = [
            ...protectedRects,
            ...movedOverlayRuleGapRectsForMask(rect, pageDiv),
        ];
        const fallbackPieces = subtractRects([{
            left: rect.left,
            top: rect.top,
            right: rect.left + rect.width,
            bottom: rect.top + rect.height,
        }], cutRects);
        const fallbackCoverage = fallbackPieces.reduce((sum, piece) => sum + (piece.width * piece.height), 0)
            / Math.max(1, rect.width * rect.height);
        if (!fallbackPieces.length || (rect.width >= 40 && fallbackCoverage < 0.82)) {
            mask.style.left = `${rect.left}px`;
            mask.style.top = `${rect.top}px`;
            mask.style.width = `${rect.width}px`;
            mask.style.height = `${rect.height}px`;
            mask.replaceChildren();
            mask.style.background = samplePageSurroundingBackgroundColor(pageDiv, rect, '')
                || samplePageBackgroundColor(pageDiv, rect)
                || '#ffffff';
            return true;
        }
        mask.style.left = `${rect.left}px`;
        mask.style.top = `${rect.top}px`;
        mask.style.width = `${rect.width}px`;
        mask.style.height = `${rect.height}px`;
        mask.style.background = 'transparent';
        mask.replaceChildren(...fallbackPieces.map((piece) => {
            const seg = document.createElement('div');
            seg.className = 'enpv-source-mask-run';
            seg.style.position = 'absolute';
            seg.style.left = `${piece.left - rect.left}px`;
            seg.style.top = `${piece.top - rect.top}px`;
            seg.style.width = `${piece.width}px`;
            seg.style.height = `${piece.height}px`;
            seg.style.background = samplePageSurroundingBackgroundColor(pageDiv, piece, '')
                || samplePageBackgroundColor(pageDiv, piece)
                || '#ffffff';
            seg.style.pointerEvents = 'none';
            return seg;
        }));
        return true;
    };
    if (!items.length) {
        return applyFallbackPieces();
    }
    const padX = MOVED_SOURCE_MASK_VISUAL_PADDING_PX;
    const runRects = [];
    for (const item of items) {
        const leftPx = Number(item.leftPx);
        const rightPx = Number(item.rightPx);
        const topPx = Number(item.topPx);
        const bottomPx = Number(item.bottomPx);
        if (![leftPx, rightPx].every(Number.isFinite) || rightPx <= leftPx) continue;
        runRects.push({
            left: Math.max(rect.left, leftPx - padX),
            top: Number.isFinite(topPx) ? Math.min(rect.top, topPx) : rect.top,
            right: Math.min(rect.left + rect.width, rightPx + padX),
            bottom: Number.isFinite(bottomPx) ? Math.max(rect.top + rect.height, bottomPx) : rect.top + rect.height,
        });
    }
    const cutRects = [
        ...protectedRects,
        ...runRects.flatMap((runRect) => movedOverlayRuleGapRectsForMask(runRect, pageDiv)),
    ];
    const protectedRunRects = subtractRects(runRects, cutRects);
    const runUnion = unionRects(protectedRunRects.map((runRect) => ({
        left: runRect.left,
        top: runRect.top,
        right: runRect.right,
        bottom: runRect.bottom,
    })));
    if (!runUnion || runUnion.width <= 0 || runUnion.height <= 0) {
        return applyFallbackPieces();
    }
    const runCoverage = (runUnion.width * runUnion.height) / Math.max(1, rect.width * rect.height);
    if (rect.width >= 40 && runCoverage < 0.82) {
        return applyFallbackPieces();
    }
    mask.style.left = `${runUnion.left}px`;
    mask.style.top = `${runUnion.top}px`;
    mask.style.width = `${runUnion.width}px`;
    mask.style.height = `${runUnion.height}px`;
    mask.style.background = 'transparent';
    const children = [];
    for (const runRect of protectedRunRects) {
        const clampedLeft = Math.max(0, runRect.left - runUnion.left);
        const clampedTop = Math.max(0, runRect.top - runUnion.top);
        const clampedRight = Math.min(runUnion.width, runRect.right - runUnion.left);
        const clampedBottom = Math.min(runUnion.height, runRect.bottom - runUnion.top);
        const width = clampedRight - clampedLeft;
        const height = clampedBottom - clampedTop;
        if (width <= 0 || height <= 0) continue;
        const seg = document.createElement('div');
        seg.className = 'enpv-source-mask-run';
        seg.style.position = 'absolute';
        seg.style.left = `${clampedLeft}px`;
        seg.style.top = `${clampedTop}px`;
        seg.style.width = `${width}px`;
        seg.style.height = `${height}px`;
        const segmentPageRect = {
            left: runRect.left,
            top: runRect.top,
            width: runRect.right - runRect.left,
            height: runRect.bottom - runRect.top,
        };
        seg.style.background = samplePageSurroundingBackgroundColor(pageDiv, segmentPageRect, '')
            || samplePageBackgroundColor(pageDiv, segmentPageRect)
            || '#ffffff';
        seg.style.pointerEvents = 'none';
        children.push(seg);
    }
    if (!children.length) {
        mask.replaceChildren();
        mask.style.background = samplePageSurroundingBackgroundColor(pageDiv, runUnion, '')
            || samplePageBackgroundColor(pageDiv, runUnion)
            || '#ffffff';
        return false;
    }
    mask.replaceChildren(...children);
    return true;
}

function scheduleMovedOverlayRunMaskRefresh(mask, box, rect, pageDiv = null) {
    if (!mask || !box || !rect || !pageDiv) return;
    window.requestAnimationFrame(() => applyMovedOverlayRunMaskSegments(mask, box, rect, pageDiv));
    window.setTimeout(() => applyMovedOverlayRunMaskSegments(mask, box, rect, pageDiv), 250);
    window.setTimeout(() => applyMovedOverlayRunMaskSegments(mask, box, rect, pageDiv), 1000);
}

function attachSourceMaskForBox(box) {
    if (!box) return null;
    if (!ENABLE_PDFJS_DOM_SOURCE_MASKS) {
        removeAnnBoxSourceMasks(box);
        return null;
    }
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
        const preserveRules = box.dataset.movedTextOverlay !== '1';
        mask = createSourceMaskElement(rect, pageDiv, { preserveRules });
        if (!mask) return null;
        const maskLayer = ensureSourceMaskLayer(pageDiv);
        (maskLayer || layer).appendChild(mask);
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
    if (box.dataset.movedTextOverlay === '1') {
        applyMovedOverlayRunMaskSegments(mask, box, rect, pageDiv);
        scheduleMovedOverlayRunMaskRefresh(mask, box, rect, pageDiv);
        applyMovedSourceVisibilityForBox(box);
    } else {
        scheduleRuleAwareMaskRefresh(mask, rect, pageDiv, { minRectWidth: 18 });
    }
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
    const shapeType = box.dataset.annotationType === 'shape' ? normalizeShapeType(box.dataset.shapeType) : '';
    const edges = shapeType === 'line'
        ? ['line-start', 'line-end']
        : (box.dataset.annotationType === 'shape'
            ? ['nw', 'ne', 'sw', 'se', 't', 'b', 'l', 'r']
            : (isImageBox(box) ? ['nw', 'ne', 'sw', 'se'] : ['t', 'r', 'b', 'l']));
    for (const edge of edges) {
        const handle = document.createElement('div');
        handle.className = `enpv-resize-handle ${edge}`;
        handle.dataset.edge = edge;
        if (edge === 'line-start') handle.setAttribute('aria-label', 'Move line start');
        if (edge === 'line-end') handle.setAttribute('aria-label', 'Move line end');
        handle.addEventListener('pointerdown', onResizeHandlePointerDown);
        box.appendChild(handle);
    }
    updateShapeResizeHandlesForBox(box);
    if (box.dataset.annotationType === 'shape') updateShapeSelectionChromeForBox(box);
}

function ensureAnnotationBoxLayer(pageIndex, scale) {
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    if (!pageDiv) return null;
    let layer = pageDiv.querySelector(':scope > .enpv-annotation-box-layer');
    if (!layer) {
        layer = document.createElement('div');
        layer.className = 'enpv-annotation-box-layer';
        layer.dataset.pageIndex = String(pageIndex);
        appendEditorAnnotationLayer(pageDiv, layer);
    }
    layer.dataset.scale = String(scale);
    normalizeEditorPaneOrder(pageDiv);
    return layer;
}

function pdfjsPointFromPageClient(pageIndex, clientX, clientY) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    const viewport = pageView?.viewport;
    if (!pageDiv || !viewport) return null;
    const pageRect = pageDiv.getBoundingClientRect();
    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!(scale > 0) || pageRect.width <= 0 || pageRect.height <= 0) return null;
    const viewportWidth = Number(viewport.width) || pageRect.width;
    const viewportHeight = Number(viewport.height) || pageRect.height;
    const canvasX = Math.max(0, Math.min(clientX - pageRect.left, viewportWidth));
    const canvasY = Math.max(0, Math.min(clientY - pageRect.top, viewportHeight));
    return {
        canvasX,
        canvasY,
        pdfX: canvasX / scale,
        pdfY: (viewportHeight - canvasY) / scale,
        pageRect,
        scale,
        viewport,
        pageDiv,
    };
}

function setDrawToolStatus(message) {
    if (drawToolStatus) drawToolStatus.textContent = String(message || '');
}

function syncDrawToolPanelUi() {
    if (drawToolPanel) {
        drawToolPanel.classList.toggle('is-visible', drawModeActive);
        drawToolPanel.setAttribute('aria-hidden', drawModeActive ? 'false' : 'true');
    }
    if (floatingDrawButton) {
        floatingDrawButton.classList.toggle('is-active', drawModeActive);
        floatingDrawButton.setAttribute('aria-pressed', drawModeActive ? 'true' : 'false');
        floatingDrawButton.title = drawModeActive ? 'Draw active — drag on the PDF to draw' : 'Draw — sketch directly on the PDF';
    }
    drawToolButtons.forEach((button) => {
        button.classList.toggle('is-active', button.dataset.drawDirectTool === drawToolType);
    });
    drawColorSwatches.forEach((button) => {
        button.classList.toggle('is-active', cssColorToHex(button.dataset.drawColor, '') === cssColorToHex(drawStrokeColor, '#111827'));
    });
    if (drawToolColorInput) drawToolColorInput.value = cssColorToHex(drawStrokeColor, '#111827');
    if (drawToolSizeInput) drawToolSizeInput.value = String(Math.round(drawBrushSize));
    if (drawToolSizeValue) drawToolSizeValue.textContent = `${Math.round(drawBrushSize)}px`;
    if (drawToolOpacityInput) {
        drawToolOpacityInput.value = String(Math.round(drawOpacity * 100));
        drawToolOpacityInput.disabled = false;
    }
    if (drawToolOpacityValue) drawToolOpacityValue.textContent = `${Math.round(drawOpacity * 100)}%`;
    setDrawToolStatus('Pen mode is active. Drag directly on the page to draw.');
}

function clearActiveDrawSession() {
    activeDrawSession?.layer?.remove?.();
    activeDrawSession = null;
}

function directDrawLayerForPage(pageIndex, className, rect = null, intrinsicWidth = null, intrinsicHeight = null) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const pageDiv = pageView?.div;
    const viewport = pageView?.viewport;
    if (!pageDiv || !viewport) return null;
    const width = Math.max(1, Number(intrinsicWidth || rect?.width || viewport.width) || 1);
    const height = Math.max(1, Number(intrinsicHeight || rect?.height || viewport.height) || 1);
    const layer = document.createElement('canvas');
    layer.className = className;
    layer.width = Math.round(width);
    layer.height = Math.round(height);
    layer.style.left = `${Math.max(0, Number(rect?.left) || 0)}px`;
    layer.style.top = `${Math.max(0, Number(rect?.top) || 0)}px`;
    layer.style.width = `${Math.max(1, Number(rect?.width || viewport.width) || 1)}px`;
    layer.style.height = `${Math.max(1, Number(rect?.height || viewport.height) || 1)}px`;
    pageDiv.appendChild(layer);
    return layer;
}

function clampDirectDrawVectorNumber(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function directDrawSvgPathFromPoints(points) {
    const cleanPoints = Array.isArray(points)
        ? points
            .map((point) => ({
                x: clampDirectDrawVectorNumber(point?.x, 0),
                y: clampDirectDrawVectorNumber(point?.y, 0),
            }))
            .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y))
        : [];
    if (!cleanPoints.length) return '';
    if (cleanPoints.length === 1) {
        const point = cleanPoints[0];
        return `M ${point.x.toFixed(3)} ${point.y.toFixed(3)} L ${(point.x + 0.01).toFixed(3)} ${point.y.toFixed(3)}`;
    }
    const [first, ...rest] = cleanPoints;
    return `M ${first.x.toFixed(3)} ${first.y.toFixed(3)} ${rest.map((point) => `L ${point.x.toFixed(3)} ${point.y.toFixed(3)}`).join(' ')}`;
}

function directDrawVectorToSvgDataUrl(vector) {
    if (!vector || typeof vector !== 'object') return '';
    const width = Math.max(1, clampDirectDrawVectorNumber(vector.width, 0));
    const height = Math.max(1, clampDirectDrawVectorNumber(vector.height, 0));
    const strokes = Array.isArray(vector.strokes) ? vector.strokes : [];
    const paths = strokes.map((stroke) => {
        const pathData = directDrawSvgPathFromPoints(stroke?.points);
        if (!pathData) return '';
        const color = cssColorToHex(stroke?.color, '#111827');
        const opacity = clamp01(stroke?.opacity ?? 1, 1);
        const brushSize = Math.max(0.25, clampDirectDrawVectorNumber(stroke?.brushSize, 1));
        return `<path d="${pathData}" fill="none" stroke="${color}" stroke-width="${brushSize.toFixed(3)}" stroke-linecap="round" stroke-linejoin="round" opacity="${opacity.toFixed(4)}"/>`;
    }).filter(Boolean).join('');
    if (!paths) return '';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">${paths}</svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function directDrawVectorFromSession(session, cropLeft, cropTop, cropWidth, cropHeight) {
    const points = (Array.isArray(session?.points) ? session.points : [])
        .map((point) => ({
            x: Math.max(0, Math.min(cropWidth, clampDirectDrawVectorNumber(point.x, 0) - cropLeft)),
            y: Math.max(0, Math.min(cropHeight, clampDirectDrawVectorNumber(point.y, 0) - cropTop)),
        }));
    if (!points.length) return null;
    return {
        version: 1,
        width: Math.max(1, cropWidth),
        height: Math.max(1, cropHeight),
        strokes: [{
            color: cssColorToHex(session.color, '#111827'),
            opacity: clamp01(session.opacity ?? 1, 1),
            brushSize: Math.max(0.25, clampDirectDrawVectorNumber(session.width, 1)),
            points,
        }],
    };
}

function directDrawAnnotationAtPageBox(pageIndex, asset, box) {
    if (!asset?.dataUrl || !box) return null;
    const uid = String(asset.uid || `draw_${generateUuidV4()}`);
    const annotation = normalizeImageAnnotation({
        _uid: uid,
        id: buildPdfjsAnnotationId(pageIndex, uid),
        pageIndex,
        type: 'image',
        pdfX: Math.max(0, Number(box.pdfX) || 0),
        pdfY: Math.max(0, Number(box.pdfY) || 0),
        pdfWidth: Math.max(1, Number(box.pdfWidth) || 1),
        pdfHeight: Math.max(1, Number(box.pdfHeight) || 1),
        rotation: 0,
        opacity: 1,
        userCreated: true,
        zIndex: 2,
        dataUrl: asset.dataUrl,
        src: '',
        fileName: 'drawing.png',
        mimeType: 'image/png',
        intrinsicWidth: Math.max(1, Number(asset.width) || 1),
        intrinsicHeight: Math.max(1, Number(asset.height) || 1),
        imageToolSource: 'direct-draw',
        drawStrokeColor: cssColorToHex(asset.drawStrokeColor, '#111827'),
        directDrawVector: asset.directDrawVector || null,
        text: '',
    });
    annotation._drawCreatedAt = Date.now();
    annotation._originalBox = { x: annotation.pdfX, y: annotation.pdfY, w: annotation.pdfWidth, h: annotation.pdfHeight };
    annotation._originalPdfBox = { ...annotation._originalBox };
    return annotation;
}

function upsertDirectDrawAnnotation(annotation, historyLabel = 'add drawing') {
    if (!annotation) return false;
    pushHistorySnapshot(historyLabel);
    upsertPersistedAnnotation(annotation);
    renderAnnotationBoxLayer(annotationPageIndex(annotation));
    const pageDiv = pdfViewer.getPageView(annotationPageIndex(annotation))?.div;
    const box = pageDiv?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(annotation.id)}"]`) || null;
    if (box) selectAnnBox(box);
    markManualSaveNeeded();
    return true;
}

function beginDirectPenStroke(event, pageIndex) {
    const point = pdfjsPointFromPageClient(pageIndex, event.clientX, event.clientY);
    if (!point) return false;
    const layer = directDrawLayerForPage(pageIndex, 'enpv-draw-preview-layer');
    const ctx = layer?.getContext('2d');
    if (!layer || !ctx) return false;
    clearActiveDrawSession();
    event.preventDefault();
    event.stopPropagation();
    try { point.pageDiv.setPointerCapture?.(event.pointerId); } catch (_) {}
    layer.style.opacity = String(drawOpacity);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.imageSmoothingEnabled = true;
    paintDrawDot(ctx, point.canvasX, point.canvasY, drawBrushSize, drawStrokeColor);
    activeDrawSession = {
        kind: 'pen',
        pageIndex,
        pageDiv: point.pageDiv,
        pointerId: event.pointerId,
        layer,
        ctx,
        color: drawStrokeColor,
        width: drawBrushSize,
        opacity: drawOpacity,
        points: [{ x: point.canvasX, y: point.canvasY }],
        lastMidPoint: { x: point.canvasX, y: point.canvasY },
        minX: point.canvasX - (drawBrushSize / 2),
        minY: point.canvasY - (drawBrushSize / 2),
        maxX: point.canvasX + (drawBrushSize / 2),
        maxY: point.canvasY + (drawBrushSize / 2),
        viewport: point.viewport,
        scale: point.scale,
    };
    return true;
}

function moveDirectPenStroke(event) {
    const session = activeDrawSession;
    if (!session || session.kind !== 'pen' || session.pointerId !== event.pointerId) return false;
    const pointInfo = pdfjsPointFromPageClient(session.pageIndex, event.clientX, event.clientY);
    if (!pointInfo) return true;
    event.preventDefault();
    const point = { x: pointInfo.canvasX, y: pointInfo.canvasY };
    const lastPoint = session.points[session.points.length - 1];
    const dx = point.x - lastPoint.x;
    const dy = point.y - lastPoint.y;
    if ((dx * dx) + (dy * dy) < 0.5) return true;
    session.points.push(point);
    session.minX = Math.min(session.minX, point.x - (session.width / 2));
    session.minY = Math.min(session.minY, point.y - (session.width / 2));
    session.maxX = Math.max(session.maxX, point.x + (session.width / 2));
    session.maxY = Math.max(session.maxY, point.y + (session.width / 2));
    const midPoint = { x: (lastPoint.x + point.x) / 2, y: (lastPoint.y + point.y) / 2 };
    paintDrawSegment(session.ctx, session.lastMidPoint, lastPoint, midPoint, session.width, session.color);
    session.lastMidPoint = midPoint;
    return true;
}

function finishDirectPenStroke(event) {
    const session = activeDrawSession;
    if (!session || session.kind !== 'pen' || session.pointerId !== event.pointerId) return false;
    event.preventDefault();
    event.stopPropagation();
    try { session.pageDiv?.releasePointerCapture?.(event.pointerId); } catch (_) {}
    if (session.points.length > 1) {
        const lastPoint = session.points[session.points.length - 1];
        paintDrawSegment(session.ctx, session.lastMidPoint, lastPoint, lastPoint, session.width, session.color);
    }
    const padding = Math.max(session.width, 8);
    const cropLeft = Math.max(0, Math.floor(session.minX - padding));
    const cropTop = Math.max(0, Math.floor(session.minY - padding));
    const cropRight = Math.min(session.layer.width, Math.ceil(session.maxX + padding));
    const cropBottom = Math.min(session.layer.height, Math.ceil(session.maxY + padding));
    if (cropRight <= cropLeft || cropBottom <= cropTop) {
        clearActiveDrawSession();
        return true;
    }
    const cropWidth = cropRight - cropLeft;
    const cropHeight = cropBottom - cropTop;
    const outputScale = 2;
    const outputCanvas = document.createElement('canvas');
    outputCanvas.width = Math.max(1, Math.ceil(cropWidth * outputScale));
    outputCanvas.height = Math.max(1, Math.ceil(cropHeight * outputScale));
    const outputCtx = outputCanvas.getContext('2d');
    outputCtx.scale(outputScale, outputScale);
    outputCtx.globalAlpha = session.opacity;
    outputCtx.drawImage(session.layer, -cropLeft, -cropTop);
    const pdfWidth = cropWidth / session.scale;
    const pdfHeight = cropHeight / session.scale;
    const annotation = directDrawAnnotationAtPageBox(session.pageIndex, {
        dataUrl: outputCanvas.toDataURL('image/png'),
        width: outputCanvas.width,
        height: outputCanvas.height,
        drawStrokeColor: session.color,
        directDrawVector: directDrawVectorFromSession(session, cropLeft, cropTop, cropWidth, cropHeight),
    }, {
        pdfX: cropLeft / session.scale,
        pdfY: ((Number(session.viewport.height) || session.layer.height) - cropBottom) / session.scale,
        pdfWidth,
        pdfHeight,
    });
    clearActiveDrawSession();
    if (upsertDirectDrawAnnotation(annotation, 'add drawing')) setDrawToolStatus('Drawing added. Keep drawing on the page.');
    return true;
}

function cancelDirectDrawPointer(event) {
    if (!activeDrawSession) return false;
    if (event && activeDrawSession.pointerId !== event.pointerId) return false;
    try { activeDrawSession.pageDiv?.releasePointerCapture?.(activeDrawSession.pointerId); } catch (_) {}
    clearActiveDrawSession();
    return true;
}

function setDrawMode(active) {
    const nextActive = Boolean(active);
    if (nextActive && document.body.classList.contains('enpv-edit-on')) setEditMode(false);
    if (nextActive && document.body.classList.contains('enpv-add-text-on')) setAddTextMode(false);
    if (nextActive && document.body.classList.contains('enpv-shape-on')) setShapeMode(false);
    if (!nextActive) clearActiveDrawSession();
    drawToolType = 'pen';
    drawModeActive = nextActive;
    document.body.classList.toggle('enpv-draw-on', drawModeActive);
    syncDrawToolPanelUi();
    if (drawModeActive) {
        deselectAnnBox();
        setStatus('Draw mode active. Drag on the PDF to draw.');
    } else if (!document.body.classList.contains('enpv-edit-on')
        && !document.body.classList.contains('enpv-add-text-on')
        && !document.body.classList.contains('enpv-shape-on')) {
        setStatus('Ready.');
    }
    renderAllAnnotationBoxLayers();
    scheduleRenderAllAnnotationBoxLayers();
}

function createNewTextAnnotation(pageIndex, canvasX, canvasY, canvasWidth = null, canvasHeight = null) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    if (!viewport) return null;
    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!(scale > 0)) return null;

    const defaultFontSize = 12;
    const defaultWidthPts = 36;
    const defaultHeightPts = Math.ceil(defaultFontSize * 1.25);
    const hasDraggedSize = Number.isFinite(canvasWidth) && Number.isFinite(canvasHeight);
    const widthPts = hasDraggedSize ? Math.max(60 / scale, Number(canvasWidth) / scale) : defaultWidthPts;
    const heightPts = hasDraggedSize ? Math.max(28 / scale, Number(canvasHeight) / scale) : defaultHeightPts;
    const rawX = Number(canvasX) / scale;
    const rawY = hasDraggedSize
        ? (Number(viewport.height) - (Number(canvasY) + (heightPts * scale))) / scale
        : ((Number(viewport.height) - Number(canvasY)) / scale) - defaultHeightPts;
    const pageWidthPts = Number(viewport.width) / scale;
    const pageHeightPts = Number(viewport.height) / scale;
    const pdfX = Math.max(0, Math.min(rawX, Math.max(0, pageWidthPts - widthPts)));
    const pdfY = Math.max(0, Math.min(rawY, Math.max(0, pageHeightPts - heightPts)));
    const uid = `new_${generateUuidV4()}`;
    const id = buildPdfjsAnnotationId(pageIndex, uid);
    return {
        id,
        type: 'text',
        pageIndex,
        text: '',
        originalText: '',
        pdfX,
        pdfY,
        pdfWidth: widthPts,
        pdfHeight: heightPts,
        fontSize: defaultFontSize,
        requestedFontSize: defaultFontSize,
        lineHeight: defaultFontSize * 1.2,
        fontFamily: 'Helvetica',
        fontWeight: '400',
        fontStyle: 'normal',
        textColor: '#000000',
        color: '#000000',
        backgroundColor: 'transparent',
        opacity: 1,
        underline: false,
        textAlign: 'left',
        verticalAlign: 'top',
        locked: false,
        zIndex: 6,
        savedTextOverlay: true,
        styleDirty: true,
        userCreated: true,
        userAuthored: true,
        userSizedTextBox: hasDraggedSize,
        userForcedRichText: true,
        pdfjsEditorMode: 'rich',
        skipPdfjsSourceMask: true,
        pdfjsAnchorUid: uid,
        _originalBox: { x: pdfX, y: pdfY, w: widthPts, h: heightPts },
        _originalPdfBox: { x: pdfX, y: pdfY, w: widthPts, h: heightPts },
    };
}

function createImageAnnotationOnPage(pageIndex, asset, centerPt = null) {
    if (!asset || (!asset.dataUrl && !asset.src)) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    if (!viewport) return null;
    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!(scale > 0)) return null;

    const pageWidthPts = Number(viewport.width) / scale;
    const pageHeightPts = Number(viewport.height) / scale;
    const intrinsicWidth = Math.max(1, Number(asset.width || asset.intrinsicWidth) || 1);
    const intrinsicHeight = Math.max(1, Number(asset.height || asset.intrinsicHeight) || 1);
    const aspectRatio = intrinsicWidth / intrinsicHeight;
    const maxWidthPts = pageWidthPts * 0.4;
    const maxHeightPts = pageHeightPts * 0.28;
    let pdfWidth = Math.max(72, Math.min(220, maxWidthPts));
    let pdfHeight = pdfWidth / Math.max(0.01, aspectRatio);
    if (pdfHeight > maxHeightPts) {
        pdfHeight = maxHeightPts;
        pdfWidth = pdfHeight * Math.max(0.01, aspectRatio);
    }
    pdfWidth = Math.max(48, Math.min(pdfWidth, pageWidthPts - 24));
    pdfHeight = Math.max(24, Math.min(pdfHeight, pageHeightPts - 24));
    const centerX = Number.isFinite(Number(centerPt?.x)) ? Number(centerPt.x) : pageWidthPts / 2;
    const centerY = Number.isFinite(Number(centerPt?.y)) ? Number(centerPt.y) : pageHeightPts / 2;
    const pdfX = Math.max(12, Math.min(pageWidthPts - pdfWidth - 12, centerX - (pdfWidth / 2)));
    const pdfY = Math.max(12, Math.min(pageHeightPts - pdfHeight - 12, centerY - (pdfHeight / 2)));
    const uid = `new_${generateUuidV4()}`;
    const id = buildPdfjsAnnotationId(pageIndex, uid);
    const annotation = normalizeImageAnnotation({
        id,
        _uid: uid,
        type: 'image',
        pageIndex,
        pdfX,
        pdfY,
        pdfWidth,
        pdfHeight,
        rotation: 0,
        opacity: 1,
        locked: false,
        zIndex: 6,
        userCreated: true,
        dataUrl: asset.dataUrl || '',
        src: asset.src || '',
        fileName: asset.fileName || 'image.png',
        mimeType: asset.mimeType || 'image/png',
        assetPath: asset.assetPath || asset.imagePath || null,
        intrinsicWidth,
        intrinsicHeight,
        pdfjsSourceX: pdfX,
        pdfjsSourceY: pdfY,
        pdfjsSourceW: pdfWidth,
        pdfjsSourceH: pdfHeight,
        pdfjsSourcePageHeight: pageHeightPts,
        pdfjsAnchorUid: uid,
        text: '',
        _originalBox: { x: pdfX, y: pdfY, w: pdfWidth, h: pdfHeight },
        _originalPdfBox: { x: pdfX, y: pdfY, w: pdfWidth, h: pdfHeight },
    });
    return annotation;
}

function createImageBoxElement(annotation, pageIndex, viewport, scale, editModeOn, hooks) {
    if (!isImageAnnotation(annotation)) return null;
    const pdfRect = annotationCurrentPdfBox(annotation) || annotation._originalPdfBox || null;
    const rect = pdfRectToCanvasRect(pdfRect, viewport, scale);
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box enpv-image-box is-persisted-overlay';
    box.dataset.uid = String(annotation.pdfjsAnchorUid || annotation.id || `${pageIndex}:image`);
    box.dataset.annotationId = String(annotation.id || '');
    box.dataset.pageIndex = String(pageIndex);
    box.dataset.annotationType = 'image';
    box.dataset.locked = annotation.locked ? '1' : '0';
    box.dataset.zIndex = String(Number(annotation.zIndex) || (isDirectDrawAnnotation(annotation) ? 2 : 6));
    box.dataset.basePageHeight = String(Number(viewport.height) / scale);
    box.style.left = `${rect.left}px`;
    box.style.top = `${rect.top}px`;
    box.style.width = `${Math.max(1, rect.width)}px`;
    box.style.height = `${Math.max(1, rect.height)}px`;
    box.style.zIndex = box.dataset.zIndex;
    box.style.opacity = String(normalizeOpacity(annotation.opacity, 1));
    box.classList.toggle('is-locked', annotation.locked === true);

    const img = document.createElement('img');
    img.className = 'enpv-image-img';
    img.draggable = false;
    const vectorSrc = isDirectDrawAnnotation(annotation) ? directDrawVectorToSvgDataUrl(annotation.directDrawVector) : '';
    const src = vectorSrc || getImageAnnotationSource(annotation);
    if (src) img.src = src;
    img.alt = isDirectDrawAnnotation(annotation) ? 'Drawing' : 'Image';
    box.appendChild(img);

    const label = document.createElement('span');
    label.className = 'enpv-image-selection-label';
    label.textContent = isDirectDrawAnnotation(annotation) ? 'drawing' : 'image';
    box.appendChild(label);

    if (hooks) {
        box.addEventListener('pointerdown', hooks.onAnnBoxPointerDown);
        for (const edge of ['nw', 'ne', 'sw', 'se']) {
            const handle = document.createElement('div');
            handle.className = `enpv-resize-handle ${edge}`;
            handle.dataset.edge = edge;
            handle.addEventListener('pointerdown', hooks.onResizeHandlePointerDown);
            box.appendChild(handle);
        }
    } else {
        box.classList.add('is-readonly');
    }
    return box;
}

function createNewTextBoxOnPage(pageIndex, canvasX, canvasY, canvasWidth = null, canvasHeight = null) {
    const annotation = createNewTextAnnotation(pageIndex, canvasX, canvasY, canvasWidth, canvasHeight);
    if (!annotation) return null;
    pushHistorySnapshot('add text');
    upsertPersistedAnnotation(annotation);
    renderAnnotationBoxLayer(pageIndex);
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    const box = pageDiv?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(annotation.id)}"]`) || null;
    if (box) {
        selectAnnBox(box);
        beginEditMode(box);
        box.dataset.pendingEdit = '1';
        positionAnnMenuOver(box);
        updateAnnotationFormatBarForBox(box);
    }
    markManualSaveNeeded();
    return annotation;
}

function pickImageImportTargetPage() {
    const pageDivs = Array.from(container?.querySelectorAll?.('.pdfViewer .page[data-page-number]') || []);
    if (!pageDivs.length) return null;
    const containerRect = container.getBoundingClientRect();
    const viewportLeft = containerRect.left;
    const viewportRight = containerRect.right;
    const viewportTop = containerRect.top;
    const viewportBottom = containerRect.bottom;
    const viewportCenterX = (viewportLeft + viewportRight) / 2;
    const viewportCenterY = (viewportTop + viewportBottom) / 2;
    let best = null;
    let bestOverlap = -Infinity;
    for (const pageDiv of pageDivs) {
        const rect = pageDiv.getBoundingClientRect();
        const overlapW = Math.max(0, Math.min(rect.right, viewportRight) - Math.max(rect.left, viewportLeft));
        const overlapH = Math.max(0, Math.min(rect.bottom, viewportBottom) - Math.max(rect.top, viewportTop));
        const overlap = overlapW * overlapH;
        if (overlap > bestOverlap) {
            bestOverlap = overlap;
            best = { pageDiv, rect };
        }
    }
    if (!best) return null;
    const pageNumber = Number.parseInt(best.pageDiv.dataset.pageNumber || '1', 10);
    const pageIndex = Number.isFinite(pageNumber) ? pageNumber - 1 : 0;
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    const scale = Number(viewport?.scale) || Number(pdfViewer.currentScale) || 1;
    if (!viewport || !(scale > 0)) return { pageIndex, centerPt: null };
    const localX = Math.max(0, Math.min(best.rect.width || 1, viewportCenterX - best.rect.left));
    const localY = Math.max(0, Math.min(best.rect.height || 1, viewportCenterY - best.rect.top));
    return {
        pageIndex,
        centerPt: {
            x: localX / scale,
            y: (Number(viewport.height) - localY) / scale,
        },
    };
}

function insertImageImportAsset(asset, target = null) {
    if (!asset || (!asset.dataUrl && !asset.src)) return null;
    const pageIndex = Number.isInteger(target?.pageIndex) ? target.pageIndex : Math.max(0, pdfViewer.currentPageNumber - 1);
    const annotation = createImageAnnotationOnPage(pageIndex, asset, target?.centerPt || null);
    if (!annotation) return null;
    pushHistorySnapshot('add image');
    upsertPersistedAnnotation(annotation);
    renderAnnotationBoxLayer(pageIndex);
    requestAnimationFrame(() => {
        const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
        const box = pageDiv?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(annotation.id)}"]`) || null;
        if (box) selectAnnBox(box);
    });
    markManualSaveNeeded();
    setStatus('Image placed. Drag to reposition or resize, then click Save.');
    return annotation;
}

function cancelImagePlacement(options = {}) {
    if (!imagePlacementState) return;
    imagePlacementState = null;
    document.body.classList.remove('enpv-image-placing');
    if (options.showStatus !== false) setStatus('Image placement cancelled.');
}

function beginImagePlacement(asset) {
    if (!asset || (!asset.dataUrl && !asset.src)) return false;
    imagePlacementState = { asset: { ...asset } };
    document.body.classList.add('enpv-image-placing');
    setStatus('Click the page location for the image.');
    return true;
}

function placePendingImageAtPointer(event) {
    if (!imagePlacementState) return;
    if (event.button !== 0) return;
    const pageIndex = pageIndexFromEventTarget(event.target);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    const point = pdfjsPointFromPageClient(pageIndex, event.clientX, event.clientY);
    if (!point) return;

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation?.();
    const asset = imagePlacementState.asset;
    const annotation = insertImageImportAsset(asset, {
        pageIndex,
        centerPt: { x: point.pdfX, y: point.pdfY },
    });
    if (!annotation) {
        setStatus('Could not place that image.', true);
        return;
    }
    cancelImagePlacement({ showStatus: false });
}

function removeAddTextPreview() {
    if (addTextCreationState?.previewEl) addTextCreationState.previewEl.remove();
    addTextCreationState = null;
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

    const sourceInfo = sourceInfoForSpan(spanEl);
    if (!sourceInfo) return null;
    const uid = sourceInfo.uid;
    const spanRect = sourceInfo.clientRect || spanEl.getBoundingClientRect();
    const anchorSpan = sourceInfo.anchor || spanEl;
    const rect = {
        left: sourceInfo.rect.left,
        top: sourceInfo.rect.top,
        width: sourceInfo.rect.width,
        height: sourceInfo.rect.height,
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
        sourceInfo.text || '',
        sourceInfo.text || spanEl.dataset.enpvOriginal || spanEl.textContent || '',
        false,
        uid,
    );
    const handleUid = sourceHandleUid(pageIndex, uid, matchedAnnotation);
    const annotationId = sourceHandleAnnotationId(pageIndex, uid, matchedAnnotation);
    if (deletedPdfjsSourceOwnsSpan(
        pageIndex,
        sourceBbox,
        sourceInfo.text || '',
        sourceInfo.text || spanEl.dataset.enpvOriginal || spanEl.textContent || '',
        uid,
        annotationId,
    )) {
        spanEl.dataset.enpvDeletedSource = '1';
        return null;
    }
    delete spanEl.dataset.enpvDeletedSource;
    const existingBox = layer.querySelector(`.enpv-annotation-box[data-uid="${cssEscape(handleUid)}"]`);
    if (existingBox) return existingBox;
    const sourceSnapshotRect = annotationBaselinePdfBox(matchedAnnotation) || sourceBbox;
    const baseRect = matchedAnnotation?._baselinePdfBox || sourceSnapshotRect;
    const baseText = String(matchedAnnotation?.pdfjsSourceText || matchedAnnotation?.originalText || sourceInfo.text || spanEl.dataset.enpvOriginal || spanEl.textContent || '');

    const box = document.createElement('div');
    box.className = 'enpv-annotation-box is-on-demand';
    box.dataset.uid = handleUid;
    box.dataset.annotationId = annotationId;
    box.dataset.pageIndex = String(pageIndex);
    box.dataset.originalText = String(matchedAnnotation?.originalText || baseText);
    box.dataset.occurrence = String(sourceInfo.occurrence || 0);
    box.dataset.sourceBboxX = String(sourceSnapshotRect.x);
    box.dataset.sourceBboxY = String(sourceSnapshotRect.y);
    box.dataset.sourceBboxW = String(sourceSnapshotRect.w);
    box.dataset.sourceBboxH = String(sourceSnapshotRect.h);
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
    for (const span of sourceGroupForSpan(spanEl)?.spans || [spanEl]) {
        span.dataset.enpvPersistentId = annotationId;
    }

    const sourcePageHeight = Number(viewport.height) / scale;
    const sourceStyle = promotedSourceStyleForPdfjsSource(pageIndex, sourceBbox, sourceInfo.text || baseText, sourcePageHeight);
    const cs = window.getComputedStyle(anchorSpan);
    let fsPx = parseFloat(cs.fontSize) || 0;
    if (spanRect.height > fsPx) fsPx = spanRect.height;
    if (!(fsPx > 1)) fsPx = Math.max(8, rect.height);
    captureSourceSpanMetrics(box, anchorSpan, spanRect, scale, pageView.div, sourceStyle);
    captureSourceSpanRunsForBox(box, sourceGroupForSpan(anchorSpan), pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv || null);
    restoreSourceSpanMetricsFromAnnotation(box, matchedAnnotation);
    if (matchedAnnotation?.fontFamily) box.style.setProperty('--enpv-font-family', matchedAnnotation.fontFamily);
    if (Number(matchedAnnotation?.fontSize) > 0) box.dataset.fontSizePts = String(Number(matchedAnnotation.fontSize));
    const matchedTextColor = cssColorToHex(
        (!boolish(matchedAnnotation?.styleDirty) && (matchedAnnotation?.pdfjsSourceTextColor || box.dataset.sourceTextColor))
        || matchedAnnotation?.textColor
        || matchedAnnotation?.color
        || '#000000',
    );
    box.style.setProperty('--enpv-text-color', matchedTextColor);
    if (matchedAnnotation) applyAnnotationTypographyToBox(box, matchedAnnotation, scale);

    const tc = document.createElement('div');
    tc.className = 'enpv-text-content';
    tc.setAttribute('role', 'textbox');
    tc.setAttribute('aria-multiline', 'true');
    tc.contentEditable = 'false';
    tc.spellcheck = false;
    const richTextHtml = sanitizeRichTextHtmlForAnnotation(matchedAnnotation?.richTextHtml || '');
    if (richTextHtml) tc.innerHTML = richTextHtml;
    else tc.textContent = normalizeAnnotationTextForDisplay(matchedAnnotation?.text || sourceInfo.visualText || sourceInfo.text || spanEl.textContent || spanEl.dataset.enpvOriginal || '');
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

function syncSelectedBoxToPersistedAnnotations(options = {}) {
    const selectedBox = findSelectedBox()
        || document.querySelector('.enpv-annotation-box.is-editing')
        || document.querySelector('.enpv-annotation-box.is-selected');
    if (!selectedBox) return;
    syncAnnotationBoxToPersistedAnnotations(selectedBox, options);
}

function syncAnnotationBoxToPersistedAnnotations(box, options = {}) {
    if (!box) return;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (isUserCreatedTextBox(box, existing) && !userCreatedBoxHasText(box)) {
        if (options.preserveEmptyUserCreated === true) {
            return;
        }
        removeUserCreatedTextBox(box, { skipHistory: true });
        return;
    }
    if (box.classList.contains('is-editing') && !options.preserveEditMode) {
        endEditMode(box);
    }
    const annotation = buildAnnotationFromBox(box, existing);
    if (annotation) {
        upsertPersistedAnnotation(annotation);
        box.classList.add('is-persisted-overlay');
        if (shouldMaskSourceForAnnotation(annotation)) {
            box.classList.remove('is-source-handle');
            attachSourceMaskForBox(box);
        } else {
            removeAnnBoxSourceMasks(box);
        }
        delete box.dataset.pendingEdit;
        delete box.dataset.pendingResize;
    } else if (box.dataset.annotationId) {
        deletePersistedAnnotation(box.dataset.annotationId);
        box.classList.remove('is-persisted-overlay');
        removeAnnBoxSourceMasks(box);
        clearTransientSourceFidelity(box);
    }
}

function promoteSourceBoxToMovedOverlay(box) {
    if (!box || box.classList.contains('is-persisted-overlay')) return;
    const tc = selectedBoxTextElement(box);
    if (tc && !String(tc.textContent || '').trim()) {
        tc.textContent = normalizeAnnotationTextForDisplay(box.dataset.baseText || box.dataset.originalText || '');
    }
    rememberSourceMaskRectForCurrentBox(box);
    box.dataset.movedTextOverlay = '1';
    box.classList.remove('is-source-handle');
    box.classList.add('is-persisted-overlay');
    applySourceFidelityTypography(box);
    applySourceFidelitySpanEditMarkup(box, { purpose: 'display' });
    refreshAttachedSourceFidelityTextFit(box);
    attachSourceMaskForBox(box);
    applyMovedSourceVisibilityForBox(box);
}

function syncDirtyBoxesToPersistedAnnotations(options = {}) {
    document.querySelectorAll(
        '.enpv-annotation-box.is-editing, ' +
        '.enpv-annotation-box[data-pending-edit="1"], ' +
        '.enpv-annotation-box[data-pending-resize="1"]'
    ).forEach((box) => syncAnnotationBoxToPersistedAnnotations(box, options));
}

function syncRenderedPersistedOverlayBoxesToPersistedAnnotations() {
    document.querySelectorAll('.enpv-annotation-box.is-persisted-overlay').forEach((box) => {
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        const annotation = buildAnnotationFromBox(box, existing);
        if (annotation) upsertPersistedAnnotation(annotation);
    });
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
const sourceGroupsByPage = new Map();
const suppressedStalePdfjsOverlayIds = new Set();

function medianNumber(values, fallback = 0) {
    const nums = values.filter((value) => Number.isFinite(value)).sort((a, b) => a - b);
    if (!nums.length) return fallback;
    const mid = Math.floor(nums.length / 2);
    return nums.length % 2 ? nums[mid] : ((nums[mid - 1] + nums[mid]) / 2);
}

function clampNumber(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function textLayerRelativeRect(el, layerRect) {
    if (!el || !layerRect) return null;
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    return {
        left: rect.left - layerRect.left,
        top: rect.top - layerRect.top,
        right: rect.right - layerRect.left,
        bottom: rect.bottom - layerRect.top,
        width: rect.width,
        height: rect.height,
        centerY: (rect.top - layerRect.top) + (rect.height / 2),
    };
}

function unionRects(rects) {
    const usable = rects.filter(Boolean);
    if (!usable.length) return null;
    const left = Math.min(...usable.map((rect) => rect.left));
    const top = Math.min(...usable.map((rect) => rect.top));
    const right = Math.max(...usable.map((rect) => rect.right ?? (rect.left + rect.width)));
    const bottom = Math.max(...usable.map((rect) => rect.bottom ?? (rect.top + rect.height)));
    return {
        left,
        top,
        right,
        bottom,
        width: right - left,
        height: bottom - top,
    };
}

function textLayerRectToPageRect(rect, layerEl, pageDiv) {
    if (!rect || !layerEl || !pageDiv) return null;
    const layerBounds = layerEl.getBoundingClientRect();
    const pageBounds = pageDiv.getBoundingClientRect();
    return {
        left: rect.left + (layerBounds.left - pageBounds.left),
        top: rect.top + (layerBounds.top - pageBounds.top),
        width: rect.width,
        height: rect.height,
    };
}

function detectVerticalCanvasRuleCuts(pageDiv, rect, options = {}) {
    if (!pageDiv || !rect || rect.width <= 0 || rect.height <= 0) return [];
    const minRectHeight = Number(options.minRectHeight ?? 8) || 8;
    if (rect.height < minRectHeight) return [];
    const minDarkRatio = Number(options.minDarkRatio ?? 0.62) || 0.62;
    const maxRuleWidth = Number(options.maxRuleWidth ?? 5) || 5;
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

    const darkColumns = [];
    for (let x = 0; x < sw; x += 1) {
        let dark = 0;
        for (let y = 0; y < sh; y += 1) {
            const offset = ((y * sw) + x) * 4;
            const r = data[offset];
            const g = data[offset + 1];
            const b = data[offset + 2];
            if (r < 150 && g < 150 && b < 150) dark += 1;
        }
        if (dark / sh >= minDarkRatio) darkColumns.push(x);
    }
    if (!darkColumns.length) return [];

    const groups = [];
    let start = darkColumns[0];
    let prev = darkColumns[0];
    for (const col of darkColumns.slice(1)) {
        if (col <= prev + 1) {
            prev = col;
            continue;
        }
        groups.push([start, prev]);
        start = col;
        prev = col;
    }
    groups.push([start, prev]);

    return groups
        .map(([a, b]) => ({
            x: rect.left + (((a + b + 1) / 2) / scaleX),
            width: Math.max(0.1, ((b - a + 1) / scaleX)),
        }))
        .filter((rule) => rule.width <= maxRuleWidth)
        .map((rule) => rule.x);
}

function detectTextLayerVerticalRuleCuts(pageDiv, layerEl, lineRect, medianHeight) {
    if (!pageDiv || !layerEl || !lineRect) return [];
    const padY = Math.max(3, medianHeight * 0.35);
    const pageRect = textLayerRectToPageRect({
        left: lineRect.left,
        top: Math.max(0, lineRect.top - padY),
        width: lineRect.width,
        height: lineRect.height + (padY * 2),
    }, layerEl, pageDiv);
    if (!pageRect) return [];
    const pageBounds = pageDiv.getBoundingClientRect();
    const layerBounds = layerEl.getBoundingClientRect();
    const layerOffsetX = layerBounds.left - pageBounds.left;
    return detectVerticalCanvasRuleCuts(pageDiv, pageRect, {
        minRectHeight: Math.max(8, medianHeight),
    }).map((x) => x - layerOffsetX);
}

function sourceGroupClientRect(group, layerEl) {
    if (!group?.rect || !layerEl) return null;
    const layerRect = layerEl.getBoundingClientRect();
    return {
        left: layerRect.left + group.rect.left,
        top: layerRect.top + group.rect.top,
        right: layerRect.left + group.rect.left + group.rect.width,
        bottom: layerRect.top + group.rect.top + group.rect.height,
        width: group.rect.width,
        height: group.rect.height,
        x: layerRect.left + group.rect.left,
        y: layerRect.top + group.rect.top,
    };
}

function buildSourceGroupText(items) {
    const charWidth = medianNumber(
        items.map((item) => {
            const len = String(item.text || '').replace(/\s+/g, '').length;
            return len > 0 ? item.rect.width / len : NaN;
        }),
        6,
    );
    const spaceGapPx = Math.max(2, charWidth * 0.45);
    let text = '';
    let previous = null;
    for (const item of items) {
        let segment = String(item.text || '').replace(/\s+/g, ' ');
        if (!segment.trim()) continue;
        if (previous) {
            const gap = item.rect.left - previous.rect.right;
            const punctuationGap = sourceSegmentNeedsPunctuationSpace(previous.text, segment, gap, charWidth);
            if ((gap > spaceGapPx || punctuationGap) && text && !text.endsWith(' ') && !segment.startsWith(' ')) {
                text += ' ';
            }
        }
        if (!text) {
            segment = segment.trimStart();
        } else if (text.endsWith(' ')) {
            segment = segment.trimStart();
        }
        text += segment;
        previous = item;
    }
    return text.trim();
}

function sourceSegmentNeedsPunctuationSpace(previousText, nextText, gapPx, charWidth) {
    if (!(gapPx > Math.max(1, charWidth * 0.25))) return false;
    const previous = String(previousText || '').trimEnd();
    const next = String(nextText || '').trimStart();
    return /[:;,.!?)]$/.test(previous) && /^[A-Za-z0-9(]/.test(next);
}

function buildSourceGroupVisualText(items) {
    const charWidth = medianNumber(
        items.map((item) => {
            const len = String(item.text || '').replace(/\s+/g, '').length;
            return len > 0 ? item.rect.width / len : NaN;
        }),
        6,
    );
    const spaceWidth = Math.max(2, charWidth * 0.48);
    const visibleGapThreshold = Math.max(2, charWidth * 0.45);
    let text = '';
    let previous = null;
    for (const item of items) {
        let segment = String(item.text || '').replace(/\s+/g, ' ');
        if (!segment.trim()) continue;
        if (previous) {
            const gap = item.rect.left - previous.rect.right;
            if (gap > visibleGapThreshold && text && !segment.startsWith(' ')) {
                const spaceCount = Math.max(1, Math.round(gap / spaceWidth));
                text = text.replace(/ +$/g, '');
                text += ' '.repeat(spaceCount);
                segment = segment.trimStart();
            } else if (sourceSegmentNeedsPunctuationSpace(previous.text, segment, gap, charWidth) && text && !text.endsWith(' ') && !segment.startsWith(' ')) {
                text += ' ';
                segment = segment.trimStart();
            } else if ((/\s$/.test(String(previous.text || '')) || /^\s/.test(segment)) && text && !text.endsWith(' ') && !segment.startsWith(' ')) {
                text += ' ';
                segment = segment.trimStart();
            } else if (text.endsWith(' ')) {
                segment = segment.trimStart();
            }
        } else {
            segment = segment.trimStart();
        }
        text += segment;
        previous = item;
    }
    return text.trim();
}

function liveSourceGroupItems(group, layerEl) {
    if (!group || !layerEl) return [];
    const layerRect = layerEl.getBoundingClientRect?.();
    if (!layerRect) return [];
    return Array.from(group.spans || [])
        .map((span, order) => {
            const text = String(span?.textContent || '');
            const rect = span ? textLayerRelativeRect(span, layerRect) : null;
            if (!text.trim() || !rect) return null;
            return { span, text, rect, order };
        })
        .filter(Boolean)
        .sort((a, b) => a.rect.left - b.rect.left || a.order - b.order);
}

function refreshSourceGroupTextFromDom(group, layerEl) {
    const items = liveSourceGroupItems(group, layerEl);
    if (!items.length) return group;
    const text = buildSourceGroupText(items);
    if (!text) return group;
    const visualText = buildSourceGroupVisualText(items) || text;
    const rect = unionRects(items.map((item) => item.rect));
    group.text = text;
    group.visualText = visualText;
    if (rect?.width > 0 && rect?.height > 0) group.rect = rect;
    group.spans = items.map((item) => item.span);
    group.anchor = group.anchor || group.spans[0];
    return group;
}

function rectOverlapRatioOfSmaller(left, right) {
    if (!left || !right) return 0;
    const leftRight = left.right ?? (left.left + left.width);
    const leftBottom = left.bottom ?? (left.top + left.height);
    const rightRight = right.right ?? (right.left + right.width);
    const rightBottom = right.bottom ?? (right.top + right.height);
    const overlapWidth = Math.max(0, Math.min(leftRight, rightRight) - Math.max(left.left, right.left));
    const overlapHeight = Math.max(0, Math.min(leftBottom, rightBottom) - Math.max(left.top, right.top));
    if (overlapWidth <= 0 || overlapHeight <= 0) return 0;
    const leftArea = Math.max(0.0001, left.width * left.height);
    const rightArea = Math.max(0.0001, right.width * right.height);
    return (overlapWidth * overlapHeight) / Math.min(leftArea, rightArea);
}

function chooseVisibleOverlappingLineItem(left, right) {
    const leftTop = Number(left?.rect?.top);
    const rightTop = Number(right?.rect?.top);
    if (Number.isFinite(leftTop) && Number.isFinite(rightTop)) {
        const topDelta = rightTop - leftTop;
        if (Math.abs(topDelta) > 0.2) return topDelta > 0 ? right : left;
    }
    const leftOrder = Number(left?.order) || 0;
    const rightOrder = Number(right?.order) || 0;
    return rightOrder >= leftOrder ? right : left;
}

function relatedSpansForSourceItem(item) {
    const spans = [];
    if (item?.span) spans.push(item.span);
    if (Array.isArray(item?.relatedSpans)) {
        item.relatedSpans.forEach((span) => {
            if (span && !spans.includes(span)) spans.push(span);
        });
    }
    return spans;
}

function shouldSuppressContainedSingleCharacterDuplicate(left, right) {
    const leftText = String(left?.text || '').trim();
    const rightText = String(right?.text || '').trim();
    if (!leftText || !rightText) return false;
    const leftSingle = leftText.length === 1;
    const rightSingle = rightText.length === 1;
    if (leftSingle === rightSingle) return false;
    const shortText = leftSingle ? leftText : rightText;
    const longText = leftSingle ? rightText : leftText;
    if (isStandaloneControlGlyphItem({ text: shortText })) return false;
    if (!longText.includes(shortText)) return false;
    const shortItem = leftSingle ? left : right;
    const longItem = leftSingle ? right : left;
    const shortHeight = Number(shortItem?.rect?.height) || 0;
    const longHeight = Number(longItem?.rect?.height) || 0;
    const minHeight = Math.max(0.0001, Math.min(shortHeight || longHeight || 0.0001, longHeight || shortHeight || 0.0001));
    const centerDelta = Math.abs((Number(shortItem?.rect?.centerY) || 0) - (Number(longItem?.rect?.centerY) || 0));
    if (centerDelta > minHeight * 0.2) return false;
    return rectOverlapRatioOfSmaller(shortItem?.rect, longItem?.rect) >= 0.86;
}

function suppressOverlappingSameLineItems(items) {
    const kept = [];
    for (const item of items) {
        const text = String(item.text || '').trim();
        let replaced = false;
        for (let i = 0; i < kept.length; i += 1) {
            const existing = kept[i];
            const existingText = String(existing.text || '').trim();
            const suppressContainedSingle = shouldSuppressContainedSingleCharacterDuplicate(existing, item);
            if ((text.length <= 1 || existingText.length <= 1) && !suppressContainedSingle) continue;
            if (rectOverlapRatioOfSmaller(item.rect, existing.rect) < 0.72) continue;
            const chosen = suppressContainedSingle
                ? (text.length === 1 ? existing : item)
                : chooseVisibleOverlappingLineItem(existing, item);
            const related = new Set([
                ...relatedSpansForSourceItem(existing),
                ...relatedSpansForSourceItem(item),
            ]);
            related.delete(chosen.span);
            kept[i] = {
                ...chosen,
                relatedSpans: Array.from(related),
            };
            replaced = true;
            break;
        }
        if (!replaced) kept.push(item);
    }
    return kept;
}

function isStandaloneControlGlyphItem(item) {
    const text = String(item?.text || '').trim();
    if (!text || text.length > 2) return false;
    return /^[\u2022\u25A0-\u25A3\u25AA-\u25AC\u25CF\u25E6\u2610-\u2612\u2713-\u2718\uF0A3\uF0B7\uF0FC\uF0FE]+$/.test(text);
}

function isStandaloneLowercaseLineLabelItem(item) {
    return /^[a-z]$/.test(String(item?.text || '').trim());
}

function isStandaloneNumberedLineLabelItem(item) {
    return /^\d{1,3}[.)]$/.test(String(item?.text || '').trim());
}

function isNumericColumnValueItem(item) {
    const text = String(item?.text || '').trim();
    if (!text || text.length > 24) return false;
    return /^\(?[-+$]?\d[\d,\s]*(?:\.\d+)?%?\)?$/.test(text);
}

function isTextColumnLabelItem(item) {
    const text = String(item?.text || '').trim();
    return /[A-Za-z]/.test(text);
}

function shouldSplitLabelValueColumnGap(previous, item, gapPx, line, charWidth) {
    if (!previous || !item || !(gapPx > 0)) return false;
    const labelThenValue = isTextColumnLabelItem(previous) && isNumericColumnValueItem(item);
    const valueThenLabel = isNumericColumnValueItem(previous) && isTextColumnLabelItem(item);
    if (!labelThenValue && !valueThenLabel) return false;
    const splitThreshold = Math.max(18, line.medianHeight * 1.05, charWidth * 4.25);
    return gapPx > splitThreshold;
}

function shouldSplitSameLineProseColumnGap(currentItems, previous, item, gapPx, line, charWidth) {
    if (!previous || !item || !(gapPx > 0)) return false;
    const previousText = String(previous.text || '').trim();
    const itemText = String(item.text || '').trim();
    if (!/[A-Za-z]{2,}/.test(previousText) || !/[A-Za-z]{2,}/.test(itemText)) return false;
    const previousHeight = Number(previous.rect?.height) || Number(line?.medianHeight) || 0;
    const itemHeight = Number(item.rect?.height) || Number(line?.medianHeight) || 0;
    const minHeight = Math.max(0.0001, Math.min(previousHeight || itemHeight || 0.0001, itemHeight || previousHeight || 0.0001));
    const centerDelta = Math.abs((Number(previous.rect?.centerY) || 0) - (Number(item.rect?.centerY) || 0));
    if (centerDelta > minHeight * 0.35) return false;
    const currentText = buildSourceGroupText(currentItems || []);
    const currentLetters = currentText.replace(/[^A-Za-z]/g, '').length;
    const previousLetters = previousText.replace(/[^A-Za-z]/g, '').length;
    const itemLetters = itemText.replace(/[^A-Za-z]/g, '').length;
    if (currentLetters < 8 && previousLetters < 4) return false;
    const longProseRuns = currentLetters >= 18 && itemLetters >= 6;
    const heightMultiplier = longProseRuns ? 0.55 : 0.65;
    const charMultiplier = longProseRuns ? 1.8 : 2.2;
    const splitThreshold = Math.max(14, (Number(line?.medianHeight) || minHeight) * heightMultiplier, charWidth * charMultiplier);
    return gapPx >= splitThreshold;
}

function shouldSplitShortLeadingColumnGap(currentItems, previous, item, gapPx, line) {
    if (!previous || !item || currentItems?.length !== 1 || !(gapPx > 0)) return false;
    const previousText = String(previous.text || '').replace(/\s+/g, ' ').trim();
    const itemText = String(item.text || '').replace(/\s+/g, ' ').trim();
    if (!previousText || !itemText) return false;
    const previousLetters = previousText.replace(/[^A-Za-z0-9]/g, '').length;
    const itemLetters = itemText.replace(/[^A-Za-z0-9]/g, '').length;
    if (previousText.length > 18 || previousLetters > 10 || itemLetters < 8) return false;
    if (!/[A-Za-z]/.test(previousText) || !/[A-Za-z]/.test(itemText)) return false;

    const previousHeight = Number(previous.rect?.height) || Number(line?.medianHeight) || 0;
    const itemHeight = Number(item.rect?.height) || Number(line?.medianHeight) || 0;
    const minHeight = Math.max(0.0001, Math.min(previousHeight || itemHeight || 0.0001, itemHeight || previousHeight || 0.0001));
    const centerDelta = Math.abs((Number(previous.rect?.centerY) || 0) - (Number(item.rect?.centerY) || 0));
    const previousFont = Number(previous.fontSizePx) || previousHeight;
    const itemFont = Number(item.fontSizePx) || itemHeight;
    const fontRatio = Math.max(previousFont, itemFont) / Math.max(0.0001, Math.min(previousFont, itemFont));
    const strongVisualSeparation = centerDelta > minHeight * 0.18 || fontRatio >= 1.10;
    const splitThreshold = Math.max(14, minHeight * (strongVisualSeparation ? 0.75 : 1.25));
    return gapPx >= splitThreshold;
}

function sourceItemTransformIsVertical(transform) {
    if (!transform || transform === 'none') return false;
    try {
        const matrix = new DOMMatrixReadOnly(transform);
        return Math.abs(matrix.b) > 0.35 || Math.abs(matrix.c) > 0.35;
    } catch (_) {
        return /matrix\(\s*0\s*,\s*[-\d.]+\s*,\s*[-\d.]+/i.test(String(transform));
    }
}

function normalizedSourceFontFamily(value) {
    return String(value || '')
        .split(',')[0]
        .replace(/^["']|["']$/g, '')
        .trim()
        .toLowerCase();
}

function normalizedSourceFontStyle(value) {
    return String(value || 'normal').trim().toLowerCase() || 'normal';
}

function normalizedSourceFontWeight(value) {
    const raw = String(value || '400').trim().toLowerCase();
    if (raw === 'normal') return 400;
    if (raw === 'bold') return 700;
    const parsed = Number.parseInt(raw, 10);
    return Number.isFinite(parsed) ? parsed : 400;
}

function sourceItemsAreInlineTextFlow(previous, item, gapPx, minHeight, centerDelta) {
    const previousText = String(previous?.text || '').trim();
    const itemText = String(item?.text || '').trim();
    if (!/[A-Za-z0-9)]$/.test(previousText) || !/^[A-Za-z0-9([]/.test(itemText)) return false;
    const lowerGapLimit = -Math.max(3, minHeight * 0.35);
    const upperGapLimit = Math.max(4, minHeight * 0.6);
    return gapPx >= lowerGapLimit && gapPx <= upperGapLimit && centerDelta <= minHeight * 0.25;
}

function shouldSplitMixedTypography(previous, item, gapPx) {
    if (!previous || !item) return false;
    if (previous.isVertical !== item.isVertical) return true;
    const previousHeight = Number(previous.rect?.height) || 0;
    const itemHeight = Number(item.rect?.height) || 0;
    const minHeight = Math.max(0.0001, Math.min(previousHeight, itemHeight));
    const centerDelta = Math.abs((Number(previous.rect?.centerY) || 0) - (Number(item.rect?.centerY) || 0));
    const isInlineTextFlow = sourceItemsAreInlineTextFlow(previous, item, gapPx, minHeight, centerDelta);
    if (!isInlineTextFlow) {
        if (previous.fontStyle !== item.fontStyle) return true;
        if (Math.abs(previous.fontWeight - item.fontWeight) >= 200) return true;
        if (previous.fontFamily && item.fontFamily && previous.fontFamily !== item.fontFamily) return true;
    }
    const previousScaleX = Number(previous.transformScaleX) || 1;
    const itemScaleX = Number(item.transformScaleX) || 1;
    const transformRatio = Math.max(previousScaleX, itemScaleX) / Math.max(0.0001, Math.min(previousScaleX, itemScaleX));
    if (transformRatio >= 1.18 && !isInlineTextFlow) return true;
    const maxHeight = Math.max(previousHeight, itemHeight);
    const heightRatio = maxHeight / minHeight;
    const previousFont = Number(previous.fontSizePx) || previousHeight;
    const itemFont = Number(item.fontSizePx) || itemHeight;
    const fontRatio = Math.max(previousFont, itemFont) / Math.max(0.0001, Math.min(previousFont, itemFont));
    if (heightRatio >= 1.45 || fontRatio >= 1.45) return true;
    return gapPx > 2 && centerDelta > minHeight * 0.38;
}

function collectSourceTextGroups(layerEl, pageIndex, pageDiv = null) {
    if (!layerEl) return [];
    const layerRect = layerEl.getBoundingClientRect();
    const rawItems = Array.from(layerEl.querySelectorAll('span'))
        .filter((span) => !span.classList.contains('markedContent'))
        .map((span, order) => {
            const text = String(span.textContent || '');
            const rect = textLayerRelativeRect(span, layerRect);
            if (!text || !rect) return null;
            const hasText = text.trim() !== '';
            const visibleInk = hasText && spanHasVisibleCanvasInk(span, pageDiv);
            if (!visibleInk) return null;
            const cs = window.getComputedStyle(span);
            const transform = cs.transform && cs.transform !== 'none' ? cs.transform : '';
            return {
                span,
                text,
                rect,
                order,
                fontSizePx: Number.parseFloat(cs.fontSize || '') || rect.height,
                fontFamily: normalizedSourceFontFamily(cs.fontFamily),
                fontStyle: normalizedSourceFontStyle(cs.fontStyle),
                fontWeight: normalizedSourceFontWeight(cs.fontWeight),
                transform,
                transformScaleX: sourceTransformScaleX(transform),
                isVertical: sourceItemTransformIsVertical(transform),
            };
        })
        .filter(Boolean);

    if (!rawItems.length) return [];

    const medianHeight = medianNumber(rawItems.map((item) => item.rect.height), 12);
    const lineTolerance = clampNumber(medianHeight * 0.45, 2.5, 8);
    const lineBuckets = [];
    const sortedByY = [...rawItems].sort((a, b) => {
        const dy = a.rect.centerY - b.rect.centerY;
        return Math.abs(dy) > lineTolerance ? dy : (a.rect.left - b.rect.left);
    });

    for (const item of sortedByY) {
        let bucket = null;
        for (const candidate of lineBuckets) {
            const tolerance = Math.max(lineTolerance, Math.min(candidate.medianHeight, item.rect.height) * 0.55);
            if (Math.abs(item.rect.centerY - candidate.centerY) <= tolerance) {
                bucket = candidate;
                break;
            }
        }
        if (!bucket) {
            lineBuckets.push({
                centerY: item.rect.centerY,
                medianHeight: item.rect.height,
                items: [item],
            });
            continue;
        }
        bucket.items.push(item);
        bucket.centerY = medianNumber(bucket.items.map((entry) => entry.rect.centerY), bucket.centerY);
        bucket.medianHeight = medianNumber(bucket.items.map((entry) => entry.rect.height), bucket.medianHeight);
    }

    const groups = [];
    for (const line of lineBuckets.sort((a, b) => a.centerY - b.centerY)) {
        const lineItems = suppressOverlappingSameLineItems(line.items)
            .sort((a, b) => a.rect.left - b.rect.left || a.order - b.order);
        const lineRect = unionRects(lineItems.map((item) => item.rect));
        const verticalRuleCuts = detectTextLayerVerticalRuleCuts(pageDiv, layerEl, lineRect, line.medianHeight);
        const charWidth = medianNumber(
            lineItems.map((item) => {
                const len = String(item.text || '').replace(/\s+/g, '').length;
                return len > 0 ? item.rect.width / len : NaN;
            }),
            6,
        );
        const splitGapPx = Math.max(24, line.medianHeight * 1.7, charWidth * 7);
        let current = [];
        let previous = null;
        const flush = () => {
            if (!current.length) return;
            const text = buildSourceGroupText(current);
            const visualText = buildSourceGroupVisualText(current);
            const rect = unionRects(current.map((item) => item.rect));
            if (text && rect?.width > 0 && rect?.height > 0) {
                const relatedSpans = Array.from(new Set(current.flatMap((item) => item.relatedSpans || [])))
                    .filter((span) => span && !current.some((item) => item.span === span));
                groups.push({
                    pageIndex,
                    text,
                    visualText,
                    rect,
                    spans: current.map((item) => item.span),
                    relatedSpans,
                    anchor: current[0].span,
                });
            }
            current = [];
        };

        for (const item of lineItems) {
            const gapFromPrevious = previous ? item.rect.left - previous.rect.right : 0;
            const isSeparatedByGap = previous && gapFromPrevious > splitGapPx;
            const isSeparatedByVerticalRule = previous && verticalRuleCuts.some((cutX) => (
                cutX >= Math.min(previous.rect.right, item.rect.left) - 2
                && cutX <= Math.max(previous.rect.right, item.rect.left) + 2
            ));
            const splitAroundControlGlyph = (
                (current.length > 0 && isStandaloneControlGlyphItem(item))
                || (current.length === 1 && isStandaloneControlGlyphItem(current[0]))
            );
            const splitAfterLowercaseLineLabel = (
                current.length === 1
                && isStandaloneLowercaseLineLabelItem(current[0])
                && gapFromPrevious > Math.max(6, line.medianHeight * 0.55)
            );
            const splitAfterNumberedLineLabel = (
                current.length === 1
                && isStandaloneNumberedLineLabelItem(current[0])
                && isTextColumnLabelItem(item)
                && gapFromPrevious > Math.max(6, line.medianHeight * 0.45)
            );
            const splitBetweenLabelAndNumericColumn = shouldSplitLabelValueColumnGap(
                previous,
                item,
                gapFromPrevious,
                line,
                charWidth,
            );
            const splitBetweenProseColumns = shouldSplitSameLineProseColumnGap(
                current,
                previous,
                item,
                gapFromPrevious,
                line,
                charWidth,
            );
            const splitShortLeadingColumn = shouldSplitShortLeadingColumnGap(
                current,
                previous,
                item,
                gapFromPrevious,
                line,
            );
            const splitMixedTypography = shouldSplitMixedTypography(previous, item, gapFromPrevious);
            if (isSeparatedByGap || isSeparatedByVerticalRule || splitAroundControlGlyph || splitAfterLowercaseLineLabel || splitAfterNumberedLineLabel || splitBetweenLabelAndNumericColumn || splitBetweenProseColumns || splitShortLeadingColumn || splitMixedTypography) {
                flush();
            }
            current.push(item);
            previous = item;
        }
        flush();
    }

    return groups.map((group, index) => ({ ...group, index }));
}

function getSourceGroupsForPage(pageIndex, layerEl, pageDiv = null) {
    const cached = sourceGroupsByPage.get(pageIndex);
    if (cached?.layerEl === layerEl) return cached.groups || [];
    const groups = collectSourceTextGroups(layerEl, pageIndex, pageDiv);
    sourceGroupsByPage.set(pageIndex, { layerEl, groups });
    return groups;
}

function sourceGroupForSpan(spanEl) {
    if (!spanEl) return null;
    const pageIndex = Number.parseInt(spanEl.dataset.enpvPage || '-1', 10);
    const groupIndex = Number.parseInt(spanEl.dataset.enpvGroupIndex || spanEl.dataset.enpvIndex || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0 || !Number.isFinite(groupIndex) || groupIndex < 0) return null;
    const cached = sourceGroupsByPage.get(pageIndex);
    return cached?.groups?.find((group) => group.index === groupIndex) || null;
}

function clearMovedSourceVisibilityMarks(layerEl) {
    if (!layerEl) return;
    layerEl.querySelectorAll('[data-enpv-moved-source-hidden="1"]').forEach((el) => {
        delete el.dataset.enpvMovedSourceHidden;
    });
}

function sourceGroupForAnnotation(annotation, pageIndex, layerEl = null) {
    if (!annotation || !Number.isFinite(pageIndex) || pageIndex < 0) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const sourceLayer = layerEl || pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!sourceLayer) return null;
    const groups = getSourceGroupsForPage(pageIndex, sourceLayer, pageView?.div || null);
    const targetText = normalizeComparableText(annotation.pdfjsSourceText || annotation.originalText || annotation.text || '');
    const targetOccurrence = String(annotation.pdfjsSourceOccurrence ?? annotation.occurrence ?? '').trim();
    const targetRect = annotationBaselinePdfBox(annotation) || annotationCurrentPdfBox(annotation);
    const anchorUid = String(annotation.pdfjsAnchorUid || '').trim();
    const anchorMatch = anchorUid.match(/^(\d+):(\d+)$/);
    if (anchorMatch && Number.parseInt(anchorMatch[1], 10) === pageIndex) {
        const groupIndex = Number.parseInt(anchorMatch[2], 10);
        const direct = groups.find((group) => Number(group.index) === groupIndex);
        if (direct) {
            const directText = normalizeComparableText(direct.text || direct.visualText || '');
            const directOccurrence = String(direct.occurrence ?? direct.anchor?.dataset?.enpvOccurrence ?? '').trim();
            const textMatches = !targetText || !directText || targetText === directText;
            const occurrenceMatches = !targetOccurrence || !directOccurrence || targetOccurrence === directOccurrence;
            let geometryMatches = true;
            if (targetRect && direct.rect) {
                const scale = Number(pageView?.viewport?.scale) || Number(pdfViewer.currentScale) || 1;
                const directRect = {
                    x: direct.rect.left / scale,
                    y: (Number(pageView?.viewport?.height) - (direct.rect.top + direct.rect.height)) / scale,
                    w: direct.rect.width / scale,
                    h: direct.rect.height / scale,
                };
                geometryMatches = scorePdfRectDistance(targetRect, directRect) <= 24;
            }
            if (textMatches && occurrenceMatches && geometryMatches) return direct;
        }
    }
    let best = null;
    let bestScore = Number.POSITIVE_INFINITY;
    for (const group of groups) {
        const groupText = normalizeComparableText(group.text || group.visualText || '');
        if (targetText && groupText && targetText !== groupText) continue;
        const occurrence = String(group.occurrence ?? group.anchor?.dataset?.enpvOccurrence ?? '').trim();
        if (targetOccurrence && occurrence && targetOccurrence !== occurrence) continue;
        let score = 0;
        if (targetRect && group.rect) {
            const scale = Number(pageView?.viewport?.scale) || Number(pdfViewer.currentScale) || 1;
            const groupRect = {
                x: group.rect.left / scale,
                y: (Number(pageView?.viewport?.height) - (group.rect.top + group.rect.height)) / scale,
                w: group.rect.width / scale,
                h: group.rect.height / scale,
            };
            score = scorePdfRectDistance(targetRect, groupRect);
        }
        if (score < bestScore) {
            best = group;
            bestScore = score;
        }
    }
    return best;
}

function movedSourceSiblingBelongsToAnotherGroup(span, groupIndex) {
    const siblingGroupIndex = String(span?.dataset?.enpvGroupIndex || span?.dataset?.enpvIndex || '').trim();
    return siblingGroupIndex && String(groupIndex ?? '').trim() && siblingGroupIndex !== String(groupIndex).trim();
}

function movedSourceSiblingIsSafeToHide(span, groupSet, groupIndex) {
    if (!span || span.tagName !== 'SPAN') return false;
    if (groupSet?.has(span)) return true;
    if (movedSourceSiblingBelongsToAnotherGroup(span, groupIndex)) return false;
    const siblingGroupIndex = String(span.dataset.enpvGroupIndex || span.dataset.enpvIndex || '').trim();
    if (siblingGroupIndex && siblingGroupIndex === String(groupIndex ?? '').trim()) return true;
    return !normalizeComparableText(span.textContent || '');
}

function markSourceGroupHiddenForMovedOverlay(group, annotationId = '') {
    if (!group?.spans?.length) return;
    const hiddenSpans = new Set([
        ...(group.spans || []),
        ...(group.relatedSpans || []),
    ]);
    const first = group.spans[0];
    const parent = first?.parentElement;
    const groupText = normalizeComparableText(group.text || group.visualText || '');
    if (parent?.classList?.contains('markedContent')) {
        const parentText = normalizeComparableText(parent.textContent || '');
        if (parentText && groupText && parentText === groupText) {
            parent.dataset.enpvMovedSourceHidden = '1';
            parent.querySelectorAll('span').forEach((span) => hiddenSpans.add(span));
        }
    }
    const groupSet = new Set(group.spans);
    const groupIndex = String(group.index ?? '');
    const siblings = Array.from(parent?.children || []);
    if (siblings.length && group.spans.every((span) => span.parentElement === parent)) {
        const indexes = group.spans.map((span) => siblings.indexOf(span)).filter((index) => index >= 0);
        if (indexes.length) {
            const minIndex = Math.min(...indexes);
            const maxIndex = Math.max(...indexes);
            siblings.slice(minIndex, maxIndex + 1).forEach((el) => {
                if (movedSourceSiblingIsSafeToHide(el, groupSet, groupIndex)) hiddenSpans.add(el);
            });
        }
    }
    hiddenSpans.forEach((span) => {
        span.dataset.enpvMovedSourceHidden = '1';
        if (annotationId && groupSet.has(span)) span.dataset.enpvPersistentId = annotationId;
    });
}

function markPersistentSourceSpansHiddenForMovedOverlay(annotationId, pageIndex) {
    const id = String(annotationId || '').trim();
    if (!id || !Number.isFinite(pageIndex) || pageIndex < 0) return false;
    const pageView = pdfViewer.getPageView(pageIndex);
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!layerEl) return false;
    const spans = Array.from(layerEl.querySelectorAll('span[data-enpv-persistent-id]'))
        .filter((span) => String(span.dataset.enpvPersistentId || '') === id);
    if (!spans.length) return false;
    const hidden = new Set(spans);
    const parent = spans[0].parentElement;
    const siblings = Array.from(parent?.children || []);
    const groupIndexes = new Set(spans.map((span) => String(span.dataset.enpvGroupIndex || span.dataset.enpvIndex || '').trim()).filter(Boolean));
    if (siblings.length && spans.every((span) => span.parentElement === parent)) {
        const indexes = spans.map((span) => siblings.indexOf(span)).filter((index) => index >= 0);
        if (indexes.length) {
            const minIndex = Math.min(...indexes);
            const maxIndex = Math.max(...indexes);
            siblings.slice(minIndex, maxIndex + 1).forEach((el) => {
                if (el.tagName !== 'SPAN') return;
                const siblingGroupIndex = String(el.dataset.enpvGroupIndex || el.dataset.enpvIndex || '').trim();
                const siblingId = String(el.dataset.enpvPersistentId || '').trim();
                const sameGroup = siblingGroupIndex && groupIndexes.has(siblingGroupIndex);
                const unassignedBlank = !siblingGroupIndex && !normalizeComparableText(el.textContent || '');
                if (siblingId === id || sameGroup || unassignedBlank) hidden.add(el);
            });
        }
    }
    hidden.forEach((span) => {
        span.dataset.enpvMovedSourceHidden = '1';
        if (spans.includes(span)) span.dataset.enpvPersistentId = id;
    });
    return true;
}

function applyMovedSourceVisibilityForPage(pageIndex, layerEl = null) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const sourceLayer = layerEl || pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    if (!sourceLayer) return;
    clearMovedSourceVisibilityMarks(sourceLayer);
    const annotations = annotationBoxesByPage.get(pageIndex) || [];
    for (const annotation of annotations) {
        if (!annotation || String(annotation.type || '').toLowerCase() !== 'text') continue;
        if (!boolish(annotation.savedTextOverlay) || !boolish(annotation.movedTextOverlay) || boolish(annotation.pdfjsDeleted)) continue;
        const group = sourceGroupForAnnotation(annotation, pageIndex, sourceLayer);
        if (group) markSourceGroupHiddenForMovedOverlay(group, String(annotation.id || ''));
        markPersistentSourceSpansHiddenForMovedOverlay(annotation.id || '', pageIndex);
    }
}

function applyMovedSourceVisibilityForBox(box) {
    if (!box || box.dataset.movedTextOverlay !== '1') return;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    const lookup = sourceGroupForBox(box);
    const group = lookup?.group
        || sourceGroupForAnnotation(persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || {
            id: box.dataset.annotationId,
            pdfjsAnchorUid: box.dataset.uid,
            pdfjsSourceText: box.dataset.baseText || box.dataset.originalText || '',
            pdfjsSourceOccurrence: box.dataset.occurrence || '',
            pdfjsSourceX: Number.parseFloat(box.dataset.sourceBboxX || box.dataset.baseBboxX || ''),
            pdfjsSourceY: Number.parseFloat(box.dataset.sourceBboxY || box.dataset.baseBboxY || ''),
            pdfjsSourceW: Number.parseFloat(box.dataset.sourceBboxW || box.dataset.baseBboxW || ''),
            pdfjsSourceH: Number.parseFloat(box.dataset.sourceBboxH || box.dataset.baseBboxH || ''),
        }, pageIndex);
    if (group) markSourceGroupHiddenForMovedOverlay(group, String(box.dataset.annotationId || ''));
    markPersistentSourceSpansHiddenForMovedOverlay(box.dataset.annotationId || '', pageIndex);
}

function sourceInfoForSpan(spanEl) {
    if (!spanEl) return null;
    const pageIndex = Number.parseInt(spanEl.dataset.enpvPage || '-1', 10);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return null;
    const pageView = pdfViewer.getPageView(pageIndex);
    const layerEl = pageView?.textLayer?.div || pageView?.textLayer?.textLayerDiv;
    const layerRect = layerEl?.getBoundingClientRect?.();
    if (!layerEl || !layerRect) return null;
    const group = sourceGroupForSpan(spanEl);
    if (group) {
        refreshSourceGroupTextFromDom(group, layerEl);
        return {
            pageIndex,
            anchor: group.anchor || spanEl,
            text: group.text,
            visualText: group.visualText || group.text,
            rect: group.rect,
            clientRect: sourceGroupClientRect(group, layerEl),
            index: group.index,
            occurrence: Number.parseInt(group.anchor?.dataset?.enpvOccurrence || spanEl.dataset.enpvOccurrence || '0', 10) || 0,
            uid: `${pageIndex}:${group.index}`,
        };
    }
    const rect = textLayerRelativeRect(spanEl, layerRect);
    if (!rect) return null;
    const index = Number.parseInt(spanEl.dataset.enpvIndex || spanEl.dataset.enpvOccurrence || '0', 10) || 0;
    return {
        pageIndex,
        anchor: spanEl,
        text: String(spanEl.dataset.enpvOriginal || spanEl.textContent || ''),
        visualText: String(spanEl.dataset.enpvOriginal || spanEl.textContent || ''),
        rect,
        clientRect: spanEl.getBoundingClientRect(),
        index,
        occurrence: Number.parseInt(spanEl.dataset.enpvOccurrence || '0', 10) || 0,
        uid: `${pageIndex}:${index}`,
    };
}

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
    const spans = Array.from(layerEl.querySelectorAll('span'));
    for (const span of spans) {
        delete span.dataset.enpvIndex;
        delete span.dataset.enpvGroupIndex;
        delete span.dataset.enpvGroupAnchor;
        delete span.dataset.enpvPage;
        delete span.dataset.enpvOccurrence;
        delete span.dataset.enpvOriginal;
    }
    const groups = collectSourceTextGroups(layerEl, pageIndex, pageDiv);
    sourceGroupsByPage.set(pageIndex, { layerEl, groups });
    const applyGroups = (sourceGroups) => {
        counters.clear();
        for (const group of sourceGroups) {
            refreshSourceGroupTextFromDom(group, layerEl);
            const text = group.text;
            if (!text || !text.trim()) continue;
            const occ = counters.get(text) || 0;
            counters.set(text, occ + 1);
            group.occurrence = occ;
            for (const span of [...(group.spans || []), ...(group.relatedSpans || [])]) {
                span.dataset.enpvIndex = String(group.index);
                span.dataset.enpvGroupIndex = String(group.index);
                span.dataset.enpvPage = String(pageIndex);
                span.dataset.enpvOccurrence = String(occ);
                span.dataset.enpvOriginal = text;
                if (span === group.anchor) span.dataset.enpvGroupAnchor = '1';
                if (span.dataset.enpvClickWired !== '1') {
                    span.addEventListener('click', onSpanClick);
                    span.addEventListener('dblclick', onSpanDblClick);
                    span.dataset.enpvClickWired = '1';
                }
            }
        }
        applyMovedSourceVisibilityForPage(pageIndex, layerEl);
    };
    applyGroups(groups);
    window.setTimeout(() => {
        if (!layerEl.isConnected) return;
        const latestPageDiv = pdfViewer.getPageView(pageIndex)?.div || null;
        const refreshedGroups = collectSourceTextGroups(layerEl, pageIndex, latestPageDiv);
        sourceGroupsByPage.set(pageIndex, { layerEl, groups: refreshedGroups });
        for (const span of spans) {
            delete span.dataset.enpvIndex;
            delete span.dataset.enpvGroupIndex;
            delete span.dataset.enpvGroupAnchor;
            delete span.dataset.enpvPage;
            delete span.dataset.enpvOccurrence;
            delete span.dataset.enpvOriginal;
        }
        applyGroups(refreshedGroups);
        renderAnnotationBoxLayer(pageIndex);
    }, 60);
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
        // Do not rewrite persisted source edits into the PDF.js canvas on load.
        // The visible editor path must keep the displayed base PDF stable and
        // render saved edits through overlay boxes/source masks only. Rewriting
        // here can bake white source masks into the canvas and make the
        // "original" text layer appear clipped before the user does anything.
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
        if (isImageAnnotation(annotation)) return true;
        if (String(annotation.type || '').toLowerCase() !== 'text') return false;
        if (isPromotedExtractionAnnotation(annotation)) {
            return pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation)
                || cleanPromotedSourceText(annotation) !== '';
        }
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
    removeSourceMaskLayer(pageDiv);
    markPageOverlaysPending(pageIndex);
}

function renderAnnotationBoxLayer(pageIndex) {
    const editModeOn = document.body.classList.contains('enpv-edit-on');
    const addTextModeOn = document.body.classList.contains('enpv-add-text-on');
    const allAnnotations = annotationBoxesByPage.get(pageIndex) || [];
    const allTextAnnotations = allAnnotations.filter(
        (annotation) => annotation && String(annotation.type || '').toLowerCase() === 'text',
    );
    const persistedTextAnnotations = allTextAnnotations.filter((annotation) => !isPromotedExtractionAnnotation(annotation));
    const promotedTextAnnotations = allTextAnnotations.filter((annotation) => isPromotedExtractionAnnotation(annotation));
    const persistedSignatureAnnotations = allAnnotations.filter(isSignatureAnnotation);
    const persistedImageAnnotations = allAnnotations.filter(isImageAnnotation);
    const directDrawAnnotations = persistedImageAnnotations.filter(isDirectDrawAnnotation);
    const regularImageAnnotations = persistedImageAnnotations.filter((annotation) => !isDirectDrawAnnotation(annotation));
    const shapeAnnotations = allAnnotations.filter(isShapeAnnotation);
    const visiblePersistedTextAnnotations = persistedTextAnnotations.filter(
        (annotation) => annotation.pdfjsDeleted !== true
            && annotation._pdfjsCanvasRewritten !== true
            && !isRedundantPdfjsSourceOverlay(annotation),
    );
    const visiblePromotedTextAnnotations = promotedTextAnnotations.filter(
        (annotation) => pdfjsPromotedOverlayShouldRenderAsPersistedOverlay(annotation)
            && annotation._pdfjsCanvasRewritten !== true,
    );
    const visibleTextAnnotations = [
        ...visiblePersistedTextAnnotations,
        ...visiblePromotedTextAnnotations,
    ];
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

    removeAnnotationBoxLayer(pageIndex);

    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    if (!scale) return;
    applyDeletedAcroWidgetVisibility(pageIndex);

    const staleCompositeVisibleTextAnnotations = visibleTextAnnotations.filter(
        (annotation) => isStaleCompositePdfjsSourceOverlay(annotation, pageIndex, viewport, scale),
    );
    staleCompositeVisibleTextAnnotations.forEach((annotation) => {
        const id = String(annotation?.id || '');
        if (id) suppressedStalePdfjsOverlayIds.add(id);
    });
    visibleTextAnnotations
        .filter((annotation) => isNoopMovedPdfjsSourceOverlay(annotation, pageIndex, viewport, scale))
        .forEach((annotation) => {
            const id = String(annotation?.id || '');
            if (id) suppressedStalePdfjsOverlayIds.add(id);
        });
    const renderableVisibleTextAnnotations = visibleTextAnnotations.filter(
        (annotation) => !isSuppressedStalePdfjsOverlay(annotation),
    );
    const preRenderedVisibleTextAnnotations = editModeOn
        ? renderableVisibleTextAnnotations.filter((annotation) => !pdfjsSourceOverlayShouldUseSourceBoxInEditMode(annotation, editModeOn))
        : renderableVisibleTextAnnotations;
    const preRenderedSourceOwners = buildSourceOwnershipRegistry(preRenderedVisibleTextAnnotations);
    const sourceOwners = buildSourceOwnershipRegistry(
        persistedTextAnnotations.filter((annotation) => !isSuppressedStalePdfjsOverlay(annotation)),
    );
    const promotedFallbackCandidates = promotedTextAnnotations.filter(
        (annotation) => !visiblePromotedTextAnnotations.includes(annotation)
            && cleanPromotedSourceText(annotation),
    );
    const promotedFallbackTextAnnotations = promotedFallbackCandidates.filter((annotation) => {
        if (!cleanPromotedSourceText(annotation)) return false;
        if (sourceOwners.isOwned(annotation)) return false;
        return textLayerEl
            ? !promotedFallbackIsCoveredByPdfjsText(annotation, textLayerEl, viewport, scale)
            : true;
    });
    if (!editModeOn
        && preRenderedVisibleTextAnnotations.length === 0
        && deletedTextMasks.length === 0
        && shapeAnnotations.length === 0
        && !(shapeCreationState?.active && shapeCreationState.pageIndex === pageIndex)
        && persistedSignatureAnnotations.length === 0
        && persistedImageAnnotations.length === 0
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
    removeSourceMaskLayer(pageDiv);
    const sourceMaskLayer = ensureSourceMaskLayer(pageDiv);

    const persistedOverlayRects = [];
    for (const annotation of deletedTextMasks) {
        const maskRect = deletedMaskCanvasRect(annotation, viewport, scale);
        const mask = createDeletedEraseElement(maskRect, pageDiv);
        if (!mask) continue;
        (sourceMaskLayer || layer).appendChild(mask);
        persistedOverlayRects.push(maskRect);
    }
    for (const annotation of shapeAnnotations) {
        const allowInteraction = editModeOn || document.body.classList.contains('enpv-shape-on');
        const rendered = createShapeOverlayBox(annotation, pageIndex, viewport, scale, allowInteraction);
        if (!rendered) continue;
        persistedOverlayRects.push(rendered.rect);
        layer.appendChild(rendered.box);
    }
    for (const annotation of directDrawAnnotations) {
        const imageBox = createImageBoxElement(annotation, pageIndex, viewport, scale, editModeOn, {
            onAnnBoxPointerDown,
            onResizeHandlePointerDown,
        });
        if (imageBox) layer.appendChild(imageBox);
    }
    for (const annotation of preRenderedVisibleTextAnnotations) {
        const allowInteraction = editModeOn || (addTextModeOn && isUserCreatedTextAnnotation(annotation));
        const rendered = createPersistedOverlayBox(annotation, pageIndex, viewport, scale, allowInteraction);
        if (!rendered) continue;
        persistedOverlayRects.push(rendered.maskRect || rendered.rect);
        if (cleanPromotedSourceText(annotation)) {
            hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale);
            window.requestAnimationFrame(() => hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale));
            window.setTimeout(() => hideCleanPromotedFallbackTextLayerSpan(annotation, pageIndex, viewport, scale), 250);
        }
        if (rendered.mask) (sourceMaskLayer || layer).appendChild(rendered.mask);
        layer.appendChild(rendered.box);
        scheduleHideTextLayerSpanUnderCleanPromotedBox(rendered.box);
        refreshAttachedSourceFidelityTextFit(rendered.box);
        // Capture per-span runs now while the live pdf.js text layer is
        // still in the DOM; persisted overlays saved before per-span
        // capture was added would otherwise hit edit mode with no runs.
        if (!rendered.box.dataset.sourceSpanRuns && rendered.box.classList.contains('is-source-fidelity')) {
            const lookup = sourceGroupForBox(rendered.box);
            if (lookup) captureSourceSpanRunsForBox(rendered.box, lookup.group, lookup.layerEl);
        }
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
    for (const annotation of regularImageAnnotations) {
        const imageBox = createImageBoxElement(annotation, pageIndex, viewport, scale, editModeOn, {
            onAnnBoxPointerDown,
            onResizeHandlePointerDown,
        });
        if (imageBox) layer.appendChild(imageBox);
    }

    if (!editModeOn) {
        appendPromotedFallbackOverlays(layer, promotedFallbackTextAnnotations, pageIndex, viewport, scale, editModeOn, sourceMaskLayer);
        if (shapeCreationState?.active && shapeCreationState.pageIndex === pageIndex && shapeCreationState.previewAnn) {
            const preview = createShapeOverlayBox(shapeCreationState.previewAnn, pageIndex, viewport, scale, false);
            if (preview) {
                preview.box.classList.add('is-shape-preview');
                layer.appendChild(preview.box);
            }
        }
        appendEditorAnnotationLayer(pageDiv, layer);
        scheduleHideTextLayerSpansUnderCleanPromotedBoxes(layer);
        fitPersistedRichOverlayBoxesToContent(layer);
        markPageOverlaysReady(pageIndex);
        return;
    }

    if (!textLayerEl) {
        if (layer.childElementCount) appendEditorAnnotationLayer(pageDiv, layer);
        markPageOverlaysReady(pageIndex);
        return;
    }

    applyMovedSourceVisibilityForPage(pageIndex, textLayerEl);

    const textLayerRect = textLayerEl.getBoundingClientRect();
    const sourceGroups = getSourceGroupsForPage(pageIndex, textLayerEl, pageDiv)
        .filter((group) => String(group.text || '').trim() !== '');
    if (!sourceGroups.length) {
        if (layer.childElementCount) appendEditorAnnotationLayer(pageDiv, layer);
        markPageOverlaysReady(pageIndex);
        return;
    }
    if (sourceGroups.length > MAX_SOURCE_SPAN_BOXES_PER_PAGE) {
        if (layer.childElementCount) appendEditorAnnotationLayer(pageDiv, layer);
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
    removeSourceMasksOverlappingAcroWidgets(layer, pageDiv);
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

    for (const group of sourceGroups) {
        refreshSourceGroupTextFromDom(group, textLayerEl);
        const span = group.anchor;
        const spanRect = sourceGroupClientRect(group, textLayerEl);
        if (!span || !spanRect) continue;
        const rawRect = {
            left: group.rect.left,
            top: group.rect.top,
            width: group.rect.width,
            height: group.rect.height,
        };
        const rect = paintedSourceGroupLayerRect(group, textLayerEl, pageDiv) || rawRect;
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
        if (preRenderedSourceOwners.ownerForSourceRect(sourceBbox, group.text || '')) continue;
        const uid = `${pageIndex}:${group.index}`;
        const matchedAnnotation = findPersistedAnnotationForSpan(
            pageIndex,
            sourceBbox,
            group.text || '',
            group.text || span.dataset.enpvOriginal || span.textContent || '',
            true,
            uid,
        );

        const offset = annotationOffsetsPts.get(uid);
        const handleUid = sourceHandleUid(pageIndex, uid, matchedAnnotation);
        const annotationId = sourceHandleAnnotationId(pageIndex, uid, matchedAnnotation);
        const sourceSnapshotRect = annotationBaselinePdfBox(matchedAnnotation) || sourceBbox;
        const baseRect = matchedAnnotation?._baselinePdfBox || sourceSnapshotRect;
        const baseText = String(matchedAnnotation?.pdfjsSourceText || matchedAnnotation?.originalText || group.text || span.dataset.enpvOriginal || span.textContent || '');

        const box = document.createElement('div');
        box.className = 'enpv-annotation-box is-source-handle';
        box.dataset.uid = handleUid;
        box.dataset.annotationId = annotationId;
        {
            const persistedPromoted = matchedAnnotation && isPromotedExtractionAnnotation(matchedAnnotation)
                ? matchedAnnotation
                : findPersistedPromotedAnnotationByText(pageIndex, group.text || span.dataset.enpvOriginal || span.textContent || '');
            if (persistedPromoted?.id) box.dataset.persistedAnnotationId = String(persistedPromoted.id);
        }
        box.dataset.pageIndex = String(pageIndex);
        box.dataset.originalText = String(matchedAnnotation?.originalText || baseText);
        box.dataset.occurrence = String(group.occurrence || span.dataset.enpvOccurrence || '0');
        box.dataset.sourceBboxX = String(sourceSnapshotRect.x);
        box.dataset.sourceBboxY = String(sourceSnapshotRect.y);
        box.dataset.sourceBboxW = String(sourceSnapshotRect.w);
        box.dataset.sourceBboxH = String(sourceSnapshotRect.h);
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
        for (const sourceSpan of group.spans || [span]) {
            sourceSpan.dataset.enpvPersistentId = annotationId;
        }

        const sourcePageHeight = Number(viewport.height) / scale;
        const sourceStyle = promotedSourceStyleForPdfjsSource(pageIndex, sourceBbox, group.text || baseText, sourcePageHeight);
        captureSourceSpanMetrics(box, span, spanRect, scale, pageDiv, sourceStyle);
        captureSourceSpanRunsForBox(box, group, textLayerEl);
        restoreSourceSpanMetricsFromAnnotation(box, matchedAnnotation);
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
        if (matchedAnnotation) applyAnnotationTypographyToBox(box, matchedAnnotation, scale);

        // Inner contenteditable element — initially read-only (only
        // becomes editable when the box is selected) so single-clicks
        // still trigger drag / select rather than placing a caret.
        const tc = document.createElement('div');
        tc.className = 'enpv-text-content';
        tc.setAttribute('role', 'textbox');
        tc.setAttribute('aria-multiline', 'true');
        tc.contentEditable = 'false';
        tc.spellcheck = false;
        const richTextHtml = sanitizeRichTextHtmlForAnnotation(matchedAnnotation?.richTextHtml || '');
        if (richTextHtml) tc.innerHTML = richTextHtml;
        else tc.textContent = normalizeAnnotationTextForDisplay(matchedAnnotation?.text || group.visualText || group.text || span.textContent || span.dataset.enpvOriginal || '');
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

    appendPromotedFallbackOverlays(layer, promotedFallbackTextAnnotations, pageIndex, viewport, scale, editModeOn, sourceMaskLayer);
    if (shapeCreationState?.active && shapeCreationState.pageIndex === pageIndex && shapeCreationState.previewAnn) {
        const preview = createShapeOverlayBox(shapeCreationState.previewAnn, pageIndex, viewport, scale, false);
        if (preview) {
            preview.box.classList.add('is-shape-preview');
            layer.appendChild(preview.box);
        }
    }

    appendEditorAnnotationLayer(pageDiv, layer);
    scheduleHideTextLayerSpansUnderCleanPromotedBoxes(layer);
    removeSourceMasksOverlappingAcroWidgets(layer, pageDiv);
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
    if (multiSelectedAnnBoxUids.size) {
        multiSelectedAnnBoxUids.forEach((uid) => {
            const box = layer.querySelector(`.enpv-annotation-box[data-uid="${cssEscape(uid)}"]`);
            if (box) box.classList.add('is-multi-selected');
        });
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

function pageIndexFromEventTarget(target) {
    const pageEl = target?.closest?.('.pdfViewer .page') || target?.closest?.('.page');
    if (!pageEl) return -1;
    const pageNumber = Number.parseInt(pageEl.dataset.pageNumber || '', 10);
    return Number.isFinite(pageNumber) ? pageNumber - 1 : -1;
}

function startAddTextCreation(ev) {
    if (!document.body.classList.contains('enpv-add-text-on')) return;
    if (ev.button !== 0 || dragState || multiDragState || resizeState || shapeRotateState || shapeCutState?.armed || marqueeSelectionState) return;
    if (ev.target?.closest?.('.enpv-ann-menu')) return;
    if (ev.target?.closest?.('.enpv-resize-handle')) return;
    const pageIndex = pageIndexFromEventTarget(ev.target);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    const point = pdfjsPointFromPageClient(pageIndex, ev.clientX, ev.clientY);
    if (!point) return;

    ev.preventDefault();
    ev.stopPropagation();
    deselectAnnBox();
    const previewEl = document.createElement('div');
    previewEl.className = 'text-drag-selection enpv-text-drag-selection';
    previewEl.style.left = `${point.canvasX}px`;
    previewEl.style.top = `${point.canvasY}px`;
    previewEl.style.width = '0px';
    previewEl.style.height = '0px';
    point.pageDiv.appendChild(previewEl);
    addTextCreationState = {
        pageIndex,
        pointerId: ev.pointerId,
        startCanvasX: point.canvasX,
        startCanvasY: point.canvasY,
        pageRect: point.pageRect,
        pageDiv: point.pageDiv,
        previewEl,
        moved: false,
    };
    try { point.pageDiv.setPointerCapture?.(ev.pointerId); } catch (_) {}
}

function updateAddTextCreation(ev) {
    const state = addTextCreationState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    const currentX = ev.clientX - state.pageRect.left;
    const currentY = ev.clientY - state.pageRect.top;
    const left = Math.min(state.startCanvasX, currentX);
    const top = Math.min(state.startCanvasY, currentY);
    const width = Math.abs(currentX - state.startCanvasX);
    const height = Math.abs(currentY - state.startCanvasY);
    state.previewEl.style.left = `${left}px`;
    state.previewEl.style.top = `${top}px`;
    state.previewEl.style.width = `${width}px`;
    state.previewEl.style.height = `${height}px`;
    if (width > 5 || height > 5) state.moved = true;
}

function finishAddTextCreation(ev) {
    const state = addTextCreationState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    ev.stopPropagation();
    const currentX = ev.clientX - state.pageRect.left;
    const currentY = ev.clientY - state.pageRect.top;
    const left = Math.min(state.startCanvasX, currentX);
    const top = Math.min(state.startCanvasY, currentY);
    const width = Math.abs(currentX - state.startCanvasX);
    const height = Math.abs(currentY - state.startCanvasY);
    const useDraggedBox = state.moved && width > 20 && height > 10;
    try { state.pageDiv.releasePointerCapture?.(ev.pointerId); } catch (_) {}
    removeAddTextPreview();
    const annotation = createNewTextBoxOnPage(
        state.pageIndex,
        useDraggedBox ? left : state.startCanvasX,
        useDraggedBox ? top : state.startCanvasY,
        useDraggedBox ? width : null,
        useDraggedBox ? height : null,
    );
    if (annotation) setAddTextMode(false, { skipRender: true });
}

const shapeInspectorRefs = {
    shapeStrokeColorInput,
    shapeStrokeHexInput,
    shapeStrokeTransparentInput,
    shapeStrokeWidthInput,
    shapeStrokeWidthValue,
    shapeStrokeOpacityInput,
    shapeStrokeOpacityValue,
    shapeFillColorInput,
    shapeFillHexInput,
    shapeFillTransparentInput,
    shapeFillOpacityInput,
    shapeFillOpacityValue,
    shapeTypeButtons,
};

function readCurrentShapeState() {
    return readShapeInspectorState(shapeInspectorRefs);
}

function pdfjsShapeAnnotationFromPoints(startPt, endPt, pageIndex, options = {}) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport;
    if (!viewport || !startPt || !endPt) return null;
    const scale = Number(viewport.scale) || Number(pdfViewer.currentScale) || 1;
    const pageWidthPts = Number(viewport.width) / scale;
    const pageHeightPts = Number(viewport.height) / scale;
    const defaults = readCurrentShapeState();
    const constrain = options.constrain === true;
    const uid = String(options.uid || `shape_${generateUuidV4()}`);
    const common = {
        _uid: uid,
        id: buildPdfjsAnnotationId(pageIndex, uid),
        pageIndex,
        type: 'shape',
        userCreated: true,
        text: '',
        locked: false,
        zIndex: 2,
        ...defaults,
    };

    if (normalizeShapeType(defaults.shapeType) === 'line') {
        const end = constrain
            ? constrainLineEndpointTo45(startPt.x, startPt.y, endPt.x, endPt.y)
            : endPt;
        const lineBox = computeLineBoxGeometry(startPt.x, startPt.y, end.x, end.y);
        const ann = normalizeShapeAnnotation({
            ...common,
            pdfX: Math.max(0, Math.min(lineBox.left, pageWidthPts)),
            pdfY: Math.max(0, Math.min(lineBox.bottom, pageHeightPts)),
            pdfWidth: Math.max(1, Math.min(lineBox.width, pageWidthPts)),
            pdfHeight: Math.max(1, Math.min(lineBox.height, pageHeightPts)),
            lineStartX: lineBox.lineStartX,
            lineStartY: lineBox.lineStartY,
            lineEndX: lineBox.lineEndX,
            lineEndY: lineBox.lineEndY,
        });
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { ...ann._originalBox };
        return ann;
    }

    let dx = endPt.x - startPt.x;
    let dy = endPt.y - startPt.y;
    if (constrain) {
        const side = Math.max(Math.abs(dx), Math.abs(dy));
        dx = (dx < 0 ? -1 : 1) * side;
        dy = (dy < 0 ? -1 : 1) * side;
    }
    const left = Math.max(0, Math.min(Math.min(startPt.x, startPt.x + dx), pageWidthPts));
    const bottom = Math.max(0, Math.min(Math.min(startPt.y, startPt.y + dy), pageHeightPts));
    const width = Math.max(1, Math.min(Math.abs(dx), pageWidthPts - left));
    const height = Math.max(1, Math.min(Math.abs(dy), pageHeightPts - bottom));
    const ann = normalizeShapeAnnotation({
        ...common,
        pdfX: left,
        pdfY: bottom,
        pdfWidth: width,
        pdfHeight: height,
    });
    ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
    ann._originalPdfBox = { ...ann._originalBox };
    return ann;
}

function removeShapeCreationPreview(state = shapeCreationState) {
    if (!state) return;
    if (state.previewRaf) {
        cancelAnimationFrame(state.previewRaf);
        state.previewRaf = 0;
    }
    state.previewBox?.remove?.();
    state.previewBox = null;
}

function ensureShapePreviewLayer(pageIndex, pageDiv, scale) {
    if (!pageDiv) return null;
    let layer = pageDiv.querySelector(':scope > .enpv-annotation-box-layer');
    if (!layer) {
        layer = document.createElement('div');
        layer.className = 'enpv-annotation-box-layer';
        layer.dataset.pageIndex = String(pageIndex);
        layer.dataset.scale = String(scale);
        layer.dataset.editMode = document.body.classList.contains('enpv-edit-on') ? '1' : '0';
        appendEditorAnnotationLayer(pageDiv, layer);
        markPageOverlaysReady(pageIndex);
    }
    normalizeEditorPaneOrder(pageDiv);
    return layer;
}

function updateShapeCreationPreviewBox(state) {
    if (!state?.active || !state.previewAnn) return;
    const pageView = pdfViewer.getPageView(state.pageIndex);
    const pageDiv = pageView?.div || state.pageDiv;
    const viewport = pageView?.viewport;
    const scale = Number(viewport?.scale) || Number(pdfViewer.currentScale) || 1;
    if (!pageDiv || !viewport || !scale) return;
    const layer = ensureShapePreviewLayer(state.pageIndex, pageDiv, scale);
    if (!layer) return;
    const ann = normalizeShapeAnnotation({ ...state.previewAnn });
    let box = state.previewBox;
    if (!box || !box.isConnected) {
        const rendered = createShapeOverlayBox(ann, state.pageIndex, viewport, scale, false);
        if (!rendered) return;
        box = rendered.box;
        box.classList.add('is-shape-preview');
        layer.appendChild(box);
        state.previewBox = box;
        return;
    }
    const currentPdfBox = annotationCurrentPdfBox(ann) || {
        x: Number(ann.pdfX),
        y: Number(ann.pdfY),
        w: Number(ann.pdfWidth),
        h: Number(ann.pdfHeight),
    };
    const rect = pdfRectToCanvasRect(currentPdfBox, viewport, scale);
    if (!rect || rect.width <= 0 || rect.height <= 0) return;
    box.dataset.uid = String(ann._uid || ann.id || `${state.pageIndex}:shape-preview`);
    box.dataset.annotationId = String(ann.id || buildPdfjsAnnotationId(state.pageIndex, box.dataset.uid));
    box.dataset.pageIndex = String(state.pageIndex);
    box.dataset.locked = '0';
    box.dataset.zIndex = String(Number(ann.zIndex) || 2);
    applyShapeAnnotationToBoxDataset(box, ann);
    box.style.left = `${rect.left}px`;
    box.style.top = `${rect.top}px`;
    box.style.width = `${Math.max(1, rect.width)}px`;
    box.style.height = `${Math.max(1, rect.height)}px`;
    box.style.zIndex = box.dataset.zIndex;
    updateShapeSvgForBox(box, ann, scale);
}

function scheduleShapeCreationPreviewUpdate(state = shapeCreationState) {
    if (!state || state.previewRaf) return;
    state.previewRaf = requestAnimationFrame(() => {
        state.previewRaf = 0;
        updateShapeCreationPreviewBox(state);
    });
}

function startShapeCreation(ev) {
    if (!document.body.classList.contains('enpv-shape-on')) return;
    if (ev.button !== 0 || dragState || multiDragState || resizeState || shapeRotateState || shapeCutState?.armed || marqueeSelectionState) return;
    if (ev.target?.closest?.('.enpv-annotation-box')) return;
    if (ev.target?.closest?.('.enpv-ann-menu')) return;
    const pageIndex = pageIndexFromEventTarget(ev.target);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    const point = pdfjsPointFromPageClient(pageIndex, ev.clientX, ev.clientY);
    if (!point) return;

    ev.preventDefault();
    ev.stopPropagation();
    deselectAnnBox();
    shapeCreationState = {
        active: true,
        pageIndex,
        pointerId: ev.pointerId,
        uid: `shape_${generateUuidV4()}`,
        startPt: { x: point.pdfX, y: point.pdfY },
        currentPt: { x: point.pdfX, y: point.pdfY },
        pageDiv: point.pageDiv,
        previewAnn: null,
        previewBox: null,
        previewRaf: 0,
        moved: false,
    };
    shapeCreationState.previewAnn = pdfjsShapeAnnotationFromPoints(
        { x: point.pdfX, y: point.pdfY },
        { x: point.pdfX, y: point.pdfY },
        pageIndex,
        { constrain: ev.shiftKey, uid: shapeCreationState.uid },
    );
    try { point.pageDiv.setPointerCapture?.(ev.pointerId); } catch (_) {}
    updateShapeCreationPreviewBox(shapeCreationState);
}

function updateShapeCreation(ev) {
    const state = shapeCreationState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    const point = pdfjsPointFromPageClient(state.pageIndex, ev.clientX, ev.clientY);
    if (!point) return;
    state.currentPt = { x: point.pdfX, y: point.pdfY };
    state.previewAnn = pdfjsShapeAnnotationFromPoints(state.startPt, state.currentPt, state.pageIndex, { constrain: ev.shiftKey, uid: state.uid });
    const dx = state.currentPt.x - state.startPt.x;
    const dy = state.currentPt.y - state.startPt.y;
    if (Math.hypot(dx, dy) > 3) state.moved = true;
    scheduleShapeCreationPreviewUpdate(state);
}

function finishShapeCreation(ev) {
    const state = shapeCreationState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    ev.stopPropagation();
    try { state.pageDiv?.releasePointerCapture?.(ev.pointerId); } catch (_) {}
    const ann = state.previewAnn ? normalizeShapeAnnotation(state.previewAnn) : null;
    const pageIndex = state.pageIndex;
    removeShapeCreationPreview(state);
    shapeCreationState = null;
    if (!ann) {
        renderAnnotationBoxLayer(pageIndex);
        return;
    }
    const minDimension = normalizeShapeType(ann.shapeType) === 'line' ? 6 : 8;
    if (!state.moved || Math.max(Number(ann.pdfWidth) || 0, Number(ann.pdfHeight) || 0) < minDimension) {
        renderAnnotationBoxLayer(pageIndex);
        return;
    }
    pushHistorySnapshot('add shape');
    upsertPersistedAnnotation(ann);
    renderAnnotationBoxLayer(pageIndex);
    const pageDiv = pdfViewer.getPageView(pageIndex)?.div;
    const box = pageDiv?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(ann.id)}"]`) || null;
    if (box) selectAnnBox(box);
    markManualSaveNeeded();
}

function startDirectDrawGesture(ev) {
    if (!drawModeActive) return;
    if (ev.button !== 0) return;
    if (ev.target?.closest?.('#draw-tool-panel, .floating-tool-bar, .top-bar, .enpv-ann-menu')) return;
    const pageIndex = pageIndexFromEventTarget(ev.target);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    beginDirectPenStroke(ev, pageIndex);
}

function updateDirectDrawGesture(ev) {
    if (!drawModeActive || !activeDrawSession) return;
    moveDirectPenStroke(ev);
}

function finishDirectDrawGesture(ev) {
    if (!drawModeActive || !activeDrawSession) return;
    finishDirectPenStroke(ev);
}

container.addEventListener('pointerdown', startDirectDrawGesture, { capture: true });
container.addEventListener('pointermove', updateDirectDrawGesture, { capture: true });
container.addEventListener('pointerup', finishDirectDrawGesture, { capture: true });
container.addEventListener('pointercancel', cancelDirectDrawPointer, { capture: true });
container.addEventListener('pointerdown', startShapeCutGesture, { capture: true });
container.addEventListener('pointermove', updateShapeCutGesture, { capture: true });
container.addEventListener('pointerup', finishShapeCutGesture, { capture: true });
container.addEventListener('pointercancel', cancelShapeCutGesture, { capture: true });
container.addEventListener('pointerdown', startAddTextCreation, { capture: true });
container.addEventListener('pointermove', updateAddTextCreation, { capture: true });
container.addEventListener('pointerup', finishAddTextCreation, { capture: true });
container.addEventListener('pointercancel', (ev) => {
    if (addTextCreationState?.pointerId === ev.pointerId) removeAddTextPreview();
}, { capture: true });
container.addEventListener('pointerdown', startShapeCreation, { capture: true });
container.addEventListener('pointermove', updateShapeCreation, { capture: true });
container.addEventListener('pointerup', finishShapeCreation, { capture: true });
container.addEventListener('pointercancel', (ev) => {
    if (!shapeCreationState || shapeCreationState.pointerId !== ev.pointerId) return;
    const pageIndex = shapeCreationState.pageIndex;
    removeShapeCreationPreview(shapeCreationState);
    shapeCreationState = null;
    renderAnnotationBoxLayer(pageIndex);
}, { capture: true });
container.addEventListener('pointerdown', startMarqueeSelection, { capture: true });
container.addEventListener('pointermove', updateMarqueeSelection, { capture: true });
container.addEventListener('pointerup', finishMarqueeSelection, { capture: true });
container.addEventListener('pointercancel', cancelMarqueeSelection, { capture: true });

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
const ANN_MENU_LOCKED_ICON_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 1 1 8 0v3"></path></svg>';
const ANN_MENU_UNLOCKED_ICON_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 7.88-1"></path></svg>';
let selectedAnnBoxUid = null;
const multiSelectedAnnBoxUids = new Set();
// True while the selected box is in in-place edit mode (.is-editing,
// contenteditable). pdf.js fires `pagerendered` repeatedly during
// streaming render and zoom, and each fire rebuilds the page's
// annotation-box layer — wiping the edit state. We track this flag so
// the re-anchor block in renderAnnotationBoxLayer can restore edit
// mode on the new box rather than silently dropping the user mid-type.
let selectedAnnBoxIsEditing = false;
let dragState = null;
let multiDragState = null;
let marqueeSelectionState = null;
let addTextCreationState = null;
let shapeCreationState = null;
let shapeRotateState = null;
let shapeCutState = null;
let imagePlacementState = null;
// Signature feature handle — installed below once viewer is wired.
let signatureFeature = null;

function cssEscape(s) {
    if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
}

function findSelectedBox() {
    if (selectedAnnBoxUid) {
        const uidSelector = `.enpv-annotation-box[data-uid="${cssEscape(selectedAnnBoxUid)}"]`;
        const selectedByUid = document.querySelector(`.enpv-annotation-box.is-selected[data-uid="${cssEscape(selectedAnnBoxUid)}"]`);
        if (selectedByUid) return selectedByUid;
        const byUid = document.querySelector(uidSelector);
        if (byUid) {
            byUid.classList.add('is-selected');
            return byUid;
        }
    }
    const selected = document.querySelector('.enpv-annotation-box.is-selected');
    if (selected) {
        selectedAnnBoxUid = selected.dataset.uid || null;
        return selected;
    }
    return null;
}

function clearMultiSelection() {
    if (!multiSelectedAnnBoxUids.size) return;
    document.querySelectorAll('.enpv-annotation-box.is-multi-selected').forEach((box) => {
        box.classList.remove('is-multi-selected');
    });
    multiSelectedAnnBoxUids.clear();
}

function multiSelectedBoxes() {
    if (!multiSelectedAnnBoxUids.size) return [];
    const boxes = [];
    multiSelectedAnnBoxUids.forEach((uid) => {
        const box = document.querySelector(`.enpv-annotation-box[data-uid="${cssEscape(uid)}"]`);
        if (box?.isConnected) boxes.push(box);
    });
    return boxes;
}

function hasMultiSelection() {
    return multiSelectedAnnBoxUids.size > 0;
}

function selectMultipleAnnBoxes(boxes) {
    const selectable = Array.from(new Set(Array.from(boxes || [])))
        .filter((box) => box?.isConnected && box.classList.contains('enpv-annotation-box') && !isAnnBoxLocked(box));
    if (selectable.length <= 0) {
        clearMultiSelection();
        return;
    }
    if (selectable.length === 1) {
        clearMultiSelection();
        selectAnnBox(selectable[0]);
        return;
    }

    deselectAnnBox();
    multiSelectedAnnBoxUids.clear();
    selectable.forEach((box) => {
        const uid = String(box.dataset.uid || '');
        if (!uid) return;
        multiSelectedAnnBoxUids.add(uid);
        box.classList.add('is-multi-selected');
    });
    selectedAnnBoxUid = null;
    selectedAnnBoxIsEditing = false;
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    setStatus(`${multiSelectedAnnBoxUids.size} annotations selected.`);
}

function isAnnBoxLocked(box) {
    return box?.dataset?.locked === '1' || box?.classList?.contains('is-locked');
}

function formatBarAvailableOptions(select) {
    return Array.from(select?.options || [])
        .filter((option) => !option.disabled && String(option.value || '').trim());
}

const embeddedFontRegistry = new Map();
let embeddedFontsLoadPromise = null;

function normalizeFontKey(value) {
    return String(value || '')
        .trim()
        .replace(/^["']|["']$/g, '')
        .toLowerCase();
}

function embeddedFontOptionForValue(value) {
    const key = normalizeFontKey(value);
    if (!key) return null;
    return embeddedFontRegistry.get(key) || null;
}

function registerEmbeddedFontMetadata(font) {
    const cleanName = String(font?.clean_name || font?.name || '').trim();
    const filePath = String(font?.file_path || '').trim();
    if (!cleanName || !filePath) return null;
    const metadata = {
        cleanName,
        family: String(font?.family || cleanName).trim() || cleanName,
        pdfFontName: String(font?.pdf_font_name || '').trim(),
        filePath,
        weight: String(font?.css_weight || '400').trim() || '400',
        style: String(font?.css_style || 'normal').trim() || 'normal',
        stretch: String(font?.css_stretch || 'normal').trim() || 'normal',
        xref: font?.xref ?? null,
    };
    [
        metadata.cleanName,
        metadata.family,
        metadata.pdfFontName,
        metadata.pdfFontName.includes('+') ? metadata.pdfFontName.split('+').slice(1).join('+') : '',
    ].forEach((candidate) => {
        const key = normalizeFontKey(candidate);
        if (key && !embeddedFontRegistry.has(key)) embeddedFontRegistry.set(key, metadata);
    });
    embeddedFontRegistry.set(normalizeFontKey(metadata.cleanName), metadata);
    return metadata;
}

function fontFaceFormatForPath(path) {
    const lower = String(path || '').split('?')[0].toLowerCase();
    if (lower.endsWith('.otf')) return 'opentype';
    if (lower.endsWith('.woff2')) return 'woff2';
    if (lower.endsWith('.woff')) return 'woff';
    return 'truetype';
}

async function loadEmbeddedFontFace(metadata) {
    if (!metadata?.cleanName || !metadata?.filePath || !('FontFace' in window)) return;
    try {
        const source = `url("${metadata.filePath.replace(/"/g, '\\"')}") format("${fontFaceFormatForPath(metadata.filePath)}")`;
        const face = new FontFace(metadata.cleanName, source, {
            weight: metadata.weight,
            style: metadata.style,
            stretch: metadata.stretch,
        });
        document.fonts.add(face);
        await face.load();
    } catch (err) {
        console.warn('Failed to load embedded PDF font', metadata.cleanName, err);
    }
}

function insertEmbeddedFontsIntoPicker(fonts) {
    if (!afbFont || !Array.isArray(fonts) || !fonts.length) return;
    afbFont.querySelectorAll('[data-pdfjs-embedded-font]').forEach((node) => node.remove());

    const header = document.createElement('option');
    header.disabled = true;
    header.textContent = '───── PDF Embedded Fonts ─────';
    header.dataset.pdfjsEmbeddedFont = 'header';
    afbFont.insertBefore(header, afbFont.firstChild);

    let insertionAnchor = header;
    fonts.forEach((metadata) => {
        const option = document.createElement('option');
        option.value = metadata.cleanName;
        option.textContent = metadata.cleanName;
        option.dataset.pdfjsEmbeddedFont = '1';
        option.dataset.fontSourceName = metadata.cleanName;
        option.dataset.pdfFontName = metadata.pdfFontName || '';
        option.dataset.fontFamily = metadata.family || metadata.cleanName;
        option.dataset.fontWeight = metadata.weight || '400';
        option.dataset.fontStyle = metadata.style || 'normal';
        option.style.fontFamily = 'system-ui, sans-serif';
        option.style.fontWeight = metadata.weight || '400';
        option.style.fontStyle = metadata.style || 'normal';
        afbFont.insertBefore(option, insertionAnchor.nextSibling);
        insertionAnchor = option;
    });
}

async function loadEmbeddedFontsForDocument() {
    if (embeddedFontsLoadPromise) return embeddedFontsLoadPromise;
    embeddedFontsLoadPromise = (async () => {
        if (!FONTS_URL || !afbFont) return [];
        try {
            const response = await fetch(FONTS_URL, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            const fonts = Array.isArray(payload?.fonts)
                ? payload.fonts
                : Object.values(payload?.embedded_fonts || {});
            const metadata = fonts
                .map(registerEmbeddedFontMetadata)
                .filter(Boolean);
            insertEmbeddedFontsIntoPicker(metadata);
            await Promise.all(metadata.map(loadEmbeddedFontFace));
            return metadata;
        } catch (err) {
            console.warn('Failed to load embedded PDF fonts', err);
            return [];
        }
    })();
    return embeddedFontsLoadPromise;
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
        || box.dataset.fontSourceName
        || existing?.fontFamily
        || existing?.fontSourceName
        || box.dataset.sourceFontFamily
        || 'Helvetica'
    ).trim();
}

function selectedBoxComputedStyle(box) {
    const tc = selectedBoxTextElement(box);
    return tc ? window.getComputedStyle(tc) : (box ? window.getComputedStyle(box) : null);
}

function selectedBoxFontSizePts(box) {
    if (!box) return 12;
    const layer = box.parentElement;
    const scale = Number.parseFloat(layer?.dataset?.scale || '1') || 1;
    const cs = selectedBoxComputedStyle(box);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const pts = Number.parseFloat(box.dataset.fontSizePts || '')
        || (Number.parseFloat(cs?.fontSize || '') / scale)
        || Number(existing?.fontSize)
        || Number(existing?.requestedFontSize)
        || 12;
    return Math.max(1, Math.min(300, Math.round(pts * 10) / 10));
}

function setFormatBarSizeLabel(fontSizePts) {
    if (afbSizeValue) afbSizeValue.textContent = `${Math.round(fontSizePts)}pt`;
}

function setToggleControl(control, active) {
    if (!control) return;
    control.setAttribute('aria-pressed', active ? 'true' : 'false');
    control.classList.toggle('is-active', Boolean(active));
}

function setFormatBarOpacityValue(opacity) {
    if (!afbOpacity) return;
    const normalized = normalizeOpacity(opacity, 1);
    const options = Array.from(afbOpacity.options || []);
    const closest = options.reduce((best, option) => {
        const optionOpacity = normalizeOpacity(option.value, 1);
        const distance = Math.abs(optionOpacity - normalized);
        return !best || distance < best.distance ? { option, distance } : best;
    }, null);
    if (closest?.option) afbOpacity.value = closest.option.value;
}

function setAnnotationBoxVerticalAlign(box, value) {
    if (!box) return;
    box.dataset.verticalAlign = normalizeVerticalAlign(value);
}

function updateAnnotationFormatBarForBox(box) {
    if (!annFormatBar || !box) return;
    const type = String(box?.dataset?.annotationType || 'text').toLowerCase();
    if (!box || type === 'signature' || type === 'image' || type === 'shape') {
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
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const cs = selectedBoxComputedStyle(box);
    const fontSizePts = selectedBoxFontSizePts(box);
    if (afbSize) afbSize.value = String(fontPtToSliderValue(fontSizePts));
    setFormatBarSizeLabel(fontSizePts);
    if (afbTextColor) {
        afbTextColor.value = cssColorToHex(
            box.style.getPropertyValue('--enpv-text-color')
            || existing?.textColor
            || existing?.color
            || cs?.color
            || '#000000',
        );
    }
    if (afbBgColor) {
        const bg = String(
            box.dataset.backgroundColor
            || box.style.getPropertyValue('--enpv-bg-color')
            || existing?.backgroundColor
            || '#ffffff',
        ).trim();
        afbBgColor.value = cssColorToHex(bg, '#ffffff');
    }
    setFormatBarOpacityValue(box.dataset.opacity || box.style.opacity || existing?.opacity || 1);
    const weight = String(box.style.getPropertyValue('--enpv-font-weight') || existing?.fontWeight || cs?.fontWeight || 'normal');
    const isBold = weight === '700' || weight.toLowerCase() === 'bold' || Number.parseInt(weight, 10) >= 600;
    setToggleControl(afbBold, isBold);
    const isItalic = String(box.style.getPropertyValue('--enpv-font-style') || existing?.fontStyle || cs?.fontStyle || 'normal').toLowerCase() === 'italic';
    setToggleControl(afbItalic, isItalic);
    const isUnderline = box.dataset.underline != null
        ? box.dataset.underline === '1'
        : boolish(existing?.underline);
    setToggleControl(afbUnderline, isUnderline);
    const inlineState = inlineSelectionStyleState(box);
    if (inlineState) {
        setToggleControl(afbBold, inlineState.bold);
        setToggleControl(afbItalic, inlineState.italic);
        setToggleControl(afbUnderline, inlineState.underline);
        if (afbTextColor && inlineState.color) afbTextColor.value = inlineState.color;
        if (inlineState.fontFamily) {
            const inlineFont = ensureFormatBarFontOption(inlineState.fontFamily);
            if (inlineFont && afbFont) afbFont.value = inlineFont;
        }
        if (inlineState.fontSizePts && afbSize) {
            afbSize.value = String(fontPtToSliderValue(inlineState.fontSizePts));
            setFormatBarSizeLabel(inlineState.fontSizePts);
        }
    }
    if (afbAlign) afbAlign.value = normalizeTextAlign(box.dataset.textAlign || box.style.getPropertyValue('--enpv-text-align') || existing?.textAlign || cs?.textAlign);
    if (afbValign) afbValign.value = normalizeVerticalAlign(box.dataset.verticalAlign || existing?.verticalAlign);
    const locked = isAnnBoxLocked(box);
    [afbFont, afbSize, afbTextColor, afbBgColor, afbOpacity, afbBold, afbItalic, afbUnderline, afbAlign, afbValign, afbCopy, afbDelete].forEach((control) => {
        if (control) control.disabled = locked;
    });
    annFormatBar.classList.add('is-visible');
}

function stripInlineStylePropertiesFromBox(box, properties) {
    const props = Array.isArray(properties)
        ? properties.map((prop) => String(prop || '').trim().toLowerCase()).filter(Boolean)
        : [];
    if (!props.length) return;
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    const propSet = new Set(props);
    const styledElements = [
        ...(tc.hasAttribute('style') ? [tc] : []),
        ...Array.from(tc.querySelectorAll('[style]')),
    ];
    styledElements.forEach((element) => {
        props.forEach((prop) => element.style.removeProperty(prop));
        if (!String(element.getAttribute('style') || '').trim()) {
            element.removeAttribute('style');
        }
    });
    if (propSet.has('font-family')) {
        tc.querySelectorAll('font[face]').forEach((element) => {
            const parent = element.parentNode;
            if (!parent) return;
            while (element.firstChild) parent.insertBefore(element.firstChild, element);
            parent.removeChild(element);
        });
    }
    if (propSet.has('color')) {
        tc.querySelectorAll('font[color]').forEach((element) => {
            element.removeAttribute('color');
            if (!element.attributes.length) unwrapElement(element);
        });
    }
    const stripFormat = (format) => {
        [tc, ...Array.from(tc.querySelectorAll('*'))].forEach((element) => {
            if (!element?.isConnected && element !== tc) return;
            removeFormatFromElement(element, format);
        });
    };
    if (propSet.has('font-weight')) stripFormat('bold');
    if (propSet.has('font-style')) stripFormat('italic');
    if (propSet.has('text-decoration') || propSet.has('text-decoration-line')) stripFormat('underline');
}

function applyStyleToSelectedBox(historyLabel, mutator, options = {}) {
    const box = findSelectedBox();
    if (!box || typeof mutator !== 'function') return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        updateAnnotationFormatBarForBox(box);
        return;
    }

    pushHistorySnapshot(historyLabel || 'change annotation style');
    if (options.promote !== false) {
        promoteBoxToRichTextMode(box, options.reason || historyLabel || 'style');
    }
    mutator(box);
    stripInlineStylePropertiesFromBox(box, options.stripInlineProps);
    box.dataset.styleDirty = '1';
    box.dataset.pendingEdit = '1';
    attachSourceMaskForBox(box);
    syncAnnotationBoxToPersistedAnnotations(box, { preserveEditMode: true });
    updateAnnotationFormatBarForBox(box);
    positionAnnMenuOver(box);
    markManualSaveNeeded();
}

function applyFontFamilyToSelectedBox(fontFamily) {
    const normalized = String(fontFamily || '').trim();
    if (!normalized) return;
    const embedded = embeddedFontOptionForValue(normalized);
    if (applyInlineStyleToSelectedText('change selected text font', (span, box) => {
        span.style.fontFamily = normalized;
        if (embedded && box) {
            box.dataset.fontSourceName = embedded.cleanName;
            box.dataset.forceEmbeddedFont = '1';
            box.dataset.fontWeight = embedded.weight || box.dataset.fontWeight || '400';
            box.dataset.fontStyle = embedded.style || box.dataset.fontStyle || 'normal';
        } else if (box) {
            delete box.dataset.fontSourceName;
            delete box.dataset.forceEmbeddedFont;
        }
    }, { reason: 'font-family' })) return;
    applyStyleToSelectedBox('change annotation font', (box) => {
        box.style.setProperty('--enpv-font-family', normalized);
        if (embedded) {
            box.dataset.fontSourceName = embedded.cleanName;
            box.dataset.forceEmbeddedFont = '1';
            box.style.setProperty('--enpv-font-weight', embedded.weight || '400');
            box.style.setProperty('--enpv-font-style', embedded.style || 'normal');
        } else {
            delete box.dataset.fontSourceName;
            delete box.dataset.forceEmbeddedFont;
        }
    }, { reason: 'font-family', stripInlineProps: ['font-family'] });
}

function applyFontSizeToSelectedBox(fontSizePts) {
    const pt = Number(fontSizePts);
    if (!Number.isFinite(pt) || pt <= 0) return;
    if (applyInlineStyleToSelectedText('change selected text size', (span, box) => {
        const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1;
        const normalized = Math.max(1, Math.min(300, Math.round(pt * 10) / 10));
        span.style.fontSize = `${normalized * scale}px`;
        span.style.lineHeight = `${normalized * scale * 1.2}px`;
    }, { reason: 'font-size', fitOptions: { allowShrink: true, fitWidth: false } })) return;
    applyStyleToSelectedBox('change annotation size', (box) => {
        const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1;
        const normalized = Math.max(1, Math.min(300, Math.round(pt * 10) / 10));
        box.dataset.fontSizePts = String(normalized);
        box.style.setProperty('--enpv-font-size', `${normalized * scale}px`);
        box.style.setProperty('--enpv-line-height', `${normalized * scale * 1.2}px`);
    }, { reason: 'font-size', stripInlineProps: ['font-size', 'line-height'], fitOptions: { allowShrink: true, fitWidth: true } });
}

function applyTextColorToSelectedBox(color) {
    const normalized = cssColorToHex(color, '#000000');
    const box = findSelectedBox();
    if (box && selectedInlineTextRange(box)) {
        const inlineState = inlineSelectionStyleState(box);
        if (inlineState?.color && cssColorToHex(inlineState.color, '') === normalized) return;
    }
    if (applyInlineStyleToSelectedText('change selected text color', (span) => {
        span.style.color = normalized;
    }, { reason: 'text-color', fit: false })) return;
    applyStyleToSelectedBox('change annotation color', (box) => {
        box.style.setProperty('--enpv-text-color', normalized);
    }, { reason: 'text-color', fit: false, stripInlineProps: ['color'] });
}

function applyBackgroundColorToSelectedBox(color) {
    const normalized = cssColorToHex(color, '#ffffff');
    applyStyleToSelectedBox('change annotation background', (box) => {
        box.dataset.backgroundColor = normalized;
        box.style.setProperty('--enpv-bg-color', normalized);
        box.classList.add('has-custom-bg');
    }, { reason: 'background-color', fit: false });
}

function previewTextColorOnSelectedBox(color) {
    const box = findSelectedBox();
    if (!box || isAnnBoxLocked(box)) return;
    if (selectedInlineTextRange(box)) return;
    const normalized = cssColorToHex(color, '#000000');
    if (box.dataset.editorMode !== 'rich') {
        promoteBoxToRichTextMode(box, 'text-color');
    }
    box.style.setProperty('--enpv-text-color', normalized);
    box.dataset.styleDirty = '1';
    box.dataset.pendingEdit = '1';
}

function previewBackgroundColorOnSelectedBox(color) {
    const box = findSelectedBox();
    if (!box || isAnnBoxLocked(box)) return;
    const normalized = cssColorToHex(color, '#ffffff');
    if (box.dataset.editorMode !== 'rich') {
        promoteBoxToRichTextMode(box, 'background-color');
    }
    box.dataset.backgroundColor = normalized;
    box.style.setProperty('--enpv-bg-color', normalized);
    box.classList.add('has-custom-bg');
    box.dataset.styleDirty = '1';
    box.dataset.pendingEdit = '1';
}

function applyOpacityToSelectedBox(opacity) {
    const normalized = normalizeOpacity(opacity, 1);
    applyStyleToSelectedBox('change annotation opacity', (box) => {
        box.dataset.opacity = String(normalized);
        box.style.opacity = String(normalized);
        box.style.setProperty('--enpv-opacity', String(normalized));
    }, { reason: 'opacity', promote: false, fit: false });
}

function applyBoldToSelectedBox(active) {
    if (applyInlineStyleToSelectedText('change selected text bold', (span) => {
        span.style.fontWeight = active ? '700' : '400';
    }, { reason: 'font-weight', format: 'bold', active })) return;
    applyStyleToSelectedBox('change annotation bold', (box) => {
        box.style.setProperty('--enpv-font-weight', active ? '700' : '400');
    }, { reason: 'font-weight', stripInlineProps: ['font-weight'] });
}

function applyItalicToSelectedBox(active) {
    if (applyInlineStyleToSelectedText('change selected text italic', (span) => {
        span.style.fontStyle = active ? 'italic' : 'normal';
    }, { reason: 'font-style', format: 'italic', active })) return;
    applyStyleToSelectedBox('change annotation italic', (box) => {
        box.style.setProperty('--enpv-font-style', active ? 'italic' : 'normal');
    }, { reason: 'font-style', stripInlineProps: ['font-style'] });
}

function applyUnderlineToSelectedBox(active) {
    if (applyInlineStyleToSelectedText('change selected text underline', (span) => {
        span.style.textDecorationLine = active ? 'underline' : 'none';
        span.style.textDecoration = active ? 'underline' : 'none';
    }, { reason: 'underline', fit: false, format: 'underline', active })) return;
    applyStyleToSelectedBox('change annotation underline', (box) => {
        box.dataset.underline = active ? '1' : '0';
        box.style.setProperty('--enpv-text-decoration-line', active ? 'underline' : 'none');
        box.style.setProperty('--enpv-text-decoration', active ? 'underline' : 'none');
    }, { reason: 'underline', fit: false, stripInlineProps: ['text-decoration', 'text-decoration-line'] });
}

function applyTextAlignToSelectedBox(align) {
    const normalized = normalizeTextAlign(align);
    applyStyleToSelectedBox('change annotation alignment', (box) => {
        box.dataset.textAlign = normalized;
        box.style.setProperty('--enpv-text-align', normalized);
    }, { reason: 'text-align', fit: false });
}

function applyVerticalAlignToSelectedBox(align) {
    const normalized = normalizeVerticalAlign(align);
    applyStyleToSelectedBox('change annotation vertical alignment', (box) => {
        setAnnotationBoxVerticalAlign(box, normalized);
    }, { reason: 'vertical-align', fit: false });
}

function setAnnBoxLocked(box, locked) {
    if (!box) return;
    const nextLocked = Boolean(locked);
    if (isAnnBoxLocked(box) === nextLocked && box.dataset.locked === (nextLocked ? '1' : '0')) {
        refreshAnnMenuState(box);
        updateAnnotationFormatBarForBox(box);
        return;
    }
    pushHistorySnapshot('lock annotation');
    box.dataset.locked = nextLocked ? '1' : '0';
    box.classList.toggle('is-locked', nextLocked);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const annotation = buildAnnotationFromBox(box, existing);
    if (annotation) upsertPersistedAnnotation(annotation);
    box.dataset.pendingEdit = '1';
    refreshAnnMenuState(box);
    updateAnnotationFormatBarForBox(box);
    markManualSaveNeeded();
}

function refreshAnnMenuState(box) {
    if (!annMenu || !box) return;
    const locked = isAnnBoxLocked(box);
    const annotationType = String(box.dataset.annotationType || '').toLowerCase();
    const isImageMenu = annotationType === 'image';
    const isShapeMenu = annotationType === 'shape';
    const compactMenuActions = isShapeMenu
        ? new Set(['move', 'cut', 'lock', 'delete'])
        : new Set(['lock', 'delete']);
    annMenu.classList.toggle('is-image-menu', isImageMenu);
    annMenu.classList.toggle('is-shape-menu', isShapeMenu);
    annMenu.querySelectorAll('[data-action]').forEach((button) => {
        const action = String(button.dataset.action || '');
        const visible = action === 'cut'
            ? (isShapeMenu && canCutShapeBox(box))
            : ((!isImageMenu && !isShapeMenu) || compactMenuActions.has(action));
        button.hidden = !visible;
        button.style.display = visible ? '' : 'none';
        button.setAttribute('aria-hidden', visible ? 'false' : 'true');
    });
    annMenu.querySelectorAll('.enpv-ann-menu-divider').forEach((divider) => {
        divider.hidden = isImageMenu || isShapeMenu;
        divider.style.display = (isImageMenu || isShapeMenu) ? 'none' : '';
        divider.setAttribute('aria-hidden', 'true');
    });
    const lockButton = annMenu.querySelector('[data-action="lock"]');
    if (lockButton) {
        const iconState = locked ? 'locked' : 'unlocked';
        if (lockButton.dataset.lockIconState !== iconState) {
            lockButton.innerHTML = locked ? ANN_MENU_LOCKED_ICON_SVG : ANN_MENU_UNLOCKED_ICON_SVG;
            lockButton.dataset.lockIconState = iconState;
        }
        lockButton.classList.toggle('is-active', locked);
        lockButton.dataset.locked = locked ? '1' : '0';
        lockButton.setAttribute('aria-pressed', locked ? 'true' : 'false');
        lockButton.title = locked ? 'Unlock annotation' : 'Lock annotation';
        lockButton.setAttribute('aria-label', locked ? 'Unlock annotation' : 'Lock annotation');
    }
    const cutButton = annMenu.querySelector('[data-action="cut"]');
    if (cutButton) {
        const active = isShapeCutModeArmedForBox(box);
        cutButton.classList.toggle('is-active', active);
        cutButton.setAttribute('aria-pressed', active ? 'true' : 'false');
        cutButton.title = active ? 'Cancel cut mode' : 'Cut shape';
        cutButton.setAttribute('aria-label', active ? 'Cancel cut mode' : 'Cut shape');
    }
}

function selectedBoxTextElement(box) {
    return box?.querySelector?.('.enpv-text-content') || null;
}

let savedInlineTextSelection = null;

function rangeIsInsideTextElement(range, textElement) {
    return Boolean(range && textElement
        && textElement.contains(range.startContainer)
        && textElement.contains(range.endContainer));
}

function childTextLengthBeforeOffset(element, offset) {
    return Array.from(element?.childNodes || [])
        .slice(0, Math.max(0, offset))
        .reduce((sum, child) => sum + String(child.textContent || '').length, 0);
}

function textOffsetForRangePoint(root, node, offset) {
    if (!root || !node || !root.contains(node)) return 0;
    try {
        const range = document.createRange();
        range.setStart(root, 0);
        range.setEnd(node, offset);
        return String(range.toString() || '').length;
    } catch (_) { /* fall through to manual fallback */ }
    if (node === root) return childTextLengthBeforeOffset(root, offset);
    let total = 0;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    while (current) {
        if (current === node) return total + Math.max(0, offset);
        total += String(current.nodeValue || '').length;
        current = walker.nextNode();
    }
    return total;
}

function rangePointForTextOffset(root, offset) {
    const target = Math.max(0, Number(offset) || 0);
    let total = 0;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    while (current) {
        const length = String(current.nodeValue || '').length;
        if (target <= total + length) {
            return { node: current, offset: Math.max(0, Math.min(length, target - total)) };
        }
        total += length;
        current = walker.nextNode();
    }
    return { node: root, offset: root.childNodes.length };
}

function textOffsetRangeForDomRange(root, range) {
    if (!root || !range || !rangeIsInsideTextElement(range, root)) return null;
    const start = textOffsetForRangePoint(root, range.startContainer, range.startOffset);
    const end = textOffsetForRangePoint(root, range.endContainer, range.endOffset);
    return {
        start: Math.min(start, end),
        end: Math.max(start, end),
    };
}

function domRangeFromTextOffsets(root, offsets) {
    if (!root || !offsets || offsets.end <= offsets.start) return null;
    const start = rangePointForTextOffset(root, offsets.start);
    const end = rangePointForTextOffset(root, offsets.end);
    const range = document.createRange();
    range.setStart(start.node, start.offset);
    range.setEnd(end.node, end.offset);
    return range.collapsed ? null : range;
}

function elementForStyleNode(node, root) {
    if (!node || !root) return null;
    const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    return element && root.contains(element) ? element : root;
}

function isBoldElement(element) {
    if (!element) return false;
    if (element.tagName === 'B' || element.tagName === 'STRONG') return true;
    const value = String(element.style?.fontWeight || window.getComputedStyle(element).fontWeight || '').toLowerCase();
    return value === 'bold' || Number.parseInt(value, 10) >= 600;
}

function isItalicElement(element) {
    if (!element) return false;
    if (element.tagName === 'I' || element.tagName === 'EM') return true;
    const value = String(element.style?.fontStyle || window.getComputedStyle(element).fontStyle || '').toLowerCase();
    return value === 'italic' || value === 'oblique';
}

function isUnderlineElement(element) {
    if (!element) return false;
    if (element.tagName === 'U') return true;
    const inline = `${element.style?.textDecoration || ''} ${element.style?.textDecorationLine || ''}`.toLowerCase();
    if (inline.includes('underline')) return true;
    return String(window.getComputedStyle(element).textDecorationLine || '').toLowerCase().includes('underline');
}

function closestFormatElement(node, root, format) {
    let element = elementForStyleNode(node, root);
    const predicate = format === 'bold'
        ? isBoldElement
        : (format === 'italic' ? isItalicElement : isUnderlineElement);
    while (element && element !== root) {
        if (predicate(element)) return element;
        element = element.parentElement;
    }
    return null;
}

function inlineSelectionStyleState(box) {
    const tc = selectedBoxTextElement(box);
    if (!box || !tc || !box.classList.contains('is-editing')) return null;
    const range = selectedInlineTextRange(box);
    if (!range) return null;
    const probeNode = range.startContainer;
    const element = elementForStyleNode(probeNode, tc);
    if (!element) return null;
    const cs = window.getComputedStyle(element);
    const weight = String(cs.fontWeight || element.style?.fontWeight || '').toLowerCase();
    const style = String(cs.fontStyle || element.style?.fontStyle || '').toLowerCase();
    const decoration = String(cs.textDecorationLine || element.style?.textDecorationLine || element.style?.textDecoration || '').toLowerCase();
    return {
        bold: closestFormatElement(probeNode, tc, 'bold') != null || weight === 'bold' || Number.parseInt(weight, 10) >= 600,
        italic: closestFormatElement(probeNode, tc, 'italic') != null || style === 'italic' || style === 'oblique',
        underline: closestFormatElement(probeNode, tc, 'underline') != null || decoration.includes('underline'),
        color: cssColorToHex(cs.color || '', ''),
        fontFamily: parseCssFontFamily(cs.fontFamily || element.style?.fontFamily || ''),
        fontSizePts: (() => {
            const scale = Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1;
            const px = Number.parseFloat(cs.fontSize || '');
            return Number.isFinite(px) && px > 0 ? Math.round((px / scale) * 10) / 10 : null;
        })(),
    };
}

function removeEmptyStyleAttribute(element) {
    if (!element?.getAttribute) return;
    if (!String(element.getAttribute('style') || '').trim()) element.removeAttribute('style');
}

function unwrapElement(element) {
    if (!element?.parentNode) return null;
    const parent = element.parentNode;
    const marker = document.createTextNode('');
    parent.insertBefore(marker, element);
    while (element.firstChild) parent.insertBefore(element.firstChild, element);
    element.remove();
    return marker;
}

function removeFormatFromElement(element, format) {
    if (!element) return null;
    if (format === 'bold') {
        element.style.fontWeight = '';
        if (element.tagName === 'B' || element.tagName === 'STRONG') return unwrapElement(element);
    } else if (format === 'italic') {
        element.style.fontStyle = '';
        if (element.tagName === 'I' || element.tagName === 'EM') return unwrapElement(element);
    } else if (format === 'underline') {
        element.style.textDecoration = '';
        element.style.textDecorationLine = '';
        if (element.tagName === 'U') return unwrapElement(element);
    }
    removeEmptyStyleAttribute(element);
    return element;
}

function stripFormatFromFragment(fragment, format) {
    if (!fragment) return;
    const elements = Array.from(fragment.querySelectorAll?.('*') || []);
    elements.forEach((element) => removeFormatFromElement(element, format));
}

function selectedRangeCoversElement(range, element) {
    if (!range || !element) return false;
    const selected = String(range.toString() || '').replace(/\s+/g, ' ').trim();
    const elementText = String(element.textContent || '').replace(/\s+/g, ' ').trim();
    return Boolean(selected && elementText && selected === elementText);
}

function clearSavedInlineTextSelectionForBox(box) {
    if (!box) return;
    if (!savedInlineTextSelection || savedInlineTextSelection.boxUid !== (box.dataset.uid || '')) return;
    savedInlineTextSelection = null;
}

function captureInlineTextSelection(options = {}) {
    const box = findSelectedBox();
    const tc = selectedBoxTextElement(box);
    const sel = window.getSelection();
    if (!box || !tc || !box.classList.contains('is-editing') || !sel?.rangeCount) return null;
    if (!tc.contains(sel.anchorNode) || !tc.contains(sel.focusNode)) return null;
    const range = sel.getRangeAt(0);
    if (!rangeIsInsideTextElement(range, tc)) return null;
    if (range.collapsed) {
        if (options.clearCollapsed !== false) clearSavedInlineTextSelectionForBox(box);
        return null;
    }
    savedInlineTextSelection = {
        boxUid: box.dataset.uid || '',
        range: range.cloneRange(),
        offsets: textOffsetRangeForDomRange(tc, range),
    };
    return savedInlineTextSelection.range.cloneRange();
}

function selectedInlineTextRange(box) {
    const tc = selectedBoxTextElement(box);
    if (!box || !tc || !box.classList.contains('is-editing')) return null;
    const sel = window.getSelection();
    if (sel?.rangeCount && tc.contains(sel.anchorNode) && tc.contains(sel.focusNode)) {
        const range = sel.getRangeAt(0);
        if (!range.collapsed && rangeIsInsideTextElement(range, tc)) {
            savedInlineTextSelection = {
                boxUid: box.dataset.uid || '',
                range: range.cloneRange(),
                offsets: textOffsetRangeForDomRange(tc, range),
            };
            return range.cloneRange();
        }
    }
    const saved = savedInlineTextSelection;
    if (!saved || saved.boxUid !== (box.dataset.uid || '')) return null;
    if (saved.range && !saved.range.collapsed && rangeIsInsideTextElement(saved.range, tc)) {
        return saved.range.cloneRange();
    }
    return domRangeFromTextOffsets(tc, saved.offsets);
}

function restoreInlineTextRange(range) {
    if (!range) return;
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
}

function finishInlineTextStyleMutation(box, range, options = {}) {
    const tc = selectedBoxTextElement(box);
    if (!tc) return;
    box.dataset.styleDirty = '1';
    box.dataset.pendingEdit = '1';
    if (box.dataset.sourceSpanEditActive === '1') delete box.dataset.sourceSpanEditActive;
    syncRichTextBoxTypographyFromContent(box);
    attachSourceMaskForBox(box);
    syncAnnotationBoxToPersistedAnnotations(box, { preserveEditMode: true });
    updateAnnotationFormatBarForBox(box);
    positionAnnMenuOver(box);
    markManualSaveNeeded();
    restoreInlineTextRange(range);
    savedInlineTextSelection = {
        boxUid: box.dataset.uid || '',
        range: range.cloneRange(),
        offsets: textOffsetRangeForDomRange(tc, range),
    };
}

function applyInlineStyleToSelectedText(historyLabel, mutator, options = {}) {
    const box = findSelectedBox();
    if (!box || typeof mutator !== 'function') return false;
    if (isAnnBoxLocked(box)) return false;
    const tc = selectedBoxTextElement(box);
    const selectedRange = selectedInlineTextRange(box);
    if (!tc || !selectedRange) return false;

    pushHistorySnapshot(historyLabel || 'change selected text style');
    if (options.promote !== false) {
        promoteBoxToRichTextMode(box, options.reason || historyLabel || 'inline-style');
    }
    restoreInlineTextRange(selectedRange);
    const range = window.getSelection()?.rangeCount ? window.getSelection().getRangeAt(0) : selectedRange;
    if (!range || range.collapsed || !rangeIsInsideTextElement(range, tc)) return false;
    const format = String(options.format || '');
    const active = options.active !== false;
    const formattedAncestor = !active && format
        ? closestFormatElement(range.startContainer, tc, format)
        : null;
    if (!active && formattedAncestor && formattedAncestor.contains(range.endContainer)
        && selectedRangeCoversElement(range, formattedAncestor)) {
        const replacement = removeFormatFromElement(formattedAncestor, format);
        const nextRange = document.createRange();
        if (replacement?.nodeType === Node.TEXT_NODE) {
            nextRange.setStartAfter(replacement);
            nextRange.setEndAfter(replacement);
            replacement.remove();
            nextRange.selectNodeContents(tc);
            nextRange.collapse(false);
        } else if (replacement) {
            nextRange.selectNodeContents(replacement);
        } else {
            nextRange.selectNodeContents(tc);
            nextRange.collapse(false);
        }
        finishInlineTextStyleMutation(box, nextRange, options);
        return true;
    }
    const wrapper = document.createElement('span');
    mutator(wrapper, box);
    const fragment = range.extractContents();
    if (!active && format) stripFormatFromFragment(fragment, format);
    wrapper.appendChild(fragment);
    range.insertNode(wrapper);
    range.selectNodeContents(wrapper);
    finishInlineTextStyleMutation(box, range, options);
    return true;
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
    const current = textContentForBox(box);
    const next = transformer(current);
    if (next === current) return;
    pushHistorySnapshot('transform annotation text');
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const preserveLineBreaks = box.dataset.editorMode === 'rich'
        || box.dataset.userForcedRichText === '1'
        || box.dataset.userCreated === '1'
        || box.dataset.userAuthored === '1'
        || annotationPreservesDisplayLineBreaks(existing);
    tc.textContent = normalizeAnnotationTextForDisplay(next, { preserveLineBreaks });
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

function removeUserCreatedTextBox(box, options = {}) {
    if (!box) return;
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (options.skipHistory !== true) pushHistorySnapshot('remove empty text annotation');
    if (box.dataset.annotationId) deletePersistedAnnotation(box.dataset.annotationId);
    if (box.dataset.uid) annotationOffsetsPts.delete(String(box.dataset.uid));
    removeAnnBoxSourceMasks(box);
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    selectedAnnBoxUid = null;
    selectedAnnBoxIsEditing = false;
    box.remove();
    if (Number.isFinite(pageIndex) && pageIndex >= 0) renderAnnotationBoxLayer(pageIndex);
    if (options.markDirty !== false) markManualSaveNeeded();
}

let annotationClipboardSnapshot = null;

function activeElementAcceptsNativeClipboard() {
    const active = document.activeElement;
    return Boolean(active?.isContentEditable
        || active?.closest?.('[contenteditable="true"]')
        || active?.tagName === 'INPUT'
        || active?.tagName === 'TEXTAREA');
}

function windowHasSelectedText() {
    const selection = window.getSelection?.();
    return Boolean(selection && !selection.isCollapsed && String(selection.toString() || '').length > 0);
}

function snapshotAnnotationBoxForClipboard(box) {
    if (!box || isAnnBoxLocked(box)) return null;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const annotation = buildAnnotationFromBox(box, existing) || existing;
    if (!annotation) return null;
    const annotationType = String(box.dataset.annotationType || annotation.type || 'text').toLowerCase();
    if (annotationType !== 'text' && annotationType !== 'shape') return null;
    return {
        annotation: cloneForHistory(annotation),
        annotationType,
    };
}

function copySelectedAnnotationToClipboard() {
    const box = findSelectedBox();
    const snapshot = snapshotAnnotationBoxForClipboard(box);
    if (!snapshot) return false;
    annotationClipboardSnapshot = snapshot;
    setStatus('Annotation copied.');
    return true;
}

function annotationClipboardTargetPageIndex(snapshot) {
    const selected = findSelectedBox();
    const selectedPage = Number.parseInt(selected?.dataset?.pageIndex || '', 10);
    if (Number.isFinite(selectedPage) && selectedPage >= 0) return selectedPage;
    const snapshotPage = Number.parseInt(snapshot?.annotation?.pageIndex ?? '', 10);
    if (Number.isFinite(snapshotPage) && snapshotPage >= 0) return snapshotPage;
    return 0;
}

function pageSizePtsForIndex(pageIndex) {
    const pageView = pdfViewer.getPageView(pageIndex);
    const viewport = pageView?.viewport || null;
    const scale = Number(viewport?.scale) || Number(pdfViewer.currentScale) || 1;
    if (!viewport || !(scale > 0)) return null;
    return {
        width: Number(viewport.width) / scale,
        height: Number(viewport.height) / scale,
        scale,
    };
}

function pasteAnnotationClipboard() {
    const snapshot = annotationClipboardSnapshot;
    if (!snapshot?.annotation) return false;
    const source = cloneForHistory(snapshot.annotation);
    const pageIndex = annotationClipboardTargetPageIndex(snapshot);
    const pageSize = pageSizePtsForIndex(pageIndex);
    if (!pageSize) return false;

    const width = Math.max(1, Number(source.pdfWidth) || Number(source.pdfjsSourceW) || 60);
    const height = Math.max(1, Number(source.pdfHeight) || Number(source.pdfjsSourceH) || 18);
    const offsetPts = 12 / Math.max(pageSize.scale, 0.0001);
    const rawX = (Number(source.pdfX) || 0) + offsetPts;
    const rawY = (Number(source.pdfY) || 0) - offsetPts;
    const pdfX = Math.max(0, Math.min(rawX, Math.max(0, pageSize.width - width)));
    const pdfY = Math.max(0, Math.min(rawY, Math.max(0, pageSize.height - height)));
    const uidPrefix = snapshot.annotationType === 'shape' ? 'shape' : 'new';
    const uid = `${uidPrefix}_${generateUuidV4()}`;
    const id = buildPdfjsAnnotationId(pageIndex, uid);
    const next = {
        ...source,
        id,
        _uid: uid,
        pageIndex,
        pdfX,
        pdfY,
        pdfWidth: width,
        pdfHeight: height,
        locked: false,
        zIndex: (Number(source.zIndex) || 2) + 1,
        userCreated: true,
        userAuthored: true,
        savedTextOverlay: true,
        styleDirty: true,
        userForcedRichText: true,
        pdfjsEditorMode: 'rich',
        skipPdfjsSourceMask: true,
        pdfjsAnchorUid: uid,
        _originalBox: { x: pdfX, y: pdfY, w: width, h: height },
        _originalPdfBox: { x: pdfX, y: pdfY, w: width, h: height },
        _currentPdfBox: { x: pdfX, y: pdfY, w: width, h: height },
        _baselinePdfBox: { x: pdfX, y: pdfY, w: width, h: height },
    };
    if (snapshot.annotationType === 'shape') {
        next.type = 'shape';
        delete next.savedTextOverlay;
        delete next.userForcedRichText;
        delete next.pdfjsEditorMode;
        delete next.richTextHtml;
        delete next.pdfjsSourceText;
    } else {
        next.type = 'text';
    }

    pushHistorySnapshot('paste annotation');
    const normalized = snapshot.annotationType === 'shape'
        ? normalizeShapeAnnotation(next)
        : next;
    upsertPersistedAnnotation(normalized);
    renderAnnotationBoxLayer(pageIndex);
    const copyBox = pdfViewer.getPageView(pageIndex)?.div
        ?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(normalized.id)}"]`) || null;
    if (copyBox) selectAnnBox(copyBox);
    annotationClipboardSnapshot = {
        annotation: cloneForHistory(normalized),
        annotationType: snapshot.annotationType,
    };
    markManualSaveNeeded();
    return true;
}

function deleteAnnBox(box, options = {}) {
    if (!box) return;
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        return;
    }
    if (options.skipHistory !== true) pushHistorySnapshot('delete annotation');
    rememberDeletedAnnotation(box);
    const deletionMaskAnnotation = deletedMaskAnnotationFromBox(box);
    if (deletionMaskAnnotation) upsertPersistedAnnotation(deletionMaskAnnotation);
    if (box.dataset.annotationId) deletePersistedAnnotation(box.dataset.annotationId);
    if (box.dataset.uid) annotationOffsetsPts.delete(String(box.dataset.uid));
    if (box._enpvOrigMask) {
        box._enpvOrigMask.remove();
        box._enpvOrigMask = null;
    }
    removeAnnBoxSourceMasks(box);
    if (annMenu) annMenu.hidden = true;
    hideAnnotationFormatBar();
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    if (box.dataset.uid) multiSelectedAnnBoxUids.delete(String(box.dataset.uid));
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
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (isShapeBox(box, existing)) {
        const source = buildAnnotationFromBox(box, existing) || existing;
        if (!source) return;
        pushHistorySnapshot('copy shape');
        const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
        const offset = 12 / scale;
        const pageIndex = Number.parseInt(box.dataset.pageIndex || '0', 10) || 0;
        const uid = `shape_${generateUuidV4()}`;
        const ann = normalizeShapeAnnotation({
            ...source,
            id: buildPdfjsAnnotationId(pageIndex, uid),
            _uid: uid,
            pageIndex,
            pdfX: Number(source.pdfX) + offset,
            pdfY: Math.max(0, Number(source.pdfY) - offset),
            userCreated: true,
            locked: false,
            zIndex: (Number(source.zIndex) || 2) + 1,
        });
        upsertPersistedAnnotation(ann);
        renderAnnotationBoxLayer(pageIndex);
        const copyBox = pdfViewer.getPageView(pageIndex)?.div
            ?.querySelector(`.enpv-annotation-box[data-annotation-id="${cssEscape(ann.id)}"]`) || null;
        if (copyBox) selectAnnBox(copyBox);
        markManualSaveNeeded();
        return;
    }
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
    copy.dataset.styleDirty = '1';
    copy.dataset.userForcedRichText = '1';
    copy.dataset.backgroundColor = box.dataset.backgroundColor || 'transparent';
    copy.dataset.opacity = box.dataset.opacity || box.style.opacity || '1';
    copy.dataset.underline = box.dataset.underline || '0';
    copy.dataset.textAlign = box.dataset.textAlign || 'left';
    copy.dataset.verticalAlign = box.dataset.verticalAlign || 'top';
    const left = (Number.parseFloat(box.style.left || '0') || 0) + 12;
    const top = (Number.parseFloat(box.style.top || '0') || 0) + 12;
    const width = Number.parseFloat(box.style.width || '') || box.offsetWidth || 1;
    const height = Number.parseFloat(box.style.height || '') || box.offsetHeight || 1;
    copy.style.left = `${left}px`;
    copy.style.top = `${top}px`;
    copy.style.width = `${Math.max(1, width)}px`;
    copy.style.height = `${Math.max(1, height)}px`;
    copy.style.zIndex = copy.dataset.zIndex;
    ['--enpv-font-family', '--enpv-font-size', '--enpv-font-weight', '--enpv-font-style', '--enpv-line-height', '--enpv-text-color', '--enpv-bg-color', '--enpv-text-align', '--enpv-text-decoration-line', '--enpv-text-decoration', '--enpv-opacity']
        .forEach((name) => {
            const value = box.style.getPropertyValue(name);
            if (value) copy.style.setProperty(name, value);
        });
    copy.style.opacity = box.style.opacity || copy.dataset.opacity;
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
    const sourceTextElement = selectedBoxTextElement(box);
    const sourceRichHtml = sanitizeRichTextHtmlForAnnotation(sourceTextElement?.innerHTML || '');
    if (sourceRichHtml) tc.innerHTML = sourceRichHtml;
    else tc.textContent = normalizeAnnotationTextForDisplay(sourceTextElement?.textContent || '');
    copy.appendChild(tc);
    setBoxEditorMode(copy, 'rich');
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
    const height = parseFloat(box.style.height) || box.offsetHeight || 0;
    // Account for any in-flight transform translate (during/after drag).
    const dxPts = parseFloat(box.dataset.dxPts || '0') || 0;
    const dyPts = parseFloat(box.dataset.dyPts || '0') || 0;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    const dxPx = dxPts * scale;
    const dyPx = dyPts * scale;
    annMenu.hidden = false;
    refreshAnnMenuState(box);
    const menuHeight = Math.max(annMenu.offsetHeight || 38, 32);
    const menuWidth = Math.max(annMenu.offsetWidth || 1, 1);
    const layerWidth = layer.clientWidth || pdfViewer.getPageView(Number.parseInt(box.dataset.pageIndex || '-1', 10))?.div?.clientWidth || 0;
    const layerHeight = layer.clientHeight || pdfViewer.getPageView(Number.parseInt(box.dataset.pageIndex || '-1', 10))?.div?.clientHeight || 0;
    const gap = 4;
    const rawLeft = left + dxPx + (width / 2);
    const halfWidth = menuWidth / 2;
    const finalLeft = layerWidth > menuWidth
        ? Math.max(halfWidth + 4, Math.min(layerWidth - halfWidth - 4, rawLeft))
        : rawLeft;
    const menuTop = top + dyPx - menuHeight - gap;
    const menuBelow = menuTop < gap;
    const belowTop = top + dyPx + height + gap;
    const rawTop = menuBelow ? belowTop : menuTop;
    const finalTop = (menuBelow && layerHeight > menuHeight)
        ? Math.min(rawTop, Math.max(gap, layerHeight - menuHeight - gap))
        : rawTop;
    annMenu.style.left = `${finalLeft}px`;
    annMenu.style.top = `${finalTop}px`;
    annMenu.classList.toggle('is-below', menuBelow);
    annMenu.classList.toggle('is-top-gutter', false);
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
    clearMultiSelection();
    deselectAnnBox();
    selectedAnnBoxUid = box.dataset.uid || null;
    box.classList.add('is-selected');
    positionAnnMenuOver(box);
    updateAnnotationFormatBarForBox(box);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (isShapeBox(box, existing)) {
        const annotation = buildAnnotationFromBox(box, existing) || existing;
        if (annotation) reflectShapeStateToInputs(annotation, shapeInspectorRefs);
        if (shapeToolPanel) shapeToolPanel.classList.add('is-visible');
    }
}

function onTextContentInput(ev) {
    const tc = ev.currentTarget;
    const box = tc.closest('.enpv-annotation-box');
    if (box) {
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        if (isUserCreatedTextBox(box, existing)) {
            removeAnnBoxSourceMasks(box);
            if (fitUserCreatedTextBoxToContent(box)) {
                positionAnnMenuOver(box);
            }
        } else {
            attachSourceMaskForBox(box);
        }
        if (editorModeForBox(box) === 'rich') {
            setBoxEditorMode(box, 'rich');
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
    if (box.dataset.annotationType === 'shape') return;
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
    if (box.dataset.annotationType === 'image') return;
    const preEditHeightPx = box.getBoundingClientRect().height
        || Number.parseFloat(box.style.height || '')
        || box.offsetHeight
        || 1;
    box.classList.add('is-editing');
    selectedAnnBoxIsEditing = true;
    attachSourceMaskForBox(box);
    box.dataset.preEditHeight = `${preEditHeightPx}px`;
    box.style.minHeight = '';
    box.style.height = `${preEditHeightPx}px`;

    const tc = box.querySelector('.enpv-text-content');
    if (tc) {
        tc.contentEditable = 'true';
        tc.dataset.preEdit = tc.textContent || '';
        tc.addEventListener('beforeinput', onTextContentBeforeInput);
        tc.addEventListener('paste', onTextContentPaste);
        tc.addEventListener('input', onTextContentInput);
        tc.addEventListener('blur', onTextContentBlur);
        tc.addEventListener('mouseup', captureInlineTextSelection);
        tc.addEventListener('keyup', captureInlineTextSelection);
        const originalText = String(tc.textContent || '');
        const hasNewline = /\r|\n/.test(originalText);
        const hasSourceMetrics = Boolean(box.dataset.sourceFontFamily || box.dataset.sourceTransform || box.dataset.sourceFontSizePx);
        const mode = editorModeForBox(box);
        setBoxEditorMode(box, mode);
        if (mode === 'source' && hasSourceMetrics && !hasNewline) {
            applySourceFidelityTypography(box, { editing: true });
            // Per-span edit markup: preserve mixed font-weights and visible
            // inter-span gaps from the underlying pdf.js text layer so the
            // user sees the same typography they're used to in the PDF.
            applySourceFidelitySpanEditMarkup(box);
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
        tc.removeEventListener('blur', onTextContentBlur);
        tc.removeEventListener('mouseup', captureInlineTextSelection);
        tc.removeEventListener('keyup', captureInlineTextSelection);
        tc.contentEditable = 'false';
    }
    const isRichTextCommit = box.dataset.editorMode === 'rich' || box.dataset.userForcedRichText === '1';
    if (isRichTextCommit) {
        // The temporary source-span marker can outlive promotion to rich text.
        // Do not flatten here: selected inline formatting, such as bolding
        // only "4a", is represented by authored spans in the same DOM.
        clearSourceFidelitySpanState(box);
    } else {
        // Strip per-span edit markup before commit so the persisted text and
        // post-edit source-fidelity render path see plain text only.
        clearSourceFidelitySpanEditMarkup(box);
    }
    // Re-apply per-span display markup so post-edit rendering keeps the
    // mixed font-weights captured from the original PDF text layer.
    if (!isRichTextCommit && box.classList.contains('is-source-fidelity')) {
        applySourceFidelitySpanEditMarkup(box, { purpose: 'display' });
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
    const hasPendingSourceChange = box.dataset.pendingEdit === '1'
        || box.dataset.pendingResize === '1'
        || box.dataset.styleDirty === '1'
        || box.dataset.userForcedRichText === '1'
        || box.classList.contains('is-persisted-overlay');
    if (!hasPendingSourceChange) {
        clearTransientSourceFidelity(box);
    }
}

function commitUserCreatedTextBoxAndKeepSelected(box) {
    if (!box) return false;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (!isUserCreatedTextBox(box, existing)) return false;
    if (!userCreatedBoxHasText(box)) {
        removeUserCreatedTextBox(box, { skipHistory: true });
        return true;
    }
    if (box.classList.contains('is-editing') || box.dataset.pendingEdit === '1' || box.dataset.pendingResize === '1') {
        pushHistorySnapshot('commit text annotation edit');
    }
    syncAnnotationBoxToPersistedAnnotations(box);
    if (!box.isConnected) return true;
    selectedAnnBoxUid = box.dataset.uid || null;
    selectedAnnBoxIsEditing = false;
    document.querySelectorAll('.enpv-annotation-box.is-selected').forEach((b) => {
        if (b !== box) b.classList.remove('is-selected');
    });
    box.classList.add('is-selected');
    positionAnnMenuOver(box);
    updateAnnotationFormatBarForBox(box);
    return true;
}

function deselectAnnBox(options = {}) {
    // Deselect is local-only: preserve edited text/geometry in memory so
    // zoom/scroll/page re-renders do not resurrect the original span, but
    // leave the explicit topbar Save button as the only server write.
    const cur = findSelectedBox();
    if (cur) {
        const existing = persistedAnnotationsById.get(String(cur.dataset.annotationId || '')) || null;
        if (isUserCreatedTextBox(cur, existing) && !userCreatedBoxHasText(cur)) {
            removeUserCreatedTextBox(cur, { skipHistory: true });
            return;
        }
        if (options.keepUserCreatedSelected === true && commitUserCreatedTextBoxAndKeepSelected(cur)) {
            return;
        }
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
    clearMultiSelection();
    if (!document.body.classList.contains('enpv-shape-on')) syncShapePanelUi();
}

function onAnnBoxPointerDown(ev) {
    if (ev.button !== 0) return;
    let box = ev.currentTarget;
    box = resolveAnnotationBoxForLinePointerDown(box, ev);
    if (!box) {
        ev.stopPropagation();
        ev.preventDefault();
        deselectAnnBox();
        return;
    }
    const isImageBacked = isImageBackedBox(box);
    const isShape = box?.dataset?.annotationType === 'shape';
    const addTextUserBox = box?.dataset?.userCreated === '1';
    if (!document.body.classList.contains('enpv-edit-on') && !document.body.classList.contains('enpv-shape-on') && !isImageBacked && !isShape && !addTextUserBox) return;
    // While the box is in in-place edit mode, suppress drag/select
    // entirely so the user can interact with the inner text without
    // accidentally moving the box. The user exits edit mode by clicking
    // outside the box (which fires the global outside-click handler →
    // deselect → endEditMode + commit) or pressing Escape.
    if (box.classList.contains('is-editing')) {
        if (ev.target?.closest?.('.enpv-text-content')) {
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
    if (hasMultiSelection() && box.classList.contains('is-multi-selected')) {
        if (isAnnBoxLocked(box)) return;
        if (beginMultiAnnBoxDrag(box, ev)) return;
    }
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
    const isImageBacked = isImageBackedBox(box);
    const isShape = box.dataset.annotationType === 'shape';
    const addTextUserBox = box.dataset.userCreated === '1';
    if (!document.body.classList.contains('enpv-edit-on') && !document.body.classList.contains('enpv-shape-on') && !isImageBacked && !isShape && !addTextUserBox) return;
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
    const pageIndex = Number.parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = Number.isFinite(pageIndex) && pageIndex >= 0 ? pdfViewer.getPageView(pageIndex) : null;
    const linePoints = edge === 'line-start' || edge === 'line-end'
        ? lineEndpointPointsForBox(box, pageView?.viewport, scale)
        : null;
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
        minHeight: minTextResizeHeightPx(box),
        startLineStartPoint: linePoints?.start || null,
        startLineEndPoint: linePoints?.end || null,
        pageIndex,
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
    const minHeight = resizeState.minHeight || MIN;
    let left = startLeft, top = startTop, w = startWidth, h = startHeight;
    const layerSize = pageLayerSizeForBox(box);
    if ((edge === 'line-start' || edge === 'line-end') && resizeState.startLineStartPoint && resizeState.startLineEndPoint) {
        const point = pdfjsPointFromPageClient(resizeState.pageIndex, ev.clientX, ev.clientY);
        if (!point) return;
        const startPoint = resizeState.startLineStartPoint;
        const endPoint = resizeState.startLineEndPoint;
        const draggedPoint = { x: point.pdfX, y: point.pdfY };
        const lineBox = edge === 'line-start'
            ? (() => {
                const nextStart = ev.shiftKey
                    ? constrainLineEndpointTo45(endPoint.x, endPoint.y, draggedPoint.x, draggedPoint.y)
                    : draggedPoint;
                return computeLineBoxGeometry(nextStart.x, nextStart.y, endPoint.x, endPoint.y);
            })()
            : (() => {
                const nextEnd = ev.shiftKey
                    ? constrainLineEndpointTo45(startPoint.x, startPoint.y, draggedPoint.x, draggedPoint.y)
                    : draggedPoint;
                return computeLineBoxGeometry(startPoint.x, startPoint.y, nextEnd.x, nextEnd.y);
            })();
        const rect = applyLineGeometryToBox(box, lineBox, point.viewport, resizeState.scale);
        if (!rect) return;
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        updateShapeSvgForBox(box, buildAnnotationFromBox(box, existing) || existing || {}, resizeState.scale || 1);
        updateShapeResizeHandlesForBox(box);
        left = rect.left;
        top = rect.top;
        w = rect.width;
        h = rect.height;
    } else if (['nw', 'ne', 'sw', 'se'].includes(edge)
        && box.dataset.annotationType === 'shape'
        && normalizeShapeType(box.dataset.shapeType) !== 'line') {
        const rawWidth = edge.includes('w') ? startWidth - dx : startWidth + dx;
        const rawHeight = edge.includes('n') ? startHeight - dy : startHeight + dy;
        const maxWidth = !layerSize
            ? Number.POSITIVE_INFINITY
            : (edge.includes('w') ? startLeft + startWidth : layerSize.width - startLeft);
        const maxHeight = !layerSize
            ? Number.POSITIVE_INFINITY
            : (edge.includes('n') ? startTop + startHeight : layerSize.height - startTop);
        w = clampResizeScalar(rawWidth, MIN, maxWidth);
        h = clampResizeScalar(rawHeight, MIN, maxHeight);
        if (edge.includes('w')) left = startLeft + (startWidth - w);
        if (edge.includes('n')) top = startTop + (startHeight - h);
    } else if (['nw', 'ne', 'sw', 'se'].includes(edge)) {
        const rawWidth = edge.includes('w') ? startWidth - dx : startWidth + dx;
        const rawHeight = edge.includes('n') ? startHeight - dy : startHeight + dy;
        const minScale = Math.max(MIN / Math.max(1, startWidth), MIN / Math.max(1, startHeight));
        const widthScale = rawWidth / Math.max(1, startWidth);
        const heightScale = rawHeight / Math.max(1, startHeight);
        const maxWidthPx = !layerSize
            ? Number.POSITIVE_INFINITY
            : (edge.includes('w') ? startLeft + startWidth : layerSize.width - startLeft);
        const maxHeightPx = !layerSize
            ? Number.POSITIVE_INFINITY
            : (edge.includes('n') ? startTop + startHeight : layerSize.height - startTop);
        const maxScale = Math.max(minScale, Math.min(
            maxWidthPx / Math.max(1, startWidth),
            maxHeightPx / Math.max(1, startHeight),
        ));
        const nextScale = Math.min(maxScale, Math.max(minScale, Math.max(widthScale, heightScale)));
        w = Math.max(MIN, startWidth * nextScale);
        h = Math.max(MIN, startHeight * nextScale);
        if (edge.includes('w')) left = startLeft + (startWidth - w);
        if (edge.includes('n')) top = startTop + (startHeight - h);
    } else if (edge === 'r') {
        const maxWidth = layerSize ? layerSize.width - startLeft : Number.POSITIVE_INFINITY;
        w = clampResizeScalar(startWidth + dx, MIN, maxWidth);
    }
    else if (edge === 'l') {
        const maxWidth = layerSize ? startLeft + startWidth : Number.POSITIVE_INFINITY;
        const nw = clampResizeScalar(startWidth - dx, MIN, maxWidth);
        left = startLeft + (startWidth - nw);
        w = nw;
    } else if (edge === 'b') {
        const maxHeight = layerSize ? layerSize.height - startTop : Number.POSITIVE_INFINITY;
        h = clampResizeScalar(startHeight + dy, minHeight, maxHeight);
    }
    else if (edge === 't') {
        const maxHeight = layerSize ? startTop + startHeight : Number.POSITIVE_INFINITY;
        const nh = clampResizeScalar(startHeight - dy, minHeight, maxHeight);
        top = startTop + (startHeight - nh);
        h = nh;
    }
    box.style.left = `${left}px`;
    box.style.top = `${top}px`;
    box.style.width = `${w}px`;
    box.style.height = `${h}px`;
    if (box.dataset.annotationType === 'shape') {
        const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
        updateShapeSvgForBox(box, buildAnnotationFromBox(box, existing) || existing || {}, resizeState?.scale || 1);
        updateShapeResizeHandlesForBox(box);
    }
    if (editorModeForBox(box) === 'rich' && (edge === 'l' || edge === 'r')) {
        fitRichTextBoxToContent(box, edge === 't' ? 'bottom' : 'top');
        clampBoxInsidePage(box);
        left = parseFloat(box.style.left) || left;
        top = parseFloat(box.style.top) || top;
        w = parseFloat(box.style.width) || w;
        h = parseFloat(box.style.height) || h;
    }
    if (clampBoxInsidePage(box)) {
        if (box.dataset.annotationType === 'shape') {
            const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
            updateShapeSvgForBox(box, buildAnnotationFromBox(box, existing) || existing || {}, resizeState?.scale || 1);
            updateShapeResizeHandlesForBox(box);
        }
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
    clampBoxInsidePage(box);
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

function rotationDeltaDegrees(left, right) {
    return Math.abs(((Number(left) - Number(right) + 540) % 360) - 180);
}

function shapeRotationCenterClient(box) {
    if (!box) return null;
    const rect = box.getBoundingClientRect();
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;
    return {
        cx: rect.left + (rect.width / 2),
        cy: rect.top + (rect.height / 2),
    };
}

function pointerAngleDegrees(clientX, clientY, center) {
    return Math.atan2(clientY - center.cy, clientX - center.cx) * (180 / Math.PI);
}

function onShapeRotateHandlePointerDown(ev) {
    if (ev.button !== 0) return;
    ev.stopPropagation();
    ev.preventDefault();
    const handle = ev.currentTarget;
    const box = handle?.parentElement || null;
    if (!shapeBoxCanRotate(box)) return;
    if (isAnnBoxLocked(box)) return;
    if (!box.classList.contains('is-selected')) selectAnnBox(box);
    const center = shapeRotationCenterClient(box);
    if (!center) return;
    const currentRotation = normalizeRotationDegrees(Number.parseFloat(box.dataset.rotation || '0') || 0);
    const handleAngle = normalizeRotationDegrees(currentRotation + 90);
    const pointerAngle = pointerAngleDegrees(ev.clientX, ev.clientY, center);
    const grabAngleOffset = ((pointerAngle - handleAngle + 540) % 360) - 180;
    shapeRotateState = {
        box,
        handle,
        pointerId: ev.pointerId,
        startRotation: currentRotation,
        currentRotation,
        grabAngleOffset,
    };
    handle.classList.add('is-active');
    document.body.classList.add('enpv-rotating');
    if (annMenu) annMenu.hidden = true;
    try { handle.setPointerCapture(ev.pointerId); } catch (_) {}
    window.addEventListener('pointermove', onShapeRotatePointerMove);
    window.addEventListener('pointerup', onShapeRotatePointerUp, { once: true });
    window.addEventListener('pointercancel', onShapeRotatePointerUp, { once: true });
}

function onShapeRotatePointerMove(ev) {
    if (!shapeRotateState) return;
    if (shapeRotateState.pointerId !== ev.pointerId) return;
    const { box, grabAngleOffset } = shapeRotateState;
    if (!box?.isConnected) return;
    const center = shapeRotationCenterClient(box);
    if (!center) return;
    const pointerAngle = pointerAngleDegrees(ev.clientX, ev.clientY, center);
    const handleAngle = normalizeRotationDegrees(pointerAngle - grabAngleOffset);
    const nextRotation = normalizeRotationDegrees(handleAngle - 90);
    shapeRotateState.currentRotation = nextRotation;
    box.dataset.rotation = String(Math.round(nextRotation * 10) / 10);
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    updateShapeSvgForBox(box, buildAnnotationFromBox(box, existing) || existing || {}, Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1);
}

function onShapeRotatePointerUp(ev) {
    if (!shapeRotateState) return;
    if (shapeRotateState.pointerId !== ev.pointerId) return;
    const { box, handle, startRotation, currentRotation } = shapeRotateState;
    window.removeEventListener('pointermove', onShapeRotatePointerMove);
    document.body.classList.remove('enpv-rotating');
    handle?.classList?.remove('is-active');
    shapeRotateState = null;
    if (!box?.isConnected) return;
    positionAnnMenuOver(box);
    if (rotationDeltaDegrees(currentRotation, startRotation) <= 0.5) return;
    pushHistorySnapshot('rotate shape');
    box.dataset.pendingEdit = '1';
    syncAnnotationBoxToPersistedAnnotations(box, { preserveEditMode: true });
    updateShapeRotateHandleForBox(box);
    markManualSaveNeeded();
}

function startShapeCutGesture(ev) {
    if (!shapeCutState?.armed) return;
    if (ev.button !== 0) return;
    if (shapeCutState.pointerId != null) return;
    if (ev.target?.closest?.('.enpv-ann-menu')) return;
    if (ev.target?.closest?.('.enpv-resize-handle')) return;
    if (ev.target?.closest?.('.enpv-shape-rotate-handle')) return;
    const box = currentShapeCutBox();
    if (!box?.isConnected || !canCutShapeBox(box)) {
        cancelShapeCutMode({ status: 'Cut mode cancelled.', error: true });
        return;
    }
    const pageIndex = pageIndexFromEventTarget(ev.target);
    if (pageIndex !== shapeCutState.pageIndex) return;
    const point = pdfjsPointFromPageClient(pageIndex, ev.clientX, ev.clientY);
    if (!point) return;
    ev.preventDefault();
    ev.stopPropagation();
    shapeCutState.pointerId = ev.pointerId;
    shapeCutState.startPoint = point;
    shapeCutState.currentPoint = point;
    document.body.classList.add('enpv-shape-cutting');
    if (annMenu) annMenu.hidden = true;
    updateShapeCutOverlay();
    try { container?.setPointerCapture?.(ev.pointerId); } catch (_) {}
}

function updateShapeCutGesture(ev) {
    if (!shapeCutState?.armed || shapeCutState.pointerId !== ev.pointerId) return;
    const point = pdfjsPointFromPageClient(shapeCutState.pageIndex, ev.clientX, ev.clientY);
    if (!point) return;
    ev.preventDefault();
    shapeCutState.currentPoint = point;
    updateShapeCutOverlay();
}

function finishShapeCutGesture(ev) {
    if (!shapeCutState?.armed || shapeCutState.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    ev.stopPropagation();
    try {
        if (container?.hasPointerCapture?.(ev.pointerId)) container.releasePointerCapture(ev.pointerId);
    } catch (_) {}
    const box = currentShapeCutBox();
    const startPoint = shapeCutState.startPoint;
    const endPoint = pdfjsPointFromPageClient(shapeCutState.pageIndex, ev.clientX, ev.clientY) || shapeCutState.currentPoint;
    removeShapeCutOverlay();
    document.body.classList.remove('enpv-shape-cutting');
    if (!box || !startPoint || !endPoint) {
        cancelShapeCutMode({ status: 'Cut mode cancelled.', error: true });
        return;
    }
    const result = cutShapeBoxWithLine(box, startPoint, endPoint);
    if (!result.ok) {
        shapeCutState.pointerId = null;
        shapeCutState.startPoint = null;
        shapeCutState.currentPoint = null;
        refreshShapeCutUi();
        positionAnnMenuOver(box);
        setStatus(result.message || 'Unable to cut shape.', true);
        return;
    }
    shapeCutState = null;
    refreshShapeCutUi();
    setStatus('Shape cut into 2 pieces.');
}

function cancelShapeCutGesture(ev) {
    if (!shapeCutState?.armed || shapeCutState.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    shapeCutState.pointerId = null;
    shapeCutState.startPoint = null;
    shapeCutState.currentPoint = null;
    removeShapeCutOverlay();
    document.body.classList.remove('enpv-shape-cutting');
    refreshShapeCutUi();
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
// at a time and grouping by getClientRects() vertical overlap. This gives
// us the lines the user sees, while ignoring inline baseline shifts from
// styled spans.
function readVisualLinesFromBox(tc) {
    const text = tc.innerText.replace(/\r\n?/g, '\n');
    if (!text) return [];
    // Walk every text node; for each character, find its client rect and
    // append it to the current line while its vertical box overlaps the
    // current row. Newline chars start a new line.
    const lines = [];
    let curLine = '';
    let curTop = null;
    let curBottom = null;
    const walker = document.createTreeWalker(tc, NodeFilter.SHOW_TEXT);
    const range = document.createRange();
    let node;
    const startsNewVisualLine = (rect, previousTop, previousBottom) => {
        if (!rect || previousTop == null || previousBottom == null) return false;
        const height = Math.max(1, rect.height || 0);
        // Inline source-fidelity runs may carry relative `top` offsets for
        // baseline alignment. Ignore those small shifts; a real wrap/newline
        // advances by most of a line even when adjacent glyph boxes overlap.
        return rect.top > previousTop + Math.max(4, height * 0.55);
    };
    while ((node = walker.nextNode())) {
        const s = node.nodeValue || '';
        for (let i = 0; i < s.length; i += 1) {
            const ch = s[i];
            if (ch === '\n') {
                lines.push(curLine);
                curLine = '';
                curTop = null;
                curBottom = null;
                continue;
            }
            range.setStart(node, i);
            range.setEnd(node, i + 1);
            const rects = range.getClientRects();
            const rect = rects.length ? rects[0] : null;
            const top = rect ? rect.top : curTop;
            const bottom = rect ? rect.bottom : curBottom;
            if (curLine && startsNewVisualLine(rect, curTop, curBottom)) {
                lines.push(curLine);
                curLine = ch;
                curTop = top;
                curBottom = bottom;
            } else {
                curLine += ch;
                curTop = curTop == null ? top : Math.min(curTop, top ?? curTop);
                curBottom = curBottom == null ? bottom : Math.max(curBottom, bottom ?? curBottom);
            }
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

function clientRectsIntersect(a, b) {
    if (!a || !b) return false;
    return Math.min(a.right, b.right) > Math.max(a.left, b.left)
        && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top);
}

function editablePageBoxes(pageDiv) {
    return Array.from(pageDiv?.querySelectorAll?.('.enpv-annotation-box') || [])
        .filter((box) => box.isConnected
            && !box.classList.contains('is-shape-preview')
            && !box.closest('.enpv-ann-menu'));
}

function startMarqueeSelection(ev) {
    if (!document.body.classList.contains('enpv-edit-on')) return;
    if (document.body.classList.contains('enpv-add-text-on') || document.body.classList.contains('enpv-shape-on')) return;
    if (ev.button !== 0 || dragState || multiDragState || resizeState || shapeRotateState || shapeCutState?.armed || addTextCreationState || shapeCreationState || imagePlacementState) return;
    if (selectedAnnBoxIsEditing) return;
    if (ev.target?.closest?.('.enpv-annotation-box')) return;
    if (ev.target?.closest?.('.enpv-ann-menu')) return;
    if (ev.target?.closest?.('#ann-format-bar')) return;
    if (ev.target?.closest?.('#shape-tool-panel')) return;

    const pageIndex = pageIndexFromEventTarget(ev.target);
    if (!Number.isFinite(pageIndex) || pageIndex < 0) return;
    const point = pdfjsPointFromPageClient(pageIndex, ev.clientX, ev.clientY);
    if (!point) return;

    ev.preventDefault();
    ev.stopPropagation();
    deselectAnnBox();

    const marquee = document.createElement('div');
    marquee.className = 'enpv-marquee-selection';
    marquee.style.left = `${point.canvasX}px`;
    marquee.style.top = `${point.canvasY}px`;
    marquee.style.width = '0px';
    marquee.style.height = '0px';
    point.pageDiv.appendChild(marquee);

    marqueeSelectionState = {
        pointerId: ev.pointerId,
        pageIndex,
        pageDiv: point.pageDiv,
        pageRect: point.pageRect,
        startCanvasX: point.canvasX,
        startCanvasY: point.canvasY,
        marquee,
        moved: false,
    };
    try { point.pageDiv.setPointerCapture?.(ev.pointerId); } catch (_) {}
}

function updateMarqueeSelection(ev) {
    const state = marqueeSelectionState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    ev.stopPropagation();
    const pageWidth = state.pageRect.width;
    const pageHeight = state.pageRect.height;
    const currentX = Math.max(0, Math.min(ev.clientX - state.pageRect.left, pageWidth));
    const currentY = Math.max(0, Math.min(ev.clientY - state.pageRect.top, pageHeight));
    const left = Math.min(state.startCanvasX, currentX);
    const top = Math.min(state.startCanvasY, currentY);
    const width = Math.abs(currentX - state.startCanvasX);
    const height = Math.abs(currentY - state.startCanvasY);
    state.marquee.style.left = `${left}px`;
    state.marquee.style.top = `${top}px`;
    state.marquee.style.width = `${width}px`;
    state.marquee.style.height = `${height}px`;
    if (width > 4 || height > 4) state.moved = true;
}

function finishMarqueeSelection(ev) {
    const state = marqueeSelectionState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    ev.stopPropagation();
    try { state.pageDiv?.releasePointerCapture?.(ev.pointerId); } catch (_) {}

    const selectionRect = state.marquee.getBoundingClientRect();
    state.marquee.remove();
    marqueeSelectionState = null;

    if (!state.moved || selectionRect.width < 6 || selectionRect.height < 6) {
        clearMultiSelection();
        return;
    }

    const selected = editablePageBoxes(state.pageDiv).filter((box) => {
        if (isAnnBoxLocked(box)) return false;
        const rect = box.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && clientRectsIntersect(selectionRect, rect);
    });
    selectMultipleAnnBoxes(selected);
}

function cancelMarqueeSelection(ev) {
    const state = marqueeSelectionState;
    if (!state || state.pointerId !== ev.pointerId) return;
    try { state.pageDiv?.releasePointerCapture?.(ev.pointerId); } catch (_) {}
    state.marquee?.remove?.();
    marqueeSelectionState = null;
}

function beginMultiAnnBoxDrag(anchorBox, ev) {
    const selected = multiSelectedBoxes().filter((box) => !isAnnBoxLocked(box));
    if (selected.length < 2 || !anchorBox?.classList?.contains('is-multi-selected')) return false;
    const layer = anchorBox.parentElement;
    if (!layer) return false;
    const scale = Number.parseFloat(layer.dataset.scale || '1') || 1;
    if (!(scale > 0)) return false;
    const pageRect = layer.getBoundingClientRect();
    const items = selected
        .filter((box) => box.parentElement === layer)
        .map((box) => {
            const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
            return {
                box,
                isUserBox: isUserCreatedTextBox(box, existing),
                startDxPts: Number.parseFloat(box.dataset.dxPts || '0') || 0,
                startDyPts: Number.parseFloat(box.dataset.dyPts || '0') || 0,
                startLeft: Number.parseFloat(box.style.left || '0') || 0,
                startTop: Number.parseFloat(box.style.top || '0') || 0,
                width: Number.parseFloat(box.style.width || '') || box.offsetWidth || 0,
                height: Number.parseFloat(box.style.height || '') || box.offsetHeight || 0,
            };
        });
    if (items.length < 2) return false;

    const minLeft = Math.min(...items.map((item) => item.startLeft));
    const minTop = Math.min(...items.map((item) => item.startTop));
    const maxRight = Math.max(...items.map((item) => item.startLeft + item.width));
    const maxBottom = Math.max(...items.map((item) => item.startTop + item.height));

    multiDragState = {
        pointerId: ev.pointerId,
        layer,
        scale,
        startClientX: ev.clientX,
        startClientY: ev.clientY,
        dxMinPx: -minLeft,
        dxMaxPx: Math.max(0, pageRect.width - maxRight),
        dyMinPx: -minTop,
        dyMaxPx: Math.max(0, pageRect.height - maxBottom),
        items,
        pendingDxPx: 0,
        pendingDyPx: 0,
    };
    items.forEach((item) => item.box.classList.add('is-dragging'));
    document.body.classList.add('enpv-dragging');
    if (annMenu) annMenu.hidden = true;
    try { anchorBox.setPointerCapture(ev.pointerId); } catch (_) {}
    window.addEventListener('pointermove', onMultiAnnBoxPointerMove);
    window.addEventListener('pointerup', onMultiAnnBoxPointerUp, { once: true });
    window.addEventListener('pointercancel', onMultiAnnBoxPointerUp, { once: true });
    return true;
}

function onMultiAnnBoxPointerMove(ev) {
    const state = multiDragState;
    if (!state || state.pointerId !== ev.pointerId) return;
    ev.preventDefault();
    let dxPx = ev.clientX - state.startClientX;
    let dyPx = ev.clientY - state.startClientY;
    dxPx = Math.max(state.dxMinPx, Math.min(state.dxMaxPx, dxPx));
    dyPx = Math.max(state.dyMinPx, Math.min(state.dyMaxPx, dyPx));
    state.pendingDxPx = dxPx;
    state.pendingDyPx = dyPx;
    if (!state.rafScheduled) {
        state.rafScheduled = true;
        requestAnimationFrame(flushMultiDragTransform);
    }
}

function flushMultiDragTransform() {
    const state = multiDragState;
    if (!state) return;
    state.rafScheduled = false;
    const dxPts = state.pendingDxPx / state.scale;
    const dyPts = state.pendingDyPx / state.scale;
    state.items.forEach((item) => {
        if (item.isUserBox) {
            item.box.style.transform = `translate3d(${state.pendingDxPx}px, ${state.pendingDyPx}px, 0)`;
            return;
        }
        const nextDx = item.startDxPts + dxPts;
        const nextDy = item.startDyPts + dyPts;
        item.box.style.transform = `translate3d(${nextDx * state.scale}px, ${nextDy * state.scale}px, 0)`;
    });
}

function onMultiAnnBoxPointerUp(ev) {
    const state = multiDragState;
    if (!state) return;
    if (ev?.preventDefault) ev.preventDefault();
    const dxPx = state.pendingDxPx || 0;
    const dyPx = state.pendingDyPx || 0;
    const moved = Math.abs(dxPx) > 1 || Math.abs(dyPx) > 1;
    if (moved) pushHistorySnapshot('move annotations');
    const dxPts = dxPx / state.scale;
    const dyPts = dyPx / state.scale;
    state.items.forEach((item) => {
        const { box } = item;
        box.classList.remove('is-dragging');
        if (!moved) return;
        if (item.isUserBox) {
            box.style.left = `${item.startLeft + dxPx}px`;
            box.style.top = `${item.startTop + dyPx}px`;
            box.style.transform = '';
            box.dataset.dxPts = '0';
            box.dataset.dyPts = '0';
            removeAnnBoxSourceMasks(box);
        } else {
            const nextDx = item.startDxPts + dxPts;
            const nextDy = item.startDyPts + dyPts;
            box.dataset.dxPts = String(nextDx);
            box.dataset.dyPts = String(nextDy);
            box.style.transform = `translate3d(${nextDx * state.scale}px, ${nextDy * state.scale}px, 0)`;
            promoteSourceBoxToMovedOverlay(box);
            if (box.dataset.uid) annotationOffsetsPts.set(String(box.dataset.uid), { dx: nextDx, dy: nextDy });
        }
        box.dataset.pendingEdit = '1';
        syncAnnotationBoxToPersistedAnnotations(box);
        box.classList.add('is-multi-selected');
    });
    document.body.classList.remove('enpv-dragging');
    window.removeEventListener('pointermove', onMultiAnnBoxPointerMove);
    multiDragState = null;
    if (moved) {
        markManualSaveNeeded();
        setStatus(`${multiSelectedAnnBoxUids.size} annotations moved.`);
    }
}

function deleteMultiSelectedAnnBoxes() {
    const boxes = multiSelectedBoxes().filter((box) => !isAnnBoxLocked(box));
    if (!boxes.length) return false;
    pushHistorySnapshot('delete annotations');
    boxes.forEach((box) => deleteAnnBox(box, { skipHistory: true }));
    clearMultiSelection();
    setStatus(`${boxes.length} annotations deleted.`);
    markManualSaveNeeded();
    return true;
}

function beginAnnBoxDrag(box, ev) {
    const layer = box.parentElement;
    if (!layer) return;
    const scale = parseFloat(layer.dataset.scale || '1') || 1;
    const startDxPts = parseFloat(box.dataset.dxPts || '0') || 0;
    const startDyPts = parseFloat(box.dataset.dyPts || '0') || 0;
    // Compute clamp limits in PDF points so the moved box stays inside
    // the page rect. Without this, dragging off the
    // page edge produces source bounding boxes that no longer correspond
    // to any text-layer span on the next render — so a follow-up move
    // fails with "no text ops found inside bbox".
    const pageIndex = parseInt(box.dataset.pageIndex || '-1', 10);
    const pageView = (Number.isFinite(pageIndex) && pageIndex >= 0)
        ? pdfViewer.getPageView(pageIndex) : null;
    const viewport = pageView?.viewport;
    const pageWPts = viewport ? (Number(viewport.width) / scale) : null;
    const pageHPts = viewport ? (Number(viewport.height) / scale) : null;
    const margin = 0; // pts
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
    const existingAnnotation = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    if (!isUserCreatedTextBox(box, existingAnnotation)
        && !boolish(existingAnnotation?.movedTextOverlay)
        && box.dataset.movedTextOverlay !== '1') {
        rememberSourceMaskRectForCurrentBox(box);
    }
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
    let dxPts = pendingDxPts != null ? pendingDxPts : (parseFloat(box.dataset.dxPts || '0') || 0);
    let dyPts = pendingDyPts != null ? pendingDyPts : (parseFloat(box.dataset.dyPts || '0') || 0);
    const moved = Math.abs(dxPts - dragState.startDxPts) > 0.25 || Math.abs(dyPts - dragState.startDyPts) > 0.25;
    if (moved) pushHistorySnapshot('move annotation');
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const isUserBox = isUserCreatedTextBox(box, existing);
    if (moved && isUserBox) {
        const left = Number.parseFloat(box.style.left || '0') || 0;
        const top = Number.parseFloat(box.style.top || '0') || 0;
        box.style.left = `${left + (dxPts * scale)}px`;
        box.style.top = `${top + (dyPts * scale)}px`;
        box.style.transform = '';
        dxPts = 0;
        dyPts = 0;
    } else {
        box.style.transform = `translate3d(${dxPts * scale}px, ${dyPts * scale}px, 0)`;
    }
    box.dataset.dxPts = String(dxPts);
    box.dataset.dyPts = String(dyPts);
    if (moved) {
        if (!isUserBox) promoteSourceBoxToMovedOverlay(box);
        else {
            removeAnnBoxSourceMasks(box);
        }
        box.dataset.pendingEdit = '1';
        syncAnnotationBoxToPersistedAnnotations(box);
        box.classList.add('is-selected');
    }
    if (uid && moved) {
        if (isUserBox || (Math.abs(dxPts) < 0.01 && Math.abs(dyPts) < 0.01)) {
            annotationOffsetsPts.delete(uid);
        } else {
            annotationOffsetsPts.set(uid, { dx: dxPts, dy: dyPts });
        }
    }
    window.removeEventListener('pointermove', onAnnBoxPointerMove);
    dragState = null;
    positionAnnMenuOver(box);
    if (moved) markManualSaveNeeded();
    if (moved && !isUserBox) {
        redactMovedSourceTextForBox(box).catch((err) => {
            console.error(err);
        });
    }

}

function buildMovedSourceRedactionEdit(annotation, box = null) {
    if (!REDACT_URL || !annotation) return null;
    if (!boolish(annotation.movedTextOverlay)) return null;
    if (boolish(annotation.pdfjsDeleted) || boolish(annotation.skipPdfjsSourceMask)) return null;
    if (annotation._pdfjsSourceRedacted === true || sourceRedactedAnnotationIds.has(String(annotation.id || ''))) return null;
    const page = annotationPageIndex(annotation);
    if (!Number.isFinite(page) || page < 0) return null;
    const baseline = annotationBaselinePdfBox(annotation) || {
        x: Number.parseFloat(box?.dataset?.sourceBboxX || box?.dataset?.baseBboxX || 'NaN'),
        y: Number.parseFloat(box?.dataset?.sourceBboxY || box?.dataset?.baseBboxY || 'NaN'),
        w: Number.parseFloat(box?.dataset?.sourceBboxW || box?.dataset?.baseBboxW || 'NaN'),
        h: Number.parseFloat(box?.dataset?.sourceBboxH || box?.dataset?.baseBboxH || 'NaN'),
    };
    if (![baseline.x, baseline.y, baseline.w, baseline.h].every(Number.isFinite)
        || baseline.w <= 0 || baseline.h <= 0) {
        return null;
    }
    const originalText = annotation._pdfjsCanvasRewritten === true
        ? String(annotation._pdfjsCanvasText || annotation.text || '')
        : String(annotation.pdfjsSourceText || annotation.originalText || box?.dataset?.baseText || box?.dataset?.originalText || '');
    const occurrence = Number.parseInt(annotation.pdfjsSourceOccurrence || box?.dataset?.occurrence || '0', 10);
    return {
        page,
        original_text: originalText || undefined,
        occurrence: Number.isFinite(occurrence) && occurrence >= 0 ? occurrence : 0,
        source_bbox: {
            x: baseline.x,
            y: baseline.y,
            w: baseline.w,
            h: baseline.h,
        },
        redact_bbox_only: false,
        remove_graphics: false,
        fill_white: false,
    };
}

async function redactMovedSourceTextForBox(box) {
    if (!REDACT_URL || !box || box.dataset.sourceRedactionInFlight === '1') return false;
    const existing = persistedAnnotationsById.get(String(box.dataset.annotationId || '')) || null;
    const annotation = buildAnnotationFromBox(box, existing);
    const edit = buildMovedSourceRedactionEdit(annotation, box);
    if (!annotation || !edit) return false;
    box.dataset.sourceRedactionInFlight = '1';
    try {
        upsertPersistedAnnotation(annotation);
        setStatus('Removing moved source text...');
        await redactSourceTextForAnnotations([{ annotation, edit }]);
        removeAnnBoxSourceMasks(box);
        setStatus('Move applied.');
        return true;
    } catch (err) {
        console.error(err);
        showError(err.message || 'Moved source cleanup failed.');
        setStatus('Move saved with fallback mask.', true);
        return false;
    } finally {
        delete box.dataset.sourceRedactionInFlight;
    }
}

function movedSourceRedactionEntriesForPersistedAnnotations() {
    return Array.from(persistedAnnotationsById.values())
        .map((annotation) => ({ annotation, edit: buildMovedSourceRedactionEdit(annotation) }))
        .filter((entry) => entry.annotation && entry.edit);
}

function scheduleMovedSourceLiveRedaction() {
    if (!REDACT_URL || movedSourceLiveRedactionScheduled || movedSourceLiveRedactionInFlight) return;
    movedSourceLiveRedactionScheduled = true;
    window.setTimeout(() => {
        movedSourceLiveRedactionScheduled = false;
        redactMovedSourceTextForPersistedAnnotations().catch((err) => {
            console.error(err);
        });
    }, 0);
}

async function redactMovedSourceTextForPersistedAnnotations() {
    if (!REDACT_URL || movedSourceLiveRedactionInFlight) return false;
    const entries = movedSourceRedactionEntriesForPersistedAnnotations();
    if (!entries.length) return false;
    movedSourceLiveRedactionInFlight = true;
    try {
        setStatus('Removing moved source text...');
        await redactSourceTextForAnnotations(entries);
        setStatus('Moved source text removed.');
        return true;
    } catch (err) {
        console.error(err);
        showError(err.message || 'Moved source cleanup failed.');
        setStatus('Move cleanup failed.', true);
        return false;
    } finally {
        movedSourceLiveRedactionInFlight = false;
    }
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
    const groups = getSourceGroupsForPage(pageIndex, layerEl, pageView?.div || null)
        .filter((group) => group.text === text);
    if (groups.length <= 1) return 0;
    const sBox = ann._originalBox;
    if (!sBox) return 0;
    const viewport = pageView.viewport;
    const scale = Number(viewport?.scale) || 1;
    const canvasHeight = Number(viewport?.height) || 0;
    const targetLeft = sBox.x * scale;
    const targetTop = canvasHeight - (sBox.y + sBox.h) * scale;
    let bestOcc = 0;
    let bestDist = Infinity;
    for (const group of groups) {
        const r = group.rect;
        const sl = r.left;
        const st = r.top;
        const dx = sl - targetLeft;
        const dy = st - targetTop;
        const d = (dx * dx) + (dy * dy);
        if (d < bestDist) {
            bestDist = d;
            bestOcc = Number.parseInt(group.anchor?.dataset?.enpvOccurrence || '0', 10) || 0;
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
        } else if (action === 'cut') {
            ev.stopPropagation();
            ev.preventDefault();
            if (box) toggleShapeCutModeForBox(box);
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
    if (dragState || multiDragState || shapeRotateState || shapeCutState?.armed || marqueeSelectionState) return;
    if (ev.target?.closest('.enpv-annotation-box')) return;
    if (ev.target?.closest('.enpv-ann-menu')) return;
    if (ev.target?.closest('#save-btn')) return;
    if (ev.target?.closest('#undo-btn')) return;
    if (ev.target?.closest('#redo-btn')) return;
    if (ev.target?.closest('#ann-format-bar')) return;
    if (ev.target?.closest('#shape-tool-panel')) return;
    const selected = findSelectedBox();
    const existing = persistedAnnotationsById.get(String(selected?.dataset?.annotationId || '')) || null;
    if (selected?.classList?.contains('is-editing') && isUserCreatedTextBox(selected, existing)) {
        deselectAnnBox({ keepUserCreatedSelected: true });
        return;
    }
    deselectAnnBox();
}, { capture: true });

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
    if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && !ev.altKey && key === 'c') {
        if (activeElementAcceptsNativeClipboard() || windowHasSelectedText()) return;
        if (copySelectedAnnotationToClipboard()) ev.preventDefault();
        return;
    }
    if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && !ev.altKey && key === 'v') {
        if (activeElementAcceptsNativeClipboard()) return;
        if (pasteAnnotationClipboard()) ev.preventDefault();
        return;
    }
    if (ev.key === 'Escape' && shapeCutState?.armed) {
        ev.preventDefault();
        cancelShapeCutMode({ status: 'Shape cut cancelled.' });
        return;
    }
    if (ev.key === 'Escape' && hasMultiSelection() && !dragState && !multiDragState) {
        ev.preventDefault();
        clearMultiSelection();
        return;
    }
    if (ev.key === 'Escape' && selectedAnnBoxUid && !dragState) deselectAnnBox();
    if ((ev.key === 'Delete' || ev.key === 'Backspace') && hasMultiSelection() && !dragState && !multiDragState) {
        const active = document.activeElement;
        if (active?.isContentEditable || active?.closest?.('[contenteditable="true"]')) return;
        if (deleteMultiSelectedAnnBoxes()) ev.preventDefault();
        return;
    }
    if ((ev.key === 'Delete' || ev.key === 'Backspace') && selectedAnnBoxUid && !dragState) {
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
    } else if (ev.currentTarget?.dataset?.enpvDeletedSource === '1') {
        return;
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
        if (ev.currentTarget?.dataset?.enpvDeletedSource === '1') return;
        showError('Could not create an annotation box for this text run.');
        return;
    }
    selectAnnBox(box);
    beginEditMode(box);
}

// Double-click on an existing annotation box (persisted overlay or the
// on-demand box from a single click) → enter in-place edit mode.
function onAnnBoxDblClick(ev) {
    const box = ev.currentTarget;
    if (!document.body.classList.contains('enpv-edit-on') && box?.dataset?.userCreated !== '1') return;
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

    const sourceInfo = sourceInfoForSpan(spanEl);
    const sourceOriginal = sourceInfo?.text || spanEl.dataset.enpvOriginal || spanEl.textContent;
    const displayOriginal = sourceInfo?.visualText || sourceOriginal;
    const pageNum = parseInt(spanEl.dataset.enpvPage, 10) + 1;
    editOrig.textContent = `was: "${displayOriginal}" — page ${pageNum}`;
    editInput.value = displayOriginal;

    if (editBar.parentElement !== container) container.appendChild(editBar);

    const containerRect = container.getBoundingClientRect();
    const spanRect = sourceInfo?.clientRect || spanEl.getBoundingClientRect();
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
document.addEventListener('selectionchange', () => {
    captureInlineTextSelection();
    const box = findSelectedBox();
    if (box?.classList?.contains('is-editing')) updateAnnotationFormatBarForBox(box);
});
annFormatBar?.addEventListener('mousedown', (ev) => {
    const target = ev.target;
    if (target?.closest?.('button')) {
        captureInlineTextSelection({ clearCollapsed: false });
        ev.preventDefault();
        return;
    }
    if (target?.closest?.('input[type="color"]')) {
        captureInlineTextSelection({ clearCollapsed: false });
        ev.preventDefault();
    }
}, { capture: true });
annFormatBar?.addEventListener('pointerdown', () => {
    captureInlineTextSelection({ clearCollapsed: false });
}, { capture: true });
annFormatBar?.addEventListener('focusin', () => {
    captureInlineTextSelection({ clearCollapsed: false });
});
undoButton?.addEventListener('click', () => undoHistory());
redoButton?.addEventListener('click', () => redoHistory());
afbFont?.addEventListener('change', () => {
    applyFontFamilyToSelectedBox(afbFont.value);
});
afbSize?.addEventListener('input', () => {
    setFormatBarSizeLabel(sliderValueToFontPt(afbSize.value));
});
afbSize?.addEventListener('change', () => {
    const pt = sliderValueToFontPt(afbSize.value);
    setFormatBarSizeLabel(pt);
    applyFontSizeToSelectedBox(pt);
});
afbTextColor?.addEventListener('change', () => {
    applyTextColorToSelectedBox(afbTextColor.value);
});
afbTextColor?.addEventListener('input', () => {
    const box = findSelectedBox();
    if (box && selectedInlineTextRange(box)) {
        applyTextColorToSelectedBox(afbTextColor.value);
        return;
    }
    previewTextColorOnSelectedBox(afbTextColor.value);
});
afbBgColor?.addEventListener('change', () => {
    applyBackgroundColorToSelectedBox(afbBgColor.value);
});
afbBgColor?.addEventListener('input', () => {
    previewBackgroundColorOnSelectedBox(afbBgColor.value);
});
afbOpacity?.addEventListener('change', () => {
    applyOpacityToSelectedBox(afbOpacity.value);
});
afbBold?.addEventListener('click', () => {
    applyBoldToSelectedBox(afbBold.getAttribute('aria-pressed') !== 'true');
});
afbItalic?.addEventListener('click', () => {
    applyItalicToSelectedBox(afbItalic.getAttribute('aria-pressed') !== 'true');
});
afbUnderline?.addEventListener('click', () => {
    applyUnderlineToSelectedBox(afbUnderline.getAttribute('aria-pressed') !== 'true');
});
afbAlign?.addEventListener('change', () => {
    applyTextAlignToSelectedBox(afbAlign.value);
});
afbValign?.addEventListener('change', () => {
    applyVerticalAlignToSelectedBox(afbValign.value);
});
afbCopy?.addEventListener('click', () => {
    const box = findSelectedBox();
    if (box) copyAnnBox(box);
});
afbDelete?.addEventListener('click', () => {
    const box = findSelectedBox();
    if (box) deleteAnnBox(box);
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
    const newText = String(tc?.textContent ?? annotation.text ?? '');
    const originalText = String(annotation.pdfjsSourceText || box.dataset.baseText || box.dataset.originalText || previousText || '');
    if (!originalText || newText === originalText || normalizeComparableText(newText) === normalizeComparableText(originalText)) return null;
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

async function overwritePromotedAnnotationText(annotation, newText, originalTextHint = '') {
    if (!annotation?.id || !OVERWRITE_TEXT_URL) return false;
    const response = await fetch(OVERWRITE_TEXT_URL, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': CSRF,
        },
        body: JSON.stringify({
            document_id: Number(DOC_ID),
            annotation_id: String(annotation.id),
            text: String(newText ?? ''),
            original_text: String(originalTextHint || annotation.originalText || annotation.pdfjsSourceText || annotation.text || ''),
            session_id: getSessionId(),
            generate_new: 1,
        }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result?.success) {
        throw new Error(result?.message || `Promoted text overwrite failed (${response.status})`);
    }

    const next = {
        ...annotation,
        text: String(newText ?? ''),
        sourceTextLines: [String(newText ?? '')],
        promotedDirty: true,
        savedTextOverlay: true,
        preserveSourceTypography: true,
    };
    upsertPersistedAnnotation(next);
    if (result.file_url) {
        await loadPdfFromUrl(String(result.file_url));
    }
    return true;
}

function buildPersistedSourceRewriteEdit(annotation) {
    if (!annotation || annotation._pdfjsCanvasRewritten === true) return null;
    if (annotation._pdfjsSourceRedacted === true) return null;
    if (String(annotation.type || '').toLowerCase() !== 'text') return null;
    if (annotation.pdfjsDeleted === true || isPromotedExtractionAnnotation(annotation)) return null;
    if (annotation.userForcedRichText === true || annotation.pdfjsEditorMode === 'rich') return null;
    const sourceText = String(annotation.pdfjsSourceText || annotation.originalText || '');
    const newText = String(annotation.text || '');
    if (!sourceText || !newText || sourceText === newText || normalizeComparableText(sourceText) === normalizeComparableText(newText)) return null;
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
    const activeSourceInfo = sourceInfoForSpan(activeRun);
    const original = activeSourceInfo?.text || activeRun.dataset.enpvOriginal || activeRun.textContent || '';
    const displayOriginal = activeSourceInfo?.visualText || original;
    if (newText === displayOriginal || normalizeComparableText(newText) === normalizeComparableText(original)) { closeEditor(); return; }
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
        const sourceInfo = activeSourceInfo || sourceInfoForSpan(activeRun);
        const sourceRect = sourceInfo?.rect;
        const layerRect = layerEl.getBoundingClientRect();
        const spanRect = activeRun.getBoundingClientRect();
        const currentRect = {
            x: ((sourceRect?.left ?? (spanRect.left - layerRect.left))) / scale,
            y: (Number(viewport.height) - ((sourceRect?.top ?? (spanRect.top - layerRect.top)) + (sourceRect?.height ?? spanRect.height))) / scale,
            w: (sourceRect?.width ?? spanRect.width) / scale,
            h: (sourceRect?.height ?? spanRect.height) / scale,
        };
        const persistentId = activeRun.dataset.enpvPersistentId;
        if (persistentId) return persistedAnnotationsById.get(String(persistentId)) || null;
        const sourceUid = sourceInfo?.uid || `${pageIndex}:${activeRun.dataset.enpvIndex || activeRun.dataset.enpvOccurrence || '0'}`;
        return findPersistedAnnotationForSpan(pageIndex, currentRect, original, original, false, sourceUid);
    })();
    const edit = {
        page: parseInt(activeRun.dataset.enpvPage, 10),
        original_text: original,
        new_text: newText,
        occurrence: activeSourceInfo?.occurrence ?? parseInt(activeRun.dataset.enpvOccurrence, 10),
        source_bbox: sourceBboxForSpan(activeRun) || undefined,
    };
    setStatus('Applying edit…');
    editApply.disabled = true;
    try {
        if (isPromotedExtractionAnnotation(existingAnnotation)) {
            await overwritePromotedAnnotationText(existingAnnotation, newText, displayOriginal || original);
            pendingEditCount += 1;
            closeEditor();
            setDownloadButtonsDisabled(false, 'Download PDF');
            setStatus(`Applied ${pendingEditCount} edit${pendingEditCount === 1 ? '' : 's'}.`);
            return;
        }

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
    syncRenderedPersistedOverlayBoxesToPersistedAnnotations();
    const sessionAnnotationsPayload = Array.from(persistedAnnotationsById.values())
        .filter((annotation) => !isRedundantPdfjsSourceOverlay(annotation))
        .filter((annotation) => !isSuppressedStalePdfjsOverlay(annotation))
        .map((annotation) => stripTransientAnnotationFields(annotation))
        .filter((annotation) => shouldIncludeInPdfjsSessionPayload(annotation))
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

async function saveAnnotationStateToDb(options = {}) {
    if (!SAVE_URL) {
        showError('Save endpoint not configured');
        return;
    }
    if (saveInFlight) {
        saveAgainAfterCurrent = true;
        if (options.source !== 'autosave') {
            setSaveStatus('Saving…');
            setStatus('Save already running. Saving latest changes next…');
        }
        return;
    }
    if (autoSaveTimer) {
        window.clearTimeout(autoSaveTimer);
        autoSaveTimer = 0;
    }
    saveInFlight = true;

    try {
        const isAutosave = options.source === 'autosave';
        const preserveEmptyUserCreated = isAutosave;
        syncSelectedBoxToPersistedAnnotations({
            preserveEmptyUserCreated,
            preserveEditMode: isAutosave,
        });
        syncDirtyBoxesToPersistedAnnotations({
            preserveEmptyUserCreated,
            preserveEditMode: isAutosave,
        });
        syncRenderedPersistedOverlayBoxesToPersistedAnnotations();
        const sessionId = getSessionId();
        const annotationsPayload = Array.from(persistedAnnotationsById.values())
            .filter((annotation) => !isRedundantPdfjsSourceOverlay(annotation))
            .filter((annotation) => !isSuppressedStalePdfjsOverlay(annotation))
            .map((annotation) => stripTransientAnnotationFields(annotation))
            .filter((annotation) => shouldIncludeInPdfjsSessionPayload(annotation))
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
                deleted_annotation_ids: Array.from(new Set([
                    ...Array.from(pendingDeletedAnnotationIds),
                    ...Array.from(suppressedStalePdfjsOverlayIds),
                ])),
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
        saveInFlight = false;
        if (saveAgainAfterCurrent && !suppressAutoSaveForNavigation) {
            saveAgainAfterCurrent = false;
            setSaveStatus('Autosaving…');
            setStatus('Saving latest changes…');
            scheduleAutoSave();
        } else if (suppressAutoSaveForNavigation) {
            saveAgainAfterCurrent = false;
        }
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
    loadEmbeddedFontsForDocument().catch((err) => {
        console.warn('Embedded PDF font preload failed', err);
    });
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
    await loadEmbeddedFontsForDocument();
    if (!isCurrentViewerLoad(loadGeneration)) return;
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
    scheduleMovedSourceLiveRedaction();
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
const addTextButtons = [addTextButton, floatingAddTextButton].filter(Boolean);
const addShapeButtons = [addShapeButton, floatingAddShapeButton].filter(Boolean);

function syncShapePanelUi() {
    const active = document.body.classList.contains('enpv-shape-on');
    if (shapeToolPanel) shapeToolPanel.classList.toggle('is-visible', active);
    for (const button of addShapeButtons) {
        button.classList.toggle('active', active);
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
}

function setMoreShapesExpanded(expanded) {
    if (!shapeToolPanel || !shapeMoreToggle) return;
    const active = Boolean(expanded);
    shapeToolPanel.classList.toggle('sfb-extra-collapsed', !active);
    shapeMoreToggle.setAttribute('aria-expanded', active ? 'true' : 'false');
    shapeMoreToggle.classList.toggle('is-active', active);
}

function setShapeMode(on, options = {}) {
    const active = Boolean(on);
    if (active && document.body.classList.contains('enpv-edit-on')) setEditMode(false);
    if (active && document.body.classList.contains('enpv-add-text-on')) setAddTextMode(false);
    if (active && drawModeActive) setDrawMode(false);
    if (!active && shapeCreationState) {
        const pageIndex = shapeCreationState.pageIndex;
        removeShapeCreationPreview(shapeCreationState);
        shapeCreationState = null;
        renderAnnotationBoxLayer(pageIndex);
    }
    document.body.classList.toggle('enpv-shape-on', active);
    syncShapePanelUi();
    if (active) {
        reflectShapeStateToInputs(currentShapeDefaults(), shapeInspectorRefs);
        deselectAnnBox();
        setStatus('Shape mode active. Drag on the PDF to draw a shape.');
    } else if (!document.body.classList.contains('enpv-edit-on') && !document.body.classList.contains('enpv-add-text-on')) {
        setStatus('Ready.');
    }
    if (options.skipRender !== true) {
        renderAllAnnotationBoxLayers();
        scheduleRenderAllAnnotationBoxLayers();
    }
}

function setAddTextMode(on, options = {}) {
    const active = Boolean(on);
    if (active && document.body.classList.contains('enpv-edit-on')) {
        setEditMode(false);
    }
    if (active && document.body.classList.contains('enpv-shape-on')) {
        setShapeMode(false);
    }
    if (active && drawModeActive) {
        setDrawMode(false);
    }
    if (!active) removeAddTextPreview();
    document.body.classList.toggle('enpv-add-text-on', active);
    for (const button of addTextButtons) {
        button.classList.toggle('active', active);
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
    if (active) {
        deselectAnnBox();
        setStatus('Add Text mode active. Click or drag on the PDF to place text.');
    } else if (!document.body.classList.contains('enpv-edit-on')) {
        setStatus('Ready.');
    }
    if (options.skipRender !== true) {
        renderAllAnnotationBoxLayers();
        scheduleRenderAllAnnotationBoxLayers();
    }
}

function setEditMode(on) {
    if (on && document.body.classList.contains('enpv-add-text-on')) {
        setAddTextMode(false);
    }
    if (on && document.body.classList.contains('enpv-shape-on')) {
        setShapeMode(false);
    }
    if (on && drawModeActive) {
        setDrawMode(false);
    }
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

for (const button of addTextButtons) {
    button.addEventListener('click', () => {
        setAddTextMode(!document.body.classList.contains('enpv-add-text-on'));
    });
}

for (const button of addShapeButtons) {
    button.addEventListener('click', () => {
        setShapeMode(!document.body.classList.contains('enpv-shape-on'));
    });
}
syncShapePanelUi();
floatingDrawButton?.addEventListener('click', () => {
    setDrawMode(!drawModeActive);
});
drawToolClose?.addEventListener('click', () => setDrawMode(false));
drawToolButtons.forEach((button) => {
    button.addEventListener('click', () => {
        drawToolType = 'pen';
        syncDrawToolPanelUi();
    });
});
drawColorSwatches.forEach((button) => {
    button.addEventListener('click', () => {
        drawStrokeColor = cssColorToHex(button.dataset.drawColor, drawStrokeColor);
        syncDrawToolPanelUi();
    });
});
drawToolColorInput?.addEventListener('input', () => {
    drawStrokeColor = cssColorToHex(drawToolColorInput.value, drawStrokeColor);
    syncDrawToolPanelUi();
});
drawToolSizeInput?.addEventListener('input', () => {
    drawBrushSize = Math.max(2, Number(drawToolSizeInput.value) || 10);
    syncDrawToolPanelUi();
});
drawToolOpacityInput?.addEventListener('input', () => {
    drawOpacity = clamp01((Number(drawToolOpacityInput.value) || 100) / 100, 1);
    syncDrawToolPanelUi();
});
syncDrawToolPanelUi();

function imageImportRefs() {
    return {
        imageImportModal,
        imageImportFileInput,
        imageImportPreview,
        imageImportPreviewImg,
        imageImportPreviewName,
        imageImportPreviewDims,
        imageImportDropzoneInner,
        imageImportApply,
        imageImportStatus,
    };
}

function setImageImportStatus(text, isError = false) {
    _setImageImportStatus(imageImportStatus, text, isError);
}

function clearImageImportSelection() {
    _clearImageImportSelection(imageImportRefs());
}

function closeImageImportModal() {
    _closeImageImportModal(imageImportRefs());
}

function openImageImportModal() {
    cancelImagePlacement({ showStatus: false });
    setAddTextMode(false, { skipRender: true });
    setShapeMode(false);
    signatureFeature?.cancelSignaturePlacement?.();
    _openImageImportModal(imageImportRefs());
}

function imageImportLoadFile(file) {
    _imageImportLoadFile(file, imageImportRefs());
}

floatingAddImageButton?.addEventListener('click', () => openImageImportModal());
imageImportScrim?.addEventListener('click', () => closeImageImportModal());
imageImportClose?.addEventListener('click', () => closeImageImportModal());
imageImportCancel?.addEventListener('click', () => closeImageImportModal());
imageImportClear?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    clearImageImportSelection();
});
imageImportFileInput?.addEventListener('change', (event) => {
    const file = event.target?.files?.[0];
    if (file) imageImportLoadFile(file);
});
if (imageImportDropzone) {
    imageImportDropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            imageImportFileInput?.click();
        }
    });
    ['dragenter', 'dragover'].forEach((eventName) => {
        imageImportDropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            imageImportDropzone.classList.add('is-drag');
        });
    });
    ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
        imageImportDropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            imageImportDropzone.classList.remove('is-drag');
        });
    });
    imageImportDropzone.addEventListener('drop', (event) => {
        const file = event.dataTransfer?.files?.[0];
        if (file) imageImportLoadFile(file);
    });
}
document.addEventListener('paste', (event) => {
    if (!imageImportModal?.classList.contains('is-open')) return;
    const items = event.clipboardData?.items || [];
    for (const item of items) {
        if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
            const file = item.getAsFile();
            if (file) {
                event.preventDefault();
                imageImportLoadFile(file);
                break;
            }
        }
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && imagePlacementState) {
        cancelImagePlacement();
        return;
    }
    if (event.key === 'Escape' && imageImportModal?.classList.contains('is-open')) {
        closeImageImportModal();
    }
});
imageImportApply?.addEventListener('click', () => {
    if (!imageImportPendingAsset) return;
    try {
        if (!beginImagePlacement(imageImportPendingAsset)) throw new Error('Could not prepare that image.');
    } catch (error) {
        console.error('Image insert failed', error);
        setImageImportStatus(error?.message || 'Could not insert that image.', true);
        return;
    }
    closeImageImportModal();
});
container?.addEventListener('pointerdown', placePendingImageAtPointer, { capture: true });

function commitShapeInspectorToSelectedBox(options = {}) {
    const state = readCurrentShapeState();
    const box = findSelectedBox();
    const existing = persistedAnnotationsById.get(String(box?.dataset?.annotationId || '')) || null;
    if (!box || !isShapeBox(box, existing)) {
        return false;
    }
    if (isAnnBoxLocked(box)) {
        setStatus('Annotation is locked.', true);
        reflectShapeStateToInputs(buildAnnotationFromBox(box, existing) || existing || state, shapeInspectorRefs);
        return false;
    }
    if (options.pushHistory === true) pushHistorySnapshot('change shape style');
    const annotation = buildAnnotationFromBox(box, existing) || existing;
    if (!annotation) return false;
    applyShapeStateToAnnotation(annotation, state);
    applyShapeAnnotationToBoxDataset(box, annotation);
    updateShapeSvgForBox(box, annotation, Number.parseFloat(box.parentElement?.dataset?.scale || '1') || 1);
    upsertPersistedAnnotation(annotation);
    box.classList.add('is-selected');
    selectedAnnBoxUid = box.dataset.uid || selectedAnnBoxUid;
    positionAnnMenuOver(box);
    markManualSaveNeeded();
    return true;
}

function shapeStateForToolButton(rawShapeType) {
    const type = normalizeShapeType(rawShapeType);
    const state = { ...currentShapeDefaults(), shapeType: type };
    if (type === 'checkmark') {
        return {
            ...state,
            strokeColor: '#16a34a',
            strokeOpacity: 1,
            strokeWidth: 4,
            strokeTransparent: false,
            fillTransparent: true,
            fillOpacity: 0,
        };
    }
    if (type === 'x') {
        return {
            ...state,
            strokeColor: '#dc2626',
            strokeOpacity: 1,
            strokeWidth: 4,
            strokeTransparent: false,
            fillTransparent: true,
            fillOpacity: 0,
        };
    }
    if (type === 'heart') {
        return {
            ...state,
            strokeColor: '#dc2626',
            fillColor: '#ef4444',
            strokeOpacity: 1,
            fillOpacity: 1,
            strokeWidth: 3,
            strokeTransparent: false,
            fillTransparent: false,
        };
    }
    if (type === 'arrow') {
        return {
            ...state,
            strokeColor: '#111827',
            strokeOpacity: 1,
            strokeWidth: 4,
            strokeTransparent: false,
            fillTransparent: true,
            fillOpacity: 0,
        };
    }
    return state;
}

shapeTypeButtons.forEach((button) => {
    button.addEventListener('click', () => {
        reflectShapeStateToInputs(shapeStateForToolButton(button.dataset.shapeTool), shapeInspectorRefs);
        commitShapeInspectorToSelectedBox({ pushHistory: true });
    });
});
shapeToolPanel?.classList.add('sfb-extra-collapsed');
shapeMoreToggle?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    setMoreShapesExpanded(shapeMoreToggle.getAttribute('aria-expanded') !== 'true');
});
[
    shapeStrokeColorInput,
    shapeStrokeHexInput,
    shapeStrokeTransparentInput,
    shapeStrokeWidthInput,
    shapeStrokeOpacityInput,
    shapeFillColorInput,
    shapeFillHexInput,
    shapeFillTransparentInput,
    shapeFillOpacityInput,
].filter(Boolean).forEach((input) => {
    input.addEventListener('input', () => {
        if (input === shapeStrokeColorInput && shapeStrokeHexInput) shapeStrokeHexInput.value = shapeStrokeColorInput.value;
        if (input === shapeStrokeHexInput && shapeStrokeColorInput) shapeStrokeColorInput.value = cssColorToHex(shapeStrokeHexInput.value, shapeStrokeColorInput.value);
        if (input === shapeFillColorInput && shapeFillHexInput) shapeFillHexInput.value = shapeFillColorInput.value;
        if (input === shapeFillHexInput && shapeFillColorInput) shapeFillColorInput.value = cssColorToHex(shapeFillHexInput.value, shapeFillColorInput.value);
        if (shapeStrokeWidthValue && shapeStrokeWidthInput) shapeStrokeWidthValue.textContent = `${shapeStrokeWidthInput.value}px`;
        if (shapeStrokeOpacityValue && shapeStrokeOpacityInput) shapeStrokeOpacityValue.textContent = `${shapeStrokeOpacityInput.value}%`;
        if (shapeFillOpacityValue && shapeFillOpacityInput) shapeFillOpacityValue.textContent = `${shapeFillOpacityInput.value}%`;
        commitShapeInspectorToSelectedBox({ pushHistory: false });
    });
    input.addEventListener('change', () => commitShapeInspectorToSelectedBox({ pushHistory: true }));
});

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
