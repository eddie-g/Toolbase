#!/usr/bin/env python3
"""Remove existing source text from a PDF without painting a background.

This is the live-editor fallback for pdf.js source text edits when direct
content-stream replacement cannot match or encode the new text. It applies
PyMuPDF text redactions with ``fill=None`` so vector form geometry remains
intact, then the browser overlay can render the replacement text without the
old glyphs showing behind it.
"""

from __future__ import annotations

import argparse
import json
import sys
from typing import Any

import fitz


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--in", dest="input_path", required=True)
    parser.add_argument("--out", dest="output_path", required=True)
    parser.add_argument("--edits", required=True, help="JSON file with source text edits")
    return parser.parse_args()


def pdf_bbox_to_rect(page: fitz.Page, bbox: dict[str, Any] | None) -> fitz.Rect | None:
    if not isinstance(bbox, dict):
        return None
    try:
        x = float(bbox["x"])
        y = float(bbox["y"])
        w = float(bbox["w"])
        h = float(bbox["h"])
    except Exception:
        return None
    if w <= 0 or h <= 0:
        return None
    page_height = page.rect.height
    rect = fitz.Rect(x, page_height - (y + h), x + w, page_height - y) & page.rect
    if rect.is_empty:
        return None
    return rect


def intersection_ratio(rect: fitz.Rect, target: fitz.Rect) -> float:
    if rect.is_empty or target.is_empty:
        return 0.0
    overlap = rect & target
    if overlap.is_empty:
        return 0.0
    area = max(0.0001, rect.width * rect.height)
    return (overlap.width * overlap.height) / area


def redaction_rects_for_edit(page: fitz.Page, edit: dict[str, Any]) -> list[fitz.Rect]:
    source_text = "" if edit.get("redact_bbox_only") else str(edit.get("original_text") or "").strip()
    source_rect = pdf_bbox_to_rect(page, edit.get("source_bbox"))
    occurrence = int(edit.get("occurrence") or 0)

    matches: list[fitz.Rect] = []
    if source_text:
        try:
            matches = [fitz.Rect(rect) & page.rect for rect in page.search_for(source_text)]
        except Exception:
            matches = []
        matches = [rect for rect in matches if not rect.is_empty]

    if source_rect is not None and matches:
        near = [rect for rect in matches if intersection_ratio(rect, source_rect) >= 0.35]
        if near:
            return near

    if matches:
        if occurrence < len(matches):
            return [matches[occurrence]]
        return [matches[-1]]

    if source_rect is not None:
        return [source_rect]

    return []


def apply_redactions(page: fitz.Page, remove_graphics: bool = False) -> None:
    graphics_mode = fitz.PDF_REDACT_LINE_ART_REMOVE_IF_TOUCHED if remove_graphics else fitz.PDF_REDACT_LINE_ART_NONE
    try:
        page.apply_redactions(images=0, graphics=graphics_mode)
        return
    except TypeError:
        pass
    try:
        page.apply_redactions(images=0)
        return
    except TypeError:
        pass
    page.apply_redactions()


def main() -> int:
    args = parse_args()
    with open(args.edits, "rb") as handle:
        edits = json.load(handle)
    if not isinstance(edits, list) or not edits:
        print("edits must be a non-empty array", file=sys.stderr)
        return 2

    doc = fitz.open(args.input_path)
    try:
        pending_by_page: dict[int, list[tuple[fitz.Rect, tuple[float, float, float] | None]]] = {}
        remove_graphics_by_page: dict[int, bool] = {}
        for edit in edits:
            if not isinstance(edit, dict):
                continue
            page_index = int(edit.get("page") or 0)
            if page_index < 0 or page_index >= doc.page_count:
                print(f"page index out of range: {page_index}", file=sys.stderr)
                return 2
            page = doc[page_index]
            rects = redaction_rects_for_edit(page, edit)
            if not rects:
                print(f"source text not found for page {page_index}: {edit.get('original_text')!r}", file=sys.stderr)
                return 2
            fill = (1, 1, 1) if edit.get("fill_white") else None
            for rect in rects:
                pending_by_page.setdefault(page_index, []).append((rect, fill))
            remove_graphics_by_page[page_index] = remove_graphics_by_page.get(page_index, False) or bool(edit.get("remove_graphics"))

        any_redaction = False
        for page_index, pending in pending_by_page.items():
            page = doc[page_index]
            for rect, fill in pending:
                page.add_redact_annot(rect, fill=fill)
                any_redaction = True
            apply_redactions(page, remove_graphics_by_page.get(page_index, False))

        if not any_redaction:
            print("no source text redactions were applied", file=sys.stderr)
            return 2
        doc.save(args.output_path, garbage=4, deflate=True)
        return 0
    finally:
        doc.close()


if __name__ == "__main__":
    raise SystemExit(main())
