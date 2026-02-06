#!/usr/bin/env python3
"""
Rotate a specific page in a PDF document by 90 degrees clockwise.
"""

import sys
import pymupdf
import tempfile
import os

# Suppress MuPDF warnings/errors
pymupdf.TOOLS.mupdf_display_errors(False)

def rotate_pdf_page(input_path, output_path, page_number, rotation=90):
    """
    Rotate a specific page in a PDF by actually transforming the content.
    
    Args:
        input_path: Path to input PDF
        output_path: Path to save output PDF
        page_number: Page number to rotate (1-based)
        rotation: Rotation angle in degrees (90, 180, 270, or -90)
    """
    try:
        doc = pymupdf.open(input_path)
        total_pages = len(doc)
        
        # Validate page number
        if page_number < 1 or page_number > total_pages:
            print(f"Error: Page number {page_number} is out of range (1-{total_pages})", file=sys.stderr)
            doc.close()
            return False
        
        # Page index (0-based)
        page_idx = page_number - 1
        page = doc[page_idx]
        
        # Get current rotation and calculate new rotation
        current_rotation = page.rotation
        new_rotation = (current_rotation + rotation) % 360
        
        # Create a new page with rotated dimensions
        old_rect = page.rect
        if new_rotation % 180 == 90:  # 90 or 270 degrees
            new_width = old_rect.height
            new_height = old_rect.width
        else:
            new_width = old_rect.width
            new_height = old_rect.height
        
        # Create a new document with a blank page of the new size
        new_doc = pymupdf.open()
        new_page = new_doc.new_page(width=new_width, height=new_height)
        
        # Show the old page on the new page with rotation
        # This actually transforms the content stream coordinates
        new_page.show_pdf_page(new_page.rect, doc, page_idx, rotate=rotation)
        
        # Replace the old page with the new rotated page
        doc.delete_page(page_idx)
        doc.insert_pdf(new_doc, from_page=0, to_page=0, start_at=page_idx)
        
        new_doc.close()
        
        # Save with de-segmentation to fix structural issues
        # expand=True uncompresses streams to fix structural errors
        # garbage=4 removes all orphaned/corrupt objects
        # deflate=True re-compresses for the final file
        # clean=True performs a final check on the content tree
        doc.save(output_path, expand=True, garbage=4, deflate=True, clean=True)
        doc.close()
        
        print(f"SUCCESS: Page {page_number} rotated by {rotation} degrees")
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
    rotation = int(sys.argv[4]) if len(sys.argv) > 4 else 90
    
    success = rotate_pdf_page(input_pdf, output_pdf, page_num, rotation)
    sys.exit(0 if success else 1)
