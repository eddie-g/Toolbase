#!/usr/bin/env python3
"""Create editable PDF annotations from an uploaded image.

The default mode reconstructs a blank PDF from detected OCR text and simple
layout shapes. Image-backed mode is available as an exact visual fallback.
"""

from __future__ import annotations

import argparse
import io
import json
import math
import os
from collections import defaultdict
from typing import Any

import fitz
from PIL import Image


PAGE_SIZES = {
    "letter": (612.0, 792.0),
    "a4": (595.28, 841.89),
    "legal": (612.0, 1008.0),
}


def page_dimensions(page_size: str, image_width: int, image_height: int) -> tuple[float, float]:
    normalized = (page_size or "letter").strip().lower()
    if normalized == "source":
        # Treat the source image as 96 DPI and cap extremely large pages.
        width = max(144.0, min(1224.0, image_width * 72.0 / 96.0))
        height = max(144.0, min(1584.0, image_height * 72.0 / 96.0))
        return width, height

    width, height = PAGE_SIZES.get(normalized, PAGE_SIZES["letter"])
    if image_width > image_height and height > width:
        width, height = height, width
    return width, height


def fit_mapping(page_width: float, page_height: float, image_width: int, image_height: int) -> dict[str, float]:
    scale = min(page_width / image_width, page_height / image_height)
    rendered_width = image_width * scale
    rendered_height = image_height * scale
    return {
        "scale": scale,
        "offsetX": (page_width - rendered_width) / 2.0,
        "offsetTop": (page_height - rendered_height) / 2.0,
        "renderedWidth": rendered_width,
        "renderedHeight": rendered_height,
    }


def project_rect(rect: tuple[float, float, float, float], page_height: float, mapping: dict[str, float]) -> dict[str, float]:
    x, y, w, h = rect
    scale = mapping["scale"]
    pdf_x = mapping["offsetX"] + x * scale
    top = mapping["offsetTop"] + y * scale
    pdf_w = max(1.0, w * scale)
    pdf_h = max(1.0, h * scale)
    return {
        "pdfX": round(pdf_x, 3),
        "pdfY": round(page_height - top - pdf_h, 3),
        "pdfWidth": round(pdf_w, 3),
        "pdfHeight": round(pdf_h, 3),
        "top": round(top, 3),
    }


def create_image_pdf(output_pdf: str, image: Image.Image, page_width: float, page_height: float, mapping: dict[str, float]) -> None:
    os.makedirs(os.path.dirname(output_pdf), exist_ok=True)
    doc = fitz.open()
    page = doc.new_page(width=page_width, height=page_height)
    raster = image
    if raster.mode in {"RGBA", "LA"} or (raster.mode == "P" and "transparency" in raster.info):
        matte = Image.new("RGB", raster.size, "white")
        matte.paste(raster.convert("RGBA"), mask=raster.convert("RGBA").getchannel("A"))
        raster = matte
    else:
        raster = raster.convert("RGB")
    stream = io.BytesIO()
    raster.save(stream, format="PNG")
    rect = fitz.Rect(
        mapping["offsetX"],
        mapping["offsetTop"],
        mapping["offsetX"] + mapping["renderedWidth"],
        mapping["offsetTop"] + mapping["renderedHeight"],
    )
    page.insert_image(rect, stream=stream.getvalue(), keep_proportion=False, overlay=True)
    doc.save(output_pdf)
    doc.close()


def create_blank_pdf(output_pdf: str, page_width: float, page_height: float) -> None:
    os.makedirs(os.path.dirname(output_pdf), exist_ok=True)
    doc = fitz.open()
    doc.new_page(width=page_width, height=page_height)
    doc.save(output_pdf)
    doc.close()


def rgb_to_hex(color: tuple[int, int, int]) -> str:
    return "#{:02x}{:02x}{:02x}".format(
        max(0, min(255, int(color[0]))),
        max(0, min(255, int(color[1]))),
        max(0, min(255, int(color[2]))),
    )


def is_background_like(color: tuple[int, int, int]) -> bool:
    r, g, b = color
    return r >= 246 and g >= 246 and b >= 246


def estimate_text_color(image: Image.Image, box: tuple[float, float, float, float]) -> str:
    x, y, w, h = box
    left = max(0, int(math.floor(x)))
    top = max(0, int(math.floor(y)))
    right = min(image.width, int(math.ceil(x + w)))
    bottom = min(image.height, int(math.ceil(y + h)))
    if right <= left or bottom <= top:
        return "#111827"

    crop = image.convert("RGB").crop((left, top, right, bottom))
    thumb = crop.resize((max(1, min(24, crop.width)), max(1, min(12, crop.height))))
    buckets: dict[tuple[int, int, int], int] = defaultdict(int)
    for r, g, b in thumb.getdata():
        lum = (r * 0.299) + (g * 0.587) + (b * 0.114)
        if 45 <= lum <= 235:
            buckets[(int(round(r / 16) * 16), int(round(g / 16) * 16), int(round(b / 16) * 16))] += 1

    if not buckets:
        return "#111827"

    return rgb_to_hex(max(buckets.items(), key=lambda item: item[1])[0])


def extract_text(image: Image.Image, page_height: float, mapping: dict[str, float]) -> tuple[list[dict[str, Any]], list[str]]:
    warnings: list[str] = []
    try:
        import pytesseract
        from pytesseract import Output
    except Exception as exc:  # pragma: no cover - depends on host packages.
        return [], [f"OCR unavailable: {exc}"]

    try:
        data = pytesseract.image_to_data(image, output_type=Output.DICT, config="--psm 6")
    except Exception as exc:  # pragma: no cover - depends on tesseract binary.
        return [], [f"OCR failed: {exc}"]

    lines: dict[tuple[int, int, int, int], list[dict[str, Any]]] = defaultdict(list)
    count = len(data.get("text", []))
    for index in range(count):
        text = str(data["text"][index] or "").strip()
        if not text:
            continue
        try:
            conf = float(data.get("conf", ["-1"])[index])
        except Exception:
            conf = -1.0
        if conf >= 0 and conf < 35:
            continue

        key = (
            int(data.get("page_num", [1])[index]),
            int(data.get("block_num", [0])[index]),
            int(data.get("par_num", [0])[index]),
            int(data.get("line_num", [0])[index]),
        )
        lines[key].append(
            {
                "text": text,
                "x": float(data["left"][index]),
                "y": float(data["top"][index]),
                "w": float(data["width"][index]),
                "h": float(data["height"][index]),
                "conf": conf,
            }
        )

    extracted: list[dict[str, Any]] = []
    for key in sorted(lines):
        words = sorted(lines[key], key=lambda item: item["x"])
        text = " ".join(word["text"] for word in words).strip()
        if not text:
            continue
        x1 = min(word["x"] for word in words)
        y1 = min(word["y"] for word in words)
        x2 = max(word["x"] + word["w"] for word in words)
        y2 = max(word["y"] + word["h"] for word in words)
        projected = project_rect((x1, y1, x2 - x1, y2 - y1), page_height, mapping)
        font_size = max(6.0, min(72.0, (y2 - y1) * mapping["scale"] * 0.82))
        extracted.append(
            {
                "text": text,
                **projected,
                "fontSize": round(font_size, 2),
                "textColor": estimate_text_color(image, (x1, y1, x2 - x1, y2 - y1)),
            }
        )

    return extracted, warnings


def groups_from_bool(values: list[bool], min_len: int = 1) -> list[tuple[int, int]]:
    groups: list[tuple[int, int]] = []
    start: int | None = None
    for index, value in enumerate(values + [False]):
        if value and start is None:
            start = index
        elif not value and start is not None:
            if index - start >= min_len:
                groups.append((start, index))
            start = None
    return groups


def shape_annotation(
    rect: tuple[float, float, float, float],
    page_height: float,
    mapping: dict[str, float],
    color: str,
) -> dict[str, Any]:
    return {
        "kind": "rect",
        **project_rect(rect, page_height, mapping),
        "strokeColor": color,
        "fillColor": color,
        "strokeWidth": 1.0,
        "strokeOpacity": 1.0,
        "fillOpacity": 1.0,
    }


def detect_shapes_pil(image: Image.Image, page_height: float, mapping: dict[str, float], max_shapes: int) -> tuple[list[dict[str, Any]], list[str]]:
    warnings: list[str] = []
    if max_shapes <= 0:
        return [], warnings

    source = image.convert("RGB")
    scale_down = 1.0
    max_side = 900
    if max(source.size) > max_side:
        scale_down = max_side / float(max(source.size))
        source = source.resize(
            (max(1, int(source.width * scale_down)), max(1, int(source.height * scale_down))),
            Image.Resampling.BILINEAR,
        )

    quantized = source.quantize(colors=36, method=Image.Quantize.MEDIANCUT)
    palette = quantized.getpalette() or []
    palette_colors: dict[int, tuple[int, int, int]] = {}
    for index in range(0, min(len(palette), 256 * 3), 3):
        palette_colors[index // 3] = (palette[index], palette[index + 1], palette[index + 2])

    width, height = quantized.size
    if hasattr(quantized, "get_flattened_data"):
        data = list(quantized.get_flattened_data())
    else:
        data = list(quantized.getdata())
    visited = bytearray(len(data))
    shapes: list[dict[str, Any]] = []
    min_component_area = max(18, int(width * height * 0.00018))

    for start, color_index in enumerate(data):
        if visited[start]:
            continue

        color = palette_colors.get(int(color_index), (255, 255, 255))
        if is_background_like(color):
            visited[start] = 1
            continue

        stack = [start]
        visited[start] = 1
        area = 0
        min_x = width
        min_y = height
        max_x = 0
        max_y = 0

        while stack:
            pos = stack.pop()
            y, x = divmod(pos, width)
            area += 1
            min_x = min(min_x, x)
            min_y = min(min_y, y)
            max_x = max(max_x, x)
            max_y = max(max_y, y)

            for nxt in (pos - 1, pos + 1, pos - width, pos + width):
                if nxt < 0 or nxt >= len(data) or visited[nxt] or data[nxt] != color_index:
                    continue
                nx = nxt % width
                if abs(nx - x) > 1:
                    continue
                visited[nxt] = 1
                stack.append(nxt)

        box_w = max_x - min_x + 1
        box_h = max_y - min_y + 1
        if area < min_component_area:
            continue

        fill_ratio = area / float(max(1, box_w * box_h))
        rect_source = (
            min_x / scale_down,
            min_y / scale_down,
            box_w / scale_down,
            box_h / scale_down,
        )
        projected = project_rect(rect_source, page_height, mapping)
        rendered_area = max(1.0, mapping["renderedWidth"] * mapping["renderedHeight"])
        page_area = projected["pdfWidth"] * projected["pdfHeight"]
        saturation = max(color) - min(color)
        is_line = projected["pdfWidth"] >= 20 and projected["pdfHeight"] <= 4
        is_panel = fill_ratio >= 0.55 and page_area / rendered_area >= 0.0009
        is_colored_panel = is_panel and (saturation >= 14 or max(color) <= 235)

        if not is_line and not is_colored_panel:
            continue
        if projected["pdfWidth"] < 3 or projected["pdfHeight"] < 1:
            continue

        shapes.append(shape_annotation(rect_source, page_height, mapping, rgb_to_hex(color)))
        if len(shapes) >= max_shapes:
            warnings.append(f"Shape reconstruction capped at {max_shapes} objects.")
            break

    unique: list[dict[str, Any]] = []
    for shape in sorted(shapes, key=lambda item: (-(item["pdfWidth"] * item["pdfHeight"]), item["pdfY"], item["pdfX"])):
        duplicate = False
        for existing in unique:
            same_color = existing.get("fillColor") == shape.get("fillColor")
            close_pos = (
                abs(existing["pdfX"] - shape["pdfX"]) < 2
                and abs(existing["pdfY"] - shape["pdfY"]) < 2
                and abs(existing["pdfWidth"] - shape["pdfWidth"]) < 3
                and abs(existing["pdfHeight"] - shape["pdfHeight"]) < 3
            )
            if same_color and close_pos:
                duplicate = True
                break
        if not duplicate:
            unique.append(shape)

    return unique[:max_shapes], warnings


def detect_shapes(image: Image.Image, page_height: float, mapping: dict[str, float], max_shapes: int) -> tuple[list[dict[str, Any]], list[str]]:
    warnings: list[str] = []
    if max_shapes <= 0:
        return [], warnings

    try:
        import numpy as np
    except Exception as exc:  # pragma: no cover - depends on host packages.
        shapes, pil_warnings = detect_shapes_pil(image, page_height, mapping, max_shapes)
        pil_warnings.insert(0, f"Using built-in shape reconstruction because NumPy is unavailable: {exc}")
        return shapes, pil_warnings

    source = image.convert("RGB")
    scale_down = 1.0
    max_side = 1600
    if max(source.size) > max_side:
        scale_down = max_side / float(max(source.size))
        source = source.resize((max(1, int(source.width * scale_down)), max(1, int(source.height * scale_down))))

    arr = np.asarray(source).astype("int16")
    height, width = arr.shape[:2]
    r = arr[:, :, 0]
    g = arr[:, :, 1]
    b = arr[:, :, 2]
    gray = (r * 0.299 + g * 0.587 + b * 0.114)

    shapes: list[dict[str, Any]] = []

    red_mask = (r > 150) & (r > g * 1.25) & (r > b * 1.25) & ((r - np.minimum(g, b)) > 45)
    red_rows = (red_mask.mean(axis=1) > 0.025).tolist()
    for row_start, row_end in groups_from_bool(red_rows, 2):
        row_mask = red_mask[row_start:row_end, :]
        red_cols = (row_mask.mean(axis=0) > 0.12).tolist()
        for col_start, col_end in groups_from_bool(red_cols, 4):
            w = (col_end - col_start) / scale_down
            h = (row_end - row_start) / scale_down
            projected = project_rect((col_start / scale_down, row_start / scale_down, w, h), page_height, mapping)
            if not (
                (projected["pdfWidth"] >= 20 and projected["pdfHeight"] >= 8)
                or (projected["pdfWidth"] >= 3 and projected["pdfHeight"] >= 30)
            ):
                continue
            shapes.append(
                shape_annotation((col_start / scale_down, row_start / scale_down, w, h), page_height, mapping, "#ef3b35")
            )

    dark_mask = gray < 80
    dark_rows = (dark_mask.mean(axis=1) > 0.18).tolist()
    for row_start, row_end in groups_from_bool(dark_rows, 1):
        if len(shapes) >= max_shapes:
            break
        row_mask = dark_mask[row_start:row_end, :]
        dark_cols = (row_mask.mean(axis=0) > 0.45).tolist()
        for col_start, col_end in groups_from_bool(dark_cols, 8):
            w = (col_end - col_start) / scale_down
            h = max(1.0, (row_end - row_start) / scale_down)
            if w < 12 or h > 20:
                continue
            rect = (col_start / scale_down, row_start / scale_down, w, h)
            shapes.append(shape_annotation(rect, page_height, mapping, "#111827"))

    # Barcode-like vertical runs: tall narrow black components, capped.
    dark_cols = (dark_mask.mean(axis=0) > 0.055).tolist()
    for col_start, col_end in groups_from_bool(dark_cols, 1):
        if len(shapes) >= max_shapes:
            break
        col_mask = dark_mask[:, col_start:col_end]
        dark_by_row = (col_mask.mean(axis=1) > 0.45).tolist()
        for row_start, row_end in groups_from_bool(dark_by_row, 16):
            w = (col_end - col_start) / scale_down
            h = (row_end - row_start) / scale_down
            if w > 10 or h < 18:
                continue
            rect = (col_start / scale_down, row_start / scale_down, max(1.0, w), h)
            shapes.append(shape_annotation(rect, page_height, mapping, "#111827"))

    if len(shapes) < max_shapes:
        general_shapes, general_warnings = detect_shapes_pil(image, page_height, mapping, max_shapes - len(shapes))
        shapes.extend(general_shapes)
        warnings.extend(general_warnings)

    # Deduplicate overlapping rectangles caused by multiple simple detectors.
    unique: list[dict[str, Any]] = []
    seen: set[tuple[int, int, int, int, str]] = set()
    for shape in shapes:
        key = (
            int(round(shape["pdfX"])),
            int(round(shape["pdfY"])),
            int(round(shape["pdfWidth"])),
            int(round(shape["pdfHeight"])),
            str(shape["fillColor"]),
        )
        if key in seen:
            continue
        seen.add(key)
        unique.append(shape)
        if len(unique) >= max_shapes:
            warnings.append(f"Shape detection capped at {max_shapes} objects.")
            break

    return unique, warnings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True)
    parser.add_argument("--output-pdf", required=True)
    parser.add_argument("--page-size", default="letter")
    parser.add_argument("--max-shapes", type=int, default=260)
    parser.add_argument("--mode", choices=["reconstruct", "image-backed"], default="reconstruct")
    args = parser.parse_args()

    image = Image.open(args.image)
    image.load()
    image_width, image_height = image.size
    page_width, page_height = page_dimensions(args.page_size, image_width, image_height)
    mapping = fit_mapping(page_width, page_height, image_width, image_height)

    if args.mode == "image-backed":
        create_image_pdf(args.output_pdf, image, page_width, page_height, mapping)
    else:
        create_blank_pdf(args.output_pdf, page_width, page_height)

    text, text_warnings = extract_text(image, page_height, mapping)
    if args.mode == "image-backed":
        shapes, shape_warnings = [], []
    else:
        shapes, shape_warnings = detect_shapes(image, page_height, mapping, max(0, args.max_shapes))

    result = {
        "page": {
            "width": round(page_width, 3),
            "height": round(page_height, 3),
            "imageWidth": image_width,
            "imageHeight": image_height,
            **{key: round(value, 6) for key, value in mapping.items()},
        },
        "mode": args.mode,
        "text": text,
        "shapes": shapes,
        "warnings": text_warnings + shape_warnings,
    }
    print(json.dumps(result, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
