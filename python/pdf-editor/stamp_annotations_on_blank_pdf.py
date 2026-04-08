#!/usr/bin/env python3

import json
import os
import shutil
import sys
from typing import Any, Dict, List, Optional, Tuple

import fitz

from apply_annotations_direct_new import apply_annotations

LETTER_WIDTH = 612.0
LETTER_HEIGHT = 792.0


def load_json_arg(arg: str):
    if arg.startswith("@"):
        with open(arg[1:], "r", encoding="utf-8") as fh:
            return json.load(fh)
    with open(arg, "r", encoding="utf-8") as fh:
        return json.load(fh)


def compute_page_size(
    annotations: List[Dict[str, Any]],
    explicit_width: Optional[float] = None,
    explicit_height: Optional[float] = None,
) -> Tuple[float, float]:
    max_right = 0.0
    max_top_extent = 0.0

    for annotation in annotations:
        if not isinstance(annotation, dict):
            continue
        try:
            x = float(annotation.get("pdfX", 0) or 0)
            y = float(annotation.get("pdfY", 0) or 0)
            width = float(annotation.get("pdfWidth", 0) or 0)
            height = float(annotation.get("pdfHeight", 0) or 0)
        except Exception:
            continue
        if width <= 0 or height <= 0:
            continue
        max_right = max(max_right, x + width)
        max_top_extent = max(max_top_extent, y + height)

    default_width = float(explicit_width) if explicit_width is not None else LETTER_WIDTH
    default_height = float(explicit_height) if explicit_height is not None else LETTER_HEIGHT
    page_width = max(default_width, max_right + 36.0, 144.0)
    page_height = max(default_height, max_top_extent + 36.0, 144.0)
    return page_width, page_height


def stamp_annotations_on_blank_pdf(
    annotations: List[Dict[str, Any]],
    output_pdf_path: str,
    page_width: Optional[float] = None,
    page_height: Optional[float] = None,
    base_pdf_path: Optional[str] = None,
    source_pdf_path: Optional[str] = None,
) -> Tuple[float, float]:
    normalized_annotations = [annotation for annotation in annotations if isinstance(annotation, dict)]
    if not normalized_annotations:
        raise ValueError("No valid annotations were provided.")
    output_dir = os.path.dirname(output_pdf_path)
    if output_dir:
        os.makedirs(output_dir, exist_ok=True)

    if base_pdf_path:
        if not os.path.isfile(base_pdf_path):
            raise FileNotFoundError(f"Base PDF not found: {base_pdf_path}")
        shutil.copyfile(base_pdf_path, output_pdf_path)
        base_doc = fitz.open(output_pdf_path)
        try:
            page_rect = base_doc[0].rect
            width = float(page_rect.width)
            height = float(page_rect.height)
        finally:
            base_doc.close()
    else:
        width, height = compute_page_size(normalized_annotations, page_width, page_height)
        doc = fitz.open()
        page = doc.new_page(width=width, height=height)
        del page

        temp_path = output_pdf_path + ".tmp"
        try:
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
                clean=True,
            )
        except TypeError:
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
            )
        finally:
            doc.close()

        os.replace(temp_path, output_pdf_path)

    for annotation in normalized_annotations:
        if base_pdf_path:
            try:
                annotation["pageIndex"] = max(0, int(annotation.get("pageIndex", 0)))
            except (TypeError, ValueError):
                annotation["pageIndex"] = 0
        else:
            annotation["pageIndex"] = 0
        if source_pdf_path:
            annotation["__sourcePdfPath"] = source_pdf_path
    apply_annotations(output_pdf_path, normalized_annotations)
    return width, height


def main() -> int:
    if len(sys.argv) < 3:
        print(
            "Usage: stamp_annotations_on_blank_pdf.py "
            "<annotations_json_or_@file> <output_pdf_path> [page_width page_height] "
            "[--base-pdf <path>] [--source-pdf <path>]"
        )
        return 1

    annotations_arg = sys.argv[1]
    output_pdf_path = sys.argv[2]
    explicit_width = None
    explicit_height = None
    base_pdf_path = None
    source_pdf_path = None

    index = 3
    while index < len(sys.argv):
        current = sys.argv[index]
        if current == "--base-pdf":
            if index + 1 >= len(sys.argv):
                print("--base-pdf requires a path argument.")
                return 1
            base_pdf_path = sys.argv[index + 1]
            index += 2
            continue
        if current == "--source-pdf":
            if index + 1 >= len(sys.argv):
                print("--source-pdf requires a path argument.")
                return 1
            source_pdf_path = sys.argv[index + 1]
            index += 2
            continue
        if explicit_width is None and explicit_height is None and index + 1 < len(sys.argv):
            explicit_width = float(sys.argv[index])
            explicit_height = float(sys.argv[index + 1])
            index += 2
            continue
        print(f"Unexpected argument: {current}")
        return 1

    annotations = load_json_arg(annotations_arg)
    if isinstance(annotations, dict):
        annotations = [annotations]
    if not isinstance(annotations, list):
        print("Annotations payload must be an array or object.")
        return 1

    width, height = stamp_annotations_on_blank_pdf(
        annotations,
        output_pdf_path,
        explicit_width,
        explicit_height,
        base_pdf_path,
        source_pdf_path,
    )
    print(f"Created blank stamped PDF at {output_pdf_path} ({width:.2f} x {height:.2f})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
