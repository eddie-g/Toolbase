#!/usr/bin/env python3
"""
Document 711 live regression suite for the strict surgical save path.

This hits the real /save-edits route and verifies three invariants:
1. The expected edited line exists after save.
2. No pixels change outside the edited line region.
3. The rebuilt line keeps the original baseline origin.
"""

import argparse
import sys
import time
from collections import Counter
from typing import Dict, List, Optional, Tuple

import fitz

from regression_surgical_save import (
    SaveRegression,
    _normalize_line_text,
    _pixel_diff_outside_mask,
    _render_page,
)


CASES = [
    {
        "name": "append_list_wide",
        "original_text": "6.  Install new shingles.",
        "new_text": "6.  Install new shingles. 3",
        "wide_bbox": True,
    },
    {
        "name": "insert_list_wide",
        "original_text": "6.  Install new shingles.",
        "new_text": "6.  Install new roof shingles.",
        "wide_bbox": True,
    },
    {
        "name": "append_deck_board_wide",
        "original_text": "3.  Install new Deck Board.",
        "new_text": "3.  Install new Deck Board. 123",
        "wide_bbox": True,
    },
    {
        "name": "replace_list_wide",
        "original_text": "2.  Remove the existing rotten Deck Board.",
        "new_text": "2.  Remove the existing damaged Deck Board.",
        "wide_bbox": True,
    },
    {
        "name": "delete_list_wide",
        "original_text": "5.  Install Ice & Water barrier.",
        "new_text": "5.  Install Water barrier.",
        "wide_bbox": True,
    },
    {
        "name": "append_warranty_line",
        "original_text": "**   Comes with 1 year warranty  **",
        "new_text": "**   Comes with 1 year warranty  ** 3",
        "wide_bbox": False,
    },
    {
        "name": "append_ligature_heading",
        "original_text": "Rooﬁng Section",
        "new_text": "Rooﬁng Section 3",
        "wide_bbox": False,
    },
    {
        "name": "replace_long_paragraph_wide",
        "original_text": "a) Unless stated otherwise, our estimate does not include removal and replacement of roof deck boards as it is not possible to fully ascertain during our",
        "new_text": "a) Unless stated otherwise, our estimate does not include removal and replacement of roof deck boards as it is not possible to fully ascertain during our visit",
        "wide_bbox": True,
    },
]


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the live regression suite for document 711.")
    parser.add_argument("--base-url", default="http://localhost:8081")
    parser.add_argument("--document-id", type=int, default=711)
    parser.add_argument("--page", type=int, default=1)
    parser.add_argument("--render-scale", type=float, default=3.0)
    parser.add_argument("--baseline-tolerance", type=float, default=0.5)
    parser.add_argument("--font-size-tolerance", type=float, default=0.35)
    return parser.parse_args()


def _line_rect(line: Dict) -> List[float]:
    return [
        float(line["left"]),
        float(line["top"]),
        float(line["left"] + line["width"]),
        float(line["top"] + line["height"]),
    ]


def _line_origin(line: Dict) -> Optional[Tuple[float, float]]:
    spans = line.get("spans") or []
    if not spans:
        return None
    origin = spans[0].get("origin")
    if not isinstance(origin, (list, tuple)) or len(origin) < 2:
        return None
    return float(origin[0]), float(origin[1])


def _find_line(page_data: Dict, line_text: str) -> Optional[Dict]:
    expected = _normalize_line_text(line_text)
    for line in page_data.get("lines", []):
        if _normalize_line_text(line.get("text")) == expected:
            return line
    return None


def _find_block_rect(page_data: Dict, block_num: int) -> Optional[List[float]]:
    for block in page_data.get("blocks", []):
        if block.get("block_num") == block_num:
            return [
                float(block["left"]),
                float(block["top"]),
                float(block["left"] + block["width"]),
                float(block["top"] + block["height"]),
            ]
    return None


def _line_words(page_data: Dict, line: Dict) -> List[Dict]:
    out = []
    line_top = float(line["top"])
    line_bottom = float(line["top"] + line["height"])
    block_num = line.get("block_num")
    for word in page_data.get("words", []):
        if block_num is not None and word.get("block_num") != block_num:
            continue
        word_top = float(word.get("top", 0))
        word_bottom = word_top + float(word.get("height", 0))
        if word_bottom < line_top - 1.0 or word_top > line_bottom + 1.0:
            continue
        out.append(word)
    return out


def _build_edit_payload(page_data: Dict, page_number: int, case: Dict) -> Tuple[Dict, Dict, List[float]]:
    line = _find_line(page_data, case["original_text"])
    if line is None:
        raise RuntimeError(f"Line not found: {case['original_text']!r}")

    line_rect = _line_rect(line)
    bbox = list(line_rect)
    if case.get("wide_bbox"):
        block_rect = _find_block_rect(page_data, line.get("block_num"))
        if block_rect is not None:
            bbox = block_rect

    words = _line_words(page_data, line)
    payload = {
        "page_number": page_number,
        "original_text": str(line["text"]),
        "new_text": case["new_text"],
        "bbox": bbox,
        "original_bbox": bbox,
        "origin_x": float(bbox[0]),
        "origin_y": float(bbox[3]),
        "font_xref": int(line["font_xref"]) if line.get("font_xref") is not None else None,
        "font": str(line.get("font") or "Helvetica"),
        "font_size": float(line.get("font_size") or 12),
        "font_weight": str(line.get("font_weight")) if line.get("font_weight") is not None else None,
        "font_style": "italic" if line.get("italic") else "normal",
        "underline": False,
        "line_height": float(line.get("height") or line.get("font_size") or 12),
        "color": str(line.get("hex_color") or "#000000"),
        "rich_html": None,
        "word_styles": [
            {
                "text": str(word.get("text") or "").strip(),
                "left": float(word["left"]),
                "top": float(word["top"]),
                "width": float(word["width"]),
                "height": float(word["height"]),
                "font": str(word.get("font") or "Helvetica"),
                "font_xref": int(word["font_xref"]) if word.get("font_xref") is not None else None,
                "font_size": float(word.get("font_size") or 12),
                "font_weight": str(word.get("font_weight")) if word.get("font_weight") is not None else "400",
                "italic": bool(word.get("italic")),
                "bold": bool(word.get("bold")),
                "underline": False,
                "color": word.get("color"),
                "hex_color": str(word.get("hex_color") or "#000000"),
                "origin_x": float(word.get("origin_x", word["left"])),
                "origin_y": float(word.get("origin_y", word["top"] + word["height"])),
            }
            for word in words
        ],
    }
    return line, payload, line_rect


def _word_counter_outside_rects(words: List[Tuple], excluded_rects: List[List[float]]) -> Counter:
    rects = [fitz.Rect(rect) for rect in excluded_rects if rect]
    counter = Counter()
    for word in words:
        bbox = fitz.Rect(word[:4])
        if any(bbox.intersects(rect) for rect in rects):
            continue
        text = str(word[4] or "").strip()
        if not text:
            continue
        counter[(round(bbox.x0, 1), round(bbox.y0, 1), text)] += 1
    return counter


def _wait_for_line(runner: SaveRegression, page_number: int, expected_text: str, attempts: int = 12, delay: float = 0.2) -> Optional[Dict]:
    expected = _normalize_line_text(expected_text)
    for _ in range(attempts):
        page = runner.get_extraction()[page_number - 1]
        line = _find_line(page, expected)
        if line is not None:
            return line
        time.sleep(delay)
    return None


def main() -> int:
    args = _parse_args()
    runner = SaveRegression(args.base_url, args.document_id, args.page, args.render_scale)
    runner.bootstrap()

    failures = []
    try:
        for case in CASES:
            runner.restore_original()
            page_data = runner.prepare_overlay()[args.page - 1]
            original_line, edit, original_line_rect = _build_edit_payload(page_data, args.page, case)
            original_origin = _line_origin(original_line)
            original_font_size = float(original_line.get("font_size") or 0.0)
            original_pdf = runner.download_pdf()

            response = runner.save_edit(edit)
            if response.status_code != 200:
                failures.append(
                    f"{case['name']}: save failed status={response.status_code} body={response.text[:250]}"
                )
                continue

            payload = response.json()
            if not payload.get("success"):
                failures.append(f"{case['name']}: save returned failure payload={payload}")
                continue

            saved_line = _wait_for_line(runner, args.page, case["new_text"])
            saved_pdf = runner.download_pdf()
            saved_line_rect = _line_rect(saved_line) if saved_line is not None else None
            mask_rects = [original_line_rect]
            if saved_line_rect is not None:
                mask_rects.append(saved_line_rect)

            diff_pixels = _pixel_diff_outside_mask(
                original_pdf=original_pdf,
                saved_pdf=saved_pdf,
                mask_rects=mask_rects,
                page_index=args.page - 1,
                scale=args.render_scale,
            )
            original_words = _render_page(original_pdf, args.page - 1, args.render_scale)[1]
            saved_words = _render_page(saved_pdf, args.page - 1, args.render_scale)[1]
            words_match = (
                _word_counter_outside_rects(original_words, mask_rects)
                == _word_counter_outside_rects(saved_words, mask_rects)
            )

            origin_dx = None
            origin_dy = None
            font_size_delta = None
            if original_origin is not None and saved_line is not None:
                saved_origin = _line_origin(saved_line)
                if saved_origin is not None:
                    origin_dx = abs(saved_origin[0] - original_origin[0])
                    origin_dy = abs(saved_origin[1] - original_origin[1])
                    font_size_delta = abs(float(saved_line.get("font_size") or 0.0) - original_font_size)

            print(
                f"{case['name']}: diff={diff_pixels} words_ok={words_match} "
                f"line_found={saved_line is not None} origin_dx={origin_dx} origin_dy={origin_dy} "
                f"font_delta={font_size_delta}"
            )

            if saved_line is None:
                failures.append(f"{case['name']}: expected line not found after save")
                continue
            if diff_pixels != 0 or not words_match:
                failures.append(
                    f"{case['name']}: collateral change detected diff={diff_pixels} words_ok={words_match}"
                )
            if origin_dx is None or origin_dx > args.baseline_tolerance:
                failures.append(f"{case['name']}: baseline x drifted by {origin_dx}")
            if origin_dy is None or origin_dy > args.baseline_tolerance:
                failures.append(f"{case['name']}: baseline y drifted by {origin_dy}")
            if font_size_delta is None or font_size_delta > args.font_size_tolerance:
                failures.append(f"{case['name']}: font size drifted by {font_size_delta}")
    finally:
        try:
            runner.restore_original()
        except Exception:
            pass

    if failures:
        print("\nFailures:")
        for failure in failures:
            print(f" - {failure}")
        return 1

    print("\nAll document 711 regression cases passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
