/*
 * AcroForm overlay/render pipeline (Phase 7aa).
 *
 * Wires the live acro state slices and pdf.js documents together to:
 *   - lazily load the AcroForm-bearing PDF (preferring the already-loaded
 *     background _pdfDoc to avoid a second HTTP request),
 *   - extract+normalize widget annotations per page,
 *   - apply value updates back into the acro entry tables, and
 *   - render the interactive overlay layer (text, choice marks).
 *
 * The module imports the live store bindings (_pdfDoc, _acroPdfDoc,
 * acroFieldLookup, acroFormEntries, acroWidgetsByPage, pageData) so
 * mutations made here are visible everywhere else.
 *
 * Note: pdfjsLib is a window-global supplied by the pdf.js CDN bundle.
 */

import { pageData } from '../store/page-data.js';
import {
    _pdfDoc,
    _acroPdfDoc,
    setAcroPdfDoc,
} from '../store/pdf-docs.js';
import {
    acroFormEntries,
    acroFieldLookup,
    acroWidgetsByPage,
    setAcroWidgetsForPage,
} from '../store/acro-state.js';
import { markDirty } from '../store/lifecycle-flags.js';
import { normalizeAcroWidget } from './widget.js';

export async function ensureAcroPdfLoaded(documentInfo) {
    if (_acroPdfDoc) return;
    // Prefer to reuse the already-loaded background PDF (_pdfDoc, which is the clean PDF).
    // The clean PDF preserves acroform widget annotations, so there is no need for a
    // separate HTTP request.  This also avoids Chromium aborting a PDF-type XHR when
    // headless mode's PDF-viewer handler intercepts the download.
    if (_pdfDoc) { setAcroPdfDoc(_pdfDoc); return; }
    // Fallback: fetch the original file as an ArrayBuffer so browser PDF handling
    // does not interfere, then hand it to pdf.js directly.
    if (!documentInfo?.file_url) return;
    const resp = await fetch(documentInfo.file_url, { credentials: 'same-origin' }).catch(() => null);
    if (!resp?.ok) return;
    setAcroPdfDoc(await pdfjsLib.getDocument({ data: await resp.arrayBuffer() }).promise);
}

export async function ensureAcroWidgetsForPage(pageNumber, documentInfo) {
    const pageKey = String(pageNumber);
    if (Array.isArray(acroWidgetsByPage[pageKey])) return acroWidgetsByPage[pageKey];
    if (!documentInfo || typeof pdfjsLib === 'undefined') return [];

    await ensureAcroPdfLoaded(documentInfo);
    if (!_acroPdfDoc) return [];

    const page = await _acroPdfDoc.getPage(pageNumber);
    const widgets = await page.getAnnotations({ intent: 'display' });
    const normalized = (Array.isArray(widgets) ? widgets : [])
        .filter((annotation) => annotation && annotation.subtype === 'Widget' && !annotation.hidden)
        .map((annotation) => normalizeAcroWidget(annotation, pageNumber))
        .filter(Boolean);

    setAcroWidgetsForPage(pageKey, normalized);
    return normalized;
}

// Update or insert an entry in acroFormEntries / acroFieldLookup.
export function updateAcroEntry(widget, value) {
    widget.value = value;
    const key = widget.key;
    const existing = acroFieldLookup[key];
    if (existing) {
        existing.value = value;
    } else {
        const entry = {
            key,
            fieldName: widget.fieldName || key,
            value,
            pageIndex: widget.pageIndex ?? 0,
            fieldType: widget.fieldType || '',
            multiLine: widget.multiLine || false,
            checkBox: widget.checkBox || false,
            radioButton: widget.radioButton || false,
            exportValue: widget.exportValue || '',
            rect: widget.rect,
        };
        acroFormEntries.push(entry);
        acroFieldLookup[key] = entry;
        if (widget.fieldName && widget.fieldName !== key) acroFieldLookup[widget.fieldName] = entry;
    }
    markDirty();
}

export function drawAcroOverlay(pi) {
    const data = pageData[pi];
    if (!data) return;
    const layer = document.getElementById('ac-' + (pi + 1));
    if (!(layer instanceof HTMLElement)) return;
    layer.innerHTML = '';

    const widgets = Array.isArray(data.acroWidgets) ? data.acroWidgets : [];
    widgets.forEach((widget) => {
        if (!Array.isArray(widget?.rect) || widget.rect.length < 4) return;
        const [leftPdf, bottomPdf, rightPdf, topPdf] = widget.rect;
        const cssLeft = leftPdf * data.scale;
        const cssTop = data.canvasHeight - (topPdf * data.scale);
        const cssWidth = Math.max(3, (rightPdf - leftPdf) * data.scale);
        const cssHeight = Math.max(3, (topPdf - bottomPdf) * data.scale);
        const fieldType = String(widget.fieldType || '').toUpperCase();
        const isChoice = fieldType === 'BTN' && (widget.checkBox || widget.radioButton);

        const el = document.createElement('div');
        el.className = 'edit-new-acro is-interactive' + (isChoice ? ' is-choice' : '');
        el.style.left   = `${cssLeft.toFixed(2)}px`;
        el.style.top    = `${cssTop.toFixed(2)}px`;
        el.style.width  = `${cssWidth.toFixed(2)}px`;
        el.style.height = `${cssHeight.toFixed(2)}px`;
        el.title = widget.fieldName || widget.key || '';

        if (isChoice) {
            const mark = document.createElement('div');
            mark.className = 'edit-new-acro-mark';
            mark.style.fontSize = `${Math.max(10, Math.min(cssHeight, cssWidth) * 0.7).toFixed(1)}px`;
            const isChecked = () => widget.radioButton
                ? (String(widget.value || '') !== '' && String(widget.value || '') === String(widget.exportValue || ''))
                : Boolean(widget.value);
            mark.textContent = isChecked() ? (widget.radioButton ? '●' : '✓') : '';
            mark.addEventListener('click', (e) => {
                e.stopPropagation();
                if (widget.readOnly) return;
                let newVal;
                if (widget.radioButton) {
                    newVal = isChecked() ? '' : String(widget.exportValue || widget.key);
                } else {
                    newVal = !widget.value;
                }
                updateAcroEntry(widget, newVal);
                mark.textContent = (widget.radioButton
                    ? (String(widget.value || '') !== '' && String(widget.value || '') === String(widget.exportValue || ''))
                    : Boolean(widget.value)) ? (widget.radioButton ? '●' : '✓') : '';
            });
            el.appendChild(mark);
        } else {
            // Text / combo / multiline field
            const isMultiLine = widget.multiLine || cssHeight > 22;
            // Flex centering wrapper (not contenteditable — avoids Chrome display:flex+contenteditable bug)
            const label = document.createElement('div');
            label.className = 'edit-new-acro-label' + (isMultiLine ? ' is-multiline' : '');
            // Inner editable element
            const inner = document.createElement('div');
            inner.className = 'acro-editable';
            inner.style.fontSize = '24px';
            inner.style.color = widget.textColor || '#0f172a';
            // For single-line fields: set line-height equal to field height so text is vertically centred
            if (!isMultiLine) inner.style.lineHeight = `${cssHeight}px`;
            if (!widget.readOnly) {
                inner.contentEditable = 'true';
                inner.spellcheck = false;
                inner.addEventListener('pointerdown', (e) => e.stopPropagation());
                inner.addEventListener('click', (e) => e.stopPropagation());
                // Forward clicks on the label (which is full-height) to the inner editable
                label.addEventListener('pointerdown', (e) => { e.stopPropagation(); });
                label.addEventListener('click', (e) => { e.stopPropagation(); inner.focus(); });
                inner.addEventListener('input', () => {
                    updateAcroEntry(widget, inner.innerText.replace(/\n$/, ''));
                });
                inner.addEventListener('keydown', (e) => {
                    // Prevent Escape from propagating to the canvas keydown handler
                    if (e.key === 'Escape') { e.stopPropagation(); inner.blur(); }
                    // Insert a <br> line break for Enter, avoiding browser's default <div> insertion
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.execCommand('insertLineBreak');
                    }
                });
            }
            // Show saved value only — no placeholder text
            inner.textContent = String(widget.value ?? '').trim();
            label.appendChild(inner);
            el.appendChild(label);
        }

        layer.appendChild(el);
    });
}
