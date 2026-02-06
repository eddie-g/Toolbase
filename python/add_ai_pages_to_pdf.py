#!/usr/bin/env python3
"""
Add AI-generated pages to an existing PDF document
Takes base64 encoded images and inserts them as pages into the PDF
"""

import sys
import json
import base64
import fitz  # PyMuPDF
from io import BytesIO
from PIL import Image


def base64_to_pdf_page(base64_image_data):
    """
    Convert a base64 encoded image to a PDF page
    
    Args:
        base64_image_data: Base64 encoded image string (without data URI prefix)
    
    Returns:
        bytes: PDF document containing the image as a page
    """
    # Decode base64 to bytes
    image_bytes = base64.b64decode(base64_image_data)
    
    # Open image with PIL to get dimensions
    img = Image.open(BytesIO(image_bytes))
    width, height = img.size
    
    # Create a new PDF document with one page
    pdf_doc = fitz.open()
    
    # Create a page with the same dimensions as the image (in points, 72 DPI)
    # PIL gives dimensions in pixels, convert to points (1 inch = 72 points)
    # Assume 96 DPI for screen resolution
    page_width = width * 72 / 96
    page_height = height * 72 / 96
    
    page = pdf_doc.new_page(width=page_width, height=page_height)
    
    # Insert the image
    rect = fitz.Rect(0, 0, page_width, page_height)
    page.insert_image(rect, stream=image_bytes)
    
    # Save to bytes
    pdf_bytes = pdf_doc.tobytes()
    pdf_doc.close()
    
    return pdf_bytes


def add_pages_to_pdf(input_pdf_path, output_pdf_path, images_json_file):
    """
    Add AI-generated pages to an existing PDF
    
    Args:
        input_pdf_path: Path to the existing PDF file
        output_pdf_path: Path where the modified PDF will be saved
        images_json_file: Path to JSON file containing array of base64 encoded images
    
    Returns:
        int: Number of pages added
    """
    # Read and parse images from JSON file
    try:
        with open(images_json_file, 'r') as f:
            images = json.load(f)
    except (json.JSONDecodeError, FileNotFoundError, IOError) as e:
        print(f"Error reading JSON file: {e}", file=sys.stderr)
        return 0
    
    if not isinstance(images, list) or len(images) == 0:
        print("No images provided", file=sys.stderr)
        return 0
    
    # Open the existing PDF
    try:
        existing_pdf = fitz.open(input_pdf_path)
    except Exception as e:
        print(f"Error opening PDF: {e}", file=sys.stderr)
        return 0
    
    # Process each image and add as a page
    pages_added = 0
    
    for idx, image_data in enumerate(images):
        try:
            # Convert base64 image to PDF page
            page_pdf_bytes = base64_to_pdf_page(image_data)
            
            # Open the single-page PDF
            temp_pdf = fitz.open(stream=page_pdf_bytes, filetype="pdf")
            
            # Insert the page at the end of the existing PDF
            existing_pdf.insert_pdf(temp_pdf)
            
            temp_pdf.close()
            pages_added += 1
            
            print(f"Added page {idx + 1}/{len(images)}", file=sys.stderr)
            
        except Exception as e:
            print(f"Error processing image {idx + 1}: {e}", file=sys.stderr)
            continue
    
    # Save the modified PDF
    try:
        existing_pdf.save(output_pdf_path, garbage=4, deflate=True)
        existing_pdf.close()
        print(f"Successfully added {pages_added} pages to PDF", file=sys.stderr)
        return pages_added
    except Exception as e:
        print(f"Error saving PDF: {e}", file=sys.stderr)
        existing_pdf.close()
        return 0


def main():
    if len(sys.argv) != 4:
        print("Usage: python3 add_ai_pages_to_pdf.py <input_pdf> <output_pdf> <images_json_file>", file=sys.stderr)
        sys.exit(1)
    
    input_pdf_path = sys.argv[1]
    output_pdf_path = sys.argv[2]
    images_json_file = sys.argv[3]
    
    pages_added = add_pages_to_pdf(input_pdf_path, output_pdf_path, images_json_file)
    
    if pages_added > 0:
        print(json.dumps({"success": True, "pages_added": pages_added}))
        sys.exit(0)
    else:
        print(json.dumps({"success": False, "error": "Failed to add pages"}))
        sys.exit(1)


if __name__ == "__main__":
    main()
