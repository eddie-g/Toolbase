#!/usr/bin/env python3
"""
PDF Text Extraction Script using PyMuPDF (fitz)
Extracts text with position data from PDF files and saves to database
Much faster than OCR-based extraction
"""

import sys
import os
import json
from pathlib import Path
import fitz  # PyMuPDF
import mysql.connector
from mysql.connector import Error
from datetime import datetime


def _normalize_font_name(font_name):
    if not font_name:
        return ''
    name = font_name
    if '+' in name:
        prefix, rest = name.split('+', 1)
        if len(prefix) == 6 and prefix.isupper():
            name = rest
    return ''.join(ch for ch in name.lower() if ch.isalnum())


def _font_family_name(font_name):
    if not font_name:
        return ''
    for sep in ('-', '_'):
        if sep in font_name:
            return font_name.split(sep, 1)[0]
    base = _normalize_font_name(font_name)
    for suffix in ("regular", "bold", "italic", "bolditalic", "medium", "semibold", "black", "light"):
        if base.endswith(suffix):
            trimmed = base[: -len(suffix)]
            if trimmed:
                return trimmed
    return font_name


def _parse_font_style(font_name):
    name = _normalize_font_name(font_name)
    return {
        "bold": "bold" in name or "black" in name,
        "italic": "italic" in name or "oblique" in name,
    }


def _score_font_match(target_name, candidate_name):
    target_norm = _normalize_font_name(target_name)
    candidate_norm = _normalize_font_name(candidate_name)
    if not target_norm or not candidate_norm:
        return -1

    target_family = _normalize_font_name(_font_family_name(target_name))
    score = 0
    if target_family and target_family in candidate_norm:
        score += 5
    if target_norm == candidate_norm:
        score += 4

    target_style = _parse_font_style(target_name)
    candidate_style = _parse_font_style(candidate_name)

    if target_style["bold"] == candidate_style["bold"]:
        score += 2
    else:
        score -= 2

    if target_style["italic"] == candidate_style["italic"]:
        score += 2
    else:
        score -= 2

    return score


def match_font_xref(font_name, page_fonts):
    if not font_name or not page_fonts:
        return None

    best = None
    best_score = -1
    for font in page_fonts:
        basefont = font[3] if len(font) > 3 else ''
        name = font[4] if len(font) > 4 else ''
        score = max(_score_font_match(font_name, name), _score_font_match(font_name, basefont))
        if score > best_score:
            best_score = score
            best = font

    if not best:
        return None
    return best[0]


def get_db_connection():
    """Create database connection using Laravel's .env configuration"""
    env_path = Path(__file__).parent.parent / '.env'
    db_config = {
        'host': 'mysql',
        'database': 'laravel',
        'user': 'sail',
        'password': 'password',
        'port': 3306
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
            print(f"✓ Connected to MySQL database: {db_config['database']}")
            return connection
    except Error as e:
        print(f"✗ Error connecting to MySQL: {e}")
        sys.exit(1)


def extract_text_with_pymupdf(pdf_path):
    """
    Extract text from PDF using PyMuPDF with position information
    Returns structured data with text blocks, lines, and spans
    """
    print(f"📄 Opening PDF: {pdf_path}")
    
    try:
        doc = fitz.open(pdf_path)
        print(f"✓ PDF opened: {doc.page_count} pages")
        
        extraction_data = []
        total_words = 0
        full_text_parts = []
        
        for page_num in range(doc.page_count):
            page = doc[page_num]
            print(f"  Processing page {page_num + 1}/{doc.page_count}...")
            
            # Get page dimensions
            page_rect = page.rect
            page_width = page_rect.width
            page_height = page_rect.height
            
            # Extract text with detailed positioning
            # Use flags to preserve ligatures and whitespace for accurate matching
            # Use sort parameter to help with better block detection
            text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_LIGATURES | fitz.TEXT_PRESERVE_WHITESPACE, sort=True)
            blocks = text_dict.get("blocks", [])
            page_fonts = page.get_fonts(full=True)
            
            page_words = []
            page_blocks = []  # Track paragraph blocks
            page_lines = []  # Track individual lines for line-level editing
            page_text_lines = []
            
            block_counter = 0
            for block_num, block in enumerate(blocks):
                if block.get("type") == 0:  # Text block (Paragraph)
                    block_bbox = block.get("bbox")  # (x0, y0, x1, y1)
                    block_width = block_bbox[2] - block_bbox[0]

                    line_items = []
                    for line_num, line in enumerate(block.get("lines", [])):
                        line_bbox = line.get("bbox", (0, 0, 0, 0))
                        span_sizes = [span.get("size", 0) for span in line.get("spans", []) if span.get("text", "")]
                        line_max_size = max(span_sizes) if span_sizes else 0
                        line_items.append({
                            'line_num': line_num,
                            'line': line,
                            'bbox': line_bbox,
                            'x0': line_bbox[0],
                            'y0': line_bbox[1],
                            'max_size': line_max_size
                        })

                    groups = []

                    # Split by font-size tiers if a large size gap exists (e.g., title vs subtitle)
                    size_groups = []
                    if len(line_items) > 1:
                        sizes = [item['max_size'] for item in line_items if item['max_size'] > 0]
                        if sizes:
                            max_size = max(sizes)
                            min_size = min(sizes)
                            if max_size >= (min_size * 1.5) and (max_size - min_size) >= 1:
                                size_threshold = max_size * 0.8
                                large_lines = [item for item in line_items if item['max_size'] >= size_threshold]
                                small_lines = [item for item in line_items if item['max_size'] < size_threshold]
                                if large_lines and small_lines:
                                    size_groups = [large_lines, small_lines]

                    if size_groups:
                        groups = size_groups
                    else:
                        groups = [line_items]

                    # Split each group into sub-blocks if there is a strong x-gap (columns/titles)
                    split_groups = []
                    for candidate in groups:
                        if len(candidate) > 1:
                            sorted_by_x = sorted(candidate, key=lambda item: item['x0'])
                            x_values = [item['x0'] for item in sorted_by_x]
                            max_gap = 0
                            split_index = None
                            for idx in range(len(x_values) - 1):
                                gap = x_values[idx + 1] - x_values[idx]
                                if gap > max_gap:
                                    max_gap = gap
                                    split_index = idx
                            gap_threshold = max(12.0, block_width * 0.08)
                            if split_index is not None and max_gap >= gap_threshold:
                                split_x = (x_values[split_index] + x_values[split_index + 1]) / 2.0
                                left_group = [item for item in candidate if item['x0'] <= split_x]
                                right_group = [item for item in candidate if item['x0'] > split_x]
                                if left_group and right_group:
                                    split_groups.extend([left_group, right_group])
                                    continue
                        split_groups.append(candidate)

                    groups = split_groups if split_groups else [line_items]

                    groups = sorted(groups, key=lambda items: min(item['y0'] for item in items) if items else 0)

                    for group_items in groups:
                        if not group_items:
                            continue

                        group_bboxes = [item['bbox'] for item in group_items]
                        group_left = min(b[0] for b in group_bboxes)
                        group_top = min(b[1] for b in group_bboxes)
                        group_right = max(b[2] for b in group_bboxes)
                        group_bottom = max(b[3] for b in group_bboxes)

                        current_block = {
                            'block_num': block_counter,
                            'left': group_left,
                            'top': group_top,
                            'width': group_right - group_left,
                            'height': group_bottom - group_top,
                            'line_count': len(group_items)
                        }

                        block_text_lines = []
                        block_spans = []  # Store all spans for rich text handling
                        block_line_bboxes = []
                        block_line_heights = []
                        block_style = None  # Dominant style for the block
                        style_counts = {}  # Track most common style

                        for item in sorted(group_items, key=lambda it: it['line_num']):
                            line = item['line']
                            line_text = ""
                            line_bbox = item['bbox']
                            block_line_bboxes.append(line_bbox)
                            block_line_heights.append(line_bbox[3] - line_bbox[1])

                            line_spans = []
                            line_style = None

                            for span_num, span in enumerate(line.get("spans", [])):
                                text = span.get("text", "")
                                if not text:
                                    continue

                                bbox = span.get("bbox")  # (x0, y0, x1, y1)
                                origin = span.get("origin")  # (x, y) baseline point
                                font = span.get("font", "")
                                size = span.get("size", 12)
                                color = span.get("color", 0)
                                flags = span.get("flags", 0)
                                
                                # Get ascender and descender from span (PyMuPDF provides these)
                                ascender = span.get("ascender", 0.8)  # Default ratio if not provided
                                descender = span.get("descender", -0.2)  # Default ratio if not provided

                                # Convert 24-bit color integer to hex (#RRGGBB) for CSS
                                hex_color = f"#{(color >> 16) & 0xFF:02x}{(color >> 8) & 0xFF:02x}{color & 0xFF:02x}"

                                # Calculate properties from flags (bit 4 = bold, bit 1 = italic)
                                is_bold_from_flags = bool(flags & 2**4)
                                is_italic_from_flags = bool(flags & 2**1)
                                
                                # Also infer from font name (more reliable than flags in many PDFs)
                                font_lower = font.lower()
                                is_bold_from_name = 'bold' in font_lower or 'black' in font_lower or 'heavy' in font_lower
                                is_italic_from_name = 'italic' in font_lower or 'oblique' in font_lower
                                
                                # Determine weight - first try to parse explicit weight from font name
                                font_weight = None
                                
                                # Check for explicit weight patterns like "_700wght" or "-700"
                                import re
                                weight_pattern = re.search(r'[_-](\d{3})w?g?h?t?', font_lower)
                                if weight_pattern:
                                    parsed_weight = int(weight_pattern.group(1))
                                    if 100 <= parsed_weight <= 900:
                                        font_weight = parsed_weight
                                
                                # If no explicit weight found, infer from font name keywords
                                if font_weight is None:
                                    if 'thin' in font_lower or 'hairline' in font_lower:
                                        font_weight = 100
                                    elif 'extralight' in font_lower or 'ultralight' in font_lower:
                                        font_weight = 200
                                    elif 'light' in font_lower:
                                        font_weight = 300
                                    elif 'medium' in font_lower:
                                        font_weight = 500
                                    elif 'semibold' in font_lower or 'demibold' in font_lower:
                                        font_weight = 600
                                    elif 'extrabold' in font_lower or 'ultrabold' in font_lower:
                                        font_weight = 800
                                    elif 'black' in font_lower or 'heavy' in font_lower:
                                        font_weight = 900
                                    elif is_bold_from_name or is_bold_from_flags:
                                        font_weight = 700
                                    else:
                                        font_weight = 400
                                
                                # Combine flags and name inference (name takes precedence)
                                is_bold = is_bold_from_name or is_bold_from_flags
                                is_italic = is_italic_from_name or is_italic_from_flags

                                font_xref = match_font_xref(font, page_fonts)

                                # Track style frequency to find dominant style
                                style_key = f"{font}_{size}_{is_bold}_{is_italic}"
                                style_counts[style_key] = style_counts.get(style_key, 0) + len(text)

                                span_data = {
                                    'text': text,
                                    'font': font,
                                    'font_xref': font_xref,
                                    'font_size': size,
                                    'font_weight': font_weight,
                                    'color': color,
                                    'hex_color': hex_color,
                                    'bold': is_bold,
                                    'italic': is_italic,
                                    'flags': flags,
                                    'ascender': ascender,
                                    'descender': descender,
                                    'origin': origin  # (x, y) baseline point
                                }
                                block_spans.append(span_data)
                                line_spans.append(span_data)

                                if line_style is None:
                                    line_style = {
                                        'font': font,
                                        'font_xref': font_xref,
                                        'font_size': size,
                                        'font_weight': font_weight,
                                        'color': color,
                                        'hex_color': hex_color,
                                        'bold': is_bold,
                                        'italic': is_italic
                                    }

                                if block_style is None or style_counts.get(style_key, 0) > style_counts.get(f"{block_style.get('font', '')}_{block_style.get('font_size', 0)}_{block_style.get('bold', False)}_{block_style.get('italic', False)}", 0):
                                    block_style = {
                                        'font': font,
                                        'font_xref': font_xref,
                                        'font_size': size,
                                        'font_weight': font_weight,
                                        'color': color,
                                        'hex_color': hex_color,
                                        'bold': is_bold,
                                        'italic': is_italic,
                                        'line_height': line_bbox[3] - line_bbox[1],
                                        'ascender': ascender,
                                        'descender': descender
                                    }

                                word_data = {
                                    'text': text,
                                    'left': bbox[0],
                                    'top': bbox[1],
                                    'width': bbox[2] - bbox[0],
                                    'height': bbox[3] - bbox[1],
                                    'origin_x': origin[0] if origin else bbox[0],
                                    'origin_y': origin[1] if origin else bbox[3],
                                    'font': font,
                                    'font_xref': font_xref,
                                    'font_size': size,
                                    'font_weight': font_weight,
                                    'color': color,
                                    'hex_color': hex_color,
                                    'bold': is_bold,
                                    'italic': is_italic,
                                    'block_num': block_counter,
                                    'line_num': item['line_num'],
                                    'span_num': span_num,
                                    'ascender': ascender,
                                    'descender': descender
                                }

                                page_words.append(word_data)
                                line_text += text + " "
                                total_words += len(text.split())

                            if line_text.strip():
                                page_text_lines.append(line_text.strip())
                                block_text_lines.append(line_text.strip())

                                line_data = {
                                    'text': line_text.strip(),
                                    'left': line_bbox[0],
                                    'top': line_bbox[1],
                                    'width': line_bbox[2] - line_bbox[0],
                                    'height': line_bbox[3] - line_bbox[1],
                                    'block_num': block_counter,
                                    'line_num': item['line_num'],
                                    'spans': line_spans
                                }
                                if line_style:
                                    line_data.update(line_style)
                                page_lines.append(line_data)

                        current_block['text'] = '\n'.join(block_text_lines)
                        current_block['text_single_line'] = ' '.join(block_text_lines)
                        current_block['text_lines'] = block_text_lines
                        current_block['line_bboxes'] = block_line_bboxes
                        if block_line_heights:
                            current_block['avg_line_height'] = sum(block_line_heights) / len(block_line_heights)
                        current_block['spans'] = block_spans  # Store span data for rich text
                        current_block['has_mixed_styles'] = len(style_counts) > 1  # Flag for mixed styling
                        if block_style:
                            if block_line_heights:
                                block_style['line_height'] = sum(block_line_heights) / len(block_line_heights)
                            current_block.update(block_style)

                        page_blocks.append(current_block)
                        block_counter += 1
            
            # Combine all text from page
            page_full_text = "\n".join(page_text_lines)
            full_text_parts.append(page_full_text)
            
            page_data = {
                'page_number': page_num + 1,
                'width': page_width,
                'height': page_height,
                'blocks': page_blocks,  # Paragraph tracking
                'lines': page_lines,  # Line-level tracking for editing
                'words': page_words,
                'text': page_full_text,
                'word_count': len(page_words)
            }
            
            extraction_data.append(page_data)
            print(f"    ✓ Extracted {len(page_words)} text spans")
        
        # Store page count before closing the document
        total_pages = doc.page_count
        doc.close()
        
        full_text = "\n\n".join(full_text_parts)
        
        return {
            'total_pages': total_pages,
            'total_words': total_words,
            'full_text': full_text,
            'extraction_data': extraction_data
        }
        
    except Exception as e:
        print(f"✗ Error extracting text: {e}")
        raise


def save_to_database(connection, document_id, pdf_filename, extraction_result):
    """Save extraction results to database"""
    cursor = connection.cursor()
    
    try:
        # Check if table exists, use the correct table name
        table_name = 'pdf_extractions_fitz'
        
        insert_query = f"""
            INSERT INTO {table_name} 
            (document_id, pdf_filename, total_pages, total_words, full_text, extraction_data, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """
        
        now = datetime.now()
        extraction_json = json.dumps(extraction_result['extraction_data'])
        
        cursor.execute(insert_query, (
            document_id,
            pdf_filename,
            extraction_result['total_pages'],
            extraction_result['total_words'],
            extraction_result['full_text'],
            extraction_json,
            now,
            now
        ))
        
        connection.commit()
        print(f"✓ Saved extraction data to database (ID: {cursor.lastrowid})")
        
    except Error as e:
        print(f"✗ Database error: {e}")
        connection.rollback()
        raise
    finally:
        cursor.close()


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 extract_pdf_pymupdf.py <pdf_path> [document_id]")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    document_id = int(sys.argv[2]) if len(sys.argv) > 2 else None
    
    if not os.path.exists(pdf_path):
        print(f"✗ PDF file not found: {pdf_path}")
        sys.exit(1)
    
    pdf_filename = os.path.basename(pdf_path)
    
    print("=" * 60)
    print("PyMuPDF Text Extraction")
    print("=" * 60)
    print(f"PDF: {pdf_filename}")
    print(f"Document ID: {document_id}")
    print()
    
    # Extract text
    extraction_result = extract_text_with_pymupdf(pdf_path)
    
    print()
    print("=" * 60)
    print("Extraction Summary")
    print("=" * 60)
    print(f"Total pages: {extraction_result['total_pages']}")
    print(f"Total words: {extraction_result['total_words']}")
    print(f"Text length: {len(extraction_result['full_text'])} characters")
    print()
    
    # Save to database
    connection = get_db_connection()
    save_to_database(connection, document_id, pdf_filename, extraction_result)
    connection.close()
    
    print()
    print("✓ Extraction complete!")


if __name__ == "__main__":
    main()
