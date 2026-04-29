/*
 * viewport/scroll-to-page (Phase 7bp).
 *
 * Pure DOM-write helper that scrolls the document to a given page
 * number, clamped against the loaded total page count. Reads
 * _pdfDoc + pageData from the shared stores; takes the page-jump
 * input ref as a param so the module stays free of closure-local
 * DOM deps. main.js declares a single-arg wrapper.
 */

import { _pdfDoc } from '../store/pdf-docs.js';
import { pageData } from '../store/page-data.js';

export function scrollToPage(pageJumpInput, pageNumber) {
    const totalPages = _pdfDoc?.numPages || Object.keys(pageData).length || 1;
    const clamped = Math.max(1, Math.min(totalPages, pageNumber));
    if (pageJumpInput) pageJumpInput.value = String(clamped);
    const target = document.getElementById(`card-${clamped}`) || document.getElementById(`pc-${clamped}`);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
