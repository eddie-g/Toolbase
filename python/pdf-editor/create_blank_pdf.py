"""Create a one-page blank PDF.

usage: create_blank_pdf.py DEST.pdf WIDTH_PT HEIGHT_PT
"""
import sys

import fitz


def main() -> int:
    dest = sys.argv[1]
    width = float(sys.argv[2])
    height = float(sys.argv[3])
    doc = fitz.open()
    doc.new_page(width=width, height=height)
    doc.save(dest)
    doc.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
