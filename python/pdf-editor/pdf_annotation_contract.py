#!/usr/bin/env python3

import copy
import html
import math
import re
from typing import Any, Dict, Iterable, Optional, Sequence

from apply_pdf_edits_simple import normalize_smart_quotes


_FORBIDDEN_TEXT_RE = re.compile(r"[\u00A0\uFFFD\u200B\u200C\u200D\u2060\uFEFF]")
_CONTROL_TEXT_RE = re.compile(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]")
_PRIVATE_USE_RE = re.compile(r"[\uE000-\uF8FF]")
_NBSP_ENTITY_RE = re.compile(r"&nbsp;|&#160;|&#xA0;|&#xa0;", re.IGNORECASE)
_NON_TEXT_KINDS = {"shape", "table", "eraser", "signature", "image"}


def _boolish(value: Any) -> bool:
    if value is True or value == 1:
        return True
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def sanitize_pdf_text(text: Any) -> str:
    if text is None:
        return ""
    value = normalize_smart_quotes(html.unescape(str(text)))
    value = value.replace("\u00A0", " ").replace("\uFFFD", "")
    value = _FORBIDDEN_TEXT_RE.sub(lambda match: " " if match.group(0) == "\u00A0" else "", value)
    value = _PRIVATE_USE_RE.sub("", value)
    value = _CONTROL_TEXT_RE.sub("", value)
    return value


def sanitize_rich_text_html(html_value: Any) -> str:
    if html_value is None:
        return ""
    value = str(html_value)
    value = _NBSP_ENTITY_RE.sub(" ", value)
    value = value.replace("\u00A0", " ").replace("\uFFFD", "")
    value = _PRIVATE_USE_RE.sub("", value)
    value = _CONTROL_TEXT_RE.sub("", value)
    value = re.sub(r"[\u200B\u200C\u200D\u2060\uFEFF]", "", value)
    return value


def is_text_annotation(annotation: Dict[str, Any]) -> bool:
    if not isinstance(annotation, dict):
        return False
    kind = str(annotation.get("type") or "").strip().lower()
    if kind in _NON_TEXT_KINDS:
        return False
    return kind in {"", "text"} or any(
        key in annotation
        for key in ("text", "richTextHtml", "sourceTextLines", "sourceSpans", "promotedFromExtraction")
    )


def normalize_pdfjs_compare_text(value: Any) -> str:
    return re.sub(r"\s+", " ", sanitize_pdf_text(value or "")).strip()


def is_pdfjs_source_backed_text_annotation(annotation: Dict[str, Any]) -> bool:
    if not is_text_annotation(annotation):
        return False
    if _boolish(annotation.get("promotedFromExtraction")):
        return False
    return (
        _boolish(annotation.get("savedTextOverlay"))
        or _boolish(annotation.get("pdfjsDeleted"))
        or annotation.get("pdfjsSourceX") is not None
        or annotation.get("pdfjsSourceY") is not None
        or annotation.get("pdfjsAnchorUid") is not None
    )


def pdfjs_source_edit_requires_redaction(annotation: Dict[str, Any]) -> bool:
    if not is_pdfjs_source_backed_text_annotation(annotation):
        return False
    if _boolish(annotation.get("skipPdfjsSourceMask")):
        return False
    if _boolish(annotation.get("pdfjsDeleted")):
        return True
    if _boolish(annotation.get("movedTextOverlay")):
        return True
    source_text = normalize_pdfjs_compare_text(annotation.get("pdfjsSourceText") or annotation.get("originalText") or "")
    current_text = normalize_pdfjs_compare_text(annotation.get("text") or "")
    return bool(source_text and current_text and source_text != current_text)


def pdfjs_source_edit_replacement_text(annotation: Dict[str, Any]) -> str:
    if not is_pdfjs_source_backed_text_annotation(annotation):
        return sanitize_pdf_text(annotation.get("text") or "")
    if _boolish(annotation.get("pdfjsDeleted")):
        return ""
    return sanitize_pdf_text(annotation.get("text") if "text" in annotation else "")


def _finite_float(value: Any) -> Optional[float]:
    try:
        parsed = float(value)
    except Exception:
        return None
    return parsed if math.isfinite(parsed) else None


def pdfjs_source_edit_source_rect(annotation: Dict[str, Any]) -> Optional[Dict[str, float]]:
    x = _finite_float(annotation.get("pdfjsSourceX"))
    y = _finite_float(annotation.get("pdfjsSourceY"))
    width = _finite_float(annotation.get("pdfjsSourceW"))
    height = _finite_float(annotation.get("pdfjsSourceH"))
    if x is None or y is None or width is None or height is None:
        return None
    if width <= 0 or height <= 0:
        return None
    return {"x": x, "y": y, "w": width, "h": height}


def pdfjs_source_edit_transform_scale_x(annotation: Dict[str, Any]) -> float:
    if (
        _boolish(annotation.get("userForcedRichText"))
        or str(annotation.get("pdfjsEditorMode") or "").strip().lower() == "rich"
        or str(annotation.get("richTextPromotionReason") or "").strip().lower() == "manual-resize"
    ):
        return 1.0

    # The captured pdfjsSourceTransformScaleX is the horizontal stretch pdf.js
    # applies to its transparent sans-serif text layer so the spans overlay
    # the *visible* PDF glyphs at their original position. Once the user has
    # moved the overlay box, the rendered text is no longer trying to align
    # with the original PDF stream — so applying the captured stretch in the
    # exporter just blows the text past its bbox (and the page edge). The
    # editor masks this by clamping fitScaleX to box width in
    # applySourceFidelityTextFit; the PyMuPDF exporter has no such clamp.
    if _boolish(annotation.get("movedTextOverlay")):
        return 1.0

    raw_scale = annotation.get("pdfjsSourceTransformScaleX")
    if raw_scale in (None, ""):
        raw_transform = str(annotation.get("pdfjsSourceTransform") or "").strip()
        match = re.match(r"matrix\(([^,]+),", raw_transform)
        raw_scale = match.group(1) if match else None
    scale_x = _finite_float(raw_scale) or 1.0
    if scale_x <= 0:
        return 1.0
    scale_x = max(0.2, min(4.0, scale_x))
    return 1.0 if abs(scale_x - 1.0) <= 0.001 else scale_x


def pdfjs_source_edit_export_metrics(annotation: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "sourceRect": pdfjs_source_edit_source_rect(annotation),
        "sourceText": sanitize_pdf_text(annotation.get("pdfjsSourceText") or annotation.get("originalText") or ""),
        "replacementText": pdfjs_source_edit_replacement_text(annotation),
        "transformScaleX": pdfjs_source_edit_transform_scale_x(annotation),
        "sourceFontFamily": str(annotation.get("pdfjsSourceFontFamily") or ""),
        "sourceFontWeight": str(annotation.get("pdfjsSourceFontWeight") or ""),
        "sourceFontStyle": str(annotation.get("pdfjsSourceFontStyle") or ""),
        "sourceFontSizePx": str(annotation.get("pdfjsSourceFontSizePx") or ""),
        "sourceLineHeightPx": str(annotation.get("pdfjsSourceLineHeightPx") or ""),
        "sourceTextWidthPx": str(annotation.get("pdfjsSourceTextWidthPx") or ""),
        "sourceTextColor": str(annotation.get("pdfjsSourceTextColor") or ""),
        "sourceTransform": str(annotation.get("pdfjsSourceTransform") or ""),
        "sourceTransformOrigin": str(annotation.get("pdfjsSourceTransformOrigin") or ""),
        "sourceFidelity": _boolish(annotation.get("pdfjsSourceFidelity")),
    }


def normalize_annotation_for_pdf_export(annotation: Dict[str, Any]) -> Dict[str, Any]:
    normalized = copy.deepcopy(annotation)
    if not is_text_annotation(normalized):
        return normalized

    if "text" in normalized or normalized.get("type") in (None, "", "text"):
        normalized["text"] = sanitize_pdf_text(normalized.get("text") or "")

    for field_name in ("originalText", "savedTextOverlay"):
        if isinstance(normalized.get(field_name), str):
            normalized[field_name] = sanitize_pdf_text(normalized.get(field_name) or "")

    if isinstance(normalized.get("richTextHtml"), str):
        normalized["richTextHtml"] = sanitize_rich_text_html(normalized.get("richTextHtml") or "")

    source_text_lines = normalized.get("sourceTextLines")
    if isinstance(source_text_lines, list):
        normalized["sourceTextLines"] = [sanitize_pdf_text(value) for value in source_text_lines]

    source_spans = normalized.get("sourceSpans")
    if isinstance(source_spans, list):
        next_spans = []
        for span in source_spans:
            if not isinstance(span, dict):
                next_spans.append(span)
                continue
            next_span = copy.deepcopy(span)
            for field_name in ("text", "rawText"):
                if field_name in next_span:
                    next_span[field_name] = sanitize_pdf_text(next_span.get(field_name) or "")
            next_spans.append(next_span)
        normalized["sourceSpans"] = next_spans

    return normalized


def assert_annotations_pdf_safe(annotations: Sequence[Dict[str, Any]]) -> None:
    violations: list[str] = []
    for index, annotation in enumerate(annotations):
        if not is_text_annotation(annotation):
            continue
        annotation_id = str(annotation.get("id") or f"index:{index}")
        for field_name in ("text", "originalText", "savedTextOverlay"):
            value = annotation.get(field_name)
            if isinstance(value, str) and _FORBIDDEN_TEXT_RE.search(value):
                violations.append(f"{annotation_id}.{field_name}")
        rich_html = annotation.get("richTextHtml")
        if isinstance(rich_html, str) and (_FORBIDDEN_TEXT_RE.search(rich_html) or _NBSP_ENTITY_RE.search(rich_html)):
            violations.append(f"{annotation_id}.richTextHtml")
        source_text_lines = annotation.get("sourceTextLines")
        if isinstance(source_text_lines, list):
            for line_index, value in enumerate(source_text_lines):
                if isinstance(value, str) and _FORBIDDEN_TEXT_RE.search(value):
                    violations.append(f"{annotation_id}.sourceTextLines[{line_index}]")
        source_spans = annotation.get("sourceSpans")
        if isinstance(source_spans, list):
            for span_index, span in enumerate(source_spans):
                if not isinstance(span, dict):
                    continue
                for field_name in ("text", "rawText"):
                    value = span.get(field_name)
                    if isinstance(value, str) and _FORBIDDEN_TEXT_RE.search(value):
                        violations.append(f"{annotation_id}.sourceSpans[{span_index}].{field_name}")
    if violations:
        raise ValueError(
            "Annotation payload still contains forbidden PDF text characters after normalization: "
            + ", ".join(violations[:12])
        )


def assert_text_annotations_redraw_contract(
    annotations: Sequence[Dict[str, Any]],
    redraw_page_indices: Optional[Iterable[int]],
) -> None:
    if redraw_page_indices is None:
        return
    redraw_pages = {int(value) for value in redraw_page_indices}
    violations: list[str] = []
    for index, annotation in enumerate(annotations):
        if not is_text_annotation(annotation):
            continue
        annotation_id = str(annotation.get("id") or f"index:{index}")
        try:
            page_index = int(annotation.get("pageIndex"))
        except Exception:
            violations.append(f"{annotation_id}@missing-pageIndex")
            continue
        if page_index not in redraw_pages:
            violations.append(f"{annotation_id}@page:{page_index}")
    if violations:
        raise ValueError(
            "Text annotations must only target requested redraw pages: "
            + ", ".join(violations[:12])
        )


def normalize_annotations_for_pdf_export(
    annotations: Sequence[Dict[str, Any]],
    *,
    redraw_page_indices: Optional[Iterable[int]] = None,
) -> list[Dict[str, Any]]:
    normalized = [
        normalize_annotation_for_pdf_export(annotation)
        if isinstance(annotation, dict)
        else annotation
        for annotation in annotations
    ]
    assert_annotations_pdf_safe([ann for ann in normalized if isinstance(ann, dict)])
    assert_text_annotations_redraw_contract(
        [ann for ann in normalized if isinstance(ann, dict)],
        redraw_page_indices,
    )
    return normalized
