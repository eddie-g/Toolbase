#!/usr/bin/env python3
"""Re-extract document 159"""

import sys
import os
sys.path.insert(0, '/var/www/html/python')

from pathlib import Path
from extract_pdf_pymupdf import extract_text_with_pymupdf, get_db_connection, save_to_database
import mysql.connector

# Database connection  
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

conn = mysql.connector.connect(**db_config)
cursor = conn.cursor()

# Get document
cursor.execute('SELECT id, original_name, path FROM documents WHERE id = 159')
doc_row = cursor.fetchone()

if not doc_row:
    print('Document 159 not found')
    sys.exit(1)

doc_id, original_name, path = doc_row
pdf_path = f'/var/www/html/storage/app/private/{path}'

print(f'Re-extracting document {doc_id}: {original_name}')
print(f'PDF path: {pdf_path}')
print()

# Extract
extraction_result = extract_text_with_pymupdf(pdf_path)

print(f'\\nExtraction complete:')
print(f'  Total pages: {extraction_result["total_pages"]}')
print(f'  Total words: {extraction_result["total_words"]}')
print(f'  Text length: {len(extraction_result["full_text"])} characters')
print()

# Save to database
conn2 = get_db_connection()
save_to_database(conn2, doc_id, original_name, extraction_result)
conn2.close()

cursor.close()
conn.close()

print('\\n✓ Done!')
