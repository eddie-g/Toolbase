#!/usr/bin/env python3
"""
rewrite_tj_inplace.py — surgical content-stream text edits.

Reads a JSON list of edits of the form:
  [
    {"page": 0, "original_text": "Business name", "new_text": "Foo bar",
     "occurrence": 0, "match_xref": null},
    ...
  ]

For each edit, walks the page's content stream(s), finds the first /Tj or /TJ
operator whose decoded text equals `original_text` (skipping `occurrence`
matches first), and rewrites THAT operator only:

  • For /Tj  (single string)  : replace the string operand with the encoded new_text.
  • For /TJ  (array of strings + numeric kerns): collapse the array to a single
    encoded string (drops kerning) — preserves visible text, loses sub-glyph
    spacing for that operator only.

Uses pikepdf for content-stream surgery (PyMuPDF cannot edit existing operator
operands). Output PDF retains every other byte of the original.

Encoding: detects whether the active font on that operator uses a single-byte
encoding (WinAnsi / MacRoman / Identity custom encoding) or a 2-byte CID
mapping, and re-encodes new_text via the font's /ToUnicode mapping inverse
when available, else WinAnsi.

Glyphs missing from the font are reported and the edit is rejected (so the
caller can re-render that operator via the editor's overlay path instead).
"""

from __future__ import annotations

import argparse
import json
import sys
from typing import Optional

import pikepdf
from pikepdf import Pdf, Name, String, Dictionary, Stream, ContentStreamInstruction
from pikepdf.objects import Array


def parse_args():
    p = argparse.ArgumentParser()
    p.add_argument("--in", dest="input_path", required=True)
    p.add_argument("--out", dest="output_path", required=True)
    p.add_argument("--edits", required=True, help="JSON file with edits")
    return p.parse_args()


# --- ToUnicode CMap parsing --------------------------------------------------

def _parse_to_unicode_cmap(cmap_bytes: bytes) -> dict[int, str]:
    """Return {gid_or_charcode_int: unicode_str}. Supports bfchar + bfrange."""
    if not cmap_bytes:
        return {}
    text = cmap_bytes.decode("latin-1", errors="ignore")
    out: dict[int, str] = {}
    import re

    # bfchar entries: <SRC> <DST>
    for m in re.finditer(
        r"beginbfchar\s+(.*?)\s+endbfchar", text, re.DOTALL
    ):
        body = m.group(1)
        for sm in re.finditer(r"<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>", body):
            src = int(sm.group(1), 16)
            dst_hex = sm.group(2)
            dst = bytes.fromhex(dst_hex).decode("utf-16-be", errors="ignore")
            if dst:
                out[src] = dst

    # bfrange entries: <S0> <S1> <DST>  or  <S0> <S1> [<D0> <D1> ...]
    for m in re.finditer(
        r"beginbfrange\s+(.*?)\s+endbfrange", text, re.DOTALL
    ):
        body = m.group(1)
        # Form A: <s0> <s1> <d0>
        for sm in re.finditer(
            r"<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>", body
        ):
            s0 = int(sm.group(1), 16)
            s1 = int(sm.group(2), 16)
            d0_hex = sm.group(3)
            d0 = bytes.fromhex(d0_hex).decode("utf-16-be", errors="ignore")
            if not d0:
                continue
            for k in range(s1 - s0 + 1):
                if len(d0) == 1:
                    out[s0 + k] = chr(ord(d0) + k)
                else:
                    out[s0 + k] = d0[:-1] + chr(ord(d0[-1]) + k)
        # Form B: <s0> <s1> [<d0> <d1> ...]
        for sm in re.finditer(
            r"<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+\[(.*?)\]",
            body,
            re.DOTALL,
        ):
            s0 = int(sm.group(1), 16)
            s1 = int(sm.group(2), 16)
            arr_body = sm.group(3)
            dsts = re.findall(r"<([0-9A-Fa-f]+)>", arr_body)
            for k, dhex in enumerate(dsts):
                if s0 + k > s1:
                    break
                ds = bytes.fromhex(dhex).decode("utf-16-be", errors="ignore")
                if ds:
                    out[s0 + k] = ds
    return out


def _build_text_to_charcode(to_unicode: dict[int, str]) -> dict[str, int]:
    """Inverse map: unicode_char → charcode (single chars only)."""
    inv: dict[str, int] = {}
    for cc, s in to_unicode.items():
        if len(s) == 1 and s not in inv:
            inv[s] = cc
    return inv


# --- per-font encoders -------------------------------------------------------

class FontEncoder:
    def __init__(
        self,
        font_obj,
        widths_per_code: int = 1,
        to_unicode: Optional[dict[int, str]] = None,
    ):
        self.font = font_obj
        self.bytes_per_code = widths_per_code  # 1 or 2
        self.to_unicode = to_unicode or {}
        self.text_to_code = _build_text_to_charcode(self.to_unicode)
        # Also build a "winansi" fallback for simple fonts whose ToUnicode is
        # essentially identity over Latin-1.
        try:
            base_encoding = str(font_obj.get("/Encoding", ""))
        except Exception:
            base_encoding = ""
        self.base_encoding = base_encoding

    def encode_text(self, text: str) -> tuple[Optional[bytes], list[str]]:
        """Encode text into the font's character codes.

        Returns (encoded_bytes, missing_chars). If missing_chars is non-empty,
        the font subset cannot represent those glyphs and the caller MUST
        reject the edit (the alternative produces .notdef tofu boxes).
        """
        if not text:
            return b"", []
        out = bytearray()
        missing: list[str] = []
        for ch in text:
            cc = self.text_to_code.get(ch)
            if cc is None:
                missing.append(ch)
                continue
            if self.bytes_per_code == 1:
                if cc > 0xFF:
                    missing.append(ch)
                    continue
                out.append(cc)
            else:
                out.append((cc >> 8) & 0xFF)
                out.append(cc & 0xFF)
        if missing:
            return None, missing
        return bytes(out), []

    def decode_bytes(self, raw: bytes) -> str:
        """Decode raw operand bytes back into unicode for matching."""
        if not raw:
            return ""
        out = []
        if self.bytes_per_code == 2:
            for i in range(0, len(raw) - 1, 2):
                cc = (raw[i] << 8) | raw[i + 1]
                out.append(self.to_unicode.get(cc, ""))
        else:
            for b in raw:
                s = self.to_unicode.get(b)
                if s is not None:
                    out.append(s)
                else:
                    # Fallback: treat as winansi/Latin-1
                    out.append(chr(b))
        return "".join(out)


def _font_encoder_for(font_obj) -> FontEncoder:
    # Detect 1-byte vs 2-byte encoding by checking for /DescendantFonts (Type0)
    bpc = 1
    if Name("/DescendantFonts") in font_obj or font_obj.get("/Subtype") == Name("/Type0"):
        bpc = 2
    to_unicode_map: dict[int, str] = {}
    try:
        tu_stream = font_obj.get("/ToUnicode")
        if tu_stream is not None:
            cmap_bytes = tu_stream.read_bytes()
            to_unicode_map = _parse_to_unicode_cmap(cmap_bytes)
    except Exception:
        to_unicode_map = {}
    return FontEncoder(font_obj, bpc, to_unicode_map)


# --- content stream rewriting -----------------------------------------------

def _operand_string_bytes(operand) -> bytes:
    """Extract raw bytes from a pikepdf String operand."""
    if isinstance(operand, String):
        return bytes(operand)
    if isinstance(operand, bytes):
        return operand
    return str(operand).encode("latin-1", errors="ignore")


def _walk_text_ops(commands, fonts_dict):
    """
    Iterate (cmd_index, operator_str, operand_text_unicode, font_encoder).
    Tracks active font via /Tf.
    """
    encoder: Optional[FontEncoder] = None
    for idx, instr in enumerate(commands):
        op = str(instr.operator)
        if op == "Tf":
            # operands: [/F1 12]
            if len(instr.operands) >= 1:
                font_key = str(instr.operands[0]).lstrip("/")
                font_obj = None
                try:
                    if fonts_dict is not None and Name(f"/{font_key}") in fonts_dict:
                        font_obj = fonts_dict[Name(f"/{font_key}")]
                except Exception:
                    font_obj = None
                if font_obj is not None:
                    try:
                        encoder = _font_encoder_for(font_obj)
                    except Exception:
                        encoder = None
        elif op in ("Tj", "'", '"'):
            if encoder is None:
                continue
            if not instr.operands:
                continue
            operand = instr.operands[-1]
            raw = _operand_string_bytes(operand)
            text = encoder.decode_bytes(raw)
            yield (idx, op, text, encoder, raw)
        elif op == "TJ":
            if encoder is None:
                continue
            if not instr.operands:
                continue
            arr = instr.operands[0]
            text_parts = []
            for elem in arr:
                if isinstance(elem, String) or isinstance(elem, bytes):
                    text_parts.append(encoder.decode_bytes(_operand_string_bytes(elem)))
            text = "".join(text_parts)
            yield (idx, op, text, encoder, None)


def _ensure_helvetica_resource(page) -> str:
    """Add (or reuse) a base-14 Helvetica font resource on the page.

    Returns the resource key (e.g. 'HelvFB') without the leading slash.
    """
    res = page.get("/Resources")
    if res is None:
        page["/Resources"] = pikepdf.Dictionary()
        res = page["/Resources"]
    fonts = res.get("/Font")
    if fonts is None:
        res["/Font"] = pikepdf.Dictionary()
        fonts = res["/Font"]
    # Reuse if already present (key marker /HelvFB).
    key = "HelvFB"
    if Name(f"/{key}") in fonts:
        return key
    fonts[Name(f"/{key}")] = pikepdf.Dictionary({
        "/Type": Name("/Font"),
        "/Subtype": Name("/Type1"),
        "/BaseFont": Name("/Helvetica"),
        "/Encoding": Name("/WinAnsiEncoding"),
    })
    return key


def _find_active_tf(commands, target_idx) -> tuple[Optional[str], Optional[float]]:
    """Walk backwards from target_idx to find the most recent /Tf.

    Returns (font_key_without_slash, font_size_pts) or (None, None).
    """
    for j in range(target_idx - 1, -1, -1):
        instr = commands[j]
        if str(instr.operator) != "Tf":
            continue
        if len(instr.operands) < 2:
            continue
        try:
            key = str(instr.operands[0]).lstrip("/")
            size = float(instr.operands[1])
            return key, size
        except Exception:
            return None, None
    return None, None


def _rewrite_with_helvetica_fallback(page, commands, target_idx, op, new_text):
    """Replace commands[target_idx] with: Tf Helv size, Tj <text>, Tf orig size.

    Falls back to Helvetica @ 12pt if no preceding /Tf could be located.
    Mutates and returns the commands list.
    """
    helv_key = _ensure_helvetica_resource(page)
    orig_key, orig_size = _find_active_tf(commands, target_idx)
    if orig_size is None:
        orig_size = 12.0
    encoded = new_text.encode("latin-1", errors="replace")

    tf_in = ContentStreamInstruction(
        [Name(f"/{helv_key}"), orig_size], pikepdf.Operator(b"Tf")
    )
    if op == "TJ":
        new_painter = ContentStreamInstruction(
            [Array([String(encoded)])], pikepdf.Operator(b"TJ")
        )
    elif op in ("'", '"'):
        # Preserve leading word/char spacing operands.
        orig_instr = commands[target_idx]
        new_painter = ContentStreamInstruction(
            list(orig_instr.operands[:-1]) + [String(encoded)],
            orig_instr.operator,
        )
    else:
        new_painter = ContentStreamInstruction(
            [String(encoded)], pikepdf.Operator(b"Tj")
        )
    splice = [tf_in, new_painter]
    if orig_key:
        tf_out = ContentStreamInstruction(
            [Name(f"/{orig_key}"), orig_size], pikepdf.Operator(b"Tf")
        )
        splice.append(tf_out)
    commands[target_idx:target_idx + 1] = splice
    return commands


def rewrite_page(page, pdf, edit) -> Optional[str]:
    """Apply one edit to the page. Returns None on success or error string."""
    target_text = edit["original_text"]
    new_text = edit["new_text"]
    occurrence = int(edit.get("occurrence") or 0)

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

    matches = list(_walk_text_ops(commands, fonts))
    candidates = [m for m in matches if m[2] == target_text]
    if not candidates and target_text.strip():
        # Try whitespace-normalized fallback
        norm = " ".join(target_text.split())
        candidates = [m for m in matches if " ".join(m[2].split()) == norm]
    if not candidates:
        return f"text not found on page (looked for {target_text!r}; saw {[m[2] for m in matches[:20]]!r})"
    if occurrence >= len(candidates):
        return f"occurrence {occurrence} > {len(candidates)} matches"
    target_idx, op, _text, encoder, _raw = candidates[occurrence]

    encoded, missing = encoder.encode_text(new_text)
    if encoded is None:
        # Subset font is missing one or more glyphs. Fall back to a
        # standard base-14 Helvetica resource embedded into the page so
        # the user can type any printable Latin-1 character. We splice a
        # /Tf swap before the target Tj and restore the original /Tf
        # immediately after, so subsequent text in the same BT block
        # keeps its original font.
        try:
            new_commands = _rewrite_with_helvetica_fallback(
                page, commands, target_idx, op, new_text,
            )
        except Exception as e:
            uniq_missing = sorted(set(missing))
            return (
                f"new_text contains glyphs not present in the font subset: "
                f"{uniq_missing!r} and Helvetica fallback failed: {e} "
                f"(text={new_text!r})"
            )
        new_stream = pikepdf.unparse_content_stream(new_commands)
        page.Contents = pdf.make_stream(new_stream)
        return None

    instr = commands[target_idx]
    if op == "Tj":
        new_instr = ContentStreamInstruction(
            [String(encoded)], pikepdf.Operator(b"Tj")
        )
        commands[target_idx] = new_instr
    elif op == "TJ":
        # Replace the whole TJ array with [<encoded>] — drops kerning.
        new_instr = ContentStreamInstruction(
            [Array([String(encoded)])], pikepdf.Operator(b"TJ")
        )
        commands[target_idx] = new_instr
    else:
        # ' or " — first operand(s) are word/char spacing; last is the string.
        new_operands = list(instr.operands[:-1]) + [String(encoded)]
        new_instr = ContentStreamInstruction(new_operands, instr.operator)
        commands[target_idx] = new_instr

    new_stream = pikepdf.unparse_content_stream(commands)
    page.Contents = pdf.make_stream(new_stream)
    return None


def main():
    args = parse_args()
    with open(args.edits, "rb") as f:
        edits = json.load(f)

    pdf = Pdf.open(args.input_path)
    errors = []
    for edit in edits:
        page_index = int(edit.get("page", 0))
        if page_index < 0 or page_index >= len(pdf.pages):
            errors.append(f"page {page_index} out of range")
            continue
        page = pdf.pages[page_index]
        err = rewrite_page(page, pdf, edit)
        if err:
            errors.append(f"[edit page={page_index}] {err}")

    if errors:
        for e in errors:
            print(f"ERROR: {e}", file=sys.stderr)
        # Still save what succeeded, but exit nonzero so caller knows.
        pdf.save(args.output_path)
        sys.exit(2)

    pdf.save(args.output_path)
    print(f"OK: wrote {args.output_path}")


if __name__ == "__main__":
    main()
