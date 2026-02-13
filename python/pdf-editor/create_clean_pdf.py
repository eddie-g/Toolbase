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
            
            words = page_data.get('words', [])
            print(f"  Processing page {page_num}: {len(words)} text spans")
            
            # Add redaction for each word - INCLUDING text over images
            # Using fill=None and images=0 removes text ink without damaging images
            # IMPORTANT: Expand each rect by a small margin to catch glyph
            # ascenders, descenders, kerning overhangs, and anti-aliasing
            # pixels that extend beyond the extracted bounding box.
            REDACT_MARGIN = 2  # points (~0.7mm) — enough for glyph edges
            for word in words:
                rect = fitz.Rect(
                    word['left'] - REDACT_MARGIN,
                    word['top'] - REDACT_MARGIN,
                    word['left'] + word['width'] + REDACT_MARGIN,
                    word['top'] + word['height'] + REDACT_MARGIN
                )
                
                # Clamp to page mediabox so redaction doesn't exceed page bounds
                rect &= page.rect
                
                # Redact ALL text with fill=None to preserve backgrounds
                page.add_redact_annot(rect, fill=None)
                total_redactions += 1
            
            # Apply all redactions on this page
            # CRITICAL: images=0 preserves underlying images when removing text on top
            page.apply_redactions(images=0)
            print(f"    ✓ Redacted {total_redactions} text spans")
        
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
