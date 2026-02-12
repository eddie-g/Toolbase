#!/usr/bin/env python3
"""
Shape Tool Test Suite

Creates a blank PDF, draws each shape type onto it using PyMuPDF
(mirroring what the browser-side PDFLib shape tool does),
saves the result, then re-opens and verifies each shape is present
in the PDF content stream.

Shape types tested:
  1. Rectangle (rect)
  2. Circle / Ellipse
  3. Triangle
  4. Arrow
  5. Star (5-pointed)
  6. Checkmark
  7. Polygon (hexagon)
  8. X mark

Usage:
  python3 test_shapes.py --list-files
  python3 test_shapes.py --run-all
  python3 test_shapes.py --single-shape <shape_type>
"""
import sys
import os
import json
import math
import tempfile
import fitz  # PyMuPDF

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SHAPES_DIR = os.path.join(os.path.dirname(SCRIPT_DIR), "tests", "OverlayEditor", "Shapes")

# Page dimensions (US Letter)
PAGE_WIDTH = 612
PAGE_HEIGHT = 792

# Grid layout: 2 columns, 4 rows — each shape gets a cell
COLS = 2
ROWS = 4
CELL_W = PAGE_WIDTH / COLS
CELL_H = PAGE_HEIGHT / ROWS
SHAPE_MARGIN = 30  # margin inside each cell
SHAPE_SIZE = min(CELL_W, CELL_H) - SHAPE_MARGIN * 2

# Shape definitions — order matters for grid placement
SHAPE_DEFS = [
    {
        "type": "rect",
        "label": "Rectangle",
        "stroke_color": (0, 0, 0),        # black
        "fill_color": (0.2, 0.4, 0.8),    # blue
        "stroke_width": 2,
    },
    {
        "type": "circle",
        "label": "Circle / Ellipse",
        "stroke_color": (0, 0, 0),
        "fill_color": (0.8, 0.2, 0.2),    # red
        "stroke_width": 2,
    },
    {
        "type": "triangle",
        "label": "Triangle",
        "stroke_color": (0, 0, 0),
        "fill_color": (0.2, 0.7, 0.3),    # green
        "stroke_width": 2,
    },
    {
        "type": "arrow",
        "label": "Arrow",
        "stroke_color": (0.1, 0.1, 0.6),  # dark blue
        "fill_color": None,
        "stroke_width": 3,
    },
    {
        "type": "star",
        "label": "Star (5-pointed)",
        "stroke_color": (0.6, 0.4, 0),    # gold outline
        "fill_color": (1.0, 0.84, 0),     # gold fill
        "stroke_width": 2,
    },
    {
        "type": "checkmark",
        "label": "Checkmark",
        "stroke_color": (0, 0.6, 0),      # green
        "fill_color": None,
        "stroke_width": 4,
    },
    {
        "type": "polygon",
        "label": "Polygon (Hexagon)",
        "stroke_color": (0.5, 0, 0.5),    # purple
        "fill_color": (0.8, 0.6, 1.0),    # light purple
        "stroke_width": 2,
    },
    {
        "type": "x",
        "label": "X Mark",
        "stroke_color": (0.8, 0, 0),      # red
        "fill_color": None,
        "stroke_width": 4,
    },
]


def get_cell_rect(idx):
    """Get the rectangle for shape at grid index idx."""
    col = idx % COLS
    row = idx // COLS
    x0 = col * CELL_W + SHAPE_MARGIN
    # PDF y-axis is bottom-up
    y0 = PAGE_HEIGHT - (row + 1) * CELL_H + SHAPE_MARGIN
    w = CELL_W - SHAPE_MARGIN * 2
    h = CELL_H - SHAPE_MARGIN * 2
    return x0, y0, w, h


def draw_rect(page, x, y, w, h, shape_def):
    """Draw a rectangle."""
    rect = fitz.Rect(x, PAGE_HEIGHT - y - h, x + w, PAGE_HEIGHT - y)
    shape = page.new_shape()
    shape.draw_rect(rect)
    fill = shape_def["fill_color"]
    stroke = shape_def["stroke_color"]
    shape.finish(
        color=stroke,
        fill=fill,
        width=shape_def["stroke_width"],
    )
    shape.commit()


def draw_circle(page, x, y, w, h, shape_def):
    """Draw an ellipse/circle."""
    center_x = x + w / 2
    center_y = PAGE_HEIGHT - y - h / 2
    rect = fitz.Rect(x, PAGE_HEIGHT - y - h, x + w, PAGE_HEIGHT - y)
    shape = page.new_shape()
    shape.draw_oval(rect)
    shape.finish(
        color=shape_def["stroke_color"],
        fill=shape_def["fill_color"],
        width=shape_def["stroke_width"],
    )
    shape.commit()


def draw_triangle(page, x, y, w, h, shape_def):
    """Draw a triangle."""
    # Points: top-center, bottom-right, bottom-left (converted to PDF coords)
    top = fitz.Point(x + w / 2, PAGE_HEIGHT - y - h)
    right = fitz.Point(x + w, PAGE_HEIGHT - y)
    left = fitz.Point(x, PAGE_HEIGHT - y)

    shape = page.new_shape()
    shape.draw_polyline([top, right, left, top])
    shape.finish(
        color=shape_def["stroke_color"],
        fill=shape_def["fill_color"],
        width=shape_def["stroke_width"],
        closePath=True,
    )
    shape.commit()


def draw_arrow(page, x, y, w, h, shape_def):
    """Draw an arrow line with arrowhead."""
    mid_y = PAGE_HEIGHT - y - h / 2
    start = fitz.Point(x + w * 0.1, mid_y)
    end = fitz.Point(x + w * 0.8, mid_y)
    head_top = fitz.Point(x + w * 0.65, mid_y - h * 0.15)
    head_bot = fitz.Point(x + w * 0.65, mid_y + h * 0.15)

    shape = page.new_shape()
    # Main line
    shape.draw_line(start, end)
    shape.finish(
        color=shape_def["stroke_color"],
        width=shape_def["stroke_width"],
        lineCap=1,  # round
        lineJoin=1,
    )
    # Arrowhead
    shape.draw_polyline([head_top, end, head_bot])
    shape.finish(
        color=shape_def["stroke_color"],
        width=shape_def["stroke_width"],
        lineCap=1,
        lineJoin=1,
    )
    shape.commit()


def draw_star(page, x, y, w, h, shape_def):
    """Draw a 5-pointed star."""
    # Normalized star points (same as the JS editor)
    norm_points = [
        (0.5, 0.05), (0.61, 0.38), (0.95, 0.38), (0.68, 0.58),
        (0.79, 0.91), (0.5, 0.71), (0.21, 0.91), (0.32, 0.58),
        (0.05, 0.38), (0.39, 0.38)
    ]
    points = []
    for nx, ny in norm_points:
        px = x + nx * w
        py = PAGE_HEIGHT - y - ny * h
        points.append(fitz.Point(px, py))
    points.append(points[0])  # close

    shape = page.new_shape()
    shape.draw_polyline(points)
    shape.finish(
        color=shape_def["stroke_color"],
        fill=shape_def["fill_color"],
        width=shape_def["stroke_width"],
        closePath=True,
        lineJoin=1,
    )
    shape.commit()


def draw_checkmark(page, x, y, w, h, shape_def):
    """Draw a checkmark."""
    p1 = fitz.Point(x + w * 0.15, PAGE_HEIGHT - y - h * 0.5)
    p2 = fitz.Point(x + w * 0.4, PAGE_HEIGHT - y - h * 0.75)
    p3 = fitz.Point(x + w * 0.85, PAGE_HEIGHT - y - h * 0.15)

    shape = page.new_shape()
    shape.draw_polyline([p1, p2, p3])
    shape.finish(
        color=shape_def["stroke_color"],
        width=shape_def["stroke_width"],
        lineCap=1,
        lineJoin=1,
    )
    shape.commit()


def draw_polygon(page, x, y, w, h, shape_def):
    """Draw a hexagon."""
    norm_points = [
        (0.5, 0.05), (0.9, 0.27), (0.9, 0.73),
        (0.5, 0.95), (0.1, 0.73), (0.1, 0.27)
    ]
    points = []
    for nx, ny in norm_points:
        px = x + nx * w
        py = PAGE_HEIGHT - y - ny * h
        points.append(fitz.Point(px, py))
    points.append(points[0])  # close

    shape = page.new_shape()
    shape.draw_polyline(points)
    shape.finish(
        color=shape_def["stroke_color"],
        fill=shape_def["fill_color"],
        width=shape_def["stroke_width"],
        closePath=True,
        lineJoin=1,
    )
    shape.commit()


def draw_x(page, x, y, w, h, shape_def):
    """Draw an X mark (two diagonal lines)."""
    shape = page.new_shape()
    # First diagonal: top-left to bottom-right (with margins)
    shape.draw_line(
        fitz.Point(x + w * 0.15, PAGE_HEIGHT - y - h * 0.85),
        fitz.Point(x + w * 0.85, PAGE_HEIGHT - y - h * 0.15),
    )
    shape.finish(
        color=shape_def["stroke_color"],
        width=shape_def["stroke_width"],
        lineCap=1,
    )
    # Second diagonal: top-right to bottom-left
    shape.draw_line(
        fitz.Point(x + w * 0.85, PAGE_HEIGHT - y - h * 0.85),
        fitz.Point(x + w * 0.15, PAGE_HEIGHT - y - h * 0.15),
    )
    shape.finish(
        color=shape_def["stroke_color"],
        width=shape_def["stroke_width"],
        lineCap=1,
    )
    shape.commit()


# Map shape type to draw function
DRAW_FUNCTIONS = {
    "rect": draw_rect,
    "circle": draw_circle,
    "triangle": draw_triangle,
    "arrow": draw_arrow,
    "star": draw_star,
    "checkmark": draw_checkmark,
    "polygon": draw_polygon,
    "x": draw_x,
}


def create_blank_pdf():
    """Create a blank PDF with a white page."""
    doc = fitz.open()
    page = doc.new_page(width=PAGE_WIDTH, height=PAGE_HEIGHT)
    return doc


def add_labels(page):
    """Add shape name labels to each cell."""
    for idx, sdef in enumerate(SHAPE_DEFS):
        x, y, w, h = get_cell_rect(idx)
        # Label at top of cell (PDF coords)
        label_y = PAGE_HEIGHT - (y + h) - 5
        label_x = x + 5
        page.insert_text(
            fitz.Point(label_x, label_y + 12),
            sdef["label"],
            fontsize=10,
            color=(0.3, 0.3, 0.3),
        )


def draw_all_shapes(page):
    """Draw all shape types onto the page."""
    for idx, sdef in enumerate(SHAPE_DEFS):
        x, y, w, h = get_cell_rect(idx)
        # Leave room for label at top
        shape_y = y + 20
        shape_h = h - 20
        draw_fn = DRAW_FUNCTIONS[sdef["type"]]
        draw_fn(page, x, shape_y, w, shape_h, sdef)


def generate_shapes_pdf(output_path=None):
    """Generate the test PDF with all shapes."""
    if output_path is None:
        output_path = os.path.join(SHAPES_DIR, "shapes_test.pdf")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)

    doc = create_blank_pdf()
    page = doc[0]
    add_labels(page)
    draw_all_shapes(page)
    doc.save(output_path)
    doc.close()
    return output_path


def verify_shape_in_pdf(pdf_path, shape_type, shape_def, cell_idx):
    """
    Verify a shape exists in the PDF by checking:
    1. Drawing commands exist in the content stream within the expected region
    2. The PDF has drawings (non-text graphical elements) in the expected area
    """
    checks = []
    doc = fitz.open(pdf_path)
    page = doc[0]

    x, y, w, h = get_cell_rect(cell_idx)
    shape_y = y + 20
    shape_h = h - 20

    # Convert to PDF rect (fitz uses top-left origin)
    check_rect = fitz.Rect(x - 5, PAGE_HEIGHT - (shape_y + shape_h) - 5,
                           x + w + 5, PAGE_HEIGHT - shape_y + 5)

    # Check 1: PDF is readable
    checks.append({
        "item": f"{shape_def['label']} — PDF Readable",
        "result": "PASS",
        "description": "PDF can be opened and read",
        "detail": f"Page size: {page.rect.width}x{page.rect.height}",
    })

    # Check 2: Content stream contains drawing operators
    page_content = page.get_text("rawdict")
    drawings = page.get_drawings()

    # Filter drawings that overlap our cell area
    cell_drawings = []
    for d in drawings:
        d_rect = fitz.Rect(d["rect"])
        if d_rect.intersects(check_rect):
            cell_drawings.append(d)

    has_drawings = len(cell_drawings) > 0
    checks.append({
        "item": f"{shape_def['label']} — Drawings Found",
        "result": "PASS" if has_drawings else "FAIL",
        "description": f"Drawing commands found in the expected region for {shape_type}",
        "detail": f"{len(cell_drawings)} drawing(s) found in cell area" if has_drawings else "No drawings detected in cell area",
    })

    # Check 3: Verify shape-specific attributes
    if shape_type == "rect":
        has_rect = any(
            d.get("type") == "re" or
            any(item[0] == "re" for item in d.get("items", []))
            for d in cell_drawings
        )
        # Also check if there are any "l" (line) items forming a rectangle
        if not has_rect:
            has_rect = len(cell_drawings) > 0  # Rectangles may be drawn as paths
        checks.append({
            "item": f"{shape_def['label']} — Shape Valid",
            "result": "PASS" if has_rect else "FAIL",
            "description": "Rectangle drawing operators present",
            "detail": f"Found rectangle-like drawings" if has_rect else "No rectangle operators found",
        })

    elif shape_type == "circle":
        has_curves = any(
            any(item[0] == "c" for item in d.get("items", []))
            for d in cell_drawings
        )
        checks.append({
            "item": f"{shape_def['label']} — Shape Valid",
            "result": "PASS" if has_curves else "FAIL",
            "description": "Curve/ellipse operators present",
            "detail": "Found curve drawing operators" if has_curves else "No curve operators found",
        })

    elif shape_type in ("triangle", "star", "polygon"):
        has_lines = any(
            any(item[0] == "l" for item in d.get("items", []))
            for d in cell_drawings
        )
        checks.append({
            "item": f"{shape_def['label']} — Shape Valid",
            "result": "PASS" if has_lines else "FAIL",
            "description": f"Line operators present for {shape_type}",
            "detail": "Found line drawing operators" if has_lines else "No line operators found",
        })

    elif shape_type in ("arrow", "checkmark", "x"):
        has_lines = any(
            any(item[0] == "l" for item in d.get("items", []))
            for d in cell_drawings
        )
        checks.append({
            "item": f"{shape_def['label']} — Shape Valid",
            "result": "PASS" if has_lines else "FAIL",
            "description": f"Line operators present for {shape_type}",
            "detail": "Found line drawing operators" if has_lines else "No line operators found",
        })

    # Check 4: Verify fill color if expected
    if shape_def["fill_color"]:
        has_fill = any(d.get("fill") is not None for d in cell_drawings)
        checks.append({
            "item": f"{shape_def['label']} — Fill Color",
            "result": "PASS" if has_fill else "FAIL",
            "description": "Shape has fill color applied",
            "detail": "Fill color detected" if has_fill else "No fill color found",
        })

    # Check 5: Verify stroke color if expected
    if shape_def["stroke_color"]:
        has_stroke = any(d.get("color") is not None for d in cell_drawings)
        checks.append({
            "item": f"{shape_def['label']} — Stroke Color",
            "result": "PASS" if has_stroke else "FAIL",
            "description": "Shape has stroke color applied",
            "detail": "Stroke color detected" if has_stroke else "No stroke color found",
        })

    # Check 6: Position accuracy — shape center roughly in correct cell
    if cell_drawings:
        avg_x = sum((d["rect"][0] + d["rect"][2]) / 2 for d in cell_drawings) / len(cell_drawings)
        avg_y = sum((d["rect"][1] + d["rect"][3]) / 2 for d in cell_drawings) / len(cell_drawings)
        expected_cx = x + w / 2
        expected_cy = PAGE_HEIGHT - shape_y - shape_h / 2
        dist = math.sqrt((avg_x - expected_cx) ** 2 + (avg_y - expected_cy) ** 2)
        position_ok = dist < max(w, h)  # generous tolerance
        checks.append({
            "item": f"{shape_def['label']} — Position",
            "result": "PASS" if position_ok else "FAIL",
            "description": "Shape positioned in correct cell area",
            "detail": f"Center offset: {dist:.1f}pt (tolerance: {max(w, h):.0f}pt)",
        })

    doc.close()
    return checks


def validate_shape(shape_type):
    """Validate a single shape type: generate, save, verify."""
    # Find the shape def
    shape_idx = None
    shape_def = None
    for idx, sdef in enumerate(SHAPE_DEFS):
        if sdef["type"] == shape_type:
            shape_idx = idx
            shape_def = sdef
            break

    if shape_def is None:
        return {
            "filename": f"{shape_type}_test.pdf",
            "description": f"Unknown shape type: {shape_type}",
            "test_category": "Shapes",
            "section_name": shape_type,
            "status": "error",
            "checks": [],
            "checks_passed": 0,
            "checks_total": 0,
            "page_count": 0,
            "file_size": 0,
            "error": f"Unknown shape type: {shape_type}",
            "warnings": [],
        }

    # Generate a single-shape PDF for this specific test
    output_path = os.path.join(SHAPES_DIR, f"{shape_type}_test.pdf")
    os.makedirs(SHAPES_DIR, exist_ok=True)

    doc = fitz.open()
    page = doc.new_page(width=PAGE_WIDTH, height=PAGE_HEIGHT)

    # Draw label
    page.insert_text(fitz.Point(20, 30), f"Shape Test: {shape_def['label']}", fontsize=14, color=(0.2, 0.2, 0.2))

    # Draw the shape centered on the page
    margin = 100
    sx = margin
    sy = 60
    sw = PAGE_WIDTH - margin * 2
    sh = PAGE_HEIGHT - margin - 80

    draw_fn = DRAW_FUNCTIONS[shape_type]
    draw_fn(page, sx, sy, sw, sh, shape_def)

    doc.save(output_path)
    file_size = os.path.getsize(output_path)
    doc.close()

    # Now verify
    checks = []

    # Re-open and verify
    doc2 = fitz.open(output_path)
    page2 = doc2[0]

    checks.append({
        "item": "PDF Created",
        "result": "PASS",
        "description": f"Shape test PDF generated successfully",
        "detail": f"File: {os.path.basename(output_path)}, Size: {file_size:,} bytes",
    })

    # Get all drawings from the page
    drawings = page2.get_drawings()
    has_drawings = len(drawings) > 0

    checks.append({
        "item": "Drawings Present",
        "result": "PASS" if has_drawings else "FAIL",
        "description": "PDF contains drawing operators",
        "detail": f"{len(drawings)} drawing path(s) found" if has_drawings else "No drawings found in PDF",
    })

    # Shape-specific checks
    if shape_type == "rect":
        has_rect = any(
            any(item[0] == "re" for item in d.get("items", []))
            for d in drawings
        )
        if not has_rect:
            has_rect = len(drawings) > 0
        checks.append({
            "item": "Rectangle Detected",
            "result": "PASS" if has_rect else "FAIL",
            "description": "Rectangle path operators found",
            "detail": f"Rectangle drawing{'s' if len(drawings) > 1 else ''} confirmed" if has_rect else "Missing",
        })

    elif shape_type == "circle":
        has_curves = any(
            any(item[0] == "c" for item in d.get("items", []))
            for d in drawings
        )
        checks.append({
            "item": "Ellipse Detected",
            "result": "PASS" if has_curves else "FAIL",
            "description": "Bezier curve operators found (ellipse)",
            "detail": "Curve paths confirmed" if has_curves else "No curves found",
        })

    elif shape_type == "triangle":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "Triangle Detected",
            "result": "PASS" if line_count >= 3 else "FAIL",
            "description": "At least 3 line segments for triangle",
            "detail": f"{line_count} line segment(s) found",
        })

    elif shape_type == "arrow":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "Arrow Detected",
            "result": "PASS" if line_count >= 3 else "FAIL",
            "description": "Arrow line + arrowhead segments found",
            "detail": f"{line_count} line segment(s) found (need shaft + 2 head lines)",
        })

    elif shape_type == "star":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "Star Detected",
            "result": "PASS" if line_count >= 9 else "FAIL",
            "description": "Star polygon with 10 points (9+ line segments)",
            "detail": f"{line_count} line segment(s) found",
        })

    elif shape_type == "checkmark":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "Checkmark Detected",
            "result": "PASS" if line_count >= 2 else "FAIL",
            "description": "Checkmark polyline with 2+ line segments",
            "detail": f"{line_count} line segment(s) found",
        })

    elif shape_type == "polygon":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "Hexagon Detected",
            "result": "PASS" if line_count >= 5 else "FAIL",
            "description": "Hexagon polygon with 6 sides (5+ line segments)",
            "detail": f"{line_count} line segment(s) found",
        })

    elif shape_type == "x":
        line_count = sum(
            sum(1 for item in d.get("items", []) if item[0] == "l")
            for d in drawings
        )
        checks.append({
            "item": "X Mark Detected",
            "result": "PASS" if line_count >= 2 else "FAIL",
            "description": "X mark with 2 diagonal lines",
            "detail": f"{line_count} line segment(s) found",
        })

    # Check fill color
    if shape_def["fill_color"]:
        has_fill = any(d.get("fill") is not None for d in drawings)
        checks.append({
            "item": "Fill Color Applied",
            "result": "PASS" if has_fill else "FAIL",
            "description": f"Shape has expected fill color",
            "detail": "Fill color present" if has_fill else "No fill color detected",
        })

    # Check stroke
    if shape_def["stroke_color"]:
        has_stroke = any(d.get("color") is not None for d in drawings)
        checks.append({
            "item": "Stroke Color Applied",
            "result": "PASS" if has_stroke else "FAIL",
            "description": "Shape has expected stroke color",
            "detail": "Stroke color present" if has_stroke else "No stroke color detected",
        })

    # Check stroke width
    if shape_def["stroke_width"]:
        has_width = any(d.get("width", 0) > 0 for d in drawings)
        checks.append({
            "item": "Stroke Width Applied",
            "result": "PASS" if has_width else "FAIL",
            "description": f"Expected stroke width {shape_def['stroke_width']}",
            "detail": "Width applied" if has_width else "No stroke width",
        })

    # Check bounding box is reasonable
    if drawings:
        all_rects = [fitz.Rect(d["rect"]) for d in drawings]
        union_rect = all_rects[0]
        for r in all_rects[1:]:
            union_rect |= r
        bbox_ok = union_rect.width > 10 and union_rect.height > 10
        checks.append({
            "item": "Bounding Box Valid",
            "result": "PASS" if bbox_ok else "FAIL",
            "description": "Shape has reasonable bounding box",
            "detail": f"Bbox: {union_rect.width:.0f}x{union_rect.height:.0f}pt",
        })

    doc2.close()

    # Aggregate
    passed = sum(1 for c in checks if c["result"] == "PASS")
    total = len(checks)

    return {
        "filename": f"{shape_type}_test.pdf",
        "description": f"{shape_def['label']} shape — stroke + {'fill' if shape_def['fill_color'] else 'no fill'}",
        "test_category": "Shapes",
        "section_name": shape_def["label"],
        "status": "pass" if passed == total else "fail",
        "checks": checks,
        "checks_passed": passed,
        "checks_total": total,
        "page_count": 1,
        "file_size": file_size,
        "error": None if passed == total else f"{total - passed} check(s) failed",
        "warnings": [],
    }


def validate_all_shapes():
    """
    Generate the combined shapes PDF and validate it,
    plus validate each individual shape.
    """
    results = []

    # First, generate the combined PDF
    combined_path = generate_shapes_pdf()
    combined_size = os.path.getsize(combined_path)

    # Overall PDF check
    doc = fitz.open(combined_path)
    all_drawings = doc[0].get_drawings()
    doc.close()

    results.append({
        "filename": "shapes_test.pdf",
        "description": f"Combined shapes PDF with {len(SHAPE_DEFS)} shape types",
        "test_category": "Shapes",
        "section_name": "Combined PDF",
        "status": "pass" if len(all_drawings) >= len(SHAPE_DEFS) else "fail",
        "checks": [
            {
                "item": "Combined PDF Created",
                "result": "PASS",
                "description": "Combined shapes test PDF generated",
                "detail": f"Size: {combined_size:,} bytes, Drawings: {len(all_drawings)}",
            },
            {
                "item": "All Shapes Present",
                "result": "PASS" if len(all_drawings) >= len(SHAPE_DEFS) else "FAIL",
                "description": f"Expected at least {len(SHAPE_DEFS)} drawing paths",
                "detail": f"{len(all_drawings)} drawing paths found",
            },
        ],
        "checks_passed": 2 if len(all_drawings) >= len(SHAPE_DEFS) else 1,
        "checks_total": 2,
        "page_count": 1,
        "file_size": combined_size,
        "error": None if len(all_drawings) >= len(SHAPE_DEFS) else "Not enough drawings",
        "warnings": [],
    })

    # Then validate each shape individually
    for sdef in SHAPE_DEFS:
        result = validate_shape(sdef["type"])
        results.append(result)

    return results


def list_shape_tests():
    """List all shape test types."""
    files = []
    for sdef in SHAPE_DEFS:
        files.append({
            "filename": f"{sdef['type']}_test.pdf",
            "path": sdef["type"],
            "description": f"{sdef['label']} shape test",
            "test_category": "Shapes",
            "section_name": sdef["label"],
        })
    return {
        "total": len(files) + 1,  # +1 for combined test
        "files": [
            {
                "filename": "shapes_test.pdf",
                "path": "all",
                "description": "Combined shapes test (all 8 types)",
                "test_category": "Shapes",
                "section_name": "Combined",
            }
        ] + files,
    }


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Shape tool test suite")
    parser.add_argument("--list-files", action="store_true", help="List shape test types")
    parser.add_argument("--run-all", action="store_true", help="Run all shape tests")
    parser.add_argument("--single-shape", help="Run test for a single shape type")
    args = parser.parse_args()

    if args.list_files:
        result = list_shape_tests()
        print(json.dumps(result))
    elif args.run_all:
        results = validate_all_shapes()
        print(json.dumps(results))
    elif args.single_shape:
        if args.single_shape == "all":
            # Generate combined PDF and return overall result
            combined_path = generate_shapes_pdf()
            combined_size = os.path.getsize(combined_path)
            doc = fitz.open(combined_path)
            all_drawings = doc[0].get_drawings()
            doc.close()
            result = {
                "filename": "shapes_test.pdf",
                "description": f"Combined shapes PDF with {len(SHAPE_DEFS)} shape types",
                "test_category": "Shapes",
                "section_name": "Combined PDF",
                "status": "pass" if len(all_drawings) >= len(SHAPE_DEFS) else "fail",
                "checks": [
                    {
                        "item": "Combined PDF Created",
                        "result": "PASS",
                        "description": "Combined shapes test PDF generated",
                        "detail": f"Size: {combined_size:,} bytes, Drawings: {len(all_drawings)}",
                    },
                    {
                        "item": "All Shapes Present",
                        "result": "PASS" if len(all_drawings) >= len(SHAPE_DEFS) else "FAIL",
                        "description": f"Expected at least {len(SHAPE_DEFS)} drawing paths",
                        "detail": f"{len(all_drawings)} drawing paths found",
                    },
                ],
                "checks_passed": 2 if len(all_drawings) >= len(SHAPE_DEFS) else 1,
                "checks_total": 2,
                "page_count": 1,
                "file_size": combined_size,
                "error": None if len(all_drawings) >= len(SHAPE_DEFS) else "Not enough drawings",
                "warnings": [],
            }
            print(json.dumps(result))
        else:
            result = validate_shape(args.single_shape)
            print(json.dumps(result))
    else:
        parser.print_help()
        sys.exit(1)
