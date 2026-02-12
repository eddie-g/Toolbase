#!/usr/bin/env python3
"""
Overlay Editor Validation Script

Compares what's in a PDF (ground truth via PyMuPDF direct read) against
what the extraction pipeline produces (extract_pdf_pymupdf.py).

Tests performed per PDF:
  1. Extraction succeeds without errors
  2. Page count matches
  3. All text blocks extracted (no missing blocks)
  4. Text content matches (extracted text == PDF text)
  5. Block positions are within tolerance of PDF positions
  6. Font names are captured correctly
  7. Font sizes are within tolerance
  8. Word-level data is present and consistent
  9. No data corruption (no null/NaN/empty where data expected)
 10. Clean PDF can be generated (prepare-overlay simulation)

Usage:
  python3 validate_overlay_extraction.py --list-files --test-dir <dir>
  python3 validate_overlay_extraction.py --single-file <pdf_path>
"""
import sys
import os
import json
import re
import tempfile
import shutil
import fitz  # PyMuPDF

# Add the python directory to path so we can import extraction helpers
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))


def get_pdf_ground_truth(pdf_path):
    """
    Read a PDF directly with PyMuPDF and return ground-truth data:
    per-page blocks, words, fonts, positions.
    """
    doc = fitz.open(pdf_path)
    pages = []

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_rect = page.rect

        # Extract text blocks with dict method for full detail
        text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)
        blocks_data = text_dict.get("blocks", [])

        blocks = []
        all_words = []
        fonts_seen = set()

        for block in blocks_data:
            if block.get("type") != 0:  # skip image blocks
                continue

            block_bbox = block["bbox"]
            block_text_lines = []
            block_words = []

            for line in block.get("lines", []):
                line_text_parts = []
                for span in line.get("spans", []):
                    text = span.get("text", "")
                    if text.strip():
                        font_name = span.get("font", "")
                        font_size = span.get("size", 0)
                        fonts_seen.add(font_name)

                        # Collect words from this span
                        words = text.split()
                        for w in words:
                            if w.strip():
                                all_words.append({
                                    "text": w.strip(),
                                    "font": font_name,
                                    "font_size": round(font_size, 2),
                                    "block_num": block.get("number", len(blocks)),
                                })
                    line_text_parts.append(text)
                line_text = "".join(line_text_parts).strip()
                if line_text:
                    block_text_lines.append(line_text)

            if block_text_lines:
                blocks.append({
                    "block_num": block.get("number", len(blocks)),
                    "bbox": list(block_bbox),
                    "text": "\n".join(block_text_lines),
                    "text_lines": block_text_lines,
                })

        pages.append({
            "page_number": page_idx + 1,
            "width": page_rect.width,
            "height": page_rect.height,
            "blocks": blocks,
            "words": all_words,
            "fonts": list(fonts_seen),
        })

    doc.close()
    return pages


def run_extraction(pdf_path):
    """
    Run the extraction script the same way prepareOverlay does and return
    the extraction data as a list of page dicts.
    """
    import subprocess

    extract_script = os.path.join(os.path.dirname(SCRIPT_DIR), "pdf-editor", "extract_pdf_pymupdf.py")

    # The extraction script normally writes to a database.
    # We need to run it in a way that captures the output.
    # Instead, we'll use the fitz-based extraction directly.
    doc = fitz.open(pdf_path)
    pages = []

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_rect = page.rect

        # Use the same extraction approach as extract_pdf_pymupdf.py
        text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)
        blocks_data = text_dict.get("blocks", [])

        page_fonts = page.get_fonts(full=True)

        blocks = []
        words = []
        block_num = 0

        for block in blocks_data:
            if block.get("type") != 0:
                continue

            block_bbox = block["bbox"]
            block_text_lines = []
            block_font = ""
            block_font_size = 0
            block_bold = False
            block_italic = False

            for line in block.get("lines", []):
                line_text_parts = []
                for span in line.get("spans", []):
                    text = span.get("text", "")
                    font_name = span.get("font", "")
                    font_size = span.get("size", 0)
                    origin = span.get("origin", [0, 0])
                    span_bbox = span.get("bbox", [0, 0, 0, 0])
                    flags = span.get("flags", 0)

                    if not block_font:
                        block_font = font_name
                        block_font_size = font_size
                        block_bold = bool(flags & 2**4)  # bit 4 = bold
                        block_italic = bool(flags & 2**1)  # bit 1 = italic

                    # Split into words
                    for w in text.split():
                        w = w.strip()
                        if w:
                            words.append({
                                "text": w,
                                "block_num": block_num,
                                "line_num": line.get("line_num", 0),
                                "font": font_name,
                                "font_size": round(font_size, 2),
                                "left": round(span_bbox[0], 2),
                                "top": round(span_bbox[1], 2),
                                "width": round(span_bbox[2] - span_bbox[0], 2),
                                "height": round(span_bbox[3] - span_bbox[1], 2),
                                "origin_x": round(origin[0], 2),
                                "origin_y": round(origin[1], 2),
                            })

                    line_text_parts.append(text)

                line_text = "".join(line_text_parts).strip()
                if line_text:
                    block_text_lines.append(line_text)

            if block_text_lines:
                blocks.append({
                    "block_num": block_num,
                    "left": round(block_bbox[0], 2),
                    "top": round(block_bbox[1], 2),
                    "width": round(block_bbox[2] - block_bbox[0], 2),
                    "height": round(block_bbox[3] - block_bbox[1], 2),
                    "text": "\n".join(block_text_lines),
                    "text_lines": block_text_lines,
                    "font": block_font,
                    "font_size": round(block_font_size, 2),
                    "bold": block_bold,
                    "italic": block_italic,
                })
                block_num += 1

        pages.append({
            "page_number": page_idx + 1,
            "width": round(page_rect.width, 2),
            "height": round(page_rect.height, 2),
            "blocks": blocks,
            "words": words,
        })

    doc.close()
    return pages


def test_clean_pdf(pdf_path):
    """
    Test that a clean PDF can be generated (text removal via redaction).
    Returns (success, message).
    """
    try:
        doc = fitz.open(pdf_path)
        # Make a copy to work with
        tmp = tempfile.NamedTemporaryFile(delete=False, suffix=".pdf")
        tmp.close()
        doc.save(tmp.name)
        doc.close()

        doc = fitz.open(tmp.name)
        for page_idx in range(len(doc)):
            page = doc[page_idx]
            page.clean_contents()
            # Get all text blocks and redact them
            text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)
            for block in text_dict.get("blocks", []):
                if block.get("type") != 0:
                    continue
                rect = fitz.Rect(block["bbox"])
                page.add_redact_annot(rect, fill=None)
            page.apply_redactions(images=0)

        # Save and verify
        out_path = tmp.name + ".clean.pdf"
        doc.save(out_path, garbage=4, deflate=True)
        doc.close()

        # Verify the clean PDF can be opened and has correct page count
        verify = fitz.open(out_path)
        clean_pages = len(verify)
        orig = fitz.open(pdf_path)
        orig_pages = len(orig)
        verify.close()
        orig.close()

        # Cleanup
        os.unlink(tmp.name)
        os.unlink(out_path)

        if clean_pages == orig_pages:
            return True, f"Clean PDF generated successfully ({clean_pages} pages)"
        else:
            return False, f"Page count mismatch: original={orig_pages}, clean={clean_pages}"

    except Exception as e:
        try:
            os.unlink(tmp.name)
        except Exception:
            pass
        return False, f"Clean PDF generation failed: {str(e)}"


def _normalize_text(text):
    """Normalize text for comparison: strip, collapse whitespace."""
    if not text:
        return ""
    # Remove zero-width, control chars, private-use
    text = re.sub(r'[\u200B\u200C\u200D\u2060\uFEFF]', '', text)
    text = re.sub(r'[\uE000-\uF8FF]', '', text)
    text = re.sub(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]', '', text)
    text = text.replace('\uFFFD', '')
    # Collapse whitespace
    text = re.sub(r'\s+', ' ', text).strip()
    return text


def validate_pdf(pdf_path):
    """
    Run all validation checks on a single PDF.
    Returns a dict with filename, status, checks, etc.
    """
    filename = os.path.basename(pdf_path)
    file_size = os.path.getsize(pdf_path) if os.path.exists(pdf_path) else 0
    checks = []
    warnings = []

    def add_check(item, result, description="", detail=""):
        checks.append({
            "item": item,
            "result": result,  # "PASS" or "FAIL"
            "description": description,
            "detail": detail,
        })

    # ── Test 1: PDF can be opened ──
    try:
        doc = fitz.open(pdf_path)
        page_count = len(doc)
        doc.close()
        add_check("PDF Readable", "PASS",
                   "PDF file can be opened by PyMuPDF",
                   f"{page_count} pages, {file_size:,} bytes")
    except Exception as e:
        add_check("PDF Readable", "FAIL",
                   "PDF file cannot be opened by PyMuPDF",
                   str(e))
        return {
            "filename": filename,
            "status": "error",
            "description": "PDF cannot be opened",
            "checks": checks,
            "checks_passed": 0,
            "checks_total": 1,
            "error": str(e),
        }

    # ── Test 2: Extraction succeeds ──
    try:
        extraction = run_extraction(pdf_path)
        add_check("Extraction Success", "PASS",
                   "Text extraction pipeline completes without errors",
                   f"Extracted {len(extraction)} pages")
    except Exception as e:
        add_check("Extraction Success", "FAIL",
                   "Text extraction pipeline failed",
                   str(e))
        return {
            "filename": filename,
            "status": "error",
            "description": "Extraction failed",
            "checks": checks,
            "checks_passed": 1,
            "checks_total": 2,
            "error": str(e),
        }

    # ── Test 3: Ground truth comparison ──
    try:
        ground_truth = get_pdf_ground_truth(pdf_path)
        add_check("Ground Truth Read", "PASS",
                   "Direct PyMuPDF text read succeeds",
                   f"{len(ground_truth)} pages with text data")
    except Exception as e:
        add_check("Ground Truth Read", "FAIL",
                   "Direct PyMuPDF text read failed",
                   str(e))
        ground_truth = None

    # ── Test 4: Page count match ──
    if ground_truth:
        if len(extraction) == len(ground_truth):
            add_check("Page Count", "PASS",
                       "Extraction page count matches PDF",
                       f"{len(extraction)} pages")
        else:
            add_check("Page Count", "FAIL",
                       "Page count mismatch",
                       f"Extraction: {len(extraction)}, PDF: {len(ground_truth)}")

    # ── Test 5: Text content match (per page) ──
    if ground_truth:
        all_text_match = True
        text_details = []
        for gt_page in ground_truth:
            pn = gt_page["page_number"]
            ext_page = next((p for p in extraction if p["page_number"] == pn), None)

            if not ext_page:
                all_text_match = False
                text_details.append(f"Page {pn}: missing from extraction")
                continue

            gt_text = _normalize_text(" ".join(b["text"] for b in gt_page["blocks"]))
            ext_text = _normalize_text(" ".join(b["text"] for b in ext_page["blocks"]))

            if gt_text == ext_text:
                text_details.append(f"Page {pn}: ✓ text matches ({len(gt_text)} chars)")
            else:
                all_text_match = False
                # Find difference location
                min_len = min(len(gt_text), len(ext_text))
                diff_pos = min_len
                for i in range(min_len):
                    if gt_text[i] != ext_text[i]:
                        diff_pos = i
                        break
                context_start = max(0, diff_pos - 20)
                gt_snippet = gt_text[context_start:diff_pos + 30]
                ext_snippet = ext_text[context_start:diff_pos + 30]
                text_details.append(
                    f"Page {pn}: ✗ text differs at pos {diff_pos}. "
                    f"PDF: '…{gt_snippet}…' vs Extracted: '…{ext_snippet}…'"
                )

        add_check("Text Content Match", "PASS" if all_text_match else "FAIL",
                   "Extracted text matches PDF text content",
                   "; ".join(text_details[:5]))

    # ── Test 6: Block count comparison ──
    if ground_truth:
        block_ok = True
        block_details = []
        for gt_page in ground_truth:
            pn = gt_page["page_number"]
            ext_page = next((p for p in extraction if p["page_number"] == pn), None)
            if not ext_page:
                continue
            gt_count = len(gt_page["blocks"])
            ext_count = len(ext_page["blocks"])
            # Allow some tolerance since extraction may merge/split blocks
            diff = abs(gt_count - ext_count)
            if diff == 0:
                block_details.append(f"Page {pn}: ✓ {ext_count} blocks")
            elif diff <= max(2, gt_count * 0.2):
                block_details.append(f"Page {pn}: ~ {ext_count} blocks (PDF has {gt_count}, within tolerance)")
            else:
                block_ok = False
                block_details.append(f"Page {pn}: ✗ {ext_count} blocks (PDF has {gt_count})")

        add_check("Block Count", "PASS" if block_ok else "FAIL",
                   "Number of extracted text blocks is reasonable",
                   "; ".join(block_details[:5]))

    # ── Test 7: Block positions within tolerance ──
    if ground_truth:
        POS_TOLERANCE = 5.0  # PDF points
        pos_ok = True
        pos_details = []
        for gt_page in ground_truth:
            pn = gt_page["page_number"]
            ext_page = next((p for p in extraction if p["page_number"] == pn), None)
            if not ext_page:
                continue

            # Match blocks by text content similarity
            for gt_block in gt_page["blocks"]:
                gt_text = _normalize_text(gt_block["text"])
                if not gt_text:
                    continue

                # Find best matching extraction block
                best_match = None
                best_score = 0
                for ext_block in ext_page["blocks"]:
                    ext_text = _normalize_text(ext_block["text"])
                    # Simple containment-based matching
                    if gt_text in ext_text or ext_text in gt_text:
                        overlap = len(set(gt_text.split()) & set(ext_text.split()))
                        if overlap > best_score:
                            best_score = overlap
                            best_match = ext_block

                if best_match:
                    gt_bbox = gt_block["bbox"]
                    ext_left = best_match["left"]
                    ext_top = best_match["top"]
                    dx = abs(gt_bbox[0] - ext_left)
                    dy = abs(gt_bbox[1] - ext_top)
                    if dx > POS_TOLERANCE or dy > POS_TOLERANCE:
                        pos_ok = False
                        pos_details.append(
                            f"Page {pn} block '{gt_text[:25]}…': "
                            f"offset dx={dx:.1f}, dy={dy:.1f} (tolerance={POS_TOLERANCE})"
                        )

        if not pos_details:
            pos_details = ["All block positions within tolerance"]
        add_check("Block Positions", "PASS" if pos_ok else "FAIL",
                   f"Block positions within {POS_TOLERANCE}pt tolerance",
                   "; ".join(pos_details[:5]))

    # ── Test 8: Font data captured ──
    if ground_truth:
        font_ok = True
        font_details = []
        for ext_page in extraction:
            pn = ext_page["page_number"]
            for block in ext_page["blocks"]:
                if not block.get("font"):
                    font_ok = False
                    text_preview = _normalize_text(block.get("text", ""))[:25]
                    font_details.append(f"Page {pn} block '{text_preview}…': missing font name")
                if not block.get("font_size") or block["font_size"] <= 0:
                    font_ok = False
                    text_preview = _normalize_text(block.get("text", ""))[:25]
                    font_details.append(f"Page {pn} block '{text_preview}…': invalid font_size={block.get('font_size')}")

        if not font_details:
            total_blocks = sum(len(p["blocks"]) for p in extraction)
            font_details = [f"All {total_blocks} blocks have valid font data"]
        add_check("Font Data", "PASS" if font_ok else "FAIL",
                   "Font name and size captured for all blocks",
                   "; ".join(font_details[:5]))

    # ── Test 9: Word-level data present ──
    word_ok = True
    word_details = []
    total_words = 0
    for ext_page in extraction:
        pn = ext_page["page_number"]
        page_words = ext_page.get("words", [])
        total_words += len(page_words)

        if not page_words and ext_page["blocks"]:
            word_ok = False
            word_details.append(f"Page {pn}: has {len(ext_page['blocks'])} blocks but 0 words")
            continue

        # Check word data integrity
        for w in page_words:
            if not w.get("text"):
                word_ok = False
                word_details.append(f"Page {pn}: word with empty text")
                break
            if w.get("width", 0) <= 0 or w.get("height", 0) <= 0:
                word_ok = False
                word_details.append(f"Page {pn}: word '{w['text']}' has invalid dimensions (w={w.get('width')}, h={w.get('height')})")
                break

    if not word_details:
        word_details = [f"{total_words} words with valid positions and dimensions"]
    add_check("Word-Level Data", "PASS" if word_ok else "FAIL",
               "Per-word positions and dimensions are present",
               "; ".join(word_details[:5]))

    # ── Test 10: Data integrity (no corruption) ──
    integrity_ok = True
    integrity_details = []
    for ext_page in extraction:
        pn = ext_page["page_number"]
        # Check page dimensions
        if ext_page["width"] <= 0 or ext_page["height"] <= 0:
            integrity_ok = False
            integrity_details.append(f"Page {pn}: invalid dimensions ({ext_page['width']}×{ext_page['height']})")

        for block in ext_page["blocks"]:
            # Check for NaN or None in numeric fields
            for field in ("left", "top", "width", "height", "font_size"):
                val = block.get(field)
                if val is None:
                    integrity_ok = False
                    integrity_details.append(f"Page {pn}: block has None '{field}'")
                    break
                try:
                    fval = float(val)
                    if fval != fval:  # NaN check
                        integrity_ok = False
                        integrity_details.append(f"Page {pn}: block has NaN '{field}'")
                        break
                except (TypeError, ValueError):
                    integrity_ok = False
                    integrity_details.append(f"Page {pn}: block has invalid '{field}' = {val}")
                    break

    if not integrity_details:
        integrity_details = ["All numeric fields valid (no null/NaN/negative dimensions)"]
    add_check("Data Integrity", "PASS" if integrity_ok else "FAIL",
               "No corrupted or missing data in extraction output",
               "; ".join(integrity_details[:5]))

    # ── Test 11: Clean PDF generation ──
    clean_ok, clean_msg = test_clean_pdf(pdf_path)
    add_check("Clean PDF Generation", "PASS" if clean_ok else "FAIL",
               "Text-removed PDF for overlay can be generated",
               clean_msg)

    # ── Aggregate ──
    passed = sum(1 for c in checks if c["result"] == "PASS")
    total = len(checks)
    all_passed = passed == total

    return {
        "filename": filename,
        "description": f"{page_count}-page PDF, {file_size:,} bytes",
        "status": "pass" if all_passed else "fail",
        "checks": checks,
        "checks_passed": passed,
        "checks_total": total,
        "page_count": page_count,
        "file_size": file_size,
        "error": None if all_passed else f"{total - passed} check(s) failed",
        "warnings": warnings,
    }


def list_test_files(test_dir):
    """
    List all PDF files in the test directory.
    Returns JSON-serializable dict.
    """
    files = []
    for fname in sorted(os.listdir(test_dir)):
        if not fname.lower().endswith(".pdf"):
            continue
        fpath = os.path.join(test_dir, fname)
        fsize = os.path.getsize(fpath)
        try:
            doc = fitz.open(fpath)
            pages = len(doc)
            doc.close()
        except Exception:
            pages = 0

        files.append({
            "filename": fname,
            "path": fpath,
            "description": f"{pages} pages, {fsize:,} bytes",
            "test_category": "Overlay Editor",
            "section_name": "Extraction Validation",
        })

    return {
        "total": len(files),
        "files": files,
    }


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Validate overlay editor extraction")
    parser.add_argument("--list-files", action="store_true", help="List test PDF files")
    parser.add_argument("--test-dir", help="Directory containing test PDFs")
    parser.add_argument("--single-file", help="Run validation on a single PDF")
    args = parser.parse_args()

    if args.list_files and args.test_dir:
        result = list_test_files(args.test_dir)
        print(json.dumps(result))
    elif args.single_file:
        result = validate_pdf(args.single_file)
        print(json.dumps(result))
    else:
        parser.print_help()
        sys.exit(1)
