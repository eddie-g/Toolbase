# PDF.js Editor — Merge PDF Tool Implementation

## Goal

Add a **Merge PDF** tool to the production PDF.js editor at
`/documents/{document}/edit-new?pdfjs=1`.

The tool lets a user:

1. Add one or more local PDF files to the PDF currently open in the editor.
2. Reorder the PDFs as whole documents.
3. Merge them into the current document and continue editing the result.

Each PDF is an indivisible item. The merge tool must **not** expose or support
reordering individual pages inside a PDF. Existing page-level operations remain
in the separate **Manage Pages** tool.

---

## Product Rules

- The PDF already open in the editor is always included and cannot be removed.
- A user can add more PDFs, remove an added PDF before merging, and drag whole
  PDF cards into the desired order.
- Moving a card moves every page in that PDF together.
- The active `documents` record remains the same after the merge. Added files
  are temporary merge inputs and do not create additional `documents` rows.
- The current document name does not change.
- A merge updates the current working PDF at `documents.path`. It must not
  overwrite `original_backup_path`.
- Edits already saved on the current document remain editable after the merge.
- Imported PDF pages initially have no Toolbase overlay annotations. Their
  normal PDF content, links, rotations, and supported form widgets are copied.
- Merge is unavailable for guided documents, matching the existing page-manager
  restriction in the PDF.js editor.

### Out of scope for v1

- Reordering pages within any selected PDF.
- Selecting a subset or range of pages from an added PDF.
- Importing a PDF directly from the user's saved document library.
- Password entry for encrypted PDFs. Encrypted inputs receive a clear error.
- Merging non-PDF files.
- Editing document outlines/bookmarks in the merge modal. Preserve them only if
  the merge library can remap them safely; otherwise document this limitation in
  the UI and do not emit broken outline entries.

---

## User Experience

### Entry point

Add a **Merge PDF** action to the existing PDF.js editor chrome. The best
placement is in the **Manage Pages** modal toolbar beside **Add Page**, because
that modal already owns document structure operations.

Do not add this behavior to the old `/edit-pdfjs` spike or the legacy
`edit-new` JavaScript.

### Merge modal

Open a dedicated modal containing:

- A file picker/drop zone accepting `.pdf` and `application/pdf`.
- A vertical list of document cards.
- One card for the current PDF, marked **Current document**.
- One card for every added PDF.
- The filename, file size, page count, and first-page thumbnail on each card.
- A drag handle and keyboard-accessible **Move up** / **Move down** controls on
  each card.
- A remove button on added cards. The current document card has no remove
  button.
- A summary such as `3 PDFs · 18 pages`.
- **Cancel** and **Merge PDFs** buttons.

The browser can use the already installed `pdfjs-dist` package to inspect each
selected file, count its pages, and render its first page to a thumbnail. This
preview is client-side only and is not authoritative; the server must inspect
every file again.

The file input may use `multiple`. Selecting more files appends cards without
discarding the current order. Duplicate filenames are allowed because each card
has a generated client ID.

### Interaction states

- Disable **Merge PDFs** until at least one valid PDF has been added.
- While merging, disable close, reorder, remove, and submit controls and show
  `Merging PDFs…`.
- Before starting, finish the active text edit and save current annotation and
  AcroForm state.
- On success, close the modal, reload the PDF.js document with a cache-busting
  URL, select the first page of the merged result, and show `PDFs merged.`.
- On failure, keep the modal and its ordering intact and show the server's safe
  error message.
- Warn before submission that the action changes the current working PDF.

### Accessibility

- Use a labelled `role="dialog"` and return focus to the Merge button on close.
- Drag-and-drop cannot be the only ordering mechanism.
- Announce validation, upload, and merge status through an `aria-live` region.
- Every card's accessible name must include its filename and position.

---

## Client Data Model

Keep document groups separate from PDF pages:

```js
[
    {
        id: 'current',
        kind: 'current',
        name: 'contract.pdf',
        pageCount: 7,
        file: null,
    },
    {
        id: 'upload-4d0195',
        kind: 'upload',
        name: 'appendix.pdf',
        pageCount: 3,
        file: File,
    },
]
```

Reordering changes only the order of this array. Do not flatten the cards into
page IDs in the frontend.

### Frontend files

Update:

- `resources/views/documents/edit-new-pdfjs.blade.php`
  - Add the merge endpoint as `data-merge-pdf-url` on `#enpv-root`.
  - Add the page-manager toolbar button and merge modal markup.
- `resources/js/edit-new-pdfjs/main.js`
  - Own modal state, file inspection, thumbnail rendering, document-card
    ordering, save-before-merge, upload, and PDF.js reload.
  - Reuse `pageManagerJsonRequest` conventions where possible, but use
    `FormData` for the merge request.
- `resources/css/edit-new-pdfjs/index.css`
  - Add styles using the existing `enpv-page-manager-*` and modal visual
    language.

If the merge code becomes more than a small isolated section, put it in
`resources/js/edit-new-pdfjs/merge-pdf.js` and inject its dependencies from
`main.js`; do not introduce a second global editor state.

### Preventing stale saves

The merge is a structural mutation. Before posting:

1. Close or commit the active inline editor.
2. Call the existing annotation save flow and await it.
3. Save AcroForm state and await it.
4. Set a `structuralMutationInFlight` flag so autosave cannot start with old
   page indexes.
5. Submit the merge.
6. Clear in-memory page/render caches and reload the document.
7. Resume autosave only after saved annotations have been reloaded.

The server must still serialize structural mutations because another tab can
bypass the frontend flag.

---

## HTTP Contract

Add this route beside the existing page-management routes in `routes/web.php`:

```php
Route::post('/documents/{document}/merge-pdfs', [DocumentController::class, 'mergePdfs'])
    ->name('documents.mergePdfs');
```

The existing `DocumentController` middleware provides the same owner, admin,
and session access checks used by the rest of the editor.

### Request

Send `multipart/form-data`:

```text
pdfs[upload-4d0195] = appendix.pdf
pdfs[upload-a81c22] = exhibits.pdf
order[0] = upload-a81c22
order[1] = current
order[2] = upload-4d0195
session_id = <current editor session id>
```

Use opaque, request-scoped upload IDs rather than filenames or client paths.

Validation requirements:

```php
'pdfs' => ['required', 'array', 'min:1', 'max:10'],
'pdfs.*' => ['required', 'file', 'mimes:pdf', 'max:20480'],
'order' => ['required', 'array'],
'order.*' => ['required', 'string', 'max:80'],
'session_id' => ['nullable', 'string', 'max:255'],
```

After Laravel validation, also require that:

- `order` contains `current` exactly once.
- Every upload ID occurs in `order` exactly once.
- `order` contains no unknown or duplicate IDs.
- Every input opens as a PDF and contains at least one page.
- No input requires a password.
- The merged page count and total input/output bytes are within configured
  limits. Suggested v1 limits are 10 added PDFs, 20 MB per upload, 100 MB total,
  and 1,000 pages.

Put these limits in `config/pdf_editor.php` so controller validation and UI help
text do not drift.

### Success response

```json
{
    "success": true,
    "message": "PDFs merged successfully.",
    "total_pages": 18,
    "current_document_start_page": 5,
    "documents": [
        {"id": "upload-a81c22", "start_page": 0, "page_count": 5},
        {"id": "current", "start_page": 5, "page_count": 7},
        {"id": "upload-4d0195", "start_page": 12, "page_count": 6}
    ],
    "pdf_url": "/documents/123/file?v=<revision>"
}
```

All page offsets in this response are zero-based.

### Error responses

- `422` for invalid ordering, unsupported/encrypted PDFs, page-limit overflow,
  or an invalid upload.
- `409` when another structural mutation is already running or the submitted
  document revision is stale.
- `500` for an unexpected merge, storage, or state-remapping failure.

Never return shell output, local paths, or uploaded filenames that have not
been escaped for display.

---

## Backend Design

Keep orchestration out of the already-large `DocumentController`. The
controller should validate the request and call a focused service such as:

```text
app/Services/PdfDocumentMergeService.php
python/pdf-editor/merge_pdf_documents.py
```

### Merge manifest

Write a temporary JSON manifest instead of passing a variable number of paths
through a shell command:

```json
{
    "inputs": [
        {"id": "upload-a81c22", "path": "/tmp/...pdf"},
        {"id": "current", "path": "/storage/...pdf"},
        {"id": "upload-4d0195", "path": "/tmp/...pdf"}
    ],
    "output": "/storage/documents/temp_merge_<uuid>.pdf",
    "max_pages": 1000
}
```

Invoke the script with the configured PDF-editor Python binary and escaped
manifest path. Prefer Laravel's process API with a timeout over concatenating a
shell command.

### Python merge behavior

`merge_pdf_documents.py` should:

1. Parse and validate the manifest.
2. Open every source with PyMuPDF and reject damaged, encrypted, or empty PDFs.
3. Calculate each document group's zero-based `start_page` and `page_count`.
4. Create a new PDF and call `insert_pdf` once per whole input document, in the
   supplied document order. Do not accept page ranges in this script.
5. Copy supported links, annotations, and widgets. Deal with duplicate form
   field names deterministically and include a warning if a widget cannot be
   retained.
6. Preserve the current document's metadata as the merged document metadata.
7. Save to the temporary output using compression and garbage collection.
8. Reopen the output, verify its page count, and emit one JSON result line.
9. Close all PDF handles in `finally` blocks.

The result must contain the exact group offsets so PHP does not independently
recalculate state locations.

### Atomic replacement and locking

Use a per-document lock such as
`Cache::lock("document:{$document->id}:structure", 60)` for merge, reorder,
delete-page, add-page, and rotation operations.

The service should follow this order:

1. Acquire the document lock.
2. Copy the current working PDF to a rollback-only temporary file.
3. Produce and verify the candidate merged PDF without changing the document.
4. Start a database transaction.
5. Atomically replace `documents.path` with the candidate.
6. Remap current-document state using the returned current-document offset.
7. Update `documents.size_bytes` and any content revision/cache metadata.
8. Commit the transaction.
9. Invalidate stale extraction data and dispatch fresh extraction.
10. Delete all request and rollback temporary files in `finally` blocks.

If a database operation fails after file replacement, restore the rollback copy
before returning an error. Never leave a successfully merged file paired with
old page indexes.

Do not overwrite the clean original backup used by **Restore original**.

---

## Preserving Existing Editor State

Only the current PDF has Toolbase-owned editor state. Because it remains one
indivisible group, all of its pages move by the same offset:

```text
new page index = old page index + current_document_start_page
```

Apply that offset to all page-scoped current-document data in the same database
transaction:

- `pdf_state.page_number`.
- `pdf_state.annotation_data.pageIndex` and any nested annotation payload copy.
- Page-bearing source/debug fields used by the PDF.js editor, including mask
  page indexes and source IDs that encode a page index.
- `pdf_acro_form.page_num` and page fields inside its JSON `data` payload.
- `document_notes.page_index`.

Create a single tested PHP helper, for example
`PdfDocumentPageOffsetRemapper`, for this work. Do not scatter JSON key updates
through the controller.

Important details:

- Both `PdfState.page_number` and editor `pageIndex` values are zero-based.
- Preserve annotation IDs when they do not encode a page. For PDF.js/source
  keys that include a page component, update only the documented page segment;
  never use a blind digit replacement.
- The remapper must be idempotent only within one operation. Record/apply the
  offset once; retrying the HTTP request must not shift state twice.
- Imported embedded AcroForm widgets are part of the merged PDF, but there is no
  pre-existing `pdf_acro_form` database state for them. They are discovered on
  reload and saved normally afterward.
- Extraction-derived rows and caches refer to the old page layout. Invalidate
  them after commit and run `ProcessUploadedDocumentJob` or the same extraction
  pipeline used after upload. Do not attempt to combine extraction JSON from
  the source PDFs.

If the application cannot safely remap a particular saved-state format, block
the merge with a clear message rather than silently moving edits to the wrong
page. Baking annotations into the PDF and clearing their editable state is not
an acceptable implicit fallback.

### Revision protection

Add a monotonically increasing `content_revision` to `documents`, or an
equivalent server-generated version. Include it when loading and submitting
structural operations. Reject a stale merge with `409` so two tabs cannot apply
state offsets to different PDF versions.

All page-management mutations should eventually share this revision and lock;
implementing it only for merge still leaves reorder/merge races.

---

## Activity, Quotas, and Logging

- Count one successful merge as one editor action using
  `consumeMonthlyActionQuota()`.
- Consume quota only after request validation and before expensive processing,
  following the export endpoint convention.
- If a consumed action fails because of an internal error, follow the existing
  quota policy consistently; do not invent merge-only accounting behavior.
- For authenticated users, write a `UserActivity` entry:
  - action: `Merge PDFs`
  - category: `pdf_merge`
  - status: `success` or `failed`
  - details: input count, input page counts, final page count, and safe error
    category
- Log document ID and generated request/operation IDs, not temporary file paths
  or document contents.

---

## Security and Reliability

- Use Laravel upload validation plus a real PyMuPDF open; extension/MIME checks
  alone are insufficient.
- Never use a client filename as a storage path.
- Store request inputs and output under UUID-based temporary names.
- Pass paths through a JSON manifest and an argument-safe process runner.
- Apply a process timeout and memory/page/file limits.
- Clean up all temporary files on success, validation failure, process timeout,
  or exception.
- Return `404` for inaccessible documents through the existing authorization
  behavior; do not reveal that another user's document exists.
- Do not send PDF bytes back in JSON.
- Add `no-store`/cache busting to the post-merge reload so PDF.js cannot display
  the previous working file.

---

## Test Plan

### Python unit/integration tests

- Merge two PDFs in both orders and verify page text/order.
- Merge three PDFs and verify group offsets and total page count.
- Verify mixed page sizes and rotations are preserved.
- Verify links/annotations/widgets for supported fixtures.
- Reject encrypted, damaged, empty, over-limit, duplicate-ID, and malformed
  manifest inputs.
- Verify a failed merge does not create a partial output.

### Laravel feature tests

- Owner, admin, and authorized anonymous session can merge.
- Another user receives `404`.
- Request requires at least one upload and exactly one `current` item.
- Unknown, missing, and duplicate order IDs receive `422`.
- File and page limits receive `422`.
- Successful merge keeps the same document ID/name, updates `size_bytes`, and
  returns correct offsets.
- Moving the current PDF after an imported five-page PDF shifts annotations,
  AcroForm state, and notes by exactly five pages.
- Keeping the current PDF first applies an offset of zero.
- A script or DB failure restores the original working PDF and state.
- Concurrent or stale revision requests receive `409`.
- `original_backup_path` is unchanged.
- Extraction is invalidated/requeued after success.

### Browser regression test

Add a Playwright/CJS regression alongside the existing PDF.js editor tests:

1. Open a two-page current document with an editable annotation on page 2.
2. Open Merge PDF and add two fixtures.
3. Reorder cards to `fixture B`, `current`, `fixture A`.
4. Assert there is no page-level drag UI in the merge modal.
5. Merge and wait for the viewer reload.
6. Verify the displayed page count and page content order.
7. Verify the old annotation is still editable on its offset page.
8. Save, reload the browser, and verify order and annotation placement again.

Also cover keyboard ordering, removal before submit, invalid upload feedback,
and retry after a server failure.

---

## Suggested Implementation Order

1. Add the Python manifest-based merge script and tests.
2. Add the document lock/revision mechanism shared by structural operations.
3. Implement and test `PdfDocumentPageOffsetRemapper`.
4. Add `PdfDocumentMergeService`, controller method, route, validation, quota,
   and activity logging.
5. Add the Blade endpoint hook and modal.
6. Add PDF inspection, whole-document reordering, save coordination, submit,
   and reload behavior to the PDF.js frontend.
7. Add styling, accessible keyboard controls, and responsive behavior.
8. Add Laravel and browser regression coverage.
9. Update `documentation/03-pdf-editor.md` to list **Merge PDFs** under Page
   Management once the feature ships.

---

## Definition of Done

- A user can add at least one PDF to the current PDF.js editor document.
- The modal reorders only whole PDFs and never individual pages.
- The resulting page order exactly matches the document-card order.
- Saved edits, form values, and notes belonging to the current PDF move to the
  correct offset pages and remain editable.
- The same document reloads in PDF.js without stale cached pages.
- Invalid or failed merges do not alter the working PDF or its saved state.
- Access control, quota, limits, locking, cleanup, logging, and automated tests
  are in place.
