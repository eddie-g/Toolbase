#!/usr/bin/env python
"""
Inspect a PDF for the "Editing existing PDF text" suite.

Reads a JSON request on stdin and writes a JSON report on stdout. The runner
has no PDF reader of its own; PyMuPDF is what the app itself uses to write the
file, so the check reads it back with the same library.

Request:
  {
    "pdf": "/path/to/file.pdf",
    "summary": true,                         # per-page text/blocks/fonts/images/widgets/hash
    "queries": [
      {"kind": "search", "page": 0, "text": "..."},
      {"kind": "pixels", "page": 0, "rect": [x0, y0, x1, y1], "zoom": 4},
      {"kind": "chars", "page": 0, "rect": [x0, y0, x1, y1]},
      {"kind": "rules", "page": 0, "rect": [x0, y0, x1, y1]}
    ]
  }
"""
import hashlib
import json
import sys

import fitz


def rect_list(rect):
    return [round(float(rect.x0), 3), round(float(rect.y0), 3), round(float(rect.x1), 3), round(float(rect.y1), 3)]


def page_summary(page):
    blocks = []
    for block in page.get_text("dict", sort=True).get("blocks", []):
        if block.get("type") != 0:
            continue
        lines = []
        for line in block.get("lines", []):
            spans = []
            for span in line.get("spans", []):
                text = span.get("text", "")
                if not text.strip():
                    continue
                spans.append({
                    "text": text,
                    "font": span.get("font", ""),
                    "size": round(float(span.get("size", 0)), 3),
                    "flags": int(span.get("flags", 0)),
                    "color": int(span.get("color", 0)),
                    "bbox": [round(float(v), 3) for v in span.get("bbox", [0, 0, 0, 0])],
                })
            if spans:
                lines.append({
                    "text": "".join(s["text"] for s in spans),
                    "bbox": [round(float(v), 3) for v in line.get("bbox", [0, 0, 0, 0])],
                    "spans": spans,
                })
        if lines:
            blocks.append({
                "text": " ".join(l["text"] for l in lines),
                "bbox": [round(float(v), 3) for v in block.get("bbox", [0, 0, 0, 0])],
                "lines": lines,
            })
    fonts = sorted({(f[3], f[2]) for f in page.get_fonts()})
    widgets = []
    for widget in page.widgets() or []:
        widgets.append({
            "name": widget.field_name,
            "type": widget.field_type_string,
            "value": widget.field_value,
            "rect": rect_list(widget.rect),
        })
    try:
        contents = page.read_contents() or b""
    except Exception:  # pragma: no cover - defensive
        contents = b""
    text = page.get_text("text")
    return {
        "index": page.number,
        "rect": rect_list(page.rect),
        "text": text,
        "text_hash": hashlib.sha256(text.encode("utf-8")).hexdigest(),
        "content_hash": hashlib.sha256(contents).hexdigest(),
        "content_length": len(contents),
        "blocks": blocks,
        "fonts": [{"name": name, "type": kind} for name, kind in fonts],
        "image_count": len(page.get_images(full=True)),
        "widgets": widgets,
        "has_notdef": "�" in text,
    }


def pixels(page, rect, zoom):
    clip = fitz.Rect(*rect) & page.rect
    if clip.is_empty:
        return {"error": "empty clip"}
    pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=clip, alpha=False)
    samples = pix.samples
    channels = max(1, int(pix.n))
    total = max(1, pix.width * pix.height)
    dark = nonwhite = light = 0
    for offset in range(0, len(samples) - channels + 1, channels):
        r, g, b = samples[offset], samples[offset + 1], samples[offset + 2]
        luma = (0.299 * r) + (0.587 * g) + (0.114 * b)
        if luma < 100:
            dark += 1
        if not (r > 245 and g > 245 and b > 245):
            nonwhite += 1
        if luma > 200:
            light += 1
    return {
        "rect": rect_list(clip),
        "width": pix.width,
        "height": pix.height,
        "dark_ratio": round(dark / total, 5),
        "nonwhite_ratio": round(nonwhite / total, 5),
        "light_ratio": round(light / total, 5),
    }


def chars(page, rect):
    clip = fitz.Rect(*rect)
    out = []
    for block in page.get_text("rawdict", clip=clip).get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    c = ch.get("c", "")
                    bbox = ch.get("bbox", [0, 0, 0, 0])
                    # Only glyphs whose centre lies inside the rect: a clip also
                    # returns neighbours whose ascent or descent merely brushes it.
                    if not clip.contains(fitz.Point((bbox[0] + bbox[2]) / 2, (bbox[1] + bbox[3]) / 2)):
                        continue
                    out.append({
                        "c": c,
                        "font": span.get("font", ""),
                        "size": round(float(span.get("size", 0)), 3),
                        "color": int(span.get("color", 0)),
                        "bbox": [round(float(v), 3) for v in ch.get("bbox", [0, 0, 0, 0])],
                    })
    text = "".join(ch["c"] for ch in out)
    return {
        "text": text,
        "count": len(out),
        "notdef": sum(1 for ch in out if ch["c"] == "�"),
        "fonts": sorted({ch["font"] for ch in out}),
        "sizes": sorted({ch["size"] for ch in out}),
        "colors": sorted({ch["color"] for ch in out}),
        "chars": out[:400],
    }


def rules(page, rect):
    """Horizontal rules (thin lines / flat rects) that cross the given rect."""
    clip = fitz.Rect(*rect)
    segments = []
    for drawing in page.get_drawings() or []:
        width = float(drawing.get("width") or 0.0)
        for item in drawing.get("items") or []:
            if not item:
                continue
            if item[0] == "l":
                a, b = item[1], item[2]
                if abs(float(a.y) - float(b.y)) > 0.6:
                    continue
                x0, x1 = sorted((float(a.x), float(b.x)))
                y = (float(a.y) + float(b.y)) / 2.0
            elif item[0] == "re":
                r = fitz.Rect(item[1])
                if float(r.height) > 1.5 or float(r.width) < 4:
                    continue
                x0, x1, y = float(r.x0), float(r.x1), (float(r.y0) + float(r.y1)) / 2.0
                width = max(width, float(r.height))
            else:
                continue
            if x1 - x0 < 4 or y < clip.y0 or y > clip.y1 or x1 < clip.x0 or x0 > clip.x1:
                continue
            segments.append({"x0": round(x0, 3), "x1": round(x1, 3), "y": round(y, 3), "width": round(width, 3)})
    return {"count": len(segments), "segments": segments[:100]}


def main():
    request = json.load(sys.stdin)
    doc = fitz.open(request["pdf"])
    report = {"page_count": doc.page_count, "pages": [], "queries": []}
    if request.get("summary"):
        for page in doc:
            report["pages"].append(page_summary(page))
    for query in request.get("queries", []):
        kind = query.get("kind")
        page = doc[int(query.get("page", 0))]
        if kind == "search":
            needle = str(query.get("text") or "").strip()
            hits = page.search_for(needle) if needle else []
            report["queries"].append({"kind": kind, "text": query.get("text"), "count": len(hits), "rects": [rect_list(r) for r in hits]})
        elif kind == "pixels":
            report["queries"].append({"kind": kind, "rect": query.get("rect"), **pixels(page, query["rect"], float(query.get("zoom", 4)))})
        elif kind == "chars":
            report["queries"].append({"kind": kind, "rect": query.get("rect"), **chars(page, query["rect"])})
        elif kind == "rules":
            report["queries"].append({"kind": kind, "rect": query.get("rect"), **rules(page, query["rect"])})
        else:
            report["queries"].append({"kind": kind, "error": "unknown query"})
    doc.close()
    json.dump(report, sys.stdout)


if __name__ == "__main__":
    main()
