#!/usr/bin/env python3
"""
PDF Edit Application Script using PyMuPDF (fitz)
Applies text edits to PDF by redacting old text and inserting new text
"""

import sys
import os
import json
from pathlib import Path
import fitz  # PyMuPDF
import tempfile
from typing import Any, Dict, List, Optional
import re
import math

SAFE_FONT_FILES = {
    "sans_regular": "fonts/Arimo-Regular.ttf",
    "sans_bold": "fonts/Arimo-Bold.ttf",
    "serif_regular": "fonts/Tinos-Regular.ttf",
    "serif_bold": "fonts/Tinos-Bold.ttf",
    "mono_regular": "fonts/Cousine-Regular.ttf",
    "mono_bold": "fonts/Cousine-Bold.ttf",
}


def normalize_smart_quotes(text: str) -> str:
    return (
        str(text or "")
        .replace("\u2018", "'")
        .replace("\u2019", "'")
        .replace("\u201c", '"')
        .replace("\u201d", '"')
    )


def _safe_font_file_for_name(font_name: str, font_weight: Optional[Any] = None) -> Optional[str]:
    font_lower = (font_name or "").lower()
    try:
        is_bold = int(font_weight) >= 600 if font_weight is not None else False
    except Exception:
        is_bold = False
    if not is_bold:
        is_bold = any(token in font_lower for token in ("bold", "700", "black"))

    if any(token in font_lower for token in ("courier", "cousine", "mono")):
        key = "mono_bold" if is_bold else "mono_regular"
    elif any(token in font_lower for token in ("times", "tinos", "serif")):
        key = "serif_bold" if is_bold else "serif_regular"
    else:
        key = "sans_bold" if is_bold else "sans_regular"

    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), SAFE_FONT_FILES[key])
    return path if os.path.exists(path) else None


def _resolve_insert_font(
    page: fitz.Page,
    inserted_fonts: Dict[str, str],
    font_name: str,
    font_weight: Optional[Any] = None,
) -> tuple[str, Optional[fitz.Font]]:
    font_file = _safe_font_file_for_name(font_name, font_weight=font_weight)
    if not font_file:
        return map_font_name(font_name), None

    font_key = os.path.basename(font_file)
    insert_font_name = inserted_fonts.get(font_key)
    if not insert_font_name:
        insert_font_name = f"safe_{len(inserted_fonts) + 1}_{Path(font_file).stem}"
        page.insert_font(fontname=insert_font_name, fontfile=font_file)
        inserted_fonts[font_key] = insert_font_name

    try:
        return insert_font_name, fitz.Font(fontfile=font_file)
    except Exception:
        return insert_font_name, None


def _rect_center(rect: fitz.Rect):
    return ((rect.x0 + rect.x1) / 2.0, (rect.y0 + rect.y1) / 2.0)

def _inflate_rect(rect: fitz.Rect, delta: float = 0.5) -> fitz.Rect:
    return fitz.Rect(rect.x0 - delta, rect.y0 - delta, rect.x1 + delta, rect.y1 + delta)


def _point_hits_any_rect(page_height: float, x_pdf: float, y_pdf: float, rects) -> bool:
    y_page = page_height - y_pdf
    for rect in rects:
        if (rect.x0 - 4.0) <= x_pdf <= (rect.x1 + 4.0) and (rect.y0 - 4.0) <= y_page <= (rect.y1 + 4.0):
            return True
    return False


def _strip_text_show_ops_in_stream(stream: bytes, page_height: float, cleanup_rects) -> tuple[bytes, int]:
    tm_re = re.compile(
        rb'([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+Tm\b'
    )
    td_re = re.compile(rb'([-\d.]+)\s+([-\d.]+)\s+T[dD]\b')

    cur_x = None
    cur_y = None
    in_text_object = False
    removed = 0
    out = bytearray()

    for line in stream.splitlines(keepends=True):
        stripped = line.strip()
        if stripped == b'BT':
            in_text_object = True
            cur_x = None
            cur_y = None
            out.extend(line)
            continue
        if stripped == b'ET':
            in_text_object = False
            cur_x = None
            cur_y = None
            out.extend(line)
            continue

        if in_text_object:
            tm_match = tm_re.search(stripped)
            if tm_match:
                cur_x = float(tm_match.group(5))
                cur_y = float(tm_match.group(6))
                out.extend(line)
                continue

            td_match = td_re.search(stripped)
            if td_match and cur_x is not None and cur_y is not None:
                cur_x += float(td_match.group(1))
                cur_y += float(td_match.group(2))
                out.extend(line)
                continue

            if (
                cur_x is not None
                and cur_y is not None
                and cleanup_rects
                and (stripped.endswith(b'TJ') or stripped.endswith(b'Tj'))
                and _point_hits_any_rect(page_height, cur_x, cur_y, cleanup_rects)
            ):
                removed += 1
                continue

        out.extend(line)

    return bytes(out), removed


def _strip_text_show_ops_in_rects(doc: fitz.Document, page: fitz.Page, cleanup_rects) -> int:
    if not cleanup_rects:
        return 0

    removed_total = 0
    xrefs = list(page.get_contents())
    xrefs.extend(
        xobj[0]
        for xobj in page.get_xobjects()
        if xobj
    )

    for xref in xrefs:
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream:
            continue
        updated_stream, removed_here = _strip_text_show_ops_in_stream(stream, page.rect.height, cleanup_rects)
        if removed_here <= 0 or updated_stream == stream:
            continue
        doc.update_stream(xref, updated_stream)
        removed_total += removed_here

    if removed_total > 0:
        try:
            page.clean_contents()
        except Exception:
            pass

    return removed_total


def _decode_pdf_string_token(token: bytes) -> str:
    if len(token) < 2 or token[:1] != b'(' or token[-1:] != b')':
        return ''
    body = token[1:-1]
    body = body.replace(b'\\(', b'(').replace(b'\\)', b')').replace(b'\\\\', b'\\')
    return body.decode('latin-1', errors='ignore')


def _encode_pdf_string_token(text: str) -> bytes:
    body = (
        (text or "")
        .replace("\\", "\\\\")
        .replace("(", "\\(")
        .replace(")", "\\)")
    )
    return f"({body})".encode("latin-1", errors="ignore")


def _strip_text_from_tj_array_line(
    line: bytes,
    text_to_remove: str,
    replacement_text: str = '',
) -> tuple[bytes, bool]:
    if not text_to_remove:
        return line, False

    stripped = line.strip()
    if b'[' not in stripped or b']' not in stripped or not stripped.endswith(b'TJ'):
        return line, False

    open_idx = line.find(b'[')
    close_idx = line.rfind(b']')
    if open_idx < 0 or close_idx <= open_idx:
        return line, False

    token_re = re.compile(rb'\((?:\\.|[^\\)])*\)|-?\d+(?:\.\d+)?')
    array_bytes = line[open_idx + 1:close_idx]
    tokens = token_re.findall(array_bytes)
    if not tokens:
        return line, False

    entries = []
    flat_chars = []
    for tok in tokens:
        if tok.startswith(b'('):
            decoded = _decode_pdf_string_token(tok)
            entry_idx = len(entries)
            entries.append({'kind': 'str', 'token': tok, 'text': decoded})
            for char_idx, ch in enumerate(decoded):
                flat_chars.append((entry_idx, char_idx, ch))
        else:
            entries.append({'kind': 'num', 'token': tok})

    full_text = ''.join(ch for _, _, ch in flat_chars)
    match_idx = full_text.find(text_to_remove)
    if match_idx < 0:
        return line, False

    remove_positions = set(range(match_idx, match_idx + len(text_to_remove)))
    replacement_chars = list(str(replacement_text or ''))
    flat_pos = 0
    changed = False
    for entry in entries:
        if entry['kind'] != 'str':
            continue
        kept_chars = []
        for ch in entry['text']:
            if flat_pos not in remove_positions:
                kept_chars.append(ch)
            else:
                if replacement_chars:
                    kept_chars.append(replacement_chars.pop(0))
                changed = True
            flat_pos += 1
        entry['text'] = ''.join(kept_chars)

    if not changed:
        return line, False

    rebuilt_tokens = []
    for entry in entries:
        if entry['kind'] == 'str':
            if entry['text']:
                rebuilt_tokens.append(_encode_pdf_string_token(entry['text']))
        else:
            rebuilt_tokens.append(entry['token'])

    rebuilt_array = b' '.join(rebuilt_tokens)
    rebuilt_line = line[:open_idx + 1] + rebuilt_array + line[close_idx:]
    return rebuilt_line, True


def _strip_text_from_tj_string_line(
    line: bytes,
    text_to_remove: str,
    replacement_text: str = '',
) -> tuple[bytes, bool]:
    if not text_to_remove:
        return line, False

    stripped = line.strip()
    if not stripped.endswith(b'Tj'):
        return line, False

    token_match = re.search(rb'\((?:\\.|[^\\)])*\)\s*Tj\b', stripped, re.DOTALL)
    if not token_match:
        return line, False

    token = token_match.group(0)[:-2].strip()
    decoded = _decode_pdf_string_token(token)
    match_idx = decoded.find(text_to_remove)
    if match_idx < 0:
        return line, False

    rebuilt_text = decoded[:match_idx] + str(replacement_text or '') + decoded[match_idx + len(text_to_remove):]
    rebuilt_token = _encode_pdf_string_token(rebuilt_text)

    token_start = line.find(token)
    token_end = token_start + len(token)
    if token_start < 0:
        return line, False
    rebuilt_line = line[:token_start] + rebuilt_token + line[token_end:]
    return rebuilt_line, True


def _normalize_source_content_ops(edit: Dict[str, Any]) -> List[Dict[str, Any]]:
    normalized = []
    seen = set()
    for op in edit.get("source_content_ops") or []:
        if not isinstance(op, dict):
            continue
        try:
            xref = int(op.get("xref"))
            start = int(op.get("start"))
            end = int(op.get("end"))
        except Exception:
            continue
        if end <= start:
            continue
        key = (
            xref,
            start,
            end,
            normalize_smart_quotes(op.get("matched_text", "")).strip(),
        )
        if key in seen:
            continue
        seen.add(key)
        normalized.append({
            "xref": xref,
            "start": start,
            "end": end,
            "operator": str(op.get("operator") or ""),
            "operator_text": normalize_smart_quotes(op.get("operator_text", "")).strip(),
            "matched_text": normalize_smart_quotes(op.get("matched_text", "")).strip(),
        })
    return normalized


def _strip_using_source_content_ops(
    doc: fitz.Document,
    page: fitz.Page,
    edit: Dict[str, Any],
) -> int:
    source_ops = _normalize_source_content_ops(edit)
    if not source_ops:
        return 0

    original_norm = normalize_smart_quotes(str(edit.get("original_text") or "")).strip()
    if not original_norm:
        return 0

    target_groups: Dict[tuple, Dict[str, Any]] = {}
    for op in source_ops:
        matched_text = op.get("matched_text") or original_norm
        matched_text = normalize_smart_quotes(matched_text).strip()
        if not matched_text:
            continue

        operator_text = op.get("operator_text") or ""
        if operator_text:
            if matched_text not in operator_text and original_norm not in operator_text:
                continue

        key = (op["xref"], op["start"], op["end"], op.get("operator") or "")
        group = target_groups.setdefault(key, {
            "xref": op["xref"],
            "start": op["start"],
            "end": op["end"],
            "operator": op.get("operator") or "",
            "texts": [],
        })
        if matched_text not in group["texts"]:
            group["texts"].append(matched_text)

    if not target_groups:
        return 0

    ops_by_xref: Dict[int, List[Dict[str, Any]]] = {}
    for group in target_groups.values():
        ops_by_xref.setdefault(group["xref"], []).append(group)

    removed_total = 0
    for xref, groups in ops_by_xref.items():
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream:
            continue

        new_stream = bytearray(stream)
        changed_any = False
        for group in sorted(groups, key=lambda item: item["start"], reverse=True):
            start = group["start"]
            end = group["end"]
            if start < 0 or end > len(new_stream):
                continue
            raw_op = bytes(new_stream[start:end])
            updated_op = raw_op
            changed_op = False
            for remove_text in sorted(group["texts"], key=len, reverse=True):
                replacement = " " * len(remove_text)
                if group["operator"] == "TJ":
                    updated_op, removed_here = _strip_text_from_tj_array_line(
                        updated_op,
                        remove_text,
                        replacement_text=replacement,
                    )
                elif group["operator"] == "Tj":
                    updated_op, removed_here = _strip_text_from_tj_string_line(
                        updated_op,
                        remove_text,
                        replacement_text=replacement,
                    )
                else:
                    removed_here = False
                if removed_here:
                    changed_op = True

            if not changed_op or updated_op == raw_op:
                continue

            new_stream[start:end] = updated_op
            changed_any = True
            removed_total += 1

        if changed_any:
            doc.update_stream(xref, bytes(new_stream))

    if removed_total > 0:
        try:
            page.clean_contents()
        except Exception:
            pass

    return removed_total


def _collect_trace_text_candidates(page: fitz.Page, cleanup_rect: fitz.Rect, original_text: str) -> List[str]:
    original_norm = normalize_smart_quotes(str(original_text or "")).strip()
    if not original_norm:
        return []

    candidates = []
    seen = set()
    try:
        traces = page.get_texttrace()
    except Exception:
        return []

    for trace in traces:
        bbox = trace.get("bbox")
        if not bbox:
            continue
        trace_rect = fitz.Rect(bbox)
        if not cleanup_rect.intersects(_inflate_rect(trace_rect, 1.0)):
            continue
        chars = trace.get("chars") or []
        trace_text = ''.join(chr(ch[0]) for ch in chars).strip()
        trace_norm = normalize_smart_quotes(trace_text)
        if not trace_norm or original_norm not in trace_norm:
            continue
        if trace_norm in seen:
            continue
        seen.add(trace_norm)
        candidates.append(trace_norm)

    return candidates


def _collect_raw_text_span_bboxes(page: fitz.Page, original_text: str) -> List[fitz.Rect]:
    original_norm = normalize_smart_quotes(str(original_text or "")).strip()
    if not original_norm:
        return []

    matches = []
    try:
        rawdict = page.get_text("rawdict")
    except Exception:
        return []

    for block in rawdict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = ''.join(ch.get("c", "") for ch in span.get("chars", [])).strip()
                if normalize_smart_quotes(text) != original_norm:
                    continue
                bbox = span.get("bbox")
                if bbox:
                    matches.append(fitz.Rect(bbox))

    return sorted(matches, key=lambda rect: (rect.y0, rect.x0))


def _strip_exact_text_from_trace_tj_runs(
    doc: fitz.Document,
    page: fitz.Page,
    cleanup_rect: fitz.Rect,
    original_text: str,
) -> int:
    original_norm = normalize_smart_quotes(str(original_text or "")).strip()
    if not original_norm:
        return 0

    candidate_texts = _collect_trace_text_candidates(page, cleanup_rect, original_norm)
    if not candidate_texts:
        return 0

    target_span_rects = _collect_raw_text_span_bboxes(page, original_norm)
    target_index = 0
    if target_span_rects:
        cleanup_center = _rect_center(cleanup_rect)
        target_index = min(
            range(len(target_span_rects)),
            key=lambda idx: (
                abs(_rect_center(target_span_rects[idx])[1] - cleanup_center[1]),
                abs(_rect_center(target_span_rects[idx])[0] - cleanup_center[0]),
            )
        )

    candidate_ops = []
    for xref in page.get_contents():
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream:
            continue

        for match in re.finditer(rb'\[(?:\\.|[^\]])*\]\s*TJ', stream):
            raw_op = match.group(0)
            open_idx = raw_op.find(b'[')
            close_idx = raw_op.rfind(b']')
            if open_idx < 0 or close_idx <= open_idx:
                continue
            token_re = re.compile(rb'\((?:\\.|[^\\)])*\)|-?\d+(?:\.\d+)?')
            array_bytes = raw_op[open_idx + 1:close_idx]
            tokens = token_re.findall(array_bytes)
            full_text = ''.join(
                _decode_pdf_string_token(tok)
                for tok in tokens
                if tok.startswith(b'(')
            )
            full_norm = normalize_smart_quotes(full_text).strip()
            if not full_norm or original_norm not in full_norm:
                continue
            if not any(candidate in full_norm or full_norm in candidate for candidate in candidate_texts):
                continue
            candidate_ops.append({
                "xref": xref,
                "start": match.start(),
                "end": match.end(),
                "op": raw_op,
            })

    if not candidate_ops:
        return 0

    candidate_ops.sort(key=lambda item: (item["xref"], item["start"]))
    target_op = candidate_ops[min(target_index, len(candidate_ops) - 1)]

    removed_total = 0
    for xref in page.get_contents():
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream or xref != target_op["xref"]:
            continue

        changed = False
        updated_op, removed_here = _strip_text_from_tj_array_line(
            target_op["op"],
            original_norm,
            replacement_text=(' ' * len(original_norm)),
        )
        if removed_here:
            new_stream = bytearray(stream)
            new_stream[target_op["start"]:target_op["end"]] = updated_op
            removed_total += 1
            changed = True

        if changed:
            doc.update_stream(xref, bytes(new_stream))

    if removed_total > 0:
        try:
            page.clean_contents()
        except Exception:
            pass

    return removed_total


def _strip_tj_text_in_rects(doc: fitz.Document, page: fitz.Page, cleanup_rects, texts_to_remove) -> int:
    normalized_texts = [t for t in {normalize_smart_quotes(str(t or "")).strip() for t in texts_to_remove} if t]
    if not cleanup_rects or not normalized_texts:
        return 0

    tm_re = re.compile(
        rb'([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+Tm\b'
    )
    td_re = re.compile(rb'([-\d.]+)\s+([-\d.]+)\s+T[dD]\b')
    tstar_re = re.compile(rb'T\*\b')

    removed_total = 0

    for xref in page.get_contents():
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream:
            continue

        changed = False
        out_lines = []
        cur_x = None
        cur_y = None
        in_text_object = False

        for line in stream.splitlines(keepends=True):
            stripped = line.strip()
            if stripped == b'BT':
                in_text_object = True
                cur_x = None
                cur_y = None
                out_lines.append(line)
                continue
            if stripped == b'ET':
                in_text_object = False
                cur_x = None
                cur_y = None
                out_lines.append(line)
                continue

            if in_text_object:
                tm_match = tm_re.search(stripped)
                if tm_match:
                    cur_x = float(tm_match.group(5))
                    cur_y = float(tm_match.group(6))
                    out_lines.append(line)
                    continue

                td_match = td_re.search(stripped)
                if td_match and cur_x is not None and cur_y is not None:
                    cur_x += float(td_match.group(1))
                    cur_y += float(td_match.group(2))
                    out_lines.append(line)
                    continue

                if tstar_re.fullmatch(stripped) and cur_y is not None:
                    out_lines.append(line)
                    continue

                if cur_x is not None and cur_y is not None and stripped.endswith(b'TJ'):
                    current_line = line
                    line_changed = False
                    for text_to_remove in normalized_texts:
                        current_line, removed_here = _strip_text_from_tj_array_line(current_line, text_to_remove)
                        if removed_here:
                            removed_total += 1
                            line_changed = True
                    if line_changed:
                        changed = True
                    out_lines.append(current_line)
                    continue

            out_lines.append(line)

        if changed:
            doc.update_stream(xref, b''.join(out_lines))

    if removed_total > 0:
        try:
            page.clean_contents()
        except Exception:
            pass

    return removed_total


def _strip_tj_tail_for_prefixes(doc: fitz.Document, page: fitz.Page, prefixes) -> int:
    normalized_prefixes = [p for p in prefixes if p]
    if not normalized_prefixes:
        return 0

    token_re = re.compile(rb'\((?:\\.|[^\\)])*\)|-?\d+(?:\.\d+)?')
    removed_total = 0

    for xref in page.get_contents():
        try:
            stream = doc.xref_stream(xref)
        except Exception:
            continue
        if not stream:
            continue

        changed = False
        out_lines = []
        for line in stream.splitlines(keepends=True):
            stripped = line.strip()
            if b'[' not in stripped or b']' not in stripped or not stripped.endswith(b'TJ'):
                out_lines.append(line)
                continue

            open_idx = line.find(b'[')
            close_idx = line.rfind(b']')
            if open_idx < 0 or close_idx <= open_idx:
                out_lines.append(line)
                continue

            array_bytes = line[open_idx + 1:close_idx]
            tokens = token_re.findall(array_bytes)
            if not tokens:
                out_lines.append(line)
                continue

            decoded_tokens = []
            full_text_parts = []
            for tok in tokens:
                if tok.startswith(b'('):
                    decoded = _decode_pdf_string_token(tok)
                    decoded_tokens.append(('str', tok, decoded))
                    full_text_parts.append(decoded)
                else:
                    try:
                        decoded_tokens.append(('num', tok, float(tok)))
                    except Exception:
                        decoded_tokens.append(('num', tok, 0.0))

            full_text = ''.join(full_text_parts)
            if not any(prefix in full_text for prefix in normalized_prefixes):
                out_lines.append(line)
                continue

            cut_idx = None
            for idx, entry in enumerate(decoded_tokens):
                if entry[0] != 'str':
                    continue
                tail_text = ''.join(
                    part[2] for part in decoded_tokens[idx:] if part[0] == 'str'
                )
                if not any(prefix in tail_text for prefix in normalized_prefixes):
                    continue
                cut_idx = idx
                while cut_idx > 0:
                    prev = decoded_tokens[cut_idx - 1]
                    if prev[0] != 'num' or prev[2] > -1000:
                        break
                    cut_idx -= 1
                break

            if cut_idx is None:
                out_lines.append(line)
                continue

            kept_tokens = decoded_tokens[:cut_idx]
            if not any(entry[0] == 'str' and entry[2] for entry in kept_tokens):
                out_lines.append(line)
                continue

            rebuilt_array = b' '.join(entry[1] for entry in kept_tokens)
            rebuilt_line = line[:open_idx + 1] + rebuilt_array + line[close_idx:]
            out_lines.append(rebuilt_line)
            changed = True
            removed_total += 1

        if changed:
            doc.update_stream(xref, b''.join(out_lines))

    if removed_total > 0:
        try:
            page.clean_contents()
        except Exception:
            pass

    return removed_total


def _measure_text_width(font_obj: fitz.Font, text: str, font_size: float) -> float:
    try:
        return float(font_obj.text_length(text or "", fontsize=font_size))
    except Exception:
        return float(len(text or "")) * float(font_size) * 0.6


def _split_token_to_width(token: str, font_obj: fitz.Font, font_size: float, max_width: float) -> List[str]:
    if not token:
        return [""]
    if max_width <= 0:
        return [token]
    out = []
    chunk = ""
    for ch in token:
        candidate = f"{chunk}{ch}"
        if chunk and _measure_text_width(font_obj, candidate, font_size) > max_width:
            out.append(chunk)
            chunk = ch
        else:
            chunk = candidate
    if chunk:
        out.append(chunk)
    return out or [token]


def _wrap_text_for_width(text: str, font_obj: fitz.Font, font_size: float, max_width: float) -> List[str]:
    source_lines = (text or "").splitlines() or [""]
    wrapped = []
    for src in source_lines:
        words = src.split(" ")
        if not words:
            wrapped.append("")
            continue
        current = ""
        for word in words:
            token = word if current == "" else f" {word}"
            candidate = f"{current}{token}"
            if current and _measure_text_width(font_obj, candidate, font_size) > max_width:
                wrapped.append(current)
                if _measure_text_width(font_obj, word, font_size) > max_width:
                    parts = _split_token_to_width(word, font_obj, font_size, max_width)
                    wrapped.extend(parts[:-1])
                    current = parts[-1]
                else:
                    current = word
            else:
                if _measure_text_width(font_obj, token.strip(), font_size) > max_width and not current:
                    parts = _split_token_to_width(token.strip(), font_obj, font_size, max_width)
                    wrapped.extend(parts[:-1])
                    current = parts[-1]
                else:
                    current = candidate
        wrapped.append(current)
    return wrapped or [""]


def _pick_font_size_to_fit_rect(
    text: str,
    fontname: str,
    desired_font_size: float,
    rect: fitz.Rect,
    line_height_factor: float = 1.2,
    min_font_size: float = 4.0,
    font_obj: Optional[fitz.Font] = None,
) -> float:
    if font_obj is None:
        try:
            font_obj = fitz.Font(fontname=fontname)
        except Exception:
            font_obj = fitz.Font(fontname="helv")

    start = max(float(desired_font_size or 12.0), min_font_size)
    max_width = max(float(rect.width), 0.0)
    max_height = max(float(rect.height), 0.0)

    size = start
    while size >= min_font_size:
        lines = _wrap_text_for_width(text, font_obj, size, max_width)
        required_height = max(len(lines), 1) * max(size * line_height_factor, 1.0)
        widest = max((_measure_text_width(font_obj, ln, size) for ln in lines), default=0.0)
        if required_height <= max_height + 0.01 and widest <= max_width + 0.01:
            return size
        size -= 0.25

    return min_font_size


def _wrap_text_for_target_widths(
    text: str,
    font_obj: fitz.Font,
    font_size: float,
    target_widths: List[float],
    fallback_width: float,
) -> List[str]:
    paragraphs = str(text or "").replace("\r\n", "\n").replace("\r", "\n").split("\n")
    widths = [max(float(width or 0.0), 1.0) for width in (target_widths or [])]
    fallback_width = max(float(fallback_width or 0.0), 1.0)
    line_index = 0
    out: List[str] = []

    def current_width(idx: int) -> float:
        return widths[idx] if idx < len(widths) else fallback_width

    for paragraph in paragraphs:
        words = paragraph.split()
        if not words:
            out.append("")
            line_index += 1
            continue

        current = ""
        for word in words:
            width_limit = current_width(line_index)
            candidate = word if not current else f"{current} {word}"
            if current and _measure_text_width(font_obj, candidate, font_size) > width_limit + 0.01:
                out.append(current)
                line_index += 1
                width_limit = current_width(line_index)
                if _measure_text_width(font_obj, word, font_size) > width_limit + 0.01:
                    parts = _split_token_to_width(word, font_obj, font_size, width_limit)
                    out.extend(parts[:-1])
                    line_index += max(len(parts) - 1, 0)
                    current = parts[-1]
                else:
                    current = word
            else:
                if not current and _measure_text_width(font_obj, word, font_size) > width_limit + 0.01:
                    parts = _split_token_to_width(word, font_obj, font_size, width_limit)
                    out.extend(parts[:-1])
                    line_index += max(len(parts) - 1, 0)
                    current = parts[-1]
                else:
                    current = candidate
        out.append(current)
        line_index += 1

    return out or [""]


def _insert_text_by_line_metrics(
    page: fitz.Page,
    edit: Dict[str, Any],
    fontname: str,
    font_obj: Optional[fitz.Font],
    color: tuple,
) -> bool:
    line_metrics = edit.get("line_metrics")
    if not isinstance(line_metrics, list) or len(line_metrics) < 2:
        return False

    new_text = "" if edit.get("new_text") is None else str(edit.get("new_text"))
    if not new_text.strip():
        return False

    if font_obj is None:
        try:
            font_obj = fitz.Font(fontname=fontname)
        except Exception:
            return False

    original_bbox = edit.get("original_bbox")
    target_bbox = edit.get("bbox")
    delta_x = 0.0
    delta_y = 0.0
    if (
        isinstance(original_bbox, (list, tuple))
        and len(original_bbox) >= 4
        and isinstance(target_bbox, (list, tuple))
        and len(target_bbox) >= 4
    ):
        try:
            delta_x = float(target_bbox[0]) - float(original_bbox[0])
            delta_y = float(target_bbox[1]) - float(original_bbox[1])
        except Exception:
            delta_x = 0.0
            delta_y = 0.0

    rect = fitz.Rect(target_bbox or original_bbox)
    base_font_size = 0.0
    target_widths: List[float] = []
    tops: List[float] = []
    origins_y: List[float] = []

    for metric in line_metrics:
        if not isinstance(metric, dict):
            continue
        try:
            target_widths.append(max(float(metric.get("width") or rect.width), 1.0))
            tops.append(float(metric.get("top") or rect.y0) + delta_y)
            origins_y.append(float(metric.get("origin_y") or ((metric.get("top") or rect.y0) + (metric.get("height") or 0))) + delta_y)
            metric_fs = float(metric.get("font_size") or 0.0)
            if metric_fs > 0:
                base_font_size = metric_fs
        except Exception:
            continue

    if not target_widths:
        return False
    target_widths[-1] = max(target_widths[-1], float(rect.width))

    if base_font_size <= 0:
        try:
            base_font_size = float(edit.get("font_size") or 12.0)
        except Exception:
            base_font_size = 12.0

    line_height = edit.get("line_height")
    try:
        line_height = float(line_height) if line_height is not None else None
    except Exception:
        line_height = None
    if not line_height or line_height <= 0:
        if len(origins_y) >= 2:
            line_height = max(origins_y[1] - origins_y[0], base_font_size * 1.2)
        else:
            line_height = max(base_font_size * 1.2, 1.0)

    lines = _wrap_text_for_target_widths(
        new_text,
        font_obj,
        base_font_size,
        target_widths,
        rect.width,
    )

    if len(lines) > len(line_metrics):
        if origins_y:
            projected_last_origin = origins_y[-1] + ((len(lines) - len(line_metrics)) * line_height)
        else:
            projected_last_origin = rect.y0 + base_font_size + ((len(lines) - 1) * line_height)
        if projected_last_origin > rect.y1 + max(1.0, base_font_size * 0.25):
            print("    ℹ Line-metric replay exceeds bbox height; falling back to textbox reflow")
            return False

    for idx, line in enumerate(lines):
        if idx < len(line_metrics) and isinstance(line_metrics[idx], dict):
            metric = line_metrics[idx]
            try:
                origin_x = float(metric.get("origin_x") or metric.get("left") or rect.x0) + delta_x
                origin_y = float(metric.get("origin_y") or ((metric.get("top") or rect.y0) + (metric.get("height") or base_font_size))) + delta_y
                metric_font_size = float(metric.get("font_size") or base_font_size)
            except Exception:
                origin_x = rect.x0
                origin_y = rect.y0 + base_font_size
                metric_font_size = base_font_size
        else:
            origin_x = rect.x0
            origin_y = origins_y[0] + (idx * line_height) if origins_y else (rect.y0 + base_font_size + (idx * line_height))
            metric_font_size = base_font_size

        if origin_y > rect.y1 + 0.5:
            print("    ℹ Line-metric replay would exceed target bbox; falling back to textbox reflow")
            return False

        page.insert_text(
            (origin_x, origin_y),
            line,
            fontsize=metric_font_size,
            fontname=fontname,
            color=color,
            overlay=True,
        )

    print(
        f"    ✓ Inserted '{new_text[:30]}...' with line-metric replay "
        f"({len(lines)} lines at fs={base_font_size:.2f})"
    )
    return True


def _insert_text_strict_in_bbox(
    page: fitz.Page,
    rect: fitz.Rect,
    text: str,
    fontname: str,
    color: tuple,
    desired_font_size: float,
    line_height_factor: float = 1.2,
    font_obj: Optional[fitz.Font] = None,
):
    # First try textbox with progressively smaller font sizes.
    fs = max(float(desired_font_size), 1.0)
    while fs >= 1.0:
        rc = page.insert_textbox(
            rect,
            text,
            fontsize=fs,
            fontname=fontname,
            color=color,
            align=fitz.TEXT_ALIGN_LEFT,
            overlay=True,
        )
        if rc >= 0:
            return True, fs, "textbox"
        fs -= 0.25

    # Fallback: manual wrapped draw, constrained to bbox height.
    if font_obj is None:
        try:
            font_obj = fitz.Font(fontname=fontname)
        except Exception:
            font_obj = fitz.Font(fontname="helv")
            fontname = "helv"

    fs = max(min(float(desired_font_size), float(rect.height)), 1.0)
    while fs >= 1.0:
        lh = max(fs * max(line_height_factor, 1.0), 1.0)
        max_lines = max(1, int(math.floor(float(rect.height) / lh)))
        lines = _wrap_text_for_width(text, font_obj, fs, float(rect.width))
        clipped = len(lines) > max_lines
        lines = lines[:max_lines]
        if not lines:
            fs -= 0.25
            continue

        # If clipped, trim final line with ellipsis to remain inside width.
        if clipped:
            last = lines[-1].rstrip()
            ell = "..."
            while last and _measure_text_width(font_obj, f"{last}{ell}", fs) > float(rect.width):
                last = last[:-1]
            lines[-1] = f"{last}{ell}" if last else ell

        y = float(rect.y0) + fs
        for line in lines:
            if y > float(rect.y1) + 0.001:
                break
            page.insert_text(
                (float(rect.x0), y),
                line,
                fontsize=fs,
                fontname=fontname,
                color=color,
                overlay=True,
            )
            y += lh
        return True, fs, "manual-wrap"

    return False, 0.0, "failed"


def _style_color_to_rgb(style: Dict[str, Any], fallback: tuple) -> tuple:
    hex_color = style.get("hex_color")
    if isinstance(hex_color, str) and hex_color:
        hex_color = hex_color.lstrip("#")
        if len(hex_color) == 6:
            try:
                return tuple(int(hex_color[i:i + 2], 16) / 255.0 for i in (0, 2, 4))
            except Exception:
                pass

    raw_color = style.get("color")
    if isinstance(raw_color, int):
        try:
            return (
                ((raw_color >> 16) & 0xFF) / 255.0,
                ((raw_color >> 8) & 0xFF) / 255.0,
                (raw_color & 0xFF) / 255.0,
            )
        except Exception:
            pass

    return fallback


def _edit_cleanup_rect(edit: Dict[str, Any]) -> Optional[fitz.Rect]:
    rect = None
    for key in ("original_bbox", "bbox", "cleanup_group_bbox"):
        raw = edit.get(key)
        if not isinstance(raw, (list, tuple)) or len(raw) < 4:
            continue
        try:
            candidate = fitz.Rect(float(raw[0]), float(raw[1]), float(raw[2]), float(raw[3]))
        except Exception:
            continue
        rect = candidate if rect is None else (rect | candidate)
    return rect


def _resolve_insert_font_name(
    font_name: str,
) -> str:
    return map_font_name(font_name)


def _normalize_style_run_text(value: Any) -> str:
    return re.sub(r"\s+", " ", "" if value is None else str(value)).strip()


def _insert_styled_runs(
    page: fitz.Page,
    edit: Dict[str, Any],
    fallback_rgb: tuple,
) -> bool:
    style_runs = edit.get("word_styles")
    if not isinstance(style_runs, list) or not style_runs:
        return False

    expected_text = _normalize_style_run_text(edit.get("new_text"))
    style_text = _normalize_style_run_text(" ".join(
        "" if not isinstance(style, dict) else str(style.get("text") or "")
        for style in style_runs
    ))
    if expected_text and style_text != expected_text:
        print(
            "    ℹ Skipping styled-run insertion because run text no longer matches "
            "the edited block text; falling back to bbox reflow"
        )
        return False

    original_bbox = edit.get("original_bbox")
    target_bbox = edit.get("bbox")
    ws_delta_x = 0.0
    ws_delta_y = 0.0
    if (
        isinstance(original_bbox, (list, tuple))
        and len(original_bbox) >= 4
        and isinstance(target_bbox, (list, tuple))
        and len(target_bbox) >= 4
    ):
        try:
            ws_delta_x = float(target_bbox[0]) - float(original_bbox[0])
            ws_delta_y = float(target_bbox[1]) - float(original_bbox[1])
        except Exception:
            ws_delta_x = 0.0
            ws_delta_y = 0.0

    if abs(ws_delta_x) > 0.5 or abs(ws_delta_y) > 0.5:
        print(f"    ℹ styled-run position offset: dx={ws_delta_x:.1f}, dy={ws_delta_y:.1f}")

    inserted_any = False
    for style in style_runs:
        if not isinstance(style, dict):
            continue

        run_text = "" if style.get("text") is None else str(style.get("text"))
        if not run_text.strip():
            continue

        left = style.get("left")
        top = style.get("top")
        width = style.get("width")
        height = style.get("height")
        if left is None or top is None or width is None or height is None:
            return False

        try:
            rect = fitz.Rect(
                float(left) + ws_delta_x,
                float(top) + ws_delta_y,
                float(left) + float(width) + ws_delta_x,
                float(top) + float(height) + ws_delta_y,
            )
        except Exception:
            return False

        if rect.width <= 0 or rect.height <= 0:
            return False

        font_size = style.get("font_size", edit.get("font_size", 12))
        try:
            font_size = float(font_size)
        except Exception:
            font_size = 12.0
        if font_size <= 0:
            font_size = 12.0

        style_font = _resolve_insert_font_name(style.get("font") or edit.get("font") or "helv")
        line_height_factor = max(float(rect.height) / font_size, 1.0) if font_size > 0 else 1.2
        rgb = _style_color_to_rgb(style, fallback_rgb)

        if "\n" not in run_text:
            origin_x = style.get("origin_x", rect.x0)
            origin_y = style.get("origin_y", rect.y1)
            try:
                origin_x = float(origin_x) + ws_delta_x
                origin_y = float(origin_y) + ws_delta_y
                page.insert_text(
                    (origin_x, origin_y),
                    run_text,
                    fontsize=font_size,
                    fontname=style_font,
                    color=rgb,
                    overlay=True,
                )
                inserted_any = True
                print(
                    f"    ✓ Inserted styled run '{run_text[:30]}...' at "
                    f"({origin_x:.1f}, {origin_y:.1f}) fs={font_size:.2f} mode=origin"
                )
                continue
            except Exception:
                pass

        ok, used_fs, mode = _insert_text_strict_in_bbox(
            page=page,
            rect=_inflate_rect(rect, 1.5),
            text=run_text,
            fontname=style_font,
            color=rgb,
            desired_font_size=font_size,
            line_height_factor=line_height_factor,
        )
        if not ok:
            return False

        inserted_any = True
        print(
            f"    ✓ Inserted styled run '{run_text[:30]}...' at "
            f"({rect.x0:.1f}, {rect.y0:.1f}) fs={used_fs:.2f} mode={mode}"
        )

    return inserted_any


def apply_edits_to_pdf(pdf_path, edits_data):
    """
    Apply text edits to PDF using the 'Search-and-Destroy' Protocol:
    
    Phase 0: Clean all pages to normalize coordinate systems (CRITICAL)
    Phase A: Search for original_text in PDF data stream and redact ALL instances
    Phase B: Overlay new_text at NEW bbox locations (no redaction)
    
    This prevents ghosting by finding text in the actual PDF data layer,
    not relying on potentially inaccurate frontend coordinates.
    
    Args:
        pdf_path: Path to the PDF file
        edits_data: List of edit objects with:
            - page_number: Page number (1-indexed)
            - original_text: Original text to find and destroy (REQUIRED)
            - original_bbox: Fallback location if search fails
            - bbox: New text location [x0, y0, x1, y1]
            - new_text: New text to insert
            - font: Font name
            - font_size: Font size
            - color: Text color (hex)
    """
    print(f"📄 Opening PDF: {pdf_path}")
    
    try:
        doc = fitz.open(pdf_path)
        print(f"✓ PDF opened: {doc.page_count} pages")
        inserted_fonts: Dict[str, str] = {}
        
        # Group edits by page (normalize page numbers to 1-indexed ints)
        # and dedupe repeated payload entries to prevent duplicate insertion.
        edits_by_page = {}
        seen_edit_keys = set()
        for edit in edits_data:
            raw_page_num = edit.get('page_number') if edit.get('page_number') is not None else edit.get('page_num')
            if raw_page_num is None:
                print("    ⚠ Skipping edit without page_number/page_num")
                continue
            try:
                page_num = int(raw_page_num)
            except Exception:
                print(f"    ⚠ Skipping edit with invalid page number: {raw_page_num}")
                continue
            # Defensive normalization for accidental 0-based page indexes.
            if page_num == 0:
                print("    ⚠ Detected page 0 in edits, normalizing to page 1")
                page_num = 1
            if page_num < 1 or page_num > doc.page_count:
                print(f"    ⚠ Skipping edit with out-of-range page number: {page_num}")
                continue
            bbox = edit.get('bbox') or edit.get('original_bbox') or []
            if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
                bbox_key = tuple(round(float(v), 2) for v in bbox[:4])
            else:
                bbox_key = ()
            dedupe_key = (
                page_num,
                bbox_key,
                ('' if edit.get('new_text') is None else str(edit.get('new_text')).strip()),
            )
            if dedupe_key in seen_edit_keys:
                print(f"    ⊘ Skipping duplicate edit payload on page {page_num}")
                continue
            seen_edit_keys.add(dedupe_key)
            if page_num not in edits_by_page:
                edits_by_page[page_num] = []
            edits_by_page[page_num].append(edit)
        
        print(f"📝 Applying {len(edits_data)} edits across {len(edits_by_page)} pages...")
        
        # PHASE 0: Prepare edited pages for reliable redaction.
        # - clean_contents() normalizes coordinates
        # - wrap_contents() helps flatten Form XObject behavior
        print("\n" + "="*50)
        print("PHASE 0: Normalizing coordinate systems")
        print("="*50)
        for page_num in edits_by_page.keys():
            page = doc[page_num - 1]
            page.clean_contents()
            try:
                page.wrap_contents()
            except Exception as wrap_err:
                print(f"  ⚠ Page {page_num}: wrap_contents() warning: {wrap_err}")
            print(f"  ✓ Page {page_num}: Cleaned contents")

        # Deep-clean pass: save + reopen once so stale object streams/XRefs are rebuilt
        # before any redaction/insertion work.
        preflight_path = pdf_path + '.preflight.tmp'
        try:
            doc.save(
                preflight_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
                clean=True
            )
        except TypeError:
            doc.save(
                preflight_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True
            )
        doc.close()
        doc = fitz.open(preflight_path)
        print("  ✓ Preflight stream rebuild complete (save+reopen)")
        
        # PHASE A: Search & Destroy - Find and redact original text in data stream
        print("\n" + "="*50)
        print("PHASE A (Search & Destroy): Finding and redacting original text")
        print("="*50)
        
        for page_num, page_edits in edits_by_page.items():
            print(f"\n  Page {page_num}: Processing {len(page_edits)} edits...")
            page = doc[page_num - 1]
            
            # Get all images on this page for smart redaction
            images = page.get_image_info()
            image_rects = [fitz.Rect(img["bbox"]) for img in images]
            print(f"    Found {len(image_rects)} images on page")
            
            redaction_count = 0
            search_success_count = 0
            image_overlap_count = 0
            
            for edit in page_edits:
                original_text = '' if edit.get('original_text') is None else str(edit.get('original_text'))
                new_text = '' if edit.get('new_text') is None else str(edit.get('new_text'))
                force_reinsert = bool(edit.get('force_reinsert'))
                if not original_text.strip():
                    continue

                # SKIP NO-OP EDITS
                original_bbox = edit.get('original_bbox')
                bbox = edit.get('bbox')
                if not force_reinsert and original_text.strip() == new_text.strip() and original_bbox and bbox:
                    pos_unchanged = (abs(original_bbox[0] - bbox[0]) < 2 and
                                     abs(original_bbox[1] - bbox[1]) < 2 and
                                     abs(original_bbox[2] - bbox[2]) < 2 and
                                     abs(original_bbox[3] - bbox[3]) < 2)
                    if pos_unchanged:
                        print(f"    ⊘ Skipping no-op edit for '{original_text[:30]}...'")
                        edit['_skip_insert'] = True
                        continue

                # Surgical redaction policy:
                # Always redact the full outer block bbox provided by the editor.
                # This prevents partial scrub / duplicate glyph artifacts.
                found_any = False
                cleanup_base_rect = _edit_cleanup_rect(edit)
                original_rect = None
                target_rect = None
                if isinstance(original_bbox, (list, tuple)) and len(original_bbox) >= 4:
                    original_rect = fitz.Rect(original_bbox[0], original_bbox[1], original_bbox[2], original_bbox[3])
                if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
                    target_rect = fitz.Rect(bbox[0], bbox[1], bbox[2], bbox[3])

                # Tight redaction padding minimizes accidental clipping of neighboring glyphs.
                try:
                    font_size = max(float(edit.get('font_size', 0) or 0), 0.0)
                except Exception:
                    font_size = 0.0
                pad = max(0.5, min(2.0, font_size * 0.12)) if font_size > 0 else 0.75
                if original_bbox:
                    rect = fitz.Rect(cleanup_base_rect) if cleanup_base_rect is not None else (
                        fitz.Rect(original_rect) if original_rect is not None else fitz.Rect(
                        original_bbox[0],
                        original_bbox[1],
                        original_bbox[2],
                        original_bbox[3]
                    ))
                    rect = fitz.Rect(
                        rect.x0 - pad,
                        rect.y0 - pad,
                        rect.x1 + pad,
                        rect.y1 + pad
                    )
                    # Prefer exact operator locators captured during extraction.
                    # This narrows the edit to the original Tj/TJ source instead of
                    # inferring from a bbox that may overlap neighboring table text.
                    source_op_stripped = _strip_using_source_content_ops(doc, page, edit)
                    if source_op_stripped > 0:
                        redaction_count += source_op_stripped
                        found_any = True
                        edit['_stream_text_replaced'] = True
                        print(
                            f"    ✓ Removed '{original_text[:30]}...' via "
                            f"{source_op_stripped} extracted source op(s)"
                        )
                        continue

                    # For tiny cell/value edits inside shared TJ table rows, try to
                    # remove the exact substring from the owning mixed text run first.
                    # This avoids bbox redaction collateral damage on neighboring row text.
                    if new_text.strip():
                        try:
                            precise_stripped = _strip_exact_text_from_trace_tj_runs(doc, page, rect, original_text)
                        except Exception as precise_strip_err:
                            precise_stripped = 0
                            print(f"    ⚠ Exact trace TJ strip failed: {precise_strip_err}")
                        if precise_stripped > 0:
                            redaction_count += precise_stripped
                            found_any = True
                            edit['_stream_text_replaced'] = True
                            print(
                                f"    ✓ Removed '{original_text[:30]}...' via "
                                f"{precise_stripped} exact trace TJ strip(s)"
                            )
                            continue
                    if not new_text.strip():
                        precise_delete_rects = []
                        seen_precise_delete_rects = set()
                        for raw_line in re.split(r'[\r\n]+', original_text):
                            line_text = raw_line.strip()
                            if not line_text:
                                continue
                            try:
                                matches = page.search_for(line_text)
                            except Exception:
                                matches = []
                            for match_rect in matches:
                                candidate = fitz.Rect(match_rect)
                                if cleanup_base_rect is not None and not _inflate_rect(cleanup_base_rect, 4.0).intersects(candidate):
                                    continue
                                rect_key = tuple(round(v, 2) for v in (candidate.x0, candidate.y0, candidate.x1, candidate.y1))
                                if rect_key in seen_precise_delete_rects:
                                    continue
                                seen_precise_delete_rects.add(rect_key)
                                precise_delete_rects.append(candidate)
                        if precise_delete_rects:
                            for candidate in precise_delete_rects:
                                page.add_redact_annot(_inflate_rect(candidate, 0.35), fill=None)
                            redaction_count += len(precise_delete_rects)
                            found_any = True
                            print(
                                f"    ✓ Redacting '{original_text[:30]}...' via "
                                f"{len(precise_delete_rects)} precise search rect(s)"
                            )
                            continue
                        stripped_ops = _strip_text_show_ops_in_rects(doc, page, [rect])
                        if stripped_ops > 0:
                            redaction_count += stripped_ops
                            found_any = True
                            print(
                                f"    ✓ Removed '{original_text[:30]}...' via "
                                f"{stripped_ops} in-stream text-show op(s)"
                            )
                            continue
                    overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                    redact_rect = _inflate_rect(rect, max(0.75, pad))
                    # Preserve underlying vector/table art. White redaction fill
                    # introduces visible patches on pure deletions, so keep the
                    # fill transparent and rely on stream cleanup for persistence.
                    fill_color = None
                    if overlaps_image:
                        image_overlap_count += 1
                    page.add_redact_annot(redact_rect, fill=fill_color)
                    redaction_count += 1
                    found_any = True
                    print(f"    ✓ Redacting '{original_text[:30]}...' via outer block bbox")

                    # If tiny/orphan lines were removed from edited text (e.g., standalone "P"),
                    # also redact matching nearby orphan glyphs so they don't survive as separate
                    # PDF text objects after re-extraction.
                    try:
                        original_lines = [ln.strip() for ln in re.split(r'[\r\n]+', original_text) if ln and ln.strip()]
                        new_lines = {ln.strip() for ln in re.split(r'[\r\n]+', new_text) if ln and ln.strip()}
                        removed_tiny = {
                            ln for ln in original_lines
                            if ln not in new_lines and len(ln) <= 2 and re.match(r'^[A-Za-z0-9]+$', ln)
                        }
                        if removed_tiny:
                            vicinity = fitz.Rect(
                                rect.x0 - 12,
                                rect.y0 - 12,
                                rect.x1 + 12,
                                rect.y1 + 12,
                            )
                            words = page.get_text("words")
                            tiny_redactions = 0
                            for w in words:
                                if len(w) < 5:
                                    continue
                                wx0, wy0, wx1, wy1, wtxt = w[:5]
                                token = (str(wtxt) if wtxt is not None else '').strip()
                                if token not in removed_tiny:
                                    continue
                                wrect = fitz.Rect(wx0 - 1, wy0 - 1, wx1 + 1, wy1 + 1)
                                cx = (wrect.x0 + wrect.x1) / 2.0
                                cy = (wrect.y0 + wrect.y1) / 2.0
                                if not (vicinity.x0 <= cx <= vicinity.x1 and vicinity.y0 <= cy <= vicinity.y1):
                                    continue
                                overlaps_image = any(wrect.intersects(img_rect) for img_rect in image_rects)
                                fill_color = None
                                if overlaps_image:
                                    image_overlap_count += 1
                                page.add_redact_annot(_inflate_rect(wrect, 0.5), fill=fill_color)
                                redaction_count += 1
                                tiny_redactions += 1
                            if tiny_redactions > 0:
                                print(f"    ✓ Redacted {tiny_redactions} nearby removed tiny token(s): {sorted(removed_tiny)}")
                    except Exception as tiny_err:
                        print(f"    ⚠ Tiny-token redaction skipped: {tiny_err}")
                else:
                    # Fallback only when bbox is missing.
                    text_instances = page.search_for(original_text)
                    if text_instances:
                        inst_rect = text_instances[0]
                        # Create rect from search result
                        rect = fitz.Rect(
                            inst_rect.x0 - pad, inst_rect.y0 - pad,
                            inst_rect.x1 + pad, inst_rect.y1 + pad
                        )
                        
                        overlaps_image = any(rect.intersects(img_rect) for img_rect in image_rects)
                        redact_rect = _inflate_rect(rect, 0.5)
                        fill_color = None
                        if overlaps_image:
                            image_overlap_count += 1
                        page.add_redact_annot(redact_rect, fill=fill_color)
                        redaction_count += 1
                        search_success_count += 1
                    else:
                        print(f"    ✗ Cannot redact '{original_text[:30]}...' - no original_bbox and search failed")
            
            # Apply all redactions at once to physically purge the character streams
            page.apply_redactions(images=0)
            # Rebuild content stream after redaction to avoid ghost/duplicate glyph artifacts.
            try:
                page.clean_contents()
            except Exception as clean_err:
                print(f"    ⚠ page.clean_contents() warning: {clean_err}")
            print(f"    ✓ Redacted {redaction_count} locations ({search_success_count} by search, {image_overlap_count} over images)")

            # ── POST-REDACTION CLEANUP ──────────────────────────────
            # PyMuPDF's apply_redactions() cannot always remove text
            # rendered with CID/TrueType fonts (e.g. /TT2 Verdana-Bold)
            # that use TJ arrays with large glyph displacements.  These
            # orphan glyphs survive redaction even though their rendered
            # position is inside the redaction rect.
            #
            # Fix: re-extract spans after redaction.  Any span whose bbox
            # is INSIDE an edit's original_bbox AND whose text is NOT the
            # new_text being inserted is a residual that must be stripped
            # directly from the content stream.
            post_blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
            residual_spans = []
            for b in post_blocks:
                if b.get("type") != 0:
                    continue
                for l in b.get("lines", []):
                    for s in l.get("spans", []):
                        st = (s.get("text") or "").strip()
                        if not st:
                            continue
                        sb = list(s["bbox"])
                        for edit in page_edits:
                            if edit.get('_skip_insert'):
                                continue
                            if edit.get('_stream_text_replaced'):
                                continue
                            cleanup_rect = _edit_cleanup_rect(edit)
                            if cleanup_rect is None:
                                continue
                            new_t = (edit.get('new_text') or '').strip()
                            cleanup_rect = _inflate_rect(cleanup_rect, 2.0)
                            span_rect = fitz.Rect(sb[0], sb[1], sb[2], sb[3])
                            if cleanup_rect.intersects(span_rect):
                                # This span is inside the edit rect — it should have been redacted
                                # Skip if it IS the new text (already inserted)
                                if st == new_t:
                                    continue
                                residual_spans.append({
                                    "text": st,
                                    "bbox": sb,
                                    "origin": list(s["origin"]),
                                })

            if residual_spans:
                print(f"    ⚠ Found {len(residual_spans)} residual span(s) that survived redaction:")
                for rs in residual_spans:
                    print(f"      '{rs['text'][:40]}' at bbox {[round(x,1) for x in rs['bbox']]}")

                # Run one more transparent cleanup pass on the exact residual
                # span bboxes before touching raw content streams. Redacting the
                # precise survivor bounds is often enough for clipped/truncated
                # remnants that escaped the original outer-block scrub.
                try:
                    for rs in residual_spans:
                        rs_rect = fitz.Rect(rs["bbox"][0], rs["bbox"][1], rs["bbox"][2], rs["bbox"][3])
                        page.add_redact_annot(_inflate_rect(rs_rect, 0.75), fill=None)
                    page.apply_redactions(images=0)
                    try:
                        page.clean_contents()
                    except Exception as clean_err:
                        print(f"    ⚠ page.clean_contents() warning after residual pass: {clean_err}")
                    post_retry_spans = []
                    for b in page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]:
                        if b.get("type") != 0:
                            continue
                        for l in b.get("lines", []):
                            for s in l.get("spans", []):
                                st = (s.get("text") or "").strip()
                                if not st:
                                    continue
                                span_rect = fitz.Rect(s["bbox"])
                                for edit in page_edits:
                                    if edit.get('_stream_text_replaced'):
                                        continue
                                    cleanup_rect = _edit_cleanup_rect(edit)
                                    if cleanup_rect is None:
                                        continue
                                    cleanup_rect = _inflate_rect(cleanup_rect, 2.0)
                                    if cleanup_rect.intersects(span_rect):
                                        post_retry_spans.append({
                                            "text": st,
                                            "bbox": list(s["bbox"]),
                                            "origin": list(s["origin"]),
                                        })
                                        break
                    residual_spans = post_retry_spans
                    if residual_spans:
                        print(f"    ⚠ Residual retry still found {len(residual_spans)} span(s)")
                    else:
                        print("    ✓ Residual retry removed remaining spans")
                except Exception as residual_retry_err:
                    print(f"    ⚠ Residual retry redaction failed: {residual_retry_err}")

                if residual_spans:
                    try:
                        residual_cleanup_rects = [
                            _inflate_rect(fitz.Rect(rs["bbox"][0], rs["bbox"][1], rs["bbox"][2], rs["bbox"][3]), 0.75)
                            for rs in residual_spans
                        ]
                        stripped_tj_text = _strip_tj_text_in_rects(
                            doc,
                            page,
                            residual_cleanup_rects,
                            [rs.get("text") for rs in residual_spans],
                        )
                        if stripped_tj_text > 0:
                            print(f"    ✓ Stripped {stripped_tj_text} residual TJ substring(s) from content stream")
                            refreshed_residuals = []
                            for b in page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]:
                                if b.get("type") != 0:
                                    continue
                                for l in b.get("lines", []):
                                    for s in l.get("spans", []):
                                        st = (s.get("text") or "").strip()
                                        if not st:
                                            continue
                                        span_rect = fitz.Rect(s["bbox"])
                                        for edit in page_edits:
                                            if edit.get('_stream_text_replaced'):
                                                continue
                                            cleanup_rect = _edit_cleanup_rect(edit)
                                            if cleanup_rect is None:
                                                continue
                                            cleanup_rect = _inflate_rect(cleanup_rect, 2.0)
                                            if cleanup_rect.intersects(span_rect):
                                                refreshed_residuals.append({
                                                    "text": st,
                                                    "bbox": list(s["bbox"]),
                                                    "origin": list(s["origin"]),
                                                })
                                                break
                            residual_spans = refreshed_residuals
                            if residual_spans:
                                print(f"    ⚠ Residual TJ substring strip still found {len(residual_spans)} span(s)")
                            else:
                                print("    ✓ Residual TJ substring strip removed remaining spans")
                    except Exception as residual_tj_strip_err:
                        print(f"    ⚠ Residual TJ substring strip failed: {residual_tj_strip_err}")

                if residual_spans:
                    try:
                        residual_cleanup_rects = [
                            _inflate_rect(fitz.Rect(rs["bbox"][0], rs["bbox"][1], rs["bbox"][2], rs["bbox"][3]), 0.75)
                            for rs in residual_spans
                        ]
                        stripped_show_ops = _strip_text_show_ops_in_rects(doc, page, residual_cleanup_rects)
                        if stripped_show_ops > 0:
                            print(f"    ✓ Stripped {stripped_show_ops} residual text-show op(s) from content stream")
                            refreshed_residuals = []
                            for b in page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]:
                                if b.get("type") != 0:
                                    continue
                                for l in b.get("lines", []):
                                    for s in l.get("spans", []):
                                        st = (s.get("text") or "").strip()
                                        if not st:
                                            continue
                                        span_rect = fitz.Rect(s["bbox"])
                                        for edit in page_edits:
                                            if edit.get('_stream_text_replaced'):
                                                continue
                                            cleanup_rect = _edit_cleanup_rect(edit)
                                            if cleanup_rect is None:
                                                continue
                                            cleanup_rect = _inflate_rect(cleanup_rect, 2.0)
                                            if cleanup_rect.intersects(span_rect):
                                                refreshed_residuals.append({
                                                    "text": st,
                                                    "bbox": list(s["bbox"]),
                                                    "origin": list(s["origin"]),
                                                })
                                                break
                            residual_spans = refreshed_residuals
                            if residual_spans:
                                print(f"    ⚠ Residual text-show strip still found {len(residual_spans)} span(s)")
                            else:
                                print("    ✓ Residual text-show strip removed remaining spans")
                    except Exception as residual_strip_err:
                        print(f"    ⚠ Residual text-show strip failed: {residual_strip_err}")

                if residual_spans:
                    try:
                        delete_line_prefixes = []
                        for ln in re.split(r'[\r\n]+', original_text):
                            line_text = ln.strip()
                            if not line_text:
                                continue
                            delete_line_prefixes.append(line_text)
                            delete_line_prefixes.append(line_text[: min(len(line_text), 18)])
                        stripped_tails = _strip_tj_tail_for_prefixes(doc, page, delete_line_prefixes)
                        if stripped_tails > 0:
                            print(f"    ✓ Stripped {stripped_tails} mixed TJ tail(s) for deletion cleanup")
                            refreshed_residuals = []
                            for b in page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]:
                                if b.get("type") != 0:
                                    continue
                                for l in b.get("lines", []):
                                    for s in l.get("spans", []):
                                        st = (s.get("text") or "").strip()
                                        if not st:
                                            continue
                                        span_rect = fitz.Rect(s["bbox"])
                                        for edit in page_edits:
                                            if edit.get('_stream_text_replaced'):
                                                continue
                                            cleanup_rect = _edit_cleanup_rect(edit)
                                            if cleanup_rect is None:
                                                continue
                                            cleanup_rect = _inflate_rect(cleanup_rect, 2.0)
                                            if cleanup_rect.intersects(span_rect):
                                                refreshed_residuals.append({
                                                    "text": st,
                                                    "bbox": list(s["bbox"]),
                                                    "origin": list(s["origin"]),
                                                })
                                                break
                            residual_spans = refreshed_residuals
                            if residual_spans:
                                print(f"    ⚠ Mixed-TJ cleanup still found {len(residual_spans)} span(s)")
                            else:
                                print("    ✓ Mixed-TJ cleanup removed remaining spans")
                    except Exception as mixed_tj_err:
                        print(f"    ⚠ Mixed-TJ cleanup failed: {mixed_tj_err}")

            if residual_spans:
                # Strip residual spans by removing their BT..ET blocks
                # from the content stream directly.
                # We match BT blocks by checking if the block's Tm position
                # (converted to PyMuPDF page coords) is close to the residual
                # span's origin.  This avoids false positives from matching
                # short text like "P" against unrelated blocks containing
                # "PDF", "PASS", etc.
                page_height = page.rect.height
                xrefs = page.get_contents()
                stripped_total = 0
                cleanup_regions = []
                for edit in page_edits:
                    if edit.get('_skip_insert'):
                        continue
                    if edit.get('_stream_text_replaced'):
                        continue
                    cleanup_rect = _edit_cleanup_rect(edit)
                    if cleanup_rect is None:
                        continue
                    cleanup_regions.append(_inflate_rect(cleanup_rect, 2.0))

                for xref in xrefs:
                    stream = doc.xref_stream(xref)
                    if not stream:
                        continue
                    bt_blocks = list(re.finditer(rb'BT\b.*?ET\b', stream, re.DOTALL))
                    if not bt_blocks:
                        continue
                    to_remove = set()
                    for m in bt_blocks:
                        blk_bytes = m.group()
                        # Extract the Tm position from the BT block
                        tm_match = re.search(
                            rb'([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+Tm',
                            blk_bytes
                        )
                        if not tm_match:
                            continue
                        # Tm matrix: [a b c d e f] where e=x, f=y in PDF coords
                        tm_f = float(tm_match.group(6))  # y in PDF coords (bottom-up)
                        tm_e = float(tm_match.group(5))  # x in PDF coords
                        # Convert PDF y-coord to PyMuPDF y-coord (top-down)
                        pymupdf_y = page_height - tm_f

                        tm_point_inside_cleanup = False
                        for cleanup_rect in cleanup_regions:
                            if (cleanup_rect.x0 - 4.0) <= tm_e <= (cleanup_rect.x1 + 4.0) and (
                                cleanup_rect.y0 - 4.0
                            ) <= pymupdf_y <= (cleanup_rect.y1 + 4.0):
                                tm_point_inside_cleanup = True
                                break

                        if not tm_point_inside_cleanup:
                            continue

                        for rs in residual_spans:
                            ro = rs["origin"]  # [x, y] in PyMuPDF coords
                            # Position check: Tm position (e,f) is in page
                            # coords already.  Allow generous x tolerance
                            # for TJ displacement (e.g. [-2312.1(P)] shifts
                            # the glyph ~37pt right of Tm x origin).
                            x_tol = 50.0
                            y_tol = 5.0
                            if (abs(pymupdf_y - ro[1]) < y_tol and
                                abs(tm_e - ro[0]) < x_tol):
                                to_remove.add((m.start(), m.end()))
                                break
                        else:
                            # Some residuals are re-encoded/truncated and do not
                            # preserve a clean literal text match. If the text
                            # object's origin lands inside the edited cleanup
                            # region, remove it conservatively.
                            to_remove.add((m.start(), m.end()))

                    if to_remove:
                        # Remove matched BT..ET blocks from the stream
                        # Process in reverse order to preserve offsets
                        new_stream = bytearray(stream)
                        for start, end in sorted(to_remove, reverse=True):
                            new_stream[start:end] = b''
                            stripped_total += 1
                        doc.update_stream(xref, bytes(new_stream))

                if stripped_total:
                    print(f"    ✓ Stripped {stripped_total} residual BT..ET block(s) from content stream")
        
        # PHASE B: Overlay new text at NEW locations
        print("\n" + "="*50)
        print("PHASE B (The Overlay): Inserting text at new locations")
        print("="*50)
        
        insert_failures = 0
        for page_num, page_edits in edits_by_page.items():
            print(f"\n  Page {page_num}: Inserting {len(page_edits)} text blocks...")
            page = doc[page_num - 1]
            
            for edit in page_edits:
                # Skip no-op edits (flagged in Phase A)
                if edit.get('_skip_insert'):
                    print(f"    - Skipping no-op edit (text unchanged)")
                    continue

                new_text = '' if edit.get('new_text') is None else str(edit.get('new_text'))
                if not new_text.strip():
                    print(f"    - Skipping empty text (deletion)")
                    continue
                
                # Use NEW bbox for insertion
                target_bbox = edit.get('bbox') or edit.get('original_bbox')
                if not target_bbox:
                    continue
                    
                rect = fitz.Rect(target_bbox)
                font_size = edit.get('font_size', 12)
                font_name = edit.get('font', 'helv')
                try:
                    font_size = float(font_size)
                except Exception:
                    font_size = 12.0
                if font_size <= 0:
                    font_size = 12.0

                # Convert hex color to RGB tuple (0-1 range)
                color_hex = edit.get('color', '#000000').lstrip('#')
                try:
                    rgb = tuple(int(color_hex[i:i+2], 16) / 255.0 for i in (0, 2, 4))
                except:
                    rgb = (0, 0, 0)
                
                # Map font name to PyMuPDF font
                try:
                    insert_font_name, measure_font_obj = _resolve_insert_font(
                        page,
                        inserted_fonts,
                        font_name,
                        font_weight=edit.get('font_weight'),
                    )
                except Exception as font_err:
                    print(f"    ⚠ Safe font setup failed for '{font_name}': {font_err}")
                    insert_font_name = map_font_name(font_name)
                    measure_font_obj = None
                line_height = edit.get('line_height')
                try:
                    line_height = float(line_height) if line_height is not None else None
                except Exception:
                    line_height = None
                line_height_factor = max((line_height / font_size), 1.0) if line_height and font_size > 0 else 1.2
                fitted_font_size = _pick_font_size_to_fit_rect(
                    new_text,
                    insert_font_name,
                    font_size,
                    rect,
                    line_height_factor=line_height_factor,
                    min_font_size=4.0,
                    font_obj=measure_font_obj,
                )
                synthetic_textbox = bool(edit.get('synthetic_textbox'))

                if not synthetic_textbox and _insert_styled_runs(page, edit, rgb):
                    continue

                if _insert_text_by_line_metrics(
                    page=page,
                    edit=edit,
                    fontname=insert_font_name,
                    font_obj=measure_font_obj,
                    color=rgb,
                ):
                    continue

                # Insert text strictly inside the provided bbox.
                try:
                    ok, used_fs, mode = _insert_text_strict_in_bbox(
                        page=page,
                        rect=rect,
                        text=new_text,
                        fontname=insert_font_name,
                        color=rgb,
                        desired_font_size=fitted_font_size,
                        line_height_factor=line_height_factor,
                        font_obj=measure_font_obj,
                    )
                    if ok:
                        print(
                            f"    ✓ Inserted '{new_text[:30]}...' at "
                            f"({target_bbox[0]:.1f}, {target_bbox[1]:.1f}) fs={used_fs:.2f} mode={mode}"
                        )
                    else:
                        insert_failures += 1
                        print(
                            f"    ✗ Text did not fit bbox (strict insert failed) at "
                            f"({target_bbox[0]:.1f}, {target_bbox[1]:.1f}, {target_bbox[2]:.1f}, {target_bbox[3]:.1f})"
                        )
                except Exception as e:
                    # Retry with guaranteed built-in sans font while preserving bbox constraints.
                    try:
                        print(f"    ⚠ Primary insert failed ({e}), retrying with 'helv'")
                        ok, used_fs, mode = _insert_text_strict_in_bbox(
                            page=page,
                            rect=rect,
                            text=new_text,
                            fontname='helv',
                            color=rgb,
                            desired_font_size=fitted_font_size,
                            line_height_factor=line_height_factor,
                        )
                        if ok:
                            print(f"    ✓ Inserted with fallback font 'helv' fs={used_fs:.2f} mode={mode}")
                            continue
                        insert_failures += 1
                        print(f"    ✗ Fallback insert did not fit bbox")
                    except Exception as e2:
                        insert_failures += 1
                        print(f"    ✗ Failed to insert text after fallback: {e2}")

        if insert_failures > 0:
            print(f"\n✗ Aborting save: {insert_failures} text insert(s) failed.")
            doc.close()
            return False
        
        # PHASE 3: Save with cleanup
        print("\n" + "="*50)
        print("PHASE 3: Saving PDF")
        print("="*50)
        
        temp_path = pdf_path + '.tmp'
        try:
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True,
                clean=True,
            )
        except TypeError:
            # Older PyMuPDF may not accept clean=...; keep aggressive GC anyway.
            doc.save(
                temp_path,
                incremental=False,
                encryption=fitz.PDF_ENCRYPT_KEEP,
                garbage=4,
                deflate=True
            )
        doc.close()
        
        # Replace original file
        import shutil
        shutil.move(temp_path, pdf_path)
        if os.path.exists(preflight_path):
            os.remove(preflight_path)
        
        print("✓ PDF saved successfully with Search-and-Destroy Protocol")
        print("  - Original text physically removed from data stream")
        print("  - New text overlaid at new positions without image damage")
        return True
        
    except Exception as e:
        print(f"✗ Error applying edits: {e}")
        import traceback
        traceback.print_exc()
        preflight_path = pdf_path + '.preflight.tmp'
        if os.path.exists(preflight_path):
            try:
                os.remove(preflight_path)
            except Exception:
                pass
        return False


def map_font_name(font_name):
    """
    Map PDF font names to PyMuPDF font names with better matching
    PyMuPDF supports: helv, times, cour, zadb, symb
    For bold/italic, append -bo, -it, or -bi
    """
    if not font_name:
        return 'helv'
        
    font_lower = font_name.lower()
    
    # Parse style
    is_bold = 'bold' in font_lower or '700' in font_lower or 'black' in font_lower
    is_italic = 'italic' in font_lower or 'oblique' in font_lower or 'it' in font_lower

    # Use guaranteed built-in font names supported by PyMuPDF.
    if 'symbol' in font_lower:
        return 'symbol'
    if 'zapf' in font_lower or 'dingbat' in font_lower:
        return 'zapfdingbats'

    if 'courier' in font_lower or 'mono' in font_lower or 'cousine' in font_lower:
        if is_bold and is_italic:
            return 'cobi'
        if is_bold:
            return 'cobo'
        if is_italic:
            return 'coit'
        return 'cour'

    if 'times' in font_lower or 'serif' in font_lower or 'tinos' in font_lower:
        if is_bold and is_italic:
            return 'times-bolditalic'
        if is_bold:
            return 'times-bold'
        if is_italic:
            return 'times-italic'
        return 'times-roman'

    # Default sans family
    if is_bold and is_italic:
        return 'hebi'
    if is_bold:
        return 'hebo'
    if is_italic:
        return 'heit'
    return 'helv'


def main():
    if len(sys.argv) < 3:
        print("Usage: python3 apply_pdf_edits.py <pdf_path> <edits_json_file>")
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    edits_file = sys.argv[2]
    
    if not os.path.exists(pdf_path):
        print(f"✗ PDF file not found: {pdf_path}")
        sys.exit(1)
    
    if not os.path.exists(edits_file):
        print(f"✗ Edits file not found: {edits_file}")
        sys.exit(1)
    
    # Load edits data
    try:
        with open(edits_file, 'r') as f:
            edits_data = json.load(f)
    except Exception as e:
        print(f"✗ Error loading edits file: {e}")
        sys.exit(1)
    
    print("=" * 60)
    print("PDF Edit Application")
    print("=" * 60)
    print(f"PDF: {os.path.basename(pdf_path)}")
    print(f"Edits: {len(edits_data)} changes")
    print()
    
    # Apply edits
    success = apply_edits_to_pdf(pdf_path, edits_data)
    
    print()
    if success:
        print("✓ Edits applied successfully!")
        sys.exit(0)
    else:
        print("✗ Failed to apply edits")
        sys.exit(1)


if __name__ == "__main__":
    main()
