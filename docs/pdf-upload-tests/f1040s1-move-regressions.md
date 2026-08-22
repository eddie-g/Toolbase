# f1040s1 move regressions

Fixture: `tests/OverlayEditor/f1040s1.pdf` (upload test `24`, retained document
`5304`). All scenarios upload a disposable clone, drive the real PDF.js editor,
and delete the clone in cleanup.

## Move Part I out of its filled form cell

- Saved annotation: `pdfjs_5304_0_0:18`
- Scenario: `f1040s1_page1_move_part_header_clears_source_glyphs`
- Exact text: `Part I`

`Part I` on this form is white text painted inside a solid black cell. The test
drags the annotation clear of the cell with the real move control and asserts
that:

- the annotation is captured as light glyphs on a dark fill before the move;
- every light canvas pixel inside the cell is covered by an opaque
  `.enpv-source-mask-run` segment afterwards, so no sliver of the original
  glyphs is left behind;
- the mask repaints the cell in its own dark colour instead of cutting a white
  hole through the form artwork; and
- the moved overlay keeps its white source colour instead of flipping to the
  cell fill colour.

The residue assertion is measured directly from the page canvas rather than
from a screenshot, so it catches sub-pixel mask truncation: before the fix the
mask stopped mid-way through the final glyph row and left 23 uncovered pixels.
