#!/usr/bin/env python3
"""
PDF Text Extraction Script using PyMuPDF (fitz)
Extracts text with position data from PDF files and saves to database
Much faster than OCR-based extraction
"""

import sys
import os
import json
import math
import re
import statistics
import time
from urllib.parse import urlparse
from pathlib import Path
import fitz  # PyMuPDF
import mysql.connector
from mysql.connector import Error
from datetime import datetime


# ---------------------------------------------------------------------------
# Glyph-name → Unicode codepoint table (Adobe standard glyph names).
# Used by cff_raw_to_otf() to populate the cmap table in the OTF wrapper.
# ---------------------------------------------------------------------------
_GLYPH_NAME_TO_UNICODE = {
    'space': 0x0020, 'exclam': 0x0021, 'quotedbl': 0x0022, 'numbersign': 0x0023,
    'dollar': 0x0024, 'percent': 0x0025, 'ampersand': 0x0026, 'quotesingle': 0x0027,
    'parenleft': 0x0028, 'parenright': 0x0029, 'asterisk': 0x002A, 'plus': 0x002B,
    'comma': 0x002C, 'hyphen': 0x002D, 'period': 0x002E, 'slash': 0x002F,
    'zero': 0x0030, 'one': 0x0031, 'two': 0x0032, 'three': 0x0033, 'four': 0x0034,
    'five': 0x0035, 'six': 0x0036, 'seven': 0x0037, 'eight': 0x0038, 'nine': 0x0039,
    'colon': 0x003A, 'semicolon': 0x003B, 'less': 0x003C, 'equal': 0x003D,
    'greater': 0x003E, 'question': 0x003F, 'at': 0x0040,
    'A': 0x0041, 'B': 0x0042, 'C': 0x0043, 'D': 0x0044, 'E': 0x0045,
    'F': 0x0046, 'G': 0x0047, 'H': 0x0048, 'I': 0x0049, 'J': 0x004A,
    'K': 0x004B, 'L': 0x004C, 'M': 0x004D, 'N': 0x004E, 'O': 0x004F,
    'P': 0x0050, 'Q': 0x0051, 'R': 0x0052, 'S': 0x0053, 'T': 0x0054,
    'U': 0x0055, 'V': 0x0056, 'W': 0x0057, 'X': 0x0058, 'Y': 0x0059, 'Z': 0x005A,
    'bracketleft': 0x005B, 'backslash': 0x005C, 'bracketright': 0x005D,
    'asciicircum': 0x005E, 'underscore': 0x005F, 'grave': 0x0060,
    'a': 0x0061, 'b': 0x0062, 'c': 0x0063, 'd': 0x0064, 'e': 0x0065,
    'f': 0x0066, 'g': 0x0067, 'h': 0x0068, 'i': 0x0069, 'j': 0x006A,
    'k': 0x006B, 'l': 0x006C, 'm': 0x006D, 'n': 0x006E, 'o': 0x006F,
    'p': 0x0070, 'q': 0x0071, 'r': 0x0072, 's': 0x0073, 't': 0x0074,
    'u': 0x0075, 'v': 0x0076, 'w': 0x0077, 'x': 0x0078, 'y': 0x0079, 'z': 0x007A,
    'braceleft': 0x007B, 'bar': 0x007C, 'braceright': 0x007D, 'asciitilde': 0x007E,
    'endash': 0x2013, 'emdash': 0x2014, 'quoteleft': 0x2018, 'quoteright': 0x2019,
    'quotedblleft': 0x201C, 'quotedblright': 0x201D, 'bullet': 0x2022,
    'ellipsis': 0x2026, 'fi': 0xFB01, 'fl': 0xFB02,
    'AE': 0x00C6, 'ae': 0x00E6, 'OE': 0x0152, 'oe': 0x0153,
    'Oslash': 0x00D8, 'oslash': 0x00F8, 'germandbls': 0x00DF,
    'copyright': 0x00A9, 'registered': 0x00AE, 'trademark': 0x2122,
    'degree': 0x00B0, 'plusminus': 0x00B1, 'multiply': 0x00D7, 'divide': 0x00F7,
    'fraction': 0x2044, 'perthousand': 0x2030, 'Euro': 0x20AC, 'currency': 0x00A4,
    'dagger': 0x2020, 'daggerdbl': 0x2021,
}


def _collect_page_link_regions(page):
    regions = []
    try:
        raw_links = page.get_links() or []
    except Exception:
        return regions

    for index, link in enumerate(raw_links):
        link_rect = link.get('from')
        if not link_rect:
            continue
        try:
            rect = fitz.Rect(link_rect)
        except Exception:
            continue
        if rect.width <= 0 or rect.height <= 0:
            continue
        regions.append({
            'index': index,
            'rect': rect,
            'uri': str(link.get('uri') or '').strip(),
            'kind': str(link.get('kind') or '').strip(),
            'page': link.get('page'),
        })

    return regions


def _find_link_region_for_bbox(bbox, link_regions):
    if not bbox or len(bbox) < 4 or not link_regions:
        return None

    try:
        target_rect = fitz.Rect(bbox)
    except Exception:
        return None

    if target_rect.width <= 0 or target_rect.height <= 0:
        return None

    center = fitz.Point(
        (target_rect.x0 + target_rect.x1) / 2,
        (target_rect.y0 + target_rect.y1) / 2,
    )
    for link_region in link_regions:
        if link_region['rect'].contains(center):
            return link_region

    best_region = None
    best_overlap_area = 0.0
    for link_region in link_regions:
        overlap = target_rect & link_region['rect']
        overlap_area = float(overlap.width * overlap.height) if overlap.width > 0 and overlap.height > 0 else 0.0
        if overlap_area > best_overlap_area:
            best_overlap_area = overlap_area
            best_region = link_region

    return best_region if best_overlap_area > 0 else None


def _link_region_display_text(link_region):
    if not isinstance(link_region, dict):
        return ''

    uri = sanitize_extracted_text(link_region.get('uri') or '').strip()
    if not uri:
        return ''

    parsed = urlparse(uri)
    if parsed.scheme in ('http', 'https'):
        host = parsed.netloc.strip()
        path = parsed.path or ''
        query = f'?{parsed.query}' if parsed.query else ''
        fragment = f'#{parsed.fragment}' if parsed.fragment else ''
        display = f'{host}{path}{query}{fragment}'.strip()
        return display.rstrip('/') if display else ''

    return uri.rstrip('/')


def cff_raw_to_otf(cff_data: bytes, font_name: str = 'UnknownFont',
                   css_weight: int = 400, css_stretch: str = 'normal',
                   gid_to_unicode=None) -> bytes:
    """
    Wrap a raw CFF blob (as extracted by PyMuPDF) in a minimal but valid
    OpenType ("OTTO") container that web browsers can load via @font-face.
    Returns the OTF binary or raises on failure.

    gid_to_unicode (optional): {glyph_index: unicode_codepoint} reconstructed
    from page.get_texttrace(). When provided, this is the authoritative
    cmap source — required for CFF subsets whose glyph names are non-standard
    (e.g. ``cid00065``) and would otherwise produce an empty cmap, causing
    browsers to fall back to Arial.
    """
    try:
        from io import BytesIO as _BytesIO
        from fontTools.cffLib import CFFFontSet
        from fontTools.ttLib import TTFont
        from fontTools.pens.boundsPen import BoundsPen
        from fontTools.ttLib.tables.C_F_F_ import table_C_F_F_
        from fontTools.ttLib.tables._h_e_a_d import table__h_e_a_d
        from fontTools.ttLib.tables._h_h_e_a import table__h_h_e_a
        from fontTools.ttLib.tables._m_a_x_p import table__m_a_x_p
        from fontTools.ttLib.tables.O_S_2f_2 import table_O_S_2f_2, Panose
        from fontTools.ttLib.tables._n_a_m_e import table__n_a_m_e, NameRecord
        from fontTools.ttLib.tables._c_m_a_p import table__c_m_a_p, cmap_format_4
        from fontTools.ttLib.tables._p_o_s_t import table__p_o_s_t
        from fontTools.ttLib.tables._h_m_t_x import table__h_m_t_x
    except ImportError as e:
        raise RuntimeError(f'fontTools not available for CFF→OTF conversion: {e}') from e

    from io import BytesIO

    cff_set = CFFFontSet()
    cff_set.decompile(BytesIO(cff_data), None, isCFF2=False)
    top_dict = cff_set.topDictIndex[0]
    cs = top_dict.CharStrings
    glyphs = list(cs.keys())

    widths: dict = {}
    lsbs: dict = {}
    all_bounds: list = []
    for g in glyphs:
        pen = BoundsPen(None)
        try:
            cs[g].draw(pen)
            b = pen.bounds or (0, 0, 500, 700)
        except Exception:
            b = (0, 0, 500, 700)
        # Use the CFF charstring's actual advance width (accessible after draw)
        # rather than estimating from bounding box, to keep hmtx consistent with CFF.
        actual_w = getattr(cs[g], 'width', None)
        widths[g] = int(actual_w) if actual_w and actual_w > 0 else max(1, int((b[2] if b else 500) + 50))
        lsbs[g] = int(b[0]) if b else 0
        if b:
            all_bounds.append(b)

    upm = 1000
    bbxMin = int(min(b[0] for b in all_bounds)) if all_bounds else 0
    bbxMax = int(max(b[2] for b in all_bounds)) if all_bounds else 800
    bbyMin = int(min(b[1] for b in all_bounds)) if all_bounds else -200
    bbyMax = int(max(b[3] for b in all_bounds)) if all_bounds else 800

    # Derive ascent/descent from the CFF Top DICT FontBBox when available
    # (Adobe CFF subsets carry the original font's design bbox, which encodes
    # the source font's true ascent/descent extents). Fall back to the
    # per-glyph aggregate bounds if FontBBox is missing or degenerate.
    # PyMuPDF's `get_text(rawdict)` reports char.bbox using the OT
    # hhea/sTypo metrics; if these are wrong, every per-glyph y-extent in
    # the reconstructed font will be smaller than the original, and any
    # downstream pixel comparison will see a vertical-shift diff at every
    # glyph edge.
    cff_font_bbox = getattr(top_dict, 'FontBBox', None)
    bbox_yMin = bbox_yMax = None
    if isinstance(cff_font_bbox, (list, tuple)) and len(cff_font_bbox) >= 4:
        try:
            cy0 = float(cff_font_bbox[1])
            cy1 = float(cff_font_bbox[3])
            if cy1 > cy0:
                bbox_yMin = int(cy0)
                bbox_yMax = int(cy1)
        except Exception:
            bbox_yMin = bbox_yMax = None
    ascent = bbox_yMax if bbox_yMax is not None else 800
    descent = bbox_yMin if bbox_yMin is not None else -200

    # Derive cap height from the 'H' glyph outline when present (matches the
    # convention used by Adobe Helvetica and other Latin fonts). Fall back to
    # the ascent value when no suitable glyph is available.
    cap_glyph_top = None
    for cap_candidate in ('H', 'I', 'E', 'B'):
        if cap_candidate in cs:
            cap_pen = BoundsPen(None)
            try:
                cs[cap_candidate].draw(cap_pen)
                if cap_pen.bounds:
                    cap_glyph_top = int(cap_pen.bounds[3])
                    break
            except Exception:
                continue
    cap_height_value = cap_glyph_top if cap_glyph_top else 700

    # x-height from 'x' glyph
    x_height_top = None
    if 'x' in cs:
        x_pen = BoundsPen(None)
        try:
            cs['x'].draw(x_pen)
            if x_pen.bounds:
                x_height_top = int(x_pen.bounds[3])
        except Exception:
            x_height_top = None
    x_height_value = x_height_top if x_height_top else 500

    # Mac timestamp = Unix timestamp + seconds(1904→1970)
    mac_ts = int(time.time()) + 2082844800

    font = TTFont(sfntVersion='OTTO')
    font.setGlyphOrder(glyphs)

    cff_table = table_C_F_F_()
    cff_table.cff = cff_set
    font['CFF '] = cff_table

    head = table__h_e_a_d()
    head.tableVersion = 1.0
    head.fontRevision = 1.0
    head.checkSumAdjustment = 0
    head.magicNumber = 0x5F0F3CF5
    head.flags = 0x000B
    head.unitsPerEm = upm
    head.created = mac_ts
    head.modified = mac_ts
    head.xMin = bbxMin; head.yMin = bbyMin
    head.xMax = bbxMax; head.yMax = bbyMax
    head.macStyle = 0x0001 if css_weight >= 700 else 0
    head.lowestRecPPEM = 8
    head.fontDirectionHint = 2
    head.indexToLocFormat = 0
    head.glyphDataFormat = 0
    font['head'] = head

    hhea = table__h_h_e_a()
    hhea.tableVersion = 0x00010000
    hhea.ascent = ascent; hhea.descent = descent; hhea.lineGap = 0
    hhea.advanceWidthMax = max(widths.values())
    hhea.minLeftSideBearing = min(lsbs.values())
    hhea.minRightSideBearing = 0
    hhea.xMaxExtent = bbxMax
    hhea.caretSlopeRise = 1; hhea.caretSlopeRun = 0; hhea.caretOffset = 0
    hhea.reserved0 = hhea.reserved1 = hhea.reserved2 = hhea.reserved3 = 0
    hhea.metricDataFormat = 0
    hhea.numberOfHMetrics = len(glyphs)
    font['hhea'] = hhea

    maxp = table__m_a_x_p()
    maxp.tableVersion = 0x00005000
    maxp.numGlyphs = len(glyphs)
    font['maxp'] = maxp

    os2 = table_O_S_2f_2()
    os2.version = 4
    os2.xAvgCharWidth = 500
    os2.usWeightClass = css_weight
    os2.usWidthClass = 3 if css_stretch == 'condensed' else 5
    os2.fsType = 0
    os2.ySubscriptXSize = 650; os2.ySubscriptYSize = 600
    os2.ySubscriptXOffset = 0; os2.ySubscriptYOffset = 75
    os2.ySuperscriptXSize = 650; os2.ySuperscriptYSize = 600
    os2.ySuperscriptXOffset = 0; os2.ySuperscriptYOffset = 350
    os2.yStrikeoutSize = 80; os2.yStrikeoutPosition = 300
    os2.sFamilyClass = 0
    os2.panose = Panose()
    os2.ulUnicodeRange1 = 3
    os2.ulUnicodeRange2 = os2.ulUnicodeRange3 = os2.ulUnicodeRange4 = 0
    os2.achVendID = 'UNKN'
    os2.fsSelection = 0x0020 if css_weight >= 700 else 0x0040
    char_codes = [cp for g, cp in _GLYPH_NAME_TO_UNICODE.items() if g in glyphs]
    os2.firstCharIndex = min(char_codes) if char_codes else 32
    os2.lastCharIndex = max(char_codes) if char_codes else 126
    os2.sTypoAscender = ascent; os2.sTypoDescender = descent; os2.sTypoLineGap = 0
    os2.usWinAscent = ascent; os2.usWinDescent = abs(descent)
    os2.ulCodePageRange1 = 1; os2.ulCodePageRange2 = 0
    os2.sxHeight = x_height_value; os2.sCapHeight = cap_height_value
    os2.usDefaultChar = 0; os2.usBreakChar = 32; os2.usMaxContext = 0
    font['OS/2'] = os2

    name_table = table__n_a_m_e()
    name_table.names = []
    family = font_name.split('-')[0]
    for nid, s in [(1, family), (2, 'Regular'), (4, font_name), (6, font_name)]:
        nr = NameRecord()
        nr.nameID = nid; nr.platformID = 3; nr.platEncID = 1; nr.langID = 0x0409
        nr.string = s.encode('utf-16-be')
        name_table.names.append(nr)
    font['name'] = name_table

    # Build cmap, preferring the gid→unicode trace map (authoritative for
    # PDF subsets) over glyph-name lookup (fails for cidNNNNN-style names).
    cmap_entries = {}
    if gid_to_unicode:
        for gid, codepoint in gid_to_unicode.items():
            if not isinstance(gid, int) or not isinstance(codepoint, int):
                continue
            if gid < 0 or gid >= len(glyphs):
                continue
            if codepoint <= 0 or codepoint > 0x10FFFF:
                continue
            glyph_name = glyphs[gid]
            if glyph_name == '.notdef':
                continue
            cmap_entries.setdefault(codepoint, glyph_name)
    if not cmap_entries:
        # Fallback: glyph-name lookup via Adobe glyph list. Works for fonts
        # that kept readable glyph names (Type1-derived CFF).
        for g in glyphs:
            cp = _GLYPH_NAME_TO_UNICODE.get(g)
            if cp is not None and g != '.notdef':
                cmap_entries.setdefault(cp, g)
    if not cmap_entries:
        # Last-ditch: ASCII 32..126 sequential — keeps font loadable so the
        # browser at least uses its metrics instead of falling back to Arial.
        for codepoint, g in zip(range(32, 127), [g for g in glyphs if g != '.notdef']):
            cmap_entries.setdefault(codepoint, g)

    cmap_t = table__c_m_a_p()
    cmap_t.tableVersion = 0
    fmt4 = cmap_format_4(4)
    fmt4.platformID = 3; fmt4.platEncID = 1; fmt4.language = 0
    fmt4.cmap = {cp: g for cp, g in cmap_entries.items() if cp <= 0xFFFF}
    cmap_t.tables = [fmt4]
    font['cmap'] = cmap_t

    post = table__p_o_s_t()
    post.formatType = 3.0; post.italicAngle = 0
    post.underlinePosition = -100; post.underlineThickness = 50
    post.isFixedPitch = 0
    post.minMemType42 = post.maxMemType42 = post.minMemType1 = post.maxMemType1 = 0
    font['post'] = post

    hmtx = table__h_m_t_x()
    hmtx.metrics = {g: (widths[g], lsbs[g]) for g in glyphs}
    font['hmtx'] = hmtx

    out = BytesIO()
    font.save(out)
    return out.getvalue()


def _glyph_name_to_unicode(glyph_name):
    if not glyph_name:
        return None

    base = str(glyph_name).split('.', 1)[0]
    if base in _GLYPH_NAME_TO_UNICODE:
        return _GLYPH_NAME_TO_UNICODE[base]

    match = re.match(r'^uni([0-9A-Fa-f]{4})$', base)
    if match:
        return int(match.group(1), 16)

    match = re.match(r'^u([0-9A-Fa-f]{4,6})$', base)
    if match:
        return int(match.group(1), 16)

    return None


def _build_doc_font_unicode_maps(doc):
    """
    Build gid->unicode maps from PyMuPDF text traces.
    PDF subset TrueType fonts often omit a browser-usable cmap table, but
    page.get_texttrace() exposes both the decoded Unicode codepoint and the
    glyph id used to paint that character. We use that to reconstruct a cmap.
    """
    font_maps = {}

    for page in doc:
        try:
            traces = page.get_texttrace() or []
        except Exception:
            continue

        for trace in traces:
            trace_font = str(trace.get('font') or '').strip()
            if not trace_font:
                continue

            candidate_keys = [
                trace_font,
                _strip_pdf_font_subset_prefix(trace_font),
                _normalize_font_name(trace_font),
                _normalize_font_name(_strip_pdf_font_subset_prefix(trace_font)),
            ]
            candidate_keys = [key for key in candidate_keys if key]
            if not candidate_keys:
                continue

            for ch in trace.get('chars') or []:
                if not ch or len(ch) < 2:
                    continue

                codepoint = ch[0]
                glyph_id = ch[1]
                if not isinstance(codepoint, int) or not isinstance(glyph_id, int):
                    continue
                if codepoint <= 0 or glyph_id < 0:
                    continue

                for key in candidate_keys:
                    mapping = font_maps.setdefault(key, {})
                    existing = mapping.get(glyph_id)
                    if existing is None or existing == codepoint:
                        mapping[glyph_id] = codepoint

    return font_maps


def _lookup_doc_font_unicode_map(font_maps, font_name):
    if not font_name or not font_maps:
        return {}

    candidates = [
        str(font_name).strip(),
        _strip_pdf_font_subset_prefix(font_name),
        _normalize_font_name(font_name),
        _normalize_font_name(_strip_pdf_font_subset_prefix(font_name)),
    ]
    for key in candidates:
        if key and key in font_maps and font_maps[key]:
            return font_maps[key]

    return {}


def _needs_truetype_web_repair(ttf_data: bytes) -> bool:
    try:
        from io import BytesIO
        from fontTools.ttLib import TTFont
        font = TTFont(BytesIO(ttf_data))
    except Exception:
        return False

    required_tables = {'head', 'hhea', 'maxp', 'hmtx', 'name', 'OS/2', 'cmap'}
    if any(table not in font for table in required_tables):
        return True

    # #4: a present-but-empty cmap is just as broken as a missing one — the
    # browser will load the font but can't map any character to a glyph and
    # silently falls back to the next family in the @font-face stack (Arial).
    try:
        cmap = font['cmap']
        total_entries = 0
        for sub in getattr(cmap, 'tables', []) or []:
            total_entries += len(getattr(sub, 'cmap', {}) or {})
        if total_entries == 0:
            return True
    except Exception:
        return True
    return False


def _truetype_glyph_health(ttf_data: bytes):
    """
    Inspect a TrueType font and return (cmap_count, empty_count, filled_count)
    for the glyphs reachable via the best cmap. An "empty" glyph has
    numberOfContours == 0 and is not a composite — i.e. it would render
    nothing on a canvas even though the font reports a width via hmtx.

    Returns (0, 0, 0) on parse failure.
    """
    try:
        from io import BytesIO
        from fontTools.ttLib import TTFont
        font = TTFont(BytesIO(ttf_data))
    except Exception:
        return (0, 0, 0)

    if 'glyf' not in font:
        # CFF/OTF fonts don't have a glyf table; skip this check (their
        # outline integrity is checked elsewhere via cff_raw_to_otf).
        return (0, 0, 0)

    try:
        cmap = font.getBestCmap() or {}
        glyf = font['glyf']
    except Exception:
        return (0, 0, 0)

    empty = 0
    filled = 0
    for glyph_name in cmap.values():
        try:
            g = glyf[glyph_name]
        except Exception:
            continue
        if g.numberOfContours == 0 and not g.isComposite():
            empty += 1
        else:
            filled += 1
    return (len(cmap), empty, filled)


def _is_truetype_glyph_outline_broken(ttf_data: bytes) -> bool:
    """
    Worst-case font corruption: cmap is intact, hmtx reports widths, but the
    actual glyph outlines are empty so canvas fillText paints nothing. This
    is the silent-text-vanishing failure mode (see
    /memories/repo/edit-new-broken-runtime-extracted-fonts.md). Returns True
    if more than half of the cmap-mapped glyphs are empty. Always tolerates
    .notdef being empty.
    """
    cmap_count, empty, filled = _truetype_glyph_health(ttf_data)
    if cmap_count == 0:
        return False  # not a glyf-based font, or unparseable — handled elsewhere
    # Allow a small slop for .notdef and a couple of intentionally-empty glyphs.
    if cmap_count <= 4:
        return empty > 1 and filled == 0
    return empty > (cmap_count // 2)


def repair_truetype_for_web(ttf_data: bytes, font_name: str = 'UnknownFont',
                            gid_to_unicode=None, css_weight: int = 400,
                            css_stretch: str = 'normal') -> bytes:
    """
    Re-save an extracted TrueType subset as a browser-safe webfont.
    Adds a synthetic cmap and fills in required metadata tables when they are
    missing. This is specifically for PDF embedded subsets that are valid for
    PDF rendering but rejected by browsers via @font-face.
    """
    try:
        from io import BytesIO
        from fontTools.ttLib import TTFont
        from fontTools.ttLib.tables._h_e_a_d import table__h_e_a_d
        from fontTools.ttLib.tables._h_h_e_a import table__h_h_e_a
        from fontTools.ttLib.tables._m_a_x_p import table__m_a_x_p
        from fontTools.ttLib.tables.O_S_2f_2 import table_O_S_2f_2, Panose
        from fontTools.ttLib.tables._n_a_m_e import table__n_a_m_e, NameRecord
        from fontTools.ttLib.tables._c_m_a_p import table__c_m_a_p, cmap_format_4, cmap_format_12
        from fontTools.ttLib.tables._p_o_s_t import table__p_o_s_t
        from fontTools.ttLib.tables._h_m_t_x import table__h_m_t_x
    except ImportError as e:
        raise RuntimeError(f'fontTools not available for TrueType repair: {e}') from e

    font = TTFont(BytesIO(ttf_data))
    glyph_order = font.getGlyphOrder()
    if not glyph_order:
        raise RuntimeError('TrueType repair failed: extracted font has no glyph order')

    mac_ts = int(time.time()) + 2082844800
    upm = int(getattr(font.get('head'), 'unitsPerEm', 1000) or 1000)

    if 'maxp' not in font:
        maxp = table__m_a_x_p()
        maxp.tableVersion = 0x00010000
        maxp.numGlyphs = len(glyph_order)
        font['maxp'] = maxp
    else:
        font['maxp'].numGlyphs = len(glyph_order)

    if 'hmtx' not in font:
        hmtx = table__h_m_t_x()
        hmtx.metrics = {glyph: (upm, 0) for glyph in glyph_order}
        font['hmtx'] = hmtx

    metrics = getattr(font['hmtx'], 'metrics', {}) or {}
    advance_values = [int(width) for width, _lsb in metrics.values()] or [upm]
    lsb_values = [int(lsb) for _width, lsb in metrics.values()] or [0]

    if 'head' not in font:
        head = table__h_e_a_d()
        head.tableVersion = 1.0
        head.fontRevision = 1.0
        head.checkSumAdjustment = 0
        head.magicNumber = 0x5F0F3CF5
        head.flags = 0x000B
        head.unitsPerEm = upm
        head.created = mac_ts
        head.modified = mac_ts
        head.xMin = 0
        head.yMin = -200
        head.xMax = max(advance_values)
        head.yMax = int(upm * 0.8)
        head.macStyle = 0x0001 if css_weight >= 700 else 0
        head.lowestRecPPEM = 8
        head.fontDirectionHint = 2
        head.indexToLocFormat = 0
        head.glyphDataFormat = 0
        font['head'] = head
    else:
        font['head'].macStyle = 0x0001 if css_weight >= 700 else 0

    if 'hhea' not in font:
        hhea = table__h_h_e_a()
        hhea.tableVersion = 0x00010000
        hhea.ascent = int(upm * 0.8)
        hhea.descent = -int(upm * 0.2)
        hhea.lineGap = 0
        hhea.advanceWidthMax = max(advance_values)
        hhea.minLeftSideBearing = min(lsb_values)
        hhea.minRightSideBearing = 0
        hhea.xMaxExtent = max(advance_values)
        hhea.caretSlopeRise = 1
        hhea.caretSlopeRun = 0
        hhea.caretOffset = 0
        hhea.reserved0 = hhea.reserved1 = hhea.reserved2 = hhea.reserved3 = 0
        hhea.metricDataFormat = 0
        hhea.numberOfHMetrics = len(metrics)
        font['hhea'] = hhea
    else:
        font['hhea'].advanceWidthMax = max(advance_values)
        font['hhea'].minLeftSideBearing = min(lsb_values)
        font['hhea'].numberOfHMetrics = len(metrics)

    if 'OS/2' not in font:
        os2 = table_O_S_2f_2()
        os2.version = 4
        os2.xAvgCharWidth = int(sum(advance_values) / max(1, len(advance_values)))
        os2.usWeightClass = css_weight
        os2.usWidthClass = 3 if css_stretch == 'condensed' else 5
        os2.fsType = 0
        os2.ySubscriptXSize = 650
        os2.ySubscriptYSize = 600
        os2.ySubscriptXOffset = 0
        os2.ySubscriptYOffset = 75
        os2.ySuperscriptXSize = 650
        os2.ySuperscriptYSize = 600
        os2.ySuperscriptXOffset = 0
        os2.ySuperscriptYOffset = 350
        os2.yStrikeoutSize = 80
        os2.yStrikeoutPosition = 300
        os2.sFamilyClass = 0
        os2.panose = Panose()
        os2.ulUnicodeRange1 = 3
        os2.ulUnicodeRange2 = os2.ulUnicodeRange3 = os2.ulUnicodeRange4 = 0
        os2.achVendID = 'UNKN'
        os2.fsSelection = 0x0020 if css_weight >= 700 else 0x0040
        os2.firstCharIndex = 32
        os2.lastCharIndex = 126
        os2.sTypoAscender = getattr(font['hhea'], 'ascent', int(upm * 0.8))
        os2.sTypoDescender = getattr(font['hhea'], 'descent', -int(upm * 0.2))
        os2.sTypoLineGap = getattr(font['hhea'], 'lineGap', 0)
        os2.usWinAscent = max(0, os2.sTypoAscender)
        os2.usWinDescent = abs(min(0, os2.sTypoDescender))
        os2.ulCodePageRange1 = 1
        os2.ulCodePageRange2 = 0
        os2.sxHeight = int(upm * 0.5)
        os2.sCapHeight = int(upm * 0.7)
        os2.usDefaultChar = 0
        os2.usBreakChar = 32
        os2.usMaxContext = 0
        font['OS/2'] = os2
    else:
        font['OS/2'].usWeightClass = css_weight
        font['OS/2'].usWidthClass = 3 if css_stretch == 'condensed' else 5

    if 'name' not in font:
        name_table = table__n_a_m_e()
        name_table.names = []
        family = font_name.split('-')[0]
        style_name = 'Bold' if css_weight >= 700 else 'Regular'
        for nid, s in [(1, family), (2, style_name), (4, font_name), (6, font_name)]:
            nr = NameRecord()
            nr.nameID = nid
            nr.platformID = 3
            nr.platEncID = 1
            nr.langID = 0x0409
            nr.string = s.encode('utf-16-be')
            name_table.names.append(nr)
        font['name'] = name_table

    cmap_entries = {}
    if gid_to_unicode:
        for glyph_id, codepoint in sorted(gid_to_unicode.items()):
            if not isinstance(glyph_id, int) or not isinstance(codepoint, int):
                continue
            if glyph_id < 0 or glyph_id >= len(glyph_order):
                continue
            if codepoint <= 0 or codepoint > 0x10FFFF:
                continue
            glyph_name = glyph_order[glyph_id]
            if glyph_name == '.notdef':
                continue
            cmap_entries.setdefault(codepoint, glyph_name)

    if not cmap_entries:
        for glyph_name in glyph_order:
            codepoint = _glyph_name_to_unicode(glyph_name)
            if codepoint is None:
                continue
            if glyph_name == '.notdef':
                continue
            cmap_entries.setdefault(codepoint, glyph_name)

    if not cmap_entries:
        fallback_codepoints = list(range(32, 127))
        for codepoint, glyph_name in zip(fallback_codepoints, [g for g in glyph_order if g != '.notdef']):
            cmap_entries.setdefault(codepoint, glyph_name)

    cmap_t = table__c_m_a_p()
    cmap_t.tableVersion = 0
    cmap_t.tables = []

    bmp_entries = {cp: g for cp, g in cmap_entries.items() if cp <= 0xFFFF}
    if bmp_entries:
        fmt4 = cmap_format_4(4)
        fmt4.platformID = 3
        fmt4.platEncID = 1
        fmt4.language = 0
        fmt4.cmap = bmp_entries
        cmap_t.tables.append(fmt4)

    astral_entries = {cp: g for cp, g in cmap_entries.items() if cp > 0xFFFF}
    if astral_entries:
        fmt12 = cmap_format_12(12)
        fmt12.platformID = 3
        fmt12.platEncID = 10
        fmt12.language = 0
        fmt12.cmap = astral_entries
        cmap_t.tables.append(fmt12)

    font['cmap'] = cmap_t

    if 'post' not in font:
        post = table__p_o_s_t()
        post.formatType = 3.0
        post.italicAngle = 0
        post.underlinePosition = -100
        post.underlineThickness = 50
        post.isFixedPitch = 0
        post.minMemType42 = post.maxMemType42 = post.minMemType1 = post.maxMemType1 = 0
        font['post'] = post

    out = BytesIO()
    font.save(out)
    return out.getvalue()


_EXTRACTION_ZERO_WIDTH_RE = re.compile(r'[\u200B\u200C\u200D\u2060\uFEFF]')
_EXTRACTION_PRIVATE_USE_RE = re.compile(r'[\uE000-\uF8FF]')
_EXTRACTION_CONTROL_RE = re.compile(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]')
_EXTRACTION_UNDERLINE_RUN_RE = re.compile(r'_{3,}')
_EXTRACTION_LARGE_GAP_RE = re.compile(r' {8,}')
_EXTRACTION_URLISH_RE = re.compile(
    r'(?:https?://|www\.)\S+|[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?:/[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]*)?',
    re.IGNORECASE,
)
_PDF_TEXT_SHOW_OP_RE = re.compile(
    rb'BT\b|ET\b|'
    rb'[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+[-\d.]+\s+Tm\b|'
    rb'[-\d.]+\s+[-\d.]+\s+T[dD]\b|'
    rb'\((?:\\.|[^\\)])*\)\s*Tj\b|'
    rb'\[(?:\\.|[^\\\]])*\]\s*TJ\b',
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
            dy = abs(float(p1.y) - float(p2.y))
            dx = abs(float(p1.x) - float(p2.x))
            if dy > 0.5:
                if dx < 100 or dy > 1.2:
                    continue
            if dx < 20:
                continue
            x0 = min(float(p1.x), float(p2.x))
            x1 = max(float(p1.x), float(p2.x))
            y = (float(p1.y) + float(p2.y)) / 2.0
            lines.append((x0, x1, y, stroke_w))
    return lines


def _collect_vertical_lines(page):
    """Collect vertical vector line segments from page drawings."""
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
            if abs(float(p1.x) - float(p2.x)) > 0.5:
                continue
            y0 = min(float(p1.y), float(p2.y))
            y1 = max(float(p1.y), float(p2.y))
            if (y1 - y0) < 10:
                continue
            x = (float(p1.x) + float(p2.x)) / 2.0
            lines.append((x, y0, y1, stroke_w))
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


def _rect_union(a, b):
    if not a:
        return b
    if not b:
        return a
    return (
        min(float(a[0]), float(b[0])),
        min(float(a[1]), float(b[1])),
        max(float(a[2]), float(b[2])),
        max(float(a[3]), float(b[3])),
    )


def _rects_have_vertical_barrier(left_rect, right_rect, vertical_lines):
    if not left_rect or not right_rect or not vertical_lines:
        return False

    a = tuple(float(value) for value in left_rect[:4])
    b = tuple(float(value) for value in right_rect[:4])
    if a[0] > b[0]:
        a, b = b, a

    gap_left = min(a[2], b[0])
    gap_right = max(a[2], b[0])
    if gap_right < gap_left:
        gap_left, gap_right = gap_right, gap_left

    band_top = min(a[1], b[1]) - 1.0
    band_bottom = max(a[3], b[3]) + 1.0
    band_height = max(1.0, band_bottom - band_top)

    for x, y0, y1, _stroke_w in vertical_lines:
        if x < (gap_left - 1.0) or x > (gap_right + 1.0):
            continue
        overlap = max(0.0, min(band_bottom, y1) - max(band_top, y0))
        if overlap <= 0:
            continue
        if overlap >= max(6.0, band_height * 0.5):
            return True
    return False


def _rects_have_widget_barrier(left_rect, right_rect, widget_rects):
    if not left_rect or not right_rect or not widget_rects:
        return False

    a = tuple(float(value) for value in left_rect[:4])
    b = tuple(float(value) for value in right_rect[:4])
    if a[0] > b[0]:
        a, b = b, a

    gap_left = min(a[2], b[0])
    gap_right = max(a[2], b[0])
    if gap_right < gap_left:
        gap_left, gap_right = gap_right, gap_left

    band_top = min(a[1], b[1]) - 2.0
    band_bottom = max(a[3], b[3]) + 2.0

    for widget_rect in widget_rects:
        wx0, wy0, wx1, wy1 = [float(value) for value in widget_rect[:4]]
        widget_center_x = (wx0 + wx1) / 2.0
        if widget_center_x < (gap_left - 1.5) or widget_center_x > (gap_right + 1.5):
            continue
        if wy1 < band_top or wy0 > band_bottom:
            continue
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


def _normalize_line_direction(line_dir):
    if not isinstance(line_dir, (list, tuple)) or len(line_dir) < 2:
        return (1.0, 0.0)
    try:
        return (float(line_dir[0]), float(line_dir[1]))
    except (TypeError, ValueError):
        return (1.0, 0.0)


def _line_rotation_degrees(line_dir):
    dx, dy = _normalize_line_direction(line_dir)
    rotation = math.degrees(math.atan2(dy, dx))
    if abs(rotation) < 0.01:
        return 0.0
    return rotation


def _should_reverse_rotated_line_text(line_dir):
    dx, dy = _normalize_line_direction(line_dir)
    return abs(dx) <= 0.25 and dy < -0.75


def _apply_line_direction_text_order(text, line_dir):
    return text


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
    name = _strip_pdf_font_subset_prefix(font_name)
    return ''.join(ch for ch in name.lower() if ch.isalnum())


def _strip_pdf_font_subset_prefix(font_name):
    if not font_name:
        return ''
    name = str(font_name).strip()
    if '+' in name:
        prefix, rest = name.split('+', 1)
        if len(prefix) == 6 and prefix.isupper():
            return rest
    return name


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


def _build_page_font_metadata(page_fonts):
    by_xref = {}
    by_name = {}

    for font in page_fonts or []:
        if not font:
            continue

        try:
            xref = int(font[0])
        except Exception:
            continue

        font_ext = str(font[1] or '') if len(font) > 1 else ''
        basefont = str(font[3] or '') if len(font) > 3 else ''
        reported_name = str(font[4] or '') if len(font) > 4 else ''
        clean_basefont = _strip_pdf_font_subset_prefix(basefont)
        clean_reported_name = _strip_pdf_font_subset_prefix(reported_name)
        clean_name = clean_basefont or clean_reported_name or basefont or reported_name
        family = _font_family_name(clean_name or basefont or reported_name)
        is_embedded = bool(font_ext and font_ext != 'n/a')

        meta = {
            'font_xref': xref,
            'pdf_font_name': basefont or reported_name or clean_name,
            'clean_name': clean_name or basefont or reported_name,
            'family': family or clean_name or basefont or reported_name,
            'file_ext': font_ext,
            'is_embedded': is_embedded,
        }
        by_xref[xref] = meta

        candidate_names = {
            basefont,
            reported_name,
            clean_basefont,
            clean_reported_name,
            meta['clean_name'],
        }
        for candidate in candidate_names:
            normalized = _normalize_font_name(candidate)
            if not normalized:
                continue
            by_name.setdefault(normalized, []).append(meta)

    return {
        'by_xref': by_xref,
        'by_name': by_name,
    }


def _resolve_embedded_font_meta(font_name, font_xref, page_font_metadata):
    if not page_font_metadata:
        return None

    try:
        xref_num = int(font_xref) if font_xref is not None else None
    except Exception:
        xref_num = None

    if xref_num is not None:
        meta = (page_font_metadata.get('by_xref') or {}).get(xref_num)
        if meta and meta.get('is_embedded'):
            return dict(meta)

    normalized_name = _normalize_font_name(font_name)
    if not normalized_name:
        return None

    for meta in (page_font_metadata.get('by_name') or {}).get(normalized_name, []):
        if meta.get('is_embedded'):
            return dict(meta)

    return None


def _attach_embedded_font_fields(target, embedded_font_meta):
    if not isinstance(target, dict):
        return target

    target['uses_embedded_font'] = bool(embedded_font_meta)
    if embedded_font_meta:
        target['embedded_font_name'] = embedded_font_meta.get('clean_name')
        target['embedded_font_family'] = embedded_font_meta.get('family')
        target['embedded_font_xref'] = embedded_font_meta.get('font_xref')
    return target


def extract_embedded_fonts(pdf_path, document_id, output_dir=None):
    """
    Extract embedded fonts from a PDF and save as web-usable font files.
    Returns a dict mapping PDF font names to their extracted file paths
    and CSS metadata (family, weight, style).
    """
    if output_dir is None:
        # Default: save under a writable public font folder.
        script_dir = os.path.dirname(os.path.abspath(__file__))
        project_root = os.path.abspath(os.path.join(script_dir, '..', '..'))
        output_dir = os.path.join(project_root, 'public', 'fonts', 'runtime-extracted', str(document_id))

    os.makedirs(output_dir, exist_ok=True)

    doc = fitz.open(pdf_path)
    embedded_fonts = {}
    seen_xrefs = set()
    font_unicode_maps = _build_doc_font_unicode_maps(doc)

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
            clean_name = _strip_pdf_font_subset_prefix(font_name)

            # Determine CSS font-family, weight, style from font name
            lower_name = clean_name.lower()

            # Extract family (strip weight/style suffixes)
            family_base = clean_name.split(',')[0]  # "TahomaUnicode,Bold" → "TahomaUnicode"
            for sep in ('-', '_'):
                if sep in family_base:
                    family_base = family_base.split(sep, 1)[0]

            # Determine weight — check full lowercase name first, then suffix tokens
            # for abbreviated forms like "Blk" (Black=900), "Bd"/"Bold"=700, "Lt"=300
            css_weight = '400'
            # Check suffix tokens (split on - _ ,) so abbreviations like "BlkCn" are isolated
            _suffix_tokens = [p for p in re.split(r'[-_,]', lower_name)[1:] if p]
            _suffix_str = '-'.join(_suffix_tokens)
            # Explicit numeric weight suffix takes highest priority: e.g. "Font_700wght" → 700
            _explicit_weight = re.search(r'[_-](\d{3})(?:wght|w)?$', lower_name)
            if _explicit_weight:
                _w = int(_explicit_weight.group(1))
                if 100 <= _w <= 900:
                    css_weight = str(_w)
            elif 'bold' in lower_name and ('extra' in lower_name or 'ultra' in lower_name):
                css_weight = '800'
            elif 'semibold' in lower_name or 'demibold' in lower_name:
                css_weight = '600'
            elif ('black' in lower_name or 'heavy' in lower_name
                  or any(t.startswith('blk') or t == 'hvy' for t in _suffix_tokens)):
                # Covers: "Black", "Heavy", "BlkCn", "BlkCond", "Hvy" suffix tokens
                css_weight = '900'
            elif (any(t == 'bd' or t.startswith('bd') for t in _suffix_tokens)
                  or re.search(r'\bbold\b|bold', _suffix_str)):
                # Covers: "Bd", "BdIt" (bold-italic), "BdOu" (bold-outline),
                # "BdCn" (bold-condensed), "Bold", "BoldItalic", etc.
                css_weight = '700'
            elif 'bold' in lower_name:
                css_weight = '700'
            elif 'demi' in lower_name:
                # "Demi" is semi-bold (e.g. ITCFranklinGothicStd-Demi)
                css_weight = '600'
            elif 'medium' in lower_name or re.search(r'\bmd\b|\bmed\b', _suffix_str):
                css_weight = '500'
            elif 'light' in lower_name or re.search(r'\blt\b|\bltc[nd]?$', _suffix_str):
                css_weight = '300'
            elif 'thin' in lower_name or 'hairline' in lower_name:
                css_weight = '100'

            # Determine stretch from suffix tokens
            css_stretch = 'normal'
            _full_suffix_lower = lower_name
            if 'condensed' in _full_suffix_lower or 'narrow' in _full_suffix_lower:
                css_stretch = 'condensed'
            elif 'expanded' in _full_suffix_lower or 'extended' in _full_suffix_lower:
                css_stretch = 'expanded'
            else:
                for _tok in _suffix_tokens:
                    # e.g. "BlkCn" → token "blkcn" ends with "cn"
                    # e.g. "BlkCond" → token "blkcond" ends with "cond"
                    if _tok in ('cn', 'cd', 'cond') or _tok.endswith(('cn', 'cd', 'cond')):
                        css_stretch = 'condensed'
                        break
                    if _tok in ('ext', 'exp', 'expanded', 'extended', 'wide'):
                        css_stretch = 'expanded'
                        break

            # Determine style
            css_style = 'italic' if ('italic' in lower_name or 'oblique' in lower_name) else 'normal'

            # Save font file
            safe_name = clean_name.replace(',', '_').replace('+', '_').replace(' ', '_')
            actual_ext = ext
            actual_content = content

            if ext == 'ttf' and _needs_truetype_web_repair(content):
                gid_to_unicode = _lookup_doc_font_unicode_map(font_unicode_maps, font_name)
                if not gid_to_unicode:
                    gid_to_unicode = _lookup_doc_font_unicode_map(font_unicode_maps, clean_name)
                try:
                    actual_content = repair_truetype_for_web(
                        content,
                        font_name=clean_name,
                        gid_to_unicode=gid_to_unicode,
                        css_weight=int(css_weight),
                        css_stretch=css_stretch,
                    )
                    print(f"    ↳ Rebuilt TTF for web use: {clean_name}")
                except Exception as ttf_err:
                    actual_content = content
                    print(f"    ⚠ TrueType web repair failed for {clean_name}: {ttf_err}")

            # Final integrity gate: if the TrueType outlines are mostly empty
            # the browser will load the font and silently render nothing for
            # every span that picks it up (see /memories/repo/edit-new-broken-
            # runtime-extracted-fonts.md). Refuse to ship such a font: skip
            # both the file write and the embedded_fonts entry so the editor
            # falls back to a system family that actually paints glyphs.
            if actual_ext == 'ttf' and _is_truetype_glyph_outline_broken(actual_content):
                cmap_count, empty_count, _filled = _truetype_glyph_health(actual_content)
                print(
                    f"  ⛔ Skipping {clean_name}: TrueType outlines broken "
                    f"({empty_count}/{cmap_count} cmap glyphs are empty). "
                    f"Editor will fall back to system font."
                )
                continue

            filename = f"{safe_name}.{actual_ext}"
            filepath = os.path.join(output_dir, filename)
            with open(filepath, 'wb') as f:
                f.write(actual_content)

            # For raw CFF data (browsers cannot load bare CFF): wrap in OTF container.
            # Saves a companion .otf file and updates file_path/ext to point at it.
            actual_path = filepath
            web_path = f"/fonts/runtime-extracted/{document_id}/{filename}"
            if ext == 'cff':
                # #4: pass the authoritative gid→unicode trace map so the
                # generated OTF's cmap covers the codepoints actually used
                # by the document (CFF subsets with cidNNNNN glyph names
                # otherwise produce an empty cmap and the browser falls
                # back to Arial).
                cff_gid_to_unicode = _lookup_doc_font_unicode_map(font_unicode_maps, font_name)
                if not cff_gid_to_unicode:
                    cff_gid_to_unicode = _lookup_doc_font_unicode_map(font_unicode_maps, clean_name)
                try:
                    otf_data = cff_raw_to_otf(
                        content,
                        font_name=clean_name,
                        css_weight=int(css_weight),
                        css_stretch=css_stretch,
                        gid_to_unicode=cff_gid_to_unicode or None,
                    )
                    otf_filename = f"{safe_name}.otf"
                    otf_filepath = os.path.join(output_dir, otf_filename)
                    with open(otf_filepath, 'wb') as f_otf:
                        f_otf.write(otf_data)
                    actual_ext = 'otf'
                    actual_path = otf_filepath
                    web_path = f"/fonts/runtime-extracted/{document_id}/{otf_filename}"
                    print(f"    ↳ Wrapped CFF → OTF: {otf_filename}")
                except Exception as cff_err:
                    print(f"    ⚠ CFF→OTF conversion failed for {clean_name}: {cff_err}")

            embedded_fonts[clean_name] = {
                'pdf_font_name': font_name,
                'clean_name': clean_name,
                'family': family_base,
                'css_weight': css_weight,
                'css_style': css_style,
                'css_stretch': css_stretch,
                'file_path': web_path,
                'file_ext': actual_ext,
                'xref': xref,
            }

            print(f"  📋 Extracted font: {clean_name} → {filename} (weight={css_weight}, style={css_style}, stretch={css_stretch})")

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


def _build_texttrace_char_records(page):
    records = []
    try:
        traces = page.get_texttrace() or []
    except Exception:
        return records

    for tr in traces:
        chars = tr.get('chars') or []
        trace_font = tr.get('font', '')
        trace_size = float(tr.get('size') or 0)
        for ch in chars:
            if not ch or len(ch) < 4:
                continue
            codepoint = ch[0]
            bbox = ch[3]
            if codepoint is None or not bbox or len(bbox) < 4:
                continue
            try:
                glyph = chr(codepoint)
            except Exception:
                continue
            glyph = sanitize_extracted_text(glyph)
            if glyph == '':
                continue
            x0, y0, x1, y1 = [float(value) for value in bbox[:4]]
            records.append({
                'text': glyph,
                'font': trace_font,
                'size': trace_size,
                'bbox': (x0, y0, x1, y1),
                'center_x': (x0 + x1) / 2.0,
                'center_y': (y0 + y1) / 2.0,
                'origin': ch[2] if len(ch) > 2 and ch[2] else None,
            })
    return records


def _collect_widget_rects(page):
    rects = []
    try:
        widgets = page.widgets() or []
    except Exception:
        return rects

    for widget in widgets:
        try:
            rect = widget.rect
        except Exception:
            continue
        if not rect:
            continue
        try:
            rects.append((float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1)))
        except Exception:
            continue
    return rects


# Map Wingdings/Symbol PUA codepoints to their Unicode semantic equivalents.
# Wingdings encodes glyphs at U+F000 + ASCII code. Only map visually meaningful
# characters that appear in passport/government forms.
_SYMBOL_PUA_TO_UNICODE = {
    '\uf0fc': '\u2713',  # Wingdings 0xFC → ✓ CHECK MARK
    '\uf0fb': '\u2714',  # Wingdings 0xFB → ✔ HEAVY CHECK MARK
    '\uf0fe': '\u2611',  # Wingdings 0xFE → ☑ BALLOT BOX WITH CHECK
    '\uf0fd': '\u2612',  # Wingdings 0xFD → ☒ BALLOT BOX WITH X
    '\uf0e7': '\u2718',  # Wingdings 0xE7 → ✘ HEAVY BALLOT X
    '\uf0e8': '\u2717',  # Wingdings 0xE8 → ✗ BALLOT X
    '\uf06e': '\u25cf',  # Wingdings 0x6E → ● BLACK CIRCLE (bullet)
    '\uf0a7': '\u2022',  # Wingdings 0xA7 → • BULLET
    '\uf0b7': '\u2022',  # Symbol/Wingdings bullet variant
}

_SYMBOL_FONT_TOKENS = ('wingdings', 'symbol', 'dingbat', 'zapfdingbat', 'webdings')


def _collect_symbol_char_spans(page):
    """
    Collect individual characters from symbol/dingbat fonts (Wingdings, etc.)
    that are stripped by sanitize_extracted_text due to being in the PUA range.
    Returns a list of dicts with keys: x, y, x1, y1, char, font, font_size, color.
    Coordinates are in PDF/fitz page space (y increasing downward).
    """
    result = []
    try:
        text_data = page.get_text("rawdict", flags=fitz.TEXT_PRESERVE_WHITESPACE)
    except Exception:
        return result

    for block in text_data.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                font_name = (span.get("font") or "").lower()
                if not any(tok in font_name for tok in _SYMBOL_FONT_TOKENS):
                    continue
                for char in span.get("chars", []):
                    c = char.get("c", "")
                    if not c:
                        continue
                    mapped = _SYMBOL_PUA_TO_UNICODE.get(c)
                    if not mapped:
                        continue
                    bbox = char.get("bbox")
                    if not bbox or len(bbox) < 4:
                        continue
                    x0, y0, x1, y1 = (
                        float(bbox[0]), float(bbox[1]),
                        float(bbox[2]), float(bbox[3]),
                    )
                    result.append({
                        "x":         x0,
                        "y":         y0,
                        "x1":        x1,
                        "y1":        y1,
                        "char":      mapped,
                        "font":      span.get("font", ""),
                        "font_size": float(span.get("size") or 10),
                        "color":     int(span.get("color") or 0),
                    })
    return result


def _collect_filled_text_background_rects(page):
    """Collect opaque filled rectangles large enough to serve as a *background
    region* behind one or more lines of text (e.g. colored section-header bars,
    note callouts, table-row shading).

    Returned tuples are ``(x0, y0, x1, y1, fill_color_int)`` where
    ``fill_color_int`` is a 24-bit sRGB integer. Used by
    :func:`_split_group_by_filled_background` to treat lines sitting on a
    distinctly-colored background as a separate paragraph, matching how
    Acrobat's reflow engine segments colored callouts.
    """
    rects = []
    try:
        drawings = page.get_drawings() or []
    except Exception:
        return rects

    page_rect = getattr(page, 'rect', None)
    try:
        page_area = float(page_rect.width) * float(page_rect.height) if page_rect else 0.0
    except Exception:
        page_area = 0.0

    for drawing in drawings:
        rect = drawing.get('rect')
        fill = drawing.get('fill')
        fill_opacity = drawing.get('fill_opacity')
        if rect is None or fill is None:
            continue
        try:
            if float(fill_opacity if fill_opacity is not None else 1.0) < 0.9:
                continue
        except Exception:
            continue
        try:
            x0 = float(rect.x0)
            y0 = float(rect.y0)
            x1 = float(rect.x1)
            y1 = float(rect.y1)
        except Exception:
            continue
        width = abs(x1 - x0)
        height = abs(y1 - y0)
        # Skip tiny shapes (checkbox outlines, dots) and whole-page bleeds
        # (watermarks / full-page backgrounds that would swallow every line).
        if width < 20.0 or height < 6.0:
            continue
        area = width * height
        if page_area > 0 and area >= 0.85 * page_area:
            continue

        if isinstance(fill, (tuple, list)) and len(fill) >= 3:
            try:
                r = max(0, min(255, int(round(float(fill[0]) * 255))))
                g = max(0, min(255, int(round(float(fill[1]) * 255))))
                b = max(0, min(255, int(round(float(fill[2]) * 255))))
            except (TypeError, ValueError):
                continue
            fill_int = (r << 16) | (g << 8) | b
        else:
            try:
                fill_int = int(fill) & 0xFFFFFF
            except (TypeError, ValueError):
                continue

        rects.append((
            min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1), fill_int
        ))

    return rects


def _line_bbox_on_filled_background(line_bbox, filled_rects):
    """Return the fill-color integer of the first background rect that mostly
    contains ``line_bbox`` (≥ 70% of the line's width and vertical midline
    falls inside the rect). ``None`` if no background rect applies.
    """
    if not filled_rects or not line_bbox or len(line_bbox) < 4:
        return None
    try:
        lx0, ly0, lx1, ly1 = (float(v) for v in line_bbox[:4])
    except (TypeError, ValueError):
        return None
    line_width = max(0.0, lx1 - lx0)
    if line_width <= 0:
        return None
    mid_y = (ly0 + ly1) / 2.0
    best = None
    best_area = 0.0
    for rect in filled_rects:
        if len(rect) < 5:
            continue
        rx0, ry0, rx1, ry1, fill_int = rect[0], rect[1], rect[2], rect[3], rect[4]
        if mid_y < ry0 or mid_y > ry1:
            continue
        overlap = max(0.0, min(lx1, rx1) - max(lx0, rx0))
        if overlap < 0.7 * line_width:
            continue
        area = (rx1 - rx0) * (ry1 - ry0)
        if area > best_area:
            best = fill_int
            best_area = area
    return best


def _collect_drawn_box_rects(page):
    """Collect small drawn rectangle outlines such as checkbox boxes."""
    rects = []
    try:
        drawings = page.get_drawings() or []
    except Exception:
        return rects

    for drawing in drawings:
        width = float(drawing.get("width") or 0)
        for item in drawing.get("items", []):
            if not item or item[0] != "re":
                continue
            rect = item[1]
            if rect is None:
                continue
            try:
                x0 = float(rect.x0)
                y0 = float(rect.y0)
                x1 = float(rect.x1)
                y1 = float(rect.y1)
            except Exception:
                continue
            box_width = abs(x1 - x0)
            box_height = abs(y1 - y0)
            if box_width < 4.0 or box_height < 4.0:
                continue
            if box_width > 24.0 or box_height > 24.0:
                continue
            aspect_ratio = box_width / max(1.0, box_height)
            if aspect_ratio < 0.45 or aspect_ratio > 2.2:
                continue
            rects.append((min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1), width))
    return rects


def _point_in_rect(x, y, rect, padding=0.0):
    if not rect or len(rect) < 4:
        return False
    return (
        x >= (float(rect[0]) - padding)
        and x <= (float(rect[2]) + padding)
        and y >= (float(rect[1]) - padding)
        and y <= (float(rect[3]) + padding)
    )


def _rect_intersects_widget(rect, widget_rects, padding=0.0):
    if not rect or len(rect) < 4 or not widget_rects:
        return False
    x0, y0, x1, y1 = [float(value) for value in rect[:4]]
    for widget_rect in widget_rects:
        wx0, wy0, wx1, wy1 = [float(value) for value in widget_rect[:4]]
        if x1 < (wx0 - padding) or x0 > (wx1 + padding) or y1 < (wy0 - padding) or y0 > (wy1 + padding):
            continue
        return True
    return False


def _best_intersecting_widget_rect(rect, widget_rects, padding=0.0):
    if not rect or len(rect) < 4 or not widget_rects:
        return None

    x0, y0, x1, y1 = [float(value) for value in rect[:4]]
    best_rect = None
    best_overlap = 0.0
    for widget_rect in widget_rects:
        wx0, wy0, wx1, wy1 = [float(value) for value in widget_rect[:4]]
        if x1 < (wx0 - padding) or x0 > (wx1 + padding) or y1 < (wy0 - padding) or y0 > (wy1 + padding):
            continue
        overlap = _rect_intersection_area((x0, y0, x1, y1), (wx0, wy0, wx1, wy1))
        if overlap > best_overlap:
            best_overlap = overlap
            best_rect = (wx0, wy0, wx1, wy1)
    return best_rect


def _should_skip_widget_intersecting_span(text, rect, widget_rects):
    widget_rect = _best_intersecting_widget_rect(rect, widget_rects, padding=0.35)
    if not widget_rect:
        return False

    x0, y0, x1, y1 = [float(value) for value in rect[:4]]
    wx0, wy0, wx1, wy1 = widget_rect
    span_area = max(1.0, (x1 - x0) * (y1 - y0))
    widget_width = max(0.0, wx1 - wx0)
    widget_height = max(0.0, wy1 - wy0)
    overlap_area = _rect_intersection_area((x0, y0, x1, y1), widget_rect)
    overlap_ratio = overlap_area / span_area

    normalized_text = sanitize_extracted_text(text or '')
    compact_text = re.sub(r'\s+', ' ', normalized_text).strip()
    word_count = len(compact_text.split()) if compact_text else 0

    # Real document text is more important than avoiding a phantom widget artifact.
    # Preserve URL-like spans even when a broad widget rect overlaps them. These
    # broad rects can come from hidden widgets or malformed form metadata and
    # previously caused content loss in inline printed URLs.
    if compact_text:
        if _EXTRACTION_URLISH_RE.search(compact_text):
            return False

    # Only suppress when the widget covers essentially the whole span and the span
    # still looks like compact field/widget text rather than authored body copy.
    if overlap_ratio < 0.92:
        return False

    if widget_width <= 96.0 or widget_height >= 14.0:
        return True

    return len(compact_text) <= 12


def _line_expects_inline_link_text(line_text):
    compact_text = re.sub(r'\s+', ' ', sanitize_extracted_text(line_text or '')).strip()
    if not compact_text:
        return False
    compact_text = compact_text.rstrip(' .,:;')
    return bool(re.search(r'(?:\b(?:at|visit|see|go to|online at|available at|found at))$', compact_text, re.IGNORECASE))


def _build_missing_link_span_for_line(
    line_text,
    line_bbox,
    line_spans,
    line_style,
    link_regions,
    used_link_region_ids,
):
    if not _line_expects_inline_link_text(line_text):
        return None
    if not line_bbox or len(line_bbox) < 4:
        return None
    if any(bool(span.get('is_link')) for span in (line_spans or []) if isinstance(span, dict)):
        return None

    line_rect = tuple(float(value) for value in line_bbox[:4])
    best_region = None
    best_score = None

    for link_region in link_regions or []:
        region_id = link_region.get('index')
        if region_id in (used_link_region_ids or set()):
            continue
        display_text = _link_region_display_text(link_region)
        if not _EXTRACTION_URLISH_RE.search(display_text or ''):
            continue

        rect = link_region.get('rect')
        if rect is None:
            continue
        link_rect = (float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1))
        vertical_overlap = max(
            0.0,
            min(line_rect[3], link_rect[3]) - max(line_rect[1], link_rect[1]),
        )
        min_height = max(1.0, min(line_rect[3] - line_rect[1], link_rect[3] - link_rect[1]))
        overlap_ratio = vertical_overlap / min_height
        if overlap_ratio < 0.45:
            continue

        intersects_existing_span = False
        for span in line_spans or []:
            span_bbox = span.get('bbox') if isinstance(span, dict) else None
            if not isinstance(span_bbox, (list, tuple)) or len(span_bbox) < 4:
                continue
            normalized_span_bbox = tuple(float(value) for value in span_bbox[:4])
            overlap_area = _rect_intersection_area(normalized_span_bbox, link_rect)
            if overlap_area <= 0:
                continue
            span_area = max(
                1.0,
                (normalized_span_bbox[2] - normalized_span_bbox[0]) * (normalized_span_bbox[3] - normalized_span_bbox[1]),
            )
            link_area = max(1.0, (link_rect[2] - link_rect[0]) * (link_rect[3] - link_rect[1]))
            overlap_ratio = overlap_area / min(span_area, link_area)
            if overlap_ratio >= 0.35:
                intersects_existing_span = True
                break
        if intersects_existing_span:
            continue

        # Prefer links that start inside the current line bounds and nearest to the
        # existing trailing text. This catches the common "found at <url>" layout.
        score = abs(link_rect[1] - line_rect[1]) * 10.0 + max(0.0, link_rect[0] - line_rect[2])
        if best_score is None or score < best_score:
            best_score = score
            best_region = link_region

    if not best_region:
        return None

    rect = best_region['rect']
    bbox = [float(rect.x0), float(rect.y0), float(rect.x1), float(rect.y1)]
    base_style = dict(line_style or {})
    font_name = str(base_style.get('font') or '')
    font_size = float(base_style.get('font_size') or 0) or max(1.0, bbox[3] - bbox[1])
    font_weight = base_style.get('font_weight')
    if font_weight is None:
        font_weight = '700' if base_style.get('bold') else '400'
    display_text = _link_region_display_text(best_region)
    return {
        'text': display_text,
        'render_text': display_text,
        'suppress_drawn_underline': False,
        'has_drawn_underline': False,
        'font': font_name,
        'font_xref': base_style.get('font_xref'),
        'font_size': font_size,
        'font_weight': font_weight,
        'color': base_style.get('color', 0),
        'hex_color': base_style.get('hex_color', '#0000EE'),
        'bold': bool(base_style.get('bold')),
        'italic': bool(base_style.get('italic')),
        'flags': 0,
        'bbox': bbox,
        'ascender': base_style.get('ascender'),
        'descender': base_style.get('descender'),
        'origin': [bbox[0], bbox[3]],
        'direction': base_style.get('direction'),
        'writing_mode': int(base_style.get('writing_mode', 0) or 0),
        'rotation': float(base_style.get('rotation', 0) or 0),
        'line_width': None,
        'render_type': None,
        'space_width': None,
        'source_content_ops': [],
        'uses_embedded_font': bool(base_style.get('uses_embedded_font')),
        'embedded_font_name': base_style.get('embedded_font_name'),
        'embedded_font_family': base_style.get('embedded_font_family'),
        'embedded_font_xref': base_style.get('embedded_font_xref'),
        'is_link': True,
        'link_uri': best_region.get('uri') or None,
        'link_kind': best_region.get('kind') or None,
        'link_page': best_region.get('page'),
        '_synthetic_missing_link': True,
        '_link_region_index': best_region.get('index'),
    }


def _rects_have_drawn_box_barrier(left_rect, right_rect, drawn_box_rects):
    if not left_rect or not right_rect or not drawn_box_rects:
        return False

    a = tuple(float(value) for value in left_rect[:4])
    b = tuple(float(value) for value in right_rect[:4])
    if a[0] > b[0]:
        a, b = b, a

    gap_left = min(a[2], b[0])
    gap_right = max(a[2], b[0])
    if gap_right < gap_left:
        gap_left, gap_right = gap_right, gap_left

    band_top = min(a[1], b[1]) - 2.0
    band_bottom = max(a[3], b[3]) + 2.0

    for box_rect in drawn_box_rects:
        bx0, by0, bx1, by1 = [float(value) for value in box_rect[:4]]
        box_center_x = (bx0 + bx1) / 2.0
        if box_center_x < (gap_left - 1.0) or box_center_x > (gap_right + 1.0):
            continue
        overlap = max(0.0, min(band_bottom, by1) - max(band_top, by0))
        if overlap >= max(4.0, min(by1 - by0, band_bottom - band_top) * 0.45):
            return True
    return False


def _char_hits_widget(char_record, widget_rects):
    if not widget_rects:
        return False
    cx = float(char_record.get('center_x') or 0)
    cy = float(char_record.get('center_y') or 0)
    for rect in widget_rects:
        if _point_in_rect(cx, cy, rect, padding=0.45):
            return True
    return False


def _compact_duplicate_compare_text(text):
    return re.sub(r'\s+', '', sanitize_extracted_text(text or '')).casefold()


def _trace_text_can_replace_original_text(original_text, rebuilt_text):
    original = sanitize_extracted_text(original_text or '')
    rebuilt = sanitize_extracted_text(rebuilt_text or '')
    if not original or not rebuilt:
        return False

    if original == rebuilt:
        return True

    original_compact = _compact_duplicate_compare_text(original)
    rebuilt_compact = _compact_duplicate_compare_text(rebuilt)
    if not original_compact or original_compact != rebuilt_compact:
        return False

    original_space_runs = re.findall(r'\s+', original)
    rebuilt_space_runs = re.findall(r'\s+', rebuilt)
    if len(rebuilt_space_runs) >= len(original_space_runs):
        return True

    # Allow texttrace to remove a single suspicious intra-word gap like
    # "w aives", but do not let it collapse genuine sentence spacing.
    if len(original_space_runs) == 1 and not rebuilt_space_runs:
        parts = original.split()
        if (
            len(parts) == 2
            and all(re.fullmatch(r'[A-Za-z]+', part or '') for part in parts)
            and min(len(parts[0]), len(parts[1])) <= 2
        ):
            return True

    return False


def _entry_bbox(entry, bbox_key='bbox', left_key='left', top_key='top', width_key='width', height_key='height'):
    if not isinstance(entry, dict):
        return None

    bbox = entry.get(bbox_key)
    if isinstance(bbox, (list, tuple)) and len(bbox) >= 4:
        try:
            return tuple(float(value) for value in bbox[:4])
        except Exception:
            return None

    try:
        left = float(entry.get(left_key))
        top = float(entry.get(top_key))
        width = float(entry.get(width_key))
        height = float(entry.get(height_key))
    except Exception:
        return None

    return (left, top, left + width, top + height)


def _rect_overlap_ratio(a, b):
    if not a or not b:
        return 0.0

    overlap_w = max(0.0, min(float(a[2]), float(b[2])) - max(float(a[0]), float(b[0])))
    overlap_h = max(0.0, min(float(a[3]), float(b[3])) - max(float(a[1]), float(b[1])))
    if overlap_w <= 0.0 or overlap_h <= 0.0:
        return 0.0

    overlap_area = overlap_w * overlap_h
    area_a = max(0.01, (float(a[2]) - float(a[0])) * (float(a[3]) - float(a[1])))
    area_b = max(0.01, (float(b[2]) - float(b[0])) * (float(b[3]) - float(b[1])))
    return overlap_area / min(area_a, area_b)


def _text_entries_are_near_duplicate_layers(
    first,
    second,
    *,
    text_key='text',
    bbox_key='bbox',
    left_key='left',
    top_key='top',
    width_key='width',
    height_key='height',
    font_key='font',
    font_size_key='font_size',
):
    if not isinstance(first, dict) or not isinstance(second, dict):
        return False

    first_text = _compact_duplicate_compare_text(first.get(text_key, ''))
    second_text = _compact_duplicate_compare_text(second.get(text_key, ''))
    if not first_text or not second_text:
        return False

    shorter, longer = sorted((first_text, second_text), key=len)
    texts_match = first_text == second_text
    if not texts_match:
        if len(shorter) < 3:
            return False
        texts_match = longer.startswith(shorter) and (len(longer) - len(shorter)) <= 2
        if not texts_match:
            return False

    if font_key:
        first_font = _normalize_font_name(first.get(font_key))
        second_font = _normalize_font_name(second.get(font_key))
        if first_font and second_font and first_font != second_font:
            return False

    if font_size_key:
        try:
            first_size = float(first.get(font_size_key) or 0)
            second_size = float(second.get(font_size_key) or 0)
        except Exception:
            first_size = 0.0
            second_size = 0.0
        if first_size > 0 and second_size > 0:
            if abs(first_size - second_size) > max(0.75, min(first_size, second_size) * 0.08):
                return False

    first_bbox = _entry_bbox(
        first,
        bbox_key=bbox_key,
        left_key=left_key,
        top_key=top_key,
        width_key=width_key,
        height_key=height_key,
    )
    second_bbox = _entry_bbox(
        second,
        bbox_key=bbox_key,
        left_key=left_key,
        top_key=top_key,
        width_key=width_key,
        height_key=height_key,
    )
    if not first_bbox or not second_bbox:
        return False

    if abs(first_bbox[0] - second_bbox[0]) > 1.25 or abs(first_bbox[1] - second_bbox[1]) > 1.25:
        return False

    return _rect_overlap_ratio(first_bbox, second_bbox) >= 0.72


def _prefer_richer_duplicate_text_entry(
    candidate,
    existing,
    *,
    text_key='text',
    bbox_key='bbox',
    left_key='left',
    top_key='top',
    width_key='width',
    height_key='height',
):
    candidate_text = _compact_duplicate_compare_text(candidate.get(text_key, ''))
    existing_text = _compact_duplicate_compare_text(existing.get(text_key, ''))
    if len(candidate_text) != len(existing_text):
        return len(candidate_text) > len(existing_text)

    candidate_raw_text = sanitize_extracted_text(candidate.get(text_key, '') or '')
    existing_raw_text = sanitize_extracted_text(existing.get(text_key, '') or '')
    candidate_space_count = len(re.findall(r'\s', candidate_raw_text))
    existing_space_count = len(re.findall(r'\s', existing_raw_text))
    if candidate_space_count != existing_space_count:
        return candidate_space_count > existing_space_count

    candidate_bbox = _entry_bbox(
        candidate,
        bbox_key=bbox_key,
        left_key=left_key,
        top_key=top_key,
        width_key=width_key,
        height_key=height_key,
    )
    existing_bbox = _entry_bbox(
        existing,
        bbox_key=bbox_key,
        left_key=left_key,
        top_key=top_key,
        width_key=width_key,
        height_key=height_key,
    )
    if candidate_bbox and existing_bbox:
        candidate_area = max(0.0, (candidate_bbox[2] - candidate_bbox[0]) * (candidate_bbox[3] - candidate_bbox[1]))
        existing_area = max(0.0, (existing_bbox[2] - existing_bbox[0]) * (existing_bbox[3] - existing_bbox[1]))
        if abs(candidate_area - existing_area) > 0.01:
            return candidate_area > existing_area

    return False


def _dedupe_near_duplicate_text_entries(
    entries,
    *,
    text_key='text',
    bbox_key='bbox',
    left_key='left',
    top_key='top',
    width_key='width',
    height_key='height',
    font_key='font',
    font_size_key='font_size',
):
    deduped = []
    for entry in entries or []:
        if not isinstance(entry, dict):
            deduped.append(entry)
            continue

        duplicate_index = -1
        for index, existing in enumerate(deduped):
            if not isinstance(existing, dict):
                continue
            if _text_entries_are_near_duplicate_layers(
                existing,
                entry,
                text_key=text_key,
                bbox_key=bbox_key,
                left_key=left_key,
                top_key=top_key,
                width_key=width_key,
                height_key=height_key,
                font_key=font_key,
                font_size_key=font_size_key,
            ):
                duplicate_index = index
                break

        if duplicate_index < 0:
            deduped.append(entry)
            continue

        if _prefer_richer_duplicate_text_entry(
            entry,
            deduped[duplicate_index],
            text_key=text_key,
            bbox_key=bbox_key,
            left_key=left_key,
            top_key=top_key,
            width_key=width_key,
            height_key=height_key,
        ):
            deduped[duplicate_index] = entry

    return deduped


def _dedupe_near_overlapping_trace_chars(records, x_eps=0.75, y_eps=0.75):
    if len(records) < 2:
        return records

    deduped = []
    for record in records:
        if not deduped:
            deduped.append(record)
            continue

        previous = deduped[-1]
        if record.get('text') != previous.get('text'):
            deduped.append(record)
            continue

        cx = float(record.get('center_x') or 0.0)
        cy = float(record.get('center_y') or 0.0)
        prev_cx = float(previous.get('center_x') or 0.0)
        prev_cy = float(previous.get('center_y') or 0.0)
        if abs(cx - prev_cx) > x_eps or abs(cy - prev_cy) > y_eps:
            deduped.append(record)
            continue

        record_bbox = record.get('bbox')
        previous_bbox = previous.get('bbox')
        record_area = 0.0
        previous_area = 0.0
        if record_bbox and len(record_bbox) >= 4:
            record_area = max(0.0, (float(record_bbox[2]) - float(record_bbox[0])) * (float(record_bbox[3]) - float(record_bbox[1])))
        if previous_bbox and len(previous_bbox) >= 4:
            previous_area = max(0.0, (float(previous_bbox[2]) - float(previous_bbox[0])) * (float(previous_bbox[3]) - float(previous_bbox[1])))
        if record_area and previous_area and record_area < previous_area:
            deduped[-1] = record

    return deduped


def _rebuild_visible_text_from_trace_bbox(trace_chars, bbox, font, size, widget_rects=None):
    if not trace_chars or not bbox or len(bbox) < 4:
        return None

    try:
        x0, y0, x1, y1 = [float(value) for value in bbox[:4]]
    except Exception:
        return None

    if x1 <= x0 or y1 <= y0:
        return None

    pad_x = 0.75
    pad_y = 1.25
    target_font = _normalize_font_name(font)
    target_size = float(size or 0)
    candidates = []

    for record in trace_chars:
        char_bbox = record.get('bbox')
        if not char_bbox:
            continue
        cx = float(record.get('center_x') or 0)
        cy = float(record.get('center_y') or 0)
        if cx < (x0 - pad_x) or cx > (x1 + pad_x) or cy < (y0 - pad_y) or cy > (y1 + pad_y):
            continue

        if target_font:
            record_font = _normalize_font_name(record.get('font'))
            if record_font and record_font != target_font:
                continue

        if target_size > 0:
            record_size = float(record.get('size') or 0)
            if record_size > 0 and abs(record_size - target_size) > max(1.25, target_size * 0.18):
                continue

        if _char_hits_widget(record, widget_rects or []):
            continue

        candidates.append(record)

    if not candidates:
        return None

    candidates.sort(key=lambda record: (
        round(float(record.get('center_y') or 0), 1),
        round(float(record.get('center_x') or 0), 1),
    ))
    candidates = _dedupe_near_overlapping_trace_chars(candidates)
    rebuilt = sanitize_extracted_text(''.join(record.get('text', '') for record in candidates))
    if rebuilt == '':
        return None

    rebuilt_x0 = min(float(record['bbox'][0]) for record in candidates)
    rebuilt_y0 = min(float(record['bbox'][1]) for record in candidates)
    rebuilt_x1 = max(float(record['bbox'][2]) for record in candidates)
    rebuilt_y1 = max(float(record['bbox'][3]) for record in candidates)
    rebuilt_origin = next((record.get('origin') for record in candidates if record.get('origin')), None)
    return {
        'text': rebuilt,
        'bbox': (rebuilt_x0, rebuilt_y0, rebuilt_x1, rebuilt_y1),
        'origin': rebuilt_origin,
    }


def _dedupe_block_text_lines(block):
    if not isinstance(block, dict):
        return block

    text_lines = [
        sanitize_extracted_text(line)
        for line in (block.get('text_lines') or [])
        if sanitize_extracted_text(line)
    ]
    line_bboxes = [
        tuple(float(value) for value in bbox[:4])
        for bbox in (block.get('line_bboxes') or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if not text_lines or not line_bboxes:
        return block

    entries = []
    for index, text in enumerate(text_lines):
        bbox = line_bboxes[index] if index < len(line_bboxes) else None
        if not bbox:
            continue
        entries.append({
            '_index': index,
            'text': text,
            'bbox': bbox,
        })

    deduped_entries = _dedupe_near_duplicate_text_entries(
        entries,
        text_key='text',
        bbox_key='bbox',
        font_key=None,
        font_size_key=None,
    )
    deduped_entries.sort(key=lambda entry: entry.get('_index', 0))
    if len(deduped_entries) == len(entries):
        return block

    deduped_lines = [entry['text'] for entry in deduped_entries]
    deduped_bboxes = [list(entry['bbox']) for entry in deduped_entries]

    block['text_lines'] = deduped_lines
    block['line_bboxes'] = deduped_bboxes
    block['text'] = '\n'.join(deduped_lines)
    block['text_single_line'] = ' '.join(deduped_lines)
    block['line_count'] = len(deduped_lines)
    if deduped_bboxes:
        block['left'] = min(bbox[0] for bbox in deduped_bboxes)
        block['top'] = min(bbox[1] for bbox in deduped_bboxes)
        right = max(bbox[2] for bbox in deduped_bboxes)
        bottom = max(bbox[3] for bbox in deduped_bboxes)
        block['width'] = right - block['left']
        block['height'] = bottom - block['top']
        heights = [max(0.0, bbox[3] - bbox[1]) for bbox in deduped_bboxes]
        if heights:
            block['avg_line_height'] = sum(heights) / len(heights)

    spans = block.get('spans')
    if isinstance(spans, list):
        block['spans'] = _dedupe_near_duplicate_text_entries(
            spans,
            text_key='text',
            bbox_key='bbox',
            font_key='font',
            font_size_key='font_size',
        )

    return block


def _select_block_spans_for_line_bboxes(spans, line_bboxes):
    selected_spans = []
    for span in spans or []:
        span_bbox = span.get('bbox')
        if not isinstance(span_bbox, (list, tuple)) or len(span_bbox) < 4:
            continue
        center_x = (float(span_bbox[0]) + float(span_bbox[2])) / 2.0
        center_y = (float(span_bbox[1]) + float(span_bbox[3])) / 2.0
        for line_bbox in line_bboxes:
            if _point_in_rect(center_x, center_y, line_bbox, padding=1.0):
                selected_spans.append(span)
                break
    return selected_spans


def _line_bbox_group_union(line_bboxes):
    group_bbox = None
    for line_bbox in line_bboxes or []:
        group_bbox = _rect_union(group_bbox, line_bbox)
    return group_bbox


def _build_split_blocks_from_line_segments(block, text_lines, line_bboxes, segments):
    split_blocks = []
    base_fields = dict(block)
    for key in (
        'text',
        'text_single_line',
        'text_lines',
        'line_bboxes',
        'line_count',
        'left',
        'top',
        'width',
        'height',
        'avg_line_height',
        'line_height',
        'spans',
        'source_content_ops',
    ):
        base_fields.pop(key, None)

    for segment in segments:
        segment_lines = [text_lines[index] for index in segment]
        segment_line_bboxes = [line_bboxes[index] for index in segment]
        segment_bbox = _line_bbox_group_union(segment_line_bboxes)
        if not segment_bbox:
            continue

        segment_spans = _select_block_spans_for_line_bboxes(block.get('spans') or [], segment_line_bboxes)
        segment_heights = [max(0.0, bbox[3] - bbox[1]) for bbox in segment_line_bboxes]
        segment_block = dict(base_fields)
        segment_block['text_lines'] = segment_lines
        segment_block['line_bboxes'] = [list(bbox) for bbox in segment_line_bboxes]
        segment_block['text'] = '\n'.join(segment_lines)
        segment_block['text_single_line'] = ' '.join(segment_lines)
        segment_block['line_count'] = len(segment_lines)
        segment_block['left'] = float(segment_bbox[0])
        segment_block['top'] = float(segment_bbox[1])
        segment_block['width'] = float(segment_bbox[2]) - float(segment_bbox[0])
        segment_block['height'] = float(segment_bbox[3]) - float(segment_bbox[1])
        if segment_heights:
            avg_line_height = sum(segment_heights) / len(segment_heights)
            segment_block['avg_line_height'] = avg_line_height
            segment_block['line_height'] = avg_line_height
        segment_block['spans'] = segment_spans
        segment_block['source_content_ops'] = []
        segment_block['has_mixed_styles'] = len({
            (
                _normalize_font_name(span.get('font') or ''),
                float(span.get('font_size') or 0),
                bool(span.get('bold')),
                bool(span.get('italic')),
            )
            for span in segment_spans
            if str(span.get('text') or '').strip()
        }) > 1
        split_blocks.append(segment_block)

    return split_blocks or [block]


def _looks_like_section_heading_label(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False

    heading_match = re.match(r'^(?P<label>.+?)\s*:\s*$', normalized)
    if not heading_match:
        return False

    label = heading_match.group('label').strip()
    if len(label) < 4 or len(label) > 72:
        return False
    if any(token in label.lower() for token in ('www.', 'http://', 'https://', '@')):
        return False

    words = re.findall(r"[A-Za-z][A-Za-z'&/-]*", label)
    if not words or len(words) > 10:
        return False

    stop_words = {'a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with', 'your'}
    significant_words = [word for word in words if word.lower() not in stop_words]
    if not significant_words:
        return False

    return all(word[:1].isupper() for word in significant_words)


def _split_block_on_horizontal_barriers(block, horizontal_lines=None):
    if not isinstance(block, dict):
        return [block] if block else []

    text_lines = [
        sanitize_extracted_text(line)
        for line in (block.get('text_lines') or [])
    ]
    line_bboxes = [
        tuple(float(value) for value in bbox[:4])
        for bbox in (block.get('line_bboxes') or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if len(text_lines) < 2 or len(text_lines) != len(line_bboxes) or not horizontal_lines:
        return [block]

    line_heights = [max(0.0, bbox[3] - bbox[1]) for bbox in line_bboxes]
    typical_line_height = statistics.median(line_heights) if line_heights else 0.0
    cap_height = max(8.0, typical_line_height * 1.35) if typical_line_height > 0 else 12.0

    def _effective_line_rect(line_bbox, position):
        x0, y0, x1, y1 = line_bbox
        if position == 'upper':
            return (x0, y0, x1, min(y1, y0 + cap_height))
        return (x0, max(y0, y1 - cap_height), x1, y1)

    segments = []
    current_segment = [0]
    split_detected = False

    for index in range(1, len(text_lines)):
        if _rects_have_horizontal_barrier(
            _effective_line_rect(line_bboxes[index - 1], 'upper'),
            _effective_line_rect(line_bboxes[index], 'lower'),
            horizontal_lines,
        ):
            segments.append(current_segment)
            current_segment = [index]
            split_detected = True
        else:
            current_segment.append(index)

    if current_segment:
        segments.append(current_segment)

    if not split_detected or len(segments) <= 1:
        return [block]

    return _build_split_blocks_from_line_segments(block, text_lines, line_bboxes, segments)


def _split_block_on_large_vertical_gaps(block):
    """Split a block when consecutive line bboxes have a vertical gap that is
    much larger than the block's typical line height. PyMuPDF occasionally
    groups text from spatially distant content-stream operations into a single
    block (e.g. the trailing line of one paragraph plus list-item text far
    below it, while a heading and body paragraph between them are emitted as
    other blocks). The result is one annotation whose bounding box spans
    several visually separate paragraphs. We split such a block at every gap
    that exceeds a conservative threshold so each visual paragraph becomes
    its own block."""
    if not isinstance(block, dict):
        return [block] if block else []

    text_lines = [
        sanitize_extracted_text(line)
        for line in (block.get('text_lines') or [])
    ]
    line_bboxes = [
        tuple(float(value) for value in bbox[:4])
        for bbox in (block.get('line_bboxes') or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if len(text_lines) < 2 or len(text_lines) != len(line_bboxes):
        return [block]

    line_heights = [max(0.0, bbox[3] - bbox[1]) for bbox in line_bboxes]
    typical_line_height = statistics.median(line_heights) if line_heights else 0.0
    if typical_line_height <= 0:
        return [block]
    # Two consecutive lines in the same paragraph are typically <= ~1.6x line
    # height apart (top-to-top). A gap (bottom-of-prev to top-of-next) larger
    # than ~2.5x typical line height is essentially never wrapped paragraph
    # leading and signals the block has absorbed unrelated text.
    gap_threshold = max(typical_line_height * 2.5, 24.0)

    segments = []
    current_segment = [0]
    split_detected = False

    for index in range(1, len(line_bboxes)):
        prev_bottom = line_bboxes[index - 1][3]
        next_top = line_bboxes[index][1]
        vertical_gap = next_top - prev_bottom
        if vertical_gap > gap_threshold:
            segments.append(current_segment)
            current_segment = [index]
            split_detected = True
        else:
            current_segment.append(index)

    if current_segment:
        segments.append(current_segment)

    if not split_detected or len(segments) <= 1:
        return [block]

    return _build_split_blocks_from_line_segments(block, text_lines, line_bboxes, segments)


def _split_block_for_standalone_list_markers_and_callouts(block, horizontal_lines=None):
    if not isinstance(block, dict):
        return [block] if block else []

    text_lines = [
        sanitize_extracted_text(line)
        for line in (block.get('text_lines') or [])
    ]
    line_bboxes = [
        tuple(float(value) for value in bbox[:4])
        for bbox in (block.get('line_bboxes') or [])
        if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
    ]
    if len(text_lines) < 2 or len(text_lines) != len(line_bboxes):
        return [block]

    segments = []
    current_segment = []
    split_detected = False

    for index, line_text in enumerate(text_lines):
        if _is_standalone_list_item_marker(line_text) or _looks_like_section_heading_label(line_text):
            if current_segment:
                segments.append(current_segment)
                current_segment = []
            segments.append([index])
            split_detected = True
            continue

        if _starts_with_callout_label(line_text):
            if current_segment:
                segments.append(current_segment)
            current_segment = [index]
            split_detected = True
            continue

        current_segment.append(index)

    if current_segment:
        segments.append(current_segment)

    if not split_detected or len(segments) <= 1:
        return [block]

    return _build_split_blocks_from_line_segments(block, text_lines, line_bboxes, segments)


def _assign_bbox_to_line_bbox_group(entry_bbox, line_bboxes):
    if not entry_bbox or not line_bboxes:
        return False

    center_x = (float(entry_bbox[0]) + float(entry_bbox[2])) / 2.0
    center_y = (float(entry_bbox[1]) + float(entry_bbox[3])) / 2.0
    if any(_point_in_rect(center_x, center_y, bbox, padding=1.0) for bbox in line_bboxes):
        return True

    best_overlap = 0.0
    for line_bbox in line_bboxes:
        best_overlap = max(best_overlap, _rect_overlap_ratio(entry_bbox, line_bbox))
    return best_overlap >= 0.35


def _split_blocks_on_list_marker_callouts(page_blocks, page_words, page_lines, horizontal_lines=None):
    if not page_blocks:
        return page_blocks

    new_blocks = []
    old_to_new = {}
    split_specs = {}

    for block in page_blocks:
        horizontally_split_blocks = _split_block_on_horizontal_barriers(block, horizontal_lines=horizontal_lines)
        split_blocks = []
        for candidate_block in horizontally_split_blocks:
            for vgap_block in _split_block_on_large_vertical_gaps(candidate_block):
                split_blocks.extend(
                    _split_block_for_standalone_list_markers_and_callouts(
                        vgap_block,
                        horizontal_lines=horizontal_lines,
                    )
                )
        if len(split_blocks) <= 1:
            new_block = dict(block)
            new_block['block_num'] = len(new_blocks)
            new_blocks.append(new_block)
            old_to_new[block.get('block_num')] = new_block['block_num']
            continue

        segment_specs = []
        for split_block in split_blocks:
            new_block = dict(split_block)
            new_block['block_num'] = len(new_blocks)
            new_blocks.append(new_block)
            segment_specs.append({
                'block_num': new_block['block_num'],
                'line_bboxes': [
                    tuple(float(value) for value in bbox[:4])
                    for bbox in (new_block.get('line_bboxes') or [])
                    if isinstance(bbox, (list, tuple)) and len(bbox) >= 4
                ],
            })
        split_specs[block.get('block_num')] = segment_specs

    for line in page_lines:
        old_block_num = line.get('block_num')
        if old_block_num in split_specs:
            line_bbox = _entry_bbox(line)
            for spec in split_specs[old_block_num]:
                if _assign_bbox_to_line_bbox_group(line_bbox, spec['line_bboxes']):
                    line['block_num'] = spec['block_num']
                    break
            else:
                line['block_num'] = split_specs[old_block_num][-1]['block_num']
        elif old_block_num in old_to_new:
            line['block_num'] = old_to_new[old_block_num]

    for word in page_words:
        old_block_num = word.get('block_num')
        if old_block_num in split_specs:
            word_bbox = _entry_bbox(word)
            for spec in split_specs[old_block_num]:
                if _assign_bbox_to_line_bbox_group(word_bbox, spec['line_bboxes']):
                    word['block_num'] = spec['block_num']
                    break
            else:
                word['block_num'] = split_specs[old_block_num][-1]['block_num']
        elif old_block_num in old_to_new:
            word['block_num'] = old_to_new[old_block_num]

    return new_blocks


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


def _block_contains_symbol_font(block):
    for span in block.get('spans') or []:
        font_name = _normalize_font_name(span.get('font') or '')
        if any(token in font_name for token in ('europeanpi', 'symbol', 'dingbat', 'zapf')):
            return True
    return False


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


def _merge_same_row_lines(line_items, vertical_lines=None, widget_rects=None, drawn_box_rects=None):
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

        # Don't merge items across real form structure such as field borders/widgets.
        if should_merge:
            row_bbox = (
                min(it['bbox'][0] for it in row),
                min(it['bbox'][1] for it in row),
                max(it['bbox'][2] for it in row),
                max(it['bbox'][3] for it in row),
            )
            item_bbox = item['bbox']
            if _rects_have_vertical_barrier(row_bbox, item_bbox, vertical_lines or []):
                should_merge = False
            elif _rects_have_widget_barrier(row_bbox, item_bbox, widget_rects or []):
                should_merge = False
            elif _rects_have_drawn_box_barrier(row_bbox, item_bbox, drawn_box_rects or []):
                should_merge = False

        # Don't merge items with significantly different dominant text colors.
        # Form "section-label on a colored chip" (e.g. white "Part I" on a black
        # bar) sits on the same visual row as the adjacent body text but must
        # stay a separate annotation so it keeps its own color and its own tight
        # bbox (which the filled-background-rect splitter relies on).
        if should_merge:
            row_color = None
            for existing_item in row:
                row_color = _line_item_dominant_color(existing_item)
                if row_color is not None:
                    break
            item_color = _line_item_dominant_color(item)
            if (
                row_color is not None
                and item_color is not None
                and _colors_differ_significantly(row_color, item_color)
            ):
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
            
            _sequential_ltr = True  # item follows the row in natural L-to-R reading order
            if row_x1 <= item_x0:
                dist = item_x0 - row_x1
                overlap = 0.0
            elif item_x1 <= row_x0:
                dist = row_x0 - item_x1
                overlap = 0.0
                _sequential_ltr = False  # item is to the LEFT of the current row
                if not _looks_like_leading_inline_label(_line_item_text(item)):
                    should_merge = False
            else:
                overlap = min(row_x1, item_x1) - max(row_x0, item_x0)
                dist = -overlap
            
            if dist > 0:
                # Positive gap logic (existing)
                avg_size = max(row_max_size if row_max_size > 0 else 12,
                               item['max_size'] if item['max_size'] > 0 else 12)
                row_text = ''.join(_line_item_text(existing_item) for existing_item in row).strip()
                item_text = _line_item_text(item)
                left_text = row_text
                left_width = max(0.0, row_x1 - row_x0)
                right_text = item_text
                right_width = max(0.0, item_x1 - item_x0)
                # Only apply the detached-row-label check when the item follows the
                # row in natural left-to-right order.  When the item lies to the LEFT
                # of the current row (_sequential_ltr=False) the left_text/right_text
                # assignments are spatially reversed and the heuristic gives false
                # positives – notably it would drop dot-leader characters that happen
                # to be processed after a right-aligned form label.
                if _sequential_ltr:
                    left_looks_like_inline_label = _looks_like_leading_inline_label(left_text)
                    detached_row_label_pair = (
                        _looks_like_detached_row_label(left_text)
                        and left_width <= max(110.0, avg_size * 6.5)
                        and len(right_text) >= 24
                        and right_width >= max(180.0, avg_size * 10.0)
                        and dist >= (
                            max(15.0, avg_size * 1.25)
                            if left_looks_like_inline_label
                            else max(8.0, avg_size * 0.65)
                        )
                    )
                    if detached_row_label_pair:
                        should_merge = False
                # Multi-column form pages (Form 1040-ES etc.) have a vertical
                # gutter ≈ 1.5–2× font height between left and right columns.
                # If we let the threshold reach 2× font size, the gutter exactly
                # matches it and lines from opposite columns at similar y-values
                # get merged into a fake cross-column "row", which then poisons
                # downstream block grouping (one synthetic block ends up
                # containing every line on the page).  Use a tighter threshold
                # and a non-strict comparison so equal-to-threshold gaps still
                # split.  Genuine inline merges (italic span breaks, kerning)
                # have gaps well under 1.5× font size.
                x_gap_threshold = max(avg_size * 1.5, 15)
                if dist >= x_gap_threshold:
                    should_merge = False
            else:
                # Negative gap (overlap)
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
                'bbox': merged_bbox,
                'dir': row_sorted[0].get('dir') or row_sorted[0].get('line', {}).get('dir'),
                'wmode': row_sorted[0].get('wmode', row_sorted[0].get('line', {}).get('wmode', 0)),
            }

            all_sizes = [s.get('size', 0) for s in combined_spans if s.get('text', '')]
            max_size = max(all_sizes) if all_sizes else 0

            merged_items.append({
                'line_num': row_sorted[0]['line_num'],
                'line': merged_line,
                'bbox': merged_bbox,
                'x0': merged_bbox[0],
                'y0': merged_bbox[1],
                'max_size': max_size,
                'dir': row_sorted[0].get('dir'),
                'wmode': row_sorted[0].get('wmode', 0),
                '_from_xgap_split': any(it.get('_from_xgap_split') for it in row_sorted),
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


def _split_line_item_by_structural_span_barriers(line_item, vertical_lines=None, widget_rects=None, drawn_box_rects=None):
    line = line_item.get('line') or {}
    spans = [
        span
        for span in (line.get('spans') or [])
        if isinstance(span.get('bbox'), (list, tuple)) and len(span.get('bbox')) >= 4 and str(span.get('text') or '').strip()
    ]
    if len(spans) <= 1:
        return [line_item]

    ordered_spans = sorted(spans, key=lambda span: (float(span['bbox'][0]), float(span['bbox'][1])))
    span_groups = [[ordered_spans[0]]]

    for span in ordered_spans[1:]:
        current_group = span_groups[-1]
        current_bbox = None
        for existing_span in current_group:
            current_bbox = _rect_union(current_bbox, existing_span.get('bbox'))
        span_bbox = span.get('bbox')

        has_structural_barrier = (
            _rects_have_vertical_barrier(current_bbox, span_bbox, vertical_lines or [])
            or _rects_have_widget_barrier(current_bbox, span_bbox, widget_rects or [])
            or _rects_have_drawn_box_barrier(current_bbox, span_bbox, drawn_box_rects or [])
        )

        current_group_max_size = max(
            (float(existing_span.get('size') or 0) for existing_span in current_group),
            default=0.0,
        )
        span_size = float(span.get('size') or 0)
        size_ratio = (
            min(current_group_max_size, span_size) / max(current_group_max_size, span_size)
            if current_group_max_size > 0 and span_size > 0
            else 1.0
        )
        force_style_split = (
            abs(current_group_max_size - span_size) >= 8.0
            and size_ratio <= 0.5
        )

        if has_structural_barrier or force_style_split:
            span_groups.append([span])
        else:
            current_group.append(span)

    if len(span_groups) <= 1:
        return [line_item]

    split_items = []
    base_line_num = int(line_item.get('line_num', 0) or 0)
    for index, group_spans in enumerate(span_groups):
        group_bbox = None
        for group_span in group_spans:
            group_bbox = _rect_union(group_bbox, group_span.get('bbox'))
        if not group_bbox:
            continue

        split_line = {
            'spans': group_spans,
            'bbox': group_bbox,
            'dir': line_item.get('dir') or line.get('dir'),
            'wmode': line_item.get('wmode', line.get('wmode', 0)),
        }
        max_size = max((float(span.get('size') or 0) for span in group_spans), default=float(line_item.get('max_size') or 0))
        split_items.append({
            'line_num': base_line_num + index,
            'line': split_line,
            'bbox': group_bbox,
            'x0': float(group_bbox[0]),
            'y0': float(group_bbox[1]),
            'max_size': max_size,
            'dir': line_item.get('dir'),
            'wmode': line_item.get('wmode', 0),
            '_from_xgap_split': True,
        })

    return split_items or [line_item]


def _line_item_text(item):
    line = item.get('line') or {}
    spans = line.get('spans') or []
    return ''.join(str(span.get('text') or '') for span in spans).strip()


def _line_item_has_large_whitespace_gap(item):
    return re.search(r'\s{6,}', _line_item_text(item) or '') is not None


def _looks_like_form_section_header(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False
    return re.match(r'^Part\s+[IVXLCM]+\b', normalized, re.IGNORECASE) is not None


def _starts_with_form_field_number(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False
    return re.match(r'^\d+[A-Za-z]?\b', normalized) is not None


def _looks_like_leading_inline_label(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False
    return re.match(
        r'^(?:\d{1,2}[A-Za-z]?|[A-Za-z]|[A-Za-z]\)|\([A-Za-z0-9]{1,2}\)|[•◦▪■▶▲])$',
        normalized,
    ) is not None


def _is_standalone_list_item_marker(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False
    return re.match(
        r'^(?:\(?\d{1,3}[A-Za-z]?\)|\d{1,3}[.)]|[A-Za-z][.)]|[•◦▪■▶▲])$',
        normalized,
    ) is not None


def _starts_with_list_item_marker(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized:
        return False
    return re.match(
        r'^(?:\(?\d{1,3}[A-Za-z]?\)|\d{1,3}[.)]|[A-Za-z][.)]|[•◦▪■▶▲])\s+\S+',
        normalized,
    ) is not None


def _starts_with_callout_label(text):
    normalized = ' '.join(str(text or '').split())
    if len(normalized) < 12:
        return False
    return re.match(
        r'^(?:NOTE|NOTES|IMPORTANT|WARNING|CAUTION|NOTICE|ATTENTION)\s*:\s+\S+',
        normalized,
        re.IGNORECASE,
    ) is not None


def _looks_like_detached_row_label(text):
    normalized = ' '.join(str(text or '').split())
    if not normalized or len(normalized) > 24:
        return False
    if normalized.endswith((':', ';', '.', '!', '?')):
        return False
    if len(normalized.split()) > 4:
        return False
    if re.search(r'\d{4,}', normalized):
        return False
    return re.match(r"^[A-Za-z0-9&()/'’.·\- ]+$", normalized) is not None


def _line_item_dominant_color(line_item):
    """Return the span color (24-bit int) that covers the most characters on a line.

    Used by _split_group_by_color_change to detect paragraph breaks caused by
    color transitions within a single PyMuPDF text block (e.g. a form with
    dark-grey body text and a white-on-colored-bar note that PyMuPDF emits as
    one block because they touch vertically).
    """
    line = line_item.get('line') or {}
    color_char_counts = {}
    for span in line.get('spans', []) or []:
        text = str(span.get('text') or '')
        stripped = text.strip()
        if not stripped:
            continue
        color = span.get('color')
        if color is None:
            continue
        try:
            color_int = int(color)
        except (TypeError, ValueError):
            continue
        color_char_counts[color_int] = color_char_counts.get(color_int, 0) + len(stripped)
    if not color_char_counts:
        return None
    return max(color_char_counts.items(), key=lambda kv: kv[1])[0]


def _srgb_luminance(color_int):
    """Rec. 601 perceptual luminance of a 24-bit sRGB color (0..255 scale)."""
    r = (color_int >> 16) & 0xFF
    g = (color_int >> 8) & 0xFF
    b = color_int & 0xFF
    return 0.299 * r + 0.587 * g + 0.114 * b


def _colors_differ_significantly(a, b):
    """True iff two 24-bit sRGB colors are perceptually distinct paragraph
    colors (not just anti-alias / rendering jitter between near-black shades).

    Uses Rec. 601 luminance delta (≥ 40) OR a large max-channel delta (≥ 120)
    so saturated same-brightness hues (e.g. red vs green legend labels) still
    count as distinct while near-black variants like #000000 vs #231F20 do not.
    """
    if a is None or b is None:
        return False
    if a == b:
        return False
    if abs(_srgb_luminance(a) - _srgb_luminance(b)) >= 40.0:
        return True
    ar, ag, ab = (a >> 16) & 0xFF, (a >> 8) & 0xFF, a & 0xFF
    br, bg, bb = (b >> 16) & 0xFF, (b >> 8) & 0xFF, b & 0xFF
    return max(abs(ar - br), abs(ag - bg), abs(ab - bb)) >= 120


_SUBSET_FONT_PREFIX_RE = re.compile(r'^[A-Z]{6}\+')


def _normalize_font_name(name):
    """Strip PDF subset prefix (e.g. ``AAAAAA+Helvetica`` → ``Helvetica``)."""
    if not name:
        return ''
    normalized = str(name).strip()
    return _SUBSET_FONT_PREFIX_RE.sub('', normalized)


def _line_item_font_key_counts(line_item):
    """Return a ``{(normalized_font_name, is_bold, is_italic): char_count}``
    mapping for every styled span on the line.
    """
    line = line_item.get('line') or {}
    font_char_counts = {}
    for span in line.get('spans', []) or []:
        text = str(span.get('text') or '')
        stripped = text.strip()
        if not stripped:
            continue
        font_name = _normalize_font_name(span.get('font'))
        if not font_name:
            continue
        try:
            flags = int(span.get('flags') or 0)
        except (TypeError, ValueError):
            flags = 0
        is_bold = bool(flags & 16) or 'bold' in font_name.lower() or 'black' in font_name.lower()
        is_italic = bool(flags & 2) or 'italic' in font_name.lower() or 'oblique' in font_name.lower()
        key = (font_name.lower(), is_bold, is_italic)
        font_char_counts[key] = font_char_counts.get(key, 0) + len(stripped)
    return font_char_counts


def _line_item_dominant_font_key(line_item):
    """Return a ``(normalized_font_name, is_bold, is_italic)`` tuple describing
    the font style that covers the most characters on the line. Used to detect
    paragraph breaks where a heading font transitions into body text (or vice
    versa) without any color change.
    """
    counts = _line_item_font_key_counts(line_item)
    if not counts:
        return None
    return max(counts.items(), key=lambda kv: kv[1])[0]


def _font_keys_differ_significantly(a, b):
    """True iff two ``_line_item_dominant_font_key`` tuples represent a real
    paragraph-level style change (family change OR weight/italic toggle).
    A same-family regular → regular with only a minor variant suffix is not
    considered significant.
    """
    if not a or not b:
        return False
    if a == b:
        return False
    family_a = (a[0] or '').split('-')[0].split(',')[0]
    family_b = (b[0] or '').split('-')[0].split(',')[0]
    if family_a != family_b:
        return True
    # Same family: only flag when bold or italic toggles.
    return (a[1] != b[1]) or (a[2] != b[2])


def _split_group_by_color_change(group_items):
    """Split a group of line_items whenever two adjacent lines use distinctly
    different text *styles* — either a significant color change OR a font
    family / weight toggle that signals a paragraph boundary.

    PyMuPDF's ``get_text("dict")`` sometimes emits multiple conceptually-
    distinct paragraphs inside a single block when they happen to be near each
    other vertically. Commercial PDF reflow engines (Acrobat, PDF.net) split
    those on any perceptible style transition so each paragraph gets its own
    annotation with the correct color, weight, and bounding box.
    """
    if len(group_items) <= 1:
        return [group_items]

    ordered = sorted(group_items, key=lambda it: (it['bbox'][1], it['bbox'][0]))
    split_groups = [[ordered[0]]]
    prev_color = _line_item_dominant_color(ordered[0])
    prev_font = _line_item_dominant_font_key(ordered[0])
    prev_font_keys = set(_line_item_font_key_counts(ordered[0]).keys())
    for item in ordered[1:]:
        item_color = _line_item_dominant_color(item)
        item_font = _line_item_dominant_font_key(item)
        item_font_keys = set(_line_item_font_key_counts(item).keys())
        color_break = (
            prev_color is not None
            and item_color is not None
            and _colors_differ_significantly(prev_color, item_color)
        )
        font_break = (
            prev_font is not None
            and item_font is not None
            and _font_keys_differ_significantly(prev_font, item_font)
        )
        # A bold/italic lead-in span at the start of a paragraph (e.g.
        # "Higher income taxpayers." followed by regular continuation text)
        # makes the dominant font of that line bold while the rest of the
        # paragraph continues in regular weight. Treat the lines as a single
        # paragraph when the bold/italic line's mixed style set still includes
        # the next line's dominant body font (or vice versa) — this means the
        # paragraph just has a styled prefix, not a real heading→body break.
        if font_break and prev_font_keys and item_font_keys:
            shared_keys = prev_font_keys & item_font_keys
            if shared_keys:
                font_break = False
        if color_break or font_break:
            split_groups.append([item])
        else:
            split_groups[-1].append(item)
        if item_font_keys:
            prev_font_keys = item_font_keys
        # Track the most-recent line's style so a run of same-styled lines
        # continues to anchor to that style.
        if item_color is not None:
            prev_color = item_color
        if item_font is not None:
            prev_font = item_font

    return split_groups


def _split_group_by_filled_background(group_items, filled_bg_rects):
    """Split a group of line_items based on which opaque filled background
    rectangle (if any) each line sits on.

    Lines that share the same background fill stay together; a transition from
    one fill to another (including ``no-fill`` ↔ ``fill``) creates a split.
    This is how Acrobat segments colored callout bars, section-header bands,
    and shaded table rows: the *design element* — not the text metrics — is
    the paragraph boundary signal.
    """
    if len(group_items) <= 1 or not filled_bg_rects:
        return [group_items]

    ordered = sorted(group_items, key=lambda it: (it['bbox'][1], it['bbox'][0]))
    split_groups = [[ordered[0]]]
    prev_bg = _line_bbox_on_filled_background(ordered[0].get('bbox'), filled_bg_rects)
    for item in ordered[1:]:
        item_bg = _line_bbox_on_filled_background(item.get('bbox'), filled_bg_rects)
        if item_bg != prev_bg:
            split_groups.append([item])
        else:
            split_groups[-1].append(item)
        prev_bg = item_bg

    return split_groups


def _split_group_by_line_width_jump(group_items):
    """Split a group when adjacent lines have dramatically different widths
    (narrow column labels adjacent to wide descriptor lines).

    Conservative trigger — requires ALL of:
      * narrow line width ≤ 80pt
      * wide   line width ≥ 200pt
      * width ratio ≥ 3×

    These thresholds target the realtor-form pattern "left-column labels stacked
    on top of full-width section descriptors" (and similar layouts) without
    false-triggering on justified body text whose last line is short.
    """
    if len(group_items) <= 1:
        return [group_items]

    ordered = sorted(group_items, key=lambda it: (it['bbox'][1], it['bbox'][0]))

    def _line_width(item):
        bbox = item.get('bbox') or (0, 0, 0, 0)
        try:
            return max(0.0, float(bbox[2]) - float(bbox[0]))
        except (TypeError, ValueError, IndexError):
            return 0.0

    split_groups = [[ordered[0]]]
    prev_width = _line_width(ordered[0])
    for item in ordered[1:]:
        item_width = _line_width(item)
        narrow = min(prev_width, item_width)
        wide = max(prev_width, item_width)
        if narrow <= 80.0 and wide >= 200.0 and wide >= 3.0 * max(narrow, 1.0):
            split_groups.append([item])
        else:
            split_groups[-1].append(item)
        prev_width = item_width

    return split_groups


def _split_stacked_form_header_groups(group_items):
    if len(group_items) <= 1:
        return [group_items]

    ordered_items = sorted(group_items, key=lambda item: (item['bbox'][1], item['bbox'][0]))
    split_groups = [[ordered_items[0]]]

    for item in ordered_items[1:]:
        current_group = split_groups[-1]
        previous_item = current_group[-1]
        previous_text = _line_item_text(previous_item)
        item_text = _line_item_text(item)
        shares_visual_row = _groups_share_visual_row([previous_item], [item])

        if (
            not shares_visual_row
            and _looks_like_form_section_header(previous_text)
            and _starts_with_form_field_number(item_text)
        ):
            split_groups.append([item])
        else:
            current_group.append(item)

    return split_groups


def _items_bbox(items):
    bbox = None
    for item in items or []:
        bbox = _rect_union(bbox, item.get('bbox'))
    return bbox


def _rects_have_horizontal_barrier(upper_rect, lower_rect, horizontal_lines):
    if not upper_rect or not lower_rect or not horizontal_lines:
        return False

    a = tuple(float(value) for value in upper_rect[:4])
    b = tuple(float(value) for value in lower_rect[:4])
    if a[1] > b[1]:
        a, b = b, a

    gap_top = a[3]
    gap_bottom = b[1]
    if gap_bottom <= gap_top:
        return False

    band_left = min(a[0], b[0]) - 1.0
    band_right = max(a[2], b[2]) + 1.0
    required_overlap = max(18.0, min((a[2] - a[0]), (b[2] - b[0])) * 0.35)

    for x0, x1, y, _stroke_w in horizontal_lines:
        if y < (gap_top - 1.0) or y > (gap_bottom + 1.0):
            continue
        overlap = max(0.0, min(band_right, x1) - max(band_left, x0))
        if overlap >= required_overlap:
            return True
    return False


def _row_accepts_line_item(row_items, candidate_item):
    row_bbox = _items_bbox(row_items)
    item_bbox = candidate_item.get('bbox')
    if not row_bbox or not item_bbox:
        return False

    row_top, row_bottom = float(row_bbox[1]), float(row_bbox[3])
    item_top, item_bottom = float(item_bbox[1]), float(item_bbox[3])
    overlap_ratio = _row_overlap_ratio(row_top, row_bottom, item_top, item_bottom)

    row_center = (row_top + row_bottom) / 2.0
    item_center = (item_top + item_bottom) / 2.0
    row_height = max(1.0, row_bottom - row_top)
    item_height = max(1.0, item_bottom - item_top)
    center_tolerance = max(2.5, min(max(row_height, item_height) * 0.3, 4.5))

    return overlap_ratio >= 0.45 or abs(row_center - item_center) <= center_tolerance


def _line_group_share_column(block_groups, candidate_group):
    block_bbox = _items_bbox([
        item
        for group in (block_groups or [])
        for item in (group or [])
    ])
    candidate_bbox = _items_bbox(candidate_group)
    if not block_bbox or not candidate_bbox:
        return False

    block_left = float(block_bbox[0])
    block_right = float(block_bbox[2])
    candidate_left = float(candidate_bbox[0])
    candidate_right = float(candidate_bbox[2])
    block_width = max(1.0, block_right - block_left)
    candidate_width = max(1.0, candidate_right - candidate_left)
    overlap_width = max(0.0, min(block_right, candidate_right) - max(block_left, candidate_left))
    overlap_ratio = overlap_width / max(1.0, min(block_width, candidate_width))
    left_delta = abs(block_left - candidate_left)
    center_delta = abs(
        ((block_left + block_right) / 2.0) - ((candidate_left + candidate_right) / 2.0)
    )

    if overlap_ratio >= 0.45:
        return True

    if left_delta <= max(12.0, min(block_width, candidate_width) * 0.2):
        return True

    if center_delta <= max(18.0, min(block_width, candidate_width) * 0.25):
        return True

    return False


def _should_start_new_form_block(current_groups, candidate_group, horizontal_lines, widget_rects=None, drawn_box_rects=None):
    if not current_groups:
        return False

    previous_group = current_groups[-1]
    prev_bbox = _items_bbox(previous_group)
    candidate_bbox = _items_bbox(candidate_group)
    if not prev_bbox or not candidate_bbox:
        return False

    prev_text = ' '.join(_line_item_text(item) for item in previous_group).strip()
    candidate_text = ' '.join(_line_item_text(item) for item in candidate_group).strip()
    shares_visual_row = _groups_share_visual_row(previous_group, candidate_group)

    vertical_gap = float(candidate_bbox[1]) - float(prev_bbox[3])
    if vertical_gap > 10.0:
        return True

    if _rects_have_horizontal_barrier(prev_bbox, candidate_bbox, horizontal_lines or []):
        return True

    if widget_rects and _rects_have_widget_barrier(prev_bbox, candidate_bbox, widget_rects):
        return True

    if drawn_box_rects and _rects_have_drawn_box_barrier(prev_bbox, candidate_bbox, drawn_box_rects):
        return True

    if shares_visual_row and (
        any(_line_item_has_large_whitespace_gap(item) for item in previous_group)
        or any(_line_item_has_large_whitespace_gap(item) for item in candidate_group)
    ):
        return True

    if shares_visual_row and (
        any(item.get('_from_xgap_split') for item in previous_group)
        or any(item.get('_from_xgap_split') for item in candidate_group)
    ):
        return True

    if (
        _looks_like_form_section_header(prev_text)
        and _starts_with_form_field_number(candidate_text)
    ):
        return True

    if _starts_with_list_item_marker(prev_text) and _starts_with_callout_label(candidate_text):
        return True

    if not _line_group_share_column(current_groups, candidate_group):
        return True

    return False


def _page_requires_synthetic_form_grouping(blocks, horizontal_lines, vertical_lines, widget_rects, drawn_box_rects=None):
    form_line_count = 0
    numbered_line_count = 0
    compact_label_count = 0

    for block in blocks or []:
        if block.get("type") != 0:
            continue
        for line in (block.get("lines") or []):
            spans = line.get("spans") or []
            text = ''.join(str(span.get('text') or '') for span in spans)
            normalized = ' '.join(str(text or '').split())
            if not normalized:
                continue
            if _looks_like_form_section_header(normalized):
                form_line_count += 1
            if _starts_with_form_field_number(normalized):
                numbered_line_count += 1
                form_line_count += 1
            if (
                len(normalized) <= 36
                and normalized.upper() == normalized
                and re.search(r'[A-Z]', normalized)
                and re.search(r'\s', normalized)
            ):
                compact_label_count += 1

    has_form_like_text_structure = (
        numbered_line_count >= 2
        or form_line_count >= 3
        or compact_label_count >= 5
    )

    if len(widget_rects or []) >= 2:
        return True

    if has_form_like_text_structure and len(vertical_lines or []) >= 3 and len(horizontal_lines or []) >= 3:
        return True

    if has_form_like_text_structure and len(drawn_box_rects or []) >= 2:
        return True

    for block in blocks or []:
        if block.get("type") != 0:
            continue
        line_bboxes = [
            tuple(line.get("bbox") or ())
            for line in (block.get("lines") or [])
            if isinstance(line.get("bbox"), (list, tuple)) and len(line.get("bbox")) >= 4
        ]
        if len(line_bboxes) < 2:
            continue
        ordered = sorted(line_bboxes, key=lambda bbox: (bbox[1], bbox[0]))
        for previous_bbox, current_bbox in zip(ordered, ordered[1:]):
            vertical_gap = float(current_bbox[1]) - float(previous_bbox[3])
            left_delta = abs(float(current_bbox[0]) - float(previous_bbox[0]))
            if has_form_like_text_structure and vertical_gap > 10.0 and (
                left_delta > 18.0
                or _rects_have_horizontal_barrier(previous_bbox, current_bbox, horizontal_lines or [])
            ):
                return True

    return False


def _build_synthetic_form_blocks(blocks, vertical_lines=None, widget_rects=None, horizontal_lines=None, drawn_box_rects=None):
    page_line_items = []
    for block in blocks or []:
        if block.get("type") != 0:
            continue
        for line_num, line in enumerate(block.get("lines", []) or []):
            line_bbox = line.get("bbox", (0, 0, 0, 0))
            if not isinstance(line_bbox, (list, tuple)) or len(line_bbox) < 4:
                continue
            span_sizes = [
                span.get("size", 0)
                for span in (line.get("spans", []) or [])
                if span.get("text", "")
            ]
            page_line_items.append({
                'line_num': line_num,
                'line': line,
                'bbox': line_bbox,
                'x0': float(line_bbox[0]),
                'y0': float(line_bbox[1]),
                'max_size': max(span_sizes) if span_sizes else 0,
                'dir': _normalize_line_direction(line.get("dir")),
                'wmode': int(line.get("wmode", 0) or 0),
            })

    if not page_line_items:
        return [block for block in (blocks or []) if block.get("type") == 0]

    split_page_line_items = []
    for page_line_item in page_line_items:
        split_page_line_items.extend(
            _split_line_item_by_structural_span_barriers(
                page_line_item,
                vertical_lines=vertical_lines,
                widget_rects=widget_rects,
                drawn_box_rects=drawn_box_rects,
            )
        )

    merged_page_line_items = _merge_same_row_lines(
        split_page_line_items,
        vertical_lines=vertical_lines,
        widget_rects=widget_rects,
        drawn_box_rects=drawn_box_rects,
    )
    ordered_items = sorted(
        merged_page_line_items,
        key=lambda item: (
            round((float(item['bbox'][1]) + float(item['bbox'][3])) / 2.0, 3),
            round(float(item['bbox'][0]), 3),
        ),
    )

    visual_rows = []
    for item in ordered_items:
        if visual_rows and _row_accepts_line_item(visual_rows[-1], item):
            visual_rows[-1].append(item)
        else:
            visual_rows.append([item])

    row_groups = []
    for row_items in visual_rows:
        x_groups = _split_candidate_lines_by_x_gap(
            row_items,
            vertical_lines=vertical_lines,
            widget_rects=widget_rects,
            drawn_box_rects=drawn_box_rects,
        )
        normalized_groups = []
        for group_items in x_groups:
            normalized_groups.extend(
                _split_same_row_group_by_barriers(
                    group_items,
                    vertical_lines or [],
                    widget_rects or [],
                    drawn_box_rects=drawn_box_rects,
                )
            )
        for group_items in normalized_groups:
            merged_group_items = _merge_same_row_lines(
                group_items,
                vertical_lines=vertical_lines,
                widget_rects=widget_rects,
                drawn_box_rects=drawn_box_rects,
            )
            if not merged_group_items:
                continue
            if len(merged_group_items) == 1:
                row_groups.append(merged_group_items)
            else:
                for merged_group_item in merged_group_items:
                    row_groups.append([merged_group_item])

    ordered_row_groups = sorted(
        row_groups,
        key=lambda group: (
            round(float(_items_bbox(group)[1]) if _items_bbox(group) else 0.0, 3),
            round(float(_items_bbox(group)[0]) if _items_bbox(group) else 0.0, 3),
        ),
    )

    grouped_blocks = []
    current_groups = []
    for row_group in ordered_row_groups:
        if _should_start_new_form_block(
            current_groups,
            row_group,
            horizontal_lines or [],
            widget_rects=widget_rects or [],
            drawn_box_rects=drawn_box_rects or [],
        ):
            grouped_blocks.append(current_groups)
            current_groups = [row_group]
        else:
            current_groups.append(row_group)
    if current_groups:
        grouped_blocks.append(current_groups)

    synthetic_blocks = []
    for grouped_rows in grouped_blocks:
        block_lines = []
        block_bbox = None
        for group in grouped_rows:
            for item in group:
                line_payload = dict(item.get('line') or {})
                if item.get('bbox'):
                    line_payload['bbox'] = item['bbox']
                if item.get('dir'):
                    line_payload['dir'] = item.get('dir')
                line_payload['wmode'] = int(item.get('wmode', line_payload.get('wmode', 0)) or 0)
                block_lines.append(line_payload)
                block_bbox = _rect_union(block_bbox, item.get('bbox'))
        if not block_lines or not block_bbox:
            continue
        synthetic_blocks.append({
            'type': 0,
            'bbox': block_bbox,
            'lines': block_lines,
            '_synthetic_form_block': True,
        })

    return synthetic_blocks or [block for block in (blocks or []) if block.get("type") == 0]


def _looks_like_dot_leader_fragment(text):
    normalized = str(text or '').strip()
    if not normalized:
        return False
    if '.' not in normalized:
        return False

    without_leaders = normalized.replace('.', '').replace(' ', '').replace('\t', '')
    if without_leaders == '':
        return True

    return re.match(r'^[A-Z0-9][A-Z0-9\-./()]*$', without_leaders) is not None


def _group_and_item_share_column(group_items, item_bbox):
    if not group_items or not item_bbox:
        return False

    group_bbox = None
    for existing_item in group_items:
        group_bbox = _rect_union(group_bbox, existing_item.get('bbox'))
    if not group_bbox:
        return False

    group_left = float(group_bbox[0])
    group_right = float(group_bbox[2])
    item_left = float(item_bbox[0])
    item_right = float(item_bbox[2])
    group_width = max(1.0, group_right - group_left)
    item_width = max(1.0, item_right - item_left)
    inter_width = max(0.0, min(group_right, item_right) - max(group_left, item_left))
    overlap_ratio = inter_width / max(1.0, min(group_width, item_width))
    center_delta = abs(
        ((group_left + group_right) / 2.0) - ((item_left + item_right) / 2.0)
    )

    if overlap_ratio >= 0.5:
        return True

    if abs(item_left - group_left) <= max(12.0, min(group_width, item_width) * 0.2):
        return True

    if center_delta <= max(18.0, min(group_width, item_width) * 0.25):
        return True

    return False


def _split_candidate_lines_by_x_gap(candidate_items, vertical_lines=None, widget_rects=None, drawn_box_rects=None):
    """
    Split a candidate line group into x-clusters when items occupy distinct columns
    on the same visual row. Vertically stacked paragraph lines in the same column
    should stay together even though their x0 is to the left of the previous
    line's right edge.
    """
    if len(candidate_items) <= 1:
        return [candidate_items]

    sorted_by_x = sorted(candidate_items, key=lambda item: item['x0'])
    x_groups = [[sorted_by_x[0]]]

    for item in sorted_by_x[1:]:
        current_group = x_groups[-1]
        current_group_bbox = None
        for existing_item in current_group:
            current_group_bbox = _rect_union(current_group_bbox, existing_item.get('bbox'))
        item_bbox = item.get('bbox')
        group_right = float(current_group_bbox[2]) if current_group_bbox else float(item['x0'])
        gap = float(item['x0']) - group_right

        avg_size = max(
            (it.get('max_size', 0) for it in current_group if it.get('max_size', 0) > 0),
            default=12,
        )
        pair_size = max(avg_size, float(item.get('max_size') or 0), 8.0)
        gap_threshold = min(max(pair_size * 1.1, 7.0), 16.0)
        overlap_threshold = avg_size * 0.2
        has_structural_barrier = (
            _rects_have_vertical_barrier(current_group_bbox, item_bbox, vertical_lines or [])
            or _rects_have_widget_barrier(current_group_bbox, item_bbox, widget_rects or [])
            or _rects_have_drawn_box_barrier(current_group_bbox, item_bbox, drawn_box_rects or [])
        )
        shares_visual_row = _groups_share_visual_row(current_group, [item])
        current_group_text = ''.join(_line_item_text(existing_item) for existing_item in current_group).strip()
        item_text = _line_item_text(item)
        keeps_dot_leader_continuity = (
            _looks_like_dot_leader_fragment(item_text)
            or _looks_like_dot_leader_fragment(current_group_text)
        )

        if has_structural_barrier or (
            shares_visual_row
            and not keeps_dot_leader_continuity
            and (gap >= gap_threshold or gap < -overlap_threshold)
        ):
            x_groups.append([item])
        elif not shares_visual_row and not _group_and_item_share_column(current_group, item_bbox):
            x_groups.append([item])
        else:
            current_group.append(item)

    return x_groups


def _split_same_row_group_by_barriers(group_items, vertical_lines, widget_rects, drawn_box_rects=None):
    if len(group_items) <= 1:
        return [group_items]

    sorted_items = sorted(group_items, key=lambda item: item['x0'])
    split_groups = [[sorted_items[0]]]

    for item in sorted_items[1:]:
        current_group = split_groups[-1]
        current_bbox = None
        for existing_item in current_group:
            current_bbox = _rect_union(current_bbox, existing_item.get('bbox'))
        item_bbox = item.get('bbox')
        same_row = _groups_share_visual_row(current_group, [item])
        avg_size = max(
            (it.get('max_size', 0) for it in current_group if it.get('max_size', 0) > 0),
            default=12,
        )
        pair_size = max(avg_size, float(item.get('max_size') or 0), 8.0)
        gap_threshold = min(max(pair_size * 0.9, 6.0), 14.0)
        gap = float(item_bbox[0]) - float(current_bbox[2]) if current_bbox and item_bbox else 0.0
        has_structural_barrier = (
            _rects_have_vertical_barrier(current_bbox, item_bbox, vertical_lines)
            or _rects_have_widget_barrier(current_bbox, item_bbox, widget_rects)
            or _rects_have_drawn_box_barrier(current_bbox, item_bbox, drawn_box_rects or [])
        )
        current_group_text = ''.join(_line_item_text(existing_item) for existing_item in current_group).strip()
        item_text = _line_item_text(item)
        keeps_dot_leader_continuity = (
            _looks_like_dot_leader_fragment(item_text)
            or _looks_like_dot_leader_fragment(current_group_text)
        )

        if same_row and has_structural_barrier:
            split_groups.append([item])
        elif same_row and not keeps_dot_leader_continuity and gap >= gap_threshold:
            split_groups.append([item])
        else:
            current_group.append(item)

    return split_groups


def _merge_adjacent_page_blocks(page_blocks, page_width, page_words, page_lines, widget_rects=None, vertical_lines=None, drawn_box_rects=None):
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

                # Adjacent-block merging is only for sequential left-to-right fragments.
                # If the blocks substantially overlap horizontally, they are layered or
                # nested same-row content and must remain separate.
                horizontal_overlap = max(0.0, min(a_right, b_right) - max(a_left, b_left))
                min_width = max(1.0, min(block_a['width'], block_b['width']))
                if horizontal_overlap > max(3.0, max(a_size, b_size) * 0.25):
                    continue
                if (horizontal_overlap / min_width) > 0.15:
                    continue

                if _block_contains_symbol_font(block_a) or _block_contains_symbol_font(block_b):
                    continue

                if bool(block_a.get('bold')) != bool(block_b.get('bold')):
                    continue

                if _rects_have_vertical_barrier(
                    (a_left, a_top, a_right, a_bottom),
                    (b_left, b_top, b_right, b_bottom),
                    vertical_lines or [],
                ):
                    continue

                if _rects_have_widget_barrier(
                    (a_left, a_top, a_right, a_bottom),
                    (b_left, b_top, b_right, b_bottom),
                    widget_rects or [],
                ):
                    continue

                if _rects_have_drawn_box_barrier(
                    (a_left, a_top, a_right, a_bottom),
                    (b_left, b_top, b_right, b_bottom),
                    drawn_box_rects or [],
                ):
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
                merged_bbox = (m_left, m_top, m_right, m_bottom)

                first_line_text = (first.get('text_single_line') or first.get('text') or '').replace('\n', ' ').strip()
                second_line_text = (second.get('text_single_line') or second.get('text') or '').replace('\n', ' ').strip()
                merged_single_line = ' '.join(part for part in [first_line_text, second_line_text] if part).strip()

                merged_block = {
                    'block_num': surviving_block_num,
                    'left': m_left,
                    'top': m_top,
                    'width': m_right - m_left,
                    'height': m_bottom - m_top,
                }

                # Same-row merges should remain a single visual line. Keeping both
                # source blocks as separate text lines creates fake multiline form
                # labels that the editor later renders as stacked content.
                merged_block['text_lines'] = [merged_single_line] if merged_single_line else []
                merged_block['text'] = merged_single_line
                merged_block['text_single_line'] = merged_single_line
                merged_block['spans'] = first.get('spans', []) + second.get('spans', [])
                merged_block['source_content_ops'] = _dedupe_source_content_ops(
                    (first.get('source_content_ops') or []) + (second.get('source_content_ops') or [])
                )
                merged_block['line_bboxes'] = [merged_bbox]
                merged_block['line_count'] = 1

                all_heights = [bbox[3] - bbox[1] for bbox in merged_block['line_bboxes']]
                if all_heights:
                    merged_block['avg_line_height'] = sum(all_heights) / len(all_heights)

                merged_block['has_mixed_styles'] = True

                # Style from the larger block (dominant)
                larger = first if len(first.get('text', '')) >= len(second.get('text', '')) else second
                for key in ('font', 'font_xref', 'font_size', 'font_weight', 'color',
                            'hex_color', 'bold', 'italic', 'line_height', 'ascender', 'descender',
                            'uses_embedded_font', 'embedded_font_name', 'embedded_font_family', 'embedded_font_xref'):
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


def _block_starts_with_bold_paragraph_lead_in(block):
    """Return True when the block opens with a bold span that looks like a
    paragraph heading lead-in (e.g. "Farming and fishing.") followed by a
    period and space. Used to keep distinct paragraphs from being merged
    together when they share body style.
    """
    if not isinstance(block, dict):
        return False
    spans = block.get('spans') or []
    if not spans:
        return False

    def _span_is_bold(span):
        if not isinstance(span, dict):
            return False
        if bool(span.get('bold')):
            return True
        font_name = str(span.get('font') or '').lower()
        return 'bold' in font_name or 'black' in font_name or 'heavy' in font_name

    if not _span_is_bold(spans[0]):
        return False

    # Concatenate consecutive bold spans at the start so that a heading split
    # across spans (e.g. hyphenated end-of-line "ex-" / "panded.") is treated
    # as one lead-in string.
    bold_parts = []
    for span in spans:
        if not _span_is_bold(span):
            break
        bold_parts.append(str(span.get('text') or ''))
    text = ''.join(bold_parts).strip()
    # Repair end-of-line hyphenation across span boundaries: "ex- panded".
    text = re.sub(r'-\s+', '', text)
    if not text or len(text) > 70:
        return False
    # Heading-like: Title-Case or Sentence-case phrase ending with `.`,
    # or with `-` when the heading is split across spans by end-of-line
    # hyphenation (e.g. "Casualty loss deduction made permanent and ex-")
    # whose continuation lives in the next block.
    if not re.match(r"^[A-Z][A-Za-z0-9&'’/().,\- ]{2,68}[\.\-]\s*$", text):
        return False
    return True


def _block_has_regular_continuation_after_bold(block):
    """Return True when the block opens with a bold lead-in and continues
    with regular body text in the same line (e.g. the `Higher income
    taxpayers. If your adjusted gross` first line)."""
    if not isinstance(block, dict):
        return False
    spans = block.get('spans') or []
    if len(spans) < 2:
        return False
    first_span = spans[0]
    if not isinstance(first_span, dict) or not bool(first_span.get('bold')):
        return False
    for span in spans[1:]:
        if not isinstance(span, dict):
            continue
        if span.get('bold'):
            continue
        text = str(span.get('text') or '').strip()
        if text:
            return True
    return False


def _merge_stacked_paragraph_blocks(page_blocks, page_words, page_lines):
    """
    Merge vertically stacked synthetic paragraph fragments back into a single block.
    This targets stamped annotation text that lacks source_content_ops and is emitted
    as multiple same-column blocks with tiny vertical gaps.
    """
    if len(page_blocks) <= 1:
        return page_blocks

    merge_map = {}
    changed = True

    def _style_key(block):
        return (
            str(block.get('font', '') or '').strip().lower(),
            round(float(block.get('font_size', 0) or 0), 1),
            str(block.get('font_weight', '')),
            bool(block.get('bold')),
            bool(block.get('italic')),
            str(block.get('hex_color', '')),
        )

    def _style_key_relaxed(block):
        # Drop bold flag — used when allowing a bold-lead-in single line to
        # merge with its regular-bodied continuation paragraph.
        return (
            round(float(block.get('font_size', 0) or 0), 1),
            bool(block.get('italic')),
            str(block.get('hex_color', '')),
        )

    while changed:
        changed = False
        new_blocks = []
        used = set()

        sorted_pairs = sorted(enumerate(page_blocks), key=lambda pair: (
            # Quantize column-left to ~16pt buckets so bulleted children whose
            # left edge is offset by a glyph-width still group with the parent
            # paragraph for merge consideration. Sub-pixel jitter (315.0 vs
            # 314.999) must not split a bucket; floor by integer division.
            int(float(pair[1].get('left', 0) or 0)) // 16,
            round(float(pair[1].get('top', 0) or 0), 1),
        ))

        index_lookup = {orig_idx: pos for pos, (orig_idx, _block) in enumerate(sorted_pairs)}

        for original_index, block_a in sorted_pairs:
            if original_index in used:
                continue

            a_top = float(block_a.get('top', 0) or 0)
            a_left = float(block_a.get('left', 0) or 0)
            a_width = float(block_a.get('width', 0) or 0)
            a_height = float(block_a.get('height', 0) or 0)
            a_bottom = a_top + a_height
            a_line_height = float(block_a.get('avg_line_height') or block_a.get('line_height') or 0) or max(1.0, a_height)
            a_source_ops = block_a.get('source_content_ops') or []

            merge_target_index = None

            if a_source_ops:
                new_blocks.append(block_a)
                used.add(original_index)
                continue

            for candidate_pos in range(index_lookup[original_index] + 1, len(sorted_pairs)):
                candidate_index, block_b = sorted_pairs[candidate_pos]
                if candidate_index in used:
                    continue

                b_top = float(block_b.get('top', 0) or 0)
                b_left = float(block_b.get('left', 0) or 0)
                b_width = float(block_b.get('width', 0) or 0)
                b_height = float(block_b.get('height', 0) or 0)
                b_bottom = b_top + b_height
                b_line_height = float(block_b.get('avg_line_height') or block_b.get('line_height') or 0) or max(1.0, b_height)
                b_source_ops = block_b.get('source_content_ops') or []

                if b_source_ops:
                    continue

                # Don't merge across a bold paragraph lead-in like
                # "Farming and fishing." — that's the start of a new paragraph
                # and anything after it belongs to that new paragraph, not to
                # block_a. Stop scanning further candidates entirely.
                if _block_starts_with_bold_paragraph_lead_in(block_b):
                    same_column = abs(a_left - float(block_b.get('left', 0) or 0)) <= 12
                    if same_column:
                        break
                    continue

                style_match = _style_key(block_a) == _style_key(block_b)
                if not style_match:
                    # Allow merging when block_a opens with a bold lead-in
                    # ("Higher income taxpayers. If your adjusted gross") and
                    # block_b is the regular-body continuation. The block-level
                    # style may carry the bold flag forward through earlier
                    # merges, but they're the same paragraph.
                    a_is_bold_lead_in = (
                        _block_has_regular_continuation_after_bold(block_a)
                        and not bool(block_b.get('bold'))
                    )
                    if not a_is_bold_lead_in:
                        continue
                    if _style_key_relaxed(block_a) != _style_key_relaxed(block_b):
                        continue

                if abs(a_left - b_left) > 3:
                    continue

                horizontal_overlap = min(a_left + a_width, b_left + b_width) - max(a_left, b_left)
                min_width = min(a_width, b_width)
                if min_width <= 0 or horizontal_overlap / min_width < 0.6:
                    continue

                vertical_gap = b_top - a_bottom
                base_gap = max(8.0, max(a_line_height, b_line_height) * 1.15)
                right_edge_delta = abs((a_left + a_width) - (b_left + b_width))
                a_line_count = int(block_a.get('line_count', 0) or 0)
                b_line_count = int(block_b.get('line_count', 0) or 0)
                multiline_fragment = (
                    a_line_count > 1
                    or b_line_count > 1
                )
                similar_column_width = right_edge_delta <= max(24.0, max(a_line_height, b_line_height) * 2.0)
                shorter_fragment_line_count = min(a_line_count or 1, b_line_count or 1)
                width_ratio = (min_width / max(a_width, b_width)) if max(a_width, b_width) > 0 else 0.0
                short_lead_paragraph_pair = (
                    multiline_fragment
                    and shorter_fragment_line_count <= 2
                    and width_ratio <= 0.82
                )
                single_line_blank_separator_pair = (
                    a_line_count == 1
                    and b_line_count == 1
                    and similar_column_width
                )
                a_text_lines = block_a.get('text_lines') or [block_a.get('text') or '']
                b_text_lines = block_b.get('text_lines') or [block_b.get('text') or '']
                a_primary_line = str(a_text_lines[0] if a_text_lines else block_a.get('text') or '').strip()
                b_primary_line = str(b_text_lines[0] if b_text_lines else block_b.get('text') or '').strip()
                heading_prefix = b_primary_line.split(':', 1)[0].strip() if ':' in b_primary_line else ''
                lower_starts_heading_like_followup = (
                    heading_prefix != ''
                    and len(heading_prefix) <= 24
                    and 1 <= len(heading_prefix.split()) <= 4
                    and re.match(r'^[A-Z][A-Za-z0-9&/().,\- ]*$', heading_prefix) is not None
                )
                single_line_text_length = max(len(a_primary_line), len(b_primary_line))
                single_line_visual_width = max(a_width, b_width)
                compact_single_line_pair = (
                    single_line_text_length <= 14
                    or single_line_visual_width <= max(60.0, max(a_line_height, b_line_height) * 5.5)
                )
                sentence_like_single_line_pair = (
                    min(len(a_primary_line), len(b_primary_line)) >= 24
                    and a_primary_line.count(' ') >= 3
                    and b_primary_line.count(' ') >= 3
                    and (
                        any(a_primary_line.endswith(mark) for mark in ('.', ':', ';', '!', '?'))
                        or any(b_primary_line.endswith(mark) for mark in ('.', ':', ';', '!', '?'))
                    )
                )
                single_line_blank_separator_pair = (
                    single_line_blank_separator_pair
                    and compact_single_line_pair
                    and sentence_like_single_line_pair
                )
                substantial_multiline_pair = (
                    a_line_count >= 3
                    and b_line_count >= 3
                )
                tight_continuation_gap = max(4.0, max(a_line_height, b_line_height) * 0.55)
                if substantial_multiline_pair and vertical_gap > tight_continuation_gap:
                    # Two real paragraphs in the same column often sit about one line-height
                    # apart. Treat substantial multiline blocks as separate paragraphs unless
                    # they are almost touching; otherwise edit/export state inherits merged
                    # line geometry and reflows incorrectly after save/download.
                    continue
                if (
                    lower_starts_heading_like_followup
                    and a_line_count >= 3
                    and vertical_gap >= max(4.0, max(a_line_height, b_line_height) * 0.4)
                ):
                    continue
                compatible_column_shape = similar_column_width or short_lead_paragraph_pair
                max_allowed_gap = base_gap
                widened_blank_line_pair = (
                    (multiline_fragment and compatible_column_shape)
                    or single_line_blank_separator_pair
                )
                if widened_blank_line_pair:
                    # Blank lines inside one saved paragraph produce a larger synthetic
                    # gap than ordinary wrapped lines, but should still stay in one block.
                    max_allowed_gap = max(max_allowed_gap, max(a_line_height, b_line_height) * 2.35)
                if vertical_gap < -1.0 or vertical_gap > max_allowed_gap:
                    continue

                merge_target_index = candidate_index
                break

            if merge_target_index is None:
                new_blocks.append(block_a)
                used.add(original_index)
                continue

            block_b = page_blocks[merge_target_index]
            absorbed_block_num = block_b['block_num']
            surviving_block_num = block_a['block_num']
            merge_map[absorbed_block_num] = surviving_block_num

            merged_left = min(block_a['left'], block_b['left'])
            merged_top = min(block_a['top'], block_b['top'])
            merged_right = max(block_a['left'] + block_a['width'], block_b['left'] + block_b['width'])
            merged_bottom = max(block_a['top'] + block_a['height'], block_b['top'] + block_b['height'])

            insert_blank_separator = widened_blank_line_pair and vertical_gap > (max(a_line_height, b_line_height) * 1.4)
            separator_lines = [''] if insert_blank_separator else []
            separator_line_bboxes = []
            if insert_blank_separator:
                blank_height = max(a_line_height, b_line_height)
                blank_top = max(a_bottom, b_top - blank_height)
                blank_bottom = min(b_top, blank_top + blank_height)
                if blank_bottom <= blank_top:
                    blank_bottom = blank_top + blank_height
                separator_line_bboxes.append([merged_left, blank_top, merged_left, blank_bottom])

            merged_block = {
                **block_a,
                'block_num': surviving_block_num,
                'left': merged_left,
                'top': merged_top,
                'width': merged_right - merged_left,
                'height': merged_bottom - merged_top,
                'text_lines': (block_a.get('text_lines') or []) + separator_lines + (block_b.get('text_lines') or []),
                'spans': (block_a.get('spans') or []) + (block_b.get('spans') or []),
                'line_bboxes': (block_a.get('line_bboxes') or []) + separator_line_bboxes + (block_b.get('line_bboxes') or []),
                'line_count': int(block_a.get('line_count', 0) or 0) + len(separator_lines) + int(block_b.get('line_count', 0) or 0),
                'source_content_ops': _dedupe_source_content_ops((block_a.get('source_content_ops') or []) + (block_b.get('source_content_ops') or [])),
                'has_mixed_styles': bool(block_a.get('has_mixed_styles')) or bool(block_b.get('has_mixed_styles')),
            }
            merged_block['text'] = '\n'.join(merged_block['text_lines'])
            merged_block['text_single_line'] = ' '.join(merged_block['text_lines'])

            all_heights = [bbox[3] - bbox[1] for bbox in merged_block.get('line_bboxes', [])]
            if all_heights:
                merged_block['avg_line_height'] = sum(all_heights) / len(all_heights)
                merged_block['line_height'] = merged_block['avg_line_height']

            new_blocks.append(merged_block)
            used.add(original_index)
            used.add(merge_target_index)
            changed = True

        page_blocks = new_blocks

    old_to_new = {}
    for idx, block in enumerate(page_blocks):
        old_to_new[block['block_num']] = idx
        block['block_num'] = idx

    def resolve_block_num(old_num):
        visited = set()
        current = old_num
        while current in merge_map and current not in visited:
            visited.add(current)
            current = merge_map[current]
        return old_to_new.get(current)

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

    for line in page_lines:
        old_bn = line['block_num']
        if old_bn in old_to_new:
            line['block_num'] = old_to_new[old_bn]
        elif old_bn in merge_map:
            new_bn = resolve_block_num(old_bn)
            if new_bn is not None:
                line['block_num'] = new_bn

    for i in reversed(words_to_remove):
        del page_words[i]

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
            text_flags = (
                fitz.TEXT_PRESERVE_LIGATURES
                | fitz.TEXT_PRESERVE_WHITESPACE
                | accurate_bboxes_flag
                | accurate_ascenders_flag
            )
            # Use sort parameter to help with better block detection.
            text_dict = page.get_text("dict", flags=text_flags, sort=True)
            blocks = text_dict.get("blocks", [])
            page_fonts = page.get_fonts(full=True)
            page_font_metadata = _build_page_font_metadata(page_fonts)
            trace_index = _build_texttrace_index(page)
            trace_char_records = _build_texttrace_char_records(page)
            widget_rects = _collect_widget_rects(page)
            source_content_op_index = _build_source_content_op_index(doc, page)
            opaque_fill_occluders = _build_opaque_fill_occluders(page)
            horizontal_lines = _collect_horizontal_lines(page)
            vertical_lines = _collect_vertical_lines(page)
            drawn_box_rects = _collect_drawn_box_rects(page)
            filled_bg_rects = _collect_filled_text_background_rects(page)
            symbol_char_spans = _collect_symbol_char_spans(page)
            page_link_regions = _collect_page_link_regions(page)
            used_link_region_ids = set()

            if _page_requires_synthetic_form_grouping(
                blocks,
                horizontal_lines,
                vertical_lines,
                widget_rects,
                drawn_box_rects,
            ):
                blocks = _build_synthetic_form_blocks(
                    blocks,
                    vertical_lines=vertical_lines,
                    widget_rects=widget_rects,
                    horizontal_lines=horizontal_lines,
                    drawn_box_rects=drawn_box_rects,
                )

            # Pre-fetch PyMuPDF word-level positions. Used below to split spans
            # that mix regular text with underscore
            # placeholder runs so each sub-word gets an exact glyph bbox instead of
            # relying on browser font-metric estimates in renderAnchoredUnderscoreSegments.
            page_pymupdf_words = page.get_text('words')
            
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
                            'max_size': line_max_size,
                            'dir': _normalize_line_direction(line.get("dir")),
                            'wmode': int(line.get("wmode", 0) or 0),
                        })

                    # Pre-process: merge lines that share the same visual row.
                    # This prevents inline formatting (e.g., italic book titles) from
                    # being split into separate bounding boxes.
                    line_items = _merge_same_row_lines(
                        line_items,
                        vertical_lines=vertical_lines,
                        widget_rects=widget_rects,
                        drawn_box_rects=drawn_box_rects,
                    )

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
                            x_groups = _split_candidate_lines_by_x_gap(
                                candidate,
                                vertical_lines=vertical_lines,
                                widget_rects=widget_rects,
                                drawn_box_rects=drawn_box_rects,
                            )

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
                                    left_group_bbox = None
                                    right_group_bbox = None
                                    for left_item in x_groups[gi]:
                                        left_group_bbox = _rect_union(left_group_bbox, left_item.get('bbox'))
                                    for right_item in x_groups[gi + 1]:
                                        right_group_bbox = _rect_union(right_group_bbox, right_item.get('bbox'))
                                    has_structural_barrier = (
                                        _rects_have_vertical_barrier(left_group_bbox, right_group_bbox, vertical_lines)
                                        or _rects_have_widget_barrier(left_group_bbox, right_group_bbox, widget_rects)
                                    )
                                    right_min_x = min(it['x0'] for it in x_groups[gi + 1])
                                    left_max_x = max(it['x0'] for it in x_groups[gi])
                                    inter_gap = right_min_x - left_max_x
                                    if inter_gap < large_gap_threshold and not has_structural_barrier:
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
                    normalized_groups = []
                    for group_items in groups:
                        regrouped_items = _split_same_row_group_by_barriers(
                            group_items,
                            vertical_lines,
                            widget_rects,
                            drawn_box_rects=drawn_box_rects,
                        )
                        if len(regrouped_items) > 1:
                            for regrouped in regrouped_items:
                                for regrouped_item in regrouped:
                                    regrouped_item['_from_xgap_split'] = True
                            normalized_groups.extend(regrouped_items)
                        else:
                            normalized_groups.append(group_items)
                    groups = normalized_groups if normalized_groups else groups
                    form_split_groups = []
                    for group_items in groups:
                        form_split_groups.extend(_split_stacked_form_header_groups(group_items))
                    groups = form_split_groups if form_split_groups else groups

                    # Color-change split: PyMuPDF can emit one block that mixes
                    # paragraphs of very different colors (e.g. dark-grey body
                    # text stacked against a white-on-colored-bar note). Separate
                    # those so each annotation carries the correct color and its
                    # own tight bounding box. Also splits on font family / weight
                    # transitions (heading vs body) even without a color change.
                    color_split_groups = []
                    for group_items in groups:
                        split = _split_group_by_color_change(group_items)
                        if len(split) > 1:
                            for sub in split:
                                for it in sub:
                                    it['_from_xgap_split'] = True
                            color_split_groups.extend(split)
                        else:
                            color_split_groups.append(group_items)
                    groups = color_split_groups if color_split_groups else groups

                    # Filled-background split: lines sitting on a distinct
                    # opaque filled rectangle (colored section-header bar,
                    # shaded note, table-row band) are separated from lines
                    # outside that rectangle. This is how Acrobat's reflow
                    # engine segments colored callouts — the design element,
                    # not the text metrics, is the paragraph-boundary signal.
                    if filled_bg_rects:
                        bg_split_groups = []
                        for group_items in groups:
                            split = _split_group_by_filled_background(group_items, filled_bg_rects)
                            if len(split) > 1:
                                for sub in split:
                                    for it in sub:
                                        it['_from_xgap_split'] = True
                                bg_split_groups.extend(split)
                            else:
                                bg_split_groups.append(group_items)
                        groups = bg_split_groups if bg_split_groups else groups

                    # Width-jump split: narrow column-label lines directly
                    # adjacent to wide descriptor lines (e.g. realtor-form
                    # "Bedroom/Breakfast/Kitchen" labels stacked on top of
                    # "Bathroom Desc: ..." full-width rows) should not share a
                    # block. Thresholds are intentionally strict to avoid
                    # triggering on justified body text whose last line is short.
                    width_split_groups = []
                    for group_items in groups:
                        split = _split_group_by_line_width_jump(group_items)
                        if len(split) > 1:
                            for sub in split:
                                for it in sub:
                                    it['_from_xgap_split'] = True
                            width_split_groups.extend(split)
                        else:
                            width_split_groups.append(group_items)
                    groups = width_split_groups if width_split_groups else groups

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
                            line_dir = item.get('dir') or _normalize_line_direction(line.get("dir"))
                            line_wmode = int(item.get('wmode', line.get("wmode", 0)) or 0)
                            line_rotation = _line_rotation_degrees(line_dir)
                            line_text = ""
                            line_bbox = item['bbox']

                            line_spans = []
                            line_style = None
                            line_source_content_ops = []
                            line_origin = None
                            line_word_start_idx = len(page_words)

                            for span_num, span in enumerate(line.get("spans", [])):
                                text = sanitize_extracted_text(span.get("text", ""))
                                text = _apply_line_direction_text_order(text, line_dir)
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

                                trace_visible_span = _rebuild_visible_text_from_trace_bbox(
                                    trace_char_records,
                                    bbox,
                                    font,
                                    size,
                                    widget_rects,
                                )
                                if trace_visible_span:
                                    rebuilt_text = trace_visible_span.get("text") or text
                                    rebuilt_text = _apply_line_direction_text_order(rebuilt_text, line_dir)
                                    if _trace_text_can_replace_original_text(text, rebuilt_text):
                                        text = rebuilt_text
                                        rebuilt_bbox = trace_visible_span.get("bbox")
                                        if rebuilt_bbox and len(rebuilt_bbox) >= 4:
                                            bbox = rebuilt_bbox
                                        rebuilt_origin = trace_visible_span.get("origin")
                                        if rebuilt_origin:
                                            origin = rebuilt_origin
                                elif _should_skip_widget_intersecting_span(text, bbox, widget_rects):
                                    continue

                                if not text or not text.strip():
                                    continue

                                source_content_op = _match_source_content_op(
                                    source_content_op_index,
                                    text,
                                    origin,
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
                                _span_suffix_tokens = [p for p in re.split(r'[-_,]', font_lower) if p][1:]
                                _span_has_blk = any(t.startswith('blk') or t == 'hvy' for t in _span_suffix_tokens)
                                _span_has_bd = any(t == 'bd' or t.startswith('bd') for t in _span_suffix_tokens)
                                is_bold_from_name = ('bold' in font_lower or 'black' in font_lower
                                                     or 'heavy' in font_lower or _span_has_blk
                                                     or _span_has_bd)
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
                                    elif 'demi' in font_lower:
                                        # "Demi" weight (e.g. ITCFranklinGothicStd-Demi) → semi-bold
                                        font_weight = 600
                                    elif 'extrabold' in font_lower or 'ultrabold' in font_lower:
                                        font_weight = 800
                                    elif 'black' in font_lower or 'heavy' in font_lower or _span_has_blk:
                                        font_weight = 900
                                    elif _span_has_bd or is_bold_from_name or is_bold_from_flags:
                                        # Covers compound tokens like "bdit" (Bold Italic), "bdou" (Bold Outline)
                                        font_weight = 700
                                    else:
                                        font_weight = 400
                                
                                # Combine flags and name inference (name takes precedence)
                                is_bold = is_bold_from_name or is_bold_from_flags
                                is_italic = is_italic_from_name or is_italic_from_flags

                                font_xref = match_font_xref(font, page_fonts)
                                embedded_font_meta = _resolve_embedded_font_meta(
                                    font,
                                    font_xref,
                                    page_font_metadata,
                                )

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
                                    'bbox': list(bbox) if bbox else None,
                                    'ascender': ascender,
                                    'descender': descender,
                                    'origin': list(origin) if origin else None,  # (x, y) baseline point
                                    'direction': list(line_dir) if line_dir else None,
                                    'writing_mode': line_wmode,
                                    'rotation': line_rotation,
                                    'line_width': trace_linewidth,
                                    'render_type': trace_render_type,
                                    'space_width': trace_spacewidth,
                                    'source_content_ops': source_content_ops,
                                }
                                link_region = _find_link_region_for_bbox(bbox, page_link_regions)
                                if link_region:
                                    used_link_region_ids.add(link_region.get('index'))
                                    span_data['is_link'] = True
                                    if link_region.get('uri'):
                                        span_data['link_uri'] = link_region['uri']
                                    if link_region.get('kind'):
                                        span_data['link_kind'] = link_region['kind']
                                    if link_region.get('page') is not None:
                                        span_data['link_page'] = link_region['page']
                                _attach_embedded_font_fields(span_data, embedded_font_meta)
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
                                    _attach_embedded_font_fields(line_style, embedded_font_meta)

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
                                        'line_height': (bbox[3] - bbox[1]) if bbox else (line_bbox[3] - line_bbox[1]),
                                        'ascender': ascender,
                                        'descender': descender
                                    }
                                    _attach_embedded_font_fields(block_style, embedded_font_meta)

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
                                    'direction': list(line_dir) if line_dir else None,
                                    'writing_mode': line_wmode,
                                    'rotation': line_rotation,
                                    'line_width': trace_linewidth,
                                    'render_type': trace_render_type,
                                    'space_width': trace_spacewidth,
                                    'source_content_ops': source_content_ops,
                                }
                                if link_region:
                                    word_data['is_link'] = True
                                    if link_region.get('uri'):
                                        word_data['link_uri'] = link_region['uri']
                                    if link_region.get('kind'):
                                        word_data['link_kind'] = link_region['kind']
                                    if link_region.get('page') is not None:
                                        word_data['link_page'] = link_region['page']
                                _attach_embedded_font_fields(word_data, embedded_font_meta)

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
                                        if se.get('is_link'):
                                            used_link_region_ids.add(se.get('_link_region_index'))
                                        page_words.append(se)
                                        block_word_bboxes.append((
                                            se['left'], se['top'],
                                            se['left'] + se['width'], se['top'] + se['height']
                                        ))
                                    line_text += " ".join(se['text'] for se in split_entries) + " "
                                else:
                                    if word_data.get('is_link'):
                                        used_link_region_ids.add(word_data.get('_link_region_index'))
                                    page_words.append(word_data)
                                    block_word_bboxes.append(bbox)
                                    line_text += text + " "
                                total_words += len(text.split())

                            synthetic_link_span = None
                            if line_text.strip():
                                effective_line_style = dict(line_style or {})
                                if line_dir:
                                    effective_line_style['direction'] = list(line_dir)
                                effective_line_style['writing_mode'] = line_wmode
                                effective_line_style['rotation'] = line_rotation
                                synthetic_link_span = _build_missing_link_span_for_line(
                                    line_text,
                                    line_bbox,
                                    line_spans,
                                    effective_line_style,
                                    page_link_regions,
                                    used_link_region_ids,
                                )
                            if synthetic_link_span:
                                line_spans.append(synthetic_link_span)
                                block_spans.append(synthetic_link_span)
                                used_link_region_ids.add(synthetic_link_span.get('_link_region_index'))
                                link_bbox = synthetic_link_span.get('bbox') or line_bbox
                                if isinstance(link_bbox, (list, tuple)) and len(link_bbox) >= 4:
                                    block_word_bboxes.append(tuple(float(value) for value in link_bbox[:4]))
                                synthetic_word = {
                                    'text': synthetic_link_span['text'],
                                    'render_text': synthetic_link_span['render_text'],
                                    'suppress_drawn_underline': False,
                                    'has_drawn_underline': False,
                                    'left': link_bbox[0],
                                    'top': link_bbox[1],
                                    'width': link_bbox[2] - link_bbox[0],
                                    'height': link_bbox[3] - link_bbox[1],
                                    'origin_x': synthetic_link_span['origin'][0],
                                    'origin_y': synthetic_link_span['origin'][1],
                                    'font': synthetic_link_span['font'],
                                    'font_xref': synthetic_link_span.get('font_xref'),
                                    'font_size': synthetic_link_span['font_size'],
                                    'font_weight': synthetic_link_span['font_weight'],
                                    'color': synthetic_link_span['color'],
                                    'hex_color': synthetic_link_span['hex_color'],
                                    'bold': synthetic_link_span['bold'],
                                    'italic': synthetic_link_span['italic'],
                                    'block_num': block_counter,
                                    'line_num': item['line_num'],
                                    'span_num': len(line_spans) - 1,
                                    'ascender': synthetic_link_span.get('ascender'),
                                    'descender': synthetic_link_span.get('descender'),
                                    'direction': synthetic_link_span.get('direction'),
                                    'writing_mode': synthetic_link_span.get('writing_mode'),
                                    'rotation': synthetic_link_span.get('rotation'),
                                    'line_width': None,
                                    'render_type': None,
                                    'space_width': None,
                                    'source_content_ops': [],
                                    'is_link': True,
                                    'link_uri': synthetic_link_span.get('link_uri'),
                                    'link_kind': synthetic_link_span.get('link_kind'),
                                    'link_page': synthetic_link_span.get('link_page'),
                                }
                                _attach_embedded_font_fields(synthetic_word, {
                                    'clean_name': synthetic_link_span.get('embedded_font_name'),
                                    'family': synthetic_link_span.get('embedded_font_family'),
                                    'font_xref': synthetic_link_span.get('embedded_font_xref'),
                                } if synthetic_link_span.get('embedded_font_name') else None)
                                page_words.append(synthetic_word)
                                sorted_line_spans = sorted(
                                    [span for span in line_spans if isinstance(span, dict)],
                                    key=lambda span: (
                                        float((span.get('bbox') or [0, 0, 0, 0])[1]),
                                        float((span.get('bbox') or [0, 0, 0, 0])[0]),
                                    ),
                                )
                                line_text = ''.join(str(span.get('text') or '') for span in sorted_line_spans)

                            if line_text.strip():
                                visible_line_bboxes = [
                                    tuple(map(float, span.get('bbox')[:4]))
                                    for span in line_spans
                                    if isinstance(span.get('bbox'), (list, tuple)) and len(span.get('bbox')) >= 4
                                ]
                                if visible_line_bboxes:
                                    effective_line_bbox = (
                                        min(bbox[0] for bbox in visible_line_bboxes),
                                        min(bbox[1] for bbox in visible_line_bboxes),
                                        max(bbox[2] for bbox in visible_line_bboxes),
                                        max(bbox[3] for bbox in visible_line_bboxes),
                                    )
                                else:
                                    effective_line_bbox = line_bbox

                                page_text_lines.append(line_text.strip())
                                block_text_lines.append(line_text.strip())
                                block_line_bboxes.append(effective_line_bbox)
                                block_line_heights.append(effective_line_bbox[3] - effective_line_bbox[1])

                                line_data = {
                                    'text': line_text.strip(),
                                    'left': effective_line_bbox[0],
                                    'top': effective_line_bbox[1],
                                    'width': effective_line_bbox[2] - effective_line_bbox[0],
                                    'height': effective_line_bbox[3] - effective_line_bbox[1],
                                    'block_num': block_counter,
                                    'line_num': item['line_num'],
                                    'direction': list(line_dir) if line_dir else None,
                                    'writing_mode': line_wmode,
                                    'rotation': line_rotation,
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
                        unique_line_rotations = sorted({
                            round(float(line.get('rotation', 0.0) or 0.0), 4)
                            for line in page_lines
                            if line.get('block_num') == block_counter
                        })
                        unique_line_directions = [
                            line.get('direction')
                            for line in page_lines
                            if line.get('block_num') == block_counter and line.get('direction')
                        ]
                        if len(unique_line_rotations) == 1:
                            current_block['rotation'] = unique_line_rotations[0]
                        if len(unique_line_directions) == 1:
                            current_block['direction'] = unique_line_directions[0]
                        if block_style:
                            if block_line_heights:
                                block_style['line_height'] = sum(block_line_heights) / len(block_line_heights)
                            current_block.update(block_style)

                        if block_text_lines or block_spans or block_word_bboxes:
                            page_blocks.append(current_block)
                            block_counter += 1

            # Post-process: merge blocks that sit on the same visual line
            # This catches cases where PyMuPDF reports inline text as separate blocks
            page_blocks = _merge_adjacent_page_blocks(
                page_blocks,
                page_width,
                page_words,
                page_lines,
                widget_rects=widget_rects,
                vertical_lines=vertical_lines,
                drawn_box_rects=drawn_box_rects,
            )
            page_blocks = _merge_stacked_paragraph_blocks(page_blocks, page_words, page_lines)
            page_blocks = _split_blocks_on_list_marker_callouts(
                page_blocks,
                page_words,
                page_lines,
                horizontal_lines=horizontal_lines,
            )

            page_lines = _dedupe_near_duplicate_text_entries(
                page_lines,
                text_key='text',
                left_key='left',
                top_key='top',
                width_key='width',
                height_key='height',
                font_key='font',
                font_size_key='font_size',
            )

            page_blocks = [
                _dedupe_block_text_lines(block)
                for block in page_blocks
            ]

            # ── Word-level deduplication ──────────────────────────────
            # PyMuPDF can emit duplicate spans for OCR layers, font
            # re-encoding, or ligature splitting. Remove near-identical
            # entries to prevent stacked/doubled glyphs in the overlay.
            deduped_words = _dedupe_near_duplicate_text_entries(
                page_words,
                text_key='text',
                left_key='left',
                top_key='top',
                width_key='width',
                height_key='height',
                font_key='font',
                font_size_key='font_size',
            )
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
                'drawn_box_rects': [list(rect[:4]) for rect in (drawn_box_rects or [])],
                'widget_rects': [list(rect[:4]) for rect in (widget_rects or [])],
                'symbol_char_spans': symbol_char_spans,
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
    cursor = connection.cursor(buffered=True)
    
    try:
        # Check if table exists, use the correct table name
        table_name = 'pdf_extractions_fitz'
        
        # Check if record exists - prioritize user_email over session_id if not guest
        existing = None
        if user_email and user_email != 'guest':
            # For authenticated users, match by document_id and user_email
            cursor.execute(
                f"SELECT id FROM {table_name} WHERE document_id = %s AND user_email = %s ORDER BY id DESC LIMIT 1",
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
                f"SELECT id FROM {table_name} WHERE document_id = %s AND session_id = %s ORDER BY id DESC LIMIT 1",
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
