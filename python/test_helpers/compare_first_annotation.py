#!/usr/bin/env python3
"""
compare_first_annotation.py

Takes the FIRST annotation from a document and applies it to the clean/base PDF
using BOTH the reference writer (apply_annotations_direct_new.py) AND the new
writer (new_annotation_writer.py), then compares the rendered page images
pixel-by-pixel.  The two outputs must be EXACTLY identical.

Usage:
    python compare_first_annotation.py \
        --clean-pdf /path/to/clean.pdf \
        --annotations-json /path/to/annotations.json \
        [--dpi 150] \
        [--output-dir /path/to/save/pngs]

Output (stdout): JSON:
    {
        "success": true,
        "annotation_index": 0,
        "annotation_type": "text",
        "page_index": 0,
        "match": true,
        "pixel_diff_pct": 0.0,
        "max_pixel_diff": 0,
        "pixels_checked": ...,
        "image_reference":  "<base64 PNG>",
        "image_new_writer": "<base64 PNG>",
        "image_diff":       "<base64 PNG or null>"
    }
"""

import argparse
import base64
import importlib.util
import json
import os
import pathlib
import shutil
import sys
import tempfile

import fitz
import numpy as np

SCRIPT_DIR   = pathlib.Path(__file__).resolve().parent
PDF_EDITOR   = SCRIPT_DIR.parent / "pdf-editor"
NEW_WRITER   = PDF_EDITOR / "new_annotation_writer.py"
REF_WRITER   = PDF_EDITOR / "apply_annotations_direct_new.py"
CONTRACT_DIR = str(PDF_EDITOR)


def _load_writer(path: pathlib.Path, module_name: str):
    if CONTRACT_DIR not in sys.path:
        sys.path.insert(0, CONTRACT_DIR)
    spec = importlib.util.spec_from_file_location(module_name, path)
    mod  = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def _render_page_png(pdf_path: str, page_index: int, dpi: int) -> bytes:
    doc = fitz.open(pdf_path)
    try:
        page = doc[page_index]
        mat  = fitz.Matrix(dpi / 72.0, dpi / 72.0)
        pix  = page.get_pixmap(matrix=mat, colorspace=fitz.csRGB, alpha=False)
        return pix.tobytes("png")
    finally:
        doc.close()


def _png_to_b64(png_bytes: bytes) -> str:
    return base64.b64encode(png_bytes).decode("ascii")


def _decode_png(raw: bytes):
    pix = fitz.Pixmap(raw)
    if pix.n != 3:
        pix = fitz.Pixmap(fitz.csRGB, pix)
    arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3)
    return arr.copy()


def _compare_images(png_a: bytes, png_b: bytes, threshold: int = 4):
    arr_a = _decode_png(png_a)
    arr_b = _decode_png(png_b)
    ha, wa = arr_a.shape[:2]
    hb, wb = arr_b.shape[:2]
    h, w   = max(ha, hb), max(wa, wb)
    if arr_a.shape[:2] != (h, w):
        c = np.full((h, w, 3), 255, dtype=np.uint8); c[:ha, :wa] = arr_a; arr_a = c
    if arr_b.shape[:2] != (h, w):
        c = np.full((h, w, 3), 255, dtype=np.uint8); c[:hb, :wb] = arr_b; arr_b = c
    diff_raw   = np.abs(arr_a.astype(np.int16) - arr_b.astype(np.int16))
    max_per_px = diff_raw.max(axis=2)
    diff_mask  = max_per_px > threshold
    total_px   = h * w
    diff_pct   = float(diff_mask.sum()) / total_px * 100.0 if total_px else 0.0
    max_diff   = int(max_per_px.max())
    vis = np.full((h, w, 3), 255, dtype=np.uint8)
    vis[diff_mask] = [220, 50, 50]
    diff_png = fitz.Pixmap(fitz.csRGB, w, h, vis.tobytes(), False).tobytes("png")
    return diff_pct, max_diff, total_px, diff_png


def _apply_single(writer_module, clean_pdf: str, annotation: dict) -> str:
    fd, tmp = tempfile.mkstemp(suffix=".pdf")
    os.close(fd)
    shutil.copy2(clean_pdf, tmp)
    writer_module.apply_annotations(tmp, [annotation])
    return tmp


def compare(clean_pdf: str, annotations: list, dpi: int = 150, output_dir=None) -> dict:
    if not annotations:
        return {"success": False, "error": "No annotations provided"}
    first_ann  = annotations[0]
    page_index = int(first_ann.get("pageIndex") or 0)
    ann_type   = str(first_ann.get("type") or "text")
    ref_mod = _load_writer(REF_WRITER, "reference_writer")
    new_mod = _load_writer(NEW_WRITER, "new_annotation_writer")
    tmp_ref = tmp_new = None
    try:
        tmp_ref = _apply_single(ref_mod, clean_pdf, first_ann)
        tmp_new = _apply_single(new_mod, clean_pdf, first_ann)
        png_ref = _render_page_png(tmp_ref, page_index, dpi)
        png_new = _render_page_png(tmp_new, page_index, dpi)
        diff_pct, max_diff, total_px, diff_png = _compare_images(png_ref, png_new)
        match = diff_pct == 0.0
        result = {
            "success": True,
            "annotation_index": 0,
            "annotation_type":  ann_type,
            "page_index":       page_index,
            "match":            match,
            "pixel_diff_pct":   round(diff_pct, 4),
            "max_pixel_diff":   max_diff,
            "pixels_checked":   total_px,
            "image_reference":  _png_to_b64(png_ref),
            "image_new_writer": _png_to_b64(png_new),
            "image_diff":       _png_to_b64(diff_png) if not match else None,
        }
        if output_dir:
            os.makedirs(output_dir, exist_ok=True)
            for name, data in [("reference.png", png_ref), ("new_writer.png", png_new), ("diff.png", diff_png)]:
                pathlib.Path(output_dir, name).write_bytes(data)
        return result
    except Exception as exc:
        import traceback
        return {"success": False, "error": str(exc), "traceback": traceback.format_exc()}
    finally:
        for p in (tmp_ref, tmp_new):
            if p:
                try: os.unlink(p)
                except OSError: pass


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--clean-pdf",        required=True)
    parser.add_argument("--annotations-json", required=True)
    parser.add_argument("--dpi",              type=int, default=150)
    parser.add_argument("--output-dir",       default=None)
    args = parser.parse_args()
    for path in (args.clean_pdf, args.annotations_json):
        if not os.path.exists(path):
            sys.stdout.write(json.dumps({"success": False, "error": f"File not found: {path}"}))
            return 1
    with open(args.annotations_json, "r", encoding="utf-8") as fh:
        annotations = json.load(fh)
    if not isinstance(annotations, list):
        sys.stdout.write(json.dumps({"success": False, "error": "annotations JSON must be an array"}))
        return 1
    result = compare(clean_pdf=args.clean_pdf, annotations=annotations, dpi=args.dpi, output_dir=args.output_dir)
    sys.stdout.write(json.dumps(result, ensure_ascii=False))
    return 0 if result.get("success") else 1


if __name__ == "__main__":
    raise SystemExit(main())
