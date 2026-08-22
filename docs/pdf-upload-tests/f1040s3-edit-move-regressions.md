# f1040s3 PDF.js edit and drag regressions

Upload fixture: `f1040s3.pdf`  
Page: 1

## Bounding box snapping

- Test ID: `7d3c382c-d127-49ba-80b1-08c961af1ca5`
- Saved annotation: `pdfjs_4506_0_0:113`
- Scenario: `f1040s3_page1_bounding_box_snapping`
- Text starts: `Total other payments or refundable credits. Add lines 13a through 13z`

The test uploads the database-retained PDF as a disposable document, opens
the real `?pdfjs=1` editor, clicks Edit, and types one character. It asserts
that:

- the source, annotation, and per-run font sizes do not change;
- the box geometry and text remain unchanged;
- active edit uses the canvas-backed preview with transparent editable text;
- no source mask is created before the first mutation; and
- the visible source-canvas crop is pixel-identical before and during edit;
- the blue annotation box does not move or resize on the first keystroke;
- the revealed DOM glyph origin stays within `0.75px` of the captured PDF.js
  glyph origin; and
- the typed character is retained.

## Drag preview spacing

- Test ID: `a37cf4db-92b4-41c9-87e5-5502fcd0c380`
- Saved annotation: `pdfjs_4506_0_0:71`
- Scenario: `f1040s3_page1_drag_preview_spacing`
- Text starts: `Credit for previously owned clean vehicles. Attach Form 8936`

The test holds the real move grip down while sampling the active pointermove
state. It asserts that the live drag:

- moves without resizing the annotation;
- retains the 22.8px source font size;
- renders all six captured source runs;
- keeps the source run offsets, widths, and complete 784.546875px advance;
- uses the same spacing after pointerup; and
- leaves the annotation text unchanged.

## Move without a false underline

- Test ID: `d8813591-1371-4bb2-b416-89b1c7a79c4c`
- Saved annotation: `pdfjs_4506_0_0:115`
- Scenario: `f1040s3_page1_move_without_false_underline`
- Text starts: `15 Add lines 9 through 12 and 14. Enter here and on Form 1040`

The test drags the annotation with the real PDF.js move control and invokes
the same request path as `downloadAnnotatedPdf()`. It asserts that:

- root CSS, source-run metadata, and the download payload contain no underline;
- the downloaded PDF contains the target text exactly once at moved coordinates;
- no new horizontal stroke appears along the moved text baseline; and
- the three original Schedule 3 form-rule segments at PDF y-coordinate 612
  remain present at their exact coordinates.

## Delete Name label and preserve the form

- Test ID: `31af9448-d870-4adb-b181-09f006de7a78`
- Saved annotation: `pdfjs_4506_0_0:12`
- Scenario: `f1040s3_page1_delete_name_preserves_form_artwork`
- Exact text: `Name(s) shown on Form 1040, 1040-SR, or 1040-NR`

The test deletes only the saved annotation and invokes the real
`downloadAnnotatedPdf()` request path. It asserts that:

- the target text is gone from the editor and export;
- `pdfjs_4506_0_0:13` (`Your social security number`) remains present;
- all three horizontal source-rule segments at `y≈84` retain their coordinates
  and stroke widths; and
- the protected form-rule raster crop is unchanged, so the deletion mask does
  not cover the line.

## Move Part I text and leave the source tile in place

- Test ID: `16721c0a-ecd6-47ae-affe-55b6c1044b32`
- Saved annotation: `pdfjs_4506_0_0:14`
- Scenario: `f1040s3_page1_move_part_header_preserves_source_tile`
- Exact text: `Part I`

The test drags the annotation with the real move control and downloads the
result. It asserts that:

- the source PDF's black `Part I` tile remains at its original coordinates;
- only the text moves;
- the moved text keeps the white glyph color it had inside the black tile, in
  the live editor and in the downloaded PDF (colour fidelity: a moved run is
  never re-contrasted against its new surroundings);
- no dark background moves with or is copied behind the text, so the white
  destination stays white; and
- the neighboring `Nonrefundable Credits` text and geometry remain unchanged.

All scenarios delete their disposable documents in cleanup and never edit
the retained upload-test document.
