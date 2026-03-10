#!/usr/bin/env python3
"""
PDF Text Extraction Script using PyMuPDF (fitz)
Extracts text with position data from PDF files and saves to database
Much faster than OCR-based extraction
"""

import sys
import os
import json
import re
from pathlib import Path
import fitz  # PyMuPDF
import mysql.connector
from mysql.connector import Error
from datetime import datetime


_EXTRACTION_ZERO_WIDTH_RE = re.compile(r'[\u200B\u200C\u200D\u2060\uFEFF]')
_EXTRACTION_PRIVATE_USE_RE = re.compile(r'[\uE000-\uF8FF]')
_EXTRACTION_CONTROL_RE = re.compile(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]')
_EXTRACTION_UNDERLINE_RUN_RE = re.compile(r'_{3,}')
_EXTRACTION_LARGE_GAP_RE = re.compile(r' {8,}')
_PDF_TEXT_SHOW_OP_RE = re.compile(
    rb'BT\b|ET\b|'
    rb'[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+Tm\b|'
    rb'[-\d.]+\s+[-\d.]+\s+T[dD]\b|'
    rb'\((?:\\.|[^\\)])*\)\s*Tj\b|'
    rb'\[(?:\\.|[^\]])*\]\s*TJ\b',
    re.DOTALL,
)


def sanitize_extracted_text(text):
    """
    Normalize extracted text to remove invisible/unsupported glyphs that
    commonly appear from problematic PDF font encodings.
    """
    if not text:
        return ''

    cleaned = text.replace('\u00A0', ' ')
    cleaned = cleaned.replace('\uFFFD', '')
    cleaned = _EXTRACTION_ZERO_WIDTH_RE.sub('', cleaned)
    cleaned = _EXTRACTION_PRIVATE_USE_RE.sub('', cleaned)
    cleaned = _EXTRACTION_CONTROL_RE.sub('', cleaned)
    return cleaned


def _decode_pdf_string_token(token):
    if not token or len(token) < 2 or token[:1] != b'(' or token[-1:] != b')':
        return ''
    body = token[1:-1]
    body = body.replace(b'\\(', b'(').replace(b'\\)', b')').replace(b'\\\\', b'\\')
    return sanitize_extracted_text(body.decode('latin-1', errors='ignore'))


def _collapse_source_op_text(text):
    return ' '.join(sanitize_extracted_text(text).split())


def _compact_source_op_text(text):
    return re.sub(r'\s+', '', sanitize_extracted_text(text or ''))


def _dedupe_source_content_ops(ops):
    deduped = []
    seen = set()
    for op in ops or []:
        if not isinstance(op, dict):
            continue
        try:
            xref = int(op.get('xref'))
            start = int(op.get('start'))
            end = int(op.get('end'))
        except Exception:
            continue
        key = (
            xref,
            start,
            end,
            sanitize_extracted_text(op.get('matched_text', '')),
        )
        if key in seen:
            continue
        seen.add(key)
        deduped.append({
            'xref': xref,
            'start': start,
            'end': end,
            'operator': str(op.get('operator') or ''),
            'operator_text': sanitize_extracted_text(op.get('operator_text', '')),
            'matched_text': sanitize_extracted_text(op.get('matched_text', '')),
            'collapsed_operator_text': _collapse_source_op_text(op.get('operator_text', '')),
            'compact_operator_text': _compact_source_op_text(op.get('operator_text', '')),
            'tm_x': float(op.get('tm_x')) if op.get('tm_x') is not None else None,
            'tm_y': float(op.get('tm_y')) if op.get('tm_y') is not None else None,
        })
    return deduped


def _clone_source_content_ops_with_matched_text(ops, matched_text):
    cloned = []
    for op in ops or []:
        if not isinstance(op, dict):
            continue
        cloned.append({
            **op,
            'matched_text': sanitize_extracted_text(matched_text or op.get('matched_text', '')),
        })
    return _dedupe_source_content_ops(cloned)


def _extract_text_show_ops_from_stream(stream, xref, page_height):
    if not stream:
        return []

    ops = []
    cur_x = None
    cur_y = None
    in_text = False
    token_re = re.compile(rb'\((?:\\.|[^\\)])*\)|-?\d+(?:\.\d+)?')

    for match in _PDF_TEXT_SHOW_OP_RE.finditer(stream):
        raw = match.group(0)
        stripped = raw.strip()

        if stripped == b'BT':
            in_text = True
            cur_x = None
            cur_y = None
            continue
        if stripped == b'ET':
            in_text = False
            cur_x = None
            cur_y = None
            continue
        if not in_text:
            continue

        if stripped.endswith(b'Tm'):
            nums = re.findall(rb'-?\d+(?:\.\d+)?', stripped)
            if len(nums) >= 6:
                try:
                    cur_x = float(nums[4])
                    cur_y = float(nums[5])
                except Exception:
                    pass
            continue

        if stripped.endswith(b'Td') or stripped.endswith(b'TD'):
            nums = re.findall(rb'-?\d+(?:\.\d+)?', stripped)
            if len(nums) >= 2:
                try:
                    dx = float(nums[0])
                    dy = float(nums[1])
                    if cur_x is None or cur_y is None:
                        cur_x = dx
                        cur_y = dy
                    else:
                        cur_x += dx
                        cur_y += dy
                except Exception:
                    pass
            continue

        operator = None
        op_text = ''
        if stripped.endswith(b'Tj'):
            operator = 'Tj'
            str_match = re.search(rb'\((?:\\.|[^\\)])*\)\s*Tj\b', stripped, re.DOTALL)
            if str_match:
                string_token = str_match.group(0)[:-2].strip()
                op_text = _decode_pdf_string_token(string_token)
        elif stripped.endswith(b'TJ'):
            operator = 'TJ'
            open_idx = stripped.find(b'[')
            close_idx = stripped.rfind(b']')
            if open_idx >= 0 and close_idx > open_idx:
                tokens = token_re.findall(stripped[open_idx + 1:close_idx])
                op_text = sanitize_extracted_text(''.join(
                    _decode_pdf_string_token(tok)
                    for tok in tokens
                    if tok.startswith(b'(')
                ))

        op_text = sanitize_extracted_text(op_text)
        if not operator or not op_text:
            continue

        ops.append({
            'xref': int(xref),
            'start': int(match.start()),
            'end': int(match.end()),
            'operator': operator,
            'operator_text': op_text,
            'collapsed_operator_text': _collapse_source_op_text(op_text),
            'compact_operator_text': _compact_source_op_text(op_text),
            'tm_x': cur_x,
            'tm_y': (page_height - cur_y) if cur_y is not None else None,
        })

    return ops


def _build_source_content_op_index(doc, page):
    by_text = {}
    ordered_ops = []
    page_height = float(page.rect.height or 0)
    for xref in page.get_contents() or []:
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        for op in _extract_text_show_ops_from_stream(stream, xref, page_height):
            ordered_ops.append(op)
            exact_text = op.get('operator_text')
            collapsed_text = op.get('collapsed_operator_text')
            compact_text = op.get('compact_operator_text')
            if exact_text:
                by_text.setdefault(exact_text, []).append(op)
            if collapsed_text and collapsed_text != exact_text:
                by_text.setdefault(collapsed_text, []).append(op)
            if compact_text and compact_text not in {exact_text, collapsed_text}:
                by_text.setdefault(compact_text, []).append(op)
    return {
        'by_text': by_text,
        'ordered_ops': ordered_ops,
    }


def _match_source_content_op(source_op_index, text, origin):
    if not source_op_index or not text:
        return None

    text = sanitize_extracted_text(text)
    if not text:
        return None

    collapsed_text = _collapse_source_op_text(text)
    compact_text = _compact_source_op_text(text)
    candidates = list(source_op_index.get('by_text', {}).get(text) or [])
    if collapsed_text and collapsed_text != text:
        candidates.extend(source_op_index.get('by_text', {}).get(collapsed_text) or [])
    if compact_text and compact_text not in {text, collapsed_text}:
        candidates.extend(source_op_index.get('by_text', {}).get(compact_text) or [])

    target_x = None
    target_y = None
    if origin and len(origin) >= 2:
        try:
            target_x = float(origin[0])
            target_y = float(origin[1])
        except Exception:
            target_x = None
            target_y = None

    ordered_ops = source_op_index.get('ordered_ops', []) or []
    if collapsed_text:
        for candidate in ordered_ops:
            op_text = candidate.get('operator_text', '')
            op_collapsed = candidate.get('collapsed_operator_text', '')
            op_compact = candidate.get('compact_operator_text', '')
            if not op_text:
                continue
            if text not in op_text and collapsed_text not in op_collapsed and compact_text not in op_compact:
                continue
            if candidate not in candidates:
                candidates.append(candidate)

    if not candidates:
        return None

    best = None
    best_score = None
    for candidate in candidates:
        score = 0.0
        cand_text = candidate.get('operator_text', '')
        cand_collapsed = candidate.get('collapsed_operator_text', '')
        cand_compact = candidate.get('compact_operator_text', '')
        contains_match = False
        strong_compact_match = False
        if cand_text == text:
            score -= 4.0
        elif cand_collapsed == collapsed_text:
            score -= 2.0
            contains_match = True
        elif compact_text and cand_compact == compact_text:
            score -= 1.5
            contains_match = True
            strong_compact_match = True
        elif text and text in cand_text:
            score += 0.75
            contains_match = True
        elif collapsed_text and collapsed_text in cand_collapsed:
            score += 1.0
            contains_match = True
        elif compact_text and compact_text in cand_compact:
            score += 1.25
            contains_match = True
            strong_compact_match = len(compact_text) >= 8
        else:
            score += 3.0

        if (
            len(compact_text) < 4
            and contains_match
            and cand_text != text
            and cand_collapsed != collapsed_text
            and cand_compact != compact_text
        ):
            continue

        cand_x = candidate.get('tm_x')
        cand_y = candidate.get('tm_y')
        if target_x is not None and target_y is not None and cand_x is not None and cand_y is not None:
            dx = abs(float(cand_x) - target_x)
            dy = abs(float(cand_y) - target_y)
            if strong_compact_match:
                if dy > 140.0 or dx > 1000.0:
                    continue
                score += min(dx, 250.0) * 0.005
                score += dy * 0.05
                score += min(len(cand_collapsed or cand_text), 500) * 0.001
            elif contains_match:
                if dy > 18.0 or dx > 400.0:
                    continue
                score += min(dx, 200.0) * 0.015
                score += dy * 1.25
                # Prefer shorter containing operators when a cell is embedded in a
                # larger shared row stream.
                score += min(len(cand_collapsed or cand_text), 500) * 0.002
            else:
                if dx > 80.0 or dy > 8.0:
                    continue
                score += dx * 0.05
                score += dy * 0.75
        elif target_y is not None and cand_y is not None:
            dy = abs(float(cand_y) - target_y)
            if strong_compact_match:
                if dy > 140.0:
                    continue
                score += dy * 0.05
                score += min(len(cand_collapsed or cand_text), 500) * 0.001
            elif contains_match:
                if dy > 10.0:
                    continue
                score += dy * 1.5
                score += min(len(cand_collapsed or cand_text), 500) * 0.002
            else:
                if dy > 8.0:
                    continue
                score += dy

        if best_score is None or score < best_score:
            best = candidate
            best_score = score

    return best


def _collect_horizontal_lines(page):
    """Collect horizontal vector line segments from page drawings."""
    lines = []
    try:
        drawings = page.get_drawings() or []
    except Exception:
        return lines

    for d in drawings:
        stroke_w = float(d.get("width") or 0)
        for item in d.get("items", []):
            if not item or item[0] != "l":
                continue
            p1, p2 = item[1], item[2]
            if p1 is None or p2 is None:
                continue
            if abs(float(p1.y) - float(p2.y)) > 0.5:
                continue
            x0 = min(float(p1.x), float(p2.x))
            x1 = max(float(p1.x), float(p2.x))
            if (x1 - x0) < 20:
                continue
            y = (float(p1.y) + float(p2.y)) / 2.0
            lines.append((x0, x1, y, stroke_w))
    return lines


def _span_has_drawn_underline(span_bbox, horizontal_lines):
    """True when a span bbox sits on top of an existing horizontal vector line."""
    if not span_bbox or not horizontal_lines:
        return False
    x0, y0, x1, y1 = [float(v) for v in span_bbox]
    span_w = max(1.0, x1 - x0)
    for lx0, lx1, ly, _sw in horizontal_lines:
        if ly < (y0 - 2.0) or ly > (y1 + 2.0):
            continue
        overlap = max(0.0, min(x1, lx1) - max(x0, lx0))
        if overlap <= 0:
            continue
        overlap_ratio = overlap / span_w
        if overlap_ratio >= 0.55 or overlap >= 40:
            return True
    return False


def _overlay_render_text(text, has_drawn_underline):
    """
    Build text used for overlay rendering. If underscore placeholders are already
    represented by vector lines, suppress duplicate underscore glyphs.
    """
    if not text:
        return text, False
    if not has_drawn_underline or "_" not in text:
        return text, False
    if not _EXTRACTION_UNDERLINE_RUN_RE.search(text):
        return text, False

    render_text = _EXTRACTION_UNDERLINE_RUN_RE.sub("", text)
    render_text = re.sub(r"[ \t]{2,}", " ", render_text).rstrip()
    changed = (render_text != text)
    return render_text, changed


def _split_gap_separated_span_words(text, bbox, word_data, page_pymupdf_words, has_drawn_underline):
    """
    Split a single extracted span into sub-word entries when the PDF encoded
    distant columns / header fragments as one text object with a huge internal
    whitespace gap (for example: "Contract Concerning      Page of 10").
    """
    if not text or not bbox or not _EXTRACTION_LARGE_GAP_RE.search(text):
        return None

    sx0, sy0, sx1, sy1 = bbox
    candidates = []

    for pw in page_pymupdf_words:
        wx0, wy0, wx1, wy1, wtext = pw[0], pw[1], pw[2], pw[3], pw[4]
        if wy0 < sy0 - 2 or wy1 > sy1 + 2:
            continue
        if wx1 < sx0 - 2 or wx0 > sx1 + 2:
            continue

        wtext_clean = sanitize_extracted_text(wtext)
        if not wtext_clean:
            continue

        render_text, suppressed = _overlay_render_text(wtext_clean, has_drawn_underline)
        sub_entry = dict(word_data)
        sub_entry['text'] = wtext_clean
        sub_entry['render_text'] = render_text
        sub_entry['suppress_drawn_underline'] = suppressed
        sub_entry['left'] = wx0
        sub_entry['top'] = wy0
        sub_entry['width'] = wx1 - wx0
        sub_entry['height'] = wy1 - wy0
        sub_entry['origin_x'] = wx0
        sub_entry['origin_y'] = wy1
        candidates.append((wx0, sub_entry))

    if len(candidates) < 2:
        return None

    ordered = [entry for _, entry in sorted(candidates, key=lambda item: item[0])]
    collapsed_span = ' '.join(text.split())
    collapsed_words = ' '.join(entry['text'] for entry in ordered)
    if collapsed_span != collapsed_words:
        return None

    total_word_width = sum(max(0.1, float(entry['width'] or 0)) for entry in ordered)
    span_width = max(0.1, float(sx1 - sx0))
    if (span_width / total_word_width) < 1.8:
        return None

    largest_gap = 0.0
    for prev, curr in zip(ordered, ordered[1:]):
        prev_right = float(prev['left']) + float(prev['width'])
        curr_left = float(curr['left'])
        largest_gap = max(largest_gap, curr_left - prev_right)

    gap_threshold = max(36.0, float(word_data.get('font_size') or 12) * 6.0)
    if largest_gap < gap_threshold:
        return None

    return ordered


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


def extract_embedded_fonts(pdf_path, document_id, output_dir=None):
    """
    Extract embedded fonts from a PDF and save as web-usable font files.
    Returns a dict mapping PDF font names to their extracted file paths
    and CSS metadata (family, weight, style).
    """
    if output_dir is None:
        # Default: save under public/fonts/extracted/{document_id}/
        script_dir = os.path.dirname(os.path.abspath(__file__))
        project_root = os.path.abspath(os.path.join(script_dir, '..', '..'))
        output_dir = os.path.join(project_root, 'public', 'fonts', 'extracted', str(document_id))

    os.makedirs(output_dir, exist_ok=True)

    doc = fitz.open(pdf_path)
    embedded_fonts = {}
    seen_xrefs = set()

    for page in doc:
        page_fonts = page.get_fonts(full=True)
        for font_info in page_fonts:
            xref = font_info[0]
            if xref in seen_xrefs:
                continue
            seen_xrefs.add(xref)

            font_ext = font_info[1]   # e.g. 'ttf', 'cff', 'n/a'
            font_name = font_info[3]  # e.g. 'AAAAAA+TahomaUnicode,Bold'

            if font_ext == 'n/a' or not font_ext:
                continue  # Skip non-embedded fonts (Type1 builtins like Helvetica)

            try:
                name, ext, subtype, content = doc.extract_font(xref)
            except Exception:
                continue

            if not content or len(content) < 100:
                continue  # Skip empty or trivially small fonts

            # Clean font name: remove AAAAAA+ prefix
            clean_name = font_name
            if '+' in clean_name:
                prefix, rest = clean_name.split('+', 1)
                if len(prefix) == 6 and prefix.isupper():
                    clean_name = rest

            # Determine CSS font-family, weight, style from font name
            lower_name = clean_name.lower()

            # Extract family (strip weight/style suffixes)
            family_base = clean_name.split(',')[0]  # "TahomaUnicode,Bold" → "TahomaUnicode"
            for sep in ('-', '_'):
                if sep in family_base:
                    family_base = family_base.split(sep, 1)[0]

            # Determine weight
            css_weight = '400'
            if 'bold' in lower_name and ('extra' in lower_name or 'ultra' in lower_name):
                css_weight = '800'
            elif 'semibold' in lower_name or 'demibold' in lower_name:
                css_weight = '600'
            elif 'bold' in lower_name or 'black' in lower_name or 'heavy' in lower_name:
                css_weight = '700'
            elif 'medium' in lower_name:
                css_weight = '500'
            elif 'light' in lower_name:
                css_weight = '300'
            elif 'thin' in lower_name or 'hairline' in lower_name:
                css_weight = '100'

            # Determine style
            css_style = 'italic' if ('italic' in lower_name or 'oblique' in lower_name) else 'normal'

            # Save font file
            safe_name = clean_name.replace(',', '_').replace('+', '_').replace(' ', '_')
            filename = f"{safe_name}.{ext}"
            filepath = os.path.join(output_dir, filename)
            with open(filepath, 'wb') as f:
                f.write(content)

            # Web-relative path (from public/)
            web_path = f"/fonts/extracted/{document_id}/{filename}"

            embedded_fonts[clean_name] = {
                'pdf_font_name': font_name,
                'clean_name': clean_name,
                'family': family_base,
                'css_weight': css_weight,
                'css_style': css_style,
                'file_path': web_path,
                'file_ext': ext,
                'xref': xref,
            }

            print(f"  📋 Extracted font: {clean_name} → {filename} (weight={css_weight}, style={css_style})")

    doc.close()
    print(f"  ✓ Extracted {len(embedded_fonts)} embedded fonts")
    return embedded_fonts


def _trace_text_from_chars(chars):
    out = []
    for ch in chars or []:
        if not ch or len(ch) < 1:
            continue
        codepoint = ch[0]
        if codepoint is None:
            continue
        try:
            out.append(chr(codepoint))
        except Exception:
            continue
    return sanitize_extracted_text(''.join(out))


def _collapse_trace_text(text):
    return ' '.join(sanitize_extracted_text(text).split())


def _build_texttrace_index(page):
    """
    Build a lookup structure keyed by sanitized text for fast matching from
    get_text('dict') spans to get_texttrace() paint metrics.
    """
    by_text = {}
    try:
        traces = page.get_texttrace() or []
    except Exception:
        return by_text

    for tr in traces:
        chars = tr.get('chars') or []
        if not chars:
            continue
        text = _trace_text_from_chars(chars)
        if not text:
            continue
        first = chars[0]
        if len(first) < 3 or not first[2]:
            continue
        origin = first[2]
        rec = {
            'text': text,
            'collapsed_text': _collapse_trace_text(text),
            'origin': origin,
            'font': tr.get('font', ''),
            'size': tr.get('size', 0),
            'bbox': tr.get('bbox'),
            'linewidth': tr.get('linewidth'),
            'render_type': tr.get('type'),
            'opacity': tr.get('opacity'),
            'seqno': tr.get('seqno'),
            'spacewidth': tr.get('spacewidth'),
            'ascender': tr.get('ascender'),
            'descender': tr.get('descender'),
        }
        by_text.setdefault(text, []).append(rec)
        collapsed_text = rec['collapsed_text']
        if collapsed_text and collapsed_text != text:
            by_text.setdefault(collapsed_text, []).append(rec)
    return by_text


def _match_texttrace_span(trace_index, text, origin, font, size):
    """
    Find the closest texttrace entry by same text and nearest baseline origin,
    with font/size compatibility checks.
    """
    if not text or not origin:
        return None
    collapsed_text = _collapse_trace_text(text)
    candidates = trace_index.get(text) or []
    if not candidates and collapsed_text:
        candidates = trace_index.get(collapsed_text) or []
    if not candidates:
        return None

    target_font = _normalize_font_name(font)
    best = None
    best_score = None
    for cand in candidates:
        cand_origin = cand.get('origin')
        if not cand_origin:
            continue
        dx = abs(float(cand_origin[0]) - float(origin[0]))
        dy = abs(float(cand_origin[1]) - float(origin[1]))
        if dx > 2.0 or dy > 2.0:
            continue

        size_diff = abs(float(cand.get('size') or 0) - float(size or 0))
        if size_diff > 0.6:
            continue

        cand_font = _normalize_font_name(cand.get('font'))
        font_penalty = 0.0 if (not target_font or target_font == cand_font) else 1.0
        score = dx + dy + (size_diff * 2.0) + font_penalty
        if best_score is None or score < best_score:
            best = cand
            best_score = score
    return best


def _is_invisible_text_render_type(render_type):
    """
    PDF text rendering modes 3 and 7 do not paint glyphs.
    They can still be extractable, which makes overlay mode resurrect text
    the user cannot actually see in the saved PDF.
    """
    try:
        return int(render_type) in (3, 7)
    except Exception:
        return False


def _rect_area(rect):
    if not rect or len(rect) < 4:
        return 0.0
    width = max(0.0, float(rect[2]) - float(rect[0]))
    height = max(0.0, float(rect[3]) - float(rect[1]))
    return width * height


def _rect_intersection_area(a, b):
    if not a or not b or len(a) < 4 or len(b) < 4:
        return 0.0
    x0 = max(float(a[0]), float(b[0]))
    y0 = max(float(a[1]), float(b[1]))
    x1 = min(float(a[2]), float(b[2]))
    y1 = min(float(a[3]), float(b[3]))
    if x1 <= x0 or y1 <= y0:
        return 0.0
    return (x1 - x0) * (y1 - y0)


def _build_opaque_fill_occluders(page):
    """
    Collect later opaque fill-path rectangles that can fully cover text painted
    earlier in the same content stream.
    """
    occluders = []
    try:
        drawings = page.get_drawings() or []
    except Exception:
        return occluders

    for drawing in drawings:
        rect = drawing.get('rect')
        seqno = drawing.get('seqno')
        fill = drawing.get('fill')
        fill_opacity = drawing.get('fill_opacity')
        if rect is None or seqno is None or fill is None:
            continue
        try:
            if float(fill_opacity if fill_opacity is not None else 1.0) < 0.98:
                continue
        except Exception:
            continue
        if _rect_area(rect) < 1.0:
            continue
        occluders.append({
            'seqno': int(seqno),
            'bbox': tuple(float(v) for v in rect),
        })

    return occluders


def _is_texttrace_occluded(trace_match, occluders):
    """
    A text run is effectively invisible if a later opaque fill-path covers
    nearly all of its painted bbox.
    """
    if not trace_match or not occluders:
        return False

    text_bbox = trace_match.get('bbox')
    text_seqno = trace_match.get('seqno')
    text_opacity = trace_match.get('opacity')
    if not text_bbox or text_seqno is None:
        return False

    try:
        if text_opacity is not None and float(text_opacity) < 0.01:
            return True
    except Exception:
        pass

    text_area = _rect_area(text_bbox)
    if text_area <= 0:
        return False

    for occluder in occluders:
        if int(occluder.get('seqno', -1)) <= int(text_seqno):
            continue
        overlap = _rect_intersection_area(text_bbox, occluder.get('bbox'))
        if (overlap / text_area) >= 0.98:
            return True

    return False


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

    # Sort by vertical position, then horizontal for deterministic L-to-R order
    sorted_items = sorted(line_items, key=lambda item: (item['y0'], item['x0']))

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

        # Don't merge items with a significant horizontal overlap (z-ordered text).
        # Normal text flows left-to-right with positive gaps.
        # Minimal negative gap (kerning) is okay, but large overlap means
        # distinct text elements on top of each other (e.g. form fields).
        if should_merge:
            row_x0 = min(it['bbox'][0] for it in row)
            row_x1 = max(it['bbox'][2] for it in row)
            item_x0 = item['bbox'][0]
            item_x1 = item['bbox'][2]
            
            # Check gap (positive) or overlap (negative)
            # We want to merge items that are SEQUENTIAL (small positive gap).
            # We do NOT want to merge items that OVERLAP significantly (negative gap).
            
            # Distance between end of row and start of item
            dist = item_x0 - row_x1
            
            if dist > 0:
                # Positive gap logic (existing)
                avg_size = max(row_max_size if row_max_size > 0 else 12,
                               item['max_size'] if item['max_size'] > 0 else 12)
                x_gap_threshold = max(avg_size * 2, 15)
                if dist > x_gap_threshold:
                    should_merge = False
            else:
                # Negative gap (overlap)
                overlap = -dist
                # Allow small overlap for italics/kerning (up to 20% of font size)
                avg_size = max(row_max_size if row_max_size > 0 else 12,
                               item['max_size'] if item['max_size'] > 0 else 12)
                if overlap > avg_size * 0.2:
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
                
                # Don't re-merge blocks that were explicitly split by x-gap detection
                if block_a.get('_from_xgap_split') or block_b.get('_from_xgap_split'):
                    continue

                # Adaptive threshold based on font size, but with hard limits
                # - Small gap: 2x font size
                # - Hard maximum: 40 points (~0.5 inches) to prevent cross-column merging
                # - Percentage maximum: 5% of page width
                adaptive_threshold = min(
                    max_font * 2,    # 2x font size
                    40.0,            # Hard limit: 40 points
                    page_width * 0.05  # 5% of page width
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
                merged_block['source_content_ops'] = _dedupe_source_content_ops(
                    (first.get('source_content_ops') or []) + (second.get('source_content_ops') or [])
                )
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


def _row_overlap_ratio(top_a, bottom_a, top_b, bottom_b):
    inter = max(0.0, min(bottom_a, bottom_b) - max(top_a, top_b))
    min_h = max(1.0, min(bottom_a - top_a, bottom_b - top_b))
    return inter / min_h


def _backfill_shared_row_source_content_ops(page_lines, page_words, page_blocks, source_op_index):
    if not page_lines or not page_words or not source_op_index:
        return

    rows = []
    sorted_lines = sorted(
        [line for line in page_lines if (line.get('text') or '').strip()],
        key=lambda line: (float(line.get('top', 0)), float(line.get('left', 0))),
    )

    for line in sorted_lines:
        top = float(line.get('top', 0))
        height = float(line.get('height', 0))
        bottom = top + height
        placed = False
        for row in rows:
            row_top = min(float(item.get('top', 0)) for item in row)
            row_bottom = max(float(item.get('top', 0)) + float(item.get('height', 0)) for item in row)
            if _row_overlap_ratio(top, bottom, row_top, row_bottom) >= 0.65 or abs(top - row_top) <= 2.5:
                row.append(line)
                placed = True
                break
        if not placed:
            rows.append([line])

    for row in rows:
        if len(row) < 2:
            continue
        ordered_row = sorted(row, key=lambda item: float(item.get('left', 0)))
        row_text = ' '.join((item.get('text') or '').strip() for item in ordered_row if (item.get('text') or '').strip()).strip()
        if not row_text:
            continue

        row_left = min(float(item.get('left', 0)) for item in ordered_row)
        row_bottom = max(float(item.get('top', 0)) + float(item.get('height', 0)) for item in ordered_row)
        row_match = _match_source_content_op(source_op_index, row_text, (row_left, row_bottom))
        if not row_match:
            continue

        for line in ordered_row:
            existing_line_ops = line.get('source_content_ops') or []
            if not existing_line_ops:
                line['source_content_ops'] = _clone_source_content_ops_with_matched_text(
                    [row_match],
                    line.get('text', ''),
                )

        for word in page_words:
            for line in ordered_row:
                if word.get('block_num') != line.get('block_num') or word.get('line_num') != line.get('line_num'):
                    continue
                existing_word_ops = word.get('source_content_ops') or []
                if existing_word_ops:
                    continue
                word['source_content_ops'] = _clone_source_content_ops_with_matched_text(
                    [row_match],
                    word.get('text', ''),
                )
                break

    block_ops = {}
    for word in page_words:
        block_num = word.get('block_num')
        if block_num is None:
            continue
        block_ops.setdefault(block_num, []).extend(word.get('source_content_ops') or [])

    for block in page_blocks:
        block_num = block.get('block_num')
        existing_block_ops = block.get('source_content_ops') or []
        merged_block_ops = _dedupe_source_content_ops(existing_block_ops + block_ops.get(block_num, []))
        if merged_block_ops:
            block['source_content_ops'] = merged_block_ops


def get_db_connection():
    """Create database connection using Laravel's .env configuration"""
    # Resolve the Laravel project .env (repo root), with safe fallbacks.
    env_candidates = [
        Path(__file__).resolve().parents[2] / '.env',  # /project/.env
        Path.cwd() / '.env',
        Path(__file__).resolve().parents[1] / '.env',  # /project/python/.env (legacy)
    ]
    env_path = next((candidate for candidate in env_candidates if candidate.exists()), None)
    db_config = {
        'host': 'mysql',
        'database': 'laravel',
        'user': 'sail',
        'password': 'password',
        'port': 3306
    }
    
    if env_path is not None:
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
            
            # Extract text with detailed positioning.
            # Include accurate bbox / ascender flags so span and line geometry
            # matches painted glyph bounds more closely across viewers.
            accurate_bboxes_flag = getattr(fitz, "TEXT_ACCURATE_BBOXES", 0)
            accurate_ascenders_flag = getattr(fitz, "TEXT_ACCURATE_ASCENDERS", 0)
            # Suppress PyMuPDF's heuristic space insertion between glyphs whose
            # advance gap exceeds the threshold.  Some PDFs use TJ kerning arrays
            # that produce a small inter-glyph gap even inside a single word (e.g.
            # "waives" is decoded as "w aives").  TEXT_INHIBIT_SPACES removes those
            # spurious spaces while leaving genuine inter-word spaces intact.
            inhibit_spaces_flag = getattr(fitz, "TEXT_INHIBIT_SPACES", 0)
            text_flags = (
                fitz.TEXT_PRESERVE_LIGATURES
                | fitz.TEXT_PRESERVE_WHITESPACE
                | accurate_bboxes_flag
                | accurate_ascenders_flag
                | inhibit_spaces_flag
            )
            # Use sort parameter to help with better block detection.
            text_dict = page.get_text("dict", flags=text_flags, sort=True)
            blocks = text_dict.get("blocks", [])
            page_fonts = page.get_fonts(full=True)
            trace_index = _build_texttrace_index(page)
            source_content_op_index = _build_source_content_op_index(doc, page)
            opaque_fill_occluders = _build_opaque_fill_occluders(page)
            horizontal_lines = _collect_horizontal_lines(page)

            # Pre-fetch PyMuPDF word-level positions (with the same space-inhibit
            # flag).  Used below to split spans that mix regular text with underscore
            # placeholder runs so each sub-word gets an exact glyph bbox instead of
            # relying on browser font-metric estimates in renderAnchoredUnderscoreSegments.
            page_pymupdf_words = page.get_text('words', flags=inhibit_spaces_flag)
            
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
                    # Uses multi-group clustering to handle 3+ table columns.
                    split_groups = []
                    for candidate in groups:
                        if len(candidate) > 1:
                            sorted_by_x = sorted(candidate, key=lambda item: item['x0'])
                            gap_threshold = max(12.0, block_width * 0.08)

                            # Cluster items by x0 proximity
                            x_groups = [[sorted_by_x[0]]]
                            for item in sorted_by_x[1:]:
                                last_x = max(it['x0'] for it in x_groups[-1])
                                gap = item['x0'] - last_x
                                
                                # Split if significant gap OR significant overlap (z-order)
                                # Gap threshold: 8% of width or 12pt
                                # Overlap threshold: 20% of font size
                                avg_size = max(
                                    (it.get('max_size', 0) for it in x_groups[-1] if it.get('max_size', 0) > 0), 
                                    default=12
                                )
                                overlap_threshold = avg_size * 0.2
                                
                                if gap >= gap_threshold or gap < -overlap_threshold:
                                    x_groups.append([item])
                                else:
                                    x_groups[-1].append(item)

                            if len(x_groups) > 1:
                                # For each adjacent pair of groups, check if the split is valid.
                                # Large gaps (>= 2x font size) are always valid (table columns).
                                # Smaller gaps require that groups don't share a visual row
                                # (to avoid splitting inline text).
                                all_valid = True
                                avg_font_size = max(
                                    (it.get('max_size', 0) for it in candidate if it.get('max_size', 0) > 0),
                                    default=12
                                )
                                large_gap_threshold = max(avg_font_size * 2, 20)
                                for gi in range(len(x_groups) - 1):
                                    right_min_x = min(it['x0'] for it in x_groups[gi + 1])
                                    left_max_x = max(it['x0'] for it in x_groups[gi])
                                    inter_gap = right_min_x - left_max_x
                                    if inter_gap < large_gap_threshold:
                                        # Small gap — only split if groups are on different rows
                                        if _groups_share_visual_row(x_groups[gi], x_groups[gi + 1]):
                                            all_valid = False
                                            break
                                if all_valid:
                                    split_groups.extend(x_groups)
                                    # Mark groups as x-gap split so post-merge won't re-join
                                    for xg in x_groups:
                                        for it in xg:
                                            it['_from_xgap_split'] = True
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
                            'line_count': len(group_items),
                            '_from_xgap_split': any(it.get('_from_xgap_split') for it in group_items)
                        }

                        block_text_lines = []
                        block_spans = []  # Store all spans for rich text handling
                        block_word_bboxes = []
                        block_line_bboxes = []
                        block_line_heights = []
                        block_style = None  # Dominant style for the block
                        style_counts = {}  # Track most common style
                        block_source_content_ops = []

                        for item in sorted(group_items, key=lambda it: it['line_num']):
                            line = item['line']
                            line_text = ""
                            line_bbox = item['bbox']
                            block_line_bboxes.append(line_bbox)
                            block_line_heights.append(line_bbox[3] - line_bbox[1])

                            line_spans = []
                            line_style = None
                            line_source_content_ops = []
                            line_origin = None
                            line_word_start_idx = len(page_words)

                            for span_num, span in enumerate(line.get("spans", [])):
                                text = sanitize_extracted_text(span.get("text", ""))
                                if not text or not text.strip():
                                    continue

                                bbox = span.get("bbox")  # (x0, y0, x1, y1)
                                origin = span.get("origin")  # (x, y) baseline point
                                if line_origin is None and origin:
                                    line_origin = origin
                                font = span.get("font", "")
                                size = span.get("size", 12)
                                color = span.get("color", 0)
                                flags = span.get("flags", 0)
                                
                                # Get ascender and descender from span (PyMuPDF provides these)
                                ascender = span.get("ascender", 0.8)  # Default ratio if not provided
                                descender = span.get("descender", -0.2)  # Default ratio if not provided

                                # Prefer texttrace geometry / metrics when we can match this span.
                                # This captures painted glyph boxes more accurately than dict spans
                                # on some PyMuPDF builds.
                                trace_match = _match_texttrace_span(trace_index, text, origin, font, size)
                                trace_linewidth = None
                                trace_render_type = None
                                trace_spacewidth = None
                                if trace_match:
                                    if _is_invisible_text_render_type(trace_match.get("render_type")):
                                        continue
                                    if _is_texttrace_occluded(trace_match, opaque_fill_occluders):
                                        continue
                                    if trace_match.get("bbox"):
                                        bbox = trace_match.get("bbox")
                                    trace_asc = trace_match.get("ascender")
                                    trace_desc = trace_match.get("descender")
                                    if trace_asc is not None:
                                        ascender = trace_asc
                                    if trace_desc is not None:
                                        descender = trace_desc
                                    trace_linewidth = trace_match.get("linewidth")
                                    trace_render_type = trace_match.get("render_type")
                                    trace_spacewidth = trace_match.get("spacewidth")

                                source_content_op = _match_source_content_op(
                                    source_content_op_index,
                                    trace_match.get('text') if trace_match else text,
                                    trace_match.get('origin') if trace_match else origin,
                                )
                                source_content_ops = _clone_source_content_ops_with_matched_text(
                                    [source_content_op] if source_content_op else [],
                                    text,
                                )

                                has_drawn_underline = _span_has_drawn_underline(bbox, horizontal_lines)
                                render_text, suppressed_drawn_underline = _overlay_render_text(
                                    text, has_drawn_underline
                                )

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
                                # Include hex_color so spans with the same font/size but
                                # different colors (e.g. red bullet vs black body text) are
                                # tracked separately.  This prevents a minority-color span
                                # from becoming the block's dominant color.
                                style_key = f"{font}_{size}_{is_bold}_{is_italic}_{hex_color}"
                                style_counts[style_key] = style_counts.get(style_key, 0) + len(text)

                                span_data = {
                                    'text': text,
                                    'render_text': render_text,
                                    'suppress_drawn_underline': suppressed_drawn_underline,
                                    'has_drawn_underline': has_drawn_underline,
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
                                    'origin': origin,  # (x, y) baseline point
                                    'line_width': trace_linewidth,
                                    'render_type': trace_render_type,
                                    'space_width': trace_spacewidth,
                                    'source_content_ops': source_content_ops,
                                }
                                block_spans.append(span_data)
                                line_spans.append(span_data)
                                line_source_content_ops.extend(source_content_ops)
                                block_source_content_ops.extend(source_content_ops)

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

                                if block_style is None or style_counts.get(style_key, 0) > style_counts.get(f"{block_style.get('font', '')}_{block_style.get('font_size', 0)}_{block_style.get('bold', False)}_{block_style.get('italic', False)}_{block_style.get('hex_color', '#000000')}", 0):
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
                                    'render_text': render_text,
                                    'suppress_drawn_underline': suppressed_drawn_underline,
                                    'has_drawn_underline': has_drawn_underline,
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
                                    'descender': descender,
                                    'line_width': trace_linewidth,
                                    'render_type': trace_render_type,
                                    'space_width': trace_spacewidth,
                                    'source_content_ops': source_content_ops,
                                }

                                # When a span has a drawn underline AND contains an
                                # underscore run with real text both before and after
                                # it (e.g. "is $_______ or more."), split it into
                                # sub-word entries using PyMuPDF's word-level positions.
                                # This gives the overlay editor exact coordinates so
                                # the text after the blank is positioned correctly.
                                split_entries = None
                                if has_drawn_underline and _EXTRACTION_UNDERLINE_RUN_RE.search(text):
                                    us_parts = _EXTRACTION_UNDERLINE_RUN_RE.split(text, maxsplit=1)
                                    pre_text = us_parts[0].strip() if us_parts else ''
                                    post_text = us_parts[1].strip() if len(us_parts) > 1 else ''
                                    if pre_text and post_text:
                                        sx0, sy0, sx1, sy1 = bbox
                                        sub_entries = []
                                        for pw in page_pymupdf_words:
                                            wx0, wy0, wx1, wy1, wtext = pw[0], pw[1], pw[2], pw[3], pw[4]
                                            if wy0 < sy0 - 2 or wy1 > sy1 + 2:
                                                continue
                                            if wx1 < sx0 - 2 or wx0 > sx1 + 2:
                                                continue
                                            wtext_clean = sanitize_extracted_text(wtext)
                                            if not wtext_clean:
                                                continue
                                            r_text_w, suppressed_w = _overlay_render_text(wtext_clean, has_drawn_underline)
                                            sub_entry = dict(word_data)
                                            sub_entry['text'] = wtext_clean
                                            sub_entry['render_text'] = r_text_w
                                            sub_entry['suppress_drawn_underline'] = suppressed_w
                                            sub_entry['left'] = wx0
                                            sub_entry['top'] = wy0
                                            sub_entry['width'] = wx1 - wx0
                                            sub_entry['height'] = wy1 - wy0
                                            sub_entry['origin_x'] = wx0
                                            sub_entry['origin_y'] = wy1
                                            sub_entry['source_content_ops'] = _clone_source_content_ops_with_matched_text(
                                                source_content_ops,
                                                wtext_clean,
                                            )
                                            sub_entries.append((wx0, sub_entry))
                                        if len(sub_entries) > 1:
                                            split_entries = [e for _, e in sorted(sub_entries, key=lambda x: x[0])]

                                if not split_entries:
                                    split_entries = _split_gap_separated_span_words(
                                        text,
                                        bbox,
                                        word_data,
                                        page_pymupdf_words,
                                        has_drawn_underline,
                                    )

                                if split_entries:
                                    for se in split_entries:
                                        page_words.append(se)
                                        block_word_bboxes.append((
                                            se['left'], se['top'],
                                            se['left'] + se['width'], se['top'] + se['height']
                                        ))
                                    line_text += " ".join(se['text'] for se in split_entries) + " "
                                else:
                                    page_words.append(word_data)
                                    block_word_bboxes.append(bbox)
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
                                    'spans': line_spans,
                                    'source_content_ops': _dedupe_source_content_ops(line_source_content_ops),
                                }

                                effective_line_source_ops = _dedupe_source_content_ops(line_source_content_ops)
                                if not effective_line_source_ops:
                                    line_level_match = _match_source_content_op(
                                        source_content_op_index,
                                        line_text.strip(),
                                        line_origin,
                                    )
                                    if line_level_match:
                                        effective_line_source_ops = _clone_source_content_ops_with_matched_text(
                                            [line_level_match],
                                            line_text.strip(),
                                        )
                                        for page_word_idx in range(line_word_start_idx, len(page_words)):
                                            existing_ops = page_words[page_word_idx].get('source_content_ops') or []
                                            if existing_ops:
                                                continue
                                            page_words[page_word_idx]['source_content_ops'] = _clone_source_content_ops_with_matched_text(
                                                effective_line_source_ops,
                                                page_words[page_word_idx].get('text', ''),
                                            )
                                            block_source_content_ops.extend(page_words[page_word_idx]['source_content_ops'])

                                if line_style:
                                    line_data.update(line_style)

                                line_data['source_content_ops'] = effective_line_source_ops
                                page_lines.append(line_data)

                        current_block['text'] = '\n'.join(block_text_lines)
                        current_block['text_single_line'] = ' '.join(block_text_lines)
                        current_block['text_lines'] = block_text_lines
                        if block_word_bboxes:
                            b_left = min(b[0] for b in block_word_bboxes)
                            b_top = min(b[1] for b in block_word_bboxes)
                            b_right = max(b[2] for b in block_word_bboxes)
                            b_bottom = max(b[3] for b in block_word_bboxes)
                            current_block['left'] = b_left
                            current_block['top'] = b_top
                            current_block['width'] = b_right - b_left
                            current_block['height'] = b_bottom - b_top
                        current_block['line_bboxes'] = block_line_bboxes
                        if block_line_heights:
                            current_block['avg_line_height'] = sum(block_line_heights) / len(block_line_heights)
                        current_block['spans'] = block_spans  # Store span data for rich text
                        current_block['source_content_ops'] = _dedupe_source_content_ops(block_source_content_ops)
                        current_block['has_mixed_styles'] = len(style_counts) > 1  # Flag for mixed styling
                        if block_style:
                            if block_line_heights:
                                block_style['line_height'] = sum(block_line_heights) / len(block_line_heights)
                            current_block.update(block_style)

                        if block_text_lines or block_spans or block_word_bboxes:
                            page_blocks.append(current_block)
                            block_counter += 1

            # Post-process: merge blocks that sit on the same visual line
            # This catches cases where PyMuPDF reports inline text as separate blocks
            page_blocks = _merge_adjacent_page_blocks(page_blocks, page_width, page_words, page_lines)

            # ── Word-level deduplication ──────────────────────────────
            # PyMuPDF can emit duplicate spans for OCR layers, font
            # re-encoding, or ligature splitting. Remove near-identical
            # entries to prevent stacked/doubled glyphs in the overlay.
            WORD_POS_EPS = 1.5  # PDF-point tolerance
            deduped_words = []
            seen_word_sigs = set()
            for w in page_words:
                sig = (
                    w.get('text', '').strip(),
                    round(w.get('left', 0) / WORD_POS_EPS),
                    round(w.get('top', 0) / WORD_POS_EPS),
                )
                if sig in seen_word_sigs:
                    continue
                seen_word_sigs.add(sig)
                deduped_words.append(w)
            removed = len(page_words) - len(deduped_words)
            if removed > 0:
                print(f"    ⚠ Removed {removed} duplicate word entries")
            page_words = deduped_words

            _backfill_shared_row_source_content_ops(page_lines, page_words, page_blocks, source_content_op_index)

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
    
    # Extract embedded fonts for accurate overlay rendering
    if document_id is not None:
        print()
        print("=" * 60)
        print("Extracting Embedded Fonts")
        print("=" * 60)
        try:
            embedded_fonts = extract_embedded_fonts(pdf_path, document_id)
            # Store embedded fonts info as a separate JSON file alongside extraction
            if embedded_fonts:
                fonts_dir = os.path.dirname(pdf_path)
                fonts_json_path = os.path.join(
                    os.path.dirname(os.path.abspath(__file__)),
                    '..', '..', 'storage', 'app', 'temp',
                    f'embedded_fonts_{document_id}.json'
                )
                import json as json_mod
                with open(fonts_json_path, 'w') as f:
                    json_mod.dump(embedded_fonts, f, indent=2)
                print(f"  ✓ Font metadata saved to {fonts_json_path}")
        except Exception as e:
            print(f"  ⚠ Font extraction failed (non-fatal): {e}")
    
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
