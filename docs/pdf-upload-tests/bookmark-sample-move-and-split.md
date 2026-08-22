# PDF Bookmark Sample move and source-split regressions

Upload fixture: `c4611_sample_explain.pdf`  
Pages: 1 and 3

## Paragraph move keeps bold runs and registered marks

- Test ID: `991bec80-883f-4092-8acc-71de9206e73b`
- Saved annotation: `promoted_1_7`
- Scenario: `bookmark_sample_page1_move_paragraph_preserves_inline_runs`
- Text starts: `The left pane displays the available bookmarks for this PDF.`

The paragraph mixes `Arial` body text with an `Arial,Bold` run for
`Window > Show Bookmarks` and two registered signs that the source draws from
the `Symbol` font as the private-use character `U+F8E8`.

The test uploads the database-retained PDF as a disposable document, opens the
real `?pdfjs=1` editor, drags the promoted paragraph 120px down with the move
grip, and then downloads the PDF through the editor's own exact visible export
path. It asserts that:

- the promoted paragraph carries bold inline runs for `Window` and
  `Show Bookmarks` before it is moved;
- every registered sign drawn inside the paragraph's bounds belongs to the
  paragraph's own text instead of an independent `Symbol`-font source group;
- the move grip displaces the paragraph by the requested distance and flags it
  as a moved text overlay;
- the moved paragraph still renders both phrases bold in the live editor;
- no registered sign is left behind on the original source line;
- the download payload carries a bold span run covering both phrases;
- the download payload still contains both registered signs;
- the request used the exact visible conversion-safe download path;
- the downloaded PDF paints both phrases with a bold font at the moved
  position; and
- the downloaded PDF draws both registered signs at the moved paragraph and
  none at the original line.

Because the moved overlay is drawn with a runtime-extracted subset font whose
ToUnicode map is unusable, the downloaded-PDF assertions match spans by
geometry and font name rather than by text search. The expected displacement is
derived from the drag distance and the page canvas scale.

## Source split covers the underscore glyph

- Test ID: `568e8f2f-b71f-4afd-a814-5ac0d7364c3c`
- Saved annotation: `pdfjs_5244_2_2:26`
- Scenario: `bookmark_sample_page3_source_split_covers_underscore`
- Text starts: `Open the template design ap`

Page 3 draws the filename `ap_bookmark.IFD` as a single bold PDF.js text-layer
span. The editor splits that span across two source boxes, moving `ap` into the
preceding box and keeping `bookmark.IFD` in its own box.

The test is editor-only. It opens the disposable clone on page 3, resolves both
source boxes, and measures a per-character coverage map of the underlying text
layer. It asserts that:

- both source boxes derived from `ap_bookmark.IFD` resolve on page 3;
- the PDF.js text layer exposes the underscore glyph;
- the underscore survives the split in one of the two box texts;
- the underscore glyph sits inside one of the two bounding boxes rather than
  the gap between them; and
- every glyph of `ap_bookmark.IFD` is covered by a source bounding box.
