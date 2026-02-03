#!/usr/bin/env python3
"""
Insert a blank page into a PDF.
Usage: python add_blank_page.py /path/to/input.pdf /path/to/output.pdf [insert_after] [size_reference]
  insert_after: 0-based index to insert after, or -1 to append at end
  size_reference: 0-based index to copy size from, or -1 to use last page
"""

import sys
import json
import pymupdf as fitz


def add_blank_page(input_path, output_path, insert_after, size_reference):
    try:
        pdf_document = fitz.open(input_path)
        total_pages = len(pdf_document)

        if total_pages == 0:
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

        ref_page = pdf_document.load_page(size_reference)
        rect = ref_page.rect

        pdf_document.new_page(pno=insert_at, width=rect.width, height=rect.height)
        pdf_document.save(output_path)
        pdf_document.close()

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
