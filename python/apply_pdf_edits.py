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
                if not original_text or not original_text.strip():
                    continue
                
                # SEARCH & DESTROY: Let PyMuPDF find the text in the data stream
                # This is more reliable than using frontend coordinates
                text_instances = page.search_for(original_text)
                
                # CRITICAL: Filter search results to only the instance at the correct
                # position. search_for() returns ALL instances on the page.
                # For table data, the same value can appear in multiple cells.
                original_bbox = edit.get('original_bbox')
                if text_instances and original_bbox and len(text_instances) > 1:
                    ob = fitz.Rect(original_bbox)
                    def rect_overlap_score(r):
                        overlap = r & ob
                        if overlap.is_empty:
                            cx1, cy1 = (r.x0 + r.x1) / 2, (r.y0 + r.y1) / 2
                            cx2, cy2 = (ob.x0 + ob.x1) / 2, (ob.y0 + ob.y1) / 2
                            return -((cx1 - cx2)**2 + (cy1 - cy2)**2)
                        return overlap.get_area()
                    best = max(text_instances, key=rect_overlap_score)
                    print(f"    ℹ Filtered {len(text_instances)} instances → 1 nearest original_bbox")
                    text_instances = [best]
                
                if text_instances:
                    print(f"    Found {len(text_instances)} instance(s) of '{original_text[:30]}...'")
                    search_success_count += 1
                    
                    for inst_rect in text_instances:
                        # Expand slightly to ensure complete coverage
                        expanded_rect = fitz.Rect(
                            inst_rect.x0 - 1,
                            inst_rect.y0 - 1,
                            inst_rect.x1 + 1,
                            inst_rect.y1 + 1
                        )
                        
                        # IMAGE-AWARE REDACTION:
                        # Check if this instance overlaps an image
                        overlaps_image = any(expanded_rect.intersects(img_rect) for img_rect in image_rects)
                        
                        if overlaps_image:
                            # Over image: Remove text WITHOUT painting white rectangle
                            page.add_redact_annot(expanded_rect, fill=None)
                            image_overlap_count += 1
                        else:
                            # Not over image: Use white fill for clean removal
                            page.add_redact_annot(expanded_rect, fill=(1, 1, 1))
                        
                        redaction_count += 1
                else:
                    # Fallback: If search fails, try using original_bbox as last resort
                    original_bbox = edit.get('original_bbox')
                    if original_bbox:
                        print(f"    ⚠ Search failed for '{original_text[:30]}...', using original_bbox fallback")
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
                    else:
                        print(f"    ✗ Cannot redact '{original_text[:30]}...' - not found and no bbox provided")
            
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


def normalize_font_name(font_name):
    if not font_name:
        return ''
    name = font_name
    if '+' in name:
        prefix, rest = name.split('+', 1)
        if len(prefix) == 6 and prefix.isupper():
            name = rest
    return ''.join(ch for ch in name.lower() if ch.isalnum())


def font_name_variants(font_name):
    """
    Return a set of normalized variants for matching font names.
    Handles style suffixes like "-Regular", "BoldItalic", etc.
    """
    variants = set()
    base = normalize_font_name(font_name)
    if base:
        variants.add(base)
    if font_name:
        # Split on common separators and take the family portion
        for sep in ('-', '_'):
            if sep in font_name:
                family = font_name.split(sep, 1)[0]
                family_norm = normalize_font_name(family)
                if family_norm:
                    variants.add(family_norm)
    # Also strip common style suffixes from the normalized name
    for suffix in ("regular", "bold", "italic", "bolditalic", "medium", "semibold", "black", "light"):
        if base.endswith(suffix):
            trimmed = base[: -len(suffix)]
            if trimmed:
                variants.add(trimmed)
    return variants


def parse_font_style(font_name):
    name = normalize_font_name(font_name)
    return {
        "bold": "bold" in name or "black" in name,
        "italic": "italic" in name or "oblique" in name,
    }


def font_family_name(font_name):
    if not font_name:
        return ''
    for sep in ('-', '_'):
        if sep in font_name:
            return font_name.split(sep, 1)[0]
    base = normalize_font_name(font_name)
    for suffix in ("regular", "bold", "italic", "bolditalic", "medium", "semibold", "black", "light"):
        if base.endswith(suffix):
            trimmed = base[: -len(suffix)]
            if trimmed:
                return trimmed
    return font_name


def score_font_match(target_name, candidate_name):
    target_norm = normalize_font_name(target_name)
    candidate_norm = normalize_font_name(candidate_name)
    if not target_norm or not candidate_norm:
        return -1

    target_family = normalize_font_name(font_family_name(target_name))
    score = 0
    if target_family and target_family in candidate_norm:
        score += 5
    if target_norm == candidate_norm:
        score += 4

    target_style = parse_font_style(target_name)
    candidate_style = parse_font_style(candidate_name)

    if target_style["bold"] == candidate_style["bold"]:
        score += 2
    else:
        score -= 2

    if target_style["italic"] == candidate_style["italic"]:
        score += 2
    else:
        score -= 2

    return score


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


def get_embedded_font_file(doc, page_fonts, font_name, cache, temp_files):
    """
    Try to locate and extract an embedded font that matches the given name.
    Returns a temp file path or None.
    """
    targets = font_name_variants(font_name)
    if not targets:
        return None

    best = None
    best_score = -1

    for font in page_fonts:
        # get_fonts(full=True) returns tuples, base font is usually index 3
        basefont = font[3] if len(font) > 3 else ''
        name = font[4] if len(font) > 4 else ''
        font_variants = font_name_variants(name)
        font_variants.update(font_name_variants(basefont))
        if not targets.intersection(font_variants):
            continue

        score = max(score_font_match(font_name, name), score_font_match(font_name, basefont))
        if score > best_score:
            best_score = score
            best = font

    if not best:
        return None

    xref = best[0]
    if xref in cache:
        return cache[xref]

    try:
        extracted = doc.extract_font(xref)
    except Exception:
        return None

    font_buffer = None
    font_ext = None
    if isinstance(extracted, dict):
        font_buffer = extracted.get("buffer") or extracted.get("file")
        font_ext = extracted.get("ext")
    elif isinstance(extracted, (list, tuple)):
        if len(extracted) >= 4:
            font_ext = extracted[1]
            font_buffer = extracted[3]

    if not font_buffer:
        return None

    suffix = f".{font_ext}" if font_ext else ".ttf"
    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=suffix)
    tmp.write(font_buffer)
    tmp.flush()
    tmp.close()
    temp_files.append(tmp.name)
    cache[xref] = tmp.name
    return tmp.name


def get_embedded_font_file_by_xref(doc, font_xref, cache, temp_files):
    if font_xref is None:
        return None
    try:
        xref = int(font_xref)
    except (TypeError, ValueError):
        return None
    if xref in cache:
        return cache[xref]
    try:
        extracted = doc.extract_font(xref)
    except Exception:
        return None

    font_buffer = None
    font_ext = None
    if isinstance(extracted, dict):
        font_buffer = extracted.get("buffer") or extracted.get("file")
        font_ext = extracted.get("ext")
    elif isinstance(extracted, (list, tuple)):
        if len(extracted) >= 4:
            font_ext = extracted[1]
            font_buffer = extracted[3]

    if not font_buffer:
        return None

    suffix = f".{font_ext}" if font_ext else ".ttf"
    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=suffix)
    tmp.write(font_buffer)
    tmp.flush()
    tmp.close()
    temp_files.append(tmp.name)
    cache[xref] = tmp.name
    return tmp.name


def find_installed_font_file(font_name, cache):
    targets = font_name_variants(font_name)
    if not targets:
        return None
    cache_key = ",".join(sorted(targets))
    if cache_key in cache:
        return cache[cache_key]

    font_dirs = [
        os.path.expanduser("~/.local/share/fonts"),
        "/usr/local/share/fonts",
        "/usr/share/fonts",
    ]

    best_path = None
    best_score = -1

    for font_dir in font_dirs:
        if not os.path.isdir(font_dir):
            continue
        for root, _, files in os.walk(font_dir):
            for filename in files:
                if not filename.lower().endswith((".ttf", ".otf", ".ttc")):
                    continue
                name_variants = font_name_variants(filename)
                if targets.intersection(name_variants):
                    score = score_font_match(font_name, filename)
                    if score > best_score:
                        best_score = score
                        best_path = os.path.join(root, filename)

    cache[cache_key] = best_path
    return best_path


def insert_text_with_font(page, point, text, size, fontfile_path, fallback_font, target_width=None):
    """
    Insert text with font matching, trying multiple approaches for best compatibility.
    If target_width is provided, apply per-character tracking to match the original width.
    """
    font_obj = None
    fontname = fallback_font

    if fontfile_path and os.path.exists(fontfile_path):
        try:
            font_obj = fitz.Font(fontfile=fontfile_path)
        except Exception:
            font_obj = None

    if font_obj is None:
        try:
            font_obj = fitz.Font(fontname=fallback_font)
        except Exception:
            font_obj = None

    if target_width is not None and font_obj and len(text) > 1:
        text_width = font_obj.text_length(text, size)
        if text_width:
            extra = (target_width - text_width) / (len(text) - 1)
            x = point.x
            for ch in text:
                try:
                    if fontfile_path and os.path.exists(fontfile_path):
                        page.insert_text(
                            fitz.Point(x, point.y),
                            ch,
                            fontfile=fontfile_path,
                            fontsize=size,
                            color=(0, 0, 0),
                            overlay=True
                        )
                    else:
                        page.insert_text(
                            fitz.Point(x, point.y),
                            ch,
                            fontname=fallback_font,
                            fontsize=size,
                            color=(0, 0, 0),
                            overlay=True
                        )
                except Exception as e:
                    print(f"      Warning: tracking insert failed: {e}")
                    break
                ch_width = font_obj.text_length(ch, size) if font_obj else 0
                x += ch_width + extra
            return

    # Default insert (no tracking)
    if fontfile_path and os.path.exists(fontfile_path):
        try:
            page.insert_text(
                point,
                text,
                fontfile=fontfile_path,
                fontsize=size,
                color=(0, 0, 0),
                overlay=True
            )
            print(f"      ✓ Successfully inserted with fontfile")
            return
        except TypeError as e:
            print(f"      Warning: fontfile parameter not supported: {e}")
        except Exception as e:
            print(f"      Warning: fontfile insert failed: {e}")

    print(f"      Using fallback font: {fallback_font}")
    try:
        page.insert_text(
            point,
            text,
            fontname=fallback_font,
            fontsize=size,
            color=(0, 0, 0),
            overlay=True
        )
        print(f"      ✓ Successfully inserted with fallback font")
    except Exception as e:
        print(f"      ✗ Error: All font insertion methods failed: {e}")
        raise


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
