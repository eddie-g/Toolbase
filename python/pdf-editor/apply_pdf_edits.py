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
from typing import Any, Dict, List, Optional
import re

def _rect_center(rect: fitz.Rect):
    return ((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0)

def _inflate_rect(rect: fitz.Rect, delta: float = 0.5) -> fitz.Rect:
    return fitz.Rect(rect.x0 - delta, rect.y0 - delta, rect.x1 + delta, rect.y1 + delta)


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
        
        # Group edits by page (normalize page numbers to 1-indexed ints)
        # and dedupe repeated payload entries to prevent duplicate insertion.
        edits_by_page = {}
        seen_edit_keys = set()
        for edit in edits_data:
            raw_page_num = edit.get('page_number') if edit.get('page_number') is not None else edit.get('page_num')
            if raw_page_num is None:
                print("    ⚠ Skipping edit without page_number/page_num")
                continue
            try:
                page_num = int(raw_page_num)
            except Exception:
                print(f"    ⚠ Skipping edit with invalid page number: {raw_page_num}")
                continue
            # Defensive normalization for accidental 0-based page indexes.
            if page_num == 0:
                print("    ⚠ Detected page 0 in edits, normalizing to page 1")
                page_num = 1
            if page_num < 1 or page_num > doc.page_count:
                print(f"    ⚠ Skipping edit with out-of-range page number: {page_num}")
                continue
            bbox = edit.get('bbox') or edit.get('original_bbox') or []
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
                bbox_key = tuple(round(float(v), 2) for v in bbox[:4])
            else:
                bbox_key = ()
            dedupe_key = (
                page_num,
                bbox_key,
                ('' if edit.get('new_text') is None else str(edit.get('new_text')).strip()),
            )
            if dedupe_key in seen_edit_keys:
                print(f"    ⊘ Skipping duplicate edit payload on page {page_num}")
                continue
            seen_edit_keys.add(dedupe_key)
            if page_num not in edits_by_page:
                edits_by_page[page_num] = []
            edits_by_page[page_num].append(edit)
        
        print(f"📝 Applying {len(edits_data)} edits across {len(edits_by_page)} pages...")
        
        # PHASE 0: Prepare edited pages for reliable redaction.
        # - clean_contents() normalizes coordinates
        # - wrap_contents() helps flatten Form XObject behavior
        print("\n" + "="*50)
        print("PHASE 0: Normalizing coordinate systems")
        print("="*50)
        for page_num in edits_by_page.keys():
            page = doc[page_num - 1]
            page.clean_contents()
            try:
                page.wrap_contents()
            except Exception as wrap_err:
                print(f"  ⚠ Page {page_num}: wrap_contents() warning: {wrap_err}")
            print(f"  ✓ Page {page_num}: Cleaned contents")

        # Deep-clean pass: save + reopen once so stale object streams/XRefs are rebuilt
        # before any redaction/insertion work.
        preflight_path = pdf_path + '.preflight.tmp'
        try:
            doc.save(
                preflight_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
                clean=True
            )
        except TypeError:
            doc.save(
                preflight_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True
            )
        doc.close()
        doc = fitz.open(preflight_path)
        print("  ✓ Preflight stream rebuild complete (save+reopen)")
        
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
                original_text = '' if edit.get('original_text') is None else str(edit.get('original_text'))
                new_text = '' if edit.get('new_text') is None else str(edit.get('new_text'))
                if not original_text.strip():
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

                # Surgical redaction policy:
                # Always redact the full outer block bbox provided by the editor.
                # This prevents partial scrub / duplicate glyph artifacts.
                found_any = False
                original_rect = None
                if isinstance(original_bbox, (list, tuple)) and len(original_bbox) >= 4:
                    original_rect = fitz.Rect(original_bbox[0], original_bbox[1], original_bbox[2], original_bbox[3])

                # Tight redaction padding minimizes accidental clipping of neighboring glyphs.
                pad = 0.25
                if original_bbox:
                    rect = fitz.Rect(
                        original_bbox[0] - pad,
                        original_bbox[1] - pad,
                        original_bbox[2] + pad,
                        original_bbox[3] + pad
                    )
                    overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                    redact_rect = _inflate_rect(rect, 0.5)
                    if overlaps_image:
                        image_overlap_count += 1
                    page.add_redact_annot(redact_rect, fill=None)
                    redaction_count += 1
                    found_any = True
                    print(f"    ✓ Redacting '{original_text[:30]}...' via outer block bbox")

                    # If tiny/orphan lines were removed from edited text (e.g., standalone "P"),
                    # also redact matching nearby orphan glyphs so they don't survive as separate
                    # PDF text objects after re-extraction.
                    try:
                        original_lines = [ln.strip() for ln in re.split(r'[\r\n]+', original_text) if ln and ln.strip()]
                        new_lines = {ln.strip() for ln in re.split(r'[\r\n]+', new_text) if ln and ln.strip()}
                        removed_tiny = {
                            ln for ln in original_lines
                            if ln not in new_lines and len(ln) <= 2 and re.match(r'^[A-Za-z0-9]+$', ln)
                        }
                        if removed_tiny:
                            vicinity = fitz.Rect(
                                rect.x0 - 12,
                                rect.y0 - 12,
                                rect.x1 + 12,
                                rect.y1 + 12,
                            )
                            words = page.get_text("words")
                            tiny_redactions = 0
                            for w in words:
                                if len(w) < 5:
                                    continue
                                wx0, wy0, wx1, wy1, wtxt = w[:5]
                                token = (str(wtxt) if wtxt is not None else '').strip()
                                if token not in removed_tiny:
                                    continue
                                wrect = fitz.Rect(wx0 - 1, wy0 - 1, wx1 + 1, wy1 + 1)
                                cx = (wrect.x0 + wrect.x1) / 2.0
                                cy = (wrect.y0 + wrect.y1) / 2.0
                                if not (vicinity.x0 <= cx <= vicinity.x1 and vicinity.y0 <= cy <= vicinity.y1):
                                    continue
                                overlaps_image = any(wrect.intersects(img_rect) for img_rect in image_rects)
                                if overlaps_image:
                                    image_overlap_count += 1
                                page.add_redact_annot(_inflate_rect(wrect, 0.5), fill=None)
                                redaction_count += 1
                                tiny_redactions += 1
                            if tiny_redactions > 0:
                                print(f"    ✓ Redacted {tiny_redactions} nearby removed tiny token(s): {sorted(removed_tiny)}")
                    except Exception as tiny_err:
                        print(f"    ⚠ Tiny-token redaction skipped: {tiny_err}")
                else:
                    # Fallback only when bbox is missing.
                    text_instances = page.search_for(original_text)
                    if text_instances:
                        inst_rect = text_instances[0]
                        # Create rect from search result
                        rect = fitz.Rect(
                            inst_rect.x0 - pad, inst_rect.y0 - pad,
                            inst_rect.x1 + pad, inst_rect.y1 + pad
                        )
                        
                        overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                        redact_rect = _inflate_rect(rect, 0.5)
                        if overlaps_image:
                            image_overlap_count += 1
                        page.add_redact_annot(redact_rect, fill=None)
                        redaction_count += 1
                        search_success_count += 1
                    else:
                        print(f"    ✗ Cannot redact '{original_text[:30]}...' - no original_bbox and search failed")
            
            # Apply all redactions at once to physically purge the character streams
            page.apply_redactions(images=0)
            # Rebuild content stream after redaction to avoid ghost/duplicate glyph artifacts.
            try:
                page.clean_contents()
            except Exception as clean_err:
                print(f"    ⚠ page.clean_contents() warning: {clean_err}")
            print(f"    ✓ Redacted {redaction_count} locations ({search_success_count} by search, {image_overlap_count} over images)")

            # ── POST-REDACTION CLEANUP ──────────────────────────────
            # PyMuPDF's apply_redactions() cannot always remove text
            # rendered with CID/TrueType fonts (e.g. /TT2 Verdana-Bold)
            # that use TJ arrays with large glyph displacements.  These
            # orphan glyphs survive redaction even though their rendered
            # position is inside the redaction rect.
            #
            # Fix: re-extract spans after redaction.  Any span whose bbox
            # is INSIDE an edit's original_bbox AND whose text is NOT the
            # new_text being inserted is a residual that must be stripped
            # directly from the content stream.
            post_blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
            residual_spans = []
            for b in post_blocks:
                if b.get("type") != 0:
                    continue
                for l in b.get("lines", []):
                    for s in l.get("spans", []):
                        st = (s.get("text") or "").strip()
                        if not st:
                            continue
                        sb = list(s["bbox"])
                        for edit in page_edits:
                            if edit.get('_skip_insert'):
                                continue
                            ob = edit.get('original_bbox')
                            if not ob or len(ob) < 4:
                                continue
                            new_t = (edit.get('new_text') or '').strip()
                            # Check if span bbox is inside the edit's original_bbox (with tolerance)
                            tol = 3.0
                            if (sb[0] >= ob[0] - tol and sb[1] >= ob[1] - tol and
                                sb[2] <= ob[2] + tol and sb[3] <= ob[3] + tol):
                                # This span is inside the edit rect — it should have been redacted
                                # Skip if it IS the new text (already inserted)
                                if st == new_t:
                                    continue
                                residual_spans.append({
                                    "text": st,
                                    "bbox": sb,
                                    "origin": list(s["origin"]),
                                })

            if residual_spans:
                print(f"    ⚠ Found {len(residual_spans)} residual span(s) that survived redaction:")
                for rs in residual_spans:
                    print(f"      '{rs['text'][:40]}' at bbox {[round(x,1) for x in rs['bbox']]}")

                # Strip residual spans by removing their BT..ET blocks
                # from the content stream directly.
                # We match BT blocks by checking if the block's Tm position
                # (converted to PyMuPDF page coords) is close to the residual
                # span's origin.  This avoids false positives from matching
                # short text like "P" against unrelated blocks containing
                # "PDF", "PASS", etc.
                page_height = page.rect.height
                xrefs = page.get_contents()
                stripped_total = 0
                for xref in xrefs:
                    stream = doc.xref_stream(xref)
                    if not stream:
                        continue
                    bt_blocks = list(re.finditer(rb'BT\b.*?ET\b', stream, re.DOTALL))
                    if not bt_blocks:
                        continue
                    to_remove = set()
                    for m in bt_blocks:
                        blk_bytes = m.group()
                        # Extract the Tm position from the BT block
                        tm_match = re.search(
                            rb'([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+Tm',
                            blk_bytes
                        )
                        if not tm_match:
                            continue
                        # Tm matrix: [a b c d e f] where e=x, f=y in PDF coords
                        tm_f = float(tm_match.group(6))  # y in PDF coords (bottom-up)
                        tm_e = float(tm_match.group(5))  # x in PDF coords
                        # Convert PDF y-coord to PyMuPDF y-coord (top-down)
                        pymupdf_y = page_height - tm_f

                        for rs in residual_spans:
                            rt = rs["text"]
                            ro = rs["origin"]  # [x, y] in PyMuPDF coords
                            # Text must appear as a parenthesized string in the block
                            rt_encoded = b'(' + rt.encode('latin-1', errors='ignore') + b')'
                            if rt_encoded not in blk_bytes:
                                continue
                            # Position check: Tm position (e,f) is in page
                            # coords already.  Allow generous x tolerance
                            # for TJ displacement (e.g. [-2312.1(P)] shifts
                            # the glyph ~37pt right of Tm x origin).
                            x_tol = 50.0
                            y_tol = 5.0
                            if (abs(pymupdf_y - ro[1]) < y_tol and
                                abs(tm_e - ro[0]) < x_tol):
                                to_remove.add((m.start(), m.end()))
                                break

                    if to_remove:
                        # Remove matched BT..ET blocks from the stream
                        # Process in reverse order to preserve offsets
                        new_stream = bytearray(stream)
                        for start, end in sorted(to_remove, reverse=True):
                            new_stream[start:end] = b''
                            stripped_total += 1
                        doc.update_stream(xref, bytes(new_stream))

                if stripped_total:
                    print(f"    ✓ Stripped {stripped_total} residual BT..ET block(s) from content stream")
        
        # PHASE B: Overlay new text at NEW locations
        print("\n" + "="*50)
        print("PHASE B (The Overlay): Inserting text at new locations")
        print("="*50)
        
        insert_failures = 0
        for page_num, page_edits in edits_by_page.items():
            print(f"\n  Page {page_num}: Inserting {len(page_edits)} text blocks...")
            page = doc[page_num - 1]
            
            for edit in page_edits:
                # Skip no-op edits (flagged in Phase A)
                if edit.get('_skip_insert'):
                    print(f"    - Skipping no-op edit (text unchanged)")
                    continue

                new_text = '' if edit.get('new_text') is None else str(edit.get('new_text'))
                if not new_text.strip():
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
                    rc = page.insert_textbox(
                        rect,
                        new_text,
                        fontsize=font_size,
                        fontname=pymupdf_font,
                        color=rgb,
                        align=fitz.TEXT_ALIGN_LEFT,
                        overlay=True  # CRITICAL: Overlay mode prevents image damage
                    )
                    if rc >= 0:
                        print(f"    ✓ Inserted '{new_text[:30]}...' at ({target_bbox[0]:.1f}, {target_bbox[1]:.1f})")
                    else:
                        # insert_textbox can fail without raising (negative rc).
                        # Fallback to baseline-anchored insert_text so text never disappears after redaction.
                        lines = [ln for ln in str(new_text).split('\n')] or ['']
                        line_height = max(font_size * 1.2, 1.0)
                        baseline = rect.y0 + font_size
                        for idx, ln in enumerate(lines):
                            page.insert_text(
                                (rect.x0, baseline + (idx * line_height)),
                                ln,
                                fontsize=font_size,
                                fontname=pymupdf_font,
                                color=rgb,
                                overlay=True
                            )
                        print(f"    ✓ Fallback inserted '{new_text[:30]}...' after textbox rc={rc}")
                except Exception as e:
                    # Fallback to a guaranteed built-in font.
                    try:
                        print(f"    ⚠ Primary insert failed ({e}), retrying with 'helv'")
                        rc = page.insert_textbox(
                            rect,
                            new_text,
                            fontsize=font_size,
                            fontname='helv',
                            color=rgb,
                            align=fitz.TEXT_ALIGN_LEFT,
                            overlay=True
                        )
                        if rc >= 0:
                            print(f"    ✓ Inserted with fallback font 'helv'")
                            continue
                        # textbox failed silently; fallback to insert_text
                        lines = [ln for ln in str(new_text).split('\n')] or ['']
                        line_height = max(font_size * 1.2, 1.0)
                        baseline = rect.y0 + font_size
                        for idx, ln in enumerate(lines):
                            page.insert_text(
                                (rect.x0, baseline + (idx * line_height)),
                                ln,
                                fontsize=font_size,
                                fontname='helv',
                                color=rgb,
                                overlay=True
                            )
                        print(f"    ✓ Fallback inserted with 'helv' after textbox rc={rc}")
                    except Exception as e2:
                        insert_failures += 1
                        print(f"    ✗ Failed to insert text after fallback: {e2}")

        if insert_failures > 0:
            print(f"\n✗ Aborting save: {insert_failures} text insert(s) failed.")
            doc.close()
            return False
        
        # PHASE 3: Save with cleanup
        print("\n" + "="*50)
        print("PHASE 3: Saving PDF")
        print("="*50)
        
        temp_path = pdf_path + '.tmp'
        try:
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
                clean=True,
            )
        except TypeError:
            # Older PyMuPDF may not accept clean=...; keep aggressive GC anyway.
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True
            )
        doc.close()
        
        # Replace original file
        import shutil
        shutil.move(temp_path, pdf_path)
        if os.path.exists(preflight_path):
            os.remove(preflight_path)
        
        print("✓ PDF saved successfully with Search-and-Destroy Protocol")
        print("  - Original text physically removed from data stream")
        print("  - New text overlaid at new positions without image damage")
        return True
        
    except Exception as e:
        print(f"✗ Error applying edits: {e}")
        import traceback
        traceback.print_exc()
        preflight_path = pdf_path + '.preflight.tmp'
        if os.path.exists(preflight_path):
            try:
                os.remove(preflight_path)
            except Exception:
                pass
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

    # Use guaranteed built-in font names supported by PyMuPDF.
    if 'symbol' in font_lower:
        return 'symbol'
    if 'zapf' in font_lower or 'dingbat' in font_lower:
        return 'zapfdingbats'

    if 'courier' in font_lower or 'mono' in font_lower or 'cousine' in font_lower:
        if is_bold and is_italic:
            return 'cobi'
        if is_bold:
            return 'cobo'
        if is_italic:
            return 'coit'
        return 'cour'

    if 'times' in font_lower or 'serif' in font_lower or 'tinos' in font_lower:
        if is_bold and is_italic:
            return 'times-bolditalic'
        if is_bold:
            return 'times-bold'
        if is_italic:
            return 'times-italic'
        return 'times-roman'

    # Default sans family
    if is_bold and is_italic:
        return 'hebi'
    if is_bold:
        return 'hebo'
    if is_italic:
        return 'heit'
    return 'helv'


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
