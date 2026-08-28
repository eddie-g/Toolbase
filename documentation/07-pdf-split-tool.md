# PDF.js Editor — Split PDF Tool Implementation

## Goal

Extend the production PDF.js editor's existing merge tool into a shared
**Merge / Split** tool at `/documents/{document}/edit-new?pdfjs=1`.

The split workflow must let a user:

1. Open the combined tool from a **Merge / Split** toolbar button.
2. Choose the **Split** tab.
3. Select pages from the current PDF using checkboxes on page thumbnails.
4. See a live count of selected pages.
5. Click **Split selected pages**.
6. Enter a filename when prompted.
7. Choose whether to download the new PDF or open it as a new editor document.
8. Generate one new PDF containing the selected pages in their original
   document order.

The current PDF is not changed and selected pages are not removed from it.
The download choice uses the existing temporary download-token flow. The editor
choice creates a second `documents` record and opens that copy without changing
the source document.

---

## Existing Components to Reuse

The repository already contains:

- The merge toolbar button and modal in
  `resources/views/documents/edit-new/_floating-toolbar.blade.php` and
  `resources/views/documents/edit-new/_merge-modal-pdfjs.blade.php`.
- Merge modal behavior in `resources/js/edit-new-pdfjs/main.js`.
- PDF.js page thumbnail rendering in the existing page manager.
- `POST /documents/{document}/split-pdf` and
  `DocumentController::splitPdf()`.
- `python/pdf-editor/split_pdf.py`, whose `custom` mode already creates one PDF
  from a set of page numbers.
- The temporary download-token flow through
  `GET /documents/download-converted`.

Do not create a second unrelated split modal. Convert the existing merge modal
into one shared shell with independent **Merge** and **Split** panels.

---

## Product Rules

- Rename the toolbar button from **Merge PDF** to **Merge / Split**.
- Keep it immediately beside the existing **Convert** button.
- The shared modal has two tabs: **Merge** and **Split**.
- Opening the modal defaults to the last tab used during the current browser
  session. Use **Merge** when there is no previous selection.
- The Merge tab retains the current whole-document merge behavior.
- The Split tab shows only pages from the current PDF.
- Every page card has a visible checkbox and can also be toggled by clicking
  the card.
- At least one page must be selected before splitting.
- Selected pages are written to the new PDF in ascending/original page order,
  regardless of the order in which the user checked them.
- Splitting does not delete, reorder, or otherwise mutate the current PDF.
- A user can select all pages. The result may therefore be a copy of the
  current PDF under a new filename.
- The output includes the current visible editor state: saved annotations,
  filled form values, source edits, rotations, and other content that the
  normal PDF.js **Download PDF** flow includes.
- Guided documents do not expose Merge / Split, matching the current merge and
  page-manager restrictions.

### Out of scope for v1

- Producing one PDF per selected page.
- Producing several page ranges in one action.
- Removing selected pages from the current document.
- Dragging split pages into a different order.
- Creating a new Netkit library document or transferring editable annotation
  records to another `documents` row.
- Password entry for an encrypted current PDF.

---

## Shared Modal Design

### Toolbar entry point

In `resources/views/documents/edit-new/_floating-toolbar.blade.php`, rename:

```html
<span>Merge PDF</span>
```

to:

```html
<span>Merge / Split</span>
```

Update its title and accessible name to `Merge or split PDF documents`.
The element ID can remain `ftb-merge-pdf` to avoid unnecessary JavaScript and
CSS churn, although `ftb-merge-split` would be clearer if all references are
changed together.

### Header and tabs

Rename the modal heading to **Merge / Split PDFs** and place a two-tab control
under the header:

- **Merge** — add and reorder complete PDFs.
- **Split** — select pages from the current PDF.

Use tab semantics:

```html
<div role="tablist" aria-label="Merge or split PDF">
    <button role="tab" aria-selected="true" aria-controls="enpv-merge-panel">Merge</button>
    <button role="tab" aria-selected="false" aria-controls="enpv-split-panel">Split</button>
</div>
```

Each panel must have `role="tabpanel"`. Inactive panels use `hidden`; do not
leave both panels interactive behind one another.

Changing tabs must not destroy the Merge tab's selected files or ordering. The
Split tab's page selection must likewise remain intact while the modal stays
open.

### Merge panel

Keep the existing behavior and layout:

- Add local PDFs.
- Show each PDF as an indivisible document card.
- Reorder complete PDFs only.
- Merge into the current working document.

Its footer action remains **Merge PDFs**.

### Split panel

The Split tab contains:

- A toolbar with **Select all** and **Clear selection**.
- A responsive grid of every current PDF page.
- A thumbnail, visible checkbox, and `Page N` label on each card.
- A sticky/live selection summary.
- A primary **Split selected pages** button.

Example summary states:

```text
0 pages selected
1 page selected
7 pages selected
```

The primary action is disabled when the selected count is zero or while page
thumbnails are still loading. Selecting every page is valid.

The grid must not provide drag handles or page-reordering behavior. The normal
**Manage Pages** tool remains responsible for reordering the current PDF.

---

## Page Selection Behavior

Represent selection as a set of zero-based PDF.js page indexes:

```js
const splitSelectedPageIndices = new Set([0, 2, 4]);
```

The UI displays one-based page labels, but all internal client state remains
zero-based until the HTTP request is built.

### Page card interaction

- Clicking a page card toggles its checkbox.
- Clicking the checkbox must toggle only once; prevent the surrounding card
  handler from toggling it a second time.
- Space toggles a focused card.
- The card exposes `aria-pressed` or an equivalent selected state.
- The checkbox accessible name is `Include page N`.
- Selected cards have a clear border, background, and checkmark that do not
  rely on color alone.
- After each toggle, update the count and primary-button disabled state.

### Thumbnail rendering

Reuse `currentPdfDoc` and the thumbnail approach already used by
`renderPageManagerThumbnail()`.

For large PDFs:

- Create all lightweight card shells immediately.
- Render thumbnails lazily with `IntersectionObserver` or a small concurrency
  queue.
- Cancel/ignore thumbnail work when the modal closes or the PDF reloads by
  checking a render-generation counter.
- Cap thumbnail resolution using `devicePixelRatio`; do not render full-size
  PDF pages into the modal.

The server remains authoritative for page count and page-index validation.

### Selection lifecycle

- Start with no pages selected each time the shared modal is newly opened.
- Preserve selection when switching between Merge and Split tabs without
  closing the modal.
- Clear selection after a successful split.
- Clear selection when the underlying PDF changes after a merge, page reorder,
  rotation, deletion, addition, or restore operation.
- Closing and reopening the modal starts a new split selection.

---

## Filename Prompt

Clicking **Split selected pages** opens a small filename dialog only after at
least one page is selected.

Do not use `window.prompt()`. Use a nested application modal so validation,
focus management, and styling match the editor.

The dialog contains:

- Heading: **Create split PDF**.
- A text input prefilled with `<current-base-name>_split.pdf`.
- Help text explaining that the result can be downloaded or opened in the editor.
- **Cancel**, **Split & download**, and **Open in editor** buttons.

Validation rules:

- Trim leading/trailing whitespace.
- Reject an empty name.
- Maximum 240 characters.
- Remove control characters and path separators.
- Append `.pdf` if it is missing, case-insensitively.
- Reject a name that becomes empty after sanitization.
- The filename is a download name only and must never be used directly as a
  server filesystem path.

Pressing Enter uses **Split & download**. Escape returns to the Split tab without
clearing the selected pages.

---

## Edited-Document Source

The current endpoint reads `documents.path`, but PDF.js editor annotations and
form values can still exist as saved overlay state. Splitting that raw file
would silently omit visible edits.

Before splitting:

1. Commit or cancel the active inline text editor.
2. Await `saveAnnotationStateToDb({ source: 'split' })`.
3. Await AcroForm persistence.
4. Materialize the same edited PDF bytes used by **Download PDF**.
5. Split those materialized bytes, not the unannotated base PDF.

Refactor the existing download code so it can return an edited PDF `Blob`
without immediately starting a browser download. Submit that blob to the split
endpoint as an optional `pdf` multipart field, following the same pattern used
by the encryption endpoint.

This keeps the split result visually consistent with **Download PDF**. It also
keeps the current document unchanged because the materialized PDF is only a
temporary split input.

If edited PDF materialization fails, abort before calling the split endpoint
and keep the filename dialog/selection available for retry. Never silently
fall back to the raw base PDF.

---

## HTTP Contract

Keep the existing route:

```php
Route::post('/documents/{document}/split-pdf', [DocumentController::class, 'splitPdf'])
    ->name('documents.splitPdf');
```

Extend it without breaking legacy `each`, `specific`, `range`, and `custom`
callers.

### PDF.js selected-pages request

Send `multipart/form-data`:

```text
mode = selected
output_action = download|editor
page_indices[0] = 0
page_indices[1] = 2
page_indices[2] = 4
output_name = contract_exhibits.pdf
session_id = <current editor session id>
pdf = <materialized edited PDF blob>
```

`page_indices` are zero-based. The controller sorts and deduplicates them, then
converts them to the one-based page string expected by the Python script.

### Validation

Add validation while retaining the existing modes:

```php
'mode' => ['required', 'string', 'in:each,specific,range,custom,selected'],
'page_indices' => ['required_if:mode,selected', 'array', 'min:1'],
'page_indices.*' => ['integer', 'min:0', 'distinct'],
'output_name' => ['required_if:mode,selected', 'string', 'max:240'],
'output_action' => ['nullable', 'string', 'in:download,editor'],
'session_id' => ['nullable', 'string', 'max:255'],
'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
```

After opening the authoritative input PDF, reject any index greater than or
equal to its page count. Do not trust the PDF.js count sent by the browser.

For `selected` mode:

- Sort indexes numerically.
- Convert `[0, 2, 4]` to the Python custom page specification `1,3,5`.
- Normalize `output_name` with a dedicated filename helper.
- Require the materialized `pdf` upload from the PDF.js editor. Legacy callers
  that do not send it retain their existing raw-document behavior.
- Count the operation once through `consumeMonthlyActionQuota()`.

### Success responses

`output_action=download` continues using the existing converted-download token
mechanism:

```json
{
    "success": true,
    "output_action": "download",
    "download_token": "<uuid>",
    "download_name": "contract_exhibits.pdf",
    "selected_pages": [1, 3, 5],
    "selected_page_count": 3,
    "source_page_count": 12
}
```

The `selected_pages` response is one-based for user-facing display.

The frontend downloads:

```text
/documents/download-converted?token=<uuid>
```

Open the download URL only after the split succeeds. Do not navigate the editor
away from the current document.

`output_action=editor` persists the generated file as an independently owned
document and returns its editor URL:

```json
{
    "success": true,
    "output_action": "editor",
    "document_id": 123,
    "edit_url": "/documents/123/edit-pdfjs",
    "download_name": "contract_exhibits.pdf",
    "selected_pages": [1, 3, 5],
    "selected_page_count": 3,
    "source_page_count": 12
}
```

Guest-created copies are added to the current session's accessible document
IDs. Owned copies inherit the source document's owner. The normal upload
processing job prepares the new copy for editor use.

### Errors

- `422` for no selection, out-of-range/duplicate indexes, invalid filename,
  invalid materialized PDF, or configured limit violations.
- `409` if a structural operation is currently changing the source PDF.
- `500` for an unexpected split, storage, or token-registration failure.

Return safe messages and never expose temporary paths or raw process output.

---

## Backend Changes

### Controller

Update `DocumentController::splitPdf()` to:

1. Support `mode=selected` and the new fields.
2. Prefer the validated temporary `pdf` upload as input for the PDF.js flow.
3. Validate selected indexes against the uploaded PDF's actual page count.
4. Normalize the requested filename.
5. Call the Python script's existing `custom` behavior.
6. Override the script-generated name with the normalized user filename.
7. Register a download token for `download`, or persist a new `Document` and
   return its editor URL for `editor`.
8. Always remove the temporary uploaded input and output directory on failure.
9. Preserve all existing split modes for older editor/export callers.

Move orchestration into a focused `PdfSelectedPagesSplitService` if expanding
the controller would make the existing method harder to verify.

### Python

`python/pdf-editor/split_pdf.py` already supports the necessary page extraction
through `custom` mode. It should still be tightened to:

- Reject encrypted, damaged, and empty PDFs explicitly.
- Accept only validated page indexes/page specifications.
- Preserve supported links, annotations, rotations, and widgets through
  `insert_pdf(..., links=True, annots=True, widgets=True)`.
- Reopen and verify the output page count before reporting success.
- Close every PDF handle in `finally` blocks.
- Avoid including local input/output paths in returned errors.

The Python script does not need to accept the requested download filename; PHP
owns that name while UUID-based temporary paths remain internal.

### Locking

A split does not mutate the current document, but it must read one stable
version while another structural operation might merge or reorder it.

Acquire the same per-document structural lock used by merge while the edited
source is materialized and split. Release it before serving the tokenized
download. If the lock is unavailable, return `409` and preserve the user's page
selection for retry.

---

## Frontend Files

Update:

- `resources/views/documents/edit-new/_floating-toolbar.blade.php`
  - Rename the button to **Merge / Split**.
- `resources/views/documents/edit-new/_merge-modal-pdfjs.blade.php`
  - Rename the shared heading.
  - Add accessible Merge/Split tabs.
  - Wrap current merge markup in the Merge panel.
  - Add the Split page grid, selection summary, and filename dialog.
- `resources/views/documents/edit-new-pdfjs.blade.php`
  - Add `data-split-pdf-url` to `#enpv-root`.
- `resources/js/edit-new-pdfjs/main.js`
  - Manage active tab, split selection, lazy thumbnails, edited-PDF
    materialization, filename prompting, destination choice, submit, download,
    and editor opening.
  - Keep Merge and Split busy states independent.
- `resources/css/edit-new-pdfjs/index.css`
  - Style tabs, selectable page cards, checkboxes, sticky count, and filename
    dialog using the current modal language.

If the combined code continues growing, extract it into:

```text
resources/js/edit-new-pdfjs/merge-split-pdf.js
```

Inject viewer, save, materialization, status, and reload dependencies rather
than duplicating editor-global state.

### Reusable modal state requirement

When either operation completes or fails, reset both JavaScript flags and real
DOM control state. Removing a CSS busy class is not enough; any control set via
`element.disabled = true` must be explicitly restored before reopening.

Add a regression test that opens the shared modal, completes a split, closes
it, reopens it, switches tabs, and verifies Close, Cancel, file selection, page
selection, and both primary actions remain usable.

---

## Activity and Cleanup

- Log successful selected-page splits as:
  - action: `Split PDF (selected pages)`
  - category: `split_export`
  - details: selected page count, one-based selected pages, source page count,
    output size
- Log failures using a safe error category, not document text or local paths.
- Store downloaded outputs for the existing ten-minute token expiration window.
- Persist editor outputs as independent documents with source ownership or
  guest-session access.
- Delete temporary materialized inputs after Python processing.
- Continue using `deleteFileAfterSend(true)` for the final tokenized download.
- Delete temporary outputs immediately on validation, process, or token setup
  failure.

---

## Test Plan

### Python tests

- Extract one selected page.
- Extract non-contiguous pages and verify output order.
- Select every page.
- Preserve mixed page sizes and rotations.
- Verify supported links, annotations, and widgets.
- Reject empty, damaged, encrypted, out-of-range, and empty-selection inputs.
- Verify output page count before success.

### Laravel feature tests

- Authorized owner, admin, and anonymous session can split.
- Inaccessible documents return `404`.
- `selected` mode requires at least one distinct page index and a filename.
- Zero-based indexes are converted to the correct one-based Python pages.
- Out-of-range indexes return `422`.
- Filename sanitization appends `.pdf` and removes path separators.
- The requested filename becomes the tokenized download name, never a storage
  path.
- The materialized upload is used instead of `documents.path`.
- Existing `each`, `specific`, `range`, and `custom` modes still work.
- Current PDF bytes, annotations, forms, notes, and document metadata remain
  unchanged.
- Temporary files are removed after success and all tested failures.
- A competing structural lock returns `409`.

### Browser regression test

1. Open a current PDF with at least five pages and a visible saved edit.
2. Click **Merge / Split** and switch to **Split**.
3. Select pages 1, 3, and 5 using page-card checkboxes.
4. Verify the summary says `3 pages selected`.
5. Click **Split selected pages**.
6. Verify the filename prompt defaults to `<base>_split.pdf`.
7. Enter a custom name and create the PDF.
8. Verify the downloaded PDF has three pages in order 1, 3, 5.
9. Verify the saved edit is visible when its page was selected.
10. Verify the original editor still has all pages and unchanged state.
11. Reopen the shared modal and verify all controls work.
12. Switch to Merge and verify its existing file selection/order flow still
    works.

Also cover Select all, Clear selection, keyboard toggling, filename validation,
canceling the filename prompt, a failed request followed by retry, and selection
reset after the underlying PDF changes.

---

## Suggested Implementation Order

1. Extend and test the selected-pages backend contract while preserving legacy
   split modes.
2. Refactor edited-PDF generation so Download and Split can share it.
3. Rename the toolbar button and convert the merge modal into a tabbed shell.
4. Add the Split page grid, lazy thumbnails, selection state, and live count.
5. Add the filename dialog and validation.
6. Wire save, materialize, split, tokenized download, cleanup, and status flow.
7. Add responsive/accessibility behavior and reusable-modal state resets.
8. Add feature and browser regression coverage.
9. Update `documentation/03-pdf-editor.md` once the combined tool ships.

---

## Definition of Done

- The toolbar button reads **Merge / Split** and remains next to Convert.
- The modal provides accessible Merge and Split tabs.
- Existing Merge behavior continues to work unchanged.
- Split displays every current page with a checkbox and thumbnail.
- The selected-page count updates immediately and uses correct singular/plural
  wording.
- The user is prompted for a valid filename before splitting.
- One new PDF downloads with exactly the checked pages in original order.
- Visible saved edits and form values are included in the split output.
- The current PDF and all of its editor state remain unchanged.
- The modal can be closed and reused after any merge or split outcome.
- Validation, authorization, quota, locking, cleanup, logging, and automated
  regression coverage are in place.
