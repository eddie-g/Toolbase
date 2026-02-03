#!/usr/bin/env python3
"""
Rebuild a PDF using OCR extraction data.
Creates a new PDF with the original page image as background and
recreates a selectable text layer based on OCR word boxes.
"""

import sys
import os
import json
from pathlib import Path
from datetime import datetime

from pdf2image import convert_from_path
import mysql.connector
from mysql.connector import Error
from reportlab.pdfgen import canvas
from reportlab.lib.utils import ImageReader


def get_db_connection():
    """Create database connection using Laravel's .env configuration."""
    env_path = Path(__file__).parent.parent / '.env'
    db_config = {
        'host': 'mysql',
        'database': 'laravel',
        'user': 'sail',
        'password': 'password',
        'port': 3306,
    }

    if env_path.exists():
        with open(env_path) as f:
            for line in f:
                line = line.strip()
                if line.startswith('DB_HOST='):
                    db_config['host'] = line.split('=', 1)[1]
                elif line.startswith('DB_DATABASE='):
                    db_config['database'] = line.split('=', 1)[1]
                elif line.startswith('DB_USERNAME='):
                    db_config['user'] = line.split('=', 1)[1]
                elif line.startswith('DB_PASSWORD='):
                    db_config['password'] = line.split('=', 1)[1]
                elif line.startswith('DB_PORT='):
                    db_config['port'] = int(line.split('=', 1)[1])

    try:
        connection = mysql.connector.connect(**db_config)
        if connection.is_connected():
            return connection
    except Error as e:
        print(f"ERROR: DB connection failed: {e}")
        sys.exit(1)


def fetch_latest_extraction(connection, document_id):
    """Fetch extraction data from pdf_extractions_fitz table."""
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute(
            "SELECT extraction_data FROM pdf_extractions_fitz WHERE document_id=%s ORDER BY id DESC LIMIT 1",
            (document_id,)
        )
        row = cursor.fetchone()
        if row and row.get('extraction_data'):
            cursor.close()
            return json.loads(row['extraction_data'])
    except Error as e:
        print(f"ERROR: Failed to fetch extraction data: {e}")
    cursor.close()
    return None


def group_words_by_line(words):
    """Group OCR words by (block_num, line_num)."""
    groups = {}
    for word in words:
        text = (word.get('text') or '').strip()
        if not text:
            continue
        block = word.get('block_num', 0)
        line = word.get('line_num', 0)
        key = f"{block}-{line}"
        if key not in groups:
            groups[key] = {
                'words': [],
                'min_x': float('inf'),
                'min_y': float('inf'),
                'max_x': float('-inf'),
                'max_y': float('-inf'),
            }
        left = float(word.get('left', 0))
        top = float(word.get('top', 0))
        width = float(word.get('width', 0))
        height = float(word.get('height', 0))
        right = left + width
        bottom = top + height
        groups[key]['words'].append({
            'text': text,
            'word_num': int(word.get('word_num', 0)),
            'height': height,
        })
        groups[key]['min_x'] = min(groups[key]['min_x'], left)
        groups[key]['min_y'] = min(groups[key]['min_y'], top)
        groups[key]['max_x'] = max(groups[key]['max_x'], right)
        groups[key]['max_y'] = max(groups[key]['max_y'], bottom)
    return list(groups.values())


def median(values):
    """Return median of a list of numbers."""
    if not values:
        return 0.0
    sorted_vals = sorted(values)
    n = len(sorted_vals)
    mid = n // 2
    if n % 2 == 0:
        return (sorted_vals[mid - 1] + sorted_vals[mid]) / 2.0
    return sorted_vals[mid]


def rebuild_pdf(pdf_path, extraction_data, output_path, dpi=150):
    """Rebuild PDF with image background and OCR text layer."""
    images = convert_from_path(pdf_path, dpi=dpi)
    c = canvas.Canvas(output_path)

    for idx, image in enumerate(images):
        page_number = idx + 1
        page_data = next(
            (p for p in extraction_data if (p.get('page_number') or p.get('page')) == page_number),
            None
        )
        width, height = image.size
        c.setPageSize((width, height))
        
        # Draw image with compression
        c.drawImage(ImageReader(image), 0, 0, width=width, height=height, preserveAspectRatio=True)

        if page_data:
            words = page_data.get('words', [])
            groups = group_words_by_line(words)
            
            for group in groups:
                if not group['words']:
                    continue
                group['words'].sort(key=lambda w: w['word_num'])
                text = ' '.join(w['text'] for w in group['words'])
                if not text:
                    continue
                
                box_width = max(1, group['max_x'] - group['min_x'])
                box_height = max(1, group['max_y'] - group['min_y'])
                x = group['min_x']
                y = height - group['min_y'] - box_height
                
                # Estimate font size using median word height per line
                word_heights = [w.get('height', 0) for w in group['words']]
                median_height = median([h for h in word_heights if h > 0])
                estimated_height = median_height if median_height > 0 else box_height
                font_size = min(max(6, estimated_height * 0.9), 18)
                
                c.setFont("Helvetica", font_size)
                c.setFillColorRGB(0, 0, 0)
                
                # Use proper text positioning with baseline adjustment
                text_y = y + (box_height * 0.2)
                c.drawString(x, text_y, text)

        c.showPage()

    c.save()


def main():
    if len(sys.argv) < 4:
        print("Usage: rebuild_pdf_from_ocr.py <pdf_path> <document_id> <output_path>")
        sys.exit(1)

    pdf_path = sys.argv[1]
    document_id = int(sys.argv[2])
    output_path = sys.argv[3]

    if not os.path.exists(pdf_path):
        print("ERROR: PDF file not found")
        sys.exit(1)

    connection = get_db_connection()
    extraction_data = fetch_latest_extraction(connection, document_id)
    connection.close()

    if not extraction_data:
        print("ERROR: No OCR extraction data found")
        sys.exit(1)

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    rebuild_pdf(pdf_path, extraction_data, output_path)

    result = {
        "success": True,
        "output_path": output_path,
        "rebuilt_at": datetime.utcnow().isoformat() + "Z",
    }
    print(json.dumps(result))


if __name__ == "__main__":
    main()
