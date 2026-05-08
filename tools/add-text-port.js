const fs = require('fs');

const path = 'resources/js/edit-new-pdfjs/main.js';
let code = fs.readFileSync(path, 'utf8');

// 1. Declare variables
code = code.replace(
    "const floatingEditModeBtn = document.getElementById('ftb-edit-mode');",
    "const floatingEditModeBtn = document.getElementById('ftb-edit-mode');\nconst addTextBtn = document.getElementById('add-text-btn');\nconst ftbAddText = document.getElementById('ftb-add-text');"
);

// 2. Add text mode state
const stateBlock = `
let addTextMode = false;
let textCreationState = { active: false, pageIndex: null, pointerId: null, startX: 0, startY: 0, rect: null, previewEl: null, moved: false };

function setAddTextMode(on) {
    addTextMode = on;
    if (on) setEditMode(false);
    if (addTextBtn) addTextBtn.classList.toggle('active', on);
    if (ftbAddText) ftbAddText.classList.toggle('is-active', on);
    document.body.classList.toggle('enpv-add-text-on', on);
    
    if (!on && textCreationState.active) {
        if (textCreationState.previewEl) textCreationState.previewEl.remove();
        textCreationState.active = false;
    }
}

if (addTextBtn) addTextBtn.addEventListener('click', () => setAddTextMode(!addTextMode));
if (ftbAddText) ftbAddText.addEventListener('click', () => setAddTextMode(!addTextMode));

window.addEventListener('pointerdown', (ev) => {
    if (!addTextMode || dragState) return;
    const pageEl = ev.target.closest('.page');
    if (!pageEl) return;
    
    const pageIndexStr = pageEl.dataset.pageNumber;
    if (!pageIndexStr) return;
    const pageIndex = parseInt(pageIndexStr, 10) - 1;
    
    ev.preventDefault();
    if (selectedAnnBoxUid) deselectAnnBox();
    
    const rect = pageEl.getBoundingClientRect();
    const startX = ev.clientX - rect.left;
    const startY = ev.clientY - rect.top;
    
    const previewEl = document.createElement('div');
    previewEl.className = 'text-drag-selection';
    previewEl.style.position = 'absolute';
    previewEl.style.border = '1px solid #1a73e8';
    previewEl.style.backgroundColor = 'rgba(26, 115, 232, 0.1)';
    previewEl.style.pointerEvents = 'none';
    previewEl.style.left = \`\${startX}px\`;
    previewEl.style.top = \`\${startY}px\`;
    previewEl.style.width = '0px';
    previewEl.style.height = '0px';
    previewEl.style.zIndex = '999';
    pageEl.appendChild(previewEl);
    
    textCreationState = {
        active: true,
        pageIndex,
        pointerId: ev.pointerId,
        startX,
        startY,
        rect,
        previewEl,
        moved: false,
    };
}, { capture: true });

window.addEventListener('pointermove', (ev) => {
    if (!textCreationState.active || ev.pointerId !== textCreationState.pointerId) return;
    const { startX, startY, rect, previewEl } = textCreationState;
    const currentX = ev.clientX - rect.left;
    const currentY = ev.clientY - rect.top;
    const left = Math.min(startX, currentX);
    const top = Math.min(startY, currentY);
    const width = Math.abs(currentX - startX);
    const height = Math.abs(currentY - startY);
    
    previewEl.style.left = \`\${left}px\`;
    previewEl.style.top = \`\${top}px\`;
    previewEl.style.width = \`\${width}px\`;
    previewEl.style.height = \`\${height}px\`;
    
    if (width > 5 || height > 5) textCreationState.moved = true;
});

window.addEventListener('pointerup', (ev) => {
    if (!textCreationState.active || ev.pointerId !== textCreationState.pointerId) return;
    const state = { ...textCreationState };
    if (state.previewEl) state.previewEl.remove();
    textCreationState.active = false;
    
    const currentX = ev.clientX - state.rect.left;
    const currentY = ev.clientY - state.rect.top;
    const left = Math.min(state.startX, currentX);
    const top = Math.min(state.startY, currentY);
    let width = Math.abs(currentX - state.startX);
    let height = Math.abs(currentY - state.startY);
    
    const useDraggedBox = state.moved && width > 20 && height > 10;
    const pageIndex = state.pageIndex;
    
    const pageView = pdfViewer.getPageView(pageIndex);
    const scale = pageView ? Number(pageView.viewport.scale) : 1;
    const pageHeight = pageView ? Number(pageView.viewport.height) : state.rect.height;
    const pageWidth = pageView ? Number(pageView.viewport.width) : state.rect.width;
    
    const defaultFontSize = 12;
    const defaultWidth = 200;
    const defaultHeight = Math.ceil(defaultFontSize * 1.5);
    
    const nextCanvasWidth = useDraggedBox ? width : defaultWidth * scale;
    const nextCanvasHeight = useDraggedBox ? height : defaultHeight * scale;
    
    const canvasX = useDraggedBox ? left : state.startX;
    const canvasY = useDraggedBox ? top : state.startY;
    
    const rawX = canvasX / scale;
    const rawY = useDraggedBox 
        ? (pageHeight - (canvasY + nextCanvasHeight)) / scale
        : ((pageHeight - canvasY) / scale) - defaultHeight;
        
    const nextWidthPts = nextCanvasWidth / scale;
    const nextHeightPts = nextCanvasHeight / scale;
    
    const x = Math.max(0, Math.min(rawX, Math.max(0, (pageWidth / scale) - nextWidthPts)));
    const y = Math.max(0, Math.min(rawY, Math.max(0, (pageHeight / scale) - nextHeightPts)));
    
    const uid = 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2);
    
    const ann = {
        _uid: uid,
        id: 'new-' + Date.now() + Math.floor(Math.random() * 1000),
        pdfX: x,
        pdfY: y,
        pdfWidth: nextWidthPts,
        pdfHeight: nextHeightPts,
        text: '',
        originalText: '',
        pageIndex: pageIndex,
        type: 'text',
        fontSize: defaultFontSize,
        fontFamily: 'Helvetica',
        textColor: '#000000',
        fontWeight: '400',
        fontStyle: 'normal',
        underline: false,
        textAlign: 'left',
        verticalAlign: 'top',
        backgroundColor: 'transparent',
        backgroundColorExplicit: false,
        opacity: 1,
        userCreated: true,
        sourceSpans: [],
        sourceLineBBoxes: [],
        sourceTextLines: [],
        _autoWidth: !useDraggedBox,
        _autoWidthMaxWidthPts: useDraggedBox ? null : nextWidthPts,
    };
    
    upsertPersistedAnnotation(ann);
    renderAnnotationBoxLayer(pageIndex);
    
    setAddTextMode(false);
    
    // Select and edit the new text box
    const layer = document.querySelector(\`.enpv-annotation-layer[data-page-index="\${pageIndex}"]\`);
    if (layer) {
        const box = layer.querySelector(\`.enpv-annotation-box[data-uid="\${uid}"]\`);
        if (box) {
            selectAnnBox(box);
            openEditorForBox(box);
        } else {
            // Re-try after a tiny delay if not in DOM yet
            setTimeout(() => {
                const box = layer.querySelector(\`.enpv-annotation-box[data-uid="\${uid}"]\`);
                if (box) {
                    selectAnnBox(box);
                    openEditorForBox(box);
                }
            }, 50);
        }
    }
});

// Clear addTextMode if edit mode is toggled ON
const originalSetEditMode = setEditMode;
setEditMode = function(on) {
    if (on) setAddTextMode(false);
    originalSetEditMode(on);
};

// Also clear it if we click on an existing box
`;

code = code.replace(
    "setEditMode(false);",
    "setEditMode(false);\n" + stateBlock
);

fs.writeFileSync(path, code);
