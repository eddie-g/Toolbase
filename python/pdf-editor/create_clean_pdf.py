#!/usr/bin/env python3
"""
Create a clean PDF with all text removed for overlay editing.

Implementation note:
- Redaction annotations paint fill rectangles into the output PDF.
- That is acceptable for preview-only cleanup, but not for overlay rebuilds:
  when a user deletes a paragraph, any baked fill patch under that text becomes
  visible and can cover nearby rules / table lines.
- For the clean overlay base, strip text drawing operators from content streams
  instead. This removes text while preserving vector lines, fills, and images.
"""

import sys
import os
import json
import tempfile
import re
import fitz  # PyMuPDF


def _clamped_redact_rect(page, left, top, right, bottom, margin_x, margin_y=None):
    if margin_y is None:
        margin_y = margin_x
    rect = fitz.Rect(
        left - margin_x,
        top - margin_y,
        right + margin_x,
        bottom + margin_y,
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


def _inflate_rect(rect, x_pad, y_pad=None):
    if y_pad is None:
        y_pad = x_pad
    return fitz.Rect(
        rect.x0 - x_pad,
        rect.y0 - y_pad,
        rect.x1 + x_pad,
        rect.y1 + y_pad,
    )


_BT_ET_RE = re.compile(rb'\bBT\b.*?\bET\b', re.DOTALL)
_SIMPLE_LINE_BLOCK_RE = re.compile(
    rb'q\s+'
    rb'([-\d.]+)\s+0\s+0\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+cm\s+'
    rb'1\s+J\s+'
    rb'([-\d.]+)\s+w\s+'
    rb'([-\d.]+)\s+([-\d.]+)\s+m\s+'
    rb'([-\d.]+)\s+([-\d.]+)\s+l\s+'
    rb'0\s+0\s+0\s+RG\s+'
    rb'S\s+Q\s*',
    re.DOTALL,
)


def _strip_text_sections(stream):
    if not stream:
        return stream

    cleaned = _BT_ET_RE.sub(b'', stream)
    bt_count = len(re.findall(rb'\bBT\b', stream))
    et_count = len(re.findall(rb'\bET\b', stream))

    # Some PDFs split a logical text object across multiple page content
    # streams. After page.read_contents() concatenates them, we may still see a
    # dangling BT or ET at the page edges. Trim those too.
    if bt_count > et_count:
        cleaned = re.sub(rb'\bBT\b.*\Z', b'', cleaned, flags=re.DOTALL)
    elif et_count > bt_count:
        cleaned = re.sub(rb'\A.*?\bET\b', b'', cleaned, flags=re.DOTALL)

    return cleaned


def _strip_text_from_stream(doc, xref):
    try:
        stream = doc.xref_stream(xref)
        if not stream:
            return False
        cleaned = _strip_text_sections(stream)
        if cleaned == stream:
            return False
        doc.update_stream(xref, cleaned)
        return True
    except Exception:
        return False


def _apply_redactions_compat(page):
    try:
        page.apply_redactions(images=0, graphics=0)
        return
    except TypeError:
        pass

    try:
        page.apply_redactions(images=0)
        return
    except TypeError:
        pass

    page.apply_redactions()


def _collect_simple_stroke_lines(page):
    lines = []
    try:
        drawings = page.get_drawings() or []
    except Exception:
        return lines

    for drawing in drawings:
        if drawing.get("type") != "s":
            continue
        items = drawing.get("items") or []
        if len(items) != 1 or not items[0] or items[0][0] != "l":
            continue
        rect = drawing.get("rect")
        if not rect:
            continue
        lines.append({
            "x0": float(rect.x0),
            "y0": float(rect.y0),
            "x1": float(rect.x1),
            "y1": float(rect.y1),
            "width": float(drawing.get("width") or 0),
        })
    return lines


def _line_rects_match(a, b, tol=0.75):
    return (
        abs(a["x0"] - b["x0"]) <= tol
        and abs(a["y0"] - b["y0"]) <= tol
        and abs(a["x1"] - b["x1"]) <= tol
        and abs(a["y1"] - b["y1"]) <= tol
        and abs(a.get("width", 0) - b.get("width", 0)) <= max(0.25, tol * 0.5)
    )


def _extract_simple_line_block(match):
    sx = float(match.group(1))
    sy = float(match.group(2))
    tx = float(match.group(3))
    ty = float(match.group(4))
    width = float(match.group(5))
    x0_raw = float(match.group(6))
    y0_raw = float(match.group(7))
    x1_raw = float(match.group(8))
    y1_raw = float(match.group(9))
    x0 = (sx * x0_raw) + tx
    y0 = (sy * y0_raw) + ty
    x1 = (sx * x1_raw) + tx
    y1 = (sy * y1_raw) + ty
    return {
        "x0": min(x0, x1),
        "y0": min(y0, y1),
        "x1": max(x0, x1),
        "y1": max(y0, y1),
        "width": abs(width * sx) if sx else abs(width),
    }


def _strip_artifact_line_blocks(doc, page, original_lines):
    if not original_lines:
        return 0

    current_lines = _collect_simple_stroke_lines(page)
    artifact_lines = [
        line for line in current_lines
        if not any(_line_rects_match(line, original_line) for original_line in original_lines)
    ]
    if not artifact_lines:
        return 0

    try:
        stream = page.read_contents()
    except Exception:
        return 0
    if not stream:
        return 0

    rebuilt = []
    last_end = 0
    removed = 0
    for match in _SIMPLE_LINE_BLOCK_RE.finditer(stream):
        line = _extract_simple_line_block(match)
        should_strip = any(_line_rects_match(line, artifact_line) for artifact_line in artifact_lines)
        if should_strip:
            rebuilt.append(stream[last_end:match.start()])
            last_end = match.end()
            removed += 1

    if removed == 0:
        return 0

    rebuilt.append(stream[last_end:])
    cleaned_stream = b"".join(rebuilt)
    content_xrefs = page.get_contents() or []
    if not content_xrefs:
        return 0

    try:
        primary_xref = content_xrefs[0]
        doc.update_stream(primary_xref, cleaned_stream)
        page.set_contents(primary_xref)
        page.clean_contents()
    except Exception:
        return 0

    return removed


def _strip_page_text_content(doc, page):
    removed = 0
    content_xrefs = page.get_contents() or []

    # Clean the page as one logical content stream first. Some PDFs split BT/ET
    # pairs across separate streams, so per-stream regex cleanup leaves text
    # behind on those pages.
    if content_xrefs:
        try:
            combined_stream = page.read_contents()
            cleaned_stream = _strip_text_sections(combined_stream)
            if cleaned_stream != combined_stream:
                primary_xref = content_xrefs[0]
                doc.update_stream(primary_xref, cleaned_stream)
                page.set_contents(primary_xref)
                removed += 1
                content_xrefs = [primary_xref]
        except Exception:
            pass

    for xref in content_xrefs:
        if _strip_text_from_stream(doc, xref):
            removed += 1

    for xobj in page.get_xobjects():
        xobj_xref = xobj[0]
        try:
            xobj_dict = doc.xref_object(xobj_xref)
            if '/Form' not in xobj_dict:
                continue
        except Exception:
            continue
        if _strip_text_from_stream(doc, xobj_xref):
            removed += 1

    try:
        page.clean_contents()
    except Exception:
        pass

    return removed


def _iter_extracted_text_rects(page_data):
    words = page_data.get("words", []) or []
    if words:
        for word in words:
            left = float(word.get("left") or 0)
            top = float(word.get("top") or 0)
            width = float(word.get("width") or 0)
            height = float(word.get("height") or 0)
            if width <= 0 or height <= 0:
                continue
            yield left, top, left + width, top + height
        return

    for block in page_data.get("blocks", []) or []:
        line_bboxes = block.get("line_bboxes") or []
        for bbox in line_bboxes:
            if not bbox or len(bbox) < 4:
                continue
            yield float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3])


def _iter_extracted_region_rects(page_data):
    blocks = page_data.get("blocks", []) or []
    yielded = False
    for block in blocks:
        line_bboxes = block.get("line_bboxes") or []
        for bbox in line_bboxes:
            if not bbox or len(bbox) < 4:
                continue
            yielded = True
            yield fitz.Rect(float(bbox[0]), float(bbox[1]), float(bbox[2]), float(bbox[3]))
    if yielded:
        return

    for left, top, right, bottom in _iter_extracted_text_rects(page_data):
        yield fitz.Rect(left, top, right, bottom)


def _cleanup_residual_spans(page, region_rects, region_pad=1.5, redact_pad=0.8):
    if not region_rects:
        return 0

    padded_regions = [_inflate_rect(rect, region_pad) & page.rect for rect in region_rects]
    text_dict = page.get_text("dict") or {}
    redact_rects = []

    for bbox in _iter_span_bboxes(text_dict):
        span_rect = fitz.Rect(bbox)
        if span_rect.width <= 0 or span_rect.height <= 0:
            continue
        center = fitz.Point((span_rect.x0 + span_rect.x1) / 2, (span_rect.y0 + span_rect.y1) / 2)
        if not any(rect.contains(center) for rect in padded_regions):
            continue
        redact_rects.append((_inflate_rect(span_rect, redact_pad, 0.6) & page.rect))

    seen = set()
    applied = 0
    for rect in redact_rects:
        if rect.width <= 0 or rect.height <= 0:
            continue
        key = _rect_key(rect)
        if key in seen:
            continue
        seen.add(key)
        page.add_redact_annot(rect, fill=None)
        applied += 1

    if applied == 0:
        return 0

    _apply_redactions_compat(page)
    try:
        page.clean_contents()
    except Exception:
        pass
    return applied


def _redact_remaining_page_spans(page, redact_pad=0.8):
    text_dict = page.get_text("dict") or {}
    seen = set()
    applied = 0

    for bbox in _iter_span_bboxes(text_dict):
        span_rect = fitz.Rect(bbox)
        if span_rect.width <= 0 or span_rect.height <= 0:
            continue
        rect = (_inflate_rect(span_rect, redact_pad, 0.6) & page.rect)
        if rect.width <= 0 or rect.height <= 0:
            continue
        key = _rect_key(rect)
        if key in seen:
            continue
        seen.add(key)
        page.add_redact_annot(rect, fill=None)
        applied += 1

    if applied == 0:
        return 0

    _apply_redactions_compat(page)
    try:
        page.clean_contents()
    except Exception:
        pass
    return applied


def _redact_extracted_page_text(page, page_data):
    seen_rects = set()
    redaction_count = 0

    for left, top, right, bottom in _iter_extracted_text_rects(page_data):
        # Give extracted word boxes a little extra horizontal room so glyph
        # overhangs (common on final stems like "d") do not survive in the
        # clean overlay base.
        rect = _clamped_redact_rect(page, left, top, right, bottom, 0.9, 0.4)
        if rect is None:
            continue
        key = _rect_key(rect)
        if key in seen_rects:
            continue
        seen_rects.add(key)
        page.add_redact_annot(rect, fill=None)
        redaction_count += 1

    if redaction_count == 0:
        return 0

    _apply_redactions_compat(page)
    try:
        page.clean_contents()
    except Exception:
        pass

    return redaction_count


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
        
        total_removed_streams = 0

        # Process each page
        for page_data in extraction_data:
            page_num = page_data['page_number']
            
            # Skip if page doesn't exist (e.g., after page deletion)
            if page_num > doc.page_count:
                print(f"  ⚠ Skipping page {page_num}: not in document (only {doc.page_count} pages)")
                continue
            
            page = doc[page_num - 1]  # Convert to 0-indexed
            original_lines = _collect_simple_stroke_lines(page)
            
            extracted_words = page_data.get('words', [])
            live_words = page.get_text("words") or []
            print(
                f"  Processing page {page_num}: "
                f"{len(extracted_words)} extracted words, {len(live_words)} live words"
            )

            removed_here = _redact_extracted_page_text(page, page_data)
            if removed_here == 0:
                removed_here = _strip_page_text_content(doc, page)
            total_removed_streams += removed_here
            residual_cleanup = _cleanup_residual_spans(page, list(_iter_extracted_region_rects(page_data)))
            removed_artifact_lines = _strip_artifact_line_blocks(doc, page, original_lines)
            residual_words = page.get_text("words") or []
            residual_spans = list(_iter_span_bboxes(page.get_text("dict") or {}))

            page_wide_cleanup = 0
            fallback_stream_strip = 0
            if residual_words or residual_spans:
                page_wide_cleanup = _redact_remaining_page_spans(page)
                if page_wide_cleanup:
                    residual_words = page.get_text("words") or []
                    residual_spans = list(_iter_span_bboxes(page.get_text("dict") or {}))
            if residual_words or residual_spans:
                fallback_stream_strip = _strip_page_text_content(doc, page)
                removed_artifact_lines += _strip_artifact_line_blocks(doc, page, original_lines)
                page_wide_cleanup += _redact_remaining_page_spans(page)
                residual_words = page.get_text("words") or []
                residual_spans = list(_iter_span_bboxes(page.get_text("dict") or {}))

            if residual_words or residual_spans:
                print(
                    f"    ⚠ Page {page_num} still has {len(residual_words)} words / "
                    f"{len(residual_spans)} spans after selective cleanup"
                )
            else:
                print(
                    f"    ✓ Removed extracted text from {removed_here} region(s); "
                    f"page is text-free"
                )
            if residual_cleanup:
                print(f"    ✓ Removed {residual_cleanup} residual span fragment(s)")
            if page_wide_cleanup:
                print(f"    ✓ Removed {page_wide_cleanup} remaining page span(s)")
            if fallback_stream_strip:
                print(f"    ✓ Stripped page text streams as final fallback ({fallback_stream_strip} stream(s))")
            if removed_artifact_lines:
                print(f"    ✓ Removed {removed_artifact_lines} cleanup-artifact line block(s)")
        
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
        print(f"  Total cleaned content streams: {total_removed_streams}")
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
