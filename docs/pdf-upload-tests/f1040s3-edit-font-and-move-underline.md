# f1040s3 edit and drag typography tests

Fixture: `f1040s3.pdf`  
Upload fixture ID: `12`  
Source document ID: `4506`  
SHA-256: `008cfd3fe3ebd0860452069b90fd0a3d53dda6dd8a5d870278370aa899a63f82`

Each saved annotation is an independent upload-test case with its own stable
UUID. All scenarios create and delete a disposable editor clone; they never
modify document `4506`.

## Bounding box snapping

- Test ID: `7d3c382c-d127-49ba-80b1-08c961af1ca5`
- Saved target: `pdfjs_4506_0_0:113`
- Runtime suffix: `0_0:113`
- Text starts with: `Total other payments or refundable credits. Add lines 13a through 13z`
- JavaScript scenario: `f1040s3_page1_bounding_box_snapping`

The scenario selects the real PDF.js box, opens Edit, and types the first
character. It verifies that:

- source, annotation, and per-run font sizes do not change;
- the exact PDF canvas remains the visible edit preview;
- no source mask is added merely by entering Edit;
- text and dirty state remain unchanged;
- annotation geometry is unchanged; and
- the before/active-edit canvas crops are pixel-identical;
- after the first keystroke reveals the DOM editor, the annotation box has
  zero position/size drift;
- the visible text Range origin remains within `0.75px` of the captured
  PDF.js glyph origin; and
- the first typed character is retained.

The editor implements this as bounding box snapping: it leaves the expanded
annotation rectangle in place and applies a bounded relative offset only to
the editable text layer. This preserves the box area used for masking and
dragging while aligning the DOM glyphs with the source PDF.js Range.

## Drag preview spacing

- Test ID: `a37cf4db-92b4-41c9-87e5-5502fcd0c380`
- Saved target: `pdfjs_4506_0_0:71`
- Runtime suffix: `0_0:71`
- Text starts with: `Credit for previously owned clean vehicles. Attach Form 8936`
- JavaScript scenario: `f1040s3_page1_drag_preview_spacing`

The scenario uses the real move grip and deliberately keeps the mouse button
down after `pointermove`, so it measures the drag preview before `pointerup`.
It verifies that:

- the production drag handler promotes and translates the exact `:71` box;
- the bounding box moves without resizing;
- source, annotation, computed, and per-run font sizes stay unchanged;
- all six source runs retain their captured offsets and glyph widths;
- the full dot-leader row keeps its captured `784.546875px` advance;
- pointerup does not introduce a second spacing change; and
- dragging does not alter the annotation text.

The original failure reapplied the anchor span's `scaleX(0.854316)` to the
entire six-run composite row, compressing it to `664.938416px`. Span-backed
drag rendering now derives its horizontal fit from the complete captured
PDF.js glyph union. The resulting live-drag transform is approximately
`scaleX(1.00799)` and the rendered advance exactly matches the source.

## Move without a false underline

- Test ID: `d8813591-1371-4bb2-b416-89b1c7a79c4c`
- Saved target: `pdfjs_4506_0_0:115`
- Runtime suffix: `0_0:115`
- Text starts with: `15 Add lines 9 through 12 and 14. Enter here and on Form 1040`
- JavaScript scenario: `f1040s3_page1_move_without_false_underline`

The scenario drags the real PDF.js box and calls `downloadAnnotatedPdf()`. It
verifies that:

- the source and moved DOM have no underline CSS, ranges, segments, or flags;
- the download payload contains exactly one clean moved annotation;
- the conversion-safe PDF.js export path is used;
- the downloaded PDF contains the text exactly once at moved coordinates;
- no horizontal stroke is added along the moved text baseline; and
- all three original Schedule 3 form-rule segments at `y=612` remain present.

## Delete the Name label without deleting form artwork

- Test ID: `31af9448-d870-4adb-b181-09f006de7a78`
- Saved target: `pdfjs_4506_0_0:12`
- Runtime suffix: `0_0:12`
- Exact text: `Name(s) shown on Form 1040, 1040-SR, or 1040-NR`
- JavaScript scenario: `f1040s3_page1_delete_name_preserves_form_artwork`

The scenario deletes the exact PDF.js annotation with the production Delete
control and calls `downloadAnnotatedPdf()`. It verifies that:

- the target text is absent from the editor and downloaded PDF;
- the neighboring `:13` annotation, `Your social security number`, remains
  present at its original coordinates;
- all three source form-rule segments at `y≈84` retain their exact coordinates
  and stroke widths; and
- the protected form-rule raster crop is unchanged, proving that no white
  deletion mask covers the line.

## Move the Part I text while preserving its source tile

- Test ID: `16721c0a-ecd6-47ae-affe-55b6c1044b32`
- Saved target: `pdfjs_4506_0_0:14`
- Runtime suffix: `0_0:14`
- Exact text: `Part I`
- JavaScript scenario: `f1040s3_page1_move_part_header_preserves_source_tile`

The black rectangle behind `Part I` is source PDF form artwork, not part of
the movable text annotation. The scenario uses the real move grip and calls
`downloadAnnotatedPdf()`. Its contract is:

- the original black tile remains at its source coordinates;
- only the `Part I` text moves;
- the moved text is rendered black and remains visible on its white
  destination in both the editor and downloaded PDF;
- no black or dark background is copied to or moved with the text; and
- the neighboring `Nonrefundable Credits` heading remains unchanged.

This distinction prevents moving a sampled source background as though it
were annotation-owned styling while still preserving the original form
header artwork.
