#!/usr/bin/env python3
"""
Rotate a specific page in a PDF document by physically transforming the content.
Uses show_pdf_page() to ensure text is preserved and content is actually rotated.
"""

import sys
import pymupdf
import tempfile
import os

# Suppress MuPDF warnings/errors
pymupdf.TOOLS.mupdf_display_errors(False)

def rotate_pdf_page(input_path, output_path, page_number, rotation=90):
    """
    Rotate a specific page by physically transforming content using show_pdf_page.
    This preserves text and actually rotates the content (not just setting a flag).
    
    Args:
        input_path: Path to input PDF
        output_path: Path to save output PDF
        page_number: Page number to rotate (1-based)
        rotation: Rotation angle in degrees (90, 180, 270, or -90)
    """
    try:
        src_doc = pymupdf.open(input_path)
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
        
        # Create new document with rotated pages
        dst_doc = pymupdf.open()
        
        for idx in range(total_pages):
            src_page = src_doc[idx]
            
            if idx == page_idx and rotation != 0:
                # Calculate new dimensions based on rotation
                if rotation in (90, 270):
                    # Swap width and height
                    new_width = src_page.rect.height
                    new_height = src_page.rect.width
                else:
                    # 180 degrees - keep same dimensions
                    new_width = src_page.rect.width
                    new_height = src_page.rect.height
                
                # Create new page with appropriate dimensions
                dst_page = dst_doc.new_page(width=new_width, height=new_height)
                
                # Use show_pdf_page with rotation to physically rotate content
                dst_page.show_pdf_page(
                    dst_page.rect,
                    src_doc,
                    idx,
                    rotate=rotation
                )
            else:
                # Copy page as-is
                dst_page = dst_doc.new_page(width=src_page.rect.width, height=src_page.rect.height)
                dst_page.show_pdf_page(
                    dst_page.rect,
                    src_doc,
                    idx,
                    rotate=0
                )
        
        # Save with garbage collection and compression
        dst_doc.save(output_path, garbage=3, deflate=True)
        dst_doc.close()
        src_doc.close()
        
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
    rotation_deg = int(sys.argv[4]) if len(sys.argv) > 4 else 90
    
    success = rotate_pdf_page(input_pdf, output_pdf, page_num, rotation_deg)
    sys.exit(0 if success else 1)
