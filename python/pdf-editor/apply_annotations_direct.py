#!/usr/bin/env python3
"""
Apply editor annotations directly to an existing PDF using PyMuPDF.
This path avoids pdf-lib full-document rewrites so shape stamping is
independent of overlay/text save behavior.
"""

import base64
import io
import json
import math
import os
import sys
from typing import Any, Dict, Optional, Tuple

import fitz


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
    rect = to_rect(page, ann)
    if rect is not None:
        page.insert_textbox(
            rect,
            text,
            fontsize=size,
            fontname="helv",
            color=color,
            overlay=True,
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
        text,
        fontsize=size,
        fontname="helv",
        color=color,
        overlay=True,
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
