/*
 * /edit-new?pdfjs=1 — signature feature port.
 *
 * Mirrors the legacy /edit-new sign affordance (Draw/Type/Upload modal,
 * saved-signature library, click-to-place, drag/lock/delete, edit by
 * re-opening the modal, persistence via /save-annotation-state). Built
 * as a single self-contained installer that hooks into the pdf.js
 * viewer's existing annotation overlay system.
 *
 * Reuses the legacy signature/* helpers and signature store so the UI
 * behaves identically. Rendering is plain DOM (`<img>` inside an
 * `enpv-annotation-box`) overlaid on the pdf.js page div, matching the
 * pdfjs viewer's text-annotation rendering pattern.
 */

import {
    normalizeSignatureSourceMode,
    signatureModeLabel,
} from '../edit-new/annotations/types.js';
import { generateAnnotationId } from '../edit-new/annotations/id.js';
import { normalizeHexColor } from '../edit-new/util/color.js';
import { cloneSerializableValue } from '../edit-new/util/clone.js';
import { loadImageElement } from '../edit-new/util/image-load.js';
import { normalizeImportedImageAsset } from '../edit-new/images/normalize-imported.js';
import { setSignatureStatus as _setSignatureStatus } from '../edit-new/signature/status.js';
import {
    clearSignatureCanvas as _clearSignatureCanvas,
    clearSignatureDrawingState as _clearSignatureDrawingState,
    hasSignatureDrawContent,
    renderDrawSignaturePreview as _renderDrawSignaturePreview,
} from '../edit-new/signature/canvas.js';
import {
    syncSignatureColorLabels as _syncSignatureColorLabels,
    updateSignatureModalCopy as _updateSignatureModalCopy,
} from '../edit-new/signature/labels.js';
import { closeSignatureModal as _closeSignatureModal } from '../edit-new/signature/modal.js';
import { cancelSignaturePlacement as _cancelSignaturePlacement } from '../edit-new/signature/placement.js';
import { ensureSignatureFontLoaded } from '../edit-new/signature/font-loader.js';
import {
    setSavedViewActive,
    paintSignatureTabs,
    installSignatureTabKeyboard,
} from '../edit-new/signature/saved-view.js';
import {
    canvasBackdropForInk,
    readSignaturePreferences,
    writeSignaturePreferences,
} from '../edit-new/signature/preferences.js';
import {
    createAccountLibraryStore,
    fetchAccountSignatures,
    saveAccountSignature,
    renameAccountSignature,
    deleteAccountSignature,
    renderAccountLibrary,
    accountEntryAsAnnotation,
} from '../edit-new/signature/account-library.js';
import {
    applyStampFrameStyle,
    stampFrameStyle,
    pdfRectToViewportRect,
    viewportPdfDimensions,
    viewportPointToPdfPoint,
} from './page-geometry.js';
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
} from '../edit-new/store/signature-state.js';

/**
 * Points of PDF page width per composer-canvas pixel.
 *
 * The composer canvas is 900px wide and represents roughly a third of a Letter
 * page (612pt * 0.34 = 208pt), so 208/900 keeps placed signatures at a natural
 * size while making the font-size slider actually change how big the signature
 * lands.
 */
const CANVAS_PX_TO_PDF_PTS = 208 / 900;

/** Placement caps and floors, in PDF points / page fractions. */
const PLACEMENT_MAX_WIDTH_FRACTION = 0.34;
const PLACEMENT_MAX_HEIGHT_FRACTION = 0.18;
const PLACEMENT_MIN_WIDTH_PTS = 24;
const PLACEMENT_MIN_HEIGHT_PTS = 8;

export function isSignatureAnnotation(annotation) {
    return !!annotation && String(annotation.type || '').toLowerCase() === 'signature';
}

function rawCanvasPointFromEvent(canvas, event) {
    if (!canvas || !event) return null;
    const rect = canvas.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (event.clientX - rect.left) * scaleX,
        y: (event.clientY - rect.top) * scaleY,
    };
}

export function installSignatureFeature(deps) {
    const {
        pdfViewer,
        container,
        upsertPersistedAnnotation,
        deletePersistedAnnotation,
        rebuildPageMap,
        pushHistorySnapshot,
        markManualSaveNeeded,
        setStatus,
        renderAnnotationBoxLayer,
        renderAllAnnotationBoxLayers,
        isEditModeOn,
        getPersistedAnnotation,
        selectAnnBox,
        annMenu,
    } = deps;

    // ---- DOM refs (legacy modal markup, reused as-is) -------------------
    const signatureModal = document.getElementById('signature-modal');
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
    const signatureStatusEl = document.getElementById('signature-status');
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
    const signatureAccountSearch = document.getElementById('signature-account-search');
    const signatureAccountViewButtons = Array.from(document.querySelectorAll('[data-signature-view-mode]'));
    const accountLibrary = createAccountLibraryStore();
    // NK_Dev_5: remembered tab + control settings, per browser.
    let signaturePrefs = readSignaturePreferences();
    accountLibrary.viewMode = signaturePrefs.accountViewMode;
    const persistPrefs = (patch) => { signaturePrefs = writeSignaturePreferences(signaturePrefs, patch); };
    const accountSignaturesUrl = signatureModal?.dataset.accountSignaturesUrl || '';
    // Read lazily rather than captured at install: the session can change
    // under a long-lived editor tab, and it keeps the flag honest.
    const accountSignaturesEnabled = () => signatureModal?.dataset.accountSignatures === '1';
    const ftbSign = document.getElementById('ftb-sign');

    if (signatureCanvas?.getContext) {
        setSignatureCtx(signatureCanvas.getContext('2d'));
    }

    // ---- Closure-bound shims over the legacy helpers --------------------
    const setSignatureStatus = (msg, tone) => _setSignatureStatus(signatureStatusEl, msg, tone);
    const setSignatureDirtyState = (dirty) => {
        setSignatureDirty(!!dirty);
        if (signatureApplyBtn) signatureApplyBtn.disabled = !signatureDirty;
        // The account save button follows the same dirty gate.
        if (signatureAccountSaveBtn) signatureAccountSaveBtn.disabled = !signatureDirty;
    };
    const clearSignatureCanvas = () => _clearSignatureCanvas(signatureCanvas);
    const clearSignatureDrawingState = () => _clearSignatureDrawingState(signatureCanvas);
    const renderDrawSignaturePreview = () => _renderDrawSignaturePreview(signatureCanvas, signatureSmoothingInput);
    /**
     * Tint the canvas so the ink always has something to read against.
     * Applied as CSS only — the bitmap stays transparent.
     */
    const syncCanvasBackdrop = () => {
        if (!signatureCanvas) return;
        const ink = signatureMode === 'type'
            ? signatureTypeColorInput?.value
            : signatureColorInput?.value;
        const backdrop = canvasBackdropForInk(ink);
        signatureCanvas.style.background = backdrop.background;
        signatureCanvas.dataset.inkTone = backdrop.tone;
    };

    const syncSignatureColorLabels = () => _syncSignatureColorLabels({
        signatureColorValue, signatureColorInput,
        signatureTypeColorValue, signatureTypeColorInput,
        signatureWidthValue, signatureWidthInput,
        signatureSmoothingValue, signatureSmoothingInput,
    });
    const updateSignatureModalCopy = () => _updateSignatureModalCopy({ signatureModalTitle, signatureModalSubtitle });
    const typedSignatureFontSize = () => Math.max(24, Math.min(240, Number(signatureTypeSizeInput?.value) || 136));
    const syncSignatureTypeSizeLabel = () => {
        if (signatureTypeSizeValue) signatureTypeSizeValue.textContent = `${Math.round(typedSignatureFontSize())}px`;
    };

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
            const verb = signatureEditTarget ? 'Update' : 'Use';
            signatureApplyBtn.textContent = signatureMode === 'upload'
                ? `${verb} uploaded signature`
                : (signatureMode === 'type' ? `${verb} typed signature` : `${verb} signature`);
        }
        if (signatureCanvas) signatureCanvas.style.cursor = signatureMode === 'draw' ? 'crosshair' : 'default';
        syncCanvasBackdrop();
        if (signatureMode === 'draw') {
            renderDrawSignaturePreview();
            setSignatureDirtyState(hasSignatureDrawContent());
            setSignatureStatus(
                hasSignatureDrawContent()
                    ? 'Signature ready to place.'
                    : 'Draw a signature, then place it on the page.',
                hasSignatureDrawContent() ? 'ready' : 'default',
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
        persistPrefs({ mode: signatureMode, savedView: false });
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
        persistPrefs({ savedView: !!active });
    }

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
        if (renderToken !== signatureTypedRenderToken
            || signatureMode !== 'type' || !signatureCanvas || !signatureCtx) return;
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

    /**
     * Clear the composer content and restore the remembered settings.
     *
     * NK_Dev_5: settings (tab, ink, stroke width, smoothing, font, size) now
     * persist between opens. The drawn/typed/uploaded CONTENT is still cleared
     * every time, so a previous signature is never silently reused.
     */
    function resetSignatureComposer() {
        setSignatureModeValue(signaturePrefs.mode || 'draw');
        showSavedView(false);
        setSignatureImageAsset(null);
        setSignatureEditTarget(null);
        bumpSignatureTypedRenderToken();
        if (signatureTextInput) signatureTextInput.value = '';
        if (signatureImageInput) signatureImageInput.value = '';
        if (signatureImageName) signatureImageName.textContent = 'No file selected';
        if (signatureColorInput) signatureColorInput.value = signaturePrefs.color;
        if (signatureTypeColorInput) signatureTypeColorInput.value = signaturePrefs.typeColor;
        if (signatureTypeSizeInput) signatureTypeSizeInput.value = signaturePrefs.typeSize;
        if (signatureWidthInput) signatureWidthInput.value = signaturePrefs.width;
        if (signatureSmoothingInput) signatureSmoothingInput.value = signaturePrefs.smoothing;
        if (signatureFontInput) signatureFontInput.value = signaturePrefs.font;
        syncSignatureColorLabels();
        syncSignatureTypeSizeLabel();
        clearSignatureDrawingState();
        setSignatureDirtyState(false);
        setSignatureStatus('Create a signature, then place it on the current page.');
        updateSignatureModalCopy();
        void ensureSignatureFontLoaded(signatureFontInput?.value || signaturePrefs.font);
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
        if (!isSignatureAnnotation(ann)) return;
        const composer = ann.signatureComposer && typeof ann.signatureComposer === 'object'
            ? ann.signatureComposer
            : null;
        const preferredMode = normalizeSignatureSourceMode(composer?.mode || ann.signatureSourceMode);

        if (preferredMode === 'type' && composer) {
            if (signatureTextInput) signatureTextInput.value = String(composer.text || '');
            if (signatureFontInput) signatureFontInput.value = String(composer.fontFamily || 'Great Vibes');
            if (signatureTypeColorInput) signatureTypeColorInput.value = normalizeHexColor(composer.inkColor, '#111827');
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
        const imageSource = String(ann.dataUrl || ann.src || ann.assetPath || ann.imagePath || '').trim();
        setSignatureImageAsset({
            dataUrl: String(composer?.imageAsset?.dataUrl || ann.dataUrl || '').trim(),
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

    function openSignatureModal(initialMode = null) {
        if (!signatureModal) return;
        // Read the remembered view BEFORE resetting: resetSignatureComposer()
        // leaves the Saved tab (and persists that), which would otherwise wipe
        // the very preference we are about to restore.
        const wantSavedView = signaturePrefs.savedView === true;
        cancelSignaturePlacement();
        resetSignatureComposer();
        if (initialMode === null) initialMode = signaturePrefs.mode || 'draw';
        signatureModal.classList.add('is-open');
        signatureModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('signature-modal-open');
        setSignatureMode(initialMode);
        // Reopen on the Saved tab if that is where the user left off.
        if (wantSavedView) showSavedView(true);
    }

    async function openSignatureEditModalForAnnotation(ann) {
        if (!signatureModal || !isSignatureAnnotation(ann)) return;
        cancelSignaturePlacement();
        resetSignatureComposer();
        setSignatureEditTarget({ id: String(ann.id), pi: Number(ann.pageIndex) });
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
    }

    function closeSignatureModal() {
        _closeSignatureModal(signatureModal, {
            updateModalCopy: updateSignatureModalCopy,
            updateUi: () => {},
        });
    }

    function cancelSignaturePlacement() {
        _cancelSignaturePlacement({
            syncCursors: () => syncPlacementCursor(),
            updateUi: () => {},
        });
    }

    function syncPlacementCursor() {
        document.body.classList.toggle('enpv-signature-placing', !!signaturePlacementState.active);
    }

    function beginSignaturePlacement(asset) {
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
            type: 'signature',
            toolSource: null,
        });
        syncPlacementCursor();
        setStatus('Click on a page to place the signature. Press Esc to cancel.');
    }

    // ---- Modal stroke handlers (draw mode) ------------------------------
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

    // ---- Wire up modal events ------------------------------------------
    if (ftbSign) {
        ftbSign.addEventListener('click', () => {
            cancelSignaturePlacement();
            openSignatureModal();
        });
    }
    signatureTabs.forEach((tab) => {
        tab.addEventListener('click', () => setSignatureMode(tab.dataset.signatureMode || 'draw'));
    });
    signatureViewTabs.forEach((tab) => {
        tab.addEventListener('click', () => showSavedView(true));
    });
    // role="tab" promises arrow-key navigation across the whole tablist.
    installSignatureTabKeyboard([...signatureTabs, ...signatureViewTabs], (tab) => {
        if (tab.dataset.signatureView === 'saved') showSavedView(true);
        else setSignatureMode(tab.dataset.signatureMode || 'draw');
    });
    if (signatureModalScrim) signatureModalScrim.addEventListener('click', () => closeSignatureModal());
    if (signatureModalClose) signatureModalClose.addEventListener('click', () => closeSignatureModal());
    if (signatureCancelBtn) signatureCancelBtn.addEventListener('click', () => closeSignatureModal());
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
            persistPrefs({ color: signatureColorInput.value });
            syncCanvasBackdrop();
            syncSignatureColorLabels();
            if (signatureMode === 'draw' && signatureActiveStroke) {
                signatureActiveStroke.color = signatureColorInput.value || '#111827';
                renderDrawSignaturePreview();
            }
        });
    }
    if (signatureWidthInput) {
        signatureWidthInput.addEventListener('input', () => {
            persistPrefs({ width: signatureWidthInput.value });
            syncSignatureColorLabels();
            if (signatureMode === 'draw' && signatureActiveStroke) {
                signatureActiveStroke.width = Math.max(1, Number(signatureWidthInput.value) || 3);
                renderDrawSignaturePreview();
            }
        });
    }
    if (signatureSmoothingInput) {
        signatureSmoothingInput.addEventListener('input', () => {
            persistPrefs({ smoothing: signatureSmoothingInput.value });
            syncSignatureColorLabels();
            if (signatureMode === 'draw') renderDrawSignaturePreview();
        });
    }
    if (signatureTypeColorInput) {
        signatureTypeColorInput.addEventListener('input', () => {
            persistPrefs({ typeColor: signatureTypeColorInput.value });
            syncCanvasBackdrop();
            syncSignatureColorLabels();
            if (signatureMode === 'type') renderTypedSignaturePreview();
        });
    }
    if (signatureTypeSizeInput) {
        signatureTypeSizeInput.addEventListener('input', () => {
            persistPrefs({ typeSize: signatureTypeSizeInput.value });
            syncSignatureTypeSizeLabel();
            if (signatureMode === 'type') renderTypedSignaturePreview();
        });
    }
    if (signatureTextInput) {
        signatureTextInput.addEventListener('input', () => { renderTypedSignaturePreview(); });
    }
    if (signatureFontInput) {
        signatureFontInput.addEventListener('change', () => {
            persistPrefs({ font: signatureFontInput.value });
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

    // Apply: build asset, close modal, then either replace existing or
    // start placement mode.
    if (signatureApplyBtn) {
        signatureApplyBtn.addEventListener('click', () => {
            if (!signatureDirty || !signatureCanvas) return;
            const asset = buildCurrentSignatureAsset();
            if (!asset?.dataUrl && !asset?.src) return;
            const editTarget = signatureEditTarget ? { ...signatureEditTarget } : null;
            closeSignatureModal();
            if (editTarget?.id) {
                const target = getPersistedAnnotation(editTarget.id);
                if (target && isSignatureAnnotation(target)) {
                    pushHistorySnapshot('replace signature');
                    const next = {
                        ...target,
                        dataUrl: asset.dataUrl || '',
                        src: asset.src || target.src || '',
                        fileName: asset.fileName || target.fileName,
                        mimeType: asset.mimeType || target.mimeType,
                        assetPath: asset.assetPath || target.assetPath || null,
                        intrinsicWidth: asset.width || asset.intrinsicWidth || target.intrinsicWidth || 1,
                        intrinsicHeight: asset.height || asset.intrinsicHeight || target.intrinsicHeight || 1,
                        signatureSourceMode: normalizeSignatureSourceMode(asset.signatureSourceMode),
                        signatureComposer: cloneSerializableValue(asset.signatureComposer || null, null),
                    };
                    upsertPersistedAnnotation(next);
                    rebuildPageMap();
                    renderAnnotationBoxLayer(Number(target.pageIndex) || 0);
                    markManualSaveNeeded();
                    return;
                }
            }
            beginSignaturePlacement(asset);
        });
    }

    // Esc cancels placement mode.
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (signatureModal?.classList.contains('is-open')) {
            e.preventDefault();
            closeSignatureModal();
            return;
        }
        if (signaturePlacementState.active) {
            e.preventDefault();
            cancelSignaturePlacement();
            setStatus('Signature placement cancelled.');
        }
    });

    // ---- Page-click placement -------------------------------------------
    function pageInfoFromEvent(ev) {
        const pageDiv = ev.target?.closest?.('.page');
        if (!pageDiv) return null;
        const pageNumber = Number(pageDiv.dataset.pageNumber);
        if (!Number.isFinite(pageNumber) || pageNumber < 1) return null;
        const pi = pageNumber - 1;
        const pageView = pdfViewer?.getPageView?.(pi);
        const viewport = pageView?.viewport;
        if (!viewport) return null;
        const scale = Number(viewport.scale) || 1;
        const pageRect = pageDiv.getBoundingClientRect();
        const xPx = ev.clientX - pageRect.left;
        const yPx = ev.clientY - pageRect.top;
        const pdfPoint = viewportPointToPdfPoint(xPx, yPx, viewport, scale);
        if (!pdfPoint) return null;
        const pageSize = viewportPdfDimensions(viewport, scale);
        return {
            pi,
            pdfX: pdfPoint.x,
            pdfY: pdfPoint.y,
            pageWPts: pageSize.width,
            pageHPts: pageSize.height,
        };
    }

    function buildSignatureAnnotationAt(asset, info) {
        if (!asset || !info) return null;
        const intrinsicW = Math.max(1, Number(asset.width || asset.intrinsicWidth) || 1);
        const intrinsicH = Math.max(1, Number(asset.height || asset.intrinsicHeight) || 1);
        // NK_Dev_5: size the placement from the composed mark instead of always
        // targeting 180pt. Previously every signature was scaled to the same
        // width, so a smaller font produced a blurrier signature rather than a
        // smaller one.
        //
        // Both axes are scaled by the SAME factor throughout, so the aspect
        // ratio is exact — clamping width and height independently used to
        // squash small marks (a 7:1 signature came out 3:1).
        let pdfWidth = intrinsicW * CANVAS_PX_TO_PDF_PTS;
        let pdfHeight = intrinsicH * CANVAS_PX_TO_PDF_PTS;

        const maxWPts = info.pageWPts * PLACEMENT_MAX_WIDTH_FRACTION;
        const maxHPts = info.pageHPts * PLACEMENT_MAX_HEIGHT_FRACTION;
        const shrink = Math.min(1, maxWPts / pdfWidth, maxHPts / pdfHeight);
        pdfWidth *= shrink;
        pdfHeight *= shrink;

        // Keep a tiny mark legible without distorting it.
        const grow = Math.max(1, PLACEMENT_MIN_WIDTH_PTS / pdfWidth, PLACEMENT_MIN_HEIGHT_PTS / pdfHeight);
        pdfWidth = Math.min(pdfWidth * grow, info.pageWPts - 24);
        pdfHeight = Math.min(pdfHeight * grow, info.pageHPts - 24);

        const pdfX = Math.max(12, Math.min(info.pageWPts - pdfWidth - 12, info.pdfX - (pdfWidth / 2)));
        const pdfY = Math.max(12, Math.min(info.pageHPts - pdfHeight - 12, info.pdfY - (pdfHeight / 2)));
        const id = generateAnnotationId();
        return {
            id,
            _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
            pageIndex: info.pi,
            type: 'signature',
            pdfX, pdfY, pdfWidth, pdfHeight,
            rotation: 0,
            opacity: 1,
            userCreated: true,
            dataUrl: asset.dataUrl || '',
            src: asset.src || '',
            fileName: asset.fileName || 'signature.png',
            mimeType: asset.mimeType || 'image/png',
            assetPath: asset.assetPath || null,
            intrinsicWidth: intrinsicW,
            intrinsicHeight: intrinsicH,
            signatureSourceMode: normalizeSignatureSourceMode(asset.signatureSourceMode),
            signatureComposer: cloneSerializableValue(asset.signatureComposer || null, null),
            pdfjsSourceX: pdfX,
            pdfjsSourceY: pdfY,
            pdfjsSourceW: pdfWidth,
            pdfjsSourceH: pdfHeight,
            pdfjsSourcePageHeight: info.pageHPts,
            pdfjsAnchorUid: id,
            text: '',
        };
    }

    // Capture-phase listener so we intercept the click before pdf.js's
    // textLayer / annotation box handlers act on it.
    container?.addEventListener('pointerdown', (ev) => {
        if (!signaturePlacementState.active) return;
        if (ev.button !== 0) return;
        const info = pageInfoFromEvent(ev);
        if (!info) return;
        const asset = signaturePlacementState.asset;
        if (!asset) { cancelSignaturePlacement(); return; }
        ev.preventDefault();
        ev.stopPropagation();
        const ann = buildSignatureAnnotationAt(asset, info);
        if (!ann) { cancelSignaturePlacement(); return; }
        pushHistorySnapshot('place signature');
        upsertPersistedAnnotation(ann);
        rebuildPageMap();
        renderAnnotationBoxLayer(info.pi);
        requestAnimationFrame(() => {
            const escapedId = window.CSS?.escape
                ? window.CSS.escape(String(ann.id))
                : String(ann.id).replace(/[^a-zA-Z0-9_-]/g, (char) => `\\${char}`);
            const box = document.querySelector(`.enpv-annotation-box[data-annotation-id="${escapedId}"]`);
            if (box) selectAnnBox?.(box);
        });
        markManualSaveNeeded();
        cancelSignaturePlacement();
        setStatus('Signature placed. Drag to reposition or click Save.');
    }, true);

    // ---- Box rendering helper (called from main.js renderer) ------------
    function createSignatureBoxElement(annotation, pageIndex, viewport, scale, editModeOn, hooks) {
        if (!isSignatureAnnotation(annotation)) return null;
        const pdfRect = {
            x: Number(annotation.pdfX) || 0,
            y: Number(annotation.pdfY) || 0,
            w: Number(annotation.pdfWidth) || 1,
            h: Number(annotation.pdfHeight) || 1,
        };
        const rect = pdfRectToViewportRect(pdfRect, viewport, scale);
        if (!rect || rect.width <= 0 || rect.height <= 0) return null;
        const box = document.createElement('div');
        box.className = 'enpv-annotation-box enpv-signature-box is-persisted-overlay';
        box.dataset.uid = String(annotation.pdfjsAnchorUid || annotation.id || `${pageIndex}:sig`);
        box.dataset.annotationId = String(annotation.id || '');
        box.dataset.pageIndex = String(pageIndex);
        box.dataset.annotationType = 'signature';
        box.dataset.locked = annotation.locked ? '1' : '0';
        box.dataset.zIndex = String(Number(annotation.zIndex) || 6);
        box.dataset.basePageHeight = String(viewportPdfDimensions(viewport, scale).height);
        box.style.left = `${rect.left}px`;
        box.style.top = `${rect.top}px`;
        box.style.width = `${Math.max(1, rect.width)}px`;
        box.style.height = `${Math.max(1, rect.height)}px`;
        box.style.zIndex = box.dataset.zIndex;
        box.classList.toggle('is-locked', annotation.locked === true);

        const img = document.createElement('img');
        img.className = 'enpv-signature-img';
        img.draggable = false;
        const src = String(annotation.dataUrl || annotation.src || annotation.assetPath || annotation.imagePath || '').trim();
        if (src) img.src = src;
        img.alt = 'Signature';
        img.style.position = 'absolute';
        img.style.left = '0';
        img.style.top = '0';
        img.style.transformOrigin = '0 0';

        // NK_DEV_6: the stamp must stay inside the box while it is resized.
        // On an unrotated page that means no inline sizing at all, so the
        // stylesheet's 100%/100% applies and the image follows the box live;
        // inline pixels were computed once and went stale mid-drag.
        const applyStamp = () => applyStampFrameStyle(
            img,
            stampFrameStyle(viewport?.rotation, box.clientWidth || rect.width, box.clientHeight || rect.height),
        );
        applyStamp();

        // A rotated page needs explicit pixel dimensions because the axes swap,
        // so those have to be recomputed whenever the box changes size. The box
        // is mutated directly during a drag without re-rendering the layer.
        if (stampFrameStyle(viewport?.rotation, 1, 1).followsBox === false
            && typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(() => {
                if (!box.isConnected) {
                    observer.disconnect();
                    return;
                }
                applyStamp();
            });
            observer.observe(box);
        }

        box.appendChild(img);
        const label = document.createElement('span');
        label.className = 'enpv-signature-selection-label';
        label.textContent = 'Signature';
        box.appendChild(label);

        if (hooks) {
            box.addEventListener('pointerdown', hooks.onAnnBoxPointerDown);
            box.addEventListener('dblclick', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                hooks.selectAnnBox(box);
                openSignatureEditModalForAnnotation(annotation);
            });
            for (const edge of ['nw', 'ne', 'sw', 'se', 't', 'r', 'b', 'l']) {
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


    // ---- Account-scoped saved signatures (NK_Dev_4) ---------------------
    const refreshAccountLibraryUi = () => renderAccountLibrary(accountLibrary, {
        listEl: signatureAccountList,
        copyEl: signatureAccountCopy,
        searchEl: signatureAccountSearch,
        viewButtons: signatureAccountViewButtons,
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

    async function commitRename(entryId, nextName) {
        if (!entryId || accountLibrary.renamingId === null) return;
        accountLibrary.renamingId = null;

        const entry = accountLibrary.entries.find((item) => String(item.id) === String(entryId));
        const trimmed = String(nextName || '').trim();
        // Nothing to do if it was cleared or left unchanged.
        if (!entry || trimmed === '' || trimmed === entry.name) {
            refreshAccountLibraryUi();
            return;
        }

        const result = await renameAccountSignature(accountSignaturesUrl, accountLibrary, entryId, trimmed);
        refreshAccountLibraryUi();
        setSignatureStatus(
            result.ok ? `Renamed to "${result.signature.name}".` : result.message,
            result.ok ? 'ready' : 'error',
        );
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
        signatureAccountSaveBtn.addEventListener('click', () => { void saveSignatureToAccount(); });
    }
    if (signatureAccountSearch) {
        signatureAccountSearch.addEventListener('input', () => {
            accountLibrary.query = signatureAccountSearch.value;
            accountLibrary.renamingId = null;
            refreshAccountLibraryUi();
        });
    }
    signatureAccountViewButtons.forEach((button) => {
        button.addEventListener('click', () => {
            accountLibrary.viewMode = button.dataset.signatureViewMode === 'row' ? 'row' : 'grid';
            persistPrefs({ accountViewMode: accountLibrary.viewMode });
            refreshAccountLibraryUi();
        });
    });
    if (signatureAccountList) {
        // Commit a rename on Enter, abandon it on Escape.
        signatureAccountList.addEventListener('keydown', async (event) => {
            const input = event.target;
            if (!(input instanceof HTMLElement) || !input.hasAttribute('data-account-signature-rename-input')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                accountLibrary.renamingId = null;
                refreshAccountLibraryUi();
                return;
            }
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const row = input.closest('[data-account-signature-id]');
            await commitRename(String(row?.getAttribute('data-account-signature-id') || ''), input.value);
        });
        signatureAccountList.addEventListener('focusout', async (event) => {
            const input = event.target;
            if (!(input instanceof HTMLElement) || !input.hasAttribute('data-account-signature-rename-input')) return;
            const row = input.closest('[data-account-signature-id]');
            await commitRename(String(row?.getAttribute('data-account-signature-id') || ''), input.value);
        });
        signatureAccountList.addEventListener('click', async (event) => {
            const button = event.target instanceof HTMLElement
                ? event.target.closest('[data-account-signature-action]')
                : null;
            if (!(button instanceof HTMLElement)) return;
            const row = button.closest('[data-account-signature-id]');
            const entryId = String(row?.getAttribute('data-account-signature-id') || '');
            if (!entryId) return;
            const action = button.dataset.accountSignatureAction;
            if (action === 'load') await loadAccountSignature(entryId);
            else if (action === 'rename') {
                accountLibrary.renamingId = entryId;
                refreshAccountLibraryUi();
            } else await removeAccountSignature(entryId);
        });
    }

    // Hydrate the account library on first load.
    if (signatureAccountSaveBtn) {
        signatureAccountSaveBtn.title = accountSignaturesEnabled()
            ? 'Save this signature to your account'
            : 'Sign in to save signatures to your account';
    }
    void loadAccountLibrary();
    syncSignatureColorLabels();
    syncCanvasBackdrop();

    return {
        isSignatureAnnotation,
        createSignatureBoxElement,
        openSignatureEditModalForAnnotation,
        cancelSignaturePlacement,
        isPlacing: () => !!signaturePlacementState.active,
    };
}
