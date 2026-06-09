#!/usr/bin/env python3
"""
Generate a PDF document from a guided template.

Supports template types: newsletter, nda_agreement, purchase_order, lease_extension,
security_deposit_return.
Reads JSON payload from stdin, writes PDF to the path given as argv[1].
"""
import json
import sys
import os
from datetime import datetime

try:
    import fitz  # PyMuPDF
except ImportError:
    print("ERROR: PyMuPDF (fitz) is required. Install via: pip install PyMuPDF", file=sys.stderr)
    sys.exit(1)


# ─── Font objects (PyMuPDF 1.26+ requires Font objects, not fontname strings) ─
FONT_HELV = fitz.Font("helv")
FONT_HEBO = fitz.Font("hebo")


# ─── Helpers ──────────────────────────────────────────────────────────────────

def _hex(color: str) -> tuple:
    """Convert hex color '#RRGGBB' to (r/255, g/255, b/255)."""
    color = color.lstrip("#")
    return tuple(int(color[i:i+2], 16) / 255.0 for i in (0, 2, 4))


def _draw_wrapped_text(page, text, rect, font=None, fontsize=10, color=(0, 0, 0), align=fitz.TEXT_ALIGN_LEFT, lineheight=1.4):
    """Draw wrapped text in a rectangle, returning the y position after the last line."""
    if font is None:
        font = FONT_HELV
    tw = fitz.TextWriter(page.rect)
    lines = text.split("\n")
    y = rect.y0
    for line in lines:
        words = line.split(" ") if line.strip() else [""]
        current_line = ""
        for word in words:
            test = (current_line + " " + word).strip()
            tl = font.text_length(test, fontsize=fontsize)
            if tl > rect.width and current_line:
                tw.append((rect.x0, y + fontsize), current_line, font=font, fontsize=fontsize)
                y += fontsize * lineheight
                current_line = word
            else:
                current_line = test
        if current_line:
            tw.append((rect.x0, y + fontsize), current_line, font=font, fontsize=fontsize)
            y += fontsize * lineheight
    tw.write_text(page, color=color)
    return y


# ─── AcroForm fill-in fields ──────────────────────────────────────────────────
#
# Guided templates embed real AcroForm text-field widgets at every fillable
# spot. The PDF.js editor (annotationMode=ENABLE_FORMS) renders these as
# typeable inputs by default and persists what the user types — so the guided
# document opens with ready-to-fill fields, mirroring the old guided cards.

# A light highlight + underline makes fields obviously typeable.
_FIELD_FILL = (0.93, 0.96, 1.0)
_FIELD_BORDER = (0.55, 0.62, 0.78)
_TX_MULTILINE_FLAG = 1 << 12  # PDF text field "multiline" flag

# Maps our fitz.Font objects to the standard PDF font name a widget needs.
_WIDGET_FONT_NAMES = {
    "helv": "Helv",
    "hebo": "HeBo",
    "tiro": "TiRo",
    "tibo": "TiBo",
    "tiit": "TiIt",
}


def _widget_font_name(font) -> str:
    try:
        name = (font.name or "").lower()
    except Exception:
        name = ""
    for key, widget_name in _WIDGET_FONT_NAMES.items():
        if key in name:
            return widget_name
    return "Helv"


class _Field:
    """An inline fill-in field placeholder for _flow_fields()."""
    __slots__ = ("name", "value", "min_w")

    def __init__(self, name, value="", min_w=70):
        self.name = name
        self.value = "" if value is None else str(value)
        self.min_w = min_w


def _add_text_field(page, name, rect, value="", fontsize=11, font_name="Helv",
                    multiline=False, color=(0, 0, 0), align=fitz.TEXT_ALIGN_LEFT,
                    fill_color=_FIELD_FILL, border_color=_FIELD_BORDER, border_width=0.6):
    """Add an AcroForm text widget. Returns the created widget."""
    widget = fitz.Widget()
    widget.field_name = name
    widget.field_type = fitz.PDF_WIDGET_TYPE_TEXT
    widget.rect = fitz.Rect(rect)
    widget.text_font = font_name
    widget.text_fontsize = float(fontsize)
    widget.text_color = color
    widget.fill_color = fill_color
    widget.border_color = border_color
    widget.border_width = border_width
    try:
        widget.text_align = align
    except Exception:
        pass
    if multiline:
        widget.field_flags = _TX_MULTILINE_FLAG
    widget.field_value = "" if value is None else str(value)
    page.add_widget(widget)
    return widget


def _flow_fields(page, parts, x, y, width, font, fontsize=11, color=(0, 0, 0),
                 lineheight=1.4, indent=0, gap_after=8, indent_all_lines=False,
                 field_fill_color=_FIELD_FILL, field_border_color=_FIELD_BORDER,
                 field_border_width=0.6, field_pad=5.0):
    """Render a paragraph that mixes static text (str) with inline fill-in
    fields (_Field). Static words wrap normally; each _Field becomes an inline
    AcroForm widget sized to hold its value. Returns y after the last line."""
    space_w = font.text_length(" ", fontsize=fontsize)
    pad = field_pad

    tokens = []
    for part in parts:
        if isinstance(part, _Field):
            vw = font.text_length(part.value or "", fontsize=fontsize)
            tokens.append(("field", part, max(part.min_w, vw + pad * 2)))
        else:
            for word in str(part).split():
                tokens.append(("word", word, font.text_length(word, fontsize=fontsize)))

    line = []
    avail = width - indent
    is_first_line = True

    def flush():
        nonlocal y, is_first_line, line, avail
        line_indent = indent if (is_first_line or indent_all_lines) else 0
        cx = x + line_indent
        tw = fitz.TextWriter(page.rect)
        pending_fields = []
        for i, tok in enumerate(line):
            needs_space = i > 0 and not (
                tok[0] == "word"
                and isinstance(tok[1], str)
                and tok[1][:1] in {".", ",", ";", ":", ")"}
            )
            if needs_space:
                cx += space_w
            if tok[0] == "word":
                tw.append((cx, y + fontsize), tok[1], font=font, fontsize=fontsize)
                cx += tok[2]
            else:
                field = tok[1]
                w = tok[2]
                rect = fitz.Rect(cx, y + fontsize * 0.05, cx + w, y + fontsize * 1.25)
                pending_fields.append((field, rect))
                cx += w
        tw.write_text(page, color=color)
        for field, rect in pending_fields:
            _add_text_field(page, field.name, rect, value=field.value,
                            fontsize=fontsize, font_name=_widget_font_name(font), color=color,
                            fill_color=field_fill_color, border_color=field_border_color,
                            border_width=field_border_width)
        y += fontsize * lineheight
        is_first_line = False
        line = []
        avail = width - (indent if indent_all_lines else 0)

    for tok in tokens:
        w = tok[2]
        add = w + (space_w if line else 0)
        if line and (sum((space_w if i else 0) + t[2] for i, t in enumerate(line)) + add) > avail:
            flush()
            line = [tok]
        else:
            line.append(tok)
    if line:
        flush()

    return y + gap_after


# ─── Newsletter Generator ────────────────────────────────────────────────────

def generate_newsletter(data: dict, output_path: str, slug: str = "newsletter_classic"):
    W, H = 612, 792  # Letter size
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)
    M = 50  # margin

    title = data.get("newsletter_title", "")
    edition = data.get("edition", "")
    date_str = data.get("date", datetime.now().strftime("%B %Y"))
    company = data.get("company_name", "")
    headline = data.get("headline", "")
    intro = data.get("intro_text", "")
    s1_title = data.get("section1_title", "")
    s1_body = data.get("section1_body", "")
    s2_title = data.get("section2_title", "")
    s2_body = data.get("section2_body", "")
    footer = data.get("footer_text", f"© {datetime.now().year} {company}. All rights reserved.")

    if slug == "newsletter_modern":
        _generate_modern_newsletter(page, W, H, M, title, edition, date_str, company,
                                     headline, intro, s1_title, s1_body, s2_title, s2_body, footer)
    else:
        _generate_classic_newsletter(page, W, H, M, title, edition, date_str, company,
                                      headline, intro, s1_title, s1_body, s2_title, s2_body, footer)

    doc.need_appearances(True)
    doc.save(output_path)
    doc.close()


def _generate_classic_newsletter(page, W, H, M, title, edition, date_str, company,
                                   headline, intro, s1_title, s1_body, s2_title, s2_body, footer):
    blue = _hex("#2563eb")
    dark = _hex("#1e293b")
    gray = _hex("#64748b")
    light_blue = _hex("#eff6ff")

    # ── Header Banner ──
    header_rect = fitz.Rect(0, 0, W, 80)
    page.draw_rect(header_rect, color=None, fill=blue)

    _add_text_field(page, "nl_title", fitz.Rect(M, 20, W - M, 42),
                    value=title, fontsize=20, font_name="HeBo", color=(1, 1, 1))
    _add_text_field(page, "nl_edition", fitz.Rect(M, 46, M + 220, 62),
                    value=(f"{edition}  ·  {date_str}" if edition else date_str),
                    fontsize=10, font_name="Helv", color=dark)
    _add_text_field(page, "nl_company", fitz.Rect(W - M - 200, 46, W - M, 62),
                    value=company, fontsize=10, font_name="HeBo", color=dark, align=fitz.TEXT_ALIGN_RIGHT)

    y = 104

    # ── Headline ──
    _add_text_field(page, "nl_headline", fitz.Rect(M, y, W - M, y + 28),
                    value=headline, fontsize=18, font_name="HeBo", color=dark)
    y += 40

    # ── Intro ──
    _add_text_field(page, "nl_intro", fitz.Rect(M, y, W - M, y + 70),
                    value=intro, fontsize=11, font_name="Helv", color=gray, multiline=True)
    y += 86

    # ── Divider ──
    page.draw_line(fitz.Point(M, y), fitz.Point(W - M, y), color=_hex("#e2e8f0"), width=1)
    y += 20

    # ── Two-column sections ──
    col_w = (W - 2 * M - 24) / 2

    for i, (sec_field, sec_title, sec_body) in enumerate([
        ("nl_section1", s1_title, s1_body),
        ("nl_section2", s2_title, s2_body),
    ]):
        col_x = M + i * (col_w + 24)

        # Section card background
        card_rect = fitz.Rect(col_x, y, col_x + col_w, y + 220)
        page.draw_rect(card_rect, color=_hex("#bfdbfe"), fill=light_blue, width=0.5, radius=0.1)

        _add_text_field(page, f"{sec_field}_title", fitz.Rect(col_x + 10, y + 10, col_x + col_w - 10, y + 28),
                        value=sec_title, fontsize=12, font_name="HeBo", color=blue)
        _add_text_field(page, f"{sec_field}_body", fitz.Rect(col_x + 10, y + 34, col_x + col_w - 10, y + 212),
                        value=sec_body, fontsize=10, font_name="Helv", color=gray, multiline=True)

    y += 240

    # ── Footer ──
    page.draw_line(fitz.Point(M, H - 60), fitz.Point(W - M, H - 60), color=_hex("#e2e8f0"), width=0.5)
    _add_text_field(page, "nl_footer", fitz.Rect(M, H - 52, W - M, H - 36),
                    value=footer, fontsize=8, font_name="Helv", color=gray)


def _generate_modern_newsletter(page, W, H, M, title, edition, date_str, company,
                                  headline, intro, s1_title, s1_body, s2_title, s2_body, footer):
    purple = _hex("#7c3aed")
    blue = _hex("#2563eb")
    dark = _hex("#1e293b")
    gray = _hex("#64748b")

    # ── Gradient header (simulated with two rects) ──
    half = W / 2
    page.draw_rect(fitz.Rect(0, 0, half, 90), color=None, fill=purple)
    page.draw_rect(fitz.Rect(half, 0, W, 90), color=None, fill=blue)
    # Blend overlay
    page.draw_rect(fitz.Rect(half - 60, 0, half + 60, 90), color=None, fill=_hex("#5b31d4"), overlay=True)

    _add_text_field(page, "nl_title", fitz.Rect(M, 22, W - M - 80, 46),
                    value=title, fontsize=22, font_name="HeBo", color=(1, 1, 1))
    _add_text_field(page, "nl_edition", fitz.Rect(M, 52, M + 240, 68),
                    value=(f"{edition}  ·  {date_str}" if edition else date_str),
                    fontsize=10, font_name="Helv", color=dark)

    # Pill button
    pill_text = "Read →"
    pill_w = fitz.get_text_length(pill_text, fontname="hebo", fontsize=9) + 20
    pill_rect = fitz.Rect(W - M - pill_w, 30, W - M, 52)
    page.draw_rect(pill_rect, color=None, fill=(1, 1, 1, 0.2), radius=0.5)
    tw = fitz.TextWriter(page.rect)
    tw.append((pill_rect.x0 + 10, 46), pill_text, font=FONT_HEBO, fontsize=9)
    tw.write_text(page, color=(1, 1, 1))

    y = 110

    # ── Headline ──
    _add_text_field(page, "nl_headline", fitz.Rect(M, y, W - M, y + 28),
                    value=headline, fontsize=18, font_name="HeBo", color=dark)
    y += 40

    # ── Intro ──
    _add_text_field(page, "nl_intro", fitz.Rect(M, y, W - M, y + 70),
                    value=intro, fontsize=11, font_name="Helv", color=gray, multiline=True)
    y += 86

    # ── Card sections ──
    card_colors = [purple, blue]
    for i, (sec_field, sec_title, sec_body) in enumerate([
        ("nl_section1", s1_title, s1_body),
        ("nl_section2", s2_title, s2_body),
    ]):
        card_rect = fitz.Rect(M, y, W - M, y + 100)
        page.draw_rect(card_rect, color=_hex("#e2e8f0"), fill=(1, 1, 1), width=0.75, radius=0.15)

        # Accent bar
        bar_rect = fitz.Rect(M + 6, y + 10, M + 10, y + 90)
        page.draw_rect(bar_rect, color=None, fill=card_colors[i], radius=0.05)

        _add_text_field(page, f"{sec_field}_title", fitz.Rect(M + 18, y + 14, W - M - 14, y + 32),
                        value=sec_title, fontsize=12, font_name="HeBo", color=dark)
        _add_text_field(page, f"{sec_field}_body", fitz.Rect(M + 18, y + 36, W - M - 14, y + 94),
                        value=sec_body, fontsize=10, font_name="Helv", color=gray, multiline=True)
        y += 116

    # ── Footer ──
    page.draw_line(fitz.Point(M, H - 60), fitz.Point(W - M, H - 60), color=_hex("#e2e8f0"), width=0.5)
    _add_text_field(page, "nl_footer", fitz.Rect(M, H - 52, W - M, H - 36),
                    value=footer, fontsize=8, font_name="Helv", color=gray)


# ─── NDA Agreement Generator ─────────────────────────────────────────────────

# Serif fonts for formal legal documents
FONT_TIRO = fitz.Font("tiro")   # Times-Roman
FONT_TIBO = fitz.Font("tibo")   # Times-Bold
FONT_TIIT = fitz.Font("tiit")   # Times-Italic


def _nda_new_page(doc, W, H, M):
    """Create a new NDA page with standard legal margins."""
    page = doc.new_page(width=W, height=H)
    return page


def _nda_write(page, x, y, text, font=None, fontsize=11, color=(0, 0, 0)):
    """Write a single line of text. Returns the TextWriter for chaining if needed."""
    if font is None:
        font = FONT_TIRO
    tw = fitz.TextWriter(page.rect)
    tw.append((x, y), text, font=font, fontsize=fontsize)
    tw.write_text(page, color=color)


def _nda_centered(page, W, y, text, font=None, fontsize=11, color=(0, 0, 0)):
    """Write centered text on the page."""
    if font is None:
        font = FONT_TIBO
    tw = fitz.TextWriter(page.rect)
    text_w = font.text_length(text, fontsize=fontsize)
    tw.append(((W - text_w) / 2, y), text, font=font, fontsize=fontsize)
    tw.write_text(page, color=color)


def _nda_wrapped(page, text, rect, font=None, fontsize=11, color=(0, 0, 0), lineheight=1.35, indent=0):
    """Draw wrapped text in a rectangle. Returns y after last line."""
    if font is None:
        font = FONT_TIRO
    tw = fitz.TextWriter(page.rect)
    lines = text.split("\n")
    y = rect.y0
    first_line = True
    for line in lines:
        words = line.split(" ") if line.strip() else [""]
        current_line = ""
        x0 = rect.x0 + (indent if first_line else 0)
        max_w = rect.width - (indent if first_line else 0)
        for word in words:
            test = (current_line + " " + word).strip()
            tl = font.text_length(test, fontsize=fontsize)
            if tl > max_w and current_line:
                tw.append((x0, y + fontsize), current_line, font=font, fontsize=fontsize)
                y += fontsize * lineheight
                current_line = word
                first_line = False
                x0 = rect.x0
                max_w = rect.width
            else:
                current_line = test
        if current_line:
            tw.append((x0, y + fontsize), current_line, font=font, fontsize=fontsize)
            y += fontsize * lineheight
            first_line = False
            x0 = rect.x0
            max_w = rect.width
    tw.write_text(page, color=color)
    return y


def generate_nda(data: dict, output_path: str):
    """Generate a formal legal NDA document matching standard confidentiality agreement format."""
    W, H = 612, 792  # US Letter
    doc = fitz.open()
    M = 72  # 1-inch margins
    BLACK = (0, 0, 0)
    BODY_SIZE = 11
    HEADING_SIZE = 11
    TITLE_SIZE = 14
    LINE_H = 1.35
    CONTENT_W = W - 2 * M

    effective_date = data.get("effective_date", "")
    party1 = data.get("party1_name", "")
    party1_addr = data.get("party1_address", "")
    party2 = data.get("party2_name", "")
    party2_addr = data.get("party2_address", "")
    purpose = data.get("purpose", "")
    term_years = data.get("term_years", "") or "2"
    governing_law = data.get("governing_law", "") or "State of Delaware"

    page = _nda_new_page(doc, W, H, M)
    y = 60

    # ═══════════════════════════════════════════════════════════════════
    # PAGE 1 — Title, Parties, Recitals, and opening sections
    # ═══════════════════════════════════════════════════════════════════

    # ── Title ──
    _nda_centered(page, W, y, "NON-DISCLOSURE AND CONFIDENTIALITY AGREEMENT", font=FONT_TIBO, fontsize=TITLE_SIZE, color=BLACK)
    y += 30

    # ── Date line (label + fill-in field) ──
    _nda_write(page, M, y, "Effective Date:", font=FONT_TIBO, fontsize=BODY_SIZE, color=BLACK)
    _add_text_field(page, "nda_effective_date",
                    fitz.Rect(M + 90, y - BODY_SIZE, M + 290, y + 4),
                    value=effective_date, fontsize=BODY_SIZE, font_name="TiRo")
    y += 28

    # ── Horizontal rule ──
    page.draw_line(fitz.Point(M, y), fitz.Point(W - M, y), color=BLACK, width=1)
    y += 22

    # ── Parties Introduction ──
    y = _flow_fields(
        page,
        [
            'This Non-Disclosure and Confidentiality Agreement (this "Agreement") is entered into as of',
            _Field("nda_effective_date", effective_date, min_w=140),
            '(the "Effective Date"), by and between:',
        ],
        M, y, W - 2 * M, FONT_TIRO, fontsize=BODY_SIZE, lineheight=LINE_H,
    )
    y += 4

    # Party 1 block
    y = _flow_fields(
        page,
        [
            _Field("nda_party1_name", party1, min_w=170),
            '("Disclosing Party"), with a principal place of business at',
            _Field("nda_party1_address", party1_addr.replace(chr(10), ", "), min_w=200),
            ';',
        ],
        M + 36, y, W - M - (M + 36), FONT_TIRO, fontsize=BODY_SIZE, lineheight=LINE_H,
    )

    # "and"
    _nda_write(page, M, y + BODY_SIZE, "and", font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK)
    y += BODY_SIZE * LINE_H + 8

    # Party 2 block
    y = _flow_fields(
        page,
        [
            _Field("nda_party2_name", party2, min_w=170),
            '("Receiving Party"), with a principal place of business at',
            _Field("nda_party2_address", party2_addr.replace(chr(10), ", "), min_w=200),
            '.',
        ],
        M + 36, y, W - M - (M + 36), FONT_TIRO, fontsize=BODY_SIZE, lineheight=LINE_H,
    )
    y += 6

    # Collectively
    _nda_write(page, M, y + BODY_SIZE,
               'Each a "Party" and collectively, the "Parties."',
               font=FONT_TIIT, fontsize=BODY_SIZE, color=BLACK)
    y += BODY_SIZE * LINE_H + 18

    # ── Recitals ──
    _nda_write(page, M, y + HEADING_SIZE, "RECITALS", font=FONT_TIBO, fontsize=HEADING_SIZE, color=BLACK)
    y += HEADING_SIZE * LINE_H + 10

    recital_a = (
        f'WHEREAS, the Disclosing Party possesses certain confidential and proprietary information '
        f'relating to its business, operations, products, services, and technical data; and'
    )
    y = _nda_wrapped(page, recital_a, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H, indent=36)
    y += 8

    y = _flow_fields(
        page,
        [
            'WHEREAS, the Receiving Party desires to receive certain Confidential Information for the purpose of',
            _Field("nda_purpose", purpose, min_w=200),
            '; and',
        ],
        M, y, W - 2 * M, FONT_TIRO, fontsize=BODY_SIZE, lineheight=LINE_H, indent=36,
    )

    recital_c = (
        'WHEREAS, the Disclosing Party is willing to disclose such Confidential Information '
        'to the Receiving Party subject to the terms and conditions set forth herein;'
    )
    y = _nda_wrapped(page, recital_c, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H, indent=36)
    y += 8

    now_therefore = (
        'NOW, THEREFORE, in consideration of the mutual covenants and agreements contained herein, '
        'and for other good and valuable consideration, the receipt and sufficiency of which are hereby '
        'acknowledged, the Parties agree as follows:'
    )
    y = _nda_wrapped(page, now_therefore, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIBO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H, indent=36)
    y += 20

    # ═══════════════════════════════════════════════════════════════════
    # Numbered Sections
    # ═══════════════════════════════════════════════════════════════════

    sections = [
        ("1. DEFINITION OF CONFIDENTIAL INFORMATION",
         '"Confidential Information" shall mean any and all non-public, confidential, or proprietary '
         'information disclosed by the Disclosing Party to the Receiving Party, whether disclosed orally, '
         'in writing, electronically, or by inspection of tangible objects, including without limitation: '
         '(a) trade secrets, inventions, ideas, processes, formulae, source and object codes, data, programs, '
         'know-how, improvements, discoveries, developments, designs, and techniques; '
         '(b) information regarding plans for research, development, new products, marketing and selling, '
         'business plans, budgets and unpublished financial statements, licenses, prices and costs, '
         'suppliers, and customers; and '
         '(c) information regarding the skills and compensation of employees, contractors, and any other '
         'service providers of the Disclosing Party.'),

        ("2. OBLIGATIONS OF RECEIVING PARTY",
         'The Receiving Party agrees to: '
         '(a) hold and maintain the Confidential Information in strict confidence using at least the same '
         'degree of care that the Receiving Party uses to protect its own confidential information, '
         'but in no event less than reasonable care; '
         '(b) not to copy, reproduce, or distribute the Confidential Information to any third party without '
         'the prior written approval of the Disclosing Party; '
         '(c) not to use the Confidential Information for any purpose other than as expressly authorized '
         'by this Agreement; '
         '(d) limit access to the Confidential Information to those of its employees, agents, and '
         'representatives who have a need to know such information and who have been informed of the '
         'confidential nature thereof and have agreed to be bound by the terms of this Agreement; and '
         '(e) promptly notify the Disclosing Party in writing of any unauthorized use, disclosure, '
         'or loss of the Confidential Information.'),

        ("3. EXCLUSIONS FROM CONFIDENTIAL INFORMATION",
         'The obligations of the Receiving Party under this Agreement shall not apply to information that: '
         '(a) was in the public domain at the time it was disclosed or has entered the public domain through '
         'no fault of the Receiving Party; '
         '(b) was known to the Receiving Party, without restriction, at the time of disclosure, as demonstrated '
         'by files in existence at the time of disclosure; '
         '(c) becomes known to the Receiving Party, without restriction, from a source other than the '
         'Disclosing Party without breach of this Agreement by the Receiving Party and otherwise not in '
         'violation of the Disclosing Party\'s rights; '
         '(d) is independently developed by the Receiving Party without use of or reference to the '
         'Disclosing Party\'s Confidential Information; or '
         '(e) is required to be disclosed by law, regulation, or court order, provided that the Receiving Party '
         'gives the Disclosing Party prompt written notice of such requirement prior to disclosure and '
         'cooperates with the Disclosing Party in seeking a protective order.'),

        ("4. RETURN OF CONFIDENTIAL INFORMATION",
         'Upon the written request of the Disclosing Party, the Receiving Party shall promptly return or '
         'destroy all documents, materials, and other tangible manifestations of Confidential Information '
         'in its possession or control, including all copies, extracts, and summaries thereof, and shall '
         'certify in writing that it has complied with this provision. Notwithstanding the foregoing, the '
         'Receiving Party may retain one (1) archival copy of the Confidential Information solely for the '
         'purpose of determining its obligations under this Agreement.'),

        ("5. NO LICENSE OR WARRANTY",
         'Nothing in this Agreement is intended to grant any rights to the Receiving Party under any patent, '
         'copyright, trade secret, or other intellectual property right of the Disclosing Party, nor shall '
         'this Agreement grant the Receiving Party any rights in or to the Confidential Information except '
         'as expressly set forth herein. The Disclosing Party makes no warranties, express or implied, '
         'regarding the accuracy, completeness, or performance of any Confidential Information provided '
         'hereunder.'),

        ("6. TERM AND TERMINATION",
         f'This Agreement shall remain in effect for a period of {term_years} year(s) from the Effective Date, '
         'unless terminated earlier by either Party upon thirty (30) days prior written notice to the other '
         'Party. The obligations of confidentiality under this Agreement shall survive any termination or '
         'expiration of this Agreement and shall continue for a period of three (3) years following '
         'the date of termination.'),

        ("7. REMEDIES",
         'The Receiving Party acknowledges that any breach or threatened breach of this Agreement may '
         'cause irreparable harm to the Disclosing Party for which monetary damages would be an inadequate '
         'remedy. Accordingly, the Disclosing Party shall be entitled to seek equitable relief, including '
         'injunction and specific performance, in addition to all other remedies available at law or in '
         'equity, without the necessity of proving actual damages or posting any bond or security.'),

        ("8. MISCELLANEOUS",
         f'(a) Governing Law. This Agreement shall be governed by and construed in accordance with the '
         f'laws of the {governing_law}, without regard to its conflict of law principles.\n\n'
         '(b) Entire Agreement. This Agreement constitutes the entire agreement between the Parties with '
         'respect to the subject matter hereof and supersedes all prior and contemporaneous agreements, '
         'understandings, negotiations, and discussions, whether oral or written.\n\n'
         '(c) Amendment. This Agreement may not be amended or modified except by a written instrument '
         'signed by both Parties.\n\n'
         '(d) Severability. If any provision of this Agreement is found to be invalid or unenforceable, '
         'the remaining provisions shall continue in full force and effect.\n\n'
         '(e) Waiver. The failure of either Party to enforce any provision of this Agreement shall not '
         'constitute a waiver of such provision or the right to enforce it at a later time.\n\n'
         '(f) Assignment. Neither Party may assign this Agreement or any rights or obligations hereunder '
         'without the prior written consent of the other Party.'),
    ]

    for sec_title, sec_body in sections:
        # Check if we need a new page (at least ~140pt needed for heading + some text)
        if y > H - 100:
            page = _nda_new_page(doc, W, H, M)
            y = 60

        # Section heading
        tw = fitz.TextWriter(page.rect)
        tw.append((M, y + HEADING_SIZE), sec_title, font=FONT_TIBO, fontsize=HEADING_SIZE)
        tw.write_text(page, color=BLACK)
        y += HEADING_SIZE * LINE_H + 6

        # Section body — split into chunks if it might overflow the page
        remaining = sec_body
        while remaining:
            available_h = H - M - y - 10
            if available_h < BODY_SIZE * 3:
                page = _nda_new_page(doc, W, H, M)
                y = 60
                available_h = H - M - y - 10

            old_y = y
            y = _nda_wrapped(page, remaining, fitz.Rect(M, y, W - M, y + available_h),
                             font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H)
            # If the text fit in this chunk, we're done
            remaining = ""
        y += 14

    # ═══════════════════════════════════════════════════════════════════
    # Signature Block
    # ═══════════════════════════════════════════════════════════════════

    sig_block_height = 160
    if y > H - sig_block_height - 20:
        page = _nda_new_page(doc, W, H, M)
        y = 60

    y += 10

    # "IN WITNESS WHEREOF" line
    witness = (
        'IN WITNESS WHEREOF, the Parties have executed this Non-Disclosure and '
        'Confidentiality Agreement as of the date first written above.'
    )
    y = _nda_wrapped(page, witness, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIBO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H)
    y += 30

    sig_w = (W - 2 * M - 60) / 2

    # ── Left signature (Disclosing Party) ──
    lx = M
    # ── Right signature (Receiving Party) ──
    rx = M + sig_w + 60

    for sx, party_name, party_label, party_slug in [
        (lx, party1, "DISCLOSING PARTY", "disclosing"),
        (rx, party2, "RECEIVING PARTY", "receiving"),
    ]:
        _nda_write(page, sx, y, party_label, font=FONT_TIBO, fontsize=9, color=BLACK)
        sy = y + 20

        # Signature line
        page.draw_line(fitz.Point(sx, sy + 24), fitz.Point(sx + sig_w, sy + 24), color=BLACK, width=0.75)
        _nda_write(page, sx, sy + 36, "Signature", font=FONT_TIRO, fontsize=9, color=BLACK)

        # Name field
        _nda_write(page, sx, sy + 68, "Name:", font=FONT_TIRO, fontsize=9, color=BLACK)
        _add_text_field(page, f"nda_sig_name_{party_slug}",
                        fitz.Rect(sx + 34, sy + 56, sx + sig_w, sy + 71),
                        value=party_name, fontsize=9, font_name="TiRo")

        # Title field
        _nda_write(page, sx, sy + 100, "Title:", font=FONT_TIRO, fontsize=9, color=BLACK)
        _add_text_field(page, f"nda_sig_title_{party_slug}",
                        fitz.Rect(sx + 30, sy + 88, sx + sig_w, sy + 103),
                        value="", fontsize=9, font_name="TiRo")

        # Date field
        _nda_write(page, sx, sy + 132, "Date:", font=FONT_TIRO, fontsize=9, color=BLACK)
        _add_text_field(page, f"nda_sig_date_{party_slug}",
                        fitz.Rect(sx + 30, sy + 120, sx + sig_w, sy + 135),
                        value="", fontsize=9, font_name="TiRo")

    doc.need_appearances(True)
    doc.save(output_path)
    doc.close()


# ─── Purchase Order Generator ────────────────────────────────────────────────

def generate_purchase_order(data: dict, output_path: str):
    W, H = 612, 792
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)
    M = 50
    teal = _hex("#0f766e")
    teal_light = _hex("#f0fdfa")
    teal_border = _hex("#99f6e4")
    dark = _hex("#1e293b")
    gray = _hex("#4b5563")
    light = _hex("#64748b")
    rule_color = _hex("#e2e8f0")

    po_number = data.get("po_number", "")
    po_date = data.get("po_date", datetime.now().strftime("%m-%d-%Y"))
    delivery_date = data.get("delivery_date", "")
    company_name = data.get("company_name", "Your Company Inc.")
    company_addr = data.get("company_address", "1234 Company St.\nCompany Town, ST 12345")
    vendor_name = data.get("vendor_name", "")
    vendor_addr = data.get("vendor_address", "")
    ship_to = data.get("ship_to", f"{company_name}\n{company_addr}")
    terms = data.get("terms", "Net 30")
    notes = data.get("notes", "")
    items = data.get("items", [
        {"qty": 1, "description": "", "unit_price": 0.00},
        {"qty": "", "description": "", "unit_price": ""},
        {"qty": "", "description": "", "unit_price": ""},
    ])

    # ── Header ──
    header_rect = fitz.Rect(0, 0, W, 70)
    page.draw_rect(header_rect, color=None, fill=teal)

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 32), "PURCHASE ORDER", font=FONT_HEBO, fontsize=18)
    tw.write_text(page, color=(1, 1, 1))

    _add_text_field(page, "po_number", fitz.Rect(M, 42, M + 160, 58),
                    value=po_number, fontsize=10, font_name="Helv")

    # Date box
    date_box = fitz.Rect(W - M - 120, 14, W - M, 56)
    page.draw_rect(date_box, color=None, fill=(1, 1, 1, 0.15), radius=0.1)

    tw = fitz.TextWriter(page.rect)
    tw.append((date_box.x0 + 10, 30), "Date", font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=(1, 1, 1, 0.7))

    _add_text_field(page, "po_date", fitz.Rect(date_box.x0 + 8, 36, date_box.x1 - 8, 52),
                    value=po_date, fontsize=10, font_name="Helv")

    y = 90

    # ── Vendor & Ship To boxes ──
    box_w = (W - 2 * M - 24) / 2

    for i, (box_label, box_field, box_name, box_addr) in enumerate([
        ("VENDOR", "po_vendor", vendor_name, vendor_addr),
        ("SHIP TO", "po_ship_to", ship_to.split("\n")[0], "\n".join(ship_to.split("\n")[1:]))
    ]):
        bx = M + i * (box_w + 24)
        box_rect = fitz.Rect(bx, y, bx + box_w, y + 70)
        page.draw_rect(box_rect, color=teal_border, fill=teal_light, width=0.5, radius=0.1)

        tw = fitz.TextWriter(page.rect)
        tw.append((bx + 12, y + 18), box_label, font=FONT_HEBO, fontsize=8)
        tw.write_text(page, color=teal)

        _add_text_field(page, f"{box_field}_name", fitz.Rect(bx + 10, y + 22, bx + box_w - 10, y + 38),
                        value=box_name, fontsize=10, font_name="HeBo", color=dark)
        _add_text_field(page, f"{box_field}_address", fitz.Rect(bx + 10, y + 40, bx + box_w - 10, y + 66),
                        value=box_addr, fontsize=9, font_name="Helv", color=gray, multiline=True)

    y += 90

    # ── Terms & Delivery ──
    tw = fitz.TextWriter(page.rect)
    tw.append((M, y + 10), "Payment Terms:", font=FONT_HELV, fontsize=9)
    tw.write_text(page, color=gray)
    _add_text_field(page, "po_terms", fitz.Rect(M + 76, y, M + 216, y + 14),
                    value=terms, fontsize=9, font_name="Helv", color=gray)

    tw = fitz.TextWriter(page.rect)
    tw.append((W - M - 200, y + 10), "Delivery Date:", font=FONT_HELV, fontsize=9)
    tw.write_text(page, color=gray)
    _add_text_field(page, "po_delivery_date", fitz.Rect(W - M - 130, y, W - M, y + 14),
                    value=delivery_date, fontsize=9, font_name="Helv", color=gray)

    y += 30

    # ── Line Items Table ──
    table_header_rect = fitz.Rect(M, y, W - M, y + 24)
    page.draw_rect(table_header_rect, color=None, fill=teal, radius=0.08)

    cols = [
        (M + 8, "Item / Description", 0.5),
        (M + (W - 2*M) * 0.5 + 8, "Qty", 0.12),
        (M + (W - 2*M) * 0.62 + 8, "Unit Price", 0.18),
        (M + (W - 2*M) * 0.82 + 8, "Amount", 0.18),
    ]
    col_x = [c[0] for c in cols]
    for cx, label, _ in cols:
        tw = fitz.TextWriter(page.rect)
        tw.append((cx, y + 16), label, font=FONT_HEBO, fontsize=8)
        tw.write_text(page, color=(1, 1, 1))

    y += 24
    total = 0.0

    for idx, item in enumerate(items):
        try:
            qty = float(item.get("qty", 0) or 0)
        except (TypeError, ValueError):
            qty = 0.0
        desc = item.get("description", "")
        try:
            price = float(item.get("unit_price", 0) or 0)
        except (TypeError, ValueError):
            price = 0.0
        amount = qty * price
        total += amount

        row_bg = teal_light if idx % 2 == 0 else (1, 1, 1)
        row_rect = fitz.Rect(M, y, W - M, y + 22)
        page.draw_rect(row_rect, color=None, fill=row_bg)

        qty_str = "" if not item.get("qty") else (str(int(qty)) if qty == int(qty) else f"{qty:.1f}")
        price_str = "" if not item.get("unit_price") else f"{price:,.2f}"
        amount_str = f"${amount:,.2f}" if amount else ""

        _add_text_field(page, f"po_item{idx}_desc", fitz.Rect(col_x[0] - 4, y + 3, col_x[1] - 8, y + 19),
                        value=desc, fontsize=9, font_name="Helv", color=dark)
        _add_text_field(page, f"po_item{idx}_qty", fitz.Rect(col_x[1] - 4, y + 3, col_x[2] - 8, y + 19),
                        value=qty_str, fontsize=9, font_name="Helv", color=gray)
        _add_text_field(page, f"po_item{idx}_price", fitz.Rect(col_x[2] - 4, y + 3, col_x[3] - 8, y + 19),
                        value=price_str, fontsize=9, font_name="Helv", color=gray)
        tw = fitz.TextWriter(page.rect)
        tw.append((col_x[3], y + 15), amount_str, font=FONT_HELV, fontsize=9)
        tw.write_text(page, color=gray)

        page.draw_line(fitz.Point(M, y + 22), fitz.Point(W - M, y + 22), color=rule_color, width=0.5)
        y += 22

    # ── Total box ──
    y += 20
    total_box = fitz.Rect(W - M - 160, y, W - M, y + 32)
    page.draw_rect(total_box, color=None, fill=teal, radius=0.1)

    tw = fitz.TextWriter(page.rect)
    tw.append((total_box.x0 + 12, y + 22), "TOTAL", font=FONT_HEBO, fontsize=10)
    tw.write_text(page, color=(1, 1, 1))

    total_str = f"${total:,.2f}"
    tw = fitz.TextWriter(page.rect)
    total_w = fitz.get_text_length(total_str, fontname="hebo", fontsize=12)
    tw.append((total_box.x1 - total_w - 12, y + 22), total_str, font=FONT_HEBO, fontsize=12)
    tw.write_text(page, color=(1, 1, 1))

    # ── Notes ──
    y += 52
    tw = fitz.TextWriter(page.rect)
    tw.append((M, y), "Notes:", font=FONT_HEBO, fontsize=9)
    tw.write_text(page, color=dark)
    y += 6
    _add_text_field(page, "po_notes", fitz.Rect(M, y, W - M, y + 70),
                    value=notes, fontsize=9, font_name="Helv", color=gray, multiline=True)

    # ── Signature ──
    sig_y = H - 100
    page.draw_line(fitz.Point(M, sig_y), fitz.Point(M + 180, sig_y), color=dark, width=1)
    tw = fitz.TextWriter(page.rect)
    tw.append((M, sig_y + 14), "Authorized Signature", font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=light)

    page.draw_line(fitz.Point(W - M - 180, sig_y), fitz.Point(W - M, sig_y), color=dark, width=1)
    tw = fitz.TextWriter(page.rect)
    tw.append((W - M - 180, sig_y + 14), "Date", font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=light)
    _add_text_field(page, "po_sig_date", fitz.Rect(W - M - 180, sig_y - 16, W - M, sig_y - 2),
                    value="", fontsize=9, font_name="Helv", color=dark)

    doc.need_appearances(True)
    doc.save(output_path)
    doc.close()


# ─── Lease Extension Generator ───────────────────────────────────────────────

def _first_value(data: dict, keys, default: str = "") -> str:
    for key in keys:
        value = data.get(key)
        if value is not None and str(value).strip() != "":
            return str(value).strip()
    return default


def _lease_centered(page, W, y, text, font=FONT_TIBO, fontsize=12):
    tw = fitz.TextWriter(page.rect)
    text_w = font.text_length(text, fontsize=fontsize)
    tw.append(((W - text_w) / 2, y), text, font=font, fontsize=fontsize)
    tw.write_text(page, color=(0, 0, 0))


def _lease_write(page, x, y, text, font=FONT_TIRO, fontsize=11):
    tw = fitz.TextWriter(page.rect)
    tw.append((x, y), text, font=font, fontsize=fontsize)
    tw.write_text(page, color=(0, 0, 0))


def _lease_paragraph(page, text, x, y, width, fontsize=11, indent=0):
    return _draw_wrapped_text(
        page,
        text,
        fitz.Rect(x + indent, y, x + width, y + 120),
        font=FONT_TIRO,
        fontsize=fontsize,
        color=(0, 0, 0),
        lineheight=1.55,
    ) + 8


def generate_lease_extension(data: dict, output_path: str):
    W, H = 612, 792
    M = 50
    doc = fitz.open()

    agreement_date = _first_value(data, ["glex-agreement-date", "agreement_date", "agreementDate"], "")
    landlord = _first_value(data, ["glex-landlord-name", "glex-company-name", "landlord_name", "landlordName"], "")
    landlord_state = _first_value(data, ["glex-landlord-state", "glex-company-state", "landlord_state", "landlordState"], "")
    landlord_address = _first_value(data, ["glex-landlord-address", "glex-company-address", "landlord_address", "landlordAddress"], "")
    tenant1 = _first_value(data, ["glex-tenant1-name", "tenant1_name", "tenant1Name"], "")
    tenant2 = _first_value(data, ["glex-tenant2-name", "tenant2_name", "tenant2Name"], "")
    tenant_state = _first_value(data, ["glex-tenant-state", "tenant_state", "tenantState"], "")
    tenant_address = _first_value(data, ["glex-tenant-address", "tenant_address", "tenantAddress"], "")
    property_address = _first_value(data, ["glex-property-address", "property_address", "propertyAddress"], "")
    premises_description = _first_value(data, ["glex-premises-description", "premises_description", "premisesDescription"], "")
    original_date = _first_value(data, ["glex-original-lease-date", "original_lease_date", "originalLeaseDate"], "")
    extension_period = _first_value(data, ["glex-extension-period", "extension_period", "extensionPeriod"], "")
    start_date = _first_value(data, ["glex-extension-start-date", "extension_start_date", "extensionStartDate"], "")
    end_date = _first_value(data, ["glex-extension-end-date", "extension_end_date", "extensionEndDate"], "")
    rent = _first_value(data, ["glex-new-rent", "new_rent", "newRent"], "")
    tenant_names = tenant1 if not tenant2 else f"{tenant1}; {tenant2}"

    rent_text = rent
    try:
        rent_text = f"{float(rent):,.2f}"
    except (TypeError, ValueError):
        pass

    page = doc.new_page(width=W, height=H)
    content_w = W - (2 * M)
    field_fill = (0.93, 0.96, 1.0)
    field_border = (0.70, 0.76, 0.88)
    line_color = (0.05, 0.05, 0.05)
    body_size = 9

    def write(x, y, text, font=FONT_HELV, size=body_size):
        tw = fitz.TextWriter(page.rect)
        tw.append((x, y), text, font=font, fontsize=size)
        tw.write_text(page, color=(0, 0, 0))

    def wrapped(text, x, y, width, size=body_size, indent=0, lineheight=1.22):
        return _draw_wrapped_text(
            page,
            text,
            fitz.Rect(x + indent, y, x + width, y + 80),
            font=FONT_HELV,
            fontsize=size,
            color=(0, 0, 0),
            lineheight=lineheight,
        )

    def flow(parts, x, y, width, indent=0, gap=0):
        return _flow_fields(
            page,
            parts,
            x,
            y,
            width,
            FONT_HELV,
            fontsize=body_size,
            lineheight=1.22,
            indent=indent,
            indent_all_lines=bool(indent),
            gap_after=gap,
            field_fill_color=None,
            field_border_color=None,
            field_border_width=0,
            field_pad=1.0,
        )

    def text_field(name, rect, value="", size=body_size, multiline=False, transparent=True):
        if transparent:
            return _add_text_field(
                page,
                name,
                rect,
                value=value,
                fontsize=size,
                font_name="Helv",
                multiline=multiline,
                fill_color=None,
                border_color=None,
                border_width=0,
            )
        return _add_text_field(
            page,
            name,
            rect,
            value=value,
            fontsize=size,
            font_name="Helv",
            multiline=multiline,
            fill_color=field_fill,
            border_color=field_border,
            border_width=0.4,
        )

    _lease_centered(page, W, 70, "EXTENSION OF LEASE AGREEMENT", font=FONT_HEBO, fontsize=15)
    page.draw_line(fitz.Point(M, 86), fitz.Point(W - M, 86), color=line_color, width=1.7)

    flow(
        [
            'This Extension of Lease Agreement (the "Agreement") is made and effective',
            _Field("lease_agreement_date", agreement_date, min_w=42),
            ".",
        ],
        M,
        114,
        content_w,
    )

    label_x = M
    text_x = 176
    y = 158
    write(label_x, y, "BETWEEN:", font=FONT_HEBO)
    y = flow(
        [
            _Field("lease_landlord_name", landlord, min_w=128),
            ' (the "Landlord"), a corporation organized and existing under the laws of the',
            _Field("lease_landlord_state", landlord_state, min_w=82),
            ", with its head office located at:",
        ],
        text_x,
        y - 9,
        W - M - text_x,
    ) + 8
    text_field("lease_landlord_address", fitz.Rect(text_x, y, W - M, y + 24), landlord_address, multiline=True)
    y += 56

    write(label_x, y, "AND:", font=FONT_HEBO)
    y = flow(
        [
            _Field("lease_tenant_names", tenant_names, min_w=118),
            ' (the "Tenant"), an individual with his main address located at OR a corporation organized and existing under the laws of the',
            _Field("lease_tenant_state", tenant_state, min_w=82),
            ", with its head office located at:",
        ],
        text_x,
        y - 9,
        W - M - text_x,
    ) + 8
    text_field("lease_tenant_address", fitz.Rect(text_x, y, W - M, y + 24), tenant_address, multiline=True)

    y = 350
    write(M, y, "RECITALS", font=FONT_HEBO)
    y += 22
    flow(
        [
            "This Agreement is relative to a certain Lease Agreement for premises known as",
            _Field("lease_premises_description", premises_description, min_w=62),
            "located at",
            _Field("lease_property_address", property_address.replace("\n", ", "), min_w=56),
            "and dated",
            _Field("lease_original_date", original_date, min_w=82),
            ".",
        ],
        M,
        y - 9,
        content_w,
    )

    y = 430
    write(M, y, "TERMS", font=FONT_HEBO)
    y += 20

    number_x = M + 22
    term_x = M + 42
    term_w = W - M - term_x
    terms = [
        (
            "1.",
            [
                "For good consideration, Landlord and Tenant each agree to extend the term of said Lease for a period of",
                _Field("lease_extension_period", extension_period, min_w=76),
                "commencing on",
                _Field("lease_start_date", start_date, min_w=82),
                "terminating on",
                _Field("lease_end_date", end_date, min_w=82),
                ", with no further right of renewal or extension beyond said termination date.",
            ],
        ),
        (
            "2.",
            [
                "During the extended term, Tenant shall pay Landlord rent of",
                _Field("lease_new_rent", rent_text, min_w=70),
                "payable in advance.",
            ],
        ),
    ]
    for number, parts in terms:
        write(number_x, y, number)
        y = flow(parts, term_x, y - 9, term_w, gap=7)

    write(number_x, y, "3.")
    y = wrapped(
        "It is further provided, however, that all other terms of the Lease shall continue during this extended term as if set forth herein.",
        term_x,
        y - 9,
        term_w,
    ) + 8
    write(number_x, y, "4.")
    y = wrapped(
        "This agreement shall be binding upon and shall inure to the benefit of the parties, their successors, assigns and personal representatives.",
        term_x,
        y - 9,
        term_w,
    ) + 12

    wrapped(
        "IN WITNESS WHEREOF, the parties have executed this Agreement as of the date first above written.",
        M,
        y,
        content_w,
    )

    sig_block_shift_y = -54
    sig_y = 668 + sig_block_shift_y
    left_x = M
    right_x = 344
    sig_w = 252
    write(left_x, sig_y, "LANDLORD")
    write(right_x, sig_y, "TENANT")

    def signature_column(x, slug, printed_value):
        def sy(value):
            return value + sig_block_shift_y

        page.draw_line(fitz.Point(x, sy(711)), fitz.Point(x + sig_w, sy(711)), color=line_color, width=0.8)
        text_field(f"lease_sig_authorized_{slug}", fitz.Rect(x, sy(691), x + sig_w, sy(710)), "", transparent=True)
        write(x, sy(723), "Authorized Signature", size=8)
        page.draw_line(fitz.Point(x, sy(752)), fitz.Point(x + sig_w, sy(752)), color=line_color, width=0.8)
        text_field(f"lease_sig_name_title_{slug}", fitz.Rect(x, sy(732), x + sig_w, sy(751)), printed_value, size=8, transparent=True)
        write(x, sy(764), "Print Name and Title", size=8)

    signature_column(left_x, "landlord", landlord)
    signature_column(right_x, "tenant", tenant_names)

    page.draw_line(fitz.Point(M, 776 + sig_block_shift_y), fitz.Point(W - M, 776 + sig_block_shift_y), color=line_color, width=0.8)
    write(M, 787 + sig_block_shift_y, "Arbitration Agreement", size=8)
    footer = "Page 1 of 1"
    footer_w = FONT_HELV.text_length(footer, fontsize=8)
    write(W - M - footer_w, 787, footer, size=8)

    doc.need_appearances(True)
    doc.save(output_path)
    doc.close()


# ─── Security Deposit Return Generator ───────────────────────────────────────

def _money_value(value, default="0.00") -> str:
    try:
        return f"{float(str(value).replace('$', '').replace(',', '') or 0):,.2f}"
    except (TypeError, ValueError):
        return default


def generate_security_deposit_return(data: dict, output_path: str):
    W, H = 612, 792
    M = 44
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)

    navy = _hex("#1e3a5f")
    blue_light = _hex("#eef4ff")
    gray = _hex("#4b5563")
    rule = _hex("#d1d5db")
    dark = _hex("#111827")
    white = (1, 1, 1)

    landlord_name = data.get("landlord_name", "Landlord Name")
    landlord_address = data.get("landlord_address", "1234 Landlord Ave.\nCity, ST 12345")
    tenant_name = data.get("tenant_name", "Tenant Name")
    tenant_address = data.get("tenant_address", "456 Tenant St., Apt 2")
    city_prov_postal = data.get("city_prov_postal", "City, Province  A1B 2C3")
    tenancy_began = data.get("tenancy_began", "")
    keys_turned_in = data.get("keys_turned_in", "")
    total_deposits = _money_value(data.get("total_deposits", "0.00"))
    notes = data.get("notes", data.get("sdr_notes", ""))
    signature_landlord = data.get("signature_landlord", data.get("sdr_signature_landlord", ""))
    signature_tenant = data.get("signature_tenant", data.get("sdr_signature_tenant", ""))
    signature_date_landlord = data.get("signature_date_landlord", data.get("sdr_signature_date_landlord", ""))
    signature_date_tenant = data.get("signature_date_tenant", data.get("sdr_signature_date_tenant", ""))
    deductions = data.get("deductions") or [
        {"type": "", "description": "", "cost": ""},
    ]

    def write(x, y, text, font=FONT_HELV, size=9, color=dark):
        tw = fitz.TextWriter(page.rect)
        tw.append((x, y), str(text), font=font, fontsize=size)
        tw.write_text(page, color=color)

    def field(name, rect, value="", size=9, multiline=False, align=fitz.TEXT_ALIGN_LEFT):
        return _add_text_field(
            page,
            name,
            rect,
            value=value,
            fontsize=size,
            font_name="Helv",
            multiline=multiline,
            color=dark,
            align=align,
            fill_color=_FIELD_FILL,
            border_color=_FIELD_BORDER,
            border_width=0.55,
        )

    page.draw_rect(fitz.Rect(0, 0, W, 76), color=None, fill=navy)
    title = "SECURITY DEPOSIT RETURN"
    title_w = FONT_HEBO.text_length(title, fontsize=17)
    write((W - title_w) / 2, 32, title, font=FONT_HEBO, size=17, color=white)
    subtitle = "Itemized Statement of Deposit, Deductions, and Refund"
    subtitle_w = FONT_HELV.text_length(subtitle, fontsize=9)
    write((W - subtitle_w) / 2, 52, subtitle, size=9, color=(0.86, 0.91, 0.96))

    y = 104
    col_gap = 24
    col_w = (W - 2 * M - col_gap) / 2

    for x, label, name, address_name, party, address in [
        (M, "LANDLORD", "sdr_landlord_name", "sdr_landlord_address", landlord_name, landlord_address),
        (M + col_w + col_gap, "TENANT", "sdr_tenant_name", "sdr_tenant_address", tenant_name, tenant_address),
    ]:
        page.draw_rect(fitz.Rect(x, y, x + col_w, y + 92), color=rule, fill=(1, 1, 1), width=0.7)
        write(x + 12, y + 20, label, font=FONT_HEBO, size=8, color=navy)
        field(name, fitz.Rect(x + 10, y + 27, x + col_w - 10, y + 47), party, size=11)
        field(address_name, fitz.Rect(x + 10, y + 52, x + col_w - 10, y + 86), address, size=9, multiline=True)

    y += 120
    write(M, y, "Property / Tenancy Information", font=FONT_HEBO, size=10, color=navy)
    y += 16
    label_w = 118
    row_h = 28
    rows = [
        ("Property Address", "sdr_property_address", city_prov_postal),
        ("Tenancy Began", "sdr_tenancy_began", tenancy_began),
        ("Keys Turned In", "sdr_keys_turned_in", keys_turned_in),
        ("Total Deposits Held", "sdr_total_deposits", total_deposits),
    ]
    for label, name, value in rows:
        page.draw_rect(fitz.Rect(M, y, W - M, y + row_h), color=rule, fill=(1, 1, 1), width=0.45)
        write(M + 10, y + 18, label, font=FONT_HEBO, size=8, color=gray)
        align = fitz.TEXT_ALIGN_RIGHT if name == "sdr_total_deposits" else fitz.TEXT_ALIGN_LEFT
        field(name, fitz.Rect(M + label_w, y + 6, W - M - 10, y + 22), value, size=9, align=align)
        y += row_h

    y += 26
    write(M, y, "Itemized Deductions", font=FONT_HEBO, size=10, color=navy)
    y += 14
    table_x = M
    table_w = W - 2 * M
    type_w = 100
    cost_w = 92
    desc_w = table_w - type_w - cost_w
    header_h = 24
    page.draw_rect(fitz.Rect(table_x, y, table_x + table_w, y + header_h), color=None, fill=navy)
    write(table_x + 10, y + 16, "Type", font=FONT_HEBO, size=8, color=white)
    write(table_x + type_w + 10, y + 16, "Description", font=FONT_HEBO, size=8, color=white)
    write(table_x + type_w + desc_w + 10, y + 16, "Cost", font=FONT_HEBO, size=8, color=white)
    y += header_h

    total_deductions = 0.0
    deduction_count = max(1, min(8, len(deductions)))
    for idx in range(deduction_count):
        item = deductions[idx] if idx < len(deductions) and isinstance(deductions[idx], dict) else {}
        item_type = item.get("type", "")
        desc = item.get("description", "")
        cost_raw = item.get("cost", "")
        try:
            cost = float(str(cost_raw).replace("$", "").replace(",", "") or 0)
        except (TypeError, ValueError):
            cost = 0.0
        total_deductions += cost
        row_bg = blue_light if idx % 2 == 0 else (1, 1, 1)
        page.draw_rect(fitz.Rect(table_x, y, table_x + table_w, y + 26), color=rule, fill=row_bg, width=0.35)
        field(f"sdr_deduction_{idx}_type", fitz.Rect(table_x + 8, y + 5, table_x + type_w - 8, y + 21), item_type, size=8)
        field(f"sdr_deduction_{idx}_description", fitz.Rect(table_x + type_w + 8, y + 5, table_x + type_w + desc_w - 8, y + 21), desc, size=8)
        field(
            f"sdr_deduction_{idx}_cost",
            fitz.Rect(table_x + type_w + desc_w + 8, y + 5, table_x + table_w - 8, y + 21),
            "" if cost_raw in ("", None) else _money_value(cost_raw, ""),
            size=8,
            align=fitz.TEXT_ALIGN_RIGHT,
        )
        y += 26

    y += 24
    summary_x = W - M - 230
    summary_label_w = 138
    deposits = float(total_deposits.replace(",", "") or 0)
    refund_due = deposits - total_deductions
    summary_rows = [
        ("Total Deposits", "sdr_summary_total_deposits", f"${deposits:,.2f}"),
        ("Total Deductions", "sdr_summary_total_deductions", f"${total_deductions:,.2f}"),
        ("Refund Due", "sdr_refund_due", f"${refund_due:,.2f}"),
    ]
    for idx, (label, name, value) in enumerate(summary_rows):
        fill = navy if idx == 2 else (1, 1, 1)
        color = white if idx == 2 else dark
        page.draw_rect(fitz.Rect(summary_x, y, W - M, y + 28), color=navy, fill=fill, width=0.65)
        write(summary_x + 10, y + 18, label, font=FONT_HEBO, size=9, color=color)
        field(
            name,
            fitz.Rect(summary_x + summary_label_w, y + 6, W - M - 8, y + 22),
            value,
            size=9,
            align=fitz.TEXT_ALIGN_RIGHT,
        )
        y += 28

    note_y = y + 24
    write(M, note_y, "Notes / Explanation", font=FONT_HEBO, size=9, color=navy)
    field("sdr_notes", fitz.Rect(M, note_y + 8, W - M, note_y + 58), notes, size=9, multiline=True)

    sig_y = H - 88
    sig_w = 220
    for x, label, slug in [
        (M, "Landlord / Agent Signature", "landlord"),
        (W - M - sig_w, "Tenant Acknowledgment", "tenant"),
    ]:
        signature_value = signature_landlord if slug == "landlord" else signature_tenant
        signature_date_value = signature_date_landlord if slug == "landlord" else signature_date_tenant
        signature_rect = fitz.Rect(x, sig_y - 24, x + sig_w, sig_y - 2)
        field(f"sdr_signature_{slug}", signature_rect, signature_value, size=9)
        page.draw_line(fitz.Point(x, sig_y), fitz.Point(x + sig_w, sig_y), color=dark, width=0.8)
        write(x, sig_y + 15, label, size=8, color=gray)

        date_y = sig_y + 36
        write(x, date_y + 13, "Date:", size=8, color=gray)
        field(
            f"sdr_signature_date_{slug}",
            fitz.Rect(x + 42, date_y, x + sig_w, date_y + 18),
            signature_date_value,
            size=8,
        )

    doc.need_appearances(True)
    doc.save(output_path)
    doc.close()


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    if len(sys.argv) < 2:
        print("Usage: generate_template.py <output_path>", file=sys.stderr)
        sys.exit(1)

    output_path = sys.argv[1]
    data = json.load(sys.stdin)

    template_type = data.get("template_type", "newsletter")
    template_slug = data.get("template_slug", "")

    if template_type == "newsletter":
        generate_newsletter(data, output_path, slug=template_slug)
    elif template_type == "realestate" and template_slug == "security_deposit_return":
        generate_security_deposit_return(data, output_path)
    elif template_type == "realestate" and template_slug == "lease_extension":
        generate_lease_extension(data, output_path)
    elif template_slug == "nda_agreement":
        generate_nda(data, output_path)
    elif template_slug == "purchase_order":
        generate_purchase_order(data, output_path)
    else:
        print(f"ERROR: Unknown template type/slug: {template_type}/{template_slug}", file=sys.stderr)
        sys.exit(1)

    print(f"OK: Generated {template_type} template -> {output_path}")


if __name__ == "__main__":
    main()
