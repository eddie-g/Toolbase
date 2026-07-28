# SS-5 uploaded annotation regression tests

## Status

The four saved SS-5 annotation cases are implemented. Each case has a stable
UUID in `pdf_upload_test_cases.test_id`; editing its comment or annotation
metadata does not change that test ID.

## Saved test catalog

| Test ID | Saved annotation | Scenario |
| --- | --- | --- |
| `e4403132-fbc0-49ff-bbf3-4400d1961348` | `pdfjs_4471_3_3:19` | Delete page-4 source row `:20`, including its underline, while preserving `:19` and `:21` |
| `9c0bf2a2-9d6a-4269-bfc9-9db7b1e4405b` | `pdfjs_4471_0_0:11` | Swap the replacement-card row with `pdfjs_4471_0_0:9` |
| `ededf05e-8dfe-4e91-81b3-c9267e894204` | `pdfjs_4471_0_0:9` | Swap the original-card row with `pdfjs_4471_0_0:11` |
| `35e296d4-4908-4c30-a65a-f09dd1d660a6` | `promoted_1_5` | Delete the requested assistance sentence and retain surrounding paragraph text |

## Verified runs

All four cases were executed through `PdfTestController::runSingleTest()` on
July 23, 2026. The controller created the same configuration and persisted
results used by the admin **Test** buttons.

| Test ID | Result report | Checks | Status |
| --- | ---: | ---: | --- |
| `e4403132-fbc0-49ff-bbf3-4400d1961348` | `200` | `13/13` | PASS |
| `9c0bf2a2-9d6a-4269-bfc9-9db7b1e4405b` | `197` | `10/10` | PASS |
| `ededf05e-8dfe-4e91-81b3-c9267e894204` | `198` | `10/10` | PASS |
| `35e296d4-4908-4c30-a65a-f09dd1d660a6` | `199` | `11/11` | PASS |

## Saved annotation test case

The uploaded PDF is the parent fixture, and each selected annotation has its
own child record in `pdf_upload_test_cases`. The current saved case is:

| Field | Value |
| --- | --- |
| Parent upload-test ID | `2` |
| Test ID | `e4403132-fbc0-49ff-bbf3-4400d1961348` |
| Source document | `4471` |
| File | `ss-5.pdf` |
| Saved annotation ID | `pdfjs_4471_3_3:19` |
| Page | 4 (`page_index = 3`) |
| Saved target text | `Paperwork Reduction Act Statement -This information collection meets the requirements of 44 U.S.C. § 3507, as` |
| Instruction | `delete this item, then run downloadAnnotatedPdf(). make sure the underline is also removed from the resulting pdf. make sure the annotation :19 and :21 and present and not masked in anyway or shape.` |

## Verified page-4 PDF.js mapping

The IDs were inspected using the same blue-box renderer as
`/edit-new?pdfjs=1`. The document-number component changes on a disposable
clone, but the page and source-group suffixes remain the same.

| ID suffix | Text |
| --- | --- |
| `3_3:18` | `not be used in decisions about your application.` |
| `3_3:19` | `Paperwork Reduction Act Statement -This information collection meets the requirements of 44 U.S.C. § 3507, as` |
| `3_3:20` | `amended by section 2 of the Paperwork Reduction Act of 1995. You do not need to answer these questions unless we` |
| `3_3:21` | `display a valid Office of Management and Budget control number. We estimate that it will take between 5 and 60` |
| `3_3:22` | `minutes to read the instructions, gather the facts, and answer the questions. SEND OR BRING THE COMPLETED` |

The phrase previously identified as having the problematic drawn underline,
`Paperwork Reduction Act of 1995`, belongs to `:20`, not `:19`. Therefore,
deleting `:20` while protecting the adjacent `:19` and `:21` is internally
consistent. Deleting `:19` while also requiring `:19` to remain present is not.

## Run PDF Tests interface

Each saved annotation case has its own comment and its own **Test** button.
Its stable **Test ID** is shown above the annotation ID and is also used as
the persisted result key.
The parent uploaded-PDF row keeps the shared **Select annotation** and
**Delete** actions.

When clicked, the row will show a running state and then an inline PASS, FAIL,
or ERROR result. The result should link to the generated PDF and diagnostic
artifacts. Only the admin who owns the upload-test record may run it.

## Execution flow

1. Load the parent `pdf_upload_tests` fixture and the selected
   `pdf_upload_test_cases` record, then validate its annotation ID, page index,
   and instruction.
2. Copy the database-retained original PDF into a disposable test document.
   Do not edit document `4471` or its saved annotation state.
3. Open the disposable document in `/edit-new?pdfjs=1`.
4. Render page 4 and resolve the saved ID by its stable page/group suffix. For
   example, `pdfjs_4471_3_3:20` becomes
   `pdfjs_<temporary-document-id>_3_3:20`.
5. Record the text and PDF-coordinate bounds of `:19`, `:20`, and `:21`.
6. Delete only source group `:20` through the PDF.js annotation deletion path.
7. Submit the same payload used by the real PDF.js Download PDF action to
   `DocumentController::downloadAnnotatedPdf()`:

   - live visible annotations;
   - session annotations;
   - AcroForm values;
   - deleted promoted source keys;
   - `use_exact_download_path = true`;
   - `use_pdfjs_visible_export = true`;
   - `use_conversion_safe_export = true`;
   - the disposable session ID.

8. Save the returned PDF as a test artifact.
9. Inspect page 4 with PyMuPDF and a raster comparison.
10. Delete the disposable document and its generated files.

## Page-1 swap scenarios

Both saved swap cases execute independently and retain separate Test IDs and
results. Each run:

1. Resolves source rows `0_0:9` and `0_0:11` on a disposable clone.
2. Uses the PDF.js annotation move grip to place each row at the other row's
   original browser position.
3. Runs the real `downloadAnnotatedPdf()` request.
4. Verifies the payload contains exactly those two moved-source annotations.
5. Verifies both strings occur exactly once in the downloaded PDF.
6. Verifies each string's extracted PDF coordinates match the other string's
   original coordinates within three points.
7. Retains before/after screenshots, original/result/diff rasters, the
   downloaded PDF, and JSON diagnostics.

## Page-1 sentence-deletion scenario

The saved review target is the promoted paragraph `promoted_1_5`. A clean
disposable extraction exposes its physical PDF.js lines separately, so the
runner resolves the saved target text to source line `0_0:17`, which contains:

`will return any documents submitted with your application. For assistance call us at 1-800-772-1213 or visit our`

The test uses the PDF.js pencil/in-place contenteditable path to select and
delete only:

`For assistance call us at 1-800-772-1213 or visit our`

It then runs `downloadAnnotatedPdf()` and asserts:

- the sentence exists once in the source and zero times in the result;
- the edited line prefix remains searchable and within 0.75 points of its
  original coordinates;
- the preceding paragraph lines remain searchable;
- `website at www.socialsecurity.gov.` remains searchable at exactly its
  original coordinates;
- the payload contains exactly one edited source annotation;
- the result includes the downloaded PDF, before/after screenshots,
  original/result/diff rasters, and JSON diagnostics.

## Assertions

### Preconditions

- The stored fixture, filename, page, target ID, target text, and instruction
  match the expected values.
- Page 4 contains source boxes `:19`, `:20`, and `:21` with the text listed
  above.
- The target phrase has a drawn underline in the original PDF.

### Deletion

- Only the confirmed target is marked deleted in the payload.
- The target text is absent from the downloaded PDF at its original bounds.
- No target glyph ink remains in the target bounds.
- No horizontal underline segment remains beneath the deleted target phrase.

### Neighbor preservation

- The complete `:19` and `:21` text remains searchable in the downloaded PDF.
- Their extracted text bounds remain aligned with their original bounds.
- Their raster regions retain their original glyph ink.
- No opaque rectangle, source mask, eraser, or annotation shape overlaps
  either survivor region.
- A page-4 raster diff shows that changes are confined to the deleted target
  and its underline, within a small antialiasing tolerance.

Because runtime annotation IDs are not embedded in the final PDF, downloaded
PDF assertions will identify `:19` and `:21` using the text and PDF-coordinate
bounds captured before download.

## Artifacts

The test should retain:

- the generated annotated PDF;
- original and resulting page-4 PNGs;
- a page-4 diff PNG;
- crops covering `:19`, the deletion target, and `:21`;
- a JSON diagnostic containing source IDs, text, bounds, drawings, masks, and
  assertion results.

## Confirmed decisions

1. Delete source group `:20`, which contains the underlined phrase
   `Paperwork Reduction Act of 1995`.
2. Remove only the underline owned by deleted source group `:20`.
3. Show a **Test** button and stable UUID on every saved annotation case.
   Unsupported instructions produce a persisted ERROR result explaining that
   no JavaScript scenario exists yet.
4. Show PASS, FAIL, or ERROR inline on that annotation case and persist the
   same run in the existing detailed PDF test-results table.
5. Protect `:19` and `:21` using all three checks: searchable text and bounds,
   unchanged raster crops, and no newly-added opaque PDF drawing overlapping
   either survivor.
6. Always upload the database-retained original into a disposable document.
   Never execute the operation against source document `4471`.
