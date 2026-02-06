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


def _merge_same_row_lines(line_items):
    """
    Merge line items that share the same visual row into single items.
    This handles cases where PyMuPDF splits text on the same visual line into
    separate line entries (e.g., due to inline font changes, italic text, or gaps).
    """
    if len(line_items) <= 1:
        return line_items

    # Sort by vertical position
    sorted_items = sorted(line_items, key=lambda item: item['y0'])

    rows = [[sorted_items[0]]]

    for i in range(1, len(sorted_items)):
        item = sorted_items[i]
        # Check overlap with current row
        row = rows[-1]
        row_y0 = min(it['bbox'][1] for it in row)
        row_y1 = max(it['bbox'][3] for it in row)
        item_y0 = item['bbox'][1]
        item_y1 = item['bbox'][3]

        y_overlap = min(row_y1, item_y1) - max(row_y0, item_y0)
        min_h = min(row_y1 - row_y0, item_y1 - item_y0)

        should_merge = min_h > 0 and y_overlap / min_h > 0.3

        # Don't merge items with very different font sizes, even on same visual row.
        # This prevents e.g. a large "$39.60" (37.5pt) from merging with nearby
        # small address text (15pt) just because the tall glyph overlaps vertically.
        if should_merge:
            row_max_size = max(
                (it['max_size'] for it in row if it['max_size'] > 0), default=0
            )
            item_size = item['max_size']
            if row_max_size > 0 and item_size > 0:
                size_ratio = min(row_max_size, item_size) / max(row_max_size, item_size)
                if size_ratio < 0.6:
                    should_merge = False

        if should_merge:
            rows[-1].append(item)
        else:
            rows.append([item])

    # Merge each multi-item row into a single line item
    merged_items = []
    for row in rows:
        if len(row) == 1:
            merged_items.append(row[0])
        else:
            # Sort by x position (left to right)
            row_sorted = sorted(row, key=lambda it: it['x0'])

            # Combine bounding boxes
            all_bboxes = [it['bbox'] for it in row_sorted]
            merged_bbox = (
                min(b[0] for b in all_bboxes),
                min(b[1] for b in all_bboxes),
                max(b[2] for b in all_bboxes),
                max(b[3] for b in all_bboxes)
            )

            # Combine spans from all lines, preserving left-to-right order
            combined_spans = []
            for it in row_sorted:
                combined_spans.extend(it['line'].get('spans', []))

            merged_line = {
                'spans': combined_spans,
                'bbox': merged_bbox
            }

            all_sizes = [s.get('size', 0) for s in combined_spans if s.get('text', '')]
            max_size = max(all_sizes) if all_sizes else 0

            merged_items.append({
                'line_num': row_sorted[0]['line_num'],
                'line': merged_line,
                'bbox': merged_bbox,
                'x0': merged_bbox[0],
                'y0': merged_bbox[1],
                'max_size': max_size
            })

    return merged_items


def _groups_share_visual_row(group_a, group_b):
    """Check if any line in group_a shares the same visual row with any line in group_b."""
    for a_item in group_a:
        a_y0, a_y1 = a_item['bbox'][1], a_item['bbox'][3]
        for b_item in group_b:
            b_y0, b_y1 = b_item['bbox'][1], b_item['bbox'][3]
            y_overlap = min(a_y1, b_y1) - max(a_y0, b_y0)
            min_h = min(a_y1 - a_y0, b_y1 - b_y0)
            if min_h > 0 and y_overlap / min_h > 0.3:
                return True
    return False


def _merge_adjacent_page_blocks(page_blocks, page_width, page_words, page_lines):
    """
    Post-process page blocks to merge blocks that sit on the same visual line.
    This handles cases where PyMuPDF reports inline text as entirely separate blocks.
    Also updates page_words and page_lines to reflect the new block_num assignments.
    """
    if len(page_blocks) <= 1:
        return page_blocks

    # Build a mapping from old block_num -> new block_num as merges happen
    # Key: old block_num, Value: the block_num it was merged into
    merge_map = {}

    changed = True
    while changed:
        changed = False
        new_blocks = []
        used = set()

        for i in range(len(page_blocks)):
            if i in used:
                continue

            block_a = page_blocks[i]
            a_top = block_a['top']
            a_bottom = a_top + block_a['height']
            a_left = block_a['left']
            a_right = a_left + block_a['width']
            a_size = block_a.get('font_size', 12)
            a_line_count = block_a.get('line_count', 1)

            merge_target = None

            for j in range(i + 1, len(page_blocks)):
                if j in used:
                    continue

                block_b = page_blocks[j]
                b_top = block_b['top']
                b_bottom = b_top + block_b['height']
                b_left = block_b['left']
                b_right = b_left + block_b['width']
                b_size = block_b.get('font_size', 12)
                b_line_count = block_b.get('line_count', 1)

                # Only merge single-line blocks on the same visual row.
                # Multi-line blocks are paragraphs — never merge paragraphs together.
                if a_line_count > 1 or b_line_count > 1:
                    continue

                # Must share the same visual row (significant y-overlap)
                y_overlap = min(a_bottom, b_bottom) - max(a_top, b_top)
                min_h = min(block_a['height'], block_b['height'])
                if min_h <= 0 or y_overlap / min_h < 0.5:
                    continue

                # Font sizes must be similar
                if max(a_size, b_size) > 0:
                    size_ratio = min(a_size, b_size) / max(a_size, b_size)
                    if size_ratio < 0.7:
                        continue

                # CRITICAL FIX: Much stricter horizontal gap threshold
                # Elements must be very close together to merge (max 3x font size)
                h_gap = max(b_left - a_right, a_left - b_right, 0)
                max_font = max(a_size, b_size) if max(a_size, b_size) > 0 else 12
                
                # Adaptive threshold based on font size, but with hard limits
                # - Small gap: 3x font size (for closely spaced text)
                # - Hard maximum: 40 points (~0.5 inches) to prevent cross-column merging
                # - Percentage maximum: 5% of page width (much stricter than before)
                adaptive_threshold = min(
                    max_font * 3,  # 3x font size
                    40.0,          # Hard limit: 40 points
                    page_width * 0.05  # 5% of page width (was 15%, way too high!)
                )
                
                if h_gap > adaptive_threshold:
                    continue
                
                # ADDITIONAL SAFETY: Check if blocks are in significantly different horizontal zones
                # If one block is clearly "left-aligned" and another is "right-aligned", don't merge
                page_mid = page_width / 2
                a_center = (a_left + a_right) / 2
                b_center = (b_left + b_right) / 2
                
                # If centers are on opposite sides of page midpoint AND far apart, don't merge
                if (a_center < page_mid < b_center or b_center < page_mid < a_center):
                    # They're on opposite sides of the page
                    center_distance = abs(a_center - b_center)
                    if center_distance > page_width * 0.3:  # Centers are >30% of page width apart
                        continue

                merge_target = j
                break

            if merge_target is not None:
                block_b = page_blocks[merge_target]

                # Record which block_num is being absorbed
                absorbed_block_num = block_b['block_num']
                surviving_block_num = block_a['block_num']
                merge_map[absorbed_block_num] = surviving_block_num

                # Determine left-to-right order
                if block_a['left'] <= block_b['left']:
                    first, second = block_a, block_b
                else:
                    first, second = block_b, block_a

                # Merged bounding box
                m_left = min(block_a['left'], block_b['left'])
                m_top = min(block_a['top'], block_b['top'])
                m_right = max(a_right, b_right)
                m_bottom = max(a_bottom, b_bottom)

                merged_block = {
                    'block_num': surviving_block_num,
                    'left': m_left,
                    'top': m_top,
                    'width': m_right - m_left,
                    'height': m_bottom - m_top,
                }

                # Combine text lines
                merged_block['text_lines'] = first.get('text_lines', []) + second.get('text_lines', [])
                merged_block['text'] = '\n'.join(merged_block['text_lines'])
                merged_block['text_single_line'] = ' '.join(merged_block['text_lines'])
                merged_block['spans'] = first.get('spans', []) + second.get('spans', [])
                merged_block['line_bboxes'] = first.get('line_bboxes', []) + second.get('line_bboxes', [])
                merged_block['line_count'] = first.get('line_count', 0) + second.get('line_count', 0)

                all_heights = [bbox[3] - bbox[1] for bbox in merged_block['line_bboxes']]
                if all_heights:
                    merged_block['avg_line_height'] = sum(all_heights) / len(all_heights)

                merged_block['has_mixed_styles'] = True

                # Style from the larger block (dominant)
                larger = first if len(first.get('text', '')) >= len(second.get('text', '')) else second
                for key in ('font', 'font_xref', 'font_size', 'font_weight', 'color',
                            'hex_color', 'bold', 'italic', 'line_height', 'ascender', 'descender'):
                    if key in larger:
                        merged_block[key] = larger[key]

                new_blocks.append(merged_block)
                used.add(i)
                used.add(merge_target)
                changed = True
            else:
                new_blocks.append(block_a)
                used.add(i)

        page_blocks = new_blocks

    # Build final renumbering map: old block_num -> new sequential index
    old_to_new = {}
    for idx, block in enumerate(page_blocks):
        old_to_new[block['block_num']] = idx
        block['block_num'] = idx

    # Resolve transitive merges: if A was merged into B, and B is now renumbered,
    # find the final index for A
    def resolve_block_num(old_num):
        # Follow merge chain
        visited = set()
        current = old_num
        while current in merge_map and current not in visited:
            visited.add(current)
            current = merge_map[current]
        # Now current is the surviving block_num, map to new index
        if current in old_to_new:
            return old_to_new[current]
        return None

    # Update page_words block_num references
    words_to_remove = []
    for i, word in enumerate(page_words):
        old_bn = word['block_num']
        if old_bn in old_to_new:
            word['block_num'] = old_to_new[old_bn]
        elif old_bn in merge_map:
            new_bn = resolve_block_num(old_bn)
            if new_bn is not None:
                word['block_num'] = new_bn
            else:
                words_to_remove.append(i)
        # else: block_num is already valid or orphaned

    # Update page_lines block_num references
    for line in page_lines:
        old_bn = line['block_num']
        if old_bn in old_to_new:
            line['block_num'] = old_to_new[old_bn]
        elif old_bn in merge_map:
            new_bn = resolve_block_num(old_bn)
            if new_bn is not None:
                line['block_num'] = new_bn

    return page_blocks


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

                    # Pre-process: merge lines that share the same visual row.
                    # This prevents inline formatting (e.g., italic book titles) from
                    # being split into separate bounding boxes.
                    line_items = _merge_same_row_lines(line_items)

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
                                    # Safety: don't split if the groups share a visual row
                                    # (this means they're inline text, not separate columns)
                                    if not _groups_share_visual_row(left_group, right_group):
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

            # Post-process: merge blocks that sit on the same visual line
            # This catches cases where PyMuPDF reports inline text as separate blocks
            page_blocks = _merge_adjacent_page_blocks(page_blocks, page_width, page_words, page_lines)

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


def save_to_database(connection, document_id, pdf_filename, extraction_result, user_email=None, session_id=None):
    """Save extraction results to database"""
    cursor = connection.cursor()
    
    try:
        # Check if table exists, use the correct table name
        table_name = 'pdf_extractions_fitz'
        
        # Check if record exists - prioritize user_email over session_id if not guest
        existing = None
        if user_email and user_email != 'guest':
            # For authenticated users, match by document_id and user_email
            cursor.execute(
                f"SELECT id FROM {table_name} WHERE document_id = %s AND user_email = %s",
                (document_id, user_email)
            )
            existing = cursor.fetchone()
            
            if existing:
                # Update existing record
                update_query = f"""
                    UPDATE {table_name}
                    SET pdf_filename = %s, total_pages = %s, total_words = %s, 
                        full_text = %s, extraction_data = %s, updated_at = %s, session_id = %s
                    WHERE document_id = %s AND user_email = %s
                """
                now = datetime.now()
                extraction_json = json.dumps(extraction_result['extraction_data'])
                
                cursor.execute(update_query, (
                    pdf_filename,
                    extraction_result['total_pages'],
                    extraction_result['total_words'],
                    extraction_result['full_text'],
                    extraction_json,
                    now,
                    session_id,
                    document_id,
                    user_email
                ))
                connection.commit()
                print(f"✓ Updated extraction data in database (ID: {existing[0]}) by user_email")
                return
        elif session_id:
            # For guest users, match by document_id and session_id
            cursor.execute(
                f"SELECT id FROM {table_name} WHERE document_id = %s AND session_id = %s",
                (document_id, session_id)
            )
            existing = cursor.fetchone()
            
            if existing:
                # Update existing record
                update_query = f"""
                    UPDATE {table_name}
                    SET pdf_filename = %s, total_pages = %s, total_words = %s, 
                        full_text = %s, extraction_data = %s, updated_at = %s
                    WHERE document_id = %s AND session_id = %s
                """
                now = datetime.now()
                extraction_json = json.dumps(extraction_result['extraction_data'])
                
                cursor.execute(update_query, (
                    pdf_filename,
                    extraction_result['total_pages'],
                    extraction_result['total_words'],
                    extraction_result['full_text'],
                    extraction_json,
                    now,
                    document_id,
                    session_id
                ))
                connection.commit()
                print(f"✓ Updated extraction data in database (ID: {existing[0]}) by session_id")
                return
        
        # Insert new record
        insert_query = f"""
            INSERT INTO {table_name} 
            (document_id, user_email, session_id, pdf_filename, total_pages, total_words, full_text, extraction_data, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """
        
        now = datetime.now()
        extraction_json = json.dumps(extraction_result['extraction_data'])
        
        cursor.execute(insert_query, (
            document_id,
            user_email,
            session_id,
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
        print("Usage: python3 extract_pdf_pymupdf.py <pdf_path> [document_id] [user_email] [session_id]")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    document_id = int(sys.argv[2]) if len(sys.argv) > 2 else None
    user_email = sys.argv[3] if len(sys.argv) > 3 else None
    session_id = sys.argv[4] if len(sys.argv) > 4 else None
    
    if not os.path.exists(pdf_path):
        print(f"✗ PDF file not found: {pdf_path}")
        sys.exit(1)
    
    pdf_filename = os.path.basename(pdf_path)
    
    print("=" * 60)
    print("PyMuPDF Text Extraction")
    print("=" * 60)
    print(f"PDF: {pdf_filename}")
    print(f"Document ID: {document_id}")
    print(f"User Email: {user_email}")
    print(f"Session ID: {session_id}")
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
    save_to_database(connection, document_id, pdf_filename, extraction_result, user_email, session_id)
    connection.close()
    
    print()
    print("✓ Extraction complete!")


if __name__ == "__main__":
    main()
