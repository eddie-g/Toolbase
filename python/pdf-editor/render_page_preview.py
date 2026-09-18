"""Render the first page of a PDF to a JPEG, for the document list thumbnails.

usage: render_page_preview.py SRC.pdf DEST.jpg TARGET_WIDTH QUALITY
prints WIDTHxHEIGHT of the rendered image.
"""
import sys

import fitz


def main() -> int:
    src, dest = sys.argv[1], sys.argv[2]
    target_width = max(int(sys.argv[3]), 64)
    quality = max(int(sys.argv[4]), 20)
    doc = fitz.open(src)
    if doc.page_count < 1:
        raise RuntimeError("Document has no pages")
    page = doc.load_page(0)
    rect = page.rect
    scale = float(target_width) / max(float(rect.width), 1.0)
    pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
    pix.save(dest, output="jpeg", jpg_quality=quality)
    print(f"{pix.width}x{pix.height}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
