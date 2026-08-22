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
    SIGNATURE_LIBRARY_LIMIT,
    readSignatureLibrary,
    writeSignatureLibrary,
} from '../edit-new/persistence/signature-library.js';
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
    makeSavedSignatureName as _makeSavedSignatureName,
} from '../edit-new/signature/labels.js';
import { closeSignatureModal as _closeSignatureModal } from '../edit-new/signature/modal.js';
import { cancelSignaturePlacement as _cancelSignaturePlacement } from '../edit-new/signature/placement.js';
import {
    updateSignatureLibraryLoadUi as _updateSignatureLibraryLoadUi,
    renderSavedSignatureLibrary as _renderSavedSignatureLibrary,
    persistSavedSignatureLibrary as _persistSavedSignatureLibrary,
    loadSavedSignatureLibrary as _loadSavedSignatureLibrary,
    deleteSavedSignatureFromLibrary as _deleteSavedSignatureFromLibrary,
} from '../edit-new/signature/library-ui.js';
import { ensureSignatureFontLoaded } from '../edit-new/signature/font-loader.js';
import {
    pdfRectToViewportRect,
    viewportPdfDimensions,
    viewportPointToPdfPoint,
    viewportRotatedContentFrame,
} from './page-geometry.js';
import {
    signatureMode,
    signatureDirty,
    signatureDrawing,
    signatureStrokes,
    signatureActiveStroke,
    signatureImageAsset,
    signatureEditTarget,
    savedSignatureLibrary,
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
    setSavedSignatureLibrary,
    setSignaturePlacementState,
    setSignatureCtx,
    bumpSignatureTypedRenderToken,
} from '../edit-new/store/signature-state.js';

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
    const signatureLibrarySelect = document.getElementById('signature-library-select');
    const signatureLibraryLoadBtn = document.getElementById('signature-library-load-btn');
    const signatureSaveNameInput = document.getElementById('signature-save-name');
    const signatureSaveBtn = document.getElementById('signature-save-btn');
    const signatureLibraryList = document.getElementById('signature-library-list');
    const ftbSign = document.getElementById('ftb-sign');

    if (signatureCanvas?.getContext) {
        setSignatureCtx(signatureCanvas.getContext('2d'));
    }

    // ---- Closure-bound shims over the legacy helpers --------------------
    const setSignatureStatus = (msg, tone) => _setSignatureStatus(signatureStatusEl, msg, tone);
    const setSignatureDirtyState = (dirty) => {
        setSignatureDirty(!!dirty);
        if (signatureApplyBtn) signatureApplyBtn.disabled = !signatureDirty;
        if (signatureSaveBtn) signatureSaveBtn.disabled = !signatureDirty;
    };
    const updateSignatureLibrarySaveUi = () => {
        if (signatureSaveBtn) signatureSaveBtn.disabled = !signatureDirty;
    };
    const clearSignatureCanvas = () => _clearSignatureCanvas(signatureCanvas);
    const clearSignatureDrawingState = () => _clearSignatureDrawingState(signatureCanvas);
    const renderDrawSignaturePreview = () => _renderDrawSignaturePreview(signatureCanvas, signatureSmoothingInput);
    const syncSignatureColorLabels = () => _syncSignatureColorLabels({
        signatureColorValue, signatureColorInput,
        signatureTypeColorValue, signatureTypeColorInput,
        signatureWidthValue, signatureWidthInput,
        signatureSmoothingValue, signatureSmoothingInput,
    });
    const updateSignatureModalCopy = () => _updateSignatureModalCopy({ signatureModalTitle, signatureModalSubtitle });
    const updateSignatureLibraryLoadUi = () => _updateSignatureLibraryLoadUi({ signatureLibrarySelect, signatureLibraryLoadBtn });
    const renderSavedSignatureLibrary = () => _renderSavedSignatureLibrary({ signatureLibraryList, signatureLibrarySelect, signatureLibraryLoadBtn });
    const persistSavedSignatureLibrary = _persistSavedSignatureLibrary;
    const loadSavedSignatureLibrary = () => _loadSavedSignatureLibrary({ signatureLibraryList, signatureLibrarySelect, signatureLibraryLoadBtn });
    const makeSavedSignatureName = () => _makeSavedSignatureName(signatureSaveNameInput);
    const typedSignatureFontSize = () => Math.max(24, Math.min(240, Number(signatureTypeSizeInput?.value) || 136));
    const syncSignatureTypeSizeLabel = () => {
        if (signatureTypeSizeValue) signatureTypeSizeValue.textContent = `${Math.round(typedSignatureFontSize())}px`;
    };

    function updateSignatureModeUi() {
        signatureTabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.signatureMode === signatureMode);
        });
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
        updateSignatureModeUi();
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

    function resetSignatureComposer() {
        setSignatureModeValue('draw');
        setSignatureImageAsset(null);
        setSignatureEditTarget(null);
        bumpSignatureTypedRenderToken();
        if (signatureSaveNameInput) signatureSaveNameInput.value = '';
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
        updateSignatureLibrarySaveUi();
        void ensureSignatureFontLoaded(signatureFontInput?.value || 'Great Vibes');
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

    function openSignatureModal(initialMode = 'draw') {
        if (!signatureModal) return;
        cancelSignaturePlacement();
        resetSignatureComposer();
        signatureModal.classList.add('is-open');
        signatureModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('signature-modal-open');
        setSignatureMode(initialMode);
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

    // ---- Signature library entry build / save ---------------------------
    function buildSignatureLibraryEntry() {
        const currentAsset = buildCurrentSignatureAsset();
        if (!currentAsset) return null;
        const snapshotDataUrl = signatureCanvas?.toDataURL('image/png') || currentAsset.dataUrl || '';
        const nextAsset = cloneSerializableValue(currentAsset, null);
        if (!nextAsset || !snapshotDataUrl) return null;
        nextAsset.dataUrl = snapshotDataUrl;
        nextAsset.src = '';
        if (nextAsset.signatureSourceMode === 'upload') {
            if (nextAsset.signatureComposer?.imageAsset && typeof nextAsset.signatureComposer.imageAsset === 'object') {
                nextAsset.signatureComposer.imageAsset.dataUrl = nextAsset.signatureComposer.imageAsset.dataUrl || snapshotDataUrl;
                nextAsset.signatureComposer.imageAsset.src = '';
                delete nextAsset.signatureComposer.imageAsset.assetPath;
            }
            delete nextAsset.assetPath;
            delete nextAsset.imagePath;
        }
        return {
            id: `saved-signature-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
            name: makeSavedSignatureName(),
            previewDataUrl: snapshotDataUrl,
            asset: nextAsset,
            updatedAt: new Date().toISOString(),
        };
    }

    function saveCurrentSignatureToLibrary() {
        const entry = buildSignatureLibraryEntry();
        if (!entry) {
            setSignatureStatus('Create a signature before saving it.', 'error');
            return;
        }
        const previousLibrary = savedSignatureLibrary.slice();
        setSavedSignatureLibrary([entry, ...savedSignatureLibrary].slice(0, SIGNATURE_LIBRARY_LIMIT));
        if (!persistSavedSignatureLibrary()) {
            setSavedSignatureLibrary(previousLibrary);
            setSignatureStatus('Unable to save signature in this browser.', 'error');
            return;
        }
        renderSavedSignatureLibrary();
        if (signatureSaveNameInput) signatureSaveNameInput.value = '';
        setSignatureStatus(`Saved "${entry.name}" to your signature library.`, 'ready');
    }

    async function loadSavedSignatureFromLibrary(entryId) {
        const entry = savedSignatureLibrary.find((item) => String(item.id || '') === String(entryId || ''));
        if (!entry?.asset) return;
        resetSignatureComposer();
        setSignatureEditTarget(null);
        updateSignatureModalCopy();
        updateSignatureModeUi();
        try {
            await loadSignatureComposerFromAnnotation({
                type: 'signature',
                signatureSourceMode: entry.asset.signatureSourceMode || entry.asset.signatureComposer?.mode || 'draw',
                signatureComposer: cloneSerializableValue(entry.asset.signatureComposer || null, null),
                dataUrl: entry.asset.dataUrl || '',
                src: entry.asset.src || '',
                fileName: entry.asset.fileName || 'signature.png',
                mimeType: entry.asset.mimeType || 'image/png',
                intrinsicWidth: entry.asset.width || entry.asset.intrinsicWidth || signatureCanvas?.width || 1,
                intrinsicHeight: entry.asset.height || entry.asset.intrinsicHeight || signatureCanvas?.height || 1,
                assetPath: entry.asset.assetPath || null,
            });
            setSignatureStatus(`Loaded "${entry.name}".`, 'ready');
        } catch (error) {
            setSignatureStatus(error?.message || 'Failed to load saved signature.', 'error');
            setSignatureDirtyState(false);
        }
    }

    function deleteSavedSignatureFromLibrary(entryId) {
        _deleteSavedSignatureFromLibrary(entryId, {
            signatureLibraryList,
            signatureLibrarySelect,
            signatureLibraryLoadBtn,
            signatureStatus: signatureStatusEl,
        });
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
            openSignatureModal('draw');
        });
    }
    signatureTabs.forEach((tab) => {
        tab.addEventListener('click', () => setSignatureMode(tab.dataset.signatureMode || 'draw'));
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
    if (signatureSaveBtn) {
        signatureSaveBtn.addEventListener('click', () => saveCurrentSignatureToLibrary());
    }
    if (signatureLibrarySelect) {
        signatureLibrarySelect.addEventListener('change', () => {
            if (signatureLibraryLoadBtn) signatureLibraryLoadBtn.disabled = !signatureLibrarySelect.value;
        });
    }
    if (signatureLibraryLoadBtn) {
        signatureLibraryLoadBtn.addEventListener('click', async () => {
            const entryId = String(signatureLibrarySelect?.value || '');
            if (!entryId) return;
            await loadSavedSignatureFromLibrary(entryId);
        });
    }
    if (signatureSaveNameInput) {
        signatureSaveNameInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            if (!signatureDirty) return;
            saveCurrentSignatureToLibrary();
        });
    }
    if (signatureLibraryList) {
        signatureLibraryList.addEventListener('click', async (event) => {
            const button = event.target instanceof HTMLElement
                ? event.target.closest('[data-signature-library-action]')
                : null;
            if (!(button instanceof HTMLElement)) return;
            const action = String(button.dataset.signatureLibraryAction || '');
            const row = button.closest('[data-signature-library-id]');
            const entryId = String(row?.getAttribute('data-signature-library-id') || '');
            if (!entryId) return;
            if (action === 'load') { await loadSavedSignatureFromLibrary(entryId); return; }
            if (action === 'delete') deleteSavedSignatureFromLibrary(entryId);
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
        signatureTextInput.addEventListener('input', () => { renderTypedSignaturePreview(); });
    }
    if (signatureFontInput) {
        signatureFontInput.addEventListener('change', () => { renderTypedSignaturePreview(); });
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
        const aspect = intrinsicW / intrinsicH;
        const preferredWPts = 180;
        const maxWPts = info.pageWPts * 0.34;
        const maxHPts = info.pageHPts * 0.18;
        let pdfWidth = Math.max(72, Math.min(preferredWPts, maxWPts));
        let pdfHeight = pdfWidth / Math.max(0.01, aspect);
        if (pdfHeight > maxHPts) {
            pdfHeight = maxHPts;
            pdfWidth = pdfHeight * Math.max(0.01, aspect);
        }
        pdfWidth = Math.max(48, Math.min(pdfWidth, info.pageWPts - 24));
        pdfHeight = Math.max(24, Math.min(pdfHeight, info.pageHPts - 24));
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
        const pageFrame = viewportRotatedContentFrame(viewport, rect.width, rect.height);
        img.style.position = 'absolute';
        img.style.left = '0';
        img.style.top = '0';
        img.style.width = `${Math.max(1, pageFrame.width)}px`;
        img.style.height = `${Math.max(1, pageFrame.height)}px`;
        img.style.transformOrigin = '0 0';
        img.style.transform = pageFrame.transform;
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

    // Hydrate library on first open + initial load
    loadSavedSignatureLibrary();
    syncSignatureColorLabels();

    return {
        isSignatureAnnotation,
        createSignatureBoxElement,
        openSignatureEditModalForAnnotation,
        cancelSignaturePlacement,
        isPlacing: () => !!signaturePlacementState.active,
    };
}
