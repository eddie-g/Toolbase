#!/usr/bin/env python3
"""
Run an end-to-end surgical save regression against a live Toolbase document.

Default target is document 712 on localhost:8081. The script:
1. Restores the original PDF.
2. Prepares overlay extraction.
3. Picks random word-sized edit candidates from extraction data.
4. Saves each edit through the real /save-edits route.
5. Compares original vs saved render outside the edited bbox.
6. Verifies text outside the edited bbox is unchanged.
7. Restores the original again before the next case.

Exit code is non-zero on any failure.
"""

import argparse
import math
import random
import re
import sys
from collections import Counter
from html.parser import HTMLParser
from typing import Dict, List, Tuple

import fitz
import requests


class _MetaParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.csrf_token = None

    def handle_starttag(self, tag, attrs):
        attrs_map = dict(attrs)
        if tag == "meta" and attrs_map.get("name") == "csrf-token":
            self.csrf_token = attrs_map.get("content")


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Regression test the strict surgical save path.")
    parser.add_argument("--base-url", default="http://localhost:8081")
    parser.add_argument("--document-id", type=int, default=712)
    parser.add_argument("--page", type=int, default=1)
    parser.add_argument("--cases", type=int, default=8)
    parser.add_argument("--seed", type=int, default=0)
    parser.add_argument("--render-scale", type=float, default=3.0)
    parser.add_argument("--append-line-text")
    parser.add_argument("--append-suffix", default=" 3")
    parser.add_argument("--append-wide-bbox", action="store_true")
    return parser.parse_args()


class SaveRegression:
    def __init__(self, base_url: str, document_id: int, page_number: int, render_scale: float) -> None:
        self.base_url = base_url.rstrip("/")
        self.document_id = document_id
        self.page_number = page_number
        self.render_scale = render_scale
        self.session = requests.Session()
        self.csrf_token = None
        self.cookies: Dict[str, str] = {}

    def bootstrap(self) -> None:
        response = self.session.get(f"{self.base_url}/documents/{self.document_id}/edit")
        response.raise_for_status()
        parser = _MetaParser()
        parser.feed(response.text)
        if not parser.csrf_token:
            raise RuntimeError("Failed to locate csrf-token meta tag.")
        self.csrf_token = parser.csrf_token
        self.cookies = self.session.cookies.get_dict()

    def _headers(self) -> Dict[str, str]:
        return {
            "X-CSRF-TOKEN": self.csrf_token,
            "X-Requested-With": "XMLHttpRequest",
            "Accept": "application/json",
        }

    def restore_original(self) -> None:
        response = self.session.post(
            f"{self.base_url}/documents/{self.document_id}/restore-original",
            headers=self._headers(),
            cookies=self.cookies,
        )
        response.raise_for_status()
        payload = response.json()
        if not payload.get("success"):
            raise RuntimeError(f"restore-original failed: {payload}")

    def prepare_overlay(self) -> List[Dict]:
        response = self.session.get(
            f"{self.base_url}/documents/{self.document_id}/prepare-overlay",
            cookies=self.cookies,
        )
        response.raise_for_status()
        payload = response.json()
        if not payload.get("success"):
            raise RuntimeError(f"prepare-overlay failed: {payload}")
        return self.get_extraction()

    def get_extraction(self) -> List[Dict]:
        extraction = self.session.get(
            f"{self.base_url}/documents/{self.document_id}/fitz-extraction-data",
            cookies=self.cookies,
        )
        extraction.raise_for_status()
        extraction_payload = extraction.json()
        if not extraction_payload.get("success"):
            raise RuntimeError(f"fitz-extraction-data failed: {extraction_payload}")
        return extraction_payload["extraction_data"]

    def download_pdf(self) -> bytes:
        response = self.session.get(
            f"{self.base_url}/documents/{self.document_id}/file?v={random.random()}",
            cookies=self.cookies,
        )
        response.raise_for_status()
        return response.content

    def save_edit(self, edit: Dict) -> requests.Response:
        response = self.session.post(
            f"{self.base_url}/documents/{self.document_id}/save-edits",
            headers={
                **self._headers(),
                "Content-Type": "application/json",
            },
            cookies=self.cookies,
            json={"edits": [edit]},
        )
        return response


def _word_rect(word: Dict) -> List[float]:
    return [
        float(word["left"]),
        float(word["top"]),
        float(word["left"] + word["width"]),
        float(word["top"] + word["height"]),
    ]


def _mutate_word(word_text: str) -> str:
    chars = list(word_text.strip())
    for index, char in enumerate(chars):
        if char.isalpha():
            if char.lower() == "q":
                chars[index] = "R" if char.isupper() else "r"
            else:
                chars[index] = "Q" if char.isupper() else "q"
            return "".join(chars)
    return word_text.strip() + "Q"


def _pick_candidates(words: List[Dict], count: int) -> List[Dict]:
    candidates = []
    seen = set()
    for word in words:
        text = str(word.get("text") or "").strip()
        key = (round(float(word.get("left", 0)), 1), round(float(word.get("top", 0)), 1), text)
        if key in seen:
            continue
        seen.add(key)
        if not re.fullmatch(r"[A-Za-z]{5,12}", text):
            continue
        if float(word.get("top", 0)) < 25 or float(word.get("height", 0)) < 6:
            continue
        candidates.append(word)
    random.shuffle(candidates)
    return candidates[:count]


def _render_page(pdf_bytes: bytes, page_index: int, scale: float) -> Tuple[fitz.Pixmap, List[Tuple]]:
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    page = doc[page_index]
    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
    words = page.get_text("words")
    doc.close()
    return pix, words


def _pixel_diff_outside_mask(original_pdf: bytes, saved_pdf: bytes, mask_rects: List[List[float]], page_index: int, scale: float) -> int:
    orig_pix, _ = _render_page(original_pdf, page_index, scale)
    saved_pix, _ = _render_page(saved_pdf, page_index, scale)
    if orig_pix.width != saved_pix.width or orig_pix.height != saved_pix.height:
        raise RuntimeError("Rendered page sizes differ.")

    width, height = orig_pix.width, orig_pix.height
    mask = [[False] * width for _ in range(height)]
    for rect in mask_rects:
        x0 = max(0, int(math.floor(rect[0] * scale)) - 8)
        y0 = max(0, int(math.floor(rect[1] * scale)) - 8)
        x1 = min(width, int(math.ceil(rect[2] * scale)) + 8)
        y1 = min(height, int(math.ceil(rect[3] * scale)) + 8)
        for y in range(y0, y1):
            row = mask[y]
            for x in range(x0, x1):
                row[x] = True

    original = memoryview(orig_pix.samples)
    saved = memoryview(saved_pix.samples)
    diff_pixels = 0
    for y in range(height):
        row_mask = mask[y]
        base = y * width * 3
        for x in range(width):
            if row_mask[x]:
                continue
            offset = base + (x * 3)
            if (
                original[offset] != saved[offset]
                or original[offset + 1] != saved[offset + 1]
                or original[offset + 2] != saved[offset + 2]
            ):
                diff_pixels += 1
    return diff_pixels


def _word_counter_outside_rect(words: List[Tuple], excluded_rect: List[float]) -> Counter:
    rect = fitz.Rect(excluded_rect)
    counter = Counter()
    for word in words:
        bbox = fitz.Rect(word[:4])
        if bbox.intersects(rect):
            continue
        text = str(word[4] or "").strip()
        if not text:
            continue
        counter[(round(bbox.x0, 1), round(bbox.y0, 1), text)] += 1
    return counter


def _build_edit_payload(word: Dict, page_number: int) -> Dict:
    rect = _word_rect(word)
    font_weight = word.get("font_weight")
    font_weight_str = str(font_weight) if font_weight is not None else None
    payload = {
        "page_number": page_number,
        "original_text": str(word["text"]).strip(),
        "new_text": _mutate_word(str(word["text"])),
        "bbox": rect,
        "original_bbox": rect,
        "origin_x": float(word.get("origin_x", word["left"])),
        "origin_y": float(word.get("origin_y", word["top"] + word["height"])),
        "font_xref": int(word["font_xref"]) if word.get("font_xref") is not None else None,
        "font": str(word.get("font") or "Helvetica"),
        "font_size": float(word.get("font_size") or 12),
        "font_weight": font_weight_str,
        "font_style": "italic" if word.get("italic") else "normal",
        "underline": False,
        "line_height": float(word.get("height") or word.get("font_size") or 12),
        "color": str(word.get("hex_color") or "#000000"),
        "rich_html": None,
        "word_styles": [
            {
                "text": str(word["text"]).strip(),
                "left": float(word["left"]),
                "top": float(word["top"]),
                "width": float(word["width"]),
                "height": float(word["height"]),
                "font": str(word.get("font") or "Helvetica"),
                "font_xref": int(word["font_xref"]) if word.get("font_xref") is not None else None,
                "font_size": float(word.get("font_size") or 12),
                "font_weight": font_weight_str or "400",
                "italic": bool(word.get("italic")),
                "bold": bool(word.get("bold")),
                "underline": False,
                "color": word.get("color"),
                "hex_color": str(word.get("hex_color") or "#000000"),
                "origin_x": float(word.get("origin_x", word["left"])),
                "origin_y": float(word.get("origin_y", word["top"] + word["height"])),
            }
        ],
    }
    return payload


def _normalize_line_text(text: str) -> str:
    return re.sub(r"\s+", " ", str(text or "")).strip()


def _build_line_append_payload(page_data: Dict, page_number: int, line_text: str, append_suffix: str, use_wide_bbox: bool) -> Dict:
    target_line = None
    for line in page_data.get("lines", []):
        if _normalize_line_text(line.get("text")) == _normalize_line_text(line_text):
            target_line = line
            break
    if not target_line:
        raise RuntimeError(f"Line not found: {line_text!r}")

    line_rect = [
        float(target_line["left"]),
        float(target_line["top"]),
        float(target_line["left"] + target_line["width"]),
        float(target_line["top"] + target_line["height"]),
    ]
    bbox = list(line_rect)
    if use_wide_bbox:
        block_num = target_line.get("block_num")
        for block in page_data.get("blocks", []):
            if block.get("block_num") == block_num:
                bbox = [
                    float(block["left"]),
                    float(block["top"]),
                    float(block["left"] + block["width"]),
                    float(block["top"] + block["height"]),
                ]
                break

    line_words = []
    line_top = float(target_line["top"])
    line_bottom = float(target_line["top"] + target_line["height"])
    block_num = target_line.get("block_num")
    for word in page_data.get("words", []):
        if block_num is not None and word.get("block_num") != block_num:
            continue
        word_top = float(word.get("top", 0))
        word_bottom = word_top + float(word.get("height", 0))
        if word_bottom < line_top - 1.0 or word_top > line_bottom + 1.0:
            continue
        line_words.append(word)

    return {
        "edit": {
            "page_number": page_number,
            "original_text": str(target_line["text"]),
            "new_text": f"{str(target_line['text']).rstrip()}{append_suffix}",
            "bbox": bbox,
            "original_bbox": bbox,
            "origin_x": float(bbox[0]),
            "origin_y": float(bbox[3]),
            "font_xref": int(target_line["font_xref"]) if target_line.get("font_xref") is not None else None,
            "font": str(target_line.get("font") or "Helvetica"),
            "font_size": float(target_line.get("font_size") or 12),
            "font_weight": str(target_line.get("font_weight")) if target_line.get("font_weight") is not None else None,
            "font_style": "italic" if target_line.get("italic") else "normal",
            "underline": False,
            "line_height": float(target_line.get("height") or target_line.get("font_size") or 12),
            "color": str(target_line.get("hex_color") or "#000000"),
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
                for word in line_words
            ],
        },
        "expected_line": f"{str(target_line['text']).rstrip()}{append_suffix}",
        "mask_rect": bbox if use_wide_bbox else line_rect,
    }


def main() -> int:
    args = _parse_args()
    random.seed(args.seed)

    runner = SaveRegression(args.base_url, args.document_id, args.page, args.render_scale)
    runner.bootstrap()

    failures = []
    try:
        runner.restore_original()
        extraction = runner.prepare_overlay()
        page_data = extraction[args.page - 1]
        candidates = _pick_candidates(page_data.get("words", []), args.cases)
        if not candidates and not args.append_line_text:
            print("No valid candidate words found.", file=sys.stderr)
            return 1

        print(f"Document {args.document_id}, page {args.page}")
        print(f"Selected candidates: {[str(w['text']).strip() for w in candidates]}")

        for index, word in enumerate(candidates, start=1):
            runner.restore_original()
            original_pdf = runner.download_pdf()
            edit = _build_edit_payload(word, args.page)
            response = runner.save_edit(edit)
            if response.status_code != 200:
                failures.append(
                    f"case {index} save failed: status={response.status_code} body={response.text[:300]}"
                )
                continue
            payload = response.json()
            if not payload.get("success"):
                failures.append(f"case {index} save returned failure payload: {payload}")
                continue

            saved_pdf = runner.download_pdf()
            diff_pixels = _pixel_diff_outside_mask(
                original_pdf,
                saved_pdf,
                [edit["original_bbox"]],
                args.page - 1,
                args.render_scale,
            )
            original_words = _render_page(original_pdf, args.page - 1, args.render_scale)[1]
            saved_words = _render_page(saved_pdf, args.page - 1, args.render_scale)[1]
            words_match = (
                _word_counter_outside_rect(original_words, edit["original_bbox"])
                == _word_counter_outside_rect(saved_words, edit["original_bbox"])
            )

            print(
                f"case {index}: {edit['original_text']} -> {edit['new_text']} | "
                f"outside-mask pixel diff={diff_pixels} | words_ok={words_match}"
            )

            if diff_pixels != 0 or not words_match:
                failures.append(
                    f"case {index} failed: diff_pixels={diff_pixels}, words_ok={words_match}, "
                    f"original={edit['original_text']}, new={edit['new_text']}"
                )

        if args.append_line_text:
            runner.restore_original()
            page_data = runner.prepare_overlay()[args.page - 1]
            original_pdf = runner.download_pdf()
            append_case = _build_line_append_payload(
                page_data,
                args.page,
                args.append_line_text,
                args.append_suffix,
                args.append_wide_bbox,
            )
            response = runner.save_edit(append_case["edit"])
            if response.status_code != 200:
                failures.append(
                    f"append case save failed: status={response.status_code} body={response.text[:300]}"
                )
            else:
                payload = response.json()
                if not payload.get("success"):
                    failures.append(f"append case save returned failure payload: {payload}")
                else:
                    saved_pdf = runner.download_pdf()
                    diff_pixels = _pixel_diff_outside_mask(
                        original_pdf=original_pdf,
                        saved_pdf=saved_pdf,
                        mask_rects=[append_case["mask_rect"]],
                        page_index=args.page - 1,
                        scale=args.render_scale,
                    )
                    refreshed_page = runner.get_extraction()[args.page - 1]
                    expected_norm = _normalize_line_text(append_case["expected_line"])
                    line_found = any(
                        _normalize_line_text(line.get("text")) == expected_norm
                        for line in refreshed_page.get("lines", [])
                    )
                    print(
                        f"append case: {append_case['edit']['original_text'].strip()} -> "
                        f"{append_case['edit']['new_text'].strip()} | outside-mask pixel diff={diff_pixels} | "
                        f"line_found={line_found}"
                    )
                    if diff_pixels != 0 or not line_found:
                        failures.append(
                            f"append case failed: diff_pixels={diff_pixels}, line_found={line_found}, "
                            f"expected={append_case['expected_line']!r}"
                        )

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

    print("\nAll regression cases passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
