#!/usr/bin/env python3
"""
Reorder PDF pages based on provided order array.
Usage: python reorder_pdf_pages.py /path/to/input.pdf /path/to/output.pdf [page_order]
Example: python reorder_pdf_pages.py input.pdf output.pdf "2,0,1" (moves page 3 to first, page 1 to second, page 2 to third)
"""

import sys
import json
import pymupdf as fitz
import tempfile
import os

# Suppress MuPDF warnings/errors
fitz.TOOLS.mupdf_display_errors(False)

def reorder_pdf_pages(input_path, output_path, page_order):
    """
    Reorder pages in a PDF file.
    
    Args:
        input_path: Path to input PDF file
        output_path: Path to save reordered PDF
        page_order: List of page indices in new order (0-based)
    
    Returns:
        dict with success status and message
    """
    normalized_path = None
    try:
        # Open the input PDF directly without normalization step
        pdf_document = fitz.open(input_path)
        total_pages = len(pdf_document)
        
        # Validate page order - allow fewer pages (for deletion) but not more
        if len(page_order) > total_pages:
            pdf_document.close()
            return {
                'success': False,
                'error': f'Page order length ({len(page_order)}) exceeds PDF page count ({total_pages})'
            }
        
        # Check all page indices are valid and within range
        for page_idx in page_order:
            if page_idx < 0 or page_idx >= total_pages:
                pdf_document.close()
                return {
                    'success': False,
                    'error': f'Invalid page index {page_idx}. Must be between 0 and {total_pages - 1}'
                }
        
        # Check for duplicate indices
        if len(page_order) != len(set(page_order)):
            pdf_document.close()
            return {
                'success': False,
                'error': 'Page order contains duplicate page indices'
            }
        
        # Create a new PDF document
        output_pdf = fitz.open()
        
        # Add pages in the new order
        for page_idx in page_order:
            output_pdf.insert_pdf(pdf_document, from_page=page_idx, to_page=page_idx)
        
        # Save the reordered PDF — avoid clean=True which crashes on pdf-lib PDFs
        output_pdf.save(output_path, garbage=3, deflate=True)
        output_pdf.close()
        pdf_document.close()
        
        return {
            'success': True,
            'message': f'Successfully reordered {total_pages} pages',
            'total_pages': total_pages
        }
        
    except Exception as e:
        return {
            'success': False,
            'error': str(e)
        }

if __name__ == '__main__':
    if len(sys.argv) < 4:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python reorder_pdf_pages.py <input_pdf> <output_pdf> <page_order>'
        }))
        sys.exit(1)
    
    input_path = sys.argv[1]
    output_path = sys.argv[2]
    page_order_str = sys.argv[3]
    
    # Parse page order (comma-separated indices)
    try:
        page_order = [int(x) for x in page_order_str.split(',')]
    except ValueError:
        print(json.dumps({
            'success': False,
            'error': 'Invalid page order format. Use comma-separated integers.'
        }))
        sys.exit(1)
    
    # Reorder the pages
    result = reorder_pdf_pages(input_path, output_path, page_order)
    print(json.dumps(result))
    
    # Exit with appropriate code
    sys.exit(0 if result['success'] else 1)
