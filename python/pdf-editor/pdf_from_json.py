#!/usr/bin/env python3
"""
Create a PDF from extracted JSON data.
Takes JSON containing text extraction data (lines with spans) and recreates
a PDF with proper text positioning, fonts, and colors.
"""

import sys
import os
import json
import fitz  # PyMuPDF
from pathlib import Path


def hex_to_rgb_float(hex_color):
    """Convert hex color string to RGB float tuple (0-1 range)."""
    if not hex_color or not hex_color.startswith('#'):
        return (0, 0, 0)  # Default to black
    
    hex_color = hex_color.lstrip('#')
    if len(hex_color) == 6:
        r = int(hex_color[0:2], 16) / 255.0
        g = int(hex_color[2:4], 16) / 255.0
        b = int(hex_color[4:6], 16) / 255.0
        return (r, g, b)
    return (0, 0, 0)


def find_system_font_file(font_name, bold=False, italic=False):
    """
    Try to locate a system font file.
    Returns path to font file or None.
    """
    # Common Linux font directories
    font_dirs = [
        '/usr/share/fonts',
        '/usr/local/share/fonts',
        '~/.fonts',
        '~/.local/share/fonts',
    ]
    
    # Expand user paths
    font_dirs = [os.path.expanduser(d) for d in font_dirs]
    
    # Build search patterns
    font_lower = font_name.lower()
    patterns = []
    
    # Map common fonts to Linux alternatives
    if 'verdana' in font_lower or 'arial' in font_lower or 'helvetica' in font_lower:
        # Map to Liberation Sans or DejaVu Sans
        if bold and italic:
            patterns = [
                'LiberationSans-BoldItalic.ttf',
                'DejaVuSans-BoldOblique.ttf',
                'NotoSans-BoldItalic.ttf'
            ]
        elif bold:
            patterns = [
                'LiberationSans-Bold.ttf',
                'DejaVuSans-Bold.ttf',
                'NotoSans-Bold.ttf'
            ]
        elif italic:
            patterns = [
                'LiberationSans-Italic.ttf',
                'DejaVuSans-Oblique.ttf',
                'NotoSans-Italic.ttf'
            ]
        else:
            patterns = [
                'LiberationSans-Regular.ttf',
                'DejaVuSans.ttf',
                'NotoSans-Regular.ttf'
            ]
    elif 'times' in font_lower:
        # Map to Liberation Serif or DejaVu Serif
        if bold and italic:
            patterns = [
                'LiberationSerif-BoldItalic.ttf',
                'DejaVuSerif-BoldItalic.ttf',
                'NotoSerif-BoldItalic.ttf'
            ]
        elif bold:
            patterns = [
                'LiberationSerif-Bold.ttf',
                'DejaVuSerif-Bold.ttf',
                'NotoSerif-Bold.ttf'
            ]
        elif italic:
            patterns = [
                'LiberationSerif-Italic.ttf',
                'DejaVuSerif-Italic.ttf',
                'NotoSerif-Italic.ttf'
            ]
        else:
            patterns = [
                'LiberationSerif-Regular.ttf',
                'DejaVuSerif.ttf',
                'NotoSerif-Regular.ttf'
            ]
    elif 'courier' in font_lower:
        # Map to Liberation Mono or DejaVu Sans Mono
        if bold and italic:
            patterns = [
                'LiberationMono-BoldItalic.ttf',
                'DejaVuSansMono-BoldOblique.ttf'
            ]
        elif bold:
            patterns = [
                'LiberationMono-Bold.ttf',
                'DejaVuSansMono-Bold.ttf'
            ]
        elif italic:
            patterns = [
                'LiberationMono-Italic.ttf',
                'DejaVuSansMono-Oblique.ttf'
            ]
        else:
            patterns = [
                'LiberationMono-Regular.ttf',
                'DejaVuSansMono.ttf'
            ]
    else:
        # Generic fallback patterns - try Liberation Sans
        if bold and italic:
            patterns = ['LiberationSans-BoldItalic.ttf', 'DejaVuSans-BoldOblique.ttf']
        elif bold:
            patterns = ['LiberationSans-Bold.ttf', 'DejaVuSans-Bold.ttf']
        elif italic:
            patterns = ['LiberationSans-Italic.ttf', 'DejaVuSans-Oblique.ttf']
        else:
            patterns = ['LiberationSans-Regular.ttf', 'DejaVuSans.ttf']
    
    # Search for font file
    for font_dir in font_dirs:
        if not os.path.exists(font_dir):
            continue
        for pattern in patterns:
            for root, dirs, files in os.walk(font_dir):
                for filename in files:
                    if filename == pattern:
                        return os.path.join(root, filename)
    
    return None


def get_base_font(font_name, bold=False, italic=False):
    """Get PDF base font code as fallback."""
    font_lower = font_name.lower() if font_name else ''
    
    if 'times' in font_lower:
        if bold and italic:
            return 'tibi'
        elif bold:
            return 'tibo'
        elif italic:
            return 'tiit'
        return 'tiro'
    elif 'courier' in font_lower:
        if bold and italic:
            return 'cobi'
        elif bold:
            return 'cobo'
        elif italic:
            return 'coit'
        return 'cour'
    else:
        # Default to Helvetica
        if bold and italic:
            return 'hvbi'
        elif bold:
            return 'hvb'
        elif italic:
            return 'hvio'
        return 'helv'


def create_pdf_from_json(json_data, output_path, page_width=595.27, page_height=841.89):
    """
    Create a PDF from JSON extraction data.
    
    Args:
        json_data: Dictionary containing 'lines' array with text positioning
        output_path: Path where the PDF will be saved
        page_width: Page width in points (default: A4 width)
        page_height: Page height in points (default: A4 height)
    """
    print(f"📄 Creating PDF from JSON data")
    
    # Create a new PDF document
    doc = fitz.open()
    
    # Create a new page (A4 size by default)
    page = doc.new_page(width=page_width, height=page_height)
    
    # Cache for registered fonts
    font_cache = {}
    font_stats = {'custom': 0, 'fallback': 0}
    
    lines = json_data.get('lines', [])
    print(f"  Processing {len(lines)} lines")
    
    # Process each line
    for line_idx, line in enumerate(lines):
        spans = line.get('spans', [])
        
        # Process each span within the line
        for span in spans:
            text = span.get('text', '').strip()
            if not text:
                continue
            
            # Get positioning from origin (baseline position)
            origin = span.get('origin', [0, 0])
            x = float(origin[0])
            y = float(origin[1])
            
            # Get font properties
            font_name = span.get('font', 'Verdana')
            font_size = float(span.get('font_size', 12))
            bold = span.get('bold', False)
            italic = span.get('italic', False)
            
            # Get color
            hex_color = span.get('hex_color', '#000000')
            color = hex_to_rgb_float(hex_color)
            
            # Create font cache key
            font_key = f"{font_name}_{bold}_{italic}"
            
            # Try to use custom font if available
            custom_font_name = None
            if font_key not in font_cache:
                font_file = find_system_font_file(font_name, bold, italic)
                if font_file:
                    try:
                        # Register the font with PyMuPDF
                        custom_font_name = f"F{len(font_cache)}"
                        page.insert_font(fontname=custom_font_name, fontfile=font_file)
                        font_cache[font_key] = custom_font_name
                    except Exception as e:
                        font_cache[font_key] = None
                else:
                    font_cache[font_key] = None
            else:
                custom_font_name = font_cache[font_key]
            
            # Insert text with custom font or fallback to base font
            inserted = False
            
            if custom_font_name:
                try:
                    page.insert_text(
                        (x, y),
                        text,
                        fontname=custom_font_name,
                        fontsize=font_size,
                        color=color
                    )
                    inserted = True
                    font_stats['custom'] += 1
                except Exception as e:
                    pass
            
            # Fallback to base font
            if not inserted:
                base_font = get_base_font(font_name, bold, italic)
                try:
                    page.insert_text(
                        (x, y),
                        text,
                        fontname=base_font,
                        fontsize=font_size,
                        color=color
                    )
                    inserted = True
                    font_stats['fallback'] += 1
                except Exception as e:
                    print(f"  ⚠ Warning: Could not insert '{text[:20]}...': {e}")
    
    # Print font statistics
    print(f"\n  Font usage:")
    print(f"    System fonts: {font_stats['custom']} spans")
    print(f"    Fallback fonts: {font_stats['fallback']} spans")
    print(f"    Unique fonts registered: {len([k for k,v in font_cache.items() if v is not None])}")
    
    # Save the PDF
    try:
        doc.save(output_path, garbage=3, deflate=True)
        doc.close()
        print(f"✓ PDF created successfully: {output_path}")
        return True
    except Exception as e:
        print(f"✗ Error saving PDF: {e}")
        doc.close()
        return False


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 pdf_from_json.py <json_file> [output_pdf]")
        print("\nExample:")
        print("  python3 pdf_from_json.py page_2_doc_469.json")
        print("  python3 pdf_from_json.py page_2_doc_469.json output.pdf")
        sys.exit(1)
    
    json_file = sys.argv[1]
    
    # Default output path: same name with .pdf extension in same directory
    if len(sys.argv) >= 3:
        output_path = sys.argv[2]
    else:
        base_name = os.path.splitext(json_file)[0]
        output_path = f"{base_name}.pdf"
    
    if not os.path.exists(json_file):
        print(f"✗ JSON file not found: {json_file}")
        sys.exit(1)
    
    # Load JSON data
    try:
        with open(json_file, 'r', encoding='utf-8') as f:
            json_data = json.load(f)
    except Exception as e:
        print(f"✗ Error loading JSON file: {e}")
        sys.exit(1)
    
    print("=" * 60)
    print("PDF Generation from JSON")
    print("=" * 60)
    print(f"Input JSON: {os.path.basename(json_file)}")
    print(f"Output PDF: {os.path.basename(output_path)}")
    print()
    
    # Create PDF
    success = create_pdf_from_json(json_data, output_path)
    
    print()
    if success:
        print("✓ PDF generated successfully!")
        print(f"  Output: {output_path}")
        
        # Print file size
        if os.path.exists(output_path):
            size_kb = os.path.getsize(output_path) / 1024
            print(f"  Size: {size_kb:.1f} KB")
        
        sys.exit(0)
    else:
        print("✗ Failed to generate PDF")
        sys.exit(1)


if __name__ == "__main__":
    main()
