#!/usr/bin/env python3
"""Bake the same annotations the /edit-new editor receives into the clean
base PDF, using the *exact* writer + normalization pipeline that the
/admin/pdf-comparison "Edit-new snapshot" panel uses.

Inputs (positional):
  1. clean_pdf_path   — the empty/clean PDF (no source text)
  2. original_pdf_path — the source PDF (used for source-box normalization)
  3. annotations_json — JSON file containing the annotations array
                        (as produced by PdfTestController::documentInfo)
  4. output_pdf_path  — where to write the baked PDF

This is the server-side equivalent of the live editor canvas painting
unedited promoted annotations.  It guarantees /edit-new and the
pdf-comparison snapshot render identical pixels for those annotations.
"""

from __future__ import annotations

import json
import os
import shutil
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT / "python" / "test_helpers"))
sys.path.insert(0, str(REPO_ROOT / "python" / "pdf-editor"))

import fitz  # noqa: E402

# Re-use the exact normalization that compare_edit_new_snapshot.py applies.
from compare_edit_new_snapshot import (  # noqa: E402
    _load_writer,
    _normalize_unedited_promoted_annotations_to_source_boxes,
    _annotation_text_matches_original,
)


def _is_clean_unedited_promoted(ann: dict) -> bool:
    """Only bake annotations that the JS canvas would otherwise draw via
    drawOriginalSource (the clean source-preserve path).  Edited / moved /
    user-authored annotations must stay canvas-only — baking them would leave
    a stale ghost behind whenever the live state diverges from what was baked.
    """
    if not isinstance(ann, dict):
        return False
    if not bool(ann.get("promotedFromExtraction")):
        return False
    if bool(ann.get("promotedDirty")) or bool(ann.get("userAuthored")) or bool(ann.get("promotedReflowEnabled")):
        return False
    if not _annotation_text_matches_original(ann):
        return False
    return True


def main() -> int:
    if len(sys.argv) != 5:
        print("usage: bake_edit_new_base.py CLEAN_PDF ORIGINAL_PDF ANNOTATIONS_JSON OUTPUT_PDF", file=sys.stderr)
        return 2

    clean_pdf, original_pdf, annotations_json, output_pdf = sys.argv[1:5]

    if not os.path.isfile(clean_pdf):
        print(f"clean_pdf not found: {clean_pdf}", file=sys.stderr)
        return 1
    if not os.path.isfile(original_pdf):
        print(f"original_pdf not found: {original_pdf}", file=sys.stderr)
        return 1
    if not os.path.isfile(annotations_json):
        print(f"annotations_json not found: {annotations_json}", file=sys.stderr)
        return 1

    with open(annotations_json, "r", encoding="utf-8") as fh:
        annotations = json.load(fh)
    if not isinstance(annotations, list):
        print("annotations_json must contain a JSON array", file=sys.stderr)
        return 1

    writer = _load_writer()

    # Copy clean → output, then apply normalized annotations in-place.
    out_dir = os.path.dirname(output_pdf)
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)
    shutil.copy2(clean_pdf, output_pdf)

    original_doc = fitz.open(original_pdf)
    try:
        # Filter to only the annotations the canvas would draw via the
        # source-preserve path.  Edited / moved / userAuthored annotations
        # are intentionally left for the canvas to render so that live
        # in-flight changes never produce a stale "ghost" of the saved state.
        clean_annotations = [a for a in annotations if _is_clean_unedited_promoted(a)]
        clean_annotations = _normalize_unedited_promoted_annotations_to_source_boxes(clean_annotations, original_doc)
        if clean_annotations:
            writer.apply_annotations(output_pdf, clean_annotations)
    finally:
        original_doc.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
