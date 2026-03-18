#!/usr/bin/env python3
"""
Apply PDF edits: Blank-slate approach.

=== HOW IT WORKS ===
1. Snapshot all text spans on edited pages (positions, fonts, colors)
2. Redact ALL text on those pages (blank slate — wipes everything)
3. Re-insert everything at absolute positions:
   - Unedited spans → original text at original position from snapshot
   - Edited spans → new text at new bbox from the editor

This eliminates ghost text, CID hex layers, partial redaction failures,
and all other encoding-related duplication issues.

=== BACKGROUND PRESERVATION RULE ===
- ALWAYS use fill=None for ALL redactions
- ALWAYS use apply_redactions(images=0) to preserve images
- fill=None removes text "ink" without adding any fill color
- This preserves backgrounds: images, colored rectangles, patterns, etc.

=== CRITICAL FONT HANDLING ===
- ALWAYS use FULL FONT FILES from the fonts/ directory!
- Subsetted font buffers extracted from PDFs DON'T WORK with TextWriter
- Full font files (Arimo-Regular.ttf, Gelasio-Bold.ttf, etc.) work correctly
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
    'Arial': 'fonts/Arimo-Regular.ttf',
    'Arial Regular': 'fonts/Arimo-Regular.ttf',
    'Arial Regular-Bold': 'fonts/Arimo-Bold.ttf',
    'Arial Regular-Regular': 'fonts/Arimo-Regular.ttf',
    'Arial Regular_700wght': 'fonts/Arimo-Bold.ttf',
    'Arial,Bold': 'fonts/Arimo-Regular.ttf',
    'Arial,Bold-Bold': 'fonts/Arimo-Bold.ttf',
    'Arial,Bold-Regular': 'fonts/Arimo-Regular.ttf',
    'Arial,Bold_700wght': 'fonts/Arimo-Bold.ttf',
    'Arial-Bold': 'fonts/Arimo-Bold.ttf',
    'Arial-BoldItalic': 'fonts/Arimo-Bold.ttf',
    'Arial-Italic': 'fonts/Arimo-Regular.ttf',
    'Arial-Regular': 'fonts/Arimo-Regular.ttf',
    'ArialMT': 'fonts/Arimo-Regular.ttf',
    'ArialMT-Bold': 'fonts/Arimo-Bold.ttf',
    'ArialMT-Regular': 'fonts/Arimo-Regular.ttf',
    'ArialMT_700wght': 'fonts/Arimo-Bold.ttf',
    'Arial_700wght': 'fonts/Arimo-Bold.ttf',
    'Arimo': 'fonts/Arimo-Regular.ttf',
    'Arimo Bold': 'fonts/Arimo-Regular.ttf',
    'Arimo Bold-Bold': 'fonts/Arimo-Bold.ttf',
    'Arimo Bold-Regular': 'fonts/Arimo-Regular.ttf',
    'Arimo Bold_700wght': 'fonts/Arimo-Bold.ttf',
    'Arimo Regular': 'fonts/Arimo-Regular.ttf',
    'Arimo Regular-Bold': 'fonts/Arimo-Bold.ttf',
    'Arimo Regular-Regular': 'fonts/Arimo-Regular.ttf',
    'Arimo Regular_700wght': 'fonts/Arimo-Bold.ttf',
    'Arimo-Bold': 'fonts/Arimo-Bold.ttf',
    'Arimo-BoldItalic': 'fonts/Arimo-Bold.ttf',
    'Arimo-Italic': 'fonts/Arimo-Regular.ttf',
    'Arimo-Regular': 'fonts/Arimo-Regular.ttf',
    'Arimo_700wght': 'fonts/Arimo-Bold.ttf',
    'Calibri': 'fonts/Carlito-Regular.ttf',
    'Calibri,Bold': 'fonts/Carlito-Regular.ttf',
    'Calibri,Bold-Bold': 'fonts/Carlito-Bold.ttf',
    'Calibri,Bold-Regular': 'fonts/Carlito-Regular.ttf',
    'Calibri,BoldItalic': 'fonts/Carlito-Regular.ttf',
    'Calibri,BoldItalic-Bold': 'fonts/Carlito-Bold.ttf',
    'Calibri,BoldItalic-Regular': 'fonts/Carlito-Regular.ttf',
    'Calibri,BoldItalic_700wght': 'fonts/Carlito-Bold.ttf',
    'Calibri,Bold_700wght': 'fonts/Carlito-Bold.ttf',
    'Calibri-Bold': 'fonts/Carlito-Bold.ttf',
    'Calibri-Regular': 'fonts/Carlito-Regular.ttf',
    'Calibri_700wght': 'fonts/Carlito-Bold.ttf',
    'Charmonman': 'fonts/Charmonman-Regular.ttf',
    'Charmonman-Bold': 'fonts/Charmonman-Bold.ttf',
    'Charmonman-Regular': 'fonts/Charmonman-Regular.ttf',
    'Charmonman_700wght': 'fonts/Charmonman-Bold.ttf',
    'Courier': 'fonts/Cousine-Regular.ttf',
    'Courier-Bold': 'fonts/Cousine-Bold.ttf',
    'Courier-Regular': 'fonts/Cousine-Regular.ttf',
    'CourierNewPS': 'fonts/Cousine-Regular.ttf',
    'CourierNewPS-Bold': 'fonts/Cousine-Bold.ttf',
    'CourierNewPS-Regular': 'fonts/Cousine-Regular.ttf',
    'CourierNewPSMT': 'fonts/Cousine-Regular.ttf',
    'CourierNewPSMT-Bold': 'fonts/Cousine-Bold.ttf',
    'CourierNewPSMT-Regular': 'fonts/Cousine-Regular.ttf',
    'CourierNewPSMT_700wght': 'fonts/Cousine-Bold.ttf',
    'CourierNewPS_700wght': 'fonts/Cousine-Bold.ttf',
    'Courier_700wght': 'fonts/Cousine-Bold.ttf',
    'Cousine': 'fonts/Cousine-Regular.ttf',
    'Cousine-Bold': 'fonts/Cousine-Bold.ttf',
    'Cousine-Regular': 'fonts/Cousine-Regular.ttf',
    'Cousine_700wght': 'fonts/Cousine-Bold.ttf',
    'DrhchpLato': 'fonts/Lato-Regular.ttf',
    'DrhchpLato-Bold': 'fonts/Lato-Bold.ttf',
    'DrhchpLato-Regular': 'fonts/Lato-Regular.ttf',
    'DrhchpLato_700wght': 'fonts/Lato-Bold.ttf',
    'Gelasio': 'fonts/Gelasio-Regular.ttf',
    'Gelasio-Bold': 'fonts/Gelasio-Bold.ttf',
    'Gelasio-Regular': 'fonts/Gelasio-Regular.ttf',
    'Gelasio_700wght': 'fonts/Gelasio-Bold.ttf',
    'Helvetica': 'fonts/Arimo-Regular.ttf',
    'Helvetica-Bold': 'fonts/Arimo-Bold.ttf',
    'Helvetica-BoldItalic': 'fonts/Arimo-Bold.ttf',
    'Helvetica-Italic': 'fonts/Arimo-Regular.ttf',
    'Helvetica-Regular': 'fonts/Arimo-Regular.ttf',
    'HelveticaWorld': 'fonts/Arimo-Regular.ttf',
    'HelveticaWorld-Bold': 'fonts/Arimo-Bold.ttf',
    'HelveticaWorld-Regular': 'fonts/Arimo-Regular.ttf',
    'HelveticaWorld_700wght': 'fonts/Arimo-Bold.ttf',
    'Helvetica_700wght': 'fonts/Arimo-Bold.ttf',
    'Lato': 'fonts/Lato-Regular.ttf',
    'Lato-Bold': 'fonts/Lato-Bold.ttf',
    'Lato-Regular': 'fonts/Lato-Regular.ttf',
    'Lato_700wght': 'fonts/Lato-Bold.ttf',
    'Montserrat': 'fonts/Montserrat-Regular.ttf',
    'Montserrat-Bold': 'fonts/Montserrat-Bold.ttf',
    'Montserrat-Regular': 'fonts/Montserrat-Regular.ttf',
    'Montserrat-Thin': 'fonts/Montserrat-Thin.ttf',
    'MontserratThin': 'fonts/Montserrat-Light.ttf',
    'MontserratThin-Bold': 'fonts/Montserrat-Bold.ttf',
    'MontserratThin-Regular': 'fonts/Montserrat-Light.ttf',
    'MontserratThin_700wght': 'fonts/Montserrat-Bold.ttf',
    'Montserrat_100wght': 'fonts/Montserrat-Thin.ttf',
    'Montserrat_700wght': 'fonts/Montserrat-Bold.ttf',
    'OpenSans': 'fonts/OpenSans-Regular.ttf',
    'OpenSans-Bold': 'fonts/OpenSans-Bold.ttf',
    'OpenSans-BoldItalic': 'fonts/OpenSans-Bold.ttf',
    'OpenSans-Italic': 'fonts/OpenSans-Regular.ttf',
    'OpenSans-Regular': 'fonts/OpenSans-Regular.ttf',
    'OpenSans_700wght': 'fonts/OpenSans-Bold.ttf',
    'PalatinoLinotype': 'fonts/EBGaramond-Regular.ttf',
    'PalatinoLinotype-Bold': 'fonts/EBGaramond-Bold.ttf',
    'PalatinoLinotype-Regular': 'fonts/EBGaramond-Regular.ttf',
    'PalatinoLinotype_700wght': 'fonts/EBGaramond-Bold.ttf',
    'PdbpbbLato': 'fonts/Lato-Regular.ttf',
    'PdbpbbLato-Bold': 'fonts/Lato-Bold.ttf',
    'PdbpbbLato-Regular': 'fonts/Lato-Regular.ttf',
    'PdbpbbLato_700wght': 'fonts/Lato-Bold.ttf',
    'Poppins': 'fonts/Poppins-Medium.ttf',
    'Poppins-Bold': 'fonts/Poppins-Bold.ttf',
    'Poppins-Regular': 'fonts/Poppins-Medium.ttf',
    'Poppins_700wght': 'fonts/Poppins-Bold.ttf',
    'Roboto': 'fonts/Roboto-Regular.ttf',
    'Roboto-Bold': 'fonts/Roboto-Bold.ttf',
    'Roboto-BoldItalic': 'fonts/Roboto-Bold.ttf',
    'Roboto-Italic': 'fonts/Roboto-Regular.ttf',
    'Roboto-Regular': 'fonts/Roboto-Regular.ttf',
    'Roboto_700wght': 'fonts/Roboto-Bold.ttf',
    'SourceSansPro': 'fonts/SourceSans3-Regular.ttf',
    'SourceSansPro-Bold': 'fonts/SourceSans3-Bold.ttf',
    'SourceSansPro-Regular': 'fonts/SourceSans3-Regular.ttf',
    'SourceSansPro_700wght': 'fonts/SourceSans3-Bold.ttf',
    'TahomaUnicode': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode,Bold': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode,Bold,Italic': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode,Bold,Italic-Bold': 'fonts/Arimo-Bold.ttf',
    'TahomaUnicode,Bold,Italic-Regular': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode,Bold,Italic_700wght': 'fonts/Arimo-Bold.ttf',
    'TahomaUnicode,Bold-Bold': 'fonts/Arimo-Bold.ttf',
    'TahomaUnicode,Bold-Regular': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode,Bold_700wght': 'fonts/Arimo-Bold.ttf',
    'TahomaUnicode-Bold': 'fonts/Arimo-Bold.ttf',
    'TahomaUnicode-Regular': 'fonts/Arimo-Regular.ttf',
    'TahomaUnicode_700wght': 'fonts/Arimo-Bold.ttf',
    'TimesNewRomanPS': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanPS-Bold': 'fonts/Tinos-Bold.ttf',
    'TimesNewRomanPS-Regular': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanPSMT': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanPSMT-Bold': 'fonts/Tinos-Bold.ttf',
    'TimesNewRomanPSMT-Regular': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanPSMT_700wght': 'fonts/Tinos-Bold.ttf',
    'TimesNewRomanPS_700wght': 'fonts/Tinos-Bold.ttf',
    'TimesNewRomanUnicode': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanUnicode-Bold': 'fonts/Tinos-Bold.ttf',
    'TimesNewRomanUnicode-Regular': 'fonts/Tinos-Regular.ttf',
    'TimesNewRomanUnicode_700wght': 'fonts/Tinos-Bold.ttf',
    'Tinos': 'fonts/Tinos-Regular.ttf',
    'Tinos-Bold': 'fonts/Tinos-Bold.ttf',
    'Tinos-BoldItalic': 'fonts/Tinos-Bold.ttf',
    'Tinos-Italic': 'fonts/Tinos-Regular.ttf',
    'Tinos-Regular': 'fonts/Tinos-Regular.ttf',
    'Verdana': 'fonts/Arimo-Regular.ttf',
    'Verdana,Bold': 'fonts/Arimo-Regular.ttf',
    'Verdana,Bold-Bold': 'fonts/Arimo-Bold.ttf',
    'Verdana,Bold-Regular': 'fonts/Arimo-Regular.ttf',
    'Verdana,Bold_700wght': 'fonts/Arimo-Bold.ttf',
    'Verdana-Bold': 'fonts/Arimo-Bold.ttf',
    'Verdana-BoldItalic': 'fonts/Arimo-Bold.ttf',
    'Verdana-Italic': 'fonts/Arimo-Regular.ttf',
    'Verdana-Regular': 'fonts/Arimo-Regular.ttf',
    'Verdana_700wght': 'fonts/Arimo-Bold.ttf',
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

def get_embedded_font(doc, font_xref, font_name=None, page_num=None, want_bold=None, want_italic=None):
    """
    Extract an embedded font from the PDF and write it to a temp file.
    Returns a fitz.Font object or None.
    Uses font_xref first, falls back to name-based lookup.

    want_bold / want_italic: passed to the name-based lookup to disambiguate
    subset-renamed fonts (e.g. "OOPGPI+Verdana Regular" which is actually bold).
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
        found_xref = find_font_xref_by_name(doc, page_num, font_name, want_bold=want_bold, want_italic=want_italic)
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


def find_font_xref_by_name(doc, page_num, font_name, want_bold=None, want_italic=None):
    """
    Find the current xref for a font by matching its name against page fonts.
    This handles the case where font xrefs change after PDF modifications.

    want_bold / want_italic: if provided, used for tie-breaking by extracting the
    actual font binary and checking fitz.Font.is_bold / is_italic flags.

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
        best_candidates: list = []  # (score, xref) — accumulate ties

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
                
                # Style matching (bold) — name-based only; may be unreliable for
                # subset-renamed fonts (e.g. "OOPGPI+Verdana Regular" is actually
                # bold).  We add it but rely on flag-based tie-breaking below.
                cand_has_bold = any(x in candidate.lower() for x in ['bold', '700', 'black'])
                if target_has_bold == cand_has_bold:
                    score += 2
                
                if score > best_score:
                    best_score = score
                    best_xref = xref
                    best_candidates = [(score, xref)]
                elif score == best_score and xref != best_xref:
                    best_candidates.append((score, xref))

        # If there are multiple candidates with the same top score, use actual
        # font flag extraction to pick the one whose bold/italic matches the
        # request.  This handles subset-renamed fonts like "OOPGPI+Verdana
        # Regular" which are actually bold but have "Regular" in their name.
        if (want_bold is not None or want_italic is not None) and len(best_candidates) > 1:
            flag_winners = []
            for _sc, xref in best_candidates:
                try:
                    buf = extract_font_buffer(doc, xref)
                    if buf and len(buf) > 100:
                        tmp_font = fitz.Font(fontbuffer=buf)
                        bold_ok = (want_bold is None) or (tmp_font.is_bold == want_bold)
                        italic_ok = (want_italic is None) or (tmp_font.is_italic == want_italic)
                        if bold_ok and italic_ok:
                            flag_winners.append(xref)
                except Exception:
                    pass
            if flag_winners:
                best_xref = flag_winners[0]

        if best_score > 0:
            print(f"  ℹ Font lookup: '{font_name}' → xref {best_xref} (score {best_score})")
            return best_xref
        
    except Exception as e:
        print(f"  ⚠ Font lookup error: {e}")
    
    return None

def normalize_smart_quotes(text):
    """
    Normalize user / extraction text before insertion:
    - Replace smart typography with ASCII equivalents.
    - Remove invisible control chars and private-use glyph artifacts.
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

    # Remove invisible and private-use glyph artifacts that often show up as
    # tofu boxes in the overlay editor (e.g. "", "□").
    text = re.sub(r'[\u200B\u200C\u200D\u2060\uFEFF]', '', text)
    text = re.sub(r'[\uE000-\uF8FF]', '', text)
    text = text.replace('\uFFFD', '')
    text = re.sub(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', '', text)
    return text


def normalize_style_run_text(value):
    return re.sub(r"\s+", " ", "" if value is None else str(value)).strip()


def apply_edits(pdf_path, edits_json):
    """
    Simple save pipeline:
    
    1. Snapshot all text spans on edited pages
    2. Redact ALL text on those pages (blank slate)
    3. Re-insert everything at absolute positions:
       - Edited spans → new text at new bbox
       - Unedited spans → original text at original position
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
    
    # STEP 1: Clean pages and snapshot all text
    print("\n" + "="*50)
    print("STEP 1: Snapshot all text")
    print("="*50)
    pre_edit_spans = {}
    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        page.clean_contents()
        spans = []
        blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
        for block in blocks:
            if block.get("type") != 0:
                continue
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    text = span.get("text", "").strip()
                    if not text:
                        continue
                    spans.append({
                        "text": text,
                        "bbox": list(span["bbox"]),
                        "origin": list(span["origin"]),
                        "font": span.get("font", ""),
                        "size": span.get("size", 12),
                        "color": span.get("color", 0),
                        "flags": span.get("flags", 0),
                    })
        pre_edit_spans[page_num] = spans
        print(f"  Page {page_num}: {len(spans)} text spans captured")

    # STEP 2: Remove ALL text from edited pages (blank slate)
    # Use direct content stream manipulation to strip BT..ET blocks.
    # This is more reliable than apply_redactions() which can't handle
    # CID hex encoded text in some PDFs.
    print("\n" + "="*50)
    print("STEP 2: Strip ALL text from content streams (blank slate)")
    print("="*50)
    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        xrefs = page.get_contents()
        for xref in xrefs:
            stream = doc.xref_stream(xref)
            if stream:
                # Remove all text-drawing blocks (BT ... ET) from the stream
                cleaned = re.sub(rb'BT\b.*?ET\b', b'', stream, flags=re.DOTALL)
                doc.update_stream(xref, cleaned)
        print(f"  Page {page_num}: All text stripped from content streams")

    # STEP 3: Build the map of what to re-insert
    # For each edit, match it to the snapshot span it replaces.
    # Everything else gets re-inserted as-is from the snapshot.
    print("\n" + "="*50)
    print("STEP 3: Re-insert all text at absolute positions")
    print("="*50)

    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        page_edits = edits_by_page[page_num]
        snapshot = pre_edit_spans.get(page_num, [])

        # Mark which snapshot spans are replaced by edits
        # Match by: original_text + original_bbox overlap / proximity.
        #
        # IMPORTANT: A single overlay field (edit) can cover MULTIPLE PDF
        # spans (e.g. "1" + "Purpose of the Isartor Test Suite" are two
        # separate spans rendered by different BT..ET blocks in the PDF
        # but shown as one editable block in the UI).  We must mark ALL
        # constituent spans as replaced, otherwise the unmatched ones get
        # re-inserted as "unedited" text — creating phantom/duplicate
        # characters (the "phantom P" bug).
        #
        # Strategy:
        #   1. Pass 1 — find the closest span (the "anchor") using
        #      substring text match + position < 5pt.
        #   2. Pass 2 — sweep for sibling spans whose text is a
        #      sub-part of orig_text or new_text AND whose bbox falls
        #      within the edit's original_bbox.
        #   3. Pass 3 — pure bbox overlap: any span whose bbox is
        #      substantially contained within the edit bbox, regardless
        #      of text content.  This catches re-edit scenarios where
        #      the span text has already been changed by a prior save
        #      (e.g. "Isartord" no longer matches "Isartor").
        replaced_indices = set()
        for edit in page_edits:
            orig_text = (edit.get('original_text') or '').strip()
            new_text_edit = (edit.get('new_text') or '').strip()
            orig_bbox = edit.get('original_bbox')
            if not orig_text or not orig_bbox:
                continue

            # Build the edit's bounding rectangle (with small tolerance)
            tol = 2.0
            edit_x0 = orig_bbox[0] - tol
            edit_y0 = orig_bbox[1] - tol
            edit_x1 = (orig_bbox[2] if len(orig_bbox) > 2 else orig_bbox[0] + 500) + tol
            edit_y1 = (orig_bbox[3] if len(orig_bbox) > 3 else orig_bbox[1] + 50) + tol

            # Also build a rect from the NEW bbox (may be resized)
            new_bbox = edit.get('bbox')
            if new_bbox and len(new_bbox) >= 4:
                wide_x0 = min(edit_x0, new_bbox[0] - tol)
                wide_y0 = min(edit_y0, new_bbox[1] - tol)
                wide_x1 = max(edit_x1, new_bbox[2] + tol)
                wide_y1 = max(edit_y1, new_bbox[3] + tol)
            else:
                wide_x0, wide_y0, wide_x1, wide_y1 = edit_x0, edit_y0, edit_x1, edit_y1

            # Pass 1 — anchor span (closest positional match)
            best_idx = None
            best_dist = float('inf')
            for i, span in enumerate(snapshot):
                if i in replaced_indices:
                    continue
                st = span["text"].strip()
                # Text check: substring match against orig_text OR new_text
                text_match = (
                    st == orig_text or orig_text in st or st in orig_text or
                    (new_text_edit and (st == new_text_edit or new_text_edit in st or st in new_text_edit))
                )
                if not text_match:
                    continue
                dist = abs(span["bbox"][0] - orig_bbox[0]) + abs(span["bbox"][1] - orig_bbox[1])
                if dist < 5.0 and dist < best_dist:
                    best_dist = dist
                    best_idx = i
            if best_idx is not None:
                replaced_indices.add(best_idx)

            # Pass 2 — sweep for sibling spans that are INSIDE the edit
            # bbox and whose text is a sub-part of orig_text or new_text.
            # This catches additional spans like "Purpose of the Isartor
            # Test Suite" that start further right but are still part of
            # the same logical text block.
            for i, span in enumerate(snapshot):
                if i in replaced_indices:
                    continue
                st = span["text"].strip()
                if not st:
                    continue
                # Text must be a substring of orig_text OR new_text
                if st not in orig_text and (not new_text_edit or st not in new_text_edit):
                    continue
                # Span bbox must be substantially inside the edit bbox
                sb = span["bbox"]
                # Check vertical overlap (same line)
                vert_overlap = min(sb[3], edit_y1) - max(sb[1], edit_y0)
                span_height = max(sb[3] - sb[1], 1)
                if vert_overlap < span_height * 0.5:
                    continue
                # Check horizontal containment
                if sb[0] < edit_x0 or sb[2] > edit_x1 + tol:
                    continue
                replaced_indices.add(i)
                print(f"    Pass 2 matched span {i}: {repr(st[:40])}")

            # Pass 3 — pure bbox overlap: catch spans from prior edits
            # whose text no longer matches (e.g. "Isartord" vs "Isartor")
            # but which occupy the same area on the page.
            for i, span in enumerate(snapshot):
                if i in replaced_indices:
                    continue
                st = span["text"].strip()
                if not st:
                    continue
                sb = span["bbox"]
                # Span must be vertically on the same line
                vert_overlap = min(sb[3], wide_y1) - max(sb[1], wide_y0)
                span_height = max(sb[3] - sb[1], 1)
                if vert_overlap < span_height * 0.5:
                    continue
                # Span must be horizontally contained within (or nearly so)
                if sb[0] < wide_x0 or sb[2] > wide_x1 + tol:
                    continue
                # Additional safety: span must overlap the edit rect by
                # at least 80% of the span's own area to avoid catching
                # adjacent but unrelated text.
                horiz_overlap = min(sb[2], wide_x1) - max(sb[0], wide_x0)
                span_width = max(sb[2] - sb[0], 1)
                if horiz_overlap < span_width * 0.8:
                    continue
                replaced_indices.add(i)
                print(f"    Pass 3 (bbox) matched span {i}: {repr(st[:40])}")

        print(f"  Page {page_num}: {len(replaced_indices)} of {len(snapshot)} spans matched as replaced")

        # Re-insert unedited spans from snapshot
        unedited_count = 0
        for i, span in enumerate(snapshot):
            if i in replaced_indices:
                continue
            # Re-insert at original position
            font_obj = None
            span_font_name = span.get("font", "")
            
            # Try full font file
            font_file = get_font_file(span_font_name, script_dir)
            if font_file and os.path.exists(font_file):
                font_obj = fitz.Font(fontfile=font_file)
            
            if not font_obj:
                # Try embedded font
                font_obj = get_embedded_font(doc, None, font_name=span_font_name, page_num=page_num)
            
            if not font_obj:
                font_obj = fitz.Font("helv")

            # Convert color
            c = span.get("color", 0)
            if isinstance(c, int):
                color = (
                    ((c >> 16) & 0xFF) / 255.0,
                    ((c >> 8) & 0xFF) / 255.0,
                    (c & 0xFF) / 255.0,
                )
            else:
                color = (0, 0, 0)

            tw = fitz.TextWriter(page.rect)
            tw.append(
                (span["origin"][0], span["origin"][1]),
                span["text"],
                font=font_obj,
                fontsize=span["size"],
            )
            tw.write_text(page, color=color, overlay=True)
            unedited_count += 1

        print(f"  Page {page_num}: Re-inserted {unedited_count} unedited spans")

        # Now insert the edited spans
        edit_count = 0
        for edit in page_edits:
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
            # ── CLAMP RECT TO PAGE BOUNDARIES ──────────────────────────
            # Text must NEVER be placed outside the page. Intersect the
            # insertion rect with the page rect so it stays within bounds.
            page_r = page.rect
            rect = rect & page_r  # fitz.Rect intersection
            if rect.is_empty or rect.width < 1 or rect.height < 1:
                print(f"  ⚠ Clamped rect is empty/tiny — placing at page origin")
                rect = fitz.Rect(page_r.x0, page_r.y0,
                                 min(page_r.x0 + 200, page_r.x1),
                                 min(page_r.y0 + 50, page_r.y1))
            # Update bbox to match the clamped rect
            bbox = [rect.x0, rect.y0, rect.x1, rect.y1]
            font_size = edit.get('font_size', 12)
            try:
                font_size = float(font_size)
            except (TypeError, ValueError):
                font_size = 12.0
            font_name = edit.get('font', 'Arimo')
            font_xref = edit.get('font_xref')
            font_weight = edit.get('font_weight')
            font_style = edit.get('font_style')
            edit_underline = edit.get('underline', False)
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

            # ── WORD-STYLES HANDLING ───────────────────────────────────────
            # When the frontend sends per-word style data (font, size, italic,
            # color, position), we re-insert each word individually using
            # TextWriter so that subscripts, superscripts, italic spans, and
            # mixed-font blocks survive the edit cycle.
            word_styles = edit.get('word_styles')
            if word_styles and isinstance(word_styles, list) and len(word_styles) > 0:
                try:
                    original_text = (edit.get('original_text') or '').strip()
                    new_text_stripped = (new_text or '').strip()
                    style_text = normalize_style_run_text(' '.join(
                        '' if not isinstance(ws, dict) else str(ws.get('text') or '')
                        for ws in word_styles
                    ))
                    if new_text_stripped and style_text != normalize_style_run_text(new_text_stripped):
                        print("  ℹ Skipping word_styles replay because run text no longer matches edited text")
                        raise ValueError("word_styles stale for edited block — use insert_textbox instead")

                    # ── POSITION OFFSET ────────────────────────────────────
                    # word_styles contain ABSOLUTE positions from the original
                    # extraction.  If the block was moved, we need to shift
                    # every word by the delta between original_bbox and the
                    # new bbox so text lands at the moved position.
                    original_bbox = edit.get('original_bbox')
                    ws_delta_x = 0.0
                    ws_delta_y = 0.0
                    if original_bbox and bbox:
                        ws_delta_x = bbox[0] - original_bbox[0]
                        ws_delta_y = bbox[1] - original_bbox[1]
                    if abs(ws_delta_x) > 0.5 or abs(ws_delta_y) > 0.5:
                        print(f"  ℹ word_styles position offset: dx={ws_delta_x:.1f}, dy={ws_delta_y:.1f}")

                    def _build_word_edits(word_styles, original_text, new_text):
                        """
                        Map original per-word styles onto new_text.
                        
                        Strategy: build an output token stream from SequenceMatcher opcodes.
                        Each output token inherits the style of the nearest original token.
                        Then group consecutive tokens by their word_style index to produce
                        the final list of word_style dicts.
                        
                        Returns a list of word_style dicts with potentially updated 'text'.
                        """
                        import difflib

                        # Build token list from word_styles
                        # Each token tracks which word_style index it came from
                        orig_tokens = []  # (token_text, ws_idx)
                        
                        for ws_idx, ws in enumerate(word_styles):
                            wt = ws.get('text', '').strip()
                            if not wt:
                                continue
                            for tok in wt.split():
                                tok = tok.strip()
                                if tok:
                                    orig_tokens.append((tok, ws_idx))
                        
                        # Tokenize new text
                        new_tokens = new_text.split()
                        orig_token_texts = [t[0] for t in orig_tokens]
                        
                        # If tokens are identical, return all words unchanged
                        if orig_token_texts == new_tokens:
                            return [dict(ws) for ws in word_styles]
                        
                        # Use SequenceMatcher to find changed regions
                        sm = difflib.SequenceMatcher(None, orig_token_texts, new_tokens)
                        
                        # Build output token stream: list of (token_text, ws_idx)
                        # Each output token inherits a word_style from the original.
                        output_tokens = []  # (token_text, ws_idx)
                        
                        for tag, i1, i2, j1, j2 in sm.get_opcodes():
                            if tag == 'equal':
                                # Keep original tokens with their styles
                                for k in range(i1, i2):
                                    output_tokens.append((orig_tokens[k][0], orig_tokens[k][1]))
                            elif tag == 'replace':
                                # New tokens inherit style from first affected original
                                style_ws = orig_tokens[i1][1] if i1 < len(orig_tokens) else 0
                                for new_tok in new_tokens[j1:j2]:
                                    output_tokens.append((new_tok, style_ws))
                            elif tag == 'insert':
                                # New tokens — inherit style from nearest preceding token
                                if i1 > 0:
                                    style_ws = orig_tokens[i1 - 1][1]
                                elif orig_tokens:
                                    style_ws = orig_tokens[0][1]
                                else:
                                    style_ws = 0
                                for new_tok in new_tokens[j1:j2]:
                                    output_tokens.append((new_tok, style_ws))
                            elif tag == 'delete':
                                # Tokens removed — nothing added to output
                                pass
                        
                        # Group consecutive tokens by word_style index
                        # Each group becomes one word_style entry with joined text
                        if not output_tokens:
                            return []
                        
                        result = []
                        current_ws_idx = output_tokens[0][1]
                        current_toks = [output_tokens[0][0]]
                        
                        for tok_text, ws_idx in output_tokens[1:]:
                            if ws_idx == current_ws_idx:
                                current_toks.append(tok_text)
                            else:
                                # Flush current group
                                new_ws = dict(word_styles[current_ws_idx])
                                new_ws['text'] = ' '.join(current_toks)
                                result.append(new_ws)
                                current_ws_idx = ws_idx
                                current_toks = [tok_text]
                        
                        # Flush last group
                        new_ws = dict(word_styles[current_ws_idx])
                        new_ws['text'] = ' '.join(current_toks)
                        result.append(new_ws)
                        
                        return result

                    mapped_words = _build_word_edits(word_styles, original_text, new_text_stripped)

                    # ── BOUNDS CHECK ───────────────────────────────────────
                    # Before using TextWriter (absolute positioning, no wrap),
                    # verify that all words after the move delta will stay
                    # within the page rect.  If ANY word would go off-page,
                    # skip word_styles entirely and fall through to
                    # insert_textbox which wraps text within the bbox rect.
                    page_rect = page.rect
                    words_off_page = False
                    for mw_check in mapped_words:
                        check_x = mw_check.get('origin_x', mw_check.get('left', bbox[0])) + ws_delta_x
                        check_y_top = mw_check.get('top', bbox[1]) + ws_delta_y
                        check_w = mw_check.get('width', 0)
                        check_y_bot = check_y_top + mw_check.get('height', mw_check.get('font_size', font_size))
                        if (check_x < page_rect.x0 - 1 or
                            check_x + check_w > page_rect.x1 + 1 or
                            check_y_top < page_rect.y0 - 1 or
                            check_y_bot > page_rect.y1 + 1):
                            words_off_page = True
                            break

                    # Also check: if the bbox itself extends beyond the page,
                    # word_styles absolute positioning will place text off-page.
                    if not words_off_page:
                        if (bbox[0] < page_rect.x0 - 1 or
                            bbox[2] > page_rect.x1 + 1 or
                            bbox[1] < page_rect.y0 - 1 or
                            bbox[3] > page_rect.y1 + 1):
                            words_off_page = True

                    if words_off_page:
                        print(f"  ⚠ word_styles would place text off-page, falling back to insert_textbox (wrapping)")
                        # Fall through to insert_textbox by raising to the except handler
                        raise ValueError("word_styles off-page — use insert_textbox instead")

                    # Re-insert each word with its own font/size/style
                    tw = fitz.TextWriter(page.rect)
                    ws_font_cache = {}
                    underline_lines = []  # Collect underline drawing commands

                    for mw in mapped_words:
                        mw_text = mw.get('text', '')
                        if not mw_text:
                            continue
                        mw_text = normalize_smart_quotes(mw_text)

                        mw_font_name = mw.get('font', font_name)
                        mw_font_size = mw.get('font_size', font_size)
                        mw_font_weight = mw.get('font_weight')
                        mw_italic = mw.get('italic', False)
                        mw_underline = mw.get('underline', False)
                        mw_font_xref = mw.get('font_xref')

                        # Color
                        mw_hex = mw.get('hex_color') or mw.get('color', '#000000')
                        mw_color = (0, 0, 0)
                        if isinstance(mw_hex, str):
                            nh = mw_hex.strip().lstrip('#')
                            if len(nh) == 6:
                                try:
                                    mw_color = (
                                        int(nh[0:2], 16) / 255.0,
                                        int(nh[2:4], 16) / 255.0,
                                        int(nh[4:6], 16) / 255.0,
                                    )
                                except Exception:
                                    mw_color = text_color

                        # Position — apply move delta so words land at the new location
                        mw_origin_x = mw.get('origin_x', mw.get('left', bbox[0]))
                        mw_origin_y = mw.get('origin_y')
                        if mw_origin_y is None:
                            # Fallback: bottom of word bbox
                            mw_origin_y = mw.get('top', bbox[1]) + mw.get('height', mw_font_size)
                        mw_origin_x += ws_delta_x
                        mw_origin_y += ws_delta_y

                        # Font resolution (with caching)
                        cache_key = f"{mw_font_name}_{mw_font_weight}_{mw_italic}"
                        if cache_key in ws_font_cache:
                            mw_font_obj = ws_font_cache[cache_key]
                        else:
                            mw_font_obj = None
                            # Try full font file
                            ff = get_font_file(mw_font_name, script_dir, font_weight=mw_font_weight)
                            if ff and os.path.exists(ff):
                                mw_font_obj = fitz.Font(fontfile=ff)
                            if not mw_font_obj:
                                # Try embedded font
                                mw_font_obj = get_embedded_font(doc, mw_font_xref, font_name=mw_font_name, page_num=page_num)
                            if not mw_font_obj:
                                mw_font_obj = fitz.Font("helv")
                            ws_font_cache[cache_key] = mw_font_obj

                        tw.append(
                            (mw_origin_x, mw_origin_y),
                            mw_text,
                            font=mw_font_obj,
                            fontsize=mw_font_size,
                        )
                        # TextWriter.write_text only takes one color, but we need
                        # per-word colors.  Flush after each word with its own color.
                        tw.write_text(page, color=mw_color, overlay=True)
                        tw = fitz.TextWriter(page.rect)

                        # Collect underline info for drawing after text insertion
                        if mw_underline:
                            # Measure text width using the font object
                            text_width = mw_font_obj.text_length(mw_text, fontsize=mw_font_size)
                            # Underline position: slightly below the baseline
                            underline_y = mw_origin_y + mw_font_size * 0.15
                            # Line thickness proportional to font size
                            underline_thickness = max(0.5, mw_font_size * 0.05)
                            underline_lines.append({
                                'start': (mw_origin_x, underline_y),
                                'end': (mw_origin_x + text_width, underline_y),
                                'color': mw_color,
                                'width': underline_thickness,
                            })

                    # Draw underline lines on the page
                    for ul in underline_lines:
                        shape = page.new_shape()
                        shape.draw_line(fitz.Point(ul['start']), fitz.Point(ul['end']))
                        shape.finish(color=ul['color'], width=ul['width'])
                        shape.commit(overlay=True)

                    print(f"  ✓ Inserted block via word_styles at ({bbox[0]:.1f}, {bbox[1]:.1f}) with {len(mapped_words)} styled words [TextWriter overlay]" +
                          (f" + {len(underline_lines)} underlines" if underline_lines else ""))
                    edit_count += 1
                    continue
                except Exception as e:
                    print(f"  ⚠ word_styles handling failed: {e}, falling back to rich_html/textbox")
                    import traceback
                    traceback.print_exc()

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
                        edit_count += 1
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

                # Draw underline for block textbox edits
                if edit_underline:
                    ul_y = bbox[3] + font_size * 0.15
                    ul_thickness = max(0.5, font_size * 0.05)
                    shape = page.new_shape()
                    shape.draw_line(fitz.Point(bbox[0], ul_y), fitz.Point(bbox[2], ul_y))
                    shape.finish(color=text_color, width=ul_thickness)
                    shape.commit(overlay=True)
                    print(f"  ✓ Added underline")

                edit_count += 1
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

                # Draw underline for multiline textbox edits
                if edit_underline:
                    ul_y = bbox[3] + font_size * 0.15
                    ul_thickness = max(0.5, font_size * 0.05)
                    shape = page.new_shape()
                    shape.draw_line(fitz.Point(bbox[0], ul_y), fitz.Point(bbox[2], ul_y))
                    shape.finish(color=text_color, width=ul_thickness)
                    shape.commit(overlay=True)
                    print(f"  ✓ Added underline")
            else:
                # Single line: use TextWriter for precise positioning
                tw = fitz.TextWriter(page.rect)
                tw.append((origin_x, origin_y), new_text, font=font_obj, fontsize=font_size)
                tw.write_text(page, color=text_color, overlay=True)
                print(f"  ✓ Text at ({origin_x:.1f}, {origin_y:.1f}) font='{font_label}' [TextWriter overlay]")

                # Draw underline for single-line TextWriter edits
                if edit_underline:
                    text_width = font_obj.text_length(new_text, fontsize=font_size)
                    ul_y = origin_y + font_size * 0.15
                    ul_thickness = max(0.5, font_size * 0.05)
                    shape = page.new_shape()
                    shape.draw_line(fitz.Point(origin_x, ul_y), fitz.Point(origin_x + text_width, ul_y))
                    shape.finish(color=text_color, width=ul_thickness)
                    shape.commit(overlay=True)
                    print(f"  ✓ Added underline")

            edit_count += 1

        print(f"  Page {page_num}: Inserted {edit_count} edited span(s)")

    print("\n" + "="*50)
    print("SAVING")
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
        print("Usage: apply_pdf_edits_simple.py <pdf_path> <edits_json_or_@file>")
        sys.exit(1)

    try:
        edits_arg = sys.argv[2]
        # Support @filename convention: read JSON from file instead of CLI arg
        if edits_arg.startswith('@'):
            edits_file = edits_arg[1:]
            with open(edits_file, 'r') as f:
                edits_arg = f.read()
        apply_edits(sys.argv[1], edits_arg)
        print("\nSUCCESS")
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)
