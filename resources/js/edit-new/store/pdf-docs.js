// Phase 5l store slice: cached pdf.js document handles. _pdfDoc holds
// the editor's primary doc; _acroPdfDoc holds the AcroForm-only doc
// loaded from a separate URL when the page contains form fields.

export let _pdfDoc = null;
export let _acroPdfDoc = null;

export function setPdfDoc(value) { _pdfDoc = value; }
export function setAcroPdfDoc(value) { _acroPdfDoc = value; }
