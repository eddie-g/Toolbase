# PDF.js Editor — Convert Tool

## Goal

Bring the conversion workflows from `/documents/{document}/edit` into the
production PDF.js editor at `/documents/{document}/edit-new?pdfjs=1` without
forking the output behavior. Implementation is staged by output type so each
format can be verified against the edited document users actually see.

## Rollout

1. **Images** — implemented in the first stage.
2. **PDF/A** — implemented with `POST /documents/{document}/convert-to-pdfa`
   and the compliance report modal.
3. **Word** — implemented with `POST /documents/{document}/convert-to-word`.
4. **Excel** — implemented with `POST /documents/{document}/convert-to-excel`.

## Stage 1: Images

The Images tab ports the client-side `/edit` workflow and supports:

- JPG, PNG, and TIFF output.
- All pages, a contiguous page range, or custom values such as `1, 3, 5-8`.
- Three plain-language image sizes: **Screen** (96 DPI), **Standard** (150 DPI),
  and **Print** (300 DPI), shown directly after the output format choices.
- Color, grayscale, and transparent-background choices. Transparency is
  available only for PNG and TIFF.
- JPG quality from 1–100.
- A compact **Anti-aliasing** checkbox beside JPG Quality, with a short
  explanation that it smooths jagged edges and no separate smoothing category.
- A direct image download when one page is exported.
- One `<document>_images.zip` download when multiple pages are exported. Files
  inside the archive use `_page-N` suffixes.
- A live estimated download size based on the selected pages, output format,
  image size, JPG quality, and color choice. Multi-page estimates include the
  ZIP container and remain approximate because JPG/PNG compression varies with
  page content.

## Edited PDF Source

Image export must not render `documents.path` or the viewer's clean base PDF
directly. Either source can omit unsaved annotation overlays, AcroForm values,
or other visible editor state.

The PDF.js workflow therefore:

1. Finishes an active inline text edit.
2. Waits for editor persistence already in flight.
3. Calls the same `requestEditedPdfBlob()` materialization used by Download
   PDF and selected-page splitting.
4. Opens the returned bytes as a temporary PDF.js document.
5. Renders only the selected pages into off-screen canvases.
6. Encodes each image.
7. Packages multiple images into one client-side ZIP archive.
8. Starts one browser download and destroys the temporary PDF.js document.

The source document is not mutated by image conversion.

Like the existing `/edit` Convert tool, conversion requires an authenticated
editor user. Logged-out users see the standard premium-feature status message
and the modal does not open.

## Stage 2: PDF/A

The PDF/A tab supports PDF/A-1b, PDF/A-2b, and PDF/A-3b with optional font
embedding and an sRGB output intent. Its option cards use normal title casing,
short descriptions, and a responsive two-column layout.

Conversion uses the same edited-document materialization as image export. The
browser uploads that temporary PDF to `convert-to-pdfa`, so annotations,
AcroForm values, and other visible PDF.js changes are included without
overwriting the stored source document. The server continues to accept a
request without an upload for compatibility with the legacy `/edit` flow.

After conversion, the PDF.js editor displays the server-generated compliance
report, including status, page count, file size, timestamp, individual checks,
and embedded-font details. Report strings are inserted as text rather than
HTML because font names and other details may originate in the uploaded PDF.
The dedicated download button uses the existing single-use, ten-minute PDF/A
download token. Its compact label stays readable for long document names while
the complete output filename remains available as a tooltip.

## Stage 3: Word

The Word tab supports flowing-text and exact-layout conversion, optional image
embedding, and optional OCR for scanned pages. It shares the responsive option
cards used by PDF/A.

Like the image and PDF/A flows, Word conversion first materializes current
PDF.js edits and uploads that temporary PDF without modifying the stored source
document. A successful response is downloaded through the existing
`download-converted` single-use token endpoint. Requests without an uploaded
PDF remain supported for the legacy `/edit` workflow.

The controller selects a Python interpreter that can import `fitz`, `docx`, and
`pdf2docx` for the default exact-layout path. This prevents a partially
provisioned virtual environment from silently selecting the older fallback.
The Word dependencies and a NumPy-compatible headless OpenCV build are pinned
in `python/requirements.txt`.

Exact mode uses `pdf2docx` to reconstruct native Word sections, multi-column
layouts, paragraph spacing/alignment, images, and tables. Opaque extracted PNG
photos are recompressed as high-quality JPEGs after conversion without changing
their Word dimensions; transparent assets remain PNG. This keeps photo-heavy
DOCX files close to the source size. The built-in PyMuPDF/`python-docx`
converter remains available for flow mode, image-free output, OCR, and as an
exact-mode failure fallback. It preserves paragraph grouping and emits
PyMuPDF-detected ruled tables as native fixed-layout DOCX tables.

## Stage 4: Excel

The Excel tab defaults to positioned All Content mode and also supports a
detected-tables-only mode,
optional source merged-cell preservation, and either one worksheet per PDF page
or a combined worksheet. Like Word and PDF/A, it materializes and uploads the
current edited PDF while retaining the stored-document fallback for `/edit`.

When a document contains tables, tables-first mode keeps non-table pages
explicit rather than inventing tables from prose. For cover-style newsletters
with no detected tables, it switches to a layout workbook modeled after
commercial reconstruction output: logical `Table` sheets, aggregated
left/right text cells, source-sized rows and columns, merged layout regions,
positioned source images, and reference-compatible font families, sizes, and
colors. Overlaid duplicate PDF text is removed before paragraph assembly. Other
zero-table documents fall back to structured page content and retain extracted
page images. Source cell rectangles determine row and column spans for real
tables, so repeated values are never treated as merged cells.

The workbook generator preserves conservative numeric types for integers,
decimals, thousands-separated values, currencies, percentages, and common date
formats. It applies readable headers, borders, wrapping, column widths, frozen
top rows, and print scaling. Table and structured-content row heights are
estimated from the wrapped text and final column width, up to Excel's row-height
limit. Successful responses reuse the single-use, ten-minute
`download-converted` token flow.

The modal footer shows an estimated download size for Images, PDF/A, Word, and
Excel. Image estimates use the selected pages and rendering options; document
conversion estimates use the materialized PDF byte size and selected mode.

## Validation and Browser Safety

- Range values must satisfy `1 <= from <= to <= page count`.
- Custom tokens must be page numbers or ascending ranges inside the document.
- Duplicate custom pages are removed and output is sorted in document order.
- The size choice is restricted to Screen, Standard, or Print; users do not
  need to enter numeric DPI values.
- JPG quality is clamped to 1–100 and disabled for PNG/TIFF.
- Transparent background is available only for PNG and TIFF.
- A rendered page is rejected before canvas allocation when it would exceed
  64 million pixels. The user is asked to select a smaller image size instead
  of risking a browser tab crash.

## Files

- `resources/views/documents/edit-new/_convert-modal-pdfjs.blade.php` — modal
  controls and accessible tab semantics.
- `resources/css/edit-new-pdfjs/index.css` — convert UI, validation, progress,
  and disabled states.
- `resources/js/edit-new-pdfjs/image-export.js` — page parsing, color
  transforms, TIFF encoding, PDF page rendering, and downloads.
- `resources/js/edit-new-pdfjs/main.js` — editor materialization, modal state,
  progress, status, and activity-log integration.
- `resources/js/edit-new-pdfjs/image-export.test.mjs` — DOM-independent unit
  coverage for the image-export contract.
- `resources/js/edit-new-pdfjs/pdfa-export.js` — PDF/A response validation,
  report normalization, and download URL construction.
- `resources/js/edit-new-pdfjs/pdfa-export.test.mjs` — focused coverage for
  the PDF/A client contract.
- `resources/js/edit-new-pdfjs/converted-export.js` — validates converted-file
  responses and constructs tokenized Word/Excel download URLs.
- `resources/js/edit-new-pdfjs/converted-export.test.mjs` — focused Word/Excel
  response and download URL coverage.
- `python/pdf-editor/tests/test_convert_to_word_layout.py` — verifies native
  4×4 tables, paragraph spacing/alignment, source-derived page margins, Drylab
  multi-column section reconstruction, and photo-size optimization.
- `python/pdf-editor/tests/test_convert_to_excel.py` — verifies page sheets,
  All Content defaults, newsletter layout, source images and typography,
  text-scaled row heights, source merged cells, numeric formats, and generic
  all-content extraction.
- `app/Http/Controllers/DocumentController.php` — accepts an optional edited
  PDF upload for conversion while retaining the stored-document fallback.

## Activity Tracking

Successful exports use
`POST /documents/{document}/log-export` with category `image_export`. Details
include format, the selected image-size label and internal DPI, JPG quality,
color choice, anti-aliasing, selected page count, and one-based page numbers.
Failed exports log the same settings with a safe error message.

## Completion Criteria

All four conversion tabs materialize the current PDF.js state where required,
retain existing token expiry and cleanup behavior, and have focused regression
coverage. Run the client tests, Word/Excel Python tests, and production build
before deployment.
