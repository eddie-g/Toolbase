/*
 * Edit-New PDF editor — main entry.
 * Extracted verbatim from resources/views/documents/edit-new.blade.php
 * (the previous inline <script> block).
 *
 * Server-side values (URLs, doc id, CSRF) are imported from ./config.js,
 * which reads them from the `#edit-new-root` data-* attributes — see the
 * blade view.
 */

import { CSRF, INFO_URL, SAVE_URL, DOWNLOAD_URL, DOC_ID } from './config.js';
import { mountProseMirrorSourceEditor } from './editor/prosemirror-source-editor.js';
import {
    fetchDocumentInfo,
    saveAnnotations,
    downloadAnnotatedPdf,
} from './persistence/api.js';
import {
    normalizeFontName,
    fontFileFormat,
    isSubsetEmbeddedFontData,
    shouldBypassEmbeddedFont,
} from './render/font-utils.js';
import { clamp01 } from './util/math.js';
import { normalizeHexColor, hexToRgbaString } from './util/color.js';
import { safeLocalStorageGet, safeLocalStorageSet } from './util/storage.js';
import { getSessionId } from './persistence/session.js';
import { escapeHtml } from './util/html.js';
import { createAutoSave } from './persistence/autosave.js';
import { cloneSerializableValue } from './util/clone.js';
import { createEmbeddedFontRegistry } from './render/embedded-fonts.js';
import {
    isShapeAnnotation,
    isImageBackedAnnotation,
    isDirectDrawAnnotation,
    canRemoveBackgroundFromImageAnnotation,
    normalizeSignatureSourceMode,
    getSignatureAnnotationLabel,
    signatureModeLabel,
    getAnnotationDisplayLabel,
    isBoxAnnotation,
    isTextAnnotation,
    isAnnotationLocked,
    isLineShape,
} from './annotations/types.js';
import { sourceSpanFontWeight, sourceSpanText } from './annotations/source-span-text.js';
import {
    normalizeShapeType,
    defaultPolygonUnitPoints,
    normalizePolygonPointList,
    getShapeUnitVertices,
    pointInPolygon,
    distanceToSegment,
} from './annotations/shape-geometry.js';
import {
    resolveAnnBox,
    resolveSourceAnnBox,
    resolveOriginalAnnBox,
    annotationOffset,
    annotationSourceOffset,
} from './annotations/box.js';
import {
    shouldClampToSourceHeight,
    normalizePromotedAnnotationGeometry,
    setAnnotationBox,
} from './annotations/geometry-writes.js';
import {
    boxesRoughlyEqual,
    constrainAnnotationBoxToPage,
    setAnnotationBoxWithinPage,
} from './annotations/box-constrain.js';
import {
    computeLineBoxGeometry,
    constrainLineEndpointTo45,
    normalizeRotationDegrees,
} from './util/geometry.js';
import {
    isUserAuthoredAnnotation,
    markUserAuthored,
    annTextIsEdited,
    annotationDimensionsChanged,
    annotationPositionChanged,
    normalizeAnnotationTextForDirtyComparison,
    persistRichEditorHtml,
    shouldPersistPromotedDirty,
    shouldPersistPromotedSourceBoxForBackground,
} from './annotations/state.js';
import {
    normalizeShapeAnnotation,
    normalizeImageAnnotation,
    correctedSourceBlockBox,
    applyShapeStateToAnnotation,
} from './annotations/normalize.js';
import {
    getShapePolygonPointsPdf,
    getShapePolygonPointsCanvas,
} from './annotations/polygon-points.js';
import { fontDisplayScale, canvasLogicalHeight, canvasLogicalWidth } from './util/dom-metrics.js';
import {
    overlayDevicePixelRatio,
    overlayZoomMultiplier,
    overlayBackingScale,
    sizeOverlayCanvas,
} from './render/overlay-sizing.js';
import { currentShapeDefaults } from './shapes/defaults.js';
import {
    activateShapeFillFromInspector,
    reflectShapeStateToInputs as _reflectShapeStateToInputs,
    readShapeInspectorState as _readShapeInspectorState,
} from './shapes/inspector-ui.js';
import { drawShapePath } from './shapes/path.js';
import { drawShapeAnnotation } from './shapes/draw-annotation.js';
import { getStickyUiBottomEdge, updatePageCardWidth } from './util/chrome-layout.js';
import { measureTextWidth, ctxFont } from './text/measure-width.js';
import { wrapParagraph } from './text/wrap.js';
import {
    spanMatchesLineBBox,
    buildRenderableLinesFromSpans,
    renderableSourceLines,
} from './annotations/source-lines.js';
import {
    normalizeQuarterTurnDegrees,
    sourceLineRotationDegrees,
    annotationSourceRotationDegrees,
    lineSpans,
    resolveSpanFillStyle,
} from './annotations/source-geometry.js';
import {
    normalizeTextBackgroundColorValue,
    sourceAverageLineHeightPts,
    resolveReflowedTextLineHeightPts,
    normalizeTextAnnotation,
} from './annotations/normalize-text.js';
import {
    sourceTextFallbackFromAnnotation,
    normalizeTextForSourceComparison,
    annotationTextMatchesSource,
} from './annotations/source-text-match.js';
import { resolveDisplayBgColor, WHITE_TEXT_RE } from './annotations/display-bg-color.js';
import { sourceVisualRectPx, sourceVisualOriginPts } from './annotations/source-visual.js';
import { annotationUsesStoredBoxForDisplay } from './annotations/uses-stored-box.js';
import { lineSelectionRectPx, annRectPx } from './annotations/canvas-rect.js';
import {
    ensureAcroPdfLoaded,
    ensureAcroWidgetsForPage,
    updateAcroEntry,
    drawAcroOverlay,
} from './acroform/render.js';
import { traceRoundedRectPath, drawAnnotationTypeBadge } from './render/badge.js';
import {
    crossProduct2d,
    dedupePolygonVertices,
    computePolygonArea,
    clipPolygonAgainstInfiniteLine,
} from './util/polygon-clip.js';
import { sliderValueToFontPt, fontPtToSliderValue, FONT_SLIDER_MIN_PT, FONT_SLIDER_MAX_PT } from './text/font-slider.js';
import { generateAnnotationId } from './annotations/id.js';
import { canvasPointFromEvent } from './util/canvas-point.js';
import { normalizeTextForDomReflow, normalizeRichHtmlForReflow } from './text/dom-reflow.js';
import { editorIsEditingAnnotation } from './editor/is-editing.js';
import {
    activeEditorForPage,
    setEditorEditingAnnotation,
    clearEditorEditingState,
} from './editor/active-editor-element.js';

import {
    getEditorPlainText,
    stripEditorSentinels,
    richHtmlHasInlineSelectionFormatting,
    normalizeRichHtmlForDisplay,
} from './editor/plain-text.js';
import {
    createDrawLayer,
    paintDrawDot,
    paintDrawSegment,
    localPointFromDrawSession,
} from './draw/primitives.js';
import { getImageAnnotationSource } from './annotations/image-source.js';
import {
    getAvailablePageWidth as _getAvailablePageWidth,
    getFitScaleForWidth as _getFitScaleForWidth,
    getAppliedScale,
} from './viewport/scale.js';
import { pickViewportTargetPage } from './viewport/picker.js';
import { getCurrentVisiblePageIndex as _getCurrentVisiblePageIndex } from './viewport/current-page.js';
import { updatePageControls as _updatePageControls } from './viewport/page-controls.js';
import { beginViewportScaleUpdate } from './viewport/scale-update.js';
import { scrollToPage as _scrollToPage } from './viewport/scroll-to-page.js';
import { syncCurrentPageFromScroll as _syncCurrentPageFromScroll } from './viewport/sync-page-from-scroll.js';
import {
    setMarkupToolStatus as _setMarkupToolStatus,
    setMarkupToolDirtyState as _setMarkupToolDirtyState,
    clearMarkupToolCanvas as _clearMarkupToolCanvas,
    clearMarkupToolDrawingState as _clearMarkupToolDrawingState,
    getMarkupToolSmoothingAmount as _getMarkupToolSmoothingAmount,
    hasMarkupToolDrawContent,
    syncMarkupToolLabels as _syncMarkupToolLabels,
    buildCurrentMarkupAsset as _buildCurrentMarkupAsset,
    renderMarkupToolDrawPreview as _renderMarkupToolDrawPreview,
    updateMarkupToolUi as _updateMarkupToolUi,
} from './markup-tool/state.js';
import {
    closeMarkupToolModal as _closeMarkupToolModal,
    setMarkupToolMode as _setMarkupToolMode,
} from './markup-tool/modal.js';
import {
    setSignatureStatus as _setSignatureStatus,
    setSignatureDirtyState as _setSignatureDirtyState,
} from './signature/status.js';
import {
    clearSignatureCanvas as _clearSignatureCanvas,
    clearSignatureDrawingState as _clearSignatureDrawingState,
    getSignatureSmoothingAmount as _getSignatureSmoothingAmount,
    hasSignatureDrawContent,
    renderDrawSignaturePreview as _renderDrawSignaturePreview,
} from './signature/canvas.js';
import {
    syncSignatureColorLabels as _syncSignatureColorLabels,
    updateSignatureModalCopy as _updateSignatureModalCopy,
} from './signature/labels.js';
import { closeSignatureModal as _closeSignatureModal } from './signature/modal.js';
import { cancelSignaturePlacement as _cancelSignaturePlacement } from './signature/placement.js';
import { ensureSignatureFontLoaded } from './signature/font-loader.js';
import {
    setSavedViewActive,
    paintSignatureTabs,
    installSignatureTabKeyboard,
} from './signature/saved-view.js';
import {
    createAccountLibraryStore,
    fetchAccountSignatures,
    saveAccountSignature,
    deleteAccountSignature,
    renderAccountLibrary,
    accountEntryAsAnnotation,
} from './signature/account-library.js';
import { readFileAsDataUrl, loadImageElement } from './util/image-load.js';
import { normalizeImportedImageAsset } from './images/normalize-imported.js';
import { paintSmoothStroke } from './draw/smooth-stroke.js';
import { paintSmoothDrawCurve } from './draw/smooth-curve.js';
import {
    DIRECT_DRAW_TOOL_ERASER,
    DIRECT_DRAW_TOOL_SMOOTH_CURVE,
    normalizeDirectDrawTool,
} from './draw/tool-type.js';
import {
    positionShapeConstrainTip as _positionShapeConstrainTip,
    showShapeConstrainTip as _showShapeConstrainTip,
    hideShapeConstrainTip as _hideShapeConstrainTip,
} from './shape/constrain-tip.js';
import {
    attachShapeConstrainShiftListener as _attachShapeConstrainShiftListener,
    detachShapeConstrainShiftListener as _detachShapeConstrainShiftListener,
} from './shape/constrain-listener.js';
import { cancelShapeCreationPreview as _cancelShapeCreationPreview } from './shape/creation-preview.js';
import { cancelEraseMode as _cancelEraseMode } from './erase-mode/cancel.js';
import { setImageImportStatus as _setImageImportStatus } from './image-import/status.js';
import {
    clearImageImportSelection as _clearImageImportSelection,
    openImageImportModal as _openImageImportModal,
    closeImageImportModal as _closeImageImportModal,
    imageImportLoadFile as _imageImportLoadFile,
} from './image-import/modal.js';
import {
    canCutShapeAnnotation,
    isShapeCutModeArmedFor,
    resetShapeCutState,
    armShapeCutState,
    cancelShapeCutMode as _cancelShapeCutMode,
    armShapeCutMode as _armShapeCutMode,
    toggleShapeCutMode as _toggleShapeCutMode,
} from './shape/cut-mode.js';
import { getActiveEditorSelection } from './editor/active-selection.js';
import { cancelTextCreationPreview } from './interactions/text-creation.js';
import { rawCanvasPointFromEvent } from './util/raw-canvas-point.js';
import {
    getGalleyEditorText,
    expectedGalleyText,
    diffGalleyAdded,
} from './editor/galley/text.js';
import {
    showErrorBanner as _showErrorBanner,
    showSaveToast as _showSaveToast,
} from './ui/banner.js';
import { updateSaveUi as _updateSaveUi } from './ui/save-ui.js';
import { currentCanvasCursor, syncCanvasCursors } from './cursor/canvas-cursor.js';
import {
    setDrawToolStatus as _setDrawToolStatus,
    clearActiveDrawSession,
    cancelDirectDrawPointer,
} from './draw/session.js';
import { syncDrawToolPanelUi as _syncDrawToolPanelUi } from './draw/panel-ui.js';
import {
    renderEditorSpanHtml,
    renderEditorGapHtml,
} from './editor/source-flow/spans.js';
import {
    dbgEscape as _dbgEscape,
    dbgPrettyHtml as _dbgPrettyHtml,
    dbgFormatStyle as _dbgFormatStyle,
} from './editor/debug-modal-format.js';
import { annIsPromotedFromExtraction } from './annotations/promoted.js';
import { shouldRenderTextInRichHtmlLayer, activeEditorCanvasOwnsPaint, markCommitted } from './render/text-routing.js';
import { capturePristineRasterSnapshot, applyCommittedRasterPatches, getCommittedPatchCount } from './render/raster-patches.js';
import { sourceLineRectWithinAnnotation } from './annotations/source-line-rect.js';
import { shapeContainsCanvasPoint, findAnnotationAt } from './annotations/hit-test.js';
import {
    canonicalTextLength,
    domTextOffsetForCanonicalOffset,
    childIndex,
    editorDomPositionToTextOffset,
    getEditorSelectionOffsets,
    findEditorTextPosition,
    setEditorSelectionOffsets,
} from './editor/selection-offsets.js';
import {
    buildAcroFieldLookup,
    normalizeAcroRect,
    normalizeAcroTextColor,
    acroFieldKey,
} from './acroform/normalize.js';
import { normalizeAcroWidget } from './acroform/widget.js';
import {
    loadImageSource,
    toTransparentPngFileName,
    buildWhiteMatteCandidateMask,
    removeWhiteMatteFromImageData,
} from './images/white-matte.js';
import { pdfPtFromClient } from './render/coords.js';
import { populateFontDropdown as populateFontDropdownImpl } from './render/font-dropdown.js';
import {
    configureDragInteractions,
    beginDrag,
    endDrag,
} from './interactions/drag.js';
import {
    configureResizeInteractions,
    beginResize,
    endResize,
} from './interactions/resize.js';
import {
    beginRotate,
    endRotate,
    getRotationCenterClient,
    pointerAngleDeg,
} from './interactions/rotate.js';
import { installPointerDispatcher } from './interactions/pointer-dispatcher.js';
import { activeState, setActiveState, clearActiveState } from './store/active-state.js';
import { hoverState, setHoverState, clearHoverState } from './store/hover-state.js';
import { hasActiveBoxSelection, hasActiveShapeSelection, getActiveAnnAndPage } from './selection/active.js';
import {
    isDirty,
    isSaving,
    isDownloadingPdf,
    setDirty,
    setSaving,
    setDownloadingPdf,
    markDirty,
    markClean,
    configureLifecycle,
} from './store/lifecycle-flags.js';
import {
    editModeEnabled,
    addTextMode,
    shapeMode,
    eraseMode,
    setEditModeEnabledFlag,
    setAddTextModeFlag,
    setShapeModeFlag,
    setEraseModeFlag,
} from './store/editor-modes.js';
import {
    currentZoomPercent,
    suppressEditorAutofit,
    suppressEditorAutofitResetToken,
    setCurrentZoomPercent,
    setSuppressEditorAutofit,
    bumpSuppressEditorAutofitResetToken,
} from './store/zoom-state.js';
import {
    acroFormEntries,
    acroFieldLookup,
    acroWidgetsByPage,
    setAcroFormEntries,
    setAcroFieldLookup,
    setAcroWidgetsByPage,
    setAcroWidgetsForPage,
} from './store/acro-state.js';
import {
    dragState,
    resizeState,
    rotateState,
    textCreationState,
    shapeCreationState,
    shapeCutState,
    setDragState,
    setResizeState,
    setRotateState,
    setTextCreationState,
    setShapeCreationState,
    setShapeCutState,
} from './store/interaction-state.js';
import {
    drawModeActive,
    drawToolType,
    drawStrokeColor,
    drawOpacity,
    drawBrushSize,
    activeDrawSession,
    setDrawModeActive,
    setDrawToolType,
    setDrawStrokeColor,
    setDrawOpacity,
    setDrawBrushSize,
    setActiveDrawSession,
} from './store/draw-tool-state.js';
import {
    currentShapeType,
    currentShapeStrokeColor,
    currentShapeStrokeOpacity,
    currentShapeStrokeWidth,
    currentShapeStrokeTransparent,
    currentShapeFillColor,
    currentShapeFillOpacity,
    currentShapeFillTransparent,
    setCurrentShapeType,
    setCurrentShapeStrokeColor,
    setCurrentShapeStrokeOpacity,
    setCurrentShapeStrokeWidth,
    setCurrentShapeStrokeTransparent,
    setCurrentShapeFillColor,
    setCurrentShapeFillOpacity,
    setCurrentShapeFillTransparent,
} from './store/shape-defaults.js';
import {
    signatureMode,
    signatureDirty,
    signatureDrawing,
    signatureStrokes,
    signatureActiveStroke,
    signatureImageAsset,
    signatureEditTarget,
    signaturePlacementState,
    signatureCtx,
    signatureTypedRenderToken,
    setSignatureMode as setSignatureModeValue,
    setSignatureDirty,
    setSignatureDrawing,
    setSignatureStrokes,
    setSignatureActiveStroke,
    setSignatureImageAsset,
    setSignatureEditTarget,
    setSignaturePlacementState,
    setSignatureCtx,
    bumpSignatureTypedRenderToken,
} from './store/signature-state.js';
import {
    markupToolMode,
    markupToolDirty,
    markupToolDrawing,
    markupToolStrokes,
    markupToolActiveStroke,
    markupToolCtx,
    setMarkupToolMode as setMarkupToolModeValue,
    setMarkupToolDirty,
    setMarkupToolDrawing,
    setMarkupToolStrokes,
    setMarkupToolActiveStroke,
    setMarkupToolCtx,
} from './store/markup-tool-state.js';
import {
    _pdfDoc,
    _acroPdfDoc,
    setPdfDoc,
    setAcroPdfDoc,
} from './store/pdf-docs.js';
import {
    measureCanvas,
    _annClipboard,
    imageImportPendingAsset,
    imageBackgroundRemovalState,
    _formatBarMousedownTs,
    shapeConstrainShiftAttached,
    shapeConstrainUserDismissedTip,
    setMeasureCanvas,
    setAnnClipboard,
    setImageImportPendingAsset,
    setImageBackgroundRemovalState,
    setFormatBarMousedownTs,
    setShapeConstrainShiftAttached,
    setShapeConstrainUserDismissedTip,
} from './store/misc-state.js';
import { pageData } from './store/page-data.js';
import { editedTexts } from './store/edited-texts.js';
import {
    isPhase2Enabled,
    setupPhase2Page,
    installPhase2Hotkeys,
    setPhase2PdfDocument,
} from './editor/phase2-aelayer.js';
import {
    pendingDeletedAnnotationIds,
    pendingDeletedPromotedSourceKeys,
} from './store/pending-deletes.js';
import {
    configureHistory,
    pushUndo,
    performUndo,
    performRedo,
    updateHistoryUi,
} from './history/undo-redo.js';
import {
    configureMeasure,
    measureEditedTextHeightPts,
} from './text/measure.js';

(function () {

    /* ──────────────────────────────────────────────────────────────────────
       Document rename: click the title (or the pencil button) to enter
       inline-edit mode. Enter / blur saves; Escape cancels. The backend
       (DocumentController@rename) normalizes punctuation and preserves the
       original extension, so we trust whatever it returns and reflect it
       back in the title bar + browser tab title.
    ────────────────────────────────────────────────────────────────────── */
    (function setupRename() {
        const wrap = document.getElementById('doc-name-wrap');
        const display = document.getElementById('doc-name-display');
        const input = document.getElementById('doc-name-input');
        const editBtn = document.getElementById('doc-name-edit-btn');
        if (!wrap || !display || !input || !editBtn) return;

        const renameUrl = wrap.getAttribute('data-rename-url') || '';
        let currentName = wrap.getAttribute('data-original-name') || display.textContent || '';
        let saving = false;

        function enterEdit() {
            if (saving) return;
            input.value = currentName;
            display.style.display = 'none';
            editBtn.style.display = 'none';
            input.style.display = '';
            input.focus();
            input.select();
        }

        function exitEdit() {
            input.style.display = 'none';
            display.style.display = '';
            editBtn.style.display = '';
        }

        function setName(newName) {
            currentName = newName;
            display.textContent = newName;
            input.value = newName;
            wrap.setAttribute('data-original-name', newName);
            display.title = 'Click to rename document';
            try { document.title = newName + ' — Edit New'; } catch (_e) {}
        }

        async function saveName() {
            const proposed = (input.value || '').trim();
            if (!proposed || proposed === currentName) {
                exitEdit();
                return;
            }
            if (!renameUrl) { exitEdit(); return; }

            saving = true;
            try {
                const resp = await fetch(renameUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                    },
                    body: JSON.stringify({ name: proposed }),
                });
                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || !data || data.success === false) {
                    const msg = (data && data.message) ? data.message : ('Rename failed (HTTP ' + resp.status + ')');
                    alert(msg);
                    exitEdit();
                    return;
                }
                setName(String(data.original_name || proposed));
                exitEdit();
            } catch (err) {
                alert('Rename failed: ' + (err && err.message ? err.message : String(err)));
                exitEdit();
            } finally {
                saving = false;
            }
        }

        display.addEventListener('click', enterEdit);
        display.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                enterEdit();
            }
        });
        editBtn.addEventListener('click', enterEdit);

        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                saveName();
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                input.value = currentName;
                exitEdit();
            }
        });
        input.addEventListener('blur', () => {
            // Defer to allow Escape keydown handler to clear the value first.
            setTimeout(() => {
                if (input.style.display !== 'none') saveName();
            }, 0);
        });
    })();

    const wrap        = document.getElementById('pages-wrap');
    const errorBanner = document.getElementById('error-banner');
    const saveToast   = document.getElementById('save-toast');
    const saveButton  = document.getElementById('save-btn');
    const downloadPdfButton = document.getElementById('download-pdf-btn');
    const saveStatus  = document.getElementById('save-status');
    const undoButton  = document.getElementById('undo-btn');
    const redoButton  = document.getElementById('redo-btn');
    const editModeToggle = document.getElementById('edit-mode-toggle');
    const editModeToggleLabel = document.getElementById('edit-mode-toggle-label');
    const addTextBtn  = document.getElementById('add-text-btn');
    const addShapeBtn = document.getElementById('add-shape-btn');
    const ftbEditMode = document.getElementById('ftb-edit-mode');
    const ftbSign     = document.getElementById('ftb-sign');
    const ftbAddText  = document.getElementById('ftb-add-text');
    const ftbAddShape = document.getElementById('ftb-add-shape');
    const ftbDrawErase = document.getElementById('ftb-draw-erase');
    const ftbAddImage = document.getElementById('ftb-add-image');
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
    const signatureModal = document.getElementById('signature-modal');
    const markupToolModal = document.getElementById('markup-tool-modal');
    const markupToolModalScrim = document.getElementById('markup-tool-modal-scrim');
    const markupToolModalClose = document.getElementById('markup-tool-modal-close');
    const markupToolTabs = Array.from(document.querySelectorAll('[data-markup-tool-mode]'));
    const markupToolPanels = Array.from(document.querySelectorAll('[data-markup-tool-panel]'));
    const markupToolCanvas = document.getElementById('markup-tool-canvas');
    const markupToolColorInput = document.getElementById('markup-tool-color');
    const markupToolColorValue = document.getElementById('markup-tool-color-value');
    const markupToolWidthInput = document.getElementById('markup-tool-width');
    const markupToolWidthValue = document.getElementById('markup-tool-width-value');
    const markupToolSmoothingInput = document.getElementById('markup-tool-smoothing');
    const markupToolSmoothingValue = document.getElementById('markup-tool-smoothing-value');
    const markupToolHint = document.getElementById('markup-tool-hint');
    const markupToolClearBtn = document.getElementById('markup-tool-clear');
    const markupToolCancelBtn = document.getElementById('markup-tool-cancel');
    const markupToolApplyBtn = document.getElementById('markup-tool-apply');
    const markupToolStatus = document.getElementById('markup-tool-status');
    const signatureModalScrim = document.getElementById('signature-modal-scrim');
    const signatureModalClose = document.getElementById('signature-modal-close');
    const signatureModalTitle = document.getElementById('signature-modal-title');
    const signatureModalSubtitle = signatureModal?.querySelector('.signature-modal__subtitle') || null;
    const signatureTabs = Array.from(document.querySelectorAll('[data-signature-mode]'));
    const signatureViewTabs = Array.from(document.querySelectorAll('[data-signature-view]'));
    const signaturePanels = Array.from(document.querySelectorAll('[data-signature-panel]'));
    const signatureCanvas = document.getElementById('signature-canvas');
    const signatureClearBtn = document.getElementById('signature-clear');
    const signatureCancelBtn = document.getElementById('signature-cancel');
    const signatureApplyBtn = document.getElementById('signature-apply');
    const signatureStatus = document.getElementById('signature-status');
    const signatureHint = document.getElementById('signature-hint');
    const signatureColorInput = document.getElementById('signature-color');
    const signatureColorValue = document.getElementById('signature-color-value');
    const signatureWidthInput = document.getElementById('signature-width');
    const signatureWidthValue = document.getElementById('signature-width-value');
    const signatureSmoothingInput = document.getElementById('signature-smoothing');
    const signatureSmoothingValue = document.getElementById('signature-smoothing-value');
    const signatureTextInput = document.getElementById('signature-text');
    const signatureFontInput = document.getElementById('signature-font');
    const signatureTypeColorInput = document.getElementById('signature-type-color');
    const signatureTypeColorValue = document.getElementById('signature-type-color-value');
    const signatureTypeSizeInput = document.getElementById('signature-type-size');
    const signatureTypeSizeValue = document.getElementById('signature-type-size-value');
    const signatureImageInput = document.getElementById('signature-image-input');
    const signatureImageName = document.getElementById('signature-image-name');
    const signatureAccountSaveBtn = document.getElementById('signature-save-account');
    const signatureAccountList = document.getElementById('signature-account-list');
    const signatureAccountCopy = document.getElementById('signature-account-copy');
    const accountLibrary = createAccountLibraryStore();
    const accountSignaturesUrl = signatureModal?.dataset.accountSignaturesUrl || '';
    // Read lazily rather than captured at install: the session can change
    // under a long-lived editor tab, and it keeps the flag honest.
    const accountSignaturesEnabled = () => signatureModal?.dataset.accountSignatures === '1';
    const shapeToolPanel = document.getElementById('shape-tool-panel');
    const shapeDrawToggle = document.getElementById('shape-draw-toggle');
    const shapeCopyBtn = document.getElementById('shape-copy-btn');
    const shapeDeleteBtn = document.getElementById('shape-delete-btn');
    const shapeModeIndicator = document.getElementById('shape-mode-indicator');
    const shapeToolStatus = document.getElementById('shape-tool-status');
    const shapeTypeButtons = Array.from(document.querySelectorAll('[data-shape-tool]'));
    const drawToolPanel = document.getElementById('draw-tool-panel');
    const drawToolClose = document.getElementById('draw-tool-close');
    const drawToolPenBtn = document.getElementById('draw-tool-pen');
    const drawToolEraserBtn = document.getElementById('draw-tool-eraser');
    const drawToolButtons = Array.from(document.querySelectorAll('[data-draw-direct-tool]'));
    const drawToolSizeInput = document.getElementById('draw-tool-size');
    const drawToolSizeValue = document.getElementById('draw-tool-size-value');
    const drawToolOpacityInput = document.getElementById('draw-tool-opacity');
    const drawToolOpacityValue = document.getElementById('draw-tool-opacity-value');
    const drawToolColorInput = document.getElementById('draw-tool-color');
    const drawColorSwatches = Array.from(document.querySelectorAll('[data-draw-color]'));
    const drawToolStatus = document.getElementById('draw-tool-status');
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
    const zoomLabel   = document.getElementById('zoom-label');
    const annFormatBar  = document.getElementById('ann-format-bar');
    const afbFont       = document.getElementById('afb-font');
    const afbSize       = document.getElementById('afb-size');
    const afbSizeValue  = document.getElementById('afb-size-value');
    const afbTextColor  = document.getElementById('afb-text-color');
    const afbBgColor    = document.getElementById('afb-bg-color');
    const afbOpacity    = document.getElementById('afb-opacity');
    const afbBold       = document.getElementById('afb-bold');
    const afbItalic     = document.getElementById('afb-italic');
    const afbUnderline  = document.getElementById('afb-underline');
    const afbAlign      = document.getElementById('afb-align');
    const afbValign     = document.getElementById('afb-valign');
    const afbCopy       = document.getElementById('afb-copy');
    const afbDelete     = document.getElementById('afb-delete');
    const zoomOutBtn  = document.getElementById('zoom-out');
    const zoomInBtn   = document.getElementById('zoom-in');
    const pageJumpInput = document.getElementById('page-jump');
    const pageTotalLabel = document.getElementById('page-total');
    const pagePrevBtn = document.getElementById('page-prev');
    const pageNextBtn = document.getElementById('page-next');

    // ── Session ID (persisted per-document so saves survive reload) ───────────
    // getSessionId() lives in ./persistence/session.js so any module can
    // request the per-document session id without depending on main.js.
    const ZOOM_MIN_PERCENT = 50;
    const ZOOM_MAX_PERCENT = 400;
    const ZOOM_STEP_PERCENT = 30;
    const initialZoomPercent = window.innerWidth < 768 ? 100 : 130;

    // ── Module-level state ─────────────────────────────────────────────────────
    // _pdfDoc + _acroPdfDoc moved to ./store/pdf-docs.js (Phase 5l).
    // pageData moved to ./store/page-data.js (Phase 5.5a).
    //   pi → { wPts, hPts, scale, canvasWidth, canvasHeight, annotations }
    // editedTexts moved to ./store/edited-texts.js (Phase 7b).
    // acroFormEntries / acroFieldLookup / acroWidgetsByPage moved to
    // ./store/acro-state.js (Phase 5f).

    // activeState moved to ./store/active-state.js (Phase 5a). Reads use
    // the imported live binding directly; reassignments go through
    // setActiveState/clearActiveState.
    // hoverState moved to ./store/hover-state.js (Phase 5b). Reads use
    // the imported live binding; writes go through setHoverState/
    // clearHoverState.
    // dragState / resizeState / rotateState / textCreationState /
    // shapeCreationState / shapeCutState moved to
    // ./store/interaction-state.js (Phase 5g).
    // isDirty / isSaving / isDownloadingPdf moved to ./store/lifecycle-flags.js (Phase 5c).
    // editModeEnabled / addTextMode / shapeMode / eraseMode moved to
    // ./store/editor-modes.js (Phase 5d).
    // signature* + savedSignatureLibrary + signaturePlacementState moved to
    // ./store/signature-state.js (Phase 5j).
    // drawModeActive / drawToolType / drawStrokeColor / drawOpacity /
    // drawBrushSize / activeDrawSession moved to ./store/draw-tool-state.js
    // (Phase 5h).
    // imageBackgroundRemovalState moved to ./store/misc-state.js (Phase 5m).
    // markupTool* moved to ./store/markup-tool-state.js (Phase 5k).
    // eraseMode moved to ./store/editor-modes.js (Phase 5d).
    // currentZoomPercent / suppressEditorAutofit / suppressEditorAutofitResetToken
    // moved to ./store/zoom-state.js (Phase 5e). Initial value seeded below.
    setCurrentZoomPercent(initialZoomPercent);
    // currentShape* defaults moved to ./store/shape-defaults.js (Phase 5i).

    // measureCanvas moved to ./store/misc-state.js (Phase 5m).
    // Embedded-PDF-font registry. Owns the @font-face injection, the
    // per-family async health check, and the broken-key set. The registry
    // is wired below (after shouldBypassEmbeddedFont and fontFileFormat are
    // in scope via imports) and queried from fallbackFontFamily /
    // populateFontDropdown via getEmbeddedFonts() and isBroken().
    // See /memories/repo/edit-new-broken-runtime-extracted-fonts.md.
    const embeddedFontRegistry = createEmbeddedFontRegistry({
        shouldBypassEmbeddedFont,
        fontFileFormat,
        onValidationChange: () => {
            // Force a re-render so previously-invisible spans repaint with the
            // system fallback now that fallbackFontFamily will skip the broken
            // families.
            try {
                if (typeof redrawAllOverlays === 'function') redrawAllOverlays();
            } catch (_) {}
        },
    });
    const loadEmbeddedFontFaces = embeddedFontRegistry.loadFaces;
    // markupToolCtx moved to ./store/markup-tool-state.js (Phase 5k).
    // signatureCtx + signatureTypedRenderToken moved to
    // ./store/signature-state.js (Phase 5j).
    // pendingDeletedAnnotationIds + pendingDeletedPromotedSourceKeys moved to
    // ./store/pending-deletes.js (Phase 7c).
    const annotationImageCache = new Map();
    // shapeHitTestCanvas / shapeHitTestCtx moved into
    // ./annotations/hit-test.js as module-private singletons (Phase 7am).
    // signatureFontLoadPromises moved into ./signature/font-loader.js (Phase 7ch).

    // Annotation type predicates + label helpers live in ./annotations/types.js.
    // Shape geometry helpers (normalizeShapeType, defaultPolygonUnitPoints,
    // normalizePolygonPointList, getShapeUnitVertices, pointInPolygon,
    // distanceToSegment) live in ./annotations/shape-geometry.js.

    // normalizeShapeAnnotation, normalizeImageAnnotation moved to ./annotations/normalize.js.

    // normalizeTextBackgroundColorValue, sourceAverageLineHeightPts,
    // resolveReflowedTextLineHeightPts, normalizeTextAnnotation moved
    // to ./annotations/normalize-text.js (Phase 7n).

    // getImageAnnotationSource moved to ./annotations/image-source.js (Phase 7ao).

    // cloneSerializableValue lives in ./util/clone.js — imported at the top of the file.

    function getCachedAnnotationImage(ann) {
        if (!isImageBackedAnnotation(ann)) return null;
        const src = getImageAnnotationSource(ann);
        if (!src) return null;
        let cached = annotationImageCache.get(src);
        if (!cached) {
            const img = new Image();
            cached = { img, loaded: false, failed: false };
            img.onload = () => {
                cached.loaded = true;
                redrawAllOverlays();
                if (activeState.uid === ann?._uid) syncActiveEditor(true);
            };
            img.onerror = () => {
                cached.failed = true;
            };
            img.src = src;
            annotationImageCache.set(src, cached);
        }
        if (cached.failed || !cached.loaded) return null;
        return cached.img;
    }

    // loadImageSource / toTransparentPngFileName / buildWhiteMatteCandidateMask /
    // removeWhiteMatteFromImageData moved to ./images/white-matte.js (Phase 7o).

    async function buildBackgroundRemovedImageAsset(ann) {
        const src = getImageAnnotationSource(ann);
        if (!src) throw new Error('Missing image source.');
        const img = getCachedAnnotationImage(ann) || await loadImageSource(src);
        const width = Math.max(1, Number(ann?.intrinsicWidth) || img.naturalWidth || img.width || 1);
        const height = Math.max(1, Number(ann?.intrinsicHeight) || img.naturalHeight || img.height || 1);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) throw new Error('Could not prepare the image for editing.');
        ctx.clearRect(0, 0, width, height);
        ctx.drawImage(img, 0, 0, width, height);
        const imageData = ctx.getImageData(0, 0, width, height);
        const result = removeWhiteMatteFromImageData(imageData);
        if (!result.changed) {
            throw new Error('No removable white background was detected.');
        }
        ctx.putImageData(imageData, 0, 0);
        return {
            dataUrl: canvas.toDataURL('image/png'),
            width,
            height,
            fileName: toTransparentPngFileName(ann?.fileName || 'image.png'),
            mimeType: 'image/png',
        };
    }

    // computeLineBoxGeometry lives in ./util/geometry.js.

    async function removeBackgroundFromImageAnnotation(ann, pi) {
        if (!canRemoveBackgroundFromImageAnnotation(ann)) return false;
        if (isAnnotationLocked(ann)) return false;
        if (imageBackgroundRemovalState.active) return false;
        setImageBackgroundRemovalState({ active: true, uid: ann._uid });
        syncActiveEditor(true);
        try {
            const asset = await buildBackgroundRemovedImageAsset(ann);
            replaceImageBackedAnnotation(ann, pi, asset);
            showToast('Background removed');
            return true;
        } finally {
            setImageBackgroundRemovalState({ active: false, uid: null });
            syncActiveEditor(true);
        }
    }


    // currentShapeDefaults moved to ./shapes/defaults.js (Phase 7q).

    // hasActiveBoxSelection / hasActiveShapeSelection moved to ./selection/active.js (Phase 7h).

    function setAnnotationLocked(ann, pi, locked) {
        if (!ann) return;
        const nextLocked = Boolean(locked);
        if (isAnnotationLocked(ann) === nextLocked) return;
        pushUndo();
        ann.locked = nextLocked;
        // Note: do NOT call markUserAuthored() here. Locking must only prevent
        // move/resize/edit; it must not promote an extracted annotation into
        // "user-authored" mode, because that flips text rendering from the
        // canvas/PDF-backed path to the rich-html DOM layer (via
        // shouldRenderTextInRichHtmlLayer), which causes extracted text to
        // disappear from the page when locked. The locked flag is persisted
        // independently in buildPersistedAnnotationPayload (payload.locked).
        if (ann.annotation_data && typeof ann.annotation_data === 'object') {
            ann.annotation_data.locked = nextLocked;
        }
        redrawOverlay(pi);
        syncActiveEditor(true);
        markDirty();
    }

    function moveAnnotationLayer(ann, pi, direction) {
        const data = pageData[pi];
        if (!data || !ann || !isBoxAnnotation(ann)) return false;
        if (isAnnotationLocked(ann)) return false;
        const idx = data.annotations.indexOf(ann);
        if (idx < 0) return false;

        if (direction === 'front') {
            if (idx === data.annotations.length - 1) return false;
            pushUndo();
            data.annotations.splice(idx, 1);
            data.annotations.push(ann);
        } else if (direction === 'back') {
            if (idx === 0) return false;
            pushUndo();
            data.annotations.splice(idx, 1);
            data.annotations.unshift(ann);
        } else {
            return false;
        }

        redrawOverlay(pi);
        syncActiveEditor(true);
        markDirty();
        return true;
    }

    // getStickyUiBottomEdge moved to ./util/chrome-layout.js (Phase 7r).

    // ── Undo / Redo history ────────────────────────────────────────────────────
    // pushUndo / performUndo / performRedo / updateHistoryUi / captureSnapshot /
    // applySnapshot / undoStack / redoStack / HISTORY_LIMIT all live in
    // ./history/undo-redo.js (Phase 7d). Wired via configureHistory() above.
    // Leftover local copies (which shadowed the imports inside this IIFE and
    // silently broke debug-modal / format-bar / etc. undo) were removed.

    // getAvailablePageWidth, getFitScaleForWidth, getAppliedScale moved to
    // ./viewport/scale.js (Phase 7aq). The two width-dependent helpers are
    // re-bound here as zero-arg wrappers so call sites don't have to thread
    // the wrap container through every invocation.
    const getAvailablePageWidth = () => _getAvailablePageWidth(wrap);
    const getFitScaleForWidth = (pageWidthPts) => _getFitScaleForWidth(pageWidthPts, wrap);

    // fontDisplayScale moved to ./util/dom-metrics.js.

    // updatePageCardWidth moved to ./util/chrome-layout.js (Phase 7r).

    // ── HiDPI overlay support ────────────────────────────────────────────────
    // The overlay canvas renders vector shapes (ellipses, strokes, polygons).
    // Two factors stretch its visible size beyond its backing store unless
    // accounted for here:
    //   1. devicePixelRatio (HiDPI / retina) — physical pixel density.
    //   2. currentZoomPercent — applied as pure-CSS upscale on the parent
    //      page card (`--scale` var). Without compensating, zoom > 100 turns
    //      every drawn pixel into 2× / 3× browser-bilinear-upscaled pixels =
    //      visible blur on curves and strokes.
    // We keep `data.scale` and the rest of the drawing/hit-testing math in
    // FIT-scale CSS-pixel space (so every existing call site is unchanged),
    // and absorb both factors into the canvas backing store + the
    // `setTransform` we apply at the start of `redrawOverlay`.
    // overlayDevicePixelRatio / overlayZoomMultiplier / overlayBackingScale /
    // sizeOverlayCanvas moved to ./render/overlay-sizing.js (Phase 7p).

    // canvasLogicalWidth moved to ./util/dom-metrics.js (Phase 5.5b).

    // canvasLogicalHeight moved to ./util/dom-metrics.js.

    function applyPageScale(pi) {
        const data = pageData[pi];
        const pageEl = document.getElementById('pc-' + (pi + 1));
        if (!data || !pageEl) return;
        data.fitScale = getFitScaleForWidth(data.wPts);
        const fitScale = data.fitScale || 1;
        const zoomMult = (Number(currentZoomPercent) || 100) / 100;

        // Pure CSS zoom: the canvas/page is rasterized once at fitScale, and
        // the user's zoom level is applied as a CSS transform on top. This
        // grows/shrinks the entire page (background + annotations + text) in
        // lockstep, exactly like browser zoom — no re-rasterization, no
        // re-laying-out of annotations on zoom changes.
        pageEl.style.setProperty('--scale', fitScale);
        pageEl.style.transformOrigin = 'top left';
        pageEl.style.transform = zoomMult === 1 ? '' : `scale(${zoomMult})`;
        // Reserve layout space for the scaled element so siblings/scroll work.
        const baseW = data.wPts * fitScale;
        const baseH = data.hPts * fitScale;
        pageEl.style.marginRight = `${baseW * (zoomMult - 1)}px`;
        pageEl.style.marginBottom = `${baseH * (zoomMult - 1)}px`;

        // Sync the canvas bitmap to fitScale (only re-rasterize when fitScale
        // itself changed, e.g. window resize). Zoom changes are pure CSS,
        // BUT the backing-store scale (dpr × zoom) does still need updating
        // on zoom so curves don't blur — see sizeOverlayCanvas /
        // overlayBackingScale.
        const newW = Math.round(data.wPts * fitScale);
        const newH = Math.round(data.hPts * fitScale);
        const fitDimsChanged = (data.canvasWidth !== newW || data.canvasHeight !== newH);
        const oc = document.getElementById('oc-' + (pi + 1));
        const desiredBacking = overlayBackingScale();
        const backingChanged = oc && (oc.__backingScale ?? null) !== desiredBacking;
        if (newW > 0 && newH > 0 && (fitDimsChanged || backingChanged)) {
            data.scale = fitScale;
            data.canvasWidth = newW;
            data.canvasHeight = newH;
            updatePageCardWidth(pi, newW);
            const ac = document.getElementById('ac-' + (pi + 1));
            if (oc) sizeOverlayCanvas(oc, newW, newH);
            if (ac) {
                ac.style.width = `${newW}px`;
                ac.style.height = `${newH}px`;
            }
            redrawOverlay(pi);
            drawAcroOverlay(pi);
            if (activeState.pi === pi) syncActiveEditor();
        }
    }

    function applyAllPageScales() {
        Object.keys(pageData).forEach((key) => applyPageScale(Number(key)));
    }

    // ── Zoom-aware page-canvas rerender ────────────────────────────────────
    // The page canvas is initially rasterized at renderScale=2. As the user
    // zooms in, the CSS transform scales that bitmap up — past a point, the
    // text gets soft. This re-rasterizes the canvas at a higher renderScale
    // so the page stays crisp at any zoom level.
    //
    // Strategy: target renderScale = ceil(fitScale * zoomMult * dpr), with a
    // floor of 2 (the default initial scale) and a ceiling to bound memory.
    // Re-render only when the target scale exceeds the current scale (i.e.
    // we never *down*-rasterize on zoom-out — the user might zoom back in).
    const _pageRerenderTokens = new Map();          // pi -> incrementing token (cancels stale)
    const _pageRerenderInflight = new Map();        // pi -> promise
    const PAGE_RENDER_SCALE_FLOOR = 2;
    const PAGE_RENDER_SCALE_CEIL = 6;               // ~9MP per US-letter page
    function targetPageRenderScale(pi) {
        const data = pageData[pi];
        if (!data) return PAGE_RENDER_SCALE_FLOOR;
        const fitScale = data.fitScale || 1;
        const zoomMult = (Number(currentZoomPercent) || 100) / 100;
        const dpr = Math.max(1, Number(window.devicePixelRatio) || 1);
        const raw = fitScale * zoomMult * dpr;
        return Math.max(PAGE_RENDER_SCALE_FLOOR, Math.min(PAGE_RENDER_SCALE_CEIL, Math.ceil(raw)));
    }
    async function rerenderPageCanvasAtZoom(pi) {
        const data = pageData[pi];
        const pdfPage = data?.pdfPage;
        const pageCanvas = document.getElementById('en-canvas-' + (pi + 1));
        if (!pdfPage || !pageCanvas) return;
        const target = targetPageRenderScale(pi);
        const current = pageCanvas.__renderScale || PAGE_RENDER_SCALE_FLOOR;
        if (target <= current) return;              // already crisp enough
        // Cancel any older inflight rerender for this page.
        const token = (_pageRerenderTokens.get(pi) || 0) + 1;
        _pageRerenderTokens.set(pi, token);
        try {
            const wPts = data.wPts;
            const hPts = data.hPts;
            const newW = Math.round(wPts * target);
            const newH = Math.round(hPts * target);
            // Render into an off-screen canvas first so the visible canvas
            // stays painted (no flash to white) until the new bitmap is ready.
            const off = document.createElement('canvas');
            off.width = newW;
            off.height = newH;
            const renderTask = pdfPage.render({
                canvasContext: off.getContext('2d'),
                viewport: pdfPage.getViewport({ scale: target }),
                annotationMode: typeof pdfjsLib?.AnnotationMode?.DISABLE === 'number'
                    ? pdfjsLib.AnnotationMode.DISABLE
                    : 0,
            });
            await renderTask.promise;
            // Bail if a newer rerender request started while we were waiting.
            if (_pageRerenderTokens.get(pi) !== token) return;
            pageCanvas.width = newW;
            pageCanvas.height = newH;
            pageCanvas.__renderScale = target;
            pageCanvas.getContext('2d').drawImage(off, 0, 0);
            // Stage 3: refresh pristine snapshot at the new resolution and
            // re-apply any committed patches so committed annotations stay
            // raster-erased after a zoom-induced re-rasterisation.
            capturePristineRasterSnapshot(pageCanvas);
            applyCommittedRasterPatches(pageCanvas, pageData[pi]);
        } catch (e) {
            // Render can throw if the page is destroyed mid-flight; harmless.
            if (e && e.name !== 'RenderingCancelledException') {
                console.warn('[zoom-rerender] page ' + (pi + 1) + ' failed', e);
            }
        }
    }
    let _zoomRerenderTimer = null;
    function scheduleZoomRerender() {
        if (_zoomRerenderTimer) clearTimeout(_zoomRerenderTimer);
        // Debounce so rapid zoom-button clicks coalesce into one render pass
        // per page. 180ms is short enough to feel responsive but long enough
        // to drop redundant intermediate scales.
        _zoomRerenderTimer = setTimeout(() => {
            _zoomRerenderTimer = null;
            for (const key of Object.keys(pageData)) {
                const pi = Number(key);
                if (_pageRerenderInflight.get(pi)) continue;
                const p = rerenderPageCanvasAtZoom(pi).finally(() => {
                    _pageRerenderInflight.delete(pi);
                });
                _pageRerenderInflight.set(pi, p);
            }
        }, 180);
    }

    // beginViewportScaleUpdate moved to ./viewport/scale-update.js (Phase 7cg).

    function updateZoom(value) {
        const zoomValue = Math.max(ZOOM_MIN_PERCENT, Math.min(ZOOM_MAX_PERCENT, parseInt(value, 10)));
        setCurrentZoomPercent(zoomValue);
        if (zoomLabel) zoomLabel.textContent = `${zoomValue}%`;
        beginViewportScaleUpdate();
        applyAllPageScales();
        scheduleZoomRerender();
    }

    // updatePageControls moved to ./viewport/page-controls.js (Phase 7bo).
    const updatePageControls = (totalPages = 1) => _updatePageControls({ pageTotalLabel, pageJumpInput }, totalPages);

    // scrollToPage moved to ./viewport/scroll-to-page.js (Phase 7bp).
    const scrollToPage = (pageNumber) => _scrollToPage(pageJumpInput, pageNumber);

    // syncCurrentPageFromScroll moved to ./viewport/sync-page-from-scroll.js (Phase 7bq).
    const syncCurrentPageFromScroll = () => _syncCurrentPageFromScroll(pageJumpInput);

    // performUndo / performRedo moved to ./history/undo-redo.js (Phase 7d).

    // ── Font helpers ───────────────────────────────────────────────────────────
    // NOTE: Use SINGLE quotes around multi-word family names. These values are interpolated
    // into HTML `style="…"` attributes; embedding double quotes there truncates the attribute
    // and causes the entire inline style (font-family, font-size, etc.) to be dropped, so the
    // contenteditable falls back to body defaults — which breaks edit-mode parity with the
    // canvas rendering and with the format-bar font-size slider.
    const FONT_MAP = {
        // Stage1 font-metrics fix: prepend metrics-compatible Linux/cross-platform
        // families (Liberation Sans/Serif/Mono, Arimo/Tinos/Cousine) before the
        // platform Arial/Times/Courier names. These are designed to share the
        // exact glyph advance widths of Arial / Times New Roman / Courier New so
        // when the embedded PDF face is missing or broken the DOM rich-html-layer
        // wraps at the same word boundaries as the underlying PDF raster
        // background. Without these, Linux falls back to DejaVu (visibly wider
        // metrics) and edited blocks reflow differently from the surrounding
        // pristine text.
        Arial:         "'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Arial,Bold':  "'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        ArialMT:       "'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Arial-BoldMT':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Arial-ItalicMT':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Arial-BoldItalicMT':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Helvetica-Bold':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Helvetica-Oblique':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        'Helvetica-BoldOblique':"'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        Helvetica:     "'Liberation Sans', Arimo, Arial, Helvetica, sans-serif",
        FreeSans:      "'Liberation Sans', Arial, Helvetica, sans-serif",
        FreeSerif:     "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        FreeMono:      "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        Times:         "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        TimesRoman:    "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'Times-Roman': "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'Times-Bold':  "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'Times-Italic':"'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'Times-BoldItalic':"'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'Times New Roman': "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        TimesNewRoman: "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        TimesNewRomanPS: "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        TimesNewRomanPSMT: "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'TimesNewRomanPS-BoldMT': "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'TimesNewRomanPS-ItalicMT': "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        'TimesNewRomanPS-BoldItalicMT': "'Liberation Serif', Tinos, 'Times New Roman', Times, serif",
        Courier:       "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'Courier-Bold':"'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'Courier-Oblique':"'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'Courier-BoldOblique':"'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        CourierNew:    "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        CourierNewPSMT: "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'CourierNewPS-BoldMT': "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'CourierNewPS-ItalicMT': "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        'CourierNewPS-BoldItalicMT': "'Liberation Mono', Cousine, 'Courier New', Courier, monospace",
        Verdana:       'Verdana, Geneva, sans-serif',
        Tahoma:        'Tahoma, Geneva, sans-serif',
        DejaVuSans:    "'DejaVu Sans', Arial, Helvetica, sans-serif",
        DejaVuSerif:   "'DejaVu Serif', 'Times New Roman', serif",
        // ── Popular Google Fonts (loaded via the @import in <head>) ────────
        Roboto:           "'Roboto', Arial, Helvetica, sans-serif",
        OpenSans:         "'Open Sans', Arial, Helvetica, sans-serif",
        Lato:             "'Lato', Arial, Helvetica, sans-serif",
        Montserrat:       "'Montserrat', Arial, Helvetica, sans-serif",
        Poppins:          "'Poppins', Arial, Helvetica, sans-serif",
        SourceSansPro:    "'Source Sans 3', 'Source Sans Pro', Arial, Helvetica, sans-serif",
        Inter:            "'Inter', Arial, Helvetica, sans-serif",
        Nunito:           "'Nunito', Arial, Helvetica, sans-serif",
        Raleway:          "'Raleway', Arial, Helvetica, sans-serif",
        WorkSans:         "'Work Sans', Arial, Helvetica, sans-serif",
        NotoSans:         "'Noto Sans', Arial, Helvetica, sans-serif",
        NotoSerif:        "'Noto Serif', Georgia, 'Times New Roman', serif",
        Merriweather:     "'Merriweather', Georgia, 'Times New Roman', serif",
        PlayfairDisplay:  "'Playfair Display', Georgia, 'Times New Roman', serif",
        Oswald:           "'Oswald', Impact, Arial, sans-serif",
        RobotoSlab:       "'Roboto Slab', Georgia, 'Times New Roman', serif",
        RobotoMono:       "'Roboto Mono', Consolas, 'Courier New', monospace",
        RobotoCondensed:  "'Roboto Condensed', Arial, Helvetica, sans-serif",
        Ubuntu:           "'Ubuntu', Arial, Helvetica, sans-serif",
        Rubik:            "'Rubik', Arial, Helvetica, sans-serif",
        DMSans:           "'DM Sans', Arial, Helvetica, sans-serif",
        Mulish:           "'Mulish', Arial, Helvetica, sans-serif",
        Quicksand:        "'Quicksand', Arial, Helvetica, sans-serif",
        Kanit:            "'Kanit', Arial, Helvetica, sans-serif",
        FiraSans:         "'Fira Sans', Arial, Helvetica, sans-serif",
        Lora:             "'Lora', Georgia, 'Times New Roman', serif",
        Cabin:            "'Cabin', Arial, Helvetica, sans-serif",
        Heebo:            "'Heebo', Arial, Helvetica, sans-serif",
        Karla:            "'Karla', Arial, Helvetica, sans-serif",
        Manrope:          "'Manrope', Arial, Helvetica, sans-serif",
        JosefinSans:      "'Josefin Sans', Arial, Helvetica, sans-serif",
        Dosis:            "'Dosis', Arial, Helvetica, sans-serif",
        Barlow:           "'Barlow', Arial, Helvetica, sans-serif",
        BebasNeue:        "'Bebas Neue', Impact, Arial, sans-serif",
        PTSans:           "'PT Sans', Arial, Helvetica, sans-serif",
        CrimsonText:      "'Crimson Text', Georgia, 'Times New Roman', serif",
        Hind:             "'Hind', Arial, Helvetica, sans-serif",
        Mukta:            "'Mukta', Arial, Helvetica, sans-serif",
    };

    const STATIC_PDF_FONT_FACES = [
        { family: 'HelveticaNeueLTStd-Roman', weight: '400', style: 'normal' },
        { family: 'HelveticaNeueLTStd', weight: '400', style: 'normal' },
    ];

    // loadEmbeddedFontFaces + the async health-check live in
    // ./render/embedded-fonts.js. The registry is constructed near the top of
    // this file (search for `createEmbeddedFontRegistry`).

    // populateFontDropdown moved to ./render/font-dropdown.js.
    const populateFontDropdown = () => populateFontDropdownImpl(embeddedFontRegistry);

    const systemFallbackFontFamily = (name, family = '') => {
        const raw = normalizeFontName(name || family);
        if (!raw) return FONT_MAP['Helvetica'];
        const key = raw.replace(/['"]/g, '').trim();
        if (FONT_MAP[key]) return FONT_MAP[key];
        const simple = key.split(/[-_,]/)[0];
        if (FONT_MAP[simple]) return FONT_MAP[simple];
        // Use single-quotes so the value is safe to interpolate into style="…" HTML attributes
        // without truncating the attribute at the first embedded double quote.
        return `'${key}', ${FONT_MAP['Helvetica']}`;
    };

    const fallbackFontFamily = (name, family = '') => {
        const rawExact = normalizeFontName(name);
        const rawFamily = normalizeFontName(family);

        const overlayEmbeddedFonts = embeddedFontRegistry.getEmbeddedFonts();
        if (overlayEmbeddedFonts) {
            const entries = Object.entries(overlayEmbeddedFonts);
            if (rawExact) {
                let exactMatchedButBroken = false;
                for (const [fontKey, fontData] of entries) {
                    const cleanName = String(fontData?.clean_name || fontKey || '').trim();
                    const embeddedFamily = String(fontData?.family || fontKey || '').trim();
                    if (!cleanName || shouldBypassEmbeddedFont(cleanName, embeddedFamily, fontData)) continue;
                    if (normalizeFontName(cleanName).toLowerCase() === rawExact.toLowerCase()) {
                        if (embeddedFontRegistry.isBroken(cleanName)) {
                            // Exact-name match exists but its outlines are
                            // broken. Don't fall through to a same-family
                            // entry of the wrong weight (e.g. picking the
                            // regular-weight PDF_Arial when the broken one
                            // was Arial-BoldMT) — that would either render
                            // wrong-weight glyphs or silently nothing.
                            exactMatchedButBroken = true;
                            break;
                        }
                        const fallback = systemFallbackFontFamily('', embeddedFamily || cleanName);
                        return `'PDF_${cleanName}', ${fallback}`;
                    }
                }
                if (exactMatchedButBroken) {
                    return systemFallbackFontFamily(name, family);
                }
            }

            if (rawFamily) {
                for (const [fontKey, fontData] of entries) {
                    const cleanName = String(fontData?.clean_name || fontKey || '').trim();
                    const embeddedFamily = String(fontData?.family || fontKey || '').trim();
                    if (!cleanName || !embeddedFamily || shouldBypassEmbeddedFont(cleanName, embeddedFamily, fontData)) continue;
                    if (embeddedFontRegistry.isBroken(cleanName)) continue;
                    if (normalizeFontName(embeddedFamily).toLowerCase() === rawFamily.toLowerCase()) {
                        const fallback = systemFallbackFontFamily('', embeddedFamily);
                        return `'PDF_${cleanName}', ${fallback}`;
                    }
                }
            }
        }

        return systemFallbackFontFamily(name, family);
    };

    // resolveDisplayBgColor moved to ./annotations/display-bg-color.js (Phase 7u).

    // ensureMeasureCtx / ctxFont / measureTextWidth moved to ./text/measure-width.js (Phase 7j).

    // buildAcroFieldLookup / normalizeAcroRect / normalizeAcroTextColor /
    // acroFieldKey moved to ./acroform/normalize.js (Phase 7v).

    // ensureAcroPdfLoaded / ensureAcroWidgetsForPage / updateAcroEntry /
    // drawAcroOverlay moved to ./acroform/render.js (Phase 7aa).

    // Annotation box resolution (resolveAnnBox, resolveSourceAnnBox,
    // resolveOriginalAnnBox, annotationOffset, annotationSourceOffset)
    // lives in ./annotations/box.js.

    // For a promoted-from-extraction annotation whose visible text still
    // matches the source PDF, return the original source-block placement
    // (top-left + width/height in source PDF y-down coords). Used by the
    // canvas erase rect and the rich-html DOM layer to override a bloated
    // pdfHeight that would otherwise push the rendered text up by one or
    // more line-heights and overlap the annotation above. We never mutate
    // the stored annotation; this is render-only correction.
    // correctedSourceBlockBox moved to ./annotations/normalize.js.



    // shouldClampToSourceHeight, normalizePromotedAnnotationGeometry, and
    // setAnnotationBox moved to ./annotations/geometry-writes.js (Phase 7a).

    // boxesRoughlyEqual / constrainAnnotationBoxToPage / setAnnotationBoxWithinPage
    // moved to ./annotations/box-constrain.js (Phase 7m).

    // ── User-authored annotation flag ──────────────────────────────────────────
    // Approach-1 model: a promoted-from-extraction annotation is rendered using
    // the exact original PDF geometry (sourceBlock*, sourceSpans, sourceLineBBoxes)
    // UNTIL the user makes a meaningful modification (resize, text edit, style
    // change, rich formatting). After that, the annotation is permanently treated
    // as a simple user-authored text box: reflow to box bounds, annotation-level
    // font/size/color, no exact-preserve fallback.
    //
    // Triggers that flip `_userAuthored = true` (one way — never reset in session):
    //   - setAnnotationBox on a width/height change (resize)
    //   - ae input event (text change)
    //   - applyFormatProperty / applySelectionFormat (style change)
    //
    // Persistence: flagged onto `userAuthored` in the saved payload; the load
    // hydrator restores `_userAuthored` from `userAuthored` OR `promotedDirty`
    // (legacy flag already persisted).

    // sourceVisualRectPx / sourceVisualOriginPts moved to ./annotations/source-visual.js (Phase 7x).

    // annotationUsesStoredBoxForDisplay moved to ./annotations/uses-stored-box.js (Phase 7y).

    // lineSelectionRectPx / annRectPx moved to ./annotations/canvas-rect.js (Phase 7z).

    // drawShapePath moved to ./shapes/path.js (Phase 7s).
    // drawShapeAnnotation moved to ./shapes/draw-annotation.js (Phase 7cf).


    // getShapePolygonPointsPdf, getShapePolygonPointsCanvas moved to ./annotations/polygon-points.js.



    // shapeContainsCanvasPoint moved to ./annotations/hit-test.js (Phase 7am).

    function drawImageBackedAnnotation(ann, ctx, scale, canvasHeight) {
        const box = resolveAnnBox(ann);
        if (!box) return;
        const img = getCachedAnnotationImage(ann);
        if (!img) return;
        const left = box.x * scale;
        const top = canvasHeight - (box.y + box.h) * scale;
        const width = Math.max(1, box.w * scale);
        const height = Math.max(1, box.h * scale);
        ctx.save();
        ctx.globalAlpha = clamp01(ann.opacity ?? 1, 1);
        const userRot = Number(ann.rotation) || 0;
        if (userRot) {
            const cx = left + width / 2;
            const cy = top + height / 2;
            ctx.translate(cx, cy);
            ctx.rotate(userRot * Math.PI / 180);
            ctx.translate(-cx, -cy);
        }
        ctx.drawImage(img, left, top, width, height);
        ctx.restore();
    }

    // ── Style helpers (ported from pdfRecon2) ─────────────────────────────────
    // spanMatchesLineBBox / buildRenderableLinesFromSpans / renderableSourceLines
    // moved to ./annotations/source-lines.js (Phase 7k).

    // sourceTextFallbackFromAnnotation / normalizeTextForSourceComparison /
    // annotationTextMatchesSource moved to ./annotations/source-text-match.js (Phase 7t).

    // normalizeQuarterTurnDegrees / sourceLineRotationDegrees /
    // annotationSourceRotationDegrees / lineSpans / resolveSpanFillStyle
    // moved to ./annotations/source-geometry.js (Phase 7l).

    function sourceStyle(ann, lineIndex = 0) {
        const spans = lineSpans(ann, lineIndex);
        const span = spans[0] || (Array.isArray(ann?.sourceSpans) ? ann.sourceSpans[0] : null) || null;
        const fontName = span?.embedded_font_name || span?.font || ann?.fontSourceName || ann?.fontFamily || '';
        const fontSizePt = Number(span?.font_size ?? span?.fontSize ?? ann?.fontSize) || 12;
        return {
            fontFamily: fallbackFontFamily(fontName, span?.embedded_font_family || ann?.fontFamily || ''),
            fontSizePt,
            fontWeight: sourceSpanFontWeight(span, ann?.fontWeight || '400'),
            fontStyle:  span?.fontStyle || (span?.italic ? 'italic' : 'normal') || ann?.fontStyle || 'normal',
            fillStyle:  resolveSpanFillStyle(ann, span),
        };
    }

    function compositeLineStyle(ann, lineIndex = 0) {
        const spans = lineSpans(ann, lineIndex);
        if (!spans.length) return sourceStyle(ann, lineIndex);

        const buckets = new Map();
        spans.forEach((span) => {
            const fontName = span?.embedded_font_name || span?.font || ann?.fontSourceName || ann?.fontFamily || '';
            const style = {
                fontFamily: fallbackFontFamily(fontName, span?.embedded_font_family || ann?.fontFamily || ''),
                fontSizePt: Number(span?.font_size ?? span?.fontSize ?? ann?.fontSize) || 12,
                fontWeight: sourceSpanFontWeight(span, ann?.fontWeight || '400'),
                fontStyle: span?.fontStyle || (span?.italic ? 'italic' : 'normal') || ann?.fontStyle || 'normal',
                fillStyle: resolveSpanFillStyle(ann, span),
            };
            const key = JSON.stringify(style);
            const spanText = sourceSpanText(span);
            const weight = Math.max(1, spanText.replace(/\s+/g, '').length || spanText.length || 1);
            buckets.set(key, (buckets.get(key) || 0) + weight);
        });

        const [bestKey] = Array.from(buckets.entries())
            .sort((left, right) => right[1] - left[1])[0] || [];

        return bestKey ? JSON.parse(bestKey) : sourceStyle(ann, lineIndex);
    }

    function editableLineStyle(ann, lineIndex = 0) {
        const baseStyle = compositeLineStyle(ann, lineIndex);
        // Only honor ann-level font overrides (family/weight/style) when the user
        // explicitly changed them via the format bar (_styleDirty). Otherwise the
        // dominant-block-style values persisted at extraction time would override
        // the per-span truth (e.g. force bold-italic sans on a block whose
        // spans are regular-italic Times), diverging editor and exported PDF.
        const styleOverride = Boolean(ann?._styleDirty);
        const annFontFamily = styleOverride ? String(ann?.fontFamily || '').trim() : '';
        // fontSize must gate on _styleDirty too: extraction can persist an ann.fontSize
        // (e.g. 17.94pt) that doesn't match the per-span/composite size (e.g. 21.53pt),
        // which would make the active-editor outer container's font-size & line-height
        // disagree with the inner per-line wrappers + spans (which use composite/span
        // sizes), producing a visibly mis-sized "selected" render vs. the loaded canvas.
        const annFontSizeRaw = Number(ann?.fontSize);
        const annFontSize = (styleOverride && annFontSizeRaw > 0) ? annFontSizeRaw : 0;
        const annFontWeight = styleOverride ? String(ann?.fontWeight || '').trim() : '';
        const annFontStyle = styleOverride ? String(ann?.fontStyle || '').trim() : '';
        const annTextColor = String(ann?.textColor || '').trim();

        return {
            fontFamily: annFontFamily ? fallbackFontFamily(annFontFamily, annFontFamily) : baseStyle.fontFamily,
            fontSizePt: annFontSize > 0 ? annFontSize : baseStyle.fontSizePt,
            fontWeight: annFontWeight || baseStyle.fontWeight,
            fontStyle: annFontStyle || baseStyle.fontStyle,
            fillStyle: annTextColor || baseStyle.fillStyle,
        };
    }

    function blockLineHeightPx(ann, lineIndex = 0, scale, styleOverride = null) {
        const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox) ? renderableSourceLines(ann)[lineIndex].bbox : null;
        const rotation = sourceLineRotationDegrees(ann, lineIndex);
        const sourceH = (!isUserAuthoredAnnotation(ann) && lineBBox)
            ? Math.max(
                0,
                Math.abs(rotation) === 90
                    ? (Number(lineBBox[2]) - Number(lineBBox[0]))
                    : (Number(lineBBox[3]) - Number(lineBBox[1]))
            ) * scale
            : 0;
        const resolvedStyle = styleOverride || sourceStyle(ann, lineIndex);
        // Font glyphs are drawn at fit-scale (zoom-invariant) so the line
        // height computed from the font also stays at fit-scale. Otherwise
        // line spacing would grow with user zoom while the actual text didn't.
        const fontH = resolvedStyle.fontSizePt * fontDisplayScale(scale) * 1.18;
        const minHeightPx = lineBBox ? 0 : 12;
        return Math.max(minHeightPx, sourceH || 0, fontH);
    }

    // Stage1 line-height unification: single source of truth for the
    // line-height the editor (renderPlainEditorHTML) AND the deselected
    // rich-html-layer (.rich-html-item) use when painting edited /
    // promoted text. Previously the two diverged — the editor used a
    // tight `fontSize * 1.18` while the rich-html-layer used
    // `blockLineHeightPx` (which inflates to the PDF source line bbox
    // including extraction leading) — so the same annotation jumped
    // baseline between selected (editing) and deselected (DOM) states.
    // Always returns the tight font-based height; callers that need the
    // source-bbox-floored variant should still use blockLineHeightPx.
    function domAndCanvasLineHeightPx(ann, lineIndex = 0, scale, styleOverride = null) {
        const style = styleOverride || editableLineStyle(ann, lineIndex);
        const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
        return Math.max(1, fontSizePx * 1.18);
    }

    function editorLineTopShiftPx(ann, lineIndex = 0, scale, styleOverride = null) {
        if (isUserAuthoredAnnotation(ann)) return 0;
        const style = styleOverride || compositeLineStyle(ann, lineIndex);
        const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale, style);
        const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
        return Math.max(0, (lineHeightPx - fontSizePx) / 2);
    }

    function lineTargetWidthPx(ann, lineIndex, scale) {
        const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
        if (rect) {
            const rotation = sourceLineRotationDegrees(ann, lineIndex);
            const targetExtent = Math.abs(rotation) === 90 ? rect.height : rect.width;
            if (Number.isFinite(targetExtent) && targetExtent > 0) return targetExtent;
        }
        const lineBBox = Array.isArray(ann?.sourceLineBBoxes?.[lineIndex]) ? ann.sourceLineBBoxes[lineIndex] : null;
        if (lineBBox) {
            const rotation = sourceLineRotationDegrees(ann, lineIndex);
            return Math.max(
                2,
                Math.abs(rotation) === 90
                    ? Math.abs(Number(lineBBox[3]) - Number(lineBBox[1])) * scale
                    : Math.abs(Number(lineBBox[2]) - Number(lineBBox[0])) * scale
            );
        }
        return Math.max(2, (resolveAnnBox(ann)?.w || 2) * scale);
    }

    function measuredLineContentWidthPx(ann, lineIndex, scale, spans = null) {
        const resolvedSpans = Array.isArray(spans) ? spans : lineSpans(ann, lineIndex);
        if (resolvedSpans.length) {
            let totalPx = 0;
            let cursorPts = null;
            resolvedSpans.forEach((span) => {
                const drawText = sourceSpanText(span);
                if (!drawText) return;

                const style = editorSpanStyle(ann, span, scale);
                const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                const origin = Array.isArray(span?.origin) ? span.origin : null;
                const spanStartPts = bbox ? Number(bbox[0]) : (origin ? Number(origin[0]) : cursorPts);

                if (cursorPts !== null && Number.isFinite(spanStartPts)) {
                    totalPx += Math.max(0, (spanStartPts - cursorPts) * scale);
                }

                totalPx += measureTextWidth(drawText, {
                    fontFamily: style.fontFamily,
                    fontSizePx: style.fontSizePx,
                    fontWeight: style.fontWeight,
                    fontStyle: style.fontStyle,
                });

                if (bbox) {
                    cursorPts = Number(bbox[2]);
                } else if (Number.isFinite(spanStartPts)) {
                    cursorPts = spanStartPts;
                } else {
                    cursorPts = null;
                }
            });

            if (totalPx > 0) return totalPx;
        }

        const text = String(renderableSourceLines(ann)[lineIndex]?.text ?? '');
        const style = compositeLineStyle(ann, lineIndex);
        return measureTextWidth(text, {
            fontFamily: style.fontFamily,
            fontSizePx: style.fontSizePt * fontDisplayScale(scale),
            fontWeight: style.fontWeight,
            fontStyle: style.fontStyle,
        });
    }

    function editorLineScaleX(ann, lineIndex, scale, spans = null) {
        // Once the user has edited the text, the original PDF source-line
        // bbox width is no longer the authoritative target — squeezing the
        // new (longer/shorter) text into the old bbox produces a different
        // scaleX per line and visually warps characters horizontally. Let
        // the new text flow at its natural width.
        if (ann && (ann._sourceExactTextEdited || ann.sourceExactTextEdited)) return 1;
        const targetWidthPx = lineTargetWidthPx(ann, lineIndex, scale);
        const measuredWidthPx = measuredLineContentWidthPx(ann, lineIndex, scale, spans);
        if (!Number.isFinite(targetWidthPx) || !Number.isFinite(measuredWidthPx) || targetWidthPx <= 0 || measuredWidthPx <= 0) {
            return 1;
        }
        const rawRatio = targetWidthPx / measuredWidthPx;
        if (!Number.isFinite(rawRatio) || rawRatio <= 0) return 1;
        const clampedRatio = Math.max(0.5, Math.min(1.3, rawRatio));
        return Math.abs(clampedRatio - 1) <= 0.015 ? 1 : clampedRatio;
    }

    function fitActiveEditorSourceLines(editor, ann, scale) {
        if (!(editor instanceof HTMLElement) || !ann) return;
        // Same rationale as editorLineScaleX: once the text was edited,
        // refitting per-line to the obsolete source bbox warps characters.
        if (ann._sourceExactTextEdited || ann.sourceExactTextEdited) {
            const lineEls = Array.from(editor.children || []);
            lineEls.forEach((lineEl) => {
                const contentEl = (lineEl instanceof HTMLElement)
                    ? (lineEl.querySelector('[data-line-content="1"]') || lineEl)
                    : null;
                if (contentEl instanceof HTMLElement) {
                    contentEl.style.transformOrigin = 'top left';
                    contentEl.style.transform = 'scaleX(1)';
                }
            });
            return;
        }
        const lineEls = Array.from(editor.children || []);
        lineEls.forEach((lineEl, index) => {
            if (!(lineEl instanceof HTMLElement)) return;
            const lineIndex = Number(lineEl.dataset.lineIndex ?? index);
            const targetWidthPx = lineTargetWidthPx(ann, lineIndex, scale);
            const contentEl = lineEl.querySelector('[data-line-content="1"]') || lineEl;
            if (!(contentEl instanceof HTMLElement)) return;
            contentEl.style.transformOrigin = 'top left';
            contentEl.style.transform = 'scaleX(1)';

            const rawWidthPx = Math.max(
                contentEl.scrollWidth || 0,
                contentEl.getBoundingClientRect().width || 0
            );
            if (!Number.isFinite(targetWidthPx) || targetWidthPx <= 0 || !Number.isFinite(rawWidthPx) || rawWidthPx <= 0) {
                return;
            }

            const rawRatio = targetWidthPx / rawWidthPx;
            const clampedRatio = Math.max(0.5, Math.min(1.3, rawRatio));
            const scaleX = Math.abs(clampedRatio - 1) <= 0.015 ? 1 : clampedRatio;
            contentEl.style.transform = `scaleX(${scaleX.toFixed(4)})`;
        });
    }

    // ── Drawing (ported from pdfRecon2) ───────────────────────────────────────
    function drawOriginalSource(ann, ctx, scale) {
        const rawSpans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
        // Dedupe overlapping duplicate-text spans on the same baseline. Some PDFs
        // (e.g. CA Form 593) include overprint instructions where the same text run
        // is drawn twice with different bbox widths. The exporter (writer.py) only
        // renders once per sourceLineBBoxes line, but per-span iteration here would
        // paint the text twice — visibly garbled. Match writer semantics by keeping
        // only the first span per (baseline-y, normalized-text) pair.
        const dedupSeen = new Map();
        const droppedKeys = new Set();
        const spans = [];
        for (const span of rawSpans) {
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            const txt = sourceSpanText(span);
            const norm = String(txt || '').replace(/\s+/g, ' ').trim();
            if (!origin || !norm) {
                spans.push(span);
                continue;
            }
            const baselineY = Math.round(Number(origin[1]) * 4) / 4; // 0.25pt buckets
            const key = `${baselineY}|${norm}`;
            if (dedupSeen.has(key)) {
                droppedKeys.add(key);
                continue;
            }
            dedupSeen.set(key, { span, key });
            spans.push(span);
        }
        // DEBUG (remove): track when sourceSpans is empty for our test uids
        try {
            const k = ann?._uid;
            if ((k === 'promoted_1_6_14' || k === 'promoted_1_0_0') && !spans.length) {
                window.__galleyDbgEmpty = window.__galleyDbgEmpty || {};
                window.__galleyDbgEmpty[k] = (window.__galleyDbgEmpty[k] || 0) + 1;
            }
        } catch (_e) {}
        if (!spans.length) return false;
        const offset = annotationSourceOffset(ann);
        // DEBUG MARKER: paint a magenta rect for promoted_1_6_14 calls so we can
        // verify the canvas paint is actually reaching the screen.
        try {
            if (ann?._uid === 'promoted_1_6_14' && ann._galleyEdited) {
                ctx.save();
                ctx.fillStyle = 'rgba(255, 0, 255, 0.5)';
                ctx.fillRect(50, 50, 30, 30);
                ctx.restore();
            }
        } catch (_e) {}
        // DEBUG probe (remove before commit): track every drawOriginalSource call
        // for select uids so we can see what styles were actually drawn to canvas.
        try {
            window.__galleyDbg3 = window.__galleyDbg3 || {};
            const k = ann?._uid;
            if (k === 'promoted_1_6_14' || k === 'promoted_1_0_0') {
                window.__galleyDbg3Counts = window.__galleyDbg3Counts || {};
                if (!window.__galleyDbg3Counts[k]) window.__galleyDbg3Counts[k] = { total: 0, post: 0, withAppends: 0 };
                window.__galleyDbg3Counts[k].total++;
                if (ann._galleyEdited) window.__galleyDbg3Counts[k].post++;
                if (ann._galleyAppends && Object.keys(ann._galleyAppends).length) window.__galleyDbg3Counts[k].withAppends++;
                window.__galleyDbg3LastState = window.__galleyDbg3LastState || {};
                window.__galleyDbg3LastState[k] = {
                    galleyEdited: !!ann._galleyEdited,
                    galleyAppends: ann._galleyAppends ? JSON.parse(JSON.stringify(ann._galleyAppends)) : null,
                    spanCount: spans.length,
                };
                window.__galleyDbg3[k] = window.__galleyDbg3[k] || [];
                // Capture ALL post-edit calls (when ann has been touched)
                const isPost = ann._galleyEdited || (ann._galleyAppends && Object.keys(ann._galleyAppends).length);
                if (isPost && window.__galleyDbg3[k].filter(e => e.post).length < 50) {
                    window.__galleyDbg3[k].push({
                        post: true,
                        callIdx: window.__galleyDbg3[k].length,
                        scale,
                        spanCount: spans.length,
                        styleDirty: !!ann._styleDirty,
                        galleyEdited: !!ann._galleyEdited,
                        galleyAppends: ann._galleyAppends ? JSON.parse(JSON.stringify(ann._galleyAppends)) : null,
                        annFontFamily: ann.fontFamily || null,
                        annFontWeight: ann.fontWeight || null,
                        annFontStyle: ann.fontStyle || null,
                        richHtml: ann._richHtml ? '['+ann._richHtml.length+'chars]' : null,
                        userAuthored: !!ann._userAuthored,
                        text: String(ann.text || '').slice(0, 80),
                        origText: String(ann.originalText || '').slice(0, 80),
                    });
                }
            }
        } catch (_e) {}
        spans.forEach((span, spanIdx) => {
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            if (!origin || origin.length < 2) return;
            const drawText = sourceSpanText(span);
            if (!drawText) return;
            // Only honor ann-level font overrides (fontFamily/Weight/Style) when the
            // user explicitly changed them via the format bar (_styleDirty). Otherwise
            // the per-span extraction data is authoritative — this is the same data
            // the PDF exporter uses, so editor and downloaded PDF stay in lockstep.
            // Blocks whose dominant font was bold/italic would otherwise force every
            // span to that style even when individual spans are regular-italic Times,
            // producing a bold-italic sans-serif editor preview for a serif italic PDF.
            const styleOverride = Boolean(ann?._styleDirty);
            const annFontFamilyOverride = styleOverride ? String(ann?.fontFamily || '').trim() : '';
            const fontFamily = annFontFamilyOverride
                ? fallbackFontFamily(annFontFamilyOverride, annFontFamilyOverride)
                : fallbackFontFamily(span?.embedded_font_name || span?.font, span?.embedded_font_family || span?.fontFamily || '');
            const fontSizePx = (Number(span?.font_size ?? span?.fontSize) || Number(ann?.fontSize) || 12) * fontDisplayScale(scale);
            const spanFontStyle  = span?.fontStyle || (span?.italic ? 'italic' : 'normal');
            const spanFontWeight = sourceSpanFontWeight(span);
            const fontStyle  = styleOverride ? (ann?.fontStyle  || spanFontStyle)  : spanFontStyle;
            const fontWeight = styleOverride ? (ann?.fontWeight || spanFontWeight) : spanFontWeight;
            ctx.font         = ctxFont({ fontFamily, fontSizePx, fontWeight, fontStyle });
            ctx.fillStyle    = resolveSpanFillStyle(ann, span);
            ctx.textBaseline = 'alphabetic';
            const drawX = (Number(origin[0]) + offset.dx) * scale;
            const drawY = (Number(origin[1]) + offset.dy) * scale;
            try {
                const k = ann?._uid;
                if (k === 'promoted_1_6_14' || k === 'promoted_1_0_0') {
                    window.__galleyDbgPaint = window.__galleyDbgPaint || {};
                    window.__galleyDbgPaint[k] = window.__galleyDbgPaint[k] || [];
                    if (window.__galleyDbgPaint[k].length < 30) {
                        window.__galleyDbgPaint[k].push({
                            spanIdx,
                            drawText: drawText.slice(0, 30),
                            drawX, drawY,
                            font: ctx.font,
                            fillStyle: ctx.fillStyle,
                            galleyEdited: !!ann._galleyEdited,
                            canvasW: ctx.canvas.width, canvasH: ctx.canvas.height,
                        });
                    }
                }
            } catch (_e) {}
            const spanRotation = Number(span?.rotation ?? 0);
            const isVertical = Math.abs(Math.abs(spanRotation) - 90) < 1;
            const targetWidthPx = (() => {
                const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                if (!bbox || bbox.length < 4) return 0;
                // For 90°-rotated text the text runs along the y-axis; use bbox height as the length
                if (isVertical) return Math.max(0, (Number(bbox[3]) - Number(bbox[1])) * scale);
                return Math.max(0, (Number(bbox[2]) - Number(bbox[0])) * scale);
            })();
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            if (!isVertical && drawText.trim() === '.' && bbox && bbox.length >= 4) {
                const dotW = Math.max(0, (Number(bbox[2]) - Number(bbox[0])) * scale);
                const dotH = Math.max(0, (Number(bbox[3]) - Number(bbox[1])) * scale);
                if (dotW > 0 && dotH > 0 && dotH <= fontSizePx * 0.35) {
                    ctx.save();
                    ctx.fillStyle = resolveSpanFillStyle(ann, span);
                    ctx.beginPath();
                    ctx.ellipse(
                        (Number(bbox[0]) + offset.dx) * scale + (dotW / 2),
                        (Number(bbox[1]) + offset.dy) * scale + (dotH / 2),
                        Math.max(0.45, dotW / 2),
                        Math.max(0.35, dotH / 2),
                        0,
                        0,
                        Math.PI * 2
                    );
                    ctx.fill();
                    ctx.restore();
                    return;
                }
            }
            const measuredWidthPx = ctx.measureText(drawText).width || 0;
            // For source-exact-edited annotations the per-span bbox no
            // longer matches the user's new text length. Skip the per-span
            // horizontal fit so characters render at natural metrics rather
            // than being squeezed/stretched to the obsolete bbox width.
            const _spanEdited = !!(ann && (ann._sourceExactTextEdited || ann.sourceExactTextEdited));
            // When this span is the surviving twin of a deduped overprint pair, the
            // remaining bbox no longer represents the visual text run length (the
            // dropped duplicate covered the rest of the run). Skip the bbox-fit so
            // text draws at natural width — matches writer.py output for these blocks.
            const _spanIsDedupSurvivor = (() => {
                const o = Array.isArray(span?.origin) ? span.origin : null;
                if (!o) return false;
                const norm = String(drawText || '').replace(/\s+/g, ' ').trim();
                const bY = Math.round(Number(o[1]) * 4) / 4;
                return droppedKeys.has(`${bY}|${norm}`);
            })();
            const _skipBboxFit = _spanEdited || _spanIsDedupSurvivor;
            const rawRatio = (!_skipBboxFit && targetWidthPx > 0 && measuredWidthPx > 0) ? (targetWidthPx / measuredWidthPx) : 1;
            // When the per-span bbox is dramatically narrower than the natural text
            // width (typical for overprint/OCR-shadow spans where one span carries
            // a wide string crammed into a tiny bbox), mirror writer.py: shrink the
            // FONT SIZE to fit the bbox rather than horizontally crushing the glyphs.
            // This makes such spans render as the visually-tiny strings they are in
            // the exported PDF, instead of obscuring legitimate text behind them.
            let scaleX;
            if (Number.isFinite(rawRatio) && rawRatio > 0 && rawRatio < 0.5 && !_skipBboxFit) {
                const shrunkenSize = fontSizePx * rawRatio;
                ctx.font = ctxFont({ fontFamily, fontSizePx: shrunkenSize, fontWeight, fontStyle });
                scaleX = 1;
            } else {
                scaleX = (!Number.isFinite(rawRatio) || rawRatio <= 0) ? 1 : Math.max(0.5, Math.min(1.3, rawRatio));
            }
            if (isVertical) {
                // spanRotation -90: direction [0,-1] (upward) → canvas rotate(-π/2) draws text upward
                // spanRotation +90: direction [0,+1] (downward) → canvas rotate(+π/2) draws text downward
                const canvasAngle = spanRotation < 0 ? -Math.PI / 2 : Math.PI / 2;
                ctx.save();
                ctx.translate(drawX, drawY);
                ctx.rotate(canvasAngle);
                if (Math.abs(scaleX - 1) > 0.015) ctx.scale(scaleX, 1);
                ctx.fillText(drawText, 0, 0);
                ctx.restore();
                return;
            }
            if (Math.abs(scaleX - 1) > 0.015) {
                ctx.save();
                ctx.translate(drawX, drawY);
                ctx.scale(scaleX, 1);
                ctx.fillText(drawText, 0, 0);
                ctx.restore();
                return;
            }
            ctx.fillText(drawText, drawX, drawY);
        });
        // Galley appends — user-typed characters in galley mode are persisted
        // here so they remain visible after blur (when the active editor goes
        // display:none). Paint each line's appended string at the right edge
        // of the last source span on that line, in the line's composite style.
        const galleyAppends = (ann && ann._galleyAppends && typeof ann._galleyAppends === 'object') ? ann._galleyAppends : null;
        if (galleyAppends) {
            const lines = renderableSourceLines(ann);
            Object.keys(galleyAppends).forEach((key) => {
                const lineIdx = Number(key);
                const appended = String(galleyAppends[key] || '');
                if (!appended) return;
                const line = lines[lineIdx];
                if (!line || !Array.isArray(line.spans) || line.spans.length === 0) return;
                const lastSpan = line.spans[line.spans.length - 1];
                const lastBBox = Array.isArray(lastSpan?.bbox) ? lastSpan.bbox : null;
                const lastOrigin = Array.isArray(lastSpan?.origin) ? lastSpan.origin : null;
                if (!lastBBox || !lastOrigin) return;
                const style = compositeLineStyle(ann, lineIdx);
                const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
                ctx.font = ctxFont({
                    fontFamily: style.fontFamily,
                    fontSizePx,
                    fontWeight: style.fontWeight,
                    fontStyle: style.fontStyle,
                });
                ctx.fillStyle = style.fillStyle;
                ctx.textBaseline = 'alphabetic';
                const drawX = (Number(lastBBox[2]) + offset.dx) * scale;
                const drawY = (Number(lastOrigin[1]) + offset.dy) * scale;
                ctx.fillText(appended, drawX, drawY);
            });
        }
        return true;
    }

    // wrapParagraph moved to ./text/wrap.js (Phase 7j).

    function buildEditedLines(ann, text, scale, pageWidthPts, pageHeightPts) {
        const box = resolveAnnBox(ann);
        if (!box) return [];
        const offset = annotationSourceOffset(ann);
        // Mirror renderPlainEditorHTML: preserve explicit blank lines created by
        // Enter at an existing line boundary. Collapsing \n\n back to \n makes
        // Enter appear to do nothing in paragraph edit mode.
        const normalized = String(text ?? '')
            .replace(/\r\n?/g, '\n');
        const paragraphs = normalized.split('\n');
        // For user-authored (edited/resized) annotations, ignore the original per-line
        // source bboxes: they were captured from the *wide* pre-resize layout with tight
        // per-line spacing, which produces uneven rows when the text reflows into a
        // narrower box. Lay out every wrapped line uniformly from box.y using the
        // annotation-level line height instead.
        const userAuthoredLayout = isUserAuthoredAnnotation(ann);
        const lineBBoxes = userAuthoredLayout
            ? []
            : renderableSourceLines(ann).map((line) => line.bbox).filter(Array.isArray);
        const lines = [];
        const maxWidthPx = Math.max(10, box.w * scale);
        let cursorTopPts = lineBBoxes.length > 0
            ? Number(lineBBoxes[0][1]) + offset.dy
            : (pageHeightPts - box.y - box.h);

        paragraphs.forEach((para, pi) => {
            const style = editableLineStyle(ann, Math.min(pi, Math.max(0, lineBBoxes.length - 1)));
            const stylePx = { ...style, fontSizePx: style.fontSizePt * fontDisplayScale(scale) };
            const wrapped = wrapParagraph(para, maxWidthPx, stylePx);
            wrapped.forEach((lineText, wi) => {
                const si = lines.length;
                const sourceBBox = Array.isArray(lineBBoxes[si]) ? lineBBoxes[si] : null;
                const lineStyle = editableLineStyle(ann, Math.min(si, Math.max(0, lineBBoxes.length - 1)));
                const lineStylePx = { ...lineStyle, fontSizePx: lineStyle.fontSizePt * fontDisplayScale(scale) };
                const lineHeightPx = blockLineHeightPx(ann, Math.min(si, Math.max(0, lineBBoxes.length - 1)), scale, lineStyle);
                const topPts = sourceBBox
                    ? Number(sourceBBox[1]) + offset.dy
                    : (cursorTopPts + ((wi === 0 && pi === 0 && lines.length === 0) ? 0 : (lineHeightPx / scale)));
                const baselinePts = (() => {
                    if (userAuthoredLayout) return topPts + (lineHeightPx / scale) * 0.82;
                    const sp = lineSpans(ann, si)[0] || null;
                    if (Array.isArray(sp?.origin) && sp.origin.length >= 2) return Number(sp.origin[1]) + offset.dy;
                    return topPts + (lineHeightPx / scale) * 0.82;
                })();
                lines.push({ text: lineText, xPts: box.x, topPts, baselinePts, lineHeightPx, style: lineStylePx });
                cursorTopPts = topPts;
            });
        });
        return lines;
    }


    function drawEditedAnnotation(ann, ctx, scale, pageWidthPts, pageHeightPts) {
        if (!resolveAnnBox(ann)) return;
        if (isShapeAnnotation(ann)) {
            drawShapeAnnotation(ann, ctx, scale, canvasLogicalHeight(ctx.canvas));
            return;
        }
        if (isImageBackedAnnotation(ann)) {
            drawImageBackedAnnotation(ann, ctx, scale, canvasLogicalHeight(ctx.canvas));
            return;
        }
        // currentText: what is being typed right now, or the saved value
        const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '');
        const hasSourceContent = (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
        // Skip canvas drawing for user-created text annotations with no content:
        // they are shown purely through the active-editor overlay while being created,
        // and drawing a white erase rect here would cover underlying PDF content.
        if (!hasSourceContent && !currentText.trim()) return;
        // Determine if there is any difference from the original PDF source text.
        // This covers both: (a) mid-session edits and (b) saved edits loaded on reload.
        const editedInSession = editedTexts[ann._uid] !== undefined
            && editedTexts[ann._uid] !== String(ann.text ?? '');
        const savedDiffersFromPdf = annTextIsEdited(ann);        const dimensionsChanged = annotationDimensionsChanged(ann);
        // styleChanged: user changed a visual property (color, background) via format bar.
        // Forces the "edited" drawing path so the canvas reflects the new style even when
        // the annotation text hasn't been changed.
        // Use resolveDisplayBgColor so white-text-on-missing-bar annotations get a dark
        // placeholder background in the rendered overlay (without mutating the stored
        // ann.backgroundColor, which stays transparent for export).
        const annBgColor = resolveDisplayBgColor(ann);
        const annOpacity = Math.min(1, Math.max(0, parseFloat(ann.opacity ?? 1)));
        // styleChanged: only force the "edited" redraw path for properties that
        // drawOriginalSource cannot handle (bg color, sub-1 opacity, underline strokes).
        // textColor is already applied inside drawOriginalSource, so it does NOT belong here.
        const styleChanged = hasSourceContent && Boolean(
            (annBgColor && annBgColor !== '#ffffff' && annBgColor !== 'transparent')
            || annOpacity < 0.999
            || ann.underline
            || (String(ann.verticalAlign || 'top').toLowerCase() !== 'top')
            || (String(ann.textAlign || 'left').toLowerCase() !== 'left')
        );
        const { dx: posDx, dy: posDy } = annotationOffset(ann);
        const positionChanged = Math.abs(posDx) > 0.25 || Math.abs(posDy) > 0.25;
        // Baked-base short-circuit: when the editor is sitting on top of the
        // server-baked PDF, the bake script (bake_edit_new_base.py) only paints
        // annotations that pass the *clean unedited promoted* filter — same
        // filter mirrored here. Skip canvas drawing for those annotations
        // because the baked PDF already has them painted by writer.py at the
        // exact source-bbox coords.  Anything edited / moved / userAuthored
        // is NOT in the baked PDF, so the canvas must draw it as before.
        const useBakedBase = window.__edn_useBakedBase === true;
        const isBakedClean = useBakedBase
            && !!ann?.promotedFromExtraction
            && !ann?.promotedDirty
            && !ann?.userAuthored
            && !ann?.promotedReflowEnabled
            && !editedInSession
            && !savedDiffersFromPdf
            && !dimensionsChanged
            && !styleChanged
            && !positionChanged
            && !ann?._richHtml;
        if (isBakedClean) {
            return;
        }
        // Exact-preserve path: use drawOriginalSource when the annotation's text,
        // dimensions, and style are unchanged from extraction. The user-authored
        // flag does NOT by itself block the preserve path — flipping that flag
        // (via setAnnotationBox grow, format-bar style edits, etc.) must not
        // collapse leader-dot spacing or per-span fonts on annotations whose
        // visible content still matches the PDF source. Reflow only kicks in
        // when the text or geometry actually diverges from the extraction.
        const userAuthored = isUserAuthoredAnnotation(ann);
        const preserveSourceExactText = Boolean(
            ann?._sourceExactTextEdited
            || ann?.sourceExactTextEdited
            || ann?.preserveSourceLayout
        );
        const canUseSourcePreservePath = (
            ((!editedInSession || ann._galleyEdited) && !savedDiffersFromPdf)
            || preserveSourceExactText
        ) && !dimensionsChanged && !styleChanged && !ann._richHtml;
        // DEBUG probe (remove before commit): record guard inputs for select uids.
        try {
            const k = ann?._uid;
            if (k === 'promoted_1_6_14' || k === 'promoted_1_0_0') {
                window.__galleyDbg4Counts = window.__galleyDbg4Counts || {};
                if (!window.__galleyDbg4Counts[k]) window.__galleyDbg4Counts[k] = { total: 0, preserveTrue: 0, preserveFalse: 0, falseReasons: {} };
                window.__galleyDbg4Counts[k].total++;
                const _pp = canUseSourcePreservePath;
                if (_pp) window.__galleyDbg4Counts[k].preserveTrue++;
                else {
                    window.__galleyDbg4Counts[k].preserveFalse++;
                    const r = (editedInSession && !ann._galleyEdited ? 'editedNoGalley,' : '')
                        + (savedDiffersFromPdf ? 'savedDiffers,' : '')
                        + (dimensionsChanged ? 'dimChanged,' : '')
                        + (styleChanged ? 'styleChanged,' : '')
                        + (ann._richHtml ? 'richHtml,' : '');
                    window.__galleyDbg4Counts[k].falseReasons[r] = (window.__galleyDbg4Counts[k].falseReasons[r] || 0) + 1;
                }
                window.__galleyDbg4 = window.__galleyDbg4 || {};
                window.__galleyDbg4[k] = window.__galleyDbg4[k] || [];
                window.__galleyDbg4Last = window.__galleyDbg4Last || {};
                window.__galleyDbg4Last[k] = {
                        editedInSession,
                        savedDiffersFromPdf,
                        dimensionsChanged,
                        styleChanged,
                        hasRichHtml: !!ann._richHtml,
                        galleyEdited: !!ann._galleyEdited,
                        userAuthored,
                        rotation: Number(ann.rotation) || 0,
                        text: String(ann.text || '').slice(0, 60),
                        editedTextValue: String(editedTexts[ann._uid] ?? '__undef__').slice(0, 60),
                        origText: String(ann.originalText || '').slice(0, 60),
                        preservePass: canUseSourcePreservePath,
                        callCount: (window.__galleyDbg4Last[k]?.callCount || 0) + 1,
                };
                if (window.__galleyDbg4[k].length < 50) {
                    window.__galleyDbg4[k].push({
                        editedInSession,
                        savedDiffersFromPdf,
                        dimensionsChanged,
                        styleChanged,
                        hasRichHtml: !!ann._richHtml,
                        galleyEdited: !!ann._galleyEdited,
                        userAuthored,
                        rotation: Number(ann.rotation) || 0,
                        text: String(ann.text || '').slice(0, 60),
                        editedTextValue: String(editedTexts[ann._uid] ?? '__undef__').slice(0, 60),
                        origText: String(ann.originalText || '').slice(0, 60),
                        preservePass: canUseSourcePreservePath,
                    });
                }
            }
        } catch (_e) {}
        if (canUseSourcePreservePath) {
            // Skip preserve path when user has applied a free rotation — the rotation
            // must be applied around the box center via canvas transform below.
            if (Number(ann.rotation) || 0) {
                // fall through to edited path
            } else {
            // When only position changed, erase old source area before drawing at new position
            if (positionChanged) {
                const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
                const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
                if (sW > 0 && sH > 0) {
                    ctx.save();
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(sL * scale, sT * scale, sW * scale, sH * scale);
                    ctx.restore();
                }
            }
            if (drawOriginalSource(ann, ctx, scale)) return;
            }
        }
        // Erase the original PDF background text by filling a white rect over the annotation area
        // (covers both original source area and current/moved position)
        const box = resolveAnnBox(ann);
        const canvasHeight = canvasLogicalHeight(ctx.canvas);
        // Erase original source area whenever one is recorded. Previously
        // gated on `positionChanged`, which meant a pure resize (no move)
        // left the original PDF span text painted outside the now-smaller
        // box — visible as a "ghost" of the source text on the right when
        // the user shrinks the bounding box.
        if (hasSourceContent) {
            const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
            const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
            if (sW > 0 && sH > 0) {
                ctx.save();
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(sL * scale, sT * scale, sW * scale, sH * scale);
                ctx.restore();
            }
        }
        const correctedSrc = correctedSourceBlockBox(ann, box);
        const eraseLeft   = correctedSrc ? (correctedSrc.left * scale) : (box.x * scale);
        const eraseTop    = correctedSrc
            ? (correctedSrc.top * scale)
            : (canvasHeight - (box.y + box.h) * scale);
        const eraseW      = correctedSrc ? (correctedSrc.width * scale) : (box.w * scale);
        const eraseH      = correctedSrc ? (correctedSrc.height * scale) : (box.h * scale);
        if (hasSourceContent) {
            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(eraseLeft, eraseTop, eraseW, eraseH);
            ctx.restore();
        }
        const lines = buildEditedLines(ann, currentText, scale, pageWidthPts, pageHeightPts);
        const annTextAlign = ann.textAlign || 'left';
        // Vertical alignment: offset all baselines by Δ so the block of text is aligned
        // top / middle / bottom within the annotation box. Only applied when the lines
        // don't already have anchored source bboxes (plain text edits); for the default
        // 'top' case Δ is 0 so source-anchored positions are unchanged.
        const vAlignRaw = String(ann.verticalAlign || 'top').toLowerCase();
        const vAlignDyPx = (() => {
            if (vAlignRaw === 'top' || !lines.length) return 0;
            const blockHeightPx = lines.reduce((sum, l) => sum + (l.lineHeightPx || 0), 0);
            const boxHeightPx = box.h * scale;
            if (blockHeightPx >= boxHeightPx - 0.5) return 0;
            const slackPx = boxHeightPx - blockHeightPx;
            if (vAlignRaw === 'middle' || vAlignRaw === 'center') return slackPx / 2;
            if (vAlignRaw === 'bottom') return slackPx;
            return 0;
        })();
        ctx.save();
        ctx.globalAlpha = annOpacity;
        const userRotText = Number(ann.rotation) || 0;
        if (userRotText) {
            const rcx = eraseLeft + eraseW / 2;
            const rcy = eraseTop + eraseH / 2;
            ctx.translate(rcx, rcy);
            ctx.rotate(userRotText * Math.PI / 180);
            ctx.translate(-rcx, -rcy);
        }
        // Draw background color (if explicitly set and not white)
        if (annBgColor && annBgColor !== '#ffffff' && annBgColor !== 'transparent') {
            ctx.fillStyle = annBgColor;
            ctx.fillRect(eraseLeft, eraseTop, eraseW, eraseH);
        }
        lines.forEach((line) => {
            // Apply ann-level font overrides on top of span-derived style (format-bar B/I)
            const renderWeight = ann._styleDirty ? (ann.fontWeight || line.style.fontWeight) : line.style.fontWeight;
            const renderFontStyle = ann._styleDirty ? (ann.fontStyle || line.style.fontStyle) : line.style.fontStyle;
            ctx.font         = ctxFont({ ...line.style, fontWeight: renderWeight, fontStyle: renderFontStyle });
            ctx.fillStyle    = line.style.fillStyle;
            ctx.textBaseline = 'alphabetic';
            const lineWidthPx = line.text ? ctx.measureText(line.text).width : 0;
            let drawX = line.xPts * scale;
            if (annTextAlign !== 'left' && line.text) {
                const boxWidthPx  = box.w * scale;
                if (annTextAlign === 'center') {
                    drawX = (box.x * scale) + (boxWidthPx - lineWidthPx) / 2;
                } else if (annTextAlign === 'right') {
                    drawX = (box.x * scale) + boxWidthPx - lineWidthPx;
                }
            }
            const drawY = (line.baselinePts * scale) + vAlignDyPx;
            // When per-selection formatting is present (ann._richHtml), the canvas only
            // erases the background and paints the opaque bg color — the rich-html-layer
            // DIV renders the styled text on top so the canvas and editor use the same
            // DOM layout. Skip fillText here to avoid doubling up.
            // Same for any user-authored text annotation: it is rendered via the
            // rich-html-layer to keep wrapping consistent with the active editor.
            const renderedByDom = !!ann._richHtml
                || ((ann.type || 'text') === 'text' && (ann.userCreated || isUserAuthoredAnnotation(ann)));
            if (!renderedByDom) {
                ctx.fillText(line.text, drawX, drawY);
                if (ann.underline && line.text) {
                    const fontSize = line.style.fontSizePt * fontDisplayScale(scale);
                    ctx.strokeStyle = line.style.fillStyle;
                    ctx.lineWidth = Math.max(0.5, fontSize * 0.07);
                    ctx.beginPath();
                    ctx.moveTo(drawX, drawY + Math.ceil(fontSize * 0.12));
                    ctx.lineTo(drawX + lineWidthPx, drawY + Math.ceil(fontSize * 0.12));
                    ctx.stroke();
                }
            }
        });
        ctx.restore();
    }

    // shouldRenderTextInRichHtmlLayer / activeEditorCanvasOwnsPaint moved to
    // ./render/text-routing.js (Phase 7aj).
    // annIsPromotedFromExtraction moved to ./annotations/promoted.js (Phase 7ai).

    // normalizeTextForDomReflow / normalizeRichHtmlForReflow moved to
    // ./text/dom-reflow.js (Phase 7ag). Both helpers had duplicate inline
    // copies (here and ~3114); the second always shadowed the first via
    // function-declaration hoisting, so the canonical second-version
    // behavior is preserved in the module.

    // Builds the rich-html layer for a page: renders a positioned <div> mirroring the
    // active-editor styling for every annotation that should use DOM layout.
    // Called at the end of redrawOverlay so the DOM layer stays in sync with the canvas.
    function renderRichHtmlLayer(pi) {
        const data = pageData[pi];
        const layer = document.getElementById('rhl-' + (pi + 1));
        if (!data || !layer) return;
        const { scale, canvasHeight, annotations } = data;
        layer.innerHTML = '';
        const renderedDomTextRecords = [];
        const normalizeDomDuplicateText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
        const overlapRatio = (a, b) => {
            if (!a || !b) return 0;
            const left = Math.max(a.left, b.left);
            const top = Math.max(a.top, b.top);
            const right = Math.min(a.left + a.width, b.left + b.width);
            const bottom = Math.min(a.top + a.height, b.top + b.height);
            const w = right - left;
            const h = bottom - top;
            if (w <= 0 || h <= 0) return 0;
            const area = w * h;
            const minArea = Math.min(Math.max(1, a.width * a.height), Math.max(1, b.width * b.height));
            return area / minArea;
        };
        const domGeometryForAnnotation = (ann) => {
            const box = resolveAnnBox(ann);
            if (!box) return null;
            const rect = annRectPx(ann, scale, canvasHeight);
            const correctedSrcDom = correctedSourceBlockBox(ann, box);
            return {
                box,
                correctedSrcDom,
                left: correctedSrcDom
                    ? (correctedSrcDom.left * scale)
                    : (rect ? rect.left : (box.x * scale)),
                top: correctedSrcDom
                    ? (correctedSrcDom.top * scale)
                    : (rect ? rect.top : (canvasHeight - (box.y + box.h) * scale)),
                width: correctedSrcDom
                    ? Math.max(2, correctedSrcDom.width * scale)
                    : (rect ? Math.max(2, rect.width) : Math.max(2, box.w * scale)),
                height: correctedSrcDom
                    ? Math.max(2, correctedSrcDom.height * scale)
                    : (rect ? Math.max(2, rect.height) : Math.max(2, box.h * scale)),
            };
        };
        const activeDomAnn = activeState.uid
            ? annotations.find((ann) => ann._uid === activeState.uid && shouldRenderTextInRichHtmlLayer(ann))
            : null;
        const activeDomEditor = activeDomAnn ? document.getElementById('ae-' + (pi + 1)) : null;
        if (activeDomAnn && editorIsEditingAnnotation(activeDomEditor, activeDomAnn)) {
            const activeGeom = domGeometryForAnnotation(activeDomAnn);
            if (activeGeom) {
                renderedDomTextRecords.push({
                    uid: String(activeDomAnn._uid || ''),
                    text: normalizeDomDuplicateText(activeDomAnn.text),
                    rect: activeGeom,
                });
            }
        }
        annotations.forEach((ann) => {
            const hasRichHtml = !!ann._richHtml;
            if (!shouldRenderTextInRichHtmlLayer(ann)) return;
            const geometry = domGeometryForAnnotation(ann);
            if (!geometry) return;
            // Selected text must never disappear. User-authored / rich text is
            // normally owned by this DOM layer; keep rendering it while merely
            // selected because the active editor may be intentionally empty in
            // drag/resize mode. Only hide it while the contenteditable is
            // actively editing the same annotation, where ae owns caret + text.
            if (ann._uid === activeState.uid) {
                const aeEl = document.getElementById('ae-' + (pi + 1));
                if (editorIsEditingAnnotation(aeEl, ann)) return;
            }
            const duplicateText = normalizeDomDuplicateText(editedTexts[ann._uid] ?? ann.text);
            if (duplicateText) {
                const duplicate = renderedDomTextRecords.some((record) => (
                    record.uid !== String(ann._uid || '')
                    && record.text === duplicateText
                    && overlapRatio(record.rect, geometry) > 0.35
                ));
                if (duplicate) return;
            }
            const { box, correctedSrcDom, left, top, width, height } = geometry;
            const style0 = editableLineStyle(ann, 0);
            // Stage1 line-height unification: prefer the shared
            // domAndCanvasLineHeightPx (font-tight, identical to the
            // active editor) over blockLineHeightPx (source-bbox
            // floored, includes PDF leading) so a deselected
            // rich-html-item lays out at the same y-positions as the
            // contenteditable. blockLineHeightPx kept as a floor only
            // when its sourceH happens to be smaller (rare).
            const lineHeightPxRaw = domAndCanvasLineHeightPx(ann, 0, scale, style0);
            // When source-block correction is active, force the line-height to a
            // value that lets the original line count fit the original block
            // height. Otherwise the bloated ann.lineHeight (e.g. 9.44 vs the
            // source's 8.17) makes the wrapped text taller than the corrected
            // box and pushes the bottom line beyond the source area.
            const lineHeightPx = (() => {
                if (!correctedSrcDom) return lineHeightPxRaw;
                const lineCount = Math.max(1, Array.isArray(ann?.sourceTextLines) ? ann.sourceTextLines.length : 1);
                return Math.max(1, (correctedSrcDom.height * scale) / lineCount);
            })();
            const bgCss = resolveDisplayBgColor(ann);
            const vAlign = (() => {
                const v = String(ann.verticalAlign || 'top').toLowerCase();
                if (v === 'middle' || v === 'center') return 'center';
                if (v === 'bottom') return 'flex-end';
                return 'flex-start';
            })();
            const useFlexLayout = vAlign !== 'flex-start';
            const item = document.createElement('div');
            item.className = 'rich-html-item';
            if (ann._uid) item.dataset.uid = String(ann._uid);
            item.style.cssText = [
                `display:${useFlexLayout ? 'flex' : 'block'}`,
                ...(useFlexLayout ? ['flex-direction:column', `justify-content:${vAlign}`] : []),
                `left:${left.toFixed(2)}px`, `top:${top.toFixed(2)}px`,
                `width:${width.toFixed(2)}px`, `height:${height.toFixed(2)}px`,
                `font-family:${style0.fontFamily}`,
                `font-size:${(style0.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px`,
                `font-weight:${ann._styleDirty ? (ann.fontWeight || style0.fontWeight || '400') : (style0.fontWeight || '400')}`,
                `font-style:${ann.fontStyle || style0.fontStyle || 'normal'}`,
                `text-decoration:${ann.underline ? 'underline' : 'none'}`,
                `color:${style0.fillStyle}`,
                `line-height:${lineHeightPx.toFixed(2)}px`,
                `background:${bgCss}`,
                `text-align:${ann.textAlign || 'left'}`,
                `opacity:${parseFloat(ann.opacity ?? 1)}`,
                ...(ann._pageBoundsConstrained ? ['overflow:hidden'] : []),
            ].join(';');
            const userRotItem = Number(ann.rotation) || 0;
            if (userRotItem) {
                item.style.transformOrigin = 'center center';
                item.style.transform = `rotate(${userRotItem}deg)`;
            }
            if (hasRichHtml) {
                // For promoted extraction annotations whose box was resized, the
                // saved _richHtml has hard <br> tags at original PDF line-break
                // positions.  Collapse single <br> runs to a space so CSS
                // word-wrap can reflow to the new box width; leave 2+
                // consecutive <br>s alone (those represent explicit paragraph
                // breaks).  Apply this only when the dimensions actually
                // changed — Stage1 fix: previously this also triggered on
                // any text edit (annTextIsEdited), which collapsed the
                // original PDF line breaks on a trivial word swap whose
                // box was unchanged, making CSS re-wrap to different
                // positions than the underlying raster background.
                const _richIsPromoted = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
                    || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
                    || ann.promotedFromExtraction === true;
                const _richNeedsReflow = _richIsPromoted && annotationDimensionsChanged(ann);
                const richSource = _richNeedsReflow
                    ? normalizeRichHtmlForReflow(ann._richHtml)
                    : ann._richHtml;
                item.innerHTML = normalizeRichHtmlForDisplay(richSource, ann);
            } else {
                const rawText = String(editedTexts[ann._uid] ?? ann.text ?? '');
                // For promoted extraction annotations whose box was resized but whose
                // text is still unedited, the raw ann.text contains \n characters at
                // original PDF line-break positions.  renderPlainEditorHTML converts
                // every \n to a <br>, which hard-codes the original line positions and
                // prevents CSS word-wrap from reflowing the paragraph to the new width.
                // Normalize single \n → space (preserving \n\n+ paragraph breaks) so
                // the browser can reflow freely.
                const _isPromotedExtraction = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
                    || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
                    || ann.promotedFromExtraction === true;
                const _needsReflow = _isPromotedExtraction && annotationDimensionsChanged(ann);
                const currentText = _needsReflow ? normalizeTextForDomReflow(rawText) : rawText;
                item.innerHTML = renderPlainEditorHTML(ann, currentText, scale);
            }
            layer.appendChild(item);
            if (duplicateText) {
                renderedDomTextRecords.push({
                    uid: String(ann._uid || ''),
                    text: duplicateText,
                    rect: geometry,
                });
            }

            // Grow the annotation box to envelop the actually-rendered DOM
            // height. measureEditedTextHeightPts only knows about the first-line
            // style and the plain-text wrap, so rich-html with per-span font
            // sizes / explicit <br>s / mixed line-heights can render taller than
            // the stored box. Without this, text spills below the dashed
            // bounding outline (most visible after zoom / window-resize because
            // browser line-height rounding diverges from the synthetic measure).
            const renderedPx = item.scrollHeight;
            if (!correctedSrcDom && renderedPx > height + 1) {
                const boxChanged = growAnnotationBoxToRenderedHeight(ann, item, pi);
                const grownBox = resolveAnnBox(ann);
                if (grownBox) {
                    const newTopPx = canvasHeight - (grownBox.y + grownBox.h) * scale;
                    item.style.top = `${newTopPx.toFixed(2)}px`;
                    item.style.height = `${(grownBox.h * scale).toFixed(2)}px`;
                }
                if (boxChanged && !data._richBoxFitScheduled) {
                    data._richBoxFitScheduled = true;
                    requestAnimationFrame(() => {
                        data._richBoxFitScheduled = false;
                        redrawOverlay(pi);
                    });
                }
            }
        });
    }

    // traceRoundedRectPath / drawAnnotationTypeBadge moved to ./render/badge.js (Phase 7ab).

    function redrawOverlay(pi) {
        const data = pageData[pi];
        if (!data) return;
        const canvas = document.getElementById('oc-' + (pi + 1));
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        // Stage 3: keep the page background raster in sync with the
        // committed-annotation set. Cheap no-op when the set hasn't
        // changed since the last redraw, so safe to call every frame.
        // Done before the overlay redraws so the user sees a consistent
        // raster + overlay composition in a single paint cycle.
        const bgCanvas = document.getElementById('en-canvas-' + (pi + 1));
        if (bgCanvas) applyCommittedRasterPatches(bgCanvas, data);

        // Normalise the transform so the rest of this function (and
        // everything it calls) can keep working in FIT-scale CSS pixel
        // coordinates. The backing store is `cssW × cssH × (dpr × zoom)`;
        // applying that combined scale here means draw operations issued at
        // logical px get rasterised at full physical resolution, regardless
        // of HiDPI density or the user's zoom level. Without this, curves
        // and strokes blur whenever zoom > 100% because the bitmap is
        // smaller than the displayed canvas and the browser bilinear-
        // upsamples it.
        const backing = canvas.__backingScale || overlayBackingScale();
        ctx.setTransform(backing, 0, 0, backing, 0, 0);
        ctx.clearRect(0, 0, canvasLogicalWidth(canvas), canvasLogicalHeight(canvas));
        const { scale, canvasHeight, annotations, wPts, hPts } = data;

        annotations.forEach((ann) => {
            if (ann._uid === activeState.uid && isTextAnnotation(ann)) {
                // Suppress canvas drawing only when the active editor element
                // owns the painting outright. When syncActiveEditor decided
                // renderMode='canvas' (just selected for drag/resize), or
                // 'source'/'source-flow' (actively editing pristine extracted
                // text — ae text is rendered transparent so canvas paints the
                // visible glyphs), keep canvas drawing so per-span layout
                // stays pixel-identical to the deselected baseline.
                const aeEl = document.getElementById('ae-' + (pi + 1));
                const editing = editorIsEditingAnnotation(aeEl, ann);
                const canvasOwns = activeEditorCanvasOwnsPaint(ann, aeEl);
                const editorHasVisibleText = aeEl instanceof HTMLElement
                    && aeEl.style.display !== 'none'
                    && String(aeEl.innerHTML || '').trim() !== ''
                    && !canvasOwns;
                if (editing && !canvasOwns) {
                    return;
                }
                if (editorHasVisibleText) {
                    return;
                }
            }
            if (isTextAnnotation(ann) && shouldRenderTextInRichHtmlLayer(ann)) return; // non-active DOM-rendered text
            drawEditedAnnotation(ann, ctx, scale, wPts, hPts);
        });

        if (shapeCreationState.active && shapeCreationState.pi === pi && shapeCreationState.previewAnn) {
            drawShapeAnnotation(shapeCreationState.previewAnn, ctx, scale, canvasHeight);
        }

        if (
            shapeCutState.armed
            && shapeCutState.pi === pi
            && shapeCutState.startPt
            && shapeCutState.currentPt
        ) {
            ctx.save();
            ctx.strokeStyle = 'rgba(220, 38, 38, 0.95)';
            ctx.lineWidth = 2;
            ctx.setLineDash([9, 6]);
            ctx.beginPath();
            ctx.moveTo(shapeCutState.startPt.x * scale, canvasHeight - (shapeCutState.startPt.y * scale));
            ctx.lineTo(shapeCutState.currentPt.x * scale, canvasHeight - (shapeCutState.currentPt.y * scale));
            ctx.stroke();
            ctx.restore();
        }

        if (editModeEnabled && !window.__pdfTestSuppressSelectionChrome) {
            annotations.forEach((ann) => {
                const rect = annRectPx(ann, scale, canvasHeight);
                if (!rect) return;
                ctx.save();
                ctx.strokeStyle = 'rgba(96, 165, 250, 0.95)';
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 4]);
                ctx.fillStyle = 'rgba(147, 197, 253, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            });
        } else if (addTextMode && !window.__pdfTestSuppressSelectionChrome) {
            // In addTextMode, only show outlines for user-created annotations
            // (not extracted ones) so existing PDF content looks untouched.
            annotations.filter(a => a.userCreated && a._uid !== activeState.uid).forEach((ann) => {
                const rect = annRectPx(ann, scale, canvasHeight);
                if (!rect) return;
                ctx.save();
                ctx.strokeStyle = 'rgba(96, 165, 250, 0.95)';
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 4]);
                ctx.fillStyle = 'rgba(147, 197, 253, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            });
        }

        // Hover highlight
        if ((editModeEnabled || addTextMode) && hoverState.pi === pi && hoverState.uid && hoverState.uid !== activeState.uid && !window.__pdfTestSuppressSelectionChrome) {
            const hAnn = annotations.find(a => a._uid === hoverState.uid);
            const rect = hAnn ? annRectPx(hAnn, scale, canvasHeight) : null;
            if (rect) {
                ctx.save();
                ctx.fillStyle   = 'rgba(37, 99, 235, 0.10)';
                ctx.strokeStyle = 'rgba(37, 99, 235, 0.55)';
                ctx.lineWidth   = 1;
                ctx.setLineDash([4, 4]);
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            }
        }

        // Active annotation selection outline
        if ((editModeEnabled || addTextMode) && activeState.pi === pi && activeState.uid && !window.__pdfTestSuppressSelectionChrome) {
            const aAnn = annotations.find(a => a._uid === activeState.uid);
            const rect = aAnn ? annRectPx(aAnn, scale, canvasHeight) : null;
            if (rect) {
                ctx.save();
                ctx.strokeStyle = '#2563eb';
                ctx.lineWidth   = 1.5;
                ctx.setLineDash([6, 4]);
                ctx.fillStyle   = 'rgba(37, 99, 235, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            }
        }

        renderRichHtmlLayer(pi);
        renderDebugDomLayer(pi);
    }

    // Debug DOM overlay — when ?dbgdom=1 is in the URL, mirror every
    // annotation's text into a positioned <div> with data-uid / data-type /
    // data-ann-index so devtools "Inspect element" reveals exactly which
    // annotation each piece of text belongs to. Click-through (pointer-
    // events:none) so it doesn't break editing. The label is rendered as a
    // small badge in the top-left corner of each annotation box.
    function renderDebugDomLayer(pi) {
        const layer = document.getElementById('dbgdom-' + (pi + 1));
        if (!layer) return;
        if (!document.body.classList.contains('debug-dom-on')) {
            if (layer.childNodes.length) layer.replaceChildren();
            return;
        }
        const data = pageData[pi];
        if (!data) return;
        const { scale, canvasHeight, annotations } = data;
        const frag = document.createDocumentFragment();
        annotations.forEach((ann, idx) => {
            const rect = annRectPx(ann, scale, canvasHeight);
            if (!rect) return;
            const node = document.createElement('div');
            node.className = 'debug-dom-item';
            node.dataset.uid = String(ann._uid || '');
            node.dataset.type = String(ann.type || 'text');
            node.dataset.annIndex = String(idx);
            node.dataset.userAuthored = String(!!(ann._userAuthored || ann.userAuthored));
            node.dataset.locked = String(!!ann.locked);
            node.style.left = `${rect.left}px`;
            node.style.top = `${rect.top}px`;
            node.style.width = `${rect.width}px`;
            node.style.height = `${rect.height}px`;
            const txt = String(editedTexts[ann._uid] ?? ann.text ?? '');
            const label = document.createElement('span');
            label.className = 'debug-dom-label';
            label.textContent = `[${idx}] ${ann.type || 'text'} · ${ann._uid || '(no uid)'}`;
            const body = document.createElement('span');
            body.className = 'debug-dom-text';
            body.textContent = txt;
            node.appendChild(label);
            if (txt) node.appendChild(body);
            frag.appendChild(node);
        });
        layer.replaceChildren(frag);
    }

    // ── Hit testing ───────────────────────────────────────────────────────────
    // findAnnotationAt moved to ./annotations/hit-test.js (Phase 7am).

    // canvasPointFromEvent moved to ./util/canvas-point.js (Phase 7af).

    // ── Active editor ─────────────────────────────────────────────────────────
    // escapeHtml lives in ./util/html.js — imported at the top of the file.

    // sourceLineRectWithinAnnotation moved to ./annotations/source-line-rect.js (Phase 7ak).

    function editorSpanStyle(ann, span, scale) {
        // Priority mirrors drawOriginalSource: per-span extraction data is authoritative
        // for an unmodified promoted annotation; ann-level format-bar overrides
        // (fontFamily/Weight/Style) only win once the user has explicitly changed them
        // (_styleDirty). Otherwise the dominant block-style values persisted at extraction
        // would override per-span truth here while drawOriginalSource still uses span data,
        // producing different glyph widths in the active editor vs. the loaded canvas
        // render — which makes the source-line scaleX fit reflow the text on click.
        const spanFontWeight = sourceSpanFontWeight(span);
        const spanFontStyle  = span?.fontStyle || (span?.italic ? 'italic' : 'normal');
        const styleOverride = Boolean(ann?._styleDirty);
        const annFontFamilyOverride = styleOverride ? String(ann?.fontFamily || '').trim() : '';
        const annFontWeightOverride = styleOverride ? String(ann?.fontWeight || '').trim() : '';
        const annFontStyleOverride  = styleOverride ? String(ann?.fontStyle  || '').trim() : '';
        const resolvedFontFamily = annFontFamilyOverride
            ? fallbackFontFamily(annFontFamilyOverride, annFontFamilyOverride)
            : fallbackFontFamily(span?.embedded_font_name || span?.font, span?.embedded_font_family || span?.fontFamily || '');
        return {
            fontFamily: resolvedFontFamily,
            fontSizePx: (Number(span?.font_size ?? span?.fontSize) || Number(ann?.fontSize) || 12) * fontDisplayScale(scale),
            fontWeight: annFontWeightOverride || spanFontWeight,
            fontStyle:  annFontStyleOverride  || spanFontStyle || 'normal',
            color: resolveSpanFillStyle(ann, span),
        };
    }

    // renderEditorSpanHtml + renderEditorGapHtml moved to ./editor/source-flow/spans.js (Phase 7bg).

    function setSourceSpanText(span, text) {
        if (!span || typeof span !== 'object') return;
        const value = String(text ?? '');
        span.text = value;
        span.render_text = value;
        span.rawText = value;
        if (Array.isArray(span.source_content_ops) && span.source_content_ops.length) {
            span.source_content_ops.forEach((op, index) => {
                if (op && typeof op === 'object') op.operator_text = index === 0 ? value : '';
            });
        }
    }

    function extractSourceExactLineTexts(host) {
        if (!(host instanceof HTMLElement)) return [];
        const lines = Array.from(host.querySelectorAll(':scope > [data-line-index]'));
        const out = [];
        lines.forEach((lineEl) => {
            const contentEl = lineEl.querySelector('[data-line-content="1"]') || lineEl;
            const clone = contentEl.cloneNode(true);
            if (clone instanceof HTMLElement) {
                clone.querySelectorAll('[data-source-gap]').forEach((gap) => gap.remove());
            }
            // Translate explicit user-inserted line breaks (<br>) into
            // separate lines so Enter inside the debug modal serialises to
            // a real \n on save. Without this, textContent collapses <br>
            // to nothing and the new line is silently dropped.
            const html = (clone instanceof HTMLElement ? clone.innerHTML : '')
                .replace(/<br\s*\/?\s*>/gi, '\n');
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            const text = String(tmp.textContent || '').replace(/\u00a0/g, ' ').replace(/\u200b/g, '');
            const segments = text.split('\n');
            if (segments.length === 1) {
                out.push(segments[0].trim());
            } else {
                segments.forEach((seg) => out.push(seg.trim()));
            }
        });
        return out;
    }

    function applySourceExactTextEdit(ann, lineTexts, lineSegments) {
        if (!ann || !Array.isArray(lineTexts) || !lineTexts.length) return '';
        const normalizedLines = lineTexts.map((line) => String(line ?? '').replace(/\r\n?/g, '\n'));
        // IMPORTANT: do NOT use renderableSourceLines() here. Its cache
        // (`ann._renderableSourceLines`) can survive a hydration cycle that
        // replaces `ann.sourceSpans` with a new array, leaving the cached
        // line.spans entries pointing at orphan span objects that are no
        // longer in `ann.sourceSpans`. Mutating those orphans does not
        // affect the visible canvas (which paints from `ann.sourceSpans`).
        // Resolve line→spans afresh from the live arrays.
        const liveSpans = Array.isArray(ann.sourceSpans) ? ann.sourceSpans : [];
        const liveBBoxes = Array.isArray(ann.sourceLineBBoxes) ? ann.sourceLineBBoxes : [];
        const segmentsArr = Array.isArray(lineSegments) ? lineSegments : null;

        // Line-count divergence (user inserted or removed a hard line break):
        // The 1:1 lineIndex→sourceLineBBox mapping below assumes PM's line
        // count matches the original source line count. When it doesn't,
        // mapping by index scrambles content -- e.g. inserting a break in
        // line 1 puts "head of line 1" at original line 1's bbox and "tail
        // of line 1" at original line 2's bbox (overwriting unrelated
        // content), then the real line 2 lands at line 3's slot, etc.
        // Drop the source-exact rendering flags so the canvas/editor
        // reflows the edited text inside the annotation's bounding box
        // using its base font (the regular plain-text path) -- a clean
        // reflow is far better than a scrambled per-span paint, and the
        // backend renderer treats it as a plain promoted text edit.
        if (liveBBoxes.length > 0 && normalizedLines.length !== liveBBoxes.length) {
            ann.sourceTextLines = normalizedLines;
            ann.text = normalizedLines.join('\n');
            ann.promotedDirty = true;
            ann.sourceExactTextEdited = false;
            ann._sourceExactTextEdited = false;
            ann.preserveSourceLayout = false;
            delete ann._richHtml;
            delete ann._renderableSourceLines;
            if (ann.annotation_data && typeof ann.annotation_data === 'object') {
                ann.annotation_data.text = ann.text;
                ann.annotation_data.sourceTextLines = normalizedLines.slice();
                ann.annotation_data.promotedDirty = true;
                ann.annotation_data.sourceExactTextEdited = false;
                ann.annotation_data.preserveSourceLayout = false;
            }
            return ann.text;
        }

        normalizedLines.forEach((lineText, lineIndex) => {
            const lineBBox = liveBBoxes[lineIndex] || null;
            const matchedSpans = lineBBox
                ? liveSpans.filter((span) => spanMatchesLineBBox(span, lineBBox))
                : (liveSpans[lineIndex] ? [liveSpans[lineIndex]] : []);
            const firstSpan = matchedSpans[0] || null;
            if (!firstSpan) return;

            // Per-segment update path: when PM provides one segment per
            // original span (the common case for inline edits that don't
            // restructure formatting boundaries), write each segment's
            // text into its corresponding span so per-span attributes
            // (bold, italic, link colors, embedded fonts) survive the
            // edit. Fall back to the legacy "dump everything into the
            // first span and empty the rest" behaviour when the segment
            // and span counts diverge (e.g. user typed across a format
            // boundary), since we can't safely realign without changing
            // the span schema.
            const segs = segmentsArr ? segmentsArr[lineIndex] : null;
            if (segs && segs.length === matchedSpans.length && matchedSpans.length > 0) {
                segs.forEach((seg, i) => {
                    setSourceSpanText(matchedSpans[i], String(seg.text ?? ''));
                });
                // Do NOT touch per-span bbox/origin in the
                // per-segment path -- each span keeps its own slot,
                // so stretching span 0 to the full line bbox would
                // make its rendered text overlap the others.
            } else {
                setSourceSpanText(firstSpan, lineText);
                for (let i = 1; i < matchedSpans.length; i++) {
                    setSourceSpanText(matchedSpans[i], '');
                }
                // Legacy fallback collapses everything into span 0;
                // expand its bbox/origin to cover the whole line so
                // the canvas paints the merged text across the row.
                if (lineBBox && lineBBox.length >= 4) {
                    firstSpan.bbox = lineBBox.slice(0, 4);
                    if (Array.isArray(firstSpan.origin) && firstSpan.origin.length >= 2) {
                        firstSpan.origin = [Number(lineBBox[0]), Number(firstSpan.origin[1])];
                    }
                }
            }
        });

        ann.sourceTextLines = normalizedLines;
        ann.text = normalizedLines.join('\n');
        ann.promotedDirty = true;
        ann.sourceExactTextEdited = true;
        ann.preserveSourceLayout = true;
        ann._sourceExactTextEdited = true;
        delete ann._richHtml;
        delete ann._renderableSourceLines;
        if (ann.annotation_data && typeof ann.annotation_data === 'object') {
            ann.annotation_data.text = ann.text;
            ann.annotation_data.sourceTextLines = normalizedLines.slice();
            ann.annotation_data.sourceSpans = Array.isArray(ann.sourceSpans)
                ? JSON.parse(JSON.stringify(ann.sourceSpans))
                : ann.sourceSpans;
            ann.annotation_data.promotedDirty = true;
            ann.annotation_data.sourceExactTextEdited = true;
            ann.annotation_data.preserveSourceLayout = true;
        }
        return ann.text;
    }

    function buildLineInnerHtml(ann, lineIndex, scale, spans) {
        if (!Array.isArray(spans) || !spans.length) return '';

        const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox) ? renderableSourceLines(ann)[lineIndex].bbox : null;
        let cursorPts = lineBBox ? Number(lineBBox[0]) : null;
        let html = '';

        spans.forEach((span) => {
            const drawText = sourceSpanText(span);
            if (!drawText) return;

            const style = editorSpanStyle(ann, span, scale);
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            // Mirror drawOriginalSource: use origin[0] as the span's x position when
            // available, falling back to bbox[0] then cursorPts.
            // This matches the canvas which draws at origin[0] coordinates.
            const spanStartPts = origin ? Number(origin[0]) : (bbox ? Number(bbox[0]) : cursorPts);

            if (cursorPts !== null && Number.isFinite(spanStartPts)) {
                const gapPts = spanStartPts - cursorPts;
                if (gapPts > 0.25) {
                    const measuredSpacePx = Math.max(
                        1,
                        (Number(span?.space_width) || 0) * scale,
                        measureTextWidth(' ', {
                            fontFamily: style.fontFamily,
                            fontSizePx: style.fontSizePx,
                            fontWeight: style.fontWeight,
                            fontStyle: style.fontStyle,
                        }) || 0
                    );
                    const gapPx = gapPts * scale;
                    const spaceCount = Math.max(1, Math.round(gapPx / measuredSpacePx));
                    html += renderEditorGapHtml(style, gapPx, spaceCount);
                }
            }

            html += renderEditorSpanHtml(style, drawText);

            // Advance cursor from span's origin by the bbox width — mirrors how
            // drawOriginalSource occupies space: drawn at origin[0], occupies bboxWidth pts.
            if (Number.isFinite(spanStartPts) && bbox) {
                cursorPts = spanStartPts + (Number(bbox[2]) - Number(bbox[0]));
            } else if (Number.isFinite(spanStartPts)) {
                cursorPts = spanStartPts + (measureTextWidth(drawText, {
                    fontFamily: style.fontFamily,
                    fontSizePx: style.fontSizePx,
                    fontWeight: style.fontWeight,
                    fontStyle: style.fontStyle,
                }) / scale);
            }
        });

        return html;
    }

    function buildSourceLineEditorHTML(ann, lineIndex, scale) {
        const spans = lineSpans(ann, lineIndex);
        const style = compositeLineStyle(ann, lineIndex);
        const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale);
        const topShiftPx = editorLineTopShiftPx(ann, lineIndex, scale);
        const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
        const scaleX = editorLineScaleX(ann, lineIndex, scale, spans);
        const lineRotation = sourceLineRotationDegrees(ann, lineIndex);
        const wrapperWidthPx = rect
            ? (Math.abs(lineRotation) === 90 ? Math.max(2, rect.height) : Math.max(2, rect.width))
            : null;
        const wrapperHeightPx = rect
            ? (Math.abs(lineRotation) === 90 ? Math.max(lineHeightPx, rect.width || 0) : Math.max(lineHeightPx, rect.height || 0))
            : lineHeightPx;
        const wrapperTransform = (() => {
            const transforms = [];
            if (Math.abs(lineRotation) === 90 && rect) {
                if (lineRotation > 0) {
                    transforms.push(`translateX(${Math.max(2, rect.width).toFixed(2)}px)`);
                    transforms.push('rotate(90deg)');
                } else {
                    transforms.push(`translateY(${Math.max(2, rect.height).toFixed(2)}px)`);
                    transforms.push('rotate(-90deg)');
                }
            }
            if (topShiftPx > 0.01) {
                transforms.push(`translateY(-${topShiftPx.toFixed(2)}px)`);
            }
            return transforms.length ? transforms.join(' ') : 'none';
        })();
        const lineWrapperStyle = [
            'position:absolute',
            rect ? `left:${rect.left.toFixed(2)}px` : 'left:0',
            rect ? `top:${rect.top.toFixed(2)}px` : 'top:0',
            wrapperWidthPx !== null ? `width:${wrapperWidthPx.toFixed(2)}px` : 'width:100%',
            `height:${wrapperHeightPx.toFixed(2)}px`,
            `min-height:${wrapperHeightPx.toFixed(2)}px`,
            `font-family:${style.fontFamily}`,
            `font-size:${(style.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px`,
            `font-weight:${style.fontWeight}`,
            `font-style:${style.fontStyle}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            `color:${style.fillStyle}`,
            `transform:${wrapperTransform}`,
            'transform-origin:top left',
            'padding:0',
            'margin:0',
            'white-space:normal',
            'overflow:visible',
        ].join(';');
        const contentStyle = [
            'display:inline-block',
            'width:max-content',
            'min-width:max-content',
            `transform:scaleX(${scaleX.toFixed(4)})`,
            'transform-origin:top left',
            'white-space:pre',
            'overflow-wrap:normal',
            'word-break:normal',
            'padding:0',
            'margin:0',
        ].join(';');

        if (!spans.length) {
            const fallbackLine = String(renderableSourceLines(ann)[lineIndex]?.text ?? '');
            return `<div data-line-index="${lineIndex}" style="${lineWrapperStyle}"><span data-line-content="1" style="${contentStyle}">${escapeHtml(fallbackLine) || '<br>'}</span></div>`;
        }

        const innerHtml = buildLineInnerHtml(ann, lineIndex, scale, spans);

        return `<div data-line-index="${lineIndex}" style="${lineWrapperStyle}"><span data-line-content="1" style="${contentStyle}">${innerHtml || '<br>'}</span></div>`;
    }

    function buildSourceFlowLineEditorHTML(ann, lineIndex, scale) {
        const spans = lineSpans(ann, lineIndex);
        const style = compositeLineStyle(ann, lineIndex);
        const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale);
        const lineStyle = [
            'display:block',
            // Keep the source line on a single visual line — the canvas render
            // never wraps these promoted lines, and using width:100% with
            // pre-wrap caused inline-block gap spans (and long leader-dot
            // tails) to break onto a second line on entering edit mode.
            'width:max-content',
            'min-width:100%',
            'box-sizing:border-box',
            `font-family:${style.fontFamily}`,
            `font-size:${(style.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px`,
            `font-weight:${style.fontWeight}`,
            `font-style:${style.fontStyle}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            `min-height:${lineHeightPx.toFixed(2)}px`,
            `color:${style.fillStyle}`,
            `text-decoration:${ann.underline ? 'underline' : 'none'}`,
            'padding:0',
            'margin:0',
            'white-space:pre',
            'overflow-wrap:normal',
            'word-break:normal',
        ].join(';');

        const fallbackLine = String(renderableSourceLines(ann)[lineIndex]?.text ?? '');
        const innerHtml = spans.length
            ? buildLineInnerHtml(ann, lineIndex, scale, spans)
            : escapeHtml(fallbackLine);

        return `<div data-line-index="${lineIndex}" style="${lineStyle}">${innerHtml || '<br>'}</div>`;
    }

    function buildSourceFlowEditorHTML(ann, scale) {
        const lines = renderableSourceLines(ann);
        if (lines.length) {
            return lines.map((_, lineIndex) => buildSourceFlowLineEditorHTML(ann, lineIndex, scale)).join('');
        }

        const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
        if (spans.length) {
            const style = compositeLineStyle(ann, 0);
            const lineHeightPx = blockLineHeightPx(ann, 0, scale, style);
            return `<div data-line-index="0" style="display:block;width:max-content;min-width:100%;box-sizing:border-box;font-family:${style.fontFamily};font-size:${(style.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};line-height:${lineHeightPx.toFixed(2)}px;min-height:${lineHeightPx.toFixed(2)}px;color:${style.fillStyle};text-decoration:${ann.underline ? 'underline' : 'none'};padding:0;margin:0;white-space:pre;overflow-wrap:normal;word-break:normal;">${buildLineInnerHtml(ann, 0, scale, spans) || '<br>'}</div>`;
        }

        return renderPlainEditorHTML(ann, String(ann.text ?? ''), scale);
    }

    function shouldUseSourceFlowEditorHTML(ann, editedText, options = {}) {
        if (!ann || ann._richHtml) return false;
        const allowCurrentBoxSourceFlow = Boolean(options.allowCurrentBoxSourceFlow);
        const hasSourceContent = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
        if (!hasSourceContent) return false;
        if (!annotationTextMatchesSource(ann, editedText !== undefined ? editedText : ann.text)) return false;
        // The absolute per-line `source` layout pins each rendered line to its
        // exact PDF baseline (sourceLineRectWithinAnnotation + scaleX), so a
        // native browser selection rectangle drawn over the editor overlays
        // the canvas-painted glyphs pixel-for-pixel. The flow layout instead
        // stacks lines via CSS line-height starting at top:0 of the editor,
        // which drifts from the per-line PDF baselines and made the
        // selection rect (and the ghost text revealed when the user begins
        // typing) visibly offset from the canvas paint on multi-line
        // canvas-owned blocks. Resized annotations must leave source-flow
        // too: its one-block-per-PDF-line structure preserves the old line
        // breaks, while plain editor HTML collapses extraction soft breaks
        // and lets CSS wrap to the current box width.
        if (!allowCurrentBoxSourceFlow) return false;
        if (annotationDimensionsChanged(ann)) return false;
        if (editedText !== undefined && editedText !== String(ann.text ?? '')) return false;
        if (annTextIsEdited(ann)) return false;
        // Note: `promotedDirty` is intentionally NOT a disqualifier. It can
        // remain set after a prior resize/move while the underlying text and
        // per-span source data are still 100% accurate. The canvas renderer
        // uses the same source spans regardless of promotedDirty, so the
        // source-flow editor must too — otherwise selecting/editing a
        // promoted block visibly collapses leader-dot gaps and drops bold
        // styling that the canvas was painting just fine.
        if (ann.userCreated || ann._styleDirty) return false;
        if (!allowCurrentBoxSourceFlow && ann.userAuthored) return false;
        return true;
    }

    function sourceLinesLookLikeWrappedParagraph(lines) {
        const texts = (Array.isArray(lines) ? lines : [])
            .map((line) => String(line?.text ?? '').trim())
            .filter((lineText) => lineText.length > 0);
        if (texts.length < 2) return false;
        const joined = texts.join(' ').replace(/\s+/g, ' ').trim();
        if (joined.length < 80) return false;
        if (/(?:\.\s*){6,}|_{4,}|\.{4,}/.test(joined)) return false;
        const hardListLines = texts.filter((lineText) => /^\s*(?:[\u2022\u25cf*\-]|\d+[.)]|[A-Z][.)])\s+/.test(lineText)).length;
        if (hardListLines >= Math.max(2, Math.ceil(texts.length * 0.45))) return false;
        const averageWords = texts.reduce((sum, lineText) => sum + lineText.split(/\s+/).filter(Boolean).length, 0) / texts.length;
        if (averageWords < 4) return false;
        return /[.!?]/.test(joined);
    }

    function sourceLineAdvancePx(ann, scale) {
        const lines = renderableSourceLines(ann)
            .map((line) => Array.isArray(line?.bbox) ? line.bbox : null)
            .filter((bbox) => bbox && bbox.length >= 4)
            .slice()
            .sort((left, right) => Number(left[1]) - Number(right[1]));
        const advances = [];
        for (let index = 1; index < lines.length; index += 1) {
            const advancePts = Number(lines[index][1]) - Number(lines[index - 1][1]);
            if (Number.isFinite(advancePts) && advancePts > 0.5) advances.push(advancePts);
        }
        if (advances.length) {
            advances.sort((left, right) => left - right);
            return advances[Math.floor(advances.length / 2)] * scale;
        }
        return 0;
    }

    function shouldUsePromotedParagraphEditorHTML(ann, editedText) {
        if (!ann || ann._richHtml) return false;
        if (ann.promotedFromExtraction !== true) return false;
        if (ann.userCreated || ann._styleDirty) return false;
        if (annotationDimensionsChanged(ann)) return false;
        const lines = renderableSourceLines(ann);
        if (!sourceLinesLookLikeWrappedParagraph(lines)) return false;
        for (let index = 0; index < lines.length; index += 1) {
            if (Math.abs(sourceLineRotationDegrees(ann, index)) > 0.25) return false;
        }
        const text = editedText !== undefined ? editedText : String(ann.text ?? '');
        return annotationTextMatchesSource(ann, text);
    }

    function renderPromotedParagraphEditorHTML(ann, text, scale) {
        const style = editableLineStyle(ann, 0);
        const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
        const lineHeightPx = Math.max(
            fontSizePx * 1.18,
            sourceLineAdvancePx(ann, scale),
            blockLineHeightPx(ann, 0, scale, style)
        );
        const paragraphText = normalizeTextForDomReflow(text !== undefined ? text : String(ann.text ?? ''));
        const paragraphStyle = [
            'display:block',
            'width:100%',
            'box-sizing:border-box',
            'font-family:inherit',
            'font-size:inherit',
            'font-weight:inherit',
            'font-style:inherit',
            'letter-spacing:inherit',
            'color:inherit',
            `text-decoration:${ann.underline ? 'underline' : 'none'}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            `min-height:${lineHeightPx.toFixed(2)}px`,
            `text-align:${ann.textAlign || 'left'}`,
            'padding:0',
            'margin:0',
            'white-space:normal',
            'overflow-wrap:normal',
            'word-break:normal',
        ].join(';');
        return `<div data-promoted-paragraph-editor="1" style="${paragraphStyle}">${renderPlainEditorInnerHtml(paragraphText)}</div>`;
    }

    // ── Galley editor (Iceni-style) ──────────────────────────────────────────
    // Builds an absolute-positioned per-source-span DOM inside the active editor
    // for extracted-text annotations. The page overlay canvas continues to paint
    // every original PDF span via drawOriginalSource (preserve path), so the
    // visible glyphs come from canvas-painted source. The contenteditable layer
    // exists only to host the caret + selection at PDF-accurate per-span
    // positions, and to receive newly-typed characters in a per-line "tail"
    // span. Tail content is mirrored into ann._galleyAppends so drawOriginalSource
    // can paint the appended text after blur (when ae becomes display:none).
    function renderGalleyEditorHTML(ann, scale) {
        const lines = renderableSourceLines(ann);
        if (!lines.length) return '';
        const box = resolveAnnBox(ann);
        if (!box) return '';
        const offset = annotationSourceOffset(ann);
        const appends = (ann._galleyAppends && typeof ann._galleyAppends === 'object') ? ann._galleyAppends : {};

        const html = lines.map((line, lineIndex) => {
            const lineBBox = Array.isArray(line.bbox) ? line.bbox : null;
            const lineSpans = Array.isArray(line.spans) ? line.spans : [];
            if (!lineBBox || lineSpans.length === 0) return '';

            const lineLeftPts = Number(lineBBox[0]);
            const lineRightPts = Number(lineBBox[2]);
            // PyMuPDF source-line bboxes are stored TOP-DOWN as
            // [x0, y0_top, x1, y1_bottom] with y measured from page top.
            // Place the line at its top-down offset from the source block's
            // top edge. ae is already positioned at the current annotation
            // box, so spans only need the within-block offset; offset.dy
            // (added when the user resized the annotation) shifts the entire
            // source layout in lock-step with the new top edge.
            const lineTopRelTopDown = Number(lineBBox[1]) - Number(ann.sourceBlockTop ?? lineBBox[1]);
            const lineLeftPx = (lineLeftPts + offset.dx - box.x) * scale;
            const lineTopPx = (lineTopRelTopDown + offset.dy) * scale;
            const lineWidthPx = Math.max(2, (lineRightPts - lineLeftPts) * scale);
            const lineStyle = compositeLineStyle(ann, lineIndex);
            const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale, lineStyle);
            const lineFontPx = lineStyle.fontSizePt * fontDisplayScale(scale);

            const spanHtml = lineSpans.map((span, spanIdx) => {
                const drawText = sourceSpanText(span);
                if (!drawText) return '';
                const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                if (!bbox || bbox.length < 4) return '';
                const spanLeftPx = (Number(bbox[0]) - lineLeftPts) * scale;
                const spanWidthPx = Math.max(0.5, (Number(bbox[2]) - Number(bbox[0])) * scale);

                const fontFamily = fallbackFontFamily(
                    span?.embedded_font_name || span?.font || lineStyle.fontFamily,
                    span?.embedded_font_family || span?.fontFamily || ''
                );
                const spanFontPx = (Number(span?.font_size ?? span?.fontSize) || lineStyle.fontSizePt) * fontDisplayScale(scale);
                const spanFontWeight = sourceSpanFontWeight(span);
                const spanFontStyle = span?.fontStyle || (span?.italic ? 'italic' : 'normal');

                const styleCss = [
                    `left:${spanLeftPx.toFixed(2)}px`,
                    `width:${spanWidthPx.toFixed(2)}px`,
                    `height:${lineHeightPx.toFixed(2)}px`,
                    `line-height:${lineHeightPx.toFixed(2)}px`,
                    `font-family:${fontFamily}`,
                    `font-size:${spanFontPx.toFixed(2)}px`,
                    `font-weight:${spanFontWeight}`,
                    `font-style:${spanFontStyle}`,
                    'overflow:hidden',
                ].join(';');

                return `<span class="ae-galley-source-span" data-source-span="1" data-line-index="${lineIndex}" data-span-index="${spanIdx}" data-original-text="${escapeHtml(drawText)}" style="${styleCss}">${escapeHtml(drawText)}</span>`;
            }).join('');

            // Tail span: sits at the right edge of the last source span, where
            // appended chars accumulate. Pre-populated from ann._galleyAppends
            // when re-entering edit mode after a previous append.
            const lastSpan = lineSpans[lineSpans.length - 1];
            const tailLeftPx = (lastSpan && Array.isArray(lastSpan.bbox))
                ? (Number(lastSpan.bbox[2]) - lineLeftPts) * scale
                : lineWidthPx;
            const appendedText = String(appends[lineIndex] || '');
            const tailColor = lineStyle.fillStyle;
            const tailStyleCss = [
                `left:${tailLeftPx.toFixed(2)}px`,
                `height:${lineHeightPx.toFixed(2)}px`,
                `line-height:${lineHeightPx.toFixed(2)}px`,
                `font-family:${lineStyle.fontFamily}`,
                `font-size:${lineFontPx.toFixed(2)}px`,
                `font-weight:${lineStyle.fontWeight}`,
                `font-style:${lineStyle.fontStyle}`,
                `color:${tailColor}`,
                'min-width:2px',
            ].join(';');
            const tailNewAttr = appendedText ? ' data-galley-new="1"' : '';

            const lineStyleCss = [
                `left:${lineLeftPx.toFixed(2)}px`,
                `top:${lineTopPx.toFixed(2)}px`,
                `width:${lineWidthPx.toFixed(2)}px`,
                `height:${lineHeightPx.toFixed(2)}px`,
                `font-family:${lineStyle.fontFamily}`,
                `font-size:${lineFontPx.toFixed(2)}px`,
                `font-weight:${lineStyle.fontWeight}`,
                `font-style:${lineStyle.fontStyle}`,
                `color:${tailColor}`,
            ].join(';');

            return `<p class="ae-galley-line" data-line-index="${lineIndex}" style="${lineStyleCss}">${spanHtml}<span class="ae-galley-tail" data-galley-tail="1" data-line-index="${lineIndex}"${tailNewAttr} style="${tailStyleCss}">${escapeHtml(appendedText)}</span></p>`;
        }).join('');

        return html;
    }

    // Walk a galley editor's DOM in document order and concatenate the text
    // that should logically be in editedTexts: source-span text + tail text per
    // line, joined with '\n'.
    // getGalleyEditorText / expectedGalleyText / diffGalleyAdded moved to
    // ./editor/galley/text.js (Phase 7bc).

    // After any input event in galley mode: detect added chars, restore any
    // mutated source-span text to the original extraction value, and append
    // the added chars into the LAST line's tail span (so they remain visible
    // via the canvas-owns visibility override). Persist to ann._galleyAppends.
    function reconcileGalleyInput(ae, ann, pi) {
        if (!(ae instanceof HTMLElement) || !ann) return;
        const tails = ae.querySelectorAll('.ae-galley-tail');
        if (!tails.length) return;
        const sourceSpans = ae.querySelectorAll('.ae-galley-source-span');

        // The "previous" baseline is what the galley DOM held BEFORE the
        // browser mutated it (source originals + ann._galleyAppends per line).
        // Diffing against ann.text would falsely flag positional gaps between
        // source spans (e.g. leader dots) as newly-typed characters.
        const prevText = expectedGalleyText(ann, ae);
        const observed = getGalleyEditorText(ae, ann);
        const added = diffGalleyAdded(prevText, observed);

        // DEBUG probe (removed before final commit): capture first reconcile
        // for select uids so we can inspect prev vs observed vs added.
        try {
            window.__galleyDbg2 = window.__galleyDbg2 || {};
            const k = ann._uid;
            if (k && !(k in window.__galleyDbg2)) {
                window.__galleyDbg2[k] = {
                    prev: prevText,
                    observed,
                    added,
                    appendsBefore: JSON.parse(JSON.stringify(ann._galleyAppends || {})),
                    sourceSpanCount: sourceSpans.length,
                    tailCount: tails.length,
                    sourceSpansData: (Array.isArray(ann.sourceSpans) ? ann.sourceSpans : []).map(s => ({
                        text: sourceSpanText(s),
                        font: s.embedded_font_name || s.font,
                        weight: s.font_weight || s.fontWeight || (s.bold ? '700' : ''),
                        style: s.fontStyle || (s.italic ? 'italic' : ''),
                        bold: !!s.bold,
                        italic: !!s.italic,
                    })),
                };
            }
        } catch (_e) {}

        // Restore any source span whose textContent diverged from its data-original-text
        // (caret was inside a source span when the browser inserted text).
        let restoredAny = false;
        sourceSpans.forEach((sp) => {
            const orig = sp.dataset.originalText || '';
            if (sp.textContent !== orig) {
                sp.textContent = orig;
                restoredAny = true;
            }
        });

        const lastTail = tails[tails.length - 1];
        const lineIdx = Number(lastTail.dataset.lineIndex || 0);
        if (added) {
            lastTail.dataset.galleyNew = '1';
            ann._galleyAppends = ann._galleyAppends || {};
            ann._galleyAppends[lineIdx] = (ann._galleyAppends[lineIdx] || '') + added;
            lastTail.textContent = ann._galleyAppends[lineIdx];
            ann._galleyEdited = true;

            // Re-place caret at end of the tail (the source-span restore above
            // would otherwise leave the caret in a stale node).
            try {
                const sel = window.getSelection();
                if (sel) {
                    sel.removeAllRanges();
                    const range = document.createRange();
                    if (lastTail.firstChild && lastTail.firstChild.nodeType === Node.TEXT_NODE) {
                        range.setStart(lastTail.firstChild, lastTail.firstChild.length);
                    } else {
                        range.selectNodeContents(lastTail);
                        range.collapse(false);
                    }
                    range.collapse(true);
                    sel.addRange(range);
                }
            } catch (_e) { /* selection placement best-effort */ }
        } else if (restoredAny) {
            // Caret may have been inside a restored source span; place it at end of last tail.
            try {
                const sel = window.getSelection();
                if (sel) {
                    sel.removeAllRanges();
                    const range = document.createRange();
                    range.selectNodeContents(lastTail);
                    range.collapse(false);
                    sel.addRange(range);
                }
            } catch (_e) { /* ignore */ }
        }

        // Update editedTexts from the now-canonical galley DOM. Use
        // expectedGalleyText so the value uses source originals (consistent
        // even if a browser quirk left a source span temporarily mutated).
        if (typeof editedTexts !== 'undefined') {
            editedTexts[ann._uid] = expectedGalleyText(ann, ae);
        }
    }

    // Build innerHTML that mirrors the extracted source lines as closely as possible:
    // one block per extracted line, preserving line heights plus per-span inline styles.
    function buildEditorHTML(ann, scale) {
        const lineBBoxes = renderableSourceLines(ann);
        if (lineBBoxes.length) {
            return lineBBoxes.map((_, lineIndex) => buildSourceLineEditorHTML(ann, lineIndex, scale)).join('');
        }

        const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
        if (!spans.length) return escapeHtml(String(ann.text ?? ''));

        const groupedLines = [];
        let currentLine = [];
        let prevY = null;

        spans.forEach((span) => {
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            const drawText = sourceSpanText(span);
            if (!drawText) return;
            const spanY = origin ? Number(origin[1]) : prevY;
            if (prevY !== null && spanY !== null && Math.abs(spanY - prevY) > 1 && currentLine.length) {
                groupedLines.push(currentLine);
                currentLine = [];
            }
            currentLine.push(span);
            if (spanY !== null) prevY = spanY;
        });

        if (currentLine.length) groupedLines.push(currentLine);

        if (!groupedLines.length) return escapeHtml(String(ann.text ?? ''));

        return groupedLines.map((lineSpansForHtml, lineIndex) => {
            const style = compositeLineStyle(ann, lineIndex);
            const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale);
            const topShiftPx = editorLineTopShiftPx(ann, lineIndex, scale);
            const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
            const innerHtml = buildLineInnerHtml(ann, lineIndex, scale, lineSpansForHtml);
            const scaleX = editorLineScaleX(ann, lineIndex, scale, lineSpansForHtml);
            const lineRotation = sourceLineRotationDegrees(ann, lineIndex);
            const wrapperWidthPx = rect
                ? (Math.abs(lineRotation) === 90 ? Math.max(2, rect.height) : Math.max(2, rect.width))
                : null;
            const wrapperHeightPx = rect
                ? (Math.abs(lineRotation) === 90 ? Math.max(lineHeightPx, rect.width || 0) : Math.max(lineHeightPx, rect.height || 0))
                : lineHeightPx;
            const wrapperTransform = (() => {
                const transforms = [];
                if (Math.abs(lineRotation) === 90 && rect) {
                    if (lineRotation > 0) {
                        transforms.push(`translateX(${Math.max(2, rect.width).toFixed(2)}px)`);
                        transforms.push('rotate(90deg)');
                    } else {
                        transforms.push(`translateY(${Math.max(2, rect.height).toFixed(2)}px)`);
                        transforms.push('rotate(-90deg)');
                    }
                }
                if (topShiftPx > 0.01) {
                    transforms.push(`translateY(-${topShiftPx.toFixed(2)}px)`);
                }
                return transforms.length ? transforms.join(' ') : 'none';
            })();
            return `<div data-line-index="${lineIndex}" style="position:absolute;left:${(rect?.left || 0).toFixed(2)}px;top:${(rect?.top || 0).toFixed(2)}px;${wrapperWidthPx !== null ? `width:${wrapperWidthPx.toFixed(2)}px;` : 'width:100%;'}height:${wrapperHeightPx.toFixed(2)}px;min-height:${wrapperHeightPx.toFixed(2)}px;font-family:${style.fontFamily};font-size:${(style.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};text-decoration:${ann.underline ? 'underline' : 'none'};line-height:${lineHeightPx.toFixed(2)}px;color:${style.fillStyle};transform:${wrapperTransform};transform-origin:top left;padding:0;margin:0;white-space:normal;overflow:visible;"><span data-line-content="1" style="display:inline-block;width:max-content;min-width:max-content;transform:scaleX(${scaleX.toFixed(4)});transform-origin:top left;white-space:pre;overflow-wrap:normal;word-break:normal;padding:0;margin:0;">${innerHtml || '<br>'}</span></div>`;
        }).join('');
    }

    function renderPlainEditorHTML(ann, text, scale) {
        const maxSourceIndex = Math.max(0, renderableSourceLines(ann).length - 1);
        const style = editableLineStyle(ann, 0);
        // Stage1 line-height unification: use the shared helper so the
        // active-editor (this) and the deselected rich-html-layer paint
        // at identical line-heights — eliminates baseline jump on
        // enter/exit edit. 1.18× matches the canvas glyph leading used
        // in galley mode.
        const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
        const lineHeightPx = domAndCanvasLineHeightPx(ann, 0, scale, style);
        // The browser puts (line-height - font-size) / 2 of leading above
        // the first text line within its line box. The canvas paints the
        // first glyph baseline directly at the PDF y, so canvas-rendered
        // text sits closer to the box top. Pull the inner block up by the
        // half-leading so the first visible line aligns with where canvas
        // would have drawn it — eliminates the small downward jump on
        // entering edit mode.
        const topShiftPx = (lineHeightPx - fontSizePx) / 2;
        const lineAlign = ann.textAlign || 'left';

        // Build the inner HTML. When the annotation has per-span style
        // variations (bold runs, italic runs from PDF extraction) AND the
        // user hasn't diverged from the source text yet, render each span
        // with its own weight/style so bold paragraph headers etc. survive
        // the click-into-edit. Otherwise fall back to plain reflowed text.
        const canUseSourceRichInner = !annotationDimensionsChanged(ann);
        const innerHtml = (canUseSourceRichInner ? renderRichEditorInnerHtml(ann, text) : '')
            || renderPlainEditorInnerHtml(text);

        // Suppress maxSourceIndex lint noise — retained for future per-line overrides.
        void maxSourceIndex;
        // Critical: do NOT hardcode font-family, font-size, color, font-weight, or
        // font-style here. They must inherit from the outer container (ae or
        // rich-html-item) — that container's style is rebuilt on every format-bar
        // change via syncActiveEditor/renderRichHtmlLayer. If we bake those
        // properties in here they'd override the outer container and format-bar
        // changes (font family / size / color) wouldn't take effect once this
        // HTML has been persisted into ann._richHtml.
        return `<div data-line-index="0" style="display:block;width:100%;box-sizing:border-box;font-family:inherit;font-size:inherit;font-weight:inherit;font-style:inherit;letter-spacing:inherit;color:inherit;text-decoration:${ann.underline ? 'underline' : 'none'};line-height:${lineHeightPx.toFixed(2)}px;min-height:${lineHeightPx.toFixed(2)}px;text-align:${lineAlign};transform:translateY(-${topShiftPx.toFixed(2)}px);transform-origin:top left;padding:0;margin:0;white-space:pre-wrap;overflow-wrap:break-word;word-break:break-word;">${innerHtml}</div>`;
    }

    // Render reflowed plain text — the caller is responsible for any
    // soft-wrap collapsing (via normalizeTextForDomReflow) before calling
    // us. Here we honor every \n as a literal line break so user-typed
    // newlines (e.g. bullet items) survive into the DOM. Previously this
    // helper collapsed every single \n to a space "as a PDF-extraction
    // soft-wrap artifact", which deleted the user's intentional line
    // breaks the moment a promoted-extraction annotation was edited (the
    // doc 4001 promoted_2_8_141 bug: each bullet item ended up on the
    // same line, link breaks gone).
    //
    // Hanging-indent: when a line begins with a bullet marker (U+2022,
    // U+25CF, '*', '-', etc.) followed by whitespace, subsequent lines
    // (until the next bullet or a blank line) are continuation lines of
    // that bullet -- in the original PDF extraction they were positioned
    // at the same x-offset as the text after the bullet. Pre-pad those
    // continuation lines with spaces equal to the bullet+gap width so
    // when rendered with `white-space: pre-wrap` they visually hang under
    // the bullet's text instead of returning to column 0 (the doc 4001
    // promoted_2_8_141 'spacing and indents not accurate' bug).
    function renderPlainEditorInnerHtml(text) {
        const normalized = String(text ?? '').replace(/\r\n?/g, '\n');
        const lines = normalized.split('\n');
        const bulletRe = /^(\s*)([\u2022\u25CF\u25E6\u2023\u2043\u2219*\-])(\s+)/;
        let indentPrefix = '';
        const out = [];
        for (const line of lines) {
            const m = bulletRe.exec(line);
            if (m) {
                indentPrefix = ' '.repeat(m[1].length + m[2].length + m[3].length);
                out.push(escapeHtml(line));
            } else if (line.trim() === '') {
                indentPrefix = '';
                out.push(escapeHtml(line));
            } else if (indentPrefix) {
                out.push(escapeHtml(indentPrefix + line));
            } else {
                out.push(escapeHtml(line));
            }
        }
        return out.join('<br>') || '<br>';
    }

    // When the annotation's pristine extracted text contains per-span style
    // variation (bold, italic from PDF extraction), rebuild the inner HTML
    // with one <span> per source span carrying the original font-weight /
    // font-style. This preserves bold paragraph headers etc. when the user
    // first clicks into edit. Returns '' when the annotation has no spans
    // or when the user-edited text no longer matches the source — in that
    // case caller falls back to plain reflowed text.
    function renderRichEditorInnerHtml(ann, text) {
        const lines = renderableSourceLines(ann);
        if (!lines.length) return '';
        // The rich path renders directly from sourceSpans and IGNORES the
        // `text` parameter. So it can only be used when the annotation's
        // text is still pristine — i.e. matches the original extraction.
        // Once the user edits, ann.text diverges from originalText and we
        // MUST fall back to the plain renderer (which honors `text`
        // literally) or the user's edits would be silently overwritten by
        // the source on every input. NB: comparing against ann.text is
        // wrong here because ann.text ITSELF is the edited value -- it
        // would always tautologically match the editedTexts[uid] copy
        // passed in via `text`, leaving the rich-from-spans path
        // mistakenly active long after the text was edited away (the
        // "deleted text comes back as duplicated original" bug on
        // promoted annotations).
        if (typeof annTextIsEdited === 'function' && annTextIsEdited(ann)) return '';
        let hasAnySpan = false;
        for (const line of lines) {
            if (Array.isArray(line.spans) && line.spans.length) { hasAnySpan = true; break; }
        }
        if (!hasAnySpan) return '';
        // Distribute each `line.text` (the canonical text-extraction-layer
        // string that includes inter-span whitespace) across `line.spans`
        // by walking spans in order and finding each span's own text inside
        // line.text. This preserves both:
        //   - per-span weight/style (bold runs etc.) for visual fidelity, and
        //   - the inter-word spaces that live in line.text but NOT in any
        //     individual span (PDF extraction emits glyph runs touching at
        //     the bbox; the visual space between "Drylab" and "News" lives
        //     only in line.text). Plain reflow gets "DrylabNews"; this gets
        //     "Drylab News" with each word still styled correctly.
        const lineHtmls = lines.map((line) => {
            const lineText = String(line.text ?? '');
            const spans = Array.isArray(line.spans) ? line.spans : [];
            if (!spans.length) return escapeHtml(lineText);
            let cursor = 0;
            let matchedAny = false;
            let sawUnmatched = false;
            const parts = [];
            for (const sp of spans) {
                const spText = sourceSpanText(sp);
                if (!spText) continue;
                let chunk = spText;
                if (lineText) {
                    let idx = lineText.indexOf(spText, cursor);
                    let matchLength = spText.length;
                    if (idx < 0) {
                        const trimmedSpanText = spText.trim();
                        if (trimmedSpanText) {
                            const trimmedIdx = lineText.indexOf(trimmedSpanText, cursor);
                            if (trimmedIdx >= 0) {
                                idx = trimmedIdx;
                                matchLength = trimmedSpanText.length;
                            }
                        }
                    }
                    if (idx >= 0) {
                        const start = sawUnmatched ? idx : cursor;
                        chunk = lineText.slice(start, idx + matchLength);
                        cursor = idx + matchLength;
                        matchedAny = true;
                    } else {
                        sawUnmatched = true;
                    }
                }
                const w = String(sourceSpanFontWeight(sp) || '400');
                const st = String(sp?.fontStyle || (sp?.italic ? 'italic' : 'normal'));
                const styleParts = [];
                if (w && w !== '400' && w !== 'normal') styleParts.push(`font-weight:${w}`);
                if (st && st !== 'normal') styleParts.push(`font-style:${st}`);
                const styleAttr = styleParts.length ? ` style="${styleParts.join(';')}"` : '';
                parts.push(`<span${styleAttr}>${escapeHtml(chunk)}</span>`);
            }
            // Tail of line.text past the last matched span — append plain.
            if (lineText && matchedAny && !sawUnmatched && cursor < lineText.length && parts.length) {
                parts.push(escapeHtml(lineText.slice(cursor)));
            }
            return parts.join('');
        });
        return lineHtmls.filter((s) => s.length > 0).join('<br>') || '<br>';
    }

    // measureEditedTextHeightPts moved to ./text/measure.js (Phase 7e).
    // normalizeTextForDomReflow / normalizeRichHtmlForReflow (second copy)
    // also moved to ./text/dom-reflow.js (Phase 7ag).

    function promotedTextNeedsDomReflow(ann) {
        return Boolean(ann?.promotedFromExtraction === true
            || (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0))
            && annotationDimensionsChanged(ann);
    }

    function textForPlainEditorCurrentBox(ann, text) {
        if (!promotedTextNeedsDomReflow(ann)) return text;
        const original = String(ann?.originalText ?? ann?.text ?? '');
        const normalized = (value) => String(value ?? '').replace(/[\s\n]+/g, ' ').trim();
        if (original && normalized(text) !== normalized(original)) return text;
        return normalizeTextForDomReflow(text);
    }

    const autoWidthMeasureCanvas = document.createElement('canvas');
    const autoWidthMeasureCtx = autoWidthMeasureCanvas.getContext('2d');
    function measureEditedTextAutoWidthPts(ann, text, scale, maxWidthPts) {
        if (!autoWidthMeasureCtx) {
            return Math.max(60, Math.min(Number(maxWidthPts) || 60, 60));
        }
        const normalized = String(text ?? '').replace(/\r\n?/g, '\n');
        const lines = normalized.length ? normalized.split('\n') : [''];
        const maxSourceIndex = Math.max(0, renderableSourceLines(ann).length - 1);
        let widestLinePx = 0;

        lines.forEach((line, index) => {
            const style = editableLineStyle(ann, Math.min(index, maxSourceIndex));
            const fontSizePx = style.fontSizePt * fontDisplayScale(scale);
            autoWidthMeasureCtx.font = `${style.fontStyle || 'normal'} ${style.fontWeight || '400'} ${fontSizePx}px ${style.fontFamily || 'Arial, Helvetica, sans-serif'}`;
            widestLinePx = Math.max(widestLinePx, autoWidthMeasureCtx.measureText(line || ' ').width);
        });

        const paddedPts = (widestLinePx + 12) / Math.max(scale, 0.0001);
        const capPts = Math.max(60, Number(maxWidthPts) || 60);
        return Math.max(60, Math.min(capPts, paddedPts));
    }

    function resizeAnnotationForEditedText(ann, text, pi) {
        const data = pageData[pi];
        const box = data ? resolveAnnBox(ann) : null;
        if (!data || !box) return;

        const topFromPagePts = data.hPts - (box.y + box.h);
        const nextHeightPts = measureEditedTextHeightPts(ann, text, data.scale);
        const nextWidthPts = ann?._autoWidth
            ? measureEditedTextAutoWidthPts(ann, text, data.scale, ann._autoWidthMaxWidthPts || box.w)
            : box.w;
        const nextY = Math.max(0, data.hPts - topFromPagePts - nextHeightPts);

        setAnnotationBoxWithinPage(ann, pi, {
            x: box.x,
            y: nextY,
            w: nextWidthPts,
            h: nextHeightPts,
        });
    }

    // For rich-html annotations, the synthetic plain-text measurement in
    // measureEditedTextHeightPts can underestimate the actual rendered height
    // (per-span font sizes, explicit <br>s, browser line-height rounding at
    // different fitScales). Grow ann.pdfHeight to envelop the live DOM height
    // measured from the contenteditable / rich-html-item container so the
    // bounding box always wraps the visible text.
    function growAnnotationBoxToRenderedHeight(ann, el, pi) {
        const data = pageData[pi];
        const box = data ? resolveAnnBox(ann) : null;
        if (!data || !box || !el) return false;
        const renderedPx = el.scrollHeight;
        if (!Number.isFinite(renderedPx) || renderedPx <= 0) return false;
        const currentPx = box.h * data.scale;
        if (renderedPx <= currentPx + 1) return false;
        const grownHeightPts = renderedPx / Math.max(data.scale, 0.0001);
        const topFromPagePts = data.hPts - (box.y + box.h);
        const newY = Math.max(0, data.hPts - topFromPagePts - grownHeightPts);
        return setAnnotationBoxWithinPage(ann, pi, { x: box.x, y: newY, w: box.w, h: grownHeightPts });
    }

    // FONT_SLIDER_MIN_PT / FONT_SLIDER_MAX_PT / sliderValueToFontPt /
    // fontPtToSliderValue moved to ./text/font-slider.js (Phase 7ad).

    // Module-scoped clipboard for Ctrl+C / Ctrl+V annotation copy/paste.
    // _annClipboard moved to ./store/misc-state.js (Phase 5m).

    function syncActiveEditor(forceRebuild = false) {
        return _syncActiveEditorImpl(forceRebuild);
    }

    function _syncActiveEditorImpl(forceRebuild = false) {
        const { pi, uid } = activeState;
        const data = pi !== null ? pageData[pi] : null;
        const ann  = data ? data.annotations.find(a => a._uid === uid) : null;


        // Hide editors/handles on all pages when no editable text mode is active
        // and there isn't an annotation actively selected. We used to gate this
        // on hasActiveBoxSelection() (shapes/images only), which made the resize
        // handles vanish whenever a text annotation was selected outside of
        // edit-mode / add-text mode (e.g. after toggling the shape/line tool on
        // and back off). Falling back to "any active selection" keeps the
        // handles around for text annotations too.
        if (!editModeEnabled && !addTextMode && !ann) {
            Object.keys(pageData).forEach((pIdx) => {
                const pn = Number(pIdx) + 1;
                const ae = document.getElementById('ae-' + pn);
                const tm = document.getElementById('tm-' + pn);
                const sh = document.getElementById('sh-' + pn);
                const shTag = document.getElementById('sh-' + pn + '-tag');
                const rhs = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pn}-${dir}`));
                const rhRot = document.getElementById('rh-' + pn + '-rot');
                const sb = document.getElementById('sb-' + pn);
                if (ae) ae.style.display = 'none';
                if (tm) tm.style.display = 'none';
                if (sh) sh.style.display = 'none';
                if (shTag) shTag.style.display = 'none';
                rhs.forEach((handle) => { if (handle) handle.style.display = 'none'; });
                if (rhRot) rhRot.style.display = 'none';
                if (sb) sb.style.display = 'none';
            });
            return;
        }

        // Hide editors/handles on all other pages
        Object.keys(pageData).forEach(pIdx => {
            if (Number(pIdx) === pi) return;
            const pn = Number(pIdx) + 1;
            const ae = document.getElementById('ae-' + pn);
            const tm = document.getElementById('tm-' + pn);
            const sh = document.getElementById('sh-' + pn);
            const shTag = document.getElementById('sh-' + pn + '-tag');
            const rhs = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pn}-${dir}`));
            const rhRot = document.getElementById('rh-' + pn + '-rot');
            if (ae) ae.style.display = 'none';
            if (tm) tm.style.display = 'none';
            if (sh) sh.style.display = 'none';
            if (shTag) shTag.style.display = 'none';
            rhs.forEach((handle) => { if (handle) handle.style.display = 'none'; });
            if (rhRot) rhRot.style.display = 'none';
            const sb = document.getElementById('sb-' + pn);
            if (sb) sb.style.display = 'none';
        });

        const ae = pi !== null ? document.getElementById('ae-' + (pi + 1)) : null;
        const tm = pi !== null ? document.getElementById('tm-' + (pi + 1)) : null;
        const sh = pi !== null ? document.getElementById('sh-' + (pi + 1)) : null;
        const rhs = pi !== null
            ? ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pi + 1}-${dir}`))
            : [];
        const rhRot = pi !== null ? document.getElementById('rh-' + (pi + 1) + '-rot') : null;
        if (!ann || !ae || !tm || !sh || rhs.some((handle) => !handle) || !rhRot) return;
        const sb = document.getElementById('sb-' + (pi + 1));
        const lkhEl = document.getElementById('lkh-' + (pi + 1));
        const ehEl = document.getElementById('eh-' + (pi + 1));
        const uhEl = document.getElementById('uh-' + (pi + 1));
        const lhEl = document.getElementById('lh-' + (pi + 1));
        const dhEl = document.getElementById('dh-' + (pi + 1));
        const shLockEl = document.getElementById('sh-' + (pi + 1) + '-lock');
        const shFrontEl = document.getElementById('sh-' + (pi + 1) + '-front');
        const shBackEl = document.getElementById('sh-' + (pi + 1) + '-back');
        const tmFrontEl = document.getElementById('tm-' + (pi + 1) + '-front');
        const tmBackEl = document.getElementById('tm-' + (pi + 1) + '-back');
        const shEditEl = document.getElementById('sh-' + (pi + 1) + '-edit');
        const shBgEl = document.getElementById('sh-' + (pi + 1) + '-bg');
        const shCutEl = document.getElementById('sh-' + (pi + 1) + '-cut');
        const shCapEl = document.getElementById('sh-' + (pi + 1) + '-cap');
        const shTagEl = document.getElementById('sh-' + (pi + 1) + '-tag');
        const shDeleteEl = document.getElementById('sh-' + (pi + 1) + '-delete');

        const locked = isAnnotationLocked(ann);
        if (lkhEl) {
            lkhEl.classList.toggle('is-active', locked);
            lkhEl.title = locked ? 'Unlock annotation' : 'Lock annotation';
            lkhEl.setAttribute('aria-label', locked ? 'Unlock annotation' : 'Lock annotation');
        }
        if (shLockEl) {
            shLockEl.classList.toggle('is-active', locked);
            shLockEl.title = locked ? 'Unlock annotation' : 'Lock annotation';
            shLockEl.setAttribute('aria-label', locked ? 'Unlock annotation' : 'Lock annotation');
        }
        [ehEl, uhEl, lhEl, dhEl].forEach((button) => {
            if (button) button.disabled = locked;
        });
        [tmFrontEl, tmBackEl].forEach((button) => {
            if (button) button.disabled = locked;
        });
        [shFrontEl, shBackEl, shEditEl, shCutEl, shCapEl, shDeleteEl].forEach((button) => {
            if (button) button.disabled = locked;
        });
        if (shBgEl) {
            shBgEl.disabled = locked
                || !canRemoveBackgroundFromImageAnnotation(ann)
                || imageBackgroundRemovalState.active;
        }

        const { scale, canvasHeight } = data;
        const box = resolveAnnBox(ann);
        if (!box) {
            ae.style.display = 'none';
            tm.style.display = 'none';
            sh.style.display = 'none';
            if (shTagEl) shTagEl.style.display = 'none';
            rhs.forEach((handle) => { handle.style.display = 'none'; });
            rhRot.style.display = 'none';
            if (sb) sb.style.display = 'none';
            return;
        }

        const displayRect = annRectPx(ann, scale, canvasHeight, {
            expandLine: !(isShapeAnnotation(ann) && isLineShape(ann)),
        });
        const left   = displayRect ? displayRect.left : (box.x * scale);
        const top    = displayRect ? displayRect.top : (canvasHeight - (box.y + box.h) * scale);
        const width  = displayRect ? Math.max(2, displayRect.width) : Math.max(2, box.w * scale);
        const height = displayRect ? Math.max(2, displayRect.height) : Math.max(2, box.h * scale);

        const positionRotateHandle = (showHandle) => {
            if (!showHandle) { rhRot.style.display = 'none'; return; }
            // 15% of selection height beyond the bottom edge → orbit radius from center.
            const orbitRadius = (height / 2) + (height * 0.15);
            const handleAngleDeg = normalizeRotationDegrees((ann.rotation || 0) + 90); // 90 = bottom by default
            const rad = handleAngleDeg * (Math.PI / 180);
            const cx = left + width / 2;
            const cy = top + height / 2;
            const hx = cx + Math.cos(rad) * orbitRadius;
            const hy = cy + Math.sin(rad) * orbitRadius;
            const half = 10; // half of 20px
            const hidden = (dragState.active && dragState.uid === ann._uid)
                || (resizeState.active && resizeState.uid === ann._uid);
            rhRot.style.cssText = [
                hidden ? 'display:none' : 'display:flex',
                'position:absolute',
                `left:${(hx - half).toFixed(2)}px`,
                `top:${(hy - half).toFixed(2)}px`,
                'width:20px',
                'height:20px',
                'z-index:7',
            ].join(';');
        };
        const applySelectionBox = ({
            left: boxLeft,
            top: boxTop,
            width: boxWidth,
            height: boxHeight,
            rotation = 0,
            padX = 0,
            padY = 0,
            minWidth = 0,
            minHeight = 0,
            borderRadius = 6,
        } = {}) => {
            if (!sb) return;
            let nextLeft = Number(boxLeft) - Number(padX || 0);
            let nextTop = Number(boxTop) - Number(padY || 0);
            let nextWidth = Math.max(0, Number(boxWidth) + (Number(padX || 0) * 2));
            let nextHeight = Math.max(0, Number(boxHeight) + (Number(padY || 0) * 2));
            if (nextWidth < minWidth) {
                nextLeft -= (minWidth - nextWidth) / 2;
                nextWidth = minWidth;
            }
            if (nextHeight < minHeight) {
                nextTop -= (minHeight - nextHeight) / 2;
                nextHeight = minHeight;
            }
            sb.style.cssText = [
                'display:block',
                'position:absolute',
                `left:${nextLeft.toFixed(2)}px`,
                `top:${nextTop.toFixed(2)}px`,
                `width:${nextWidth.toFixed(2)}px`,
                `height:${nextHeight.toFixed(2)}px`,
                'border:1px dashed #2563eb',
                `border-radius:${borderRadius}px`,
                'pointer-events:none',
                'box-sizing:border-box',
                `transform:${rotation ? `rotate(${Number(rotation).toFixed(3)}deg)` : 'none'}`,
                'transform-origin:center center',
                'z-index:5',
            ].join(';');
        };

        if (isShapeAnnotation(ann)) {
            const cutArmed = isShapeCutModeArmedFor(ann, pi);
            ae.style.display = 'none';
            tm.style.display = 'none';
            if (shTagEl) shTagEl.style.display = 'none';
            if (shFrontEl) shFrontEl.style.display = '';
            if (shBackEl) shBackEl.style.display = '';
            if (shEditEl) shEditEl.style.display = 'none';
            if (shBgEl) shBgEl.style.display = 'none';
            if (shCutEl) {
                shCutEl.style.display = isLineShape(ann) ? 'none' : '';
                shCutEl.classList.toggle('is-active', cutArmed);
                shCutEl.title = cutArmed ? 'Cancel cut mode' : 'Cut shape';
                shCutEl.setAttribute('aria-label', cutArmed ? 'Cancel cut mode' : 'Cut shape');
            }
            const isActiveLineShape = isLineShape(ann);
            const lineGeometry = isActiveLineShape ? {
                lineStartX: clamp01(ann.lineStartX, 0),
                lineStartY: clamp01(ann.lineStartY, 0),
                lineEndX: clamp01(ann.lineEndX, 1),
                lineEndY: clamp01(ann.lineEndY, 1),
            } : null;
            const handleConfigs = isActiveLineShape ? {
                nw: {
                    visible: !locked,
                    dir: 'line-start',
                    cursor: 'move',
                    aria: 'Move line start',
                    left: left + (width * lineGeometry.lineStartX) - 6,
                    top: top + (height * lineGeometry.lineStartY) - 6,
                },
                ne: { visible: false, dir: 'ne', cursor: 'ne-resize', aria: 'Resize northeast' },
                sw: { visible: false, dir: 'sw', cursor: 'sw-resize', aria: 'Resize southwest' },
                se: {
                    visible: !locked,
                    dir: 'line-end',
                    cursor: 'move',
                    aria: 'Move line end',
                    left: left + (width * lineGeometry.lineEndX) - 6,
                    top: top + (height * lineGeometry.lineEndY) - 6,
                },
            } : {
                nw: { visible: !locked, dir: 'nw', cursor: 'nw-resize', aria: 'Resize northwest', left: left - 6, top: top - 6 },
                ne: { visible: !locked, dir: 'ne', cursor: 'ne-resize', aria: 'Resize northeast', left: left + width - 6, top: top - 6 },
                sw: { visible: !locked, dir: 'sw', cursor: 'sw-resize', aria: 'Resize southwest', left: left - 6, top: top + height - 6 },
                se: { visible: !locked, dir: 'se', cursor: 'se-resize', aria: 'Resize southeast', left: left + width - 6, top: top + height - 6 },
            };
            ['nw', 'ne', 'sw', 'se'].forEach((key, index) => {
                const handle = rhs[index];
                const config = handleConfigs[key];
                handle.dataset.dir = config.dir;
                handle.setAttribute('aria-label', config.aria);
                if (!config.visible || cutArmed) {
                    handle.style.display = 'none';
                    return;
                }
                handle.style.cssText = [
                    'display:block',
                    'position:absolute',
                    `left:${config.left.toFixed(2)}px`,
                    `top:${config.top.toFixed(2)}px`,
                    'width:12px',
                    'height:12px',
                    `cursor:${config.cursor}`,
                    'z-index:6',
                ].join(';');
            });
            const helperTop = top - 46;
            const helperBelow = helperTop < (getStickyUiBottomEdge() + 8);
            sh.classList.toggle('is-below', helperBelow);
            sh.style.cssText = [
                // Keep the action bar hidden while a shape drag is in progress
                // so it doesn't follow the cursor and re-trigger hover transitions
                // on every mousemove tick.
                (dragState.active && dragState.uid === ann._uid) ? 'display:none' : 'display:flex',
                'position:absolute',
                `left:${(left + (width / 2)).toFixed(2)}px`,
                `top:${(helperBelow ? (top + height) : helperTop).toFixed(2)}px`,
                'z-index:6',
            ].join(';');
            syncShapePanelUi();
            positionRotateHandle(!isActiveLineShape && !locked && !cutArmed);
            // Toggle the line-cap button only for line shapes; reflect its
            // current state visually (active = round, default).
            if (shCapEl) {
                if (isActiveLineShape) {
                    const isRound = (ann.lineCap || 'round') === 'round';
                    shCapEl.style.display = '';
                    shCapEl.classList.toggle('is-active', isRound);
                    shCapEl.title = isRound ? 'Disable rounded line caps' : 'Enable rounded line caps';
                } else {
                    shCapEl.style.display = 'none';
                }
            }
            // Lines are stroked outward from the bbox center by strokeWidth/2
            // perpendicular to the line, AND round caps extend strokeWidth/2
            // beyond each endpoint. Pad by the FULL stroke width on each side
            // so the dashed selection box always sits clearly outside the
            // visible stroke regardless of cap setting or AA.
            if (isActiveLineShape) {
                const selectionRect = lineSelectionRectPx(ann, scale, canvasHeight, 14);
                applySelectionBox({
                    left: selectionRect?.left ?? left,
                    top: selectionRect?.top ?? top,
                    width: selectionRect?.width ?? width,
                    height: selectionRect?.height ?? height,
                    rotation: 0,
                    padX: 0,
                    padY: 0,
                    minWidth: 0,
                    minHeight: 0,
                    borderRadius: 6,
                });
            } else {
                applySelectionBox({
                    left,
                    top,
                    width,
                    height,
                    rotation: ann.rotation || 0,
                    padX: 4,
                    padY: 4,
                    minWidth: 0,
                    minHeight: 0,
                    borderRadius: 6,
                });
            }
            return;
        }
        if (isImageBackedAnnotation(ann)) {
            ae.style.display = 'none';
            tm.style.display = 'none';
            if (shTagEl) {
                const tagText = getAnnotationDisplayLabel(ann);
                shTagEl.textContent = tagText;
                shTagEl.style.display = tagText ? 'inline-flex' : 'none';
            }
            if (shFrontEl) shFrontEl.style.display = '';
            if (shBackEl) shBackEl.style.display = '';
            if (shEditEl) shEditEl.style.display = String(ann.type || '').toLowerCase() === 'signature' ? '' : 'none';
            if (shBgEl) {
                shBgEl.style.display = canRemoveBackgroundFromImageAnnotation(ann) ? '' : 'none';
                shBgEl.textContent = imageBackgroundRemovalState.active && imageBackgroundRemovalState.uid === ann._uid
                    ? '...'
                    : 'BG';
            }
            if (shCutEl) shCutEl.style.display = 'none';
            if (shCapEl) shCapEl.style.display = 'none';
            ['nw', 'ne', 'sw', 'se'].forEach((key, index) => {
                const handle = rhs[index];
                const positions = {
                    nw: { left: left - 6, top: top - 6 },
                    ne: { left: left + width - 6, top: top - 6 },
                    sw: { left: left - 6, top: top + height - 6 },
                    se: { left: left + width - 6, top: top + height - 6 },
                };
                handle.dataset.dir = key;
                handle.setAttribute('aria-label', `Resize ${key.toUpperCase()}`);
                if (locked) {
                    handle.style.display = 'none';
                    return;
                }
                handle.style.cssText = [
                    'display:block',
                    'position:absolute',
                    `left:${positions[key].left.toFixed(2)}px`,
                    `top:${positions[key].top.toFixed(2)}px`,
                    'width:12px',
                    'height:12px',
                    'z-index:6',
                ].join(';');
            });
            const helperTop = top - 46;
            const helperBelow = helperTop < (getStickyUiBottomEdge() + 8);
            sh.classList.toggle('is-below', helperBelow);
            sh.style.cssText = [
                (dragState.active && dragState.uid === ann._uid) ? 'display:none' : 'display:flex',
                'position:absolute',
                `left:${(left + (width / 2)).toFixed(2)}px`,
                `top:${(helperBelow ? (top + height) : helperTop).toFixed(2)}px`,
                'z-index:6',
            ].join(';');
            positionRotateHandle(!locked);
            applySelectionBox({
                left,
                top,
                width,
                height,
                rotation: ann.rotation || 0,
                padX: 4,
                padY: 4,
                minWidth: 0,
                minHeight: 0,
                borderRadius: 12,
            });
            return;
        }
        sh.style.display = 'none';
        if (shTagEl) shTagEl.style.display = 'none';
        if (shCapEl) shCapEl.style.display = 'none';
        if (shCutEl) shCutEl.style.display = 'none';

        // Use editableLineStyle so ann-level overrides (format-bar font size / family / color)
        // drive the outer container, keeping the caret + any unwrapped text node in sync with
        // the format bar as soon as a slider change fires — not only after blur/reopen.
        const style0       = editableLineStyle(ann, 0);
        const lineHeightPx = blockLineHeightPx(ann, 0, scale, style0);
        const sourceRotation = annotationSourceRotationDegrees(ann);
        const _inEditing = editorIsEditingAnnotation(ae, ann);
        const selectedTextUsesDomLayer = !_inEditing && shouldRenderTextInRichHtmlLayer(ann);
        const aeBgColor = selectedTextUsesDomLayer ? 'transparent' : resolveDisplayBgColor(ann);
        const aeTextColor = style0.fillStyle;
        // Vertical alignment: use flex on the outer container so inner content is pushed
        // to top / middle / bottom of the annotation box. The editor HTML remains a single
        // block inside, so flex vertically positions the whole block without disrupting
        // text wrap or caret behavior.
        const vAlign = (() => {
            const v = String(ann.verticalAlign || 'top').toLowerCase();
            if (v === 'middle' || v === 'center') return 'center';
            if (v === 'bottom') return 'flex-end';
            return 'flex-start';
        })();
        const useFlexEditorLayout = vAlign !== 'flex-start';
        ae.style.cssText = [
            `display:${useFlexEditorLayout ? 'flex' : 'block'}`,
            ...(useFlexEditorLayout ? ['flex-direction:column', `justify-content:${vAlign}`] : []),
            'position:absolute',
            `left:${left.toFixed(2)}px`, `top:${top.toFixed(2)}px`,
            `width:${width.toFixed(2)}px`, `height:${Math.max(18, height).toFixed(2)}px`,
            `font-family:${style0.fontFamily}`,
            `font-size:${(style0.fontSizePt * fontDisplayScale(scale)).toFixed(2)}px`,
            `font-weight:${ann._styleDirty ? (ann.fontWeight || style0.fontWeight || '400') : (style0.fontWeight || '400')}`,
            `font-style:${ann.fontStyle || style0.fontStyle || 'normal'}`,
            `text-decoration:${ann.underline ? 'underline' : 'none'}`,
            `color:${aeTextColor}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            'padding:0', 'margin:0', `background:${aeBgColor}`,
            `text-align:${ann.textAlign || 'left'}`,
            `opacity:${parseFloat(ann.opacity ?? 1)}`,
            'border:none', 'outline:none',
            ...(ann._pageBoundsConstrained ? ['overflow-x:hidden', 'overflow-y:auto'] : ['overflow:visible']),
            'white-space:pre-wrap', 'word-break:break-word',
            'pointer-events:auto',
            'box-sizing:border-box',
            'z-index:5',
        ].join(';');
        // Cursor + selection vary based on whether the user is actively typing
        // (data-editing) vs. just having the annotation selected (cursor:move,
        // body click → drag). Set after cssText so they aren't overridden.
        ae.style.cursor = _inEditing ? 'text' : 'move';
        ae.style.userSelect = _inEditing ? 'text' : 'none';
        ae.style.caretColor = _inEditing ? '#2563eb' : 'transparent';
        // Mark new-annotation creation state so CSS can show a visible input box + placeholder.
        // A new annotation has no source content and empty text; the green dashed border
        // (via [data-creating]) matches the .text-box-creator style from the edit view.
        const _hasSource = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
        const _annText = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
        if (!_hasSource && !_annText) {
            ae.dataset.creating = '1';
        } else {
            delete ae.dataset.creating;
        }
        // Only reset content when not focused (avoids caret jump mid-edit).
        // forceRebuild=true is set by format-bar actions so changes are immediately visible.
        if (document.activeElement !== ae || forceRebuild) {
            // Per-selection formatting (via execCommand) is captured in ann._richHtml.
            // Respect it so subsequent syncs don't flatten inline <span style="…"> back to
            // the annotation-level uniform styling. A forceRebuild from annotation-level
            // format changes (font family/size/etc.) still preserves the rich HTML since
            // annotation-level styles apply to the editor container, not the inner spans.
            const _isEditing = editorIsEditingAnnotation(ae, ann);
            const _canUseSourceEditorLayout = _isEditing && !shouldRenderTextInRichHtmlLayer(ann);
            const _canUseCurrentBoxSourceFlow = _isEditing && !_canUseSourceEditorLayout;
            const _editedTextForRouting = editedTexts[ann._uid];
            const _sourceComparableText = _editedTextForRouting !== undefined
                ? _editedTextForRouting
                : String(ann.text ?? '');
            const _canPreserveSourceEditing = _isEditing && !ann._richHtml && (
                shouldUseSourceFlowEditorHTML(ann, _editedTextForRouting, {
                    allowCurrentBoxSourceFlow: _canUseCurrentBoxSourceFlow,
                })
                || (
                    _canUseSourceEditorLayout
                    && annotationTextMatchesSource(ann, _sourceComparableText)
                    // Source-exact edits keep ann.sourceSpans synced with the
                    // updated text, so annTextIsEdited (which compares
                    // ann.text vs ann.originalText) being true here is not
                    // a layout divergence — the canvas/source render still
                    // has the right glyphs at the right positions. Without
                    // this allowance, double-clicking a saved source-exact
                    // annotation kicks the editor into plain-reflow and
                    // collapses the per-line PDF layout.
                    && (!annTextIsEdited(ann) || ann._sourceExactTextEdited || ann.preserveSourceLayout)
                    && !ann.userCreated
                    && !ann._styleDirty
                )
            );
            if (!_isEditing && shouldRenderTextInRichHtmlLayer(ann)) {
                ae.dataset.renderMode = 'dom-layer';
                if (ae.innerHTML !== '') ae.innerHTML = '';
                if (typeof window !== 'undefined' && _isEditing === false && ann?._uid) {
                    window.__galleyDbg = window.__galleyDbg || {};
                    if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = `dom-layer notEditing rich=${!!ann._richHtml} userAuth=${isUserAuthoredAnnotation(ann)}`;
                }
            } else if (ann._richHtml) {
                const _richIsPromoted = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
                    || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
                    || ann.promotedFromExtraction === true;
                const _richNeedsReflow = _richIsPromoted
                    && (annotationDimensionsChanged(ann)
                        || (typeof annTextIsEdited === 'function' && annTextIsEdited(ann)));
                const richHtml = normalizeRichHtmlForDisplay(
                    _richNeedsReflow ? normalizeRichHtmlForReflow(ann._richHtml) : ann._richHtml,
                    ann
                );
                ae.dataset.renderMode = 'plain';
                if (ae.innerHTML !== richHtml) ae.innerHTML = richHtml;
                if (typeof window !== 'undefined' && ann?._uid) {
                    window.__galleyDbg = window.__galleyDbg || {};
                    window.__galleyDbg[ann._uid] = `plain via _richHtml`;
                }
            } else if (_isEditing && !_canPreserveSourceEditing) {
                // Clean editor mode: while actively editing a text annotation,
                // render the contenteditable as a plain text box styled with the
                // annotation's font / size / color / alignment — no per-span
                // scaleX, no gap-as-spaces, no canvas-baseline mimicry. The
                // editor will look slightly different from the deselected
                // canvas baseline (typing intentionally diverges from per-glyph
                // PDF positions), but typing, caret movement, selection, and
                // formatting all behave like a normal text input.
                //
                // Triggers _canvasOwnsPaint=false (renderMode='plain' is not
                // in the canvas-owns set), and the canvas already early-returns
                // for active+editing+!canvasOwns (~line 2241), so nothing
                // double-paints underneath.
                const editedText = editedTexts[ann._uid];
                const cleanText = editedText !== undefined
                    ? String(editedText)
                    : String(ann.text ?? '');
                ae.dataset.renderMode = 'plain';
                const cleanHtml = renderPlainEditorHTML(ann, textForPlainEditorCurrentBox(ann, cleanText), scale);
                if (ae.innerHTML !== cleanHtml) ae.innerHTML = cleanHtml;
                if (typeof window !== 'undefined' && ann?._uid) {
                    window.__galleyDbg = window.__galleyDbg || {};
                    window.__galleyDbg[ann._uid] = 'plain via clean-editor';
                }
            } else {
                const editedText = editedTexts[ann._uid];
                // Galley editor (Iceni-style) — takes precedence over source-flow
                // for actively-editing pristine extracted text. The contenteditable
                // hosts per-source-span caret targets at exact PDF positions while
                // the page overlay canvas continues to paint every original glyph.
                // Stays engaged after the user appends chars (ann._galleyEdited)
                // so the layout never collapses to plain reflow on first keystroke.
                const _galleyExtracted = Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0
                    && renderableSourceLines(ann).length > 0;
                const _galleyTextOk = editedText === undefined
                    || annotationTextMatchesSource(ann, editedText)
                    || ann._galleyEdited === true;
                const _galleyEnabled = (typeof window !== 'undefined') && (window.__editorGalleyMode !== false);
                if (_isEditing && shouldUsePromotedParagraphEditorHTML(ann, editedText)) {
                    ae.dataset.renderMode = 'paragraph';
                    const paragraphText = editedText !== undefined ? editedText : String(ann.text ?? '');
                    ae.innerHTML = renderPromotedParagraphEditorHTML(ann, paragraphText, scale);
                    if (typeof window !== 'undefined' && ann?._uid) {
                        window.__galleyDbg = window.__galleyDbg || {};
                        if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = 'paragraph';
                    }
                } else if (_galleyEnabled
                    && _isEditing
                    && _canUseSourceEditorLayout
                    && _galleyExtracted
                    && _galleyTextOk
                    && !annotationDimensionsChanged(ann)
                    && !ann._richHtml
                    && !isUserAuthoredAnnotation(ann)) {
                    ae.dataset.renderMode = 'galley';
                    ae.innerHTML = renderGalleyEditorHTML(ann, scale);
                    if (typeof window !== 'undefined') {
                        window.__galleyDbg = window.__galleyDbg || {};
                        if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = 'galley';
                    }
                } else if (_isEditing && shouldUseSourceFlowEditorHTML(ann, editedText, {
                    allowCurrentBoxSourceFlow: _canUseCurrentBoxSourceFlow,
                })) {
                    ae.dataset.renderMode = 'source-flow';
                    ae.innerHTML = buildSourceFlowEditorHTML(ann, scale);
                    if (typeof window !== 'undefined' && ann?._uid) { window.__galleyDbg = window.__galleyDbg || {}; if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = `source-flow gExt=${_galleyExtracted} gOk=${_galleyTextOk} dimChg=${annotationDimensionsChanged(ann)} userAuth=${isUserAuthoredAnnotation(ann)} canUseSrcLayout=${_canUseSourceEditorLayout}`; }
                } else if (editedText !== undefined
                    && (annotationDimensionsChanged(ann)
                        || (editedText !== String(ann.text ?? '')
                            && !annotationTextMatchesSource(ann, editedText)))) {
                    // Real divergence from the extraction (user typed, or
                    // dimensions grew). Use the plain reflow path. If the
                    // editedText still whitespace-normalizes to the original
                    // PDF source content (e.g. seeded from ann.text on load
                    // with no user edit), fall through to the source-render
                    // path so leader-dot spacing and per-span layout remain
                    // pixel-identical to the deselected canvas baseline.
                    ae.dataset.renderMode = 'plain';
                    ae.innerHTML = renderPlainEditorHTML(ann, textForPlainEditorCurrentBox(ann, editedText), scale);
                    if (typeof window !== 'undefined' && ann?._uid) { window.__galleyDbg = window.__galleyDbg || {}; if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = `plain editedDiverge gExt=${_galleyExtracted} gOk=${_galleyTextOk} dimChg=${annotationDimensionsChanged(ann)} userAuth=${isUserAuthoredAnnotation(ann)} canUseSrcLayout=${_canUseSourceEditorLayout} editedText=${JSON.stringify(String(editedText).slice(0,40))}`; }
                } else {
                    // Detect persisted edits (same logic as annTextIsEdited in canvas draw):
                    // compare ann.text against ann.originalText (the value at extraction).
                    const normWS = s => String(s).replace(/[\s\n]+/g, ' ').trim();
                    const savedText     = String(ann.text ?? '');
                    const originalText  = String(ann.originalText ?? '');
                    const textWasEdited = originalText !== '' && normWS(savedText) !== normWS(originalText);
                    if (textWasEdited && !ann._sourceExactTextEdited && !ann.preserveSourceLayout) {
                        ae.dataset.renderMode = 'plain';
                        ae.innerHTML = renderPlainEditorHTML(ann, textForPlainEditorCurrentBox(ann, savedText), scale);
                    } else if (_isEditing) {
                        if (_canUseSourceEditorLayout && annotationTextMatchesSource(ann, savedText)) {
                            // Actively editing an unmodified promoted block — render the
                            // per-span source DOM so the user can place a caret between
                            // styled runs. Resized/DOM-owned annotations must not use
                            // this source-positioned HTML because its native selection
                            // highlight is offset from the current annotation box.
                            ae.dataset.renderMode = 'source';
                            ae.innerHTML = buildEditorHTML(ann, scale);
                            if (typeof window !== 'undefined' && ann?._uid) { window.__galleyDbg = window.__galleyDbg || {}; if (!window.__galleyDbg[ann._uid]) window.__galleyDbg[ann._uid] = `source gExt=${_galleyExtracted} gOk=${_galleyTextOk} userAuth=${isUserAuthoredAnnotation(ann)}`; }
                        } else {
                            // The saved text has diverged from the old extraction
                            // source lines. Rendering source DOM here shows stale
                            // text, then the next keystroke snaps back to saved
                            // text. Use the canonical annotation text instead.
                            ae.dataset.renderMode = 'plain';
                            // For resized promoted annotations, normalize single
                            // \n (source line-break positions) → space so the
                            // contenteditable shows the text reflowed to the
                            // current box width.  \n\n+ (paragraph breaks) are
                            // preserved.
                            const _aeIsPromoted = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
                                || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
                                || ann.promotedFromExtraction === true;
                            const _aeNeedsReflow = _aeIsPromoted && annotationDimensionsChanged(ann);
                            ae.innerHTML = renderPlainEditorHTML(ann, _aeNeedsReflow ? normalizeTextForDomReflow(savedText) : savedText, scale);
                        }
                    } else {
                        // Just selected (drag/resize), not editing: leave the editor
                        // empty so the canvas underneath renders with PDF-accurate
                        // per-span positioning. Otherwise buildEditorHTML's
                        // approximated inter-span gaps + scaleX fit can collapse a
                        // space between styled runs (e.g. bold "Program
                        // Administrator" joining "Steadily" with no space) and
                        // shift glyph metrics — visibly changing spacing/font on
                        // selection vs the unselected canvas render.
                        ae.dataset.renderMode = 'canvas';
                        if (ae.innerHTML !== '') ae.innerHTML = '';
                    }
                }
            }
        }
        // For renderModes where canvas drawOriginalSource owns the visible
        // painting (canvas-only fallback, or source/source-flow while actively
        // editing pristine extracted text), hide ae's background + text color
        // so the canvas baseline shows through unobstructed. ae still holds
        // the contenteditable text nodes so the caret + execCommand keep
        // working; only the visible glyphs come from canvas. The moment
        // editedText diverges from ann.text, the cascade above switches to
        // 'plain' renderMode and ae becomes opaque again.
        const _canvasOwnsPaint = activeEditorCanvasOwnsPaint(ann, ae);
        if (_canvasOwnsPaint) {
            ae.style.background = 'transparent';
            ae.style.color = 'transparent';
            ae.dataset.canvasOwns = '1';
        } else {
            delete ae.dataset.canvasOwns;
        }
        if (ae.dataset.renderMode !== 'source' && Math.abs(sourceRotation) === 90 && height > 0 && width > 0) {
            ae.style.width = `${height.toFixed(2)}px`;
            ae.style.height = `${width.toFixed(2)}px`;
            ae.style.transformOrigin = 'top left';
            ae.style.transform = sourceRotation > 0
                ? `translateX(${width.toFixed(2)}px) rotate(90deg)`
                : `translateY(${height.toFixed(2)}px) rotate(-90deg)`;
        } else {
            ae.style.transformOrigin = 'top left';
            ae.style.transform = 'none';
        }
        const userRotAe = Number(ann.rotation) || 0;
        if (userRotAe) {
            ae.style.transformOrigin = 'center center';
            ae.style.transform = `rotate(${userRotAe}deg)`;
        }
        applySelectionBox({
            left,
            top,
            width,
            height: Math.max(18, height),
            rotation: userRotAe,
            padX: 0,
            padY: 0,
            minWidth: 0,
            minHeight: 0,
            borderRadius: 4,
        });
        if (ae.dataset.renderMode === 'source') {
            fitActiveEditorSourceLines(ae, ann, scale);
        }

        ae.setAttribute('contenteditable', locked ? 'false' : 'true');

        const menuTop = top - 42;
        const menuBelow = menuTop < 4;
        tm.classList.toggle('is-below', menuBelow);
        tm.style.cssText = [
            // Hide the text hover menu while dragging so it doesn't follow the
            // cursor and re-trigger hover transitions on every mousemove tick.
            (dragState.active && dragState.uid === ann._uid) ? 'display:none' : 'display:flex',
            'position:absolute',
            `left:${(left + (width / 2)).toFixed(2)}px`,
            `top:${(menuBelow ? (top + height) : menuTop).toFixed(2)}px`,
            'z-index:6',
        ].join(';');

        const handlePositions = {
            nw: { left: left - 6, top: top - 6 },
            ne: { left: left + width - 6, top: top - 6 },
            sw: { left: left - 6, top: top + height - 6 },
            se: { left: left + width - 6, top: top + height - 6 },
        };
        ['nw', 'ne', 'sw', 'se'].forEach((dir, index) => {
            const handle = rhs[index];
            const pos = handlePositions[dir];
            handle.dataset.dir = dir;
            handle.setAttribute('aria-label', `Resize ${dir.toUpperCase()}`);
            if (locked) {
                handle.style.display = 'none';
                return;
            }
            handle.style.cssText = [
                'display:block',
                'position:absolute',
                `left:${pos.left.toFixed(2)}px`,
                `top:${pos.top.toFixed(2)}px`,
                'width:12px',
                'height:12px',
                'z-index:6',
            ].join(';');
        });

        positionRotateHandle(!locked);

        // Autofit: the canvas-based measureEditedTextHeightPts can under-estimate the
        // rendered height (different metrics from CSS layout). After the editor DOM is
        // populated, compare its scrollHeight against the assigned client height and
        // grow the annotation box if the text overflows. Single re-sync only — guard
        // prevents recursion.
        // Skip autofit for pristine extracted annotations the user hasn't touched:
        // CSS line-height rounding for promoted text routinely reports ~1–2px of
        // spurious overflow vs the extraction-derived box, and growing the box on
        // mere selection/focus mutates loaded geometry (and via setAnnotationBox →
        // markUserAuthored permanently dirties the annotation), causing visible drift
        // on no-op enter+blur cycles.
        const _autofitEditedText = editedTexts[ann._uid];
        const _autofitTextChanged = _autofitEditedText !== undefined
            && _autofitEditedText !== String(ann.text ?? '');
        // Source-exact-edited annotations carry their own per-span layout
        // (sourceSpans + sourceLineBBoxes) that drives the canvas/source DOM.
        // CSS scrollHeight on the absolutely-positioned source DOM has no
        // bearing on the actual rendered geometry, and growing pdfH here
        // re-mutates the box on every dblclick, eventually pushing render
        // routing into the plain-reflow path (visible blank box).
        const _autofitSourceExact = !!(ann?._sourceExactTextEdited
            || ann?.sourceExactTextEdited
            || ann?.preserveSourceLayout);
        const _autofitPristine = (!ann.userCreated
            && !isUserAuthoredAnnotation(ann)
            && !_autofitTextChanged
            && (
                (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
                || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0)
                || ann.promotedFromExtraction === true
            )) || _autofitSourceExact;
        if (!_autofitPristine && !suppressEditorAutofit && !syncActiveEditor._autofitGuard) {
            const contentH = ae.scrollHeight;
            const clientH = ae.clientHeight;
            if (contentH > clientH + 0.5) {
                const extraPts = (contentH - clientH) / Math.max(scale, 0.0001);
                const curBox = resolveAnnBox(ann);
                if (curBox) {
                    const topFromPagePts = data.hPts - (curBox.y + curBox.h);
                    const newH = curBox.h + extraPts;
                    const newY = Math.max(0, data.hPts - topFromPagePts - newH);
                    const boxChanged = setAnnotationBoxWithinPage(ann, activeState.pi, { x: curBox.x, y: newY, w: curBox.w, h: newH });
                    if (boxChanged) {
                        redrawOverlay(activeState.pi);
                        syncActiveEditor._autofitGuard = true;
                        try { syncActiveEditor(forceRebuild); }
                        finally { syncActiveEditor._autofitGuard = false; }
                    }
                }
            }
        }
    }

    function clearActiveAnnotation() {
        if (dragState.active) endDrag();
        if (resizeState.active) endResize();
        if (textCreationState.active) cancelTextCreationPreview();
        if (shapeCreationState.active) cancelShapeCreationPreview();
        if (shapeCutState.armed) cancelShapeCutMode({ redraw: false });
        const prevPi = activeState.pi;
        clearEditorEditingState(activeEditorForPage(prevPi));
        clearActiveState();
        syncActiveEditor();
        updateFormatBar();
        if (prevPi !== null) redrawOverlay(prevPi);
    }

    function selectAnnotation(ann, pi) {
        if ((!editModeEnabled && !addTextMode && !shapeMode && !isBoxAnnotation(ann)) || !ann) return;
        if (shapeCutState.armed && (shapeCutState.pi !== pi || shapeCutState.uid !== ann._uid)) {
            cancelShapeCutMode({ redraw: false });
        }
        const prevPi = activeState.pi;
        const prevUid = activeState.uid;
        const selectionChanged = prevPi !== pi || prevUid !== ann._uid;
        if (prevUid && selectionChanged) {
            clearEditorEditingState(activeEditorForPage(prevPi));
        }
        setActiveState({ pi, uid: ann._uid });
        syncActiveEditor(selectionChanged);
        updateFormatBar();
        if (prevPi !== null && prevPi !== pi) redrawOverlay(prevPi);
        redrawOverlay(pi);
    }

    function updateFormatBar() {
        const { pi, uid } = activeState;
        const data = pi !== null ? pageData[pi] : null;
        const ann  = data ? data.annotations.find(a => a._uid === uid) : null;
        if (!annFormatBar) return;
        if (ann && !isTextAnnotation(ann)) {
            annFormatBar.classList.remove('is-visible');
            syncShapePanelUi();
            return;
        }
        if (!ann) {
            annFormatBar.classList.remove('is-visible');
            syncShapePanelUi();
            return;
        }
        const locked = isAnnotationLocked(ann);
        annFormatBar.classList.add('is-visible');
        if (afbFont) {
            const fv = ann.fontFamily || 'Helvetica';
            afbFont.value = fv;
            // If the font isn't in the list, fall back to Helvetica
            if (!afbFont.value) afbFont.value = 'Helvetica';
        }
        const fontSize = Number(ann.fontSize) || 12;
        if (afbSize)      afbSize.value = String(fontPtToSliderValue(fontSize));
        if (afbSizeValue) afbSizeValue.textContent = Math.round(fontSize) + 'pt';
        if (afbTextColor) afbTextColor.value = ann.textColor || '#000000';
        if (afbBgColor)   afbBgColor.value   = ann.backgroundColor || '#ffffff';
        if (afbOpacity) {
            const op = parseFloat(ann.opacity ?? 1);
            const rounded = Math.round((isNaN(op) ? 1 : Math.min(1, Math.max(0.1, op))) * 10) / 10;
            afbOpacity.value = String(rounded);
        }
        if (afbBold) {
            const isBold = String(ann.fontWeight || '400') === '700';
            afbBold.setAttribute('aria-pressed', String(isBold));
            afbBold.classList.toggle('is-active', isBold);
        }
        if (afbItalic) {
            const isItalic = (ann.fontStyle || 'normal') === 'italic';
            afbItalic.setAttribute('aria-pressed', String(isItalic));
            afbItalic.classList.toggle('is-active', isItalic);
        }
        if (afbUnderline) {
            const isUnderline = Boolean(ann.underline);
            afbUnderline.setAttribute('aria-pressed', String(isUnderline));
            afbUnderline.classList.toggle('is-active', isUnderline);
        }
        if (afbAlign)  afbAlign.value  = ann.textAlign     || 'left';
        if (afbValign) afbValign.value = ann.verticalAlign || 'top';
        [afbFont, afbSize, afbTextColor, afbBgColor, afbOpacity, afbBold, afbItalic, afbUnderline, afbAlign, afbValign].forEach((control) => {
            if (control) control.disabled = locked;
        });
        syncShapePanelUi();
    }

    // updateSaveUi moved to ./ui/save-ui.js (Phase 7bl).
    const updateSaveUi = () => _updateSaveUi({ saveButton, downloadPdfButton, saveStatus });

    function redrawAllOverlays() {
        Object.keys(pageData).forEach((pi) => {
            const piNum = Number(pi);
            redrawOverlay(piNum);
        });
    }

    // reflectShapeStateToInputs moved to ./shapes/inspector-ui.js (Phase 7by).
    const reflectShapeStateToInputs = (shapeState) => _reflectShapeStateToInputs(shapeState, {
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
    });

    function syncShapePanelUi() {
        const active = getActiveAnnAndPage();
        const activeShape = active && isShapeAnnotation(active.ann) ? active.ann : null;
        const activeShapeLocked = isAnnotationLocked(activeShape);
        const cutArmed = activeShape ? isShapeCutModeArmedFor(activeShape, active.pi) : false;
        if (activeShape) normalizeShapeAnnotation(activeShape);
        reflectShapeStateToInputs(activeShape || currentShapeDefaults());

        const showPanel = !!activeShape || (!editModeEnabled && shapeMode);
        if (shapeToolPanel) shapeToolPanel.classList.toggle('is-visible', showPanel);
        if (shapeDrawToggle) {
            shapeDrawToggle.textContent = shapeMode ? 'Drawing Active' : 'Draw Shape';
            shapeDrawToggle.classList.toggle('is-primary', !shapeMode);
            shapeDrawToggle.setAttribute('aria-pressed', shapeMode ? 'true' : 'false');
        }
        if (shapeModeIndicator) {
            shapeModeIndicator.textContent = shapeMode ? 'Draw Mode' : (activeShape ? 'Shape Selected' : 'Idle');
        }
        if (shapeToolStatus) {
            if (activeShape) {
                const shapeLabel = normalizeShapeType(activeShape.shapeType);
                if (cutArmed) {
                    shapeToolStatus.innerHTML = `<strong>Cut mode:</strong> drag a line through the selected ${shapeLabel} shape.`;
                } else {
                    shapeToolStatus.innerHTML = `<strong>Selected:</strong> ${shapeLabel.charAt(0).toUpperCase() + shapeLabel.slice(1)} shape ready for styling, resize, move, or cut.`;
                }
            } else if (shapeMode) {
                shapeToolStatus.innerHTML = '<strong>Drawing:</strong> drag on the canvas to place the next shape.';
            } else {
                shapeToolStatus.innerHTML = '<strong>Ready:</strong> pick a shape and click <span style="font-weight:700;">Draw Shape</span>.';
            }
        }
        if (shapeCopyBtn) shapeCopyBtn.disabled = !activeShape || activeShapeLocked;
        if (shapeDeleteBtn) shapeDeleteBtn.disabled = !activeShape || activeShapeLocked;
    }

    // setDrawToolStatus + clearActiveDrawSession moved to ./draw/session.js (Phase 7bf).
    const setDrawToolStatus = (message) => _setDrawToolStatus(drawToolStatus, message);

    function syncDrawToolPanelUi() {
        _syncDrawToolPanelUi({
            drawToolPanel,
            drawToolButtons,
            drawColorSwatches,
            drawToolColorInput,
            drawToolSizeInput,
            drawToolSizeValue,
            drawToolOpacityInput,
            drawToolOpacityValue,
            drawToolStatus,
        });
    }

    function setDrawMode(active, nextTool = drawToolType) {
        const nextState = !editModeEnabled && !!active;
        if (nextState) {
            cancelSignaturePlacement();
            cancelEraseMode();
            closeMarkupToolModal();
            setAddTextMode(false);
            setShapeMode(false);
            cancelShapeCutMode({ redraw: false });
            clearActiveAnnotation();
            setDrawToolType(nextTool);
        } else {
            clearActiveDrawSession();
        }
        setDrawModeActive(nextState);
        clearHoverState();
        syncCanvasCursors();
        syncDrawToolPanelUi();
        updateEditModeUi();
        redrawAllOverlays();
    }

    function updateEditModeUi() {
        if (editModeToggle) {
            editModeToggle.classList.toggle('is-active', editModeEnabled);
            editModeToggle.setAttribute('aria-pressed', editModeEnabled ? 'true' : 'false');
            editModeToggle.setAttribute(
                'title',
                editModeEnabled
                    ? 'Turn edit mode off to hide text boxes and lock text editing'
                    : 'Turn edit mode on to show editable text boxes'
            );
        }
        if (ftbEditMode) {
            ftbEditMode.classList.toggle('is-active', editModeEnabled);
        }
        if (editModeToggleLabel) {
            editModeToggleLabel.textContent = editModeEnabled ? 'Edit Mode ON' : 'Edit Mode OFF';
        }
        if (addTextBtn) {
            addTextBtn.disabled = editModeEnabled;
            addTextBtn.title = editModeEnabled
                ? 'Turn Edit Mode OFF to add text'
                : 'Add Text — click or drag on the page to place a new text block';
        }
        if (addShapeBtn) {
            addShapeBtn.disabled = editModeEnabled;
            addShapeBtn.title = editModeEnabled
                ? 'Turn Edit Mode OFF to draw shapes'
                : 'Shapes — choose a shape and drag on the page to draw it';
            addShapeBtn.classList.toggle('active', shapeMode);
        }
        if (ftbAddText) {
            ftbAddText.classList.toggle('is-disabled', editModeEnabled);
            ftbAddText.title = editModeEnabled
                ? 'Turn Edit Mode OFF to add text'
                : 'Text — click on the page to place a new text block';
        }
        if (ftbAddShape) {
            ftbAddShape.classList.toggle('is-disabled', editModeEnabled);
            ftbAddShape.classList.toggle('is-active', shapeMode);
            ftbAddShape.title = editModeEnabled
                ? 'Turn Edit Mode OFF to draw shapes'
                : 'Shapes — choose a shape, then drag on the page to draw';
        }
        if (ftbSign) {
            ftbSign.disabled = editModeEnabled;
            ftbSign.classList.toggle('is-disabled', editModeEnabled);
            ftbSign.classList.toggle(
                'is-active',
                !editModeEnabled && (
                    signatureModal?.classList.contains('is-open')
                    || (signaturePlacementState.active && signaturePlacementState.toolSource !== 'draw-erase')
                )
            );
            ftbSign.title = editModeEnabled
                ? 'Turn Edit Mode OFF to place signatures'
                : 'Sign — create a signature by drawing, typing, or uploading an image';
        }
        if (ftbDrawErase) {
            ftbDrawErase.disabled = editModeEnabled;
            ftbDrawErase.classList.toggle('is-disabled', editModeEnabled);
            ftbDrawErase.classList.toggle('is-active', !editModeEnabled && drawModeActive);
            ftbDrawErase.title = editModeEnabled
                ? 'Turn Edit Mode OFF to draw or erase annotations'
                : (drawModeActive
                    ? (drawToolType === DIRECT_DRAW_TOOL_ERASER
                        ? 'Eraser active — drag over a drawing to remove parts of it'
                        : drawToolType === DIRECT_DRAW_TOOL_SMOOTH_CURVE
                            ? 'Smooth curve active — drag directly on the page to draw a fitted curve'
                            : 'Pen active — drag directly on the page to draw')
                    : 'Draw & Erase — draw directly on the page or erase parts of an existing drawing');
        }
        if (ftbAddImage) {
            ftbAddImage.disabled = editModeEnabled;
            ftbAddImage.classList.toggle('is-disabled', editModeEnabled);
            ftbAddImage.title = editModeEnabled
                ? 'Turn Edit Mode OFF to insert an image'
                : 'Image — import an image and place it on the page';
        }
        syncShapePanelUi();
        syncDrawToolPanelUi();
    }

    function setEditModeEnabled(active) {
        const nextState = !!active;
        if (editModeEnabled === nextState) return;
        cancelSignaturePlacement();
        cancelEraseMode();
        cancelShapeCutMode({ redraw: false });
        if (nextState) {
            closeSignatureModal();
            closeMarkupToolModal();
            setDrawMode(false);
        }
        setEditModeEnabledFlag(nextState);
        setAddTextMode(false); // always exit addTextMode when edit mode changes
        setShapeMode(false);
        if (!editModeEnabled) {
            clearHoverState();
            clearActiveAnnotation();
        }
        updateEditModeUi();
        redrawAllOverlays();
        if (editModeEnabled && activeState.pi !== null) {
            syncActiveEditor();
        }
    }

    // signature status helpers moved to ./signature/status.js (Phase 7au).
    const setSignatureStatus = (m, t) => _setSignatureStatus(signatureStatus, m, t);
    const setSignatureDirtyState = (d) => {
        _setSignatureDirtyState(signatureApplyBtn, null, d);
        // The account save button follows the same dirty gate.
        if (signatureAccountSaveBtn) signatureAccountSaveBtn.disabled = !signatureDirty;
    };

    // markup-tool helpers moved to ./markup-tool/state.js (Phase 7at).
    const setMarkupToolStatus = (m, t) => _setMarkupToolStatus(markupToolStatus, m, t);
    const setMarkupToolDirtyState = (d) => _setMarkupToolDirtyState(markupToolApplyBtn, d);
    const clearMarkupToolCanvas = () => _clearMarkupToolCanvas(markupToolCanvas);
    const clearMarkupToolDrawingState = () => _clearMarkupToolDrawingState(markupToolCanvas);
    const getMarkupToolSmoothingAmount = () => _getMarkupToolSmoothingAmount(markupToolSmoothingInput);

    // paintMarkupToolStroke moved to ./draw/smooth-stroke.js (Phase 7aw).
    // renderMarkupToolDrawPreview moved to ./markup-tool/state.js (Phase 7bt).
    const renderMarkupToolDrawPreview = () => _renderMarkupToolDrawPreview(markupToolCanvas, markupToolSmoothingInput);

    // syncMarkupToolLabels moved to ./markup-tool/state.js (Phase 7bj).
    const syncMarkupToolLabels = () => _syncMarkupToolLabels({
        markupToolColorValue,
        markupToolColorInput,
        markupToolWidthValue,
        markupToolWidthInput,
        markupToolSmoothingValue,
        markupToolSmoothingInput,
    });

    function updateMarkupToolUi() {
        _updateMarkupToolUi({
            markupToolTabs,
            markupToolPanels,
            markupToolHint,
            markupToolClearBtn,
            markupToolApplyBtn,
            markupToolCanvas,
            markupToolSmoothingInput,
            markupToolStatus,
        });
    }

    function setMarkupToolMode(nextMode) {
        _setMarkupToolMode(nextMode, updateMarkupToolUi);
    }

    function resetMarkupToolComposer() {
        setMarkupToolMode('draw');
        if (markupToolColorInput) markupToolColorInput.value = '#0f172a';
        if (markupToolWidthInput) markupToolWidthInput.value = '4';
        if (markupToolSmoothingInput) markupToolSmoothingInput.value = '58';
        syncMarkupToolLabels();
        clearMarkupToolDrawingState();
        setMarkupToolDirtyState(false);
        setMarkupToolStatus('Draw a mark, then place it on the page.');
    }

    function buildCurrentMarkupAsset() {
        return _buildCurrentMarkupAsset(markupToolCanvas);
    }

    function getMarkupToolCanvasPoint(event) {
        return rawCanvasPointFromEvent(markupToolCanvas, event);
    }

    function beginMarkupToolStroke(event) {
        if (markupToolMode !== 'draw' || !markupToolCtx) return;
        const point = getMarkupToolCanvasPoint(event);
        if (!point) return;
        event.preventDefault();
        setMarkupToolDrawing(true);
        setMarkupToolActiveStroke({
            color: markupToolColorInput?.value || '#0f172a',
            width: Math.max(1, Number(markupToolWidthInput?.value) || 4),
            points: [point],
        });
        markupToolCanvas?.setPointerCapture?.(event.pointerId);
        renderMarkupToolDrawPreview();
    }

    function drawMarkupToolStroke(event) {
        if (!markupToolDrawing || markupToolMode !== 'draw' || !markupToolActiveStroke) return;
        const point = getMarkupToolCanvasPoint(event);
        if (!point) return;
        markupToolActiveStroke.points.push(point);
        renderMarkupToolDrawPreview();
    }

    function endMarkupToolStroke(event) {
        if (!markupToolDrawing || markupToolMode !== 'draw') return;
        setMarkupToolDrawing(false);
        markupToolCanvas?.releasePointerCapture?.(event?.pointerId);
        if (markupToolActiveStroke?.points?.length) markupToolStrokes.push(markupToolActiveStroke);
        setMarkupToolActiveStroke(null);
        renderMarkupToolDrawPreview();
        const hasContent = hasMarkupToolDrawContent();
        setMarkupToolDirtyState(hasContent);
        setMarkupToolStatus(
            hasContent ? 'Drawing ready to place.' : 'Draw a mark, then place it on the page.',
            hasContent ? 'ready' : 'default'
        );
    }

    // createDrawLayer, paintDrawDot, paintDrawSegment, localPointFromDrawSession
    // moved to ./draw/primitives.js (Phase 7ao).

    function beginDirectPenStroke(event, oc, pi) {
        const point = canvasPointFromEvent(event, oc);
        if (!point) return false;
        const layer = createDrawLayer(pi, 'draw-preview-layer', oc.clientWidth, oc.clientHeight, 0, 0, oc.clientWidth, oc.clientHeight);
        const ctx = layer?.getContext('2d');
        if (!layer || !ctx) return false;
        clearActiveDrawSession();
        event.preventDefault();
        oc.setPointerCapture?.(event.pointerId);
        layer.style.opacity = String(drawOpacity);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.imageSmoothingEnabled = true;
        paintDrawDot(ctx, point.x, point.y, drawBrushSize, drawStrokeColor);
        setActiveDrawSession({
            kind: 'pen',
            pi,
            overlay: oc,
            pointerId: event.pointerId,
            layer,
            ctx,
            color: drawStrokeColor,
            width: drawBrushSize,
            opacity: drawOpacity,
            directDrawTool: normalizeDirectDrawTool(drawToolType),
            points: [point],
            lastMidPoint: { x: point.x, y: point.y },
            minX: point.x - (drawBrushSize / 2),
            minY: point.y - (drawBrushSize / 2),
            maxX: point.x + (drawBrushSize / 2),
            maxY: point.y + (drawBrushSize / 2),
        });
        return true;
    }

    function moveDirectPenStroke(event, oc) {
        if (!activeDrawSession || activeDrawSession.kind !== 'pen' || activeDrawSession.overlay !== oc) return false;
        if (event.pointerId !== activeDrawSession.pointerId) return false;
        const point = canvasPointFromEvent(event, oc);
        if (!point) return true;
        const lastPoint = activeDrawSession.points[activeDrawSession.points.length - 1];
        const dx = point.x - lastPoint.x;
        const dy = point.y - lastPoint.y;
        if ((dx * dx) + (dy * dy) < 0.5) return true;
        activeDrawSession.points.push(point);
        activeDrawSession.minX = Math.min(activeDrawSession.minX, point.x - (activeDrawSession.width / 2));
        activeDrawSession.minY = Math.min(activeDrawSession.minY, point.y - (activeDrawSession.width / 2));
        activeDrawSession.maxX = Math.max(activeDrawSession.maxX, point.x + (activeDrawSession.width / 2));
        activeDrawSession.maxY = Math.max(activeDrawSession.maxY, point.y + (activeDrawSession.width / 2));
        if (activeDrawSession.directDrawTool === DIRECT_DRAW_TOOL_SMOOTH_CURVE) {
            activeDrawSession.ctx.clearRect(0, 0, activeDrawSession.layer.width, activeDrawSession.layer.height);
            paintSmoothDrawCurve(activeDrawSession.ctx, activeDrawSession, activeDrawSession.color, activeDrawSession.width);
        } else {
            const midPoint = { x: (lastPoint.x + point.x) / 2, y: (lastPoint.y + point.y) / 2 };
            paintDrawSegment(activeDrawSession.ctx, activeDrawSession.lastMidPoint, lastPoint, midPoint, activeDrawSession.width, activeDrawSession.color);
            activeDrawSession.lastMidPoint = midPoint;
        }
        return true;
    }

    function finishDirectPenStroke(event, oc) {
        if (!activeDrawSession || activeDrawSession.kind !== 'pen' || activeDrawSession.overlay !== oc) return false;
        if (event.pointerId !== activeDrawSession.pointerId) return false;
        const session = activeDrawSession;
        oc.releasePointerCapture?.(event.pointerId);
        if (session.points.length > 1 && session.directDrawTool !== DIRECT_DRAW_TOOL_SMOOTH_CURVE) {
            const lastPoint = session.points[session.points.length - 1];
            paintDrawSegment(session.ctx, session.lastMidPoint, lastPoint, lastPoint, session.width, session.color);
        }
        const padding = Math.max(session.width, 8);
        const cropLeft = Math.max(0, Math.floor(session.minX - padding));
        const cropTop = Math.max(0, Math.floor(session.minY - padding));
        const cropRight = Math.min(session.layer.width, Math.ceil(session.maxX + padding));
        const cropBottom = Math.min(session.layer.height, Math.ceil(session.maxY + padding));
        const pageInfo = pageData[session.pi];
        const pageScale = Math.max(0.0001, Number(pageInfo?.scale || 1));

        // ── Auto-grouping ────────────────────────────────────────────────────
        // If the user just drew another stroke on the same page within a short
        // time window AND the new stroke's bbox is close to the previous
        // direct-draw bbox, merge them into a single image annotation. This
        // gives the natural "scribble several quick marks → one selection"
        // behaviour while still keeping spatially-distant marks separate.
        const MERGE_TIME_MS = 1500;
        const MERGE_GAP_PT = 50;
        const mergeGapPx = Math.max(MERGE_GAP_PT * pageScale, session.width * 2);
        const now = Date.now();
        let mergeCandidate = null;
        if (pageInfo && Array.isArray(pageInfo.annotations)) {
            for (let i = pageInfo.annotations.length - 1; i >= 0; i -= 1) {
                const a = pageInfo.annotations[i];
                if (!a) continue;
                if (String(a.imageToolSource || '') !== 'direct-draw') continue;
                if (typeof a._drawCreatedAt !== 'number') continue;
                if (now - a._drawCreatedAt > MERGE_TIME_MS) continue;
                if (Math.abs(Number(a.rotation) || 0) > 0.01) continue; // rotated → unsafe to composite
                const aLeft = (Number(a.pdfX) || 0) * pageScale;
                const aBottom = oc.clientHeight - (Number(a.pdfY) || 0) * pageScale;
                const aRight = aLeft + (Number(a.pdfWidth) || 0) * pageScale;
                const aTop = aBottom - (Number(a.pdfHeight) || 0) * pageScale;
                const gapX = Math.max(0, Math.max(cropLeft, aLeft) - Math.min(cropRight, aRight));
                const gapY = Math.max(0, Math.max(cropTop, aTop) - Math.min(cropBottom, aBottom));
                const gap = Math.hypot(gapX, gapY);
                if (gap > mergeGapPx) continue;
                const cachedImg = getCachedAnnotationImage(a);
                if (!cachedImg) continue; // can't composite without the bitmap
                mergeCandidate = { ann: a, left: aLeft, top: aTop, right: aRight, bottom: aBottom, image: cachedImg };
                break;
            }
        }

        const uLeft = mergeCandidate ? Math.min(cropLeft, mergeCandidate.left) : cropLeft;
        const uTop = mergeCandidate ? Math.min(cropTop, mergeCandidate.top) : cropTop;
        const uRight = mergeCandidate ? Math.max(cropRight, mergeCandidate.right) : cropRight;
        const uBottom = mergeCandidate ? Math.max(cropBottom, mergeCandidate.bottom) : cropBottom;
        const cropWidth = Math.max(1, uRight - uLeft);
        const cropHeight = Math.max(1, uBottom - uTop);
        const outputCanvas = document.createElement('canvas');
        const outputScale = 2;
        outputCanvas.width = Math.max(1, Math.ceil(cropWidth * outputScale));
        outputCanvas.height = Math.max(1, Math.ceil(cropHeight * outputScale));
        const outputCtx = outputCanvas.getContext('2d');
        outputCtx.imageSmoothingEnabled = true;
        outputCtx.scale(outputScale, outputScale);
        if (mergeCandidate) {
            // Draw the prior drawing first (its image already has stroke opacity
            // baked in from its own export pass), then the new stroke on top.
            outputCtx.globalAlpha = 1;
            outputCtx.drawImage(
                mergeCandidate.image,
                mergeCandidate.left - uLeft,
                mergeCandidate.top - uTop,
                mergeCandidate.right - mergeCandidate.left,
                mergeCandidate.bottom - mergeCandidate.top
            );
        }
        outputCtx.globalAlpha = session.opacity;
        if (session.directDrawTool === DIRECT_DRAW_TOOL_SMOOTH_CURVE) {
            paintSmoothDrawCurve(outputCtx, {
                color: session.color,
                width: session.width,
                points: session.points.map((point) => ({
                    x: point.x - uLeft,
                    y: point.y - uTop,
                })),
            }, session.color, session.width);
        } else {
            outputCtx.drawImage(session.layer, -uLeft, -uTop);
        }
        const pdfWidth = cropWidth / pageScale;
        const pdfHeight = cropHeight / pageScale;
        const pdfX = uLeft / pageScale;
        const pdfY = (oc.clientHeight - uTop) / pageScale - pdfHeight;
        clearActiveDrawSession();

        if (mergeCandidate && pageInfo) {
            // Replace the prior annotation in-place with the merged one. Single
            // pushUndo() captures the pre-merge state so undo restores BOTH the
            // prior annotation AND removes the merged result.
            const mergedAnn = createImageAnnotationAtPageBox({
                type: 'image',
                dataUrl: outputCanvas.toDataURL('image/png'),
                width: outputCanvas.width,
                height: outputCanvas.height,
                fileName: 'drawing.png',
                mimeType: 'image/png',
                intrinsicWidth: outputCanvas.width,
                intrinsicHeight: outputCanvas.height,
                imageToolSource: 'direct-draw',
                directDrawTool: session.directDrawTool,
                drawStrokeColor: session.color,
                pdfX,
                pdfY,
                pdfWidth,
                pdfHeight,
            }, session.pi);
            if (mergedAnn) {
                pushUndo();
                const priorIdx = pageInfo.annotations.indexOf(mergeCandidate.ann);
                if (priorIdx >= 0) pageInfo.annotations.splice(priorIdx, 1);
                const priorId = String(mergeCandidate.ann.id || '').trim();
                if (priorId) pendingDeletedAnnotationIds.add(priorId);
                const firstTextIdx = pageInfo.annotations.findIndex((existing) => isTextAnnotation(existing));
                if (firstTextIdx < 0) {
                    pageInfo.annotations.push(mergedAnn);
                } else {
                    pageInfo.annotations.splice(firstTextIdx, 0, mergedAnn);
                }
                mergedAnn._drawCreatedAt = now;
                redrawOverlay(session.pi);
                clearActiveAnnotation();
                markDirty();
                setDrawToolStatus(
                    session.directDrawTool === DIRECT_DRAW_TOOL_SMOOTH_CURVE
                        ? 'Smooth curve added. Keep drawing or switch tools.'
                        : 'Drawing added. Keep drawing or switch to the eraser.',
                );
                return true;
            }
        }

        const created = placeImageBackedAnnotationAtPageBox(session.pi, {
            dataUrl: outputCanvas.toDataURL('image/png'),
            width: outputCanvas.width,
            height: outputCanvas.height,
            fileName: 'drawing.png',
            mimeType: 'image/png',
            imageToolSource: 'direct-draw',
            directDrawTool: session.directDrawTool,
            drawStrokeColor: session.color,
        }, {
            pdfX,
            pdfY,
            pdfWidth,
            pdfHeight,
        }, 'image');
        if (created) {
            created._drawCreatedAt = now;
            clearActiveAnnotation();
            setDrawToolStatus(
                session.directDrawTool === DIRECT_DRAW_TOOL_SMOOTH_CURVE
                    ? 'Smooth curve added. Keep drawing or switch tools.'
                    : 'Drawing added. Keep drawing or switch to the eraser.',
            );
        }
        return true;
    }

    async function beginDirectEraserStroke(event, oc, pi) {
        const data = pageData[pi];
        const point = canvasPointFromEvent(event, oc);
        if (!data || !point) return false;
        const ann = findAnnotationAt(point.x, point.y, data.annotations, data.scale, data.canvasHeight);
        if (!ann) return false;
        if (isAnnotationLocked(ann)) {
            setDrawToolStatus('This annotation is locked. Unlock it before erasing.');
            return true;
        }

        // For non-direct-draw annotations (shapes, text, etc.), delete them on click
        if (!isDirectDrawAnnotation(ann)) {
            deleteAnnotation(ann, pi);
            setDrawToolStatus('Annotation deleted.');
            return true;
        }

        // For direct-draw annotations, do partial erasing by painting over them
        if (Math.abs(Number(ann.rotation) || 0) > 0.01) {
            setDrawToolStatus('Rotate the drawing back to 0° before erasing it.');
            return true;
        }
        const box = resolveAnnBox(ann);
        if (!box) return true;
        const left = box.x * data.scale;
        const top = data.canvasHeight - (box.y + box.h) * data.scale;
        const displayWidth = Math.max(1, box.w * data.scale);
        const displayHeight = Math.max(1, box.h * data.scale);
        const layer = createDrawLayer(
            pi,
            'draw-edit-layer',
            Math.max(1, Number(ann.intrinsicWidth) || 1),
            Math.max(1, Number(ann.intrinsicHeight) || 1),
            left,
            top,
            displayWidth,
            displayHeight
        );
        const ctx = layer?.getContext('2d');
        if (!layer || !ctx) return true;
        clearActiveDrawSession();
        const src = getImageAnnotationSource(ann);
        try {
            const img = getCachedAnnotationImage(ann) || await loadImageSource(src);
            ctx.drawImage(img, 0, 0, layer.width, layer.height);
        } catch (_error) {
            if (layer.parentNode) layer.parentNode.removeChild(layer);
            setDrawToolStatus('Failed to load the drawing for erasing.');
            return true;
        }
        event.preventDefault();
        oc.setPointerCapture?.(event.pointerId);
        const localPoint = localPointFromDrawSession({ layer, left, top, displayWidth, displayHeight }, point);
        paintDrawDot(ctx, localPoint.x, localPoint.y, drawBrushSize, '#000000', 'destination-out');
        setActiveDrawSession({
            kind: 'eraser',
            pi,
            overlay: oc,
            pointerId: event.pointerId,
            ann,
            layer,
            ctx,
            left,
            top,
            displayWidth,
            displayHeight,
            width: drawBrushSize,
            lastPoint: localPoint,
            lastMidPoint: { x: localPoint.x, y: localPoint.y },
            dirty: true,
        });
        setDrawToolStatus('Erasing the selected drawing. Release to save the change.');
        return true;
    }

    function moveDirectEraserStroke(event, oc) {
        if (!activeDrawSession || activeDrawSession.kind !== 'eraser' || activeDrawSession.overlay !== oc) return false;
        if (event.pointerId !== activeDrawSession.pointerId) return false;
        const point = canvasPointFromEvent(event, oc);
        if (!point) return true;
        const localPoint = localPointFromDrawSession(activeDrawSession, point);
        const lastPoint = activeDrawSession.lastPoint;
        const dx = localPoint.x - lastPoint.x;
        const dy = localPoint.y - lastPoint.y;
        if ((dx * dx) + (dy * dy) < 0.5) return true;
        const midPoint = { x: (lastPoint.x + localPoint.x) / 2, y: (lastPoint.y + localPoint.y) / 2 };
        paintDrawSegment(activeDrawSession.ctx, activeDrawSession.lastMidPoint, lastPoint, midPoint, activeDrawSession.width, '#000000', 'destination-out');
        activeDrawSession.lastPoint = localPoint;
        activeDrawSession.lastMidPoint = midPoint;
        activeDrawSession.dirty = true;
        return true;
    }

    function finishDirectEraserStroke(event, oc) {
        if (!activeDrawSession || activeDrawSession.kind !== 'eraser' || activeDrawSession.overlay !== oc) return false;
        if (event.pointerId !== activeDrawSession.pointerId) return false;
        const session = activeDrawSession;
        oc.releasePointerCapture?.(event.pointerId);
        paintDrawSegment(session.ctx, session.lastMidPoint, session.lastPoint, session.lastPoint, session.width, '#000000', 'destination-out');
        const dataUrl = session.layer.toDataURL('image/png');
        clearActiveDrawSession();
        replaceImageBackedAnnotation(session.ann, session.pi, {
            dataUrl,
            width: session.ann.intrinsicWidth,
            height: session.ann.intrinsicHeight,
            fileName: session.ann.fileName || 'drawing.png',
            mimeType: session.ann.mimeType || 'image/png',
            imageToolSource: 'direct-draw',
            drawStrokeColor: session.ann.drawStrokeColor || null,
        });
        clearActiveAnnotation();
        setDrawToolStatus('Drawing updated. Continue erasing or switch back to the pen.');
        return true;
    }

    // cancelDirectDrawPointer moved to ./draw/session.js (Phase 7bw).

    // currentCanvasCursor moved to ./cursor/canvas-cursor.js (Phase 7be).
    // syncCanvasCursors moved to ./cursor/canvas-cursor.js (Phase 7bv).

    function openMarkupToolModal(initialMode = 'draw') {
        if (!markupToolModal || editModeEnabled) return;
        cancelSignaturePlacement();
        cancelEraseMode();
        cancelShapeCutMode({ redraw: false });
        setAddTextMode(false);
        setShapeMode(false);
        resetMarkupToolComposer();
        markupToolModal.classList.add('is-open');
        markupToolModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('markup-tool-modal-open');
        setMarkupToolMode(initialMode);
        updateEditModeUi();
    }

    function closeMarkupToolModal() {
        _closeMarkupToolModal(markupToolModal, updateEditModeUi);
    }

    function cancelEraseMode() {
        _cancelEraseMode({
            syncCursors: syncCanvasCursors,
            updateUi: updateEditModeUi,
            redrawAllOverlays,
        });
    }

    function setEraseMode(active) {
        const nextState = !editModeEnabled && !!active;
        if (eraseMode === nextState) {
            updateEditModeUi();
            return;
        }
        if (nextState) {
            cancelSignaturePlacement();
            setAddTextMode(false);
            setShapeMode(false);
            cancelShapeCutMode({ redraw: false });
            clearActiveAnnotation();
        }
        setEraseModeFlag(nextState);
        clearHoverState();
        syncCanvasCursors();
        updateEditModeUi();
        redrawAllOverlays();
    }

    // signature canvas helpers moved to ./signature/canvas.js (Phase 7av).
    const clearSignatureCanvas = () => _clearSignatureCanvas(signatureCanvas);
    const clearSignatureDrawingState = () => _clearSignatureDrawingState(signatureCanvas);
    const getSignatureSmoothingAmount = () => _getSignatureSmoothingAmount(signatureSmoothingInput);

    // paintSignatureStroke moved to ./draw/smooth-stroke.js (Phase 7aw).
    // renderDrawSignaturePreview moved to ./signature/canvas.js (Phase 7bu).
    const renderDrawSignaturePreview = () => _renderDrawSignaturePreview(signatureCanvas, signatureSmoothingInput);

    // syncSignatureColorLabels + updateSignatureModalCopy moved to ./signature/labels.js (Phase 7bh).
    const syncSignatureColorLabels = () => _syncSignatureColorLabels({
        signatureColorValue,
        signatureColorInput,
        signatureTypeColorValue,
        signatureTypeColorInput,
        signatureWidthValue,
        signatureWidthInput,
        signatureSmoothingValue,
        signatureSmoothingInput,
    });
    const updateSignatureModalCopy = () => _updateSignatureModalCopy({ signatureModalTitle, signatureModalSubtitle });

    // signatureModeLabel moved to ./annotations/types.js (Phase 7ao).

    // updateSignatureLibrarySaveUi moved to ./signature/status.js (Phase 7au).

    const typedSignatureFontSize = () => Math.max(24, Math.min(240, Number(signatureTypeSizeInput?.value) || 136));
    const syncSignatureTypeSizeLabel = () => {
        if (signatureTypeSizeValue) signatureTypeSizeValue.textContent = `${Math.round(typedSignatureFontSize())}px`;
    };

    function resetSignatureComposer() {
        setSignatureMode('draw');
        setSignatureImageAsset(null);
        setSignatureEditTarget(null);
        bumpSignatureTypedRenderToken();
        if (signatureTextInput) signatureTextInput.value = '';
        if (signatureImageInput) signatureImageInput.value = '';
        if (signatureImageName) signatureImageName.textContent = 'No file selected';
        if (signatureColorInput) signatureColorInput.value = '#111827';
        if (signatureTypeColorInput) signatureTypeColorInput.value = '#111827';
        if (signatureTypeSizeInput) signatureTypeSizeInput.value = '136';
        if (signatureWidthInput) signatureWidthInput.value = '3';
        if (signatureSmoothingInput) signatureSmoothingInput.value = '58';
        if (signatureFontInput) signatureFontInput.value = 'Great Vibes';
        syncSignatureColorLabels();
        syncSignatureTypeSizeLabel();
        clearSignatureDrawingState();
        setSignatureDirtyState(false);
        setSignatureStatus('Create a signature, then place it on the current page.');
        updateSignatureModalCopy();
        void ensureSignatureFontLoaded(signatureFontInput?.value || 'Great Vibes');
    }

    // readFileAsDataUrl + loadImageElement moved to ./util/image-load.js (Phase 7ci).

    // normalizeImportedImageAsset moved to ./images/normalize-imported.js (Phase 7cj).

    // ensureSignatureFontLoaded moved to ./signature/font-loader.js (Phase 7ch).

    async function renderTypedSignaturePreview() {
        const renderToken = bumpSignatureTypedRenderToken();
        if (signatureMode !== 'type' || !signatureCanvas || !signatureCtx) return;
        const text = String(signatureTextInput?.value || '').trim();
        clearSignatureCanvas();
        if (!text) {
            setSignatureDirtyState(false);
            return;
        }
        const fontName = signatureFontInput?.value || 'Great Vibes';
        await ensureSignatureFontLoaded(fontName);
        if (
            renderToken !== signatureTypedRenderToken
            || signatureMode !== 'type'
            || !signatureCanvas
            || !signatureCtx
        ) {
            return;
        }
        clearSignatureCanvas();
        const inkColor = signatureTypeColorInput?.value || '#111827';
        const maxWidth = signatureCanvas.width * 0.82;
        let fontSize = typedSignatureFontSize();
        signatureCtx.textAlign = 'center';
        signatureCtx.textBaseline = 'middle';
        signatureCtx.fillStyle = inkColor;
        while (fontSize > 24) {
            signatureCtx.font = `${fontSize}px "${fontName}", cursive`;
            if (signatureCtx.measureText(text).width <= maxWidth) break;
            fontSize -= 4;
        }
        signatureCtx.fillText(text, signatureCanvas.width / 2, signatureCanvas.height / 2);
        setSignatureDirtyState(true);
        setSignatureStatus('Typed signature ready to place.', 'ready');
    }

    function trimmedSignatureCanvasAsset() {
        if (!signatureCanvas || !signatureCtx) return null;
        const width = signatureCanvas.width;
        const height = signatureCanvas.height;
        if (!(width > 0) || !(height > 0)) return null;
        let data;
        try {
            data = signatureCtx.getImageData(0, 0, width, height);
        } catch (_) {
            return null;
        }

        let minX = width;
        let minY = height;
        let maxX = -1;
        let maxY = -1;
        const pixels = data.data;
        for (let y = 0; y < height; y += 1) {
            for (let x = 0; x < width; x += 1) {
                if (pixels[((y * width + x) * 4) + 3] <= 8) continue;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }
        if (maxX < minX || maxY < minY) return null;

        const contentWidth = maxX - minX + 1;
        const contentHeight = maxY - minY + 1;
        const padX = Math.max(2, Math.ceil(contentWidth * 0.05));
        const padY = Math.max(2, Math.ceil(contentHeight * 0.05));
        const sx = Math.max(0, minX - padX);
        const sy = Math.max(0, minY - padY);
        const sw = Math.min(width - sx, contentWidth + (padX * 2));
        const sh = Math.min(height - sy, contentHeight + (padY * 2));
        const trimmed = document.createElement('canvas');
        trimmed.width = Math.max(1, sw);
        trimmed.height = Math.max(1, sh);
        const ctx = trimmed.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(signatureCanvas, sx, sy, sw, sh, 0, 0, trimmed.width, trimmed.height);
        return {
            dataUrl: trimmed.toDataURL('image/png'),
            width: trimmed.width,
            height: trimmed.height,
        };
    }

    async function renderSignatureImagePreview() {
        if (signatureMode !== 'upload' || !signatureCanvas || !signatureCtx) return;
        clearSignatureCanvas();
        const previewSrc = String(signatureImageAsset?.dataUrl || signatureImageAsset?.src || '').trim();
        if (!previewSrc) {
            setSignatureDirtyState(false);
            return;
        }
        const img = await loadImageElement(previewSrc);
        const padding = 24;
        const availableWidth = Math.max(1, signatureCanvas.width - (padding * 2));
        const availableHeight = Math.max(1, signatureCanvas.height - (padding * 2));
        const drawScale = Math.min(availableWidth / img.naturalWidth, availableHeight / img.naturalHeight, 1);
        const drawWidth = Math.max(1, img.naturalWidth * drawScale);
        const drawHeight = Math.max(1, img.naturalHeight * drawScale);
        const drawX = (signatureCanvas.width - drawWidth) / 2;
        const drawY = (signatureCanvas.height - drawHeight) / 2;
        signatureCtx.drawImage(img, drawX, drawY, drawWidth, drawHeight);
        setSignatureDirtyState(true);
        setSignatureStatus('Uploaded signature ready to place.', 'ready');
    }

    function updateSignatureModeUi() {
        signatureTabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.signatureMode === signatureMode);
        });
        paintSignatureTabs([...signatureTabs, ...signatureViewTabs]);
        signaturePanels.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.signaturePanel === signatureMode);
        });
        if (signatureHint) {
            signatureHint.textContent = signatureMode === 'draw'
                ? 'Draw mode: click and drag to sign.'
                : (signatureMode === 'type'
                    ? 'Type mode: edit your name and preview it live.'
                    : 'Upload mode: choose an image and preview the stamp.');
        }
        if (signatureApplyBtn) {
            const actionVerb = signatureEditTarget ? 'Update' : 'Use';
            signatureApplyBtn.textContent = signatureMode === 'upload'
                ? `${actionVerb} uploaded signature`
                : (signatureMode === 'type' ? `${actionVerb} typed signature` : `${actionVerb} signature`);
        }
        if (signatureCanvas) signatureCanvas.style.cursor = signatureMode === 'draw' ? 'crosshair' : 'default';
        if (signatureMode === 'draw') {
            renderDrawSignaturePreview();
            setSignatureDirtyState(hasSignatureDrawContent());
            setSignatureStatus(
                hasSignatureDrawContent()
                    ? 'Signature ready to place.'
                    : 'Draw a signature, then place it on the page.',
                hasSignatureDrawContent() ? 'ready' : 'default'
            );
        } else if (signatureMode === 'type') {
            renderTypedSignaturePreview();
        } else if (signatureMode === 'upload') {
            renderSignatureImagePreview().catch((error) => {
                setSignatureStatus(error?.message || 'Failed to preview image.', 'error');
                setSignatureDirtyState(false);
            });
        }
    }

    function setSignatureMode(nextMode) {
        setSignatureModeValue(['draw', 'type', 'upload'].includes(String(nextMode)) ? String(nextMode) : 'draw');
        // Choosing a composer mode always leaves the Saved tab.
        showSavedView(false);
        updateSignatureModeUi();
    }

    /** Toggle the Saved tab. Saved is a view, never a composer mode. */
    function showSavedView(active) {
        setSavedViewActive(active, {
            signatureModal,
            modeTabs: signatureTabs,
            viewTabs: signatureViewTabs,
            restoreModeUi: updateSignatureModeUi,
        });
        if (active) {
            setSignatureStatus('Pick a saved signature to load it into the composer.');
        }
    }

    function buildCurrentSignatureAsset() {
        if (!signatureDirty || !signatureCanvas) return null;

        if (signatureMode === 'upload') {
            const uploadAsset = signatureImageAsset || {};
            const previewSrc = String(uploadAsset.dataUrl || uploadAsset.src || '').trim();
            if (!previewSrc) return null;
            return {
                ...uploadAsset,
                dataUrl: uploadAsset.dataUrl || '',
                src: uploadAsset.src || '',
                width: uploadAsset.width || uploadAsset.intrinsicWidth || 1,
                height: uploadAsset.height || uploadAsset.intrinsicHeight || 1,
                fileName: uploadAsset.fileName || 'signature.png',
                mimeType: uploadAsset.mimeType || 'image/png',
                assetPath: uploadAsset.assetPath || uploadAsset.imagePath || null,
                signatureSourceMode: 'upload',
                signatureComposer: {
                    mode: 'upload',
                    imageAsset: {
                        dataUrl: uploadAsset.dataUrl || '',
                        src: uploadAsset.src || '',
                        width: uploadAsset.width || uploadAsset.intrinsicWidth || 1,
                        height: uploadAsset.height || uploadAsset.intrinsicHeight || 1,
                        fileName: uploadAsset.fileName || 'signature.png',
                        mimeType: uploadAsset.mimeType || 'image/png',
                        assetPath: uploadAsset.assetPath || uploadAsset.imagePath || null,
                    },
                },
            };
        }

        const trimmedAsset = trimmedSignatureCanvasAsset();
        const canvasAsset = {
            dataUrl: trimmedAsset?.dataUrl || signatureCanvas.toDataURL('image/png'),
            width: trimmedAsset?.width || signatureCanvas.width,
            height: trimmedAsset?.height || signatureCanvas.height,
            fileName: 'signature.png',
            mimeType: 'image/png',
            signatureSourceMode: signatureMode,
        };

        if (signatureMode === 'type') {
            canvasAsset.signatureComposer = {
                mode: 'type',
                text: String(signatureTextInput?.value || '').trim(),
                fontFamily: String(signatureFontInput?.value || 'Great Vibes').trim(),
                inkColor: normalizeHexColor(signatureTypeColorInput?.value, '#111827'),
                fontSize: typedSignatureFontSize(),
            };
            return canvasAsset;
        }

        canvasAsset.signatureComposer = {
            mode: 'draw',
            strokes: cloneSerializableValue(signatureStrokes, []),
            inkColor: normalizeHexColor(signatureColorInput?.value, '#111827'),
            strokeWidth: Math.max(1, Number(signatureWidthInput?.value) || 3),
            smoothing: Math.max(0, Math.min(100, Number(signatureSmoothingInput?.value) || 58)),
        };
        return canvasAsset;
    }

    async function loadSignatureComposerFromAnnotation(ann) {
        if (!ann || String(ann.type || '').toLowerCase() !== 'signature') return;

        const composer = ann.signatureComposer && typeof ann.signatureComposer === 'object'
            ? ann.signatureComposer
            : null;
        const preferredMode = normalizeSignatureSourceMode(composer?.mode || ann.signatureSourceMode);

        if (preferredMode === 'type' && composer) {
            if (signatureTextInput) signatureTextInput.value = String(composer.text || '');
            if (signatureFontInput) signatureFontInput.value = String(composer.fontFamily || 'Great Vibes');
            if (signatureTypeColorInput) {
                signatureTypeColorInput.value = normalizeHexColor(composer.inkColor, '#111827');
            }
            if (signatureTypeSizeInput) signatureTypeSizeInput.value = String(Math.max(24, Math.min(240, Number(composer.fontSize) || 136)));
            syncSignatureColorLabels();
            syncSignatureTypeSizeLabel();
            setSignatureMode('type');
            await renderTypedSignaturePreview();
            setSignatureStatus('Typed signature loaded. Update it and save it back to the page.', signatureDirty ? 'ready' : 'default');
            return;
        }

        if (preferredMode === 'draw' && Array.isArray(composer?.strokes) && composer.strokes.length > 0) {
            if (signatureColorInput) signatureColorInput.value = normalizeHexColor(composer.inkColor, '#111827');
            if (signatureWidthInput) signatureWidthInput.value = String(Math.max(1, Number(composer.strokeWidth) || 3));
            if (signatureSmoothingInput) signatureSmoothingInput.value = String(Math.max(0, Math.min(100, Number(composer.smoothing) || 58)));
            syncSignatureColorLabels();
            setSignatureStrokes(cloneSerializableValue(composer.strokes, []));
            setSignatureActiveStroke(null);
            setSignatureMode('draw');
            renderDrawSignaturePreview();
            setSignatureDirtyState(hasSignatureDrawContent());
            setSignatureStatus('Drawn signature loaded. Keep drawing or save it back to the page.', signatureDirty ? 'ready' : 'default');
            return;
        }

        const imageSource = getImageAnnotationSource(ann);
        setSignatureImageAsset({
            dataUrl: String(composer?.imageAsset?.dataUrl || '').trim(),
            src: String(composer?.imageAsset?.src || imageSource || '').trim(),
            width: Math.max(1, Number(composer?.imageAsset?.width || ann.intrinsicWidth || ann.pdfWidth || 1) || 1),
            height: Math.max(1, Number(composer?.imageAsset?.height || ann.intrinsicHeight || ann.pdfHeight || 1) || 1),
            fileName: String(composer?.imageAsset?.fileName || ann.fileName || 'signature.png'),
            mimeType: String(composer?.imageAsset?.mimeType || ann.mimeType || 'image/png'),
            assetPath: String(composer?.imageAsset?.assetPath || ann.assetPath || ann.imagePath || ''),
        });
        if (signatureImageName) {
            signatureImageName.textContent = `${signatureImageAsset.fileName} • ${signatureImageAsset.width}×${signatureImageAsset.height}`;
        }
        setSignatureMode('upload');
        await renderSignatureImagePreview();
        setSignatureStatus('Signature loaded. Replace it or save it back to the page.', signatureDirty ? 'ready' : 'default');
    }

    function openSignatureModal(initialMode = 'draw') {
        if (!signatureModal || editModeEnabled) return;
        closeMarkupToolModal();
        cancelEraseMode();
        cancelShapeCutMode({ redraw: false });
        setDrawMode(false);
        setAddTextMode(false);
        setShapeMode(false);
        resetSignatureComposer();
        signatureModal.classList.add('is-open');
        signatureModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('signature-modal-open');
        setSignatureMode(initialMode);
        updateEditModeUi();
    }

    async function openSignatureEditModal(ann, pi) {
        if (!signatureModal || editModeEnabled) return;
        if (!ann || String(ann.type || '').toLowerCase() !== 'signature') return;
        closeMarkupToolModal();
        cancelEraseMode();
        cancelShapeCutMode({ redraw: false });
        setDrawMode(false);
        setAddTextMode(false);
        setShapeMode(false);
        cancelSignaturePlacement();
        resetSignatureComposer();
        setSignatureEditTarget({ pi, uid: ann._uid });
        updateSignatureModalCopy();
        updateSignatureModeUi();
        signatureModal.classList.add('is-open');
        signatureModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('signature-modal-open');
        setSignatureStatus('Loading selected signature…');
        try {
            await loadSignatureComposerFromAnnotation(ann);
        } catch (error) {
            setSignatureStatus(error?.message || 'Failed to load signature.', 'error');
            setSignatureDirtyState(false);
        }
        updateEditModeUi();
    }

    function closeSignatureModal() {
        _closeSignatureModal(signatureModal, {
            updateModalCopy: updateSignatureModalCopy,
            updateUi: updateEditModeUi,
        });
    }

    function cancelSignaturePlacement() {
        _cancelSignaturePlacement({ syncCursors: syncCanvasCursors, updateUi: updateEditModeUi });
    }

    function beginSignaturePlacement(asset, type = 'signature') {
        if (!asset?.dataUrl && !asset?.src) return;
        setSignaturePlacementState({
            active: true,
            asset: {
                dataUrl: asset.dataUrl || '',
                src: asset.src || '',
                width: asset.width || asset.intrinsicWidth || 1,
                height: asset.height || asset.intrinsicHeight || 1,
                fileName: asset.fileName || null,
                mimeType: asset.mimeType || 'image/png',
                assetPath: asset.assetPath || asset.imagePath || null,
                signatureSourceMode: normalizeSignatureSourceMode(asset.signatureSourceMode),
                signatureComposer: cloneSerializableValue(asset.signatureComposer || null, null),
            },
            type: String(type || 'signature').toLowerCase() === 'image' ? 'image' : 'signature',
            toolSource: asset.toolSource || null,
        });
        syncCanvasCursors();
        clearActiveAnnotation();
        updateEditModeUi();
    }

    function getSignatureCanvasPoint(event) {
        return rawCanvasPointFromEvent(signatureCanvas, event);
    }

    function beginSignatureStroke(event) {
        if (signatureMode !== 'draw' || !signatureCtx) return;
        const point = getSignatureCanvasPoint(event);
        if (!point) return;
        event.preventDefault();
        setSignatureDrawing(true);
        setSignatureActiveStroke({
            color: signatureColorInput?.value || '#111827',
            width: Math.max(1, Number(signatureWidthInput?.value) || 3),
            points: [point],
        });
        renderDrawSignaturePreview();
        setSignatureDirtyState(true);
        setSignatureStatus('Signature ready to place.', 'ready');
    }

    function drawSignatureStroke(event) {
        if (!signatureDrawing || signatureMode !== 'draw' || !signatureCtx) return;
        const point = getSignatureCanvasPoint(event);
        if (!point) return;
        signatureActiveStroke?.points?.push(point);
        renderDrawSignaturePreview();
    }

    function endSignatureStroke() {
        if (!signatureDrawing) return;
        setSignatureDrawing(false);
        if (signatureActiveStroke?.points?.length) {
            signatureStrokes.push(signatureActiveStroke);
        }
        setSignatureActiveStroke(null);
        renderDrawSignaturePreview();
        setSignatureDirtyState(hasSignatureDrawContent());
    }

    // getEditorPlainText, stripEditorSentinels, richHtmlHasInlineSelectionFormatting
    // moved to ./editor/plain-text.js (Phase 7an).

    // normalizeRichHtmlForDisplay moved to ./editor/plain-text.js (Phase 7ar).

    // canonicalTextLength / domTextOffsetForCanonicalOffset / childIndex /
    // editorDomPositionToTextOffset / getEditorSelectionOffsets /
    // findEditorTextPosition / setEditorSelectionOffsets moved to
    // ./editor/selection-offsets.js (Phase 7al).

    // markDirty / markClean moved to ./store/lifecycle-flags.js (Phase 7g);
    // configureLifecycle({updateSaveUi, scheduleAutoSave}) below wires the
    // side-effect callbacks they need.

    // Auto-save: debounce for 800ms of idle time after the last edit. The
    // factory in ./persistence/autosave.js owns the timer; this file just
    // tells it when there are pending changes and how to actually save.
    const autoSave = createAutoSave({
        debounceMs: 800,
        shouldSave: () =>
            isDirty
            || pendingDeletedAnnotationIds.size > 0
            || pendingDeletedPromotedSourceKeys.size > 0,
        isBusy: () => isSaving,
        runSave: () => saveAllChanges({ silent: true }),
    });
    const scheduleAutoSave = autoSave.schedule;
    const triggerAutoSave = autoSave.trigger;
    const flushAutoSaveIfPending = autoSave.flushIfPending;
    configureLifecycle({
        updateSaveUi: () => updateSaveUi(),
        scheduleAutoSave: () => scheduleAutoSave(),
    });

    // Test-only: force a save POST to /save-annotation-state regardless of the
    // current dirty/timer state. Used by automated tests that need to observe
    // the network round-trip after the auto-save debounce may have already
    // fired and reset isDirty.
    async function forceSaveForTests() {
        autoSave.cancel();
        const wasDirty = isDirty;
        setDirty(true);
        try { await saveAllChanges({ silent: true }); }
        finally { if (!wasDirty && isDirty) markClean(); }
    }
    window.addEventListener('beforeunload', flushAutoSaveIfPending);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flushAutoSaveIfPending();
    });

    // ── Drag-to-reposition ────────────────────────────────────────────────────
    // pdfPtFromClient moved to ./render/coords.js (Phase 5.5b).
    // beginDrag / endDrag moved to ./interactions/drag.js (Phase 6a) and are
    // wired to main.js helpers via configureDragInteractions() below.
    configureDragInteractions({
        syncActiveEditor: (restore) => syncActiveEditor(restore),
    });
    configureResizeInteractions({
        editableLineStyle: (ann, lineIndex) => editableLineStyle(ann, lineIndex),
    });
    installPointerDispatcher({
        redrawOverlay: (pi) => redrawOverlay(pi),
        syncActiveEditor: (force) => syncActiveEditor(force),
        updateFormatBar: () => updateFormatBar(),
    });
    configureHistory({
        undoButton,
        redoButton,
        clearActiveAnnotation: () => clearActiveAnnotation(),
        redrawOverlay: (pi) => redrawOverlay(pi),
    });
    configureMeasure({
        editableLineStyle: (ann, lineIndex) => editableLineStyle(ann, lineIndex),
        blockLineHeightPx: (ann, lineIndex, scale, style) => blockLineHeightPx(ann, lineIndex, scale, style),
    });

    // Rotation helpers (getRotationCenterClient, pointerAngleDeg, beginRotate,
    // endRotate) moved to ./interactions/rotate.js (Phase 6c).

    // shouldScaleFontOnResize / scaledResizeFontSize and the window-level
    // mousemove / mouseup / pointer* listeners moved to
    // ./interactions/pointer-dispatcher.js (Phase 6d).

    // beginResize / endResize moved to ./interactions/resize.js (Phase 6b).
    // endDrag moved to ./interactions/drag.js (Phase 6a).

    // Rotation helpers (getRotationCenterClient, pointerAngleDeg, beginRotate,
    // endRotate) moved to ./interactions/rotate.js (Phase 6c).

    // (mousemove / mouseup / pointer* handlers moved to pointer-dispatcher.js — Phase 6d)

    window.addEventListener('keydown', (e) => {
        if (markupToolModal?.classList.contains('is-open') && e.key === 'Escape') {
            e.preventDefault();
            closeMarkupToolModal();
            return;
        }
        if (signatureModal?.classList.contains('is-open') && e.key === 'Escape') {
            e.preventDefault();
            closeSignatureModal();
            return;
        }
        if (eraseMode && e.key === 'Escape') {
            e.preventDefault();
            cancelEraseMode();
            return;
        }
        if (drawModeActive && e.key === 'Escape') {
            e.preventDefault();
            setDrawMode(false);
            return;
        }
        if (shapeCutState.armed && e.key === 'Escape') {
            e.preventDefault();
            cancelShapeCutMode();
            return;
        }
        if (signaturePlacementState.active && e.key === 'Escape') {
            e.preventDefault();
            cancelSignaturePlacement();
            return;
        }
        // Undo / Redo — never intercept when focus is inside a text field.
        const inTextField = document.activeElement && (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA' ||
            document.activeElement.isContentEditable
        );
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
            if (!inTextField) { e.preventDefault(); performUndo(); return; }
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z'))) {
            if (!inTextField) { e.preventDefault(); performRedo(); return; }
        }

        // Ctrl/Cmd+C — copy the active annotation into an in-memory clipboard
        // (only when the user isn't editing text and has nothing selected).
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'c' || e.key === 'C')) {
            if (inTextField) return; // editing text → native copy
            const sel = window.getSelection?.();
            if (sel && !sel.isCollapsed && String(sel.toString() || '').length > 0) return;
            if (!activeState.uid || activeState.pi === null) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            e.preventDefault();
            const { ann } = active;
            // Snapshot a deep-ish copy so future mutations to the source don't
            // leak into the clipboard.
            setAnnClipboard({
                ann: JSON.parse(JSON.stringify(ann)),
                text: String(editedTexts[ann._uid] ?? ann.text ?? ''),
            });
            return;
        }

        // Ctrl/Cmd+V — paste the most recently copied annotation onto the page
        // currently containing the active annotation (or page 0 if nothing
        // active). Always offset slightly so it's visible.
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'v' || e.key === 'V')) {
            if (inTextField) return; // editing text → native paste
            if (!_annClipboard) return;
            e.preventDefault();
            const targetPi = activeState.pi !== null ? activeState.pi : 0;
            const data = pageData[targetPi];
            if (!data) return;
            pushUndo();
            const OFFSET = 12;
            const src = _annClipboard.ann;
            const newAnn = {
                ...JSON.parse(JSON.stringify(src)),
                _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
                id:   generateAnnotationId(),
                pageIndex: targetPi,
                pdfX: Math.min((Number(src.pdfX) || 0) + OFFSET, Math.max(0, data.wPts - (Number(src.pdfWidth) || 60))),
                pdfY: Math.max((Number(src.pdfY) || 0) - OFFSET, 0),
                userCreated: true,
            };
            newAnn._originalBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            newAnn._originalPdfBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            editedTexts[newAnn._uid] = String(_annClipboard.text ?? '');
            data.annotations.push(newAnn);
            selectAnnotation(newAnn, targetPi);
            markDirty();
            return;
        }

        if (!editModeEnabled && !addTextMode && !eraseMode) return;
        // Only the Delete key removes a selected annotation. Backspace is
        // reserved for text editing — too easy to nuke the whole annotation
        // by hitting Backspace when focus drifted off the contenteditable.
        if (e.key !== 'Delete') return;
        if (dragState.active || resizeState.active) return;
        if (!activeState.uid || activeState.pi === null) return;
        // Never delete-on-Delete while the user is typing inside ANY
        // contenteditable / input (the page AE, the debug modal preview
        // host, format-bar inputs, etc.). Without this guard a Delete press
        // inside the modal's reconstruction host would nuke the whole
        // annotation instead of deleting one character.
        const focused = document.activeElement;
        if (focused) {
            const tag = String(focused.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (focused.isContentEditable) return;
        }
        e.preventDefault();
        const ann = pageData[activeState.pi]?.annotations.find(a => a._uid === activeState.uid);
        if (!ann) return;
        if (isAnnotationLocked(ann)) return;
        deleteAnnotation(ann, activeState.pi);
    });

    // ── Save ──────────────────────────────────────────────────────────────────
    // showError + showToast moved to ./ui/banner.js (Phase 7bd).
    const showError = (msg) => _showErrorBanner(errorBanner, msg);
    const showToast = (msg) => _showSaveToast(saveToast, msg);

    function buildPersistedAnnotationPayload(ann) {
        const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
        const payload = normalizeTextAnnotation(Object.assign({}, ann, {
            text: currentText,
        }));
        // Promote the editor's display-only dark-bg fallback (resolveDisplayBgColor)
        // into a real persisted backgroundColor when the annotation has white text
        // but no explicit bg. Without this the editor shows white text on a dark
        // slate placeholder, but the saved/downloaded PDF would render white-on-
        // transparent (invisible on a white page) — i.e. the user sees text in
        // the editor and gets a blank PDF on download.
        if (String(payload.type || '').toLowerCase() === 'text'
            && !payload.backgroundColorExplicit
            && (!payload.backgroundColor || String(payload.backgroundColor).toLowerCase() === 'transparent')
            && payload.textColor
            && WHITE_TEXT_RE.test(String(payload.textColor).replace(/\s/g, ''))
            && String(currentText || '').length > 0) {
            payload.backgroundColor = '#2c3e50';
            payload.backgroundColorExplicit = true;
        }
        const persistedSourceBox = shouldPersistPromotedSourceBoxForBackground(ann, currentText)
            ? resolveSourceAnnBox(ann)
            : null;
        if (persistedSourceBox) {
            payload.pdfX = persistedSourceBox.x;
            payload.pdfY = persistedSourceBox.y;
            payload.pdfWidth = persistedSourceBox.w;
            payload.pdfHeight = persistedSourceBox.h;
        }
        if (ann.promotedFromExtraction) {
            payload.promotedDirty = shouldPersistPromotedDirty(ann, currentText);
        }
        if (ann._sourceExactTextEdited || ann.sourceExactTextEdited || ann.preserveSourceLayout) {
            payload.sourceExactTextEdited = true;
            payload.preserveSourceLayout = true;
            payload.promotedDirty = true;
        }
        const userAuthoredFlag = !!(ann._userAuthored || ann.userAuthored || payload.promotedDirty);
        payload.userAuthored = userAuthoredFlag;
        payload.locked = isAnnotationLocked(ann);
        if (ann._styleDirty) {
            payload.styleDirty = true;
        }
        // Persist per-selection rich HTML (bold/italic/font/colour spans baked
        // into the contenteditable by execCommand or manualWrapSelection) so it
        // survives a page reload. We standardise on the top-level
        // `richTextHtml` key — the Python exporter
        // (apply_annotations_direct{,_new}.py) reads that field, and the
        // hydration path below restores `_richHtml` from it on reload. Older
        // payloads that wrote `richHtml` (top-level or under annotation_data)
        // are still read on hydration for backwards compatibility.
        const richHtml = (
            typeof ann._richHtml === 'string'
            && ann._richHtml.length > 0
            && richHtmlHasInlineSelectionFormatting(ann._richHtml)
        )
            ? stripEditorSentinels(ann._richHtml)
            : null;
        if (richHtml) {
            payload.richTextHtml = richHtml;
        } else {
            delete payload.richTextHtml;
            delete payload.richHtml;
        }
        if (payload.annotation_data && typeof payload.annotation_data === 'object') {
            payload.annotation_data = Object.assign({}, payload.annotation_data, {
                text: currentText,
                pdfX: payload.pdfX,
                pdfY: payload.pdfY,
                pdfWidth: payload.pdfWidth,
                pdfHeight: payload.pdfHeight,
                lineHeight: payload.lineHeight,
                userAuthored: userAuthoredFlag,
                locked: payload.locked,
                styleDirty: !!ann._styleDirty,
                sourceTextLines: Array.isArray(payload.sourceTextLines) ? payload.sourceTextLines : ann.sourceTextLines,
                sourceSpans: Array.isArray(payload.sourceSpans) ? payload.sourceSpans : ann.sourceSpans,
                sourceExactTextEdited: !!payload.sourceExactTextEdited,
                preserveSourceLayout: !!payload.preserveSourceLayout,
            });
            if (ann.promotedFromExtraction) {
                payload.annotation_data.promotedDirty = payload.promotedDirty;
            }
            if (!richHtml) {
                delete payload.annotation_data.richTextHtml;
                delete payload.annotation_data.richHtml;
            }
        }
        delete payload._originalBox;
        delete payload._originalPdfBox;
        delete payload._styleDirty;
        delete payload._richHtml;
        delete payload._userAuthored;
        delete payload._sourceExactTextEdited;
        delete payload._renderableSourceLines;
        // Stage2: _committed is transient routing state, re-derived on
        // hydrate via legacyShouldDomRender memoization in
        // shouldRenderTextInRichHtmlLayer. Never persist.
        delete payload._committed;
        return payload;
    }

    // shouldPersistPromotedSourceBoxForBackground + shouldPersistPromotedDirty
    // moved to ./annotations/state.js (Phase 7br).

    function clearSourceExactFlagsForPlainTextEdit(ann) {
        if (!ann || typeof ann !== 'object') return;
        if (ann.preserveSourceLayout || ann.sourceExactTextEdited || ann._sourceExactTextEdited) {
            ann.preserveSourceLayout = false;
            ann.sourceExactTextEdited = false;
            ann._sourceExactTextEdited = false;
            if (ann.annotation_data && typeof ann.annotation_data === 'object') {
                ann.annotation_data.preserveSourceLayout = false;
                ann.annotation_data.sourceExactTextEdited = false;
            }
        }
        delete ann._renderableSourceLines;
    }

    function flushActiveEditorState() {
        if (activeState.pi === null || !activeState.uid) return;
        const ae = document.getElementById('ae-' + (activeState.pi + 1));
        if (!(ae instanceof HTMLElement) || ae.style.display === 'none') return;
        const ann = pageData[activeState.pi]?.annotations.find(a => a._uid === activeState.uid);
        if (!ann) return;
        // Only flush when the contenteditable is actually the source of truth.
        // After a format-bar change (color / align / font / etc.) on a
        // user-authored annotation, syncActiveEditor switches the render mode
        // to 'dom-layer' and empties `ae` — the canonical text now lives in
        // editedTexts[uid] and is rendered via rich-html-layer. Reading
        // getEditorPlainText(ae) in that state returns "" and would delete
        // editedTexts[uid] (since "" === ann.text === ""), causing the save
        // payload to ship text:"" and persistently wipe the user's typed text
        // on reload — the "text vanishes after changing color/alignment" bug.
        if (!editorIsEditingAnnotation(ae, ann)) return;
        const renderMode = ae.dataset.renderMode || '';
        if (renderMode === 'galley') {
            reconcileGalleyInput(ae, ann, activeState.pi);
        }
        let nextText = renderMode === 'galley'
            ? expectedGalleyText(ann, ae)
            : getEditorPlainText(ae);
        const savedText = String(ann.text ?? '');
        if (renderMode === 'paragraph'
            && annotationTextMatchesSource(ann, savedText)
            && annotationTextMatchesSource(ann, nextText)) {
            nextText = savedText;
        }
        if (nextText !== savedText) {
            markUserAuthored(ann);
            clearSourceExactFlagsForPlainTextEdit(ann);
            // Stage2: explicit commit at the canonical "user finalized
            // a text edit" moment. This routes all subsequent paints
            // through the DOM rich-html-layer immediately, instead of
            // waiting for the next shouldRenderTextInRichHtmlLayer call
            // to observe the divergence and memoize.
            markCommitted(ann, 'flushActiveEditorState:textChanged');
        }
        const sourceAwareRenderMode = renderMode === 'source' || renderMode === 'source-flow';
        if (renderMode !== 'galley' && !sourceAwareRenderMode && richHtmlHasInlineSelectionFormatting(ae.innerHTML)) {
            ann._richHtml = ae.innerHTML;
            markCommitted(ann, 'flushActiveEditorState:richHtmlAssigned');
        } else if (sourceAwareRenderMode && nextText !== savedText) {
            delete ann._richHtml;
        }
        if (nextText === savedText) {
            delete editedTexts[ann._uid];
        } else {
            if (editedTexts[ann._uid] !== nextText) {
                markDirty();
            }
            editedTexts[ann._uid] = nextText;
        }
    }

    function collectSessionAnnotations() {
        return Object.values(pageData)
            .flatMap((data) => Array.isArray(data?.annotations) ? data.annotations : [])
            .map((ann) => buildPersistedAnnotationPayload(ann));
    }
    // Test hook: expose on window for headless download tool + inspection.
    try {
        window.collectSessionAnnotations = collectSessionAnnotations;
        Object.defineProperty(window, 'pendingDeletedPromotedSourceKeys', {
            get: () => pendingDeletedPromotedSourceKeys,
            configurable: true,
        });
        Object.defineProperty(window, 'acroFormEntries', {
            get: () => (typeof acroFormEntries !== 'undefined' ? acroFormEntries : []),
            configurable: true,
        });
        // Bridge for the pdf-new test harness (run_pdf_tests.cjs). All editor
        // state is IIFE-scoped, so the harness needs an explicit hook to read /
        // mutate annotations programmatically.
        window.__editorTestState = {
            get pageData()    { return pageData; },
            get editedTexts() { return editedTexts; },
            get activeState() { return activeState; },
            get addTextMode() { return addTextMode; },
            get isDirty()     { return isDirty; },
            get isSaving()    { return isSaving; },
            getSessionId,
            setAddTextMode,
            createNewTextAnnotation,
            redrawOverlay,
            markDirty,
            scheduleAutoSave,
            triggerAutoSave,
            flushAutoSaveIfPending,
            forceSaveForTests,
            saveAllChanges,
            generateAnnotationId,
            selectAnnotation,
            clearActiveAnnotation,
            setEditModeEnabled,
            updateFormatBar,
            normalizeShapeAnnotation,
            createShapeAnnotationFromPoints,
            // Stage1 font diagnostics: returns the per-family load/probe
            // outcome captured by embedded-fonts.js validateHealth().
            getFontDiagnostics: () => (typeof embeddedFontRegistry?.getDiagnostics === 'function'
                ? embeddedFontRegistry.getDiagnostics()
                : null),
            // Stage3 raster-patch diagnostics: returns the count of
            // committed text annotations currently being raster-erased
            // on a given page (defaults to page 0).
            getCommittedPatchCount: (pi = 0) => getCommittedPatchCount(pageData[pi]),
            // Stage3: force re-application of raster patches for a given
            // page (defaults to page 0). Returns true when a repaint
            // happened, false on no-op.
            applyRasterPatches: (pi = 0) => {
                const bg = document.getElementById('en-canvas-' + (pi + 1));
                return bg ? applyCommittedRasterPatches(bg, pageData[pi]) : false;
            },
        };
    } catch (_e) {}

    async function downloadReadyPdf() {
        if (isSaving || isDownloadingPdf) return;
        flushActiveEditorState();

        const popup = window.open('', '_blank');
        if (popup) {
            popup.document.write('<!DOCTYPE html><title>Preparing PDF</title><body style="font-family:system-ui,sans-serif;padding:24px;color:#111827;">Preparing PDF...</body>');
            popup.document.close();
        }

        // Persist any pending edits BEFORE generating the PDF so the user
        // never has to click Save manually. Flush a debounced auto-save if one
        // is queued, then await it (saveAllChanges is a no-op when nothing is
        // dirty). This also ensures the saved DB state matches the downloaded
        // PDF for the same click.
        autoSave.cancel();
        if (isDirty || pendingDeletedAnnotationIds.size > 0 || pendingDeletedPromotedSourceKeys.size > 0) {
            try { await saveAllChanges({ silent: true }); }
            catch (_error) { /* saveAllChanges surfaces its own toast */ }
        }

        setDownloadingPdf(true);
        updateSaveUi();

        const sessionAnnotations = collectSessionAnnotations();
        const deletedPromotedSourceKeys = Array.from(pendingDeletedPromotedSourceKeys);

        try {
            const pdfBlob = await downloadAnnotatedPdf({
                annotations: sessionAnnotations,
                session_annotations: sessionAnnotations,
                acro_form_entries: acroFormEntries,
                deleted_promoted_source_keys: deletedPromotedSourceKeys,
                use_exact_download_path: true,
                session_id: getSessionId(),
            });
            const blobUrl = URL.createObjectURL(pdfBlob);
            if (popup && !popup.closed) {
                popup.location.replace(blobUrl);
            } else {
                window.open(blobUrl, '_blank', 'noopener');
            }
            window.setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);

            Object.values(pageData).forEach((data) => {
                (data.annotations || []).forEach((ann) => {
                    ann.text = editedTexts[ann._uid] ?? String(ann.text ?? '');
                });
            });
            pendingDeletedAnnotationIds.clear();
            pendingDeletedPromotedSourceKeys.clear();
            markClean();
            showToast('PDF ready');
        } catch (error) {
            if (popup && !popup.closed) popup.close();
            showToast(error?.message || 'Failed to generate PDF');
        } finally {
            setDownloadingPdf(false);
            updateSaveUi();
        }
    }

    async function saveAllChanges(options = {}) {
        if (isSaving) return;
        const silent = Boolean(options.silent);
        flushActiveEditorState();
        if (!isDirty && pendingDeletedAnnotationIds.size === 0 && pendingDeletedPromotedSourceKeys.size === 0) {
            if (!silent) showToast('No changes to save');
            return;
        }

        setSaving(true);
        updateSaveUi();

        const sessionAnnotations = collectSessionAnnotations();
        const deletedAnnotationIds = Array.from(pendingDeletedAnnotationIds);
        const deletedPromotedSourceKeys = Array.from(pendingDeletedPromotedSourceKeys);

        try {
            await saveAnnotations({
                annotations: sessionAnnotations,
                session_annotations: sessionAnnotations,
                acro_form_entries: acroFormEntries,
                deleted_annotation_ids: deletedAnnotationIds,
                deleted_promoted_source_keys: deletedPromotedSourceKeys,
                session_id: getSessionId(),
            });

            Object.values(pageData).forEach((data) => {
                (data.annotations || []).forEach((ann) => {
                    ann.text = editedTexts[ann._uid] ?? String(ann.text ?? '');
                });
            });
            pendingDeletedAnnotationIds.clear();
            pendingDeletedPromotedSourceKeys.clear();
            markClean();
            if (!silent) showToast('Saved');
        } catch (error) {
            // Always surface save failures — even in auto-save mode — so the
            // user is aware that edits did not persist.
            showToast(error?.message || 'Save failed');
        } finally {
            setSaving(false);
            updateSaveUi();
        }
    }

    function deleteAnnotation(ann, pi) {
        if (!ann) return;

        const annotationId = String(ann.id || '').trim();
        const promotedSourceKey = annIsPromotedFromExtraction(ann)
            ? String(ann.promotedSourceKey || ann.annotation_data?.promotedSourceKey || '').trim()
            : '';
        const data = pageData[pi];
        if (!data) return;

        pushUndo();
        // Record the deleted annotation's footprint so the raster-patch
        // pass can keep covering the underlying PDF text after the ann is
        // gone. We patch the union of the current box and the original-
        // extraction box (where the PDF glyphs actually sit).
        try {
            if (isTextAnnotation(ann) || annIsPromotedFromExtraction(ann)) {
                const cur = { x: Number(ann.pdfX), y: Number(ann.pdfY), w: Number(ann.pdfWidth), h: Number(ann.pdfHeight) };
                const orig = ann._originalPdfBox && [ann._originalPdfBox.x, ann._originalPdfBox.y, ann._originalPdfBox.w, ann._originalPdfBox.h].every(Number.isFinite)
                    ? ann._originalPdfBox
                    : null;
                const rects = [];
                if ([cur.x, cur.y, cur.w, cur.h].every(Number.isFinite) && cur.w > 0 && cur.h > 0) rects.push(cur);
                if (orig && orig.w > 0 && orig.h > 0) rects.push(orig);
                if (rects.length) {
                    if (!Array.isArray(data.deletedRectPatches)) data.deletedRectPatches = [];
                    for (const r of rects) {
                        data.deletedRectPatches.push({
                            uid: ann._uid,
                            x: r.x, y: r.y, w: r.w, h: r.h,
                            color: '#ffffff',
                        });
                    }
                }
            }
        } catch (_e) {}
        data.annotations = data.annotations.filter((item) => item._uid !== ann._uid);
        if (annotationId) pendingDeletedAnnotationIds.add(annotationId);
        if (promotedSourceKey) pendingDeletedPromotedSourceKeys.add(promotedSourceKey);
        delete editedTexts[ann._uid];
        if (hoverState.uid === ann._uid) clearHoverState();
        clearActiveAnnotation();
        redrawOverlay(pi);
        markDirty();
    }

    function replaceImageBackedAnnotation(ann, pi, asset) {
        if (!ann || !asset) return;
        pushUndo();
        ann.dataUrl = asset.dataUrl || '';
        ann.src = asset.src || '';
        ann.fileName = asset.fileName || ann.fileName || 'signature.png';
        ann.mimeType = asset.mimeType || ann.mimeType || 'image/png';
        ann.intrinsicWidth = Math.max(1, Number(asset.width || asset.intrinsicWidth || ann.intrinsicWidth || 1) || 1);
        ann.intrinsicHeight = Math.max(1, Number(asset.height || asset.intrinsicHeight || ann.intrinsicHeight || 1) || 1);
        ann.signatureSourceMode = normalizeSignatureSourceMode(asset.signatureSourceMode || ann.signatureSourceMode);
        ann.signatureComposer = cloneSerializableValue(asset.signatureComposer || null, null);
        if (asset.imageToolSource) {
            ann.imageToolSource = asset.imageToolSource;
        }
        if (asset.drawStrokeColor) {
            ann.drawStrokeColor = normalizeHexColor(asset.drawStrokeColor, '#111827');
        } else if (asset.imageToolSource !== 'direct-draw') {
            delete ann.drawStrokeColor;
        }
        if (asset.assetPath || asset.imagePath) {
            ann.assetPath = asset.assetPath || asset.imagePath || ann.assetPath || null;
        } else if (asset.dataUrl) {
            delete ann.assetPath;
            delete ann.imagePath;
        }
        normalizeImageAnnotation(ann);
        markUserAuthored(ann);
        redrawOverlay(pi);
        selectAnnotation(ann, pi);
        markDirty();
    }

    // canCutShapeAnnotation / isShapeCutModeArmedFor / resetShapeCutState
    // moved to ./shape/cut-mode.js (Phase 7ay).

    // cancelShapeCutMode / armShapeCutMode / toggleShapeCutMode moved to
    // ./shape/cut-mode.js (Phase 7cl). Wrappers thread the editor's
    // cursor / panel / overlay-redraw callbacks into the module.
    const _shapeCutCallbacks = () => ({
        redrawOverlay,
        syncCanvasCursors,
        syncShapePanelUi,
    });
    const cancelShapeCutMode = (opts = { redraw: true }) => _cancelShapeCutMode(opts, _shapeCutCallbacks());
    const armShapeCutMode = (ann, pi) => _armShapeCutMode(ann, pi, _shapeCutCallbacks());
    const toggleShapeCutMode = (ann, pi) => _toggleShapeCutMode(ann, pi, _shapeCutCallbacks());

    // crossProduct2d / dedupePolygonVertices / computePolygonArea /
    // clipPolygonAgainstInfiniteLine moved to ./util/polygon-clip.js (Phase 7ac).

    function createPolygonAnnotationFromPdfPoints(templateAnn, pi, points) {
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
        const polygonPoints = cleaned.map((point) => ({
            x: clamp01((point.x - left) / width, 0.5),
            y: clamp01((top - point.y) / height, 0.5),
        }));
        const ann = normalizeShapeAnnotation({
            _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
            id: generateAnnotationId(),
            pageIndex: pi,
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
            text: '',
        });
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        return ann;
    }

    function cutShapeAnnotation(ann, pi, startPt, endPt) {
        if (!canCutShapeAnnotation(ann)) return { ok: false, message: 'This shape cannot be cut.' };
        const polygon = getShapePolygonPointsPdf(ann);
        if (polygon.length < 3) return { ok: false, message: 'Shape outline is unavailable.' };
        const lineDx = (Number(endPt?.x) || 0) - (Number(startPt?.x) || 0);
        const lineDy = (Number(endPt?.y) || 0) - (Number(startPt?.y) || 0);
        if (Math.hypot(lineDx, lineDy) < 4) {
            return { ok: false, message: 'Drag a longer cut line through the shape.' };
        }

        const firstHalf = clipPolygonAgainstInfiniteLine(polygon, startPt, endPt, true);
        const secondHalf = clipPolygonAgainstInfiniteLine(polygon, startPt, endPt, false);
        const cutA = createPolygonAnnotationFromPdfPoints(ann, pi, firstHalf);
        const cutB = createPolygonAnnotationFromPdfPoints(ann, pi, secondHalf);
        if (!cutA || !cutB) {
            return { ok: false, message: 'The cut line must pass through the shape.' };
        }

        const data = pageData[pi];
        if (!data) return { ok: false, message: 'Page state is unavailable.' };
        const index = data.annotations.findIndex((item) => item._uid === ann._uid);
        if (index < 0) return { ok: false, message: 'Selected shape no longer exists.' };

        const annotationId = String(ann.id || '').trim();
        const promotedSourceKey = annIsPromotedFromExtraction(ann)
            ? String(ann.promotedSourceKey || ann.annotation_data?.promotedSourceKey || '').trim()
            : '';
        pushUndo();
        if (annotationId) pendingDeletedAnnotationIds.add(annotationId);
        if (promotedSourceKey) pendingDeletedPromotedSourceKeys.add(promotedSourceKey);
        delete editedTexts[ann._uid];
        data.annotations.splice(index, 1, cutA, cutB);
        if (hoverState.uid === ann._uid) clearHoverState();
        resetShapeCutState();
        selectAnnotation(cutA, pi);
        redrawOverlay(pi);
        markDirty();
        return { ok: true, annotations: [cutA, cutB] };
    }

    // ── Add Text mode ─────────────────────────────────────────────────────────
    function setAddTextMode(active) {
        if (active) {
            cancelSignaturePlacement();
            cancelEraseMode();
            setDrawMode(false);
            cancelShapeCutMode({ redraw: false });
        }
        setAddTextModeFlag(!editModeEnabled && !shapeMode && !!active);
        if (!addTextMode && textCreationState.active) cancelTextCreationPreview();
        if (addTextBtn) addTextBtn.classList.toggle('active', addTextMode);
        if (ftbAddText) ftbAddText.classList.toggle('is-active', addTextMode);
        syncCanvasCursors();
        syncShapePanelUi();
    }

    // cancelTextCreationPreview moved to ./interactions/text-creation.js (Phase 7az).

    function cancelShapeCreationPreview() {
        _cancelShapeCreationPreview({
            detachShiftListener: detachShapeConstrainShiftListener,
            hideConstrainTip: hideShapeConstrainTip,
            redrawAllOverlays,
            syncShapePanelUi,
        });
    }

    // ── Shift-to-constrain shape drawing ──────────────────────────────────────
    // Mirrors Photoshop's behaviour: while the user is actively drawing a new
    // shape (rectangle/ellipse/polygon/line), holding Shift locks the preview
    // to a 1:1 aspect ratio (perfect square / circle) or — for lines —
    // snaps the endpoint to the nearest 45° step. A floating hint near the
    // pointer tells the user about the modifier, and disappears once Shift
    // is actually pressed (so it doesn't keep interrupting experienced users).
    const shapeConstrainTipEl = document.getElementById('shape-constrain-tip');
    // shapeConstrainShiftAttached + shapeConstrainUserDismissedTip moved to
    // ./store/misc-state.js (Phase 5m).

    function handleShapeConstrainShiftEvent(event) {
        if (event.key !== 'Shift') return;
        const wantConstrain = event.type === 'keydown';
        if (shapeCreationState.constrain === wantConstrain) return;
        shapeCreationState.constrain = wantConstrain;
        if (wantConstrain) {
            setShapeConstrainUserDismissedTip(true);
            hideShapeConstrainTip();
        }
        if (!shapeCreationState.active || shapeCreationState.pi === null) return;
        if (!shapeCreationState.startPt || !shapeCreationState.currentPt) return;
        shapeCreationState.previewAnn = createShapeAnnotationFromPoints(
            shapeCreationState.startPt,
            shapeCreationState.currentPt,
            shapeCreationState.pi,
            { constrain: wantConstrain }
        );
        redrawOverlay(shapeCreationState.pi);
    }

    // attachShapeConstrainShiftListener / detachShapeConstrainShiftListener
    // moved to ./shape/constrain-listener.js (Phase 7ck).
    const attachShapeConstrainShiftListener = () => _attachShapeConstrainShiftListener(handleShapeConstrainShiftEvent);
    const detachShapeConstrainShiftListener = () => _detachShapeConstrainShiftListener(handleShapeConstrainShiftEvent);

    // shape constrain-tip helpers moved to ./shape/constrain-tip.js (Phase 7ax).
    const positionShapeConstrainTip = (cx, cy) => _positionShapeConstrainTip(shapeConstrainTipEl, cx, cy);
    const showShapeConstrainTip = (cx, cy) => _showShapeConstrainTip(shapeConstrainTipEl, cx, cy);
    const hideShapeConstrainTip = () => _hideShapeConstrainTip(shapeConstrainTipEl);

    function setShapeMode(active) {
        if (active) {
            cancelSignaturePlacement();
            cancelEraseMode();
            setDrawMode(false);
            cancelShapeCutMode({ redraw: false });
        }
        const nextState = !editModeEnabled && !!active;
        if (shapeMode === nextState) {
            syncShapePanelUi();
            return;
        }
        setShapeModeFlag(nextState);
        if (shapeMode) {
            setAddTextMode(false);
        } else if (shapeCreationState.active) {
            cancelShapeCreationPreview();
        }
        syncCanvasCursors();
        syncShapePanelUi();
        redrawAllOverlays();
    }

    // generateAnnotationId moved to ./annotations/id.js (Phase 7ae).

    function createNewTextAnnotation(canvasX, canvasY, pi, canvasWidth = null, canvasHeight = null) {
        if (!addTextMode) return;
        const data = pageData[pi];
        if (!data) return;

        const defaultFontSize = 12;   // pts
        const defaultWidth    = 200;  // pts
        const defaultHeight   = Math.ceil(defaultFontSize * 1.5); // pts
        const hasDraggedSize  = Number.isFinite(canvasWidth) && Number.isFinite(canvasHeight);
        const nextCanvasWidth = hasDraggedSize ? Math.max(60, Number(canvasWidth) || 0) : defaultWidth * data.scale;
        const nextCanvasHeight = hasDraggedSize ? Math.max(28, Number(canvasHeight) || 0) : defaultHeight * data.scale;

        const rawX = canvasX / data.scale;
        const rawY = hasDraggedSize
            ? (data.canvasHeight - (canvasY + nextCanvasHeight)) / data.scale
            : ((data.canvasHeight - canvasY) / data.scale) - defaultHeight;
        const nextWidthPts = hasDraggedSize ? (nextCanvasWidth / data.scale) : defaultWidth;
        const nextHeightPts = hasDraggedSize ? (nextCanvasHeight / data.scale) : defaultHeight;

        const x = Math.max(0, Math.min(rawX, Math.max(0, data.wPts - nextWidthPts)));
        const y = Math.max(0, Math.min(rawY, Math.max(0, data.hPts - nextHeightPts)));

        const uid = 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        const ann = {
            _uid:         uid,
            id:           generateAnnotationId(),
            pdfX:         x,
            pdfY:         y,
            pdfWidth:     nextWidthPts,
            pdfHeight:    nextHeightPts,
            text:         '',
            originalText: '',
            pageIndex:    pi,
            type:         'text',
            fontSize:     defaultFontSize,
            fontFamily:   'Helvetica',
            textColor:    '#000000',
            fontWeight:   '400',
            fontStyle:    'normal',
            underline:    false,
            textAlign:    'left',
            verticalAlign: 'top',
            backgroundColor: 'transparent',
            backgroundColorExplicit: false,
            opacity:      1,
            userCreated:        true,
            sourceSpans:        [],
            sourceLineBBoxes:   [],
            sourceTextLines:    [],
            _autoWidth:         !hasDraggedSize,
            _autoWidthMaxWidthPts: hasDraggedSize ? null : nextWidthPts,
        };
        ann._originalBox = { x, y, w: nextWidthPts, h: nextHeightPts };
        ann._originalPdfBox = { x, y, w: nextWidthPts, h: nextHeightPts };

        pushUndo();
        editedTexts[uid] = '';
        data.annotations.push(ann);
        redrawOverlay(pi);
        selectAnnotation(ann, pi);

        // Focus editor immediately so the user can start typing
        const ae = document.getElementById('ae-' + (pi + 1));
        if (ae) {
            setEditorEditingAnnotation(ae, ann);
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
        }

        markDirty();
    }

    function createShapeAnnotationFromPoints(startPt, endPt, pi, options = {}) {
        const data = pageData[pi];
        if (!data || !startPt || !endPt) return null;

        const constrain = Boolean(options && options.constrain);
        const defaults = currentShapeDefaults();
        if (isLineShape(defaults.shapeType)) {
            const snapped = constrain
                ? constrainLineEndpointTo45(startPt.x, startPt.y, endPt.x, endPt.y)
                : endPt;
            const lineBox = computeLineBoxGeometry(startPt.x, startPt.y, snapped.x, snapped.y);
            const ann = normalizeShapeAnnotation({
                _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
                id: generateAnnotationId(),
                pageIndex: pi,
                type: 'shape',
                pdfX: lineBox.left,
                pdfY: lineBox.bottom,
                pdfWidth: lineBox.width,
                pdfHeight: lineBox.height,
                userCreated: true,
                ...defaults,
                lineStartX: lineBox.lineStartX,
                lineStartY: lineBox.lineStartY,
                lineEndX: lineBox.lineEndX,
                lineEndY: lineBox.lineEndY,
                text: '',
            });
            ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
            ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
            return ann;
        }

        // When Shift is held on a non-line shape, lock the bounding box to
        // a 1:1 aspect ratio so rectangles become perfect squares and
        // ellipses become perfect circles. The side is driven by the
        // longer of the two drag deltas so the preview always reaches
        // under the cursor; we flip around startPt to preserve the drag
        // direction (drag up-left still grows up-left).
        let dx = endPt.x - startPt.x;
        let dy = endPt.y - startPt.y;
        if (constrain) {
            const side = Math.max(Math.abs(dx), Math.abs(dy));
            dx = (dx < 0 ? -1 : 1) * side;
            dy = (dy < 0 ? -1 : 1) * side;
        }
        const left = Math.min(startPt.x, startPt.x + dx);
        const bottom = Math.min(startPt.y, startPt.y + dy);
        const width = Math.max(1, Math.abs(dx));
        const height = Math.max(1, Math.abs(dy));
        const ann = normalizeShapeAnnotation({
            _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
            id: generateAnnotationId(),
            pageIndex: pi,
            type: 'shape',
            pdfX: left,
            pdfY: bottom,
            pdfWidth: width,
            pdfHeight: height,
            userCreated: true,
            ...defaults,
            text: '',
        });
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        return ann;
    }

    // getCurrentVisiblePageIndex moved to ./viewport/current-page.js (Phase 7as).
    const getCurrentVisiblePageIndex = () => _getCurrentVisiblePageIndex(pageJumpInput);

    function createImageAnnotation({
        type = 'image',
        dataUrl = '',
        src = '',
        fileName = null,
        mimeType = 'image/png',
        assetPath = null,
        intrinsicWidth = 1,
        intrinsicHeight = 1,
        signatureSourceMode = 'draw',
        signatureComposer = null,
        centerXPts = null,
        centerYPts = null,
        imageToolSource = null,
        directDrawTool = 'pen',
        drawStrokeColor = null,
    } = {}, pi) {
        const data = pageData[pi];
        if (!data) return null;
        const safeIntrinsicWidth = Math.max(1, Number(intrinsicWidth) || 1);
        const safeIntrinsicHeight = Math.max(1, Number(intrinsicHeight) || 1);
        const aspectRatio = safeIntrinsicWidth / safeIntrinsicHeight;
        const preferredWidthPts = String(type).toLowerCase() === 'signature' ? 180 : 220;
        const maxWidthPts = data.wPts * (String(type).toLowerCase() === 'signature' ? 0.34 : 0.4);
        const maxHeightPts = data.hPts * (String(type).toLowerCase() === 'signature' ? 0.18 : 0.28);
        let pdfWidth = Math.max(72, Math.min(preferredWidthPts, maxWidthPts));
        let pdfHeight = pdfWidth / Math.max(0.01, aspectRatio);
        if (pdfHeight > maxHeightPts) {
            pdfHeight = maxHeightPts;
            pdfWidth = pdfHeight * Math.max(0.01, aspectRatio);
        }
        pdfWidth = Math.max(48, Math.min(pdfWidth, data.wPts - 24));
        pdfHeight = Math.max(24, Math.min(pdfHeight, data.hPts - 24));
        const centeredPdfX = Math.max(12, (data.wPts - pdfWidth) / 2);
        const centeredPdfY = Math.max(12, (data.hPts - pdfHeight) / 2);
        const pdfX = Number.isFinite(Number(centerXPts))
            ? Math.max(12, Math.min(data.wPts - pdfWidth - 12, Number(centerXPts) - (pdfWidth / 2)))
            : centeredPdfX;
        const pdfY = Number.isFinite(Number(centerYPts))
            ? Math.max(12, Math.min(data.hPts - pdfHeight - 12, Number(centerYPts) - (pdfHeight / 2)))
            : centeredPdfY;
        const ann = normalizeImageAnnotation({
            _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
            id: generateAnnotationId(),
            pageIndex: pi,
            type: String(type).toLowerCase() === 'signature' ? 'signature' : 'image',
            pdfX,
            pdfY,
            pdfWidth,
            pdfHeight,
            rotation: 0,
            opacity: 1,
            userCreated: true,
            dataUrl: dataUrl || '',
            src: src || '',
            fileName: fileName || undefined,
            mimeType: mimeType || 'image/png',
            assetPath: assetPath || undefined,
            intrinsicWidth: safeIntrinsicWidth,
            intrinsicHeight: safeIntrinsicHeight,
            signatureSourceMode: String(type).toLowerCase() === 'signature'
                ? normalizeSignatureSourceMode(signatureSourceMode)
                : undefined,
            signatureComposer: String(type).toLowerCase() === 'signature'
                ? cloneSerializableValue(signatureComposer, null)
                : undefined,
            imageToolSource: imageToolSource || undefined,
            directDrawTool: imageToolSource === 'direct-draw'
                ? normalizeDirectDrawTool(directDrawTool)
                : undefined,
            drawStrokeColor: imageToolSource === 'direct-draw' && drawStrokeColor
                ? normalizeHexColor(drawStrokeColor, '#111827')
                : undefined,
            text: '',
        });
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        return ann;
    }

    function createImageAnnotationAtPageBox({
        type = 'image',
        dataUrl = '',
        src = '',
        fileName = null,
        mimeType = 'image/png',
        assetPath = null,
        intrinsicWidth = 1,
        intrinsicHeight = 1,
        pdfX = 0,
        pdfY = 0,
        pdfWidth = 1,
        pdfHeight = 1,
        signatureSourceMode = 'draw',
        signatureComposer = null,
        imageToolSource = null,
        directDrawTool = 'pen',
        drawStrokeColor = null,
    } = {}, pi) {
        const ann = normalizeImageAnnotation({
            _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
            id: generateAnnotationId(),
            pageIndex: pi,
            type: String(type).toLowerCase() === 'signature' ? 'signature' : 'image',
            pdfX: Math.max(0, Number(pdfX) || 0),
            pdfY: Math.max(0, Number(pdfY) || 0),
            pdfWidth: Math.max(1, Number(pdfWidth) || 1),
            pdfHeight: Math.max(1, Number(pdfHeight) || 1),
            rotation: 0,
            opacity: 1,
            userCreated: true,
            dataUrl: dataUrl || '',
            src: src || '',
            fileName: fileName || undefined,
            mimeType: mimeType || 'image/png',
            assetPath: assetPath || undefined,
            intrinsicWidth: Math.max(1, Number(intrinsicWidth) || 1),
            intrinsicHeight: Math.max(1, Number(intrinsicHeight) || 1),
            signatureSourceMode: String(type).toLowerCase() === 'signature'
                ? normalizeSignatureSourceMode(signatureSourceMode)
                : undefined,
            signatureComposer: String(type).toLowerCase() === 'signature'
                ? cloneSerializableValue(signatureComposer, null)
                : undefined,
            imageToolSource: imageToolSource || undefined,
            directDrawTool: imageToolSource === 'direct-draw'
                ? normalizeDirectDrawTool(directDrawTool)
                : undefined,
            drawStrokeColor: imageToolSource === 'direct-draw' && drawStrokeColor
                ? normalizeHexColor(drawStrokeColor, '#111827')
                : undefined,
            text: '',
        });
        ann._originalBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        ann._originalPdfBox = { x: ann.pdfX, y: ann.pdfY, w: ann.pdfWidth, h: ann.pdfHeight };
        return ann;
    }

    function placeImageBackedAnnotationAtPageBox(pi, asset, box, type = 'image') {
        const data = pageData[pi];
        if (!data || (!asset?.dataUrl && !asset?.src) || !box) return null;
        const ann = createImageAnnotationAtPageBox({
            type,
            dataUrl: asset.dataUrl || '',
            src: asset.src || '',
            fileName: asset.fileName || null,
            mimeType: asset.mimeType || 'image/png',
            assetPath: asset.assetPath || asset.imagePath || null,
            intrinsicWidth: asset.width || asset.intrinsicWidth || 1,
            intrinsicHeight: asset.height || asset.intrinsicHeight || 1,
            signatureSourceMode: asset.signatureSourceMode || 'draw',
            signatureComposer: asset.signatureComposer || null,
            imageToolSource: asset.imageToolSource || null,
            directDrawTool: asset.directDrawTool || 'pen',
            drawStrokeColor: asset.drawStrokeColor || null,
            pdfX: box.pdfX,
            pdfY: box.pdfY,
            pdfWidth: box.pdfWidth,
            pdfHeight: box.pdfHeight,
        }, pi);
        if (!ann) return null;
        pushUndo();
        // Direct-draw strokes (marker/pen tool) sit between shapes and text:
        //   shape annotations  →  direct-draw  →  text annotations  →  signatures/images
        // The editor renders text via a DOM rich-html-layer (z-index 4) that
        // sits above the overlay canvas, so direct-draw is visually under text
        // in the editor regardless of array position. Mirroring that order in
        // the PDF export keeps the download identical to what the editor shows.
        if (String(asset.imageToolSource || '') === 'direct-draw') {
            const firstTextIdx = data.annotations.findIndex((existing) => isTextAnnotation(existing));
            if (firstTextIdx < 0) {
                data.annotations.push(ann);
            } else {
                data.annotations.splice(firstTextIdx, 0, ann);
            }
        } else {
            data.annotations.push(ann);
        }
        redrawOverlay(pi);
        selectAnnotation(ann, pi);
        markDirty();
        return ann;
    }

    function placeImageBackedAnnotationOnPage(pi, asset, type = 'signature', centerPt = null) {
        if (!asset?.dataUrl && !asset?.src) return;
        const data = pageData[pi];
        if (!data) return;
        const ann = createImageAnnotation({
            type,
            dataUrl: asset.dataUrl || '',
            src: asset.src || '',
            fileName: asset.fileName || null,
            mimeType: asset.mimeType || 'image/png',
            assetPath: asset.assetPath || asset.imagePath || null,
            intrinsicWidth: asset.width || asset.intrinsicWidth || 1,
            intrinsicHeight: asset.height || asset.intrinsicHeight || 1,
            signatureSourceMode: asset.signatureSourceMode || 'draw',
            signatureComposer: asset.signatureComposer || null,
            centerXPts: centerPt?.x ?? null,
            centerYPts: centerPt?.y ?? null,
            imageToolSource: asset.imageToolSource || null,
            directDrawTool: asset.directDrawTool || 'pen',
            drawStrokeColor: asset.drawStrokeColor || null,
        }, pi);
        if (!ann) return;
        pushUndo();
        data.annotations.push(ann);
        redrawOverlay(pi);
        selectAnnotation(ann, pi);
        markDirty();
    }

    function placeImageBackedAnnotationOnCurrentPage(asset, type = 'signature') {
        const pi = getCurrentVisiblePageIndex();
        placeImageBackedAnnotationOnPage(pi, asset, type, null);
    }

    // applyShapeStateToAnnotation moved to ./annotations/normalize.js (Phase 7bx).
    // readShapeInspectorState moved to ./shapes/inspector-ui.js (Phase 7bz).
    const readShapeInspectorState = () => _readShapeInspectorState({
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
    });

    function commitShapeInspectorToActive({ pushHistory = false } = {}) {
        const state = readShapeInspectorState();
        const active = getActiveAnnAndPage();
        if (!active || !isShapeAnnotation(active.ann)) {
            // No active shape — defaults are now updated; skip syncShapePanelUi
            // because reflecting the (stale) active shape state would revert the
            // inputs the user is currently dragging.
            return;
        }
        if (isAnnotationLocked(active.ann)) return;
        if (pushHistory) pushUndo();
        applyShapeStateToAnnotation(active.ann, state);
        redrawOverlay(active.pi);
        syncActiveEditor(true);
        syncShapePanelUi();
        markDirty();
    }

    // ── Page building ─────────────────────────────────────────────────────────
    const scaleObs = new ResizeObserver(entries => {
        for (const entry of entries) {
            const pageEl = entry.target;
            const pi   = Number(pageEl.dataset.pi);
            const data = pageData[pi];
            if (!data) continue;
            const newW = Math.round(entry.contentRect.width);
            const newH = Math.round(entry.contentRect.height);
            data.scale       = data.wPts > 0 ? newW / data.wPts : 1;
            data.canvasWidth  = newW;
            data.canvasHeight = newH;
            updatePageCardWidth(pi, newW);
            const oc = document.getElementById('oc-' + (pi + 1));
            const ac = document.getElementById('ac-' + (pi + 1));
            if (oc) sizeOverlayCanvas(oc, newW, newH);
            if (ac) {
                ac.style.width = `${newW}px`;
                ac.style.height = `${newH}px`;
            }
            redrawOverlay(pi);
            drawAcroOverlay(pi);
            if (activeState.pi === pi) syncActiveEditor();
        }
    });

    function createPageCard(pg, total) {
        const card = document.createElement('div');
        card.className = 'page-card';
        card.id = `card-${pg}`;
        card.dataset.pageNumber = String(pg);
        card.innerHTML =
            `<div class="page-label"><span>Page ${pg} of ${total}</span></div>` +
            `<div class="page-canvas-wrap"><div class="page-content" id="pc-${pg}">` +
                `<canvas id="en-canvas-${pg}" class="page-canvas" aria-label="Page ${pg}"></canvas>` +
                `<div id="tl-${pg}" class="textLayer"></div>` +
                `<div id="ac-${pg}" class="acro-layer"></div>` +
                `<canvas id="oc-${pg}" class="overlay-canvas"></canvas>` +
                `<div id="rhl-${pg}" class="rich-html-layer"></div>` +
                `<div id="ael-${pg}" class="annotation-editor-layer"></div>` +
                `<div id="dbgdom-${pg}" class="debug-dom-layer" aria-hidden="true"></div>` +
                `<div id="ae-${pg}" class="active-editor" contenteditable="true" spellcheck="false"></div>` +
                `<div id="tm-${pg}" class="annotation-tbc-menu">` +
                    `<button id="lkh-${pg}" type="button" class="tbc-menu-btn tbc-lock" title="Lock annotation" aria-label="Lock annotation">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 1 1 8 0v3"></path></svg>` +
                    `</button>` +
                    `<button id="tm-${pg}-front" type="button" class="tbc-menu-btn" title="Bring to front" aria-label="Bring to front">⬆</button>` +
                    `<button id="tm-${pg}-back" type="button" class="tbc-menu-btn" title="Send to back" aria-label="Send to back">⬇</button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="eh-${pg}" type="button" class="tbc-menu-btn tbc-ok" title="Edit text" aria-label="Edit text">` +
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>` +
                    `</button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="uh-${pg}" type="button" class="tbc-menu-btn" title="UPPERCASE" aria-label="Uppercase"><span style="font-weight:700;font-size:12px;">Tt</span></button>` +
                    `<button id="lh-${pg}" type="button" class="tbc-menu-btn" title="lowercase" aria-label="Lowercase"><span style="font-size:12px;">tl</span></button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="ch-${pg}" type="button" class="tbc-menu-btn" title="Copy text" aria-label="Copy text">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>` +
                    `</button>` +
                    `<button id="dh-${pg}" type="button" class="tbc-menu-btn tbc-delete" title="Delete" aria-label="Delete">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>` +
                    `</button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="dbgh-${pg}" type="button" class="tbc-menu-btn tbc-debug" title="Debug: preview editor HTML" aria-label="Debug editor HTML">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2l1.5 3M16 2l-1.5 3"/><rect x="6" y="5" width="12" height="14" rx="6"/><path d="M9 11h6M9 14h6"/><path d="M3 12h3M18 12h3M5 19l2-2M19 19l-2-2M5 5l2 2M19 5l-2 2"/></svg>` +
                    `</button>` +
                `</div>` +
                `<div id="sh-${pg}" class="shape-action-bar">` +
                    `<span id="sh-${pg}-tag" class="shape-action-bar__tag"></span>` +
                    `<button id="sh-${pg}-edit" type="button" class="shape-action-bar__btn" title="Edit signature" aria-label="Edit signature" style="display:none;">` +
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg>` +
                    `</button>` +
                    `<button id="sh-${pg}-bg" type="button" class="shape-action-bar__btn" title="Remove white background" aria-label="Remove white background" style="display:none;">BG</button>` +
                    `<button id="sh-${pg}-lock" type="button" class="shape-action-bar__btn" title="Lock annotation" aria-label="Lock annotation">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 1 1 8 0v3"></path></svg>` +
                    `</button>` +
                    `<button id="sh-${pg}-front" type="button" class="shape-action-bar__btn" title="Bring to front" aria-label="Bring to front">⬆</button>` +
                    `<button id="sh-${pg}-back" type="button" class="shape-action-bar__btn" title="Send to back" aria-label="Send to back">⬇</button>` +
                    `<button id="sh-${pg}-cut" type="button" class="shape-action-bar__btn" title="Cut shape" aria-label="Cut shape">Cut</button>` +
                    `<button id="sh-${pg}-cap" type="button" class="shape-action-bar__btn" title="Toggle rounded line caps" aria-label="Toggle rounded line caps" style="display:none;">` +
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>` +
                    `</button>` +
                    `<button id="sh-${pg}-delete" type="button" class="shape-action-bar__btn is-danger" title="Delete" aria-label="Delete">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>` +
                    `</button>` +
                `</div>` +
                `<button id="rh-${pg}-nw" type="button" class="resize-handle" data-dir="nw" aria-label="Resize northwest"></button>` +
                `<button id="rh-${pg}-ne" type="button" class="resize-handle" data-dir="ne" aria-label="Resize northeast"></button>` +
                `<button id="rh-${pg}-sw" type="button" class="resize-handle" data-dir="sw" aria-label="Resize southwest"></button>` +
                `<button id="rh-${pg}-se" type="button" class="resize-handle" data-dir="se" aria-label="Resize southeast"></button>` +
                `<div id="sb-${pg}" class="shape-selection-box" aria-hidden="true"></div>` +
                `<button id="rh-${pg}-rot" type="button" class="rotate-handle" aria-label="Rotate">` +
                    `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3.5-7.1"></path><polyline points="21 4 21 10 15 10"></polyline></svg>` +
                `</button>` +
            `</div></div>`;
        wrap.appendChild(card);
        return card;
    }

    function setupPage(pg, wPts, hPts, annotations, acroWidgets = []) {
        const pi     = pg - 1;
        const pageEl = document.getElementById('pc-' + pg);
        const oc     = document.getElementById('oc-' + pg);
        const ac     = document.getElementById('ac-' + pg);
        const ae     = document.getElementById('ae-' + pg);
        const tm     = document.getElementById('tm-' + pg);
        const eh     = document.getElementById('eh-' + pg);
        const lkh    = document.getElementById('lkh-' + pg);
        const uh     = document.getElementById('uh-' + pg);
        const lh     = document.getElementById('lh-' + pg);
        const ch     = document.getElementById('ch-' + pg);
        const dh     = document.getElementById('dh-' + pg);
        const dbgh   = document.getElementById('dbgh-' + pg);
        const tmFront = document.getElementById('tm-' + pg + '-front');
        const tmBack = document.getElementById('tm-' + pg + '-back');
        const sh     = document.getElementById('sh-' + pg);
        const shLock = document.getElementById('sh-' + pg + '-lock');
        const shFront = document.getElementById('sh-' + pg + '-front');
        const shBack = document.getElementById('sh-' + pg + '-back');
        const shEdit = document.getElementById('sh-' + pg + '-edit');
        const shBg = document.getElementById('sh-' + pg + '-bg');
        const shCut = document.getElementById('sh-' + pg + '-cut');
        const shCap = document.getElementById('sh-' + pg + '-cap');
        const shDelete = document.getElementById('sh-' + pg + '-delete');
        const rhs    = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pg}-${dir}`));
        const rhRot  = document.getElementById('rh-' + pg + '-rot');
        if (!pageEl || !oc || !ac || !ae || !tm || !eh || !lkh || !uh || !lh || !ch || !dh || !dbgh || !tmFront || !tmBack || !sh || !shLock || !shFront || !shBack || !shEdit || !shBg || !shCut || !shCap || !shDelete || rhs.some((handle) => !handle) || !rhRot) return;

        const fitScale  = getFitScaleForWidth(wPts);
        // Pure CSS zoom model: the canvas/page is always rasterized at
        // fitScale; the user's zoom (currentZoomPercent) is applied as a CSS
        // transform on the page card by applyPageScale(). Sizing the initial
        // canvas at fitScale*zoom (the previous behaviour) made the very
        // first zoom action invalidate canvasWidth and force an editor
        // rebuild — which dropped active source-flow editors back to plain
        // mode and produced visibly mis-scaled text. Keep `data.scale`
        // synonymous with `fitScale` here so subsequent applyPageScale calls
        // are no-ops on canvas dimensions and only update the CSS transform.
        const initScale = fitScale;
        const initW     = Math.round(wPts * initScale);
        const initH     = Math.round(hPts * initScale);

        pageData[pi] = { wPts, hPts, fitScale, scale: initScale, canvasWidth: initW, canvasHeight: initH, annotations, acroWidgets };

        pageEl.style.width  = `calc(${wPts}px * var(--scale, 1))`;
        pageEl.style.height = `calc(${hPts}px * var(--scale, 1))`;
        pageEl.style.setProperty('--scale', initScale);
        pageEl.dataset.pi = String(pi);
        updatePageCardWidth(pi, initW);
        scaleObs.observe(pageEl);

        sizeOverlayCanvas(oc, initW, initH);
        ac.style.width = `${initW}px`;
        ac.style.height = `${initH}px`;

        // Apply the initial CSS zoom transform now that the page card exists.
        // applyPageScale is otherwise only triggered by the zoom controls.
        applyPageScale(pi);

        const applyEditorTextTransform = (transformer) => {
            if (!editModeEnabled && !addTextMode) return;
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann || typeof transformer !== 'function') return;
            if (isAnnotationLocked(ann)) return;
            const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
            const nextText = transformer(currentText);
            if (nextText === currentText) return;
            pushUndo();
            // Mirror the input-handler's behaviour so the case change is treated
            // as a real user edit (otherwise dirty/save tracking and the
            // user-authored render path can disagree).
            if (nextText !== String(ann.text ?? '')) markUserAuthored(ann);
            editedTexts[ann._uid] = nextText;
            resizeAnnotationForEditedText(ann, nextText, pi);
            ae.innerHTML = renderPlainEditorHTML(ann, nextText, pageData[pi].scale);
            // CRITICAL: update ann._richHtml to the freshly-rendered editor HTML.
            // The rich-html DOM layer (renderRichHtmlLayer) and syncActiveEditor
            // both read from ann._richHtml. If we leave it stale, then:
            //   (a) clicking off the annotation makes the page revert to the
            //       pre-transform case (rich-html-layer renders old _richHtml),
            //   (b) re-selecting the annotation rebuilds ae.innerHTML from the
            //       stale _richHtml, while editedTexts[_uid] already holds the
            //       transformed text — so the next case-button click hits the
            //       `nextText === currentText` early-return and appears to do
            //       nothing until the user types something to refresh _richHtml.
            // Note: this collapses any per-selection rich formatting (bold spans,
            // mid-text colour, etc.) to the annotation-level uniform style. That
            // matches the intent of "make all UPPERCASE / lowercase".
            ann._richHtml = ae.innerHTML;
            syncActiveEditor();
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
            markDirty();
            redrawOverlay(pi);
        };

        const startTextCreation = (event) => {
            if (!addTextMode || dragState.active || resizeState.active) return;
            const _rect = oc.getBoundingClientRect();
            const _pt = canvasPointFromEvent(event, oc);
            event.preventDefault();
            if (activeState.uid) {
                const activeEditor = activeState.pi !== null ? document.getElementById('ae-' + (activeState.pi + 1)) : null;
                if (activeEditor && document.activeElement === activeEditor) activeEditor.blur();
                clearActiveAnnotation();
            }
            if (!_pt || !_rect.width || !_rect.height) return;
            const rect = oc.getBoundingClientRect();
            const startX = event.clientX - rect.left;
            const startY = event.clientY - rect.top;
            const previewEl = document.createElement('div');
            previewEl.className = 'text-drag-selection';
            previewEl.style.left = `${startX}px`;
            previewEl.style.top = `${startY}px`;
            previewEl.style.width = '0px';
            previewEl.style.height = '0px';
            pageEl.appendChild(previewEl);
            setTextCreationState({
                active: true,
                pi,
                pointerId: event.pointerId,
                startX,
                startY,
                startCanvasX: _pt.x,
                startCanvasY: _pt.y,
                rect,
                logicalWidth: canvasLogicalWidth(oc),
                logicalHeight: canvasLogicalHeight(oc),
                previewEl,
                moved: false,
            });
            if (typeof oc.setPointerCapture === 'function') {
                oc.setPointerCapture(event.pointerId);
            }
        };

        const updateTextCreation = (event) => {
            if (!textCreationState.active || textCreationState.pi !== pi || textCreationState.pointerId !== event.pointerId) return;
            const currentX = event.clientX - textCreationState.rect.left;
            const currentY = event.clientY - textCreationState.rect.top;
            const left = Math.min(textCreationState.startX, currentX);
            const top = Math.min(textCreationState.startY, currentY);
            const width = Math.abs(currentX - textCreationState.startX);
            const height = Math.abs(currentY - textCreationState.startY);
            textCreationState.previewEl.style.left = `${left}px`;
            textCreationState.previewEl.style.top = `${top}px`;
            textCreationState.previewEl.style.width = `${width}px`;
            textCreationState.previewEl.style.height = `${height}px`;
            if (width > 5 || height > 5) textCreationState.moved = true;
        };

        const finishTextCreation = (event) => {
            if (!textCreationState.active || textCreationState.pi !== pi || textCreationState.pointerId !== event.pointerId) return false;
            const state = { ...textCreationState };
            cancelTextCreationPreview();
            if (typeof oc.releasePointerCapture === 'function' && oc.hasPointerCapture?.(event.pointerId)) {
                oc.releasePointerCapture(event.pointerId);
            }
            const currentX = event.clientX - state.rect.left;
            const currentY = event.clientY - state.rect.top;
            const left = Math.min(state.startX, currentX);
            const top = Math.min(state.startY, currentY);
            const width = Math.abs(currentX - state.startX);
            const height = Math.abs(currentY - state.startY);
            const scaleX = state.logicalWidth / Math.max(state.rect.width, 1);
            const scaleY = state.logicalHeight / Math.max(state.rect.height, 1);
            const useDraggedBox = state.moved && width > 20 && height > 10;
            createNewTextAnnotation(
                useDraggedBox ? (left * scaleX) : state.startCanvasX,
                useDraggedBox ? (top * scaleY) : state.startCanvasY,
                pi,
                useDraggedBox ? (width * scaleX) : null,
                useDraggedBox ? (height * scaleY) : null
            );
            return true;
        };

        const startShapeCreation = (event) => {
            if (!shapeMode || dragState.active || resizeState.active) return false;
            const rect = oc.getBoundingClientRect();
            const canvasX = (event.clientX - rect.left) * (oc.width / Math.max(rect.width, 1));
            const canvasY = (event.clientY - rect.top) * (oc.height / Math.max(rect.height, 1));
            const data = pageData[pi];
            const hit = data ? findAnnotationAt(canvasX, canvasY, data.annotations, data.scale, data.canvasHeight) : null;
            // Hitting any existing annotation while shape/line tool is active
            // selects it (and starts a drag) instead of starting a new shape.
            // Previously this only matched shape annotations, so clicking on a
            // text/image annotation would clear the selection and start a tiny
            // shape-creation preview — which made the resize handles vanish.
            if (hit) {
                event.preventDefault();
                if (activeState.uid !== hit._uid) selectAnnotation(hit, pi);
                if (isAnnotationLocked(hit)) return true;
                beginDrag(event, pi);
                return true;
            }

            const startPt = pdfPtFromClient(event.clientX, event.clientY, pi);
            if (!startPt) return false;
            event.preventDefault();
            if (activeState.uid) clearActiveAnnotation();
            const initialConstrain = Boolean(event.shiftKey);
            setShapeCreationState({
                active: true,
                pi,
                pointerId: event.pointerId,
                startPt,
                currentPt: startPt,
                previewAnn: createShapeAnnotationFromPoints(startPt, startPt, pi, { constrain: initialConstrain }),
                constrain: initialConstrain,
            });
            attachShapeConstrainShiftListener();
            if (initialConstrain) {
                setShapeConstrainUserDismissedTip(true);
            } else {
                showShapeConstrainTip(event.clientX, event.clientY);
            }
            if (typeof oc.setPointerCapture === 'function') {
                oc.setPointerCapture(event.pointerId);
            }
            redrawOverlay(pi);
            syncShapePanelUi();
            return true;
        };

        const updateShapeCreation = (event) => {
            if (!shapeCreationState.active || shapeCreationState.pi !== pi || shapeCreationState.pointerId !== event.pointerId) return false;
            const currentPt = pdfPtFromClient(event.clientX, event.clientY, pi);
            if (!currentPt) return false;
            shapeCreationState.currentPt = currentPt;
            // Sync constrain flag with the live event — covers the case
            // where Shift is pressed while the pointer is over the canvas
            // (keydown handler still fires, but reading shiftKey keeps us
            // correct if the event was dispatched without focus).
            shapeCreationState.constrain = Boolean(event.shiftKey);
            if (shapeCreationState.constrain) {
                setShapeConstrainUserDismissedTip(true);
                hideShapeConstrainTip();
            } else if (!shapeConstrainUserDismissedTip) {
                showShapeConstrainTip(event.clientX, event.clientY);
            }
            shapeCreationState.previewAnn = createShapeAnnotationFromPoints(
                shapeCreationState.startPt,
                currentPt,
                pi,
                { constrain: shapeCreationState.constrain }
            );
            redrawOverlay(pi);
            return true;
        };

        const finishShapeCreation = (event) => {
            if (!shapeCreationState.active || shapeCreationState.pi !== pi || shapeCreationState.pointerId !== event.pointerId) return false;
            const state = { ...shapeCreationState };
            if (typeof oc.releasePointerCapture === 'function' && oc.hasPointerCapture?.(event.pointerId)) {
                oc.releasePointerCapture(event.pointerId);
            }
            const ann = state.previewAnn ? normalizeShapeAnnotation(state.previewAnn) : null;
            setShapeCreationState({ active: false, pi: null, pointerId: null, startPt: null, currentPt: null, previewAnn: null, constrain: false });
            detachShapeConstrainShiftListener();
            hideShapeConstrainTip();
            redrawOverlay(pi);
            if (!ann) return false;
            const minDimension = isLineShape(ann) ? 6 : 8;
            const longEnough = Math.max(Number(ann.pdfWidth) || 0, Number(ann.pdfHeight) || 0) >= minDimension;
            if (!longEnough) {
                syncShapePanelUi();
                return true;
            }
            pushUndo();
            pageData[pi].annotations.push(ann);
            selectAnnotation(ann, pi);
            markDirty();
            syncShapePanelUi();
            redrawOverlay(pi);
            return true;
        };

        const beginShapeCutDrag = (event) => {
            if (!shapeCutState.armed || shapeCutState.pi !== pi || shapeCutState.pointerId !== null) return false;
            const startPt = pdfPtFromClient(event.clientX, event.clientY, pi);
            if (!startPt) return false;
            const ann = pageData[pi]?.annotations.find((item) => item._uid === shapeCutState.uid);
            if (!canCutShapeAnnotation(ann)) {
                cancelShapeCutMode();
                return false;
            }
            event.preventDefault();
            setShapeCutState({
                ...shapeCutState,
                pointerId: event.pointerId,
                startPt,
                currentPt: startPt,
            });
            oc.setPointerCapture?.(event.pointerId);
            redrawOverlay(pi);
            return true;
        };

        const updateShapeCutDrag = (event) => {
            if (!shapeCutState.armed || shapeCutState.pi !== pi || shapeCutState.pointerId !== event.pointerId) return false;
            const currentPt = pdfPtFromClient(event.clientX, event.clientY, pi);
            if (!currentPt) return false;
            setShapeCutState({
                ...shapeCutState,
                currentPt,
            });
            redrawOverlay(pi);
            return true;
        };

        const finishShapeCutDrag = (event) => {
            if (!shapeCutState.armed || shapeCutState.pi !== pi || shapeCutState.pointerId !== event.pointerId) return false;
            if (typeof oc.releasePointerCapture === 'function' && oc.hasPointerCapture?.(event.pointerId)) {
                oc.releasePointerCapture(event.pointerId);
            }
            const ann = pageData[pi]?.annotations.find((item) => item._uid === shapeCutState.uid);
            const startPt = shapeCutState.startPt;
            const endPt = pdfPtFromClient(event.clientX, event.clientY, pi) || shapeCutState.currentPt;
            if (!ann || !startPt || !endPt) {
                cancelShapeCutMode();
                return true;
            }
            const result = cutShapeAnnotation(ann, pi, startPt, endPt);
            if (!result.ok) {
                setShapeCutState({
                    ...shapeCutState,
                    pointerId: null,
                    startPt: null,
                    currentPt: null,
                });
                redrawOverlay(pi);
                showToast(result.message || 'Unable to cut shape.');
                syncCanvasCursors();
                syncShapePanelUi();
                return true;
            }
            showToast('Shape cut into 2 pieces.');
            return true;
        };

        // ── Canvas events ──
        oc.addEventListener('pointerdown', (e) => {
            if (signaturePlacementState.active) {
                const pt = pdfPtFromClient(e.clientX, e.clientY, pi);
                if (!pt || !signaturePlacementState.asset) return;
                e.preventDefault();
                placeImageBackedAnnotationOnPage(pi, signaturePlacementState.asset, signaturePlacementState.type, pt);
                cancelSignaturePlacement();
                return;
            }
            if (drawModeActive) {
                if (drawToolType === DIRECT_DRAW_TOOL_ERASER) {
                    void beginDirectEraserStroke(e, oc, pi);
                } else {
                    beginDirectPenStroke(e, oc, pi);
                }
                return;
            }
            if (shapeCutState.armed && shapeCutState.pi === pi) {
                if (beginShapeCutDrag(e)) return;
            }
            if (eraseMode) return;
            if (!addTextMode && !shapeMode) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (data && pt) {
                    const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                    if (ann && isBoxAnnotation(ann) && !isAnnotationLocked(ann)) {
                        if (activeState.uid !== ann._uid) selectAnnotation(ann, pi);
                        beginDrag(e, pi);
                        return;
                    }
                }
            }
            if (addTextMode) {
                startTextCreation(e);
                return;
            }
            if (shapeMode) {
                startShapeCreation(e);
            }
        });

        oc.addEventListener('pointermove', (e) => {
            if (drawModeActive && activeDrawSession) {
                if (activeDrawSession.kind === 'eraser') {
                    moveDirectEraserStroke(e, oc);
                } else {
                    moveDirectPenStroke(e, oc);
                }
                return;
            }
            if (textCreationState.active) {
                updateTextCreation(e);
                return;
            }
            if (shapeCutState.armed && shapeCutState.pi === pi && shapeCutState.pointerId !== null) {
                updateShapeCutDrag(e);
                return;
            }
            if (shapeCreationState.active) {
                updateShapeCreation(e);
            }
        });

        oc.addEventListener('pointerup', (e) => {
            if (drawModeActive && activeDrawSession) {
                if (activeDrawSession.kind === 'eraser') {
                    finishDirectEraserStroke(e, oc);
                } else {
                    finishDirectPenStroke(e, oc);
                }
                e.preventDefault();
                return;
            }
            if (shapeCutState.armed && shapeCutState.pi === pi && shapeCutState.pointerId !== null) {
                if (finishShapeCutDrag(e)) {
                    e.preventDefault();
                    return;
                }
            }
            if (finishTextCreation(e)) {
                e.preventDefault();
                return;
            }
            if (finishShapeCreation(e)) {
                e.preventDefault();
            }
        });

        oc.addEventListener('pointercancel', (e) => {
            if (drawModeActive && activeDrawSession) {
                cancelDirectDrawPointer(oc, e.pointerId);
                return;
            }
            if (textCreationState.active && textCreationState.pi === pi && textCreationState.pointerId === e.pointerId) {
                cancelTextCreationPreview();
            }
            if (shapeCreationState.active && shapeCreationState.pi === pi && shapeCreationState.pointerId === e.pointerId) {
                cancelShapeCreationPreview();
            }
            if (shapeCutState.armed && shapeCutState.pi === pi && shapeCutState.pointerId === e.pointerId) {
                setShapeCutState({
                    ...shapeCutState,
                    pointerId: null,
                    startPt: null,
                    currentPt: null,
                });
                redrawOverlay(pi);
            }
        });

        oc.addEventListener('mousemove', (e) => {
            if (textCreationState.active || shapeCreationState.active) return;
            if (dragState.active || resizeState.active) return;
            if (signaturePlacementState.active) {
                oc.style.cursor = 'copy';
                return;
            }
            if (drawModeActive) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                const ann = (drawToolType === DIRECT_DRAW_TOOL_ERASER && data && pt)
                    ? findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight)
                    : null;
                const canErase = isDirectDrawAnnotation(ann) && !isAnnotationLocked(ann);
                const nextUid = canErase ? ann._uid : null;
                if (nextUid !== hoverState.uid || pi !== hoverState.pi) {
                    setHoverState(pi, nextUid);
                    redrawOverlay(pi);
                }
                oc.style.cursor = ann && !canErase ? 'not-allowed' : 'crosshair';
                return;
            }
            if (eraseMode) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (!pt || !data) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                const nextUid = ann?._uid || null;
                if (nextUid !== hoverState.uid || pi !== hoverState.pi) {
                    setHoverState(pi, nextUid);
                    redrawOverlay(pi);
                }
                oc.style.cursor = nextUid ? 'not-allowed' : 'crosshair';
                return;
            }
            if (shapeCutState.armed && shapeCutState.pi === pi) {
                if (hoverState.pi === pi) {
                    clearHoverState();
                    redrawOverlay(pi);
                }
                oc.style.cursor = 'crosshair';
                return;
            }
            if (!editModeEnabled && !addTextMode && !shapeMode && !hasActiveBoxSelection()) {
                if (hoverState.pi === pi) {
                    clearHoverState();
                    redrawOverlay(pi);
                }
                oc.style.cursor = 'default';
                return;
            }
            const data = pageData[pi];
            const pt   = canvasPointFromEvent(e, oc);
            if (!pt) return;
            const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
            if (addTextMode) {
                if (hoverState.pi === pi) {
                    clearHoverState();
                    redrawOverlay(pi);
                }
                oc.style.cursor = 'crosshair';
                return;
            }
            if (!editModeEnabled && !shapeMode && hasActiveBoxSelection()) {
                const nextUid = ann && isBoxAnnotation(ann) ? ann._uid : null;
                if (nextUid !== hoverState.uid || pi !== hoverState.pi) {
                    setHoverState(pi, nextUid);
                    redrawOverlay(pi);
                }
                oc.style.cursor = nextUid
                    ? (isAnnotationLocked(ann) ? 'not-allowed' : 'move')
                    : 'default';
                return;
            }
            if (shapeMode) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (!pt) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                const nextUid = ann && isShapeAnnotation(ann) ? ann._uid : null;
                if (nextUid !== hoverState.uid || pi !== hoverState.pi) {
                    setHoverState(pi, nextUid);
                    redrawOverlay(pi);
                }
                oc.style.cursor = nextUid
                    ? (isAnnotationLocked(ann) ? 'not-allowed' : 'move')
                    : 'crosshair';
                return;
            }
            const nextUid = ann?._uid || null;
            if (nextUid === hoverState.uid && pi === hoverState.pi) return;
            setHoverState(pi, nextUid);
            oc.style.cursor = nextUid
                ? (isAnnotationLocked(ann) ? 'not-allowed' : (isTextAnnotation(ann) ? 'text' : 'move'))
                : 'default';
            redrawOverlay(pi);
        });

        oc.addEventListener('mouseleave', () => {
            if (signaturePlacementState.active) {
                oc.style.cursor = 'copy';
                return;
            }
            if (drawModeActive) {
                if (hoverState.pi === pi) {
                    clearHoverState();
                    redrawOverlay(pi);
                }
                oc.style.cursor = currentCanvasCursor();
                return;
            }
            if (!editModeEnabled && !addTextMode && !shapeMode && !eraseMode && !hasActiveBoxSelection()) {
                oc.style.cursor = currentCanvasCursor();
                return;
            }
            if (hoverState.pi !== pi) return;
            clearHoverState();
            oc.style.cursor = currentCanvasCursor();
            redrawOverlay(pi);
        });

        oc.addEventListener('click', (e) => {
            if (dragState.active || resizeState.active) return;
            if (drawModeActive) return;
            if (!editModeEnabled && !addTextMode && !shapeMode && !eraseMode && !hasActiveBoxSelection()) return;
            if (shapeCutState.armed && shapeCutState.pi === pi) return;
            if (eraseMode) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (!pt || !data) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                if (!ann) return;
                if (isAnnotationLocked(ann)) return;
                deleteAnnotation(ann, pi);
                return;
            }
            if (addTextMode) {
                return;
            }
            if (shapeMode) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (!pt) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                // Allow selecting any annotation (text included) while the
                // shape/line tool is active so the resize handles aren't
                // wiped out by a stray click on a text annotation.
                if (!ann) {
                    clearActiveAnnotation();
                    return;
                }
                if (activeState.uid !== ann._uid) selectAnnotation(ann, pi);
                return;
            }
            if (!editModeEnabled && hasActiveBoxSelection()) {
                const data = pageData[pi];
                const pt = canvasPointFromEvent(e, oc);
                if (!pt) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                if (!ann || !isBoxAnnotation(ann)) {
                    clearActiveAnnotation();
                    return;
                }
                if (activeState.uid !== ann._uid) selectAnnotation(ann, pi);
                return;
            }
            const data = pageData[pi];
            const pt   = canvasPointFromEvent(e, oc);
            if (!pt) return;
            const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
            if (!ann) {
                // Blur the editor first so the blur handler can save before clearing state
                const aeEl = document.getElementById('ae-' + (pi + 1));
                if (aeEl && document.activeElement === aeEl) { aeEl.blur(); return; }
                clearActiveAnnotation();
                return;
            }
            selectAnnotation(ann, pi);
        });

        // ── Editor events ──
        let _textUndoPending = false;
        ae.addEventListener('focus', () => {
            if (!editModeEnabled && !addTextMode) { ae.blur(); return; }
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann) { clearEditorEditingState(ae); return; }
            if (isAnnotationLocked(ann)) { ae.blur(); return; }
            _textUndoPending = true;
            // Entering text-editing mode: switch cursor + enable selection.
            setEditorEditingAnnotation(ae, ann);
            ae.style.cursor = 'text';
            ae.style.userSelect = 'text';
            ae.style.caretColor = '#2563eb';
            // Populate the editor DOM (selected-but-not-editing leaves it empty
            // so canvas renders the text) and refresh the canvas which now
            // suppresses drawing the active annotation.
            try { syncActiveEditor(true); } catch (_e) {}
            try { redrawOverlay(pi); } catch (_e) {}
            // Stepping in to edit text — make sure the format bar is shown
            // for the active annotation. Some entry paths (eh hover-menu
            // button click, tab focus, programmatic focus) bypass
            // selectAnnotation, leaving the bar hidden if it was previously
            // closed by a stale blur.
            updateFormatBar();
        });

        // Drag the annotation by clicking anywhere on its body when not in
        // text-editing mode (replaces the dedicated move handle).
        ae.addEventListener('mousedown', (e) => {
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (editorIsEditingAnnotation(ae, ann)) return; // editing → place caret normally
            if (e.button !== 0) return;
            if (isAnnotationLocked(ann)) return;
            e.preventDefault();
            e.stopPropagation();
            beginDrag(e, pi);
        });

        // Double-click on the annotation body → enter text-editing mode.
        ae.addEventListener('dblclick', (e) => {
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (editorIsEditingAnnotation(ae, ann)) return;
            if (isAnnotationLocked(ann)) return;
            e.preventDefault();
            e.stopPropagation();
            setEditorEditingAnnotation(ae, ann);
            ae.style.cursor = 'text';
            ae.style.userSelect = 'text';
            ae.style.caretColor = '#2563eb';
            // Populate the editor DOM now that we're entering edit mode (the
            // selected-but-not-editing branch left it empty so the canvas
            // showed the PDF-accurate render underneath).
            try { syncActiveEditor(true); } catch (_e) {}
            try { redrawOverlay(pi); } catch (_e) {}
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
        });

        // Plain-text paste: strip styles so pasted content adopts the
        // annotation's own font / color / size instead of the source's.
        ae.addEventListener('paste', (e) => {
            e.preventDefault();
            const cd = e.clipboardData || window.clipboardData;
            const text = cd ? cd.getData('text/plain') : '';
            if (!text) return;
            try { document.execCommand('insertText', false, text); }
            catch (_err) { /* fallback: ignore */ }
        });

        ae.addEventListener('input', (e) => {
            if (!editModeEnabled && !addTextMode) return;
            // Push undo snapshot once — before the first character change in this focus session.
            if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
            const data = pageData[pi];
            const editingUid = e.currentTarget.dataset.editingUid || activeState.uid;
            const ann  = data?.annotations.find(a => a._uid === editingUid);
            if (!ann) return;
            if (isAnnotationLocked(ann)) return;
            // Galley editor mode: appended chars accumulate in per-line tail spans
            // (kept visible via the canvas-owns CSS override for [data-galley-new]).
            // The page overlay canvas keeps painting original source spans verbatim
            // and also paints ann._galleyAppends after each line. We must NOT call
            // markUserAuthored or rebuild innerHTML — both would collapse the
            // galley layout into plain CSS reflow on the very first keystroke.
            if (e.currentTarget.dataset.renderMode === 'galley') {
                reconcileGalleyInput(e.currentTarget, ann, pi);
                markDirty();
                redrawOverlay(pi);
                return;
            }
            const nextText = getEditorPlainText(e.currentTarget);
            if (nextText !== String(ann.text ?? '')) markUserAuthored(ann);
            editedTexts[ann._uid] = nextText;
            resizeAnnotationForEditedText(ann, nextText, pi);
            const hasFormattedRichHtml = richHtmlHasInlineSelectionFormatting(ann._richHtml);
            const sourceAwareRenderMode = e.currentTarget.dataset.renderMode === 'source-flow'
                || e.currentTarget.dataset.renderMode === 'source';
            if (!sourceAwareRenderMode && hasFormattedRichHtml) {
                // Preserve per-selection formatting: don't flatten innerHTML on typing.
                // Just capture the updated HTML and keep the caret where the browser left it.
                ann._richHtml = e.currentTarget.innerHTML;
                // Grow the box to the actual rendered editor height — the
                // synthetic plain-text measure above doesn't account for
                // per-span font sizes / explicit <br>s in rich html.
                growAnnotationBoxToRenderedHeight(ann, e.currentTarget, pi);
            } else {
                if (sourceAwareRenderMode) {
                    clearSourceExactFlagsForPlainTextEdit(ann);
                    delete ann._richHtml;
                    e.currentTarget.dataset.renderMode = 'plain';
                }
                const selection = getEditorSelectionOffsets(e.currentTarget);
                e.currentTarget.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                if (selection) {
                    setEditorSelectionOffsets(e.currentTarget, selection.start, selection.end);
                }
                // Plain edited text should be rendered from current geometry each time.
                // Persisting generated wrapper HTML stores pixel line-height and
                // translateY values from the old box, which makes later resize/edit
                // sessions keep stale padding. Only formatted-rich content keeps
                // `_richHtml` because it carries actual inline style spans.
                delete ann._richHtml;
            }
            markDirty();
            syncActiveEditor();
            redrawOverlay(pi);  // keep canvas in sync for when annotation is deselected
        });

        ae.addEventListener('blur', (e) => {
            const blurredEditingUid = ae.dataset.editingUid || activeState.uid;
            flushActiveEditorState();
            // Always clear editing mode on blur so the next selection starts in
            // "select / drag" mode (cursor: move) rather than "text input" mode.
            clearEditorEditingState(ae);
            // After leaving edit mode, re-sync (editor empties itself for the
            // selected-but-not-editing branch) and re-draw the canvas so the
            // active annotation is rendered there again instead of in the DOM.
            try { syncActiveEditor(true); } catch (_e) {}
            try { redrawOverlay(pi); } catch (_e) {}
            if (!editModeEnabled && !addTextMode) return;
            const data = pageData[pi];
            const ann  = data?.annotations.find(a => a._uid === blurredEditingUid);
            if (!ann) return;
            // Auto-remove new annotations that are still empty — matches edit view behaviour
            // where clicking off a blank text-box-creator discards it.
            const isNewAnn = typeof ann._uid === 'string' && ann._uid.startsWith('new_');
            const annText  = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
            if (isNewAnn && !annText) {
                data.annotations = data.annotations.filter(a => a._uid !== ann._uid);
                delete editedTexts[ann._uid];
                // Pop the undo snapshot that was pushed when this annotation was created
                // so Ctrl+Z doesn't restore an invisible blank annotation.
                if (undoStack.length > 0) undoStack.pop();
                updateHistoryUi();
                clearActiveState();
                syncActiveEditor();
                redrawOverlay(pi);
                return;
            }
            if (isNewAnn) {
                // Stay in addTextMode with the new annotation remaining selected.
                // Do NOT call setEditModeEnabled(true) — that would show all extracted
                // fields as editable, which is not wanted here.
                // activeState still points at this annotation; just redraw to show the
                // selection outline.
                redrawOverlay(pi);
            } else {
                // For existing annotations: clear active state so the canvas redraws with text.
                // Exception: don't clear if focus moved into the format bar (e.g. color picker),
                // otherwise the bar disappears before the property change can fire.
                // Also check a recent mousedown timestamp on the bar, because a native color-picker
                // dialog can steal document focus away from the input element itself.
                const clearUid = ann._uid;
                requestAnimationFrame(() => {
                    if (activeState.uid === clearUid) {
                        const focused = document.activeElement;
                        const recentFormatBarClick = (Date.now() - _formatBarMousedownTs) < 600;
                        if (recentFormatBarClick || (focused && annFormatBar && annFormatBar.contains(focused))) return;
                        clearActiveAnnotation();
                    }
                });
            }
        });

        ae.addEventListener('keydown', (e) => {
            if (!editModeEnabled && !addTextMode) return;
            // Blur (don't clearActiveAnnotation directly) so the blur handler saves first
            if (e.key === 'Escape') { e.preventDefault(); ae.blur(); return; }
            // Let the global Ctrl+Z/Y handler take care of undo/redo instead of browser default.
            if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'y')) {
                e.preventDefault(); return;
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
                const data = pageData[pi];
                // Resolve the annotation by ae.dataset.editingUid first (set when
                // editing was entered) and fall back to activeState.uid. The two
                // can diverge after focus moves through the format bar / outside
                // controls; using only activeState.uid would silently drop Enter.
                const editingUid = ae.dataset.editingUid || activeState.uid;
                const ann = data?.annotations.find(a => a._uid === editingUid);
                if (!ann) return;
                if (isAnnotationLocked(ann)) return;
                // Rich-formatted path: insert a <br> in place so per-selection
                // bold/italic/color spans around the caret survive the new line.
                // Rebuilding via renderPlainEditorHTML (the plain-text branch
                // below) would flatten the styled spans and re-render the whole
                // annotation in the annotation-level style.
                const hasFormattedRichHtml = richHtmlHasInlineSelectionFormatting(ann._richHtml);
                const sourceAwareRenderMode = ae.dataset.renderMode === 'source-flow'
                    || ae.dataset.renderMode === 'source';
                if (!sourceAwareRenderMode && hasFormattedRichHtml) {
                    // Insert a real <br> at the caret. execCommand('insertLineBreak')
                    // is unreliable across browsers (sometimes inserts a \n text
                    // node that doesn't render as a visible break); doing the DOM
                    // mutation by hand keeps surrounding bold/italic/color spans
                    // intact and produces a visible newline in every browser.
                    const sel = window.getSelection();
                    if (sel && sel.rangeCount > 0) {
                        const range = sel.getRangeAt(0);
                        if (!ae.contains(range.startContainer) || !ae.contains(range.endContainer)) return;
                        if (!range.collapsed) range.deleteContents();
                        const br = document.createElement('br');
                        range.insertNode(br);
                        // If the <br> ends up being the very last node in its parent,
                        // append a trailing zero-width text node so the caret can
                        // sit AFTER the break (browsers refuse to place a caret
                        // after a trailing <br> otherwise).
                        const trailing = document.createTextNode('\u200b');
                        if (br.parentNode) br.parentNode.insertBefore(trailing, br.nextSibling);
                        const after = document.createRange();
                        after.setStart(trailing, 1);
                        after.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(after);
                    }
                    ann._richHtml = ae.innerHTML;
                    const nextText = getEditorPlainText(ae);
                    if (nextText !== String(ann.text ?? '')) markUserAuthored(ann);
                    editedTexts[ann._uid] = nextText;
                    resizeAnnotationForEditedText(ann, nextText, pi);
                    syncActiveEditor();
                    markDirty();
                    redrawOverlay(pi);
                    return;
                }
                const selection = getEditorSelectionOffsets(ae);
                if (!selection) return;
                const currentText = ae.dataset.renderMode === 'paragraph' && editedTexts[ann._uid] === undefined
                    ? getEditorPlainText(ae)
                    : (editedTexts[ann._uid] ?? String(ann.text ?? ''));
                const beforeText = currentText.slice(0, selection.start);
                const afterText = currentText.slice(selection.end);
                const nextText = beforeText + '\n' + afterText;
                const nextCaret = beforeText.length + 1;
                if (sourceAwareRenderMode) {
                    markUserAuthored(ann);
                    clearSourceExactFlagsForPlainTextEdit(ann);
                    delete ann._richHtml;
                    ae.dataset.renderMode = 'plain';
                }
                editedTexts[ann._uid] = nextText;
                resizeAnnotationForEditedText(ann, nextText, pi);
                ae.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                syncActiveEditor();
                setEditorSelectionOffsets(ae, nextCaret, nextCaret);
                markDirty();
                redrawOverlay(pi);
            }
            if (e.key === 'Backspace' || e.key === 'Delete') {
                const data = pageData[pi];
                const ann = data?.annotations.find(a => a._uid === activeState.uid);
                if (!ann) return;
                if (isAnnotationLocked(ann)) return;
                // Rich-formatted path: let the browser perform the deletion
                // natively so per-selection bold/italic/color spans around the
                // caret survive. The 'input' listener above will then capture
                // the updated innerHTML into ann._richHtml. Calling
                // renderPlainEditorHTML here would flatten all styled spans.
                const hasFormattedRichHtml = richHtmlHasInlineSelectionFormatting(ann._richHtml);
                const sourceAwareRenderMode = ae.dataset.renderMode === 'source-flow'
                    || ae.dataset.renderMode === 'source';
                if (!sourceAwareRenderMode && hasFormattedRichHtml) {
                    if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
                    return;
                }
                e.preventDefault();
                if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
                const selection = getEditorSelectionOffsets(ae);
                if (!selection) return;
                const currentText = ae.dataset.renderMode === 'paragraph' && editedTexts[ann._uid] === undefined
                    ? getEditorPlainText(ae)
                    : (editedTexts[ann._uid] ?? String(ann.text ?? ''));
                let start = selection.start;
                let end = selection.end;

                if (start === end) {
                    if (e.key === 'Backspace') {
                        if (start === 0) return;
                        start -= 1;
                    } else {
                        if (end >= currentText.length) return;
                        end += 1;
                    }
                }

                const nextText = currentText.slice(0, start) + currentText.slice(end);
                if (sourceAwareRenderMode) {
                    markUserAuthored(ann);
                    clearSourceExactFlagsForPlainTextEdit(ann);
                    delete ann._richHtml;
                    ae.dataset.renderMode = 'plain';
                }
                editedTexts[ann._uid] = nextText;
                resizeAnnotationForEditedText(ann, nextText, pi);
                ae.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                syncActiveEditor();
                setEditorSelectionOffsets(ae, start, start);
                markDirty();
                redrawOverlay(pi);
            }
        });

        // In Add Text mode, clicking the currently active editor should start a
        // fresh text box at that point, not trap the click inside the old box.
        // Outside Add Text mode we still block the canvas beneath the editor.
        ae.addEventListener('pointerdown', (e) => {
            if (addTextMode) {
                e.preventDefault();
                e.stopPropagation();
                startTextCreation(e);
                return;
            }
            e.stopPropagation();
        });
        ae.addEventListener('click',       (e) => e.stopPropagation());

        [tm, eh, lkh, uh, lh, ch, dh, sh, shLock, shFront, shBack, shEdit, shBg, shCut, shCap, shDelete].forEach((element) => {
            element.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
            element.addEventListener('click', (e) => e.stopPropagation());
        });

        // ── Floating menu ──
        eh.addEventListener('click', () => {
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (isAnnotationLocked(ann)) return;
            setEditorEditingAnnotation(ae, ann);
            ae.style.cursor = 'text';
            ae.style.userSelect = 'text';
            ae.style.caretColor = '#2563eb';
            // Rebuild the editor for the editing render mode (source / galley
            // / plain) BEFORE focusing — otherwise the contenteditable is
            // still in its just-selected empty state and ae.focus() drops the
            // caret at the top of the empty <div> (which is positioned at the
            // page-content origin), so the user sees a blinking cursor up at
            // the top of the page instead of inside the annotation box.
            syncActiveEditor(true);
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
        });

        uh.addEventListener('click', () => {
            applyEditorTextTransform((text) => String(text || '').toUpperCase());
        });

        lh.addEventListener('click', () => {
            applyEditorTextTransform((text) => String(text || '').toLowerCase());
        });

        ch.addEventListener('click', () => {
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann) return;
            const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
            if (!currentText) return;
            navigator.clipboard.writeText(currentText).catch(() => {});
        });

        lkh.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann) return;
            setAnnotationLocked(ann, pi, !isAnnotationLocked(ann));
        });

        rhs.forEach((handle) => {
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                beginResize(e, pi, handle.dataset.dir || 'se');
            });
        });

        rhRot.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            beginRotate(e, pi);
        });

        dh.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann) return;
            if (isAnnotationLocked(ann)) return;
            deleteAnnotation(ann, pi);
        });

        dbgh.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann) return;
            openEditorHtmlDebugModal(ann, pi);
        });

        tmFront.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann || !isTextAnnotation(ann)) return;
            moveAnnotationLayer(ann, pi, 'front');
        });

        tmBack.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann || !isTextAnnotation(ann)) return;
            moveAnnotationLayer(ann, pi, 'back');
        });

        shLock.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann || !isBoxAnnotation(ann)) return;
            setAnnotationLocked(ann, pi, !isAnnotationLocked(ann));
        });

        shFront.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann || !isBoxAnnotation(ann)) return;
            moveAnnotationLayer(ann, pi, 'front');
        });

        shBack.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann || !isBoxAnnotation(ann)) return;
            moveAnnotationLayer(ann, pi, 'back');
        });

        shEdit.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann || String(ann.type || '').toLowerCase() !== 'signature') return;
            if (isAnnotationLocked(ann)) return;
            await openSignatureEditModal(ann, pi);
        });

        shBg.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!canRemoveBackgroundFromImageAnnotation(ann)) return;
            if (isAnnotationLocked(ann)) return;
            try {
                await removeBackgroundFromImageAnnotation(ann, pi);
            } catch (error) {
                showToast(error?.message || 'Could not remove that background.');
            }
        });

        shCut.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!canCutShapeAnnotation(ann)) return;
            toggleShapeCutMode(ann, pi);
        });

        shCap.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann || !isShapeAnnotation(ann) || !isLineShape(ann)) return;
            if (isAnnotationLocked(ann)) return;
            pushUndo();
            const current = (ann.lineCap || 'round').toLowerCase();
            ann.lineCap = current === 'round' ? 'butt' : 'round';
            shCap.classList.toggle('is-active', ann.lineCap === 'round');
            shCap.title = ann.lineCap === 'round' ? 'Disable rounded line caps' : 'Enable rounded line caps';
            markDirty();
            redrawOverlay(pi);
            syncActiveEditor(true);
        });

        shDelete.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann || !isBoxAnnotation(ann)) return;
            if (isAnnotationLocked(ann)) return;
            deleteAnnotation(ann, pi);
        });
    }

    if (saveButton) {
        saveButton.addEventListener('click', () => {
            saveAllChanges();
        });
    }
    if (undoButton) undoButton.addEventListener('click', () => performUndo());
    if (redoButton) redoButton.addEventListener('click', () => performRedo());
    if (editModeToggle) {
        editModeToggle.addEventListener('click', () => {
            setEditModeEnabled(!editModeEnabled);
        });
    }
    if (ftbEditMode) {
        ftbEditMode.addEventListener('click', () => {
            setEditModeEnabled(!editModeEnabled);
        });
    }
    if (markupToolCanvas) {
        setMarkupToolCtx(markupToolCanvas.getContext('2d'));
        clearMarkupToolCanvas();
        syncMarkupToolLabels();
    }
    if (signatureCanvas) {
        setSignatureCtx(signatureCanvas.getContext('2d'));
        clearSignatureCanvas();
        syncSignatureColorLabels();
    }
    syncDrawToolPanelUi();
    if (ftbDrawErase) {
        ftbDrawErase.addEventListener('click', () => {
            if (editModeEnabled) return;
            setDrawMode(!drawModeActive, drawToolType);
        });
    }
    if (drawToolClose) {
        drawToolClose.addEventListener('click', () => setDrawMode(false));
    }
    drawToolButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setDrawToolType(button.dataset.drawDirectTool);
            if (!drawModeActive) {
                setDrawMode(true, drawToolType);
                return;
            }
            syncDrawToolPanelUi();
            updateEditModeUi();
        });
    });
    drawColorSwatches.forEach((button) => {
        button.addEventListener('click', () => {
            setDrawStrokeColor(normalizeHexColor(button.dataset.drawColor, drawStrokeColor));
            syncDrawToolPanelUi();
        });
    });
    if (drawToolColorInput) {
        drawToolColorInput.addEventListener('input', () => {
            setDrawStrokeColor(normalizeHexColor(drawToolColorInput.value, drawStrokeColor));
            syncDrawToolPanelUi();
        });
    }
    if (drawToolSizeInput) {
        drawToolSizeInput.addEventListener('input', () => {
            setDrawBrushSize(Number(drawToolSizeInput.value));
            syncDrawToolPanelUi();
        });
    }
    if (drawToolOpacityInput) {
        drawToolOpacityInput.addEventListener('input', () => {
            setDrawOpacity(clamp01((Number(drawToolOpacityInput.value) || 100) / 100, 1));
            syncDrawToolPanelUi();
        });
    }
    markupToolTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            setMarkupToolMode(tab.dataset.markupToolMode || 'draw');
        });
    });
    if (markupToolModalScrim) {
        markupToolModalScrim.addEventListener('click', () => closeMarkupToolModal());
    }
    if (markupToolModalClose) {
        markupToolModalClose.addEventListener('click', () => closeMarkupToolModal());
    }
    if (markupToolCancelBtn) {
        markupToolCancelBtn.addEventListener('click', () => closeMarkupToolModal());
    }
    if (markupToolClearBtn) {
        markupToolClearBtn.addEventListener('click', () => {
            clearMarkupToolDrawingState();
            setMarkupToolDirtyState(false);
            setMarkupToolStatus('Drawing cleared. Start a new mark.');
            updateMarkupToolUi();
        });
    }
    if (markupToolColorInput) markupToolColorInput.addEventListener('input', syncMarkupToolLabels);
    if (markupToolWidthInput) markupToolWidthInput.addEventListener('input', syncMarkupToolLabels);
    if (markupToolSmoothingInput) {
        markupToolSmoothingInput.addEventListener('input', () => {
            syncMarkupToolLabels();
            if (markupToolMode === 'draw') renderMarkupToolDrawPreview();
        });
    }
    if (markupToolCanvas) {
        markupToolCanvas.addEventListener('pointerdown', beginMarkupToolStroke);
        markupToolCanvas.addEventListener('pointermove', drawMarkupToolStroke);
        markupToolCanvas.addEventListener('pointerup', endMarkupToolStroke);
        markupToolCanvas.addEventListener('pointerleave', endMarkupToolStroke);
        markupToolCanvas.addEventListener('pointercancel', endMarkupToolStroke);
    }
    if (markupToolApplyBtn) {
        markupToolApplyBtn.addEventListener('click', () => {
            if (markupToolMode === 'erase') {
                closeMarkupToolModal();
                setEraseMode(true);
                return;
            }
            const asset = buildCurrentMarkupAsset();
            if (!asset?.dataUrl) {
                setMarkupToolStatus('Draw something before placing it on the page.', 'error');
                return;
            }
            closeMarkupToolModal();
            beginSignaturePlacement(asset, 'image');
        });
    }
    if (ftbSign) {
        ftbSign.addEventListener('click', () => {
            if (editModeEnabled) return;
            cancelSignaturePlacement();
            openSignatureModal('draw');
        });
    }

    // ---- Image import modal ---------------------------------------------
    // Lightweight standalone modal: pick / drop / paste an image, preview it,
    // then place it as an annotation on the current visible page using the
    // same plumbing signatures use (placeImageBackedAnnotationOnCurrentPage).
    // IMAGE_IMPORT_ACCEPTED_TYPES + IMAGE_IMPORT_MAX_BYTES moved to ./image-import/modal.js (Phase 7ce).
    // imageImportPendingAsset moved to ./store/misc-state.js (Phase 5m).

    // setImageImportStatus moved to ./image-import/status.js (Phase 7ax).
    const setImageImportStatus = (text, isError = false) => _setImageImportStatus(imageImportStatus, text, isError);
    // clearImageImportSelection / openImageImportModal / closeImageImportModal
    // moved to ./image-import/modal.js (Phase 7cb).
    const _imageImportRefs = () => ({
        imageImportModal,
        imageImportFileInput,
        imageImportPreview,
        imageImportPreviewImg,
        imageImportPreviewName,
        imageImportPreviewDims,
        imageImportDropzoneInner,
        imageImportApply,
        imageImportStatus,
    });
    const clearImageImportSelection = () => _clearImageImportSelection(_imageImportRefs());
    const openImageImportModal = () => _openImageImportModal(_imageImportRefs());
    const closeImageImportModal = () => _closeImageImportModal(_imageImportRefs());
    // imageImportLoadFile moved to ./image-import/modal.js (Phase 7ce).
    const imageImportLoadFile = (file) => _imageImportLoadFile(file, _imageImportRefs());
    if (ftbAddImage) {
        ftbAddImage.addEventListener('click', () => {
            if (editModeEnabled) return;
            openImageImportModal();
        });
    }
    if (imageImportScrim) imageImportScrim.addEventListener('click', () => closeImageImportModal());
    if (imageImportClose) imageImportClose.addEventListener('click', () => closeImageImportModal());
    if (imageImportCancel) imageImportCancel.addEventListener('click', () => closeImageImportModal());
    if (imageImportClear) {
        imageImportClear.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearImageImportSelection();
        });
    }
    if (imageImportFileInput) {
        imageImportFileInput.addEventListener('change', (e) => {
            const file = e.target?.files?.[0];
            if (file) imageImportLoadFile(file);
        });
    }
    if (imageImportDropzone) {
        imageImportDropzone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                imageImportFileInput?.click();
            }
        });
        ['dragenter', 'dragover'].forEach((ev) => {
            imageImportDropzone.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
                imageImportDropzone.classList.add('is-drag');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach((ev) => {
            imageImportDropzone.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
                imageImportDropzone.classList.remove('is-drag');
            });
        });
        imageImportDropzone.addEventListener('drop', (e) => {
            const file = e.dataTransfer?.files?.[0];
            if (file) imageImportLoadFile(file);
        });
    }
    // Paste-from-clipboard: only while modal is open.
    document.addEventListener('paste', (e) => {
        if (!imageImportModal?.classList.contains('is-open')) return;
        const items = e.clipboardData?.items || [];
        for (const it of items) {
            if (it.kind === 'file' && String(it.type || '').startsWith('image/')) {
                const file = it.getAsFile();
                if (file) {
                    e.preventDefault();
                    imageImportLoadFile(file);
                    break;
                }
            }
        }
    });
    if (imageImportApply) {
        imageImportApply.addEventListener('click', () => {
            if (!imageImportPendingAsset) return;
            try {
                // Pick the page that occupies the largest portion of the
                // current viewport and place the image at the viewport's
                // visible center on that page (converted to PDF bottom-origin
                // coords). Falls back to page center when geometry isn't
                // available. This avoids the "weird place" caused by relying
                // on the stale page-jump input or last-active annotation page.
                const target = pickViewportTargetPage();
                if (target && Number.isInteger(target.pi) && pageData[target.pi]) {
                    placeImageBackedAnnotationOnPage(target.pi, imageImportPendingAsset, 'image', target.centerPt);
                } else {
                    placeImageBackedAnnotationOnCurrentPage(imageImportPendingAsset, 'image');
                }
            } catch (err) {
                console.error('Image insert failed', err);
                setImageImportStatus('Could not insert that image.', true);
                return;
            }
            closeImageImportModal();
        });
    }
    // pickViewportTargetPage moved to ./viewport/picker.js (Phase 7as).
    // Close on Escape (only when this modal is the topmost open one).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && imageImportModal?.classList.contains('is-open')) {
            closeImageImportModal();
        }
    });

    signatureTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            setSignatureMode(tab.dataset.signatureMode || 'draw');
        });
    });
    signatureViewTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            showSavedView(true);
        });
    });
    // role="tab" promises arrow-key navigation across the whole tablist.
    installSignatureTabKeyboard([...signatureTabs, ...signatureViewTabs], (tab) => {
        if (tab.dataset.signatureView === 'saved') showSavedView(true);
        else setSignatureMode(tab.dataset.signatureMode || 'draw');
    });
    if (signatureModalScrim) {
        signatureModalScrim.addEventListener('click', () => closeSignatureModal());
    }
    if (signatureModalClose) {
        signatureModalClose.addEventListener('click', () => closeSignatureModal());
    }
    if (signatureCancelBtn) {
        signatureCancelBtn.addEventListener('click', () => closeSignatureModal());
    }
    if (signatureClearBtn) {
        signatureClearBtn.addEventListener('click', () => {
            clearSignatureDrawingState();
            setSignatureImageAsset(null);
            if (signatureTextInput) signatureTextInput.value = '';
            if (signatureImageInput) signatureImageInput.value = '';
            if (signatureImageName) signatureImageName.textContent = 'No file selected';
            setSignatureDirtyState(false);
            setSignatureStatus('Preview cleared.');
        });
    }
    if (signatureColorInput) {
        signatureColorInput.addEventListener('input', () => {
            syncSignatureColorLabels();
            if (signatureMode === 'draw' && signatureActiveStroke) {
                signatureActiveStroke.color = signatureColorInput.value || '#111827';
                renderDrawSignaturePreview();
            }
        });
    }
    if (signatureWidthInput) {
        signatureWidthInput.addEventListener('input', () => {
            syncSignatureColorLabels();
            if (signatureMode === 'draw' && signatureActiveStroke) {
                signatureActiveStroke.width = Math.max(1, Number(signatureWidthInput.value) || 3);
                renderDrawSignaturePreview();
            }
        });
    }
    if (signatureSmoothingInput) {
        signatureSmoothingInput.addEventListener('input', () => {
            syncSignatureColorLabels();
            if (signatureMode === 'draw') renderDrawSignaturePreview();
        });
    }
    if (signatureTypeColorInput) {
        signatureTypeColorInput.addEventListener('input', () => {
            syncSignatureColorLabels();
            if (signatureMode === 'type') renderTypedSignaturePreview();
        });
    }
    if (signatureTypeSizeInput) {
        signatureTypeSizeInput.addEventListener('input', () => {
            syncSignatureTypeSizeLabel();
            if (signatureMode === 'type') renderTypedSignaturePreview();
        });
    }
    if (signatureTextInput) {
        signatureTextInput.addEventListener('input', () => {
            renderTypedSignaturePreview();
        });
    }
    if (signatureFontInput) {
        signatureFontInput.addEventListener('change', () => {
            renderTypedSignaturePreview();
        });
    }
    if (signatureImageInput) {
        signatureImageInput.addEventListener('change', async () => {
            const file = signatureImageInput.files?.[0];
            if (!file) return;
            try {
                setSignatureImageAsset(await normalizeImportedImageAsset(file));
                if (signatureImageName) {
                    signatureImageName.textContent = `${signatureImageAsset.fileName} • ${signatureImageAsset.width}×${signatureImageAsset.height}`;
                }
                setSignatureMode('upload');
                await renderSignatureImagePreview();
                setSignatureStatus('Image imported and ready to place.', 'ready');
            } catch (error) {
                setSignatureImageAsset(null);
                if (signatureImageName) signatureImageName.textContent = 'No file selected';
                setSignatureStatus(error?.message || 'Failed to import image.', 'error');
                setSignatureDirtyState(false);
            }
        });
    }
    if (signatureCanvas) {
        signatureCanvas.addEventListener('pointerdown', beginSignatureStroke);
        signatureCanvas.addEventListener('pointermove', drawSignatureStroke);
        signatureCanvas.addEventListener('pointerup', endSignatureStroke);
        signatureCanvas.addEventListener('pointerleave', endSignatureStroke);
        signatureCanvas.addEventListener('pointercancel', endSignatureStroke);
    }
    if (signatureApplyBtn) {
        signatureApplyBtn.addEventListener('click', () => {
            if (!signatureDirty || !signatureCanvas) return;
            const asset = buildCurrentSignatureAsset();
            if (!asset?.dataUrl && !asset?.src) return;
            const editTarget = signatureEditTarget ? { ...signatureEditTarget } : null;
            closeSignatureModal();
            if (editTarget) {
                const targetAnn = pageData[editTarget.pi]?.annotations.find((item) => item._uid === editTarget.uid);
                if (targetAnn) {
                    replaceImageBackedAnnotation(targetAnn, editTarget.pi, asset);
                    return;
                }
            }
            beginSignaturePlacement(asset, 'signature');
        });
    }
    if (addTextBtn) {
        addTextBtn.addEventListener('click', () => {
            setAddTextMode(!addTextMode);
            if (addTextMode) clearActiveAnnotation();
        });
    }
    if (addShapeBtn) {
        addShapeBtn.addEventListener('click', () => {
            if (editModeEnabled) return;
            setShapeMode(!shapeMode);
            if (shapeMode) clearActiveAnnotation();
        });
    }
    if (ftbAddText) {
        ftbAddText.addEventListener('click', () => {
            if (editModeEnabled) return;
            setAddTextMode(!addTextMode);
            if (addTextMode) clearActiveAnnotation();
        });
    }
    if (ftbAddShape) {
        ftbAddShape.addEventListener('click', () => {
            if (editModeEnabled) return;
            setShapeMode(!shapeMode);
            if (shapeMode) clearActiveAnnotation();
        });
    }
    if (shapeDrawToggle) {
        shapeDrawToggle.addEventListener('click', () => {
            if (editModeEnabled) return;
            setShapeMode(!shapeMode);
            if (shapeMode) clearActiveAnnotation();
        });
    }
    if (shapeCopyBtn) {
        shapeCopyBtn.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active || !isShapeAnnotation(active.ann)) return;
            if (isAnnotationLocked(active.ann)) return;
            const { ann, pi, data } = active;
            pushUndo();
            const OFFSET = 12;
            const newAnn = JSON.parse(JSON.stringify(ann));
            newAnn._uid = 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2);
            newAnn.id = generateAnnotationId();
            newAnn.pageIndex = pi;
            newAnn.pdfX = Math.min((Number(ann.pdfX) || 0) + OFFSET, Math.max(0, data.wPts - (Number(ann.pdfWidth) || 60)));
            newAnn.pdfY = Math.max((Number(ann.pdfY) || 0) - OFFSET, 0);
            newAnn.userCreated = true;
            normalizeShapeAnnotation(newAnn);
            newAnn._originalBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            newAnn._originalPdfBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            data.annotations.push(newAnn);
            selectAnnotation(newAnn, pi);
            markDirty();
        });
    }
    if (shapeDeleteBtn) {
        shapeDeleteBtn.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active || !isShapeAnnotation(active.ann)) return;
            if (isAnnotationLocked(active.ann)) return;
            deleteAnnotation(active.ann, active.pi);
        });
    }
    if (downloadPdfButton) {
        downloadPdfButton.addEventListener('click', () => {
            downloadReadyPdf();
        });
    }
    if (zoomOutBtn) {
        zoomOutBtn.addEventListener('click', () => updateZoom(currentZoomPercent - ZOOM_STEP_PERCENT));
    }
    if (zoomInBtn) {
        zoomInBtn.addEventListener('click', () => updateZoom(currentZoomPercent + ZOOM_STEP_PERCENT));
    }
    if (pageJumpInput) {
        pageJumpInput.addEventListener('change', () => {
            const value = parseInt(pageJumpInput.value || '1', 10);
            if (Number.isFinite(value)) scrollToPage(value);
        });
        pageJumpInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const value = parseInt(pageJumpInput.value || '1', 10);
            if (Number.isFinite(value)) scrollToPage(value);
        });
    }
    if (pagePrevBtn) {
        pagePrevBtn.addEventListener('click', () => {
            const current = parseInt(pageJumpInput?.value || '1', 10) || 1;
            scrollToPage(current - 1);
        });
    }
    if (pageNextBtn) {
        pageNextBtn.addEventListener('click', () => {
            const current = parseInt(pageJumpInput?.value || '1', 10) || 1;
            scrollToPage(current + 1);
        });
    }
    window.addEventListener('scroll', () => {
        syncCurrentPageFromScroll();
        if (activeState.pi !== null) syncActiveEditor();
    }, { passive: true });
    window.addEventListener('resize', () => {
        beginViewportScaleUpdate();
        applyAllPageScales();
        syncCurrentPageFromScroll();
        if (activeState.pi !== null) syncActiveEditor();
    });

    shapeTypeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextType = normalizeShapeType(button.dataset.shapeTool);
            reflectShapeStateToInputs({ ...currentShapeDefaults(), shapeType: nextType });
            const active = getActiveAnnAndPage();
            if (active && isShapeAnnotation(active.ann)) {
                commitShapeInspectorToActive({ pushHistory: true });
            } else {
                syncShapePanelUi();
            }
        });
    });

    if (shapeStrokeColorInput) {
        shapeStrokeColorInput.addEventListener('input', () => {
            if (shapeStrokeHexInput) shapeStrokeHexInput.value = shapeStrokeColorInput.value;
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeStrokeColorInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeStrokeHexInput) {
        shapeStrokeHexInput.addEventListener('input', () => {
            const normalized = normalizeHexColor(shapeStrokeHexInput.value, currentShapeStrokeColor);
            if (shapeStrokeColorInput) shapeStrokeColorInput.value = normalized;
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeStrokeHexInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeStrokeTransparentInput) {
        shapeStrokeTransparentInput.addEventListener('change', () => {
            readShapeInspectorState();
            commitShapeInspectorToActive({ pushHistory: true });
        });
    }
    if (shapeStrokeWidthInput) {
        shapeStrokeWidthInput.addEventListener('input', () => {
            if (shapeStrokeWidthValue) shapeStrokeWidthValue.textContent = `${shapeStrokeWidthInput.value} px`;
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeStrokeWidthInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeStrokeOpacityInput) {
        shapeStrokeOpacityInput.addEventListener('input', () => {
            if (shapeStrokeOpacityValue) shapeStrokeOpacityValue.textContent = `${shapeStrokeOpacityInput.value}%`;
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeStrokeOpacityInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeFillColorInput) {
        shapeFillColorInput.addEventListener('input', () => {
            if (shapeFillHexInput) shapeFillHexInput.value = shapeFillColorInput.value;
            activateShapeFillFromInspector({ shapeFillOpacityInput, shapeFillTransparentInput });
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeFillColorInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeFillHexInput) {
        shapeFillHexInput.addEventListener('input', () => {
            const normalized = normalizeHexColor(shapeFillHexInput.value, currentShapeFillColor);
            if (shapeFillColorInput) shapeFillColorInput.value = normalized;
            activateShapeFillFromInspector({ shapeFillOpacityInput, shapeFillTransparentInput });
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeFillHexInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }
    if (shapeFillTransparentInput) {
        shapeFillTransparentInput.addEventListener('change', () => {
            readShapeInspectorState();
            commitShapeInspectorToActive({ pushHistory: true });
        });
    }
    if (shapeFillOpacityInput) {
        shapeFillOpacityInput.addEventListener('input', () => {
            if (shapeFillOpacityValue) shapeFillOpacityValue.textContent = `${shapeFillOpacityInput.value}%`;
            activateShapeFillFromInspector({ shapeFillOpacityInput, shapeFillTransparentInput });
            commitShapeInspectorToActive({ pushHistory: false });
        });
        shapeFillOpacityInput.addEventListener('change', () => commitShapeInspectorToActive({ pushHistory: true }));
    }

    // ── Text Format Bar event listeners ───────────────────────────────────────
    // Track pointer-down on the format bar so the ae blur handler doesn't clear
    // the active annotation when the user clicks a color picker or other control.
    // _formatBarMousedownTs moved to ./store/misc-state.js (Phase 5m).
    if (annFormatBar) {
        annFormatBar.addEventListener('mousedown', (e) => {
            setFormatBarMousedownTs(Date.now());
            // Prevent the format-bar button/control from stealing focus away from
            // the active editor. This preserves the user's selection so per-selection
            // commands (bold/italic/underline/color on a highlighted word) can run
            // via document.execCommand without collapsing the range. Inputs that need
            // to receive focus (text colour picker, select dropdowns, the size slider)
            // are excluded so they can still be interacted with.
            const target = e.target;
            if (!(target instanceof HTMLElement)) return;
            const tag = target.tagName;
            const isFocusableControl = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA'
                || target.isContentEditable
                || (target.closest && target.closest('input, select, textarea, [contenteditable=""], [contenteditable="true"]'));
            if (!isFocusableControl) e.preventDefault();
        });
    }

    // getActiveAnnAndPage moved to ./selection/active.js (Phase 7ap).

    // activeEditorForPage, setEditorEditingAnnotation moved to
    // ./editor/active-editor-element.js (Phase 7an).

    // ── Debug: preview what syncActiveEditor would put into ae when "Edit text"
    // is hit on a given annotation. Pure capture — no permanent state mutation.
    function captureEditorHtmlPreview(ann, pi) {
        const ae = activeEditorForPage(pi);
        if (!ae || !ann) return null;

        // Preferred path: when this annotation is rendered by the rich-html
        // DOM layer (the actual on-page text the user sees), capture THAT
        // element's innerHTML/outerStyle directly. Capturing syncActiveEditor
        // output instead would yield galley-mode absolute spans that, when
        // flattened in the modal, wrap differently than the on-page render
        // and make the preview useless for layout debugging.
        try {
            const layer = document.getElementById('rhl-' + (Number(pi) + 1));
            const richItem = layer && ann._uid
                ? layer.querySelector('.rich-html-item[data-uid="' + CSS.escape(String(ann._uid)) + '"]')
                : null;
            if (richItem) {
                return {
                    renderMode: 'rich-html-layer',
                    innerHTML: richItem.innerHTML,
                    outerStyle: richItem.style.cssText,
                    editingUid: String(ann._uid || ''),
                };
            }
        } catch (_e) { /* fall through to ae capture */ }

        // Second preferred path: canvas-painted annotations
        // (`drawOriginalSource`, typical for promoted-from-extraction text)
        // aren't in the rich-html-layer. Use the same source-coordinate
        // editor HTML as the selected on-page annotation, including per-line
        // absolute offsets and scaleX fitting, so the debug live preview
        // matches the PDF/canvas render instead of reflowing through normal
        // browser text layout.
        try {
            const data = pageData[pi];
            const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
            const box = (typeof resolveAnnBox === 'function') ? resolveAnnBox(ann) : null;
            const scale = Number(data?.scale) || 0;
            if (spans.length && box && scale > 0
                && typeof renderableSourceLines === 'function'
                && typeof escapeHtml === 'function'
                && typeof resolveSpanFillStyle === 'function'
                && typeof fallbackFontFamily === 'function'
                && typeof blockLineHeightPx === 'function'
                && typeof editableLineStyle === 'function'
                && typeof buildEditorHTML === 'function'
            ) {
                const lines = renderableSourceLines(ann) || [];
                if (lines.length) {
                    const style0 = editableLineStyle(ann, 0);
                    const boxWPx = Math.max(10, box.w * scale);
                    const boxHPx = Math.max(10, box.h * scale);
                    const lineHeightPx = blockLineHeightPx(ann, 0, scale, style0);
                    const outerStyle = [
                        'display:block',
                        'position:relative',
                        'left:0',
                        'top:0',
                        'width:' + boxWPx.toFixed(2) + 'px',
                        'height:' + boxHPx.toFixed(2) + 'px',
                        'min-height:' + boxHPx.toFixed(2) + 'px',
                        'font-family:' + style0.fontFamily,
                        'font-size:' + (style0.fontSizePt * fontDisplayScale(scale)).toFixed(2) + 'px',
                        'font-weight:' + (style0.fontWeight || '400'),
                        'font-style:' + (style0.fontStyle || 'normal'),
                        'line-height:' + lineHeightPx.toFixed(2) + 'px',
                        'color:' + style0.fillStyle,
                        'background:transparent',
                        'padding:0',
                        'margin:0',
                        'border:none',
                        'outline:none',
                        'overflow:visible',
                        'text-align:left',
                        'white-space:normal',
                        'max-width:none',
                        'box-sizing:border-box',
                    ].join(';');

                    return {
                        renderMode: 'source-exact',
                        innerHTML: buildEditorHTML(ann, scale),
                        outerStyle,
                        editingUid: String(ann._uid || ''),
                    };
                }
            }
        } catch (_e) { /* fall through to ae capture */ }

        const saved = {
            editing: ae.dataset.editing,
            editingUid: ae.dataset.editingUid,
            renderMode: ae.dataset.renderMode,
            innerHTML: ae.innerHTML,
            cssText: ae.style.cssText,
            activeUid: activeState.uid,
            activePi: activeState.pi,
        };
        try {
            setActiveState({ pi, uid: ann._uid });
            ae.dataset.editing = '1';
            ae.dataset.editingUid = String(ann._uid || '');
            syncActiveEditor(true);
            return {
                renderMode: ae.dataset.renderMode || '(unset)',
                innerHTML: ae.innerHTML,
                outerStyle: ae.style.cssText,
                editingUid: ae.dataset.editingUid || '',
            };
        } finally {
            // Restore exactly
            ae.style.cssText = saved.cssText || '';
            ae.innerHTML = saved.innerHTML;
            if (saved.editing === undefined) delete ae.dataset.editing;
            else ae.dataset.editing = saved.editing;
            if (saved.editingUid === undefined) delete ae.dataset.editingUid;
            else ae.dataset.editingUid = saved.editingUid;
            if (saved.renderMode === undefined) delete ae.dataset.renderMode;
            else ae.dataset.renderMode = saved.renderMode;
            setActiveState({ pi: saved.activePi, uid: saved.activeUid });
        }
    }

    // _dbgEscape, _dbgPrettyHtml, _dbgFormatStyle moved to
    // ./editor/debug-modal-format.js (Phase 7ap).

    function openEditorHtmlDebugModal(ann, pi) {
        // Close any existing instance.
        document.getElementById('dbg-editor-modal')?.remove();

        const preview = captureEditorHtmlPreview(ann, pi) || {
            renderMode: '(error)', innerHTML: '', outerStyle: '', editingUid: '',
        };

        // ORIGINAL vs EDITED determination — any user modification flips to EDITED.
        const _dimsChanged = (typeof annotationDimensionsChanged === 'function')
            && annotationDimensionsChanged(ann);
        const _textEdited = (typeof annTextIsEdited === 'function') && annTextIsEdited(ann);
        const _hasOffset = (() => {
            try {
                const off = (typeof annotationOffset === 'function') ? annotationOffset(ann) : null;
                return !!off && (Math.abs(off.dx) > 0.25 || Math.abs(off.dy) > 0.25);
            } catch (_e) { return false; }
        })();
        const _hasRichHtml = !!ann._richHtml;
        const _styleDirty = !!ann._styleDirty;
        const _userAuthored = (typeof isUserAuthoredAnnotation === 'function')
            && isUserAuthoredAnnotation(ann);
        const _isEdited = _dimsChanged || _textEdited || _hasOffset || _hasRichHtml
            || _styleDirty || _userAuthored;
        const _statusLabel = _isEdited ? 'EDITED' : 'ORIGINAL';
        const _statusClass = _isEdited ? 'is-edited' : 'is-original';

        const overlay = document.createElement('div');
        overlay.id = 'dbg-editor-modal';
        overlay.innerHTML =
            '<div class="dbg-modal-backdrop"></div>' +
            '<div class="dbg-modal-card" role="dialog" aria-label="Editor HTML debug preview">' +
                '<header class="dbg-modal-header">' +
                    '<div class="dbg-modal-title">' +
                        '<span class="dbg-modal-dot"></span>' +
                        'Editor HTML preview' +
                        '<span class="dbg-modal-status ' + _statusClass + '">' + _statusLabel + '</span>' +
                        '<span class="dbg-modal-badge">' + _dbgEscape(preview.renderMode) + '</span>' +
                    '</div>' +
                    '<div class="dbg-modal-meta">' +
                        '<code>uid=' + _dbgEscape(ann._uid) + '</code>' +
                        '<code>pi=' + pi + '</code>' +
                        '<code>has _richHtml=' + _hasRichHtml + '</code>' +
                        '<code>dimsChanged=' + _dimsChanged + '</code>' +
                        '<code>textEdited=' + _textEdited + '</code>' +
                        '<code>moved=' + _hasOffset + '</code>' +
                        '<code>userAuthored=' + _userAuthored + '</code>' +
                    '</div>' +
                    '<button type="button" class="dbg-modal-close" aria-label="Close">×</button>' +
                '</header>' +
                '<nav class="dbg-tabs" role="tablist">' +
                    '<button type="button" class="dbg-tab is-active" data-tab="dbg-tab-live" role="tab" aria-selected="true">Live render</button>' +
                    '<button type="button" class="dbg-tab" data-tab="dbg-tab-styles" role="tab" aria-selected="false">Outer styles</button>' +
                    '<button type="button" class="dbg-tab" data-tab="dbg-tab-raw" role="tab" aria-selected="false">innerHTML (raw)</button>' +
                    '<button type="button" class="dbg-tab" data-tab="dbg-tab-pretty" role="tab" aria-selected="false">innerHTML (pretty)</button>' +
                '</nav>' +
                '<div class="dbg-modal-body">' +
                    '<section class="dbg-section is-active" id="dbg-tab-live" role="tabpanel">' +
                        '<div class="dbg-section-head">' +
                            '<h3>Live render <span class="dbg-section-hint">— editable; click ✓ to save & redraw</span></h3>' +
                            '<div class="dbg-section-actions">' +
                                '<span class="dbg-save-status" id="dbg-save-status"></span>' +
                                '<button type="button" class="dbg-save-btn" id="dbg-save-btn" title="Save edit and redraw PDF section">✓ Save</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="dbg-live-frame"><div id="dbg-live-host" class="dbg-live-host" contenteditable="true" spellcheck="false"></div></div>' +
                    '</section>' +
                    '<section class="dbg-section" id="dbg-tab-styles" role="tabpanel">' +
                        '<div class="dbg-section-head">' +
                            '<h3>Outer container styles</h3>' +
                            '<button type="button" class="dbg-copy-btn" data-target="dbg-styles">Copy</button>' +
                        '</div>' +
                        '<pre id="dbg-styles" class="dbg-pre dbg-pre-style"></pre>' +
                    '</section>' +
                    '<section class="dbg-section" id="dbg-tab-raw" role="tabpanel">' +
                        '<div class="dbg-section-head">' +
                            '<h3>innerHTML (raw)</h3>' +
                            '<button type="button" class="dbg-copy-btn" data-target="dbg-html-raw">Copy</button>' +
                        '</div>' +
                        '<pre id="dbg-html-raw" class="dbg-pre dbg-pre-html"></pre>' +
                    '</section>' +
                    '<section class="dbg-section" id="dbg-tab-pretty" role="tabpanel">' +
                        '<div class="dbg-section-head">' +
                            '<h3>innerHTML (pretty)</h3>' +
                            '<button type="button" class="dbg-copy-btn" data-target="dbg-html-pretty">Copy</button>' +
                        '</div>' +
                        '<pre id="dbg-html-pretty" class="dbg-pre dbg-pre-html"></pre>' +
                    '</section>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        // Populate text content via .textContent so escaping happens automatically.
        overlay.querySelector('#dbg-styles').textContent = _dbgFormatStyle(preview.outerStyle);
        overlay.querySelector('#dbg-html-raw').textContent = preview.innerHTML;
        overlay.querySelector('#dbg-html-pretty').textContent = _dbgPrettyHtml(preview.innerHTML);

        // Live render — apply outer styles + innerHTML to a sandbox div.
        const host = overlay.querySelector('#dbg-live-host');
        host.style.cssText = preview.outerStyle;
        const sourceExactPreview = preview.renderMode === 'source-exact';
        // Force visible positioning inside our flow regardless of original
        // absolute coordinates from syncActiveEditor.
        host.style.position = 'relative';
        host.style.left = '0';
        host.style.top = '0';
        // Keep the original width from the captured outerStyle so text
        // wraps the same way as in the actual annotation box. Clearing the
        // width here lets the modal column shrink the host and create
        // extra wrap points that don't exist in the real render.
        // Height is cleared so the host grows to fit content. Exact source
        // previews keep their annotation height because their children are
        // absolutely positioned and otherwise would not contribute flow height.
        if (!sourceExactPreview) {
            host.style.height = '';
        }
        // Do NOT cap maxWidth to the modal column — that would re-introduce
        // the very wrap mismatch the comment above warns about. The
        // surrounding `.dbg-live-frame` has `overflow: auto`, so wider
        // annotations scroll horizontally inside the frame instead of
        // being squeezed into a narrower wrap.
        host.style.maxWidth = 'none';
        // Galley/source/canvas render modes use absolute-positioned per-line
        // and per-span CSS scoped to .active-editor[data-render-mode="..."].
        // Without those scopes the spans collapse to inline flow and the text
        // visibly overlaps. Mirror the render-mode marker so the same rules
        // apply inside the preview frame.
        if (preview.renderMode) {
            host.dataset.renderMode = preview.renderMode;
        }
        // Strip any inherited transparent color from the editor's outer
        // cssText — canvas-owns hides editor glyphs because the canvas paints
        // them, but in the preview there is no canvas, so transparent text
        // would show as a blank frame.
        host.style.color = '';

        // Always use the captured preview.innerHTML as the editable surface.
        // It preserves all inline formatting (bold spans, font-weight,
        // colors, italics) regardless of render mode. Universal CSS
        // overrides on `.dbg-live-host` strip the absolute positioning so
        // galley/source per-line blocks reflow naturally in the modal.
        host.innerHTML = preview.innerHTML;

        // For source-exact previews, swap the raw contenteditable host for
        // a ProseMirror editor that preserves per-character font/size/
        // weight/style/color and per-line absolute coordinates from the
        // captured HTML, while giving native caret + Enter/Backspace
        // behaviour. The PM instance owns its own DOM under `host`; we
        // expose `host._pmEditor` so the save handler can pull line texts
        // through getLineTexts() instead of re-parsing the DOM.
        let pmEditor = null;
        if (sourceExactPreview) {
            try {
                pmEditor = mountProseMirrorSourceEditor(host, preview.innerHTML);
                host._pmEditor = pmEditor;
            } catch (err) {
                // Fall back to the raw contenteditable surface if PM fails
                // to construct (e.g. malformed source HTML).
                host.innerHTML = preview.innerHTML;
                pmEditor = null;
                if (typeof console !== 'undefined') console.warn('[dbg-modal] PM mount failed, falling back to contenteditable', err);
            }
        }

        // Graceful deletion of contenteditable=false markers / kerning
        // spacers: when the user presses Backspace at the start of an
        // editable text node and the previous DOM neighbor is a non-
        // editable marker (•, kerning offset, etc), remove that neighbor
        // instead of letting the browser silently no-op. Same for Delete
        // at the end of a text node with a non-editable next neighbor.
        host.addEventListener('keydown', (ev) => {
            if (ev.key !== 'Backspace' && ev.key !== 'Delete') return;
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) return;
            const range = sel.getRangeAt(0);
            let node = range.startContainer;
            const offset = range.startOffset;
            const isAtTextEdge = (n, off, dir) => {
                if (!n) return false;
                if (n.nodeType === Node.TEXT_NODE) {
                    return dir === 'back' ? off === 0 : off === (n.textContent || '').length;
                }
                return true;
            };
            const findNeighbor = (n, dir) => {
                // Walk up while at edge of containing element looking for
                // the previous/next non-editable atomic neighbor.
                let cur = n;
                if (cur.nodeType !== Node.ELEMENT_NODE) cur = cur.parentNode;
                let probe = (n.nodeType === Node.TEXT_NODE) ? n : (dir === 'back' ? n.childNodes[offset - 1] : n.childNodes[offset]);
                while (cur && cur !== host) {
                    const sib = dir === 'back'
                        ? (probe ? probe.previousSibling : cur.previousSibling)
                        : (probe ? probe.nextSibling : cur.nextSibling);
                    if (sib) {
                        if (sib.nodeType === Node.ELEMENT_NODE
                            && (sib.getAttribute('contenteditable') === 'false'
                                || sib.hasAttribute('data-source-marker')
                                || sib.hasAttribute('data-kerning-offset'))) {
                            return sib;
                        }
                        return null;
                    }
                    probe = cur;
                    cur = cur.parentNode;
                }
                return null;
            };
            if (ev.key === 'Backspace' && isAtTextEdge(node, offset, 'back')) {
                const victim = findNeighbor(node, 'back');
                if (victim) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    victim.remove();
                    return;
                }
            }
            if (ev.key === 'Delete' && isAtTextEdge(node, offset, 'fwd')) {
                const victim = findNeighbor(node, 'fwd');
                if (victim) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    victim.remove();
                    return;
                }
            }
        });

        // Enter handler: in positioned render modes the host's children are
        // top-level [data-line-index] blocks containing an absolutely
        // positioned [data-line-content="1"] span. The browser default for
        // Enter inside that structure produces no visible feedback. Insert
        // a real <br> at the caret so the user sees the break immediately;
        // extractSourceExactLineTexts splits the line on <br> at save time.
        // Skip when the host is owned by ProseMirror (its own keymap
        // handles Enter as splitBlock with proper selection updates).
        host.addEventListener('keydown', (ev) => {
            if (host._pmEditor) return;
            if (ev.key !== 'Enter' || ev.shiftKey || ev.ctrlKey || ev.metaKey || ev.altKey) return;
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            const range = sel.getRangeAt(0);
            if (!host.contains(range.startContainer)) return;
            // Only intercept inside a [data-line-content="1"] span; let the
            // browser handle Enter normally elsewhere.
            let ctx = range.startContainer;
            if (ctx.nodeType !== Node.ELEMENT_NODE) ctx = ctx.parentNode;
            const contentEl = ctx && ctx.closest && ctx.closest('[data-line-content="1"]');
            if (!contentEl) return;
            ev.preventDefault();
            ev.stopPropagation();
            if (!range.collapsed) range.deleteContents();
            const br = document.createElement('br');
            range.insertNode(br);
            // Trailing zero-width text node so the caret can sit AFTER the
            // <br> (browsers refuse to place a caret after a trailing <br>).
            const trailing = document.createTextNode('\u200b');
            if (br.parentNode) br.parentNode.insertBefore(trailing, br.nextSibling);
            const after = document.createRange();
            after.setStart(trailing, 1);
            after.collapse(true);
            sel.removeAllRanges();
            sel.addRange(after);
        });

        // Track whether the source is a positioned render mode (galley /
        // source / canvas). The save handler uses this to:
        //  (a) extract text by grouping `<p data-line-index="N">` blocks by
        //      source-line index (consecutive blocks with the same index =
        //      one source paragraph, joined with a single space; different
        //      indexes = `\n`). Without this every visual wrap line would
        //      become its own `\n` and explode the saved text.
        //  (b) drop _richHtml on save so the per-line absolute positioning
        //      doesn't leak into persisted state — let renderPlainEditorHTML
        //      rebuild from text using current annotation styles. Inline
        //      bold/italic from font-weight on galley spans is lost in this
        //      case (galley spans aren't <b>/<strong>); for flow modes
        //      (plain/source-flow/dom-layer) the inline formatting is
        //      preserved by keeping _richHtml.
        const _positionedModes = new Set(['galley', 'source', 'source-exact', 'canvas']);
        const _isPositionedMode = preview.renderMode && _positionedModes.has(preview.renderMode);

        // Tab switching
        const tabs = overlay.querySelectorAll('.dbg-tab');
        const panels = overlay.querySelectorAll('.dbg-section');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                tabs.forEach((t) => {
                    const active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach((p) => p.classList.toggle('is-active', p.id === target));
            });
        });

        const close = () => {
            if (host && host._pmEditor && typeof host._pmEditor.destroy === 'function') {
                try { host._pmEditor.destroy(); } catch (_e) {}
                host._pmEditor = null;
            }
            overlay.remove();
        };
        overlay.querySelector('.dbg-modal-backdrop').addEventListener('click', close);
        overlay.querySelector('.dbg-modal-close').addEventListener('click', close);
        document.addEventListener('keydown', function esc(ev) {
            if (ev.key === 'Escape') {
                close();
                document.removeEventListener('keydown', esc);
            }
        });

        // Save handler — apply edits from the live-render host back onto the
        // annotation, mark dirty (triggers auto-save to backend) and redraw
        // the PDF overlay so the section repaints with the new text.
        const saveBtn = overlay.querySelector('#dbg-save-btn');
        const saveStatus = overlay.querySelector('#dbg-save-status');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                try {
                    const data = pageData[pi];
                    const targetAnn = data?.annotations.find(a => a._uid === ann._uid) || ann;
                    if (!targetAnn) {
                        if (saveStatus) saveStatus.textContent = 'Annotation not found';
                        return;
                    }
                    if (typeof isAnnotationLocked === 'function' && isAnnotationLocked(targetAnn)) {
                        if (saveStatus) saveStatus.textContent = 'Locked — cannot edit';
                        return;
                    }
                    saveBtn.disabled = true;

                    // Snapshot for undo before mutating.
                    if (typeof pushUndo === 'function') {
                        try { pushUndo(); } catch (_e) {}
                    }

                    // Capture edited plain text from the host. For positioned
                    // render modes (galley/source/canvas) the host contains
                    // many `<p data-line-index="N">` blocks — one per visual
                    // wrap line. Group consecutive blocks with the same
                    // source-line index into one paragraph (joined with a
                    // single space) so the round-tripped text matches the
                    // original `ann.text` line count instead of exploding
                    // each visual wrap line into its own `\n`.
                    const _isSourceExactMode = preview.renderMode === 'source-exact';
                    let newText;
                    let sourceExactSaved = false;
                    if (_isSourceExactMode) {
                        const lineTexts = (host._pmEditor && typeof host._pmEditor.getLineTexts === 'function')
                            ? host._pmEditor.getLineTexts()
                            : extractSourceExactLineTexts(host);
                        const lineSegments = (host._pmEditor && typeof host._pmEditor.getLineSegments === 'function')
                            ? host._pmEditor.getLineSegments()
                            : null;
                        newText = lineTexts.join('\n');
                        if (lineTexts.length) {
                            newText = applySourceExactTextEdit(targetAnn, lineTexts, lineSegments);
                            sourceExactSaved = true;
                        }
                    } else if (_isPositionedMode) {
                        const blocks = host.querySelectorAll('[data-line-index]');
                        if (blocks.length) {
                            const grouped = new Map();
                            const order = [];
                            blocks.forEach((b) => {
                                // Skip nested data-line-index spans (e.g. galley
                                // source-spans inside a <p data-line-index>);
                                // only top-level paragraph blocks contribute.
                                if (b.parentElement && b.parentElement.closest('[data-line-index]')) return;
                                const idx = b.getAttribute('data-line-index') || '0';
                                const txt = (b.textContent || '').replace(/\u00A0/g, ' ').trim();
                                if (!grouped.has(idx)) { grouped.set(idx, []); order.push(idx); }
                                if (txt) grouped.get(idx).push(txt);
                            });
                            newText = order.map((idx) => grouped.get(idx).join(' ')).join('\n');
                        } else {
                            newText = (typeof getEditorPlainText === 'function')
                                ? getEditorPlainText(host)
                                : (host.textContent || '');
                        }
                    } else {
                        newText = (typeof getEditorPlainText === 'function')
                            ? getEditorPlainText(host)
                            : (host.textContent || '');
                    }
                    const prevText = String(targetAnn.text ?? '');
                    const newHtml = host.innerHTML;

                    // Update editedTexts and decide whether to persist
                    // _richHtml. We keep _richHtml only when:
                    //  - the source was a flow render mode (we used the
                    //    original _richHtml as the editable surface), AND
                    //  - the resulting HTML still carries inline selection
                    //    formatting (bold/italic/underline/font/color spans).
                    // Otherwise drop _richHtml so renderPlainEditorHTML
                    // rebuilds with current annotation styles. This avoids
                    // leaking modal-only structure (positioned per-line
                    // blocks from galley) into the persistent ann state.
                    editedTexts[targetAnn._uid] = newText;
                    const _hasInlineFormatting = (typeof richHtmlHasInlineSelectionFormatting === 'function')
                        && richHtmlHasInlineSelectionFormatting(newHtml);
                    if (!_isPositionedMode && _hasInlineFormatting) {
                        targetAnn._richHtml = newHtml;
                    } else {
                        delete targetAnn._richHtml;
                    }
                    if (!sourceExactSaved && newText !== prevText && typeof markUserAuthored === 'function') {
                        markUserAuthored(targetAnn);
                    }
                    if (!sourceExactSaved && typeof resizeAnnotationForEditedText === 'function') {
                        try { resizeAnnotationForEditedText(targetAnn, newText, pi); } catch (_e) {}
                    }
                    if (typeof markDirty === 'function') markDirty();
                    try { syncActiveEditor(true); } catch (_e) {}
                    try { redrawOverlay(pi); } catch (_e) {}

                    if (saveStatus) saveStatus.textContent = 'Saved · redrawing…';
                    setTimeout(close, 350);
                } catch (err) {
                    saveBtn.disabled = false;
                    if (saveStatus) saveStatus.textContent = 'Error: ' + (err && err.message ? err.message : err);
                }
            });
        }

        overlay.querySelectorAll('.dbg-copy-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const tgt = overlay.querySelector('#' + btn.dataset.target);
                if (!tgt) return;
                navigator.clipboard.writeText(tgt.textContent || '').then(() => {
                    const orig = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(() => { btn.textContent = orig; }, 900);
                }).catch(() => {});
            });
        });
    }

    // clearEditorEditingState moved to ./editor/active-editor-element.js (Phase 7an).
    // editorIsEditingAnnotation moved to ./editor/is-editing.js (Phase 7ah).

    // Return the active editor element and the current selection range if
    // (a) the selection lies fully inside the editor AND (b) the selection
    // is not collapsed — i.e. the user actually has characters highlighted.
    // Used to route format-bar actions to per-selection formatting via
    // document.execCommand, instead of setting annotation-level properties.
    // getActiveEditorSelection moved to ./editor/active-selection.js (Phase 7az).

    // Persist the editor's current innerHTML on the annotation so subsequent
    // syncActiveEditor calls (triggered by redraw, resize etc.) don't flatten
    // per-selection formatting back to annotation-level uniform styling.
    // persistRichEditorHtml moved to ./annotations/state.js (Phase 7bn).

    function applySelectionFormat(command, valueArg) {
        const info = getActiveEditorSelection();
        if (!info) return false;
        if (isAnnotationLocked(info.active?.ann)) return false;
        // Ensure the editor has focus so execCommand targets our selection.
        if (document.activeElement !== info.ae) {
            info.ae.focus({ preventScroll: true });
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(info.range);
        }
        pushUndo();
        if (info.active?.ann) markUserAuthored(info.active.ann);
        // Pre-seed `_richHtml` from the current editor markup BEFORE invoking
        // execCommand. The editor's `input` listener wipes any per-selection
        // formatting by re-rendering plain HTML when `_richHtml` is empty —
        // that branch destroys the bold/italic span execCommand just produced.
        // Setting _richHtml here puts the input handler on the preservation
        // branch so the freshly-styled span survives the first click.
        if (info.active?.ann && !info.active.ann._richHtml) {
            info.active.ann._richHtml = info.ae.innerHTML;
        }
        const beforeHtml = info.ae.innerHTML;
        document.execCommand('styleWithCSS', false, true);
        document.execCommand(command, false, valueArg);
        // Chromium silently no-ops execCommand('bold'/'italic'/'underline') when the
        // contenteditable's ancestor has explicit inline font-weight / font-style /
        // text-decoration (as our `<div data-line-index=0 style="font-weight:400;...">`
        // wrapper does). Fall back to manual wrapping so the three toolbar commands
        // always produce a CSS span around the selection.
        if (info.ae.innerHTML === beforeHtml) {
            manualWrapSelection(info.ae, info.range, command, valueArg);
        }
        persistRichEditorHtml(info.active, info.ae);
        redrawOverlay(info.active.pi);
        return true;
    }

    // Wraps the current selection with a <span> that applies the equivalent inline
    // style for the given execCommand. Used as a fallback when Chromium refuses to
    // modify the DOM (happens when the editor's wrapper has explicit font-weight
    // etc. declarations).
    function manualWrapSelection(ae, fallbackRange, command, valueArg) {
        const sel = window.getSelection();
        let range = null;
        if (sel && sel.rangeCount > 0) range = sel.getRangeAt(0);
        if (!range || range.collapsed) range = fallbackRange;
        if (!range || range.collapsed) return;
        if (!ae.contains(range.startContainer) || !ae.contains(range.endContainer)) return;

        const cssDecl = (() => {
            switch (command) {
                case 'bold':      return 'font-weight: bold';
                case 'italic':    return 'font-style: italic';
                case 'underline': return 'text-decoration: underline';
                case 'foreColor': return `color: ${valueArg || '#000000'}`;
                case 'fontName':  return `font-family: ${valueArg || 'Helvetica'}`;
                case 'fontSize':  return `font-size: ${valueArg || '12pt'}`;
                default:          return null;
            }
        })();
        if (!cssDecl) return;

        try {
            const contents = range.extractContents();
            const span = document.createElement('span');
            span.setAttribute('style', cssDecl);
            span.appendChild(contents);
            range.insertNode(span);
            // Restore selection across the freshly-wrapped span.
            const newRange = document.createRange();
            newRange.selectNodeContents(span);
            sel.removeAllRanges();
            sel.addRange(newRange);
        } catch (_error) {
            // Selection spanned non-wrappable structure (eg. adjacent block boundaries).
            // Give up silently — the undo snapshot will let the user retry.
        }
    }

    function applyFormatProperty(update, opts) {
        const active = getActiveAnnAndPage();
        if (!active) return;
        const { ann, pi } = active;
        if (isAnnotationLocked(ann)) return;
        pushUndo();
        // Mark the style as dirty BEFORE running update(): editableLineStyle (and
        // editorSpanStyle) gate ann-level font overrides on _styleDirty, and the
        // font-size update callback calls resizeAnnotationForEditedText, which
        // measures the wrapped height via editableLineStyle. If _styleDirty is
        // still false at measurement time, the resize uses the OLD composite
        // size — height stays unchanged, annotationDimensionsChanged returns
        // false, and syncActiveEditor falls into the source-render path that
        // shows the OLD per-span sizes instead of the new ann.fontSize.
        ann._styleDirty = true;
        update(ann, pi);
        markUserAuthored(ann);
        // Block-level styles (text-align, underline) are baked into the inner
        // <div data-line-index="…"> wrappers produced by renderPlainEditorHTML.
        // Once the user has typed, _richHtml caches that DOM and is replayed by
        // syncActiveEditor, so the outer ae's text-align is overridden by the
        // inner div's stale value. Re-sync those wrappers to ann.textAlign /
        // ann.underline so format-bar changes take effect immediately.
        if (typeof ann._richHtml === 'string' && ann._richHtml.length > 0) {
            try {
                const tmp = document.createElement('div');
                tmp.innerHTML = ann._richHtml;
                const wrappers = tmp.querySelectorAll('[data-line-index]');
                if (wrappers.length) {
                    const align = ann.textAlign || 'left';
                    const decoration = ann.underline ? 'underline' : 'none';
                    wrappers.forEach((el) => {
                        el.style.textAlign = align;
                        el.style.textDecoration = decoration;
                    });
                }
                ann._richHtml = normalizeRichHtmlForDisplay(tmp.innerHTML, ann);
                tmp.innerHTML = ann._richHtml;
                // Strip the named inline style props from every descendant so
                // an annotation-level override (font color, font family, size,
                // weight, style) actually wins over previously-applied inline
                // selection styles cached inside _richHtml.
                const stripProps = Array.isArray(opts && opts.stripInlineProps) ? opts.stripInlineProps : null;
                if (stripProps && stripProps.length) {
                    tmp.querySelectorAll('[style]').forEach((el) => {
                        stripProps.forEach((prop) => { el.style.removeProperty(prop); });
                        if (!el.getAttribute('style')) el.removeAttribute('style');
                    });
                    // <font face="…"> tags from execCommand('fontName') aren't
                    // covered by the [style] sweep above — unwrap them when we
                    // strip font-family.
                    if (stripProps.includes('font-family')) {
                        tmp.querySelectorAll('font[face]').forEach((el) => {
                            const parent = el.parentNode;
                            if (!parent) return;
                            while (el.firstChild) parent.insertBefore(el.firstChild, el);
                            parent.removeChild(el);
                        });
                    }
                }
                ann._richHtml = normalizeRichHtmlForDisplay(tmp.innerHTML, ann);
            } catch (_err) { /* best-effort sync */ }
        }
        redrawOverlay(pi);
        syncActiveEditor(true);
        markDirty();
    }

    if (afbFont) {
        afbFont.addEventListener('change', () => {
            // Per-selection: if the user has highlighted characters, change only
            // those. Otherwise apply to the whole annotation and strip any
            // previously-applied inline font-family overrides so the new value wins.
            if (applySelectionFormat('fontName', afbFont.value)) return;
            applyFormatProperty(
                (ann) => { ann.fontFamily = afbFont.value; },
                { stripInlineProps: ['font-family'] }
            );
        });
    }
    if (afbSize) {
        // Live label update while dragging (no annotation write)
        afbSize.addEventListener('input', () => {
            const pt = sliderValueToFontPt(afbSize.value);
            if (afbSizeValue) afbSizeValue.textContent = pt + 'pt';
        });
        // Commit when slider is released
        afbSize.addEventListener('change', () => {
            const pt = sliderValueToFontPt(afbSize.value);
            if (afbSizeValue) afbSizeValue.textContent = pt + 'pt';
            // Per-selection size if a range is highlighted; otherwise whole annotation.
            if (applySelectionFormat('fontSize', `${pt}pt`)) return;
            applyFormatProperty(
                (ann, pi) => {
                    ann.fontSize = pt;
                    const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '');
                    resizeAnnotationForEditedText(ann, currentText, pi);
                },
                { stripInlineProps: ['font-size'] }
            );
        });
    }
    // Color pickers (`<input type="color">`) fire 'input' during drag for live
    // preview, then 'change' when the user commits (or even when they dismiss
    // on some browsers). Mutating the annotation directly from 'input' is
    // destructive: if the user cancels, the last intermediate color sticks —
    // this is the "I changed font and got a random red box" bug.
    //
    // Pattern used here:
    //   • On first 'input' after the picker was idle, snapshot the current ann
    //     value so we can revert.
    //   • Live preview via CSS only (no ann mutation, no markUserAuthored,
    //     no markDirty).
    //   • 'change' commits the value through applyFormatProperty (undo +
    //     markUserAuthored + markDirty). If no 'change' fires after the
    //     'input' burst (dismissed), the preview is cleared on blur.
    function setupColorPicker(input, property, { applySelectionMode } = {}) {
        if (!input) return;
        const state = {
            active: false,
            originalValue: null,
            // Per-selection drag state: when the user opens the picker with a
            // text selection in the active editor, we snapshot that range and
            // re-apply it on every 'input' tick so the color is restricted to
            // the selection for the entire drag (browser focus shifting into
            // the native picker would otherwise collapse the range and cause
            // each subsequent tick to recolor the whole paragraph).
            selectionRange: null,
            selectionAe: null,
            selectionAnn: null,
            undoPushed: false,
            // True once any per-selection drag tick has been applied during
            // this picker interaction. Used to suppress the fall-through path
            // in `change`/`blur` that would otherwise call applyFormatProperty
            // with stripInlineProps and recolor the entire paragraph (browsers
            // may fire blur before change when the native picker dialog
            // closes, clearing selectionRange just in time for change to take
            // the wrong branch — the classic "inverse text changed color"
            // bug).
            perSelectionApplied: false,
            fauxNodes: [],
        };

        const clearFauxSelection = () => {
            if (!state.fauxNodes.length) return;
            for (const node of state.fauxNodes) {
                if (node && node.parentNode) node.parentNode.removeChild(node);
            }
            state.fauxNodes = [];
        };

        const renderFauxSelection = () => {
            clearFauxSelection();
            if (!state.selectionRange) return;
            let rects;
            try { rects = state.selectionRange.getClientRects(); }
            catch (_e) { return; }
            if (!rects || rects.length === 0) return;
            for (const r of rects) {
                if (r.width <= 0 || r.height <= 0) continue;
                const el = document.createElement('div');
                el.className = 'afb-faux-selection';
                el.style.left   = r.left   + 'px';
                el.style.top    = r.top    + 'px';
                el.style.width  = r.width  + 'px';
                el.style.height = r.height + 'px';
                document.body.appendChild(el);
                state.fauxNodes.push(el);
            }
        };

        const captureSelectionForDrag = () => {
            if (!applySelectionMode) return false;
            const info = getActiveEditorSelection();
            if (!info) return false;
            state.selectionAe = info.ae;
            state.selectionAnn = info.active.ann;
            state.selectionRange = info.range.cloneRange();
            renderFauxSelection();
            return true;
        };

        const restoreSelectionForDrag = () => {
            if (!state.selectionRange || !state.selectionAe) return false;
            if (!state.selectionAe.isConnected) return false;
            if (document.activeElement !== state.selectionAe) {
                state.selectionAe.focus({ preventScroll: true });
            }
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(state.selectionRange);
            return true;
        };

        const applyDragColor = (value) => {
            if (!restoreSelectionForDrag()) return false;
            if (!state.undoPushed) {
                pushUndo();
                state.undoPushed = true;
            }
            if (state.selectionAnn) markUserAuthored(state.selectionAnn);
            if (state.selectionAnn && !state.selectionAnn._richHtml) {
                state.selectionAnn._richHtml = state.selectionAe.innerHTML;
            }
            try {
                document.execCommand('styleWithCSS', false, true);
                document.execCommand('foreColor', false, value);
            } catch (_err) { return false; }
            // Re-snapshot the (now-collapsed-into-new-span) selection so the
            // next tick's restore targets the newly-wrapped span instead of
            // a stale range pointing at the now-removed text node.
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
                state.selectionRange = sel.getRangeAt(0).cloneRange();
            }
            if (state.selectionAnn) {
                state.selectionAnn._richHtml = state.selectionAe.innerHTML;
                state.selectionAnn._styleDirty = true;
            }
            renderFauxSelection();
            return true;
        };

        const beginPreviewIfNeeded = () => {
            if (state.active) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            state.active = true;
            state.originalValue = active.ann[property];
        };

        const clearPreview = () => {
            state.active = false;
            state.originalValue = null;
            state.selectionRange = null;
            state.selectionAe = null;
            state.selectionAnn = null;
            state.undoPushed = false;
            state.perSelectionApplied = false;
            clearFauxSelection();
        };

        // Snapshot the selection BEFORE the native picker takes focus (the
        // pointerdown / mousedown on the input fires before the picker dialog
        // opens). This guarantees the selection is captured even though the
        // first 'input' event runs after focus has moved.
        const onPickerOpen = () => {
            if (!applySelectionMode) return;
            captureSelectionForDrag();
        };
        input.addEventListener('pointerdown', onPickerOpen);
        input.addEventListener('mousedown', onPickerOpen);

        input.addEventListener('input', () => {
            if (applyDragColor && applySelectionMode && state.selectionRange) {
                if (applyDragColor(input.value)) {
                    state.perSelectionApplied = true;
                    redrawOverlay(getActiveAnnAndPage()?.pi ?? 0);
                    markDirty();
                    return;
                }
            }
            beginPreviewIfNeeded();
            const active = getActiveAnnAndPage();
            if (!active) return;
            const ae = document.getElementById('ae-' + (active.pi + 1));
            if (ae) {
                if (property === 'backgroundColor') ae.style.background = input.value;
                else if (property === 'textColor')  ae.style.color = input.value;
            }
        });

        input.addEventListener('change', () => {
            if (applySelectionMode && state.selectionRange) {
                applyDragColor(input.value);
                state.perSelectionApplied = true;
                const pi = getActiveAnnAndPage()?.pi ?? 0;
                redrawOverlay(pi);
                syncActiveEditor(true);
                markDirty();
                clearPreview();
                return;
            }
            // If a per-selection drag already applied colors during this
            // picker interaction (and the range was cleared by an earlier
            // blur), skip the strip-and-recolor fallback so we don't wipe
            // the spans we just painted onto the selection.
            if (state.perSelectionApplied) {
                clearPreview();
                return;
            }
            const opts = (property === 'textColor') ? { stripInlineProps: ['color'] }
                       : (property === 'backgroundColor') ? { stripInlineProps: ['background-color', 'background'] }
                       : undefined;
            applyFormatProperty((ann) => {
                if (property === 'backgroundColor') {
                    ann.backgroundColorExplicit = true;
                    ann.backgroundColor = normalizeTextBackgroundColorValue(input.value, true);
                    return;
                }
                ann[property] = input.value;
            }, opts);
            clearPreview();
        });

        input.addEventListener('blur', () => {
            if (!state.active && !state.selectionRange && !state.perSelectionApplied) return;
            // Per-selection drags: don't clear selectionRange yet — `change`
            // may still fire after blur in some browsers and needs the range
            // to stay on the per-selection branch. Only clear the transient
            // ann-level CSS preview.
            const active = getActiveAnnAndPage();
            if (active) {
                const ae = document.getElementById('ae-' + (active.pi + 1));
                if (ae && !state.perSelectionApplied) {
                    if (property === 'backgroundColor') ae.style.background = '';
                    else if (property === 'textColor') ae.style.color = '';
                    syncActiveEditor(true);
                }
            }
            if (!state.perSelectionApplied) clearPreview();
        });
    }

    setupColorPicker(afbTextColor, 'textColor', { applySelectionMode: true });
    setupColorPicker(afbBgColor, 'backgroundColor');
    if (afbOpacity) {
        afbOpacity.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.opacity = parseFloat(afbOpacity.value); });
        });
    }
    if (afbBold) {
        afbBold.addEventListener('click', () => {
            if (applySelectionFormat('bold')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isBold = String(active.ann.fontWeight || '400') === '700';
            pushUndo();
            active.ann.fontWeight = isBold ? '400' : '700';
            active.ann._styleDirty = true;
            // Mark as user-authored so the style change survives reload: the
            // persisted userAuthored/promotedDirty flag switches rendering to
            // the edited-reflow path, which honors ann-level font weight/style.
            markUserAuthored(active.ann);
            afbBold.setAttribute('aria-pressed', String(!isBold));
            afbBold.classList.toggle('is-active', !isBold);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbItalic) {
        afbItalic.addEventListener('click', () => {
            if (applySelectionFormat('italic')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isItalic = (active.ann.fontStyle || 'normal') === 'italic';
            pushUndo();
            active.ann.fontStyle = isItalic ? 'normal' : 'italic';
            active.ann._styleDirty = true;
            markUserAuthored(active.ann);
            afbItalic.setAttribute('aria-pressed', String(!isItalic));
            afbItalic.classList.toggle('is-active', !isItalic);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbUnderline) {
        afbUnderline.addEventListener('click', () => {
            if (applySelectionFormat('underline')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isUnderline = Boolean(active.ann.underline);
            pushUndo();
            active.ann.underline = !isUnderline;
            active.ann._styleDirty = true;
            markUserAuthored(active.ann);
            afbUnderline.setAttribute('aria-pressed', String(!isUnderline));
            afbUnderline.classList.toggle('is-active', !isUnderline);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbAlign) {
        afbAlign.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.textAlign = afbAlign.value; });
        });
    }
    if (afbValign) {
        afbValign.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.verticalAlign = afbValign.value; });
        });
    }
    if (afbCopy) {
        afbCopy.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active) return;
            const { ann, pi, data } = active;
            pushUndo();
            const OFFSET = 12; // pts
            const newAnn = {
                ...ann,
                _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
                id:   generateAnnotationId(),
                pdfX: Math.min(ann.pdfX + OFFSET, Math.max(0, data.wPts - (ann.pdfWidth || 60))),
                pdfY: Math.max(ann.pdfY - OFFSET, 0),
                userCreated: true,
            };
            newAnn._originalBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            newAnn._originalPdfBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            editedTexts[newAnn._uid] = String(editedTexts[ann._uid] ?? ann.text ?? '');
            data.annotations.push(newAnn);
            selectAnnotation(newAnn, pi);
            markDirty();
        });
    }
    if (afbDelete) {
        afbDelete.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active) return;
            deleteAnnotation(active.ann, active.pi);
        });
    }

    // ── Main ──────────────────────────────────────────────────────────────────
    function annotationSourceRect(ann) {
        const left = Number(ann?.sourceBlockLeft ?? ann?.pdfX);
        const top = Number(ann?.sourceBlockTop);
        const width = Number(ann?.sourceBlockWidth ?? ann?.pdfWidth);
        const height = Number(ann?.sourceBlockHeight ?? ann?.pdfHeight);
        if (![left, width, height].every(Number.isFinite) || width <= 0 || height <= 0) return null;
        const resolvedTop = Number.isFinite(top)
            ? top
            : (Number(ann?.sourcePageHeight) - (Number(ann?.pdfY) + height));
        if (!Number.isFinite(resolvedTop)) return null;
        return {
            left,
            top: resolvedTop,
            right: left + width,
            bottom: resolvedTop + height,
            width,
            height,
            centerX: left + (width / 2),
        };
    }

    function isDecorativeCalloutSymbolAnnotation(ann) {
        const text = String(ann?.text || '').replace(/\s+/g, '');
        const rect = annotationSourceRect(ann);
        return text === '!' && rect && rect.width <= 42 && rect.height <= 60;
    }

    function isDecorativeCalloutCaptionAnnotation(ann) {
        const text = String(ann?.text || '').trim();
        const rect = annotationSourceRect(ann);
        return /^[A-Z][A-Z0-9/& -]{2,14}$/.test(text) && rect && rect.width <= 80 && rect.height <= 20;
    }

    function isPreservedDecorativeCalloutTextAnnotation(ann, pageAnnotations) {
        const isSymbol = isDecorativeCalloutSymbolAnnotation(ann);
        const isCaption = isDecorativeCalloutCaptionAnnotation(ann);
        if (!isSymbol && !isCaption) return false;

        const rect = annotationSourceRect(ann);
        if (!rect) return false;

        return (pageAnnotations || []).some((other) => {
            if (!other || other === ann) return false;
            const otherIsSymbol = isDecorativeCalloutSymbolAnnotation(other);
            const otherIsCaption = isDecorativeCalloutCaptionAnnotation(other);
            if (isSymbol === otherIsSymbol || isCaption === otherIsCaption) return false;
            const otherRect = annotationSourceRect(other);
            if (!otherRect) return false;

            const horizontalOverlap = Math.max(0, Math.min(rect.right, otherRect.right) - Math.max(rect.left, otherRect.left));
            const overlapRatio = horizontalOverlap / Math.max(1, Math.min(rect.width, otherRect.width));
            const sameColumn = overlapRatio >= 0.55 || Math.abs(rect.centerX - otherRect.centerX) <= 12;
            const verticalGap = Math.max(0, Math.max(otherRect.top - rect.bottom, rect.top - otherRect.bottom));
            return sameColumn && verticalGap <= 12;
        });
    }


    // ---- Account-scoped saved signatures (NK_Dev_4) ---------------------
    const refreshAccountLibraryUi = () => renderAccountLibrary(accountLibrary, {
        listEl: signatureAccountList,
        copyEl: signatureAccountCopy,
    });

    async function loadAccountLibrary() {
        if (!accountSignaturesUrl) return;
        if (!accountSignaturesEnabled()) {
            // Guest: there is no account to query, so skip the request that
            // would only 401 and log a console error. The list still renders
            // its sign-in prompt.
            accountLibrary.signedIn = false;
            accountLibrary.loaded = true;
            refreshAccountLibraryUi();
            return;
        }
        try {
            await fetchAccountSignatures(accountSignaturesUrl, accountLibrary);
        } catch (_) {
            // A failed fetch just leaves the list empty; never block the modal.
        }
        refreshAccountLibraryUi();
    }

    async function saveSignatureToAccount() {
        if (!signatureDirty) {
            setSignatureStatus('Create a signature before saving it.', 'error');
            return;
        }
        if (!accountSignaturesEnabled()) {
            setSignatureStatus('Sign in to save signatures to your account.', 'error');
            return;
        }

        const asset = buildCurrentSignatureAsset();
        if (!asset?.dataUrl) {
            setSignatureStatus('Create a signature before saving it.', 'error');
            return;
        }

        setSignatureStatus('Saving signature to your account…');
        const result = await saveAccountSignature(
            accountSignaturesUrl,
            accountLibrary,
            asset,
            // The name field went away with the browser library; the server
            // assigns "Signature N" from the account's own count.
            '',
        );

        if (!result.ok) {
            setSignatureStatus(result.message, 'error');
            refreshAccountLibraryUi();
            return;
        }

        refreshAccountLibraryUi();
        setSignatureStatus(`Saved "${result.signature.name}" to your account.`, 'ready');
    }

    async function loadAccountSignature(entryId) {
        const entry = accountLibrary.entries.find((item) => String(item.id) === String(entryId));
        if (!entry) return;

        resetSignatureComposer();
        setSignatureEditTarget(null);
        updateSignatureModalCopy();
        try {
            await loadSignatureComposerFromAnnotation(accountEntryAsAnnotation(entry));
            setSignatureStatus(`Loaded "${entry.name}" from your account.`, 'ready');
        } catch (error) {
            setSignatureStatus(error?.message || 'Failed to load that signature.', 'error');
            setSignatureDirtyState(false);
        }
    }

    async function removeAccountSignature(entryId) {
        const result = await deleteAccountSignature(accountSignaturesUrl, accountLibrary, entryId);
        refreshAccountLibraryUi();
        setSignatureStatus(
            result.ok ? 'Signature removed from your account.' : result.message,
            result.ok ? 'default' : 'error',
        );
    }

    if (signatureAccountSaveBtn) {
        signatureAccountSaveBtn.title = accountSignaturesEnabled()
            ? 'Save this signature to your account'
            : 'Sign in to save signatures to your account';
        signatureAccountSaveBtn.addEventListener('click', () => { void saveSignatureToAccount(); });
    }
    if (signatureAccountList) {
        signatureAccountList.addEventListener('click', async (event) => {
            const button = event.target instanceof HTMLElement
                ? event.target.closest('[data-account-signature-action]')
                : null;
            if (!(button instanceof HTMLElement)) return;
            const row = button.closest('[data-account-signature-id]');
            const entryId = String(row?.getAttribute('data-account-signature-id') || '');
            if (!entryId) return;
            if (button.dataset.accountSignatureAction === 'load') await loadAccountSignature(entryId);
            else await removeAccountSignature(entryId);
        });
    }

    async function run() {
        let data;
        updateEditModeUi();
        void loadAccountLibrary();
        updateZoom(currentZoomPercent);
        try {
            // Lazy load: fetch only page 1 annotations on the initial request
            // so the editor paints the first page quickly. Remaining pages are
            // backfilled via a second request after the initial render below.
            data = await fetchDocumentInfo(getSessionId(), { page: 1 });
        } catch (e) { showError(String(e)); return; }

        const embeddedFontsBySource = data.embedded_fonts_by_source && typeof data.embedded_fonts_by_source === 'object'
            ? data.embedded_fonts_by_source
            : null;
        const preferredEmbeddedFonts = (embeddedFontsBySource?.clean && typeof embeddedFontsBySource.clean === 'object')
            ? embeddedFontsBySource.clean
            : (embeddedFontsBySource?.file && typeof embeddedFontsBySource.file === 'object')
                ? embeddedFontsBySource.file
                : ((data.embedded_fonts && typeof data.embedded_fonts === 'object') ? data.embedded_fonts : null);
        loadEmbeddedFontFaces(preferredEmbeddedFonts);
        populateFontDropdown();

        setAcroFormEntries(Array.isArray(data.acro_form_entries) ? data.acro_form_entries : []);
        setAcroFieldLookup(buildAcroFieldLookup(acroFormEntries));
        setAcroWidgetsByPage({});

        // Resolve the span-origin-based box (sourceBlockLeft/Top) as the "original" reference
        // so annotationOffset() correctly reflects the drag displacement even after a save+reload,
        // when pdfX/pdfY already contain the moved position.
        const resolveSourceBox = (ann) => {
            const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
            const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
            const pageH = Number(ann.sourcePageHeight);
            if ([sL, sT, sW, sH, pageH].every(Number.isFinite) && sW > 0 && sH > 0)
                return { x: sL, y: pageH - (sT + sH), w: sW, h: sH };
            return resolveAnnBox(ann);
        };

        const rawAnns = (data.annotations || []).filter(a => a && a.db_state !== 'deleted');
        const hydrateRawAnnotations = (raws, uidSalt = '') => {
        let allAnnotations = raws.map((ann, i) => {
            const hydrated = { ...ann, _uid: String(ann.id || ann.db_id || '') + '_' + uidSalt + i };
            hydrated.locked = Boolean(
                hydrated.locked
                || (hydrated.annotation_data && hydrated.annotation_data.locked)
            );
            if (isShapeAnnotation(hydrated)) normalizeShapeAnnotation(hydrated);
            if (isImageBackedAnnotation(hydrated)) normalizeImageAnnotation(hydrated);
            if (String(hydrated.type || '').toLowerCase() === 'text') normalizeTextAnnotation(hydrated);
            if (
                String(hydrated.type || 'text').toLowerCase() === 'text'
                && String(hydrated.text ?? '').trim() === ''
                && (
                    (Array.isArray(hydrated.sourceSpans) && hydrated.sourceSpans.length > 0)
                    || (Array.isArray(hydrated.sourceLineBBoxes) && hydrated.sourceLineBBoxes.length > 0)
                )
            ) {
                const sourceTextFallback = sourceTextFallbackFromAnnotation(hydrated);
                if (sourceTextFallback) {
                    hydrated.text = sourceTextFallback;
                    if (String(hydrated.originalText ?? '').trim() === '') {
                        hydrated.originalText = sourceTextFallback;
                    }
                    if (hydrated.annotation_data && typeof hydrated.annotation_data === 'object') {
                        hydrated.annotation_data.text = sourceTextFallback;
                    }
                }
            }
            hydrated._originalBox = resolveSourceBox(hydrated) || resolveAnnBox(hydrated) || null;
            // Pick the "original" PDF box used by annotationDimensionsChanged:
            //  - If the stored pdfW/H matches the sourceBlock dims within a few pts,
            //    the annotation was never resized — use the stored box so
            //    dimensionsChanged stays false (avoids false-positive forcing the
            //    plain-text editor render for promoted annotations).
            //  - Otherwise the user resized it in a prior session — use the sourceBlock
            //    box so dimensionsChanged returns true and drawEditedAnnotation takes
            //    the resized render path instead of falling back to drawOriginalSource
            //    (which would ignore the saved pdfWidth/pdfHeight and re-render the
            //    original wide PDF layout).
            const storedBox = resolveAnnBox(hydrated);
            const sourceBox = resolveSourceBox(hydrated);
            const TOL = 2;
            const roughlyEqual = storedBox && sourceBox
                && Math.abs(storedBox.w - sourceBox.w) <= TOL
                && Math.abs(storedBox.h - sourceBox.h) <= TOL;
            hydrated._originalPdfBox = roughlyEqual ? storedBox : (sourceBox || storedBox || null);
            // Restore user-authored flag from any persisted signal (new `userAuthored`,
            // legacy `promotedDirty`, or a previously-resized box). Once set, the
            // annotation is rendered via the reflow path instead of drawOriginalSource.
            const adUserAuthored = !!(hydrated.annotation_data && hydrated.annotation_data.userAuthored);
            hydrated._userAuthored = !!(hydrated.userAuthored || adUserAuthored || hydrated.promotedDirty || !roughlyEqual);
            hydrated._styleDirty = !!(
                hydrated.styleDirty
                || (hydrated.annotation_data && hydrated.annotation_data.styleDirty)
            );
            hydrated._sourceExactTextEdited = !!(
                hydrated.sourceExactTextEdited
                || hydrated.preserveSourceLayout
                || (hydrated.annotation_data && (
                    hydrated.annotation_data.sourceExactTextEdited
                    || hydrated.annotation_data.preserveSourceLayout
                ))
            );
            // Restore per-selection rich HTML so bold/italic/font spans survive
            // a reload and the rich-html-layer / active editor render them.
            // Modern saves emit `richTextHtml` at the top level; older payloads
            // wrote `richHtml` (top-level or under annotation_data) — we still
            // read those for backwards compatibility.
            const savedRichHtml = (typeof hydrated.richTextHtml === 'string' && hydrated.richTextHtml.length > 0)
                ? hydrated.richTextHtml
                : (typeof hydrated.richHtml === 'string' && hydrated.richHtml.length > 0
                    ? hydrated.richHtml
                    : (hydrated.annotation_data && typeof hydrated.annotation_data.richTextHtml === 'string'
                        && hydrated.annotation_data.richTextHtml.length > 0
                        ? hydrated.annotation_data.richTextHtml
                        : (hydrated.annotation_data && typeof hydrated.annotation_data.richHtml === 'string'
                            && hydrated.annotation_data.richHtml.length > 0
                            ? hydrated.annotation_data.richHtml
                            : null)));
            if (savedRichHtml && richHtmlHasInlineSelectionFormatting(savedRichHtml)) hydrated._richHtml = savedRichHtml;
            // Heal stale source-exact layout flags on hydration: if the
            // persisted `text` no longer matches what the per-span source
            // layout would render (i.e. concatenating sourceSpans' text),
            // the flags lie. Painting from those spans (drawOriginalSource)
            // would render the ORIGINAL extracted text on top of the user's
            // edited replacement -- the "deleted text comes back" bug from
            // doc 4001 promoted_1_22_59 / promoted_1_9_38. This corrects
            // payloads saved before flushActiveEditorState learned to
            // clear the flags itself.
            (() => {
                const flagsSet = !!(
                    hydrated.preserveSourceLayout
                    || hydrated.sourceExactTextEdited
                    || hydrated._sourceExactTextEdited
                );
                if (!flagsSet) return;
                const spans = Array.isArray(hydrated.sourceSpans) ? hydrated.sourceSpans : [];
                if (!spans.length) return;
                const norm = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
                const spanText = norm(spans.map((sp) => String(sp?.text ?? sp?.render_text ?? '')).join(' '));
                const persistedText = norm(hydrated.text);
                if (!spanText || !persistedText) return;
                if (spanText === persistedText) return;
                // Texts diverge -- the per-span layout no longer represents
                // the saved annotation. Drop the flags so the renderer falls
                // through to the edited-text reflow path.
                hydrated.preserveSourceLayout = false;
                hydrated.sourceExactTextEdited = false;
                hydrated._sourceExactTextEdited = false;
                if (hydrated.annotation_data && typeof hydrated.annotation_data === 'object') {
                    hydrated.annotation_data.preserveSourceLayout = false;
                    hydrated.annotation_data.sourceExactTextEdited = false;
                }
                hydrated._userAuthored = true;
                hydrated.promotedDirty = true;
                if (hydrated.annotation_data && typeof hydrated.annotation_data === 'object') {
                    hydrated.annotation_data.userAuthored = true;
                    hydrated.annotation_data.promotedDirty = true;
                }
                // Also drop any persisted rich-html: the rich-html-layer
                // uses _richHtml verbatim (renderRichHtmlLayer line ~2261)
                // when set, ignoring ann.text. If the saved richTextHtml
                // still mirrors the original extracted text (because it
                // was captured before flushActiveEditorState learned to
                // sync), the DOM layer paints the OLD text on top of the
                // PDF and the user's actual edit (ann.text) never appears.
                // Strip rich-html on this divergence so the layer falls
                // through to the `editedTexts[uid] ?? ann.text` branch.
                hydrated._richHtml = null;
                if (hydrated.annotation_data && typeof hydrated.annotation_data === 'object') {
                    hydrated.annotation_data.richTextHtml = null;
                    hydrated.annotation_data.richHtml = null;
                }
                hydrated.richTextHtml = null;
                hydrated.richHtml = null;
            })();
            // Heal bloated pdfHeight on promoted-extraction annotations whose
            // text still matches the original source. This corrects already-
            // persisted bad geometry left over from prior buggy auto-grow paths
            // so the next save writes the corrected value back.
            normalizePromotedAnnotationGeometry(hydrated);
            return hydrated;
        });
        const annotationsForCalloutFilter = allAnnotations.slice();
        allAnnotations = allAnnotations.filter((ann) => (
            !isPreservedDecorativeCalloutTextAnnotation(
                ann,
                annotationsForCalloutFilter.filter((other) => Number(other?.pageIndex) === Number(ann?.pageIndex))
            )
        ));

        // Runtime safety net for duplicate promoted annotations that the
        // server-side dedup pass missed. Drop a promoted annotation when
        // every one of its source line bboxes is highly overlapped (>= 70%
        // by smaller area) by a distinct source line bbox of another
        // (larger / multi-line) promoted annotation on the same page.
        // Keeps the larger one — its text already contains the smaller's
        // content as part of a multi-line block, so the smaller would
        // double-paint on top.
        (() => {
            const byPg = {};
            allAnnotations.forEach((a, idx) => {
                if (!a?.promotedFromExtraction || a?.promotedDirty || a?.promotedReflowEnabled) return;
                const lb = Array.isArray(a.sourceLineBBoxes) ? a.sourceLineBBoxes : null;
                if (!lb || !lb.length) return;
                const pi = Number(a.pageIndex) || 0;
                (byPg[pi] = byPg[pi] || []).push({ idx, lineBBoxes: lb });
            });
            const drop = new Set();
            const overlapsDistinct = (childBoxes, parentBoxes) => {
                if (!childBoxes.length || !parentBoxes.length) return false;
                const used = new Set();
                for (const cb of childBoxes) {
                    if (!Array.isArray(cb) || cb.length < 4) return false;
                    const ca = Math.max(1, (cb[2] - cb[0]) * (cb[3] - cb[1]));
                    let matched = -1;
                    for (let i = 0; i < parentBoxes.length; i++) {
                        if (used.has(i)) continue;
                        const pb = parentBoxes[i];
                        if (!Array.isArray(pb) || pb.length < 4) continue;
                        const w = Math.max(0, Math.min(cb[2], pb[2]) - Math.max(cb[0], pb[0]));
                        const h = Math.max(0, Math.min(cb[3], pb[3]) - Math.max(cb[1], pb[1]));
                        if (w <= 0 || h <= 0) continue;
                        const pa = Math.max(1, (pb[2] - pb[0]) * (pb[3] - pb[1]));
                        if ((w * h) / Math.min(ca, pa) >= 0.70) { matched = i; break; }
                    }
                    if (matched < 0) return false;
                    used.add(matched);
                }
                return true;
            };
            Object.values(byPg).forEach((entries) => {
                for (let i = 0; i < entries.length; i++) {
                    if (drop.has(entries[i].idx)) continue;
                    for (let j = 0; j < entries.length; j++) {
                        if (i === j || drop.has(entries[j].idx)) continue;
                        // Drop entries[i] if its lines are fully covered by
                        // entries[j]'s lines AND j has strictly more lines.
                        if (entries[j].lineBBoxes.length <= entries[i].lineBBoxes.length) continue;
                        if (overlapsDistinct(entries[i].lineBBoxes, entries[j].lineBBoxes)) {
                            drop.add(entries[i].idx);
                            break;
                        }
                    }
                }
            });
            if (drop.size) {
                allAnnotations = allAnnotations.filter((_, idx) => !drop.has(idx));
            }
        })();

        allAnnotations.forEach(ann => { editedTexts[ann._uid] = String(ann.text || ''); });
        return allAnnotations;
        };
        let allAnnotations = hydrateRawAnnotations(rawAnns, '');

        const byPage = {};
        allAnnotations.forEach(ann => {
            const pi = Number(ann.pageIndex) || 0;
            if (!byPage[pi]) byPage[pi] = [];
            byPage[pi].push(ann);
        });

        // Re-sort each page so the editor canvas draw order matches the PDF
        // export's draw order (annotation_layer_order in
        // apply_annotations_direct_new.py). Layers (low → high):
        //   0 shapes / tables / erasers
        //   1 direct-draw (marker / pen)
        //   2 text annotations
        //   3 signatures and regular images
        // Stable sort preserves intra-layer order so the user's manual
        // "Bring to front" / "Send to back" within a layer is respected.
        // This also fixes annotations that were saved with an older ordering
        // rule (e.g. an early version that unshifted direct-draw to position
        // 0, putting marker strokes underneath user-drawn shapes).
        const annotationLayerKey = (ann) => {
            if (isShapeAnnotation(ann)) return 0;
            if (isImageBackedAnnotation(ann)) {
                return String(ann?.imageToolSource || '') === 'direct-draw' ? 1 : 3;
            }
            return 2; // text
        };
        Object.keys(byPage).forEach((pi) => {
            const arr = byPage[pi];
            const decorated = arr.map((ann, idx) => ({ ann, idx, key: annotationLayerKey(ann) }));
            decorated.sort((a, b) => (a.key - b.key) || (a.idx - b.idx));
            byPage[pi] = decorated.map((entry) => entry.ann);
        });

        try {
            if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js not loaded.');
            // Render the unmodified ORIGINAL PDF as the base (matches the
            // /edit-pdfjs spike approach). Edits flow through the surgical
            // /Tj rewriter (rewrite_tj_inplace.py) — no baking step, so the
            // canvas IS the source of truth and overlays draw on top.
            // Falls back to baked → clean → file only if original_url is
            // missing (legacy documents predating the original-backup field).
            const candidateUrls = [data.document.original_url, data.document.baked_url, data.document.clean_url, data.document.file_url]
                .filter((u) => typeof u === 'string' && u.length > 0);
            let lastErr = null;
            let loadedUrl = null;
            for (const url of candidateUrls) {
                try {
                    setPdfDoc(await pdfjsLib.getDocument(url).promise);
                    lastErr = null;
                    loadedUrl = url;
                    break;
                } catch (err) {
                    lastErr = err;
                    setPdfDoc(null);
                }
            }
            if (!_pdfDoc) throw lastErr || new Error('No PDF source URL succeeded.');
            // Suppress canvas redraw of promoted source spans whenever the
            // loaded PDF already contains them (original or baked). The
            // file_url / clean_url variants do NOT contain them, so the
            // overlay must repaint as before.
            window.__edn_useBakedBase = (
                loadedUrl === data.document.original_url
                || loadedUrl === data.document.baked_url
            );
        } catch (e) { showError(String(e)); return; }

        // Phase 2 scaffolding (no-op unless ?phase2=1 is in the URL).
        try {
            setPhase2PdfDocument(_pdfDoc);
            installPhase2Hotkeys();
        } catch (e) {
            console.warn('[phase2] init failed', e);
        }

        // Debug DOM overlay opt-in (?dbgdom=1) — see renderDebugDomLayer.
        // Toggle in console: document.body.classList.toggle('debug-dom-on')
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.has('dbgdom')) {
                document.body.classList.add('debug-dom-on');
                console.info('[dbgdom] Debug DOM overlay ENABLED. Toggle: document.body.classList.toggle("debug-dom-on")');
            }
        } catch (_e) {}

        const pageCount = _pdfDoc.numPages;
        for (let pg = 1; pg <= pageCount; pg++) createPageCard(pg, pageCount);
        updatePageControls(pageCount);

        for (let pg = 1; pg <= pageCount; pg++) {
            const pi  = pg - 1;
            const pageCanvas = document.getElementById('en-canvas-' + pg);

            const pdfPage = await _pdfDoc.getPage(pg);
            const vp1     = pdfPage.getViewport({ scale: 1 });
            const wPts    = vp1.width;
            const hPts    = vp1.height;

            // Render PDF page directly into the visible page canvas at 2×
            // backing for sharpness at default zoom. CSS sizes it to
            // .page-content's box. When the user zooms in past the point
            // where this 2× backing becomes blurry, rerenderPageCanvas()
            // re-rasterizes at a higher scale.
            const renderScale = 2;
            if (pageCanvas) {
                pageCanvas.width  = Math.round(wPts * renderScale);
                pageCanvas.height = Math.round(hPts * renderScale);
                pageCanvas.__renderScale = renderScale;
                await pdfPage.render({
                    canvasContext: pageCanvas.getContext('2d'),
                    viewport: pdfPage.getViewport({ scale: renderScale }),
                    annotationMode: typeof pdfjsLib?.AnnotationMode?.DISABLE === 'number'
                        ? pdfjsLib.AnnotationMode.DISABLE
                        : 0,
                }).promise.catch(() => {});
                // Stage 3: capture the pristine raster immediately after the
                // first render so applyCommittedRasterPatches can later
                // restore-and-patch when annotations transition to
                // _committed. pageData[pi] isn't populated yet at this
                // point — setupPage assigns it below — so the first patch
                // pass happens lazily from redrawOverlay.
                capturePristineRasterSnapshot(pageCanvas);
            }

            // Stash the PDFPageProxy + intrinsic size so the zoom-rerender
            // path can re-rasterize this page at higher resolution without
            // re-fetching it. setupPage() reassigns pageData[pi] further
            // down, so we attach pdfPage after that call below.

            // Mount PDF.js TextLayer over the canvas so the user can natively
            // select PDF text (like Acrobat / Firefox PDF viewer). The layer
            // sits above overlay-canvas; container has pointer-events:none so
            // empty space falls through to annotation hit-testing, while text
            // <span>s have pointer-events:auto so the user can drag-select.
            const tlDiv = document.getElementById('tl-' + pg);
            if (tlDiv && typeof pdfjsLib?.TextLayer === 'function') {
                try {
                    tlDiv.replaceChildren();
                    const fitScaleForTl = getFitScaleForWidth(wPts);
                    const tlViewport = pdfPage.getViewport({ scale: fitScaleForTl });
                    const textLayer = new pdfjsLib.TextLayer({
                        textContentSource: pdfPage.streamTextContent({
                            includeMarkedContent: true,
                            disableNormalization: true,
                        }),
                        container: tlDiv,
                        viewport: tlViewport,
                    });
                    await textLayer.render();
                } catch (e) {
                    console.warn('[textLayer] render failed for page ' + pg, e);
                }
            }

            // Phase 2 of the PDF.js v4 migration: mount the real
            // AnnotationEditorLayer alongside the TextLayer when the
            // ?phase2=1 flag is set. Inert no-op otherwise.
            if (isPhase2Enabled()) {
                try {
                    const fitScaleForAel = getFitScaleForWidth(wPts);
                    const aelViewport = pdfPage.getViewport({ scale: fitScaleForAel });
                    await setupPhase2Page(pi, pdfPage, aelViewport);
                } catch (e) {
                    console.warn('[phase2] setupPhase2Page failed for page ' + pg, e);
                }
            }

            const acroWidgets = await ensureAcroWidgetsForPage(pg, data.document).catch(() => []);
            setupPage(pg, wPts, hPts, byPage[pi] || [], acroWidgets);
            // Stash PDFPageProxy for the zoom-rerender path. Must run AFTER
            // setupPage because it reassigns pageData[pi].
            if (pageData[pi]) pageData[pi].pdfPage = pdfPage;
            redrawOverlay(pi);
            drawAcroOverlay(pi);
        }

        // CSS @font-face fonts are lazy-loaded — only triggered when first used.
        // Explicitly load each font face so canvas ctx.measureText() uses real metrics.
        // Then redraw all overlays so span scaleX calculations are accurate.
        if (document.fonts?.load) {
            const fontLoadPromises = [];
            if (preferredEmbeddedFonts) {
                for (const [, fontData] of Object.entries(preferredEmbeddedFonts)) {
                    const cleanName = String(fontData?.clean_name || '').trim();
                    const family    = String(fontData?.family || '').trim();
                    const weight    = String(fontData?.css_weight || '400');
                    const style     = String(fontData?.css_style || 'normal');
                    const sizePx    = '16px'; // arbitrary size — triggers download
                    if (cleanName) fontLoadPromises.push(document.fonts.load(`${style} ${weight} ${sizePx} PDF_${cleanName}`).catch(() => {}));
                    if (family && family !== cleanName) fontLoadPromises.push(document.fonts.load(`${style} ${weight} ${sizePx} PDF_${family}`).catch(() => {}));
                }
            }
            STATIC_PDF_FONT_FACES.forEach((face) => {
                const sizePx    = '16px'; // arbitrary size — triggers download
                fontLoadPromises.push(document.fonts.load(`${face.style} ${face.weight} ${sizePx} '${face.family}'`).catch(() => {}));
            });
            if (fontLoadPromises.length) {
                try { await Promise.all(fontLoadPromises); } catch (_) {}
            }
            for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
        }

        markClean();
        syncCurrentPageFromScroll();

        // Backfill annotations for the remaining pages in the background.
        // The initial fetch above intentionally restricted the response to
        // page 1 so the editor paints quickly. Now that the user can see
        // page 1, fetch the rest, hydrate them through the same pipeline,
        // and merge them into the per-page state. Any failure here just
        // leaves the lazy-loaded behaviour as-is for those pages — the user
        // can refresh to retry.
        const scheduleIdle = (cb) => {
            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(cb, { timeout: 500 });
            } else {
                setTimeout(cb, 0);
            }
        };
        scheduleIdle(async () => {
            try {
                const restData = await fetchDocumentInfo(getSessionId(), {
                    pagesExclude: [1],
                    skipMeta: true,
                });
                const restRaw = (restData.annotations || [])
                    .filter(a => a && a.db_state !== 'deleted');
                if (!restRaw.length) return;
                const backfilled = hydrateRawAnnotations(restRaw, 'b1_');
                if (!backfilled.length) return;

                const touchedPages = new Set();
                backfilled.forEach((ann) => {
                    const pi = Number(ann.pageIndex) || 0;
                    if (!pageData[pi]) return; // page not mounted (PDF doc didn't expose it)
                    if (!Array.isArray(pageData[pi].annotations)) pageData[pi].annotations = [];
                    pageData[pi].annotations.push(ann);
                    touchedPages.add(pi);
                });

                // Re-sort each touched page using the same layer-order rule
                // that run() applied to the initial batch. Stable sort keeps
                // intra-layer ordering predictable.
                const layerKey = (ann) => {
                    if (isShapeAnnotation(ann)) return 0;
                    if (isImageBackedAnnotation(ann)) {
                        return String(ann?.imageToolSource || '') === 'direct-draw' ? 1 : 3;
                    }
                    return 2;
                };
                touchedPages.forEach((pi) => {
                    const arr = pageData[pi].annotations;
                    const decorated = arr.map((ann, idx) => ({ ann, idx, key: layerKey(ann) }));
                    decorated.sort((a, b) => (a.key - b.key) || (a.idx - b.idx));
                    pageData[pi].annotations = decorated.map((entry) => entry.ann);
                    redrawOverlay(pi);
                });
            } catch (e) {
                console.warn('[edit-new] backfill annotations failed', e);
            }
        });
    }

    run();
})();
