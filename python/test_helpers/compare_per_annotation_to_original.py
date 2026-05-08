#!/usr/bin/env python3
"""
compare_per_annotation_to_original.py

For a given document's annotations list, iterate one annotation at a time:
  - Apply ONLY that annotation onto a fresh copy of the clean PDF using
    new_annotation_writer.apply_annotations
  - Render the annotation's bbox region from BOTH (rendered) and (original PDF)
  - Pixel-diff with strict threshold

Outputs a per-annotation JSON report and (on mismatch) PNG crops to OUTPUT_DIR.

Usage:
    python compare_per_annotation_to_original.py \
        --clean-pdf  /tmp/doc4021_clean.pdf \
        --original   /tmp/doc4021_orig.pdf \
        --annotations-json /tmp/doc4021_anns.json \
        --output-dir /tmp/doc4021_per_ann \
        [--start 0] [--stop 154] [--dpi 200] \
        [--diff-threshold 4] [--fail-fast]
"""

import argparse
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


def _load_writer(path: pathlib.Path, name: str):
    if str(PDF_EDITOR) not in sys.path:
        sys.path.insert(0, str(PDF_EDITOR))
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def _render_crop(pdf_path: str, page_index: int, bbox_pts, dpi: int, pad_pts: float = 4.0):
    """Return RGB numpy array of the bbox region of the page at given DPI."""
    doc = fitz.open(pdf_path)
    try:
        page = doc[page_index]
        page_rect = page.rect
        x0, y0, x1, y1 = bbox_pts
        x0 = max(0.0, x0 - pad_pts)
        y0 = max(0.0, y0 - pad_pts)
        x1 = min(page_rect.width, x1 + pad_pts)
        y1 = min(page_rect.height, y1 + pad_pts)
        clip = fitz.Rect(x0, y0, x1, y1)
        mat = fitz.Matrix(dpi / 72.0, dpi / 72.0)
        pix = page.get_pixmap(matrix=mat, colorspace=fitz.csRGB, alpha=False, clip=clip)
        arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, 3).copy()
        return arr, (x0, y0, x1, y1)
    finally:
        doc.close()


def _ann_bbox_pdf_coords(ann: dict):
    """Return (x0, y0, x1, y1) in PyMuPDF (top-left origin) page coordinates."""
    pdfX = float(ann.get("pdfX") or 0)
    pdfY = float(ann.get("pdfY") or 0)
    pdfW = float(ann.get("pdfWidth") or 0)
    pdfH = float(ann.get("pdfHeight") or 0)
    page_h = float(ann.get("sourcePageHeight") or 0)
    if pdfW <= 0 or pdfH <= 0 or page_h <= 0:
        # Fall back to source block
        sL = float(ann.get("sourceBlockLeft") or 0)
        sT = float(ann.get("sourceBlockTop") or 0)
        sW = float(ann.get("sourceBlockWidth") or 0)
        sH = float(ann.get("sourceBlockHeight") or 0)
        return (sL, sT, sL + sW, sT + sH)
    # PDF coords are bottom-left; convert to top-left
    top = page_h - (pdfY + pdfH)
    return (pdfX, top, pdfX + pdfW, top + pdfH)


def _diff(a: np.ndarray, b: np.ndarray, threshold: int):
    h = max(a.shape[0], b.shape[0])
    w = max(a.shape[1], b.shape[1])

    def _pad(x):
        if x.shape[:2] == (h, w):
            return x
        c = np.full((h, w, 3), 255, dtype=np.uint8)
        c[: x.shape[0], : x.shape[1]] = x
        return c

    aa = _pad(a)
    bb = _pad(b)
    diff_raw = np.abs(aa.astype(np.int16) - bb.astype(np.int16))
    max_per_px = diff_raw.max(axis=2)
    diff_mask = max_per_px > threshold
    total = h * w
    pct = float(diff_mask.sum()) / total * 100.0 if total else 0.0
    vis = np.full((h, w, 3), 255, dtype=np.uint8)
    vis[diff_mask] = [220, 50, 50]
    return pct, int(max_per_px.max()), int(total), vis, aa, bb


def _save_png(arr: np.ndarray, path: str):
    h, w = arr.shape[:2]
    pix = fitz.Pixmap(fitz.csRGB, w, h, arr.tobytes(), False)
    pix.save(path)


def _apply_one(writer_module, clean_pdf: str, ann: dict) -> str:
    fd, tmp = tempfile.mkstemp(suffix=".pdf", prefix="ann_")
    os.close(fd)
    shutil.copy2(clean_pdf, tmp)
    writer_module.apply_annotations(tmp, [ann])
    return tmp


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--clean-pdf", required=True)
    p.add_argument("--original", required=True)
    p.add_argument("--annotations-json", required=True)
    p.add_argument("--output-dir", required=True)
    p.add_argument("--start", type=int, default=0)
    p.add_argument("--stop", type=int, default=None)
    p.add_argument("--dpi", type=int, default=200)
    p.add_argument("--diff-threshold", type=int, default=4)
    p.add_argument("--max-pct", type=float, default=0.0,
                   help="Max diff%% considered a pass; >0 treats small areas as pass")
    p.add_argument("--fail-fast", action="store_true")
    p.add_argument("--include-types", default="text",
                   help="Comma-separated annotation types to include (default text)")
    p.add_argument("--ann-id", default=None,
                   help="Run only the annotation with this id")
    p.add_argument("--document-id", type=int, default=None,
                   help="Inject __documentId into each annotation so the writer can resolve embedded fonts.")
    args = p.parse_args()

    out_dir = pathlib.Path(args.output_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    with open(args.annotations_json, "r", encoding="utf-8") as fh:
        annotations = json.load(fh)

    include_types = {t.strip() for t in args.include_types.split(",") if t.strip()}

    writer = _load_writer(NEW_WRITER, "new_writer")

    stop = args.stop if args.stop is not None else len(annotations)
    results = []
    failures = []

    for idx in range(args.start, min(stop, len(annotations))):
        ann = annotations[idx]
        ann_type = str(ann.get("type") or "text")
        ann_id = str(ann.get("id") or idx)
        if include_types and ann_type not in include_types:
            continue
        if args.ann_id is not None and ann_id != args.ann_id:
            continue
        if args.document_id is not None:
            ann = dict(ann)
            ann.setdefault("__documentId", args.document_id)
            ann.setdefault("documentId", args.document_id)
        page_index = int(ann.get("pageIndex") or 0)
        bbox = _ann_bbox_pdf_coords(ann)

        tmp_pdf = None
        try:
            tmp_pdf = _apply_one(writer, args.clean_pdf, ann)
            rendered, used_bbox = _render_crop(tmp_pdf, page_index, bbox, args.dpi)
            original, _ = _render_crop(args.original, page_index, bbox, args.dpi)
            pct, max_diff, total, diff_vis, ra, oa = _diff(rendered, original, args.diff_threshold)
            ok = (pct <= args.max_pct)
            r = {
                "index": idx,
                "id": ann_id,
                "type": ann_type,
                "page": page_index,
                "bbox": list(bbox),
                "diff_pct": round(pct, 4),
                "max_diff": max_diff,
                "pixels": total,
                "match": ok,
                "text_preview": (ann.get("text") or "").replace("\n", " / ")[:80],
            }
            results.append(r)
            if not ok:
                base = out_dir / f"ann_{idx:03d}_{ann_id}"
                _save_png(ra, f"{base}_rendered.png")
                _save_png(oa, f"{base}_original.png")
                _save_png(diff_vis, f"{base}_diff.png")
                failures.append(r)
                print(f"[FAIL] idx={idx} id={ann_id} page={page_index} diff={pct:.3f}%% max={max_diff} text={r['text_preview']!r}")
                if args.fail_fast:
                    break
            else:
                print(f"[ ok ] idx={idx} id={ann_id} page={page_index} diff={pct:.4f}%% max={max_diff}")
        except Exception as exc:
            import traceback
            print(f"[ERR ] idx={idx} id={ann_id}: {exc}")
            traceback.print_exc()
            results.append({"index": idx, "id": ann_id, "error": str(exc)})
            if args.fail_fast:
                break
        finally:
            if tmp_pdf and os.path.exists(tmp_pdf):
                try:
                    os.unlink(tmp_pdf)
                except OSError:
                    pass

    summary = {
        "total": len(results),
        "matched": sum(1 for r in results if r.get("match")),
        "failed": len(failures),
        "results": results,
    }
    (out_dir / "summary.json").write_text(json.dumps(summary, indent=2))
    print()
    print(f"== Done. {summary['matched']}/{summary['total']} matched. {summary['failed']} failures. ==")
    print(f"Report: {out_dir / 'summary.json'}")
    return 0 if summary["failed"] == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
