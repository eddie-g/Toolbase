#!/usr/bin/env python3
"""
Rebuild PDF text layer from extraction data on a clean (text-redacted) PDF.

Design goal:
- No duplicate text ever. We start from a PDF that already has ALL text removed.
- Render untouched text from extraction words at absolute origins.
- Render edited blocks at absolute positions (origin/word-style based), never by
  "scrub + reinsert" heuristics.
"""

import json
import os
import re
import sys
from typing import Any, Dict, List, Optional, Tuple

import fitz  # PyMuPDF

from apply_pdf_edits_simple import (  # Reuse existing font resolution helpers.
    cleanup_embedded_fonts,
    get_embedded_font,
    get_font_file,
    normalize_smart_quotes,
)


def load_json_arg(arg: str):
    if arg.startswith("@"):
        with open(arg[1:], "r", encoding="utf-8") as fh:
            return json.load(fh)
    with open(arg, "r", encoding="utf-8") as fh:
        return json.load(fh)


def sanitize_text(text: Any) -> str:
    if text is None:
        return ""
    text = normalize_smart_quotes(str(text))
    text = text.replace("\u00A0", " ").replace("\uFFFD", "")
    text = re.sub(r"[\u200B\u200C\u200D\u2060\uFEFF]", "", text)
    text = re.sub(r"[\uE000-\uF8FF]", "", text)
    text = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]", "", text)
    return text


def norm_cmp(text: Any) -> str:
    return re.sub(r"\s+", " ", sanitize_text(text)).strip().lower()


def parse_color(value: Any) -> Tuple[float, float, float]:
    if isinstance(value, int):
        return (
            ((value >> 16) & 0xFF) / 255.0,
            ((value >> 8) & 0xFF) / 255.0,
            (value & 0xFF) / 255.0,
        )
    if isinstance(value, str):
        raw = value.strip().lstrip("#")
        if len(raw) == 6:
            try:
                return (
                    int(raw[0:2], 16) / 255.0,
                    int(raw[2:4], 16) / 255.0,
                    int(raw[4:6], 16) / 255.0,
                )
            except Exception:
                return (0.0, 0.0, 0.0)
    return (0.0, 0.0, 0.0)


def block_rect(block: Dict[str, Any]) -> Optional[fitz.Rect]:
    if isinstance(block.get("bbox"), list) and len(block["bbox"]) >= 4:
        return fitz.Rect(block["bbox"][0], block["bbox"][1], block["bbox"][2], block["bbox"][3])
    left = block.get("left")
    top = block.get("top")
    width = block.get("width")
    height = block.get("height")
    if left is None or top is None or width is None or height is None:
        return None
    return fitz.Rect(float(left), float(top), float(left) + float(width), float(top) + float(height))


def iou(a: fitz.Rect, b: fitz.Rect) -> float:
    inter = a & b
    if inter.is_empty:
        return 0.0
    inter_area = inter.get_area()
    den = a.get_area() + b.get_area() - inter_area
    return inter_area / den if den > 0 else 0.0


def block_text(block: Dict[str, Any]) -> str:
    lines = block.get("text_lines")
    if isinstance(lines, list) and lines:
        return sanitize_text("\n".join(str(x) for x in lines))
    return sanitize_text(block.get("text", ""))


def resolve_edit_block_nums(extraction_pages: List[Dict[str, Any]], edits: List[Dict[str, Any]]) -> None:
    page_map = {int(p.get("page_number", 0)): p for p in extraction_pages if isinstance(p, dict)}
    for edit in edits:
        if edit.get("block_num") is not None:
            continue
        page_num = int(edit.get("page_number") or 0)
        page = page_map.get(page_num)
        if not page:
            continue
        blocks = page.get("blocks") or []
        if not isinstance(blocks, list) or not blocks:
            continue

        orig_text = norm_cmp(edit.get("original_text", ""))
        orig_bbox = edit.get("original_bbox")
        edit_rect = None
        if isinstance(orig_bbox, list) and len(orig_bbox) >= 4:
            edit_rect = fitz.Rect(orig_bbox[0], orig_bbox[1], orig_bbox[2], orig_bbox[3])

        best = None
        best_score = -1.0
        for block in blocks:
            score = 0.0
            b_text = norm_cmp(block_text(block))
            if orig_text and b_text:
                if b_text == orig_text:
                    score += 10.0
                elif orig_text in b_text or b_text in orig_text:
                    score += 5.0
            if edit_rect is not None:
                b_rect = block_rect(block)
                if b_rect is not None:
                    overlap = iou(edit_rect, b_rect)
                    score += overlap * 8.0
            if score > best_score:
                best_score = score
                best = block

        if best is not None and best_score > 0:
            edit["block_num"] = best.get("block_num")


def is_center_inside_any(rect: fitz.Rect, regions: List[fitz.Rect]) -> bool:
    cx = (rect.x0 + rect.x1) / 2.0
    cy = (rect.y0 + rect.y1) / 2.0
    for rg in regions:
        if rg.x0 <= cx <= rg.x1 and rg.y0 <= cy <= rg.y1:
            return True
    return False


def draw_text_absolute(
    page: fitz.Page,
    text: str,
    origin_x: float,
    origin_y: float,
    font_obj: fitz.Font,
    font_size: float,
    color: Tuple[float, float, float],
) -> None:
    tw = fitz.TextWriter(page.rect)
    tw.append((origin_x, origin_y), text, font=font_obj, fontsize=font_size)
    tw.write_text(page, color=color, overlay=True)


def text_width(font_obj: fitz.Font, text: str, font_size: float) -> float:
    if not text:
        return 0.0
    try:
        return float(font_obj.text_length(text, fontsize=font_size))
    except Exception:
        # Conservative fallback if font metrics are unavailable.
        return float(len(text)) * font_size * 0.6


def wrap_text_to_width(text: str, font_obj: fitz.Font, font_size: float, max_width: Optional[float]) -> List[str]:
    raw_lines = text.split("\n")
    if not max_width or max_width <= 0:
        return [sanitize_text(line) for line in raw_lines]

    wrapped: List[str] = []
    for raw in raw_lines:
        line = sanitize_text(raw).strip()
        if line == "":
            wrapped.append("")
            continue

        parts = [p for p in line.split() if p]
        if not parts:
            wrapped.append("")
            continue

        current = parts[0]
        for token in parts[1:]:
            candidate = f"{current} {token}"
            if text_width(font_obj, candidate, font_size) <= max_width:
                current = candidate
            else:
                wrapped.append(current)
                current = token
        wrapped.append(current)

    return wrapped if wrapped else [""]


def inferred_layout_from_edit(
    edit: Dict[str, Any],
    ws: Optional[List[Dict[str, Any]]],
    font_size: float,
    fallback_x: float,
    fallback_y: float,
) -> Tuple[float, float, Optional[float]]:
    bbox = edit.get("bbox")
    wrap_width: Optional[float] = None
    base_x = fallback_x
    base_y = fallback_y

    if isinstance(bbox, list) and len(bbox) >= 4:
        try:
            b_left = float(bbox[0])
            b_top = float(bbox[1])
            b_right = float(bbox[2])
            base_x = b_left
            base_y = b_top + font_size
            wrap_width = max(1.0, b_right - b_left)
        except Exception:
            pass

    if isinstance(ws, list) and ws:
        # For edited multiline blocks, anchor to first extracted baseline,
        # not to bbox bottom (origin_y), which can drift the paragraph down.
        baseline_vals = []
        min_x = None
        min_left = None
        max_right = None
        for w in ws:
            ox = w.get("origin_x")
            left = w.get("left")
            width = w.get("width")
            oy = w.get("origin_y")
            top = w.get("top")
            height = w.get("height")

            try:
                if ox is not None:
                    fx = float(ox)
                    min_x = fx if min_x is None else min(min_x, fx)
                elif left is not None:
                    fx = float(left)
                    min_x = fx if min_x is None else min(min_x, fx)
            except Exception:
                pass

            try:
                if oy is not None:
                    baseline_vals.append(float(oy))
                elif top is not None and height is not None:
                    baseline_vals.append(float(top) + float(height))
            except Exception:
                pass

            try:
                if left is not None and width is not None:
                    l = float(left)
                    r = l + float(width)
                    min_left = l if min_left is None else min(min_left, l)
                    max_right = r if max_right is None else max(max_right, r)
            except Exception:
                pass

        if min_x is not None:
            base_x = min_x
        if baseline_vals:
            base_y = min(baseline_vals)
        if wrap_width is None and min_left is not None and max_right is not None and max_right > min_left:
            wrap_width = max_right - min_left

    return base_x, base_y, wrap_width


def build_font(
    doc: fitz.Document,
    page_num: int,
    font_name: Optional[str],
    font_weight: Optional[Any],
    font_style: Optional[str],
    font_xref: Optional[Any],
    font_cache: Dict[str, fitz.Font],
) -> fitz.Font:
    key = f"{page_num}|{font_name}|{font_weight}|{font_style}|{font_xref}"
    if key in font_cache:
        return font_cache[key]

    script_dir = os.path.dirname(os.path.abspath(__file__))
    font_obj = None
    font_file = get_font_file(font_name or "", script_dir, font_weight=font_weight)
    if font_file and os.path.exists(font_file):
        try:
            font_obj = fitz.Font(fontfile=font_file)
        except Exception:
            font_obj = None

    if font_obj is None:
        try:
            xref_num = int(font_xref) if font_xref is not None else None
        except Exception:
            xref_num = None
        font_obj = get_embedded_font(doc, xref_num, font_name=font_name, page_num=page_num)

    if font_obj is None:
        # Final fallback. Keep deterministic and robust over fancy matching.
        font_obj = fitz.Font("helv")

    font_cache[key] = font_obj
    return font_obj


def render_edited_block(
    doc: fitz.Document,
    page: fitz.Page,
    page_num: int,
    edit: Dict[str, Any],
    font_cache: Dict[str, fitz.Font],
) -> int:
    text = sanitize_text(edit.get("new_text", ""))
    if not text.strip():
        return 0

    color = parse_color(edit.get("color", "#000000"))
    try:
        font_size = float(edit.get("font_size", 12) or 12)
    except Exception:
        font_size = 12.0

    try:
        origin_x = float(edit.get("origin_x")) if edit.get("origin_x") is not None else None
    except Exception:
        origin_x = None
    try:
        origin_y = float(edit.get("origin_y")) if edit.get("origin_y") is not None else None
    except Exception:
        origin_y = None

    bbox = edit.get("bbox")
    if origin_x is None:
        if isinstance(bbox, list) and len(bbox) >= 1:
            origin_x = float(bbox[0])
        else:
            origin_x = 0.0
    if origin_y is None:
        if isinstance(bbox, list) and len(bbox) >= 2:
            origin_y = float(bbox[1]) + font_size
        else:
            origin_y = font_size

    font_obj = build_font(
        doc,
        page_num,
        edit.get("font"),
        edit.get("font_weight"),
        edit.get("font_style"),
        edit.get("font_xref"),
        font_cache,
    )

    try:
        line_height = float(edit.get("line_height")) if edit.get("line_height") is not None else None
    except Exception:
        line_height = None
    if not line_height or line_height <= 0:
        line_height = font_size * 1.2

    # Prefer absolute per-word placement only when token count still matches.
    ws = edit.get("word_styles")
    dx = 0.0
    dy = 0.0
    ob = edit.get("original_bbox")
    if isinstance(ob, list) and len(ob) >= 4 and isinstance(bbox, list) and len(bbox) >= 4:
        try:
            dx = float(bbox[0]) - float(ob[0])
            dy = float(bbox[1]) - float(ob[1])
        except Exception:
            dx = 0.0
            dy = 0.0

    if isinstance(ws, list) and ws:
        old_tokens = []
        for w in ws:
            wt = sanitize_text(w.get("text", ""))
            if not wt:
                continue
            old_tokens.extend(t for t in wt.split() if t)
        new_tokens = [t for t in text.split() if t]
        if old_tokens and len(old_tokens) == len(new_tokens):
            token_idx = 0
            for w in ws:
                wt = sanitize_text(w.get("text", ""))
                if not wt:
                    continue
                ws_tokens = [t for t in wt.split() if t]
                if not ws_tokens:
                    continue
                # Keep original span grouping, but with updated token values.
                take = len(ws_tokens)
                repl = new_tokens[token_idx: token_idx + take]
                token_idx += take
                if not repl:
                    continue
                draw_text = " ".join(repl)

                wx = float(w.get("origin_x", w.get("left", origin_x))) + dx
                wy = w.get("origin_y")
                if wy is None:
                    wy = float(w.get("top", 0.0)) + float(w.get("height", font_size))
                wy = float(wy) + dy

                w_font = build_font(
                    doc,
                    page_num,
                    w.get("font") or edit.get("font"),
                    w.get("font_weight") or edit.get("font_weight"),
                    "italic" if bool(w.get("italic")) else edit.get("font_style"),
                    w.get("font_xref") if w.get("font_xref") is not None else edit.get("font_xref"),
                    font_cache,
                )
                try:
                    w_size = float(w.get("font_size", font_size))
                except Exception:
                    w_size = font_size
                w_color = parse_color(w.get("hex_color") or w.get("color") or edit.get("color"))

                draw_text_absolute(page, draw_text, wx, wy, w_font, w_size, w_color)
            return 1

    base_x, base_y, wrap_width = inferred_layout_from_edit(edit, ws if isinstance(ws, list) else None, font_size, origin_x, origin_y)
    base_x += dx
    base_y += dy
    lines = wrap_text_to_width(text, font_obj, font_size, wrap_width)
    for idx, line in enumerate(lines):
        draw_line = sanitize_text(line)
        if not draw_line:
            continue
        draw_text_absolute(page, draw_line, base_x, base_y + (idx * line_height), font_obj, font_size, color)
    return 1


def rebuild(clean_pdf_path: str, extraction_data: List[Dict[str, Any]], edits: List[Dict[str, Any]], output_pdf_path: str, original_pdf_path: str = None, edited_pages: List[int] = None) -> None:
    """
    Rebuild PDF with selective page processing.
    
    Args:
        clean_pdf_path: Path to PDF with text removed
        extraction_data: List of page extraction data
        edits: List of edits to apply
        output_pdf_path: Where to save the result
        original_pdf_path: Path to original PDF (for copying unmodified pages)
        edited_pages: List of page numbers that were edited (1-indexed). If None, process all pages.
    """
    doc = fitz.open(clean_pdf_path)
    font_cache: Dict[str, fitz.Font] = {}
    
    # If we have the original PDF and a list of edited pages, we can preserve unmodified pages
    original_doc = None
    preserve_unmodified = False
    if original_pdf_path and os.path.exists(original_pdf_path) and edited_pages is not None:
        try:
            original_doc = fitz.open(original_pdf_path)
            preserve_unmodified = True
            print(f"✓ Will preserve {doc.page_count - len(edited_pages)} unmodified pages from original PDF")
        except Exception as e:
            print(f"⚠ Could not open original PDF for page preservation: {e}")
            original_doc = None

    resolve_edit_block_nums(extraction_data, edits)

    edits_by_page: Dict[int, List[Dict[str, Any]]] = {}
    for edit in edits:
        page_num = int(edit.get("page_number") or 0)
        if page_num <= 0:
            continue
        edits_by_page.setdefault(page_num, []).append(edit)

    extraction_by_page = {
        int(p.get("page_number", 0)): p for p in extraction_data if isinstance(p, dict) and int(p.get("page_number", 0)) > 0
    }

    total_words_drawn = 0
    total_edits_drawn = 0

    for page_num in range(1, doc.page_count + 1):
        # Check if this page was edited
        page_was_edited = edited_pages is None or page_num in edited_pages
        
        if not page_was_edited and preserve_unmodified:
            # This page was NOT edited - we'll use the original page later
            print(f"  Page {page_num}: Will preserve unchanged from original (no edits)")
            continue
            
        # Page was edited - rebuild it from extraction + edits
        page = doc[page_num - 1]
        page_data = extraction_by_page.get(page_num) or {}
        page_words = page_data.get("words") or []
        page_edits = edits_by_page.get(page_num, [])

        edited_blocks = set()
        unresolved_rects: List[fitz.Rect] = []
        for edit in page_edits:
            bnum = edit.get("block_num")
            if bnum is not None:
                edited_blocks.add(bnum)
                continue
            ob = edit.get("original_bbox")
            if isinstance(ob, list) and len(ob) >= 4:
                unresolved_rects.append(fitz.Rect(ob[0], ob[1], ob[2], ob[3]))

        # ── Collect valid words, dedup, group by block ────────────
        seen_words = set()
        words_by_block: Dict[int, List[Dict[str, Any]]] = {}
        for word in sorted(page_words, key=lambda w: (float(w.get("top", 0)), float(w.get("left", 0)))):
            block_num = word.get("block_num")
            if block_num in edited_blocks:
                continue
            w_rect = fitz.Rect(
                float(word.get("left", 0)),
                float(word.get("top", 0)),
                float(word.get("left", 0)) + float(word.get("width", 0)),
                float(word.get("top", 0)) + float(word.get("height", 0)),
            )
            if unresolved_rects and is_center_inside_any(w_rect, unresolved_rects):
                continue

            text = sanitize_text(word.get("text", ""))
            if not text.strip():
                continue

            # Defend against duplicate extraction entries.
            sig = (
                text,
                round(float(word.get("origin_x", word.get("left", 0))), 2),
                round(float(word.get("origin_y", float(word.get("top", 0)) + float(word.get("height", 0)))), 2),
                round(float(word.get("font_size", 0)), 2),
            )
            if sig in seen_words:
                continue
            seen_words.add(sig)

            words_by_block.setdefault(block_num if block_num is not None else -1, []).append(word)

        # ── Draw words batched by block_num → one TextWriter per (block, color) ──
        # Grouping words from the same block into the same BT…ET run preserves
        # paragraph structure when PyMuPDF re-extracts the rebuilt PDF.
        for _block_num, block_words in words_by_block.items():
            # TextWriter.write_text() takes a single color, so sub-group by color.
            color_groups: Dict[Tuple[float, float, float], List[Dict[str, Any]]] = {}
            for w in block_words:
                c = parse_color(w.get("hex_color") if w.get("hex_color") is not None else w.get("color"))
                color_groups.setdefault(c, []).append(w)

            for color, cwords in color_groups.items():
                tw = fitz.TextWriter(page.rect)
                for w in cwords:
                    text = sanitize_text(w.get("text", ""))
                    ox = float(w.get("origin_x", w.get("left", 0)))
                    oy = float(w.get("origin_y", float(w.get("top", 0)) + float(w.get("height", 0))))
                    try:
                        fs = float(w.get("font_size", 12))
                    except Exception:
                        fs = 12.0
                    font_obj = build_font(
                        doc,
                        page_num,
                        w.get("font"),
                        w.get("font_weight"),
                        "italic" if bool(w.get("italic")) else "normal",
                        w.get("font_xref"),
                        font_cache,
                    )
                    try:
                        tw.append((ox, oy), text, font=font_obj, fontsize=fs)
                    except Exception:
                        # Fallback: draw individually if append fails
                        draw_text_absolute(page, text, ox, oy, font_obj, fs, color)
                    total_words_drawn += 1
                tw.write_text(page, color=color, overlay=True)

        for edit in page_edits:
            if not sanitize_text(edit.get("new_text", "")).strip():
                continue
            total_edits_drawn += render_edited_block(doc, page, page_num, edit, font_cache)

    # Save the result
    tmp_path = output_pdf_path + ".tmp"
    
    if preserve_unmodified and original_doc:
        # Create a new output PDF by combining pages from rebuilt doc (edited) and original doc (unedited)
        output_doc = fitz.open()
        for page_num in range(1, doc.page_count + 1):
            if edited_pages is None or page_num in edited_pages:
                # Use rebuilt page
                output_doc.insert_pdf(doc, from_page=page_num - 1, to_page=page_num - 1)
            else:
                # Use original page
                output_doc.insert_pdf(original_doc, from_page=page_num - 1, to_page=page_num - 1)
        
        output_doc.save(tmp_path, garbage=4, deflate=True, encryption=fitz.PDF_ENCRYPT_KEEP)
        output_doc.close()
        print(f"✓ Assembled final PDF: {len(edited_pages if edited_pages else [])} edited pages, {doc.page_count - len(edited_pages if edited_pages else [])} preserved pages")
    else:
        # No selective preservation - save the rebuilt doc as-is
        doc.save(tmp_path, garbage=4, deflate=True, encryption=fitz.PDF_ENCRYPT_KEEP)
    
    doc.close()
    if original_doc:
        original_doc.close()
    os.replace(tmp_path, output_pdf_path)
    cleanup_embedded_fonts()
    print(f"✓ Rebuilt from clean base. Drawn unchanged words: {total_words_drawn}, edited blocks: {total_edits_drawn}")


def main():
    # Support both old (5 args) and new (6 args) syntax
    # Old: <clean_pdf> <extraction> <edits> <output>
    # New: <clean_pdf> <extraction> <edits> <output> <original_pdf> <edited_pages>
    if len(sys.argv) not in [5, 6, 7]:
        print("Usage: rebuild_pdf_from_overlay_extraction.py <clean_pdf_path> <extraction_json_or_@file> <edits_json_or_@file> <output_pdf_path> [original_pdf_path] [edited_pages_json_or_@file]")
        sys.exit(1)

    clean_pdf_path = sys.argv[1]
    extraction_arg = sys.argv[2]
    edits_arg = sys.argv[3]
    output_pdf_path = sys.argv[4]
    original_pdf_path = sys.argv[5] if len(sys.argv) >= 6 else None
    edited_pages_arg = sys.argv[6] if len(sys.argv) >= 7 else None

    if not os.path.exists(clean_pdf_path):
        print(f"ERROR: clean PDF not found: {clean_pdf_path}", file=sys.stderr)
        sys.exit(1)

    extraction_data = load_json_arg(extraction_arg)
    edits = load_json_arg(edits_arg)
    edited_pages = None
    if edited_pages_arg:
        edited_pages = load_json_arg(edited_pages_arg)
        if not isinstance(edited_pages, list):
            print("ERROR: edited_pages must be a list of page numbers", file=sys.stderr)
            sys.exit(1)

    if not isinstance(extraction_data, list):
        print("ERROR: extraction_data must be a list of page objects", file=sys.stderr)
        sys.exit(1)
    if not isinstance(edits, list):
        print("ERROR: edits must be a list", file=sys.stderr)
        sys.exit(1)

    rebuild(clean_pdf_path, extraction_data, edits, output_pdf_path, original_pdf_path, edited_pages)
    print("SUCCESS")


if __name__ == "__main__":
    main()
