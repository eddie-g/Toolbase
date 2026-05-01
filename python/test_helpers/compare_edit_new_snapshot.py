#!/usr/bin/env python3
"""
Render the /edit-new loaded visual state and compare it with the original PDF.

The loaded state is modeled as:
  loaded base PDF (clean PDF when available, otherwise the document file)
  + all edit-new annotations written by new_annotation_writer.py

The script writes per-page original/snapshot/diff PNGs and returns JSON with
artifact filenames plus mismatch metrics.
"""

import argparse
import copy
import importlib.util
import json
import os
import pathlib
import shutil
import sys
import tempfile

import fitz
import numpy as np

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
PDF_EDITOR = SCRIPT_DIR.parent / "pdf-editor"
NEW_WRITER = PDF_EDITOR / "new_annotation_writer.py"
CONTRACT_DIR = str(PDF_EDITOR)


def _load_writer():
    if CONTRACT_DIR not in sys.path:
        sys.path.insert(0, CONTRACT_DIR)
    spec = importlib.util.spec_from_file_location("new_annotation_writer", NEW_WRITER)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def _render_page_arr(doc: fitz.Document, page_index: int, dpi: int) -> np.ndarray:
    page = doc[page_index]
    matrix = fitz.Matrix(dpi / 72.0, dpi / 72.0)
    pix = page.get_pixmap(matrix=matrix, colorspace=fitz.csRGB, alpha=False)
    arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3)
    return arr.copy()


def _arr_to_png(arr: np.ndarray) -> bytes:
    h, w = arr.shape[:2]
    pix = fitz.Pixmap(fitz.csRGB, w, h, arr.tobytes(), False)
    return pix.tobytes("png")


def _fit_to_same_canvas(arr_a: np.ndarray, arr_b: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    ha, wa = arr_a.shape[:2]
    hb, wb = arr_b.shape[:2]
    h, w = max(ha, hb), max(wa, wb)
    if arr_a.shape[:2] != (h, w):
        canvas = np.full((h, w, 3), 255, dtype=np.uint8)
        canvas[:ha, :wa] = arr_a
        arr_a = canvas
    if arr_b.shape[:2] != (h, w):
        canvas = np.full((h, w, 3), 255, dtype=np.uint8)
        canvas[:hb, :wb] = arr_b
        arr_b = canvas
    return arr_a, arr_b


def _bbox_from_mask(mask: np.ndarray):
    ys, xs = np.where(mask)
    if xs.size == 0 or ys.size == 0:
        return None
    return [int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1]


def _compare_arrays(original: np.ndarray, snapshot: np.ndarray, threshold: int):
    original, snapshot = _fit_to_same_canvas(original, snapshot)
    diff_raw = np.abs(original.astype(np.int16) - snapshot.astype(np.int16))
    max_per_pixel = diff_raw.max(axis=2)
    diff_mask = max_per_pixel > threshold

    total = int(diff_mask.size)
    mismatched = int(diff_mask.sum())
    diff_pct = (mismatched / total * 100.0) if total else 0.0
    max_diff = int(max_per_pixel.max()) if total else 0

    original_luma = (
        0.2126 * original[:, :, 0]
        + 0.7152 * original[:, :, 1]
        + 0.0722 * original[:, :, 2]
    )
    snapshot_luma = (
        0.2126 * snapshot[:, :, 0]
        + 0.7152 * snapshot[:, :, 1]
        + 0.0722 * snapshot[:, :, 2]
    )
    missing_mask = diff_mask & (original_luma + threshold < snapshot_luma)
    added_mask = diff_mask & (snapshot_luma + threshold < original_luma)
    changed_mask = diff_mask & ~(missing_mask | added_mask)

    diff_vis = snapshot.copy()
    dimmed = (diff_vis.astype(np.float32) * 0.55 + 255.0 * 0.45).astype(np.uint8)
    diff_vis = dimmed
    diff_vis[missing_mask] = [220, 38, 38]
    diff_vis[added_mask] = [37, 99, 235]
    diff_vis[changed_mask] = [245, 158, 11]

    return {
        "diff_pct": round(diff_pct, 4),
        "max_diff": max_diff,
        "pixels_checked": total,
        "mismatched_pixels": mismatched,
        "missing_pixels": int(missing_mask.sum()),
        "added_pixels": int(added_mask.sum()),
        "changed_pixels": int(changed_mask.sum()),
        "diff_bbox_px": _bbox_from_mask(diff_mask),
        "diff_image": diff_vis,
    }


def _write_png(path: pathlib.Path, arr: np.ndarray):
    path.write_bytes(_arr_to_png(arr))


def _safe_float(value, default: float = 0.0) -> float:
    try:
        return float(value)
    except Exception:
        return default


def _annotation_page_index(annotation: dict) -> int:
    return int(_safe_float(annotation.get("pageIndex", annotation.get("page_index", 0)), 0.0))


def _annotation_id(annotation: dict, fallback_index: int) -> str:
    for key in ("id", "annotation_id", "promotedSourceKey", "db_id"):
        value = annotation.get(key)
        if value is not None and str(value).strip():
            return str(value).strip()
    return f"annotation_{fallback_index + 1}"


def _annotation_text(annotation: dict, limit: int = 120) -> str:
    value = annotation.get("text")
    if value is None:
        value = annotation.get("originalText")
    text = " ".join(str(value or "").split())
    if len(text) > limit:
        return text[: limit - 1].rstrip() + "…"
    return text


def _merge_bboxes(boxes: list) -> list[float] | None:
    merged = None
    for box in boxes:
        if not isinstance(box, (list, tuple)) or len(box) < 4:
            continue
        x0 = _safe_float(box[0])
        y0 = _safe_float(box[1])
        x1 = _safe_float(box[2])
        y1 = _safe_float(box[3])
        if x1 <= x0 or y1 <= y0:
            continue
        if merged is None:
            merged = [x0, y0, x1, y1]
        else:
            merged[0] = min(merged[0], x0)
            merged[1] = min(merged[1], y0)
            merged[2] = max(merged[2], x1)
            merged[3] = max(merged[3], y1)
    return merged


def _annotation_pdf_bbox(annotation: dict, page_height: float) -> list[float] | None:
    line_boxes = annotation.get("sourceLineBBoxes") or []
    if isinstance(line_boxes, list):
        merged = _merge_bboxes(line_boxes)
        if merged:
            return merged

    spans = annotation.get("sourceSpans") or []
    if isinstance(spans, list):
        span_boxes = [span.get("bbox") for span in spans if isinstance(span, dict)]
        merged = _merge_bboxes(span_boxes)
        if merged:
            return merged

    if annotation.get("sourceBlockLeft") is not None and annotation.get("sourceBlockTop") is not None:
        x0 = _safe_float(annotation.get("sourceBlockLeft"))
        y0 = _safe_float(annotation.get("sourceBlockTop"))
        width = _safe_float(annotation.get("sourceBlockWidth"), _safe_float(annotation.get("pdfWidth")))
        height = _safe_float(annotation.get("sourceBlockHeight"), _safe_float(annotation.get("pdfHeight")))
        if width > 0 and height > 0:
            return [x0, y0, x0 + width, y0 + height]

    pdf_x = _safe_float(annotation.get("pdfX"))
    pdf_y = _safe_float(annotation.get("pdfY"))
    pdf_w = _safe_float(annotation.get("pdfWidth"))
    pdf_h = _safe_float(annotation.get("pdfHeight"))
    if pdf_w > 0 and pdf_h > 0:
        y0 = page_height - (pdf_y + pdf_h)
        return [pdf_x, y0, pdf_x + pdf_w, y0 + pdf_h]

    return None


def _annotation_text_matches_original(annotation: dict) -> bool:
    text = str(annotation.get("text") or "")
    original = str(annotation.get("originalText") or "")
    return bool(original) and text == original


def _source_box_for_annotation(annotation: dict, page_height: float) -> list[float] | None:
    try:
        source_left = float(annotation.get("sourceBlockLeft"))
        source_top = float(annotation.get("sourceBlockTop"))
        source_width = float(annotation.get("sourceBlockWidth"))
        source_height = float(annotation.get("sourceBlockHeight"))
    except Exception:
        source_left = source_top = source_width = source_height = None

    if (
        source_left is not None
        and source_top is not None
        and source_width is not None
        and source_height is not None
        and source_width > 0
        and source_height > 0
    ):
        return [source_left, source_top, source_left + source_width, source_top + source_height]

    return _annotation_pdf_bbox(annotation, page_height)


def _normalize_unedited_promoted_annotations_to_source_boxes(
    annotations: list,
    original_doc: fitz.Document,
) -> list:
    normalized = []
    for annotation in annotations:
        if not isinstance(annotation, dict):
            normalized.append(annotation)
            continue

        ann = copy.deepcopy(annotation)
        page_index = _annotation_page_index(ann)
        if page_index < 0 or page_index >= original_doc.page_count:
            normalized.append(ann)
            continue

        if not bool(ann.get("promotedFromExtraction")):
            normalized.append(ann)
            continue
        if bool(ann.get("promotedDirty")) or bool(ann.get("promotedReflowEnabled")) or bool(ann.get("userAuthored")):
            normalized.append(ann)
            continue
        if not _annotation_text_matches_original(ann):
            normalized.append(ann)
            continue

        page_height = float(original_doc[page_index].rect.height)
        source_box = _source_box_for_annotation(ann, page_height)
        if not source_box:
            normalized.append(ann)
            continue

        x0, y0, x1, y1 = source_box
        width = max(0.0, x1 - x0)
        height = max(0.0, y1 - y0)
        if width <= 0 or height <= 0:
            normalized.append(ann)
            continue

        ann["pdfX"] = x0
        ann["pdfY"] = page_height - (y0 + height)
        ann["pdfWidth"] = width
        ann["pdfHeight"] = height
        ann["sourceBlockLeft"] = x0
        ann["sourceBlockTop"] = y0
        ann["sourceBlockWidth"] = width
        ann["sourceBlockHeight"] = height
        ann["sourcePageHeight"] = page_height
        if isinstance(ann.get("annotation_data"), dict):
            ann["annotation_data"]["pdfX"] = ann["pdfX"]
            ann["annotation_data"]["pdfY"] = ann["pdfY"]
            ann["annotation_data"]["pdfWidth"] = ann["pdfWidth"]
            ann["annotation_data"]["pdfHeight"] = ann["pdfHeight"]

        normalized.append(ann)

    return normalized


def _crop(arr: np.ndarray, x0: int, y0: int, x1: int, y1: int) -> np.ndarray:
    h, w = arr.shape[:2]
    x0 = max(0, min(w, x0))
    x1 = max(0, min(w, x1))
    y0 = max(0, min(h, y0))
    y1 = max(0, min(h, y1))
    if x1 <= x0 or y1 <= y0:
        return np.zeros((0, 0, 3), dtype=np.uint8)
    return arr[y0:y1, x0:x1]


def _compact(value: str) -> str:
    return " ".join(str(value or "").split()).lower()


_DUPLICATE_TOKEN_STOPWORDS = {
    "a",
    "an",
    "and",
    "are",
    "as",
    "at",
    "be",
    "by",
    "for",
    "from",
    "has",
    "if",
    "in",
    "is",
    "it",
    "its",
    "of",
    "on",
    "or",
    "the",
    "this",
    "to",
    "whose",
    "with",
}


def _distinct_duplicate_tokens(text: str) -> set[str]:
    tokens = set()
    for raw in text.replace("/", " ").split():
        token = "".join(ch for ch in raw.lower() if ch.isalnum())
        if len(token) < 3 or token in _DUPLICATE_TOKEN_STOPWORDS:
            continue
        tokens.add(token)
    return tokens


def _rect_overlap_ratio(rect_a: list[float], rect_b: list[float]) -> float:
    ax0, ay0, ax1, ay1 = rect_a
    bx0, by0, bx1, by1 = rect_b
    overlap_w = max(0.0, min(ax1, bx1) - max(ax0, bx0))
    overlap_h = max(0.0, min(ay1, by1) - max(ay0, by0))
    overlap = overlap_w * overlap_h
    if overlap <= 0:
        return 0.0
    area_a = max(1.0, (ax1 - ax0) * (ay1 - ay0))
    area_b = max(1.0, (bx1 - bx0) * (by1 - by0))
    return overlap / min(area_a, area_b)


def _rect_relationship(rect_a: list[float], rect_b: list[float]) -> dict:
    ax0, ay0, ax1, ay1 = rect_a
    bx0, by0, bx1, by1 = rect_b
    overlap_w = max(0.0, min(ax1, bx1) - max(ax0, bx0))
    overlap_h = max(0.0, min(ay1, by1) - max(ay0, by0))
    min_w = max(1.0, min(max(0.0, ax1 - ax0), max(0.0, bx1 - bx0)))
    min_h = max(1.0, min(max(0.0, ay1 - ay0), max(0.0, by1 - by0)))
    vertical_gap = max(0.0, max(by0 - ay1, ay0 - by1))
    horizontal_gap = max(0.0, max(bx0 - ax1, ax0 - bx1))

    return {
        "overlap_ratio": _rect_overlap_ratio(rect_a, rect_b),
        "horizontal_overlap_ratio": overlap_w / min_w,
        "vertical_overlap_ratio": overlap_h / min_h,
        "vertical_gap": vertical_gap,
        "horizontal_gap": horizontal_gap,
    }


def _find_overlapping_duplicate_annotations(
    annotations: list,
    current_annotation: dict,
    current_index: int,
    current_bbox: list[float],
    page_index: int,
    page_height: float,
) -> list[dict]:
    current_text = _compact(_annotation_text(current_annotation, limit=500))
    if not current_text:
        return []

    duplicates = []
    for other_index, other in enumerate(annotations):
        if other_index == current_index or not isinstance(other, dict) or _annotation_page_index(other) != page_index:
            continue

        other_text = _compact(_annotation_text(other, limit=500))
        if not other_text:
            continue

        text_contains = len(other_text) >= 12 and (other_text in current_text or current_text in other_text)
        if not text_contains:
            current_tokens = _distinct_duplicate_tokens(current_text)
            other_tokens = _distinct_duplicate_tokens(other_text)
            common = current_tokens & other_tokens
            text_contains = len(common) >= 4 and (len(common) / max(1, min(len(current_tokens), len(other_tokens)))) >= 0.75
        if not text_contains:
            continue

        other_bbox = _annotation_pdf_bbox(other, page_height)
        if not other_bbox:
            continue

        relationship = _rect_relationship(current_bbox, other_bbox)
        near_same_band = (
            relationship["horizontal_overlap_ratio"] >= 0.35
            and relationship["vertical_gap"] <= 12.0
        )
        nearby_continuation = (
            relationship["horizontal_gap"] <= 18.0
            and relationship["vertical_gap"] <= 12.0
            and relationship["vertical_overlap_ratio"] >= 0.30
        )
        if relationship["overlap_ratio"] < 0.05 and not near_same_band and not nearby_continuation:
            continue

        duplicates.append({
            "annotation_id": _annotation_id(other, other_index),
            "text": _annotation_text(other),
            "overlap_ratio": round(relationship["overlap_ratio"], 4),
            "horizontal_overlap_ratio": round(relationship["horizontal_overlap_ratio"], 4),
            "vertical_gap": round(relationship["vertical_gap"], 2),
            "pdf_bbox": [round(float(v), 2) for v in other_bbox],
        })

    duplicates.sort(key=lambda item: item["overlap_ratio"], reverse=True)
    return duplicates[:5]


def _luma(arr: np.ndarray) -> np.ndarray:
    if arr.size == 0:
        return np.zeros(arr.shape[:2], dtype=np.float32)
    return (
        0.2126 * arr[:, :, 0].astype(np.float32)
        + 0.7152 * arr[:, :, 1].astype(np.float32)
        + 0.0722 * arr[:, :, 2].astype(np.float32)
    )


def _mask_centroid(mask: np.ndarray) -> tuple[float, float] | None:
    ys, xs = np.where(mask)
    if xs.size == 0 or ys.size == 0:
        return None
    return float(xs.mean()), float(ys.mean())


def _diff_direction_masks(original_crop: np.ndarray, snapshot_crop: np.ndarray, threshold: int):
    original_crop, snapshot_crop = _fit_to_same_canvas(original_crop, snapshot_crop)
    original_luma = _luma(original_crop)
    snapshot_luma = _luma(snapshot_crop)
    diff_raw = np.abs(original_crop.astype(np.int16) - snapshot_crop.astype(np.int16))
    max_per_pixel = diff_raw.max(axis=2)
    diff_mask = max_per_pixel > threshold
    missing_mask = diff_mask & (original_luma + threshold < snapshot_luma)
    added_mask = diff_mask & (snapshot_luma + threshold < original_luma)
    return missing_mask, added_mask


def _annotation_issue_findings(
    annotations: list,
    original_arr: np.ndarray,
    snapshot_arr: np.ndarray,
    page_index: int,
    page_rect: fitz.Rect,
    dpi: int,
    threshold: int,
    max_findings: int = 8,
) -> list[dict]:
    scale = dpi / 72.0
    page_h_px, page_w_px = original_arr.shape[:2]
    findings = []

    for ann_index, annotation in enumerate(annotations):
        if not isinstance(annotation, dict) or _annotation_page_index(annotation) != page_index:
            continue

        bbox = _annotation_pdf_bbox(annotation, float(page_rect.height))
        if not bbox:
            continue

        x0_pt, y0_pt, x1_pt, y1_pt = bbox
        pad_px = max(20, int(round(12 * scale)))
        x0 = int(np.floor(x0_pt * scale)) - pad_px
        y0 = int(np.floor(y0_pt * scale)) - pad_px
        x1 = int(np.ceil(x1_pt * scale)) + pad_px
        y1 = int(np.ceil(y1_pt * scale)) + pad_px

        original_crop = _crop(original_arr, x0, y0, x1, y1)
        snapshot_crop = _crop(snapshot_arr, x0, y0, x1, y1)
        if original_crop.size == 0 or snapshot_crop.size == 0:
            continue

        comparison = _compare_arrays(original_crop, snapshot_crop, threshold)
        if comparison["mismatched_pixels"] < 10 or comparison["diff_pct"] < 1.0:
            continue

        missing_mask, added_mask = _diff_direction_masks(original_crop, snapshot_crop, threshold)
        original_dark = _luma(original_crop) < 245
        snapshot_dark = _luma(snapshot_crop) < 245
        original_dark_count = int(original_dark.sum())
        snapshot_dark_count = int(snapshot_dark.sum())
        original_centroid = _mask_centroid(missing_mask) or _mask_centroid(original_dark)
        snapshot_centroid = _mask_centroid(added_mask) or _mask_centroid(snapshot_dark)

        dx_px = 0.0
        dy_px = 0.0
        if original_centroid and snapshot_centroid:
            dx_px = snapshot_centroid[0] - original_centroid[0]
            dy_px = snapshot_centroid[1] - original_centroid[1]

        dx_pct = (dx_px / page_w_px * 100.0) if page_w_px else 0.0
        dy_pct = (dy_px / page_h_px * 100.0) if page_h_px else 0.0
        abs_offset_px = (dx_px ** 2 + dy_px ** 2) ** 0.5

        missing = int(comparison["missing_pixels"])
        added = int(comparison["added_pixels"])
        changed = int(comparison["changed_pixels"])
        one_directional_noise = (
            (missing <= max(2, int(added * 0.02)) and added > 0)
            or (added <= max(2, int(missing * 0.02)) and missing > 0)
        )
        dark_count_ratio = (
            snapshot_dark_count / max(1, original_dark_count)
            if original_dark_count > 0
            else 1.0
        )
        if (
            one_directional_noise
            and changed == 0
            and comparison["diff_pct"] < 8.0
            and 0.85 <= dark_count_ratio <= 1.18
        ):
            continue
        duplicate_annotations = _find_overlapping_duplicate_annotations(
            annotations,
            annotation,
            ann_index,
            bbox,
            page_index,
            float(page_rect.height),
        )
        suspected = "visual mismatch inside the annotation bounds"
        if duplicate_annotations:
            duplicate_ids = ", ".join(item["annotation_id"] for item in duplicate_annotations)
            suspected = f"annotation text may be duplicated or overlapping with annotation_id={duplicate_ids}"
        elif abs_offset_px >= max(2.0, 1.0 * scale) and missing > 0 and added > 0:
            suspected = "annotation content appears offset from its original position"
            if abs(dy_px) >= max(2.0, abs(dx_px) * 1.25):
                suspected = "annotation baseline or top coordinate appears vertically shifted from the original"
            elif abs(dx_px) >= max(2.0, abs(dy_px) * 1.25):
                suspected = "annotation left/x coordinate or text width appears horizontally shifted from the original"
        if not duplicate_annotations and snapshot_dark_count > max(40, original_dark_count * 1.25) and added > missing * 1.2:
            suspected = "annotation text may be duplicated or overdrawn in the edit-new snapshot"
        elif not duplicate_annotations and original_dark_count > max(40, snapshot_dark_count * 1.25) and missing > added * 1.2:
            suspected = "annotation content appears missing or too light in the edit-new snapshot"

        text = _annotation_text(annotation)
        ann_id = _annotation_id(annotation, ann_index)
        horizontal = "right" if dx_px > 0 else "left"
        vertical = "down from the top" if dy_px > 0 else "up toward the top"
        text_fragment = f' the text "{text}"' if text else ""
        offset_phrase = (
            f"offset by {abs(dx_px):.1f}px {horizontal} ({abs(dx_pct):.3f}% of page width) "
            f"and {abs(dy_px):.1f}px {vertical} ({abs(dy_pct):.3f}% of page height)"
        )
        description = (
            f"On page {page_index + 1}, annotation_id={ann_id}{text_fragment} is {offset_phrase}; "
            f"suspected issue: {suspected}."
        )

        findings.append({
            "page": page_index + 1,
            "page_index": page_index,
            "annotation_id": ann_id,
            "annotation_type": str(annotation.get("type") or "text"),
            "text": text,
            "pdf_bbox": [round(float(v), 2) for v in bbox],
            "pixel_diff_pct": comparison["diff_pct"],
            "mismatched_pixels": comparison["mismatched_pixels"],
            "missing_pixels": missing,
            "added_pixels": added,
            "changed_pixels": changed,
            "offset_px": {"x": round(dx_px, 2), "y": round(dy_px, 2)},
            "offset_pct": {"x": round(dx_pct, 4), "y": round(dy_pct, 4)},
            "suspected_issue": suspected,
            "overlapping_duplicate_annotations": duplicate_annotations,
            "description": description,
        })

    findings.sort(key=lambda item: (item["pixel_diff_pct"], item["mismatched_pixels"]), reverse=True)
    return findings[:max_findings]


def compare(
    original_pdf: str,
    loaded_base_pdf: str,
    annotations: list,
    output_dir: str,
    artifact_prefix: str,
    dpi: int,
    threshold: int,
    max_pages: int | None,
):
    os.makedirs(output_dir, exist_ok=True)
    writer = _load_writer()

    fd, snapshot_pdf = tempfile.mkstemp(suffix=".pdf")
    os.close(fd)
    shutil.copy2(loaded_base_pdf, snapshot_pdf)

    try:
        original_doc = fitz.open(original_pdf)
        try:
            annotations = _normalize_unedited_promoted_annotations_to_source_boxes(annotations, original_doc)
            if annotations:
                writer.apply_annotations(snapshot_pdf, annotations)

            snapshot_doc = fitz.open(snapshot_pdf)
            try:
                original_pages = int(original_doc.page_count)
                snapshot_pages = int(snapshot_doc.page_count)
                compare_pages = min(original_pages, snapshot_pages)
                if max_pages is not None:
                    compare_pages = min(compare_pages, max_pages)

                pages = []
                worst_page = None
                total_pixels = 0
                total_mismatch = 0
                total_missing = 0
                total_added = 0
                total_changed = 0
                ai_findings = []

                for page_index in range(compare_pages):
                    original_arr = _render_page_arr(original_doc, page_index, dpi)
                    snapshot_arr = _render_page_arr(snapshot_doc, page_index, dpi)
                    comparison = _compare_arrays(original_arr, snapshot_arr, threshold)
                    page_findings = _annotation_issue_findings(
                        annotations=annotations,
                        original_arr=original_arr,
                        snapshot_arr=snapshot_arr,
                        page_index=page_index,
                        page_rect=original_doc[page_index].rect,
                        dpi=dpi,
                        threshold=threshold,
                    )

                    page_no = page_index + 1
                    stem = f"{artifact_prefix}_page_{page_no:03d}"
                    original_name = f"{stem}_original.png"
                    snapshot_name = f"{stem}_edit_new_snapshot.png"
                    diff_name = f"{stem}_diff.png"

                    _write_png(pathlib.Path(output_dir, original_name), original_arr)
                    _write_png(pathlib.Path(output_dir, snapshot_name), snapshot_arr)
                    _write_png(pathlib.Path(output_dir, diff_name), comparison["diff_image"])

                    page = {
                        "page": page_no,
                        "page_index": page_index,
                        "pixel_diff_pct": comparison["diff_pct"],
                        "max_pixel_diff": comparison["max_diff"],
                        "pixels_checked": comparison["pixels_checked"],
                        "mismatched_pixels": comparison["mismatched_pixels"],
                        "missing_pixels": comparison["missing_pixels"],
                        "added_pixels": comparison["added_pixels"],
                        "changed_pixels": comparison["changed_pixels"],
                        "diff_bbox_px": comparison["diff_bbox_px"],
                        "original_artifact": original_name,
                        "snapshot_artifact": snapshot_name,
                        "diff_artifact": diff_name,
                        "ai_findings": page_findings,
                    }
                    pages.append(page)
                    ai_findings.extend(page_findings)

                    total_pixels += comparison["pixels_checked"]
                    total_mismatch += comparison["mismatched_pixels"]
                    total_missing += comparison["missing_pixels"]
                    total_added += comparison["added_pixels"]
                    total_changed += comparison["changed_pixels"]

                    if worst_page is None or page["pixel_diff_pct"] > worst_page["pixel_diff_pct"]:
                        worst_page = page

                overall_diff_pct = (total_mismatch / total_pixels * 100.0) if total_pixels else 0.0
                return {
                    "success": True,
                    "dpi": dpi,
                    "threshold": threshold,
                    "page_count_original": original_pages,
                    "page_count_snapshot": snapshot_pages,
                    "page_count_compared": compare_pages,
                    "page_count_mismatch": original_pages != snapshot_pages,
                    "annotation_count": len(annotations),
                    "overall": {
                        "pixel_diff_pct": round(overall_diff_pct, 4),
                        "mismatched_pixels": total_mismatch,
                        "pixels_checked": total_pixels,
                        "missing_pixels": total_missing,
                        "added_pixels": total_added,
                        "changed_pixels": total_changed,
                        "worst_page": worst_page["page"] if worst_page else None,
                        "worst_page_diff_pct": worst_page["pixel_diff_pct"] if worst_page else 0.0,
                    },
                    "ai_findings": ai_findings,
                    "pages": pages,
                }
            finally:
                snapshot_doc.close()
        finally:
            original_doc.close()
    except Exception as exc:
        import traceback
        return {"success": False, "error": str(exc), "traceback": traceback.format_exc()}
    finally:
        try:
            os.unlink(snapshot_pdf)
        except OSError:
            pass


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--original-pdf", required=True)
    parser.add_argument("--loaded-base-pdf", required=True)
    parser.add_argument("--annotations-json", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--artifact-prefix", required=True)
    parser.add_argument("--dpi", type=int, default=144)
    parser.add_argument("--threshold", type=int, default=10)
    parser.add_argument("--max-pages", type=int, default=0)
    args = parser.parse_args()

    for path in (args.original_pdf, args.loaded_base_pdf, args.annotations_json):
        if not os.path.exists(path):
            sys.stdout.write(json.dumps({"success": False, "error": f"File not found: {path}"}))
            return 1

    with open(args.annotations_json, "r", encoding="utf-8") as handle:
        annotations = json.load(handle)
    if not isinstance(annotations, list):
        sys.stdout.write(json.dumps({"success": False, "error": "annotations JSON must be an array"}))
        return 1

    result = compare(
        original_pdf=args.original_pdf,
        loaded_base_pdf=args.loaded_base_pdf,
        annotations=annotations,
        output_dir=args.output_dir,
        artifact_prefix=args.artifact_prefix,
        dpi=max(72, min(int(args.dpi), 240)),
        threshold=max(0, min(int(args.threshold), 255)),
        max_pages=args.max_pages if args.max_pages and args.max_pages > 0 else None,
    )
    sys.stdout.write(json.dumps(result, ensure_ascii=False))
    return 0 if result.get("success") else 1


if __name__ == "__main__":
    raise SystemExit(main())
