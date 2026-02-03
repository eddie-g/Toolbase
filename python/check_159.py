#!/usr/bin/env python3
"""Check extraction data for document 159"""

import sys
import os
sys.path.insert(0, '/var/www/html/python')

from pathlib import Path
import mysql.connector
import json

# Database connection
env_path = Path('/var/www/html/.env')
db_config = {'host': 'mysql', 'database': 'laravel', 'user': 'sail', 'password': 'password'}

if env_path.exists():
    with open(env_path) as f:
        for line in f:
            line = line.strip()
            if line.startswith('DB_DATABASE='):
                db_config['database'] = line.split('=', 1)[1]

conn = mysql.connector.connect(**db_config)
cursor = conn.cursor()

cursor.execute('SELECT extraction_data FROM pdf_extractions_fitz WHERE document_id = 159')
row = cursor.fetchone()

if row:
    data = json.loads(row[0])
    print(f'Data type: {type(data)}')
    
    if isinstance(data, dict):
        print(f'Total words: {len(data.get("words", []))}')
        print(f'Total pages: {len(data.get("pages", []))}')
        print()
        print('First 30 words from page 1:')
        print('='*120)
        print(f'{"#":<4} {"TEXT":<25} {"FONT":<35} {"SIZE":<6} {"WEIGHT":<7}')
        print('='*120)
        
        for i, word in enumerate(data['words'][:30]):
            text = word.get('text', '')[:24]
            font = word.get('font', 'unknown')[:34]
            size = word.get('font_size', 0)
            weight = word.get('font_weight', 400)
            
            print(f'{i:<4} {text:<25} {font:<35} {size:<6.1f} {weight:<7}')
    else:
        print('Data is a list, not a dict')
        print(f'List length: {len(data)}')

cursor.close()
conn.close()
