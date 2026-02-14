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

    # ── PRE-EDIT SNAPSHOT ──────────────────────────────────────────────
    # Capture every text span on edited pages BEFORE we redact anything.
    # This is the ground truth we'll verify against after Phase B.
    # Structure: { page_num: [ {text, bbox, font, size, color, flags}, ... ] }
    print("\n" + "="*50)
    print("SNAPSHOT: Capturing pre-edit text for verification")
    print("="*50)
    pre_edit_spans = {}
    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        spans = []
        blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
        for block in blocks:
            if block.get("type") != 0:  # skip image blocks
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
        print(f"  Page {page_num}: captured {len(spans)} text spans")

    # ── PHASE 0.5: SCRUB OVERLAPPING TEXT LAYERS ──────────────────────
    # Some PDFs contain ghost text layers — multiple text spans rendered
    # at the exact same position with slightly different content (e.g.,
    # from font encoding issues or prior overlay edits).  These cause
    # visual duplication in the final PDF.
    #
    # Strategy: Detect overlapping spans via fitz text extraction, then
    # remove the duplicate BT…ET block directly from the content stream.
    # We do NOT use page.apply_redactions() here because it fails on
    # hex-encoded CID fonts (<00470048…> glyph IDs).
    #
    # Heuristic for choosing which span to KEEP:
    #   – If one text is a truncated prefix of the other, keep the LONGER
    #     (more complete) version.
    #   – If the texts diverge early, keep the SHORTER version (the longer
    #     one typically has garbled glyph names appended).
    print("\n" + "="*50)
    print("PHASE 0.5: Scrubbing overlapping text layers")
    print("="*50)
    overlap_scrub_count = 0
    scrubbed_overlap_texts = {}

    for page_num in edits_by_page.keys():
        page = doc[page_num - 1]
        spans = pre_edit_spans.get(page_num, [])
        if len(spans) < 2:
            continue

        # ── Step 1: Find groups of overlapping spans ──
        processed = set()
        overlap_groups = []  # each group = list of span indices

        for i in range(len(spans)):
            if i in processed:
                continue
            si_rect = fitz.Rect(spans[i]["bbox"])
            si_size = spans[i].get("size", 12)
            si_text = spans[i]["text"]
            group = [i]
            for j in range(i + 1, len(spans)):
                if j in processed:
                    continue
                sj_rect = fitz.Rect(spans[j]["bbox"])
                sj_size = spans[j].get("size", 12)
                sj_text = spans[j]["text"]

                # ── Guard 1: Font-size ratio ──
                # True duplicates use the same (or very similar) font size.
                # A 40pt heading vs 9pt body text is never a duplicate pair.
                max_size = max(si_size, sj_size)
                min_size = max(min(si_size, sj_size), 0.1)  # floor to avoid /0
                if max_size / min_size > 1.5:
                    continue

                # ── Guard 2: Vertical centre proximity ──
                # True duplicates sit at the *same* baseline.  If vertical
                # centres are more than half the smaller span height apart,
                # they're stacked, not overlapping.
                si_cy = (si_rect.y0 + si_rect.y1) / 2
                sj_cy = (sj_rect.y0 + sj_rect.y1) / 2
                smaller_h = min(si_rect.height, sj_rect.height)
                if abs(si_cy - sj_cy) > smaller_h * 0.5:
                    continue

                # Same start-x?
                if abs(sj_rect.x0 - si_rect.x0) > 2:
                    continue
                inter = si_rect & sj_rect
                if inter.is_empty:
                    continue
                inter_area = inter.get_area()
                smaller_area = min(max(1e-6, si_rect.get_area()),
                                   max(1e-6, sj_rect.get_area()))
                if inter_area / smaller_area < 0.5:
                    continue

                # ── Guard 3: Text similarity ──
                # True duplicates share significant text content.
                # Require either: a) one text contains the other, or
                # b) texts share a prefix ≥ 40% of the shorter text.
                min_tlen = min(len(si_text), len(sj_text))
                if min_tlen == 0:
                    continue
                # Check containment
                is_contained = (si_text in sj_text or sj_text in si_text)
                # Check prefix overlap
                common_prefix = 0
                for k in range(min_tlen):
                    if si_text[k] == sj_text[k]:
                        common_prefix += 1
                    else:
                        break
                has_prefix = common_prefix / min_tlen >= 0.4

                if not is_contained and not has_prefix:
                    continue

                group.append(j)
            if len(group) > 1:
                for idx in group:
                    processed.add(idx)
                overlap_groups.append(group)

        if not overlap_groups:
            continue

        # ── helper: pick the best span from a group ──
        def _pick_best(grp):
            if len(grp) == 2:
                ta = spans[grp[0]]["text"]
                tb = spans[grp[1]]["text"]
                min_len = min(len(ta), len(tb))
                if min_len == 0:
                    return grp[0]
                common = 0
                for k in range(min_len):
                    if ta[k] == tb[k]:
                        common += 1
                    else:
                        break
                if common / min_len >= 0.8:
                    # One is a truncated prefix → keep the longer text
                    return grp[0] if len(ta) >= len(tb) else grp[1]
                else:
                    # Texts diverge → keep the shorter (cleaner) version
                    return grp[0] if len(ta) <= len(tb) else grp[1]
            return min(grp, key=lambda idx: len(spans[idx]["text"]))

        # ── Step 2: For each group, identify the span to SCRUB ──
        scrub_bboxes = []  # (bbox, text) of spans to remove
        scrubbed_texts = set()
        for group in overlap_groups:
            best_idx = _pick_best(group)
            for idx in group:
                label = "KEEP" if idx == best_idx else "SCRUB"
                sr = fitz.Rect(spans[idx]["bbox"])
                print(f"  Page {page_num}: {label} '{spans[idx]['text'][:60]}' "
                      f"[{sr.x0:.1f},{sr.y0:.1f},{sr.x1:.1f},{sr.y1:.1f}]")
                if idx != best_idx:
                    scrubbed_texts.add(spans[idx]["text"])
                    overlap_scrub_count += 1
                    scrub_bboxes.append(spans[idx]["bbox"])

        if not scrub_bboxes:
            continue

        # ── Step 3: Remove duplicate STANDALONE BT…ET blocks ──────────
        # We target only "standalone" BT blocks: those with exactly one
        # Tm, zero Td operators, and at most one TJ/Tj.  Multi-line
        # blocks (with Td movements and multiple TJ operators) contain
        # other content and must NOT be removed wholesale.
        #
        # For matching, we collect ALL overlap-group positions (not just
        # the scrub targets) so we catch standalone blocks at that row
        # regardless of which duplicate the span-level heuristic chose.
        page_height = page.rect.height
        _bt_et_re = re.compile(rb'\bBT\b(.*?)\bET\b', re.DOTALL)
        _tm_re = re.compile(
            rb'([\d.e+-]+)\s+([\d.e+-]+)\s+([\d.e+-]+)\s+([\d.e+-]+)\s+'
            rb'([\d.e+-]+)\s+([\d.e+-]+)\s+Tm\b'
        )
        _td_re = re.compile(rb'\bTd\b')
        _tj_re = re.compile(rb'(?:\)\s*Tj|\]\s*TJ)')

        # Collect ALL overlap positions (page coords) from ALL groups
        overlap_positions_page = []
        for group in overlap_groups:
            for idx in group:
                bbox = spans[idx]["bbox"]
                center_y = (bbox[1] + bbox[3]) / 2.0
                overlap_positions_page.append((bbox[0], center_y))

        removed_blocks = 0
        for xref in page.get_contents():
            try:
                stream = doc.xref_stream(xref)
                if not stream:
                    continue
            except Exception:
                continue

            def _replace_bt(match):
                nonlocal removed_blocks
                bt_body = match.group(1)

                # Only consider standalone blocks
                tms = _tm_re.findall(bt_body)
                if len(tms) != 1:
                    return match.group(0)
                if _td_re.search(bt_body):
                    return match.group(0)
                if len(_tj_re.findall(bt_body)) > 1:
                    return match.group(0)

                # Extract page-coordinate origin
                try:
                    tx = float(tms[0][4])
                    ty = float(tms[0][5])
                except (ValueError, IndexError):
                    return match.group(0)
                page_y = page_height - ty

                # Does this standalone block sit at an overlap position?
                for ox, oy in overlap_positions_page:
                    if abs(tx - ox) < 3 and abs(page_y - oy) < 8:
                        removed_blocks += 1
                        print(f"  Page {page_num}: removed standalone BT at "
                              f"page ({tx:.1f},{page_y:.1f})")
                        return b''  # strip entire BT…ET
                return match.group(0)

            cleaned = _bt_et_re.sub(_replace_bt, stream)
            if cleaned != stream:
                doc.update_stream(xref, cleaned)

        if removed_blocks > 0:
            page.clean_contents()
            print(f"  Page {page_num}: removed {removed_blocks} duplicate BT block(s)")
        else:
            # No standalone BT blocks matched.  Do NOT fall back to redaction:
            # overlapping duplicate spans share nearly the same bbox, so
            # redacting the SCRUB copy also destroys the KEEP copy.
            # It is safer to leave harmless visual duplicates than to risk
            # deleting content.
            print(f"  Page {page_num}: no standalone BT blocks matched – "
                  f"skipping (safe: duplicates are visually harmless)")

        scrubbed_overlap_texts[page_num] = scrubbed_texts
        print(f"  Page {page_num}: processed {len(overlap_groups)} overlap group(s)")

    if overlap_scrub_count > 0:
        print(f"  ★ Total overlapping spans scrubbed: {overlap_scrub_count}")
        # Re-capture spans after overlap scrub so pre_edit_spans is accurate
        for page_num in scrubbed_overlap_texts:
            page = doc[page_num - 1]
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
            print(f"  Page {page_num}: re-captured {len(spans)} spans after overlap scrub")
    else:
        print("  ✓ No overlapping text layers found")

    # Build a set of texts that are being INTENTIONALLY edited or deleted.
    # We key by (page_num, normalised_text) so we know what the user touched.
    edited_texts_by_page = {}
    for page_num, page_edits in edits_by_page.items():
        touched = set()
        for edit in page_edits:
            # Add BOTH original and new text as "touched" — neither should be
            # recovered by Phase C.  This prevents re-inserting old underlying
            # text that happens to share words with the edit.
            for text_key in ("original_text", "new_text"):
                txt = (edit.get(text_key) or "").strip()
                if txt:
                    touched.add(txt)
                    for line in txt.split("\n"):
                        line = line.strip()
                        if line:
                            touched.add(line)
                    for word in txt.split():
                        word = word.strip()
                        if len(word) > 1:
                            touched.add(word)
        edited_texts_by_page[page_num] = touched

    # Merge scrubbed overlap texts into edited_texts_by_page so Phase C
    # knows NOT to recover them as "collateral damage".
    for page_num, scrubbed_set in scrubbed_overlap_texts.items():
        if page_num not in edited_texts_by_page:
            edited_texts_by_page[page_num] = set()
        for txt in scrubbed_set:
            edited_texts_by_page[page_num].add(txt)
            for word in txt.split():
                word = word.strip()
                if len(word) > 1:
                    edited_texts_by_page[page_num].add(word)
    
    # Also build a set of redaction rects per page so we know exactly
    # which areas are being redacted (to detect collateral damage).
    # CRITICAL: These rects MUST match the adaptive padding used in Phase A,
    # otherwise Phase C will "recover" text that was intentionally redacted
    # (e.g., old underlying text from prior overlay edits).
    redaction_rects_by_page = {}
    intentional_edit_rects_by_page = {}
    for page_num, page_edits in edits_by_page.items():
        rects = []
        intentional_rects = []
        for edit in page_edits:
            original_bbox = edit.get("original_bbox")
            if original_bbox:
                # Use the SAME adaptive padding as Phase A for accurate matching
                try:
                    edit_font_size = float(edit.get('font_size', 12))
                except Exception:
                    edit_font_size = 12.0
                pad_left = max(1.0, edit_font_size * 0.15)
                pad_top = max(1.0, edit_font_size * 0.15)
                pad_right = max(2.0, edit_font_size * 0.9)
                pad_bottom = max(1.0, edit_font_size * 0.15)

                # ── Neighbor-aware padding clamp (pre-computation) ────
                # Prevent pad_top/pad_bottom from extending the redaction
                # rect into adjacent non-edited spans.
                _edit_text = (edit.get('original_text') or '').strip()
                _edit_rect = fitz.Rect(original_bbox)
                for _span in pre_edit_spans.get(page_num, []):
                    _sb = _span.get("bbox")
                    if not _sb or len(_sb) != 4:
                        continue
                    _sr = fitz.Rect(_sb)
                    # Skip spans with no horizontal overlap with the ORIGINAL edit area
                    # (use small margin, NOT pad_right which extends far for glyph cleanup)
                    if _sr.x1 <= _edit_rect.x0 - 2 or _sr.x0 >= _edit_rect.x1 + 2:
                        continue
                    # Skip the edited span itself
                    if (abs(_sr.x0 - _edit_rect.x0) < 2 and abs(_sr.y0 - _edit_rect.y0) < 2
                            and _span.get("text", "").strip() == _edit_text):
                        continue
                    # Clamp pad_bottom: don't extend into a span below
                    if _sr.y1 > _edit_rect.y1 and _sr.y0 < _edit_rect.y1 + pad_bottom:
                        pad_bottom = _sr.y0 - _edit_rect.y1 - 0.5
                    # Clamp pad_top: don't extend into a span above
                    if _sr.y0 < _edit_rect.y0 and _sr.y1 > _edit_rect.y0 - pad_top:
                        pad_top = _edit_rect.y0 - _sr.y1 - 0.5

                padded_rect = fitz.Rect(
                    original_bbox[0] - pad_left,
                    original_bbox[1] - pad_top,
                    original_bbox[2] + pad_right,
                    original_bbox[3] + pad_bottom,
                )
                intentional_rects.append(padded_rect)
                rects.append(padded_rect)

                # Include overlapping text layers that start at the same position.
                # These are ghost text from prior overlay edits and will be scrubbed
                # by Phase A's extended redaction. We must include them here so
                # Phase C doesn't try to "recover" them.
                edit_rect = fitz.Rect(original_bbox)
                page_spans = pre_edit_spans.get(page_num, [])
                for span in page_spans:
                    sb = span.get("bbox")
                    if not sb or len(sb) != 4:
                        continue
                    span_rect = fitz.Rect(sb)
                    # Same row + same start position
                    if span_rect.y1 < edit_rect.y0 - 1 or span_rect.y0 > edit_rect.y1 + 1:
                        continue
                    if abs(span_rect.x0 - edit_rect.x0) > 2:
                        continue
                    # If span extends beyond the padded rect, add that extension
                    if span_rect.x1 > padded_rect.x1:
                        ext_rect = fitz.Rect(
                            padded_rect.x1 - 1,
                            span_rect.y0 - 1,
                            span_rect.x1 + pad_right,
                            span_rect.y1 + 1
                        )
                        rects.append(ext_rect)
                        intentional_rects.append(ext_rect)

        redaction_rects_by_page[page_num] = rects
        intentional_edit_rects_by_page[page_num] = intentional_rects
    
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
            original_text = edit.get('original_text') or ''
            new_text = edit.get('new_text') or ''
            original_bbox = edit.get('original_bbox')
            edit_font_size = edit.get('font_size', 12)

            # Skip entirely empty original text — nothing to remove
            if not original_text or not original_text.strip():
                continue

            # SKIP NO-OP EDITS: If text is identical, position hasn't moved,
            # AND no style changes, there's nothing to scrub or re-insert.
            bbox = edit.get('bbox')
            if original_text.strip() == new_text.strip() and original_bbox and bbox:
                # Check if position essentially unchanged (within 2pt tolerance)
                pos_unchanged = (abs(original_bbox[0] - bbox[0]) < 2 and
                                 abs(original_bbox[1] - bbox[1]) < 2 and
                                 abs(original_bbox[2] - bbox[2]) < 2 and
                                 abs(original_bbox[3] - bbox[3]) < 2)
                # Check if style changed (font_weight, font_style, color)
                has_style_change = False
                fw = edit.get('font_weight')
                if fw and str(fw) not in ('400', 'normal', ''):
                    has_style_change = True
                fs = edit.get('font_style')
                if fs and fs not in ('normal', ''):
                    has_style_change = True
                # rich_html or word_styles indicate styled content that must be re-inserted
                if edit.get('rich_html') or edit.get('word_styles'):
                    has_style_change = True
                if pos_unchanged and not has_style_change:
                    print(f"  ⊘ Skipping no-op edit for '{original_text[:30]}...' (text, position & style unchanged)")
                    edit['_skip_insert'] = True  # Flag to skip Phase B too
                    continue

            # PRIMARY SCRUB METHOD: Use original_bbox directly.
            # This is the most reliable approach because:
            # 1. search_for() fails for multi-line text
            # 2. search_for() can match text at WRONG locations (duplicates)
            # 3. original_bbox is the exact known location from extraction data
            if original_bbox:
                # Add adaptive padding around the original block before redaction.
                # Some PDFs contain trailing symbol/private-use glyphs that sit just
                # outside extraction bboxes; tight boxes leave visual "tofu" artifacts.
                try:
                    font_size_num = float(edit_font_size or 12)
                except Exception:
                    font_size_num = 12.0
                pad_left = max(1.0, font_size_num * 0.15)
                pad_top = max(1.0, font_size_num * 0.15)
                pad_right = max(2.0, font_size_num * 0.9)
                pad_bottom = max(1.0, font_size_num * 0.15)

                # ── Neighbor-aware padding clamp (Phase A) ────────────
                # Prevent pad_top/pad_bottom from extending the redaction
                # rect into adjacent non-edited spans.
                _edit_rect = fitz.Rect(original_bbox)
                for _span in pre_edit_spans.get(page_num, []):
                    _sb = _span.get("bbox")
                    if not _sb or len(_sb) != 4:
                        continue
                    _sr = fitz.Rect(_sb)
                    # Use original edit area + small margin for horizontal check
                    if _sr.x1 <= _edit_rect.x0 - 2 or _sr.x0 >= _edit_rect.x1 + 2:
                        continue
                    if (abs(_sr.x0 - _edit_rect.x0) < 2 and abs(_sr.y0 - _edit_rect.y0) < 2
                            and _span.get("text", "").strip() == original_text.strip()):
                        continue
                    if _sr.y1 > _edit_rect.y1 and _sr.y0 < _edit_rect.y1 + pad_bottom:
                        safe = _sr.y0 - _edit_rect.y1 - 0.5  # allow negative to shrink away
                        if safe < pad_bottom:
                            print(f"    ⚠ Clamping pad_bottom {pad_bottom:.1f}→{safe:.1f} "
                                  f"to protect '{_span.get('text','')[:30]}'")
                            pad_bottom = safe
                    if _sr.y0 < _edit_rect.y0 and _sr.y1 > _edit_rect.y0 - pad_top:
                        safe = _edit_rect.y0 - _sr.y1 - 0.5  # allow negative to shrink away
                        if safe < pad_top:
                            print(f"    ⚠ Clamping pad_top {pad_top:.1f}→{safe:.1f} "
                                  f"to protect '{_span.get('text','')[:30]}'")
                            pad_top = safe

                rect = fitz.Rect(
                    original_bbox[0] - pad_left,
                    original_bbox[1] - pad_top,
                    original_bbox[2] + pad_right,
                    original_bbox[3] + pad_bottom
                )
                page.add_redact_annot(rect, fill=None)
                redaction_count += 1
                print(
                    f"  ✓ Redacting '{original_text[:30]}...' via original_bbox "
                    f"[{original_bbox[0]:.0f},{original_bbox[1]:.0f},{original_bbox[2]:.0f},{original_bbox[3]:.0f}] "
                    f"pad(L={pad_left:.1f},R={pad_right:.1f},T={pad_top:.1f},B={pad_bottom:.1f})"
                )

                # Secondary scrub: remove adjacent symbol/replacement glyph spans that
                # often survive when they are encoded as separate tiny runs.
                special_spans = pre_edit_spans.get(page_num, [])
                for span in special_spans:
                    span_text = span.get("text", "")
                    if not span_text:
                        continue
                    if not re.search(r'[\uFFFD\uE000-\uF8FF]', span_text):
                        continue
                    sb = span.get("bbox")
                    if not sb or len(sb) != 4:
                        continue
                    span_rect = fitz.Rect(sb)
                    same_row = not (span_rect.y1 < rect.y0 or span_rect.y0 > rect.y1)
                    near_right_edge = span_rect.x0 <= (rect.x1 + (font_size_num * 1.25)) and span_rect.x1 >= (rect.x0 - 2)
                    if same_row and near_right_edge:
                        cleanup_rect = fitz.Rect(span_rect.x0 - 1, span_rect.y0 - 1, span_rect.x1 + 1, span_rect.y1 + 1)
                        page.add_redact_annot(cleanup_rect, fill=None)
                        redaction_count += 1
                        print(f"    ↳ Redacting adjacent special span '{span_text.encode('unicode_escape').decode()}' at {list(cleanup_rect)}")

                # Overlapping text layer cleanup: if there are OTHER text spans
                # at the same vertical position whose horizontal extent overlaps
                # significantly with this edit's original_bbox, they are likely
                # ghost text from prior overlay edits (e.g., "violate" hiding
                # behind "violated").  Extend the redaction to cover them too.
                edit_rect = fitz.Rect(original_bbox)
                all_page_spans = pre_edit_spans.get(page_num, [])
                for span in all_page_spans:
                    sb = span.get("bbox")
                    if not sb or len(sb) != 4:
                        continue
                    span_rect = fitz.Rect(sb)
                    # Same row check (vertical overlap)
                    if span_rect.y1 < edit_rect.y0 - 1 or span_rect.y0 > edit_rect.y1 + 1:
                        continue
                    # Horizontal overlap: spans must share the same starting position
                    if abs(span_rect.x0 - edit_rect.x0) > 2:
                        continue
                    # If span extends beyond the existing redaction rect, extend it
                    if span_rect.x1 > rect.x1:
                        extra_rect = fitz.Rect(
                            rect.x1 - 1,
                            span_rect.y0 - 1,
                            span_rect.x1 + max(2.0, font_size_num * 0.9),
                            span_rect.y1 + 1
                        )
                        page.add_redact_annot(extra_rect, fill=None)
                        redaction_count += 1
                        span_text_preview = span.get("text", "")[:40]
                        print(f"    ↳ Extending redaction for overlapping span '{span_text_preview}' to x={span_rect.x1:.1f}")
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

    # ── PHASE C: COLLATERAL DAMAGE RECOVERY (OPTIONAL) ─────────────────
    # This pass can create false-positive "recoveries" (duplicate text) on
    # complex PDFs where span segmentation changes after edits. Keep it OFF
    # by default; enable only for targeted troubleshooting.
    enable_collateral_recovery = str(os.getenv("PDF_EDIT_ENABLE_COLLATERAL_RECOVERY", "0")).strip().lower() in {"1", "true", "yes", "on"}
    print("\n" + "="*50)
    print("PHASE C (VERIFY): Checking for collateral text loss")
    print("="*50)

    recovered_count = 0
    if not enable_collateral_recovery:
        print("  ⊘ Collateral recovery disabled (set PDF_EDIT_ENABLE_COLLATERAL_RECOVERY=1 to enable)")
    else:
        for page_num in edits_by_page.keys():
            page = doc[page_num - 1]
            touched_texts = edited_texts_by_page.get(page_num, set())
            redact_rects = redaction_rects_by_page.get(page_num, [])
            intentional_rects = intentional_edit_rects_by_page.get(page_num, [])
            pre_spans = pre_edit_spans.get(page_num, [])

            if not pre_spans:
                continue

            # Collect current (post-edit) span texts for this page
            post_texts = set()
            post_blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
            for block in post_blocks:
                if block.get("type") != 0:
                    continue
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        t = span.get("text", "").strip()
                        if t:
                            post_texts.add(t)

            # Check each pre-edit span
            for span_info in pre_spans:
                span_text = span_info["text"]
                normalized_span_text = normalize_smart_quotes(span_text).strip()

                # Never recover artifact glyphs (replacement chars, private-use symbols,
                # zero-width/control chars). These are typically encoding debris and
                # should be removed during scrub, not re-inserted.
                if (
                    not normalized_span_text
                    or re.search(r'[\uFFFD\uE000-\uF8FF\u200B\u200C\u200D\u2060\uFEFF]', span_text)
                    or re.search(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', span_text)
                ):
                    continue

                # Already present in post-edit PDF → no loss
                if span_text in post_texts:
                    continue

                # Was this span part of an intentional edit/deletion?
                # Check if the span text appears in any of the touched texts
                is_intentional = False
                for touched in touched_texts:
                    if span_text in touched or touched in span_text:
                        is_intentional = True
                        break
                if is_intentional:
                    continue

                # Was this span inside a redaction rectangle? (collateral damage)
                span_rect = fitz.Rect(span_info["bbox"])

                # If the span is inside one of the user's intentional edit boxes,
                # never recover it. Recovery is only for accidental neighboring loss.
                is_inside_intentional_edit = False
                for i_rect in intentional_rects:
                    inter = span_rect & i_rect
                    if inter.is_empty:
                        continue
                    span_area = max(1e-6, span_rect.get_area())
                    overlap_ratio = inter.get_area() / span_area
                    center_x = (span_rect.x0 + span_rect.x1) / 2.0
                    center_y = (span_rect.y0 + span_rect.y1) / 2.0
                    center_inside = (i_rect.x0 <= center_x <= i_rect.x1 and i_rect.y0 <= center_y <= i_rect.y1)
                    if overlap_ratio >= 0.5 or center_inside:
                        is_inside_intentional_edit = True
                        break

                if is_inside_intentional_edit:
                    continue

                # Check if span was inside the actual redaction zone.
                # If it was, the text was INTENTIONALLY removed (it was within the
                # padded redaction area of an edit). Do NOT recover it — it could be
                # old underlying text from a prior overlay edit (e.g., "Isartor"
                # hiding behind "Modified" from a previous save).
                was_in_redaction = False
                for r_rect in redact_rects:
                    if not (span_rect & r_rect).is_empty:
                        was_in_redaction = True
                        break

                if was_in_redaction:
                    print(f"  ⊘ Skipping recovery of '{span_text}' — inside redaction zone (likely old underlying text)")
                    continue

                if span_text not in post_texts:
                    # Text disappeared but wasn't in a redaction zone → unusual
                    # Could be coordinate-system shift or font issue; still recover it
                    print(f"  ⚠ '{span_text}' missing (not in redaction zone) – recovering")

                # ── Re-insert the lost span ──
                origin = span_info["origin"]
                font_size_s = span_info["size"]
                span_color_int = span_info["color"]

                # Convert integer color (sRGB packed) to (r, g, b) 0-1
                if isinstance(span_color_int, int):
                    r = ((span_color_int >> 16) & 0xFF) / 255.0
                    g = ((span_color_int >>  8) & 0xFF) / 255.0
                    b = ( span_color_int        & 0xFF) / 255.0
                    span_color = (r, g, b)
                else:
                    span_color = (0, 0, 0)

                # Try to use the original font
                span_font_name = span_info.get("font", "")
                font_obj = None

                # Try full font file
                font_file = get_font_file(span_font_name, script_dir)
                if font_file and os.path.exists(font_file):
                    font_obj = fitz.Font(fontfile=font_file)

                if not font_obj:
                    # Try embedded font by name
                    font_obj = get_embedded_font(doc, None, font_name=span_font_name, page_num=page_num)

                if not font_obj:
                    font_obj = fitz.Font("helv")

                tw = fitz.TextWriter(page.rect)
                tw.append((origin[0], origin[1]), span_text, font=font_obj, fontsize=font_size_s)
                tw.write_text(page, color=span_color, overlay=True)
                recovered_count += 1
                print(f"  ✓ Recovered '{span_text}' at ({origin[0]:.1f}, {origin[1]:.1f})")

        if recovered_count > 0:
            print(f"\n  ★ Recovered {recovered_count} collateral-damage span(s)")
        else:
            print("  ✓ No collateral text loss detected")

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
