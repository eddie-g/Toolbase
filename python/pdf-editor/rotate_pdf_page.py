#!/usr/bin/env python3
"""Rotate one PDF page by updating its standard /Rotate entry.

Keeping the original page objects and coordinate system is important to the web
editor: saved annotations remain expressed in the same PDF coordinates while
PDF.js applies the page rotation to the canvas, text layer, and overlay layer.
"""

import sys
import fitz  # PyMuPDF

# Suppress MuPDF warnings/errors
fitz.TOOLS.mupdf_display_errors(False)

def rotate_pdf_page(input_path, output_path, page_number, rotation=90):
    """
    Rotate a specific page by updating its standard PDF rotation metadata.

    The page's media box and content coordinates remain unchanged. PDF viewers
    apply the rotation when rendering, which keeps editor annotations anchored
    in the same canonical PDF coordinate system.
    
    Args:
        input_path: Path to input PDF
        output_path: Path to save output PDF
        page_number: Page number to rotate (1-based)
        rotation: Rotation angle in degrees (90, 180, 270, or -90)
    """
    try:
        src_doc = fitz.open(input_path)
        total_pages = len(src_doc)
        
        # Validate page number
        if page_number < 1 or page_number > total_pages:
            print(f"Error: Page number {page_number} is out of range (1-{total_pages})", file=sys.stderr)
            src_doc.close()
            return False
        
        # Normalize rotation to 0, 90, 180, 270
        rotation = rotation % 360
        if rotation < 0:
            rotation += 360
        
        # Page index (0-based)
        page_idx = page_number - 1
        
        page = src_doc[page_idx]
        next_rotation = (int(page.rotation or 0) + rotation) % 360
        page.set_rotation(next_rotation)

        # Saving the existing document preserves page annotations, links,
        # bookmarks, forms, and the original content streams. Rebuilding pages
        # through show_pdf_page() discarded those objects and erased the
        # rotation metadata the browser needs for rotation-aware geometry.
        src_doc.save(output_path, garbage=3, deflate=True)
        src_doc.close()
        
        print(
            f"SUCCESS: Page {page_number} rotated by {rotation} degrees "
            f"(page rotation {next_rotation})"
        )
        return True
        
    except Exception as e:
        error_msg = str(e)
        if "not a dict" in error_msg or "format error" in error_msg or "syntax error" in error_msg:
            print(f"ERROR: PDF file is corrupted and cannot be rotated. Please re-upload the document.", file=sys.stderr)
        else:
            print(f"Error rotating page: {error_msg}", file=sys.stderr)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Usage: python rotate_pdf_page.py <input_pdf> <output_pdf> <page_number> [rotation_degrees]")
        print("  rotation_degrees: 90 (default), 180, 270, or -90")
        sys.exit(1)
    
    input_pdf = sys.argv[1]
    output_pdf = sys.argv[2]
    page_num = int(sys.argv[3])
    rotation_deg = int(sys.argv[4]) if len(sys.argv) > 4 else 90
    
    success = rotate_pdf_page(input_pdf, output_pdf, page_num, rotation_deg)
    sys.exit(0 if success else 1)
