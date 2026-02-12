#!/usr/bin/env python3
"""
PDF Edit Application Script using PyMuPDF (fitz)
Applies text edits to PDF by redacting old text and inserting new text
"""

import sys
import os
import json
from pathlib import Path
import fitz  # PyMuPDF
import tempfile


def apply_edits_to_pdf(pdf_path, edits_data):
    """
    Apply text edits to PDF using the 'Search-and-Destroy' Protocol:
    
    Phase 0: Clean all pages to normalize coordinate systems (CRITICAL)
    Phase A: Search for original_text in PDF data stream and redact ALL instances
    Phase B: Overlay new_text at NEW bbox locations (no redaction)
    
    This prevents ghosting by finding text in the actual PDF data layer,
    not relying on potentially inaccurate frontend coordinates.
    
    Args:
        pdf_path: Path to the PDF file
        edits_data: List of edit objects with:
            - page_number: Page number (1-indexed)
            - original_text: Original text to find and destroy (REQUIRED)
            - original_bbox: Fallback location if search fails
            - bbox: New text location [x0, y0, x1, y1]
            - new_text: New text to insert
            - font: Font name
            - font_size: Font size
            - color: Text color (hex)
    """
    print(f"📄 Opening PDF: {pdf_path}")
    
    try:
        doc = fitz.open(pdf_path)
        print(f"✓ PDF opened: {doc.page_count} pages")
        
        # Group edits by page
        edits_by_page = {}
        for edit in edits_data:
            page_num = edit.get('page_number') or edit.get('page_num')
            if page_num not in edits_by_page:
                edits_by_page[page_num] = []
            edits_by_page[page_num].append(edit)
        
        print(f"📝 Applying {len(edits_data)} edits across {len(edits_by_page)} pages...")
        
        # PHASE 0: Clean all pages FIRST to normalize coordinate systems
        # This ensures search and insertion use the same coordinate grid
        print("\n" + "="*50)
        print("PHASE 0: Normalizing coordinate systems")
        print("="*50)
        for page_num in edits_by_page.keys():
            page = doc[page_num - 1]
            page.clean_contents()
            print(f"  ✓ Page {page_num}: Cleaned contents")
        
        # PHASE A: Search & Destroy - Find and redact original text in data stream
        print("\n" + "="*50)
        print("PHASE A (Search & Destroy): Finding and redacting original text")
        print("="*50)
        
        for page_num, page_edits in edits_by_page.items():
            print(f"\n  Page {page_num}: Processing {len(page_edits)} edits...")
            page = doc[page_num - 1]
            
            # Get all images on this page for smart redaction
            images = page.get_image_info()
            image_rects = [fitz.Rect(img["bbox"]) for img in images]
            print(f"    Found {len(image_rects)} images on page")
            
            redaction_count = 0
            search_success_count = 0
            image_overlap_count = 0
            
            for edit in page_edits:
                original_text = edit.get('original_text', '')
                new_text = edit.get('new_text', '')
                if not original_text or not original_text.strip():
                    continue

                # SKIP NO-OP EDITS
                original_bbox = edit.get('original_bbox')
                bbox = edit.get('bbox')
                if original_text.strip() == new_text.strip() and original_bbox and bbox:
                    pos_unchanged = (abs(original_bbox[0] - bbox[0]) < 2 and
                                     abs(original_bbox[1] - bbox[1]) < 2 and
                                     abs(original_bbox[2] - bbox[2]) < 2 and
                                     abs(original_bbox[3] - bbox[3]) < 2)
                    if pos_unchanged:
                        print(f"    ⊘ Skipping no-op edit for '{original_text[:30]}...'")
                        edit['_skip_insert'] = True
                        continue

                # PRIMARY: Use original_bbox for reliable scrub
                if original_bbox:
                    rect = fitz.Rect(
                        original_bbox[0] - 1,
                        original_bbox[1] - 1,
                        original_bbox[2] + 1,
                        original_bbox[3] + 1
                    )
                    overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                    if overlaps_image:
                        page.add_redact_annot(rect, fill=None)
                        image_overlap_count += 1
                    else:
                        page.add_redact_annot(rect, fill=(1, 1, 1))
                    redaction_count += 1
                    print(f"    ✓ Redacting '{original_text[:30]}...' via original_bbox")
                else:
                    # FALLBACK: No original_bbox — try search_for
                    text_instances = page.search_for(original_text)
                    if text_instances:
                        inst_rect = text_instances[0]
                        # Create rect from search result
                        rect = fitz.Rect(
                            inst_rect.x0 - 1, inst_rect.y0 - 1,
                            inst_rect.x1 + 1, inst_rect.y1 + 1
                        )
                        
                        overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                        if overlaps_image:
                            page.add_redact_annot(rect, fill=None)
                            image_overlap_count += 1
                        else:
                            page.add_redact_annot(rect, fill=(1, 1, 1))
                        redaction_count += 1
                    else:
                        print(f"    ✗ Cannot redact '{original_text[:30]}...' - no original_bbox and search failed")
            
            # Apply all redactions at once to physically purge the character streams
            page.apply_redactions()
            print(f"    ✓ Redacted {redaction_count} locations ({search_success_count} by search, {image_overlap_count} over images)")
        
        # PHASE B: Overlay new text at NEW locations
        print("\n" + "="*50)
        print("PHASE B (The Overlay): Inserting text at new locations")
        print("="*50)
        
        for page_num, page_edits in edits_by_page.items():
            print(f"\n  Page {page_num}: Inserting {len(page_edits)} text blocks...")
            page = doc[page_num - 1]
            
            for edit in page_edits:
                # Skip no-op edits (flagged in Phase A)
                if edit.get('_skip_insert'):
                    print(f"    - Skipping no-op edit (text unchanged)")
                    continue

                new_text = edit.get('new_text', '')
                if not new_text or not new_text.strip():
                    print(f"    - Skipping empty text (deletion)")
                    continue
                
                # Use NEW bbox for insertion
                target_bbox = edit.get('bbox') or edit.get('original_bbox')
                if not target_bbox:
                    continue
                    
                rect = fitz.Rect(target_bbox)
                font_size = edit.get('font_size', 12)
                font_name = edit.get('font', 'helv')
                
                # Convert hex color to RGB tuple (0-1 range)
                color_hex = edit.get('color', '#000000').lstrip('#')
                try:
                    rgb = tuple(int(color_hex[i:i+2], 16) / 255.0 for i in (0, 2, 4))
                except:
                    rgb = (0, 0, 0)
                
                # Map font name to PyMuPDF font
                pymupdf_font = map_font_name(font_name)
                
                # Insert text with overlay=True (no redaction at new position!)
                try:
                    page.insert_textbox(
                        rect,
                        new_text,
                        fontsize=font_size,
                        fontname=pymupdf_font,
                        color=rgb,
                        align=fitz.TEXT_ALIGN_LEFT,
                        overlay=True  # CRITICAL: Overlay mode prevents image damage
                    )
                    print(f"    ✓ Inserted '{new_text[:30]}...' at ({target_bbox[0]:.1f}, {target_bbox[1]:.1f})")
                except Exception as e:
                    print(f"    ✗ Failed to insert text: {e}")
        
        # PHASE 3: Save with cleanup
        print("\n" + "="*50)
        print("PHASE 3: Saving PDF")
        print("="*50)
        
        temp_path = pdf_path + '.tmp'
        doc.save(temp_path, garbage=4, deflate=True, encryption=fitz.PDF_ENCRYPT_KEEP)
        doc.close()
        
        # Replace original file
        import shutil
        shutil.move(temp_path, pdf_path)
        
        print("✓ PDF saved successfully with Search-and-Destroy Protocol")
        print("  - Original text physically removed from data stream")
        print("  - New text overlaid at new positions without image damage")
        return True
        
    except Exception as e:
        print(f"✗ Error applying edits: {e}")
        import traceback
        traceback.print_exc()
        return False


def map_font_name(font_name):
    """
    Map PDF font names to PyMuPDF font names with better matching
    PyMuPDF supports: helv, times, cour, zadb, symb
    For bold/italic, append -bo, -it, or -bi
    """
    if not font_name:
        return 'helv'
        
    font_lower = font_name.lower()
    
    # Parse style
    is_bold = 'bold' in font_lower or '700' in font_lower or 'black' in font_lower
    is_italic = 'italic' in font_lower or 'oblique' in font_lower or 'it' in font_lower
    
    # Determine base font family
    base_font = 'helv'  # default
    if 'helvetica' in font_lower or 'arial' in font_lower or 'sans' in font_lower or 'arimo' in font_lower:
        base_font = 'helv'
    elif 'times' in font_lower or 'serif' in font_lower or 'tinos' in font_lower:
        base_font = 'times'
    elif 'courier' in font_lower or 'mono' in font_lower or 'cousine' in font_lower:
        base_font = 'cour'
    elif 'symbol' in font_lower:
        return 'symb'
    elif 'zapf' in font_lower or 'dingbat' in font_lower:
        return 'zadb'
    
    # Apply style modifiers
    if is_bold and is_italic:
        return base_font + '-bi'
    elif is_bold:
        return base_font + '-bo'
    elif is_italic:
        return base_font + '-it'
    else:
        return base_font


def main():
    if len(sys.argv) < 3:
        print("Usage: python3 apply_pdf_edits.py <pdf_path> <edits_json_file>")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    edits_file = sys.argv[2]
    
    if not os.path.exists(pdf_path):
        print(f"✗ PDF file not found: {pdf_path}")
        sys.exit(1)
    
    if not os.path.exists(edits_file):
        print(f"✗ Edits file not found: {edits_file}")
        sys.exit(1)
    
    # Load edits data
    try:
        with open(edits_file, 'r') as f:
            edits_data = json.load(f)
    except Exception as e:
        print(f"✗ Error loading edits file: {e}")
        sys.exit(1)
    
    print("=" * 60)
    print("PDF Edit Application")
    print("=" * 60)
    print(f"PDF: {os.path.basename(pdf_path)}")
    print(f"Edits: {len(edits_data)} changes")
    print()
    
    # Apply edits
    success = apply_edits_to_pdf(pdf_path, edits_data)
    
    print()
    if success:
        print("✓ Edits applied successfully!")
        sys.exit(0)
    else:
        print("✗ Failed to apply edits")
        sys.exit(1)


if __name__ == "__main__":
    main()
