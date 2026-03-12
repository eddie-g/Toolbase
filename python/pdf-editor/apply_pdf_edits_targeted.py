#!/usr/bin/env python3
"""
Apply PDF edits using a narrowly targeted strategy.

This path intentionally avoids overlap-group cleanup and sibling reinserts.
Only explicitly changed edits are processed:
1. Remove original text via exact source_content_ops when available.
2. Otherwise redact precise search hits that intersect the field rect.
3. As a final fallback, redact only the field's own original_bbox.
4. Reinsert only the edited field into its bbox.
"""

import json
import os
import re
import shutil
import sys
from typing import Any, Dict, List, Optional

import fitz

from apply_pdf_edits import (
    _inflate_rect,
    _insert_styled_runs,
    _insert_text_by_line_metrics,
    _insert_text_strict_in_bbox,
    _pick_font_size_to_fit_rect,
    _resolve_insert_font,
    _strip_exact_text_from_trace_tj_runs,
    _strip_using_source_content_ops,
    map_font_name,
)


def normalize_smart_quotes(text: str) -> str:
    return (
        str(text or "")
        .replace("\u2018", "'")
        .replace("\u2019", "'")
        .replace("\u201c", '"')
        .replace("\u201d", '"')
    )


def _normalize_text(value: Any) -> str:
    return re.sub(r"\s+", " ", normalize_smart_quotes(str(value or "")).strip())


def _rect_from_values(raw: Any) -> Optional[fitz.Rect]:
    if not isinstance(raw, (list, tuple)) or len(raw) < 4:
        return None
    try:
        return fitz.Rect(float(raw[0]), float(raw[1]), float(raw[2]), float(raw[3]))
    except Exception:
        return None


def _normalize_style_runs(runs: Any) -> List[Dict[str, Any]]:
    normalized = []
    if not isinstance(runs, list):
        return normalized

    for run in runs:
        if not isinstance(run, dict):
            continue
        color = str(run.get("hex_color") or run.get("color") or "").strip().lower()
        if color and not color.startswith("#"):
            color = f"#{color}"
        normalized.append({
            "text": _normalize_text(run.get("text")),
            "font": str(run.get("font") or "").strip(),
            "font_size": round(float(run.get("font_size") or 0.0), 2),
            "font_weight": str(run.get("font_weight") or "").strip(),
            "italic": bool(run.get("italic", False)),
            "bold": bool(run.get("bold", False)),
            "underline": bool(run.get("underline", False)),
            "color": color,
            "left": round(float(run.get("left") or 0.0), 2),
            "top": round(float(run.get("top") or 0.0), 2),
            "width": round(float(run.get("width") or 0.0), 2),
            "height": round(float(run.get("height") or 0.0), 2),
            "origin_x": round(float(run.get("origin_x") or 0.0), 2),
            "origin_y": round(float(run.get("origin_y") or 0.0), 2),
        })
    return normalized


def _styles_changed(edit: Dict[str, Any]) -> bool:
    word_styles = _normalize_style_runs(edit.get("word_styles"))
    line_metrics = _normalize_style_runs(edit.get("line_metrics"))
    if word_styles and line_metrics:
        return word_styles != line_metrics

    font_weight = str(edit.get("font_weight") or "").strip().lower()
    font_style = str(edit.get("font_style") or "normal").strip().lower()
    color = str(edit.get("color") or "#000000").strip().lower()
    if color and not color.startswith("#"):
        color = f"#{color}"
    return (
        font_weight not in ("", "400", "normal")
        or font_style != "normal"
        or color not in ("", "#000000")
        or bool(edit.get("underline"))
    )


def _anchor_changed(edit: Dict[str, Any], tolerance: float = 0.75) -> bool:
    original_bbox = edit.get("original_bbox")
    bbox = edit.get("bbox")
    if not isinstance(original_bbox, (list, tuple)) or not isinstance(bbox, (list, tuple)):
        return False
    if len(original_bbox) < 4 or len(bbox) < 4:
        return False
    return (
        abs(float(original_bbox[0]) - float(bbox[0])) > tolerance
        or abs(float(original_bbox[1]) - float(bbox[1])) > tolerance
    )


def _is_material_edit(edit: Dict[str, Any]) -> bool:
    if _normalize_text(edit.get("original_text")) != _normalize_text(edit.get("new_text")):
        return True
    if _anchor_changed(edit):
        return True
    return _styles_changed(edit)


def _dedupe_material_edits(edits_data: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    material = []
    seen = set()
    for edit in edits_data:
        if not isinstance(edit, dict):
            continue
        if not _is_material_edit(edit):
            continue
        page_num = edit.get("page_number") or edit.get("page_num")
        try:
            page_num = int(page_num)
        except Exception:
            continue
        bbox = edit.get("bbox") or edit.get("original_bbox") or []
        bbox_key = tuple(round(float(v), 2) for v in bbox[:4]) if isinstance(bbox, (list, tuple)) and len(bbox) >= 4 else ()
        dedupe_key = (
            page_num,
            bbox_key,
            _normalize_text(edit.get("original_text")),
            _normalize_text(edit.get("new_text")),
        )
        if dedupe_key in seen:
            continue
        seen.add(dedupe_key)
        material.append(edit)
    return material


def _target_original_rect(edit: Dict[str, Any]) -> Optional[fitz.Rect]:
    return _rect_from_values(edit.get("original_bbox")) or _rect_from_values(edit.get("bbox"))


def _collect_precise_search_rects(page: fitz.Page, original_text: str, target_rect: Optional[fitz.Rect]) -> List[fitz.Rect]:
    precise_rects: List[fitz.Rect] = []
    seen = set()
    for raw_line in re.split(r"[\r\n]+", str(original_text or "")):
        line_text = normalize_smart_quotes(raw_line).strip()
        if not line_text:
            continue
        try:
            matches = page.search_for(line_text)
        except Exception:
            matches = []
        for match_rect in matches:
            candidate = fitz.Rect(match_rect)
            if target_rect is not None and not _inflate_rect(target_rect, 2.0).intersects(candidate):
                continue
            key = tuple(round(v, 2) for v in (candidate.x0, candidate.y0, candidate.x1, candidate.y1))
            if key in seen:
                continue
            seen.add(key)
            precise_rects.append(candidate)
    return precise_rects


def _parse_rgb(color_value: Any) -> tuple[float, float, float]:
    color = str(color_value or "#000000").strip().lstrip("#")
    if len(color) != 6:
        return (0.0, 0.0, 0.0)
    try:
        return tuple(int(color[idx:idx + 2], 16) / 255.0 for idx in (0, 2, 4))
    except Exception:
        return (0.0, 0.0, 0.0)


def _clamp_rect_to_page(page: fitz.Page, rect: fitz.Rect) -> fitz.Rect:
    page_rect = page.rect
    clamped = rect & page_rect
    if clamped.is_empty or clamped.width < 1 or clamped.height < 1:
        return fitz.Rect(page_rect.x0, page_rect.y0, min(page_rect.x0 + 200, page_rect.x1), min(page_rect.y0 + 50, page_rect.y1))
    return clamped


def apply_targeted_edits_to_pdf(pdf_path: str, edits_data: List[Dict[str, Any]]) -> bool:
    preflight_path = f"{pdf_path}.preflight.tmp"
    temp_path = f"{pdf_path}.tmp"
    try:
        material_edits = _dedupe_material_edits(edits_data)
        print(f"Material edits: {len(material_edits)} / {len(edits_data)}")
        if not material_edits:
            print("No material edits to apply.")
            return True

        doc = fitz.open(pdf_path)
        print(f"Opened PDF with {doc.page_count} page(s)")
        inserted_fonts: Dict[str, str] = {}

        edits_by_page: Dict[int, List[Dict[str, Any]]] = {}
        for edit in material_edits:
            page_num = int(edit.get("page_number") or edit.get("page_num"))
            if page_num < 1 or page_num > doc.page_count:
                print(f"  Skipping out-of-range edit on page {page_num}")
                continue
            edits_by_page.setdefault(page_num, []).append(edit)

        print("\n" + "=" * 50)
        print("PHASE 0: Source-op removal on original streams")
        print("=" * 50)
        for page_num, page_edits in edits_by_page.items():
            page = doc[page_num - 1]
            for edit in page_edits:
                original_text = str(edit.get("original_text") or "")
                if not original_text.strip():
                    continue
                removed = _strip_using_source_content_ops(doc, page, edit)
                if removed > 0:
                    edit["_targeted_removed"] = True
                    print(f"Page {page_num}: removed '{original_text[:40]}' via {removed} source op(s)")

        for page_num in edits_by_page:
            page = doc[page_num - 1]
            page.clean_contents()
            try:
                page.wrap_contents()
            except Exception:
                pass

        try:
            doc.save(preflight_path, incremental=False, encryption=fitz.PDF_ENCRYPT_KEEP, garbage=4, deflate=True, clean=True)
        except TypeError:
            doc.save(preflight_path, incremental=False, encryption=fitz.PDF_ENCRYPT_KEEP, garbage=4, deflate=True)
        doc.close()
        doc = fitz.open(preflight_path)

        print("\n" + "=" * 50)
        print("PHASE A: Targeted removal")
        print("=" * 50)
        for page_num, page_edits in edits_by_page.items():
            page = doc[page_num - 1]
            redaction_count = 0
            print(f"Page {page_num}: {len(page_edits)} edit(s)")
            for edit in page_edits:
                original_text = str(edit.get("original_text") or "")
                if not original_text.strip():
                    continue
                if edit.get("_targeted_removed"):
                    continue

                target_rect = _target_original_rect(edit)
                if target_rect is not None:
                    removed = _strip_exact_text_from_trace_tj_runs(doc, page, target_rect, original_text)
                if removed > 0:
                    print(f"  Removed '{original_text[:40]}' via {removed} exact trace run(s)")
                    edit["_targeted_removed"] = True
                    continue

                precise_rects = _collect_precise_search_rects(page, original_text, target_rect)
                if precise_rects:
                    for precise_rect in precise_rects:
                        page.add_redact_annot(_inflate_rect(precise_rect, 0.35), fill=None)
                    redaction_count += len(precise_rects)
                    print(f"  Redacting '{original_text[:40]}' via {len(precise_rects)} search rect(s)")
                    continue

                if target_rect is not None:
                    page.add_redact_annot(_inflate_rect(target_rect, 0.35), fill=None)
                    redaction_count += 1
                    print(f"  Redacting '{original_text[:40]}' via original_bbox fallback")

            if redaction_count > 0:
                page.apply_redactions(images=0)
                try:
                    page.clean_contents()
                except Exception:
                    pass
                print(f"  Applied {redaction_count} targeted redaction(s)")

        print("\n" + "=" * 50)
        print("PHASE B: Targeted insertion")
        print("=" * 50)
        insert_failures = 0
        for page_num, page_edits in edits_by_page.items():
            page = doc[page_num - 1]
            for edit in page_edits:
                new_text = normalize_smart_quotes(str(edit.get("new_text") or ""))
                if not new_text.strip() and not edit.get("rich_html"):
                    continue

                target_rect = _rect_from_values(edit.get("bbox")) or _target_original_rect(edit)
                if target_rect is None:
                    print("  Skipping insert with no bbox")
                    continue

                rect = _clamp_rect_to_page(page, target_rect)
                font_name = str(edit.get("font") or "Helvetica")
                try:
                    font_size = float(edit.get("font_size") or 12.0)
                except Exception:
                    font_size = 12.0
                rgb = _parse_rgb(edit.get("color"))

                try:
                    insert_font_name, measure_font_obj = _resolve_insert_font(
                        page,
                        inserted_fonts,
                        font_name,
                        font_weight=edit.get("font_weight"),
                    )
                except Exception as err:
                    print(f"  Safe font resolution failed for '{font_name}': {err}")
                    insert_font_name = map_font_name(font_name)
                    measure_font_obj = None

                line_height = edit.get("line_height")
                try:
                    line_height = float(line_height) if line_height is not None else None
                except Exception:
                    line_height = None
                line_height_factor = max((line_height / font_size), 1.0) if line_height and font_size > 0 else 1.2
                fitted_font_size = _pick_font_size_to_fit_rect(
                    new_text,
                    insert_font_name,
                    font_size,
                    rect,
                    line_height_factor=line_height_factor,
                    min_font_size=4.0,
                    font_obj=measure_font_obj,
                )
                synthetic_textbox = bool(edit.get("synthetic_textbox"))

                if not synthetic_textbox and _insert_styled_runs(page, edit, rgb):
                    print(f"  Inserted styled run '{new_text[:40]}'")
                    continue

                if _insert_text_by_line_metrics(
                    page=page,
                    edit=edit,
                    fontname=insert_font_name,
                    font_obj=measure_font_obj,
                    color=rgb,
                ):
                    print(f"  Inserted line-metric block '{new_text[:40]}'")
                    continue

                try:
                    ok, used_fs, mode = _insert_text_strict_in_bbox(
                        page=page,
                        rect=rect,
                        text=new_text,
                        fontname=insert_font_name,
                        color=rgb,
                        desired_font_size=fitted_font_size,
                        line_height_factor=line_height_factor,
                        font_obj=measure_font_obj,
                    )
                except Exception as err:
                    print(f"  Insert failed for '{new_text[:40]}': {err}")
                    ok = False
                    used_fs = fitted_font_size
                    mode = "error"

                if ok:
                    print(
                        f"  Inserted '{new_text[:40]}' at "
                        f"({rect.x0:.1f}, {rect.y0:.1f}, {rect.x1:.1f}, {rect.y1:.1f}) "
                        f"fs={used_fs:.2f} mode={mode}"
                    )
                else:
                    insert_failures += 1

        if insert_failures > 0:
            print(f"Aborting save because {insert_failures} insertion(s) failed")
            doc.close()
            return False

        print("\n" + "=" * 50)
        print("PHASE C: Saving PDF")
        print("=" * 50)
        try:
            doc.save(temp_path, incremental=False, encryption=fitz.PDF_ENCRYPT_KEEP, garbage=4, deflate=True, clean=True)
        except TypeError:
            doc.save(temp_path, incremental=False, encryption=fitz.PDF_ENCRYPT_KEEP, garbage=4, deflate=True)
        doc.close()
        shutil.move(temp_path, pdf_path)
        if os.path.exists(preflight_path):
            os.remove(preflight_path)
        print("Targeted save completed successfully")
        return True
    except Exception as err:
        print(f"Targeted save failed: {err}")
        import traceback
        traceback.print_exc()
        return False
    finally:
        for path in (preflight_path, temp_path):
            if os.path.exists(path):
                try:
                    os.remove(path)
                except Exception:
                    pass


def main() -> None:
    if len(sys.argv) < 3:
        print("Usage: python3 apply_pdf_edits_targeted.py <pdf_path> <edits_json_file>")
        sys.exit(1)

    pdf_path = sys.argv[1]
    edits_file = sys.argv[2]
    if not os.path.exists(pdf_path):
        print(f"PDF file not found: {pdf_path}")
        sys.exit(1)
    if not os.path.exists(edits_file):
        print(f"Edits file not found: {edits_file}")
        sys.exit(1)

    with open(edits_file, "r", encoding="utf-8") as handle:
        edits_data = json.load(handle)

    print("=" * 60)
    print("PDF Targeted Save")
    print("=" * 60)
    print(f"PDF: {os.path.basename(pdf_path)}")
    print(f"Edits received: {len(edits_data)}")
    print()

    success = apply_targeted_edits_to_pdf(pdf_path, edits_data)
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
