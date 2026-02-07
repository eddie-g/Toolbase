#!/usr/bin/env python3
"""
Apply PDF edits with the 'Clean & Layer' Architecture:

=== BACKGROUND PRESERVATION RULE ===
- ALWAYS use fill=None for ALL redactions
- ALWAYS use apply_redactions(images=0) to preserve images
- fill=None removes text "ink" without adding any fill color
- This preserves backgrounds: images, colored rectangles, patterns, etc.
- Using fill=(1,1,1) creates visible white boxes on non-white backgrounds!

=== PHASE 0: Coordinate Normalization ===
- Run page.clean_contents() to normalize the coordinate grid
- Ensures search_for() and insert_textbox() use consistent coordinates

=== PHASE A: SCRUB (Remove Original Text) ===
- Search for original_text using page.search_for() to find in data stream
- Redact ONLY the original_bbox (where text WAS) with fill=None
- NEVER redact new_bbox (where text IS NOW)

=== PHASE B: INSERTION (Overlay New Text) ===
- Insert text at NEW bbox locations using overlay=True
- NO redaction at new positions - just draw on top
- This prevents cutting holes in images when text moves onto them

=== CRITICAL FONT HANDLING ===
- ALWAYS use FULL FONT FILES from the fonts/ directory!
- Subsetted font buffers extracted from PDFs DON'T WORK with TextWriter
- Extracted font buffers cause PyMuPDF to fall back to "Noto Serif Regular"
- Full font files (Arimo-Regular.ttf, Gelasio-Bold.ttf, etc.) work correctly

This fixes the "Double Text", "White Box", and "Wrong Font" bugs.
"""
import sys
import json
import os
import fitz  # PyMuPDF
import re
import html
import tempfile

# Font file mapping - prefer full font files for TextWriter
# If no local file is available, embedded fonts from the PDF are used as fallback
FONT_FILES = {
    # Arimo variants
    'Arimo': 'fonts/Arimo-Regular.ttf',
    'Arimo-Bold': 'fonts/Arimo-Bold.ttf',
    'Arimo-Regular': 'fonts/Arimo-Regular.ttf',
    'Arimo_700wght': 'fonts/Arimo-Bold.ttf',  # 700 weight = Bold
    
    # Gelasio variants
    'Gelasio': 'fonts/Gelasio-Regular.ttf',
    'Gelasio-Bold': 'fonts/Gelasio-Bold.ttf',
    'Gelasio-Regular': 'fonts/Gelasio-Regular.ttf',
    'Gelasio_700wght': 'fonts/Gelasio-Bold.ttf',  # 700 weight = Bold
    
    # Tinos variants (substitute for Times New Roman)
    'Tinos': 'fonts/Tinos-Regular.ttf',
    'Tinos-Bold': 'fonts/Tinos-Bold.ttf',
    'Tinos-Regular': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanUnicode': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanUnicode-Bold': 'fonts/Tinos-Bold.ttf',
    
    # Montserrat variants
    'Montserrat': 'fonts/Montserrat-Thin.ttf',
    'Montserrat-Bold': 'fonts/Montserrat-Bold.ttf',
    'Montserrat-Thin': 'fonts/Montserrat-Thin.ttf',
    'Montserrat_700wght': 'fonts/Montserrat-Bold.ttf',  # 700 weight = Bold
    'Montserrat_100wght': 'fonts/Montserrat-Thin.ttf',  # 100 weight = Thin
    'MontserratThin': 'fonts/Montserrat-Thin.ttf',
    'MontserratThin_700wght': 'fonts/Montserrat-Bold.ttf',  # Montserrat Thin family with 700 weight = Bold
    
    # Lato variants
    'Lato': 'fonts/Lato-Regular.ttf',
    'Lato-Regular': 'fonts/Lato-Regular.ttf',
    'Lato-Bold': 'fonts/Lato-Bold.ttf',
    'Lato_700wght': 'fonts/Lato-Bold.ttf',
}

def get_font_file(font_name, script_dir, font_weight=None):
    """
    Get the full font file path for a given font name.
    
    Prefers full font files from the fonts/ directory. If no local file is found,
    the caller should use get_embedded_font() to try extracting the font from the PDF.
    
    If font_weight is provided (e.g., '700', '400'), it takes priority over
    inferring boldness from the font name. This handles ambiguous names like
    "MontserratThin_700wght" where the family contains 'Thin' but the weight is bold.
    """
    if not font_name:
        return None
    
    # Direct match
    if font_name in FONT_FILES:
        path = os.path.join(script_dir, FONT_FILES[font_name])
        if os.path.exists(path):
            return path
    
    # Check for weight indicators (700wght = Bold, 400wght = Regular)
    # Strip subset prefix if present (e.g., "PXAAAB+Arimo_700wght" → "Arimo_700wght")
    clean_name = font_name.split('+')[-1] if '+' in font_name else font_name
    
    if clean_name in FONT_FILES:
        path = os.path.join(script_dir, FONT_FILES[clean_name])
        if os.path.exists(path):
            return path
    
    # Determine if this is a bold font - use explicit font_weight if provided
    if font_weight is not None:
        try:
            is_bold = int(font_weight) >= 600
        except (ValueError, TypeError):
            is_bold = any(x in font_name.lower() for x in ['bold', '700', 'black'])
    else:
        is_bold = any(x in font_name.lower() for x in ['bold', '700', 'black'])
    
    # Try to match font family with proper weight
    for key, rel in FONT_FILES.items():
        # Get base family name (Arimo, Gelasio, etc.)
        key_lower = key.lower().replace('-', '').replace('_', '')
        font_lower = font_name.lower().replace('-', '').replace('_', '').replace('+', '')
        
        # Check if font family matches
        if key.split('-')[0].lower() in font_lower or font_lower in key_lower:
            key_is_bold = 'bold' in key.lower()
            if is_bold == key_is_bold:
                path = os.path.join(script_dir, rel)
                if os.path.exists(path):
                    return path
    
    # Last resort: any matching family with any weight
    for key, rel in FONT_FILES.items():
        key_family = key.split('-')[0].split('_')[0].lower()
        font_family = font_name.split('-')[0].split('_')[0].split('+')[-1].lower()
        if key_family == font_family:
            path = os.path.join(script_dir, rel)
            if os.path.exists(path):
                return path
    return None

def get_image_rects(page):
    """Get all image bounding boxes on the page using multiple methods"""
    image_rects = []
    
    # Method 1: Use get_text('dict') to find image blocks
    blocks = page.get_text('dict').get('blocks', [])
    for block in blocks:
        if block.get('type') == 1:  # Image block
            image_rects.append(fitz.Rect(block['bbox']))
    
    # Method 2: Use get_images() and get image locations
    # This catches images that might not appear in text blocks
    images = page.get_images(full=True)
    for img_info in images:
        xref = img_info[0]
        try:
            # Get all locations where this image appears
            rects = page.get_image_rects(xref)
            for rect in rects:
                # Expand image rect slightly to ensure we catch overlapping text
                expanded = fitz.Rect(
                    rect.x0 - 2,
                    rect.y0 - 2,
                    rect.x1 + 2,
                    rect.y1 + 2
                )
                image_rects.append(expanded)
        except Exception:
            pass
    
    return image_rects

def overlaps_image(rect, image_rects):
    """Check if rect overlaps with any image"""
    for img_rect in image_rects:
        if not (rect & img_rect).is_empty:
            return True
    return False

def extract_font_buffer(doc, xref):
    try:
        extracted = doc.extract_font(xref)
        if isinstance(extracted, dict):
            return extracted.get('content')
        if isinstance(extracted, (list, tuple)) and len(extracted) >= 4:
            return extracted[3]
    except Exception:
        pass
    return None


# Cache for embedded fonts extracted to temp files
_embedded_font_tempfiles = []
_embedded_font_cache = {}

def get_embedded_font(doc, font_xref, font_name=None, page_num=None):
    """
    Extract an embedded font from the PDF and write it to a temp file.
    Returns a fitz.Font object or None.
    Uses font_xref first, falls back to name-based lookup.
    """
    global _embedded_font_tempfiles, _embedded_font_cache
    
    # Try by xref first
    if font_xref is not None:
        if font_xref in _embedded_font_cache:
            return _embedded_font_cache[font_xref]
        buf = extract_font_buffer(doc, font_xref)
        if buf and len(buf) > 100:
            try:
                tmp = tempfile.NamedTemporaryFile(delete=False, suffix='.ttf')
                tmp.write(buf)
                tmp.close()
                _embedded_font_tempfiles.append(tmp.name)
                font_obj = fitz.Font(fontfile=tmp.name)
                _embedded_font_cache[font_xref] = font_obj
                print(f"  ℹ Extracted embedded font from xref {font_xref}")
                return font_obj
            except Exception as e:
                print(f"  ⚠ Embedded font xref {font_xref} failed: {e}")
    
    # Try name-based lookup
    if font_name and page_num is not None:
        found_xref = find_font_xref_by_name(doc, page_num, font_name)
        if found_xref and found_xref not in _embedded_font_cache:
            buf = extract_font_buffer(doc, found_xref)
            if buf and len(buf) > 100:
                try:
                    tmp = tempfile.NamedTemporaryFile(delete=False, suffix='.ttf')
                    tmp.write(buf)
                    tmp.close()
                    _embedded_font_tempfiles.append(tmp.name)
                    font_obj = fitz.Font(fontfile=tmp.name)
                    _embedded_font_cache[found_xref] = font_obj
                    print(f"  ℹ Extracted embedded font '{font_name}' from xref {found_xref}")
                    return font_obj
                except Exception as e:
                    print(f"  ⚠ Embedded font '{font_name}' failed: {e}")
    
    return None


def cleanup_embedded_fonts():
    """Remove all temp font files."""
    for path in _embedded_font_tempfiles:
        try:
            os.unlink(path)
        except Exception:
            pass
    _embedded_font_tempfiles.clear()
    _embedded_font_cache.clear()


def find_font_xref_by_name(doc, page_num, font_name):
    """
    Find the current xref for a font by matching its name against page fonts.
    This handles the case where font xrefs change after PDF modifications.
    
    Returns the xref of the best matching font, or None if not found.
    """
    if not font_name:
        return None
    
    try:
        page_fonts = doc.get_page_fonts(page_num - 1, full=True)
        if not page_fonts:
            return None
        
        # Normalize the target font name for matching
        target_norm = font_name.lower().replace('-', '').replace('_', '').replace(' ', '')
        target_has_bold = any(x in font_name.lower() for x in ['bold', '700', 'black'])
        
        best_xref = None
        best_score = -1
        
        for font in page_fonts:
            xref = font[0]
            basefont = font[3] if len(font) > 3 else ''
            name = font[4] if len(font) > 4 else ''
            
            # Skip fonts without extractable data
            if not basefont and not name:
                continue
            
            # Check both basefont and name for matches
            for candidate in [basefont, name]:
                if not candidate:
                    continue
                    
                cand_norm = candidate.lower().replace('-', '').replace('_', '').replace(' ', '').replace('+', '')
                # Remove subset prefix (e.g., "PXAAAB+" from "PXAAAB+MontserratThin_700wght")
                if '+' in candidate:
                    cand_clean = candidate.split('+', 1)[1]
                    cand_norm_clean = cand_clean.lower().replace('-', '').replace('_', '').replace(' ', '')
                else:
                    cand_norm_clean = cand_norm
                
                score = 0
                
                # Exact match (ignoring subset prefix)
                if target_norm == cand_norm_clean:
                    score += 10
                # Partial match - target is in candidate
                elif target_norm in cand_norm_clean or cand_norm_clean in target_norm:
                    score += 5
                # Family name match
                else:
                    target_family = font_name.split('-')[0].split('_')[0].lower()
                    cand_family = cand_norm_clean.split('bold')[0].split('regular')[0].split('thin')[0]
                    if target_family in cand_family or cand_family in target_family:
                        score += 3
                
                # Style matching (bold)
                cand_has_bold = any(x in candidate.lower() for x in ['bold', '700', 'black'])
                if target_has_bold == cand_has_bold:
                    score += 2
                
                if score > best_score:
                    best_score = score
                    best_xref = xref
        
        if best_score > 0:
            print(f"  ℹ Font lookup: '{font_name}' → xref {best_xref} (score {best_score})")
            return best_xref
        
    except Exception as e:
        print(f"  ⚠ Font lookup error: {e}")
    
    return None

def normalize_smart_quotes(text):
    """
    Replace smart/curly quotes and other typographic characters with their
    ASCII equivalents. Many PDF fonts (especially subsetted ones) don't include
    glyphs for Unicode curly quotes, causing PyMuPDF to render them as '?'.
    """
    if not text:
        return text
    replacements = {
        '\u2018': "'",   # LEFT SINGLE QUOTATION MARK
        '\u2019': "'",   # RIGHT SINGLE QUOTATION MARK (curly apostrophe)
        '\u201A': "'",   # SINGLE LOW-9 QUOTATION MARK
        '\u201B': "'",   # SINGLE HIGH-REVERSED-9 QUOTATION MARK
        '\u201C': '"',   # LEFT DOUBLE QUOTATION MARK
        '\u201D': '"',   # RIGHT DOUBLE QUOTATION MARK
        '\u201E': '"',   # DOUBLE LOW-9 QUOTATION MARK
        '\u201F': '"',   # DOUBLE HIGH-REVERSED-9 QUOTATION MARK
        '\u2013': '-',   # EN DASH
        '\u2014': '-',   # EM DASH
        '\u2026': '...', # HORIZONTAL ELLIPSIS
        '\u00A0': ' ',   # NON-BREAKING SPACE
        '\u2032': "'",   # PRIME
        '\u2033': '"',   # DOUBLE PRIME
        '\ufb01': 'fi',  # LATIN SMALL LIGATURE FI
        '\ufb02': 'fl',  # LATIN SMALL LIGATURE FL
    }
    for smart, ascii_char in replacements.items():
        text = text.replace(smart, ascii_char)
    return text


def apply_edits(pdf_path, edits_json):
    """
    Apply edits using the 'Search-and-Destroy' Protocol:
    
    Phase 0: Clean all pages to normalize coordinate systems (CRITICAL)
    Phase A: Search for original_text in PDF data stream and redact ALL instances
    Phase B: Overlay new_text at NEW bbox locations (no redaction)
    
    This prevents ghosting by finding text in the actual PDF data layer.
    """
    script_dir = os.path.dirname(os.path.abspath(__file__))
    print(f"Opening PDF: {pdf_path}")
    doc = fitz.open(pdf_path)

    inserted_fonts = {}

    edits = json.loads(edits_json)

    # Group edits by page
    edits_by_page = {}
    for edit in edits:
        page_num = edit.get('page_num') or edit.get('page_number')
        if page_num not in edits_by_page:
            edits_by_page[page_num] = []
        edits_by_page[page_num].append(edit)

    print(f"Total edits: {len(edits)} across {len(edits_by_page)} pages")
    
    # PHASE 0: Clean all pages FIRST to normalize coordinate systems
    print("\n" + "="*50)
    print("PHASE 0: Normalizing coordinate systems")
    print("="*50)
    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        page.clean_contents()
        print(f"  ✓ Page {page_num}: Cleaned contents")
    
    # PHASE A: SCRUB PHASE - Remove text from ORIGINAL locations only
    # This phase ONLY handles where text USED TO BE, never where it IS NOW
    print("\n" + "="*50)
    print("PHASE A (SCRUB): Removing text from ORIGINAL locations")
    print("="*50)

    for page_num, page_edits in edits_by_page.items():
        print(f"\nPage {page_num}: Processing {len(page_edits)} edits...")
        page = doc[page_num - 1]
        
        redaction_count = 0

        for edit in page_edits:
            original_text = edit.get('original_text', '')
            new_text = edit.get('new_text', '')
            original_bbox = edit.get('original_bbox')
            edit_font_size = edit.get('font_size', 12)

            # Skip entirely empty original text — nothing to remove
            if not original_text or not original_text.strip():
                continue

            # SKIP NO-OP EDITS: If text is identical and position hasn't moved,
            # there's nothing to scrub or re-insert. This prevents duplication.
            bbox = edit.get('bbox')
            if original_text.strip() == new_text.strip() and original_bbox and bbox:
                # Check if position essentially unchanged (within 2pt tolerance)
                pos_unchanged = (abs(original_bbox[0] - bbox[0]) < 2 and
                                 abs(original_bbox[1] - bbox[1]) < 2 and
                                 abs(original_bbox[2] - bbox[2]) < 2 and
                                 abs(original_bbox[3] - bbox[3]) < 2)
                if pos_unchanged:
                    print(f"  ⊘ Skipping no-op edit for '{original_text[:30]}...' (text & position unchanged)")
                    edit['_skip_insert'] = True  # Flag to skip Phase B too
                    continue

            # PRIMARY SCRUB METHOD: Use original_bbox directly.
            # This is the most reliable approach because:
            # 1. search_for() fails for multi-line text
            # 2. search_for() can match text at WRONG locations (duplicates)
            # 3. original_bbox is the exact known location from extraction data
            if original_bbox:
                rect = fitz.Rect(
                    original_bbox[0] - 1,
                    original_bbox[1] - 1,
                    original_bbox[2] + 1,
                    original_bbox[3] + 1
                )
                page.add_redact_annot(rect, fill=None)
                redaction_count += 1
                print(f"  ✓ Redacting '{original_text[:30]}...' via original_bbox [{original_bbox[0]:.0f},{original_bbox[1]:.0f},{original_bbox[2]:.0f},{original_bbox[3]:.0f}]")
            else:
                # FALLBACK: No original_bbox — try search_for for single-line text only
                text_instances = page.search_for(original_text)
                if text_instances:
                    # Only use the first/best match
                    inst_rect = text_instances[0]
                    expanded_rect = fitz.Rect(
                        inst_rect.x0 - 1,
                        inst_rect.y0 - 1,
                        inst_rect.x1 + 1,
                        inst_rect.y1 + 1
                    )
                    page.add_redact_annot(expanded_rect, fill=None)
                    redaction_count += 1
                    print(f"  ✓ Redacting '{original_text[:30]}...' via search_for (no original_bbox)")
                else:
                    print(f"  ✗ Cannot redact '{original_text[:30]}...' - no original_bbox and search failed")

        # Apply ALL redactions for this page at once
        page.apply_redactions(images=0)
        print(f"  ✓ Scrubbed {redaction_count} locations [fill=None, images=0]")

    print("\n" + "="*50)
    print("PHASE B (INSERTION): Overlaying text at NEW locations")
    print("="*50)

    # PHASE B: Insert ALL new text at their NEW (current) locations
    # ╔═══════════════════════════════════════════════════════════════════════╗
    # ║ CRITICAL: NEVER redact the new_bbox!                                  ║
    # ║ Redacting the new location is what cuts holes in images.              ║
    # ║ We ONLY use overlay=True to draw text ON TOP of the existing PDF.    ║
    # ╚═══════════════════════════════════════════════════════════════════════╝
    for page_num, page_edits in edits_by_page.items():
        print(f"\nPage {page_num}: Inserting {len(page_edits)} text blocks...")
        page = doc[page_num - 1]

        for edit in page_edits:
            # Skip no-op edits flagged in Phase A
            if edit.get('_skip_insert'):
                continue

            new_text = (edit.get('new_text') or '')
            new_text = normalize_smart_quotes(new_text)
            if not new_text and not edit.get('rich_html'):
                print("  - Skipping empty/deleted block")
                continue

            # Use NEW bbox for insertion position (not original_bbox)
            bbox = edit.get('bbox')
            if not bbox:
                print("  ⚠ Skipping edit with no bbox")
                continue

            rect = fitz.Rect(bbox[0], bbox[1], bbox[2], bbox[3])
            font_size = edit.get('font_size', 12)
            font_name = edit.get('font', 'Arimo')
            font_xref = edit.get('font_xref')
            font_weight = edit.get('font_weight')
            font_style = edit.get('font_style')
            block_num = edit.get('block_num')
            line_height = edit.get('line_height')
            rich_html = edit.get('rich_html')

            # CRITICAL: Font xrefs can become stale after PDF modifications!
            # Always verify the xref is valid, and fall back to name-based lookup if not.
            if font_xref:
                # Verify the stored xref actually has font data
                test_content = extract_font_buffer(doc, font_xref)
                if not test_content:
                    print(f"  ⚠ Stored font_xref {font_xref} has no data, looking up by name...")
                    found_xref = find_font_xref_by_name(doc, page_num, font_name)
                    if found_xref:
                        font_xref = found_xref
                    else:
                        print(f"  ⚠ Could not find font '{font_name}' by name, will use fallback")
                        font_xref = None

            # Parse color from hex to RGB tuple (0-1 range)
            color_hex = edit.get('color', '#000000')
            text_color = (0, 0, 0)
            if isinstance(color_hex, str):
                normalized = color_hex.strip().lstrip('#')
                if len(normalized) == 6:
                    try:
                        text_color = (
                            int(normalized[0:2], 16) / 255.0,
                            int(normalized[2:4], 16) / 255.0,
                            int(normalized[4:6], 16) / 255.0,
                        )
                    except Exception:
                        text_color = (0, 0, 0)

            # Rich HTML handling (for formatted text)
            # CRITICAL: Use FULL FONT FILES, not extracted subsetted font buffers!
            # Subsetted fonts from PDFs don't work with TextWriter - they produce
            # "Noto Serif Regular" fallback. Full font files work correctly.
            if rich_html:
                try:
                    span_re = re.compile(r'<span[^>]*style=\"([^\"]*)\"[^>]*>(.*?)</span>', re.IGNORECASE | re.DOTALL)
                    spans = span_re.findall(rich_html)
                    if spans:
                        scale_ratio = 1.0
                        if font_size:
                            for style, _ in spans:
                                m = re.search(r'font-size\s*:\s*([0-9.]+)px', style)
                                if m:
                                    font_size_px = float(m.group(1))
                                    if font_size_px > 0:
                                        scale_ratio = font_size_px / float(font_size)
                                    break

                        # Extract wrapper <div> font info as fallback for spans without their own styles
                        wrapper_font_family = None
                        wrapper_font_weight = None
                        div_m = re.search(r'<div[^>]*style=\"([^\"]*)\"', rich_html, re.IGNORECASE)
                        if div_m:
                            div_style = div_m.group(1)
                            fm = re.search(r'font-family\s*:\s*([^;]+)', div_style)
                            if fm:
                                wrapper_font_family = fm.group(1).strip().split(',')[0].strip().strip('"').strip("'")
                            wm = re.search(r'font-weight\s*:\s*(\d+)', div_style)
                            if wm:
                                wrapper_font_weight = int(wm.group(1))

                        # Create TextWriter for this page
                        tw = fitz.TextWriter(page.rect)
                        
                        # Cache for loaded fonts to avoid reloading
                        span_font_cache = {}
                        
                        for style, text in spans:
                            left_px = 0.0
                            top_px = 0.0
                            letter_spacing_px = 0.0
                            m = re.search(r'left\s*:\s*([-0-9.]+)px', style)
                            if m:
                                left_px = float(m.group(1))
                            m = re.search(r'top\s*:\s*([-0-9.]+)px', style)
                            if m:
                                top_px = float(m.group(1))
                            # Extract letter-spacing for justified text
                            m = re.search(r'letter-spacing\s*:\s*([-0-9.]+)px', style)
                            if m:
                                letter_spacing_px = float(m.group(1))
                            
                            # Extract font-family and font-weight from THIS span's style
                            span_font_family = None
                            span_font_weight = None
                            m = re.search(r'font-family\s*:\s*([^;]+)', style)
                            if m:
                                # Extract first font name (before comma)
                                font_str = m.group(1).strip()
                                span_font_family = font_str.split(',')[0].strip().strip('"').strip("'")
                            m = re.search(r'font-weight\s*:\s*(\d+)', style)
                            if m:
                                span_font_weight = int(m.group(1))
                            
                            # Fall back to wrapper div font info if span doesn't have its own
                            if not span_font_family and wrapper_font_family:
                                span_font_family = wrapper_font_family
                            if span_font_weight is None and wrapper_font_weight is not None:
                                span_font_weight = wrapper_font_weight
                            
                            # Determine the actual font to use for this span
                            span_font_name = None
                            if span_font_family and span_font_weight:
                                # Build font name with weight (e.g., "Montserrat_700wght")
                                if span_font_weight == 700:
                                    # Try Bold variant
                                    span_font_name = f"{span_font_family}-Bold"
                                    if span_font_name not in FONT_FILES:
                                        span_font_name = f"{span_font_family}_700wght"
                                elif span_font_weight == 100:
                                    # Try Thin variant
                                    span_font_name = f"{span_font_family}-Thin"
                                    if span_font_name not in FONT_FILES:
                                        span_font_name = f"{span_font_family}_100wght"
                                else:
                                    # Use base family name
                                    span_font_name = span_font_family
                            elif span_font_family:
                                span_font_name = span_font_family
                            else:
                                # Fallback to the edit's font_name
                                span_font_name = font_name
                            
                            # Load font for this span (with caching)
                            cache_key = f"{span_font_name}_{span_font_weight}"
                            if cache_key in span_font_cache:
                                font_obj, font_label = span_font_cache[cache_key]
                            else:
                                font_obj = None
                                font_label = "fallback"
                                
                                # Try full font file
                                font_file = get_font_file(span_font_name, script_dir, font_weight=span_font_weight)
                                if font_file and os.path.exists(font_file):
                                    font_obj = fitz.Font(fontfile=font_file)
                                    font_label = f"file-{os.path.basename(font_file)}"
                                    print(f"  ℹ Span font: {span_font_name} → {font_file}")
                                
                                if not font_obj:
                                    # Try extracting the embedded font from the PDF
                                    font_obj = get_embedded_font(doc, font_xref, font_name=span_font_name or font_name, page_num=page_num)
                                    if font_obj:
                                        font_label = f"embedded-{span_font_name or font_name}"
                                
                                if not font_obj:
                                    # Last resort: Helvetica fallback
                                    font_obj = fitz.Font("helv")
                                    font_label = "helv"
                                    print(f"  ⚠ No font file for '{span_font_name}', using Helvetica")
                                
                                span_font_cache[cache_key] = (font_obj, font_label)
                            
                            clean_text = html.unescape(re.sub(r'<[^>]+>', '', text))
                            clean_text = normalize_smart_quotes(clean_text)
                            if not clean_text:
                                continue
                            x = bbox[0] + (left_px / scale_ratio if scale_ratio else left_px)
                            y = bbox[1] + (top_px / scale_ratio if scale_ratio else top_px) + float(font_size)
                            
                            # Convert letter-spacing from px to PDF points
                            letter_spacing = letter_spacing_px / scale_ratio if scale_ratio else letter_spacing_px
                            
                            # If we have letter-spacing, render char-by-char for precision
                            if abs(letter_spacing) > 0.001:
                                current_x = x
                                for char in clean_text:
                                    tw.append((current_x, y), char, font=font_obj, fontsize=font_size)
                                    # Advance by char width + letter spacing
                                    char_width = font_obj.glyph_advance(ord(char)) * font_size
                                    current_x += char_width + letter_spacing
                            else:
                                tw.append((x, y), clean_text, font=font_obj, fontsize=font_size)
                        
                        # Write all text to page
                        tw.write_text(page, color=text_color, overlay=True)
                        print(f"  ✓ Inserted rich HTML at ({bbox[0]:.1f}, {bbox[1]:.1f}) with {len(spans)} spans [TextWriter overlay]")
                        continue
                except Exception as e:
                    print(f"  ⚠ HTML parse error: {e}, falling back to textbox")

            # Block edits: use insert_textbox with overlay=True (no redaction)
            # PRIORITY: Use full font files, not extracted subsetted fonts
            if block_num is not None:
                insert_font_name = None
                
                # Try full font file FIRST (reliable)
                # Pass font_weight so bold/regular is chosen correctly even for ambiguous names
                font_file = get_font_file(font_name, script_dir, font_weight=font_weight)
                if font_file and os.path.exists(font_file):
                    file_key = os.path.basename(font_file)
                    if file_key in inserted_fonts:
                        insert_font_name = inserted_fonts[file_key]
                    else:
                        try:
                            insert_font_name = f"file_{file_key}"
                            page.insert_font(fontname=insert_font_name, fontfile=font_file)
                            inserted_fonts[file_key] = insert_font_name
                            print(f"  ℹ Using full font file: {font_file}")
                        except Exception as e:
                            print(f"  ⚠ Font file insert failed: {e}")
                            insert_font_name = None

                if not insert_font_name:
                    # Try extracting embedded font from PDF as fallback
                    embedded_font_obj = get_embedded_font(doc, font_xref, font_name=font_name, page_num=page_num)
                    if embedded_font_obj:
                        # Extract to temp file for insert_font
                        tmp = tempfile.NamedTemporaryFile(delete=False, suffix='.ttf')
                        buf = extract_font_buffer(doc, font_xref) if font_xref else None
                        if not buf:
                            found_xref = find_font_xref_by_name(doc, page_num, font_name)
                            if found_xref:
                                buf = extract_font_buffer(doc, found_xref)
                        if buf:
                            tmp.write(buf)
                            tmp.close()
                            _embedded_font_tempfiles.append(tmp.name)
                            try:
                                insert_font_name = f"emb_{font_name[:20]}"
                                page.insert_font(fontname=insert_font_name, fontfile=tmp.name)
                                inserted_fonts[font_name] = insert_font_name
                                print(f"  ℹ Using embedded font for '{font_name}'")
                            except Exception as e:
                                print(f"  ⚠ Embedded font insert failed: {e}")
                                insert_font_name = None
                        else:
                            tmp.close()
                            os.unlink(tmp.name)

                if not insert_font_name:
                    insert_font_name = "helv"
                    print(f"  ⚠ No font file for '{font_name}', using Helvetica fallback")

                textbox_kwargs = {
                    "fontname": insert_font_name,
                    "fontsize": font_size,
                    "color": text_color,
                    "align": fitz.TEXT_ALIGN_LEFT,
                    "overlay": True,  # CRITICAL: Overlay mode - no redaction!
                }

                if line_height and font_size:
                    try:
                        textbox_kwargs["lineheight"] = float(line_height) / float(font_size)
                    except Exception:
                        pass

                rc = page.insert_textbox(rect, new_text, **textbox_kwargs)
                
                # If text overflowed and has newlines, retry without newlines to allow natural wrapping
                if rc < 0 and '\n' in new_text:
                    print(f"  ⚠ Text overflowed with hard line breaks, retrying with natural wrapping...")
                    cleaned_text = new_text.replace('\n', ' ').strip()
                    # Remove multiple spaces
                    cleaned_text = re.sub(r'\s+', ' ', cleaned_text)
                    rc = page.insert_textbox(rect, cleaned_text, **textbox_kwargs)
                
                # If still overflowing, reduce font size until it fits
                if rc < 0:
                    print(f"  ⚠ Text still overflowing, reducing font size to fit...")
                    text_to_fit = cleaned_text if '\n' in new_text else new_text
                    reduced_font_size = font_size * 0.9  # Start with 90% of original
                    attempts = 0
                    max_attempts = 5
                    
                    while rc < 0 and attempts < max_attempts and reduced_font_size >= 6:
                        textbox_kwargs["fontsize"] = reduced_font_size
                        rc = page.insert_textbox(rect, text_to_fit, **textbox_kwargs)
                        if rc < 0:
                            reduced_font_size *= 0.9  # Reduce by another 10%
                        attempts += 1
                    
                    if rc >= 0:
                        print(f"  ✓ Fit text with reduced font size: {reduced_font_size:.1f}pt (was {font_size}pt)")
                
                status = "✓" if rc >= 0 else "⚠ (overflow - text too large for box)"
                print(f"  {status} Textbox at ({bbox[0]:.1f}, {bbox[1]:.1f}) font='{insert_font_name}' [overlay mode]")
                continue

            # Single text: use TextWriter with FULL FONT FILES for correct rendering
            # CRITICAL: DO NOT use extracted subsetted font buffers - they produce Noto Serif fallback!
            # Full font files work correctly with TextWriter.
            origin_x = edit.get('origin_x', bbox[0])
            origin_y = edit.get('origin_y', bbox[1] + font_size)

            font_obj = None
            font_label = "fallback"
            
            # PRIORITY: Full font file FIRST (only reliable method)
            font_file = get_font_file(font_name, script_dir)
            if font_file and os.path.exists(font_file):
                font_obj = fitz.Font(fontfile=font_file)
                font_label = f"file-{os.path.basename(font_file)}"
                print(f"  ℹ Using full font file: {font_file}")
            
            if not font_obj:
                # Try extracting embedded font from PDF
                font_obj = get_embedded_font(doc, font_xref, font_name=font_name, page_num=page_num)
                if font_obj:
                    font_label = f"embedded-{font_name}"
                    # Also need the font file path for insert_font later
                    if font_xref and font_xref in _embedded_font_cache:
                        for tf in _embedded_font_tempfiles:
                            if os.path.exists(tf):
                                font_file = tf
                                break
            
            if not font_obj:
                # Last resort: Helvetica fallback
                font_obj = fitz.Font("helv")
                font_label = "helv"
                print(f"  ⚠ No font file for '{font_name}', using Helvetica fallback")

            # Check if text has multiple lines - if so, use insert_textbox for proper wrapping
            if '\n' in new_text:
                # Multiline text: use insert_textbox for proper line handling
                insert_font_name = None
                if font_file and os.path.exists(font_file):
                    file_key = os.path.basename(font_file)
                    if file_key in inserted_fonts:
                        insert_font_name = inserted_fonts[file_key]
                    else:
                        try:
                            insert_font_name = f"file_{file_key}"
                            page.insert_font(fontname=insert_font_name, fontfile=font_file)
                            inserted_fonts[file_key] = insert_font_name
                        except Exception as e:
                            print(f"  ⚠ Font file insert failed: {e}")
                            insert_font_name = None
                
                if not insert_font_name:
                    insert_font_name = "helv"
                
                # Calculate line height (typically 1.2x font size)
                line_height_ratio = 1.2
                
                textbox_kwargs = {
                    "fontname": insert_font_name,
                    "fontsize": font_size,
                    "color": text_color,
                    "align": fitz.TEXT_ALIGN_LEFT,
                    "overlay": True,
                    "lineheight": line_height_ratio,
                }
                
                rc = page.insert_textbox(rect, new_text, **textbox_kwargs)
                
                # If text overflowed with hard line breaks, retry with natural wrapping
                if rc < 0:
                    print(f"  ⚠ Text overflowed with hard line breaks, retrying with natural wrapping...")
                    cleaned_text = new_text.replace('\n', ' ').strip()
                    # Remove multiple spaces
                    cleaned_text = re.sub(r'\s+', ' ', cleaned_text)
                    rc = page.insert_textbox(rect, cleaned_text, **textbox_kwargs)
                
                # If still overflowing, reduce font size until it fits
                if rc < 0:
                    print(f"  ⚠ Text still overflowing, reducing font size to fit...")
                    text_to_fit = cleaned_text if '\n' in new_text else new_text
                    reduced_font_size = font_size * 0.9  # Start with 90% of original
                    attempts = 0
                    max_attempts = 5
                    
                    while rc < 0 and attempts < max_attempts and reduced_font_size >= 6:
                        textbox_kwargs["fontsize"] = reduced_font_size
                        rc = page.insert_textbox(rect, text_to_fit, **textbox_kwargs)
                        if rc < 0:
                            reduced_font_size *= 0.9  # Reduce by another 10%
                        attempts += 1
                    
                    if rc >= 0:
                        print(f"  ✓ Fit text with reduced font size: {reduced_font_size:.1f}pt (was {font_size}pt)")
                
                status = "✓" if rc >= 0 else "⚠ (overflow - text too large for box)"
                print(f"  {status} Multiline text at ({bbox[0]:.1f}, {bbox[1]:.1f}) font='{insert_font_name}' [textbox overlay]")
            else:
                # Single line: use TextWriter for precise positioning
                tw = fitz.TextWriter(page.rect)
                tw.append((origin_x, origin_y), new_text, font=font_obj, fontsize=font_size)
                tw.write_text(page, color=text_color, overlay=True)
                print(f"  ✓ Text at ({origin_x:.1f}, {origin_y:.1f}) font='{font_label}' [TextWriter overlay]")

    print("\n" + "="*50)
    print("PHASE 3: Saving with cleanup")
    print("="*50)

    temp_path = pdf_path + '.tmp'
    # Use garbage=4 and deflate to optimize file
    # Keep encryption settings with encryption=fitz.PDF_ENCRYPT_KEEP
    doc.save(temp_path, garbage=4, deflate=True, encryption=fitz.PDF_ENCRYPT_KEEP)
    doc.close()
    os.replace(temp_path, pdf_path)
    cleanup_embedded_fonts()
    print("✓ PDF saved with full garbage collection and encryption preserved")
    return True

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Usage: apply_pdf_edits_simple.py <pdf_path> <edits_json>")
        sys.exit(1)

    try:
        apply_edits(sys.argv[1], sys.argv[2])
        print("\nSUCCESS")
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
