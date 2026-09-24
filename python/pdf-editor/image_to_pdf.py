"""One-page PDF holding a single image, for opening a generated image in the
PDF editor. The page takes the image's proportions with its long side at
LONG_SIDE_PT points (US Letter width), so a 1024 px logo lands on an
8.5-inch page rather than a 14-inch one.

Usage: image_to_pdf.py <image: png|jpg|webp|svg> <output.pdf>
"""
import sys

import fitz  # PyMuPDF

LONG_SIDE_PT = 612.0


def main(source: str, target: str) -> int:
    image = fitz.open(source)
    # Raster and SVG alike: PyMuPDF opens both as a one-page document.
    image_pdf = fitz.open("pdf", image.convert_to_pdf())
    width, height = image_pdf[0].rect.width, image_pdf[0].rect.height
    if width <= 0 or height <= 0:
        print("image has no size", file=sys.stderr)
        return 1

    scale = LONG_SIDE_PT / max(width, height)
    out = fitz.open()
    page = out.new_page(width=width * scale, height=height * scale)
    # Drawn as a page (not a bitmap stamp), so an SVG stays vector.
    page.show_pdf_page(page.rect, image_pdf, 0)
    out.save(target, garbage=3, deflate=True)
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        sys.exit(2)
    sys.exit(main(sys.argv[1], sys.argv[2]))
