#!/usr/bin/env python3
"""
Compare extraction data for document 159
Shows differences between fresh fitz extraction and stored extraction_data
"""

import sys
import os
sys.path.insert(0, '/var/www/html/python')

import fitz
import json
import mysql.connector
from pathlib import Path

# Get DB config from .env
env_path = Path('/var/www/html/.env')
db_config = {
    'host': 'mysql',
    'database': 'laravel',
    'user': 'sail',
    'password': 'password'
}

if env_path.exists():
    with open(env_path) as f:
        for line in f:
            line = line.strip()
            if line.startswith('DB_DATABASE='):
                db_config['database'] = line.split('=', 1)[1]

# Database connection
conn = mysql.connector.connect(**db_config)
cursor = conn.cursor()

# Get document info
cursor.execute('SELECT id, original_name, path FROM documents WHERE id = 159')
doc_row = cursor.fetchone()

if not doc_row:
    print('Document 159 not found')
    sys.exit(1)

doc_id, original_name, path = doc_row
print(f'Document {doc_id}: {original_name}')
print(f'PDF path: {path}')
print()

# Get stored extraction data
cursor.execute('SELECT extraction_data FROM pdf_extractions_fitz WHERE document_id = 159')
ext_row = cursor.fetchone()

if not ext_row:
    print('No extraction data found in database')
    sys.exit(1)

stored_data = json.loads(ext_row[0])
print(f'Stored extraction data:')
print(f'  Type: {type(stored_data)}')

# Handle both dict and list formats
if isinstance(stored_data, list):
    stored_words = stored_data
    print(f'  Words (list format): {len(stored_words)}')
elif isinstance(stored_data, dict):
    stored_words = stored_data.get('words', [])
    print(f'  Pages: {len(stored_data.get("pages", []))}')
    print(f'  Words (dict format): {len(stored_words)}')
else:
    print('  Unknown format!')
    stored_words = []
print()

# Extract fresh from PDF using fitz
# Try multiple possible paths
possible_paths = [
    f'/var/www/html/storage/app/{path}',
    f'/var/www/html/storage/app/private/{path}',
    f'/var/www/html/storage/app/public/{path}'
]

full_pdf_path = None
for test_path in possible_paths:
    if os.path.exists(test_path):
        full_pdf_path = test_path
        break

if not full_pdf_path:
    print(f'PDF file not found. Tried:')
    for p in possible_paths:
        print(f'  - {p}')
    sys.exit(1)

print(f'Extracting fresh data from: {full_pdf_path}')

doc = fitz.open(full_pdf_path)
fresh_words = []

for page_num in range(len(doc)):
    page = doc[page_num]
    words = page.get_text("words")
    
    for word in words:
        x0, y0, x1, y1, text, block_no, line_no, word_no = word[:8]
        
        # Get font info for this word
        blocks = page.get_text("dict")["blocks"]
        font = "unknown"
        font_size = 12
        font_weight = 400
        
        for block in blocks:
            if block.get("type") == 0:  # text block
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        span_bbox = span.get("bbox", [0,0,0,0])
                        # Check if this span overlaps with our word
                        if (abs(span_bbox[0] - x0) < 1 and 
                            abs(span_bbox[1] - y0) < 1):
                            font = span.get("font", "unknown")
                            font_size = span.get("size", 12)
                            # Parse font weight from font name
                            if "Bold" in font or "bold" in font:
                                font_weight = 700
                            elif "Thin" in font or "thin" in font or "Light" in font:
                                font_weight = 100
                            elif "Medium" in font:
                                font_weight = 500
                            break
        
        fresh_words.append({
            'text': text,
            'font': font,
            'font_size': font_size,
            'font_weight': font_weight,
            'page': page_num + 1,
            'bbox': [x0, y0, x1, y1]
        })

doc.close()

print(f'Fresh extraction:')
print(f'  Words: {len(fresh_words)}')
print()

# Compare first 20 words
print('Comparison of first 20 words:')
print('=' * 100)
print(f'{"#":<4} {"STORED TEXT":<25} {"STORED FONT":<30} {"FRESH TEXT":<25} {"FRESH FONT":<30}')
print('=' * 100)

for i in range(min(20, len(stored_words), len(fresh_words))):
    stored_word = stored_words[i]
    fresh_word = fresh_words[i]
    
    stored_text = stored_word.get('text', '')[:24]
    stored_font = f"{stored_word.get('font', 'N/A')[:20]} ({stored_word.get('font_weight', 'N/A')})"
    
    fresh_text = fresh_word['text'][:24]
    fresh_font = f"{fresh_word['font'][:20]} ({fresh_word['font_weight']})"
    
    match = '✓' if stored_word.get('text') == fresh_word['text'] else '✗'
    font_match = '✓' if stored_word.get('font') == fresh_word['font'] else '✗'
    
    print(f'{i:<4} {stored_text:<25} {stored_font:<30} {fresh_text:<25} {fresh_font:<30} {match} {font_match}')

cursor.close()
conn.close()
