#!/usr/bin/env python3
"""
Create a clean PDF with all text removed for overlay editing.
Redacts ALL text from the PDF while preserving backgrounds, colors, and images.

IMPORTANT:
- Uses fill=None for redactions to preserve colored backgrounds
- Uses images=0 in apply_redactions() to preserve underlying images
- This removes text "ink" without damaging anything behind it
"""

import sys
import os
import json
import tempfile
import fitz  # PyMuPDF


def _clamped_redact_rect(page, left, top, right, bottom, margin):
    rect = fitz.Rect(
        left - margin,
        top - margin,
        right + margin,
        bottom + margin,
    )
    rect &= page.rect
    if rect.width <= 0 or rect.height <= 0:
        return None
    return rect


def _rect_key(rect):
    # 0.25pt quantization dedupes near-identical rects from duplicate layers
    return (
        round(rect.x0 * 4) / 4,
        round(rect.y0 * 4) / 4,
        round(rect.x1 * 4) / 4,
        round(rect.y1 * 4) / 4,
    )


def _iter_span_bboxes(text_dict):
    for block in text_dict.get("blocks", []):
        if block.get("type", 0) != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                bbox = span.get("bbox")
                if not bbox or len(bbox) < 4:
                    continue
                yield bbox


def create_clean_pdf(pdf_path, extraction_data, output_path):
    """
    Create a clean version of the PDF with all text redacted
    
    Args:
        pdf_path: Path to the original PDF file
        extraction_data: List of page data with word bboxes
        output_path: Path where the clean PDF will be saved
    """
    print(f"📄 Opening PDF: {pdf_path}")
    normalized_path = None

    try:
        # Normalize the PDF first to avoid structural issues after PDF-lib saves.
        # IMPORTANT: Do NOT use clean=True — pdf-lib generates PDF structures
        # (e.g., Form XObjects with Transparency Groups) that cause MuPDF's
        # clean pass to crash with "not a dict (null)".
        doc = fitz.open(pdf_path)
        print(f"✓ PDF opened: {doc.page_count} pages")
        try:
            temp_dir = os.path.dirname(output_path) or "."
            with tempfile.NamedTemporaryFile(prefix="normalized_", suffix=".pdf", dir=temp_dir, delete=False) as tmp:
                normalized_path = tmp.name
            doc.save(normalized_path, garbage=3, deflate=True)
            doc.close()
            doc = fitz.open(normalized_path)
            print(f"✓ Normalized PDF saved: {normalized_path}")
        except Exception as norm_error:
            print(f"⚠ PyMuPDF normalization failed: {norm_error}")
            try:
                doc.close()
            except Exception:
                pass
            
            # Fallback: use qpdf to normalize the PDF.
            # pdf-lib creates structures (ExtGState dicts, Contents arrays)
            # that PyMuPDF cannot save. qpdf rewrites the structure cleanly
            # without touching font encodings (unlike Ghostscript which
            # re-encodes fonts and corrupts space character glyphs).
            import subprocess
            import shutil
            qpdf_path = shutil.which('qpdf')
            if qpdf_path:
                qpdf_output = normalized_path  # Reuse the temp path
                qpdf_cmd = [
                    qpdf_path, '--normalize-content=n',
                    pdf_path, qpdf_output
                ]
                try:
                    result = subprocess.run(qpdf_cmd, capture_output=True, text=True, timeout=60)
                    # qpdf: 0=success, 3=warnings (file still written)
                    if result.returncode in (0, 3) and os.path.exists(qpdf_output) and os.path.getsize(qpdf_output) > 0:
                        doc = fitz.open(qpdf_output)
                        print(f"✓ qpdf normalization succeeded")
                    else:
                        print(f"⚠ qpdf failed (code {result.returncode}), using original")
                        if result.stderr:
                            print(f"  stderr: {result.stderr[:200]}")
                        doc = fitz.open(pdf_path)
                except Exception as qpdf_err:
                    print(f"⚠ qpdf error: {qpdf_err}, using original")
                    doc = fitz.open(pdf_path)
            else:
                print(f"⚠ qpdf not available, using original PDF")
                doc = fitz.open(pdf_path)
        
        total_redactions = 0

        # Process each page
        for page_data in extraction_data:
            page_num = page_data['page_number']
            
            # Skip if page doesn't exist (e.g., after page deletion)
            if page_num > doc.page_count:
                print(f"  ⚠ Skipping page {page_num}: not in document (only {doc.page_count} pages)")
                continue
            
            page = doc[page_num - 1]  # Convert to 0-indexed
            
            extracted_words = page_data.get('words', [])
            live_words = page.get_text("words") or []
            live_text_dict = page.get_text("dict") or {}
            print(
                f"  Processing page {page_num}: "
                f"{len(extracted_words)} extracted words, {len(live_words)} live words"
            )

            # Build a robust redaction set from:
            # 1) extracted words (used by overlay editor),
            # 2) live MuPDF words (captures duplicate/secondary text layers),
            # 3) live span bboxes (captures fragment glyph runs missed by word tokenization).
            REDACT_MARGIN_WORD = 2.5
            REDACT_MARGIN_SPAN = 1.8
            rects = []
            seen_rects = set()

            for word in extracted_words:
                left = word.get('left')
                top = word.get('top')
                width = word.get('width')
                height = word.get('height')
                if left is None or top is None or width is None or height is None:
                    continue
                rect = _clamped_redact_rect(
                    page,
                    left,
                    top,
                    left + width,
                    top + height,
                    REDACT_MARGIN_WORD,
                )
                if not rect:
                    continue
                key = _rect_key(rect)
                if key in seen_rects:
                    continue
                seen_rects.add(key)
                rects.append(rect)

            for word in live_words:
                # get_text("words") returns tuples:
                # (x0, y0, x1, y1, "word", block_no, line_no, word_no)
                if len(word) < 4:
                    continue
                rect = _clamped_redact_rect(
                    page,
                    word[0],
                    word[1],
                    word[2],
                    word[3],
                    REDACT_MARGIN_WORD,
                )
                if not rect:
                    continue
                key = _rect_key(rect)
                if key in seen_rects:
                    continue
                seen_rects.add(key)
                rects.append(rect)

            for bbox in _iter_span_bboxes(live_text_dict):
                rect = _clamped_redact_rect(
                    page,
                    bbox[0],
                    bbox[1],
                    bbox[2],
                    bbox[3],
                    REDACT_MARGIN_SPAN,
                )
                if not rect:
                    continue
                key = _rect_key(rect)
                if key in seen_rects:
                    continue
                seen_rects.add(key)
                rects.append(rect)

            for rect in rects:
                # Redact ALL text with fill=None to preserve backgrounds
                page.add_redact_annot(rect, fill=None)
                total_redactions += 1
            
            # Apply all redactions on this page
            # CRITICAL: images=0 preserves underlying images when removing text on top
            page.apply_redactions(images=0)
            print(f"    ✓ Redacted {len(rects)} text spans on this page")

            # ── Multi-pass residual cleanup ──────────────────────────
            # PyMuPDF's apply_redactions() can leave behind partial glyph
            # fragments (e.g. first/last characters of a span, or text in
            # re-encoded CID fonts). Re-check and re-redact until the
            # page is truly text-free, up to a maximum number of passes.
            MAX_CLEANUP_PASSES = 3
            for cleanup_pass in range(1, MAX_CLEANUP_PASSES + 1):
                residual_words = page.get_text("words") or []
                residual_spans = list(_iter_span_bboxes(
                    page.get_text("dict") or {}
                ))
                if not residual_words and not residual_spans:
                    break  # Page is clean

                cleanup_rects = []
                cleanup_seen = set()
                # Use larger margin on cleanup passes to catch edge glyphs
                CLEANUP_MARGIN = 3.5
                for word in residual_words:
                    if len(word) < 4:
                        continue
                    rect = _clamped_redact_rect(
                        page, word[0], word[1], word[2], word[3],
                        CLEANUP_MARGIN,
                    )
                    if not rect:
                        continue
                    key = _rect_key(rect)
                    if key not in cleanup_seen:
                        cleanup_seen.add(key)
                        cleanup_rects.append(rect)

                for bbox in residual_spans:
                    rect = _clamped_redact_rect(
                        page, bbox[0], bbox[1], bbox[2], bbox[3],
                        CLEANUP_MARGIN,
                    )
                    if not rect:
                        continue
                    key = _rect_key(rect)
                    if key not in cleanup_seen:
                        cleanup_seen.add(key)
                        cleanup_rects.append(rect)

                if not cleanup_rects:
                    break

                for rect in cleanup_rects:
                    page.add_redact_annot(rect, fill=None)
                    total_redactions += 1
                page.apply_redactions(images=0)
                print(
                    f"    ✓ Cleanup pass {cleanup_pass}: re-redacted "
                    f"{len(cleanup_rects)} residual fragments"
                )

            # ── Nuclear cleanup: remove BT…ET text blocks from content stream ──
            # Redaction can leave partial glyph fragments when word bboxes
            # don't perfectly cover the actual glyph extents (kerning, CID fonts,
            # unusual text matrices).  As a final guarantee, parse the raw
            # content stream(s) and strip all BT…ET text drawing blocks.
            final_residual = page.get_text("words") or []
            if final_residual:
                print(
                    f"    ⚠ Page {page_num} still has {len(final_residual)} "
                    f"residual words after {MAX_CLEANUP_PASSES} cleanup passes"
                    " — applying content-stream text removal"
                )
                import re as _re
                _bt_et_re = _re.compile(rb'\bBT\b.*?\bET\b', _re.DOTALL)

                def _strip_text_from_stream(xref, label="content"):
                    """Remove all BT…ET blocks from a single PDF stream."""
                    try:
                        stream = doc.xref_stream(xref)
                        if not stream:
                            return False
                        cleaned = _bt_et_re.sub(b'', stream)
                        if cleaned != stream:
                            doc.update_stream(xref, cleaned)
                            return True
                    except Exception as err:
                        print(f"    ⚠ Stream cleanup error on {label} xref {xref}: {err}")
                    return False

                # 1) Page-level content streams
                for xref in page.get_contents():
                    _strip_text_from_stream(xref, "page-content")

                # 2) Form XObjects referenced by this page.
                #    Text inside XObjects has its own content stream that
                #    page.get_contents() does NOT include, but
                #    page.get_text() DOES read recursively.  If we skip
                #    these, residual text inside XObjects would still
                #    render on the canvas.
                for xobj in page.get_xobjects():
                    xobj_xref = xobj[0]
                    # Only process Form XObjects (subtype /Form)
                    try:
                        xobj_dict = doc.xref_object(xobj_xref)
                        if '/Form' not in xobj_dict:
                            continue
                    except Exception:
                        continue
                    _strip_text_from_stream(xobj_xref, f"XObject:{xobj[1]}")

                # After modifying the content streams, force PyMuPDF to re-read
                page.clean_contents()
                verify_residual = page.get_text("words") or []
                if verify_residual:
                    print(
                        f"    ⚠ Page {page_num} STILL has "
                        f"{len(verify_residual)} residual words after "
                        "content-stream cleanup"
                    )
                else:
                    print(f"    ✓ Content-stream cleanup removed all residual text")
        
        # Save the clean PDF — use fallback strategy if initial save fails
        # NEVER use clean=True — it crashes on pdf-lib generated PDFs
        saved = False
        for save_label, save_kwargs in [
            ("deflate", dict(garbage=3, deflate=True)),
            ("minimal", dict(deflate=True)),
            ("default", dict()),
        ]:
            try:
                doc.save(output_path, **save_kwargs)
                saved = True
                print(f"  ✓ Saved with strategy: {save_label}")
                break
            except Exception as save_err:
                print(f"  ⚠ Save strategy '{save_label}' failed: {save_err}")
        
        doc.close()
        
        if not saved:
            print(f"✗ All save strategies failed")
            if normalized_path and os.path.exists(normalized_path):
                os.remove(normalized_path)
            return False

        if normalized_path and os.path.exists(normalized_path):
            os.remove(normalized_path)

        # Validate the output PDF to catch structural issues early
        try:
            test_doc = fitz.open(output_path)
            _ = test_doc.page_count
            test_doc.close()
        except Exception as validate_error:
            print(f"✗ Clean PDF validation failed: {validate_error}")
            return False
        
        print(f"✓ Clean PDF created: {output_path}")
        print(f"  Total redactions: {total_redactions}")
        return True
        
    except Exception as e:
        print(f"✗ Error creating clean PDF: {e}")
        import traceback
        traceback.print_exc()
        if normalized_path and os.path.exists(normalized_path):
            os.remove(normalized_path)
        return False


def main():
    if len(sys.argv) < 4:
        print("Usage: python3 create_clean_pdf.py <pdf_path> <extraction_json_file> <output_path>")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    extraction_file = sys.argv[2]
    output_path = sys.argv[3]
    
    if not os.path.exists(pdf_path):
        print(f"✗ PDF file not found: {pdf_path}")
        sys.exit(1)
    
    if not os.path.exists(extraction_file):
        print(f"✗ Extraction file not found: {extraction_file}")
        sys.exit(1)
    
    # Load extraction data
    try:
        with open(extraction_file, 'r') as f:
            extraction_data = json.load(f)
    except Exception as e:
        print(f"✗ Error loading extraction file: {e}")
        sys.exit(1)
    
    print("=" * 60)
    print("Clean PDF Creation")
    print("=" * 60)
    print(f"Original PDF: {os.path.basename(pdf_path)}")
    print(f"Output PDF: {os.path.basename(output_path)}")
    print()
    
    # Create clean PDF
    success = create_clean_pdf(pdf_path, extraction_data, output_path)
    
    print()
    if success:
        print("✓ Clean PDF created successfully!")
        sys.exit(0)
    else:
        print("✗ Failed to create clean PDF")
        sys.exit(1)


if __name__ == "__main__":
    main()
