#!/usr/bin/env python3
"""
Verify Screenshot Against Edit Coordinates

This script takes a screenshot and its corresponding edit coordinates JSON file,
then verifies that the text appears at the expected positions.

Usage:
    python verify_screenshot.py <document_id> [--suffix after]
    
It will look for:
    - python/screenshots/<document_id>_page_1_<suffix>.png
    - python/screenshots/<document_id>_page_1_<suffix>_edits.json
"""

import sys
import os
import json
import argparse
from pathlib import Path

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    print("Pillow not installed. Install with: pip install Pillow")
    sys.exit(1)

try:
    import pytesseract
except ImportError:
    pytesseract = None
    print("Warning: pytesseract not installed. OCR verification disabled.")


def load_edit_coordinates(json_path):
    """Load edit coordinates from JSON file"""
    with open(json_path, 'r') as f:
        return json.load(f)


# Standard PDF page sizes in points (72 DPI)
PDF_PAGE_SIZES = {
    'A4': (595.276, 841.890),
    'US_LETTER': (612, 792),
    'A4_LANDSCAPE': (841.890, 595.276),
}


def calculate_scale_factors(screenshot_size, pdf_page_size=None):
    """
    Calculate scale factors between PDF coordinates and screenshot coordinates.
    
    The screenshot is rendered at a higher resolution than the PDF points.
    We need to scale PDF coordinates to match screenshot pixel positions.
    """
    screenshot_width, screenshot_height = screenshot_size
    
    # Try to detect PDF page size from aspect ratio if not provided
    if pdf_page_size is None:
        aspect_ratio = screenshot_width / screenshot_height
        
        # A4 portrait aspect ratio is ~0.707
        a4_aspect = PDF_PAGE_SIZES['A4'][0] / PDF_PAGE_SIZES['A4'][1]
        letter_aspect = PDF_PAGE_SIZES['US_LETTER'][0] / PDF_PAGE_SIZES['US_LETTER'][1]
        
        if abs(aspect_ratio - a4_aspect) < 0.05:
            pdf_page_size = PDF_PAGE_SIZES['A4']
        elif abs(aspect_ratio - letter_aspect) < 0.05:
            pdf_page_size = PDF_PAGE_SIZES['US_LETTER']
        else:
            # Default to A4
            pdf_page_size = PDF_PAGE_SIZES['A4']
    
    pdf_width, pdf_height = pdf_page_size
    
    scale_x = screenshot_width / pdf_width
    scale_y = screenshot_height / pdf_height
    
    return scale_x, scale_y


def draw_expected_boxes(image_path, edits, output_path, pdf_page_size=None):
    """
    Draw boxes on the screenshot showing where text SHOULD be based on coordinates.
    Green = expected position from edit data
    
    IMPORTANT: The edit coordinates are in PDF points, but the screenshot is at
    a higher resolution. We must scale the coordinates to match.
    """
    img = Image.open(image_path)
    draw = ImageDraw.Draw(img)
    
    # Calculate scale factors from PDF to screenshot coordinates
    scale_x, scale_y = calculate_scale_factors(img.size, pdf_page_size)
    
    print(f"  Screenshot size: {img.size}")
    print(f"  Scale factors: x={scale_x:.4f}, y={scale_y:.4f}")
    
    for i, edit in enumerate(edits):
        bbox = edit.get('bbox', [0, 0, 100, 100])
        new_text = edit.get('new_text', '')
        original_text = edit.get('original_text', '')
        
        # bbox format: [x0, y0, x1, y1] - in PDF coordinates
        # Scale to screenshot coordinates
        x0 = bbox[0] * scale_x
        y0 = bbox[1] * scale_y
        x1 = bbox[2] * scale_x
        y1 = bbox[3] * scale_y
        
        # Draw green rectangle for expected position
        draw.rectangle([x0, y0, x1, y1], outline='green', width=2)
        
        # Draw label
        label = f"#{i+1}: {new_text[:20]}..." if len(new_text) > 20 else f"#{i+1}: {new_text}"
        draw.text((x0, y0 - 15), label, fill='green')
        
        # Also show original bbox in red if different
        original_bbox = edit.get('original_bbox')
        if original_bbox and original_bbox != bbox:
            ox0 = original_bbox[0] * scale_x
            oy0 = original_bbox[1] * scale_y
            ox1 = original_bbox[2] * scale_x
            oy1 = original_bbox[3] * scale_y
            draw.rectangle([ox0, oy0, ox1, oy1], outline='red', width=1)
            draw.text((ox0, oy0 - 15), f"orig #{i+1}", fill='red')
    
    img.save(output_path)
    print(f"✓ Annotated screenshot saved to: {output_path}")
    return output_path


def verify_text_positions(image_path, edits, pdf_page_size=None):
    """
    Use OCR to verify text appears at expected positions.
    Returns a report of matches and mismatches.
    """
    if pytesseract is None:
        return {"error": "pytesseract not installed"}
    
    img = Image.open(image_path)
    
    # Calculate scale factors from PDF to screenshot coordinates
    scale_x, scale_y = calculate_scale_factors(img.size, pdf_page_size)
    
    results = []
    for i, edit in enumerate(edits):
        bbox = edit.get('bbox', [0, 0, 100, 100])
        expected_text = edit.get('new_text', '')
        
        if not expected_text.strip():
            results.append({
                "edit_num": i + 1,
                "expected": expected_text,
                "status": "skipped (empty text)",
                "bbox": bbox
            })
            continue
        
        # Scale bbox from PDF coords to screenshot coords, then convert to int
        x0 = int(bbox[0] * scale_x)
        y0 = int(bbox[1] * scale_y)
        x1 = int(bbox[2] * scale_x)
        y1 = int(bbox[3] * scale_y)
        
        # Ensure coordinates are within image bounds
        x0 = max(0, x0)
        y0 = max(0, y0)
        x1 = min(img.width, x1)
        y1 = min(img.height, y1)
        
        if x1 <= x0 or y1 <= y0:
            results.append({
                "edit_num": i + 1,
                "expected": expected_text,
                "status": "invalid bbox",
                "bbox": bbox
            })
            continue
        
        cropped = img.crop((x0, y0, x1, y1))
        
        # Run OCR on the cropped region
        try:
            found_text = pytesseract.image_to_string(cropped).strip()
            
            # Check if expected text is found
            expected_clean = expected_text.strip().lower()
            found_clean = found_text.lower()
            
            if expected_clean in found_clean or found_clean in expected_clean:
                status = "MATCH"
            elif any(word in found_clean for word in expected_clean.split()):
                status = "PARTIAL"
            else:
                status = "MISMATCH"
            
            results.append({
                "edit_num": i + 1,
                "expected": expected_text,
                "found": found_text,
                "status": status,
                "bbox": bbox
            })
        except Exception as e:
            results.append({
                "edit_num": i + 1,
                "expected": expected_text,
                "status": f"OCR error: {e}",
                "bbox": bbox
            })
    
    return results


def print_verification_report(edits, results=None):
    """Print a verification report"""
    print("\n" + "=" * 60)
    print("EDIT COORDINATES VERIFICATION REPORT")
    print("=" * 60)
    
    print(f"\nTotal edits: {len(edits)}")
    
    for i, edit in enumerate(edits):
        print(f"\n--- Edit #{i+1} ---")
        print(f"  Original text: {edit.get('original_text', '')[:50]}...")
        print(f"  New text:      {edit.get('new_text', '')[:50]}...")
        print(f"  Original bbox: {edit.get('original_bbox', 'N/A')}")
        print(f"  New bbox:      {edit.get('bbox', 'N/A')}")
        print(f"  Font size:     {edit.get('font_size', 'N/A')}")
        
        if results:
            result = results[i] if i < len(results) else None
            if result:
                print(f"  OCR Status:    {result.get('status', 'N/A')}")
                if result.get('found'):
                    print(f"  OCR Found:     {result.get('found', '')[:50]}...")
    
    print("\n" + "=" * 60)


def main():
    parser = argparse.ArgumentParser(description="Verify screenshot against edit coordinates")
    parser.add_argument("document_id", type=int, help="Document ID")
    parser.add_argument("--suffix", "-s", default="after", help="Screenshot suffix (default: after)")
    parser.add_argument("--page", "-p", type=int, default=1, help="Page number (default: 1)")
    parser.add_argument("--ocr", action="store_true", help="Run OCR verification (requires pytesseract)")
    
    args = parser.parse_args()
    
    script_dir = Path(__file__).parent
    screenshots_dir = script_dir / "screenshots"
    
    # Build filenames
    base_name = f"{args.document_id}_page_{args.page}_{args.suffix}"
    screenshot_path = screenshots_dir / f"{base_name}.png"
    edits_path = screenshots_dir / f"{base_name}_edits.json"
    annotated_path = screenshots_dir / f"{base_name}_annotated.png"
    
    # Check files exist
    if not screenshot_path.exists():
        print(f"❌ Screenshot not found: {screenshot_path}")
        sys.exit(1)
    
    if not edits_path.exists():
        print(f"❌ Edit coordinates not found: {edits_path}")
        print("   Run save with screenshot enabled to generate this file.")
        sys.exit(1)
    
    print(f"📷 Screenshot: {screenshot_path}")
    print(f"📋 Edits:      {edits_path}")
    
    # Load edit coordinates
    edits = load_edit_coordinates(edits_path)
    
    # Draw expected boxes on screenshot
    draw_expected_boxes(screenshot_path, edits, annotated_path)
    
    # Optionally run OCR verification
    results = None
    if args.ocr and pytesseract:
        print("\n🔍 Running OCR verification...")
        results = verify_text_positions(screenshot_path, edits)
    
    # Print report
    print_verification_report(edits, results)
    
    print(f"\n✅ Verification complete!")
    print(f"   View annotated screenshot: {annotated_path}")


if __name__ == "__main__":
    main()
