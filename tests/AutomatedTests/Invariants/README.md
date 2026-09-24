# Invariant tester for the pdf.js editor

Finds editor bugs on many real PDFs without anyone pointing at them. Each job
uploads a fresh copy of a PDF as a guest document, picks a block and a random
action sequence from a seed, drives the editor with **real** mouse and keyboard
input (Playwright `page.mouse` / `page.keyboard`), and checks eight invariants
after every step. A failing sequence is shrunk to a minimal repro script, and
failures are grouped into signatures.

It never opens, modifies or downloads-over an existing document: every job
works on its own fresh upload (`inv_s<seed>_<pdf>_<pid>_<n>.pdf`).

## Run it

```bash
cd tests/AutomatedTests/Invariants

# pick the corpus once (copies PDFs out of the Sail container; never touches originals)
../../../.venv/bin/python pick_corpus.py            # -> corpus.json

# quick mode: 4-5 PDFs x 5 seeds, ~4-10 minutes
node run_invariants.cjs --quick --out results/quick

# a longer run with shrinking, time-boxed
node run_invariants.cjs --corpus corpus.json --seeds 1-50 --steps 12 --workers 3 \
     --minutes 75 --shrink --out results/full1

# one PDF, one block, only some invariants
node run_invariants.cjs --pdf ../../OverlayEditor/drylab.pdf --block promoted_2_4 \
     --seeds 1-10 --invariants 1,2,3 --out results/drylab-2-4 --verbose
```

Options: `--seeds 1-50` (or `3,7,9-12`), `--steps N`, `--workers N` (parallel
browsers, default 2; 3 is fine on a 4-core box), `--minutes M` (stop starting
new jobs after M minutes; shrinking gets up to 45 more), `--keep-going` (record
the first violation of *each* invariant instead of stopping at the first one),
`--shrink`, `--shrink-budget N` (fresh-upload trials per signature, default 14),
`--max-pdfs N`, `--verbose`.

The app must be up at `http://localhost:8081` (override with
`INVARIANTS_BASE_URL`). Everything runs on the host (Playwright from the
repo's `node_modules`, PyMuPDF from `.venv`).

Deterministic regression mode (`--script`, one fresh upload, exit code 1 on any violation or error):

```bash
C=tests/AutomatedTests/Invariants/corpus
node run_invariants.cjs --pdf $C/ss-5.pdf --block promoted_3_4 --script type-before:Contact:"123 " --invariants 7 --out results/reg-ss5
```

`--script type-before:<word>:<text>` (or `type-after:`) = open the block from the menu, real click just
before the first word starting with `<word>` (caret verified, nudged with arrow keys if one character
off), type `<text>`, click outside, Download PDF. `--script steps.json` replays any JSON step list.

Unit check of the I7 row-origin geometry (no browser, no server):

```bash
../../../.venv/bin/python unit/test_i7_row_origin.py
```

It feeds `lib/fitz_tools.py row_geometry` the untouched and edited SS-5 downloads from the real
endpoint and the same payload run through the pre-4db22b2 exporter (`unit/offline_export.py`, the
ONLY offline replay in this folder: it applies Laravel's TrimStrings and nothing else of the
controller, so everything else must go through `/download-annotated-pdf`). It asserts dx ≈ −55 pt for
the old exporter and first glyph/baseline within 0.5 pt for the server download. Regenerate the
fixtures by running the regression command above, copying `baselines/*.pdf`, `download4.pdf`,
`.spec.json`, `.payload.json` into `unit/fixtures/`, and
`git show 4db22b2^:python/pdf-editor/apply_annotations_direct_new.py > <scratch>/old.py &&
.venv/bin/python unit/offline_export.py <scratch>/old.py <ss-5.pdf> unit/fixtures/ss5_payload.json unit/fixtures/ss5_old_exporter_4db22b2parent.pdf`.

A repro script replays one minimal sequence on a fresh upload:

```bash
node results/full1/repros/repro-<signature>.cjs [--verbose]
# exit 1 = invariant still violated (bug present), 0 = holds, 2 = harness error
```

## What each invariant means

Tolerances live in `TOL` at the top of `lib/session.cjs`; every report carries
the measured numbers, not only pass/fail. `*Px100` tolerances are CSS px at
100% zoom and are multiplied by the viewer zoom (the tester runs at the default
fit, usually 190%), with a floor of 1.05 px because Chromium snaps text
baselines to whole pixels at deviceScaleFactor 1.

| id | name | checked on | fails when |
|---|---|---|---|
| I1 | Open/close no-op | `enter`, `openClose`, `exit`, caret clicks, double-click, drag-select, navigation keys | Opening a block moves a word vs. what was shown (the PDF's own glyph boxes from PyMuPDF for a pristine block: every row's first word > 0.5 px@100% in x or y, or any word > 2 px@100% relative to its row's first word — browser fonts advance differently from embedded fonts; the last displayed state for an edited block: 0.5 px@100%). Leaving without typing changes a word, the box (> 0.5 pt) or the persisted annotation (`__enpv.persistedAnnotation`). A click/selection/arrow key moves any word or changes box height. |
| I2 | Keystroke integrity | every typed character, Backspace, Delete, Enter | The block's non-whitespace text (soft hyphens and zero-width characters removed) is not exactly the old text with the key's edit applied at the caret. What Backspace/Delete remove is learned beforehand with `Selection.modify` and the selection restored. `typeDelete` also requires the text to come back exactly. |
| I3 | Row locality | typed characters, Backspace/Delete with a collapsed caret | A word on another row moves, a word before the caret on the same row moves, or the box height changes. Allowed only: typing into a row with less than 2.5 average characters of room (it has to wrap). A delete that pulls the next row's word up is reported under its own locus. |
| I4 | Neighbour isolation | after every step | Any other box on the page changes text, geometry (> 0.5 pt) or persisted state. |
| I5 | Session stability | `cycles` (4 open/commit cycles, generated after font/size changes) | Font size (> 0.1 px), row count, box height (> 0.5 pt) or text drift between cycles. |
| I6 | Move cleanliness | `move` (drag of the move grip) | The block's ORIGINAL area, screenshotted with editor chrome hidden, has > 6 pixels clearly darker (luma −60) than the original page rendered by PyMuPDF with the block's glyphs redacted (5x5-min: tolerant of 2 px registration and renderer anti-aliasing); or the new place renders no text (< 12 dark pixels). |
| I7 | Download fidelity | `download` | Compared with an untouched download of the same PDF (cached per PDF+bundle in `<out>/baselines/`), both from the real `/download-annotated-pdf` endpoint: any row outside the block's old/new area changes text, font, colour or moves > 0.5 pt, or a new row appears; the rows inside the block's area (read top-to-bottom) don't contain the editor's text; a row whose first word is unchanged loses its first span's colour. **Edited-row origin** (locus `exporter edited-row origin`): every row of the block, paired with the same row of the untouched download (after removing a move offset), must keep its first non-space glyph x (rawdict char bbox x0) and baseline (char origin y) within 0.5 pt, and in a part-edited row every word before the first changed word keeps its x within 0.5 pt. Per-row dx/dy/prefix-dx are in `data.rowGeometry` and summarised as `i7Geometry` in result.json. **Style fidelity** (locus `exporter style: …`): when the block paints its own text (it was edited), every editor word (computed colour, weight ≥ 600, italic, size, baseline) is paired by text with the download's words in the block's area; a word whose colour differs by more than 48 in any channel, whose bold/italic differs, whose size differs by more than 0.6 pt, or whose row offset from the block's first word moved by more than max(2.5 pt, 45% of its size) (a lost empty row or break) is reported. Details in `data.style`. Sequences that type, restyle or press Enter always end with a download; `--script style-at` is a dedicated sweep (seeded caret, colour/bold/italic, typed word, maybe Enter, download). |
| I8 | Reload fidelity | `reload` | After reloading the editor, any word of the block moves (0.5 px@100%), the box moves (> 0.5 pt), the text differs, or a neighbour box changed. |

## Actions

Generated from the seed by `lib/generator.cjs` as plain JSON (so they can be
saved, shrunk and replayed). Targets are fractions (row, word) resolved against
the live block: word start / end, between words, just after a list marker, row
end, inside the hanging indent, mid-word; points are kept 8 px away from the
resize handles so a click never becomes a resize.

`enter` (via the box menu or like a user: click the box, double-click the text,
click to place the caret), `exit` (click outside / Escape), `openClose`,
`click`, `dblclick`, `dragSelect`, `type`, `key` (Backspace, Delete, Enter,
Home, End, arrows), `typeDelete`, `undo`/`redo` (Ctrl+Z/Ctrl+Y), `format`
(`#afb-font` select, `#afb-size` slider click, `#afb-bold`, `#afb-italic`,
`#afb-text-color`), `cycles`, `move`, `reload`, `download`. After a format-bar
control the tester clicks back into the text before typing (as a person must).

## Reading the output

```
results/<run>/
  summary.md / summary.json   per invariant, per signature, per PDF, per PDF class
  meta.json                   args, tolerances, corpus
  jobs/<pdf>/seed-<n>/        result.json (steps, block, kind, violations with numbers, notes, checks run)
                              v1_I3_before.png / _after.png   the block before/after the failing step
                              v1_I3_editor.png                the whole editor viewport
                              v1_I3_pdf.png                   the block's area in the original PDF (PyMuPDF)
                              move<k>_old.png / _ink.png / _expected.png   I6 evidence (ink in red)
                              download<k>.pdf / .spec.json    I7 evidence
  baselines/                  untouched downloads per PDF+bundle
  shrink/<signature>/trialN/  shrinker runs that still failed
  repros/repro-<signature>.cjs
```

A **signature** is `invariant | block kind | action | locus`, e.g.
`I3|paragraph|type|row fit: box height changes on keystroke`. Block kinds come
from the PDF words inside the box (list item, justified paragraph, heading,
table cell, single row, hanging indent, paragraph). The locus is a coarse hint
inferred from the numbers only (row origin vs intra-row drift, box height,
pull-up, mask, exporter untouched row, ...) — confirm it before blaming a
function.

`bundleStart` / `bundleEnd` in every result and `bundle` on every violation
name the served editor bundle (`public/build/manifest.json`): the editor is
rebuilt while runs happen, so a signature that appears on only one bundle may
already be fixed.

## Adding an invariant

1. Give it an id (`I9`) and a name in `lib/report.cjs` (`INV_NAMES`) and add it
   to `ALL_INVARIANTS` in `lib/session.cjs`.
2. Put its tolerance(s) in `TOL`.
3. Check it in `Session`: either inside the step executor that triggers it
   (`exec()` switch) or after every step next to `checkNeighbours()` in
   `run()`. Call `this.count('I9')` whenever it is evaluated and
   `await this.violate('I9', step, this.stepIndex, message, data)` with the
   measured numbers in `data`.
4. Teach `locus()` in `lib/signature.cjs` how to name its failures.
5. If it needs a new user action, add the op to `lib/generator.cjs` (JSON only,
   targets as fractions) and its executor to `exec()`.

## Files

- `run_invariants.cjs` — CLI, job pool, shrink scheduling, summaries.
- `pick_corpus.py` — corpus picker/classifier (PyMuPDF) -> `corpus.json`.
- `lib/harness.cjs` — browser, guest upload, signed-in shim, editor open, box inventory, download (copied from the SourceText suite).
- `lib/measure.cjs` — in-page per-word geometry (Range rects), caret/text state, key plans, click targets, word alignment.
- `lib/session.cjs` — one session: block choice, step executors, all invariant checks, tolerances.
- `lib/generator.cjs`, `lib/prng.cjs` — seeded sequences.
- `lib/shrink.cjs` — delta-debugging shrinker + repro script writer.
- `lib/signature.cjs` — block kinds, loci, signatures. `lib/report.cjs` — summary.json/md.
- `lib/fitz_tools.py`, `lib/fitz.cjs` — PyMuPDF words, renders, leftover-ink check, export diff.
