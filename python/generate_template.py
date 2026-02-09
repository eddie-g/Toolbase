#!/usr/bin/env python3
"""
Generate a PDF document from a guided template.

Supports template types: newsletter, nda_agreement, purchase_order.
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


# ─── Newsletter Generator ────────────────────────────────────────────────────

def generate_newsletter(data: dict, output_path: str, slug: str = "newsletter_classic"):
    W, H = 612, 792  # Letter size
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)
    M = 50  # margin

    title = data.get("newsletter_title", "Monthly Newsletter")
    edition = data.get("edition", "Vol. 1 — Issue 1")
    date_str = data.get("date", datetime.now().strftime("%B %Y"))
    company = data.get("company_name", "Your Company Inc.")
    headline = data.get("headline", "Big Exciting Headline Goes Here")
    intro = data.get("intro_text", "Welcome to this month's newsletter!")
    s1_title = data.get("section1_title", "Featured Story")
    s1_body = data.get("section1_body", "Lorem ipsum dolor sit amet...")
    s2_title = data.get("section2_title", "Upcoming Events")
    s2_body = data.get("section2_body", "Event details go here...")
    footer = data.get("footer_text", f"© {datetime.now().year} {company}. All rights reserved.")

    if slug == "newsletter_modern":
        _generate_modern_newsletter(page, W, H, M, title, edition, date_str, company,
                                     headline, intro, s1_title, s1_body, s2_title, s2_body, footer)
    else:
        _generate_classic_newsletter(page, W, H, M, title, edition, date_str, company,
                                      headline, intro, s1_title, s1_body, s2_title, s2_body, footer)

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

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 35), title, font=FONT_HEBO, fontsize=22)
    tw.write_text(page, color=(1, 1, 1))

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 55), f"{edition}  ·  {date_str}", font=FONT_HELV, fontsize=10)
    tw.write_text(page, color=(1, 1, 1, 0.7))

    tw = fitz.TextWriter(page.rect)
    tw.append((W - M - fitz.get_text_length(company, fontname="hebo", fontsize=10), 55),
              company, font=FONT_HEBO, fontsize=10)
    tw.write_text(page, color=(1, 1, 1, 0.85))

    y = 110

    # ── Headline ──
    tw = fitz.TextWriter(page.rect)
    tw.append((M, y), headline, font=FONT_HEBO, fontsize=20)
    tw.write_text(page, color=dark)
    y += 30

    # ── Intro ──
    y = _draw_wrapped_text(page, intro, fitz.Rect(M, y, W - M, y + 200),
                           font=FONT_HELV, fontsize=11, color=gray, lineheight=1.5)
    y += 20

    # ── Divider ──
    page.draw_line(fitz.Point(M, y), fitz.Point(W - M, y), color=_hex("#e2e8f0"), width=1)
    y += 20

    # ── Two-column sections ──
    col_w = (W - 2 * M - 24) / 2

    for i, (sec_title, sec_body) in enumerate([(s1_title, s1_body), (s2_title, s2_body)]):
        col_x = M + i * (col_w + 24)

        # Section card background
        card_rect = fitz.Rect(col_x, y, col_x + col_w, y + 220)
        page.draw_rect(card_rect, color=_hex("#bfdbfe"), fill=light_blue, width=0.5, radius=0.1)

        # Section title
        tw = fitz.TextWriter(page.rect)
        tw.append((col_x + 12, y + 22), sec_title, font=FONT_HEBO, fontsize=12)
        tw.write_text(page, color=blue)

        # Section body
        _draw_wrapped_text(page, sec_body,
                           fitz.Rect(col_x + 12, y + 36, col_x + col_w - 12, y + 210),
                           font=FONT_HELV, fontsize=10, color=gray, lineheight=1.5)

    y += 240

    # ── Footer ──
    page.draw_line(fitz.Point(M, H - 60), fitz.Point(W - M, H - 60), color=_hex("#e2e8f0"), width=0.5)
    tw = fitz.TextWriter(page.rect)
    tw.append((M, H - 40), footer, font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=gray)


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

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 38), title, font=FONT_HEBO, fontsize=24)
    tw.write_text(page, color=(1, 1, 1))

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 62), f"{edition}  ·  {date_str}", font=FONT_HELV, fontsize=10)
    tw.write_text(page, color=(1, 1, 1, 0.7))

    # Pill button
    pill_text = "Read →"
    pill_w = fitz.get_text_length(pill_text, fontname="hebo", fontsize=9) + 20
    pill_rect = fitz.Rect(W - M - pill_w, 30, W - M, 52)
    page.draw_rect(pill_rect, color=None, fill=(1, 1, 1, 0.2), radius=0.5)
    tw = fitz.TextWriter(page.rect)
    tw.append((pill_rect.x0 + 10, 46), pill_text, font=FONT_HEBO, fontsize=9)
    tw.write_text(page, color=(1, 1, 1))

    y = 118

    # ── Headline ──
    tw = fitz.TextWriter(page.rect)
    tw.append((M, y), headline, font=FONT_HEBO, fontsize=20)
    tw.write_text(page, color=dark)
    y += 28

    # ── Intro ──
    y = _draw_wrapped_text(page, intro, fitz.Rect(M, y, W - M, y + 200),
                           font=FONT_HELV, fontsize=11, color=gray, lineheight=1.5)
    y += 24

    # ── Card sections ──
    card_colors = [purple, blue]
    for i, (sec_title, sec_body) in enumerate([(s1_title, s1_body), (s2_title, s2_body)]):
        card_rect = fitz.Rect(M, y, W - M, y + 100)
        page.draw_rect(card_rect, color=_hex("#e2e8f0"), fill=(1, 1, 1), width=0.75, radius=0.15)

        # Accent bar
        bar_rect = fitz.Rect(M + 6, y + 10, M + 10, y + 90)
        page.draw_rect(bar_rect, color=None, fill=card_colors[i], radius=0.05)

        # Title
        tw = fitz.TextWriter(page.rect)
        tw.append((M + 20, y + 28), sec_title, font=FONT_HEBO, fontsize=12)
        tw.write_text(page, color=dark)

        # Body
        _draw_wrapped_text(page, sec_body,
                           fitz.Rect(M + 20, y + 38, W - M - 16, y + 92),
                           font=FONT_HELV, fontsize=10, color=gray, lineheight=1.4)
        y += 116

    # ── Footer ──
    page.draw_line(fitz.Point(M, H - 60), fitz.Point(W - M, H - 60), color=_hex("#e2e8f0"), width=0.5)
    tw = fitz.TextWriter(page.rect)
    tw.append((M, H - 40), footer, font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=gray)


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

    effective_date = data.get("effective_date", datetime.now().strftime("%B %d, %Y"))
    party1 = data.get("party1_name", "Your Company Inc.")
    party1_addr = data.get("party1_address", "1234 Company St.\nCompany Town, ST 12345")
    party2 = data.get("party2_name", "Recipient Name")
    party2_addr = data.get("party2_address", "5678 Recipient Rd.\nRecipient City, ST 67890")
    purpose = data.get("purpose", "exploring a potential business relationship between the parties")
    term_years = data.get("term_years", "2")
    governing_law = data.get("governing_law", "State of Delaware")

    page = _nda_new_page(doc, W, H, M)
    y = 60

    # ═══════════════════════════════════════════════════════════════════
    # PAGE 1 — Title, Parties, Recitals, and opening sections
    # ═══════════════════════════════════════════════════════════════════

    # ── Title ──
    _nda_centered(page, W, y, "NON-DISCLOSURE AND CONFIDENTIALITY AGREEMENT", font=FONT_TIBO, fontsize=TITLE_SIZE, color=BLACK)
    y += 30

    # ── Date line ──
    _nda_centered(page, W, y, f"Effective Date: {effective_date}", font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK)
    y += 28

    # ── Horizontal rule ──
    page.draw_line(fitz.Point(M, y), fitz.Point(W - M, y), color=BLACK, width=1)
    y += 22

    # ── Parties Introduction ──
    intro = (
        f'This Non-Disclosure and Confidentiality Agreement (this "Agreement") is entered into '
        f'as of {effective_date} (the "Effective Date"), by and between:'
    )
    y = _nda_wrapped(page, intro, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H)
    y += 12

    # Party 1 block
    party1_block = (
        f'{party1} ("Disclosing Party"), with a principal place of business at '
        f'{party1_addr.replace(chr(10), ", ")};'
    )
    y = _nda_wrapped(page, party1_block, fitz.Rect(M + 36, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H)
    y += 8

    # "and"
    _nda_write(page, M, y + BODY_SIZE, "and", font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK)
    y += BODY_SIZE * LINE_H + 8

    # Party 2 block
    party2_block = (
        f'{party2} ("Receiving Party"), with a principal place of business at '
        f'{party2_addr.replace(chr(10), ", ")}.'
    )
    y = _nda_wrapped(page, party2_block, fitz.Rect(M + 36, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H)
    y += 14

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

    recital_b = (
        f'WHEREAS, the Receiving Party desires to receive certain Confidential Information '
        f'for the purpose of {purpose}; and'
    )
    y = _nda_wrapped(page, recital_b, fitz.Rect(M, y, W - M, y + 200),
                     font=FONT_TIRO, fontsize=BODY_SIZE, color=BLACK, lineheight=LINE_H, indent=36)
    y += 8

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

    for sx, party_name, party_label in [
        (lx, party1, "DISCLOSING PARTY"),
        (rx, party2, "RECEIVING PARTY"),
    ]:
        _nda_write(page, sx, y, party_label, font=FONT_TIBO, fontsize=9, color=BLACK)
        sy = y + 20

        # Signature line
        page.draw_line(fitz.Point(sx, sy + 24), fitz.Point(sx + sig_w, sy + 24), color=BLACK, width=0.75)
        _nda_write(page, sx, sy + 36, "Signature", font=FONT_TIRO, fontsize=9, color=BLACK)

        # Name line
        page.draw_line(fitz.Point(sx, sy + 56), fitz.Point(sx + sig_w, sy + 56), color=BLACK, width=0.75)
        _nda_write(page, sx, sy + 68, f"Name: {party_name}", font=FONT_TIRO, fontsize=9, color=BLACK)

        # Title line
        page.draw_line(fitz.Point(sx, sy + 88), fitz.Point(sx + sig_w, sy + 88), color=BLACK, width=0.75)
        _nda_write(page, sx, sy + 100, "Title:", font=FONT_TIRO, fontsize=9, color=BLACK)

        # Date line
        page.draw_line(fitz.Point(sx, sy + 120), fitz.Point(sx + sig_w, sy + 120), color=BLACK, width=0.75)
        _nda_write(page, sx, sy + 132, "Date:", font=FONT_TIRO, fontsize=9, color=BLACK)

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

    po_number = data.get("po_number", "PO-2026-001")
    po_date = data.get("po_date", datetime.now().strftime("%m-%d-%Y"))
    delivery_date = data.get("delivery_date", "")
    company_name = data.get("company_name", "Your Company Inc.")
    company_addr = data.get("company_address", "1234 Company St.\nCompany Town, ST 12345")
    vendor_name = data.get("vendor_name", "Vendor Name")
    vendor_addr = data.get("vendor_address", "5678 Vendor Rd.\nVendor City, ST 67890")
    ship_to = data.get("ship_to", f"{company_name}\n{company_addr}")
    terms = data.get("terms", "Net 30")
    notes = data.get("notes", "")
    items = data.get("items", [
        {"qty": 1, "description": "Sample Item", "unit_price": 0.00},
    ])

    # ── Header ──
    header_rect = fitz.Rect(0, 0, W, 70)
    page.draw_rect(header_rect, color=None, fill=teal)

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 32), "PURCHASE ORDER", font=FONT_HEBO, fontsize=18)
    tw.write_text(page, color=(1, 1, 1))

    tw = fitz.TextWriter(page.rect)
    tw.append((M, 52), po_number, font=FONT_HELV, fontsize=10)
    tw.write_text(page, color=(1, 1, 1, 0.7))

    # Date box
    date_box = fitz.Rect(W - M - 120, 14, W - M, 56)
    page.draw_rect(date_box, color=None, fill=(1, 1, 1, 0.15), radius=0.1)

    tw = fitz.TextWriter(page.rect)
    tw.append((date_box.x0 + 10, 30), "Date", font=FONT_HELV, fontsize=8)
    tw.write_text(page, color=(1, 1, 1, 0.7))

    tw = fitz.TextWriter(page.rect)
    tw.append((date_box.x0 + 10, 46), po_date, font=FONT_HEBO, fontsize=10)
    tw.write_text(page, color=(1, 1, 1))

    y = 90

    # ── Vendor & Ship To boxes ──
    box_w = (W - 2 * M - 24) / 2

    for i, (box_label, box_name, box_addr) in enumerate([
        ("VENDOR", vendor_name, vendor_addr),
        ("SHIP TO", ship_to.split("\n")[0], "\n".join(ship_to.split("\n")[1:]))
    ]):
        bx = M + i * (box_w + 24)
        box_rect = fitz.Rect(bx, y, bx + box_w, y + 70)
        page.draw_rect(box_rect, color=teal_border, fill=teal_light, width=0.5, radius=0.1)

        tw = fitz.TextWriter(page.rect)
        tw.append((bx + 12, y + 18), box_label, font=FONT_HEBO, fontsize=8)
        tw.write_text(page, color=teal)

        tw = fitz.TextWriter(page.rect)
        tw.append((bx + 12, y + 34), box_name, font=FONT_HEBO, fontsize=10)
        tw.write_text(page, color=dark)

        _draw_wrapped_text(page, box_addr, fitz.Rect(bx + 12, y + 40, bx + box_w - 12, y + 65),
                           font=FONT_HELV, fontsize=9, color=gray, lineheight=1.4)

    y += 90

    # ── Terms & Delivery ──
    tw = fitz.TextWriter(page.rect)
    tw.append((M, y + 10), f"Payment Terms: {terms}", font=FONT_HELV, fontsize=9)
    tw.write_text(page, color=gray)

    if delivery_date:
        tw = fitz.TextWriter(page.rect)
        delivery_text = f"Delivery Date: {delivery_date}"
        tw.append((W - M - fitz.get_text_length(delivery_text, fontname="helv", fontsize=9), y + 10),
                  delivery_text, font=FONT_HELV, fontsize=9)
        tw.write_text(page, color=gray)

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
    for cx, label, _ in cols:
        tw = fitz.TextWriter(page.rect)
        tw.append((cx, y + 16), label, font=FONT_HEBO, fontsize=8)
        tw.write_text(page, color=(1, 1, 1))

    y += 24
    total = 0.0

    for idx, item in enumerate(items):
        qty = float(item.get("qty", 0))
        desc = item.get("description", "")
        price = float(item.get("unit_price", 0))
        amount = qty * price
        total += amount

        row_bg = teal_light if idx % 2 == 0 else (1, 1, 1)
        row_rect = fitz.Rect(M, y, W - M, y + 22)
        page.draw_rect(row_rect, color=None, fill=row_bg)

        row_data = [desc, str(int(qty)) if qty == int(qty) else f"{qty:.1f}", f"${price:,.2f}", f"${amount:,.2f}"]
        for i, (cx, _, __) in enumerate(cols):
            tw = fitz.TextWriter(page.rect)
            tw.append((cx, y + 15), row_data[i], font=FONT_HELV, fontsize=9)
            tw.write_text(page, color=dark if i == 0 else gray)

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
    if notes:
        y += 52
        tw = fitz.TextWriter(page.rect)
        tw.append((M, y), "Notes:", font=FONT_HEBO, fontsize=9)
        tw.write_text(page, color=dark)
        y += 4
        _draw_wrapped_text(page, notes, fitz.Rect(M, y, W - M, y + 100),
                           font=FONT_HELV, fontsize=9, color=gray, lineheight=1.5)

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
