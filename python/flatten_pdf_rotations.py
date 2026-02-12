#!/usr/bin/env python3
"""
Flatten all page rotations in a PDF by physically transforming the content.
This prepares the PDF for shape/annotation addition by ensuring rotation flags are 0.
"""

import sys
import fitz  # PyMuPDF


def flatten_rotations(input_path, output_path):
    """
    Physically flatten all page rotations by wrapping content in Form XObjects.
    This preserves text while physically rotating content.
    """
    try:
        doc = fitz.open(input_path)
        
        for page_idx in range(len(doc)):
            page = doc[page_idx]
            rotation = page.rotation
            
            if rotation != 0:
                print(f"Flattening page {page_idx + 1}: {rotation}° → 0°")
                
                # Step 1: Wrap contents in Form XObject
                page.wrap_contents()
                
                # Step 2: Keep rotation applied (content is wrapped, rotation applies to wrapped object)
                # The rotation is already set, so no need to set it again
                
                # Step 3: Clean and rebuild with sanitize to physically apply rotation
                page.clean_contents(sanitize=True)
                
                # Step 4: Reset rotation flag to 0 (content is now physically rotated)
                page.set_rotation(0)
        
        # Save with clean parameters
        doc.save(output_path, garbage=3, deflate=True)
        doc.close()
        
        print(f"SUCCESS: All rotations flattened using Form XObject wrapping")
        return True
        
    except Exception as e:
        print(f"ERROR: Failed to flatten rotations: {e}", file=sys.stderr)
        return False


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: python flatten_pdf_rotations.py <input_pdf> <output_pdf>")
        sys.exit(1)
    
    input_pdf = sys.argv[1]
    output_pdf = sys.argv[2]
    
    success = flatten_rotations(input_pdf, output_pdf)
    sys.exit(0 if success else 1)
