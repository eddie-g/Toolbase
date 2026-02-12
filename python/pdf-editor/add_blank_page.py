#!/usr/bin/env python3
"""
Insert a blank page into a PDF.
Usage: python add_blank_page.py /path/to/input.pdf /path/to/output.pdf [insert_after] [size_reference]
  insert_after: 0-based index to insert after, or -1 to append at end
  size_reference: 0-based index to copy size from, or -1 to use last page
"""

import sys
import json
import fitz  # PyMuPDF


def add_blank_page(input_path, output_path, insert_after, size_reference):
    try:
        # Open the input PDF
        input_doc = fitz.open(input_path)
        total_pages = len(input_doc)

        if total_pages == 0:
            input_doc.close()
            return {
                'success': False,
                'error': 'Cannot insert into an empty PDF'
            }

        if insert_after < -1:
            insert_after = -1
        if insert_after >= total_pages:
            insert_after = total_pages - 1

        insert_at = insert_after + 1 if insert_after >= 0 else total_pages

        if size_reference < 0 or size_reference >= total_pages:
            size_reference = total_pages - 1

        # Get the reference page dimensions
        ref_page = input_doc.load_page(size_reference)
        rect = ref_page.rect

        # Create a new output document
        output_doc = fitz.open()

        # Insert all pages before the blank page position
        if insert_at > 0:
            output_doc.insert_pdf(input_doc, from_page=0, to_page=insert_at - 1)

        # Insert the blank page
        output_doc.new_page(width=rect.width, height=rect.height)

        # Insert remaining pages after the blank page position
        if insert_at < total_pages:
            output_doc.insert_pdf(input_doc, from_page=insert_at, to_page=total_pages - 1)

        # Save the output document with safer parameters
        # garbage=0, deflate=False to avoid corruption of rotated pages
        output_doc.save(output_path, garbage=0, deflate=False)
        output_doc.close()
        input_doc.close()

        return {
            'success': True,
            'message': 'Blank page added',
            'total_pages': total_pages + 1
        }

    except Exception as e:
        return {
            'success': False,
            'error': str(e)
        }


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python add_blank_page.py <input_pdf> <output_pdf> [insert_after] [size_reference]'
        }))
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]
    insert_after = -1
    size_reference = -1

    if len(sys.argv) >= 4:
        try:
            insert_after = int(sys.argv[3])
        except ValueError:
            insert_after = -1

    if len(sys.argv) >= 5:
        try:
            size_reference = int(sys.argv[4])
        except ValueError:
            size_reference = -1

    result = add_blank_page(input_path, output_path, insert_after, size_reference)
    print(json.dumps(result))
    sys.exit(0 if result['success'] else 1)
