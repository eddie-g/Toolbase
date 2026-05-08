#!/usr/bin/env python3
"""
move_text_inplace.py — surgical content-stream text *move*.

Companion to rewrite_tj_inplace.py. Given a list of moves of the form

  [
    {"page": 0, "original_text": "Foo", "occurrence": 0,
     "dx_pts": 12.5, "dy_pts": -4.0},
    ...
  ]

…locates the matching /Tj or /TJ operator on the page and shifts that one
operator's text-rendering position by (dx_pts, dy_pts) in user-space points
WITHOUT moving any other operator on the page.

Strategy (per the design discussion):

  1. Walk the page content stream simulating the full text state machine
     (Tm, Tlm, Tf font + size, Tc, Tw, Tz, TL).
  2. For the matched Tj/TJ:
       a. If the IMMEDIATELY PRECEDING positioning operator is `Tm` and
          the original text-state walk shows that Tm is consumed only by
          this run (i.e. nothing else between this Tm and the matched op
          besides whitespace / Tf / colour ops that don't affect position),
          rewrite that Tm's e/f directly. Cheapest, no extra bytes.
       b. Otherwise: splice two new Tm operators around the target —
          a "shift" Tm (current simulated Tm with e/f += dx,dy) immediately
          before the target, and a "restore" Tm immediately after that
          undoes the shift AND adds the glyph-string advance the target
          would have produced (so subsequent operators see exactly the
          Tm they would have without the move).

PDF coordinate convention: y points UP. Frontend deltas come from a screen
drag where y points down; callers must already flip the sign before posting.

For TJ operators we collapse the kerning array into the measured advance
when computing the post-shift restore. Glyph widths come from the font's
/Widths (Type1/Truetype) or /W (CID/Type0) entry, falling back to 500/1000
of the font size for any unknown glyph (acceptable because the result is
only used for the restore-Tm; the visible glyph stream is NOT re-encoded).
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from typing import Optional

import pikepdf
from pikepdf import Name, Pdf, String, ContentStreamInstruction
from pikepdf.objects import Array

# Reuse the encoder + ToUnicode parser from the rewrite path.
from rewrite_tj_inplace import (
    FontEncoder,
    _font_encoder_for,
    _operand_string_bytes,
    _ensure_helvetica_resource,
)


def parse_args():
    p = argparse.ArgumentParser()
    p.add_argument("--in", dest="input_path", required=True)
    p.add_argument("--out", dest="output_path", required=True)
    p.add_argument("--moves", help="JSON file with moves")
    p.add_argument("--reflows", help="JSON file with reflows")
    return p.parse_args()


# --- Glyph width lookup ------------------------------------------------------

def _build_width_table(font_obj) -> dict[int, float]:
    """Return {charcode_or_cid: width_in_1000ths_of_em}."""
    widths: dict[int, float] = {}
    try:
        if font_obj.get("/Subtype") == Name("/Type0") or Name("/DescendantFonts") in font_obj:
            descendants = font_obj.get("/DescendantFonts")
            if descendants is not None and len(descendants) > 0:
                cid_font = descendants[0]
                w = cid_font.get("/W")
                default = float(cid_font.get("/DW", 1000)) if cid_font.get("/DW", None) is not None else 1000.0
                # /W array forms:
                #   c [w1 w2 w3 ...]   -> codes c, c+1, c+2 each get w_i
                #   c_first c_last w   -> codes c_first..c_last all get w
                if w is not None:
                    i = 0
                    arr = list(w)
                    while i < len(arr):
                        first = int(arr[i])
                        nxt = arr[i + 1]
                        if isinstance(nxt, (Array, list)):
                            for k, ww in enumerate(nxt):
                                widths[first + k] = float(ww)
                            i += 2
                        else:
                            last = int(nxt)
                            ww = float(arr[i + 2])
                            for c in range(first, last + 1):
                                widths[c] = ww
                            i += 3
                # store default under sentinel -1
                widths[-1] = default
        else:
            first = int(font_obj.get("/FirstChar", 0))
            ws = font_obj.get("/Widths")
            missing_width = float(font_obj.get("/MissingWidth", 0)) if font_obj.get("/MissingWidth", None) is not None else 0.0
            if ws is not None:
                for k, ww in enumerate(ws):
                    widths[first + k] = float(ww)
            widths[-1] = missing_width or 500.0
    except Exception:
        widths[-1] = 500.0
    if -1 not in widths:
        widths[-1] = 500.0
    return widths


def _measure_advance_pts(
    raw_bytes: bytes,
    encoder: FontEncoder,
    widths: dict[int, float],
    font_size: float,
    char_space: float,
    word_space: float,
    h_scale: float,
) -> float:
    """Return the horizontal advance (in user-space pts) of `raw_bytes`
    when rendered through `encoder.font` at the given text state.

    Per PDF 1.7 §9.4.4:
        tx = ((w0 - Tj/1000) * Tfs + Tc + Tw_if_space) * Th
    where w0 = width in 1000-units, Tfs = font size, Tc = char spacing,
    Tw applies only to the single-byte space (0x20) glyph, Th = horiz scale.
    Tj here is the per-glyph displacement from a TJ kern; for Tj operands
    it's zero (we measure the operand's natural advance).
    """
    if not raw_bytes:
        return 0.0
    bpc = encoder.bytes_per_code
    advance = 0.0
    if bpc == 2:
        i = 0
        while i + 1 < len(raw_bytes):
            cc = (raw_bytes[i] << 8) | raw_bytes[i + 1]
            w0 = widths.get(cc, widths[-1])
            tx = ((w0 / 1000.0) * font_size + char_space) * h_scale
            advance += tx
            i += 2
    else:
        for b in raw_bytes:
            w0 = widths.get(b, widths[-1])
            extra_tw = word_space if b == 0x20 else 0.0
            tx = ((w0 / 1000.0) * font_size + char_space + extra_tw) * h_scale
            advance += tx
    return advance


# --- Text state walker -------------------------------------------------------

def _identity():
    return [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]  # a,b,c,d,e,f


def _matmul(m1, m2):
    a1, b1, c1, d1, e1, f1 = m1
    a2, b2, c2, d2, e2, f2 = m2
    return [
        a1 * a2 + b1 * c2,
        a1 * b2 + b1 * d2,
        c1 * a2 + d1 * c2,
        c1 * b2 + d1 * d2,
        e1 * a2 + f1 * c2 + e2,
        e1 * b2 + f1 * d2 + f2,
    ]


def _translate(m, tx, ty):
    return _matmul([1.0, 0.0, 0.0, 1.0, tx, ty], m)


def _inverse(m):
    """Inverse of a 2D affine [a,b,c,d,e,f]. Returns identity if singular."""
    a, b, c, d, e, f = m
    det = a * d - b * c
    if det == 0:
        return _identity()
    ia = d / det
    ib = -b / det
    ic = -c / det
    id_ = a / det
    ie = -(ia * e + ic * f)
    if_ = -(ib * e + id_ * f)
    return [ia, ib, ic, id_, ie, if_]


def _apply_point(m, x, y):
    """Apply affine `m` to point (x,y). PDF convention [a,b,c,d,e,f]."""
    a, b, c, d, e, f = m
    return (a * x + c * y + e, b * x + d * y + f)


def _xobject_dict_for_page(page):
    """Return the page's /Resources /XObject dict (or None)."""
    try:
        res = page.get("/Resources", None)
        if res is None:
            return None
        return res.get("/XObject", None)
    except Exception:
        return None


def _xobject_fonts(xobj):
    """Return the XObject's /Resources /Font dict (or None)."""
    try:
        res = xobj.get("/Resources", None)
        if res is None:
            return None
        return res.get("/Font", None)
    except Exception:
        return None


def walk_and_locate(commands, fonts_dict, target_text: str, occurrence: int):
    """Walk the content stream simulating text state. Return a list of
    candidate hits, each a dict with everything needed to splice a move:

        {
            "idx":              int,         # commands[idx] is the Tj/TJ
            "op":               "Tj" | "TJ",
            "tm":               [a,b,c,d,e,f],   # active Tm at the op
            "tlm":              [a,b,c,d,e,f],   # active Tlm at the op
            "font_size":        float,
            "char_space":       float,
            "word_space":       float,
            "h_scale":          float (1.0 = 100%),
            "encoder":          FontEncoder,
            "widths":           dict[int, float],
            "raw":              bytes,        # operand bytes (decoded text matches)
            "prev_positioner":  Optional[int], # idx of Tm/Td/TD/T* immediately
                                               # before the op, with no other text
                                               # state mutation in between
            "advance_pts":      float,        # horizontal advance the op would consume
        }
    """
    tm = _identity()
    tlm = _identity()
    font_size = 0.0
    char_space = 0.0
    word_space = 0.0
    h_scale = 1.0
    leading = 0.0
    encoder: Optional[FontEncoder] = None
    widths: dict[int, float] = {-1: 500.0}
    in_text = False
    last_positioner_idx: Optional[int] = None
    last_positioner_clean = False  # True if no other state mutation since

    hits: list[dict] = []

    def reset_text_state_at_BT():
        nonlocal tm, tlm, last_positioner_idx, last_positioner_clean
        tm = _identity()
        tlm = _identity()
        last_positioner_idx = None
        last_positioner_clean = False

    for idx, instr in enumerate(commands):
        op = str(instr.operator)

        if op == "BT":
            in_text = True
            reset_text_state_at_BT()
            continue
        if op == "ET":
            in_text = False
            last_positioner_idx = None
            last_positioner_clean = False
            continue

        if op == "Tf":
            if len(instr.operands) >= 2:
                font_key = str(instr.operands[0]).lstrip("/")
                font_size = float(instr.operands[1])
                font_obj = None
                try:
                    if fonts_dict is not None and Name(f"/{font_key}") in fonts_dict:
                        font_obj = fonts_dict[Name(f"/{font_key}")]
                except Exception:
                    font_obj = None
                if font_obj is not None:
                    try:
                        encoder = _font_encoder_for(font_obj)
                        widths = _build_width_table(font_obj)
                    except Exception:
                        encoder = None
            last_positioner_clean = False
            continue
        if op == "Tc":
            char_space = float(instr.operands[0])
            last_positioner_clean = False
            continue
        if op == "Tw":
            word_space = float(instr.operands[0])
            last_positioner_clean = False
            continue
        if op == "Tz":
            h_scale = float(instr.operands[0]) / 100.0
            last_positioner_clean = False
            continue
        if op == "TL":
            leading = float(instr.operands[0])
            last_positioner_clean = False
            continue

        # Positioning ops mutate Tm/Tlm.
        if op == "Tm":
            a, b, c, d, e, f = (float(x) for x in instr.operands)
            tm = [a, b, c, d, e, f]
            tlm = [a, b, c, d, e, f]
            last_positioner_idx = idx
            last_positioner_clean = True
            continue
        if op in ("Td", "TD"):
            tx = float(instr.operands[0])
            ty = float(instr.operands[1])
            if op == "TD":
                leading = -ty
            tlm = _translate(tlm, tx, ty)
            tm = list(tlm)
            last_positioner_idx = idx
            last_positioner_clean = True
            continue
        if op == "T*":
            tlm = _translate(tlm, 0.0, -leading)
            tm = list(tlm)
            last_positioner_idx = idx
            last_positioner_clean = True
            continue

        # Text-showing ops.
        if op in ("Tj", "'", '"'):
            if not in_text or encoder is None or not instr.operands:
                last_positioner_clean = False
                continue
            if op in ("'", '"'):
                # ' and " perform a T* first; " also sets Tw and Tc.
                if op == '"':
                    word_space = float(instr.operands[0])
                    char_space = float(instr.operands[1])
                tlm = _translate(tlm, 0.0, -leading)
                tm = list(tlm)
            operand = instr.operands[-1]
            raw = _operand_string_bytes(operand)
            text = encoder.decode_bytes(raw)
            if text == target_text or " ".join(text.split()) == " ".join(target_text.split()):
                advance = _measure_advance_pts(
                    raw, encoder, widths, font_size, char_space, word_space, h_scale
                )
                hits.append({
                    "idx": idx,
                    "op": "Tj",
                    "tm": list(tm),
                    "tlm": list(tlm),
                    "font_size": font_size,
                    "char_space": char_space,
                    "word_space": word_space,
                    "h_scale": h_scale,
                    "encoder": encoder,
                    "widths": widths,
                    "raw": raw,
                    "prev_positioner": last_positioner_idx if last_positioner_clean else None,
                    "advance_pts": advance,
                })
            # Tj advances Tm by the rendered width along the text-line direction.
            adv = _measure_advance_pts(raw, encoder, widths, font_size, char_space, word_space, h_scale)
            tm = _translate(tm, adv, 0.0)
            last_positioner_clean = False
            continue
        if op == "TJ":
            if not in_text or encoder is None or not instr.operands:
                last_positioner_clean = False
                continue
            arr = instr.operands[0]
            text_parts = []
            total_adv = 0.0
            raw_concat = bytearray()
            for elem in arr:
                if isinstance(elem, (String, bytes)):
                    eb = _operand_string_bytes(elem)
                    text_parts.append(encoder.decode_bytes(eb))
                    total_adv += _measure_advance_pts(eb, encoder, widths, font_size, char_space, word_space, h_scale)
                    raw_concat.extend(eb)
                else:
                    # Numeric kern: subtract (n/1000)*Tfs*h_scale from advance.
                    total_adv -= (float(elem) / 1000.0) * font_size * h_scale
            text = "".join(text_parts)
            if text == target_text or " ".join(text.split()) == " ".join(target_text.split()):
                hits.append({
                    "idx": idx,
                    "op": "TJ",
                    "tm": list(tm),
                    "tlm": list(tlm),
                    "font_size": font_size,
                    "char_space": char_space,
                    "word_space": word_space,
                    "h_scale": h_scale,
                    "encoder": encoder,
                    "widths": widths,
                    "raw": bytes(raw_concat),
                    "prev_positioner": last_positioner_idx if last_positioner_clean else None,
                    "advance_pts": total_adv,
                })
            tm = _translate(tm, total_adv, 0.0)
            last_positioner_clean = False
            continue

        # Any other op invalidates "preceding positioner is clean".
        last_positioner_clean = False

    if occurrence < 0 or occurrence >= len(hits):
        return None, hits
    return hits[occurrence], hits


def walk_all_text_ops(commands, fonts_dict):
    """Variant of walk_and_locate that returns *every* Tj/TJ on the page
    with its simulated text state. Used by bbox-based moves where we
    need to shift every text op whose origin lies in a target rect."""
    return walk_and_locate(commands, fonts_dict, target_text=None, occurrence=0)[1] \
        if False else _walk_all(commands, fonts_dict)


def _walk_all(commands, fonts_dict, xobjects=None, _ctm_to_page=None, _xobj_path=None, _seen=None):
    """Walk a content-stream `commands` list, simulating text state.

    When `xobjects` is provided, recurses into Form XObjects via the
    `Do` operator, accumulating CTM (q/Q stack + cm). Each returned hit
    carries:
      * `tm` — text matrix in the LOCAL stream's coord space
      * `page_x, page_y` — Tm origin transformed into PAGE coords via
        the accumulated CTM stack (= ctm_to_page * (tm.e, tm.f))
      * `xobj_key` — the `Name` of the Form XObject this hit lives in
        (None if the hit is in the page stream itself)
      * `xobj_path` — list of Names representing nested Do calls (empty
        for page-level hits)
      * `ctm_to_page` — the accumulated CTM that maps LOCAL coords →
        PAGE coords. Identity for page-level hits.
      * `ctm_scale` — sqrt(a*a+b*b) of `ctm_to_page` (for effective glyph
        size = font_size * tm_scale * ctm_scale).
    """
    if _ctm_to_page is None:
        _ctm_to_page = _identity()
    if _xobj_path is None:
        _xobj_path = []
    if _seen is None:
        _seen = set()

    tm = _identity()
    tlm = _identity()
    font_size = 0.0
    char_space = 0.0
    word_space = 0.0
    h_scale = 1.0
    leading = 0.0
    encoder: Optional[FontEncoder] = None
    widths: dict[int, float] = {-1: 500.0}
    in_text = False
    last_positioner_idx: Optional[int] = None
    last_positioner_clean = False
    font_key: Optional[str] = None
    hits: list[dict] = []
    # CTM stack: each entry is the local CTM (relative to the parent
    # frame). The "current" ctm_to_page is _ctm_to_page * product(stack).
    ctm_stack: list[list] = [_identity()]

    def _current_ctm_to_page():
        """Compute accumulated CTM that maps current local point → page."""
        m = list(_ctm_to_page)
        for entry in ctm_stack:
            m = _matmul(entry, m)
        return m

    for idx, instr in enumerate(commands):
        op = str(instr.operator)
        # Graphics state stack ----------------------------------------
        if op == "q":
            ctm_stack.append(_identity())
            continue
        if op == "Q":
            if len(ctm_stack) > 1:
                ctm_stack.pop()
            continue
        if op == "cm":
            try:
                a, b, c, d, e, f = (float(x) for x in instr.operands)
                ctm_stack[-1] = _matmul([a, b, c, d, e, f], ctm_stack[-1])
            except Exception:
                pass
            continue
        # Recurse into Form XObjects ---------------------------------
        if op == "Do" and xobjects is not None and instr.operands:
            try:
                key = instr.operands[0]
                key_name = str(key)
                if key_name in _seen:
                    # Avoid infinite recursion on cyclic refs.
                    continue
                xobj = xobjects.get(key)
                if xobj is None:
                    try:
                        xobj = xobjects[key]
                    except Exception:
                        xobj = None
                if xobj is None:
                    continue
                subtype = None
                try:
                    subtype = str(xobj.get("/Subtype", None))
                except Exception:
                    subtype = None
                if subtype != "/Form":
                    continue
                # Form XObjects can declare a /Matrix that prepends to CTM.
                form_matrix = _identity()
                try:
                    fm = xobj.get("/Matrix", None)
                    if fm is not None:
                        form_matrix = [float(v) for v in list(fm)]
                except Exception:
                    form_matrix = _identity()
                # Build the ctm_to_page that the XObject's stream will use.
                child_ctm = _matmul(form_matrix, _current_ctm_to_page())
                # XObject may have its own /Resources/Font, fall back to
                # the parent font dict if not present.
                child_fonts = _xobject_fonts(xobj) or fonts_dict
                # XObject may also reference further XObjects.
                child_xobjects = None
                try:
                    res = xobj.get("/Resources", None)
                    if res is not None:
                        child_xobjects = res.get("/XObject", None)
                except Exception:
                    child_xobjects = None
                if child_xobjects is None:
                    child_xobjects = xobjects
                try:
                    child_cmds = pikepdf.parse_content_stream(xobj)
                except Exception:
                    child_cmds = None
                if child_cmds is not None:
                    next_seen = _seen | {key_name}
                    next_path = _xobj_path + [key_name]
                    sub_hits = _walk_all(
                        child_cmds, child_fonts,
                        xobjects=child_xobjects,
                        _ctm_to_page=child_ctm,
                        _xobj_path=next_path,
                        _seen=next_seen,
                    )
                    for h in sub_hits:
                        # Stamp the XObject identity so callers know
                        # which stream to operate on. Outer-most XObject
                        # is the first element of xobj_path.
                        if not h.get("xobj_path"):
                            h["xobj_path"] = next_path
                        if not h.get("xobj_key"):
                            h["xobj_key"] = next_path[0] if next_path else None
                    hits.extend(sub_hits)
            except Exception:
                pass
            continue

        # ---- Original text-state handlers ----
        if op == "BT":
            in_text = True; tm = _identity(); tlm = _identity()
            last_positioner_idx = None; last_positioner_clean = False
            continue
        if op == "ET":
            in_text = False; last_positioner_idx = None; last_positioner_clean = False
            continue
        if op == "Tf":
            if len(instr.operands) >= 2:
                font_key = str(instr.operands[0]).lstrip("/")
                font_size = float(instr.operands[1])
                font_obj = None
                try:
                    if fonts_dict is not None and Name(f"/{font_key}") in fonts_dict:
                        font_obj = fonts_dict[Name(f"/{font_key}")]
                except Exception:
                    font_obj = None
                if font_obj is not None:
                    try:
                        encoder = _font_encoder_for(font_obj)
                        widths = _build_width_table(font_obj)
                    except Exception:
                        encoder = None
            last_positioner_clean = False; continue
        if op == "Tc": char_space = float(instr.operands[0]); last_positioner_clean = False; continue
        if op == "Tw": word_space = float(instr.operands[0]); last_positioner_clean = False; continue
        if op == "Tz": h_scale = float(instr.operands[0]) / 100.0; last_positioner_clean = False; continue
        if op == "TL": leading = float(instr.operands[0]); last_positioner_clean = False; continue
        if op == "Tm":
            a, b, c, d, e, f = (float(x) for x in instr.operands)
            tm = [a, b, c, d, e, f]; tlm = [a, b, c, d, e, f]
            last_positioner_idx = idx; last_positioner_clean = True; continue
        if op in ("Td", "TD"):
            tx = float(instr.operands[0]); ty = float(instr.operands[1])
            if op == "TD": leading = -ty
            tlm = _translate(tlm, tx, ty); tm = list(tlm)
            last_positioner_idx = idx; last_positioner_clean = True; continue
        if op == "T*":
            tlm = _translate(tlm, 0.0, -leading); tm = list(tlm)
            last_positioner_idx = idx; last_positioner_clean = True; continue
        if op in ("Tj", "'", '"'):
            if not in_text or encoder is None or not instr.operands:
                last_positioner_clean = False; continue
            if op in ("'", '"'):
                if op == '"':
                    word_space = float(instr.operands[0])
                    char_space = float(instr.operands[1])
                tlm = _translate(tlm, 0.0, -leading); tm = list(tlm)
            operand = instr.operands[-1]
            raw = _operand_string_bytes(operand)
            advance = _measure_advance_pts(raw, encoder, widths, font_size, char_space, word_space, h_scale)
            ctm_now = _current_ctm_to_page()
            page_xy = _apply_point(ctm_now, tm[4], tm[5])
            ctm_scale_now = math.sqrt(ctm_now[0] * ctm_now[0] + ctm_now[1] * ctm_now[1]) or 1.0
            hits.append({
                "idx": idx, "op": "Tj",
                "tm": list(tm), "tlm": list(tlm),
                "font_key": font_key, "leading": leading,
                "font_size": font_size, "char_space": char_space,
                "word_space": word_space, "h_scale": h_scale,
                "encoder": encoder, "widths": widths, "raw": raw,
                "text": encoder.decode_bytes(raw),
                "prev_positioner": last_positioner_idx if last_positioner_clean else None,
                "advance_pts": advance,
                "page_x": page_xy[0], "page_y": page_xy[1],
                "ctm_to_page": list(ctm_now),
                "ctm_scale": ctm_scale_now,
                "xobj_key": _xobj_path[0] if _xobj_path else None,
                "xobj_path": list(_xobj_path),
            })
            tm = _translate(tm, advance, 0.0)
            last_positioner_clean = False; continue
        if op == "TJ":
            if not in_text or encoder is None or not instr.operands:
                last_positioner_clean = False; continue
            arr = instr.operands[0]
            text_parts = []; total_adv = 0.0; raw_concat = bytearray()
            for elem in arr:
                if isinstance(elem, (String, bytes)):
                    eb = _operand_string_bytes(elem)
                    text_parts.append(encoder.decode_bytes(eb))
                    total_adv += _measure_advance_pts(eb, encoder, widths, font_size, char_space, word_space, h_scale)
                    raw_concat.extend(eb)
                else:
                    total_adv -= (float(elem) / 1000.0) * font_size * h_scale
            ctm_now = _current_ctm_to_page()
            page_xy = _apply_point(ctm_now, tm[4], tm[5])
            ctm_scale_now = math.sqrt(ctm_now[0] * ctm_now[0] + ctm_now[1] * ctm_now[1]) or 1.0
            hits.append({
                "idx": idx, "op": "TJ",
                "tm": list(tm), "tlm": list(tlm),
                "font_key": font_key, "leading": leading,
                "font_size": font_size, "char_space": char_space,
                "word_space": word_space, "h_scale": h_scale,
                "encoder": encoder, "widths": widths, "raw": bytes(raw_concat),
                "text": "".join(text_parts),
                "prev_positioner": last_positioner_idx if last_positioner_clean else None,
                "advance_pts": total_adv,
                "page_x": page_xy[0], "page_y": page_xy[1],
                "ctm_to_page": list(ctm_now),
                "ctm_scale": ctm_scale_now,
                "xobj_key": _xobj_path[0] if _xobj_path else None,
                "xobj_path": list(_xobj_path),
            })
            tm = _translate(tm, total_adv, 0.0)
            last_positioner_clean = False; continue
        last_positioner_clean = False
    return hits


# --- Splicing helpers --------------------------------------------------------

def _make_tm(a, b, c, d, e, f):
    return ContentStreamInstruction(
        [a, b, c, d, e, f],
        pikepdf.Operator(b"Tm"),
    )


def apply_move(commands, hit, dx_pts: float, dy_pts: float, force_isolate: bool = False) -> None:
    """Mutate `commands` (list) in place to apply the move.

    When `force_isolate` is True, always splice Tm-shift / Tm-restore
    around the target instead of rewriting a preceding Tm. Required
    when multiple targets share a BT block (bbox-based moves), since
    rewriting one Tm would shift every following op too.
    """
    a, b, c, d, e, f = hit["tm"]
    target_idx = hit["idx"]

    # Strategy A: rewrite the preceding clean Tm if available.
    if not force_isolate and hit["prev_positioner"] is not None:
        prev_idx = hit["prev_positioner"]
        prev_op = str(commands[prev_idx].operator)
        if prev_op == "Tm":
            ops = list(commands[prev_idx].operands)
            new_e = float(ops[4]) + dx_pts
            new_f = float(ops[5]) + dy_pts
            commands[prev_idx] = _make_tm(
                float(ops[0]), float(ops[1]), float(ops[2]), float(ops[3]), new_e, new_f
            )
            return
        if prev_op in ("Td", "TD"):
            tx = float(commands[prev_idx].operands[0]) + dx_pts
            ty = float(commands[prev_idx].operands[1]) + dy_pts
            commands[prev_idx] = ContentStreamInstruction(
                [tx, ty], pikepdf.Operator(prev_op.encode("ascii")),
            )
            return

    # Strategy B: splice Tm-shifted before, Tm-restored after.
    advance = hit["advance_pts"]
    # Shifted Tm: same matrix with translation += (dx, dy).
    shifted = _make_tm(a, b, c, d, e + dx_pts, f + dy_pts)
    # What Tm would have been right after the unmodified Tj/TJ: original
    # Tm advanced by `advance` along text-line direction. In matrix terms
    # that's a translate-right by `advance` in text space.
    after = _translate([a, b, c, d, e, f], advance, 0.0)
    restored = _make_tm(after[0], after[1], after[2], after[3], after[4], after[5])

    # Insert in order: [..., shifted, target, restored, ...]
    commands.insert(target_idx, shifted)
    commands.insert(target_idx + 2, restored)


# --- Per-page entry ----------------------------------------------------------

def move_page(page, pdf, move) -> Optional[str]:
    occurrence = int(move.get("occurrence") or 0)
    dx_pts = float(move.get("dx_pts", 0.0))
    dy_pts = float(move.get("dy_pts", 0.0))
    if abs(dx_pts) < 1e-4 and abs(dy_pts) < 1e-4:
        return None  # nothing to do

    fonts = None
    try:
        res = page.get("/Resources", None)
        if res is not None:
            fonts = res.get("/Font", None)
    except Exception:
        fonts = None

    try:
        commands = pikepdf.parse_content_stream(page)
    except Exception as e:
        return f"parse_content_stream failed: {e}"
    commands = list(commands)

    # Bbox-based move: shift every text op whose origin lies in the rect.
    # Bbox-based move: shift every text-painting op whose origin lies in
    # the rect.
    #
    # Why we rebuild every BT/ET that contains a target instead of
    # splicing Tm-shift / Tm-restore around individual Tj's:
    #
    # PDF text state has *two* independent matrices — the text matrix
    # (`Tm`) and the text line matrix (`Tlm`). `Tj` only mutates `Tm`
    # (advances by glyph width); `Tlm` is untouched. `Td`/`TD`/`T*` all
    # translate `Tlm` and then assign `Tm := Tlm`. There is no PDF op
    # that can set `Tm` to one value while leaving `Tlm` at a different
    # value. So a single restore-`Tm` after the shifted Tj corrupts
    # `Tlm`, and any subsequent `Td` snowballs the shift onto every
    # following text run on the page.
    #
    # The general fix: canonicalise the rest of the BT block. Drop all
    # `Td`/`TD`/`T*` and pin every text-painting op (`Tj`/`TJ`/`'`/`"`)
    # with its own absolute `Tm` derived from the simulated state — and
    # add `(dx, dy)` to that Tm only when the op's origin is in the
    # bbox. This makes `Tlm` irrelevant because every painter restates
    # `Tm` (and `Tlm`) before painting.
    bbox = move.get("source_bbox")
    if bbox:
        try:
            bx = float(bbox["x"]); by = float(bbox["y"])
            bw = float(bbox["w"]); bh = float(bbox["h"])
        except Exception as e:
            return f"invalid source_bbox: {e}"
        pad = 2.0
        x0 = bx - pad; y0 = by - pad
        x1 = bx + bw + pad; y1 = by + bh + pad
        # Optional text filter: when supplied, ONLY shift Tj's whose
        # decoded text matches the original_text. Prevents nearby form
        # captions or other text runs that happen to share the bbox
        # (e.g. after the user has moved one annotation on top of
        # another) from being swept along.
        wanted_text = move.get("original_text")
        wanted_norm = " ".join(wanted_text.split()) if wanted_text else None
        # AcroForm widget rectangles (PDF points). Any Tj whose origin
        # falls inside one of these is locked: it cannot be relocated by
        # this move even if the bbox would otherwise include it.
        lock_rects_raw = move.get("lock_rects") or []
        lock_rects = []
        for lr in lock_rects_raw:
            try:
                lx = float(lr["x"]); ly = float(lr["y"])
                lw = float(lr["w"]); lh = float(lr["h"])
            except Exception:
                continue
            if lw <= 0 or lh <= 0:
                continue
            lock_rects.append((lx, ly, lx + lw, ly + lh))

        def _is_locked(h):
            ex, ey = h["tm"][4], h["tm"][5]
            for (lx0, ly0, lx1, ly1) in lock_rects:
                if lx0 <= ex <= lx1 and ly0 <= ey <= ly1:
                    return True
            return False

        all_hits = _walk_all(commands, fonts)

        def _matches(h):
            if not (x0 <= h["tm"][4] <= x1 and y0 <= h["tm"][5] <= y1):
                return False
            if _is_locked(h):
                return False
            if wanted_norm is None:
                return True
            text_norm = " ".join((h.get("text") or "").split())
            return text_norm == wanted_norm or wanted_norm in text_norm

        target_idxs = {h["idx"] for h in all_hits if _matches(h)}
        if not target_idxs:
            seen = [(round(h["tm"][4], 1), round(h["tm"][5], 1), h["text"][:40])
                    for h in all_hits[:20]]
            extra = f" wanted={wanted_norm!r}" if wanted_norm else ""
            return (
                f"no text ops found inside bbox "
                f"x=[{x0:.1f},{x1:.1f}] y=[{y0:.1f},{y1:.1f}]{extra} "
                f"(saw {len(all_hits)} text ops, first 20 origins+text: {seen!r})"
            )
        # Index hits by command idx for O(1) lookup of simulated state.
        hit_by_idx = {h["idx"]: h for h in all_hits}

        # Walk the command list and emit a new list, transforming each
        # BT...ET that contains any target.
        new_cmds: list = []
        i = 0
        n = len(commands)
        while i < n:
            instr = commands[i]
            op = str(instr.operator)
            if op != "BT":
                new_cmds.append(instr); i += 1; continue
            # Scan forward to the matching ET.
            j = i + 1
            while j < n and str(commands[j].operator) != "ET":
                j += 1
            if j >= n:
                # Malformed; pass through.
                new_cmds.extend(commands[i:]); break
            block = commands[i:j + 1]  # includes BT and ET
            block_has_target = any(
                (i + k) in target_idxs for k in range(len(block))
            )
            if not block_has_target:
                new_cmds.extend(block); i = j + 1; continue
            # Rebuild this BT/ET block.
            new_cmds.append(block[0])  # BT
            for k in range(1, len(block) - 1):
                sub = block[k]
                sub_op = str(sub.operator)
                # Drop relative positioners — text painters below will
                # restate Tm explicitly.
                if sub_op in ("Td", "TD", "T*"):
                    continue
                if sub_op == "Tm":
                    # Drop too — same reason.
                    continue
                if sub_op in ("Tj", "TJ", "'", '"'):
                    abs_idx = i + k
                    h = hit_by_idx.get(abs_idx)
                    if h is None:
                        # Couldn't resolve simulated state (shouldn't
                        # happen); pass op through.
                        new_cmds.append(sub); continue
                    a, b, c, d, e, f = h["tm"]
                    if abs_idx in target_idxs:
                        e += dx_pts; f += dy_pts
                    new_cmds.append(_make_tm(a, b, c, d, e, f))
                    new_cmds.append(sub)
                    continue
                # Tf, Tc, Tw, Tz, TL, colour ops, etc. — pass through.
                new_cmds.append(sub)
            new_cmds.append(block[-1])  # ET
            i = j + 1

        new_stream = pikepdf.unparse_content_stream(new_cmds)
        page.Contents = pdf.make_stream(new_stream)
        return None

    # Text+occurrence move (original single-op path).
    target_text = move.get("original_text")
    if not target_text:
        return "neither source_bbox nor original_text supplied"
    hit, all_hits = walk_and_locate(commands, fonts, target_text, occurrence)
    if hit is None:
        seen = [
            "".join((__d for __d in [str(h.get("op", "")), ":", repr_short(h.get("raw", b""))]))
            for h in all_hits[:10]
        ]
        return (
            f"text not found on page (looked for {target_text!r} occ={occurrence}; "
            f"saw {len(all_hits)} candidates: {seen!r})"
        )

    apply_move(commands, hit, dx_pts, dy_pts)
    new_stream = pikepdf.unparse_content_stream(commands)
    page.Contents = pdf.make_stream(new_stream)
    return None


def repr_short(b) -> str:
    if isinstance(b, (bytes, bytearray)):
        return b[:24].decode("latin-1", errors="replace")
    return repr(b)[:32]


# ============================================================================
# Reflow path: take a paragraph of text inside `source_bbox` and re-wrap it
# inside `new_bbox`, dropping the original Tj's and emitting a fresh BT
# block at new_bbox's top-left. Uses the active font/size/state captured
# from the FIRST hit in the bbox (mixed-font paragraphs lose their
# secondary fonts — that's an acknowledged limitation of this MVP).
# ============================================================================

def _wrap_text_to_width(
    text: str,
    encoder: "FontEncoder",
    widths: dict,
    font_size: float,
    char_space: float,
    word_space: float,
    h_scale: float,
    max_width_pts: float,
) -> list[str]:
    """Greedy word-wrap. Returns list of line strings."""
    words = text.split(" ")
    lines: list[str] = []
    current = ""
    for w in words:
        candidate = w if not current else (current + " " + w)
        try:
            enc, missing = encoder.encode_text(candidate)
        except Exception:
            enc, missing = None, [candidate]
        if enc is None:
            # Glyph missing from subset — give up wrapping; caller will
            # detect zero/short lines and surface an error if needed.
            current = candidate
            continue
        adv = _measure_advance_pts(
            enc, encoder, widths, font_size, char_space, word_space, h_scale
        )
        if adv <= max_width_pts or not current:
            current = candidate
        else:
            lines.append(current)
            current = w
    if current:
        lines.append(current)
    return lines


def _overlay_reflow(
    page, pdf, commands,
    source_bbox, new_bbox, lines, font_size_pts,
) -> Optional[str]:
    """Append a white redaction rect over `source_bbox` and draw `lines`
    in Helvetica at `new_bbox`. Used when no editable Tj/TJ ops live in
    the source area (PDFs whose text is rendered as vector paths).

    Mutates `page.Contents` in place and returns None on success.
    """
    bx, by, bw, bh = source_bbox
    nx, ny, nw, nh = new_bbox
    helv_key = _ensure_helvetica_resource(page)
    Op = pikepdf.Operator
    new_cmds = list(commands)

    # 1) White rect covering the source_bbox (slightly padded).
    pad = 0.5
    rx, ry = bx - pad, by - pad
    rw, rh = bw + 2 * pad, bh + 2 * pad
    new_cmds.append(ContentStreamInstruction([], Op(b"q")))
    new_cmds.append(ContentStreamInstruction([1, 1, 1], Op(b"rg")))
    new_cmds.append(ContentStreamInstruction([rx, ry, rw, rh], Op(b"re")))
    new_cmds.append(ContentStreamInstruction([], Op(b"f")))
    new_cmds.append(ContentStreamInstruction([], Op(b"Q")))

    # 2) Text block at new_bbox, top-anchored.
    leading = font_size_pts * 1.2
    top_y = ny + nh
    first_y = top_y - font_size_pts
    new_cmds.append(ContentStreamInstruction([], Op(b"q")))
    new_cmds.append(ContentStreamInstruction([0, 0, 0], Op(b"rg")))
    new_cmds.append(ContentStreamInstruction([], Op(b"BT")))
    new_cmds.append(ContentStreamInstruction(
        [Name(f"/{helv_key}"), font_size_pts], Op(b"Tf"),
    ))
    new_cmds.append(ContentStreamInstruction([leading], Op(b"TL")))
    new_cmds.append(ContentStreamInstruction(
        [1.0, 0.0, 0.0, 1.0, nx, first_y], Op(b"Tm"),
    ))
    for ix, line in enumerate(lines):
        enc = (line or "").encode("latin-1", errors="replace")
        if ix == 0:
            new_cmds.append(ContentStreamInstruction([String(enc)], Op(b"Tj")))
        else:
            new_cmds.append(ContentStreamInstruction([], Op(b"T*")))
            new_cmds.append(ContentStreamInstruction([String(enc)], Op(b"Tj")))
    new_cmds.append(ContentStreamInstruction([], Op(b"ET")))
    new_cmds.append(ContentStreamInstruction([], Op(b"Q")))

    try:
        new_stream = pikepdf.unparse_content_stream(new_cmds)
        page.Contents = pdf.make_stream(new_stream)
    except Exception as e:
        return f"overlay reflow: failed to write content stream: {e}"
    return None


def reflow_page(page, pdf, req) -> Optional[str]:
    bbox = req.get("source_bbox")
    new_bbox = req.get("new_bbox")
    if not bbox or not new_bbox:
        return "source_bbox and new_bbox are required"
    try:
        bx = float(bbox["x"]); by = float(bbox["y"])
        bw = float(bbox["w"]); bh = float(bbox["h"])
        nx = float(new_bbox["x"]); ny = float(new_bbox["y"])
        nw = float(new_bbox["w"]); nh = float(new_bbox["h"])
    except Exception as e:
        return f"invalid bbox: {e}"
    pad = 2.0
    x0, y0 = bx - pad, by - pad
    x1, y1 = bx + bw + pad, by + bh + pad

    fonts = None
    try:
        res = page.get("/Resources", None)
        if res is not None:
            fonts = res.get("/Font", None)
    except Exception:
        fonts = None
    xobjects = _xobject_dict_for_page(page)
    try:
        commands = pikepdf.parse_content_stream(page)
    except Exception as e:
        return f"parse_content_stream failed: {e}"

    # Lock rects (acroform widgets) — never reflow text whose origin is
    # inside one. Mirrors the move path. Lock rects are page-coords.
    lock_rects = []
    for lr in (req.get("lock_rects") or []):
        try:
            lx = float(lr["x"]); ly = float(lr["y"])
            lw = float(lr["w"]); lh = float(lr["h"])
        except Exception:
            continue
        if lw > 0 and lh > 0:
            lock_rects.append((lx, ly, lx + lw, ly + lh))

    def _is_locked(h):
        ex, ey = h.get("page_x", h["tm"][4]), h.get("page_y", h["tm"][5])
        for (lx0, ly0, lx1, ly1) in lock_rects:
            if lx0 <= ex <= lx1 and ly0 <= ey <= ly1:
                return True
        return False

    all_hits = _walk_all(commands, fonts, xobjects=xobjects)
    target_hits = [
        h for h in all_hits
        if x0 <= h.get("page_x", h["tm"][4]) <= x1
        and y0 <= h.get("page_y", h["tm"][5]) <= y1
        and not _is_locked(h)
    ]
    if not target_hits:
        # Overlay/redact fallback: this PDF either renders text as vector
        # paths or the source bbox doesn't contain a Tj/TJ origin (e.g.
        # the box snaps the bbox to a glyph cluster but the Tm origin is
        # just outside). Paint a white rect over the source area and
        # draw the new text at new_bbox in Helvetica. Loses original
        # font fidelity but works on any PDF.
        client_lines = req.get("lines")
        if isinstance(client_lines, list) and client_lines:
            wrapped = [str(s) for s in client_lines]
        else:
            return (
                f"no text ops in source_bbox and no client-supplied lines for "
                f"overlay fallback "
                f"(x=[{x0:.1f},{x1:.1f}] y=[{y0:.1f},{y1:.1f}])"
            )
        try:
            cs_pts = float(req.get("font_size_pts") or 0)
        except Exception:
            cs_pts = 0.0
        if cs_pts <= 0:
            cs_pts = max(8.0, bh * 0.85)
        return _overlay_reflow(
            page, pdf, commands,
            source_bbox=(bx, by, bw, bh),
            new_bbox=(nx, ny, nw, nh),
            lines=wrapped,
            font_size_pts=cs_pts,
        )

    # Sort by line (y desc), then column (x asc) in PAGE coords.
    target_hits.sort(key=lambda h: (-h.get("page_y", h["tm"][5]), h.get("page_x", h["tm"][4])))
    primary = target_hits[0]

    # All targets must live in the same stream (page or single XObject).
    primary_xkey = primary.get("xobj_key")
    primary_xpath = primary.get("xobj_path") or []
    mismatched = [
        h for h in target_hits
        if (h.get("xobj_key") != primary_xkey)
        or ((h.get("xobj_path") or []) != primary_xpath)
    ]
    if mismatched:
        return (
            f"reflow targets span multiple streams "
            f"(primary xobj={primary_xkey!r}; {len(mismatched)} mismatched)"
        )

    font_key = primary.get("font_key") or ""
    font_size = primary["font_size"] or 12.0
    # Effective glyph size on page = Tf-size * Tm-scale * CTM-scale.
    tm_a, tm_b = primary["tm"][0], primary["tm"][1]
    tm_scale = math.sqrt(tm_a * tm_a + tm_b * tm_b) or 1.0
    ctm_scale = float(primary.get("ctm_scale", 1.0)) or 1.0
    effective_size = font_size * tm_scale * ctm_scale
    char_space = primary["char_space"]
    word_space = primary["word_space"]
    h_scale = primary["h_scale"]
    leading = primary.get("leading") or (effective_size * 1.2)
    if leading <= 0:
        leading = effective_size * 1.2
    encoder = primary["encoder"]
    widths = primary["widths"]

    # If the client provided a font size (measured from the rendered DOM
    # span in PDF points), prefer it — DOM-side metrics are more accurate
    # than our content-stream walk for fonts whose Tm scale we mis-read.
    client_size = req.get("font_size_pts")
    if client_size:
        try:
            cs = float(client_size)
            if cs > 0:
                effective_size = cs
                leading = cs * 1.2
        except Exception:
            pass

    # Option A: when the client did the wrap in the DOM (browser text
    # shaper, font-aware), trust those lines and skip Python wrapping.
    client_lines = req.get("lines")
    paragraph_text = ""
    if isinstance(client_lines, list) and client_lines:
        wrapped = [str(s) for s in client_lines]
        paragraph_text = " ".join(wrapped)
    else:
        # Fall back to extracting text from the bbox and wrapping with
        # the source font's metrics (legacy path).
        lines: list[str] = []
        cur_y = None
        cur_parts: list[str] = []
        line_eps = effective_size * 0.5
        for h in target_hits:
            if cur_y is None or abs(h["tm"][5] - cur_y) <= line_eps:
                cur_parts.append(h["text"])
                if cur_y is None:
                    cur_y = h["tm"][5]
            else:
                lines.append(" ".join(p.strip() for p in cur_parts if p.strip()))
                cur_parts = [h["text"]]
                cur_y = h["tm"][5]
        if cur_parts:
            lines.append(" ".join(p.strip() for p in cur_parts if p.strip()))
        paragraph_text = " ".join(s for s in lines if s)
        if not paragraph_text.strip():
            return "no text content extracted from source_bbox"
        wrapped = _wrap_text_to_width(
            paragraph_text, encoder, widths,
            effective_size, char_space, word_space, h_scale,
            nw,
        )
    if not wrapped:
        return "wrapping produced zero lines"

    # ----- Determine which stream to operate on -----
    # If primary lives in a Form XObject, surgery happens on the
    # XObject's content stream (not the page stream). Coordinates the
    # client gave us are in PAGE space; convert them to LOCAL XObject
    # space via inv(ctm_to_page) before emitting Tm/cm.
    target_xobj = None
    target_xobj_key_name = None
    target_commands = commands
    target_resource_host = page  # for _ensure_helvetica_resource fallback
    primary_ctm = list(primary.get("ctm_to_page") or _identity())
    primary_ctm_inv = _inverse(primary_ctm)
    if primary_xkey is not None and xobjects is not None:
        try:
            # primary_xkey is a string like "/Fm1"; pikepdf accepts Name.
            key_name = Name(primary_xkey) if not str(primary_xkey).startswith("/") else Name(primary_xkey)
            target_xobj = xobjects.get(key_name) or xobjects[key_name]
            target_xobj_key_name = key_name
        except Exception as e:
            return f"failed to resolve XObject {primary_xkey!r}: {e}"
        if target_xobj is None:
            return f"XObject {primary_xkey!r} not found on page"
        try:
            target_commands = pikepdf.parse_content_stream(target_xobj)
        except Exception as e:
            return f"parse XObject {primary_xkey!r} stream failed: {e}"
        target_resource_host = target_xobj

    # Build new BT block painting the wrapped lines at the top of new_bbox.
    # PDF y-axis points up: top of new_bbox is (ny + nh).
    target_idx_set = {h["idx"] for h in target_hits}
    # Walk through; remember the insertion point as the END of the FIRST
    # BT block that contained any target. Inserting the new block at that
    # position (rather than at the very end of the content stream) keeps
    # the new text inside the same CTM/graphics-state context as the
    # original — otherwise any top-level cm scale operator would shrink
    # or distort our reflowed text.
    new_cmds: list = []
    insert_at = None  # index into new_cmds where reflow block should be spliced
    i = 0
    n = len(target_commands)
    while i < n:
        instr = target_commands[i]
        op = str(instr.operator)
        if op != "BT":
            new_cmds.append(instr); i += 1; continue
        j = i + 1
        while j < n and str(target_commands[j].operator) != "ET":
            j += 1
        if j >= n:
            new_cmds.extend(target_commands[i:]); break
        block = target_commands[i:j + 1]
        block_has_target = any((i + k) in target_idx_set for k in range(len(block)))
        if not block_has_target:
            new_cmds.extend(block); i = j + 1; continue
        # Filter: drop the target Tj/TJ; keep state ops + non-target painters.
        new_cmds.append(block[0])  # BT
        for k in range(1, len(block) - 1):
            if (i + k) in target_idx_set:
                continue
            new_cmds.append(block[k])
        new_cmds.append(block[-1])  # ET
        if insert_at is None:
            insert_at = len(new_cmds)
        i = j + 1

    # Build the reflow BT block.
    use_helvetica = False
    encoded_lines = []
    for line in wrapped:
        enc, missing = encoder.encode_text(line)
        if enc is None:
            use_helvetica = True
            break
        encoded_lines.append(enc)

    if use_helvetica:
        # Source font subset can't represent the wrapped text. Fall back
        # to a base-14 Helvetica resource (no embedding required) and
        # latin-1 encode every line. Re-wrap with an approximate
        # Helvetica avg-width-per-char of 0.50 * font_size — close
        # enough for English text without loading AFM metrics.
        helv_key = _ensure_helvetica_resource(target_resource_host)
        font_key = helv_key
        approx_char_w = effective_size * 0.50
        max_chars = max(1, int(nw / max(0.1, approx_char_w)))
        words = paragraph_text.split(" ")
        wrapped = []
        cur = ""
        for w in words:
            cand = w if not cur else (cur + " " + w)
            if len(cand) <= max_chars or not cur:
                cur = cand
            else:
                wrapped.append(cur); cur = w
        if cur: wrapped.append(cur)
        encoded_lines = [line.encode("latin-1", errors="replace") for line in wrapped]

    Op = pikepdf.Operator
    bt = ContentStreamInstruction([], Op(b"BT"))
    et = ContentStreamInstruction([], Op(b"ET"))
    # First line origin in PAGE coords: (nx, top - ascent ~ top - effective_size).
    top_y = ny + nh
    first_y_page = top_y - effective_size
    # Convert origin to LOCAL stream coords. For page-stream this is a no-op.
    first_x_local, first_y_local = _apply_point(primary_ctm_inv, nx, first_y_page)
    # Glyph size: when painted via Tf=size_local with unit Tm, the
    # rendered size on page = size_local * ctm_scale. We want
    # effective_size on page, so size_local = effective_size / ctm_scale.
    size_local = effective_size / (ctm_scale or 1.0)
    leading_local = (leading) / (ctm_scale or 1.0)
    tf = ContentStreamInstruction(
        [Name(f"/{font_key}"), size_local], Op(b"Tf"),
    )
    tl = ContentStreamInstruction([leading_local], Op(b"TL"))
    tm = ContentStreamInstruction(
        [1.0, 0.0, 0.0, 1.0, first_x_local, first_y_local], Op(b"Tm"),
    )
    block = [bt, tf]
    if char_space:
        block.append(ContentStreamInstruction([char_space], Op(b"Tc")))
    if word_space:
        block.append(ContentStreamInstruction([word_space], Op(b"Tw")))
    if h_scale and abs(h_scale - 1.0) > 1e-6:
        block.append(ContentStreamInstruction([h_scale * 100.0], Op(b"Tz")))
    block.append(tl)
    block.append(tm)
    for ix, enc_bytes in enumerate(encoded_lines):
        if ix == 0:
            block.append(ContentStreamInstruction([String(enc_bytes)], Op(b"Tj")))
        else:
            # T* moves to next line at -leading; then Tj.
            block.append(ContentStreamInstruction([], Op(b"T*")))
            block.append(ContentStreamInstruction([String(enc_bytes)], Op(b"Tj")))
    block.append(et)
    if insert_at is None:
        new_cmds.extend(block)
    else:
        new_cmds[insert_at:insert_at] = block

    new_stream = pikepdf.unparse_content_stream(new_cmds)
    if target_xobj is not None:
        # Mutate the XObject's content stream in place. Preserve the
        # XObject's existing dictionary keys (BBox, Matrix, Resources).
        try:
            target_xobj.write(new_stream)
        except Exception as e:
            return f"failed to write XObject {primary_xkey!r}: {e}"
    else:
        page.Contents = pdf.make_stream(new_stream)
    return None


def main():
    args = parse_args()
    if not args.moves and not args.reflows:
        print("ERROR: --moves or --reflows required", file=sys.stderr)
        sys.exit(2)
    pdf = Pdf.open(args.input_path)
    errors = []

    if args.moves:
        with open(args.moves, "rb") as f:
            moves = json.load(f)
        for move in moves:
            page_index = int(move.get("page", 0))
            if page_index < 0 or page_index >= len(pdf.pages):
                errors.append(f"page {page_index} out of range"); continue
            err = move_page(pdf.pages[page_index], pdf, move)
            if err:
                errors.append(f"[move page={page_index}] {err}")

    if args.reflows:
        with open(args.reflows, "rb") as f:
            reflows = json.load(f)
        for r in reflows:
            page_index = int(r.get("page", 0))
            if page_index < 0 or page_index >= len(pdf.pages):
                errors.append(f"page {page_index} out of range"); continue
            err = reflow_page(pdf.pages[page_index], pdf, r)
            if err:
                errors.append(f"[reflow page={page_index}] {err}")

    if errors:
        for e in errors:
            print(f"ERROR: {e}", file=sys.stderr)
        pdf.save(args.output_path)
        sys.exit(2)

    pdf.save(args.output_path)
    print(f"OK: wrote {args.output_path}")


if __name__ == "__main__":
    main()
