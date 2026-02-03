#!/usr/bin/env python3
"""
Extract font files from a PDF for web use
"""

import sys
import json
import fitz  # PyMuPDF
import base64
import tempfile
import os


def extract_fonts_from_pdf(pdf_path):
    """
    Extract all fonts from a PDF and return them as base64-encoded data
    
    Returns:
        List of font objects with:
        - name: Font name
        - base64: Base64-encoded font file data
        - format: Font format (ttf, otf, etc.)
    """
    try:
        doc = fitz.open(pdf_path)
        fonts = []
        seen_fonts = set()
        
        for page_num in range(len(doc)):
            page = doc[page_num]
            page_fonts = page.get_fonts(full=True)
            
            for font_info in page_fonts:
                xref, ext, ftype, basefont, name, encoding = font_info[:6]
                
                # Skip if we've already extracted this font
                font_key = f"{name}_{basefont}"
                if font_key in seen_fonts:
                    continue
                seen_fonts.add(font_key)
                
                try:
                    # Try to extract the font file
                    font_buffer = doc.extract_font(xref)
                    
                    if font_buffer and font_buffer[0]:
                        # font_buffer is tuple: (basename, ext, type, buffer)
                        font_basename, font_ext, font_type, font_data = font_buffer
                        
                        if font_data:
                            # Convert to base64
                            font_b64 = base64.b64encode(font_data).decode('utf-8')
                            
                            # Determine format
                            font_format = 'truetype'  # default
                            if font_ext == 'otf':
                                font_format = 'opentype'
                            elif font_ext == 'woff':
                                font_format = 'woff'
                            elif font_ext == 'woff2':
                                font_format = 'woff2'
                            
                            fonts.append({
                                'name': name or basefont,
                                'basefont': basefont,
                                'base64': font_b64,
                                'format': font_format,
                                'extension': font_ext or 'ttf',
                                'xref': xref
                            })
                except Exception as e:
                    # Skip fonts that can't be extracted
                    continue
        
        doc.close()
        return fonts
        
    except Exception as e:
        print(f"Error extracting fonts: {e}", file=sys.stderr)
        return []


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'PDF path required'}))
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    
    if not os.path.exists(pdf_path):
        print(json.dumps({'error': 'PDF file not found'}))
        sys.exit(1)
    
    fonts = extract_fonts_from_pdf(pdf_path)
    print(json.dumps({'fonts': fonts}))
