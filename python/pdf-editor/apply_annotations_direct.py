#!/usr/bin/env python3
"""
Apply editor annotations directly to an existing PDF using PyMuPDF.
This path avoids pdf-lib full-document rewrites so shape stamping is
independent of overlay/text save behavior.
"""

import base64
import html
import io
import json
import math
import os
import sys
from typing import Any, Dict, Optional, Tuple

import fitz


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(SCRIPT_DIR, "fonts")


PDF_FONT_VARIANTS = {
    "Helvetica": {
        "normal": "helv",
        "bold": "hebo",
        "italic": "heit",
        "boldItalic": "hebi",
    },
    "TimesRoman": {
        "normal": "tiro",
        "bold": "tibo",
        "italic": "tiit",
        "boldItalic": "tibi",
    },
    "Courier": {
        "normal": "cour",
        "bold": "cobo",
        "italic": "coit",
        "boldItalic": "cobi",
    },
}

FONT_FILE_VARIANTS = {
    "Helvetica": {
        "normal": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Arimo-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Arimo-Bold.ttf"),
    },
    "TimesRoman": {
        "normal": os.path.join(FONT_DIR, "Tinos-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Tinos-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Tinos-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Tinos-Bold.ttf"),
    },
    "Courier": {
        "normal": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "bold": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
        "italic": os.path.join(FONT_DIR, "Cousine-Regular.ttf"),
        "boldItalic": os.path.join(FONT_DIR, "Cousine-Bold.ttf"),
    },
}

HTML_FONT_FAMILY_ALIASES = {
    "Helvetica": [
        "Helvetica",
        "Arial",
        "Verdana",
        "Trebuchet MS",
        "Geneva",
        "sans-serif",
    ],
    "TimesRoman": [
        "TimesRoman",
        "Times New Roman",
        "Times",
        "Georgia",
        "Palatino",
        "Book Antiqua",
        "Garamond",
        "Baskerville",
        "serif",
    ],
    "Courier": [
        "Courier",
        "Courier New",
        "monospace",
    ],
}


def clamp_page_index(value: Any, page_count: int) -> Optional[int]:
    try:
        idx = int(value)
    except Exception:
        return None
    if idx < 0 or idx >= page_count:
        return None
    return idx


def hex_to_rgb(value: Any) -> Tuple[float, float, float]:
    if not value:
        return (0.0, 0.0, 0.0)
    text = str(value).strip().lstrip("#")
    if len(text) != 6:
        return (0.0, 0.0, 0.0)
    try:
        return (
            int(text[0:2], 16) / 255.0,
            int(text[2:4], 16) / 255.0,
            int(text[4:6], 16) / 255.0,
        )
    except Exception:
        return (0.0, 0.0, 0.0)


def to_rect(page: fitz.Page, ann: Dict[str, Any]) -> Optional[fitz.Rect]:
    try:
        x = float(ann.get("pdfX", 0))
        y = float(ann.get("pdfY", 0))
        w = float(ann.get("pdfWidth", 0))
        h = float(ann.get("pdfHeight", 0))
    except Exception:
        return None
    if w <= 0 or h <= 0:
        return None
    # Frontend stores annotation.pdfY in PDF-lib coordinates (bottom-origin).
    # PyMuPDF page methods use top-origin page coordinates.
    ph = page.rect.height
    return fitz.Rect(x, ph - (y + h), x + w, ph - y)


def normalize_font_family(value: Any) -> str:
    lower = str(value or "").strip().lower().replace('"', "").replace("'", "")
    lower = lower.replace(" ", "").replace("-", "")

    if any(token in lower for token in ("courier", "monospace")):
        return "Courier"
    if any(token in lower for token in ("arial", "helvetica", "verdana", "trebuchet", "geneva", "sansserif")):
        return "Helvetica"
    if any(token in lower for token in (
        "times",
        "georgia",
        "palatino",
        "bookantiqua",
        "garamond",
        "baskerville",
        "serif",
    )):
        return "TimesRoman"
    return "Helvetica"


def css_font_family(value: Any) -> str:
    family = normalize_font_family(value)
    if family == "TimesRoman":
        return "Times New Roman, serif"
    if family == "Courier":
        return "Courier New, monospace"
    return "Helvetica, Arial, sans-serif"


def build_html_font_face_css() -> str:
    css_rules: list[str] = []
    for normalized_family, aliases in HTML_FONT_FAMILY_ALIASES.items():
        variants = FONT_FILE_VARIANTS.get(normalized_family, {})
        regular_file = variants.get("normal")
        bold_file = variants.get("bold") or regular_file
        italic_file = variants.get("italic") or regular_file
        bold_italic_file = variants.get("boldItalic") or bold_file or italic_file or regular_file
        faces = [
            (400, "normal", regular_file),
            (700, "normal", bold_file),
            (400, "italic", italic_file),
            (700, "italic", bold_italic_file),
        ]
        for alias in aliases:
            quoted_alias = json.dumps(alias)
            for weight, style, font_path in faces:
                if not font_path or not os.path.exists(font_path):
                    continue
                css_rules.append(
                    "@font-face { "
                    f"font-family: {quoted_alias}; "
                    f"src: url({os.path.basename(font_path)}); "
                    f"font-weight: {weight}; "
                    f"font-style: {style}; "
                    "}"
                )
    return "\n".join(css_rules)


HTML_FONT_FACE_CSS = build_html_font_face_css()


def build_annotation_htmlbox_css(ann: Dict[str, Any], font_size: float, opacity: float) -> str:
    font_weight = str(ann.get("fontWeight") or "400").strip() or "400"
    font_style = str(ann.get("fontStyle") or "normal").strip() or "normal"
    text_align = str(ann.get("textAlign") or "left").strip().lower()
    text_decoration = "underline" if ann.get("underline") else "none"
    font_family = css_font_family(ann.get("fontFamily"))
    text_color = str(ann.get("textColor") or "#000000").strip() or "#000000"
    return (
        f"{HTML_FONT_FACE_CSS}\n"
        "body { margin: 0; padding: 0; }\n"
        ".annotation-box {\n"
        "  margin: 0;\n"
        "  padding: 0;\n"
        f"  font-family: {font_family};\n"
        f"  font-size: {font_size}pt;\n"
        f"  color: {text_color};\n"
        f"  font-weight: {font_weight};\n"
        f"  font-style: {font_style};\n"
        f"  text-align: {text_align};\n"
        f"  text-decoration: {text_decoration};\n"
        f"  opacity: {opacity};\n"
        "  line-height: normal;\n"
        "  white-space: pre-wrap;\n"
        "  hyphens: none;\n"
        "  overflow-wrap: break-word;\n"
        "  word-break: break-word;\n"
        "}\n"
        ".annotation-box p, .annotation-box div, .annotation-box span {\n"
        "  margin-top: 0;\n"
        "  margin-bottom: 0;\n"
        "}\n"
    )


def build_annotation_htmlbox_markup(ann: Dict[str, Any], text: str) -> str:
    rich_html = str(ann.get("richTextHtml") or "").strip()
    inner_html = rich_html if rich_html else html.escape(text)
    return f'<div class="annotation-box">{inner_html}</div>'


def is_bold_weight(value: Any) -> bool:
    lower = str(value or "").strip().lower()
    if lower == "bold":
        return True
    try:
        return int(float(lower)) >= 600
    except Exception:
        return "bold" in lower


def is_italic_style(value: Any) -> bool:
    lower = str(value or "").strip().lower()
    return "italic" in lower or "oblique" in lower


def resolve_text_fontname(ann: Dict[str, Any]) -> str:
    family = normalize_font_family(ann.get("fontFamily"))
    variants = PDF_FONT_VARIANTS.get(family, PDF_FONT_VARIANTS["Helvetica"])
    is_bold = is_bold_weight(ann.get("fontWeight"))
    is_italic = is_italic_style(ann.get("fontStyle"))
    if is_bold and is_italic:
        return variants["boldItalic"]
    if is_bold:
        return variants["bold"]
    if is_italic:
        return variants["italic"]
    return variants["normal"]


def resolve_text_fontfile(ann: Dict[str, Any]) -> Optional[str]:
    family = normalize_font_family(ann.get("fontFamily"))
    variants = FONT_FILE_VARIANTS.get(family, FONT_FILE_VARIANTS["Helvetica"])
    is_bold = is_bold_weight(ann.get("fontWeight"))
    is_italic = is_italic_style(ann.get("fontStyle"))
    if is_bold and is_italic:
        candidate = variants["boldItalic"]
    elif is_bold:
        candidate = variants["bold"]
    elif is_italic:
        candidate = variants["italic"]
    else:
        candidate = variants["normal"]
    return candidate if os.path.exists(candidate) else None


def resolve_text_font_resource_name(ann: Dict[str, Any]) -> str:
    family = normalize_font_family(ann.get("fontFamily"))
    is_bold = is_bold_weight(ann.get("fontWeight"))
    is_italic = is_italic_style(ann.get("fontStyle"))
    suffix = "Regular"
    if is_bold and is_italic:
        suffix = "BoldItalic"
    elif is_bold:
        suffix = "Bold"
    elif is_italic:
        suffix = "Italic"
    return f"Annot{family}{suffix}"


def parse_text_align(value: Any) -> int:
    lower = str(value or "left").strip().lower()
    if lower == "center":
        return 1
    if lower == "right":
        return 2
    if lower == "justify":
        return 3
    return 0


def normalized_opacity(value: Any) -> float:
    try:
        opacity = float(value)
    except Exception:
        opacity = 1.0
    return max(0.0, min(1.0, opacity))


def wrap_text_to_width(font: fitz.Font, text: str, font_size: float, max_width: float) -> list[str]:
    safe_text = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    paragraphs = safe_text.split("\n")
    lines: list[str] = []

    def push_wrapped_word(word: str) -> None:
        segment = ""
        for char in word:
            candidate = segment + char
            if not segment or font.text_length(candidate, fontsize=font_size) <= max_width:
                segment = candidate
                continue
            lines.append(segment)
            segment = char
        if segment:
            lines.append(segment)

    for idx, paragraph in enumerate(paragraphs):
        words = [word for word in paragraph.split() if word]
        if not words:
            lines.append("")
        else:
            current_line = ""
            for word in words:
                candidate = f"{current_line} {word}".strip() if current_line else word
                if not current_line or font.text_length(candidate, fontsize=font_size) <= max_width:
                    current_line = candidate
                    continue

                if current_line:
                    lines.append(current_line)
                    current_line = ""

                if font.text_length(word, fontsize=font_size) <= max_width:
                    current_line = word
                else:
                    push_wrapped_word(word)

            if current_line:
                lines.append(current_line)

        if idx < len(paragraphs) - 1:
            lines.append("")

    return lines or [""]


def draw_shape(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return

    shape_type = (ann.get("shapeType") or "rect").lower()
    opacity = float(ann.get("opacity", 1.0) or 1.0)
    stroke_width = float(ann.get("strokeWidth", 2.0) or 2.0)
    rotation = float(ann.get("rotation", 0.0) or 0.0)

    stroke = None if ann.get("strokeTransparent") else hex_to_rgb(ann.get("strokeColor") or "#000000")
    fill = None if ann.get("fillTransparent") else hex_to_rgb(ann.get("fillColor") or "#ffffff")

    if stroke is None and fill is None:
        return

    cx = (rect.x0 + rect.x1) / 2.0
    cy = (rect.y0 + rect.y1) / 2.0
    rad = math.radians(rotation)
    cos_r = math.cos(rad)
    sin_r = math.sin(rad)

    def rp(nx: float, ny: float) -> fitz.Point:
        x = rect.x0 + nx * rect.width
        y = rect.y0 + ny * rect.height
        if abs(rotation) < 1e-6:
            return fitz.Point(x, y)
        dx = x - cx
        dy = y - cy
        # Y axis points down; this matrix matches frontend CSS clockwise rotation.
        xr = cx + (dx * cos_r - dy * sin_r)
        yr = cy + (dx * sin_r + dy * cos_r)
        return fitz.Point(xr, yr)

    def draw_open_lines(lines) -> None:
        if stroke is None:
            return
        s = page.new_shape()
        for p1, p2 in lines:
            s.draw_line(p1, p2)
        s.finish(color=stroke, width=stroke_width, lineCap=1, stroke_opacity=opacity)
        s.commit(overlay=True)

    def draw_poly(points, close_path=True, line_join=1) -> None:
        s = page.new_shape()
        s.draw_polyline(points)
        s.finish(
            color=stroke,
            fill=fill if close_path else None,
            width=stroke_width,
            closePath=close_path,
            lineCap=1,
            lineJoin=line_join,
            stroke_opacity=opacity,
            fill_opacity=opacity,
        )
        s.commit(overlay=True)

    if shape_type in ("circle", "ellipse"):
        # Draw as a rotated polygon approximation so rotation persists in direct-save mode.
        steps = 48
        points = []
        for i in range(steps):
            a = (2.0 * math.pi * i) / steps
            px = 0.5 + 0.48 * math.cos(a)
            py = 0.5 + 0.48 * math.sin(a)
            points.append(rp(px, py))
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "triangle":
        draw_poly([rp(0.5, 0.05), rp(0.95, 0.95), rp(0.05, 0.95), rp(0.5, 0.05)], close_path=True, line_join=1)
        return

    if shape_type == "arrow":
        draw_open_lines([
            (rp(0.10, 0.50), rp(0.80, 0.50)),
            (rp(0.65, 0.35), rp(0.80, 0.50)),
            (rp(0.65, 0.65), rp(0.80, 0.50)),
        ])
        return

    if shape_type == "x":
        draw_open_lines([
            (rp(0.15, 0.15), rp(0.85, 0.85)),
            (rp(0.85, 0.15), rp(0.15, 0.85)),
        ])
        return

    if shape_type == "checkmark":
        s = page.new_shape()
        p1 = rp(0.15, 0.50)
        p2 = rp(0.40, 0.75)
        p3 = rp(0.85, 0.15)
        s.draw_polyline([p1, p2, p3])
        s.finish(color=stroke, fill=None, width=stroke_width, lineCap=1, lineJoin=1, stroke_opacity=opacity)
        s.commit(overlay=True)
        return

    if shape_type == "star":
        points = [
            rp(0.50, 0.05), rp(0.61, 0.38), rp(0.95, 0.38), rp(0.68, 0.58), rp(0.79, 0.91),
            rp(0.50, 0.71), rp(0.21, 0.91), rp(0.32, 0.58), rp(0.05, 0.38), rp(0.39, 0.38),
        ]
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    if shape_type == "polygon":
        points = [rp(0.50, 0.05), rp(0.90, 0.27), rp(0.90, 0.73), rp(0.50, 0.95), rp(0.10, 0.73), rp(0.10, 0.27)]
        points.append(points[0])
        draw_poly(points, close_path=True, line_join=1)
        return

    # Default rectangle
    points = [rp(0.05, 0.05), rp(0.95, 0.05), rp(0.95, 0.95), rp(0.05, 0.95), rp(0.05, 0.05)]
    draw_poly(points, close_path=True, line_join=1)


def draw_table(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return

    rows = max(1, int(ann.get("rows", 1) or 1))
    cols = max(1, int(ann.get("cols", 1) or 1))
    opacity = float(ann.get("opacity", 1.0) or 1.0)
    stroke_width = float(ann.get("strokeWidth", 1.0) or 1.0)
    stroke = hex_to_rgb(ann.get("strokeColor") or "#000000")
    fill = None if ann.get("fillTransparent") else hex_to_rgb(ann.get("fillColor") or "#ffffff")

    # Draw cell fill first
    if fill is not None:
        s = page.new_shape()
        s.draw_rect(rect)
        s.finish(color=None, fill=fill, width=0, fill_opacity=opacity)
        s.commit(overlay=True)

    # Draw outer rectangle (optional) + internal grid lines
    s = page.new_shape()
    outer_border = ann.get("outerBorder", True)
    if outer_border is not False and str(outer_border).lower() not in ("false", "0"):
        s.draw_rect(rect)
    cell_h = rect.height / rows
    for i in range(1, rows):
        y = rect.y0 + i * cell_h
        s.draw_line(fitz.Point(rect.x0, y), fitz.Point(rect.x1, y))
    cell_w = rect.width / cols
    for j in range(1, cols):
        x = rect.x0 + j * cell_w
        s.draw_line(fitz.Point(x, rect.y0), fitz.Point(x, rect.y1))
    s.finish(color=stroke, fill=None, width=stroke_width, lineCap=1, stroke_opacity=opacity)
    s.commit(overlay=True)


def draw_eraser(page: fitz.Page, ann: Dict[str, Any]) -> None:
    ph = page.rect.height
    rects = ann.get("strokeRects") or []
    if isinstance(rects, list) and rects:
        for r in rects:
            try:
                x = float(r.get("x", 0))
                y = float(r.get("y", 0))
                w = float(r.get("w", 0))
                h = float(r.get("h", 0))
            except Exception:
                continue
            if w <= 0 or h <= 0:
                continue
            rr = fitz.Rect(x, ph - (y + h), x + w, ph - y)
            page.draw_rect(rr, color=None, fill=(1, 1, 1), width=0, overlay=True)
        return

    rect = to_rect(page, ann)
    if rect is not None:
        page.draw_rect(rect, color=None, fill=(1, 1, 1), width=0, overlay=True)


def draw_text(page: fitz.Page, ann: Dict[str, Any]) -> None:
    text = str(ann.get("text") or "")
    if not text:
        return
    size = float(ann.get("fontSize", 12) or 12)
    color = hex_to_rgb(ann.get("textColor") or "#000000")
    background = str(ann.get("backgroundColor") or "").strip().lower()
    background_color = None if not background or background == "transparent" else hex_to_rgb(background)
    opacity = normalized_opacity(ann.get("opacity", 1.0) or 1.0)
    fontname = resolve_text_fontname(ann)
    fontfile = resolve_text_fontfile(ann)
    align = parse_text_align(ann.get("textAlign"))
    rect = to_rect(page, ann)
    custom_font = None
    html_archive = None
    if fontfile:
        try:
            custom_font = fitz.Font(fontfile=fontfile)
            fontname = resolve_text_font_resource_name(ann)
            page.insert_font(fontname=fontname, fontfile=fontfile)
        except Exception:
            custom_font = None
            fontname = resolve_text_fontname(ann)
    if rect is not None:
        if background_color is not None:
            page.draw_rect(
                rect,
                color=None,
                fill=background_color,
                width=0,
                overlay=True,
                fill_opacity=opacity,
            )
        try:
            html_archive = fitz.Archive(FONT_DIR)
        except Exception:
            html_archive = None
        if html_archive is not None:
            inner_rect = fitz.Rect(
                rect.x0 + 6.0,
                rect.y0 + 2.0,
                rect.x1 - 6.0,
                rect.y1 - 2.0,
            )
            if inner_rect.width > 1 and inner_rect.height > 1:
                html_box = build_annotation_htmlbox_markup(ann, text)
                html_css = build_annotation_htmlbox_css(ann, size, opacity)
                try:
                    spare_height, scale = page.insert_htmlbox(
                        inner_rect,
                        html_box,
                        css=html_css,
                        archive=html_archive,
                        scale_low=1,
                        opacity=opacity,
                        overlay=True,
                    )
                    if spare_height >= -0.001 and scale >= 0.999:
                        return
                except Exception:
                    pass
        draw_font = custom_font or fitz.Font(fontname)
        padding_x = min(6.0, max(1.0, rect.width * 0.03))
        padding_top = min(2.0, max(0.5, rect.height * 0.01))
        available_width = max(1.0, rect.width - (padding_x * 2.0))
        line_height = size * 1.2
        lines = wrap_text_to_width(draw_font, text, size, available_width)
        baseline_y = rect.y0 + size + padding_top

        for line_index, line in enumerate(lines):
            line_baseline_y = baseline_y + (line_index * line_height)
            if line_baseline_y > rect.y1:
                break

            text_width = draw_font.text_length(line, fontsize=size) if line else 0.0
            draw_x = rect.x0 + padding_x
            if align == 1:
                draw_x = rect.x0 + padding_x + max(0.0, (available_width - text_width) / 2.0)
            elif align == 2:
                draw_x = rect.x1 - padding_x - text_width

            page.insert_text(
                fitz.Point(draw_x, line_baseline_y),
                html.unescape(line),
                fontsize=size,
                fontname=fontname,
                color=color,
                overlay=True,
                fill_opacity=opacity,
                stroke_opacity=opacity,
            )

            if ann.get("underline") and line:
                underline_y = min(rect.y1 - max(0.5, size * 0.08), line_baseline_y + max(0.5, size * 0.08))
                page.draw_line(
                    fitz.Point(draw_x, underline_y),
                    fitz.Point(draw_x + text_width, underline_y),
                    color=color,
                    width=max(0.5, size * 0.06),
                    overlay=True,
                    stroke_opacity=opacity,
                )
        return

    # Fallback for text annotations that do not include pdfWidth/pdfHeight.
    # Frontend stores pdfX/pdfY in bottom-origin coordinates (pdf-lib style).
    # Convert to top-origin for PyMuPDF and place baseline from font size.
    try:
        x = float(ann.get("pdfX", 0))
        y_pdf = float(ann.get("pdfY", 0))
    except Exception:
        return
    if x < 0:
        x = 0
    ph = page.rect.height
    y_top = ph - y_pdf
    baseline_y = y_top + size
    page.insert_text(
        fitz.Point(x, baseline_y),
        html.unescape(text),
        fontsize=size,
        fontname=fontname,
        color=color,
        overlay=True,
        fill_opacity=opacity,
        stroke_opacity=opacity,
    )
    if ann.get("underline"):
        font = fitz.Font(fontname)
        text_width = font.text_length(text, fontsize=size)
        underline_y = baseline_y + max(0.5, size * 0.08)
        page.draw_line(
            fitz.Point(x, underline_y),
            fitz.Point(x + text_width, underline_y),
            color=color,
            width=max(0.5, size * 0.06),
            overlay=True,
            stroke_opacity=opacity,
        )


def draw_signature(page: fitz.Page, ann: Dict[str, Any]) -> None:
    rect = to_rect(page, ann)
    if rect is None:
        return
    data_url = str(ann.get("dataUrl") or "")
    if not data_url.startswith("data:image/"):
        return
    try:
        _, payload = data_url.split(",", 1)
        img = base64.b64decode(payload)
    except Exception:
        return
    page.insert_image(rect, stream=img, overlay=True)


def apply_annotations(pdf_path: str, annotations: list) -> None:
    doc = fitz.open(pdf_path)
    for ann in annotations:
        if not isinstance(ann, dict):
            continue
        page_index = clamp_page_index(ann.get("pageIndex"), doc.page_count)
        if page_index is None:
            continue
        page = doc[page_index]
        kind = (ann.get("type") or "").lower()
        if kind == "shape":
            draw_shape(page, ann)
        elif kind == "table":
            draw_table(page, ann)
        elif kind == "eraser":
            draw_eraser(page, ann)
        elif kind == "text":
            draw_text(page, ann)
        elif kind == "signature":
            draw_signature(page, ann)

    temp_path = pdf_path + ".ann.tmp"
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

    os.replace(temp_path, pdf_path)


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: apply_annotations_direct.py <pdf_path> <annotations_json_path>")
        return 1

    pdf_path = sys.argv[1]
    annotations_path = sys.argv[2]
    if not os.path.exists(pdf_path):
        print(f"PDF not found: {pdf_path}")
        return 1
    if not os.path.exists(annotations_path):
        print(f"Annotations JSON not found: {annotations_path}")
        return 1

    with open(annotations_path, "r", encoding="utf-8") as fh:
        annotations = json.load(fh)
    if not isinstance(annotations, list):
        print("Annotations payload must be a JSON array.")
        return 1

    apply_annotations(pdf_path, annotations)
    print(f"Applied {len(annotations)} annotation(s) directly.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
