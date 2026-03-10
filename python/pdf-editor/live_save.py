#!/usr/bin/env python3
"""
live_save.py — Surgical single-field PDF patch.

Accepts a single edit record (JSON on stdin or as a file argument) and applies
it to the PDF in-place via incremental save, avoiding a full re-render cycle.

Edit record schema (matches the overlay editor's edit data):
  {
    "page_number": 1,           // 1-indexed
    "original_text": "Foo",
    "new_text": "Bar",
    "original_bbox": [x0, y0, x1, y1],   // PDF-space (y flipped: origin=bottom-left)
    "bbox": [x0, y0, x1, y1],             // target insertion bbox
    "origin_x": float,                    // text baseline start X
    "origin_y": float,                    // text baseline start Y (PDF space)
    "font": "Helvetica",
    "font_size": 11.0,
    "font_weight": null | "700" | "bold",
    "font_style": null | "italic",
    "color": "#000000",
    "word_styles": null | [...]            // ignored for now; future multi-style
  }

Usage:
  python3 live_save.py <pdf_path> <edit_json_file> [preview_dir]

Exit codes:
  0 — success
  1 — failure (error printed to stderr, JSON to stdout)
"""

import sys
import os
import json
from datetime import datetime, timezone
from pathlib import Path
from statistics import median
from typing import Optional

import fitz  # PyMuPDF

try:
    from apply_pdf_edits import _strip_using_source_content_ops
except Exception:
    _strip_using_source_content_ops = None


# ── Font helpers (same logic as apply_pdf_edits.py) ────────────────────────────

SAFE_FONT_FILES = {
    "sans_regular":  "fonts/Arimo-Regular.ttf",
    "sans_bold":     "fonts/Arimo-Bold.ttf",
    "serif_regular": "fonts/Tinos-Regular.ttf",
    "serif_bold":    "fonts/Tinos-Bold.ttf",
    "mono_regular":  "fonts/Cousine-Regular.ttf",
    "mono_bold":     "fonts/Cousine-Bold.ttf",
}

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))


def _safe_font_file(font_name: str, font_weight=None) -> Optional[str]:
    font_lower = (font_name or "").lower()
    try:
        is_bold = int(font_weight) >= 600 if font_weight is not None else False
    except Exception:
        is_bold = False
    if not is_bold:
        is_bold = any(t in font_lower for t in ("bold", "700", "black"))

    if any(t in font_lower for t in ("courier", "cousine", "mono")):
        key = "mono_bold" if is_bold else "mono_regular"
    elif any(t in font_lower for t in ("times", "tinos", "serif")):
        key = "serif_bold" if is_bold else "serif_regular"
    else:
        key = "sans_bold" if is_bold else "sans_regular"

    path = os.path.join(_SCRIPT_DIR, SAFE_FONT_FILES[key])
    return path if os.path.exists(path) else None


def _parse_color(color_str: str) -> tuple:
    """Return (r, g, b) floats 0-1 from a '#rrggbb' string."""
    c = (color_str or "#000000").strip().lstrip("#")
    if len(c) == 3:
        c = "".join(x * 2 for x in c)
    if len(c) != 6:
        return (0.0, 0.0, 0.0)
    return (int(c[0:2], 16) / 255, int(c[2:4], 16) / 255, int(c[4:6], 16) / 255)


def _is_same_rect(a: fitz.Rect, b: fitz.Rect, tol: float = 2.0) -> bool:
    return (
        abs(a.x0 - b.x0) < tol
        and abs(a.y0 - b.y0) < tol
        and abs(a.x1 - b.x1) < tol
        and abs(a.y1 - b.y1) < tol
    )


def _union_rect(*rects: fitz.Rect) -> fitz.Rect:
    valid = [r for r in rects if isinstance(r, fitz.Rect) and not r.is_empty]
    if not valid:
        return fitz.Rect(0, 0, 0, 0)
    return fitz.Rect(
        min(r.x0 for r in valid),
        min(r.y0 for r in valid),
        max(r.x1 for r in valid),
        max(r.y1 for r in valid),
    )


def _compute_preview_clip(page_rect: fitz.Rect, focus_rect: fitz.Rect) -> fitz.Rect:
    if focus_rect.is_empty:
        return fitz.Rect(page_rect)

    pad_x = max(28.0, focus_rect.width * 1.8)
    pad_y = max(20.0, focus_rect.height * 2.2)
    clip = fitz.Rect(
        max(page_rect.x0, focus_rect.x0 - pad_x),
        max(page_rect.y0, focus_rect.y0 - pad_y),
        min(page_rect.x1, focus_rect.x1 + pad_x),
        min(page_rect.y1, focus_rect.y1 + pad_y),
    )

    if clip.width < 80:
        extra = (80 - clip.width) / 2
        clip.x0 = max(page_rect.x0, clip.x0 - extra)
        clip.x1 = min(page_rect.x1, clip.x1 + extra)
    if clip.height < 50:
        extra = (50 - clip.height) / 2
        clip.y0 = max(page_rect.y0, clip.y0 - extra)
        clip.y1 = min(page_rect.y1, clip.y1 + extra)

    return clip


def _build_redaction_rect(target_rect: fitz.Rect, insert_rect: fitz.Rect) -> fitz.Rect:
    """Use a slightly more generous rect than extraction bboxes to catch glyph overhangs."""
    base = _union_rect(target_rect, insert_rect)
    return fitz.Rect(
        base.x0 - 2.5,
        base.y0 - 1.5,
        base.x1 + 6.0,
        base.y1 + 2.5,
    )


def _build_precise_redaction_rect(target_rect: fitz.Rect) -> fitz.Rect:
    """Minimal redaction rect for exact-coordinate retries."""
    return fitz.Rect(
        target_rect.x0 - 0.75,
        target_rect.y0 - 0.6,
        target_rect.x1 + 0.9,
        target_rect.y1 + 0.8,
    )


def _inflate_rect(rect: fitz.Rect, x_pad: float, y_pad: Optional[float] = None) -> fitz.Rect:
    if y_pad is None:
        y_pad = x_pad
    return fitz.Rect(
        rect.x0 - x_pad,
        rect.y0 - y_pad,
        rect.x1 + x_pad,
        rect.y1 + y_pad,
    )


def _rect_to_dict(rect: fitz.Rect) -> dict:
    return {
        "x0": round(rect.x0, 3),
        "y0": round(rect.y0, 3),
        "x1": round(rect.x1, 3),
        "y1": round(rect.y1, 3),
        "width": round(rect.width, 3),
        "height": round(rect.height, 3),
    }


def _relative_rect(rect: fitz.Rect, clip_rect: fitz.Rect) -> dict:
    clip_width = max(clip_rect.width, 1.0)
    clip_height = max(clip_rect.height, 1.0)
    return {
        "left_pct": round(((rect.x0 - clip_rect.x0) / clip_width) * 100, 4),
        "top_pct": round(((rect.y0 - clip_rect.y0) / clip_height) * 100, 4),
        "width_pct": round((rect.width / clip_width) * 100, 4),
        "height_pct": round((rect.height / clip_height) * 100, 4),
    }


def _write_preview_assets(page, preview_dir: str, edit: dict, clip_rect: fitz.Rect, target_rect: fitz.Rect, insert_rect: fitz.Rect, prefix: str, save_id: str):
    Path(preview_dir).mkdir(parents=True, exist_ok=True)
    pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), clip=clip_rect, alpha=False)
    pix.save(os.path.join(preview_dir, f"latest-{prefix}.png"))
    pix.save(os.path.join(preview_dir, f"{save_id}-{prefix}.png"))

    if prefix != "final":
        return

    metadata = {
        "save_id": save_id,
        "page_number": int(edit.get("page_number", 1)),
        "original_text": str(edit.get("original_text") or ""),
        "new_text": str(edit.get("new_text") or ""),
        "created_at": datetime.now(timezone.utc).isoformat(),
        "clip_rect": _rect_to_dict(clip_rect),
        "target_rect": _rect_to_dict(target_rect),
        "insert_rect": _rect_to_dict(insert_rect),
        "highlight": _relative_rect(target_rect, clip_rect),
    }

    with open(os.path.join(preview_dir, "latest.json"), "w", encoding="utf-8") as f:
        json.dump(metadata, f, ensure_ascii=True)
    with open(os.path.join(preview_dir, f"{save_id}.json"), "w", encoding="utf-8") as f:
        json.dump(metadata, f, ensure_ascii=True)


def _apply_redactions_compat(page) -> None:
    """Support both older and newer PyMuPDF apply_redactions() signatures."""
    try:
        page.apply_redactions(images=0, graphics=0)
        return
    except TypeError:
        pass

    try:
        page.apply_redactions(images=0)
        return
    except TypeError:
        pass

    page.apply_redactions()


def live_save_redaction(page, target_pdf_rect: fitz.Rect) -> None:
    """Apply the actual live-save redaction for the edited block."""
    # fill=None keeps the page background intact while removing the text content.
    page.add_redact_annot(target_pdf_rect, fill=None)
    _apply_redactions_compat(page)


def _cleanup_residual_spans(page, region_rect: fitz.Rect) -> int:
    padded_region = (_inflate_rect(region_rect, 1.5, 1.0) & page.rect)
    if padded_region.width <= 0 or padded_region.height <= 0:
        return 0

    text_dict = page.get_text("dict") or {}
    redact_rects = []
    for block in text_dict.get("blocks", []):
        if block.get("type", 0) != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                bbox = span.get("bbox")
                if not bbox or len(bbox) < 4:
                    continue
                span_rect = fitz.Rect(bbox)
                if span_rect.width <= 0 or span_rect.height <= 0:
                    continue
                center = fitz.Point((span_rect.x0 + span_rect.x1) / 2, (span_rect.y0 + span_rect.y1) / 2)
                if not padded_region.contains(center):
                    continue
                redact_rects.append((_inflate_rect(span_rect, 0.8, 0.6) & page.rect))

    seen = set()
    applied = 0
    for rect in redact_rects:
        if rect.width <= 0 or rect.height <= 0:
            continue
        key = (
            round(rect.x0 * 4) / 4,
            round(rect.y0 * 4) / 4,
            round(rect.x1 * 4) / 4,
            round(rect.y1 * 4) / 4,
        )
        if key in seen:
            continue
        seen.add(key)
        page.add_redact_annot(rect, fill=None)
        applied += 1

    if applied == 0:
        return 0

    _apply_redactions_compat(page)
    return applied


def _clean_text(value) -> str:
    return str(value or "").replace("\r\n", "\n").replace("\r", "\n")


def _normalized_compare_text(value: str) -> str:
    return " ".join(_clean_text(value).split()).strip()


def _normalize_line_metrics(raw_metrics) -> list:
    metrics = []
    for raw in raw_metrics or []:
        if not isinstance(raw, dict):
            continue
        left = float(raw.get("left") or 0)
        top = float(raw.get("top") or 0)
        width = max(float(raw.get("width") or 0), 0.0)
        height = max(float(raw.get("height") or 0), 0.0)
        if width <= 0 or height <= 0:
            continue
        metrics.append({
            "text": _clean_text(raw.get("text")),
            "font": raw.get("font") or "Helvetica",
            "font_xref": int(raw.get("font_xref")) if raw.get("font_xref") not in (None, "") else None,
            "font_size": max(float(raw.get("font_size") or 0), 1.0),
            "font_weight": raw.get("font_weight"),
            "italic": bool(raw.get("italic")),
            "bold": bool(raw.get("bold")),
            "underline": bool(raw.get("underline")),
            "hex_color": raw.get("hex_color") or raw.get("color") or "#000000",
            "left": left,
            "top": top,
            "width": width,
            "height": height,
            "origin_x": float(raw.get("origin_x") or left),
            "origin_y": float(raw.get("origin_y") or (top + height)),
            "ascender": raw.get("ascender"),
            "descender": raw.get("descender"),
        })
    metrics.sort(key=lambda item: (item["top"], item["left"]))
    return metrics


def _font_from_xref(doc, page, font_xref: Optional[int], fallback_name: str) -> tuple[Optional[str], Optional[fitz.Font], str]:
    if not font_xref:
        return None, None, "none"

    try:
        extracted = doc.extract_font(int(font_xref))
    except Exception:
        return None, None, "xref-open-failed"

    if isinstance(extracted, dict):
        font_name = extracted.get("name") or fallback_name or "embedded"
        font_buffer = extracted.get("content")
    elif isinstance(extracted, tuple) and len(extracted) >= 4:
        font_name = extracted[0] or fallback_name or "embedded"
        font_buffer = extracted[3]
    else:
        return None, None, "xref-invalid"

    if not font_buffer:
        return None, None, "xref-empty"

    safe_name = "".join(ch if ch.isalnum() else "_" for ch in font_name)[:24] or "embedded"
    font_key = f"ls_{int(font_xref)}_{safe_name}"
    try:
        page.insert_font(fontname=font_key, fontbuffer=font_buffer)
        return font_key, fitz.Font(fontbuffer=font_buffer), "xref"
    except Exception:
        try:
            return font_key, fitz.Font(fontbuffer=font_buffer), "xref-font-only"
        except Exception:
            return None, None, "xref-load-failed"


def _resolve_font(doc, page, font_name: str, font_weight=None, font_xref: Optional[int] = None) -> tuple[str, fitz.Font, str]:
    xref_key, xref_font, xref_source = _font_from_xref(doc, page, font_xref, font_name)
    if xref_key and xref_font:
        return xref_key, xref_font, xref_source

    font_file = _safe_font_file(font_name, font_weight)
    if font_file:
        font_key = f"__ls_{Path(font_file).stem}"
        try:
            page.insert_font(fontname=font_key, fontfile=font_file)
            return font_key, fitz.Font(fontfile=font_file), "safe-font"
        except Exception:
            pass

    return "helv", fitz.Font("helv"), "builtin"


def _alignment_from_line_metrics(line_metrics: list, paragraph_rect: fitz.Rect, fontsize: float) -> int:
    if len(line_metrics) < 2:
        return fitz.TEXT_ALIGN_LEFT

    right_margin = paragraph_rect.x1
    tolerance = max(4.0, fontsize * 0.55)
    non_last = line_metrics[:-1]
    if not non_last:
        return fitz.TEXT_ALIGN_LEFT

    flush_right = 0
    for line in non_last:
        line_right = line["left"] + line["width"]
        if abs(right_margin - line_right) <= tolerance:
            flush_right += 1

    return fitz.TEXT_ALIGN_JUSTIFY if flush_right >= max(1, len(non_last) - 1) else fitz.TEXT_ALIGN_LEFT


def _paragraph_dna(edit: dict, original_rect: fitz.Rect, insert_rect: fitz.Rect) -> dict:
    line_metrics = _normalize_line_metrics(edit.get("line_metrics"))
    font_size = max(float(edit.get("font_size") or 12.0), 1.0)
    median_height = float(median([line["height"] for line in line_metrics])) if line_metrics else max(original_rect.height, font_size)
    group_gap_threshold = max(median_height * 1.55, font_size * 1.45, 18.0)

    paragraph_groups = []
    current_group = []
    for line in line_metrics:
        if not current_group:
            current_group = [line]
            continue
        prev = current_group[-1]
        gap = line["top"] - prev["top"]
        if gap > group_gap_threshold:
            paragraph_groups.append(current_group)
            current_group = [line]
        else:
            current_group.append(line)
    if current_group:
        paragraph_groups.append(current_group)

    line_rects = [
        fitz.Rect(line["left"], line["top"], line["left"] + line["width"], line["top"] + line["height"])
        for line in line_metrics
    ]
    line_union_rect = _union_rect(*line_rects) if line_rects else fitz.Rect(0, 0, 0, 0)
    field_union_rect = _union_rect(original_rect, insert_rect)
    paragraph_rect = field_union_rect

    leading_values = []
    for idx in range(len(line_metrics) - 1):
        diff = line_metrics[idx + 1]["origin_y"] - line_metrics[idx]["origin_y"]
        if diff > 0.1:
            leading_values.append(diff)
    typical_leading_values = [
        diff for diff in leading_values
        if diff <= (median_height * 1.8) and diff >= max(median_height * 0.7, font_size * 0.75)
    ]
    if typical_leading_values:
        leading = float(median(typical_leading_values))
    elif leading_values and median_height > 0:
        leading = min(float(median(leading_values)), median_height * 1.35)
    elif line_metrics:
        leading = max(median_height * 1.2, font_size * 1.2)
    else:
        leading = max(original_rect.height, font_size * 1.2)

    if line_metrics:
        # Overlay fields can carry an inflated bbox that spans neighboring paragraphs.
        # Prefer the measured line union unless the field bbox is materially the same size.
        line_area = max(line_union_rect.width * line_union_rect.height, 1.0)
        field_area = max(field_union_rect.width * field_union_rect.height, 1.0)
        height_ratio = field_union_rect.height / max(line_union_rect.height, 1.0)
        width_ratio = field_union_rect.width / max(line_union_rect.width, 1.0)
        if field_area <= line_area * 1.35 and height_ratio <= 1.25 and width_ratio <= 1.15:
            paragraph_rect = _union_rect(field_union_rect, line_union_rect)
        else:
            pad_x = max(1.5, font_size * 0.18)
            pad_y = max(1.0, median_height * 0.15)
            paragraph_rect = fitz.Rect(
                line_union_rect.x0 - pad_x,
                line_union_rect.y0 - pad_y,
                line_union_rect.x1 + pad_x,
                line_union_rect.y1 + pad_y,
            )

    return {
        "line_metrics": line_metrics,
        "paragraph_groups": paragraph_groups,
        "paragraph_rect": paragraph_rect,
        "font_size": float(median([line["font_size"] for line in line_metrics])) if line_metrics else font_size,
        "leading": max(leading, font_size),
        "align": _alignment_from_line_metrics(line_metrics, paragraph_rect, font_size),
    }


def _normalize_reflow_text(text: str, line_metrics: list, leading: float, paragraph_groups: Optional[list] = None) -> str:
    raw = _clean_text(text)
    if not raw:
        return ""
    if "\n" not in raw:
        return raw
    if "\n\n" in raw:
        return raw

    lines = [line.strip() for line in raw.split("\n")]
    if len(lines) <= 1:
        return raw.replace("\n", " ").strip()

    break_after = set()
    if paragraph_groups:
        offset = 0
        for group in paragraph_groups[:-1]:
            offset += len(group)
            if offset - 1 < len(lines):
                break_after.add(offset - 1)
    elif len(line_metrics) >= 2:
        threshold = max(leading * 1.55, 18.0)
        for idx in range(min(len(line_metrics), len(lines)) - 1):
            gap = line_metrics[idx + 1]["top"] - line_metrics[idx]["top"]
            if gap > threshold:
                break_after.add(idx)

    rebuilt = []
    for idx, line in enumerate(lines):
        if line:
            rebuilt.append(line)
        if idx >= len(lines) - 1:
            continue
        rebuilt.append("\n\n" if idx in break_after else " ")

    return "".join(rebuilt).strip()


def _paragraph_group_rect(group: list) -> fitz.Rect:
    rects = [
        fitz.Rect(line["left"], line["top"], line["left"] + line["width"], line["top"] + line["height"])
        for line in group
    ]
    base = _union_rect(*rects)
    if base.is_empty:
        return base
    font_size = float(median([line["font_size"] for line in group])) if group else 11.0
    return fitz.Rect(
        base.x0 - max(1.5, font_size * 0.18),
        base.y0 - max(1.0, font_size * 0.15),
        base.x1 + max(1.5, font_size * 0.22),
        base.y1 + max(1.0, font_size * 0.15),
    )


def _split_normalized_paragraphs(text: str) -> list[str]:
    value = _clean_text(text).strip()
    if not value:
        return []
    return [part.strip() for part in value.split("\n\n")]


def _normalized_text_lines(text: str) -> list[str]:
    return [
        " ".join(line.strip().split())
        for line in _clean_text(text).split("\n")
    ]


def _line_range_rect(lines: list) -> fitz.Rect:
    rects = [
        fitz.Rect(line["left"], line["top"], line["left"] + line["width"], line["top"] + line["height"])
        for line in lines
    ]
    base = _union_rect(*rects)
    if base.is_empty:
        return base
    font_size = float(median([line["font_size"] for line in lines])) if lines else 11.0
    return fitz.Rect(
        base.x0 - max(1.0, font_size * 0.14),
        base.y0 - max(0.8, font_size * 0.10),
        base.x1 + max(1.0, font_size * 0.18),
        base.y1 + max(0.8, font_size * 0.10),
    )


def _select_changed_paragraph_groups(original_text: str, new_text: str, paragraph: dict) -> tuple[Optional[fitz.Rect], Optional[str]]:
    groups = paragraph.get("paragraph_groups") or []
    if len(groups) <= 1:
        return None, None

    normalized_original = _normalize_reflow_text(original_text, paragraph["line_metrics"], paragraph["leading"], groups)
    normalized_new = _normalize_reflow_text(new_text, paragraph["line_metrics"], paragraph["leading"], groups)
    original_parts = _split_normalized_paragraphs(normalized_original)
    new_parts = _split_normalized_paragraphs(normalized_new)
    if len(original_parts) != len(groups) or len(new_parts) != len(groups):
        return None, None

    changed_indexes = [idx for idx, (orig, new) in enumerate(zip(original_parts, new_parts)) if orig != new]
    if not changed_indexes:
        return None, None

    start = min(changed_indexes)
    end = max(changed_indexes)
    selected_groups = groups[start:end + 1]
    rect = _union_rect(*[_paragraph_group_rect(group) for group in selected_groups])
    text = "\n\n".join(new_parts[start:end + 1]).strip()
    return rect, text


def _select_changed_line_range(original_text: str, new_text: str, line_metrics: list) -> tuple[Optional[fitz.Rect], Optional[str], Optional[int], Optional[int]]:
    if len(line_metrics) < 2:
        return None, None, None, None

    original_lines = _normalized_text_lines(original_text)
    new_lines = _normalized_text_lines(new_text)
    if len(original_lines) != len(line_metrics) or len(new_lines) != len(line_metrics):
        return None, None, None, None

    prefix = 0
    while prefix < len(line_metrics) and original_lines[prefix] == new_lines[prefix]:
        prefix += 1

    if prefix >= len(line_metrics):
        return None, None, None, None

    suffix = 0
    while (suffix < (len(line_metrics) - prefix)
           and original_lines[len(line_metrics) - 1 - suffix] == new_lines[len(line_metrics) - 1 - suffix]):
        suffix += 1

    start = prefix
    end = len(line_metrics) - suffix - 1
    if start > end:
        return None, None, None, None

    changed_lines = line_metrics[start:end + 1]
    render_text = "\n".join(_clean_text(new_text).split("\n")[start:end + 1]).strip()
    if not render_text:
        render_text = ""
    return _line_range_rect(changed_lines), render_text, start, end


def _normalized_flat_line_text(line_metrics: list) -> tuple[str, list[tuple[int, int]]]:
    parts = []
    spans = []
    cursor = 0
    for idx, line in enumerate(line_metrics):
        text = " ".join(_clean_text(line.get("text")).split()).strip()
        if idx > 0:
            parts.append(" ")
            cursor += 1
        start = cursor
        parts.append(text)
        cursor += len(text)
        spans.append((start, cursor))
    return "".join(parts), spans


def _common_prefix_len(a: str, b: str) -> int:
    limit = min(len(a), len(b))
    idx = 0
    while idx < limit and a[idx] == b[idx]:
        idx += 1
    return idx


def _common_suffix_len(a: str, b: str, prefix_len: int = 0) -> int:
    max_len = min(len(a), len(b)) - prefix_len
    idx = 0
    while idx < max_len and a[-1 - idx] == b[-1 - idx]:
        idx += 1
    return idx


def _select_locked_suffix_range(new_text: str, line_metrics: list) -> tuple[Optional[fitz.Rect], Optional[str], Optional[int], Optional[int]]:
    if len(line_metrics) < 2:
        return None, None, None, None

    source_flat, spans = _normalized_flat_line_text(line_metrics)
    new_flat = " ".join(_clean_text(new_text).split()).strip()
    if not source_flat or not new_flat:
        return None, None, None, None

    prefix_len = _common_prefix_len(source_flat, new_flat)
    suffix_len = _common_suffix_len(source_flat, new_flat, prefix_len)
    if suffix_len <= 0:
        return None, None, None, None

    changed_start = prefix_len
    changed_end = len(source_flat) - suffix_len
    if changed_start >= changed_end:
        return None, None, None, None

    start_idx = next((idx for idx, (_start, end) in enumerate(spans) if end > changed_start), None)
    end_idx = next((idx for idx in range(len(spans) - 1, -1, -1) if spans[idx][0] < changed_end), None)
    if start_idx is None or end_idx is None or start_idx > end_idx:
        return None, None, None, None

    changed_lines = line_metrics[start_idx:end_idx + 1]
    render_text = new_flat[prefix_len:len(new_flat) - suffix_len].strip()
    return _line_range_rect(changed_lines), render_text, start_idx, end_idx


def _fits_single_line(text: str, rect: fitz.Rect, font_obj: fitz.Font, fontsize: float) -> bool:
    normalized = _clean_text(text).strip("\n")
    if not normalized:
        return True
    if "\n" in normalized:
        return False

    required_width = font_obj.text_length(normalized, fontsize=fontsize)
    required_height = fontsize * 1.4
    return required_width <= (rect.width + 0.75) and required_height <= (rect.height + 2.0)


def _insert_text_in_rect(page, rect: fitz.Rect, text: str, font_obj: fitz.Font, fontsize: float, color: tuple, align: int = fitz.TEXT_ALIGN_LEFT):
    """Write text once inside the target bbox using TextWriter for font consistency."""
    fs = max(float(fontsize or 12.0), 1.0)
    min_fs = 4.0
    while fs >= min_fs:
        writer = fitz.TextWriter(page.rect)
        overflow = writer.fill_textbox(
            rect,
            text,
            font=font_obj,
            fontsize=fs,
            lineheight=1.0,
            align=align,
        )
        if not overflow:
            writer.write_text(page, color=color, overlay=True)
            return True, fs, "textbox"
        fs -= 0.25

    return False, min_fs, "textbox"


def _insert_single_line_text(page, origin: fitz.Point, text: str, font_obj: fitz.Font, fontsize: float, color: tuple, max_width: Optional[float] = None):
    fs = max(float(fontsize or 12.0), 1.0)
    min_fs = 4.0

    while fs >= min_fs:
        required_width = font_obj.text_length(text, fontsize=fs)
        if max_width is not None and required_width > (max_width + 0.75):
            fs -= 0.25
            continue

        writer = fitz.TextWriter(page.rect)
        writer.append((origin.x, origin.y), text, font=font_obj, fontsize=fs)
        writer.write_text(page, color=color, overlay=True)
        return True, fs, "append"

    return False, min_fs, "append"


def _insert_precise_coordinate_text(page, origin: fitz.Point, rect: fitz.Rect, text: str, font_obj: fitz.Font, fontsize: float, color: tuple):
    """Write a single line using only the exact bbox + baseline passed from the overlay."""
    normalized = " ".join(_clean_text(text).split())
    if not normalized:
        return True, max(float(fontsize or 12.0), 1.0), "precise-origin-empty"

    fs = max(float(fontsize or 12.0), 1.0)
    min_fs = 4.0
    max_width = max(rect.width, 1.0)
    max_height = max(rect.height, 1.0)

    while fs >= min_fs:
        required_width = font_obj.text_length(normalized, fontsize=fs)
        required_height = fs * 1.35
        if required_width <= (max_width + 0.5) and required_height <= (max_height + 1.0):
            writer = fitz.TextWriter(page.rect)
            writer.append((origin.x, origin.y), normalized, font=font_obj, fontsize=fs)
            writer.write_text(page, color=color, overlay=True)
            return True, fs, "precise-origin"
        fs -= 0.25

    return False, min_fs, "precise-origin"


def _reflow_paragraph(page, rect: fitz.Rect, text: str, font_obj: fitz.Font, fontsize: float, leading: float, color: tuple, align: int):
    writer = fitz.TextWriter(page.rect)
    lineheight = max(0.85, min(3.0, leading / max(fontsize, 0.1)))
    overflow = writer.fill_textbox(
        rect,
        text,
        font=font_obj,
        fontsize=fontsize,
        lineheight=lineheight,
        align=align,
    )
    if overflow:
        return False, lineheight, overflow
    writer.write_text(page, color=color, overlay=True)
    return True, lineheight, []


def _fit_reflow_rect(page_rect: fitz.Rect, rect: fitz.Rect, text: str, font_obj: fitz.Font, fontsize: float, leading: float, align: int):
    lineheight = max(0.85, min(3.0, leading / max(fontsize, 0.1)))
    growth_step = max(leading, fontsize * lineheight, 6.0)
    candidate = fitz.Rect(rect)
    max_bottom = page_rect.y1 - 2.0

    while True:
        writer = fitz.TextWriter(page_rect)
        overflow = writer.fill_textbox(
            candidate,
            text,
            font=font_obj,
            fontsize=fontsize,
            lineheight=lineheight,
            align=align,
        )
        if not overflow:
            return candidate, lineheight, []
        if candidate.y1 >= max_bottom:
            return None, lineheight, overflow
        candidate = fitz.Rect(
            candidate.x0,
            candidate.y0,
            candidate.x1,
            min(max_bottom, candidate.y1 + growth_step),
        )


# ── Span-level search and replace ──────────────────────────────────────────────

def _diff_changed_substring(original_text: str, new_text: str) -> tuple[str, str]:
    """
    Diff two strings and return (old_part, new_part) — the changed substring
    expanded to full word boundaries so that search_for() gets a complete
    token (e.g. "34" instead of just "4").
    """
    orig_flat = " ".join(_clean_text(original_text).split())
    new_flat = " ".join(_clean_text(new_text).split())
    if not orig_flat or not new_flat:
        return orig_flat, new_flat
    if orig_flat == new_flat:
        return "", ""

    prefix_len = _common_prefix_len(orig_flat, new_flat)
    suffix_len = _common_suffix_len(orig_flat, new_flat, prefix_len)

    # Expand to word boundaries so we search for whole tokens, not fragments.
    # "34" → "35" should yield ("34","35") not ("4","5").
    while prefix_len > 0 and orig_flat[prefix_len - 1] != ' ':
        prefix_len -= 1
    end_pos = len(orig_flat) - suffix_len
    while suffix_len > 0 and end_pos < len(orig_flat) and orig_flat[end_pos] != ' ':
        suffix_len -= 1
        end_pos = len(orig_flat) - suffix_len

    old_part = orig_flat[prefix_len:len(orig_flat) - suffix_len] if suffix_len else orig_flat[prefix_len:]
    new_part = new_flat[prefix_len:len(new_flat) - suffix_len] if suffix_len else new_flat[prefix_len:]
    return old_part.strip(), new_part.strip()


def _find_best_span_for_hit(page, hit_rect: fitz.Rect) -> Optional[dict]:
    """
    Given a search_for() hit rect, find the span in the text dict that
    overlaps it most.  Returns the span dict (with 'origin', 'size', etc.)
    or None.
    """
    text_dict = page.get_text("dict") or {}
    best_span = None
    best_overlap = 0.0

    for block in text_dict.get("blocks", []):
        if block.get("type", 0) != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                bbox = span.get("bbox")
                if not bbox or len(bbox) < 4:
                    continue
                span_rect = fitz.Rect(bbox)
                overlap = span_rect & hit_rect
                if overlap.is_empty:
                    continue
                area = overlap.width * overlap.height
                if area > best_overlap:
                    best_overlap = area
                    best_span = span

    return best_span


def _try_span_search_replace(doc, page, edit: dict, preview_dir: Optional[str], save_id: str) -> Optional[dict]:
    """
    Attempt 3 strategy: diff original_text vs new_text to find the tiny
    changed substring, locate it on the PDF page via search_for(), redact
    ONLY that small rect, and write the replacement at the exact same origin.

    Returns a result dict on success, or None if this strategy can't handle
    the edit (e.g. pure insertion, search miss).
    """
    original_text = edit.get("original_text") or ""
    new_text = edit.get("new_text") or ""
    old_part, new_part = _diff_changed_substring(original_text, new_text)

    print(f"  [span_search] diff: '{old_part}' → '{new_part}'  original_text={repr(original_text[:80])}  new_text={repr(new_text[:80])}", file=sys.stderr)

    if not old_part:
        return None  # Pure insertion — nothing to search for

    # Search the page for the old substring
    hits = page.search_for(old_part, quads=False)
    if not hits:
        print(f"  [span_search] no hits for '{old_part}' — trying page.get_text() dump", file=sys.stderr)
        page_text = page.get_text("text") or ""
        found_in_text = old_part in page_text
        print(f"  [span_search] old_part in page text: {found_in_text}  page_text_len={len(page_text)}", file=sys.stderr)
        if found_in_text:
            # search_for failed but text exists — try character-by-character search
            # for short strings that may be split across spans
            if len(old_part) <= 5:
                for ch_idx, ch in enumerate(old_part):
                    ch_hits = page.search_for(ch, quads=False)
                    print(f"  [span_search] char '{ch}' [{ch_idx}]: {len(ch_hits)} hit(s)", file=sys.stderr)
        return None

    # Filter hits to those near the block area
    original_bbox = edit.get("original_bbox") or edit.get("bbox") or [0, 0, 0, 0]
    paragraph_bbox = edit.get("paragraph_bbox") or original_bbox
    block_rect = fitz.Rect([float(v) for v in paragraph_bbox])
    search_area = _inflate_rect(block_rect, 30) & page.rect

    nearby_hits = [h for h in hits if search_area.intersects(h)]
    if not nearby_hits:
        print(f"  [span_search] {len(hits)} hit(s) but none near block rect", file=sys.stderr)
        return None

    hit_rect = nearby_hits[0]

    # Find the matching span to get the precise baseline origin
    best_span = _find_best_span_for_hit(page, hit_rect)

    if best_span and "origin" in best_span:
        baseline_y = float(best_span["origin"][1])
        span_font_size = float(best_span.get("size", edit.get("font_size") or 12))
    else:
        # Approximate: baseline ≈ bottom of rect minus descender
        span_font_size = float(edit.get("font_size") or 12)
        baseline_y = hit_rect.y1 - span_font_size * 0.18

    # Resolve font
    font_name = edit.get("font") or "Helvetica"
    font_weight = edit.get("font_weight")
    font_xref = int(edit.get("font_xref")) if edit.get("font_xref") not in (None, "") else None
    _font_key, font_obj, font_source = _resolve_font(doc, page, font_name, font_weight, font_xref)
    color = _parse_color(edit.get("color") or "#000000")

    # Redact rect: just the tiny hit rect with minimal glyph-overhang padding
    redact_rect = fitz.Rect(
        hit_rect.x0 - 0.5,
        hit_rect.y0 - 0.3,
        hit_rect.x1 + 0.8,
        hit_rect.y1 + 0.3,
    ) & page.rect

    preview_clip_rect = _compute_preview_clip(page.rect, redact_rect)

    print(
        f"  [span_search] '{old_part}' → '{new_part}'  hit={hit_rect}  redact={redact_rect}  font={font_source}  fs={span_font_size:.2f}",
        file=sys.stderr,
    )

    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redact_rect, redact_rect, "before", save_id)
        except Exception:
            pass

    # Redact ONLY the tiny rect
    page.add_redact_annot(redact_rect, fill=None)
    _apply_redactions_compat(page)

    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redact_rect, redact_rect, "redacted", save_id)
        except Exception:
            pass

    # Insert replacement text at the exact same origin
    if new_part:
        writer = fitz.TextWriter(page.rect)
        writer.append(fitz.Point(hit_rect.x0, baseline_y), new_part, font=font_obj, fontsize=span_font_size)
        writer.write_text(page, color=color, overlay=True)

    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redact_rect, redact_rect, "final", save_id)
        except Exception:
            pass

    return {
        "success": True,
        "strategy": "span_search",
        "old_part": old_part,
        "new_part": new_part,
    }


# ── Core patch routine ─────────────────────────────────────────────────────────

def live_save_patch(pdf_path: str, edit: dict, preview_dir: Optional[str] = None) -> dict:
    """
    Apply a single overlay-field edit surgically to the PDF.

    Returns {"success": True} or {"success": False, "error": "..."}.
    """
    page_number = int(edit.get("page_number", 1))
    page_index  = page_number - 1

    original_bbox = edit.get("original_bbox") or edit.get("bbox") or [0, 0, 0, 0]
    insert_bbox   = edit.get("bbox") or original_bbox
    original_text = _clean_text(edit.get("original_text"))
    new_text      = _clean_text(edit.get("new_text"))
    font_name     = edit.get("font") or "Helvetica"
    font_size     = float(edit.get("font_size") or 12)
    font_weight   = edit.get("font_weight")
    font_xref     = int(edit.get("font_xref")) if edit.get("font_xref") not in (None, "") else None
    color_str     = edit.get("color") or "#000000"
    retry_mode    = str(edit.get("retry_mode") or "").strip().lower()
    try:
        redaction_padding = max(0.0, float(edit.get("redaction_padding") or 0.0))
    except Exception:
        redaction_padding = 0.0

    try:
        doc = fitz.open(pdf_path)
    except Exception as e:
        return {"success": False, "error": f"Cannot open PDF: {e}"}

    if page_index < 0 or page_index >= doc.page_count:
        doc.close()
        return {"success": False, "error": f"Page {page_number} out of range ({doc.page_count} pages)"}

    page = doc[page_index]
    save_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")

    # ── Fast path: span_search — always try first (word-level precision) ──────
    #    On first attempt (empty retry_mode) AND on explicit span_search retry,
    #    try to diff old→new text, find the exact changed substring on the PDF
    #    page, and redact only that tiny rect.  Skipped when the retry system
    #    is requesting a specific broader strategy.
    if retry_mode in ("", "span_search"):
        result = _try_span_search_replace(doc, page, edit, preview_dir, save_id)
        if result and result.get("success"):
            try:
                doc.saveIncr()
            except Exception:
                try:
                    doc.save(pdf_path, incremental=False, garbage=2, deflate=True)
                except Exception as e2:
                    doc.close()
                    return {"success": False, "error": f"Save failed (span_search): {e2}"}
            doc.close()
            return {"success": True}
        else:
            # span_search couldn't handle it — fall through to normal strategy
            print("  [span_search] miss, falling back to normal strategy", file=sys.stderr)
            doc.close()
            doc = fitz.open(pdf_path)
            page = doc[page_index]
            retry_mode = ""  # reset so normal path runs

    # ── 1. Establish the atomic target rect ────────────────────────────────────
    x0, y0, x1, y1 = [float(v) for v in original_bbox]
    ix0, iy0, ix1, iy1 = [float(v) for v in insert_bbox]
    try:
        origin_x = float(edit.get("origin_x") or ix0)
    except Exception:
        origin_x = ix0
    try:
        origin_y = float(edit.get("origin_y") or iy1)
    except Exception:
        origin_y = iy1
    exact_target_pdf_rect = fitz.Rect(x0, y0, x1, y1) & page.rect
    exact_insert_pdf_rect = fitz.Rect(ix0, iy0, ix1, iy1) & page.rect
    exact_origin = fitz.Point(origin_x, origin_y)
    move_only = (
        _normalized_compare_text(original_text) == _normalized_compare_text(new_text)
        and not _is_same_rect(exact_target_pdf_rect, exact_insert_pdf_rect)
    )
    if retry_mode == "source_ops_strict":
        target_pdf_rect = fitz.Rect(x0, y0, x1, y1) & page.rect
    else:
        target_pdf_rect = fitz.Rect(x0 - 1, y0 - 1, x1 + 1, y1 + 1) & page.rect
    insert_pdf_rect = fitz.Rect(ix0, iy0, ix1, iy1)
    if target_pdf_rect.is_empty or target_pdf_rect.width <= 0 or target_pdf_rect.height <= 0:
        doc.close()
        return {"success": False, "error": "original_bbox is empty or invalid"}

    paragraph = _paragraph_dna(edit, target_pdf_rect, insert_pdf_rect)
    _font_key, font_obj, font_source = _resolve_font(doc, page, font_name, font_weight, font_xref)
    effective_font_size = paragraph["font_size"] or font_size

    line_metrics = paragraph["line_metrics"]
    reflow_text = _normalize_reflow_text(new_text, line_metrics, paragraph["leading"], paragraph.get("paragraph_groups"))
    changed_line_rect, changed_line_text, _changed_start, _changed_end = _select_changed_line_range(
        edit.get("original_text") or "",
        new_text,
        line_metrics,
    )
    locked_suffix_rect, locked_suffix_text, _locked_start, _locked_end = _select_locked_suffix_range(
        new_text,
        line_metrics,
    )
    changed_group_rect, changed_group_text = _select_changed_paragraph_groups(
        edit.get("original_text") or "",
        new_text,
        paragraph,
    )

    single_line_fit_rect = _union_rect(target_pdf_rect, insert_pdf_rect)
    single_line_origin = fitz.Point(origin_x, origin_y)
    if line_metrics:
        delta_x = insert_pdf_rect.x0 - x0
        delta_y = insert_pdf_rect.y0 - y0
        single_line_origin = fitz.Point(
            float(line_metrics[0].get("origin_x") or origin_x) + delta_x,
            float(line_metrics[0].get("origin_y") or origin_y) + delta_y,
        )
    use_atomic_line = len(line_metrics) <= 1 and _fits_single_line(new_text, single_line_fit_rect, font_obj, effective_font_size)
    if move_only:
        redaction_rect = _build_redaction_rect(target_pdf_rect, target_pdf_rect) & page.rect
        render_rect = insert_pdf_rect
        render_text = new_text
        strategy = "atomic_move"
    elif retry_mode == "precise_coords_strict" and "\n" not in new_text:
        exact_render_rect = exact_insert_pdf_rect if not exact_insert_pdf_rect.is_empty else exact_target_pdf_rect
        redaction_rect = _build_precise_redaction_rect(exact_target_pdf_rect) & page.rect
        render_rect = exact_render_rect
        render_text = new_text
        single_line_origin = exact_origin
        strategy = "precise_coords_line"
    elif retry_mode == "paragraph_strict":
        paragraph_pdf_rect = (changed_group_rect or paragraph["paragraph_rect"]) & page.rect
        redaction_rect = _build_redaction_rect(paragraph_pdf_rect, paragraph_pdf_rect) & page.rect
        render_rect = paragraph_pdf_rect
        render_text = changed_group_text or reflow_text
        strategy = "atomic_paragraph"
    elif retry_mode == "line_range_strict" and changed_line_rect is not None and changed_line_text is not None:
        redaction_rect = _build_redaction_rect(changed_line_rect, changed_line_rect) & page.rect
        render_rect = changed_line_rect & page.rect
        render_text = changed_line_text
        strategy = "atomic_line_range"
    elif retry_mode == "source_ops_strict" and _fits_single_line(new_text, single_line_fit_rect, font_obj, effective_font_size):
        redaction_rect = _union_rect(target_pdf_rect, insert_pdf_rect) & page.rect
        render_rect = insert_pdf_rect
        render_text = new_text
        strategy = "atomic_line"
    elif use_atomic_line:
        redaction_rect = _build_redaction_rect(target_pdf_rect, insert_pdf_rect) & page.rect
        render_rect = insert_pdf_rect
        render_text = new_text
        strategy = "atomic_line"
    elif changed_line_rect is not None and changed_line_text is not None:
        redaction_rect = _build_redaction_rect(changed_line_rect, changed_line_rect) & page.rect
        render_rect = changed_line_rect & page.rect
        render_text = changed_line_text
        strategy = "atomic_line_range"
    elif locked_suffix_rect is not None and locked_suffix_text is not None:
        redaction_rect = _build_redaction_rect(locked_suffix_rect, locked_suffix_rect) & page.rect
        render_rect = locked_suffix_rect & page.rect
        render_text = locked_suffix_text
        strategy = "locked_suffix_range"
    else:
        paragraph_pdf_rect = (changed_group_rect or paragraph["paragraph_rect"]) & page.rect
        redaction_rect = _build_redaction_rect(paragraph_pdf_rect, paragraph_pdf_rect) & page.rect
        render_rect = paragraph_pdf_rect
        render_text = changed_group_text or reflow_text
        strategy = "atomic_paragraph"

    if retry_mode == "precise_coords_strict" and strategy == "precise_coords_line":
        redaction_rect = _inflate_rect(redaction_rect, redaction_padding or 0.0, (redaction_padding or 0.0) * 0.8 if redaction_padding else 0.0) & page.rect
    elif retry_mode == "line_range_strict" and strategy == "atomic_line_range":
        redaction_rect = render_rect & page.rect
    elif retry_mode == "paragraph_strict" and strategy == "atomic_paragraph":
        redaction_rect = render_rect & page.rect

    if redaction_padding > 0 and strategy != "precise_coords_line":
        redaction_rect = _inflate_rect(redaction_rect, redaction_padding) & page.rect

    # ── 2. Redact only the atomic target ───────────────────────────────────────
    print(
        f"  strategy={strategy} retry_mode={retry_mode or 'default'} redaction_rect={redaction_rect}  render_rect={render_rect}  font={font_source}  fs={effective_font_size:.2f}",
        file=sys.stderr,
    )

    preview_clip_rect = _compute_preview_clip(page.rect, redaction_rect)
    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redaction_rect, render_rect, "before", save_id)
        except Exception as preview_err:
            print(f"  ⚠ before preview failed: {preview_err}", file=sys.stderr)

    source_op_removed = 0
    if _strip_using_source_content_ops is not None and edit.get("source_content_ops"):
        try:
            source_op_removed = int(_strip_using_source_content_ops(doc, page, edit) or 0)
        except Exception as strip_err:
            print(f"  ⚠ source_content_ops strip failed: {strip_err}", file=sys.stderr)
            source_op_removed = 0

    if source_op_removed > 0:
        print(f"  Stripped {source_op_removed} source operator(s) before redaction fallback", file=sys.stderr)
        residual_cleanup = _cleanup_residual_spans(page, redaction_rect) if move_only else 0
    else:
        live_save_redaction(page, redaction_rect)

        residual_cleanup = 0 if strategy in ("atomic_line", "precise_coords_line", "atomic_move") or retry_mode else _cleanup_residual_spans(page, redaction_rect)
        if residual_cleanup:
            print(f"  Removed {residual_cleanup} residual span fragment(s)", file=sys.stderr)

    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redaction_rect, render_rect, "redacted", save_id)
        except Exception as preview_err:
            print(f"  ⚠ redacted preview failed: {preview_err}", file=sys.stderr)

    if not new_text.strip():
        # Deletion only
        if preview_dir:
            try:
                _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redaction_rect, render_rect, "final", save_id)
            except Exception as preview_err:
                print(f"  ⚠ final preview failed: {preview_err}", file=sys.stderr)
        try:
            doc.saveIncr()
            doc.close()
            return {"success": True}
        except Exception as e:
            doc.close()
            return {"success": False, "error": f"saveIncr failed: {e}"}

    # ── 3. Reinsert text into the atomic target ────────────────────────────────
    color = _parse_color(color_str)

    try:
        if strategy in ("atomic_line", "atomic_line_range", "locked_suffix_range", "precise_coords_line"):
            if strategy == "precise_coords_line" and "\n" not in render_text:
                ok, used_fs, mode = _insert_precise_coordinate_text(
                    page=page,
                    origin=single_line_origin,
                    rect=render_rect,
                    text=render_text,
                    font_obj=font_obj,
                    fontsize=effective_font_size,
                    color=color,
                )
            elif strategy == "atomic_line" and "\n" not in render_text:
                ok, used_fs, mode = _insert_single_line_text(
                    page=page,
                    origin=single_line_origin,
                    text=render_text,
                    font_obj=font_obj,
                    fontsize=effective_font_size,
                    color=color,
                    max_width=max(single_line_fit_rect.width, render_rect.width),
                )
            else:
                ok, used_fs, mode = _insert_text_in_rect(
                    page=page,
                    rect=render_rect,
                    text=render_text,
                    font_obj=font_obj,
                    fontsize=effective_font_size,
                    color=color,
                    align=fitz.TEXT_ALIGN_LEFT,
                )
            if not ok:
                raise RuntimeError("Atomic line write did not fit target rect")
            print(f"  Inserted line via {mode} fs={used_fs:.2f}", file=sys.stderr)
        else:
            fitted_rect, _fitted_lineheight, fitted_overflow = _fit_reflow_rect(
                page.rect,
                render_rect,
                render_text,
                font_obj,
                effective_font_size,
                paragraph["leading"],
                paragraph["align"],
            )
            if fitted_rect is None:
                raise RuntimeError(f"Paragraph reflow overflowed: {fitted_overflow}")
            render_rect = fitted_rect
            ok, lineheight, overflow = _reflow_paragraph(
                page=page,
                rect=render_rect,
                text=render_text,
                font_obj=font_obj,
                fontsize=effective_font_size,
                leading=paragraph["leading"],
                color=color,
                align=paragraph["align"],
            )
            if not ok:
                raise RuntimeError(f"Paragraph reflow overflowed: {overflow}")
            print(
                f"  Reflowed into paragraph_rect={render_rect} fs={effective_font_size:.2f} leading={paragraph['leading']:.2f} lh={lineheight:.3f}",
                file=sys.stderr,
            )
    except Exception as te:
        doc.close()
        return {"success": False, "error": f"Text insertion failed: {te}"}

    if preview_dir:
        try:
            _write_preview_assets(page, preview_dir, edit, preview_clip_rect, redaction_rect, render_rect, "final", save_id)
        except Exception as preview_err:
            print(f"  ⚠ final preview failed: {preview_err}", file=sys.stderr)

    # ── 4. Incremental save ────────────────────────────────────────────────────
    try:
        doc.saveIncr()
    except Exception as e:
        # saveIncr can fail on PDFs that have been linearized differently;
        # fall back to a full in-place rewrite
        try:
            doc.save(pdf_path, incremental=False, garbage=2, deflate=True)
        except Exception as e2:
            doc.close()
            return {"success": False, "error": f"Save failed: {e} / fallback: {e2}"}

    doc.close()
    return {"success": True}


# ── Entry point ────────────────────────────────────────────────────────────────

def main():
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Usage: live_save.py <pdf_path> <edit_json_file> [preview_dir]"}))
        sys.exit(1)

    pdf_path = sys.argv[1]
    edit_file = sys.argv[2]
    preview_dir = sys.argv[3] if len(sys.argv) > 3 else None

    if not os.path.exists(pdf_path):
        print(json.dumps({"success": False, "error": f"PDF not found: {pdf_path}"}))
        sys.exit(1)

    if not os.path.exists(edit_file):
        print(json.dumps({"success": False, "error": f"Edit file not found: {edit_file}"}))
        sys.exit(1)

    try:
        with open(edit_file, "r", encoding="utf-8") as f:
            edit = json.load(f)
    except Exception as e:
        print(json.dumps({"success": False, "error": f"Cannot parse edit JSON: {e}"}))
        sys.exit(1)

    print(f"live_save: page={edit.get('page_number')} orig='{edit.get('original_text')}' new='{edit.get('new_text')}'", file=sys.stderr)

    result = live_save_patch(pdf_path, edit, preview_dir)
    print(json.dumps(result))
    sys.exit(0 if result.get("success") else 1)


if __name__ == "__main__":
    main()
