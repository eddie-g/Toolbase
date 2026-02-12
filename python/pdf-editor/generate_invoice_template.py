#!/usr/bin/env python3
"""
Generate professional invoice PDF templates.
Each template creates a fully-formed invoice PDF with placeholder data
that users can customize in the PDF editor.

Usage: python3 generate_invoice_template.py <template_name> <output_path>
Templates: clean_modern, bold_red, classic_blue
"""

import sys
import os
import fitz  # PyMuPDF


# ── Shared placeholder data ───────────────────────────────────────────────

COMPANY = {
    "name": "Your Company Name",
    "address_1": "123 Business Avenue",
    "address_2": "Suite 100",
    "city_state_zip": "New York, NY 10001",
    "phone": "(555) 123-4567",
    "email": "billing@yourcompany.com",
}

CLIENT = {
    "name": "Client Name",
    "address_1": "456 Client Street",
    "city_state_zip": "Los Angeles, CA 90001",
}

INVOICE = {
    "number": "INV-2026-001",
    "date": "February 6, 2026",
    "due_date": "March 8, 2026",
}

LINE_ITEMS = [
    ("Web Design & Development", 1, 2500.00),
    ("Logo & Brand Identity", 1, 800.00),
    ("SEO Optimization Setup", 1, 450.00),
    ("Content Writing (5 pages)", 5, 120.00),
    ("Monthly Hosting (Annual)", 12, 29.99),
]

TAX_RATE = 0.08  # 8%


def _calc_totals(items):
    subtotal = sum(qty * price for _, qty, price in items)
    tax = round(subtotal * TAX_RATE, 2)
    total = round(subtotal + tax, 2)
    return subtotal, tax, total


def _fmt(amount):
    return f"${amount:,.2f}"


# ── Template 1: Clean Modern (Minimal, sans-serif) ───────────────────────

def generate_clean_modern(output_path):
    """Minimal modern invoice — light grey accents, clean typography."""
    W, H = 612, 792  # US Letter
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)

    # Colors
    BLACK = (0, 0, 0)
    DARK = (0.15, 0.15, 0.15)
    GREY = (0.45, 0.45, 0.45)
    LIGHT_BG = (0.96, 0.96, 0.96)
    ACCENT = (0.20, 0.60, 0.86)  # Blue accent

    M = 50  # margin
    shape = page.new_shape()

    # ── Header band ──
    shape.draw_rect(fitz.Rect(0, 0, W, 4))
    shape.finish(fill=ACCENT, color=ACCENT)
    shape.commit()

    y = 40

    # Company name
    page.insert_text(fitz.Point(M, y + 20), COMPANY["name"],
                     fontsize=22, fontname="helv", color=DARK)
    page.insert_text(fitz.Point(M, y + 38), COMPANY["address_1"],
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 50), COMPANY["city_state_zip"],
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 62), f"{COMPANY['phone']}  |  {COMPANY['email']}",
                     fontsize=9, fontname="helv", color=GREY)

    # "INVOICE" right-aligned
    page.insert_text(fitz.Point(W - M - 140, y + 22), "INVOICE",
                     fontsize=32, fontname="helv", color=ACCENT)

    y = 130

    # Invoice details (right column)
    labels = [("Invoice #:", INVOICE["number"]),
              ("Date:", INVOICE["date"]),
              ("Due Date:", INVOICE["due_date"])]
    for i, (label, val) in enumerate(labels):
        ly = y + i * 18
        page.insert_text(fitz.Point(W - M - 200, ly), label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(W - M - 110, ly), val,
                         fontsize=9, fontname="helv", color=DARK)

    # Bill To
    page.insert_text(fitz.Point(M, y), "Bill To",
                     fontsize=9, fontname="helv", color=ACCENT)
    page.insert_text(fitz.Point(M, y + 18), CLIENT["name"],
                     fontsize=12, fontname="helv", color=DARK)
    page.insert_text(fitz.Point(M, y + 33), CLIENT["address_1"],
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 45), CLIENT["city_state_zip"],
                     fontsize=9, fontname="helv", color=GREY)

    # ── Table ──
    y = 220
    col_x = [M, M + 250, M + 330, M + 420, W - M]
    headers = ["Description", "Qty", "Unit Price", "Amount"]

    # Header background
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, y - 4, W - M, y + 18))
    shape.finish(fill=LIGHT_BG, color=LIGHT_BG)
    shape.commit()

    for i, h in enumerate(headers):
        align = col_x[i] if i == 0 else col_x[i] + 5
        page.insert_text(fitz.Point(align, y + 12), h,
                         fontsize=8.5, fontname="helv", color=GREY)

    y += 30
    subtotal, tax, total = _calc_totals(LINE_ITEMS)

    for desc, qty, price in LINE_ITEMS:
        amount = qty * price
        page.insert_text(fitz.Point(col_x[0], y), desc,
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[1] + 10, y), str(qty),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[2] + 5, y), _fmt(price),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[3] + 5, y), _fmt(amount),
                         fontsize=10, fontname="helv", color=DARK)
        y += 24

        # Divider
        shape = page.new_shape()
        shape.draw_line(fitz.Point(M, y - 8), fitz.Point(W - M, y - 8))
        shape.finish(color=(0.90, 0.90, 0.90), width=0.5)
        shape.commit()

    # Totals
    y += 12
    tot_x = col_x[2] + 5
    val_x = col_x[3] + 5

    page.insert_text(fitz.Point(tot_x, y), "Subtotal",
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(val_x, y), _fmt(subtotal),
                     fontsize=10, fontname="helv", color=DARK)

    y += 20
    page.insert_text(fitz.Point(tot_x, y), f"Tax ({int(TAX_RATE*100)}%)",
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(val_x, y), _fmt(tax),
                     fontsize=10, fontname="helv", color=DARK)

    y += 24
    shape = page.new_shape()
    shape.draw_line(fitz.Point(tot_x - 5, y - 8), fitz.Point(W - M, y - 8))
    shape.finish(color=ACCENT, width=1.5)
    shape.commit()

    page.insert_text(fitz.Point(tot_x, y + 2), "Total Due",
                     fontsize=11, fontname="helv", color=ACCENT)
    page.insert_text(fitz.Point(val_x, y + 2), _fmt(total),
                     fontsize=14, fontname="helv", color=DARK)

    # ── Footer ──
    y = H - 90
    page.insert_text(fitz.Point(M, y), "Payment Terms",
                     fontsize=9, fontname="helv", color=ACCENT)
    page.insert_text(fitz.Point(M, y + 16), "Payment is due within 30 days. Please make checks payable to Your Company Name.",
                     fontsize=8.5, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 30), "Bank: First National  |  Routing: 021000021  |  Account: 1234567890",
                     fontsize=8.5, fontname="helv", color=GREY)

    page.insert_text(fitz.Point(M, H - 30), "Thank you for your business!",
                     fontsize=9, fontname="helv", color=ACCENT)

    doc.save(output_path, garbage=4, deflate=True)
    doc.close()


# ── Template 2: Bold Red (Corporate, color blocks) ───────────────────────

def generate_bold_red(output_path):
    """Bold corporate invoice — red accent header, structured layout."""
    W, H = 612, 792
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)

    BLACK = (0, 0, 0)
    DARK = (0.15, 0.15, 0.15)
    GREY = (0.50, 0.50, 0.50)
    WHITE = (1, 1, 1)
    RED = (0.80, 0.20, 0.18)
    LIGHT_RED = (0.98, 0.94, 0.94)

    M = 50

    # ── Red header block ──
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(0, 0, W, 110))
    shape.finish(fill=RED, color=RED)
    shape.commit()

    page.insert_text(fitz.Point(M, 45), COMPANY["name"],
                     fontsize=24, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(M, 65), f"{COMPANY['address_1']}, {COMPANY['city_state_zip']}",
                     fontsize=9, fontname="helv", color=(1, 0.85, 0.85))
    page.insert_text(fitz.Point(M, 80), f"{COMPANY['phone']}  |  {COMPANY['email']}",
                     fontsize=9, fontname="helv", color=(1, 0.85, 0.85))

    page.insert_text(fitz.Point(W - M - 120, 65), "INVOICE",
                     fontsize=28, fontname="helv", color=WHITE)

    y = 135

    # Invoice meta — two columns
    meta_left = [("Invoice Number", INVOICE["number"]),
                 ("Invoice Date", INVOICE["date"]),
                 ("Due Date", INVOICE["due_date"])]
    for i, (label, val) in enumerate(meta_left):
        ly = y + i * 22
        page.insert_text(fitz.Point(W - M - 200, ly), label,
                         fontsize=8, fontname="helv", color=RED)
        page.insert_text(fitz.Point(W - M - 100, ly), val,
                         fontsize=9, fontname="helv", color=DARK)

    # Bill To section
    page.insert_text(fitz.Point(M, y), "BILL TO",
                     fontsize=8, fontname="helv", color=RED)
    page.insert_text(fitz.Point(M, y + 18), CLIENT["name"],
                     fontsize=13, fontname="helv", color=DARK)
    page.insert_text(fitz.Point(M, y + 35), CLIENT["address_1"],
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 48), CLIENT["city_state_zip"],
                     fontsize=9, fontname="helv", color=GREY)

    # ── Table ──
    y = 240
    col_x = [M, M + 250, M + 340, M + 420, W - M]
    headers = ["Description", "Qty", "Unit Price", "Amount"]

    # Red header row
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, y - 6, W - M, y + 18))
    shape.finish(fill=RED, color=RED)
    shape.commit()

    for i, h in enumerate(headers):
        x = col_x[i] if i == 0 else col_x[i] + 5
        page.insert_text(fitz.Point(x, y + 10), h,
                         fontsize=9, fontname="helv", color=WHITE)

    y += 28
    subtotal, tax, total = _calc_totals(LINE_ITEMS)

    for idx, (desc, qty, price) in enumerate(LINE_ITEMS):
        amount = qty * price

        # Alternate row shading
        if idx % 2 == 0:
            shape = page.new_shape()
            shape.draw_rect(fitz.Rect(M, y - 12, W - M, y + 12))
            shape.finish(fill=LIGHT_RED, color=LIGHT_RED)
            shape.commit()

        page.insert_text(fitz.Point(col_x[0], y), desc,
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[1] + 10, y), str(qty),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[2] + 5, y), _fmt(price),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[3] + 5, y), _fmt(amount),
                         fontsize=10, fontname="helv", color=DARK)
        y += 28

    # Totals
    y += 10
    tot_x = col_x[2] + 5
    val_x = col_x[3] + 5

    for label, value in [("Subtotal", _fmt(subtotal)),
                         (f"Tax ({int(TAX_RATE*100)}%)", _fmt(tax))]:
        page.insert_text(fitz.Point(tot_x, y), label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(val_x, y), value,
                         fontsize=10, fontname="helv", color=DARK)
        y += 20

    # Total box
    y += 4
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(tot_x - 10, y - 14, W - M, y + 14))
    shape.finish(fill=RED, color=RED)
    shape.commit()

    page.insert_text(fitz.Point(tot_x, y + 4), "TOTAL DUE",
                     fontsize=10, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(val_x, y + 4), _fmt(total),
                     fontsize=14, fontname="helv", color=WHITE)

    # ── Footer ──
    y = H - 100

    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, y), fitz.Point(W - M, y))
    shape.finish(color=RED, width=1)
    shape.commit()

    y += 18
    page.insert_text(fitz.Point(M, y), "Terms & Conditions",
                     fontsize=9, fontname="helv", color=RED)
    page.insert_text(fitz.Point(M, y + 16), "Payment is due within 30 days of the invoice date. Late payments are subject to a 1.5% monthly fee.",
                     fontsize=8, fontname="helv", color=GREY)

    page.insert_text(fitz.Point(M, H - 30), "Thank you for choosing " + COMPANY["name"] + "!",
                     fontsize=9, fontname="helv", color=RED)

    doc.save(output_path, garbage=4, deflate=True)
    doc.close()


# ── Template 3: Classic Blue (Traditional, serif-feel) ────────────────────

def generate_classic_blue(output_path):
    """Classic professional invoice — navy & gold accents, elegant spacing."""
    W, H = 612, 792
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)

    DARK = (0.12, 0.12, 0.18)
    GREY = (0.45, 0.45, 0.50)
    WHITE = (1, 1, 1)
    NAVY = (0.10, 0.18, 0.36)
    GOLD = (0.78, 0.62, 0.20)
    LIGHT_BLUE = (0.94, 0.96, 0.99)

    M = 50

    # ── Side stripe ──
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(0, 0, 6, H))
    shape.finish(fill=NAVY, color=NAVY)
    shape.commit()

    # ── Gold rule top ──
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, 28), fitz.Point(W - M, 28))
    shape.finish(color=GOLD, width=2)
    shape.commit()

    y = 52

    page.insert_text(fitz.Point(M, y), COMPANY["name"],
                     fontsize=20, fontname="helv", color=NAVY)
    page.insert_text(fitz.Point(M, y + 18), COMPANY["address_1"] + ", " + COMPANY["city_state_zip"],
                     fontsize=8.5, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 30), f"Tel: {COMPANY['phone']}  |  {COMPANY['email']}",
                     fontsize=8.5, fontname="helv", color=GREY)

    # INVOICE title right
    page.insert_text(fitz.Point(W - M - 100, y - 2), "INVOICE",
                     fontsize=26, fontname="helv", color=NAVY)

    # Gold rule below header
    y = 100
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, y), fitz.Point(W - M, y))
    shape.finish(color=GOLD, width=0.75)
    shape.commit()

    y = 120

    # Two-column meta
    # Left: Bill To
    page.insert_text(fitz.Point(M, y), "BILL TO",
                     fontsize=8, fontname="helv", color=GOLD)
    page.insert_text(fitz.Point(M, y + 16), CLIENT["name"],
                     fontsize=12, fontname="helv", color=DARK)
    page.insert_text(fitz.Point(M, y + 32), CLIENT["address_1"],
                     fontsize=9, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 44), CLIENT["city_state_zip"],
                     fontsize=9, fontname="helv", color=GREY)

    # Right: Invoice info
    info_x = W - M - 180
    info_val_x = W - M - 80
    details = [("Invoice #", INVOICE["number"]),
               ("Date", INVOICE["date"]),
               ("Due Date", INVOICE["due_date"]),
               ("Terms", "Net 30")]
    for i, (label, val) in enumerate(details):
        dy = y + i * 18
        page.insert_text(fitz.Point(info_x, dy), label,
                         fontsize=8.5, fontname="helv", color=NAVY)
        page.insert_text(fitz.Point(info_val_x, dy), val,
                         fontsize=9, fontname="helv", color=DARK)

    # ── Table ──
    y = 220
    col_x = [M, M + 260, M + 340, M + 420, W - M]
    headers = ["Description", "Qty", "Rate", "Amount"]

    # Navy header
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, y - 6, W - M, y + 18))
    shape.finish(fill=NAVY, color=NAVY)
    shape.commit()

    for i, h in enumerate(headers):
        x = col_x[i] if i == 0 else col_x[i] + 5
        page.insert_text(fitz.Point(x, y + 10), h,
                         fontsize=9, fontname="helv", color=WHITE)

    y += 30
    subtotal, tax, total = _calc_totals(LINE_ITEMS)

    for idx, (desc, qty, price) in enumerate(LINE_ITEMS):
        amount = qty * price

        if idx % 2 == 0:
            shape = page.new_shape()
            shape.draw_rect(fitz.Rect(M, y - 12, W - M, y + 12))
            shape.finish(fill=LIGHT_BLUE, color=LIGHT_BLUE)
            shape.commit()

        page.insert_text(fitz.Point(col_x[0], y), desc,
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[1] + 10, y), str(qty),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[2] + 5, y), _fmt(price),
                         fontsize=10, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_x[3] + 5, y), _fmt(amount),
                         fontsize=10, fontname="helv", color=DARK)
        y += 28

    # Totals
    y += 10
    tot_x = col_x[2] + 5
    val_x = col_x[3] + 5

    for label, value in [("Subtotal", _fmt(subtotal)),
                         (f"Tax ({int(TAX_RATE*100)}%)", _fmt(tax))]:
        page.insert_text(fitz.Point(tot_x, y), label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(val_x, y), value,
                         fontsize=10, fontname="helv", color=DARK)
        y += 20

    # Gold-bordered total
    y += 4
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(tot_x - 10, y - 14, W - M, y + 16))
    shape.finish(fill=NAVY, color=GOLD, width=1.5)
    shape.commit()

    page.insert_text(fitz.Point(tot_x, y + 6), "TOTAL",
                     fontsize=11, fontname="helv", color=GOLD)
    page.insert_text(fitz.Point(val_x, y + 6), _fmt(total),
                     fontsize=15, fontname="helv", color=WHITE)

    # ── Notes & Footer ──
    y = H - 130
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, y), fitz.Point(W - M, y))
    shape.finish(color=GOLD, width=0.75)
    shape.commit()

    y += 16
    page.insert_text(fitz.Point(M, y), "Notes",
                     fontsize=9, fontname="helv", color=NAVY)
    page.insert_text(fitz.Point(M, y + 16), "Payment is due within 30 days. Please include invoice number on your check.",
                     fontsize=8.5, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(M, y + 30), "For questions about this invoice, contact billing@yourcompany.com.",
                     fontsize=8.5, fontname="helv", color=GREY)

    # Bottom gold line
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, H - 35), fitz.Point(W - M, H - 35))
    shape.finish(color=GOLD, width=0.75)
    shape.commit()

    page.insert_text(fitz.Point(M, H - 22), COMPANY["name"] + "  |  " + COMPANY["email"],
                     fontsize=8, fontname="helv", color=NAVY)

    doc.save(output_path, garbage=4, deflate=True)
    doc.close()


# ── CLI ───────────────────────────────────────────────────────────────────

TEMPLATES = {
    "clean_modern": generate_clean_modern,
    "bold_red": generate_bold_red,
    "classic_blue": generate_classic_blue,
}


def main():
    if len(sys.argv) < 3:
        print(f"Usage: python3 {sys.argv[0]} <template_name> <output_path>")
        print(f"Available templates: {', '.join(TEMPLATES.keys())}")
        sys.exit(1)

    template_name = sys.argv[1]
    output_path = sys.argv[2]

    if template_name not in TEMPLATES:
        print(f"Unknown template: {template_name}")
        print(f"Available: {', '.join(TEMPLATES.keys())}")
        sys.exit(1)

    TEMPLATES[template_name](output_path)
    print(f"OK:{output_path}")


if __name__ == "__main__":
    main()
