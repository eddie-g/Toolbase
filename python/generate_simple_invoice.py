#!/usr/bin/env python3
"""
Generate an invoice PDF from JSON form data passed via stdin.

Usage: echo '{"company_name":"...","items":[...]}' | python3 generate_simple_invoice.py <output_path>

JSON schema:
{
  "company_name": str,
  "company_address": str,       # may contain newlines
  "customer_name": str,
  "customer_address": str,      # may contain newlines
  "invoice_number": str,
  "invoice_date": str,
  "due_date": str,
  "items": [
    {"qty": number, "description": str, "unit_price": number}
  ],
  "discount_label": str|null,   # e.g. "10% Early-pay Discount"
  "discount_amount": number,    # positive value subtracted from subtotal
  "terms": str,
  "notes": str,
  "style": "default"|"bold_red"
}
"""

import json
import sys
import fitz  # PyMuPDF


def _fmt(amount):
    """Format a number as $X,XXX.XX"""
    return f"${amount:,.2f}"


# ===================================================================
#  DEFAULT STYLE  (dark header bar, green accents)
# ===================================================================

def _generate_default(data, page, W, H):
    WHITE   = (1, 1, 1)
    BG      = (0.976, 0.976, 0.984)
    BLACK   = (0.12, 0.12, 0.14)
    DARK    = (0.22, 0.22, 0.26)
    GREY    = (0.52, 0.52, 0.56)
    GREEN   = (0.16, 0.50, 0.35)
    GREEN_L = (0.94, 0.98, 0.96)
    BORDER  = (0.84, 0.84, 0.86)

    M  = 50
    shape = page.new_shape()

    # Full-page background
    shape.draw_rect(fitz.Rect(0, 0, W, H))
    shape.finish(fill=BG, color=BG)
    shape.commit()

    # Company box (top-left)
    box_w, box_h = 240, 70
    bx, by = M, 40
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(bx, by, bx + box_w, by + box_h))
    shape.finish(fill=WHITE, color=BORDER, width=0.75)
    shape.commit()

    company_name = data.get("company_name", "Your Company Inc.")
    company_addr = data.get("company_address", "1234 Company St.\nCompany Town ST 12345")
    page.insert_text(fitz.Point(bx + 12, by + 22), company_name,
                     fontsize=12, fontname="helv", color=BLACK)
    for i, line in enumerate(company_addr.split("\n")[:3]):
        page.insert_text(fitz.Point(bx + 12, by + 38 + i * 13), line.strip(),
                         fontsize=9, fontname="helv", color=GREY)

    # "INVOICE" title
    page.insert_text(fitz.Point(W - M - 155, by + box_h + 30), "INVOICE",
                     fontsize=28, fontname="helv", color=GREEN)

    # Bill-To box
    y_bill = by + box_h + 50
    page.insert_text(fitz.Point(M, y_bill), "Bill To",
                     fontsize=10, fontname="helv", color=BLACK)
    y_bill += 10

    cust_box_h = 64
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, y_bill, M + box_w, y_bill + cust_box_h))
    shape.finish(fill=WHITE, color=BORDER, width=0.75)
    shape.commit()

    customer_name = data.get("customer_name", "Customer Name")
    customer_addr = data.get("customer_address", "1234 Customer St.\nCustomer Town ST 12345")
    page.insert_text(fitz.Point(M + 12, y_bill + 18), customer_name,
                     fontsize=11, fontname="helv", color=BLACK)
    for i, line in enumerate(customer_addr.split("\n")[:3]):
        page.insert_text(fitz.Point(M + 12, y_bill + 34 + i * 13), line.strip(),
                         fontsize=9, fontname="helv", color=GREY)

    # Invoice meta (right column)
    inv_number = data.get("invoice_number", "0001001")
    inv_date   = data.get("invoice_date", "02-06-2026")
    due_date   = data.get("due_date", "02-20-2026")

    meta_label_x = W - M - 210
    meta_val_x   = W - M - 90
    meta_box_w   = 86
    meta_y       = y_bill

    for i, (label, val) in enumerate([
        ("Invoice #", inv_number),
        ("Invoice Date", inv_date),
        ("Due Date", due_date),
    ]):
        ry = meta_y + i * 28
        page.insert_text(fitz.Point(meta_label_x, ry + 14), label,
                         fontsize=9, fontname="helv", color=BLACK)
        shape = page.new_shape()
        shape.draw_rect(fitz.Rect(meta_val_x, ry + 2, meta_val_x + meta_box_w, ry + 20))
        shape.finish(fill=WHITE, color=BORDER, width=0.75)
        shape.commit()
        page.insert_text(fitz.Point(meta_val_x + 6, ry + 15), val,
                         fontsize=9, fontname="helv", color=DARK)

    # Line-items table
    table_y = y_bill + cust_box_h + 30
    col_qty   = M
    col_desc  = M + 48
    col_price = W - M - 170
    col_amt   = W - M - 70

    hdr_h = 22
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, table_y, W - M, table_y + hdr_h))
    shape.finish(fill=GREEN, color=GREEN)
    shape.commit()

    page.insert_text(fitz.Point(col_qty + 8, table_y + 15), "QTY",
                     fontsize=8.5, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_desc + 4, table_y + 15), "Description",
                     fontsize=8.5, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_price, table_y + 15), "Unit Price",
                     fontsize=8.5, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_amt + 4, table_y + 15), "Amount",
                     fontsize=8.5, fontname="helv", color=WHITE)

    items = data.get("items", [])
    row_h = 22
    y = table_y + hdr_h
    subtotal = 0.0

    for idx, item in enumerate(items):
        qty    = item.get("qty", 0)
        desc   = item.get("description", "")
        price  = item.get("unit_price", 0)
        amount = round(qty * price, 2)
        subtotal += amount

        bg = WHITE if idx % 2 == 0 else GREEN_L
        shape = page.new_shape()
        shape.draw_rect(fitz.Rect(M, y, W - M, y + row_h))
        shape.finish(fill=bg, color=bg)
        shape.commit()

        page.insert_text(fitz.Point(col_qty + 12, y + 15), str(qty),
                         fontsize=9, fontname="helv", color=DARK)
        d = desc if len(desc) <= 50 else desc[:47] + "..."
        page.insert_text(fitz.Point(col_desc + 4, y + 15), d,
                         fontsize=9, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_price + 4, y + 15), _fmt(price),
                         fontsize=9, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_amt + 4, y + 15), _fmt(amount),
                         fontsize=9, fontname="helv", color=DARK)
        y += row_h

    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, y), fitz.Point(W - M, y))
    shape.finish(color=BORDER, width=0.75)
    shape.commit()

    # Discount
    discount_label  = data.get("discount_label", "")
    discount_amount = float(data.get("discount_amount", 0))
    y += 14

    if discount_label and discount_amount > 0:
        page.insert_text(fitz.Point(col_price - 80, y), discount_label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(col_amt + 4, y), "-" + _fmt(discount_amount),
                         fontsize=9, fontname="helv", color=(0.8, 0.2, 0.2))
        y += 20

    # Total
    total = max(round(subtotal - discount_amount, 2), 0.0)
    tot_bar_y = y
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(col_price - 80, tot_bar_y, W - M, tot_bar_y + 22))
    shape.finish(fill=WHITE, color=BORDER, width=0.75)
    shape.commit()

    shape = page.new_shape()
    shape.draw_line(fitz.Point(col_price - 80, tot_bar_y), fitz.Point(W - M, tot_bar_y))
    shape.finish(color=GREEN, width=2)
    shape.commit()

    page.insert_text(fitz.Point(col_price - 74, tot_bar_y + 15), "Total (USD)",
                     fontsize=10, fontname="helv", color=BLACK)
    page.insert_text(fitz.Point(col_amt + 4, tot_bar_y + 15), _fmt(total),
                     fontsize=11, fontname="helv", color=BLACK)

    y = tot_bar_y + 44
    _draw_terms_boxed(data, page, M, W, H, y, BLACK, WHITE, BORDER, GREY)


# ===================================================================
#  BOLD RED STYLE  (red header block, red table, subtotal/tax/total)
# ===================================================================

def _generate_bold_red(data, page, W, H):
    WHITE   = (1, 1, 1)
    BLACK   = (0.12, 0.12, 0.14)
    DARK    = (0.22, 0.22, 0.26)
    GREY    = (0.52, 0.52, 0.56)
    LGREY   = (0.72, 0.72, 0.74)
    RED     = (0.78, 0.18, 0.18)
    RED_D   = (0.75, 0.16, 0.16)
    RED_L   = (0.99, 0.94, 0.94)
    BORDER  = (0.88, 0.88, 0.88)

    M = 50

    # Full-page white background
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(0, 0, W, H))
    shape.finish(fill=WHITE, color=WHITE)
    shape.commit()

    # ── Red header block ──────────────────────────────────────
    hdr_h = 100
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(0, 0, W, hdr_h))
    shape.finish(fill=RED_D, color=RED_D)
    shape.commit()

    company_name = data.get("company_name", "Your Company Name")
    company_addr = data.get("company_address",
                            "123 Business Avenue, New York, NY 10001\n(555) 123-4567  |  billing@yourcompany.com")

    # Company name – large white, left
    page.insert_text(fitz.Point(M, 42), company_name,
                     fontsize=22, fontname="helv", color=WHITE)
    # Company address lines – lighter pink, left
    for i, line in enumerate(company_addr.split("\n")[:3]):
        page.insert_text(fitz.Point(M, 62 + i * 15), line.strip(),
                         fontsize=9, fontname="helv", color=(0.95, 0.8, 0.8))

    # "INVOICE" – large white text, inside header, right side
    page.insert_text(fitz.Point(W - M - 148, 55), "INVOICE",
                     fontsize=32, fontname="helv", color=WHITE)

    # ── Horizontal divider below header ───────────────────────
    div_y = hdr_h + 20
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, div_y), fitz.Point(W - M, div_y))
    shape.finish(color=LGREY, width=0.75)
    shape.commit()

    # ── Bill To (left side) ───────────────────────────────────
    y_sect = div_y + 20
    page.insert_text(fitz.Point(M, y_sect), "BILL TO",
                     fontsize=9, fontname="helv", color=RED)
    y_sect += 18

    customer_name = data.get("customer_name", "Client Name")
    customer_addr = data.get("customer_address",
                             "456 Client Street\nLos Angeles, CA 90001")
    page.insert_text(fitz.Point(M, y_sect), customer_name,
                     fontsize=12, fontname="helv", color=BLACK)
    y_sect += 16
    for i, line in enumerate(customer_addr.split("\n")[:4]):
        page.insert_text(fitz.Point(M, y_sect + i * 14), line.strip(),
                         fontsize=9.5, fontname="helv", color=GREY)

    # ── Invoice meta (right side) ─────────────────────────────
    inv_number = data.get("invoice_number", "INV-2026-001")
    inv_date   = data.get("invoice_date", "February 3, 2026")
    due_date   = data.get("due_date", "February 23, 2026")

    meta_label_x = W - M - 250
    meta_val_x   = W - M - 115

    meta_y = div_y + 20
    for i, (label, val) in enumerate([
        ("Invoice Number", inv_number),
        ("Invoice Date", inv_date),
        ("Due Date", due_date),
    ]):
        ry = meta_y + i * 22
        page.insert_text(fitz.Point(meta_label_x, ry), label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(meta_val_x, ry), val,
                         fontsize=9, fontname="helv", color=DARK)

    # ── Line-items table ──────────────────────────────────────
    table_top = max(y_sect + 60, meta_y + 80)
    col_desc  = M
    col_qty   = M + 265
    col_price = M + 340
    col_amt   = W - M - 68

    hdr_row_h = 26
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, table_top, W - M, table_top + hdr_row_h))
    shape.finish(fill=RED_D, color=RED_D)
    shape.commit()

    page.insert_text(fitz.Point(col_desc + 10, table_top + 17), "Description",
                     fontsize=9, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_qty + 10, table_top + 17), "Qty",
                     fontsize=9, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_price + 6, table_top + 17), "Unit Price",
                     fontsize=9, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(col_amt + 2, table_top + 17), "Amount",
                     fontsize=9, fontname="helv", color=WHITE)

    items = data.get("items", [])
    row_h = 28
    y = table_top + hdr_row_h
    subtotal = 0.0

    for idx, item in enumerate(items):
        qty    = item.get("qty", 0)
        desc   = item.get("description", "")
        price  = item.get("unit_price", 0)
        amount = round(qty * price, 2)
        subtotal += amount

        # Row background – white base, faint border at bottom
        shape = page.new_shape()
        shape.draw_rect(fitz.Rect(M, y, W - M, y + row_h))
        shape.finish(fill=WHITE, color=WHITE)
        shape.commit()
        shape = page.new_shape()
        shape.draw_line(fitz.Point(M, y + row_h), fitz.Point(W - M, y + row_h))
        shape.finish(color=BORDER, width=0.5)
        shape.commit()

        d = desc if len(desc) <= 42 else desc[:39] + "..."
        page.insert_text(fitz.Point(col_desc + 10, y + 18), d,
                         fontsize=9.5, fontname="helv", color=DARK)
        qty_str = str(int(qty)) if qty == int(qty) else str(qty)
        page.insert_text(fitz.Point(col_qty + 18, y + 18), qty_str,
                         fontsize=9.5, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_price + 6, y + 18), _fmt(price) if price else "",
                         fontsize=9.5, fontname="helv", color=DARK)
        page.insert_text(fitz.Point(col_amt + 2, y + 18), _fmt(amount),
                         fontsize=9.5, fontname="helv", color=DARK)
        y += row_h

    # bottom border of last row
    shape = page.new_shape()
    shape.draw_line(fitz.Point(M, y), fitz.Point(W - M, y))
    shape.finish(color=BORDER, width=0.75)
    shape.commit()

    # ── Subtotal / Tax / Total Due ────────────────────────────
    discount_label  = data.get("discount_label", "")
    discount_amount = float(data.get("discount_amount", 0))
    tax_rate = 0.08

    y += 20
    label_x = col_price - 30
    val_x   = col_amt + 2

    # Subtotal
    page.insert_text(fitz.Point(label_x, y), "Subtotal",
                     fontsize=9.5, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(val_x, y), _fmt(subtotal),
                     fontsize=9.5, fontname="helv", color=DARK)
    y += 20

    # Discount (if any)
    if discount_label and discount_amount > 0:
        page.insert_text(fitz.Point(label_x, y), discount_label,
                         fontsize=9, fontname="helv", color=GREY)
        page.insert_text(fitz.Point(val_x, y), "-" + _fmt(discount_amount),
                         fontsize=9, fontname="helv", color=RED)
        y += 20
        subtotal -= discount_amount

    # Tax
    tax = round(max(subtotal, 0) * tax_rate, 2)
    page.insert_text(fitz.Point(label_x, y), f"Tax ({int(tax_rate*100)}%)",
                     fontsize=9.5, fontname="helv", color=GREY)
    page.insert_text(fitz.Point(val_x, y), _fmt(tax),
                     fontsize=9.5, fontname="helv", color=DARK)
    y += 24

    # TOTAL DUE – red bar
    total = max(round(subtotal + tax, 2), 0.0)
    tot_box_h = 28
    tot_box_w = 240
    tot_x = W - M - tot_box_w

    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(tot_x, y, W - M, y + tot_box_h))
    shape.finish(fill=RED_D, color=RED_D)
    shape.commit()

    page.insert_text(fitz.Point(tot_x + 14, y + 19), "TOTAL DUE",
                     fontsize=10, fontname="helv", color=WHITE)
    page.insert_text(fitz.Point(val_x, y + 19), _fmt(total),
                     fontsize=13, fontname="helv", color=WHITE)

    y += tot_box_h + 36

    # ── Terms / Notes ─────────────────────────────────────────
    terms = data.get("terms", "") or data.get("notes", "")
    if terms:
        page.insert_text(fitz.Point(M, y), "Terms and Conditions",
                         fontsize=10, fontname="helv", color=BLACK)
        y += 16
        for line in terms.split("\n")[:6]:
            if y > H - 40:
                break
            page.insert_text(fitz.Point(M, y), line.strip(),
                             fontsize=9, fontname="helv", color=GREY)
            y += 14


# ===================================================================
#  SHARED HELPERS
# ===================================================================

def _draw_terms_boxed(data, page, M, W, H, y, BLACK, WHITE, BORDER, GREY):
    terms = data.get("terms", "")
    notes = data.get("notes", "")
    if not terms and not notes:
        return
    page.insert_text(fitz.Point(M, y), "Terms and Conditions",
                     fontsize=10, fontname="helv", color=BLACK)
    y += 6
    box_top = y
    text_content = terms if terms else notes
    lines = text_content.split("\n")
    needed_h = max(60, len(lines) * 14 + 20)
    if y + needed_h > H - 40:
        needed_h = H - 40 - y
    shape = page.new_shape()
    shape.draw_rect(fitz.Rect(M, box_top, W - M, box_top + needed_h))
    shape.finish(fill=WHITE, color=BORDER, width=0.75)
    shape.commit()
    ty = box_top + 16
    for line in lines:
        if ty > box_top + needed_h - 8:
            break
        page.insert_text(fitz.Point(M + 12, ty), line.strip(),
                         fontsize=9, fontname="helv", color=GREY)
        ty += 14


# ===================================================================
#  MAIN
# ===================================================================

def generate(data, output_path):
    W, H = 612, 792  # US Letter
    doc = fitz.open()
    page = doc.new_page(width=W, height=H)

    style = data.get("style", "default")
    if style == "bold_red":
        _generate_bold_red(data, page, W, H)
    else:
        _generate_default(data, page, W, H)

    doc.save(output_path, garbage=4, deflate=True)
    doc.close()


def main():
    if len(sys.argv) < 2:
        print(f"Usage: echo '<json>' | python3 {sys.argv[0]} <output_path>", file=sys.stderr)
        sys.exit(1)

    output_path = sys.argv[1]

    try:
        data = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        print(f"Invalid JSON input: {e}", file=sys.stderr)
        sys.exit(1)

    generate(data, output_path)
    print(f"OK:{output_path}")


if __name__ == "__main__":
    main()
