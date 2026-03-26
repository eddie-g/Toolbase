#!/usr/bin/env python3
"""
Rebuild only the touched PDF pages from extraction data, then stitch untouched
pages back from the preserve-source PDF.

This is used for editor saves/downloads where any change on a page should cause
that whole page to be regenerated, while untouched pages remain byte-faithful to
the preserve source.
"""

import json
import os
import re
import sys
from typing import Any, Dict, List, Optional, Set, Tuple

import fitz

from apply_annotations_direct import (
    draw_eraser,
    draw_shape,
    draw_signature,
    draw_table,
    draw_text,
)
from pdf_annotation_contract import normalize_annotations_for_pdf_export, sanitize_pdf_text as sanitize_text
from rebuild_pdf_from_overlay_extraction import build_font, parse_color


def load_json_arg(arg: str):
    if arg.startswith("@"):
        with open(arg[1:], "r", encoding="utf-8") as fh:
            return json.load(fh)
    with open(arg, "r", encoding="utf-8") as fh:
        return json.load(fh)


def parse_promoted_source_key(value: Any) -> Optional[Tuple[int, int]]:
    match = re.match(r"^block-(\d+)-(\d+)(?:-.+)?$", str(value or "").strip())
    if not match:
        return None
    page_number = int(match.group(1))
    block_num = int(match.group(2))
    if page_number <= 0:
        return None
    return page_number - 1, block_num


def parse_promoted_source_key_details(
    value: Any,
) -> Optional[Tuple[int, int, Optional[int], Optional[int]]]:
    match = re.match(
        r"^block-(\d+)-(\d+)(?:-lines-(\d+)-(\d+))?(?:-.+)?$",
        str(value or "").strip(),
    )
    if not match:
        return None
    page_number = int(match.group(1))
    block_num = int(match.group(2))
    if page_number <= 0:
        return None
    line_start = int(match.group(3)) if match.group(3) is not None else None
    line_end = int(match.group(4)) if match.group(4) is not None else None
    return page_number - 1, block_num, line_start, line_end


def rect_from_bbox(
    bbox: Any,
    *,
    padding: float = 0.75,
) -> Optional[fitz.Rect]:
    if not isinstance(bbox, (list, tuple)) or len(bbox) < 4:
        return None
    try:
        x0 = float(bbox[0])
        y0 = float(bbox[1])
        x1 = float(bbox[2])
        y1 = float(bbox[3])
    except Exception:
        return None
    rect = fitz.Rect(x0, y0, x1, y1)
    if rect.is_empty or rect.width <= 0 or rect.height <= 0:
        return None
    return fitz.Rect(rect.x0 - padding, rect.y0 - padding, rect.x1 + padding, rect.y1 + padding)


def rect_from_box_metrics(
    left: Any,
    top: Any,
    width: Any,
    height: Any,
    *,
    padding: float = 0.75,
) -> Optional[fitz.Rect]:
    try:
        x0 = float(left)
        y0 = float(top)
        rect_width = float(width)
        rect_height = float(height)
    except Exception:
        return None
    if rect_width <= 0 or rect_height <= 0:
        return None
    return rect_from_bbox([x0, y0, x0 + rect_width, y0 + rect_height], padding=padding)


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


def get_widget_rects(page: fitz.Page) -> List[fitz.Rect]:
    rects: List[fitz.Rect] = []
    try:
        widgets = page.widgets() or []
    except Exception:
        widgets = []

    for widget in widgets:
        try:
            rect = fitz.Rect(widget.rect)
        except Exception:
            continue
        if rect.is_empty or rect.width <= 0 or rect.height <= 0:
            continue
        # Expand slightly to catch extraction words that sit right on the field edge.
        rects.append(
            fitz.Rect(rect.x0 - 0.75, rect.y0 - 0.75, rect.x1 + 0.75, rect.y1 + 0.75)
        )

    return rects


def word_rect(word: Dict[str, Any]) -> Optional[fitz.Rect]:
    left = word.get("left")
    top = word.get("top")
    width = word.get("width")
    height = word.get("height")

    try:
        if left is not None and top is not None and width is not None and height is not None:
            rect = fitz.Rect(
                float(left),
                float(top),
                float(left) + float(width),
                float(top) + float(height),
            )
            if not rect.is_empty and rect.width > 0 and rect.height > 0:
                return rect
    except Exception:
        pass

    try:
        x0 = float(word.get("x0"))
        y0 = float(word.get("y0"))
        x1 = float(word.get("x1"))
        y1 = float(word.get("y1"))
        rect = fitz.Rect(x0, y0, x1, y1)
        if not rect.is_empty and rect.width > 0 and rect.height > 0:
            return rect
    except Exception:
        pass

    return None


def overlaps_widget_rects(rect: Optional[fitz.Rect], widget_rects: List[fitz.Rect]) -> bool:
    if rect is None:
        return False
    for widget_rect in widget_rects:
        if not (rect & widget_rect).is_empty:
            return True
    return False


def point_in_rect(rect: fitz.Rect, x: float, y: float) -> bool:
    return rect.x0 <= x <= rect.x1 and rect.y0 <= y <= rect.y1


def overlaps_mask_rects(rect: Optional[fitz.Rect], mask_rects: List[fitz.Rect]) -> bool:
    if rect is None:
        return False

    center_x = rect.x0 + (rect.width / 2.0)
    center_y = rect.y0 + (rect.height / 2.0)
    rect_area = max(0.01, rect.get_area())

    for mask_rect in mask_rects:
        if point_in_rect(mask_rect, center_x, center_y):
            return True
        intersection = rect & mask_rect
        if intersection.is_empty:
            continue
        if intersection.get_area() >= (rect_area * 0.5):
            return True
    return False


def get_block_lookup(page_data: Dict[str, Any]) -> Dict[int, Dict[str, Any]]:
    lookup: Dict[int, Dict[str, Any]] = {}
    for block in page_data.get("blocks") or []:
        if not isinstance(block, dict):
            continue
        try:
            block_num = int(block.get("block_num"))
        except Exception:
            continue
        lookup[block_num] = block
    return lookup


def normalize_text_lines(value: Any) -> List[str]:
    normalized = str(value or "").replace("\r\n", "\n").replace("\r", "\n")
    return [str(line) for line in normalized.split("\n")]


def get_annotation_source_line_count(annotation: Dict[str, Any]) -> int:
    raw_boxes = annotation.get("sourceLineBBoxes")
    if isinstance(raw_boxes, list) and raw_boxes:
        return len(raw_boxes)

    raw_source_lines = annotation.get("sourceTextLines")
    if isinstance(raw_source_lines, list) and raw_source_lines:
        return len(raw_source_lines)

    parsed = parse_promoted_source_key_details(annotation.get("promotedSourceKey"))
    if parsed:
        _page_index, _block_num, line_start, line_end = parsed
        if line_start is not None and line_end is not None and line_end >= line_start:
            return (line_end - line_start) + 1

    return 0


def should_expand_promoted_annotation_to_block(
    annotation: Dict[str, Any],
    page_data: Dict[str, Any],
) -> bool:
    if not bool(annotation.get("promotedFromExtraction")):
        return False

    parsed = parse_promoted_source_key_details(annotation.get("promotedSourceKey"))
    if not parsed:
        return False

    _page_index, block_num, line_start, line_end = parsed
    if line_start is None or line_end is None:
        return False

    block = get_block_lookup(page_data).get(block_num)
    if not isinstance(block, dict):
        return False

    block_line_bboxes = [
        bbox
        for bbox in (block.get("line_bboxes") or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if len(block_line_bboxes) <= 1:
        return False

    text_line_count = len(normalize_text_lines(annotation.get("text")))
    source_line_count = max(1, get_annotation_source_line_count(annotation))
    return text_line_count > source_line_count


def expand_promoted_annotation_to_block(
    annotation: Dict[str, Any],
    page_data: Dict[str, Any],
    page_height: float,
) -> Dict[str, Any]:
    if not should_expand_promoted_annotation_to_block(annotation, page_data):
        return annotation

    parsed = parse_promoted_source_key_details(annotation.get("promotedSourceKey"))
    if not parsed:
        return annotation

    _page_index, block_num, _line_start, _line_end = parsed
    block = get_block_lookup(page_data).get(block_num)
    if not isinstance(block, dict):
        return annotation

    try:
        block_left = float(block.get("left"))
        block_top = float(block.get("top"))
        block_width = float(block.get("width"))
        block_height = float(block.get("height"))
    except Exception:
        return annotation

    if block_width <= 0 or block_height <= 0:
        return annotation

    expanded = dict(annotation)
    expanded["pdfX"] = block_left
    expanded["pdfY"] = max(0.0, page_height - (block_top + block_height))
    expanded["pdfWidth"] = block_width
    expanded["pdfHeight"] = block_height
    expanded["sourceBlockLeft"] = block_left
    expanded["sourceBlockTop"] = block_top
    expanded["sourceBlockWidth"] = block_width
    expanded["sourceBlockHeight"] = block_height

    block_text_lines = block.get("text_lines")
    if isinstance(block_text_lines, list) and block_text_lines:
        expanded["sourceTextLines"] = [str(line or "") for line in block_text_lines]

    block_line_bboxes = [
        bbox
        for bbox in (block.get("line_bboxes") or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if block_line_bboxes:
        expanded["sourceLineBBoxes"] = block_line_bboxes

    block_spans = [
        span
        for span in (block.get("spans") or [])
        if isinstance(span, dict)
    ]
    if block_spans:
        expanded["sourceSpans"] = block_spans

    return expanded


def get_block_word_union_rect(page_data: Dict[str, Any], block_num: int) -> Optional[fitz.Rect]:
    word_rects = []
    for word in page_data.get("words") or []:
        if not isinstance(word, dict):
            continue
        try:
            word_block_num = int(word.get("block_num"))
        except Exception:
            continue
        if word_block_num != block_num:
            continue
        rect = word_rect(word)
        if rect is not None:
            word_rects.append(rect)

    if not word_rects:
        return None

    x0 = min(rect.x0 for rect in word_rects)
    y0 = min(rect.y0 for rect in word_rects)
    x1 = max(rect.x1 for rect in word_rects)
    y1 = max(rect.y1 for rect in word_rects)
    return rect_from_bbox([x0, y0, x1, y1])


def get_annotation_mask_rects(annotation: Dict[str, Any]) -> List[fitz.Rect]:
    rects: List[fitz.Rect] = []
    for bbox in annotation.get("sourceLineBBoxes") or []:
        rect = rect_from_bbox(bbox)
        if rect is not None:
            rects.append(rect)

    rect = rect_from_box_metrics(
        annotation.get("sourceBlockLeft"),
        annotation.get("sourceBlockTop"),
        annotation.get("sourceBlockWidth"),
        annotation.get("sourceBlockHeight"),
    )
    if rect is not None:
        rects.append(rect)
    return rects


def get_multiline_promoted_mask_rects(
    page_data: Dict[str, Any],
    annotation: Dict[str, Any],
) -> List[fitz.Rect]:
    if not bool(annotation.get("promotedFromExtraction")):
        return []

    text_line_count = len(normalize_text_lines(annotation.get("text")))
    source_line_count = max(1, get_annotation_source_line_count(annotation))
    if text_line_count <= source_line_count:
        return []

    try:
        anchor_left = float(annotation.get("sourceBlockLeft"))
        anchor_top = float(annotation.get("sourceBlockTop"))
    except Exception:
        return []

    try:
        line_height = float(annotation.get("lineHeight") or 0)
    except Exception:
        line_height = 0.0
    if line_height <= 0:
        source_boxes = [
            bbox
            for bbox in (annotation.get("sourceLineBBoxes") or [])
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
        ]
        source_heights = [
            max(0.0, float(bbox[3]) - float(bbox[1]))
            for bbox in source_boxes
        ]
        line_height = max(source_heights) if source_heights else 14.4

    vertical_end = anchor_top + max(
        float(annotation.get("sourceBlockHeight") or 0),
        line_height * text_line_count,
    ) + 6.0
    horizontal_tolerance = max(12.0, float(annotation.get("fontSize") or 12) * 0.75)
    rects: List[fitz.Rect] = []

    for block in page_data.get("blocks") or []:
        if not isinstance(block, dict):
            continue
        try:
            block_left = float(block.get("left"))
            block_top = float(block.get("top"))
            block_height = float(block.get("height"))
        except Exception:
            continue

        if abs(block_left - anchor_left) > horizontal_tolerance:
            continue
        if (block_top + block_height) < (anchor_top - 4.0) or block_top > vertical_end:
            continue

        line_bboxes = [
            bbox
            for bbox in (block.get("line_bboxes") or [])
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
        ]
        if line_bboxes:
            for bbox in line_bboxes:
                rect = rect_from_bbox(bbox)
                if rect is not None:
                    rects.append(rect)
            continue

        rect = rect_from_box_metrics(
            block.get("left"),
            block.get("top"),
            block.get("width"),
            block.get("height"),
        )
        if rect is not None:
            rects.append(rect)

    return rects


def get_deleted_source_mask_rects(page_data: Dict[str, Any], source_key: Any) -> List[fitz.Rect]:
    parsed = parse_promoted_source_key_details(source_key)
    if not parsed:
        return []

    _page_index, block_num, line_start, line_end = parsed
    rects: List[fitz.Rect] = []
    block = get_block_lookup(page_data).get(block_num)

    if block is not None:
        line_bboxes = [
            bbox
            for bbox in (block.get("line_bboxes") or [])
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
        ]
        if line_bboxes and line_start is not None and line_end is not None:
            start = max(0, line_start)
            end = min(len(line_bboxes) - 1, line_end)
            for index in range(start, end + 1):
                rect = rect_from_bbox(line_bboxes[index])
                if rect is not None:
                    rects.append(rect)
            if rects:
                return rects
        if line_bboxes:
            for bbox in line_bboxes:
                rect = rect_from_bbox(bbox)
                if rect is not None:
                    rects.append(rect)
            if rects:
                return rects

        block_rect = rect_from_box_metrics(
            block.get("left"),
            block.get("top"),
            block.get("width"),
            block.get("height"),
        )
        if block_rect is not None:
            rects.append(block_rect)
            return rects

    union_rect = get_block_word_union_rect(page_data, block_num)
    if union_rect is not None:
        rects.append(union_rect)

    return rects


def should_skip_word(
    word: Dict[str, Any],
    mask_rects: List[fitz.Rect],
    widget_rects: List[fitz.Rect],
) -> bool:
    rect = word_rect(word)
    if overlaps_mask_rects(rect, mask_rects):
        return True
    if overlaps_widget_rects(rect, widget_rects):
        return True
    return False


def draw_extraction_words_for_page(
    rebuilt_doc: fitz.Document,
    font_lookup_doc: fitz.Document,
    page_index: int,
    page_data: Dict[str, Any],
    mask_rects: List[fitz.Rect],
    widget_rects: List[fitz.Rect],
    font_cache: Dict[str, fitz.Font],
) -> int:
    page = rebuilt_doc[page_index]
    page_words = page_data.get("words") or []
    seen_words = set()
    block_color_writers: Dict[Tuple[Tuple[float, float, float], Any], fitz.TextWriter] = {}
    drawn_words = 0

    sorted_words = sorted(
        [word for word in page_words if isinstance(word, dict)],
        key=lambda word: (float(word.get("top", 0) or 0), float(word.get("left", 0) or 0)),
    )

    for word in sorted_words:
        if should_skip_word(word, mask_rects, widget_rects):
            continue

        text = sanitize_text(word.get("text", ""))
        if not text.strip():
            continue

        signature = (
            text,
            round(float(word.get("origin_x", word.get("left", 0)) or 0), 2),
            round(float(word.get("origin_y", float(word.get("top", 0) or 0) + float(word.get("height", 0) or 0))), 2),
            round(float(word.get("font_size", 0) or 0), 2),
        )
        if signature in seen_words:
            continue
        seen_words.add(signature)

        origin_x = float(word.get("origin_x", word.get("left", 0)) or 0)
        origin_y = float(word.get("origin_y", float(word.get("top", 0) or 0) + float(word.get("height", 0) or 0)))
        font_size = float(word.get("font_size", 12) or 12)
        color = parse_color(word.get("hex_color") if word.get("hex_color") is not None else word.get("color"))
        font_obj = build_font(
            font_lookup_doc,
            page_index + 1,
            word.get("font"),
            word.get("font_weight"),
            "italic" if bool(word.get("italic")) else "normal",
            word.get("font_xref"),
            font_cache,
        )

        block_num = word.get("block_num")
        writer_key = (color, block_num)
        writer = block_color_writers.setdefault(writer_key, fitz.TextWriter(page.rect))
        try:
            writer.append((origin_x, origin_y), text, font=font_obj, fontsize=font_size)
        except Exception:
            draw_text_absolute(page, text, origin_x, origin_y, font_obj, font_size, color)
        drawn_words += 1

    for writer_key, writer in block_color_writers.items():
        writer.write_text(page, color=writer_key[0], overlay=True)

    return drawn_words


def draw_annotation(page: fitz.Page, annotation: Dict[str, Any]) -> None:
    annotation = dict(annotation)
    annotation["skipPromotedSourceErase"] = True
    kind = str(annotation.get("type") or "").strip().lower()
    if kind == "shape":
        draw_shape(page, annotation)
    elif kind == "table":
        draw_table(page, annotation)
    elif kind == "eraser":
        draw_eraser(page, annotation)
    elif kind == "text":
        draw_text(page, annotation)
    elif kind in {"signature", "image"}:
        draw_signature(page, annotation)


def erase_mask_rects(page: fitz.Page, mask_rects: List[fitz.Rect]) -> None:
    applied = False
    for rect in mask_rects:
        if not isinstance(rect, fitz.Rect) or rect.is_empty:
            continue
        page.add_redact_annot(rect, fill=(1, 1, 1))
        applied = True
    if not applied:
        return
    try:
        page.apply_redactions(
            images=fitz.PDF_REDACT_IMAGE_NONE,
            graphics=fitz.PDF_REDACT_LINE_ART_NONE,
            text=fitz.PDF_REDACT_TEXT_REMOVE,
        )
        return
    except TypeError:
        pass
    try:
        page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE, graphics=fitz.PDF_REDACT_LINE_ART_NONE)
        return
    except TypeError:
        pass
    try:
        page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        return
    except TypeError:
        pass
    page.apply_redactions()


def erase_extraction_words_on_page(
    page: fitz.Page,
    page_data: Dict[str, Any],
    widget_rects: List[fitz.Rect],
) -> None:
    applied = False
    for word in page_data.get("words") or []:
        if not isinstance(word, dict):
            continue
        rect = word_rect(word)
        if rect is None or overlaps_widget_rects(rect, widget_rects):
            continue
        erase_rect = fitz.Rect(rect.x0 - 0.35, rect.y0 - 0.35, rect.x1 + 0.35, rect.y1 + 0.35)
        page.add_redact_annot(erase_rect, fill=None)
        applied = True

    if not applied:
        return

    try:
        page.apply_redactions(
            images=fitz.PDF_REDACT_IMAGE_NONE,
            graphics=fitz.PDF_REDACT_LINE_ART_NONE,
            text=fitz.PDF_REDACT_TEXT_REMOVE,
        )
        return
    except TypeError:
        pass
    try:
        page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE, graphics=fitz.PDF_REDACT_LINE_ART_NONE)
        return
    except TypeError:
        pass
    try:
        page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        return
    except TypeError:
        pass
    page.apply_redactions()


def rebuild_pages_with_annotations(
    clean_pdf_path: str,
    extraction_data: List[Dict[str, Any]],
    annotations: List[Dict[str, Any]],
    output_pdf_path: str,
    redraw_page_indices: List[int],
    preserve_pdf_path: str,
    deleted_promoted_source_keys: Optional[List[str]] = None,
) -> None:
    annotations = normalize_annotations_for_pdf_export(
        annotations,
        redraw_page_indices=redraw_page_indices,
    )
    preserve_doc = fitz.open(preserve_pdf_path)
    font_lookup_doc = preserve_doc
    font_cache: Dict[str, fitz.Font] = {}

    extraction_by_page = {
        int(page.get("page_number", 0)) - 1: page
        for page in extraction_data
        if isinstance(page, dict) and int(page.get("page_number", 0)) > 0
    }
    redraw_pages = {
        int(page_index)
        for page_index in redraw_page_indices
        if isinstance(page_index, int) and page_index >= 0 and page_index < preserve_doc.page_count
    }
    annotations_by_page: Dict[int, List[Dict[str, Any]]] = {}
    mask_rects_by_page: Dict[int, List[fitz.Rect]] = {}

    for annotation in annotations:
        if not isinstance(annotation, dict):
            continue
        try:
            page_index = int(annotation.get("pageIndex"))
        except Exception:
            continue
        if page_index < 0 or page_index >= preserve_doc.page_count:
            continue
        page_data = extraction_by_page.get(page_index, {})
        prepared_annotation = expand_promoted_annotation_to_block(
            annotation,
            page_data,
            preserve_doc[page_index].rect.height,
        )
        annotations_by_page.setdefault(page_index, []).append(prepared_annotation)
        if bool(prepared_annotation.get("promotedFromExtraction")):
            mask_rects_by_page.setdefault(page_index, []).extend(get_annotation_mask_rects(prepared_annotation))
            mask_rects_by_page.setdefault(page_index, []).extend(
                get_multiline_promoted_mask_rects(page_data, prepared_annotation)
            )

    for source_key in deleted_promoted_source_keys or []:
        parsed = parse_promoted_source_key(source_key)
        if not parsed:
            continue
        page_index, block_num = parsed
        if 0 <= page_index < preserve_doc.page_count:
            redraw_pages.add(page_index)
            del block_num
            page_data = extraction_by_page.get(page_index, {})
            mask_rects_by_page.setdefault(page_index, []).extend(
                get_deleted_source_mask_rects(page_data, source_key)
            )

    total_words_drawn = 0
    total_annotations_drawn = 0

    output_doc = fitz.open()
    output_doc.insert_pdf(
        preserve_doc,
        from_page=0,
        to_page=preserve_doc.page_count - 1,
        widgets=1,
    )

    for page_index in sorted(redraw_pages):
        page_data = extraction_by_page.get(page_index, {})
        mask_rects = mask_rects_by_page.get(page_index, [])
        output_page = output_doc[page_index]
        widget_rects = get_widget_rects(output_page)
        erase_extraction_words_on_page(output_page, page_data, widget_rects)
        total_words_drawn += draw_extraction_words_for_page(
            output_doc,
            font_lookup_doc,
            page_index,
            page_data,
            mask_rects,
            widget_rects,
            font_cache,
        )

        for annotation in annotations_by_page.get(page_index, []):
            draw_annotation(output_page, annotation)
            total_annotations_drawn += 1

    total_page_count = preserve_doc.page_count
    temp_path = output_pdf_path + ".redraw.tmp"
    try:
        output_doc.save(
            temp_path,
            incremental=False,
            encryption=fitz.PDF_ENCRYPT_KEEP,
            garbage=4,
            deflate=True,
            clean=True,
        )
    except TypeError:
        output_doc.save(
            temp_path,
            incremental=False,
            encryption=fitz.PDF_ENCRYPT_KEEP,
            garbage=4,
            deflate=True,
        )
    finally:
        output_doc.close()
        preserve_doc.close()

    os.replace(temp_path, output_pdf_path)
    print(
        f"Rebuilt {len(redraw_pages)} touched page(s); "
        f"preserved {max(0, total_page_count - len(redraw_pages))} untouched page(s); "
        f"redrew {total_words_drawn} extracted words and {total_annotations_drawn} annotation(s)."
    )


def main() -> int:
    if len(sys.argv) not in {7, 8}:
        print(
            "Usage: apply_annotations_redraw_pages.py "
            "<clean_pdf_path> <extraction_json_or_@file> <annotations_json_or_@file> "
            "<output_pdf_path> <redraw_pages_json_or_@file> <preserve_pdf_path> "
            "[deleted_promoted_source_keys_json_or_@file]"
        )
        return 1

    clean_pdf_path = sys.argv[1]
    extraction_arg = sys.argv[2]
    annotations_arg = sys.argv[3]
    output_pdf_path = sys.argv[4]
    redraw_pages_arg = sys.argv[5]
    preserve_pdf_path = sys.argv[6]
    deleted_keys_arg = sys.argv[7] if len(sys.argv) >= 8 else None

    if not os.path.exists(clean_pdf_path):
        print(f"Clean PDF not found: {clean_pdf_path}")
        return 1
    if not os.path.exists(preserve_pdf_path):
        print(f"Preserve-source PDF not found: {preserve_pdf_path}")
        return 1

    extraction_data = load_json_arg(extraction_arg)
    annotations = load_json_arg(annotations_arg)
    redraw_page_indices = load_json_arg(redraw_pages_arg)
    deleted_promoted_source_keys = load_json_arg(deleted_keys_arg) if deleted_keys_arg else []

    if not isinstance(extraction_data, list):
        print("Extraction payload must be a JSON array.")
        return 1
    if not isinstance(annotations, list):
        print("Annotations payload must be a JSON array.")
        return 1
    if not isinstance(redraw_page_indices, list):
        print("Redraw pages payload must be a JSON array.")
        return 1
    if not isinstance(deleted_promoted_source_keys, list):
        print("Deleted promoted source keys payload must be a JSON array.")
        return 1

    rebuild_pages_with_annotations(
        clean_pdf_path,
        extraction_data,
        annotations,
        output_pdf_path,
        [int(value) for value in redraw_page_indices],
        preserve_pdf_path,
        [str(value) for value in deleted_promoted_source_keys],
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
